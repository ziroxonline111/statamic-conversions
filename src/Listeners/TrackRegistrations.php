<?php

namespace KareliaLabs\Conversions\Listeners;

use KareliaLabs\Conversions\Conversion;
use KareliaLabs\Conversions\Conversions;
use KareliaLabs\Conversions\Settings;
use Statamic\Events\UserRegistered;

class TrackRegistrations
{
    public function handle(UserRegistered $event): void
    {
        if (! Settings::trackRegistrations()) {
            return;
        }

        $user = $event->user;

        $conversion = Conversion::make('sign_up')
            ->fromRequest(request())
            ->user([
                'email' => $user->email(),
                'first_name' => $user->get('first_name') ?? (str_contains((string) $user->name(), ' ') ? strtok((string) $user->name(), ' ') : null),
                'last_name' => $user->get('last_name'),
                'external_id' => (string) $user->id(),
            ])
            ->source('user', 'registration', (string) $user->id(), __('Registration'));

        app(Conversions::class)->track($conversion);
    }
}
