<?php

use App\Http\Controllers\PlatformBrandLogoController;
use App\Http\Controllers\PlatformPricingPageController;
use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Http\Middleware\PlatformRequireTwoFactor;
use App\Http\Middleware\VerifyCsrfToken;
use App\Livewire\Platform\Admin;
use App\Livewire\Platform\Integrations;
use App\Livewire\Platform\Logs;
use App\Livewire\Platform\Onboarding;
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
// An image for every page, signed in or not: no session, no redirect to plans or two-factor setup.
Route::get('/platform/brand/logo.svg', PlatformBrandLogoController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class, CheckForcePasswordReset::class, DecideWhatToDoWithUser::class, PlatformRequireTwoFactor::class])
    ->name('platform.brand.logo');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/platform/onboarding', Onboarding::class)->name('platform.onboarding');
    Route::get('/platform/logs', Logs::class)->name('platform.logs');
    Route::get('/platform/integrations', Integrations::class)->name('platform.integrations');
});
