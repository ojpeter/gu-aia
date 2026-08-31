<?php

declare(strict_types=1);

namespace GuAia\Answering;

/**
 * The generation boundary. requirements.md Section 3, Section 18 open question 1.
 *
 * Everything the project does not want to decide yet lives behind this
 * interface: hosted API versus self-hosted open-weight model, which provider,
 * which model. "Build behind the Generator interface so the decision is
 * reversible."
 *
 * Note what is NOT here, deliberately (OWASP LLM "excessive agency"): no tools,
 * no function calling, no ability to act. The generator takes a system prompt
 * and a user turn built from retrieved context and returns text. It cannot fetch
 * anything, write anything, or reach the database. Nothing should be added here
 * "for later" — an interface is the easiest place in a codebase to grant a
 * capability by accident.
 */
interface Generator
{
    /**
     * @param string $systemPrompt versioned, from config/prompts/ (Section 8)
     * @param string $userContent  the question plus the retrieved context,
     *                             already delimited and labelled as data (INV-6)
     *
     * @throws GenerationTimedOut when the call exceeds its budget; the caller
     *                            must fall back to retrieval-only rather than
     *                            surfacing an error page (Section 11)
     */
    public function generate(string $systemPrompt, string $userContent): GenerationResult;

    /** Model identifier recorded on every logged interaction (Section 13). */
    public function modelName(): string;
}
