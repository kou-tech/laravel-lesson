<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/hello', function () {
    return ['message' => 'Hello, World!'];
});

Route::get('/fruits', function () {
    return [
        ['id' => 1, 'name' => 'りんご', 'price' => 150],
        ['id' => 2, 'name' => 'みかん', 'price' => 100],
        ['id' => 3, 'name' => 'バナナ', 'price' => 200],
    ];
});

Route::get('/fruits/{id}', function (int $id) {
    $fruits = [
        1 => ['id' => 1, 'name' => 'りんご', 'price' => 150],
        2 => ['id' => 2, 'name' => 'みかん', 'price' => 100],
        3 => ['id' => 3, 'name' => 'バナナ', 'price' => 200],
    ];

    if (!isset($fruits[$id])) {
        return response()->json(['message' => '見つかりません'], 404);
    }

    return $fruits[$id];
});

Route::post('/fruits', function () {
    // リクエストボディからデータを取得
    $name = request('name');
    $price = request('price');

    // 本来はデータベースに保存する
    return response()->json([
        'message' => '作成しました',
        'data' => [
            'id' => 4,
            'name' => $name,
            'price' => $price,
        ]
    ], 201);
});

Route::get('/users', [\App\Http\Controllers\Api\UserController::class, 'index']);
Route::get('/users/{user}', [\App\Http\Controllers\Api\UserController::class, 'show']);

// 公開API（認証不要）
Route::get('/courses', [\App\Http\Controllers\Api\CourseController::class, 'index']);
Route::get('/courses/{course}', [\App\Http\Controllers\Api\CourseController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [\App\Http\Controllers\Api\UserController::class, 'me']);
    Route::patch('/users/{user}', [\App\Http\Controllers\Api\UserController::class, 'update']);

    // 講座管理
    Route::post('/courses', [\App\Http\Controllers\Api\CourseController::class, 'store']);
    Route::patch('/courses/{course}', [\App\Http\Controllers\Api\CourseController::class, 'update']);
    Route::delete('/courses/{course}', [\App\Http\Controllers\Api\CourseController::class, 'destroy']);
    // 受講登録
    Route::post('/courses/{course}/attendances', [\App\Http\Controllers\Api\AttendanceController::class, 'store']);
});
