<?php

namespace KareliaLabs\Conversions;

use Illuminate\Support\Collection;
use KareliaLabs\Conversions\Jobs\SendConversion;
use KareliaLabs\Conversions\Platforms\Ga4;
use KareliaLabs\Conversions\Platforms\Meta;
use KareliaLabs\Conversions\Platforms\Platform;
use KareliaLabs\Conversions\Platforms\TikTok;
use KareliaLabs\Conversions\Platforms\Result;

/**
 * Sends conversions. From your own code:
 *
 *     app(\KareliaLabs\Conversions\Conversions::class)->track(
 *         Conversion::make('lead')->fromRequest(request())->user(['email' => $email])->value(50, 'EUR')
 *     );
 */
class Conversions
{
    /** @var array<int, class-string<Platform>> */
    protected array $platforms = [Meta::class, Ga4::class, TikTok::class];

    /** @return Collection<string, Platform> */
    public function platforms(): Collection
    {
        return collect($this->platforms)
            ->map(fn ($class) => app($class))
            ->keyBy(fn (Platform $p) => $p->key());
    }

    public function platform(string $key): ?Platform
    {
        return $this->platforms()->get($key);
    }

    /** Queue the conversion (or send it right after the response). */
    public function track(Conversion $conversion): void
    {
        if (! Settings::enabled()) {
            return;
        }

        if (! $conversion->consent) {
            app(EventLog::class)->record($conversion, $this->platforms()
                ->filter(fn (Platform $p) => $p->on())
                ->map(fn () => Result::skipped(__('No consent'))->toArray())
                ->all());

            return;
        }

        if ($this->platforms()->filter(fn (Platform $p) => $p->on())->isEmpty()) {
            return;
        }

        $job = new SendConversion($conversion->toArray());

        if ($queue = Settings::queue()) {
            dispatch($job)->onConnection($queue);
        } else {
            dispatch($job)->afterResponse();
        }
    }

    /**
     * Send to every platform that is on, write the log line and return the
     * results keyed by platform.
     *
     * @return array<string, array>
     */
    public function send(Conversion $conversion): array
    {
        $results = $this->platforms()
            ->filter(fn (Platform $p) => $p->on())
            ->map(fn (Platform $p) => $p->send($conversion)->toArray())
            ->all();

        app(EventLog::class)->record($conversion, $results);

        return $results;
    }

    /** A test event to one platform. Not logged. Meta and TikTok use their test codes, GA4 its validation endpoint. */
    public function test(string $platform): Result
    {
        $target = $this->platform($platform);

        if (! $target) {
            return Result::failed(__('Unknown platform'));
        }

        $conversion = Conversion::make('lead')
            ->source('test', 'test', null, __('Test from the Control Panel'))
            ->user(['email' => 'test@example.com'])
            ->value(1.0);
        $conversion->url = url('/');
        $conversion->ip = '127.0.0.1';
        $conversion->userAgent = 'KareliaConversions test';

        return $target->send($conversion, true);
    }
}
