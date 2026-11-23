<?php

use App\Http\Controllers\PlatformBrandLogoController;
use App\Http\Controllers\PlatformImpersonationController;
use App\Http\Controllers\PlatformLegalController;
use App\Http\Controllers\PlatformPricingPageController;
use App\Http\Controllers\PlatformPrivacyExportController;
use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Http\Middleware\PlatformRequireTwoFactor;
use App\Http\Middleware\VerifyCsrfToken;
use App\Livewire\Platform\Admin;
use App\Livewire\Platform\Integrations;
use App\Livewire\Platform\Logs;
use App\Livewire\Platform\Onboarding;
use App\Livewire\Platform\Privacy;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Platform web routes
|--------------------------------------------------------------------------
|
| Loaded by PlatformServiceProvider with the "web" middleware group, before routes/web.php
| (which ends with a catch-all redirect).
|
*/

Route::get('/admin/platform', Admin::class)->middleware(['auth'])->name('admin.platform');
Route::get('/pricing', PlatformPricingPageController::class)->name('platform.pricing');
// LGPD: public privacy policy and terms of use, and the page of the data subject (also for
// accounts that did not confirm the e-mail: they can see, copy and delete their data).
Route::get('/privacidade', [PlatformLegalController::class, 'privacy'])->name('platform.legal.privacy');
Route::get('/termos', [PlatformLegalController::class, 'terms'])->name('platform.legal.terms');
Route::middleware(['auth'])->group(function () {
    Route::get('/platform/privacy', Privacy::class)->name('platform.privacy');
    Route::get('/platform/privacy/export', PlatformPrivacyExportController::class)->name('platform.privacy.export');
});
// An image for every page, signed in or not: no session, no redirect to plans or two-factor setup.
Route::get('/platform/brand/logo.svg', PlatformBrandLogoController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class, CheckForcePasswordReset::class, DecideWhatToDoWithUser::class, PlatformRequireTwoFactor::class])
    ->name('platform.brand.logo');

// Whatever the state of the customer's account (unpaid, e-mail not verified), the operator can go back.
Route::post('/platform/impersonation/stop', [PlatformImpersonationController::class, 'stop'])
    ->middleware(['auth'])
    ->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class, PlatformRequireTwoFactor::class])
    ->name('platform.impersonation.stop');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/platform/onboarding', Onboarding::class)->name('platform.onboarding');
    Route::get('/platform/logs', Logs::class)->name('platform.logs');
    Route::get('/platform/integrations', Integrations::class)->name('platform.integrations');
});
