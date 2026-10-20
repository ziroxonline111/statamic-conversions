<?php

namespace KareliaLabs\Conversions\Platforms;

use Illuminate\Http\Client\Response;
use KareliaLabs\Conversions\Attribution;
use KareliaLabs\Conversions\Conversion;
use KareliaLabs\Conversions\Settings;
use KareliaLabs\Conversions\UserData;

/** Meta Conversions API: graph.facebook.com/{version}/{pixel id}/events */
class Meta extends Platform
{
    public function key(): string
    {
        return 'meta';
    }

    public function label(): string
    {
        return 'Meta';
    }

    public function configured(): bool
    {
        return ! $this->missing();
    }

    public function missing(): array
    {
        return array_keys(array_filter([
            __('Pixel ID') => ! Settings::metaPixelId(),
            'KARELIA_CONVERSIONS_META_ACCESS_TOKEN' => ! Settings::metaAccessToken(),
        ]));
    }

    public function endpoint(): string
    {
        return sprintf('%s/%s/%s/events', Settings::endpoint('meta', 'https://graph.facebook.com'), Settings::metaApiVersion(), Settings::metaPixelId());
    }

    public function payload(Conversion $conversion, bool $test = false): array
    {
        $u = $conversion->user;

        $userData = array_filter([
            'em' => $this->hashed(UserData::email($u['email'] ?? null)),
            'ph' => $this->hashed(UserData::phoneDigits($u['phone'] ?? null)),
            'fn' => $this->hashed(UserData::name($u['first_name'] ?? null)),
            'ln' => $this->hashed(UserData::name($u['last_name'] ?? null)),
            'ct' => $this->hashed(UserData::plain($u['city'] ?? null)),
            'zp' => $this->hashed(UserData::plain($u['zip'] ?? null)),
            'country' => $this->hashed(UserData::countryCode($u['country'] ?? null)),
            'external_id' => $this->hashed($u['external_id'] ?? null),
            'client_ip_address' => $conversion->ip,
            'client_user_agent' => $conversion->userAgent,
            'fbc' => Attribution::fbc($conversion),
            'fbp' => $conversion->cookies['_fbp'] ?? null,
        ]);

        $customData = array_filter([
            'value' => $conversion->value,
            'currency' => $conversion->value !== null ? $conversion->currency() : null,
            'order_id' => $conversion->orderId,
            'content_name' => $conversion->source['label'] ?? null,
            'content_ids' => $conversion->items ? array_values(array_map(fn ($i) => (string) $i['id'], $conversion->items)) : null,
            'contents' => $conversion->items ? array_values(array_map(fn ($i) => array_filter([
                'id' => (string) $i['id'],
                'quantity' => (int) ($i['quantity'] ?? 1),
                'item_price' => isset($i['price']) ? (float) $i['price'] : null,
            ], fn ($v) => $v !== null), $conversion->items)) : null,
            'content_type' => $conversion->items ? 'product' : null,
        ], fn ($v) => $v !== null);

        $event = array_filter([
            'event_name' => $conversion->nameFor('meta'),
            'event_time' => $conversion->time,
            'event_id' => $conversion->id,
            'action_source' => 'website',
            'event_source_url' => $conversion->url,
            'user_data' => $userData,
            'custom_data' => $customData ?: null,
        ], fn ($v) => $v !== null);

        $payload = ['data' => [$event]];

        if ($code = Settings::metaTestEventCode()) {
            $payload['test_event_code'] = $code;
        }

        return $payload;
    }

    protected function post(array $payload, bool $test): Response
    {
        return $this->http()->post($this->endpoint().'?access_token='.urlencode((string) Settings::metaAccessToken()), $payload);
    }

    protected function interpret(Response $response, bool $test): Result
    {
        if ($response->successful() && (int) $response->json('events_received') > 0) {
            return Result::sent(__(':count received', ['count' => $response->json('events_received')]), $response->status());
        }

        return Result::failed($this->errorMessage($response, ['error.error_user_msg', 'error.message']), $response->status());
    }

    private function hashed(?string $value): ?array
    {
        return $value !== null ? [UserData::hash($value)] : null;
    }
}
