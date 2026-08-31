<?php

declare(strict_types=1);

namespace GuAia\Safety;

/**
 * Renders a refusal. requirements.md Section 9.
 *
 * "A refusal is a successful outcome, and it must not read as a failure. Every
 *  refusal: says plainly that it cannot answer from the University's published
 *  information; names a human contact — office, email, telephone — appropriate
 *  to the category; offers the closest relevant page if retrieval found anything
 *  at all; and is logged as an unanswered question."
 *
 * A CORRECTION TO AN EARLIER DECISION, RECORDED RATHER THAN QUIETLY MADE
 *
 * config/refusals.php says the handoff renderer should "fail loudly on a null
 * contact rather than emit a refusal with no route". That was the right
 * instinct and the wrong mechanism. Throwing would mean that until the Registry
 * supplies its email address, every refusal — which is the correct outcome for a
 * large share of questions — becomes an error page. The user then gets nothing
 * at all, which is strictly worse than a refusal that names an office without a
 * phone number.
 *
 * So: the refusal is always rendered, and `handoffMissing` is set so the gap is
 * visible in the interaction log, on the status page, and in the weekly report.
 * Loud to the people who can fix it; never loud at the person who just asked a
 * question. The pre-launch checklist in docs/data-protection.md keeps it from
 * shipping unresolved.
 */
final class RefusalRenderer
{
    /** @param array{contacts: array<string, array<string, ?string>>, templates: array<string, string>} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function render(string $templateKey, ?string $handoffKey): RenderedRefusal
    {
        $template = $this->config['templates'][$templateKey]
            ?? $this->config['templates']['no_confident_context']
            ?? 'I cannot answer that from Gulu University\'s published information.';

        $contact = $handoffKey === null ? null : ($this->config['contacts'][$handoffKey] ?? null);
        $office = $contact['office'] ?? null;

        $text = str_replace('{office}', $office ?? 'the relevant University office', $template);

        $missing = $contact === null
            || ($contact['email'] ?? null) === null && ($contact['telephone'] ?? null) === null
                && ($contact['url'] ?? null) === null;

        $details = [];
        foreach (['email', 'telephone', 'url'] as $field) {
            $value = $contact[$field] ?? null;
            if ($value !== null && $value !== '') {
                $details[$field] = $value;
            }
        }

        return new RenderedRefusal(
            text: $text,
            office: $office,
            contactDetails: $details,
            handoffMissing: $missing,
        );
    }
}
