<?php

namespace KareliaLabs\Conversions\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use KareliaLabs\Conversions\Listeners\TrackCargoOrders;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\UserRegistered;
use Statamic\Facades\User;

class OtherEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePlatforms();
    }

    private function browserRequest(array $cookies = [], string $path = '/checkout'): void
    {
        $header = $this->cookieHeader($cookies);
        $request = HttpRequest::create('https://shop.test'.$path, 'POST', server: [
            'HTTP_COOKIE' => $header,
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Shopper)',
            'HTTP_REFERER' => 'https://shop.test/checkout',
            'REMOTE_ADDR' => '203.0.113.9',
        ]);
        $this->app->instance('request', $request);
    }

    #[Test]
    public function a_registration_sends_a_sign_up()
    {
        $this->browserRequest(['karelia_conversions_consent' => '1']);

        $user = User::make()->email('new@example.com')->data(['first_name' => 'Nina']);
        $user->save();

        UserRegistered::dispatch($user);
        app()->terminate();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && $r['data'][0]['event_name'] === 'CompleteRegistration'
            && $r['data'][0]['user_data']['em'] === [hash('sha256', 'new@example.com')]
            && $r['data'][0]['user_data']['external_id'] === [hash('sha256', (string) $user->id())]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'google-analytics.com') && $r['events'][0]['name'] === 'sign_up');
    }

    #[Test]
    public function registrations_can_be_switched_off()
    {
        config(['statamic.conversions.registrations' => false]);
        $this->browserRequest(['karelia_conversions_consent' => '1']);

        UserRegistered::dispatch(tap(User::make()->email('x@example.com'))->save());
        app()->terminate();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_paid_cargo_order_sends_a_purchase_with_the_shoppers_details_from_checkout()
    {
        $order = new FakeCargoOrder;
        $listener = new TrackCargoOrders;

        // Checkout happens in the shopper's browser...
        $this->browserRequest([
            'karelia_conversions_consent' => '1',
            '_fbp' => 'fb.1.1.shopper',
            'karelia_conversions_attr' => json_encode(['gclid' => 'g-1', 'utm_source' => 'google', 'utm_medium' => 'cpc']),
        ]);
        $listener->created((object) ['order' => $order]);

        // ...and the payment provider confirms it later in a webhook.
        $this->app->instance('request', HttpRequest::create('https://shop.test/!/cargo/payments/stripe/webhook', 'POST', server: ['REMOTE_ADDR' => '3.18.12.63']));
        $listener->paid((object) ['order' => $order]);
        app()->terminate();

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'graph.facebook.com')) {
                return false;
            }

            $e = $r['data'][0];

            return $e['event_name'] === 'Purchase'
                && $e['user_data']['client_ip_address'] === '203.0.113.9'
                && $e['user_data']['fbp'] === 'fb.1.1.shopper'
                && $e['user_data']['em'] === [hash('sha256', 'buyer@example.com')]
                && $e['custom_data']['value'] === 129.5
                && $e['custom_data']['currency'] === 'EUR'
                && $e['custom_data']['order_id'] === '1042'
                && $e['custom_data']['content_ids'] === ['prod-1', 'prod-2']
                && $e['custom_data']['contents'][0] === ['id' => 'prod-1', 'quantity' => 2, 'item_price' => 49.75];
        });

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'google-analytics.com')
            && $r['events'][0]['name'] === 'purchase'
            && $r['events'][0]['params']['transaction_id'] === '1042'
            && count($r['events'][0]['params']['items']) === 2);

        $this->assertSame('google / cpc', $this->logLines()[0]['from']);
    }

    #[Test]
    public function a_webhook_without_checkout_details_does_not_assume_consent()
    {
        $this->app->instance('request', HttpRequest::create('https://shop.test/!/cargo/payments/stripe/webhook', 'POST'));

        (new TrackCargoOrders)->paid((object) ['order' => new FakeCargoOrder]);
        app()->terminate();

        Http::assertNothingSent();
        $this->assertFalse($this->logLines()[0]['consent']);
    }
}

class FakeCargoOrder
{
    public function id() { return 'order-uuid'; }

    public function orderNumber() { return 1042; }

    public function grandTotal() { return 12950; }

    public function site() { return new class { public function attribute($key) { return $key === 'currency' ? 'eur' : null; } }; }

    public function customer() { return new class { public function email() { return 'buyer@example.com'; } public function name() { return 'Ben Buyer'; } public function id() { return 'cust-1'; } }; }

    public function lineItems()
    {
        return collect([
            new class { public function product() { return new class { public function id() { return 'prod-1'; } public function get($k) { return $k === 'title' ? 'Mug' : null; } }; } public function quantity() { return 2; } public function unitPrice() { return 4975; } },
            new class { public function product() { return new class { public function id() { return 'prod-2'; } public function get($k) { return $k === 'title' ? 'Poster' : null; } }; } public function quantity() { return 1; } public function unitPrice() { return 3000; } },
        ]);
    }

    public function get($key) { return null; }
}
