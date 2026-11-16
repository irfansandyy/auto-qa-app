<?php

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ChatSessionController;
use App\Http\Controllers\Api\ChatStreamController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'ability:chat', 'throttle:chat'])->group(function () {
    Route::post('/chat-ai', ChatController::class);
    Route::post('/chat-ai/stream', ChatStreamController::class);
});

Route::middleware(['auth:sanctum', 'throttle:chat'])->group(function () {
    Route::get('/chat-sessions', [ChatSessionController::class, 'index']);
    Route::get('/chat-sessions/{session}', [ChatSessionController::class, 'show']);
});
