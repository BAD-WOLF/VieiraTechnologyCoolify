<?php

use App\Http\Controllers\PlatformPricingPageController;
use App\Livewire\Platform\Admin;
use App\Livewire\Platform\Onboarding;
use Illuminate\Support\Facades\Route;

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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/platform/onboarding', Onboarding::class)->name('platform.onboarding');
});
