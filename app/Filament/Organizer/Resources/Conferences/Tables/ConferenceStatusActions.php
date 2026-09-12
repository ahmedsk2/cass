<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Tables;

use App\Actions\Conferences\ArchiveConference;
use App\Actions\Conferences\CloseSubmissions;
use App\Actions\Conferences\MarkDecided;
use App\Actions\Conferences\PublishConference;
use App\Actions\Conferences\StartReviewing;
use App\Actions\Reviews\SendReviewerReminders;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Exceptions\ReviewNotAcceptable;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * One definition of each status transition, reused by the table row actions
 * and by the header of the view and edit pages, so the rules cannot drift
 * between the two places an organizer meets them.
 */
class ConferenceStatusActions
{
    public static function publish(): Action
    {
        return Action::make('publish')
            ->label(fn (Conference $record): string => $record->published_at === null ? 'Publish' : 'Reopen submissions')
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('success')
            ->requiresConfirmation()
            // The same action serves the first go-live and every later reopen
            // (the label above already says which), so the confirmation must
            // ask the question the organizer is actually answering: a
            // conference with a published_at has been public for weeks and its
            // short link is printed on posters.
            ->modalHeading(fn (Conference $record): string => $record->published_at === null
                ? 'Publish this conference?'
                : 'Reopen submissions?')
            ->modalDescription(fn (Conference $record): string => $record->published_at === null
                ? 'The public page goes live and a short link and QR code are generated. You can close submissions again at any time.'
                : 'Authors can submit again from now on. The public page and the printed short link are unchanged.')
            ->visible(fn (Conference $record): bool => $record->status->canTransitionTo(ConferenceStatus::Open)
                && Gate::allows('publish', $record))
            ->action(function (Conference $record, PublishConference $publish): void {
                Gate::authorize('publish', $record);

                $blockers = $publish->blockers($record);

                if ($blockers !== []) {
                    Notification::make()
                        ->danger()
                        ->title('This conference is not ready to publish')
                        ->body(implode(' ', $blockers))
                        ->persistent()
                        ->send();

                    return;
                }

                /** @var User $actor */
                $actor = auth()->user();
                $published = $publish->handle($record, $actor);

                // Filament renders a notification's title and body through
                // Str::sanitizeHtml(), whose shared config keeps `style` and
                // `class` on every element (fact 15). The conference name is
                // organizer-supplied and every member can edit it, so escape
                // it before it reaches the owner's screen.
                Notification::make()
                    ->success()
                    ->title(e($published->name).' is live')
                    ->body('Short link: '.e($published->shortLink?->url() ?? ''))
                    ->send();
            });
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label('Close submissions')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Close submissions?')
            ->modalDescription('Authors can no longer submit or edit. The public page stays online and shows that submissions are closed.')
            ->visible(fn (Conference $record): bool => $record->status->canTransitionTo(ConferenceStatus::Closed)
                && Gate::allows('close', $record))
            ->action(function (Conference $record, CloseSubmissions $close): void {
                Gate::authorize('close', $record);

                /** @var User $actor */
                $actor = auth()->user();
                $close->handle($record, $actor);

                Notification::make()->warning()->title('Submissions are closed')->send();
            });
    }

    public static function archive(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Archive this conference?')
            ->modalDescription('The public page and the short link stop working. Nothing is deleted and you keep full access here. This cannot be undone.')
            ->visible(fn (Conference $record): bool => $record->status->canTransitionTo(ConferenceStatus::Archived)
                && Gate::allows('archive', $record))
            ->action(function (Conference $record, ArchiveConference $archive): void {
                Gate::authorize('archive', $record);

                /** @var User $actor */
                $actor = auth()->user();
                $archive->handle($record, $actor);

                Notification::make()->success()->title('Conference archived')->send();
            });
    }

    public static function share(): Action
    {
        return Action::make('share')
            ->label('Share and print')
            ->icon(Heroicon::OutlinedQrCode)
            ->color('gray')
            ->visible(fn (Conference $record): bool => $record->shortLink !== null && Gate::allows('view', $record))
            ->url(fn (Conference $record): string => ConferenceResource::getUrl('short-link', ['record' => $record]));
    }

