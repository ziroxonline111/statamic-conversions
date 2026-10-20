<?php

namespace KareliaLabs\Conversions;

use Illuminate\Support\Facades\Event;
use KareliaLabs\Conversions\Commands\PruneLog;
use KareliaLabs\Conversions\Commands\TestConversion;
use KareliaLabs\Conversions\Listeners\TrackCargoOrders;
use KareliaLabs\Conversions\Listeners\TrackFormSubmissions;
use KareliaLabs\Conversions\Listeners\TrackRegistrations;
use KareliaLabs\Conversions\Tags\ConversionsTag;
use KareliaLabs\Conversions\Widgets\ConversionsWidget;
use Statamic\Events\SubmissionCreated;
use Statamic\Events\UserRegistered;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    protected $vite = [
        'input' => [
            'resources/js/addon.js',
            'resources/css/addon.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $tags = [
        ConversionsTag::class,
    ];

    protected $widgets = [
        ConversionsWidget::class,
    ];

    protected $commands = [
        TestConversion::class,
        PruneLog::class,
    ];

    protected $listen = [
        SubmissionCreated::class => [TrackFormSubmissions::class],
        UserRegistered::class => [TrackRegistrations::class],
    ];

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/conversions.php', 'statamic.conversions');

        $this->app->singleton(Conversions::class);
        $this->app->singleton(EventLog::class);
    }

    public function bootAddon()
    {
        $this->publishes([
            __DIR__.'/../config/conversions.php' => config_path('statamic/conversions.php'),
        ], 'statamic-conversions-config');

        $this->registerSettingsBlueprint(fn () => Settings::blueprint());

        // Cargo is optional: only listen when it is installed.
        if (class_exists(TrackCargoOrders::CREATED)) {
            Event::listen(TrackCargoOrders::CREATED, [TrackCargoOrders::class, 'created']);
        }

        if (class_exists(TrackCargoOrders::PAID)) {
            Event::listen(TrackCargoOrders::PAID, [TrackCargoOrders::class, 'paid']);
        }

        Permission::extend(function () {
            Permission::group('conversions', __('Conversions'), function () {
                Permission::register('view conversions', function ($permission) {
                    $permission
                        ->label(__('View conversions'))
                        ->description(__('See the conversions log and the totals. No personal data is shown.'))
                        ->children([
                            Permission::make('send test conversions')->label(__('Send test events')),
                        ]);
                });
            });
        });

        Nav::extend(function ($nav) {
            $nav->tools(__('Conversions'))
                ->route('karelia-conversions.index')
                ->icon('target-aim-bulleye')
                ->can('view conversions');
        });
    }

    protected function schedule($schedule)
    {
        $schedule->command('statamic:conversions:prune')->daily()->withoutOverlapping();
    }
}
