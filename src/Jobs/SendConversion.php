<?php

namespace KareliaLabs\Conversions\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use KareliaLabs\Conversions\Conversion;
use KareliaLabs\Conversions\Conversions;

class SendConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** The conversion as an array, so nothing but plain data is serialized onto the queue. */
    public function __construct(public array $conversion) {}

    public function handle(Conversions $conversions): void
    {
        $conversions->send(Conversion::fromArray($this->conversion));
    }
}
