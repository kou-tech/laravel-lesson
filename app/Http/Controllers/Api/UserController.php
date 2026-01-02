<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function show(User $user): UserResource
    {
        // Laravelが自動的にIDからUserを取得してくれる
        // 見つからない場合は自動で404を返す
        return new UserResource($user);
    }

    /**
     * 認証済みユーザー自身の情報を返す
     */
    public function me(Request $request): UserResource
    {
        // $request->user() で認証済みユーザーを取得
        return new UserResource($request->user());
    }

    public function put(Request $request): UserResource
    {
        // 認証済みユーザーのみが更新可能
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user->update($validated);

        return new UserResource($user);
    }
}
