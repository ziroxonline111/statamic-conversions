<?php

namespace KareliaLabs\Conversions\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request as HttpRequest;
use KareliaLabs\Conversions\Consent;
use KareliaLabs\Conversions\Listeners\TrackCargoOrders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

class ConsentTest extends TestCase
{
    private const COOKIEBOT_YES = "{stamp:'Ab3/x==',necessary:true,preferences:false,statistics:true,marketing:true,method:'explicit',ver:1,utc:1792500000000,region:'se'}";

    private const COOKIEBOT_NO = "{stamp:'Ab3/x==',necessary:true,preferences:true,statistics:true,marketing:false,method:'explicit',ver:1,utc:1792500000000,region:'se'}";

    private const COOKIEYES_YES = 'consentid:YWJj,consent:yes,action:yes,necessary:yes,functional:no,analytics:no,performance:no,advertisement:yes,other:no';

    private const COOKIEYES_NO = 'consentid:YWJj,consent:yes,action:yes,necessary:yes,functional:yes,analytics:yes,performance:yes,advertisement:no,other:yes';

    /** Cookie value, rule key, rule value, expected. */
    public static function cases(): array
    {
        return [
            'Cookiebot accepted' => [self::COOKIEBOT_YES, 'marketing', 'true', true],
            'Cookiebot declined' => [self::COOKIEBOT_NO, 'marketing', 'true', false],
            'CookieYes accepted' => [self::COOKIEYES_YES, 'advertisement', 'yes', true],
            'CookieYes declined' => [self::COOKIEYES_NO, 'advertisement', 'yes', false],
            'JSON declined' => ['{"necessary":true,"marketing":false}', 'marketing', 'true', false],
            'JSON accepted' => ['{"necessary":true,"marketing":true}', 'marketing', 'true', true],
            'JSON with quoted value' => ['{"marketing":"granted"}', 'marketing', 'granted', true],
            'key=value accepted' => ['stats=false&marketing=true', 'marketing', 'true', true],
            'key=value declined' => ['marketing=false&stats=true', 'marketing', 'true', false],
            'key only inside another key' => ['nomarketing:true,marketingx:true', 'marketing', 'true', false],
            'key missing' => ['necessary:true', 'marketing', 'true', false],
            'said twice, once no' => ['marketing:true,marketing:false', 'marketing', 'true', false],
            'Complianz allow' => ['allow', null, 'allow', true],
            'Complianz deny' => ['deny', null, 'allow', false],
            'own cookie' => ['1', null, '1', true],
            'exact word is not "contains"' => ['marketing:false', null, 'marketing', false],
            'case does not matter' => ['Marketing:TRUE', 'marketing', 'true', true],
            'empty rule never passes' => ['anything', null, '', false],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function cookie_values_are_matched_exactly(string $cookie, ?string $key, string $value, bool $expected)
    {
        $this->assertSame($expected, Consent::matches($cookie, $key, $value));
    }

    #[Test]
    public function custom_rules_are_a_pair_or_an_exact_value()
    {
        $this->assertSame(['marketing', 'true'], Consent::parse('marketing:true'));
        $this->assertSame(['marketing', 'true'], Consent::parse('"marketing":true'));
        $this->assertSame(['ad_storage', 'granted'], Consent::parse('ad_storage=granted'));
        $this->assertSame([null, 'allow'], Consent::parse('allow'));
        $this->assertSame([null, '1'], Consent::parse('1'));
    }

    #[Test]
    public function a_declined_cookiebot_cookie_sends_nothing()
    {
        $this->fakePlatforms();
        config(['statamic.conversions.consent.banner' => 'cookiebot']);

        $this->submitContactForm(['CookieConsent' => self::COOKIEBOT_NO, '_fbp' => 'fb.1.1.1', 'karelia_conversions_attr' => json_encode(['fbclid' => 'x'])])->assertRedirect();

        Http::assertNothingSent();
        $this->assertFalse($this->logLines()[0]['consent']);
    }

    #[Test]
    public function a_declined_cookieyes_cookie_sends_nothing_and_an_accepted_one_sends()
    {
        $this->fakePlatforms();
        config(['statamic.conversions.consent.banner' => 'cookieyes']);

        $this->submitContactForm(['cookieyes-consent' => self::COOKIEYES_NO]);
        Http::assertNothingSent();

        $this->submitContactForm(['cookieyes-consent' => self::COOKIEYES_YES]);
        Http::assertSentCount(3);
    }

    #[Test]
    public function a_custom_banner_with_json_false_sends_nothing()
    {
        $this->fakePlatforms();
        config([
            'statamic.conversions.consent.banner' => 'custom',
            'statamic.conversions.consent.cookie' => 'site_consent',
            'statamic.conversions.consent.value' => 'marketing:true',
        ]);

        $this->submitContactForm(['site_consent' => '{"necessary":true,"marketing":false}']);
        Http::assertNothingSent();

        $this->submitContactForm(['site_consent' => '{"necessary":true,"marketing":true}']);
        Http::assertSentCount(3);
    }

    #[Test]
    public function cargo_keeps_no_visitor_details_without_consent()
    {
        $order = new FakeCargoOrder;
        $this->app->instance('request', HttpRequest::create('https://shop.test/checkout', 'POST', server: [
            'HTTP_COOKIE' => '_fbp=fb.1.1.shopper',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Shopper)',
            'REMOTE_ADDR' => '203.0.113.9',
        ]));

        (new TrackCargoOrders)->created((object) ['order' => $order]);

        $this->assertSame(['consent' => false], Cache::get('karelia-conversions:cargo:'.$order->id()));
    }

    #[Test]
    public function the_browser_script_uses_the_same_rule()
    {
        $node = (new \Symfony\Component\Process\ExecutableFinder)->find('node');

        if (! $node) {
            $this->markTestSkipped('Node.js is not installed.');
        }

        $cases = [
            ['own', '', ['karelia_conversions_consent' => '1'], true],
            ['own', '', ['karelia_conversions_consent' => '0'], false],
            ['cookiebot', '', ['CookieConsent' => self::COOKIEBOT_YES], true],
            ['cookiebot', '', ['CookieConsent' => self::COOKIEBOT_NO], false],
            ['cookieyes', '', ['cookieyes-consent' => self::COOKIEYES_YES], true],
            ['cookieyes', '', ['cookieyes-consent' => self::COOKIEYES_NO], false],
            ['complianz', '', ['cmplz_marketing' => 'deny'], false],
            ['custom', 'marketing:true', ['site_consent' => '{"marketing":false}'], false],
            ['custom', 'marketing:true', ['site_consent' => '{"marketing":true}'], true],
            ['custom', 'marketing', ['site_consent' => 'marketing:false'], false],
        ];

        foreach ($cases as [$banner, $rule, $cookies, $expected]) {
            config([
                'statamic.conversions.consent.banner' => $banner,
                'statamic.conversions.consent.cookie' => $banner === 'custom' ? 'site_consent' : 'karelia_conversions_consent',
                'statamic.conversions.consent.value' => $rule ?: '1',
            ]);

            $html = app(\Statamic\Tags\Loader::class)->load('conversions', ['parser' => null, 'params' => [], 'content' => '', 'context' => [], 'tag' => 'conversions:script', 'tag_method' => 'script'])->script();
            $js = preg_replace('#^\s*<script[^>]*>|</script>\s*$#', '', trim($html));
            $cookie = collect($cookies)->map(fn ($v, $k) => $k.'='.rawurlencode($v))->implode('; ');
            $harness = 'var window = {}; var document = { cookie: '.json_encode($cookie).', referrer: "" };'
                .' var location = { search: "", pathname: "/", host: "example.test", protocol: "https:" };'
                .$js.' process.stdout.write(String(window.KareliaConversions.hasConsent()));';

            $process = new Process([$node, '-e', $harness]);
            $process->run();

            $this->assertSame($expected ? 'true' : 'false', trim($process->getOutput()), "{$banner} {$rule} ".json_encode($cookies).' '.$process->getErrorOutput());
        }
    }
}
