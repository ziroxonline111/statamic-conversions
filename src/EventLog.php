<?php

namespace KareliaLabs\Conversions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * The Control Panel log: one JSON line per conversion, one file per day in
 * storage/statamic/karelia-conversions. No personal data is written: only the
 * event, its source, value, where the visitor came from and each platform's answer.
 */
class EventLog
{
    public function directory(): string
    {
        return storage_path('statamic/karelia-conversions');
    }

    public function record(Conversion $conversion, array $results): array
    {
        $line = [
            'id' => $conversion->id,
            'time' => $conversion->time,
            'event' => $conversion->event,
            'label' => $conversion->label(),
            'source' => $conversion->source,
            'value' => $conversion->value,
            'currency' => $conversion->value !== null ? $conversion->currency() : null,
            'from' => Attribution::summary($conversion->attribution),
            'click' => collect(['fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid'])
                ->first(fn ($key) => ! empty($conversion->attribution[$key])),
            'campaign' => $conversion->attribution['utm_campaign'] ?? null,
            'consent' => $conversion->consent,
            'results' => $results,
        ];

        File::ensureDirectoryExists($this->directory());
        File::append($this->path(Carbon::createFromTimestamp($conversion->time)), json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $line;
    }

    /** Newest first. */
    public function recent(int $days = 30): Collection
    {
        $lines = collect();

        for ($i = 0; $i < $days; $i++) {
            $path = $this->path(now()->subDays($i));

            if (! File::exists($path)) {
                continue;
            }

            foreach (array_reverse(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $raw) {
                $line = json_decode($raw, true);

                if (is_array($line)) {
                    $lines->push($line);
                }
            }
        }

        return $lines->sortByDesc('time')->values();
    }

    public function stats(int $days = 30): array
    {
        $lines = $this->recent($days)->filter(fn ($l) => $l['consent'] ?? true);

        $perDay = collect(range($days - 1, 0))
            ->mapWithKeys(fn ($i) => [now()->subDays($i)->format('Y-m-d') => 0])
            ->all();

        foreach ($lines as $line) {
            $day = Carbon::createFromTimestamp($line['time'])->format('Y-m-d');

            if (array_key_exists($day, $perDay)) {
                $perDay[$day]++;
            }
        }

        $values = $lines->filter(fn ($l) => $l['value'] !== null)->groupBy('currency')
            ->map(fn ($group) => round($group->sum('value'), 2))->all();

        return [
            'total' => $lines->count(),
            'per_day' => $perDay,
            'per_event' => $lines->groupBy('label')->map->count()->sortDesc()->all(),
            'per_source' => $lines->groupBy(fn ($l) => $l['from'] ?? __('Direct or unknown'))->map->count()->sortDesc()->take(8)->all(),
            'value' => $values,
            'failed' => $lines->filter(fn ($l) => collect($l['results'] ?? [])->contains(fn ($r) => ($r['status'] ?? null) === 'failed'))->count(),
            'without_consent' => $this->recent($days)->reject(fn ($l) => $l['consent'] ?? true)->count(),
        ];
    }

    public function prune(?int $days = null): int
    {
        $days ??= Settings::logDays();
        $cutoff = now()->subDays($days)->format('Y-m-d');
        $deleted = 0;

        foreach (File::glob($this->directory().'/events-*.jsonl') as $file) {
            if (preg_match('/events-(\d{4}-\d{2}-\d{2})\.jsonl$/', $file, $m) && $m[1] < $cutoff) {
                File::delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function path(Carbon $day): string
    {
        return $this->directory().'/events-'.$day->format('Y-m-d').'.jsonl';
    }
}
