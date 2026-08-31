<?php

declare(strict_types=1);

namespace GuAia\Tests\Invariant;

use GuAia\Answering\AnswerMode;
use GuAia\Answering\AnswerResult;
use GuAia\Http\WidgetRenderer;
use GuAia\Retrieval\ScoredChunk;
use GuAia\Safety\Csrf;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * INV-9 — It works on a bad connection.
 *
 * "Total widget payload under the stated budget; a no-JavaScript fallback
 *  returns cited results by form post. Automated page-weight check in CI; the
 *  fallback path has its own tests."
 *
 * Section 10 states the budget: 60 KB including CSS and JS.
 *
 * This is not a performance nicety. A large share of this audience is mobile-
 * first on constrained connections, and the people most likely to be on the
 * worst connection are the ones with the most at stake in what the assistant
 * says. A widget that only works on a good connection has quietly selected
 * which applicants it serves.
 */
#[Group('invariant')]
final class WorksOnABadConnectionTest extends TestCase
{
    private const BUDGET_BYTES = 60 * 1024;

    private function publicRoot(): string
    {
        return dirname(__DIR__, 2) . '/public';
    }

    private function csrfField(): string
    {
        $session = [];

        return (new Csrf($session))->field();
    }

    public function testTotalWidgetPayloadIsWithinBudget(): void
    {
        $css = (int) filesize($this->publicRoot() . '/assets/widget.css');
        $js = (int) filesize($this->publicRoot() . '/assets/widget.js');

        $html = strlen($this->renderRealisticPage());
        $total = $css + $js + $html;

        self::assertLessThanOrEqual(
            self::BUDGET_BYTES,
            $total,
            sprintf(
                'INV-9 breach: widget payload is %d bytes (HTML %d + CSS %d + JS %d), over the 60 KB budget.',
                $total,
                $html,
                $css,
                $js
            )
        );
    }

    public function testTheScriptIsSmallEnoughToBeOptional(): void
    {
        // The script is progressive enhancement. If it ever grows past a few
        // kilobytes it has almost certainly started doing something the server
        // should be doing, which is how a no-JS fallback rots.
        self::assertLessThan(
            8 * 1024,
            (int) filesize($this->publicRoot() . '/assets/widget.js'),
            'INV-9: the enhancement script has grown enough to suggest it is now load-bearing.'
        );
    }

    public function testTheFormWorksWithoutJavaScript(): void
    {
        $html = $this->renderer()->shell($this->csrfField());

        // A real form, a real method, a real action. Not a button wired up by a
        // script, which is the usual way this requirement is quietly lost.
        self::assertMatchesRegularExpression('/<form[^>]+method="post"/i', $html);
        self::assertMatchesRegularExpression('/<form[^>]+action="[^"]+"/i', $html);
        self::assertMatchesRegularExpression('/<input[^>]+name="question"/i', $html);
        self::assertMatchesRegularExpression('/<button[^>]+type="submit"/i', $html);
    }

    public function testTheFallbackPostsToTheSameEndpoint(): void
    {
        // Section 10: "a plain form posting to the SAME endpoint". Two endpoints
        // means two code paths, and the one nobody uses is the one that breaks.
        $source = (string) file_get_contents($this->publicRoot() . '/ask.php');

        self::assertStringContainsString("REQUEST_METHOD", $source);
        self::assertStringContainsString('text/html+fragment', $source);
        self::assertStringContainsString(
            'new WidgetRenderer()',
            $source,
            'Both the full page and the fragment must render through the same renderer.'
        );
    }

    public function testAnAnswerRendersItsCitationsAsRealLinks(): void
    {
        // "returns CITED results by form post" - a fallback that drops the
        // sources is not the same answer.
        $html = $this->renderer()->answer($this->citedAnswer());

        self::assertStringContainsString('Sources', $html);
        self::assertStringContainsString('href="https://gu.ac.ug/fees"', $html);
        self::assertStringContainsString('Last reviewed 2026-01-01', $html);
    }

    public function testTheAnswerRegionIsAnnouncedAndFocusable(): void
    {
        // WCAG 2.1 AA, Section 10: "screen-reader announced, focus managed on
        // new answers". aria-live does the announcing; tabindex="-1" is what
        // lets the script move focus there instead of leaving a keyboard user
        // stranded on the button.
        $html = $this->renderer()->answer($this->citedAnswer());

        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('tabindex="-1"', $html);
        self::assertStringContainsString('role="region"', $html);
    }

    public function testModelOutputIsEscapedOnRender(): void
    {
        // OWASP LLM: insecure output handling. The answer text is untrusted, and
        // it is the one string on the page that a language model wrote.
        $hostile = '<img src=x onerror="alert(1)"> and <script>alert(2)</script>';

        $html = $this->renderer()->answer(new AnswerResult(AnswerMode::Grounded, $hostile));

        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    public function testNoExternalAssetsAreRequested(): void
    {
        // An external font or CDN script is a second connection, a second point
        // of failure, and on a bad connection it is the one that hangs.
        $css = (string) file_get_contents($this->publicRoot() . '/assets/widget.css');
        $js = (string) file_get_contents($this->publicRoot() . '/assets/widget.js');

        foreach (['@import', 'https://', 'http://'] as $needle) {
            self::assertStringNotContainsString($needle, $css, 'The stylesheet must not fetch anything external.');
        }
        self::assertStringNotContainsString('https://', $js);
    }

    public function testReducedMotionIsRespected(): void
    {
        $css = (string) file_get_contents($this->publicRoot() . '/assets/widget.css');

        self::assertStringContainsString('prefers-reduced-motion', $css);
    }

    private function renderer(): WidgetRenderer
    {
        return new WidgetRenderer();
    }

    /** A page carrying an answer with sources, which is the heaviest realistic case. */
    private function renderRealisticPage(): string
    {
        return $this->renderer()->shell(
            $this->csrfField(),
            'What are the entry requirements for Computer Science?',
            $this->citedAnswer(),
            null,
            ['What are the fees?', 'How do I apply?', 'When do applications close?']
        );
    }

    private function citedAnswer(): AnswerResult
    {
        $chunk = new ScoredChunk(
            chunkId: 1,
            documentId: 1,
            body: 'Entry requirements are published annually.',
            score: 0.9,
            sourceRef: 'https://gu.ac.ug/fees',
            title: 'Fees and requirements',
            reviewedAt: '2026-01-01',
            reviewIntervalDays: 365,
        );

        return new AnswerResult(
            mode: AnswerMode::Grounded,
            text: str_repeat('This is a realistic answer sentence with a citation. [1] ', 12),
            sources: [$chunk],
            retrieved: [$chunk],
            citations: [1 => 1],
        );
    }
}
