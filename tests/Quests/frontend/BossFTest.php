<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
 * ボス戦F 自分の受講一覧（lessons/frontend/boss-f-my-attendances.md）
 */

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

require_once __DIR__.'/../QuestHelpers.php';

beforeEach(function (): void {
    questRequireClasses(['App\Http\Controllers\Web\MyAttendanceController'], 'ボス戦F');
    $this->withoutVite();
});

test('[メイン] 自分の受講中の講座だけが一覧に出る', function (): void {
    $student = questStudent();
    $first = questAttendance($student, questCourse(['title' => '一つ目']));
    $second = questAttendance($student, questCourse(['title' => '二つ目']));
    questAttendance($student, questCourse(), 'cancelled');
    questAttendance(questStudent(), questCourse());

    $this->actingAs($student)->get(route('me.attendances.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('me/attendances')
            ->has('attendances.data', 2)
            ->where('attendances.data.0.id', $first)
            ->where('attendances.data.1.id', $second)
            ->has('attendances.data.0.course.title')
            ->has('attendances.data.0.course.starts_at'));
});

test('[メイン] 締切の前ならキャンセルでき、講座一覧の残席が戻る', function (): void {
    $student = questStudent();
    $course = questCourse(['capacity' => 5, 'starts_at' => now()->addDays(10)]);
    questAttendance($student, $course);

    $this->actingAs($student)
        ->from(route('me.attendances.index'))
        ->delete(route('courses.attendances.destroy', $course))
        ->assertRedirect(route('me.attendances.index'))
        ->assertSessionHasNoErrors();

    expect(DB::table('attendances')->where('course_id', $course->id)->value('status'))
        ->toBe('cancelled', 'ヒント: Web\AttendanceController@destroy で CancelAttendance を呼びます');

    $this->actingAs($student)->get(route('courses.index'))
        ->assertInertia(fn (Assert $page) => $page->where('courses.data.0.remaining_seats', 5));
});

test('[メイン] 締切を過ぎたキャンセルはエラーメッセージ付きで戻る', function (): void {
    $student = questStudent();
    $course = questCourse(['starts_at' => now()->addDays(2)]);
    questAttendance($student, $course);

    $response = $this->actingAs($student)
        ->withHeaders(['X-Inertia' => 'true'])
        ->from(route('me.attendances.index'))
        ->delete(route('courses.attendances.destroy', $course));

    questExpectStatus($response, 303, '業務例外（締切を過ぎたキャンセル）を catch して back()->withErrors([\'attendance\' => ...]) にしましょう');
    $response->assertSessionHasErrors('attendance');
    expect(DB::table('attendances')->where('course_id', $course->id)->value('status'))->toBe('attending');
});
