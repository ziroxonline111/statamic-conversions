<?php

namespace KareliaLabs\Conversions\Listeners;

use KareliaLabs\Conversions\Conversion;
use KareliaLabs\Conversions\Conversions;
use KareliaLabs\Conversions\Settings;
use KareliaLabs\Conversions\UserData;
use Statamic\Events\SubmissionCreated;

class TrackFormSubmissions
{
    public function handle(SubmissionCreated $event): void
    {
        $submission = $event->submission;
        $form = $submission?->form();

        if (! $form || ! ($rule = Settings::formRule($form->handle()))) {
            return;
        }

        // Only visitors submitting on the front end count, not submissions made in code or the CP.
        $request = request();
        if (! $request || $request->is(trim(config('statamic.cp.route', 'cp'), '/').'/*')) {
            return;
        }

        $types = [];
        try {
            $types = $form->blueprint()->fields()->all()->map(fn ($field) => $field->type())->all();
        } catch (\Throwable) {
        }

        $conversion = Conversion::make($rule['event'], $rule['custom_name'] ?? null)
            ->fromRequest($request)
            ->user(UserData::fromFormData($submission->data()->all(), $types))
            ->source('form', $form->handle(), (string) $submission->id(), $form->title());

        if ($rule['value'] !== null) {
            $conversion->value($rule['value'], $rule['currency']);
        }

        app(Conversions::class)->track($conversion);
    }
}
