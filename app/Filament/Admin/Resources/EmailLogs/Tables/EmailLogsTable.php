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
                TextColumn::make('created_at')->label(__('admin.email_log.columns.queued'))->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('to_email')->label(__('admin.email_log.columns.to'))->searchable()->copyable(),
                TextColumn::make('subject')->label(__('admin.email_log.columns.subject'))->limit(60)->wrap()->searchable(),
                TextColumn::make('status')->label(__('admin.email_log.columns.status'))->badge()->sortable(),
                TextColumn::make('template_key')->label(__('admin.email_log.columns.template'))->placeholder('-')->toggleable(),
                // The class name without its namespace: the full one is 40
                // characters of App\Notifications\ in every row.
                TextColumn::make('mailable')->label(__('admin.email_log.columns.sent_by'))
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->tooltip(fn (EmailLog $record): string => (string) $record->mailable)
                    ->toggleable(),
                TextColumn::make('organization.name')->label(__('admin.email_log.columns.organization'))->placeholder(__('admin.email_log.platform'))->toggleable(),
                TextColumn::make('sent_at')->label(__('admin.email_log.columns.sent'))->dateTime('j M Y, H:i')->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.email_log.filters.status'))->options(EmailLogStatus::class)->multiple(),
                SelectFilter::make('organization_id')
                    ->label(__('admin.email_log.filters.organization'))
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading(__('admin.email_log.empty'));
    }
}
