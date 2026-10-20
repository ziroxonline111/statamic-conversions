<?php

namespace KareliaLabs\Conversions\Platforms;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use KareliaLabs\Conversions\Conversion;
use KareliaLabs\Conversions\Settings;

abstract class Platform
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** True when the IDs and the secret are in place. */
    abstract public function configured(): bool;

    /** What is missing, for the Control Panel. */
    abstract public function missing(): array;

    /** The request body, without secrets. Public so it can be tested and shown. */
    abstract public function payload(Conversion $conversion, bool $test = false): array;

    abstract protected function post(array $payload, bool $test): Response;

    abstract protected function interpret(Response $response, bool $test): Result;

    public function on(): bool
    {
        return Settings::platformOn($this->key()) && $this->configured();
    }

    public function send(Conversion $conversion, bool $test = false): Result
    {
        if (! $this->configured()) {
            return Result::skipped(__('Not set up: :missing', ['missing' => implode(', ', $this->missing())]));
        }

        try {
            $response = $this->post($this->payload($conversion, $test), $test);
        } catch (\Throwable $e) {
            return Result::failed(Str::limit($e->getMessage(), 300));
        }

        return $this->interpret($response, $test);
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(Settings::timeout())
            ->connectTimeout(Settings::timeout())
            ->acceptJson()
            ->withUserAgent('KareliaConversions/1.0 (+https://karelialabs.com)');
    }

    protected function errorMessage(Response $response, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($response->json(), $path);

            if (is_string($value) && $value !== '') {
                return Str::limit($value, 300);
            }
        }

        return Str::limit(trim($response->body()) ?: __('HTTP :status', ['status' => $response->status()]), 300);
    }
}
