<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages;

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\InviteMember;
use App\Actions\Organizations\RemoveMember;
use App\Actions\Organizations\RevokeInvitation;
use App\Actions\Organizations\SetSubmissionNotifications;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Spec section 4, "Manage organization members".
 *
 * One **array-backed** table (fact 15) listing members and open invitations
 * together, because that is the question the person on this page is asking. Row
 * actions receive `array $record`; the `__key` is `member:{user id}` or
 * `invitation:{invitation id}`, which is also what every test addresses.
 */
class Members extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.organizer.pages.members';

    /**
     * Every member of the tenant, not only the managers: a plain member needs
     * this page to turn their own submission notifications off. The management
     * actions are each gated separately.
     *
     * Note what this does NOT admit: a platform admin who is not a member of the
     * organization. OrganizationMemberPolicy::before() and
     * OrganizationInvitationPolicy::before() both answer true for them, but this
     * page lives in the membership-gated organizer panel
     * (User::canAccessPanel('organizer') is organizations()->exists(),
     * app/Models/User.php:70), so spec section 4's platform-admin cell for
     * "Manage organization members" has no screen behind it - exactly as Plan 3
     * left submissions. Plan 6 adds the read-only admin resources; it is in the
     * backlog and in the "does NOT build" list, not a surprise.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User
            && $tenant instanceof Organization
            && $user->roleIn($tenant) !== null;
    }

    public static function getNavigationLabel(): string
    {
        return __('members.page.title');
    }

    public function getTitle(): string
    {
        return __('members.page.title');
    }

    public function getSubheading(): ?string
    {
        return __('members.page.subheading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('name')->label(__('members.columns.name'))->weight('semibold')
                    ->description(fn (array $record): string => $record['email']),
                TextColumn::make('role')->label(__('members.columns.role'))->badge(),
                TextColumn::make('state')->label(__('members.columns.state'))->badge()
                    ->color(fn (array $record): string => $record['state_color']),
                TextColumn::make('joined')->label(__('members.columns.joined')),
                TextColumn::make('notify_label')->label(__('members.columns.notify')),
            ])
            ->recordActions([
                $this->changeRoleAction(),
                $this->notificationsAction(),
                $this->removeAction(),
                $this->resendAction(),
                $this->revokeAction(),
            ])
            ->paginated(false);
    }

    /**
     * Members and open invitations in one list, keyed `member:{user id}` or
     * `invitation:{invitation id}` - the `__key` every row action and every
     * test addresses (fact 15).
     *
     * The value type is the exact shape rather than `array<string, mixed>`,
     * which is what tells every column and action closure below what `$record`
     * really holds. Two shapes this is *not*, and why, because both were tried:
     *
     *  - Not a `Collection`. Its TValue is invariant, so PHPStan refuses
     *    `Collection<string, array{...}>` even as the return value of a method
     *    declaring exactly that type - the returned value type is a union of
     *    the two literal row shapes, a strict subtype, and the error prints
     *    both sides identically because the shapes generalise to the same
     *    string. `array<string, array{...}>` is covariant in its value and is
     *    accepted; Filament keys a plain array exactly as it keys a Collection,
     *    because `getTableRecords()` calls `collect()` on an array first
     *    (Tables\Concerns\HasRecords::getTableRecords(), the `is_array` branch).
     *  - Not `$organization->members()->get()` with `$user->pivot`. Larastan
     *    types a relation's get() as `Collection<int, User>`, without the
     *    `User&object{pivot: OrganizationMember}` that BelongsToMany declares,
     *    so reading the pivot off the user is an undefined property. The loop
     *    below walks the membership rows instead, which is the truthful query
     *    for a page that lists memberships anyway.
     *
     * @return array<string, array{kind: string, id: int, name: string, email: string, role: string, role_value: string, state: string, state_color: string, joined: string, notify: bool|null, notify_label: string, is_self: bool, is_owner: bool}>
     */
    private function rows(): array
    {
        $organization = $this->getOrganization();
        $actor = $this->actor();
        $rows = [];

        // The membership rows themselves, with their user eager-loaded, rather
        // than `$organization->members()->get()` and `$user->pivot`: Larastan
        // types a relation's get() as `Collection<int, User>` and drops the
        // `User&object{pivot: OrganizationMember}` that BelongsToMany really
        // yields (vendor/laravel/.../Relations/BelongsToMany.php:28), so
        // `$user->pivot` reads as an undefined property. This is also the
        // truthful query for a page that lists memberships, and it is what
        // OrganizationMember::user() was added for. Sorted in PHP because the
        // name lives on the other table; case-folded, to match the
        // case-insensitive collation an ORDER BY would have used.
        $members = OrganizationMember::query()
            ->with('user')
            ->where('organization_id', $organization->getKey())
            ->get()
            ->sortBy(fn (OrganizationMember $row): string => mb_strtolower((string) $row->user?->name));

        foreach ($members as $pivot) {
            $user = $pivot->user;

            if ($user === null) {
                // organization_members.user_id is a cascading foreign key, so a
                // row without its user cannot exist; skipping is still the only
                // sane answer if one ever does.
                continue;
            }

            $isSelf = $actor !== null && $actor->getKey() === $user->getKey();

            $rows['member:'.$user->getKey()] = [
                'kind' => 'member',
                'id' => (int) $user->getKey(),
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'role' => $pivot->role->getLabel(),
                'role_value' => $pivot->role->value,
                'state' => __('members.state.member'),
                'state_color' => 'success',
                'joined' => $pivot->created_at?->format('j M Y') ?? '-',
                'notify' => (bool) $pivot->notify_on_submission,
                // Only your own preference is anybody's business.
                'notify_label' => $isSelf
                    ? ($pivot->notify_on_submission ? __('members.notify.on') : __('members.notify.off'))
                    : '-',
                'is_self' => $isSelf,
                'is_owner' => $pivot->role === OrganizationRole::Owner,
            ];
        }

        $invitations = $organization->invitations()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->get();

        foreach ($invitations as $invitation) {
            $status = $invitation->invitationStatus();

            $rows['invitation:'.$invitation->getKey()] = [
                'kind' => 'invitation',
                'id' => (int) $invitation->getKey(),
                'name' => (string) $invitation->email,
                'email' => (string) $invitation->email,
                'role' => $invitation->role->getLabel(),
                'role_value' => $invitation->role->value,
                'state' => $status === InvitationStatus::Expired
                    ? __('members.state.expired')
                    : __('members.state.invited'),
                'state_color' => $status->getColor(),
                'joined' => '-',
                'notify' => null,
                'notify_label' => '-',
                'is_self' => false,
                'is_owner' => false,
            ];
        }

        return $rows;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('members.actions.invite'))
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn (): bool => Gate::allows('create', OrganizationInvitation::class))
                ->modalHeading(__('members.actions.invite_heading'))
                ->modalDescription(__('members.actions.invite_description'))
                ->schema([
                    TextInput::make('email')->label(__('members.fields.email'))
                        ->email()->required()->maxLength(255),
                    Select::make('role')->label(__('members.fields.role'))
                        // A plain options array, not `->options(OrganizationRole::class)`:
                        // an enum-backed Select registers a state cast (fact 27)
                        // and `$data['role']` inside the action closure would be
                        // an enum case here and a string there depending on how
                        // it was filled. A string both ways removes the question,
                        // and the list has to be filtered by the actor's own role
                        // anyway.
                        ->options(fn (): array => $this->roleOptions())
                        ->default(OrganizationRole::Member->value)
                        ->required()
                        ->helperText(__('members.fields.role_help')),
                ])
                ->action(function (array $data, InviteMember $invite): void {
                    Gate::authorize('create', OrganizationInvitation::class);

                    try {
                        $invite->handle(
                            $this->getOrganization(),
                            (string) $data['email'],
                            OrganizationRole::from((string) $data['role']),
                            $this->requireActor(),
                        );
                    } catch (MemberChangeRefused $exception) {
                        $this->refuse($exception);

                        return;
                    }

                    Notification::make()->success()
                        ->title(__('members.notices.invited'))
                        ->body(__('members.notices.invited_body', ['email' => e((string) $data['email'])]))
                        ->send();
                }),
        ];
    }

    private function changeRoleAction(): Action
    {
        return Action::make('changeRole')
            ->label(__('members.actions.change_role'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (array $record): bool => $record['kind'] === 'member'
                && ! $record['is_self']
                && $this->canManageMember($record['id']))
            ->modalHeading(fn (array $record): string => __('members.actions.change_role_heading', ['name' => $record['name']]))
            ->fillForm(fn (array $record): array => ['role' => $record['role_value']])
            ->schema([
                Select::make('role')->label(__('members.fields.role'))
                    ->options(fn (): array => $this->roleOptions())
                    ->required(),
            ])
            ->action(function (array $record, array $data, ChangeMemberRole $change): void {
                $member = $this->memberUser($record['id']);

                Gate::authorize('update', $this->pivotFor($member));

                try {
                    $change->handle(
                        $this->getOrganization(),
                        $member,
                        OrganizationRole::from((string) $data['role']),
                        $this->requireActor(),
                    );
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception);

                    return;
                }

                Notification::make()->success()->title(__('members.notices.role_changed'))->send();
            });
    }

    private function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('members.actions.remove'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => __('members.actions.remove_heading', ['name' => $record['name']]))
            ->modalDescription(__('members.actions.remove_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'member'
                && ! $record['is_self']
                && $this->canManageMember($record['id']))
            ->action(function (array $record, RemoveMember $remove): void {
                $member = $this->memberUser($record['id']);

                Gate::authorize('delete', $this->pivotFor($member));

                try {
                    $remove->handle($this->getOrganization(), $member, $this->requireActor());
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception);

                    return;
                }

                Notification::make()->success()->title(__('members.notices.removed'))->send();
            });
    }

    private function notificationsAction(): Action
    {
        return Action::make('notifications')
            ->label(fn (array $record): string => $record['notify'] === true
                ? __('members.actions.notifications_off')
                : __('members.actions.notifications_on'))
            ->icon(Heroicon::OutlinedBell)
            ->color('gray')
            ->visible(fn (array $record): bool => $record['kind'] === 'member' && $record['is_self'])
            // NOT `$set`: Filament resolves a closure parameter by *name* before
            // it resolves one by type, and `set` is one of its reserved names -
            // it hands back the schema's Set utility, which on an action with no
            // schema component is null, and the closure dies on
            // `makeSetUtility() on null` before the body ever runs
            // (vendor/filament/actions/src/Action.php:579). `get`, `state`,
            // `data`, `record`, `table` and `component` are the same trap.
            ->action(function (array $record, SetSubmissionNotifications $setNotifications): void {
                $actor = $this->requireActor();

                Gate::authorize('updateNotifications', $this->pivotFor($actor));

                try {
                    $setNotifications->handle($this->getOrganization(), $actor, $record['notify'] !== true, $actor);
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception);

                    return;
                }

                Notification::make()->success()->title(__('members.notices.notifications_saved'))->send();
            });
    }

    private function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('members.actions.resend'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('members.actions.resend_heading'))
            ->modalDescription(__('members.actions.resend_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'invitation'
                && Gate::allows('create', OrganizationInvitation::class))
            ->action(function (array $record, InviteMember $invite): void {
                $invitation = $this->invitation($record['id']);

                Gate::authorize('update', $invitation);

                try {
                    // The same method as Invite: one live row per address, with
                    // a new token that kills the old link.
                    $invite->handle(
                        $this->getOrganization(),
                        (string) $invitation->email,
                        $invitation->role,
                        $this->requireActor(),
                    );
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception);

                    return;
                }

                Notification::make()->success()->title(__('members.notices.resent'))->send();
            });
    }

    private function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('members.actions.revoke'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('members.actions.revoke_heading'))
            ->modalDescription(__('members.actions.revoke_description'))
            ->visible(fn (array $record): bool => $record['kind'] === 'invitation'
                && Gate::allows('create', OrganizationInvitation::class))
            ->action(function (array $record, RevokeInvitation $revoke): void {
                $invitation = $this->invitation($record['id']);

                Gate::authorize('delete', $invitation);

                $revoke->handle($invitation, $this->requireActor());

                Notification::make()->success()->title(__('members.notices.revoked'))->send();
            });
    }

    /**
     * The owner role is only offered by an owner. `InviteMember::blockers()`
     * and `ChangeMemberRole::blockers()` refuse it as well, so a forged option
     * value is refused rather than merely invisible.
     *
     * @return array<string, string>
     */
    private function roleOptions(): array
    {
        $actorRole = $this->actor()?->roleIn($this->getOrganization());

        $options = [];

        foreach (OrganizationRole::cases() as $role) {
            if ($role === OrganizationRole::Owner && $actorRole !== OrganizationRole::Owner) {
                continue;
            }

            $options[$role->value] = $role->getLabel();
        }

        return $options;
    }

    public function getOrganization(): Organization
    {
        /** @var Organization $tenant */
        $tenant = Filament::getTenant();

        return $tenant;
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function requireActor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function canManageMember(int $userId): bool
    {
        $member = User::query()->find($userId);
        $pivot = $member === null ? null : $this->findPivot($member);

        return $pivot !== null && Gate::allows('update', $pivot);
    }

    /** The pivot row the policy is handed, fetched rather than reconstructed. */
    private function pivotFor(User $member): OrganizationMember
    {
        /** @var OrganizationMember $pivot */
        $pivot = $this->pivotQuery($member)->firstOrFail();

        return $pivot;
    }

    /**
     * The same row, or null when it has just gone. Filament memoises the
     * table's records for the whole request
     * (Tables\Concerns\HasRecords::getTableRecords() returns `$this->records`
     * when it is already set), so the render that follows a successful Remove
     * still evaluates every row action's visible() against the member who is no
     * longer there. A visibility question answers "no" for a row that has gone;
     * pivotFor(), which feeds Gate::authorize() on the write path, still
     * insists on a real row.
     */
    private function findPivot(User $member): ?OrganizationMember
    {
        return $this->pivotQuery($member)->first();
    }

    /** @return Builder<OrganizationMember> */
    private function pivotQuery(User $member): Builder
    {
        return OrganizationMember::query()
            ->where('organization_id', $this->getOrganization()->getKey())
            ->where('user_id', $member->getKey());
    }

    private function memberUser(int $userId): User
    {
        /** @var User $user */
        $user = $this->getOrganization()->members()->whereKey($userId)->firstOrFail();

        return $user;
    }

    private function invitation(int $id): OrganizationInvitation
    {
        /** @var OrganizationInvitation $invitation */
        $invitation = $this->getOrganization()->invitations()->whereKey($id)->firstOrFail();

        return $invitation;
    }

    private function refuse(MemberChangeRefused $exception): void
    {
        // Filament renders a notification body as sanitised HTML whose shared
        // config keeps `style` and `class` (Plan 2 fact 15), and these messages
        // interpolate an address somebody typed - escape it.
        Notification::make()->danger()
            ->title(__('members.notices.refused'))
            ->body(e($exception->getMessage()))
            ->persistent()
            ->send();
    }
}
