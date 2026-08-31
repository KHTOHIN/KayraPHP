<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
*/

use App\Controllers\HomeController;
use Kayra\Routing\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/docs', [HomeController::class, 'docs'])->name('docs');

Route::get('/health', [HomeController::class, 'health'])->name('health');
