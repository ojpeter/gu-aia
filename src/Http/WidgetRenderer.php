<?php

declare(strict_types=1);

namespace GuAia\Http;

use GuAia\Answering\AnswerMode;
use GuAia\Answering\AnswerResult;

/**
 * Renders the widget shell and its answers. requirements.md Section 10.
 *
 * INV-4 — DISCLOSURE
 *
 * "Every session states plainly that the user is talking to an AI assistant, in
 *  the interface, before the first answer. Rendered SERVER-SIDE in the widget
 *  shell, NOT INJECTABLE AWAY."
 *
 * The disclosure is emitted by `shell()` as part of the same string as the form,
 * before any answer can exist, and it is not behind a flag, a template variable,
 * a collapsed section or a JavaScript branch. There is no code path that
 * produces the widget without it, which is what "not injectable away" has to
 * mean in practice — a banner that a stylesheet or a script can hide is not a
 * disclosure.
 *
 * INV-9 — WORKS ON A BAD CONNECTION
 *
 * Everything here is server-rendered HTML. The form posts to the same endpoint
 * and returns a cited answer with no JavaScript involved at all; the script is
 * progressive enhancement over a page that already works without it.
 *
 * OUTPUT ESCAPING (OWASP LLM: insecure output handling)
 *
 * Model output is untrusted. Every dynamic value goes through esc(), and source
 * links are rendered only from the retrieved set's own sourceRef values, never
 * from anything the model produced. A generated answer cannot introduce a link
 * target, because the answer text is escaped and the links are built separately
 * from the chunks.
 */
final class WidgetRenderer
{
    public function __construct(
        private readonly string $actionUrl = 'ask.php',
        private readonly string $assetBase = 'assets',
    ) {
    }

    public function esc(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The AI-use disclosure. INV-4.
     *
     * Deliberately a separate method with no arguments and no conditions: there
     * is nothing to pass that could suppress it, and nothing to configure that
     * could water it down. `role="note"` rather than an aside so screen readers
     * announce it as content rather than skipping it as decoration.
     */
    public function disclosure(): string
    {
        return '<p class="gu-aia-disclosure" role="note">'
            . '<strong>You are talking to an AI assistant.</strong> '
            . 'It answers only from Gulu University&rsquo;s published information and shows you the source '
            . 'for everything it says. It cannot tell you whether you will be admitted, and it cannot see '
            . 'your application or your records.'
            . '</p>';
    }

    /**
     * The full widget: disclosure, form, and an answer if there is one.
     *
     * @param list<string> $starterQuestions drawn from the real log, never invented (Section 10)
     */
    public function shell(
        string $csrfField,
        ?string $question = null,
        ?AnswerResult $answer = null,
        ?string $notice = null,
        array $starterQuestions = [],
    ): string {
        $html = '<section class="gu-aia" aria-labelledby="gu-aia-title">';
        $html .= '<h2 id="gu-aia-title">Ask Gulu University</h2>';

        // INV-4: before the form, before any answer, unconditionally.
        $html .= $this->disclosure();

        if ($notice !== null) {
            $html .= '<p class="gu-aia-notice" role="alert">' . $this->esc($notice) . '</p>';
        }

        $html .= '<form class="gu-aia-form" method="post" action="' . $this->esc($this->actionUrl) . '">';
        $html .= $csrfField;
        $html .= '<label for="gu-aia-question">Your question</label>';
        $html .= '<input type="text" id="gu-aia-question" name="question" maxlength="500" required'
            . ' autocomplete="off" value="' . $this->esc($question) . '">';
        $html .= '<button type="submit">Ask</button>';
        $html .= '</form>';

        if ($starterQuestions !== []) {
            $html .= '<div class="gu-aia-starters"><p id="gu-aia-starters-label">Questions people ask most:</p><ul aria-labelledby="gu-aia-starters-label">';
            foreach ($starterQuestions as $starter) {
                $html .= '<li><a href="' . $this->esc($this->actionUrl) . '?question=' . rawurlencode($starter) . '">'
                    . $this->esc($starter) . '</a></li>';
            }
            $html .= '</ul></div>';
        }

        if ($answer !== null) {
            $html .= $this->answer($answer);
        }

        $html .= '</section>';

        return $html;
    }

    /**
     * One answer, with its sources and, where applicable, the stale-content
     * caution INV-11 requires.
     *
     * aria-live="polite" plus tabindex="-1" on the container: the answer is
     * announced when it arrives, and JavaScript moves focus here so a keyboard
     * user lands on the new content instead of being left at the button
     * (Section 10, WCAG 2.1 AA).
     */
    public function answer(AnswerResult $result): string
    {
        $html = '<div class="gu-aia-answer" id="gu-aia-answer" role="region" aria-live="polite"'
            . ' aria-label="Answer" tabindex="-1" data-mode="' . $this->esc($result->mode->value) . '">';

        if ($result->staleSource) {
            // INV-11: content past its review interval is still served, with a
            // visible caution. Not a footnote — it precedes the answer.
            $html .= '<p class="gu-aia-caution" role="note">'
                . 'This comes from a page that is overdue for review, so it may be out of date. '
                . 'Please check the source before relying on it.'
                . '</p>';
        }

        if ($result->degraded) {
            $html .= '<p class="gu-aia-caution" role="note">'
                . 'Showing the published pages that match your question, without a written summary.'
                . '</p>';
        }

        // Escaped. Model output is untrusted, and nl2br is applied to the ESCAPED
        // string so it cannot introduce markup of its own.
        $html .= '<div class="gu-aia-text">' . nl2br($this->esc($result->text), false) . '</div>';

        if ($result->sources !== []) {
            $html .= '<h3 class="gu-aia-sources-title">Sources</h3><ol class="gu-aia-sources">';
            foreach ($result->sources as $source) {
                $html .= '<li><a href="' . $this->esc($source->sourceRef) . '" rel="noopener">'
                    . $this->esc($source->title) . '</a>'
                    . ' <span class="gu-aia-reviewed">Last reviewed ' . $this->esc($source->reviewedAt) . '</span>'
                    . '</li>';
            }
            $html .= '</ol>';
        }

        if ($result->mode === AnswerMode::Refuse) {
            $html .= '<p class="gu-aia-refusal-note">A refusal means the answer is not in the University&rsquo;s '
                . 'published information &mdash; not that your question was wrong.</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /** Stylesheet and script tags, cache-busted by file mtime. */
    public function assets(string $publicRoot): string
    {
        $css = $this->assetBase . '/widget.css';
        $js = $this->assetBase . '/widget.js';

        $cssVersion = @filemtime($publicRoot . '/' . $css) ?: 0;
        $jsVersion = @filemtime($publicRoot . '/' . $js) ?: 0;

        return sprintf(
            '<link rel="stylesheet" href="%s?v=%d">' . "\n" . '<script defer src="%s?v=%d"></script>',
            $this->esc($css),
            $cssVersion,
            $this->esc($js),
            $jsVersion
        );
    }
}
