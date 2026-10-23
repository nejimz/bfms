<?php

use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::post('/session/login', [SessionController::class, 'store'])->middleware('throttle:10,1');
Route::post('/session/logout', [SessionController::class, 'destroy'])->middleware('auth');

Route::get('/', function () {
    return view('welcome');
});
