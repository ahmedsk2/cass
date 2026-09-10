<?php

declare(strict_types=1);

use App\Livewire\Public\ContactForm;
use App\Livewire\Public\RegisterOrganization;
use Illuminate\Support\Facades\Route;

Route::view('/', 'public.landing')->name('landing');
Route::view('/about', 'public.about')->name('about');
Route::view('/privacy', 'public.privacy')->name('privacy');
Route::view('/terms', 'public.terms')->name('terms');
Route::get('/contact', ContactForm::class)->name('contact');
Route::get('/register', RegisterOrganization::class)->name('register');
