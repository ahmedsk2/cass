<?php

declare(strict_types=1);

use App\Http\Controllers\Public\ConferenceController;
use App\Http\Controllers\Public\ShortLinkController;
use App\Livewire\Public\ContactForm;
use App\Livewire\Public\RegisterOrganization;
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
// AuthenticateSession is on this public route because of the member preview of
// an unpublished conference: a session stolen before a password change must
// not keep reading drafts. It is a no-op for guests.
Route::get('/c/{organization}/{conference:slug}', [ConferenceController::class, 'show'])
    ->scopeBindings()
    ->middleware(AuthenticateSession::class)
    ->name('conference.show');

// No throttle middleware: the cap lives in RecordShortLinkVisit and limits
// counting only, because spec 5.7 requires the redirect itself to always work
// (a hall full of people scanning one poster shares a single NAT address).
Route::get('/q/{code}', ShortLinkController::class)
    ->where('code', '[A-Za-z0-9]{8}')
    ->name('shortlink.show');
