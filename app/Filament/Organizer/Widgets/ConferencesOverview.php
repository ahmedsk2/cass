<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Widgets;

use App\Enums\ConferenceStatus;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ConferencesOverview extends TableWidget
{
    protected static ?int $sort = -2;

    /** @var int | string | array<string, int | null> */
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Conferences')
            ->description('Everything this organization is running, newest first.')
            ->query($this->query())
            ->defaultSort('created_at', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('name')->searchable()
                    ->description(fn (Conference $record): string => $record->slug)
                    ->url(fn (Conference $record): string => ConferenceResource::getUrl('view', ['record' => $record])),
                TextColumn::make('status')->badge(),
                // ->timezone() as well as the description: without it Filament
                // formats in config('app.timezone') (UTC) and the row would
                // label a UTC time with the conference's timezone.
                TextColumn::make('submission_deadline')->label('Deadline')
                    ->dateTime('j M Y, H:i')->placeholder('Not set')
                    ->timezone(fn (Conference $record): string => $record->timezone)
                    ->description(fn (Conference $record): string => $record->timezone),
                TextColumn::make('tracks_count')->label('Tracks')->badge()->color('gray'),
                TextColumn::make('review_questions_count')->label('Questions')->badge()->color('gray'),
                TextColumn::make('shortLink.clicks')->label('Scans')->badge()->color('gray')->placeholder('0'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Conference $record): string => ConferenceResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([
                Action::make('create')
                    ->label('New conference')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url(ConferenceResource::getUrl('create')),
            ])
            ->emptyStateHeading('No conferences yet')
            ->emptyStateDescription('Create one to set your dates, review form and submission window.');
    }

    /**
     * The tenancy global scope already limits this to the current tenant while
     * the organizer panel is current; the explicit whereBelongsTo makes the
     * widget safe to reuse anywhere and documents the intent.
     *
     * @return Builder<Conference>
     */
    private function query(): Builder
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();

        return Conference::query()
            ->whereBelongsTo($organization)
            ->whereNot('status', ConferenceStatus::Archived)
            ->withCount(['tracks', 'reviewQuestions'])
            ->with('shortLink');
    }
}
