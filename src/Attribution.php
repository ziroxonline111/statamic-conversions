<?php

namespace KareliaLabs\Conversions;

use Illuminate\Http\Request;

/**
 * Where the visitor came from. The {{ conversions:script }} tag stores UTM
 * tags and click IDs in a first-party cookie after consent, which also works
 * on statically cached pages; this class reads it back on the server.
 *
 * Cookies are read from the raw Cookie header: Laravel's EncryptCookies
 * middleware would otherwise hand back null for cookies it did not write,
 * such as Meta's _fbp or Google's _ga.
 */
final class Attribution
{
    public const KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid',
    ];

    /** @return array<string, string> */
    public static function rawCookies(Request $request): array
    {
        $header = (string) $request->headers->get('cookie', '');
        $cookies = [];

        foreach (explode(';', $header) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $name = trim($name);

            if ($name !== '' && ! array_key_exists($name, $cookies)) {
                $cookies[$name] = urldecode(trim($value));
            }
        }

        return $cookies;
    }

    /** The UTM tags and click IDs the script stored, cleaned. */
    public static function stored(array $cookies): array
    {
        $raw = $cookies[Settings::attributionCookie()] ?? null;

        if (! $raw) {
            return [];
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return [];
        }

        $clean = [];

        foreach (array_merge(self::KEYS, ['landing', 'referrer', 'ts']) as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                $clean[$key] = mb_substr((string) $data[$key], 0, 500);
            }
        }

        return $clean;
    }

    /**
     * Meta's click ID cookie. Prefer the _fbc cookie the Pixel sets; otherwise
     * build it from a stored fbclid: fb.1.<milliseconds>.<fbclid>.
     */
    public static function fbc(Conversion $conversion): ?string
    {
        if (! empty($conversion->cookies['_fbc'])) {
            return $conversion->cookies['_fbc'];
        }

        if (empty($conversion->attribution['fbclid'])) {
            return null;
        }

        $ms = isset($conversion->attribution['ts']) && ctype_digit((string) $conversion->attribution['ts'])
            ? (int) $conversion->attribution['ts']
            : $conversion->time * 1000;

        return 'fb.1.'.$ms.'.'.$conversion->attribution['fbclid'];
    }

    /** GA4's client ID from the _ga cookie: GA1.1.123456789.1700000000 becomes 123456789.1700000000. */
    public static function gaClientId(Conversion $conversion): ?string
    {
        $ga = $conversion->cookies['_ga'] ?? null;

        if ($ga && preg_match('/^GA\d\.\d+\.(\d+\.\d+)$/', $ga, $m)) {
            return $m[1];
        }

        return null;
    }

    /** A short "where from" for the log: the UTM source, or the ad network of the click ID. */
    public static function summary(array $attribution): ?string
    {
        if (! empty($attribution['utm_source'])) {
            return trim($attribution['utm_source'].(! empty($attribution['utm_medium']) ? ' / '.$attribution['utm_medium'] : ''));
        }

        foreach (['fbclid' => 'Meta ads', 'gclid' => 'Google Ads', 'gbraid' => 'Google Ads', 'wbraid' => 'Google Ads', 'ttclid' => 'TikTok ads', 'msclkid' => 'Microsoft Ads'] as $key => $label) {
            if (! empty($attribution[$key])) {
                return $label;
            }
        }

        if (! empty($attribution['referrer'])) {
            return parse_url($attribution['referrer'], PHP_URL_HOST) ?: null;
        }

        return null;
    }
}
