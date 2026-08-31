<?php

declare(strict_types=1);

namespace GuAia\Tests\Invariant;

use GuAia\Answering\AnswerMode;
use GuAia\Answering\AnswerResult;
use GuAia\Http\WidgetRenderer;
use GuAia\Safety\Csrf;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * INV-4 — Disclosure.
 *
 * "Every session states plainly that the user is talking to an AI assistant, in
 *  the interface, BEFORE THE FIRST ANSWER. Rendered server-side in the widget
 *  shell, NOT INJECTABLE AWAY."
 *
 * docs/standards.md treats EU AI Act Article 50 as the benchmark for this, not
 * as a compliance claim: Uganda does not impose it, but "a person must be told
 * plainly they are interacting with an AI system" is the correct floor for a
 * public university service regardless.
 *
 * What "not injectable away" has to mean in practice is that no argument, flag,
 * template variable or code path produces the widget without it. These tests
 * assert exactly that, including on the paths a developer would most plausibly
 * add later — an error state, a rate-limit refusal, a page rendered before any
 * question has been asked.
 */
#[Group('invariant')]
final class DisclosureTest extends TestCase
{
    private const REQUIRED = 'You are talking to an AI assistant.';

    private function renderer(): WidgetRenderer
    {
        return new WidgetRenderer();
    }

    private function csrfField(): string
    {
        $session = [];

        return (new Csrf($session))->field();
    }

    public function testTheDisclosureIsPresentOnAFreshWidget(): void
    {
        $html = $this->renderer()->shell($this->csrfField());

        self::assertStringContainsString(self::REQUIRED, $html);
    }

    public function testTheDisclosureComesBeforeTheFormAndBeforeAnyAnswer(): void
    {
        // "Before the first answer" is positional, not merely present-somewhere.
        // A disclosure below the answer has already failed by the time it is read.
        $html = $this->renderer()->shell(
            $this->csrfField(),
            'What are the fees?',
            new AnswerResult(mode: AnswerMode::Refuse, text: 'I cannot answer that.')
        );

        $disclosureAt = strpos($html, self::REQUIRED);
        $formAt = strpos($html, '<form');
        $answerAt = strpos($html, 'gu-aia-answer');

        self::assertIsInt($disclosureAt);
        self::assertIsInt($formAt);
        self::assertIsInt($answerAt);
        self::assertLessThan($formAt, $disclosureAt, 'INV-4: the disclosure must precede the form.');
        self::assertLessThan($answerAt, $disclosureAt, 'INV-4: the disclosure must precede the answer.');
    }

    public function testTheDisclosureSurvivesEveryShellVariation(): void
    {
        $renderer = $this->renderer();
        $field = $this->csrfField();

        $variations = [
            'bare' => $renderer->shell($field),
            'with question' => $renderer->shell($field, 'What are the fees?'),
            'with answer' => $renderer->shell($field, 'x', new AnswerResult(AnswerMode::Grounded, 'Answer. [1]')),
            'with notice' => $renderer->shell($field, null, null, 'You have reached the hourly limit.'),
            'with starters' => $renderer->shell($field, null, null, null, ['What are the fees?']),
        ];

        foreach ($variations as $name => $html) {
            self::assertStringContainsString(
                self::REQUIRED,
                $html,
                sprintf('INV-4 breach: the "%s" rendering omits the disclosure.', $name)
            );
        }
    }

    public function testTheDisclosureTakesNoArgumentsThatCouldSuppressIt(): void
    {
        // If disclosure() ever grows a parameter, something can be passed to turn
        // it off — which is the mechanism INV-4 forbids. This fails the moment
        // that becomes possible.
        $method = new \ReflectionMethod(WidgetRenderer::class, 'disclosure');

        self::assertSame(
            0,
            $method->getNumberOfParameters(),
            'INV-4: disclosure() must not accept anything that could suppress or vary it.'
        );
    }

    public function testTheDisclosureSaysWhatTheAssistantCannotDo(): void
    {
        // Section 7 and INV-3: the commonest reason a visitor is disappointed is
        // asking whether they will be admitted. Saying so up front is part of
        // disclosing what this thing is.
        $html = $this->renderer()->disclosure();

        self::assertStringContainsString('cannot tell you whether you will be admitted', $html);
        self::assertStringContainsString('cannot see', $html);
    }

    public function testTheDisclosureIsAnnouncedToScreenReaders(): void
    {
        // role="note" rather than a decorative aside, so it is read as content.
        self::assertStringContainsString('role="note"', $this->renderer()->disclosure());
    }

    public function testTheEndpointEmitsTheDisclosureOnEveryPath(): void
    {
        // A static read of the endpoint: the disclosure comes from shell(), and
        // shell() is the only thing that renders the page. If a second render
        // path is ever added that does not call shell(), this is the test that
        // should be updated to cover it - deliberately, not by accident.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/ask.php');

        self::assertStringContainsString('$renderer->shell(', $source);
        self::assertSame(
            1,
            substr_count($source, '<body>'),
            'INV-4: more than one page rendering path would need its own disclosure check.'
        );
    }
}
