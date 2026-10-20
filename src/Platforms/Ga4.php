<?php

namespace KareliaLabs\Conversions\Platforms;

use Illuminate\Http\Client\Response;
use KareliaLabs\Conversions\Attribution;
use KareliaLabs\Conversions\Conversion;
use KareliaLabs\Conversions\Settings;
use KareliaLabs\Conversions\UserData;

/**
 * Google Analytics 4 Measurement Protocol. To count the conversions in Google
 * Ads, mark the events as key events in GA4 and import them into Google Ads.
 */
class Ga4 extends Platform
{
    public function key(): string
    {
        return 'ga4';
    }

    public function label(): string
    {
        return 'Google Analytics 4';
    }

    public function configured(): bool
    {
        return ! $this->missing();
    }

    public function missing(): array
    {
        return array_keys(array_filter([
            __('Measurement ID') => ! Settings::ga4MeasurementId(),
            'KARELIA_CONVERSIONS_GA4_API_SECRET' => ! Settings::ga4ApiSecret(),
        ]));
    }

    public function endpoint(bool $debug = false): string
    {
        return Settings::endpoint('ga4', 'https://www.google-analytics.com').'/'.($debug ? 'debug/' : '').'mp/collect';
    }

    public function payload(Conversion $conversion, bool $test = false): array
    {
        $u = $conversion->user;

        $params = array_filter([
            'value' => $conversion->value,
            'currency' => $conversion->value !== null ? $conversion->currency() : null,
            'transaction_id' => $conversion->orderId,
            'form_name' => ($conversion->source['type'] ?? null) === 'form' ? ($conversion->source['label'] ?? null) : null,
            'lead_source' => in_array($conversion->event, ['lead', 'contact'], true) ? Attribution::summary($conversion->attribution) : null,
            'page_location' => $conversion->url,
            'items' => $conversion->items ? array_values(array_map(fn ($i) => array_filter([
                'item_id' => (string) $i['id'],
                'item_name' => $i['name'] ?? (string) $i['id'],
                'quantity' => (int) ($i['quantity'] ?? 1),
                'price' => isset($i['price']) ? (float) $i['price'] : null,
            ], fn ($v) => $v !== null), $conversion->items)) : null,
            // Shows the hit in DebugView while testing.
            'debug_mode' => $test ? true : null,
            'engagement_time_msec' => 1,
        ], fn ($v) => $v !== null);

        // A visitor without a _ga cookie still gets a valid, random client ID.
        $clientId = Attribution::gaClientId($conversion)
            ?? (crc32($conversion->id) % 2147483647).'.'.$conversion->time;

        $userData = array_filter([
            'sha256_email_address' => ($e = UserData::googleEmail($u['email'] ?? null)) ? [UserData::hash($e)] : null,
            'sha256_phone_number' => ($p = UserData::phoneE164($u['phone'] ?? null)) ? [UserData::hash($p)] : null,
            'address' => ($address = array_filter([
                'sha256_first_name' => UserData::hash(UserData::name($u['first_name'] ?? null)),
                'sha256_last_name' => UserData::hash(UserData::name($u['last_name'] ?? null)),
                'city' => UserData::name($u['city'] ?? null),
                'postal_code' => UserData::plain($u['zip'] ?? null),
                'country' => ($c = UserData::countryCode($u['country'] ?? null)) ? strtoupper($c) : null,
            ])) ? [$address] : null,
        ]);

        return array_filter([
            'client_id' => $clientId,
            'timestamp_micros' => $conversion->time * 1000000,
            'non_personalized_ads' => $conversion->consent ? null : true,
            'consent' => [
                'ad_user_data' => $conversion->consent ? 'GRANTED' : 'DENIED',
                'ad_personalization' => $conversion->consent ? 'GRANTED' : 'DENIED',
            ],
            'user_data' => $userData ?: null,
            'events' => [[
                'name' => $conversion->nameFor('ga4'),
                'params' => $params,
            ]],
        ], fn ($v) => $v !== null);
    }

    protected function post(array $payload, bool $test): Response
    {
        $query = http_build_query([
            'measurement_id' => Settings::ga4MeasurementId(),
            'api_secret' => Settings::ga4ApiSecret(),
        ]);

        return $this->http()->post($this->endpoint($test).'?'.$query, $payload);
    }

    protected function interpret(Response $response, bool $test): Result
    {
        if ($test) {
            $messages = (array) $response->json('validationMessages', []);

            if ($response->successful() && ! $messages) {
                return Result::sent(__('Valid, no problems found'), $response->status());
            }

            return Result::failed($messages[0]['description'] ?? $this->errorMessage($response, []), $response->status());
        }

        // The live endpoint always answers 2xx without a body; problems only show in the debug endpoint.
        return $response->successful()
            ? Result::sent(null, $response->status())
            : Result::failed($this->errorMessage($response, []), $response->status());
    }
}
