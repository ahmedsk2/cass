<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrganizationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organization')->columns(2)->components([
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('type'),
                TextEntry::make('country')->formatStateUsing(fn (string $state): string => config('cass.countries')[$state] ?? $state),
                TextEntry::make('website')->url(fn (?string $state): ?string => $state)->openUrlInNewTab(),
                TextEntry::make('contact_email'),
                TextEntry::make('purpose')->columnSpanFull(),
            ]),
            Section::make('Status')->columns(2)->components([
                TextEntry::make('status')->badge(),
                TextEntry::make('status_reason'),
                TextEntry::make('approved_at')->dateTime(),
                TextEntry::make('approver.name')->label('Approved by'),
                TextEntry::make('created_at')->dateTime()->label('Registered'),
            ]),
            Section::make('Members')->components([
                TextEntry::make('members.name')->listWithLineBreaks()->label('Names'),
                TextEntry::make('members.email')->listWithLineBreaks()->label('Emails'),
            ]),
        ]);
    }
}
