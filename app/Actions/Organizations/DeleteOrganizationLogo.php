<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a logo file from the public `branding` disk once no organization row
 * points at it - the one rule both writers that stop pointing at a logo share:
 * UpdateOrganizationProfile when a logo is replaced or cleared, and
 * PurgeOrganization when the organization itself goes.
 *
 * Call it AFTER the commit that stopped pointing at the file, never inside it:
 * DeleteSubmissionFile's order, for its reason. A row pointing at a deleted
 * file is a broken image on every public page and in every email, while a file
 * no row points at is only bytes - and no rollback can put a deleted file back.
 *
 * "No row points at it" is read from the database, trashed rows included,
 * rather than trusted from the caller: that one query is what keeps a file
 * another organization's row also holds (an exact copy of another
 * organization's path, written by a hand-edited request before the profile
 * form refused them; any other spelling of a path is refused by isCanonical(),
 * because the disk normalises `logos/./x.png` to `logos/x.png` before it
 * deletes), and what keeps the file when the write that should have released
 * it never committed.
 */
class DeleteOrganizationLogo
{
    /**
     * True when the file was deleted; false when a row still points at it, or
     * when the path is not one Filament writes and is left alone.
     */
    public function handle(string $path): bool
    {
        if (! self::isCanonical($path) || $this->stillUsed($path)) {
            return false;
        }

        // The bool is deliberately unchecked, exactly as in
        // DeleteSubmissionFile: no row points at the file any more, so there is
        // nothing left to refuse for.
        Storage::disk('branding')->delete($path);

        return true;
    }

    /**
     * Whether the path is spelled exactly as the profile form's FileUpload
     * stores one: 'logos/' + a ULID + '.' + the client's extension. The
     * branding disk normalises a path before it deletes - 'logos/./beta.png'
     * is 'logos/beta.png' to it - and throws on one that climbs out of the
     * disk, so a row holding any other spelling could reach a file the
     * exact-string row check never matches. Fail-closed: a path this refuses
     * only leaves bytes behind.
     */
    public static function isCanonical(string $path): bool
    {
        return preg_match('#^logos/[A-Za-z0-9]+(\.[A-Za-z0-9]*)?\z#', $path) === 1;
    }

    /**
     * Whether any organization row other than $except points at the file.
     * PurgeOrganization::preview() asks it about a row that still exists and is
     * about to go; handle() asks it once that row has gone or moved on.
     */
    public function stillUsed(string $path, ?Organization $except = null): bool
    {
        return Organization::withTrashed()
            ->where('logo_path', $path)
            ->when($except !== null, static fn (Builder $query): Builder => $query->whereKeyNot($except?->getKey()))
            ->exists();
    }
}