    public static function emails(): Action
    {
        return Action::make('emails')
            ->label('Email templates')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (Conference $record): bool => Gate::allows('view', $record))
            ->url(fn (Conference $record): string => ConferenceResource::getUrl('emails', ['record' => $record]));
    }

    public static function ranking(): Action
    {
        return Action::make('ranking')
            ->label(__('decisions.ranking.action'))
            ->icon(Heroicon::OutlinedTrophy)
            ->color('gray')
            // Only once there is something to rank. Before `reviewing` every
            // number on that page is null and every decision action is refused,
            // which is a screen that answers a question nobody asked.
            //
            // Archived is in the list on purpose: archiving takes a conference
            // off the public site and changes nothing about the committee's
            // record of what it decided, and an organizer asked six months
            // later "what did we accept" must still be able to look.
            ->visible(fn (Conference $record): bool => in_array(
                $record->status,
                [ConferenceStatus::Reviewing, ConferenceStatus::Decided, ConferenceStatus::Archived],
                true,
            ) && Gate::allows('view', $record))
            ->url(fn (Conference $record): string => ConferenceResource::getUrl('ranking', ['record' => $record]));
    }

    public static function reviewers(): Action
    {
        return Action::make('reviewers')
            ->label(__('reviewer.actions.page_link'))
            ->icon(Heroicon::OutlinedUserGroup)
            ->color('gray')
            ->visible(fn (Conference $record): bool => Gate::allows('view', $record))
            ->url(fn (Conference $record): string => ConferenceResource::getUrl('reviewers', ['record' => $record]));
    }

    public static function assignments(): Action
    {
        return Action::make('assignments')
            ->label(__('reviewer.assign.page_link'))
            ->icon(Heroicon::OutlinedScale)
            ->color('gray')
            ->visible(fn (Conference $record): bool => $record->review_mode === ReviewMode::Assigned
                && Gate::allows('view', $record))
            ->url(fn (Conference $record): string => ConferenceResource::getUrl('assignments', ['record' => $record]));
    }

    public static function remindReviewers(): Action
    {
        return Action::make('remindReviewers')
            ->label(__('reviewer.remind.action'))
            ->icon(Heroicon::OutlinedBellAlert)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('reviewer.remind.heading'))
            ->modalDescription(__('reviewer.remind.description'))
            // Hidden rather than disabled when it cannot be used: the three
            // reasons (not reviewing, sent recently, nobody behind) are all
            // states where the button would be noise.
            ->visible(fn (Conference $record): bool => Gate::allows('view', $record)
                && app(SendReviewerReminders::class)->manualBlockers($record) === [])
            ->action(function (Conference $record, SendReviewerReminders $reminders): void {
                Gate::authorize('view', $record);

                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $sent = $reminders->manual($record, $actor);
                } catch (ReviewNotAcceptable $exception) {
                    Notification::make()->danger()
                        ->title(__('reviewer.notices.refused'))
                        ->body(e($exception->getMessage()))
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()
                    ->title(__('reviewer.remind.sent', ['count' => $sent]))
                    ->send();
            });
    }

    public static function startReviewing(): Action
    {
        return Action::make('startReviewing')
            ->label(__('reviewer.start.action'))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('reviewer.start.heading'))
            ->modalDescription(__('reviewer.start.description'))
            ->visible(fn (Conference $record): bool => $record->status === ConferenceStatus::Closed
                && Gate::allows('publish', $record))
            ->action(function (Conference $record, StartReviewing $start): void {
                Gate::authorize('publish', $record);

                $blockers = $start->blockers($record);

                if ($blockers !== []) {
                    // The same shape the publish action uses: report, do not
                    // throw, and name every missing piece at once.
                    Notification::make()
                        ->danger()
                        ->title(__('reviewer.start.not_ready'))
                        ->body(implode(' ', array_map('e', $blockers)))
                        ->persistent()
                        ->send();

                    return;
                }

                /** @var User $actor */
                $actor = auth()->user();
                $start->handle($record, $actor);

                Notification::make()->success()->title(__('reviewer.start.started'))->send();
            });
    }

    public static function markDecided(): Action
    {
        return Action::make('markDecided')
            ->label(__('decisions.mark.action'))
            ->icon(Heroicon::OutlinedFlag)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('decisions.mark.heading'))
            ->modalDescription(function (Conference $record, MarkDecided $mark): string {
                $unsent = $mark->unsentLetters($record);

                // The warning that is deliberately not a blocker: say the
                // number, then let the organizer decide.
                return $unsent > 0
                    ? __('decisions.mark.description').' '.__('decisions.mark.unsent_warning', ['count' => $unsent])
                    : __('decisions.mark.description');
            })
            ->visible(fn (Conference $record): bool => $record->status === ConferenceStatus::Reviewing
                && Gate::allows('publish', $record))
            ->action(function (Conference $record, MarkDecided $mark): void {
                Gate::authorize('publish', $record);

                $blockers = $mark->blockers($record);

                if ($blockers !== []) {
                    // The same shape publish() and startReviewing() use:
                    // report, do not throw, and name every missing piece at
                    // once.
                    Notification::make()
                        ->danger()
                        ->title(__('decisions.mark.not_ready'))
                        ->body(implode(' ', array_map('e', $blockers)))
                        ->persistent()
                        ->send();

                    return;
                }

                /** @var User $actor */
                $actor = auth()->user();
                $mark->handle($record, $actor);

                Notification::make()->success()->title(__('decisions.mark.done'))->send();
            });
    }

    /** @return list<Action> */
    public static function all(): array
    {
        return [
            static::share(), static::emails(), static::ranking(), static::reviewers(), static::assignments(),
            static::remindReviewers(), static::publish(), static::startReviewing(), static::markDecided(),
            static::close(), static::archive(),
        ];
    }
}
