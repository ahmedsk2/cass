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
