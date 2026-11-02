<?php
declare(strict_types=1);
namespace SpiceMama\Support;

/** UTC time source; tests inject a fixed or advancing closure. */
final class Clock
{
    public function __construct(private readonly ?\Closure $source = null) {}

    public function now(): \DateTimeImmutable
    {
        return $this->source === null ? new \DateTimeImmutable('now', new \DateTimeZone('UTC')) : ($this->source)();
    }

    /** DATETIME(6) literal in UTC. */
    public function db(): string
    {
        return $this->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public static function parse(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
