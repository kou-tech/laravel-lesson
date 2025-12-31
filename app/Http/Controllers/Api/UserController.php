<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;

class UserController extends Controller
{
    public function show(User $user): UserResource
    {
        // Laravelが自動的にIDからUserを取得してくれる
        // 見つからない場合は自動で404を返す
        return new UserResource($user);
    }
}
