<?php

namespace KareliaLabs\Conversions\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use KareliaLabs\Conversions\Consent;
use KareliaLabs\Conversions\EventLog;
use KareliaLabs\Conversions\ServiceProvider;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Form;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        Consent::resolveUsing(null);
        File::deleteDirectory(app(EventLog::class)->directory());

        config([
            'statamic.conversions.meta.pixel_id' => '1234567890',
            'statamic.conversions.meta.access_token' => 'meta-token',
            'statamic.conversions.ga4.measurement_id' => 'G-TEST123',
            'statamic.conversions.ga4.api_secret' => 'ga4-secret',
            'statamic.conversions.tiktok.pixel_code' => 'TTPIXEL1',
            'statamic.conversions.tiktok.access_token' => 'tiktok-token',
            'statamic.conversions.forms' => [
                ['form' => 'contact', 'event' => 'lead', 'value' => 50, 'currency' => 'EUR'],
            ],
        ]);

        Http::preventStrayRequests();
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('statamic.editions.pro', true);
    }

    protected function fakePlatforms(array $overrides = []): void
    {
        Http::fake(array_merge([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'abc']),
            'www.google-analytics.com/debug/*' => Http::response(['validationMessages' => []]),
            'www.google-analytics.com/*' => Http::response('', 204),
            'business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => []]),
        ], $overrides));
    }

    protected function makeContactForm(): void
    {
        Blueprint::make('contact')->setNamespace('forms')->setContents([
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text', 'input_type' => 'email', 'validate' => 'required|email']],
                ['handle' => 'phone', 'field' => ['type' => 'text']],
                ['handle' => 'message', 'field' => ['type' => 'textarea']],
            ],
        ])->save();

        Form::make('contact')->title('Contact us')->store(true)->save();
    }

    /** A Cookie header like a browser would send. */
    protected function cookieHeader(array $cookies): string
    {
        return collect($cookies)->map(fn ($v, $k) => $k.'='.rawurlencode($v))->implode('; ');
    }

    protected function submitContactForm(array $cookies = [], array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Cookie' => $this->cookieHeader($cookies),
            'Referer' => 'https://example.com/contact?utm_source=newsletter',
            'User-Agent' => 'Mozilla/5.0 (Test)',
        ])->post('/!/forms/contact', array_merge([
            'name' => 'Jane Doe',
            'email' => 'Jane.Doe@Example.com',
            'phone' => '+46 70-123 45 67',
            'message' => 'Hello',
        ], $data));
    }

    protected function logLines(): array
    {
        return app(EventLog::class)->recent(2)->all();
    }
}
