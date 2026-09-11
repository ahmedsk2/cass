<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Tables;

use App\Actions\Conferences\ArchiveConference;
use App\Actions\Conferences\CloseSubmissions;
use App\Actions\Conferences\PublishConference;
use App\Enums\ConferenceStatus;
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
            ->modalHeading('Publish this conference?')
            ->modalDescription('The public page goes live and a short link and QR code are generated. You can close submissions again at any time.')
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

    /** @return list<Action> */
    public static function all(): array
    {
        return [static::share(), static::publish(), static::close(), static::archive()];
    }
}
