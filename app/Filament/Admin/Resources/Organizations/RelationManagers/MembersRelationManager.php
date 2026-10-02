<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations\RelationManagers;

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\RemoveMember;
use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Spec section 4's platform-admin cell for "Manage organization members". The
 * organizer panel is membership-gated (canAccessPanel('organizer') is
 * organizations()->exists()), so a platform admin who is not a member cannot
 * open the organizer panel's Members page for anybody; this is that page's
 * admin twin.
 *
 * `members` is a BelongsToMany of User through the OrganizationMember pivot,
 * so the interesting columns are on `pivot.*` and the `role` cast lives on the
 * pivot model rather than on User - which is why the badge is formatted from
 * the raw pivot value.
 *
 * isReadOnly() stays true: it is what refuses Filament's own Create, Edit,
 * Attach and Detach actions, which would write the pivot directly. The two
 * writes here are custom actions - RelationManager::getDefaultActionAuthorizationResponse()
 * answers null for those - that call ChangeMemberRole and RemoveMember with
 * the platform admin as the actor, so the last-owner, own-row and
 * invitation-withdrawal rules are the organizer page's, not a copy of them.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = null;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.members.title'))
            ->columns([
                TextColumn::make('name')->label(__('admin.members.columns.name')),
                TextColumn::make('email')->label(__('admin.members.columns.email')),
                TextColumn::make('pivot.role')
                    ->label(__('admin.members.columns.role'))
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof OrganizationRole
                        ? $state->getLabel()
                        : OrganizationRole::from((string) $state)->getLabel()),
                IconColumn::make('pivot.notify_on_submission')
                    ->label(__('admin.members.columns.notified'))
                    ->boolean(),
                TextColumn::make('pivot.created_at')
                    ->label(__('admin.members.columns.since'))
                    ->dateTime('j M Y, H:i')
                    ->placeholder('-'),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([
                $this->changeRoleAction(),
                $this->removeAction(),
            ]);
    }

    private function changeRoleAction(): Action
    {
        return Action::make('changeRole')
            ->label(__('members.actions.change_role'))
            ->icon(Heroicon::OutlinedPencilSquare)
            // The resource's own gate, is_platform_admin. Not the pivot policy:
            // OrganizationMemberPolicy::update() says yes to this
            // organization's owners too, which is right on their own page and
            // wrong on this one.
            ->authorize(fn (): bool => OrganizationResource::canAccess())
            ->visible(fn (User $record): bool => ! $record->is(auth()->user()))
            ->modalHeading(fn (User $record): string => __('members.actions.change_role_heading', ['name' => (string) $record->name]))
            ->modalDescription(__('admin.members.change_role_description'))
            ->fillForm(fn (User $record): array => ['role' => $record->roleIn($this->organization())?->value])
            ->schema([
                // A plain options array rather than ->options(OrganizationRole::class):
                // an enum-backed Select casts its state, and the organizer
                // page's comment on the same field says why a string both ways
                // is the safer shape. Every role, the owner's included, because
                // the platform admin acts with an owner's authority.
                Select::make('role')
                    ->label(__('members.fields.role'))
                    ->options(fn (): array => collect(OrganizationRole::cases())
                        ->mapWithKeys(fn (OrganizationRole $role): array => [$role->value => $role->getLabel()])
                        ->all())
                    ->required(),
            ])
            ->action(function (User $record, array $data, ChangeMemberRole $change): void {
                try {
                    $change->handle($this->organization(), $record, OrganizationRole::from((string) $data['role']), $this->actor());
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
            ->authorize(fn (): bool => OrganizationResource::canAccess())
            ->visible(fn (User $record): bool => ! $record->is(auth()->user()))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('members.actions.remove_heading', ['name' => (string) $record->name]))
            ->modalDescription(__('admin.members.remove_description'))
            ->action(function (User $record, RemoveMember $remove): void {
                try {
                    $remove->handle($this->organization(), $record, $this->actor());
                } catch (MemberChangeRefused $exception) {
                    $this->refuse($exception);

                    return;
                }

                Notification::make()->success()->title(__('members.notices.removed'))->send();
            });
    }

    private function organization(): Organization
    {
        /** @var Organization $organization */
        $organization = $this->getOwnerRecord();

        return $organization;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * The organizer page's refusal, word for word: a danger notification that
     * stays until it is read, listing every blocker. The reasons are escaped
     * because a notification body is rendered as HTML.
     */
    private function refuse(MemberChangeRefused $exception): void
    {
        Notification::make()->danger()
            ->title(__('members.notices.refused'))
            ->body(e($exception->getMessage()))
            ->persistent()
            ->send();
    }
}
