<?php

namespace KareliaLabs\Conversions;

use Closure;
use Illuminate\Http\Request;

/**
 * Whether the visitor has agreed to marketing measurement.
 *
 * "cookie" mode looks for the consent cookie (set by your cookie banner with
 * KareliaConversions.consent(true), or any cookie and value you configure).
 * "always" mode sends without asking. A site can also decide in code:
 *
 *     Consent::resolveUsing(fn (Request $request) => ...);
 */
final class Consent
{
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

        $cookies = Attribution::rawCookies($request);
        $value = $cookies[Settings::consentCookie()] ?? null;

        if ($value === null) {
            return false;
        }

        $expected = Settings::consentValue();

        // Banners often store JSON or a list of categories, so "contains" is enough.
        return $expected === '' ? $value !== '' : str_contains($value, $expected);
    }
}
