<?php

/*
|--------------------------------------------------------------------------
| Karelia Conversions
|--------------------------------------------------------------------------
|
| Secrets (access tokens and API secrets) belong in your .env file, never in
| the Control Panel settings: those are saved to resources/addons and usually
| end up in git. Everything else can be changed under Tools > Addons >
| Karelia Conversions, and the Control Panel settings win over this file.
|
*/

return [

    'enabled' => env('KARELIA_CONVERSIONS_ENABLED', true),

    'meta' => [
        'pixel_id' => env('KARELIA_CONVERSIONS_META_PIXEL_ID'),
        'access_token' => env('KARELIA_CONVERSIONS_META_ACCESS_TOKEN'),
        'test_event_code' => env('KARELIA_CONVERSIONS_META_TEST_EVENT_CODE'),
        'api_version' => env('KARELIA_CONVERSIONS_META_API_VERSION', 'v26.0'),
        'endpoint' => env('KARELIA_CONVERSIONS_META_ENDPOINT', 'https://graph.facebook.com'),
    ],

    'ga4' => [
        'measurement_id' => env('KARELIA_CONVERSIONS_GA4_MEASUREMENT_ID'),
        'api_secret' => env('KARELIA_CONVERSIONS_GA4_API_SECRET'),
        // EU sites can use https://region1.google-analytics.com to keep collection in the EU.
        'endpoint' => env('KARELIA_CONVERSIONS_GA4_ENDPOINT', 'https://www.google-analytics.com'),
    ],

    'tiktok' => [
        'pixel_code' => env('KARELIA_CONVERSIONS_TIKTOK_PIXEL_CODE'),
        'access_token' => env('KARELIA_CONVERSIONS_TIKTOK_ACCESS_TOKEN'),
        'test_event_code' => env('KARELIA_CONVERSIONS_TIKTOK_TEST_EVENT_CODE'),
        'endpoint' => env('KARELIA_CONVERSIONS_TIKTOK_ENDPOINT', 'https://business-api.tiktok.com'),
    ],

    /*
    | Consent. "cookie": only send when the visitor's consent cookie says yes,
    | checked with an exact rule (a preset for your banner, or your own cookie
    | and value). The {{ conversions:script }} tag also gives any banner a
    | one-line call to set its own cookie. "always": send without asking, for sites where no consent is
    | needed. Click IDs and UTM tags are only stored after consent as well.
    */
    'consent' => [
        'mode' => env('KARELIA_CONVERSIONS_CONSENT', 'cookie'),
        // own (KareliaConversions.consent), cookiebot, cookieyes, complianz or custom.
        'banner' => env('KARELIA_CONVERSIONS_CONSENT_BANNER'),
        // For "custom": the cookie, and either its exact value or a pair such as marketing:true.
        'cookie' => 'karelia_conversions_consent',
        'value' => '1',
    ],

    'attribution' => [
        'cookie' => 'karelia_conversions_attr',
        'days' => 90,
    ],

    // Send on this queue connection. Null sends right after the response, so
    // visitors never wait for Meta, Google or TikTok.
    'queue' => env('KARELIA_CONVERSIONS_QUEUE'),

    'timeout' => 5,

    // How long the Control Panel log keeps sent events, in days.
    'log_days' => 30,

    'currency' => env('KARELIA_CONVERSIONS_CURRENCY', 'USD'),

];
