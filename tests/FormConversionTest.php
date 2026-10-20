<?php

namespace KareliaLabs\Conversions\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class FormConversionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeContactForm();
    }

    private function consentAndClicks(): array
    {
        return [
            'karelia_conversions_consent' => '1',
            'karelia_conversions_attr' => json_encode(['utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'autumn', 'fbclid' => 'IwAR123', 'ttclid' => 'tt-456', 'ts' => '1760000000000']),
            '_fbp' => 'fb.1.1759999999999.987654321',
            '_ga' => 'GA1.1.111222333.1759990000',
            '_ttp' => 'ttp-cookie',
            'laravel_session' => 'encrypted-junk',
        ];
    }

    #[Test]
    public function a_tracked_form_sends_a_lead_to_all_three_platforms()
    {
        $this->fakePlatforms();
        $this->submitContactForm($this->consentAndClicks())->assertRedirect();

        Http::assertSentCount(3);

        $email = hash('sha256', 'jane.doe@example.com');

        Http::assertSent(function (Request $request) use ($email) {
            if (! str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/1234567890/events')) {
                return false;
            }

            $event = $request['data'][0];

            return str_contains($request->url(), 'access_token=meta-token')
                && $event['event_name'] === 'Lead'
                && $event['action_source'] === 'website'
                && $event['event_source_url'] === 'https://example.com/contact?utm_source=newsletter'
                && $event['user_data']['em'] === [$email]
                && $event['user_data']['ph'] === [hash('sha256', '46701234567')]
                && $event['user_data']['fn'] === [hash('sha256', 'jane')]
                && $event['user_data']['ln'] === [hash('sha256', 'doe')]
                && $event['user_data']['fbc'] === 'fb.1.1760000000000.IwAR123'
                && $event['user_data']['fbp'] === 'fb.1.1759999999999.987654321'
                && $event['user_data']['client_user_agent'] === 'Mozilla/5.0 (Test)'
                && $event['custom_data']['value'] === 50.0
                && $event['custom_data']['currency'] === 'EUR'
                && $event['custom_data']['content_name'] === 'Contact us';
        });

        Http::assertSent(function (Request $request) use ($email) {
            if (! str_starts_with($request->url(), 'https://www.google-analytics.com/mp/collect')) {
                return false;
            }

            return str_contains($request->url(), 'measurement_id=G-TEST123')
                && str_contains($request->url(), 'api_secret=ga4-secret')
                && $request['client_id'] === '111222333.1759990000'
                && $request['events'][0]['name'] === 'generate_lead'
                && $request['events'][0]['params']['value'] === 50.0
                && $request['events'][0]['params']['form_name'] === 'Contact us'
                && $request['user_data']['sha256_email_address'] === [$email]
                && $request['user_data']['sha256_phone_number'] === [hash('sha256', '+46701234567')]
                && $request['consent']['ad_user_data'] === 'GRANTED';
        });

        Http::assertSent(function (Request $request) use ($email) {
            if ($request->url() !== 'https://business-api.tiktok.com/open_api/v1.3/event/track/') {
                return false;
            }

            $data = $request['data'][0];

            return $request->header('Access-Token')[0] === 'tiktok-token'
                && $request['event_source'] === 'web'
                && $request['event_source_id'] === 'TTPIXEL1'
                && $data['event'] === 'Lead'
                && $data['user']['email'] === $email
                && $data['user']['ttclid'] === 'tt-456'
                && $data['user']['ttp'] === 'ttp-cookie'
                && $data['properties']['currency'] === 'EUR';
        });

        $line = $this->logLines()[0];
        $this->assertSame('lead', $line['event']);
        $this->assertSame('facebook / paid', $line['from']);
        $this->assertSame('autumn', $line['campaign']);
        $this->assertSame('fbclid', $line['click']);
        $this->assertSame(['meta', 'ga4', 'tiktok'], array_keys($line['results']));
        $this->assertSame('sent', $line['results']['meta']['status']);

        // The log never holds personal data.
        $this->assertStringNotContainsString('jane', strtolower(json_encode($line)));
    }

    #[Test]
    public function the_same_event_id_goes_to_every_platform()
    {
        $this->fakePlatforms();
        $this->submitContactForm($this->consentAndClicks());

        $ids = collect(Http::recorded())->map(fn ($pair) => $pair[0])->map(fn (Request $r) => $r['data'][0]['event_id'] ?? null)->filter()->unique();

        $this->assertCount(1, $ids);
    }

    #[Test]
    public function nothing_is_sent_without_consent()
    {
        $this->fakePlatforms();
        $this->submitContactForm(['_fbp' => 'fb.1.1.1'])->assertRedirect();

        Http::assertNothingSent();

        $line = $this->logLines()[0];
        $this->assertFalse($line['consent']);
        $this->assertSame('skipped', $line['results']['meta']['status']);
    }

    #[Test]
    public function a_cookie_from_another_banner_can_count_as_consent()
    {
        $this->fakePlatforms();
        config(['statamic.conversions.consent.cookie' => 'cookie_preferences', 'statamic.conversions.consent.value' => 'marketing']);

        $this->submitContactForm(['cookie_preferences' => '{"necessary":true,"categories":["analytics","marketing"]}']);

        Http::assertSentCount(3);
    }

    #[Test]
    public function always_mode_sends_without_a_consent_cookie()
    {
        $this->fakePlatforms();
        config(['statamic.conversions.consent.mode' => 'always']);

        $this->submitContactForm();

        Http::assertSentCount(3);
    }

    #[Test]
    public function untracked_forms_send_nothing()
    {
        $this->fakePlatforms();
        config(['statamic.conversions.forms' => []]);

        $this->submitContactForm($this->consentAndClicks());

        Http::assertNothingSent();
        $this->assertSame([], $this->logLines());
    }

    #[Test]
    public function a_platform_that_is_not_set_up_is_left_out()
    {
        $this->fakePlatforms();
        config(['statamic.conversions.tiktok.access_token' => null]);

        $this->submitContactForm($this->consentAndClicks());

        Http::assertSentCount(2);
        $this->assertArrayNotHasKey('tiktok', $this->logLines()[0]['results']);
    }

    #[Test]
    public function an_error_from_a_platform_is_logged_and_the_others_still_get_it()
    {
        $this->fakePlatforms([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]], 400),
        ]);

        $this->submitContactForm($this->consentAndClicks())->assertRedirect();

        $results = $this->logLines()[0]['results'];
        $this->assertSame('failed', $results['meta']['status']);
        $this->assertSame('Invalid OAuth access token.', $results['meta']['message']);
        $this->assertSame('sent', $results['ga4']['status']);
        $this->assertSame('sent', $results['tiktok']['status']);
    }

    #[Test]
    public function a_slow_platform_does_not_break_the_form()
    {
        $this->fakePlatforms([
            'graph.facebook.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'),
        ]);

        $this->submitContactForm($this->consentAndClicks())->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('failed', $this->logLines()[0]['results']['meta']['status']);
    }

    #[Test]
    public function a_custom_event_name_is_used_per_platform()
    {
        $this->fakePlatforms();
        config(['statamic.conversions.forms' => [['form' => 'contact', 'event' => 'custom', 'custom_name' => 'Book a demo']]]);

        $this->submitContactForm($this->consentAndClicks());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && $r['data'][0]['event_name'] === 'BookADemo');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'google-analytics.com') && $r['events'][0]['name'] === 'book_a_demo');
    }
}
