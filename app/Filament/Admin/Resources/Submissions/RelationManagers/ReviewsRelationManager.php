<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\RelationManagers;

use App\Filament\Admin\Resources\Reviews\ReviewResource;
use App\Models\Review;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only, and the refusal is this class rather than the policy.
 * RelationManager adds a CreateAction in the header and Edit/Delete per row by
 * default, ReviewPolicy::before() answers true for a platform admin, and
 * Laravel returns a non-null before() result WITHOUT ever calling the ability -
 * so isReadOnly() plus three empty arrays are what actually refuse.
 */
class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $title = null;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.reviews.title'))
            ->defaultSort('submitted_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('reviewer'))
            ->columns([
                TextColumn::make('reviewer.name')->label(__('admin.reviews.columns.reviewer')),
                TextColumn::make('status')->label(__('admin.reviews.columns.status'))->badge(),
                TextColumn::make('score')->label(__('admin.reviews.columns.score'))->numeric(2)->placeholder('-'),
                TextColumn::make('submitted_at')->label(__('admin.reviews.columns.submitted'))->dateTime('j M Y, H:i')->placeholder('-'),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([
                // Not a ViewAction: the answers live on ReviewResource's own
                // page, and a second infolist here would be the same code twice.
                Action::make('view')
                    ->label(__('admin.reviews.open'))
                    ->url(fn (Review $record): string => ReviewResource::getUrl('view', ['record' => $record], panel: 'admin'))
                    ->icon(Heroicon::OutlinedEye),
            ]);
    }
}
