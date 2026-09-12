<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Conferences\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The conference's reviewer pool, as spec section 4's admin "See all" row asks
 * for it. A reviewer's identity is shown here on purpose:
 * Conference::hidesAuthorsFrom() blinds a reviewer from the *authors* and has
 * never blinded anybody from the reviewer.
 *
 * Read-only by class, for the reason ReviewsRelationManager states.
 */
class ReviewersRelationManager extends RelationManager
{
    protected static string $relationship = 'reviewers';

    protected static ?string $title = null;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.reviewers.title'))
            ->defaultSort('accepted_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label(__('admin.reviewers.columns.name')),
                TextColumn::make('user.email')->label(__('admin.reviewers.columns.email')),
                TextColumn::make('affiliation')->label(__('admin.reviewers.columns.affiliation'))->placeholder('-'),
                TextColumn::make('status')->label(__('admin.reviewers.columns.status'))->badge(),
                TextColumn::make('accepted_at')->label(__('admin.reviewers.columns.accepted'))->dateTime('j M Y, H:i')->placeholder('-'),
                TextColumn::make('removed_at')->label(__('admin.reviewers.columns.removed'))->dateTime('j M Y, H:i')->placeholder('-'),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([]);
    }
}
