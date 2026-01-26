<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    /**
     * 講座を作成できるか
     */
    public function create(User $user): bool
    {
        return $user->isInstructor();
    }

    /**
     * 講座を更新できるか
     */
    public function update(User $user, Course $course): bool
    {
        // 担当講師のみ
        return $user->id === $course->instructor_id;
    }

    /**
     * 講座を削除できるか
     */
    public function delete(User $user, Course $course): bool
    {
        // 担当講師のみ
        return $user->id === $course->instructor_id;
    }
}
