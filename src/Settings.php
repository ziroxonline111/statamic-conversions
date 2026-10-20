<?php

namespace KareliaLabs\Conversions;

use Statamic\Facades\Addon;

/**
 * Settings from the Control Panel (Tools > Addons > Karelia Conversions) win
 * over config/statamic/conversions.php. Secrets only ever come from config.
 */
final class Settings
{
    public const PACKAGE = 'karelialabs/statamic-conversions';

    public static function enabled(): bool
    {
        return (bool) self::get('enabled', config('statamic.conversions.enabled', true));
    }

    /** A platform is on when it is configured and not switched off in the settings. */
    public static function platformOn(string $platform): bool
    {
        return (bool) self::get("{$platform}_enabled", true);
    }

    public static function metaPixelId(): ?string
    {
        return self::string('meta_pixel_id', 'meta.pixel_id');
    }

    public static function metaAccessToken(): ?string
    {
        return self::secret('meta.access_token');
    }

    public static function metaTestEventCode(): ?string
    {
        return self::string('meta_test_event_code', 'meta.test_event_code');
    }

    public static function metaApiVersion(): string
    {
        return (string) config('statamic.conversions.meta.api_version', 'v26.0');
    }

    /** Base URL for a platform: the official one, a regional one (GA4 in the EU) or a proxy. */
    public static function endpoint(string $platform, string $default): string
    {
        return rtrim((string) (config("statamic.conversions.{$platform}.endpoint") ?: $default), '/');
    }

    public static function ga4MeasurementId(): ?string
    {
        return self::string('ga4_measurement_id', 'ga4.measurement_id');
    }

    public static function ga4ApiSecret(): ?string
    {
        return self::secret('ga4.api_secret');
    }

    public static function tiktokPixelCode(): ?string
    {
        return self::string('tiktok_pixel_code', 'tiktok.pixel_code');
    }

    public static function tiktokAccessToken(): ?string
    {
        return self::secret('tiktok.access_token');
    }

    public static function tiktokTestEventCode(): ?string
    {
        return self::string('tiktok_test_event_code', 'tiktok.test_event_code');
    }

    public static function consentMode(): string
    {
        $mode = (string) self::get('consent_mode', config('statamic.conversions.consent.mode', 'cookie'));

        return in_array($mode, ['cookie', 'always'], true) ? $mode : 'cookie';
    }

    /**
     * The cookie banner: a preset, "own" (KareliaConversions.consent), or "custom".
     * Older settings with their own cookie name count as "custom".
     */
    public static function consentBanner(): string
    {
        $banner = (string) self::get('consent_banner', config('statamic.conversions.consent.banner', ''));

        if ($banner === '') {
            $cookie = (string) self::get('consent_cookie', '');
            $banner = $cookie !== '' && $cookie !== config('statamic.conversions.consent.cookie', 'karelia_conversions_consent') ? 'custom' : 'own';
        }

        return in_array($banner, ['own', 'cookiebot', 'cookieyes', 'complianz', 'custom'], true) ? $banner : 'own';
    }

    public static function consentCookie(): string
    {
        return (string) (self::get('consent_cookie') ?: config('statamic.conversions.consent.cookie', 'karelia_conversions_consent'));
    }

    public static function consentValue(): string
    {
        $value = self::get('consent_value');

        return (string) ($value !== null && $value !== '' ? $value : config('statamic.conversions.consent.value', '1'));
    }

    public static function attributionCookie(): string
    {
        return (string) config('statamic.conversions.attribution.cookie', 'karelia_conversions_attr');
    }

    public static function attributionDays(): int
    {
        return max(1, (int) config('statamic.conversions.attribution.days', 90));
    }

