{{--
    The four submission-window states. Plan 3 turned the Open branch's
    `href="#"` into a real link to the submission form; Plan 6 Task 3 replaced
    that named-route call with $conference->publicSubmitUrl(), so the link
    stays on the organization's verified custom domain when it has one. The
    grep in Task 3 Step 7 is what keeps it that way, so this comment does not
    spell the route name it replaced.
--}}
@php use App\Enums\SubmissionWindow; @endphp

<div class="mt-8">
    @switch($conference->submissionWindow())
        @case(SubmissionWindow::Open)
            <a href="{{ $conference->publicSubmitUrl() }}"
               class="inline-flex items-center rounded-lg bg-[var(--org-primary)] px-6 py-3 text-base font-semibold text-[var(--org-on-primary)] shadow-sm hover:opacity-90">
                Submit abstract
            </a>
            <p class="mt-3 text-sm text-slate-600">
                Deadline
                <time datetime="{{ $conference->submission_deadline->toIso8601String() }}"
                      data-countdown
                      data-deadline="{{ $conference->submission_deadline->toIso8601String() }}">
                    {{ $conference->deadlineInConferenceTimezone()?->format('j F Y, H:i') }} ({{ $conference->timezone }})
                </time>
            </p>
            @break

        @case(SubmissionWindow::Upcoming)
            <p class="inline-flex items-center rounded-lg border border-slate-300 px-6 py-3 text-base font-semibold text-slate-600">
                Submissions open on {{ $conference->opensAtInConferenceTimezone()?->format('j F Y, H:i') }} ({{ $conference->timezone }})
            </p>
            @break

        @case(SubmissionWindow::Closed)
            <p class="inline-flex items-center rounded-lg border border-slate-300 px-6 py-3 text-base font-semibold text-slate-600">
                Submissions are closed
            </p>
            @break

        @default
            <p class="inline-flex items-center rounded-lg border border-slate-300 px-6 py-3 text-base font-semibold text-slate-600">
                Submission dates have not been announced yet
            </p>
    @endswitch
</div>
