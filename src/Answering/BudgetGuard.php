<?php

declare(strict_types=1);

namespace GuAia\Answering;

/**
 * The spend ceiling, checked before every generation call. INV-8.
 *
 * "On reaching the configured monthly ceiling the system degrades to
 *  retrieval-only — links and extracts, no generation — and alerts. It never
 *  overspends and never silently fails."
 *
 * FAILS CLOSED ON AN UNSET CEILING, and that is not pedantry.
 *
 * config/budget.php ships with `monthly_ceiling => null` because the value is
 * set by the Chief, ICT Services before launch (Section 18, open question 4).
 * Null means "nobody has authorised any spend", and the only reading of that
 * which cannot end in an unauthorised bill is: do not spend. So a null ceiling
 * degrades to retrieval-only, exactly as an exhausted one does.
 *
 * The tempting alternative — treat null as unlimited so development is
 * convenient — is how a system reaches production having never once exercised
 * the path it takes when the money runs out, in the middle of an admissions
 * cycle. Degraded mode is a tested code path here because it is the DEFAULT
 * path until somebody sets a number.
 */
final class BudgetGuard
{
    private ?string $reason = null;

    public function __construct(
        private readonly ?float $monthlyCeiling,
        private readonly float $spendThisPeriod = 0.0,
        private readonly float $alertThreshold = 0.8,
    ) {
    }

    public function mayGenerate(): bool
    {
        if ($this->monthlyCeiling === null) {
            $this->reason = 'budget_ceiling_not_configured';

            return false;
        }

        if ($this->monthlyCeiling <= 0.0) {
            $this->reason = 'budget_ceiling_is_zero';

            return false;
        }

        if ($this->spendThisPeriod >= $this->monthlyCeiling) {
            $this->reason = 'budget_exhausted';

            return false;
        }

        $this->reason = null;

        return true;
    }

    /** Why generation was refused, for the interaction log and the alert. */
    public function reason(): string
    {
        return $this->reason ?? 'budget_ok';
    }

    /** Section 11: alert at 80% of the ceiling, once. */
    public function shouldAlert(): bool
    {
        if ($this->monthlyCeiling === null || $this->monthlyCeiling <= 0.0) {
            return false;
        }

        return ($this->spendThisPeriod / $this->monthlyCeiling) >= $this->alertThreshold;
    }

    public function fractionUsed(): ?float
    {
        if ($this->monthlyCeiling === null || $this->monthlyCeiling <= 0.0) {
            return null;
        }

        return $this->spendThisPeriod / $this->monthlyCeiling;
    }
}