    /** @return array<int, array{form: string, event: string, custom_name?: string, value?: float|null, currency?: string|null}> */
    public static function forms(): array
    {
        $rows = self::get('forms');

        if (! is_array($rows)) {
            $rows = (array) config('statamic.conversions.forms', []);
        }

        return collect($rows)
            ->map(function ($row) {
                $form = is_array($row['form'] ?? null) ? ($row['form'][0] ?? null) : ($row['form'] ?? null);

                return $form ? [
                    'form' => (string) $form,
                    'event' => in_array($row['event'] ?? 'lead', Conversion::EVENTS, true) ? ($row['event'] ?? 'lead') : 'lead',
                    'custom_name' => $row['custom_name'] ?? null,
                    'value' => is_numeric($row['value'] ?? null) ? (float) $row['value'] : null,
                    'currency' => ($row['currency'] ?? null) ?: null,
                ] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    public static function formRule(string $handle): ?array
    {
        return collect(self::forms())->firstWhere('form', $handle);
    }

    public static function trackRegistrations(): bool
    {
        return (bool) self::get('registrations', config('statamic.conversions.registrations', true));
    }

    public static function trackOrders(): bool
    {
        return (bool) self::get('orders', config('statamic.conversions.orders', true));
    }

    public static function queue(): ?string
    {
        return config('statamic.conversions.queue') ?: null;
    }

    public static function timeout(): int
    {
        return max(1, (int) config('statamic.conversions.timeout', 5));
    }

    public static function logDays(): int
    {
        return max(1, (int) config('statamic.conversions.log_days', 30));
    }

    /**
     * A value saved in the Control Panel, or the default. Blueprint defaults
     * are ignored on purpose, so an unsaved setting never overrides config.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $settings = Addon::get(self::PACKAGE)?->settings();

            if (! $settings || ! array_key_exists($key, $settings->raw())) {
                return $default;
            }

            $value = $settings->get($key);
        } catch (\Throwable) {
            return $default;
        }

        return $value ?? $default;
    }

    private static function string(string $setting, string $config): ?string
    {
        $value = trim((string) (self::get($setting) ?: config("statamic.conversions.{$config}")));

        return $value !== '' ? $value : null;
    }

    private static function secret(string $config): ?string
    {
        $value = trim((string) config("statamic.conversions.{$config}"));

        return $value !== '' ? $value : null;
    }

    public static function blueprint(): array
    {
        $event = fn () => [
            'type' => 'select',
            'display' => __('Send as'),
            'options' => [
                'lead' => __('Lead'),
                'contact' => __('Contact'),
                'sign_up' => __('Sign-up'),
                'custom' => __('Custom event'),
            ],
            'default' => 'lead',
            'width' => 50,
        ];

        $fromEnv = fn (string $key) => ($v = config("statamic.conversions.{$key}")) ? __('From .env: :value', ['value' => $v]) : null;

        return [
            'tabs' => [
                'platforms' => [
                    'display' => __('Platforms'),
                    'sections' => [
                        [
                            'display' => __('Meta (Facebook and Instagram)'),
                            'instructions' => __('Put the access token in your .env file as KARELIA_CONVERSIONS_META_ACCESS_TOKEN, never here: these settings are saved in resources/addons and usually end up in git.'),
                            'fields' => [
                                ['handle' => 'meta_enabled', 'field' => ['type' => 'toggle', 'display' => __('Send to Meta'), 'default' => true, 'width' => 33]],
                                ['handle' => 'meta_pixel_id', 'field' => ['type' => 'text', 'display' => __('Pixel ID (dataset ID)'), 'placeholder' => $fromEnv('meta.pixel_id'), 'width' => 33, 'validate' => 'nullable|regex:/^\d{6,20}$/']],
                                ['handle' => 'meta_test_event_code', 'field' => ['type' => 'text', 'display' => __('Test event code'), 'instructions' => __('From Events Manager > Test events. Clear it when you are done testing.'), 'width' => 33]],
                            ],
                        ],
                        [
                            'display' => __('Google Analytics 4'),
                            'instructions' => __('Put the Measurement Protocol API secret in your .env file as KARELIA_CONVERSIONS_GA4_API_SECRET. To count these conversions in Google Ads, mark the events as key events in GA4 and import them into Google Ads.'),
                            'fields' => [
                                ['handle' => 'ga4_enabled', 'field' => ['type' => 'toggle', 'display' => __('Send to GA4'), 'default' => true, 'width' => 33]],
                                ['handle' => 'ga4_measurement_id', 'field' => ['type' => 'text', 'display' => __('Measurement ID'), 'instructions' => __('Starts with G-'), 'placeholder' => $fromEnv('ga4.measurement_id'), 'width' => 66, 'validate' => 'nullable|regex:/^G-[A-Z0-9]+$/']],
                            ],
                        ],
                        [
                            'display' => __('TikTok'),
                            'instructions' => __('Put the Events API access token in your .env file as KARELIA_CONVERSIONS_TIKTOK_ACCESS_TOKEN.'),
                            'fields' => [
                                ['handle' => 'tiktok_enabled', 'field' => ['type' => 'toggle', 'display' => __('Send to TikTok'), 'default' => true, 'width' => 33]],
                                ['handle' => 'tiktok_pixel_code', 'field' => ['type' => 'text', 'display' => __('Pixel code'), 'placeholder' => $fromEnv('tiktok.pixel_code'), 'width' => 33]],
                                ['handle' => 'tiktok_test_event_code', 'field' => ['type' => 'text', 'display' => __('Test event code'), 'width' => 33]],
                            ],
                        ],
                    ],
                ],
                'events' => [
                    'display' => __('Events'),
                    'sections' => [
                        [
                            'display' => __('Forms'),
                            'instructions' => __('Each form you add here sends a conversion when a visitor submits it. Email, phone and name fields are found automatically and only ever sent hashed.'),
                            'fields' => [
                                [
                                    'handle' => 'forms',
                                    'field' => [
                                        'type' => 'grid',
                                        'display' => __('Forms that count as conversions'),
                                        'mode' => 'table',
                                        'add_row' => __('Add form'),
                                        'fields' => [
                                            ['handle' => 'form', 'field' => ['type' => 'form', 'display' => __('Form'), 'max_items' => 1, 'mode' => 'select', 'width' => 50, 'validate' => 'required']],
                                            ['handle' => 'event', 'field' => $event()],
                                            ['handle' => 'custom_name', 'field' => ['type' => 'text', 'display' => __('Custom event name'), 'instructions' => __('e.g. Book a demo'), 'width' => 100, 'if' => ['event' => 'custom']]],
                                            ['handle' => 'value', 'field' => ['type' => 'float', 'display' => __('Value'), 'instructions' => __('What a lead is worth to you, optional.'), 'width' => 50]],
                                            ['handle' => 'currency', 'field' => ['type' => 'text', 'display' => __('Currency'), 'instructions' => __('e.g. EUR'), 'width' => 50]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'display' => __('Other events'),
                            'fields' => [
                                ['handle' => 'registrations', 'field' => ['type' => 'toggle', 'display' => __('Sign-ups'), 'instructions' => __('Send a sign-up when a visitor registers with the user:register_form tag.'), 'default' => true, 'width' => 50]],
                                ['handle' => 'orders', 'field' => ['type' => 'toggle', 'display' => __('Cargo orders'), 'instructions' => __('Send a purchase with value and products when a Cargo order is paid.'), 'default' => true, 'width' => 50]],
                                ['handle' => 'currency', 'field' => ['type' => 'text', 'display' => __('Default currency'), 'instructions' => __('ISO code, e.g. USD or EUR. Used when a form row has a value but no currency.'), 'default' => config('statamic.conversions.currency', 'USD'), 'width' => 50, 'validate' => 'nullable|size:3']],
                            ],
                        ],
                    ],
                ],
                'consent' => [
                    'display' => __('Consent'),
                    'sections' => [
                        [
                            'display' => __('Consent'),
                            'instructions' => __('Add {{ conversions:script }} to your layout. Your cookie banner then calls KareliaConversions.consent(true) when the visitor accepts marketing cookies, or you point the addon at the cookie your banner already sets.'),
                            'fields' => [
                                [
                                    'handle' => 'consent_mode',
                                    'field' => [
                                        'type' => 'button_group',
                                        'display' => __('Send conversions'),
                                        'options' => [
                                            'cookie' => __('Only after consent'),
                                            'always' => __('Always (no consent needed)'),
                                        ],
                                        'default' => 'cookie',
                                    ],
                                ],
                                [
                                    'handle' => 'consent_banner',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Cookie banner'),
                                        'instructions' => __('Which cookie says that the visitor accepted marketing cookies. The ready-made choices check the exact value for marketing, so a visitor who declined is never counted.'),
                                        'options' => [
                                            'own' => __('My banner calls KareliaConversions.consent(true)'),
                                            'cookiebot' => __('Cookiebot (CookieConsent: marketing:true)'),
                                            'cookieyes' => __('CookieYes (cookieyes-consent: advertisement:yes)'),
                                            'complianz' => __('Complianz (cmplz_marketing = allow)'),
                                            'custom' => __('Another banner: choose the cookie and value'),
                                        ],
                                        'default' => 'own',
                                        'if' => ['consent_mode' => 'cookie'],
                                    ],
                                ],
                                ['handle' => 'consent_cookie', 'field' => ['type' => 'text', 'display' => __('Consent cookie'), 'instructions' => __('The name of the cookie your banner sets.'), 'width' => 50, 'if' => ['consent_mode' => 'cookie', 'consent_banner' => 'custom']]],
                                ['handle' => 'consent_value', 'field' => ['type' => 'text', 'display' => __('Accepted value'), 'instructions' => __('Either the exact cookie value, e.g. allow, or a key and value, e.g. marketing:true. A key and value also match "marketing":true and marketing=true, and never marketing:false. "Contains" is not used.'), 'width' => 50, 'if' => ['consent_mode' => 'cookie', 'consent_banner' => 'custom']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
