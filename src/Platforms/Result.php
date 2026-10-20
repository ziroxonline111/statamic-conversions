<?php

namespace KareliaLabs\Conversions\Platforms;

/** What happened when one conversion was sent to one platform. */
final class Result
{
    public function __construct(
        public string $status,
        public ?string $message = null,
        public ?int $code = null,
    ) {}

    public static function sent(?string $message = null, ?int $code = null): self
    {
        return new self('sent', $message, $code);
    }

    public static function failed(string $message, ?int $code = null): self
    {
        return new self('failed', $message, $code);
    }

    public static function skipped(string $message): self
    {
        return new self('skipped', $message);
    }

    public function ok(): bool
    {
        return $this->status === 'sent';
    }

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'message' => $this->message,
            'code' => $this->code,
        ], fn ($v) => $v !== null);
    }
}
