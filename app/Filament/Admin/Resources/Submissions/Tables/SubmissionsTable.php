<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Tables;

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\SubmissionStatus;
use App\Models\Organization;
use App\Models\Submission;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.submissions.columns.reference'))
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->placeholder('-'),
                TextColumn::make('title')
                    ->label(__('admin.submissions.columns.title'))
                    ->searchable()
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('conference.name')
                    ->label(__('admin.submissions.columns.conference'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('conference.organization.name')
                    ->label(__('admin.submissions.columns.organization'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.submissions.columns.status'))
                    ->badge(),
                TextColumn::make('decision')
                    ->label(__('admin.submissions.columns.decision'))
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('review_count')
                    ->label(__('admin.submissions.columns.reviews'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('score')
                    ->label(__('admin.submissions.columns.score'))
                    ->numeric(2)
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('submitted_at')
                    ->label(__('admin.submissions.columns.submitted'))
                    ->dateTime('j M Y, H:i')
                    // The conference's own timezone (spec section 10), read
                    // off the row the query already eager-loaded.
                    ->timezone(fn (Submission $record): string => (string) $record->conference?->timezone)
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('organization')
                    ->label(__('admin.submissions.filters.organization'))
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    // Through the conference: submissions have no
                    // organization_id, which is the same fact that made the
                    // organizer resource override tenancy.
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('conference', fn (Builder $inner): Builder => $inner->where('organization_id', $data['value']))
                        : $query),
                SelectFilter::make('conference')
                    ->label(__('admin.submissions.filters.conference'))
                    ->relationship('conference', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label(__('admin.submissions.filters.status'))
                    ->options(SubmissionStatus::class)
                    ->multiple(),
                SelectFilter::make('decision')
                    ->label(__('admin.submissions.filters.decision'))
                    ->options(Decision::class)
                    ->multiple(),
                SelectFilter::make('conference_status')
                    ->label(__('admin.submissions.filters.conference_status'))
                    ->options(ConferenceStatus::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('conference', fn (Builder $inner): Builder => $inner->where('status', $data['value']))
                        : $query),
            ])
            // One action, and it reads. No bulk actions at all: the flat
            // action list is asserted to be exactly ['view'].
            ->recordActions([ViewAction::make()]);
    }
}
