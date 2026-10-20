<?php

namespace KareliaLabs\Conversions\Commands;

use Illuminate\Console\Command;
use KareliaLabs\Conversions\Conversions;
use Statamic\Console\RunsInPlease;

class TestConversion extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:conversions:test {platform? : meta, ga4 or tiktok (all when left out)}';

    protected $description = 'Send a test conversion to Meta, GA4 or TikTok and show the answer';

    public function handle(Conversions $conversions): int
    {
        $keys = $this->argument('platform') ? [$this->argument('platform')] : $conversions->platforms()->keys()->all();
        $failed = false;

        foreach ($keys as $key) {
            $platform = $conversions->platform($key);

            if (! $platform) {
                $this->error("Unknown platform [{$key}]. Use meta, ga4 or tiktok.");

                return self::FAILURE;
            }

            $result = $conversions->test($key);
            $line = $platform->label().': '.$result->status.($result->message ? ' ('.$result->message.')' : '');

            match ($result->status) {
                'sent' => $this->info($line),
                'skipped' => $this->line($line),
                default => $this->error($line),
            };

            $failed = $failed || $result->status === 'failed';
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
