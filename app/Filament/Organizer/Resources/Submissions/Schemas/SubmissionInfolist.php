<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Submissions\Schemas;

use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\SubmissionFile;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

class SubmissionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Abstract')->columns(3)->components([
                TextEntry::make('reference')->fontFamily('mono')->placeholder('Not submitted'),
                TextEntry::make('status')->badge(),
                TextEntry::make('conference.name')->label('Conference'),
                TextEntry::make('title')->columnSpanFull(),
                // The abstract is plain text (the column is, and the form is a
                // textarea), so this is an escaped entry and never HTML.
                TextEntry::make('abstract')->columnSpanFull()->prose(),
                TextEntry::make('word_count')->suffix(' words'),
                TextEntry::make('track.name')->label('Track')->placeholder('-'),
                TextEntry::make('presentation_preference')->label('Presentation preference')->badge()->placeholder('-'),
            ]),

            Section::make('Authors')->components([
                RepeatableEntry::make('authors')
                    ->hiddenLabel()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('affiliation')->placeholder('-'),
                        IconEntry::make('is_corresponding')->label('Corresponding')->boolean(),
                    ]),
                TextEntry::make('contact_phone')->label('Contact phone')->placeholder('-'),
            ]),

            Section::make('Extra answers')
                ->visible(fn (Submission $record): bool => filled($record->custom_field_values))
                ->components([
                    TextEntry::make('custom_field_values')
                        ->hiddenLabel()
                        ->listWithLineBreaks()
                        ->bulleted()
                        // Keyed by custom_fields.key; print the label the
                        // organizer wrote, not the derived key.
                        ->state(function (Submission $record): array {
                            $labels = $record->conference->customFields()->pluck('label', 'key');
                            $lines = [];

                            foreach ((array) $record->custom_field_values as $key => $value) {
                                $printable = match (true) {
                                    is_bool($value) => $value ? 'Yes' : 'No',
                                    is_scalar($value) => (string) $value,
                                    default => json_encode($value) ?: '',
                                };

                                $lines[] = ($labels[$key] ?? $key).': '.$printable;
                            }

                            return $lines;
                        }),
                ]),

            Section::make(__('decisions.infolist.heading'))
                // Only when there is one. A "Decision: none" panel on every
                // abstract in an open conference is a row of empty furniture.
                ->visible(fn (Submission $record): bool => $record->decision !== null)
                ->columns(3)
                ->components([
                    TextEntry::make('decision')->label(__('decisions.infolist.decision'))->badge(),
                    TextEntry::make('decision_notified_at')
                        ->label(__('decisions.infolist.notified'))
                        ->dateTime('j M Y, H:i')
                        ->timezone(fn (Submission $record): string => $record->conference->timezone)
                        // helperText(), not description(): an infolist Entry has
                        // no description() in 5.8.1 - HasDescription is a schema
                        // concern Entry does not use, and Entry::helperText()
                        // (vendor/filament/infolists/src/Components/Concerns/
                        // HasHelperText.php:12) is the same small line under the
                        // value that a table column's description() prints.
                        ->helperText(fn (Submission $record): string => $record->conference->timezone)
                        ->placeholder(__('decisions.infolist.not_sent')),
                    TextEntry::make('score')
                        ->label(__('decisions.infolist.score'))
                        ->numeric(2)
                        ->placeholder('—')
                        ->helperText(fn (Submission $record): string => __('decisions.infolist.reviews', [
                            'count' => (int) $record->review_count,
                        ])),
                    TextEntry::make('decision_history')
                        ->label(__('decisions.infolist.history'))
                        ->listWithLineBreaks()
                        ->columnSpanFull()
                        ->state(fn (Submission $record): array => $record->decisions()->with('decidedBy')->get()
                            ->map(fn (SubmissionDecision $row): string => trim(implode(' ', array_filter([
                                // `->`, not `?->`: `decided_at` is cast to
                                // datetime, so Larastan types it non-nullable
                                // and rejects the nullsafe hop outright
                                // (nullsafe.neverNull) - the trap
                                // SubmissionDecision::actorName() already
                                // records. Every row is written with a
                                // timestamp, by ApplyDecision and by the
                                // factory alike.
                                $row->decided_at->copy()->setTimezone($record->conference->timezone)->format('j M Y, H:i'),
                                '—',
                                $row->decision->getLabel(),
                                '('.$row->actorName().')',
                                $row->note !== null && $row->note !== '' ? '· '.$row->note : null,
                            ]))))
                            ->all()),
                ]),

            Section::make('Files')
                ->visible(fn (Submission $record): bool => $record->files()->exists())
                ->components([
                    RepeatableEntry::make('files')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('original_name')
                                ->label('File')
                                // A fresh 30-minute signed URL per render. The
                                // policy is checked before one is minted, so an
                                // organizer cannot hand out a link to a file
                                // they may not read (the signature itself is
                                // the capability once it exists).
                                ->url(fn (SubmissionFile $record): ?string => Gate::allows('view', $record)
                                    ? $record->temporaryUrl()
                                    : null)
                                ->openUrlInNewTab(),
                            TextEntry::make('size')->formatStateUsing(fn (int $state): string => number_format($state / 1024).' KB'),
                            TextEntry::make('mime')->label('Type'),
                        ]),
                ]),

            Section::make('Activity')->columns(3)->components([
                TextEntry::make('submitted_at')->dateTime('j M Y, H:i')
                    ->timezone(fn (Submission $record): string => $record->conference->timezone)
                    ->placeholder('Not submitted'),
                TextEntry::make('last_edited_at')->label('Last edited')->dateTime('j M Y, H:i')
                    ->timezone(fn (Submission $record): string => $record->conference->timezone)
                    ->placeholder('-'),
                TextEntry::make('withdrawn_at')->label('Withdrawn')->dateTime('j M Y, H:i')
                    ->timezone(fn (Submission $record): string => $record->conference->timezone)
                    ->placeholder('-'),
            ]),
        ]);
    }
}
