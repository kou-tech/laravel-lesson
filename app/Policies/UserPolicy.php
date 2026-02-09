<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * ユーザー一覧を閲覧できるか
     */
    public function viewAny(User $user): bool
    {
        // 講師のみ全ユーザーを閲覧可能
        return $user->isInstructor();
    }

    /**
     * 特定のユーザーを閲覧できるか
     */
    public function view(User $user, User $model): bool
    {
        // 自分自身 または 講師なら閲覧可能
        return $user->id === $model->id || $user->isInstructor();
    }

    /**
     * ユーザーを作成できるか
     */
    public function create(User $user): bool
    {
        // 講師のみ作成可能
        return $user->isInstructor();
    }

    /**
     * ユーザーを更新できるか
     */
    public function update(User $user, User $model): bool
    {
        // 自分自身のみ更新可能
        return $user->id === $model->id;
    }

    /**
     * ユーザーを削除できるか
     */
    public function delete(User $user, User $model): bool
    {
        // 誰も削除できない（または管理者のみ）
        return false;
    }
}
