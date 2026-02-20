<?php

use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\ImageUploadController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Broadcast channel auth for Reverb (JWT)
Broadcast::routes(['middleware' => ['auth:api']]);

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

        Route::prefix('categories')->group(function () {
            Route::get('/', [CategoryController::class, 'index']);
            Route::get('/{id}', [CategoryController::class, 'show']);
            Route::post('/', [CategoryController::class, 'store'])->middleware('admin');
            Route::put('/{id}', [CategoryController::class, 'update'])->middleware('admin');
            Route::delete('/{id}', [CategoryController::class, 'destroy'])->middleware('admin');
        });

        Route::prefix('images')->group(function () {
            Route::post('/upload', [ImageUploadController::class, 'upload']);
            Route::get('/get/{id}', [ImageUploadController::class, 'show']);
            Route::get('/{image_type?}', [ImageUploadController::class, 'index']);
        });

        Route::prefix('conversations')->group(function () {
            Route::get('/', [ConversationController::class, 'index']);
            Route::get('/categorized', [ConversationController::class, 'indexGroupedByCategory']);
            Route::post('/', [ConversationController::class, 'store']);
            Route::post('/{id}/close', [ConversationController::class, 'close']);
            Route::post('/{id}/invite', [ConversationController::class, 'invite']);
            Route::delete('/{id}/participants/{userId}', [ConversationController::class, 'remove']);
            Route::post('/{id}/ban/{userId}', [ConversationController::class, 'ban']);
            Route::post('/{id}/read', [ConversationController::class, 'markRead']);
            Route::get('/{id}/messages', [MessageController::class, 'index']);
            Route::post('/{id}/messages', [MessageController::class, 'store'])->middleware('throttle:60,1');
            Route::get('/{id}', [ConversationController::class, 'show']);
        });
    });
});