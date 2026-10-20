<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('register'));
Route::get('/register', [RegistrationController::class, 'show'])->name('register');
Route::post('/register', [RegistrationController::class, 'store']);
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store']);
Route::get('/dashboard', fn () => view('dashboard'))->middleware('auth')->name('dashboard');
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');
