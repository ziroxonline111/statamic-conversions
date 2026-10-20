<?php

namespace KareliaLabs\Conversions\Tests;

use Illuminate\Http\Request;
use KareliaLabs\Conversions\Attribution;
use KareliaLabs\Conversions\Conversion;
use KareliaLabs\Conversions\UserData;
use PHPUnit\Framework\Attributes\Test;

class DataTest extends TestCase
{
    #[Test]
    public function emails_and_phones_are_normalised_the_way_the_platforms_match_them()
    {
        $this->assertSame('jane.doe@example.com', UserData::email('  Jane.Doe@Example.COM '));
        $this->assertNull(UserData::email('not an email'));
        $this->assertSame('janedoe@gmail.com', UserData::googleEmail('Jane.Doe@gmail.com'));
        $this->assertSame('jane.doe@example.com', UserData::googleEmail('Jane.Doe@example.com'));
        $this->assertSame('46701234567', UserData::phoneDigits('+46 70-123 45 67'));
        $this->assertSame('46701234567', UserData::phoneDigits('0046 70 123 45 67'));
        $this->assertSame('+46701234567', UserData::phoneE164('+46 70 123 45 67'));
        $this->assertNull(UserData::phoneDigits('123'));
        $this->assertSame('josé', UserData::name(' José. '));
        $this->assertSame('newyork', UserData::plain('New York'));
        $this->assertSame('sw1a1aa', UserData::plain('SW1A 1AA'));
        $this->assertSame('se', UserData::countryCode('SE'));
        $this->assertNull(UserData::countryCode('Sweden'));
    }

    #[Test]
    public function names_email_and_phone_are_found_in_form_data()
    {
        $user = UserData::fromFormData([
            'your_name' => 'Ada Lovelace King',
            'work_email' => 'ada@example.com',
            'phone_number' => '+44 20 7946 0000',
            'company' => 'Analytical Engines',
            'message' => 'Hi',
        ]);

        ksort($user);

        $this->assertSame([
            'email' => 'ada@example.com',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace King',
            'phone' => '+44 20 7946 0000',
        ], $user);
    }

    #[Test]
    public function an_email_field_is_found_by_its_type()
    {
        $user = UserData::fromFormData(['contact' => 'x@example.com'], ['contact' => 'email']);

        $this->assertSame('x@example.com', $user['email']);
    }

    #[Test]
    public function cookies_are_read_from_the_raw_header()
    {
        $request = Request::create('/', 'GET', server: ['HTTP_COOKIE' => '_fbp=fb.1.1.2; _ga=GA1.1.123.456; karelia_conversions_attr=%7B%22gclid%22%3A%22abc%22%7D']);

        $cookies = Attribution::rawCookies($request);

        $this->assertSame('fb.1.1.2', $cookies['_fbp']);
        $this->assertSame(['gclid' => 'abc'], Attribution::stored($cookies));
    }

    #[Test]
    public function the_stored_attribution_cookie_is_cleaned()
    {
        $stored = Attribution::stored(['karelia_conversions_attr' => json_encode([
            'utm_source' => 'google',
            'evil' => '<script>',
            'gclid' => str_repeat('x', 900),
        ])]);

        $this->assertSame(['utm_source', 'gclid'], array_keys($stored));
        $this->assertSame(500, mb_strlen($stored['gclid']));
        $this->assertSame([], Attribution::stored(['karelia_conversions_attr' => 'not json']));
    }

    #[Test]
    public function the_meta_click_id_is_built_from_fbclid_when_there_is_no_cookie()
    {
        $c = Conversion::make('lead');
        $c->attribution = ['fbclid' => 'AbC-123', 'ts' => '1760000000123'];

        $this->assertSame('fb.1.1760000000123.AbC-123', Attribution::fbc($c));

        $c->cookies = ['_fbc' => 'fb.1.999.cookie'];
        $this->assertSame('fb.1.999.cookie', Attribution::fbc($c));
    }

    #[Test]
    public function the_ga4_client_id_comes_from_the_ga_cookie()
    {
        $c = Conversion::make('lead');
        $c->cookies = ['_ga' => 'GA1.1.987654321.1700000000'];

        $this->assertSame('987654321.1700000000', Attribution::gaClientId($c));

        $c->cookies = ['_ga' => 'garbage'];
        $this->assertNull(Attribution::gaClientId($c));
    }

    #[Test]
    public function the_source_summary_prefers_utm_tags_then_click_ids_then_the_referrer()
    {
        $this->assertSame('google / cpc', Attribution::summary(['utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => 'x']));
        $this->assertSame('Google Ads', Attribution::summary(['gclid' => 'x']));
        $this->assertSame('TikTok ads', Attribution::summary(['ttclid' => 'x']));
        $this->assertSame('news.ycombinator.com', Attribution::summary(['referrer' => 'https://news.ycombinator.com/item?id=1']));
        $this->assertNull(Attribution::summary([]));
    }

    #[Test]
    public function a_conversion_survives_the_queue_unchanged()
    {
        $c = Conversion::make('custom', 'Book a demo')->user(['email' => 'a@b.co', 'nickname' => 'dropped'])->value(9.5, 'sek')->orderId('42');
        $c->cookies = ['_fbp' => 'x'];

        $copy = Conversion::fromArray($c->toArray());

        $this->assertSame($c->toArray(), $copy->toArray());
        $this->assertSame(['email' => 'a@b.co'], $copy->user);
        $this->assertSame('SEK', $copy->currency);
    }

    #[Test]
    public function unknown_events_are_refused()
    {
        $this->expectException(\InvalidArgumentException::class);

        Conversion::make('pageview');
    }
}
