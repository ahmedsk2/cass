<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions\Tables;

use App\Enums\SubmissionStatus;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        // Filament::getTenant() is typed Model|null, so narrow before calling
        // anything Organization-specific: without this, $tenant->conferences()
        // is a call on Illuminate\Database\Eloquent\Model and Larastan level 6
        // reports method.notFound. Same narrowing as
        // SubmissionResource::getEloquentQuery() and ConferenceForm.
        $tenant = Filament::getTenant();
        $tenant = $tenant instanceof Organization ? $tenant : null;

        return $table
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Draft'),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(80),
                TextColumn::make('corresponding_author')
                    ->label('Corresponding author')
                    // Not a relation column: the corresponding author is one
                    // row of a HasMany chosen by a flag, and `authors.name`
                    // would print every author's name joined together.
                    // `->` on the left of `??` (nullsafe.neverNull); the
                    // description below keeps its `?->` because nothing
                    // swallows a null there.
                    ->state(fn (Submission $record): string => $record->correspondingAuthor()->name ?? '-')
                    ->description(fn (Submission $record): ?string => $record->correspondingAuthor()?->email)
                    // Searching an email has to reach the related table.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'authors',
                        fn (Builder $authors): Builder => $authors->where('email', 'like', "%{$search}%"),
                    )),
                TextColumn::make('conference.name')->label('Conference')->sortable()->toggleable(),
                TextColumn::make('track.name')->label('Track')->placeholder('-')->toggleable(),
                TextColumn::make('status')->badge()->sortable(),
                // Timestamps are UTC; show them in the conference's own zone
                // (spec section 10), the way Plan 2's conference table does.
                TextColumn::make('submitted_at')->label('Submitted')->dateTime('j M Y, H:i')->sortable()
                    ->timezone(fn (Submission $record): string => $record->conference->timezone)
                    ->description(fn (Submission $record): string => $record->conference->timezone)
                    ->placeholder('Not submitted'),
                TextColumn::make('files_count')->counts('files')->label('Files')->badge()->color('gray')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('conference_id')
                    ->label('Conference')
                    // The tenant's conferences only. `->relationship()` would
                    // query every conference on the platform, because this
                    // resource is not tenant-scoped by Filament (see
                    // SubmissionResource::$isScopedToTenant).
                    ->options(fn (): array => $tenant === null
                        ? []
                        : $tenant->conferences()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('status')->options(SubmissionStatus::class)->multiple(),
                SelectFilter::make('track_id')
                    ->label('Track')
                    ->options(fn (): array => $tenant === null
                        ? []
                        : Track::query()
                            ->whereHas('conference', fn (Builder $q): Builder => $q->where('organization_id', $tenant->getKey()))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                SubmissionActions::resendLink(),
                SubmissionActions::withdraw(),
            ])
            ->headerActions([
                SubmissionActions::export(),
            ])
            ->emptyStateHeading('No submissions yet')
            ->emptyStateDescription('Abstracts appear here as soon as authors start sending them.');
    }
}
