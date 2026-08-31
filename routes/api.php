<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
*/

use App\Controllers\UserController;
use Kayra\Routing\Route;

Route::group(['prefix' => 'api/v1', 'name' => 'api.v1.'], function (): void {
    Route::get('/ping', fn (): array => ['pong' => true, 'at' => gmdate('c')])->name('ping');

    Route::apiResource('users', UserController::class);

    Route::group(['middleware' => 'auth'], function (): void {
        Route::get('/me', [UserController::class, 'me'])->name('me');
    });
});
