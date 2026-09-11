<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\RelationManagers;

use App\Enums\CustomFieldType;
use App\Models\Conference;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CustomFieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'customFields';

    protected static ?string $title = 'Extra submission fields';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentList;

    /** Same direct-mount guard as TracksRelationManager. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $ownerRecord instanceof Conference
            && $user->roleIn($ownerRecord->organization) !== null
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->required()->maxLength(120)
                ->helperText('What the author sees above the field.'),
            TextInput::make('key')->disabled()->dehydrated(false)->visibleOn('edit')
                ->helperText('The storage key. Fixed after creation so answers already submitted keep their meaning.'),
            Select::make('type')->options(CustomFieldType::class)->required()->live()
                ->default(CustomFieldType::Text->value),
            // `options(CustomFieldType::class)` casts the state, so $get()
            // returns the enum case (fact 14). Comparing with ->value would
            // hide this for ever and the choices would be saved as NULL.
            TagsInput::make('options')->label('Choices')
                ->required(fn (Get $get): bool => $get('type') === CustomFieldType::Select)
                ->visible(fn (Get $get): bool => $get('type') === CustomFieldType::Select)
                ->helperText('Press Enter after each choice.'),
            TextInput::make('help_text')->maxLength(500)->columnSpanFull(),
            Toggle::make('required')->label('Authors must answer this'),
            Toggle::make('hide_from_reviewers')
                ->label(__('reviewer.review.hide_from_reviewers'))
                ->helperText(__('reviewer.review.hide_from_reviewers_help'))
                ->default(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('label')->searchable(),
                TextColumn::make('key')->fontFamily(FontFamily::Mono)->color('gray'),
                TextColumn::make('type')->badge(),
                IconColumn::make('required')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add field'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Answers already given for this field stay in the database but stop being displayed.'),
            ])
            ->emptyStateHeading('No extra fields')
            ->emptyStateDescription('Title, abstract, authors and files are always collected. Add a field here only for something else you need.');
    }
}
