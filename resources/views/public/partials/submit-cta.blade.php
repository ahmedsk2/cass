{{--
    The four submission-window states. Plan 3 turned the Open branch's
    `href="#"` into a real link to the submission form; Plan 6 Task 3 replaced
    that named-route call with $conference->publicSubmitUrl(), so the link
    stays on the organization's verified custom domain when it has one. The
    grep in Task 3 Step 7 is what keeps it that way, so this comment does not
    spell the route name it replaced.

    The three closed states and the button reuse submission.window.* and
    submission.buttons.submit word for word rather than restating them here.
--}}
@php use App\Enums\SubmissionWindow; @endphp

<div class="mt-8">
    @switch($conference->submissionWindow())
        @case(SubmissionWindow::Open)
            <a href="{{ $conference->publicSubmitUrl() }}"
               class="inline-flex items-center rounded-lg bg-[var(--org-primary)] px-6 py-3 text-base font-semibold text-[var(--org-on-primary)] shadow-sm hover:opacity-90">
                {{ __('submission.buttons.submit') }}
            </a>
            <p class="mt-3 text-sm text-slate-600">
                {{ __('conference.deadline_label') }}
                {{--
                    The countdown's four phrases ride on the element rather than
                    living in resources/js/countdown.js: __() cannot reach a
                    module, and English's `days === 1 ? '' : 's'` has no Arabic
                    analogue - Arabic has six plural forms. Lang::get() and not
                    __(): the script substitutes :days, :hours and :minutes
                    client-side, so the template is wanted with its own
                    placeholders intact.
                --}}
                <time datetime="{{ $conference->submission_deadline->toIso8601String() }}"
                      data-countdown
                      data-deadline="{{ $conference->submission_deadline->toIso8601String() }}"
                      data-countdown-days="{{ Lang::get('submission.countdown.days') }}"
                      data-countdown-days-one="{{ Lang::get('submission.countdown.days_one') }}"
                      data-countdown-hours="{{ Lang::get('submission.countdown.hours') }}"
                      data-countdown-hours-one="{{ Lang::get('submission.countdown.hours_one') }}"
                      data-countdown-minutes="{{ Lang::get('submission.countdown.minutes') }}"
                      data-countdown-minutes-one="{{ Lang::get('submission.countdown.minutes_one') }}"
                      data-countdown-passed="{{ Lang::get('submission.countdown.passed') }}">
                    {{ __('conference.deadline_value', [
                        'date' => $conference->deadlineInConferenceTimezone()?->format('j F Y, H:i'),
                        'timezone' => $conference->timezone,
                    ]) }}
                </time>
            </p>
            @break

        @case(SubmissionWindow::Upcoming)
            <p class="inline-flex items-center rounded-lg border border-slate-300 px-6 py-3 text-base font-semibold text-slate-600">
                {{ __('submission.window.upcoming', [
                    'date' => $conference->opensAtInConferenceTimezone()?->format('j F Y, H:i'),
                    'timezone' => $conference->timezone,
                ]) }}
            </p>
            @break

        @case(SubmissionWindow::Closed)
            <p class="inline-flex items-center rounded-lg border border-slate-300 px-6 py-3 text-base font-semibold text-slate-600">
                {{ __('submission.window.closed') }}
            </p>
            @break

        @default
            <p class="inline-flex items-center rounded-lg border border-slate-300 px-6 py-3 text-base font-semibold text-slate-600">
                {{ __('submission.window.not_configured') }}
            </p>
    @endswitch
</div>
