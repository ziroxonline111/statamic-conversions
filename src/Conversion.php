<?php

namespace KareliaLabs\Conversions;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One conversion, as it is sent to every platform: what happened, who did it
 * (personal data is only hashed on the way out), what it was worth, and where
 * the visitor came from.
 */
final class Conversion
{
    public const EVENTS = ['lead', 'contact', 'sign_up', 'purchase', 'custom'];

    public string $id;

    public int $time;

    /** @var array<string, string> email, phone, first_name, last_name, city, zip, country, external_id */
    public array $user = [];

    public ?float $value = null;

    public ?string $currency = null;

    /** @var array<int, array{id: string, name?: string, quantity?: int, price?: float}> */
    public array $items = [];

    public ?string $orderId = null;

    /** @var array{type?: string, handle?: string, id?: string, label?: string} */
    public array $source = [];

    public ?string $url = null;

    public ?string $referrer = null;

    public ?string $ip = null;

    public ?string $userAgent = null;

    /** @var array<string, string> Raw first-party cookies the platforms understand: _fbp, _fbc, _ga, _ttp. */
    public array $cookies = [];

    /** @var array<string, string> utm_*, fbclid, gclid, gbraid, wbraid, ttclid, msclkid, landing, referrer */
    public array $attribution = [];

    public bool $consent = true;

    public function __construct(public string $event, public ?string $customName = null)
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown conversion event [{$event}].");
        }

        if ($event === 'custom' && blank($customName)) {
            throw new \InvalidArgumentException('A custom conversion needs a name.');
        }

        $this->id = (string) Str::uuid();
        $this->time = time();
    }

    public static function make(string $event, ?string $customName = null): static
    {
        return new static($event, $customName);
    }

    /** Fill in the visitor's details from the request that caused the conversion. */
    public function fromRequest(?Request $request): static
    {
        if (! $request) {
            return $this;
        }

        $this->ip = $request->ip();
        $this->userAgent = Str::limit((string) $request->userAgent(), 500, '');

        // A form posts to /!/forms/..., so the page the visitor was on is the referer.
        $page = $request->headers->get('referer');
        $this->url = $page && Str::startsWith($page, ['http://', 'https://']) ? $page : $request->fullUrl();

        $cookies = Attribution::rawCookies($request);
        $this->cookies = array_filter([
            '_fbp' => $cookies['_fbp'] ?? null,
            '_fbc' => $cookies['_fbc'] ?? null,
            '_ga' => $cookies['_ga'] ?? null,
            '_ttp' => $cookies['_ttp'] ?? null,
        ]);
        $this->attribution = Attribution::stored($cookies);
        $this->referrer = $this->attribution['referrer'] ?? null;
        $this->consent = Consent::granted($request);

        return $this;
    }

    public function user(array $user): static
    {
        $this->user = array_filter(array_map(
            fn ($v) => is_scalar($v) ? trim((string) $v) : null,
            array_intersect_key($user, array_flip(['email', 'phone', 'first_name', 'last_name', 'city', 'zip', 'country', 'external_id']))
        ), fn ($v) => $v !== null && $v !== '');

        return $this;
    }

    public function value(?float $value, ?string $currency = null): static
    {
        $this->value = $value;
        $this->currency = $currency ? strtoupper($currency) : $this->currency;

        return $this;
    }

    public function items(array $items): static
    {
        $this->items = array_values($items);

        return $this;
    }

    public function orderId(?string $id): static
    {
        $this->orderId = $id;

        return $this;
    }

    public function source(string $type, ?string $handle = null, ?string $id = null, ?string $label = null): static
    {
        $this->source = array_filter(compact('type', 'handle', 'id', 'label'), fn ($v) => $v !== null);

        return $this;
    }

    public function withConsent(bool $consent): static
    {
        $this->consent = $consent;

        return $this;
    }

    /** The currency to report, falling back to the site default. */
    public function currency(): string
    {
        return $this->currency ?: strtoupper((string) Settings::get('currency', config('statamic.conversions.currency', 'USD')));
    }

    /** The event name on a given platform. */
    public function nameFor(string $platform): string
    {
        if ($this->event === 'custom') {
            return $platform === 'ga4'
                ? Str::limit(Str::snake(preg_replace('/[^A-Za-z0-9 _]/', ' ', $this->customName)), 40, '')
                : Str::limit(preg_replace('/[^A-Za-z0-9_]/', '', Str::studly($this->customName)), 50, '');
        }

        return [
            'meta' => ['lead' => 'Lead', 'contact' => 'Contact', 'sign_up' => 'CompleteRegistration', 'purchase' => 'Purchase'],
            'ga4' => ['lead' => 'generate_lead', 'contact' => 'generate_lead', 'sign_up' => 'sign_up', 'purchase' => 'purchase'],
            'tiktok' => ['lead' => 'Lead', 'contact' => 'Contact', 'sign_up' => 'CompleteRegistration', 'purchase' => 'Purchase'],
        ][$platform][$this->event];
    }

    /** A short label for the Control Panel. */
    public function label(): string
    {
        return match ($this->event) {
            'lead' => __('Lead'),
            'contact' => __('Contact'),
            'sign_up' => __('Sign-up'),
            'purchase' => __('Purchase'),
            default => (string) $this->customName,
        };
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'time' => $this->time,
            'event' => $this->event,
            'custom_name' => $this->customName,
            'user' => $this->user,
            'value' => $this->value,
            'currency' => $this->currency,
            'items' => $this->items,
            'order_id' => $this->orderId,
            'source' => $this->source,
            'url' => $this->url,
            'referrer' => $this->referrer,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'cookies' => $this->cookies,
            'attribution' => $this->attribution,
            'consent' => $this->consent,
        ];
    }

    public static function fromArray(array $data): static
    {
        $conversion = new static($data['event'], $data['custom_name'] ?? null);

        foreach ([
            'id' => 'id', 'time' => 'time', 'user' => 'user', 'value' => 'value', 'currency' => 'currency',
            'items' => 'items', 'order_id' => 'orderId', 'source' => 'source', 'url' => 'url', 'referrer' => 'referrer',
            'ip' => 'ip', 'user_agent' => 'userAgent', 'cookies' => 'cookies', 'attribution' => 'attribution', 'consent' => 'consent',
        ] as $key => $property) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $conversion->{$property} = $data[$key];
            }
        }

        return $conversion;
    }
}
