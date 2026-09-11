<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailLogs;

use App\Filament\Admin\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Admin\Resources\EmailLogs\Pages\ViewEmailLog;
use App\Filament\Admin\Resources\EmailLogs\Schemas\EmailLogInfolist;
use App\Filament\Admin\Resources\EmailLogs\Tables\EmailLogsTable;
use App\Models\EmailLog;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spec 5.9: every outgoing message, with its status and its error. Read-only,
 * platform-admin only, and deliberately **not** tenant-scoped - a password
 * reset and an organization approval have no organization at all, and the
 * question this screen answers ("did that email go out?") is asked by the
 * person who runs the SMTP account.
 *
 * @extends \Filament\Resources\Resource<EmailLog>
 */
class EmailLogResource extends Resource
{
    protected static ?string $model = EmailLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $recordTitleAttribute = 'to_email';

    protected static ?string $navigationLabel = 'Email log';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->is_platform_admin;
    }

    public static function table(Table $table): Table
    {
        return EmailLogsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmailLogInfolist::configure($schema);
    }

    /** @return Builder<EmailLog> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['organization', 'conference', 'submission']);
    }

    public static function getPages(): array
    {
        // index and view only. There is no create, no edit and no delete page,
        // and EmailLogPolicy answers false to every one of those abilities -
        // Filament treats a missing policy method as ALLOW (Plan 2 fact 7), so
        // both halves are needed.
        return [
            'index' => ListEmailLogs::route('/'),
            'view' => ViewEmailLog::route('/{record}'),
        ];
    }
}
