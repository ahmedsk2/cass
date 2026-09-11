<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizer;

use App\Actions\Conferences\GenerateConferencePoster;
use App\Actions\Conferences\GenerateConferenceQr;
use App\Enums\PosterSize;
use App\Http\Controllers\Controller;
use App\Models\Conference;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Downloads live on plain authenticated routes rather than Filament actions so
 * they can be linked from anywhere (an email, the panel, a bookmark) and so
 * the authorization is one explicit Gate call per request.
 */
class ConferenceAssetController extends Controller
{
    public function svg(Conference $conference, GenerateConferenceQr $qr): Response
    {
        $this->authorizeDownload($conference);

        return $this->download($qr->svg($conference), $qr->fileName($conference, 'svg'), 'image/svg+xml');
    }

    public function png(Conference $conference, GenerateConferenceQr $qr): Response
    {
        $this->authorizeDownload($conference);

        return $this->download($qr->png($conference), $qr->fileName($conference, 'png'), 'image/png');
    }

    public function poster(Conference $conference, string $size, GenerateConferencePoster $poster): Response
    {
        $this->authorizeDownload($conference);

        $posterSize = PosterSize::tryFrom($size);
        abort_if($posterSize === null, 404);

        return $this->download(
            $poster->handle($conference, $posterSize),
            $poster->fileName($conference, $posterSize),
            'application/pdf',
        );
    }

    private function authorizeDownload(Conference $conference): void
    {
        Gate::authorize('view', $conference);

        // Nothing to download until publishing has created the short link.
        abort_if($conference->shortLink === null, 404);
    }

    private function download(string $body, string $fileName, string $contentType): Response
    {
        return response($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
