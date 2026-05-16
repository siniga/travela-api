<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VerifyEmailController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\BundleTypeController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\OrderController;  
use App\Http\Controllers\EvPayController;
use App\Http\Controllers\RevenueDashboard;
use App\Http\Controllers\Api\EsimController;
use App\Http\Controllers\Api\UserEsimController;
use App\Http\Controllers\Api\AdminUserEsimController;


Route::prefix('auth')->group(function () {
  Route::post('/register', [AuthController::class, 'register']);
  Route::post('/login', [AuthController::class, 'login']);
  Route::post('/forgot-password', [PasswordResetController::class, 'sendLink']);
  Route::post('/reset-password', [PasswordResetController::class, 'reset']);
  Route::post('/verify-email', [VerifyEmailController::class, 'verify']);
  Route::post('/email/resend', [VerifyEmailController::class, 'resend'])
    ->middleware('auth:sanctum');
});

Route::prefix('public')->group(function () {
    // FAKE / TEST ONLY: remove after testing
    Route::post('/orders/{orderId}/payment-paid-test', [OrderController::class, 'paymentPaidTest']);

    // Vodacom callbacks
    Route::post('/sims-balances/callback', [EsimController::class, 'simsBalancesCallback']);
    Route::post('/my-recharge-callback-url', [EsimController::class, 'rechargeCallback']);

    
//kyc
  Route::get('/kyc', [KycController::class, 'show']);     // get my KYC
  Route::post('/kyc', [KycController::class, 'store']);   // create/update
  Route::delete('/kyc', [KycController::class, 'destroy']);


  Route::get('/countries', [CountryController::class, 'index']);
  Route::get('/countries/{iso2}/providers', [CountryController::class, 'providers']);

  Route::get('/bundle-types', [BundleTypeController::class, 'index']);
  Route::get('/bundles', [BundleController::class, 'index']); // ?active=1
  Route::get('/providers/{provider}/bundles', [ProviderController::class, 'bundles']); // ?country=TZ&type=DATA&active=1
  
});

// Admin/system Vodacom proxy routes (do not use for normal customer dashboard)
Route::prefix('esim')->middleware(['auth:sanctum', 'admin'])->group(function () {
  Route::get('/organisation/balance', [EsimController::class, 'organisationBalance']);
  Route::get('/networks', [EsimController::class, 'networks']);
  Route::get('/products', [EsimController::class, 'products']);
  Route::get('/sims', [EsimController::class, 'sims']);
  Route::post('/sims/activate', [EsimController::class, 'activate']);
  Route::post('/sims/suspend', [EsimController::class, 'suspend']);
  Route::get('/usage', [EsimController::class, 'usage']);
  Route::get('/usage-details', [EsimController::class, 'usageDetails']);
  Route::get('/recharges', [EsimController::class, 'recharges']);
  Route::post('/recharge', [EsimController::class, 'recharge']);
  Route::get('/sims-balances', [EsimController::class, 'simsBalances']);
});

Route::prefix('me')->middleware('auth:sanctum')->group(function () {
  Route::get('/orders', [OrderController::class, 'myOrders']);
  Route::get('/esims', [UserEsimController::class, 'index']);
  Route::get('/recharges', [UserEsimController::class, 'recharges']);
  Route::get('/usage', [UserEsimController::class, 'usage']);
  Route::get('/usage-details', [UserEsimController::class, 'usageDetails']);
  Route::post('/recharge', [UserEsimController::class, 'recharge']);
});

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
  Route::get('/user-esims', [AdminUserEsimController::class, 'index']);
  Route::get('/user-esims/counts', [AdminUserEsimController::class, 'userEsimCounts']);
  Route::post('/user-esims', [AdminUserEsimController::class, 'store']);
  Route::delete('/user-esims/{id}', [AdminUserEsimController::class, 'destroy']);
  Route::post('/user-esims/sync-from-vodacom', [AdminUserEsimController::class, 'syncFromVodacom']);

  // Orders (admin)
  Route::get('/orders', [OrderController::class, 'getOrders']);
  Route::get('/users/{userId}/orders', [OrderController::class, 'getOrdersByUser']);

  // Dashboard (admin)
  Route::get('/dashboard/stats', [RevenueDashboard::class, 'stats']);
});


Route::middleware(['auth:sanctum', 'verified'])->group(function () {
  Route::post('/logout', [AuthController::class, 'logout']);

  // Orders (merged create / checkout)
  Route::post('/orders', [OrderController::class, 'storeOrder']);

  // Order routes
  // routes/api.php
  Route::get('/orders/{draft_id}', [OrderController::class, 'show']);
  Route::put('/orders/{draft_id}', [OrderController::class, 'update']);
  Route::delete('/orders/{draft_id}', [OrderController::class, 'destroy']);

  
  Route::post('/orders/{orderId}/prepare-evpay', [EvPayController::class, 'preparePayment']);
  Route::post('/orders/{orderId}/evpay-checkout-url', [EvPayController::class, 'createCheckoutUrl']);
});

// Authenticated user info (no email verification required)
Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me']);



