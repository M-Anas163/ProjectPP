<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\BatchController;
use Illuminate\Support\Facades\Route;


Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me',      [AuthController::class, 'me']);
    });
});


Route::middleware('auth:sanctum')->group(function () {

    Route::prefix('products')->group(function () {
        Route::get('/',     [ProductController::class, 'index']);
        Route::get('/{id}', [ProductController::class, 'show']);
    });

    Route::prefix('orders')->group(function () {
        Route::post('/unsafe',       [OrderController::class, 'createUnsafe']);
        Route::post('/safe-atomic',  [OrderController::class, 'createSafeAtomic']);
        Route::post('/safe-lock',    [OrderController::class, 'createSafeLock']);
        Route::post('/pool',         [OrderController::class, 'createViaPool']);
        Route::post('/sync-slow',    [OrderController::class, 'createSyncSlow']);
        Route::post('/async',        [OrderController::class, 'createAsync']);

        Route::get('/comparison',    [OrderController::class, 'comparison']);
        Route::get('/{id}/status',   [OrderController::class, 'status']);
        Route::get('/{id}/invoice',  [OrderController::class, 'invoice']);
    });

    Route::prefix('batch')->group(function () {
        Route::post('/run',               [BatchController::class, 'run']);
        Route::get('/reports/{date}',     [BatchController::class, 'report']);
        Route::get('/comparison',         [BatchController::class, 'comparison']);
    });

});
