<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\RelationManagers;

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ReviewQuestionType;
use App\Models\Conference;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewQuestionsRelationManager extends RelationManager
{
    /**
     * Only used to resolve the related model class for authorization
     * (`Conference::reviewQuestions()` is a read-only HasManyThrough); every
     * read and write goes through getRelationship() below.
     */
    protected static string $relationship = 'reviewQuestions';

    protected static ?string $title = 'Review form';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedListBullet;

    /** Same direct-mount guard as TracksRelationManager. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $ownerRecord instanceof Conference
            && $user->roleIn($ownerRecord->organization) !== null
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    /**
     * Writes must land on the conference's single active review form, so the
     * relation manager works against that HasMany rather than the
     * HasManyThrough. A conference created outside the panel (a factory, the
     * Plan 6 import) may not have a form yet; CreateDefaultReviewForm is
     * idempotent and gives it the standard template.
     *
     * The return type is narrowed to the concrete relation (covariant with the
     * parent's `Relation|Builder`) because the parent has no PHPDoc to inherit
     * and a bare `Relation` fails Larastan level 6 with missingType.generics.
     *
     * @return HasMany<ReviewQuestion, ReviewForm>
     */
    public function getRelationship(): HasMany
    {
        return $this->activeReviewForm()->questions();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('prompt')->required()->rows(3)->maxLength(1000)->columnSpanFull(),
            TextInput::make('help_text')->maxLength(500)->columnSpanFull()
                ->helperText('Optional guidance shown under the question.'),
            Select::make('type')->options(ReviewQuestionType::class)->required()->live()
                ->default(ReviewQuestionType::Likert->value),
            TextInput::make('weight')->numeric()->required()->default('1.00')
                ->minValue(0)->maxValue(999.99)->step(0.25)
                ->helperText('Relative importance when the score is averaged. Text answers are never scored.'),
            // Every closure below compares against the enum *case*: the Select
            // above casts its state, so $get('type') is never the backing
            // string (fact 14), and a permanently hidden field is not
            // dehydrated - Likert questions would be stored with NULL scale
            // bounds and select questions with NULL choices.
            TextInput::make('scale_min')->numeric()->minValue(0)->maxValue(10)->default(1)
                ->required(fn (Get $get): bool => $get('type') === ReviewQuestionType::Likert)
                ->visible(fn (Get $get): bool => $get('type') === ReviewQuestionType::Likert),
            TextInput::make('scale_max')->numeric()->minValue(1)->maxValue(10)->default(5)
                ->required(fn (Get $get): bool => $get('type') === ReviewQuestionType::Likert)
                ->visible(fn (Get $get): bool => $get('type') === ReviewQuestionType::Likert)
                ->gt('scale_min'),
            // Spec 5.6 gives each select choice an optional score, which a
            // plain TagsInput cannot hold - and once Plan 4 locks the form,
            // existing questions can never be edited to add one.
            Repeater::make('options')->label('Choices')
                ->schema([
                    TextInput::make('label')->required()->maxLength(200),
                    TextInput::make('score')->numeric()->minValue(0)->maxValue(100)
                        ->helperText('Optional, 0-100. A choice without a score does not count towards the review score.'),
                ])
                ->columns(2)
                ->minItems(2)
                ->defaultItems(2)
                ->reorderable()
                ->required(fn (Get $get): bool => $get('type') === ReviewQuestionType::Select)
                ->visible(fn (Get $get): bool => $get('type') === ReviewQuestionType::Select)
                ->columnSpanFull(),
            Toggle::make('required')->default(true)->label('Reviewers must answer this'),
        ]);
    }

    public function table(Table $table): Table
    {
        $locked = $this->activeReviewForm()->isLocked();

        return $table
            ->recordTitleAttribute('prompt')
            ->heading($locked ? 'Review form (Locked)' : 'Review form')
            ->description($locked
                ? 'Reviews have been submitted, so existing questions can no longer be changed, reordered or removed. You can still add a new question.'
                : 'These questions are what every reviewer answers. Edit, reorder or replace them before reviews start.')
            ->defaultSort('sort')
            ->reorderable($locked ? null : 'sort')
            ->columns([
                TextColumn::make('prompt')->wrap()->limit(120)->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('scale')->label('Scale')
                    ->state(fn (ReviewQuestion $record): string => $record->type === ReviewQuestionType::Likert
                        ? "{$record->scale_min}-{$record->scale_max}"
                        : '-'),
                TextColumn::make('weight'),
                IconColumn::make('required')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add question'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No questions')
            ->emptyStateDescription('A conference cannot be published until the review form has at least one question.');
    }

    private function activeReviewForm(): ReviewForm
    {
        /** @var Conference $conference */
        $conference = $this->getOwnerRecord();

        $form = $conference->reviewForm()->first();

        if ($form instanceof ReviewForm) {
            return $form;
        }

        return app(CreateDefaultReviewForm::class)->handle($conference);
    }
}
