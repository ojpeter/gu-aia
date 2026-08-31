<?php

declare(strict_types=1);

namespace GuAia\Answering;

use RuntimeException;

/**
 * Generation exceeded its timeout.
 *
 * Section 11: "Generation timeout falls back to retrieval-only results rather
 * than an error page." This is therefore an expected control-flow signal, not a
 * crash — the caller catches it, serves links and extracts, and records
 * degraded=1 on the interaction (INV-8).
 */
final class GenerationTimedOut extends RuntimeException
{
}
