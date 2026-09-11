<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Tables;

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('starts_at')->label('Dates')->date('j M Y')->sortable(),
                // Timestamps are stored UTC and Filament formats a date-time in
                // config('app.timezone') (UTC) unless told otherwise, so
                // without this the deadline reads three hours early for a
                // Riyadh conference (spec section 10).
                TextColumn::make('submission_deadline')->label('Deadline')->dateTime('j M Y, H:i')->sortable()
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->description(fn (Conference $record): string => $record->timezone)
                    ->placeholder('Not set'),
                TextColumn::make('tracks_count')->counts('tracks')->label('Tracks')->badge()->color('gray'),
                TextColumn::make('shortLink.clicks')->label('Scans')->badge()->color('gray')->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->options(ConferenceStatus::class)->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ConferenceStatusActions::publish(),
                ConferenceStatusActions::close(),
                ConferenceStatusActions::archive(),
                DeleteAction::make()->icon(Heroicon::OutlinedArchiveBox)
                    ->modalDescription('Soft delete: the conference and everything under it stay in the database and can be restored by the platform team.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Filament authorizes this with ConferencePolicy::deleteAny()
                    // and would otherwise delete every selected row without a
                    // per-record check; authorizeIndividualRecords() makes it
                    // run delete() on each one as well.
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading('No conferences yet')
            ->emptyStateDescription('Create one to set your dates, review form and submission window. You can publish it once it is ready.');
    }
}
