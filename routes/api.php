<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/user/{user}', [App\Http\Controllers\Api\UserController::class, 'show']);
