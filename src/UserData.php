<?php

namespace KareliaLabs\Conversions;

use Illuminate\Support\Str;

/**
 * Normalises and hashes personal data the way the platforms match it.
 * Email, phone, names and the user ID are always sent hashed. Meta also gets
 * city, postcode and country hashed; Google Analytics 4 requires those three
 * in plain text, so they are sent to GA4 unhashed. TikTok gets no address.
 */
final class UserData
{
    public static function hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash('sha256', $value);
    }

    public static function email(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Google wants gmail.com and googlemail.com addresses without dots before
     * the @, so "jane.doe@gmail.com" and "janedoe@gmail.com" match.
     */
    public static function googleEmail(?string $email): ?string
    {
        $email = self::email($email);

        if (! $email) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
        }

        return $local.'@'.$domain;
    }

    /** Digits only, with the country code: "+46 70-123 45 67" becomes 46701234567. */
    public static function phoneDigits(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }

        $digits = ltrim(preg_replace('/\D+/', '', $phone), '0');

        return strlen($digits) >= 7 ? $digits : null;
    }

    /** E.164 with a plus sign, as Google and TikTok want it. */
    public static function phoneE164(?string $phone): ?string
    {
        $digits = self::phoneDigits($phone);

        return $digits ? '+'.$digits : null;
    }

    public static function name(?string $name): ?string
    {
        $name = Str::lower(trim((string) $name));
        $name = preg_replace('/[\p{P}\p{S}]+/u', '', $name);

        return $name !== '' ? $name : null;
    }

    /** Lowercase without spaces, dashes or punctuation: "New York" becomes newyork, "SW1A 1AA" sw1a1aa. */
    public static function plain(?string $value): ?string
    {
        $value = Str::lower(preg_replace('/[\s\p{P}\p{S}]+/u', '', trim((string) $value)));

        return $value !== '' ? $value : null;
    }

    /** Two-letter ISO country code in lowercase, or nothing: platforms cannot match "Sweden". */
    public static function countryCode(?string $value): ?string
    {
        $value = Str::lower(trim((string) $value));

        return preg_match('/^[a-z]{2}$/', $value) ? $value : null;
    }

    /**
     * Pick email, phone and names out of form data by field type and handle,
     * e.g. "email", "your_email", "phone_number", "first_name", "name".
     */
    public static function fromFormData(array $data, array $fieldTypes = []): array
    {
        $user = [];

        foreach ($data as $handle => $value) {
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $h = Str::lower((string) $handle);
            $type = $fieldTypes[$handle] ?? null;

            if (! isset($user['email']) && ($type === 'email' || $h === 'email' || str_contains($h, 'email') || str_contains($h, 'e_mail'))) {
                $user['email'] = (string) $value;
            } elseif (! isset($user['phone']) && preg_match('/(^|_)(phone|tel|telephone|mobile|cell)(_|$)/', $h)) {
                $user['phone'] = (string) $value;
            } elseif (! isset($user['first_name']) && in_array($h, ['first_name', 'firstname', 'given_name', 'fname'], true)) {
                $user['first_name'] = (string) $value;
            } elseif (! isset($user['last_name']) && in_array($h, ['last_name', 'lastname', 'surname', 'family_name', 'lname'], true)) {
                $user['last_name'] = (string) $value;
            } elseif (! isset($user['full_name']) && in_array($h, ['name', 'full_name', 'fullname', 'your_name'], true)) {
                $user['full_name'] = (string) $value;
            } elseif (! isset($user['city']) && in_array($h, ['city', 'town'], true)) {
                $user['city'] = (string) $value;
            } elseif (! isset($user['zip']) && in_array($h, ['zip', 'zipcode', 'zip_code', 'postcode', 'postal_code'], true)) {
                $user['zip'] = (string) $value;
            } elseif (! isset($user['country']) && $h === 'country') {
                $user['country'] = (string) $value;
            }
        }

        if (isset($user['full_name']) && ! isset($user['first_name'])) {
            $parts = preg_split('/\s+/', trim($user['full_name']));
            $user['first_name'] = array_shift($parts);

            if ($parts) {
                $user['last_name'] = $user['last_name'] ?? implode(' ', $parts);
            }
        }

        unset($user['full_name']);

        return $user;
    }
}
