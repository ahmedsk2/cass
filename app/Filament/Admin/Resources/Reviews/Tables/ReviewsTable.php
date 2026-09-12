<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reviews\Tables;

use App\Enums\ReviewStatus;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Review;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('submission.reference')
                    ->label(__('admin.reviews.columns.reference'))
                    ->fontFamily('mono')
                    ->placeholder('-'),
                TextColumn::make('submission.title')
                    ->label(__('admin.reviews.columns.abstract'))
                    ->searchable()
                    ->limit(50)
                    ->wrap(),
                TextColumn::make('submission.conference.name')
                    ->label(__('admin.reviews.columns.conference'))
                    ->searchable(),
                TextColumn::make('reviewer.name')
                    ->label(__('admin.reviews.columns.reviewer'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('admin.reviews.columns.status'))
                    ->badge(),
                TextColumn::make('score')
                    ->label(__('admin.reviews.columns.score'))
                    ->numeric(2)
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('submitted_at')
                    ->label(__('admin.reviews.columns.submitted'))
                    ->dateTime('j M Y, H:i')
                    // The conference's own timezone (spec section 10). The
                    // fallback is not decoration: a submission or a conference
                    // that has been soft-deleted resolves the relation to null,
                    // and Carbon's setTimezone('') throws
                    // InvalidTimeZoneException - a 500 on the whole screen
                    // rather than one blank cell.
                    ->timezone(fn (Review $record): string => (string) ($record->submission?->conference?->timezone ?: config('app.timezone')))
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.reviews.filters.status'))
                    ->options(ReviewStatus::class)
                    ->multiple(),
                SelectFilter::make('conference')
                    ->label(__('admin.reviews.filters.conference'))
                    ->options(fn (): array => Conference::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    // Through the abstract: a review has no conference_id, the
                    // same fact the admin SubmissionsTable's organization
                    // filter works around one hop lower down.
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('submission', fn (Builder $inner): Builder => $inner->where('conference_id', $data['value']))
                        : $query),
                SelectFilter::make('organization')
                    ->label(__('admin.reviews.filters.organization'))
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('submission.conference', fn (Builder $inner): Builder => $inner->where('organization_id', $data['value']))
                        : $query),
            ])
            // One action, and it reads. No bulk actions at all: the flat action
            // list is asserted to be exactly ['view'].
            ->recordActions([ViewAction::make()]);
    }
}
