<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions;

use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Submissions\Schemas\SubmissionInfolist;
use App\Filament\Admin\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Submission;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spec section 4's "View submissions and files" for the platform admin, three
 * plans late. The policies have answered `true` for is_platform_admin since
 * Plan 3 and there has been no route in: the organizer panel is
 * membership-gated (User::canAccessPanel('organizer') requires
 * organizations()->exists()), so an admin who is not a member of an
 * organization cannot open one of its abstracts at all.
 *
 * Read-only, and the refusal is HERE rather than in the policy. Laravel
 * returns a non-null before() result without ever calling the ability
 * (Gate::resolvePolicyCallback), so SubmissionPolicy's explicit
 * deleteAny(): false does not apply to a platform admin. Two pages, no header
 * actions, no bulk actions, one row action.
 *
 * No tenancy override. Plan 3's backlog entry about $isScopedToTenant and the
 * HasOneThrough fatal was written for the ORGANIZER panel, which has a tenant;
 * AdminPanelProvider never calls ->tenant(), so there is nothing here to scope
 * and nothing for Filament's tenancy observer to do.
 *
 * @extends \Filament\Resources\Resource<Submission>
 */
class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 30;

    /**
     * An abstract title is text an author typed, and a global search box asks
     * every tenant at once. The table's own conference filter is one click
     * further and shows who it belongs to.
     */
    protected static bool $isGloballySearchable = false;

    /** Defense in depth: the panel's middleware blocks non-admins at the HTTP layer, but Filament also runs this when a page's Livewire component is mounted directly. */
    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->is_platform_admin;
    }

    /**
     * The list prints the conference and the organization on every row, and
     * the infolist links to both. Eager-loading them here is what keeps that
     * from being two queries per row.
     *
     * withoutGlobalScopes is deliberately NOT used: a soft-deleted abstract is
     * an organizer's own deletion and the admin list is not a recycle bin. The
     * purge screen is on the conference, not here.
     *
     * @return Builder<Submission>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['conference.organization', 'track']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SubmissionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubmissionsTable::configure($table);
    }

    /**
     * Index and view only. There is no create, no edit and no delete page —
     * and because before() short-circuits the policy for a platform admin,
     * this array IS the refusal, together with the empty getHeaderActions()
     * on both pages.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'view' => ViewSubmission::route('/{record}'),
        ];
    }
}
