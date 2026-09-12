<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations\RelationManagers;

use App\Enums\OrganizationRole;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Spec section 4's platform-admin cell for "Manage organization members" had no
 * screen at all: canAccessPanel('organizer') is organizations()->exists(), so a
 * platform admin who is not a member cannot open the organizer panel's Members
 * page for anybody.
 *
 * Read-only by class. `members` is a BelongsToMany of User through the
 * OrganizationMember pivot, so the interesting columns are on `pivot.*` and the
 * `role` cast lives on the pivot model rather than on User - which is why the
 * badge is formatted from the raw pivot value.
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
            ->recordActions([]);
    }
}
