<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reviews\Schemas;

use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Models\Review;
use App\Models\ReviewAnswer;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class ReviewInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.reviews.sections.review'))->columns(3)->components([
                TextEntry::make('reviewer.name')->label(__('admin.reviews.columns.reviewer')),
                TextEntry::make('reviewer.email')->label(__('admin.reviews.columns.email')),
                TextEntry::make('status')->badge(),
                TextEntry::make('score')->numeric(2)->placeholder('-'),
                TextEntry::make('submitted_at')->dateTime('j M Y, H:i')
                    // Same null-relation fallback as the list: a soft-deleted
                    // abstract or conference resolves to null, and Carbon's
                    // setTimezone('') throws rather than blanking one value.
                    ->timezone(fn (Review $record): string => (string) ($record->submission?->conference?->timezone ?: config('app.timezone')))
                    ->placeholder('-'),
                TextEntry::make('reopened_at')->dateTime('j M Y, H:i')->placeholder('-'),
            ]),

            Section::make(__('admin.reviews.sections.abstract'))->columns(2)->components([
                TextEntry::make('submission.reference')->fontFamily('mono')->placeholder('-'),
                TextEntry::make('submission.title')
                    ->url(fn (Review $record): ?string => $record->submission === null
                        ? null
                        : SubmissionResource::getUrl('view', ['record' => $record->submission], panel: 'admin')),
                TextEntry::make('submission.conference.name'),
                TextEntry::make('submission.conference.organization.name'),
            ]),

            Section::make(__('admin.reviews.sections.answers'))->components([
                // The answers in the FORM's order, not the answers' own, so an
                // admin reads the review the way the reviewer filled it in -
                // ReviewResource::getEloquentQuery() eager-loads them that way.
                // ReviewAnswer stores one of four typed columns, which is why
                // the value is formatted rather than printed from a state path.
                RepeatableEntry::make('answers')->hiddenLabel()->columns(1)->schema([
                    TextEntry::make('question.prompt')
                        ->hiddenLabel()
                        ->weight(FontWeight::SemiBold),
                    TextEntry::make('id')
                        ->hiddenLabel()
                        ->formatStateUsing(fn (ReviewAnswer $record): string => static::answerText($record))
                        ->prose(),
                ]),
            ]),
        ]);
    }

    /**
     * ReviewAnswer stores value_int, value_text, value_bool or choice_key -
     * one of four columns per question type - so there is no single state path
     * to print. This is the only place in the application that renders one for
     * a human, which is why it lives here rather than on the model: Plan 4's
     * reviewer panel renders the *form*, not the answer.
     *
     * PUBLIC static, not protected: the organizer's SubmissionInfolist calls
     * `ReviewInfolist::answerText($record)` from another class, so `protected`
     * here is a fatal visibility error there.
     */
    public static function answerText(ReviewAnswer $answer): string
    {
        $question = $answer->question;

        $parts = [];

        if ($answer->value_int !== null) {
            $scaleMax = $question?->scale_max;

            $parts[] = $scaleMax === null
                ? (string) $answer->value_int
                : $answer->value_int.' / '.$scaleMax;
        }

        if ($answer->value_bool !== null) {
            $parts[] = $answer->value_bool ? __('admin.reviews.yes') : __('admin.reviews.no');
        }

        if ($answer->choice_key !== null) {
            // The label the organizer wrote, not the stored key. They are the
            // same string today (ReviewQuestion::optionKey() returns the
            // label), and this is the one line that keeps reading right the day
            // real keys are introduced.
            $parts[] = (string) ($question?->optionLabels()[$answer->choice_key] ?? $answer->choice_key);
        }

        if (filled($answer->value_text)) {
            $parts[] = (string) $answer->value_text;
        }

        return $parts === [] ? __('admin.reviews.no_answer') : implode(' — ', $parts);
    }
}
