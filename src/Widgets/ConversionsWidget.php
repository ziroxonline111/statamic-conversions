<?php

namespace KareliaLabs\Conversions\Widgets;

use KareliaLabs\Conversions\EventLog;
use Statamic\Facades\User;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

/**
 * Dashboard widget: conversions in the last 30 days and where they came from.
 *
 * config/statamic/cp.php:
 *   'widgets' => [['type' => 'conversions', 'width' => 50]],
 */
class ConversionsWidget extends Widget
{
    protected static $handle = 'conversions';

    public function component()
    {
        $user = User::current();

        if (! $user || (! $user->isSuper() && ! $user->hasPermission('view conversions'))) {
            return null;
        }

        $days = (int) $this->config('days', 30);
        $stats = app(EventLog::class)->stats($days);

        return VueComponent::render('karelia-conversions-widget', [
            'title' => $this->config('title', __('Conversions')),
            'days' => $days,
            'total' => $stats['total'],
            'perDay' => array_values($stats['per_day']),
            'perSource' => $stats['per_source'],
            'failed' => $stats['failed'],
            'url' => cp_route('karelia-conversions.index'),
        ]);
    }
}
