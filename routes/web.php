<?php

use App\Http\Controllers\Admin\AdsAccountController;
use App\Http\Controllers\Admin\UserAccessController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Guest-only: an already-logged-in user hitting these gets bounced straight
// to the dashboard instead of seeing the login screen again.
Route::middleware('guest')->group(function () {
    Route::get('/login', [GoogleAuthController::class, 'show'])->name('login');
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});
Route::post('/logout', [GoogleAuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index']);

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [UserAccessController::class, 'index'])->name('index');
        Route::post('/users', [UserAccessController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}/role', [UserAccessController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserAccessController::class, 'destroy'])->name('users.destroy');
        Route::post('/ads-accounts', [AdsAccountController::class, 'store'])->name('ads-accounts.store');
        Route::patch('/ads-accounts/{adsAccount}', [AdsAccountController::class, 'update'])->name('ads-accounts.update');
        Route::delete('/ads-accounts/{adsAccount}', [AdsAccountController::class, 'destroy'])->name('ads-accounts.destroy');
    });
});
