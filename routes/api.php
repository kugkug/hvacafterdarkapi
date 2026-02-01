<?php

use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('user')->group(function () {
        Route::post('/create', [UserController::class, 'create']);
        Route::post('/login', [UserController::class, 'login']);
    });
});


Route::middleware('auth:api')->group(function() {
    Route::prefix('v1')->group(function () {
        Route::prefix('user')->group(function () {
            Route::get('/all', [UserController::class, 'index']);
            Route::get('/{id}', [UserController::class, 'show']);
            Route::post('/update/{id}', [UserController::class, 'update']);
            Route::delete('/delete/{id}', [UserController::class, 'delete']);

            Route::post('/logout', [UserController::class, 'logout']);
        });
    });
});