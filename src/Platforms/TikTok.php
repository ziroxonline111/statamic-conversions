<?php

namespace KareliaLabs\Conversions\Platforms;

use Illuminate\Http\Client\Response;
use KareliaLabs\Conversions\Conversion;
use KareliaLabs\Conversions\Settings;
use KareliaLabs\Conversions\UserData;

/** TikTok Events API: business-api.tiktok.com/open_api/v1.3/event/track/ */
class TikTok extends Platform
{
    public function key(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok';
    }

    public function configured(): bool
    {
        return ! $this->missing();
    }

    public function missing(): array
    {
        return array_keys(array_filter([
            __('Pixel code') => ! Settings::tiktokPixelCode(),
            'KARELIA_CONVERSIONS_TIKTOK_ACCESS_TOKEN' => ! Settings::tiktokAccessToken(),
        ]));
    }

    public function payload(Conversion $conversion, bool $test = false): array
    {
        $u = $conversion->user;

        $user = array_filter([
            'email' => ($e = UserData::email($u['email'] ?? null)) ? UserData::hash($e) : null,
            'phone' => ($p = UserData::phoneE164($u['phone'] ?? null)) ? UserData::hash($p) : null,
            'external_id' => isset($u['external_id']) ? UserData::hash($u['external_id']) : null,
            'ttclid' => $conversion->attribution['ttclid'] ?? null,
            'ttp' => $conversion->cookies['_ttp'] ?? null,
            'ip' => $conversion->ip,
            'user_agent' => $conversion->userAgent,
        ]);

        $properties = array_filter([
            'value' => $conversion->value,
            'currency' => $conversion->value !== null ? $conversion->currency() : null,
            'order_id' => $conversion->orderId,
            'content_type' => $conversion->items ? 'product' : null,
            'contents' => $conversion->items ? array_values(array_map(fn ($i) => array_filter([
                'content_id' => (string) $i['id'],
                'content_name' => $i['name'] ?? null,
                'quantity' => (int) ($i['quantity'] ?? 1),
                'price' => isset($i['price']) ? (float) $i['price'] : null,
            ], fn ($v) => $v !== null), $conversion->items)) : null,
        ], fn ($v) => $v !== null);

        $payload = [
            'event_source' => 'web',
            'event_source_id' => Settings::tiktokPixelCode(),
            'data' => [array_filter([
                'event' => $conversion->nameFor('tiktok'),
                'event_time' => $conversion->time,
                'event_id' => $conversion->id,
                'user' => $user,
                'page' => array_filter(['url' => $conversion->url, 'referrer' => $conversion->referrer]),
                'properties' => $properties ?: null,
            ], fn ($v) => $v !== null && $v !== [])],
        ];

        if ($code = Settings::tiktokTestEventCode()) {
            $payload['test_event_code'] = $code;
        }

        return $payload;
    }

    protected function post(array $payload, bool $test): Response
    {
        return $this->http()
            ->withHeaders(['Access-Token' => (string) Settings::tiktokAccessToken()])
            ->post(Settings::endpoint('tiktok', 'https://business-api.tiktok.com').'/open_api/v1.3/event/track/', $payload);
    }

    protected function interpret(Response $response, bool $test): Result
    {
        if ($response->successful() && (int) $response->json('code', -1) === 0) {
            return Result::sent($response->json('message'), $response->status());
        }

        return Result::failed($this->errorMessage($response, ['message']), $response->status());
    }
}
