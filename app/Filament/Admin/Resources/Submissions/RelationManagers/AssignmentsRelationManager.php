<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who was asked to review this abstract, and by whom. Read-only by class, for
 * the reason ReviewsRelationManager states: ReviewAssignmentPolicy::before()
 * answers true for a platform admin without the ability ever being called.
 */
class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviewAssignments';

    protected static ?string $title = null;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.assignments.title'))
            ->defaultSort('assigned_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['reviewer', 'assigner']))
            ->columns([
                TextColumn::make('reviewer.name')->label(__('admin.assignments.columns.reviewer')),
                // `assigned_by` is nullOnDelete and the auto-assigner leaves it
                // null, so a missing name is normal rather than an error.
                TextColumn::make('assigner.name')
                    ->label(__('admin.assignments.columns.assigned_by'))
                    ->placeholder(__('admin.submissions.former_member')),
                TextColumn::make('assigned_at')->label(__('admin.assignments.columns.assigned'))->dateTime('j M Y, H:i')->placeholder('-'),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([]);
    }
}
