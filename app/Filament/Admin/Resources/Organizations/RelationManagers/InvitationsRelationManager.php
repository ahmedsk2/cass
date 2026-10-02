<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations\RelationManagers;

use App\Actions\Organizations\RevokeInvitation;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who has been invited into this organization and what became of each
 * invitation. Read-only for Filament's own actions: OrganizationInvitationPolicy::before()
 * answers true for a platform admin without the ability ever being called, so
 * isReadOnly() and the empty header and toolbar arrays are the refusal.
 *
 * The one write is Withdraw, through RevokeInvitation - the organizer Members
 * page's own action. Not Invite and not Resend: an invitation is its
 * inviter's authority exercised later, and AcceptInvitation::blockers()
 * refuses one whose inviter holds no manager role in the organization at
 * accept time - which a platform admin who is not a member never does. An
 * admin-minted link would be dead on arrival (Plan 7 Task 4 decision 3).
 *
 * `token_hash` is deliberately not a column here. It is a hash, it is useless
 * to a human, and printing credential material on a support screen is how it
 * ends up in a screenshot.
 */
class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    protected static ?string $title = null;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.invitations.title'))
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('inviter'))
            ->columns([
                TextColumn::make('email')->label(__('admin.invitations.columns.email')),
                TextColumn::make('role')->label(__('admin.invitations.columns.role'))->badge(),
                TextColumn::make('inviter.name')
                    ->label(__('admin.invitations.columns.invited_by'))
                    ->placeholder(__('admin.submissions.former_member')),
                TextColumn::make('expires_at')->label(__('admin.invitations.columns.expires'))->dateTime('j M Y, H:i')->placeholder('-'),
                TextColumn::make('accepted_at')->label(__('admin.invitations.columns.accepted'))->dateTime('j M Y, H:i')->placeholder('-'),
                TextColumn::make('revoked_at')->label(__('admin.invitations.columns.revoked'))->dateTime('j M Y, H:i')->placeholder('-'),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([
                $this->revokeAction(),
            ]);
    }

    private function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('members.actions.revoke'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            // The resource's own gate, is_platform_admin, for the same reason
            // as MembersRelationManager: the invitation policy says yes to this
            // organization's managers as well.
            ->authorize(fn (): bool => OrganizationResource::canAccess())
            ->visible(fn (OrganizationInvitation $record): bool => $record->accepted_at === null && $record->revoked_at === null)
            ->requiresConfirmation()
            ->modalHeading(__('members.actions.revoke_heading'))
            ->modalDescription(__('members.actions.revoke_description'))
            ->action(function (OrganizationInvitation $record, RevokeInvitation $revoke): void {
                /** @var User $admin */
                $admin = auth()->user();

                $revoke->handle($record, $admin);

                Notification::make()->success()->title(__('members.notices.revoked'))->send();
            });
    }
}
