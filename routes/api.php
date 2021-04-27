<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\TestController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
*/

// Route::middleware('auth:api')->get('/user', function (Request $request) {
//     return $request->user();
// });

//customers
Route::middleware(['customAuth'])->group(function () {
    Route::get('/customermanager', [AccountController::class, 'fetchCustomerManager']);
    Route::get('/customers', [AccountController::class, 'fetchAllCustomerId']);
    Route::get('/customers/auth', [AccountController::class, 'fetchOAuth2Google']);
    Route::get('/customers/verifiy-auth', [AccountController::class, 'verifyCodeOAuth2Google']);

    Route::get('/campaign', [CampaignController::class, 'fetchAllCampaign']);
    Route::get('/campaign-budget', [CampaignController::class, 'fetchTrackPerformanceCampaignBudget']);
    Route::get('/campaign-criteria',  [CampaignController::class, 'fecthTargetingCriteria']);
    Route::get('/campaign-adgroup-criteria',  [CampaignController::class, 'fetchAdGroupInterest']);

    Route::get('/billing', [InvoiceController::class, 'fetchBillingSetup']);
    Route::get('/invoice', [InvoiceController::class, 'fetchInvoice']);
    Route::get('/invoice-file', [InvoiceController::class, 'extractInvoice']);
    Route::get('/test', [TestController::class, 'test']);
});


