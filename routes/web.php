<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
*/

use App\Controllers\Auth\AuthController;
use App\Controllers\HomeController;
use App\Controllers\PostController;
use Kayra\Routing\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/docs', [HomeController::class, 'docs'])->name('docs');

Route::get('/health', [HomeController::class, 'health'])->name('health');

/*
|--------------------------------------------------------------------------
| Demo application
|--------------------------------------------------------------------------
| A small blog that exercises the whole stack end to end: sessions, CSRF,
| validation, the ORM with an eager-loaded relation, authentication and
| policy-based authorization.
|
| Everything here is behind the `web` group, which starts the session, verifies
| the CSRF token on unsafe methods and publishes $errors / $old / $currentUser
| to the views.
*/

Route::group(['middleware' => 'web'], static function (): void {
    // Guest-only pages. The controllers redirect a signed-in visitor away.
    // `throttle` is what stops the login form from being a free password
    // oracle: 10 attempts a minute, per client.
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');

    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Reading is open to everyone; the index and the show page render the same
    // for a guest.
    Route::get('/posts', [PostController::class, 'index'])->name('posts.index');

    // Writing needs an account. `auth` answers "who are you", the policy
    // answers "may you" -- two questions, two layers.
    Route::group(['middleware' => 'auth'], static function (): void {
        Route::get('/posts/create', [PostController::class, 'create'])->name('posts.create');
        Route::post('/posts', [PostController::class, 'store'])->name('posts.store');

        // `can:update,post` runs PostPolicy::update() against the {post} route
        // parameter -- by then already substituted for the Post record -- before
        // the controller is even constructed. The controller calls authorize()
        // as well, on purpose: the route is the fence, the controller is the
        // lock, and neither is trusted to be the only one.
        Route::get('/posts/{post}/edit', [PostController::class, 'edit'])
            ->whereNumber('post')
            ->middleware('can:update,post')
            ->name('posts.edit');

        Route::put('/posts/{post}', [PostController::class, 'update'])
            ->whereNumber('post')
            ->middleware('can:update,post')
            ->name('posts.update');

        Route::delete('/posts/{post}', [PostController::class, 'destroy'])
            ->whereNumber('post')
            ->middleware('can:delete,post')
            ->name('posts.destroy');
    });
});
