<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions\Tables;

use App\Actions\Submissions\ExportSubmissionsCsv;
use App\Actions\Submissions\SendSubmissionStatusLink;
use App\Actions\Submissions\WithdrawSubmission;
use App\Exceptions\SubmissionNotAcceptable;
use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One definition of each organizer-side action, reused by the table row and by
 * the view page's header - the same pattern Plan 2's ConferenceStatusActions
 * established, for the same reason: the rules must not drift between the two
 * places an organizer meets them.
 */
class SubmissionActions
{
    public static function withdraw(): Action
    {
        return Action::make('withdraw')
            ->label('Withdraw')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Withdraw this abstract?')
            ->modalDescription('Use this when the author has asked you to withdraw it. The reference is kept, the abstract stays visible to you, and the author sees the withdrawal on their status page. It cannot be undone here.')
            ->visible(fn (Submission $record): bool => $record->status->isOpenToAuthor() && Gate::allows('withdraw', $record))
            ->action(function (Submission $record, WithdrawSubmission $withdraw): void {
                Gate::authorize('withdraw', $record);

                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $withdraw->handle($record, $actor);
                } catch (SubmissionNotAcceptable $exception) {
                    Notification::make()->danger()->title('Not withdrawn')->body(e($exception->getMessage()))->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Abstract withdrawn')->send();
            });
    }

    public static function resendLink(): Action
    {
        return Action::make('resendLink')
            ->label('Resend status link')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Email a new status link?')
            ->modalDescription('The corresponding author gets a fresh link to their abstract. **Any link they already have stops working**, which is the point when a link has been lost or forwarded.')
            ->visible(fn (Submission $record): bool => Gate::allows('resendLink', $record))
            ->action(function (Submission $record, SendSubmissionStatusLink $send): void {
                Gate::authorize('resendLink', $record);

                try {
                    $log = $send->handle($record);
                } catch (SubmissionNotAcceptable $exception) {
                    Notification::make()->danger()->title('Nothing sent')->body(e($exception->getMessage()))->persistent()->send();

                    return;
                }

                // Filament renders a notification body as sanitised HTML whose
                // shared config keeps `style` and `class` (Plan 2 fact 15), and
                // an author's address is author-supplied - escape it.
                Notification::make()->success()->title('Link sent')->body('A new link is on its way to '.e($log->to_email).'.')->send();
            });
    }

    public static function export(): Action
    {
        return Action::make('export')
            ->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('export', Submission::class))
            ->action(function (HasTable $livewire, ExportSubmissionsCsv $export): ?StreamedResponse {
                Gate::authorize('export', Submission::class);

                // The table's own query: already tenant-scoped by
                // SubmissionResource::getEloquentQuery() and already carrying
                // whatever filters and search the organizer is looking at, so
                // the file matches the screen. Filament types it `?Builder`
                // with no generic (Tables\Contracts\HasTable), which Larastan
                // level 6 will not hand to a `Builder<Submission>` parameter -
                // hence the annotation and the null guard.
                /** @var Builder<Submission>|null $query */
                $query = $livewire->getFilteredTableQuery();

                if ($query === null) {
                    Notification::make()->danger()->title('Nothing to export')->send();

                    return null;
                }

                return $export->handle($query, 'submissions-'.now()->format('Y-m-d-His').'.csv');
            });
    }

    /** @return list<Action> */
    public static function all(): array
    {
        return [static::resendLink(), static::withdraw()];
    }

    public static function backToList(): Action
    {
        return Action::make('backToList')
            ->label('All submissions')
            ->icon(Heroicon::OutlinedInbox)
            ->color('gray')
            ->url(fn (): string => SubmissionResource::getUrl('index'));
    }
}
