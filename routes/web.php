<?php

declare(strict_types=1);

use App\Http\Controllers\Organizer\ConferenceAssetController;
use App\Http\Controllers\Public\ConferenceController;
use App\Http\Controllers\Public\ShortLinkController;
use App\Http\Controllers\Public\SubmissionFileController;
use App\Livewire\Public\ContactForm;
use App\Livewire\Public\RegisterOrganization;
use App\Livewire\Public\SubmissionStatus;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

Route::view('/', 'public.landing')->name('landing');
Route::view('/about', 'public.about')->name('about');
Route::view('/privacy', 'public.privacy')->name('privacy');
Route::view('/terms', 'public.terms')->name('terms');
Route::get('/contact', ContactForm::class)->name('contact');
Route::get('/register', RegisterOrganization::class)->name('register');

// The model's route key is the ULID (panel URLs use it), so the slug is asked
// for explicitly here. Scoped binding resolves {conference:slug} through
// $organization->conferences(), which is what makes a slug that is unique only
// per organization safe in a URL. Plan 3 registers
// /c/{organization}/{conference:slug}/submit with the same binding field.
//
// No AuthenticateSession here. The page is public, and that middleware throws
// AuthenticationException for *any* signed-in visitor whose session password
// hash is stale (a password changed on another device, a session older than the
// last reset) - which on a public route answers a redirect to the organizer
// login instead of the call for abstracts. The member preview of an unpublished
// conference reads auth()->user() and needs no session-password check; the
// panel and the asset downloads below still run AuthenticateSession, so a
// stolen session loses access to everything private.
Route::get('/c/{organization}/{conference:slug}', [ConferenceController::class, 'show'])
    ->scopeBindings()
    ->name('conference.show');

// Authenticated but outside the Filament panel, so the URLs are stable and
// short. Binding is by ULID because the conference slug is only unique inside
// one organization.
//
// No .svg/.png/.pdf suffix on these paths: docker/nginx.conf answers any URI
// ending in a static-file extension from disk, so such a route would 404 in
// production while passing every test (Task 1 Step 6 fixes the fallback as
// well, but a download route should not depend on it). The saved file name
// comes from Content-Disposition.
//
// The panel runs Filament's AuthenticateSession and EnsureEmailIsVerified on
// every tenant route; these downloads live outside the panel, so they repeat
// both (spec section 9), and throttle the expensive render.
Route::middleware([
    'auth',
    AuthenticateSession::class,
    'verified:filament.organizer.auth.email-verification.prompt',
    'throttle:conference-assets',
])
    ->prefix('conference-assets/{conference:ulid}')
    ->name('conference-assets.')
    ->group(function (): void {
        Route::get('/qr-svg', [ConferenceAssetController::class, 'svg'])->name('qr.svg');
        Route::get('/qr-png', [ConferenceAssetController::class, 'png'])->name('qr.png');
        Route::get('/poster/{size}', [ConferenceAssetController::class, 'poster'])
            ->where('size', 'a4|a3')
            ->name('poster');
    });

// No throttle middleware: the cap lives in RecordShortLinkVisit and limits
// counting only, because spec 5.7 requires the redirect itself to always work
// (a hall full of people scanning one poster shares a single NAT address).
Route::get('/q/{code}', ShortLinkController::class)
    ->where('code', '[A-Za-z0-9]{8}')
    ->name('shortlink.show');

// Spec sections 6 and 8. The signature is the capability, so no auth and no
// token here; the throttle is what stops a leaked URL being used to hammer the
// disk.
//
// No file extension in the path, for the same reason the conference asset
// routes have none: docker/nginx.conf answers any URI ending in a static-file
// extension from disk before PHP sees it. The saved file name comes from
// Content-Disposition.
//
// The constraint is the Crockford base32 alphabet a ULID uses - no I, L, O or
// U - so a path that could not be a ULID never reaches the database.
Route::get('/files/{ulid}', SubmissionFileController::class)
    ->middleware(['signed', 'throttle:file-download'])
    ->where('ulid', '[0-9A-HJKMNP-TV-Z]{26}')
    ->name('files.download');

// Registered here rather than with the component (Task 8) because
// Submission::statusUrl() resolves this name from Task 5 onwards, and every
// Task 5 test that touches SubmissionLink::url(), SubmitAbstract's placeholders
// or SendSubmissionStatusLink would otherwise die on RouteNotFoundException.
// The class this names is a placeholder until Task 8 writes the real page.
// It has to be a real class today: RouteAction::makeInvokable() calls
// method_exists() on a string action at *registration*, and
// RouteListCommand::isVendorRoute() reflects on it, so a dangling name would
// throw here and break `php artisan route:list` for the whole app.
//
// No AuthenticateSession and no auth: there is no account here at all.
// The throttle is spec section 9's 20/min/IP, and it is on the route rather
// than in the component because a page *view* has to be counted too - guessing
// tokens is a GET loop, not a Livewire action.
Route::get('/s/{token}', SubmissionStatus::class)
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:submission-status')
    ->name('submission.status');
