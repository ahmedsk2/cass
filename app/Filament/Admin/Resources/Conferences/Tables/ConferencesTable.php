<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Conferences\Tables;

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConferencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (Conference $record): string => $record->slug),
                TextColumn::make('organization.name')->label('Organization')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('submissions_count')
                    ->counts('submissions')
                    ->label('Abstracts')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                // Read-only, like everything else on this screen. `counts()`
                // reaches the query as `$query->withCount(Arr::wrap(...))`
                // (vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery.php:24-25),
                // and Arr::wrap leaves an associative array alone - so Laravel's
                // `['relation as alias' => Closure]` form works and both counts
                // ride along on the list's own query rather than costing a
                // query per row.
                TextColumn::make('decided_count')
                    ->counts(['submissions as decided_count' => fn (Builder $query): Builder => $query->whereNotNull('decision')])
                    ->label('Decided')
                    ->badge()
                    ->color('success')
                    ->sortable(),
                TextColumn::make('notified_count')
                    ->counts(['submissions as notified_count' => fn (Builder $query): Builder => $query->whereNotNull('decision_notified_at')])
                    ->label('Letters sent')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('submission_deadline')->label('Deadline')->dateTime('j M Y, H:i')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->description(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ConferenceStatus::class)->multiple(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                // Restores the soft delete an organizer made. There is no
                // ForceDeleteAction anywhere: ConferencePolicy::before() makes
                // forceDelete true for a platform admin, and the hard purge has
                // to cascade in application code (spec section 3, backlog).
                RestoreAction::make(),
            ]);
    }
}
