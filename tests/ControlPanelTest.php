<?php

namespace KareliaLabs\Conversions\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class ControlPanelTest extends TestCase
{
    private function super()
    {
        $user = User::make()->email('super@example.com')->makeSuper();
        $user->save();

        return $user;
    }

    private function editor(array $permissions)
    {
        Role::make('editor')->title('Editor')->permissions(array_merge(['access cp'], $permissions))->save();

        $user = User::make()->email('editor@example.com')->assignRole('editor');
        $user->save();

        return $user;
    }

    #[Test]
    public function guests_are_sent_to_the_login()
    {
        $this->get(cp_route('karelia-conversions.index'))->assertRedirect();
    }

    #[Test]
    public function the_page_shows_platforms_stats_and_the_log()
    {
        $this->fakePlatforms();
        $this->makeContactForm();
        config(['statamic.conversions.consent.mode' => 'always']);
        $this->submitContactForm();

        $this->actingAs($this->super())
            ->get(cp_route('karelia-conversions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statamic-conversions::ConversionsIndex')
                ->where('platforms.0.key', 'meta')
                ->where('platforms.0.configured', true)
                ->where('stats.total', 1)
                ->where('events.0.label', 'Lead')
                ->where('events.0.source', 'Contact us')
                ->where('events.0.value', '50.00 EUR')
                ->where('events.0.results.meta.status', 'sent')
                ->where('canTest', true));
    }

    #[Test]
    public function missing_secrets_are_named()
    {
        config(['statamic.conversions.ga4.api_secret' => null]);

        $this->actingAs($this->super())
            ->get(cp_route('karelia-conversions.index'))
            ->assertInertia(fn ($page) => $page
                ->where('platforms.1.key', 'ga4')
                ->where('platforms.1.configured', false)
                ->where('platforms.1.missing', ['KARELIA_CONVERSIONS_GA4_API_SECRET']));
    }

    #[Test]
    public function editors_need_permission()
    {
        $denied = $this->actingAs($this->editor([]))->get(cp_route('karelia-conversions.index'));
        $this->assertContains($denied->status(), [302, 403]);
        $this->assertNotSame(200, $denied->status());

        Role::find('editor')->permissions(['access cp', 'view conversions'])->save();
        $this->actingAs(User::find(User::findByEmail('editor@example.com')->id()))->get(cp_route('karelia-conversions.index'))->assertOk();
    }

    #[Test]
    public function viewing_does_not_allow_sending_test_events()
    {
        $this->actingAs($this->editor(['view conversions']))
            ->postJson(cp_route('karelia-conversions.test'), ['platform' => 'meta'])
            ->assertForbidden();
    }

    #[Test]
    public function a_test_event_goes_to_meta_with_the_test_code()
    {
        config(['statamic.conversions.meta.test_event_code' => 'TEST4242']);
        $this->fakePlatforms();

        $this->actingAs($this->super())
            ->postJson(cp_route('karelia-conversions.test'), ['platform' => 'meta'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn (Request $r) => $r['test_event_code'] === 'TEST4242' && $r['data'][0]['event_name'] === 'Lead');
        $this->assertSame([], $this->logLines(), 'Test events are not logged.');
    }

    #[Test]
    public function a_ga4_test_uses_the_validation_endpoint_and_shows_its_message()
    {
        $this->fakePlatforms([
            'www.google-analytics.com/debug/*' => Http::response(['validationMessages' => [['fieldPath' => 'events', 'description' => 'Event name is reserved.', 'validationCode' => 'NAME_RESERVED']]]),
        ]);

        $this->actingAs($this->super())
            ->postJson(cp_route('karelia-conversions.test'), ['platform' => 'ga4'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Event name is reserved.');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://www.google-analytics.com/debug/mp/collect') && $r['events'][0]['params']['debug_mode'] === true);
    }

    #[Test]
    public function the_widget_renders_for_permitted_users()
    {
        $this->actingAs($this->super());

        $component = (new \KareliaLabs\Conversions\Widgets\ConversionsWidget)->component();

        $this->assertNotNull($component);
    }
}
