<?php
declare(strict_types=1);
namespace SpiceMama\Services;
use PDO;
use SpiceMama\Http\ApiException;
use SpiceMama\Support\{Clock, Db};

/**
 * Persistent login throttling with separate account and client buckets.
 * Bucket keys are HMACs; raw emails and IP addresses are never stored.
 * Attempts are counted before password verification under row locks, so
 * concurrent requests cannot exceed the limit.
 */
final class LoginThrottle
{
    /** Provisional defaults pending owner security review: [attempts, window s, block s]. */
    public const DEFAULTS = ['account' => [5, 900, 900], 'client' => [20, 900, 900]];

    public function __construct(private readonly PDO $db, private readonly string $key, private readonly Clock $clock, private readonly array $limits = self::DEFAULTS)
    {
        if (strlen($key) < 32) {
            throw new ApiException(503, 'unavailable', 'Admin service is not configured.');
        }
    }

    public function attempt(string $email, string $clientIp): void
    {
        $buckets = $this->buckets($email, $clientIp);
        // Commit counters before throwing so blocks persist.
        $retry = Db::transaction($this->db, function () use ($buckets): int {
            $now = $this->clock->now();
            $rows = [];
            foreach ($buckets as $key => $type) {
                $this->db->prepare('INSERT INTO login_throttles(bucket_key,attempts,window_started_at,blocked_until,updated_at) VALUES(?,0,?,NULL,?) ON DUPLICATE KEY UPDATE bucket_key=bucket_key')
                    ->execute([$key, $this->clock->db(), $this->clock->db()]);
                $select = $this->db->prepare('SELECT attempts,window_started_at,blocked_until FROM login_throttles WHERE bucket_key=? FOR UPDATE');
                $select->execute([$key]);
                $rows[$key] = $select->fetch();
            }
            $retry = 0;
            foreach ($rows as $row) {
                if ($row['blocked_until'] !== null && Clock::parse($row['blocked_until']) > $now) {
                    $retry = max($retry, Clock::parse($row['blocked_until'])->getTimestamp() - $now->getTimestamp());
                }
            }
            if ($retry > 0) {
                return $retry;
            }
            foreach ($rows as $key => $row) {
                [$max, $window, $block] = $this->limits[$buckets[$key]];
                $started = Clock::parse($row['window_started_at']);
                $attempts = (int) $row['attempts'];
                if ($row['blocked_until'] !== null || $now->getTimestamp() - $started->getTimestamp() >= $window) {
                    [$attempts, $started] = [0, $now];
                }
                ++$attempts;
                $blocked = null;
                if ($attempts > $max) {
                    $blocked = $now->modify("+$block seconds");
                    $retry = max($retry, $block);
                }
                $this->db->prepare('UPDATE login_throttles SET attempts=?,window_started_at=?,blocked_until=?,updated_at=? WHERE bucket_key=?')
                    ->execute([$attempts, $started->format('Y-m-d H:i:s.u'), $blocked?->format('Y-m-d H:i:s.u'), $this->clock->db(), $key]);
            }
            return $retry;
        });
        if ($retry > 0) {
            throw new ApiException(429, 'rate_limited', 'Too many sign-in attempts. Try again later.', [], ['Retry-After' => (string) max(1, $retry)]);
        }
    }

    /** Successful sign-in clears the account bucket; the client bucket keeps counting. */
    public function reset(string $email): void
    {
        $this->db->prepare('UPDATE login_throttles SET attempts=0,blocked_until=NULL,updated_at=? WHERE bucket_key=?')
            ->execute([$this->clock->db(), $this->bucket('account', $email)]);
    }

    /** Sorted keys give every request the same lock order (no deadlocks between buckets). */
    private function buckets(string $email, string $clientIp): array
    {
        $buckets = [$this->bucket('account', $email) => 'account', $this->bucket('client', $clientIp) => 'client'];
        ksort($buckets);
        return $buckets;
    }

    private function bucket(string $type, string $value): string
    {
        return hash_hmac('sha256', $type . ':' . $value, $this->key);
    }
}
