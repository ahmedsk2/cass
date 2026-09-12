<x-filament-panels::page>
    @php($conferences = $this->getConferences())

    @if ($conferences->isEmpty())
        <x-filament::section :heading="__('reviewer.dashboard.empty_heading')">
            <p style="font-size:0.875rem">{{ __('reviewer.dashboard.empty_body') }}</p>
        </x-filament::section>
    @else
        @foreach ($conferences as $conference)
            <x-filament::section :heading="$conference->name">
                <p style="font-size:0.875rem">{{ $conference->organization->name }}</p>

                <p style="margin-top:0.5rem;font-size:0.875rem">
                    @if ($conference->review_deadline)
                        {{ __('reviewer.dashboard.deadline', [
                            'date' => $conference->reviewDeadlineInConferenceTimezone()->format('j F Y, H:i'),
                            'timezone' => $conference->timezone,
                        ]) }}
                    @else
                        {{ __('reviewer.dashboard.no_deadline') }}
                    @endif
                </p>

                @unless ($conference->isOpenToReviewers())
                    <p style="margin-top:0.5rem;font-size:0.875rem;opacity:0.75">
                        {{ __('reviewer.dashboard.not_started') }}
                    </p>
                @endunless

                @if ($conference->isOpenToReviewers())
                    @php($progress = $this->progressFor($conference))
                    <p style="margin-top:0.5rem;font-size:0.875rem;font-weight:600">
                        {{ __('reviewer.progress.yours', ['submitted' => $progress['submitted'], 'expected' => $progress['expected']]) }}
                    </p>

                    @unless ($conference->acceptsReviewWrites())
                        <p style="margin-top:0.5rem;font-size:0.875rem;opacity:0.75">
                            {{ __('reviewer.dashboard.decided') }}
                        </p>
                    @endunless

                    <p style="margin-top:0.75rem">
                        <x-filament::link :href="\App\Filament\Reviewer\Resources\Submissions\SubmissionResource::urlForConference($conference)">
                            {{ __('reviewer.dashboard.open_queue') }}
                        </x-filament::link>
                    </p>
                @endif
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
