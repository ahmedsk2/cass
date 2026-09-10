<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations\Tables;

use App\Actions\Organizations\ApproveOrganization;
use App\Actions\Organizations\RejectOrganization;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrganizationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (Organization $record): string => $record->slug),
                TextColumn::make('type')->badge(),
                TextColumn::make('country')->formatStateUsing(fn (string $state): string => config('cass.countries')[$state] ?? $state),
                TextColumn::make('owners.email')->label('Owner')->listWithLineBreaks(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->label('Registered')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrganizationStatus::class)->default(OrganizationStatus::Pending->value),
            ])
            ->recordActions([
                ViewAction::make(),
                static::approveAction(),
                static::rejectAction(),
            ]);
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve this organization?')
            ->modalDescription('The owner will be emailed and can publish conferences immediately.')
            ->visible(fn (Organization $record): bool => $record->status !== OrganizationStatus::Approved)
            ->action(function (Organization $record, ApproveOrganization $approve): void {
                /** @var User $admin */
                $admin = auth()->user();
                $approve->handle($record, $admin);
                Notification::make()->success()->title("{$record->name} approved")->send();
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Organization $record): bool => $record->status !== OrganizationStatus::Suspended)
            ->schema([
                Textarea::make('reason')->label('Reason sent to the owner')->required()->minLength(10)->maxLength(500)->rows(3),
            ])
            ->action(function (Organization $record, array $data, RejectOrganization $reject): void {
                /** @var User $admin */
                $admin = auth()->user();
                $reject->handle($record, $admin, $data['reason']);
                Notification::make()->warning()->title("{$record->name} rejected")->send();
            });
    }
}
