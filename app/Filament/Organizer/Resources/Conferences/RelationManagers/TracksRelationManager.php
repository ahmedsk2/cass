<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Resources\Conferences\RelationManagers;

use App\Models\Conference;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TracksRelationManager extends RelationManager
{
    protected static string $relationship = 'tracks';

    protected static ?string $title = 'Tracks';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedTag;

    /**
     * A relation manager's Livewire component can be mounted directly, which
     * bypasses the resource page's tenant-scoped route binding, so the owner
     * record's own organization is checked here as well as the model policy.
     */
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
            TextInput::make('name')->required()->maxLength(120),
            Textarea::make('description')->rows(2)->maxLength(500)
                ->helperText('Shown next to the track name on the public page.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('description')->limit(60)->placeholder('-'),
            ])
            ->headerActions([
                CreateAction::make()->label('Add track'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No tracks')
            ->emptyStateDescription('Tracks are optional. Add them if authors should pick a theme when they submit.');
    }
}
