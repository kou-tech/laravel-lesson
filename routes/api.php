<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/user/{user}', [App\Http\Controllers\Api\UserController::class, 'show']);

// 認証必要（要ログイン）
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [App\Http\Controllers\Api\UserController::class, 'me']);
    Route::put('/me', [App\Http\Controllers\Api\UserController::class, 'put']);

    // 講座管理
    Route::post('/courses', [App\Http\Controllers\Api\CourseController::class, 'store']);
    Route::get('/courses/{course}', [App\Http\Controllers\Api\CourseController::class, 'show']);
    Route::patch('/courses/{course}', [App\Http\Controllers\Api\CourseController::class, 'update']);
    Route::delete('/courses/{course}', [App\Http\Controllers\Api\CourseController::class, 'destroy']);
});
