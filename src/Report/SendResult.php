<?php

declare(strict_types=1);

namespace Arisultima\OverwatchAgent\Report;

/**
 * What became of one send: the server took the report (Accepted), refused
 * it for good (Rejected: a resend cannot succeed), or anything else (Keep:
 * the minutes wait for the next report).
 */
final readonly class SendResult
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const KEEP = 'keep';

    /**
     * @param  string  $kind  one of the constants above
     * @param  int|null  $status  the HTTP status; null when no response came
     * @param  string|null  $error  on Rejected, the server's first validation message;
     *                              on Keep without a status, the short class name thrown
     */
    private function __construct(
        public string $kind,
        public ?int $status,
        public ?string $error,
    ) {}

    public static function accepted(int $status): self
    {
        return new self(self::ACCEPTED, $status, null);
    }

    public static function rejected(int $status, ?string $error): self
    {
        return new self(self::REJECTED, $status, $error);
    }

    public static function keep(?int $status = null, ?string $error = null): self
    {
        return new self(self::KEEP, $status, $error);
    }

    public function isAccepted(): bool
    {
        return $this->kind === self::ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->kind === self::REJECTED;
    }

    public function isKeep(): bool
    {
        return $this->kind === self::KEEP;
    }
}
