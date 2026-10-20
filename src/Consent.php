<?php

namespace KareliaLabs\Conversions;

use Closure;
use Illuminate\Http\Request;

/**
 * Whether the visitor has agreed to marketing measurement.
 *
 * "cookie" mode reads one cookie and checks it with an exact rule, never with
 * "contains": many banners write every category whether it was accepted or not
 * (Cookiebot: marketing:false, CookieYes: advertisement:no), so the presence of
 * a word proves nothing. A rule is either
 *   - an exact value: the whole cookie must equal it ("1", "allow"), or
 *   - a key and value: the cookie must hold that key with exactly that value
 *     (marketing:true matches marketing:true, "marketing":true and
 *     marketing=true, and never marketing:false).
 *
 * "always" mode sends without asking. A site can also decide in code:
 *
 *     Consent::resolveUsing(fn (Request $request) => ...);
 */
final class Consent
{
    /** Ready-made rules for common cookie banners: cookie, key (null for an exact value), value. */
    public const BANNERS = [
        'own' => ['karelia_conversions_consent', null, '1'],
        'cookiebot' => ['CookieConsent', 'marketing', 'true'],
        'cookieyes' => ['cookieyes-consent', 'advertisement', 'yes'],
        'complianz' => ['cmplz_marketing', null, 'allow'],
    ];

    private static ?Closure $resolver = null;

    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function granted(?Request $request): bool
    {
        if (self::$resolver) {
            return (bool) (self::$resolver)($request);
        }

        if (Settings::consentMode() === 'always') {
            return true;
        }

        if (! $request) {
            return false;
        }

        [$cookie, $key, $value] = self::rule();
        $raw = Attribution::rawCookies($request)[$cookie] ?? null;

        return $raw !== null && self::matches($raw, $key, $value);
    }

    /**
     * The cookie and the rule in use: a banner preset, or the custom cookie and value.
     *
     * @return array{0: string, 1: ?string, 2: string}
     */
    public static function rule(): array
    {
        $banner = Settings::consentBanner();

        if ($banner !== 'custom') {
            return self::BANNERS[$banner] ?? self::BANNERS['own'];
        }

        [$key, $value] = self::parse(Settings::consentValue());

        return [Settings::consentCookie(), $key, $value];
    }

    /**
     * "marketing:true" or "marketing=true" is a key and a value; anything else is an exact value.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function parse(string $rule): array
    {
        $rule = trim($rule);

        if (preg_match('/^["\']?([A-Za-z0-9_.\-]+)["\']?\s*[:=]\s*["\']?([^"\']*)["\']?$/', $rule, $m)) {
            return [$m[1], trim($m[2])];
        }

        return [null, $rule];
    }

    /**
     * Whether a cookie value passes the rule. Values are compared whole, ignoring case.
     * The browser script uses the same steps (ConversionsTag::script).
     */
    public static function matches(string $raw, ?string $key, string $value): bool
    {
        $expected = strtolower(trim($value));

        if ($expected === '') {
            return false;
        }

        if ($key === null) {
            return strtolower(self::strip($raw)) === $expected;
        }

        // Split at the separators banners use, then read "key:value" or "key=value" pairs.
        $seen = false;

        foreach (preg_split('/[,;&|{}\[\]]/', $raw) as $part) {
            if (! preg_match('/[:=]/', $part, $sep, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $at = $sep[0][1];

            if (strtolower(self::strip(substr($part, 0, $at))) !== strtolower($key)) {
                continue;
            }

            $seen = true;

            // Every occurrence must say yes; a single "false" anywhere means no.
            if (strtolower(self::strip(substr($part, $at + 1))) !== $expected) {
                return false;
            }
        }

        return $seen;
    }

    private static function strip(string $text): string
    {
        return trim($text, " \t\n\r\"'");
    }
}
