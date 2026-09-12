<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who has been invited into this organization and what became of each
 * invitation. Read-only by class: OrganizationInvitationPolicy::before()
 * answers true for a platform admin without the ability ever being called, so
 * the empty arrays are the refusal.
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
            ->recordActions([]);
    }
}
