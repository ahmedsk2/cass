<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailLogs\Tables;

use App\Enums\EmailLogStatus;
use App\Models\EmailLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmailLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Queued')->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('to_email')->label('To')->searchable()->copyable(),
                TextColumn::make('subject')->limit(60)->wrap()->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('template_key')->label('Template')->placeholder('-')->toggleable(),
                // The class name without its namespace: the full one is 40
                // characters of App\Notifications\ in every row.
                TextColumn::make('mailable')->label('Sent by')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->tooltip(fn (EmailLog $record): string => (string) $record->mailable)
                    ->toggleable(),
                TextColumn::make('organization.name')->label('Organization')->placeholder('Platform')->toggleable(),
                TextColumn::make('sent_at')->label('Sent')->dateTime('j M Y, H:i')->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(EmailLogStatus::class)->multiple(),
                SelectFilter::make('organization_id')
                    ->label('Organization')
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Nothing sent yet');
    }
}
