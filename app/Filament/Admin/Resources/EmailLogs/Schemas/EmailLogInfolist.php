<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailLogs\Schemas;

use App\Enums\EmailLogStatus;
use App\Filament\Admin\Resources\Conferences\ConferenceResource;
use App\Models\Conference;
use App\Models\EmailLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmailLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Message')->columns(3)->components([
                TextEntry::make('to_email')->label('To')->copyable(),
                TextEntry::make('status')->badge(),
                TextEntry::make('template_key')->label('Template')->placeholder('-'),
                TextEntry::make('subject')->columnSpanFull(),
                TextEntry::make('mailable')->label('Sent by')->columnSpanFull(),
                TextEntry::make('ulid')->label('Correlation id')
                    ->helperText('Travels with the message as the X-CASS-Log header, so an SMTP log line can be matched to this row.')
                    ->copyable(),
            ]),

            Section::make('Context')->columns(3)->components([
                TextEntry::make('organization.name')->label('Organization')->placeholder('Platform-wide'),
                TextEntry::make('conference.name')->label('Conference')
                    ->placeholder('-')
                    ->url(fn (EmailLog $record): ?string => $record->conference instanceof Conference
                        ? ConferenceResource::getUrl('view', ['record' => $record->conference], panel: 'admin')
                        : null),
                TextEntry::make('submission.reference')->label('Submission')->placeholder('-'),
            ]),

            Section::make('Delivery')->columns(2)->components([
                TextEntry::make('created_at')->label('Queued')->dateTime('j M Y, H:i:s'),
                TextEntry::make('sent_at')->label('Sent')->dateTime('j M Y, H:i:s')->placeholder('Not sent'),
                TextEntry::make('error')
                    ->columnSpanFull()
                    ->color('danger')
                    // Raw SMTP text, shown as escaped text and never as markup.
                    ->prose()
                    ->visible(fn (EmailLog $record): bool => $record->status === EmailLogStatus::Failed)
                    ->placeholder('-'),
            ]),
        ]);
    }
}
