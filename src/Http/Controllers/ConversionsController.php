<?php

namespace KareliaLabs\Conversions\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use KareliaLabs\Conversions\Conversions;
use KareliaLabs\Conversions\EventLog;
use KareliaLabs\Conversions\Platforms\Platform;
use KareliaLabs\Conversions\Settings;
use Statamic\Facades\Addon;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class ConversionsController extends CpController
{
    public function index(Conversions $conversions, EventLog $log)
    {
        $this->authorize('view conversions');

        $user = User::current();

        return Inertia::render('statamic-conversions::ConversionsIndex', [
            'platforms' => $conversions->platforms()->map(fn (Platform $p) => [
                'key' => $p->key(),
                'label' => $p->label(),
                'configured' => $p->configured(),
                'switched_on' => Settings::platformOn($p->key()),
                'missing' => $p->missing(),
            ])->values()->all(),
            'stats' => $log->stats(30),
            'events' => $log->recent(30)->take(200)->map(fn ($line) => $this->present($line))->values()->all(),
            'enabled' => Settings::enabled(),
            'consentMode' => Settings::consentMode(),
            'formsTracked' => count(Settings::forms()),
            'canTest' => $user->isSuper() || $user->hasPermission('send test conversions'),
            'testUrl' => cp_route('karelia-conversions.test'),
            'settingsUrl' => $user->isSuper() || $user->hasPermission('configure addons')
                ? Addon::get(Settings::PACKAGE)?->settingsUrl()
                : null,
        ]);
    }

    public function test(Request $request, Conversions $conversions)
    {
        $this->authorize('send test conversions');

        $data = $request->validate(['platform' => 'required|in:meta,ga4,tiktok']);
        $result = $conversions->test($data['platform']);

        return response()->json([
            'ok' => $result->ok(),
            'status' => $result->status,
            'message' => $result->message,
        ], $result->ok() ? 200 : 422);
    }

    private function present(array $line): array
    {
        $time = Carbon::createFromTimestamp($line['time'])->setTimezone(config('app.timezone'));

        return [
            'id' => $line['id'],
            'label' => $line['label'] ?? $line['event'],
            'event' => $line['event'],
            'source' => $line['source']['label'] ?? ($line['source']['handle'] ?? null),
            'source_type' => $line['source']['type'] ?? null,
            'value' => $line['value'] !== null ? number_format((float) $line['value'], 2).' '.$line['currency'] : null,
            'from' => $line['from'] ?? null,
            'campaign' => $line['campaign'] ?? null,
            'consent' => $line['consent'] ?? true,
            'results' => $line['results'] ?? [],
            'time' => $time->format('Y-m-d H:i'),
            'time_human' => $time->diffForHumans(),
        ];
    }
}
