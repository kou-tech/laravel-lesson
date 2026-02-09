<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        $users = User::all();
        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
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

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        // 認可チェック
        $this->authorize('update', $user);

        // 更新
        $user->update($request->validated());

        return new UserResource($user);
    }
}
