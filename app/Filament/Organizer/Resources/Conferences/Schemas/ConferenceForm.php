<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\Schemas;

use App\Enums\ReviewMode;
use App\Models\Conference;
use App\Models\Organization;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ConferenceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make('Details')->components([
                    Section::make()->columns(2)->components([
                        TextInput::make('name')->required()->minLength(3)->maxLength(180)->columnSpanFull(),
                        // Spec 5.2 lets the organizer choose the public address
                        // (`/c/gps/gpcc26` rather than the derived
                        // `/c/gps/gulf-pediatric-critical-care-2026`). Left
                        // empty it is derived from the name. Uniqueness is
                        // checked against trashed rows too, because
                        // Conference::uniqueSlug() reserves them so an old
                        // printed link can never point at a new conference -
                        // Laravel's `unique` rule would ignore them.
                        TextInput::make('slug')
                            ->label('Web address')
                            ->maxLength(80)
                            // One closure rule rather than ->regex() plus a
                            // separate uniqueness rule: the field is optional,
                            // and `nullable` does not skip an empty *string*,
                            // so a bare regex would reject "leave it blank".
                            //
                            // The record is excluded from the lookup: a field
                            // that is not dehydrated is still validated
                            // (Schemas\Concerns\CanBeValidated::getValidationRules()
                            // skips a component only when it is neither
                            // dehydrated *nor* validated, and
                            // isValidatedWhenNotDehydrated defaults to true),
                            // so on the edit page this rule runs against the
                            // conference's own stored slug.
                            ->rule(static fn (?Conference $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                if (! is_string($value) || $value === '') {
                                    return; // Empty means "derive it from the name".
                                }

                                // \z, not $: `$` also matches just before a
                                // final newline, so "gpcc26\n" would pass and
                                // end up in a URL and a printed QR code.
                                if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) !== 1) {
                                    $fail('Use lower-case letters, numbers and single hyphens, for example gpcc26.');

                                    return;
                                }

                                $tenant = Filament::getTenant();

                                if (! $tenant instanceof Organization) {
                                    return;
                                }

                                $query = Conference::withTrashed()
                                    ->where('organization_id', $tenant->getKey())
                                    ->where('slug', $value);

                                if ($record instanceof Conference) {
                                    $query->whereKeyNot($record->getKey());
                                }

                                if ($query->exists()) {
                                    $fail('Another conference of this organization already uses this address.');
                                }
                            })
                            ->helperText('Optional. Derived from the name when empty. Fixed after creation so printed links and QR codes keep working.')
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->columnSpanFull(),
                        Textarea::make('short_description')->rows(2)->maxLength(500)->columnSpanFull()
                            ->label('One-line summary')
                            ->helperText('Shown under the title on the public page and in search results.'),
                        RichEditor::make('description')
                            ->label('Call for abstracts')
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'link'],
                                ['h2', 'h3'],
                                ['bulletList', 'orderedList'],
                                ['undo', 'redo'],
                            ])
                            ->columnSpanFull()
                            ->helperText('Rendered on the public page. Formatting is kept; styles, classes, images and embedded media are removed.'),
                    ]),
                    Section::make('When and where')->columns(2)->components([
                        DatePicker::make('starts_at')->label('First day')->native(false),
                        DatePicker::make('ends_at')->label('Last day')->native(false)->afterOrEqual('starts_at'),
                        TextInput::make('venue')->maxLength(180),
                        TextInput::make('city')->maxLength(120),
                        Select::make('country')->options(config('cass.countries'))->searchable(),
                        Select::make('timezone')
                            ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()->required()->default('Asia/Riyadh')
                            ->helperText('Deadlines are shown to authors in this timezone.'),
                    ]),
                ]),

                Tab::make('Submissions')->components([
                    Section::make('Window')->columns(2)->components([
                        DateTimePicker::make('submission_opens_at')
                            ->label('Submissions open')->seconds(false)->native(false)
                            ->timezone(fn (Get $get): string => (string) ($get('timezone') ?: 'Asia/Riyadh')),
                        DateTimePicker::make('submission_deadline')
                            ->label('Submission deadline')->seconds(false)->native(false)
                            ->timezone(fn (Get $get): string => (string) ($get('timezone') ?: 'Asia/Riyadh'))
                            ->after('submission_opens_at'),
                        DateTimePicker::make('review_deadline')
                            ->label('Review deadline')->seconds(false)->native(false)
                            ->timezone(fn (Get $get): string => (string) ($get('timezone') ?: 'Asia/Riyadh'))
                            ->after('submission_deadline')
                            ->helperText('Reviewers are reminded 7, 3 and 1 days before this date.'),
                    ]),
                    Section::make('What authors may send')->columns(2)->components([
                        TextInput::make('word_limit')->numeric()->required()->minValue(50)->maxValue(5000)->default(500)
                            ->helperText('Counted live in the submission form.'),
                        TextInput::make('max_files')->numeric()->required()->minValue(0)->maxValue(10)->default(3),
                        CheckboxList::make('allowed_file_types')
                            ->options(['pdf' => 'PDF', 'doc' => 'Word (.doc)', 'docx' => 'Word (.docx)'])
                            ->default(['pdf'])->required()->bulkToggleable(),
                        CheckboxList::make('presentation_types')
                            ->options(['oral' => 'Oral', 'poster' => 'Poster', 'either' => 'Either'])
                            ->default(['oral', 'poster', 'either'])->required()->bulkToggleable(),
                        Textarea::make('terms')->rows(4)->maxLength(2000)->columnSpanFull()
                            ->helperText('Shown on the public page and above the agreement checkbox.'),
                    ]),
                ]),

                Tab::make('Review')->components([
                    Section::make()->columns(2)->components([
                        Select::make('review_mode')
                            ->options(ReviewMode::class)->required()->live()
                            ->default(ReviewMode::OpenPool->value)->columnSpanFull(),
                        // `options(ReviewMode::class)` registers an
                        // EnumStateCast, so $get() hands back the enum case and
                        // never the backing string (fact 14). Comparing with
                        // ->value here would hide the field for ever, and a
                        // hidden field is not dehydrated - the value would be
                        // silently dropped on save instead of failing loudly.
                        TextInput::make('reviewers_per_submission')
                            ->numeric()->minValue(1)->maxValue(10)->default(2)
                            ->required(fn (Get $get): bool => $get('review_mode') === ReviewMode::Assigned)
                            ->visible(fn (Get $get): bool => $get('review_mode') === ReviewMode::Assigned),
                        Toggle::make('blind_review')->default(true)
                            ->label('Hide author names and affiliations from reviewers'),
                    ]),
                ]),
            ]),
        ]);
    }
}
