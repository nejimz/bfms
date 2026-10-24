<?php

use App\Http\Controllers\Api\V1\AccountColumnReportController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\BusinessSummaryController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ChartAccountColumnReportController;
use App\Http\Controllers\Api\V1\ChartAccountEntryController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\IncomeExpenseChartController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\TrialBalanceController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\SetCurrentCompany;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::patch('/user/password', [CurrentUserController::class, 'updatePassword'])->middleware('throttle:6,1');
});

Route::middleware(['auth:sanctum', SetCurrentCompany::class])->prefix('v1')->group(function () {
    Route::get('/user', [CurrentUserController::class, 'show']);
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::patch('/companies/{company}', [CompanyController::class, 'update']);
    Route::post('/current-company', [CompanyController::class, 'switch']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::patch('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    Route::get('/accounts', [AccountController::class, 'index']);
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::patch('/accounts/{account}', [AccountController::class, 'update']);

    Route::get('/chart-account-entries', [ChartAccountEntryController::class, 'index']);
    Route::post('/chart-account-entries', [ChartAccountEntryController::class, 'store']);
    Route::get('/chart-account-entries/{entry}', [ChartAccountEntryController::class, 'show']);
    Route::put('/chart-account-entries/{entry}', [ChartAccountEntryController::class, 'update']);
    Route::delete('/chart-account-entries/{entry}', [ChartAccountEntryController::class, 'destroy']);
    Route::post('/chart-account-entries/{entry}/post', [ChartAccountEntryController::class, 'post']);

    Route::get('/journals', [JournalController::class, 'index']);
    Route::post('/journals', [JournalController::class, 'store']);
    Route::get('/journals/{journal}', [JournalController::class, 'show']);
    Route::put('/journals/{journal}', [JournalController::class, 'update']);
    Route::post('/journals/{journal}/post', [JournalController::class, 'post']);

    Route::get('/account-column-reports', [AccountColumnReportController::class, 'index']);
    Route::post('/account-column-reports', [AccountColumnReportController::class, 'store']);
    Route::get('/account-column-reports/{accountColumnReport}', [AccountColumnReportController::class, 'show']);
    Route::put('/account-column-reports/{accountColumnReport}', [AccountColumnReportController::class, 'update']);
    Route::delete('/account-column-reports/{accountColumnReport}', [AccountColumnReportController::class, 'destroy']);

    Route::get('/reports/business-summary', [BusinessSummaryController::class, 'show']);
    Route::get('/reports/income-expense', [IncomeExpenseChartController::class, 'show']);
    Route::get('/reports/trial-balance', [TrialBalanceController::class, 'show']);
    Route::get('/reports/chart-account-columns', [ChartAccountColumnReportController::class, 'show']);
});
