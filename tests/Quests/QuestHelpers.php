<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * 判定テスト（tests/Quests）だけが使うヘルパーです。
 *
 * 受講者が書く Factory の state（student() や active() など）は、書き方が人によって違うので使いません。
 * データはここで、教材が指定しているカラムと値だけを使って作ります。
 */

if (! function_exists('questRequireClasses')) {
    /**
     * 教材で作るクラスがまだなければ、そのミッションを「未着手」としてスキップします。
     *
     * @param array<int, class-string> $classes
     */
    function questRequireClasses(array $classes, string $where): void
    {
        foreach ($classes as $class) {
            if (! class_exists($class) && ! enum_exists($class)) {
                test()->markTestSkipped("未着手: {$class} がまだありません（{$where}）");
            }
        }
    }
}

if (! function_exists('questExpectStatus')) {
    /**
     * ステータスコードを確かめ、違ったときは日本語のヒントを出します。
     * 500 のときは、原因が分かるよう例外のメッセージも添えます。
     */
    function questExpectStatus(TestResponse $response, int $expected, string $hint): void
    {
        $actual = $response->status();
        $detail = '';

        if ($actual >= 500 && $response->exception !== null) {
            $detail = ' / 例外: '.$response->exception->getMessage();
        }

        expect($actual)->toBe($expected, "ヒント: {$hint}（返ってきたステータス: {$actual}{$detail}）");
    }
}

if (! function_exists('questStudent')) {
    function questStudent(): User
    {
        return User::factory()->create(['role' => 'student']);
    }
}

if (! function_exists('questInstructor')) {
    function questInstructor(): User
    {
        return User::factory()->create(['role' => 'instructor']);
    }
}

if (! function_exists('questCourse')) {
    /**
     * 判定用の講座を作ります。starts_at は Lesson 10 で足すカラムなので、あるときだけ入れます。
     *
     * @param array<string, mixed> $overrides
     */
    function questCourse(array $overrides = []): Course
    {
        $attributes = [
            'title' => '判定用の講座 '.Str::random(8),
            'description' => '判定テストで使う講座です。',
            'capacity' => 10,
            'status' => 'active',
        ];

        if (! array_key_exists('instructor_id', $overrides)) {
            $attributes['instructor_id'] = questInstructor()->id;
        }

        if (Schema::hasColumn('courses', 'starts_at')) {
            $attributes['starts_at'] = now()->addMonth();
        }

        return Course::forceCreate(array_merge($attributes, $overrides));
    }
}

if (! function_exists('questAttendance')) {
    /**
     * 受講の行を直接作ります。受講者のモデルの $fillable に左右されないよう、クエリビルダーで入れます。
     */
    function questAttendance(User $user, Course $course, string $status = 'attending'): int
    {
        return DB::table('attendances')->insertGetId([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => $status,
            'attended_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
