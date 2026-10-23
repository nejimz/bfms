<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\TrialBalanceController;
use App\Http\Middleware\SetCurrentCompany;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', SetCurrentCompany::class])->prefix('v1')->group(function () {
    Route::get('/user', [CurrentUserController::class, 'show']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::post('/current-company', [CompanyController::class, 'switch']);

    Route::get('/accounts', [AccountController::class, 'index']);
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::patch('/accounts/{account}', [AccountController::class, 'update']);

    Route::get('/journals', [JournalController::class, 'index']);
    Route::post('/journals', [JournalController::class, 'store']);
    Route::get('/journals/{journal}', [JournalController::class, 'show']);
    Route::put('/journals/{journal}', [JournalController::class, 'update']);
    Route::post('/journals/{journal}/post', [JournalController::class, 'post']);

    Route::get('/reports/trial-balance', [TrialBalanceController::class, 'show']);
});
