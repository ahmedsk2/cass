<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Every refusal an author can meet while attaching a file, each with a sentence
 * they can act on. The Livewire component turns the message into a field error;
 * nothing about the storage layout, the disk or the sniffed type leaks into it.
 */
class SubmissionFileRejected extends RuntimeException
{
    /** @param  list<string>  $allowed */
    public static function extension(string $extension, array $allowed): self
    {
        $list = strtoupper(implode(', ', $allowed));

        return new self($extension === ''
            ? "This conference accepts {$list} files. Please rename your file so it ends in the right extension."
            : "This conference accepts {$list} files, not .{$extension}.");
    }

    public static function contentMismatch(string $extension): self
    {
        // Never says what it *is*: an uploader learning "we think this is a
        // PNG" is learning how the check works.
        return new self('That file does not look like a '.strtoupper($extension).' inside. Please upload the original document.');
    }

    public static function tooLarge(int $bytes, int $limit): self
    {
        return new self(sprintf(
            'That file is %.1f MB, which is larger than the %d MB limit.',
            $bytes / 1_048_576,
            (int) round($limit / 1_048_576),
        ));
    }

    public static function tooMany(int $limit): self
    {
        return new self($limit === 0
            ? 'This conference does not accept file attachments.'
            : 'You can attach at most '.$limit.' file'.($limit === 1 ? '' : 's').'. Remove one first.');
    }

    public static function duplicate(string $originalName): self
    {
        return new self("That file is already attached to this abstract (as \"{$originalName}\").");
    }

    public static function unreadable(): self
    {
        return new self('That file could not be read. Please try uploading it again.');
    }

    /**
     * The conference soft-deleted while the abstract survived - the case
     * Submission::isOpenToAuthor() already guards. Not tooMany(0), which would
     * tell the author this conference accepts no attachments: it did, and it is
     * simply not there any more.
     */
    public static function conferenceUnavailable(): self
    {
        return new self('This conference is no longer accepting attachments. Please contact the organizers.');
    }

    /**
     * The private disk answered a write with `false` - a full volume, a
     * permission, a broken mount. The author is told the truth (nothing was
     * saved) and no operational detail; StoreSubmissionFile logs the rest.
     */
    public static function storageFailed(): self
    {
        return new self('That file could not be saved. Please try uploading it again, and tell the organizers if it keeps failing.');
    }
}
