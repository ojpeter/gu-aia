<?php

declare(strict_types=1);

namespace GuAia\Safety;

use PDO;

/**
 * Fixed-window rate limiting, per IP and per session. requirements.md Section 11.
 *
 * "Per IP and per session, with a stated limit and a clear message on breach."
 *
 * TWO SCOPES, BECAUSE EACH ALONE IS WEAK
 *
 * Per-session alone is defeated by discarding the cookie. Per-IP alone punishes
 * a whole university computer lab, or a campus behind one NAT address — which in
 * Uganda is the common case, not the edge case, and getting it wrong would
 * throttle exactly the students this service is for. Both together let the
 * per-session limit be tight and the per-IP limit generous.
 *
 * VIOLATIONS ARE LOGGED, NEVER SILENT
 *
 * A 429 nobody records is a 429 nobody learns from. A spike in this table is
 * either abuse worth seeing or a limit set too low, and neither is visible if
 * the refusal is anonymous.
 *
 * The counter is keyed on (scope, bucket, window_start) with a unique index, so
 * incrementing is an upsert rather than a read-modify-write, and two concurrent
 * requests cannot both read 9 and both write 10.
 */
final class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $windowSeconds = 3600,
    ) {
    }

    /**
     * Records a hit and reports whether the caller is now over the limit.
     *
     * @param string $bucketKey ALREADY HASHED by IdentifierHasher. This class
     *   never sees a raw IP, so a limiter bug cannot leak one into a table that
     *   is not supposed to hold one.
     */
    public function hit(string $scope, string $bucketKey, int $limit, string $route): RateLimitDecision
    {
        $windowStart = $this->windowStart();

        $upsert = $this->pdo->prepare(
            'INSERT INTO rate_limits (scope, bucket_key, window_start, hit_count, last_hit_at)
             VALUES (:scope, :bucket_key, :window_start, 1, NOW())
             ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_hit_at = NOW()'
        );
        $upsert->execute([
            'scope' => $scope,
            'bucket_key' => $bucketKey,
            'window_start' => $windowStart,
        ]);

        $read = $this->pdo->prepare(
            'SELECT hit_count FROM rate_limits
              WHERE scope = :scope AND bucket_key = :bucket_key AND window_start = :window_start'
        );
        $read->execute([
            'scope' => $scope,
            'bucket_key' => $bucketKey,
            'window_start' => $windowStart,
        ]);

        $count = (int) $read->fetchColumn();
        $exceeded = $count > $limit;

        if ($exceeded) {
            $this->recordViolation($scope, $bucketKey, $route, $count, $limit);
        }

        return new RateLimitDecision(
            allowed: !$exceeded,
            hitCount: $count,
            limit: $limit,
            retryAfterSeconds: $exceeded ? $this->secondsUntilWindowEnds() : 0,
        );
    }

    private function recordViolation(string $scope, string $bucketKey, string $route, int $count, int $limit): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO rate_limit_violations (scope, bucket_key, route, hit_count, limit_value)
             VALUES (:scope, :bucket_key, :route, :hit_count, :limit_value)'
        );
        $statement->execute([
            'scope' => $scope,
            'bucket_key' => $bucketKey,
            'route' => $route,
            'hit_count' => $count,
            'limit_value' => $limit,
        ]);
    }

    private function windowStart(): string
    {
        $now = time();

        return date('Y-m-d H:i:s', $now - ($now % $this->windowSeconds));
    }

    private function secondsUntilWindowEnds(): int
    {
        $now = time();

        return $this->windowSeconds - ($now % $this->windowSeconds);
    }
}
