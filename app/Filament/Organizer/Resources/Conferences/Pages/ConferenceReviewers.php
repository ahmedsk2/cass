<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Pages;

use App\Actions\Reviewers\InviteReviewer;
use App\Actions\Reviewers\InviteReviewerList;
use App\Actions\Reviewers\RemoveConferenceReviewer;
use App\Actions\Reviewers\RevokeReviewerInvitation;
use App\Enums\InvitationStatus;
use App\Exceptions\MemberChangeRefused;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\ReviewerInvitation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 5.4 steps 1 and 2 from the organizer's side. One array-backed table
 * (fact 15) listing reviewers and open invitations together; the `__key` is
 * `reviewer:{id}` or `invitation:{id}`.
 */
class ConferenceReviewers extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ConferenceResource::class;

    protected string $view = 'filament.organizer.resources.conferences.pages.reviewers';

    public function mount(int|string $record): void
    {
        // resolveRecord() runs through ConferenceResource::getEloquentQuery(),
        // which carries the panel's tenancy global scope, so another
        // organization's conference is already a 404. The policy check is
        // defence in depth, exactly as on ConferenceShortLink.
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 404);
    }

    public function getTitle(): string
    {
        return __('reviewer.page.title');
    }

    public function getSubheading(): ?string
    {
        return __('reviewer.page.subheading');
    }

    public function getConference(): Conference
    {
        /** @var Conference $conference */
        $conference = $this->getRecord();

        return $conference;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('name')->label(__('reviewer.columns.name'))->weight('semibold')
                    ->description(fn (array $record): string => $record['email']),
                TextColumn::make('affiliation')->label(__('reviewer.columns.affiliation'))->placeholder('-'),
                TextColumn::make('state')->label(__('reviewer.columns.state'))->badge()
                    ->color(fn (array $record): string => $record['state_color']),
                TextColumn::make('since')->label(__('reviewer.columns.since')),
            ])
            ->recordActions([
                $this->removeAction(),
                $this->reinviteAction(),
                $this->resendAction(),
                $this->revokeAction(),
            ])
            ->paginated(false);
    }

    /**
     * Reviewers and open invitations in one list, keyed `reviewer:{id}` or
     * `invitation:{id}` - the `__key` every row action and every test
     * addresses (fact 15).
     *
     * A plain array rather than a Collection, for the reason the Members page
     * records at length: Collection's TValue is invariant, so a Collection built
     * by merging two *different* literal row shapes cannot be narrowed back to
     * the declared shape, while `array<string, array{...}>` is covariant in its
     * value and is accepted. Filament keys a plain array exactly as it keys a
     * Collection (Tables\Concerns\HasRecords::getTableRecords() calls collect()
     * on an array first).
     *
     * @return array<string, array{kind: string, id: int, name: string, email: string, affiliation: string, state: string, state_color: string, since: string, is_active: bool}>
     */
    private function rows(): array
    {
        $conference = $this->getConference();
        $rows = [];

        foreach ($conference->reviewers()->with('user')->get() as $reviewer) {
            $rows['reviewer:'.$reviewer->getKey()] = [
                'kind' => 'reviewer',
                'id' => (int) $reviewer->getKey(),
                // Not `?->name ?? '-'`: conference_reviewers.user_id is a
                // non-nullable cascading foreign key, so the user is always
                // there and Larastan rejects the nullsafe hop as dead code.
                'name' => (string) $reviewer->user->name,
                'email' => (string) $reviewer->user->email,
                'affiliation' => (string) ($reviewer->affiliation ?? '-'),
                'state' => $reviewer->status->getLabel(),
                'state_color' => $reviewer->status->getColor(),
                'since' => $reviewer->accepted_at?->format('j M Y') ?? '-',
                'is_active' => $reviewer->isActive(),
            ];
        }

        $invitations = $conference->reviewerInvitations()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->get();

        foreach ($invitations as $invitation) {
            $status = $invitation->invitationStatus();

            $rows['invitation:'.$invitation->getKey()] = [
                'kind' => 'invitation',
                'id' => (int) $invitation->getKey(),
                'name' => (string) ($invitation->invitedName() ?? $invitation->email),
                'email' => (string) $invitation->email,
                'affiliation' => (string) ($invitation->affiliation ?? '-'),
                'state' => $status === InvitationStatus::Expired
                    ? __('reviewer.state.expired')
                    : __('reviewer.state.invited'),
                'state_color' => $status->getColor(),
                'since' => '-',
                'is_active' => false,
            ];
        }

        return $rows;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('reviewer.actions.invite'))
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn (): bool => Gate::allows('create', ReviewerInvitation::class))
                ->modalHeading(__('reviewer.actions.invite_heading'))
                ->schema([
                    TextInput::make('name')->label(__('reviewer.fields.name'))->maxLength(255),
                    TextInput::make('email')->label(__('reviewer.fields.email'))->email()->required()->maxLength(255),
                    TextInput::make('affiliation')->label(__('reviewer.fields.affiliation'))->maxLength(255)
                        ->helperText(__('reviewer.fields.affiliation_help')),
                ])
                ->action(function (array $data, InviteReviewer $invite): void {
                    Gate::authorize('create', ReviewerInvitation::class);

                    try {
                        $invite->handle(
                            $this->getConference(),
                            (string) $data['email'],
                            $data['name'] === null ? null : (string) $data['name'],
                            $data['affiliation'] === null ? null : (string) $data['affiliation'],
                            $this->actor(),
                        );
                    } catch (MemberChangeRefused $exception) {
                        $this->refuse($exception->getMessage());

                        return;
                    }

                    Notification::make()->success()
                        ->title(__('reviewer.notices.invited'))
                        ->body(__('reviewer.notices.invited_body', ['email' => e((string) $data['email'])]))
                        ->send();
                }),

            Action::make('inviteList')
                ->label(__('reviewer.actions.invite_list'))
                ->icon(Heroicon::OutlinedUsers)
                ->color('gray')
                ->visible(fn (): bool => Gate::allows('create', ReviewerInvitation::class))
                ->modalHeading(__('reviewer.actions.invite_list_heading'))
                ->modalDescription(__('reviewer.actions.invite_list_description'))
                ->schema([
                    Textarea::make('list')->label(__('reviewer.fields.list'))->rows(12)->required()
                        ->maxLength(20000)
                        ->helperText(__('reviewer.fields.list_help')),
                ])
                ->action(function (array $data, InviteReviewerList $invite): void {
                    Gate::authorize('create', ReviewerInvitation::class);

                    $result = $invite->handle($this->getConference(), (string) $data['list'], $this->actor());

                    $lines = array_merge($result['errors'], $result['skipped']);

                    Notification::make()
                        ->status($result['invited'] > 0 ? 'success' : 'warning')
                        ->title(__('reviewer.notices.list_done', ['count' => $result['invited']]))
                        // Every line the organizer typed comes back escaped: it
                        // is their own text, but a Filament notification body is
                        // sanitised HTML that keeps `style` and `class`
                        // (Plan 2 fact 15).
                        ->body($lines === [] ? null : e(implode(' ', array_slice($lines, 0, 10))))
                        // duration(), not persistent($condition): persistent()
                        // takes no arguments in Filament 5.8.1
                        // (notifications/src/Concerns/HasDuration.php:30), so an
                        // argument would be silently discarded and a clean run
                        // would leave a notification to dismiss by hand.
                        // 'persistent' is the duration sentinel, which makes
                        // duration() the conditional form.
                        ->duration($lines === [] ? 6000 : 'persistent')
                        ->send();
                }),

            Action::make('backToConference')
                ->label(__('reviewer.actions.back'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (): string => ConferenceResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    private function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('reviewer.actions.remove'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => __('reviewer.actions.remove_heading', ['name' => $record['name']]))
            ->modalDescription(__('reviewer.actions.remove_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'reviewer'
                && $record['is_active']
                && Gate::allows('update', $this->reviewer($record['id'])))
            ->action(function (array $record, RemoveConferenceReviewer $remove): void {
                $reviewer = $this->reviewer($record['id']);

                Gate::authorize('update', $reviewer);

                $remove->handle($reviewer, $this->actor());

                Notification::make()->success()->title(__('reviewer.notices.removed'))->send();
            });
    }

    private function reinviteAction(): Action
    {
        return Action::make('reinvite')
            ->label(__('reviewer.actions.reinvite'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('reviewer.actions.reinvite_heading'))
            ->visible(fn (array $record): bool => $record['kind'] === 'reviewer'
                && ! $record['is_active']
                && Gate::allows('create', ReviewerInvitation::class))
            ->action(function (array $record, InviteReviewer $invite): void {
                $reviewer = $this->reviewer($record['id']);

                Gate::authorize('create', ReviewerInvitation::class);

                try {
                    $invite->handle(
                        $this->getConference(),
                        (string) $reviewer->user?->email,
                        $reviewer->user?->name === null ? null : (string) $reviewer->user->name,
                        $reviewer->affiliation === null ? null : (string) $reviewer->affiliation,
                        $this->actor(),
                    );
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception->getMessage());

                    return;
                }

                Notification::make()->success()->title(__('reviewer.notices.invited'))->send();
            });
    }

    private function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('reviewer.actions.resend'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('reviewer.actions.resend_heading'))
            ->modalDescription(__('reviewer.actions.resend_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'invitation'
                && Gate::allows('create', ReviewerInvitation::class))
            ->action(function (array $record, InviteReviewer $invite): void {
                $invitation = $this->invitation($record['id']);

                Gate::authorize('update', $invitation);

                try {
                    $invite->handle(
                        $this->getConference(),
                        (string) $invitation->email,
                        $invitation->name === null ? null : (string) $invitation->name,
                        $invitation->affiliation === null ? null : (string) $invitation->affiliation,
                        $this->actor(),
                    );
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception->getMessage());

                    return;
                }

                Notification::make()->success()->title(__('reviewer.notices.resent'))->send();
            });
    }

    private function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('reviewer.actions.revoke'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('reviewer.actions.revoke_heading'))
            ->modalDescription(__('reviewer.actions.revoke_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'invitation'
                && Gate::allows('create', ReviewerInvitation::class))
            ->action(function (array $record, RevokeReviewerInvitation $revoke): void {
                $invitation = $this->invitation($record['id']);

                Gate::authorize('delete', $invitation);

                $revoke->handle($invitation, $this->actor());

                Notification::make()->success()->title(__('reviewer.notices.revoked'))->send();
            });
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function reviewer(int $id): ConferenceReviewer
    {
        /** @var ConferenceReviewer $reviewer */
        $reviewer = $this->getConference()->reviewers()->whereKey($id)->firstOrFail();

        return $reviewer;
    }

    private function invitation(int $id): ReviewerInvitation
    {
        /** @var ReviewerInvitation $invitation */
        $invitation = $this->getConference()->reviewerInvitations()->whereKey($id)->firstOrFail();

        return $invitation;
    }

    private function refuse(string $message): void
    {
        Notification::make()->danger()
            ->title(__('reviewer.notices.refused'))
            ->body(e($message))
            ->persistent()
            ->send();
    }
}
