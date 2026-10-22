<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Signed link from the verification email.
Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

// Laravel's password broker builds a URL with this name; the email itself links to the SPA.
Route::get('reset-password/{token}', fn () => view('app'))->name('password.reset');

// Everything else is the Vue single-page application.
Route::view('/{any?}', 'app')->where('any', '^(?!api|up|storage|build).*$');
