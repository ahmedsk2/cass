<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\Submissions\Tables;

use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Reviews\ReviewerScope;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Deliberately has **no author column and no author search**, in any mode.
 * Blind review is per conference, a reviewer's queue can span several, and a
 * column that is present for one conference and blank for another is a column
 * whose absence tells you something. The review page is where a non-blind
 * conference shows its authors.
 */
class QueueTable
{
    public static function configure(Table $table): Table
    {
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;

        return $table
            ->defaultSort('reference')
            ->columns([
                TextColumn::make('reference')->label(__('reviewer.queue.columns.reference'))
                    ->fontFamily('mono')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('title')->label(__('reviewer.queue.columns.title'))
                    ->searchable()->sortable()->wrap()->limit(90),
                TextColumn::make('conference.name')->label(__('reviewer.queue.columns.conference'))
                    ->sortable()->toggleable(),
                TextColumn::make('track.name')->label(__('reviewer.queue.columns.track'))->placeholder('-'),
                TextColumn::make('presentation_preference')->label(__('reviewer.queue.columns.preference'))
                    ->badge()->placeholder('-'),
                TextColumn::make('files_count')->counts('files')->label(__('reviewer.queue.columns.files'))
                    ->badge()->color('gray'),
            ])
            ->filters([
                SelectFilter::make('conference_id')
                    ->label(__('reviewer.queue.columns.conference'))
                    // This reviewer's conferences only. `->relationship()`
                    // would query every conference on the platform, because
                    // this resource is not tenant-scoped by Filament.
                    ->options(fn (): array => $user === null
                        ? []
                        : ReviewerScope::conferences($user)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('track_id')
                    ->label(__('reviewer.queue.columns.track'))
                    ->options(fn (): array => $user === null
                        ? []
                        : Track::query()
                            ->whereIn('conference_id', ReviewerScope::conferences($user)->select('id'))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('review')
                    ->label(__('reviewer.queue.actions.review'))
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->url(fn (Submission $record): string => SubmissionResource::getUrl(
                        'review',
                        ['record' => $record],
                        panel: 'reviewer',
                    )),
            ])
            ->emptyStateHeading(__('reviewer.queue.empty_heading'))
            ->emptyStateDescription(__('reviewer.queue.empty_body'));
    }
}
