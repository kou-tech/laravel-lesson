<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Course;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * フロントエンド編の練習用のデータを入れます（make seed-frontend）。
 *
 * バックエンドの教材を最後まで終えた状態（attendances テーブル、courses.starts_at がある）を前提にしています。
 * 何度実行しても、同じデータが重複しないようにしています。
 */
class FrontendLessonSeeder extends Seeder
{
    public function run(): void
    {
        $instructor = User::query()->where('email', 'test2@example.com')->firstOrFail();
        $student = User::query()->where('email', 'test@example.com')->firstOrFail();
        $others = collect(['student1@example.com', 'student2@example.com'])
            ->map(fn (string $email): User => $this->student($email));

        $open = $this->course($instructor, 'React 入門', 'active', 20, now()->addDays(30));
        $full = $this->course($instructor, 'TypeScript 実践（満席）', 'active', 2, now()->addDays(20));
        $soon = $this->course($instructor, 'Inertia でつくる画面（開始間近）', 'active', 10, now()->addDays(2));
        $joined = $this->course($instructor, 'コンポーネント設計（受講中）', 'active', 10, now()->addDays(14));
        $this->course($instructor, '非同期処理の設計（下書き）', 'draft', 10, now()->addDays(60));
        $this->course($instructor, 'JavaScript 基礎（募集終了）', 'closed', 10, now()->addDays(7));

        foreach ($others as $other) {
            $this->attend($other, $full);
        }

        $this->attend($others->first(), $open);
        $this->attend($student, $soon);
        $this->attend($student, $joined);
    }

    private function student(string $email): User
    {
        return User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => '練習用の生徒 '.mb_strstr($email, '@', true),
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'role' => 'student',
            ],
        );
    }

    private function course(User $instructor, string $title, string $status, int $capacity, DateTimeInterface $startsAt): Course
    {
        $course = Course::query()
            ->where('instructor_id', $instructor->id)
            ->where('title', $title)
            ->first();

        if ($course !== null) {
            return $course;
        }

        return Course::query()->forceCreate([
            'instructor_id' => $instructor->id,
            'title' => $title,
            'description' => 'フロントエンド編の練習用の講座です。',
            'capacity' => $capacity,
            'status' => $status,
            'starts_at' => $startsAt,
        ]);
    }

    private function attend(User $user, Course $course): void
    {
        DB::table('attendances')->updateOrInsert(
            ['user_id' => $user->id, 'course_id' => $course->id],
            ['status' => 'attending', 'attended_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        );
    }
}
