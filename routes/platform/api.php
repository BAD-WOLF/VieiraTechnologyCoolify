<?php

use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\RollbackController;
use App\Http\Controllers\PlatformEdgeController;
use App\Http\Controllers\PlatformInterWebhookController;
use App\Http\Controllers\PlatformMetricsController;
use App\Http\Controllers\Webhook\Stripe as StripeWebhook;
use App\Http\Middleware\ApiAllowed;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform API routes
|--------------------------------------------------------------------------
|
| Loaded by PlatformServiceProvider with the "api" middleware group and the /api prefix,
| before routes/api.php (which ends with a catch-all). Kept out of the upstream route files
| so pulling Coolify updates does not conflict with the platform.
|
*/

Route::post('/v1/billing/webhooks/stripe', [StripeWebhook::class, 'events']);
Route::post('/v1/billing/webhooks/inter/{token}', PlatformInterWebhookController::class)->where('token', '[A-Za-z0-9]{20,100}');
Route::get('/v1/platform/metrics', PlatformMetricsController::class);
Route::get('/v1/platform/edge/{region}', PlatformEdgeController::class)->where('region', '[a-z0-9-]+');

Route::group([
    'middleware' => ['auth:sanctum', ApiAllowed::class, 'api.sensitive'],
    'prefix' => 'v1/platform',
], function () {
    Route::get('/plans', [PlatformController::class, 'plans'])->middleware(['api.ability:read']);
    Route::get('/usage', [PlatformController::class, 'usage'])->middleware(['api.ability:read']);
    Route::get('/invoices', [PlatformController::class, 'invoices'])->middleware(['api.ability:read']);
    Route::get('/audit-logs', [PlatformController::class, 'auditLogs'])->middleware(['api.ability:read']);
    Route::post('/servers/{uuid}/region', [PlatformController::class, 'assignServerRegion'])->middleware(['api.ability:write']);
    Route::post('/tenants/{teamId}/provision', [PlatformController::class, 'provisionTenant'])->middleware(['api.ability:write'])->whereNumber('teamId');
});

Route::group([
    'middleware' => ['auth:sanctum', ApiAllowed::class, 'api.ability:deploy'],
    'prefix' => 'v1',
], function () {
    Route::post('/rollback', RollbackController::class);
});
