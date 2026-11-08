<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
 * Stage F7 待ち時間を設計する（lessons/frontend/07-async-ui.md）
 */

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

require_once __DIR__.'/../QuestHelpers.php';

beforeEach(function (): void {
    questRequireClasses(['App\Http\Controllers\Web\AttendanceController'], 'F7 の Step 3');
    $this->withoutVite();
});

test('[メイン] 画面から受講登録できる', function (): void {
    $student = questStudent();
    $course = questCourse();

    $response = $this->actingAs($student)
        ->from(route('courses.index'))
        ->post(route('courses.attendances.store', $course));

    $response->assertRedirect(route('courses.index'))->assertSessionHasNoErrors();
    expect(DB::table('attendances')->where('user_id', $student->id)->where('course_id', $course->id)->value('status'))
        ->toBe('attending', 'ヒント: Step 3。Web\AttendanceController@store で AttendCourse を呼びます');
});

test('[メイン] 満席と二重申し込みはエラーメッセージ付きで戻る', function (): void {
    $full = questCourse(['capacity' => 1]);
    questAttendance(questStudent(), $full);
    $student = questStudent();

    $response = $this->actingAs($student)
        ->withHeaders(['X-Inertia' => 'true'])
        ->from(route('courses.index'))
        ->post(route('courses.attendances.store', $full));

    questExpectStatus($response, 302, 'Step 3。業務例外を catch して back()->withErrors([\'attendance\' => ...]) にしましょう（catch しないと JSON が返り、画面にエラーのモーダルが出ます）');
    $response->assertSessionHasErrors('attendance');

    $course = questCourse();
    questAttendance($student, $course);

    $this->actingAs($student)
        ->from(route('courses.index'))
        ->post(route('courses.attendances.store', $course))
        ->assertSessionHasErrors('attendance');
    expect(DB::table('attendances')->where('course_id', $course->id)->count())->toBe(1, 'ヒント: 二重申し込みで受講の行が増えています');
});

test('[メイン] 講師は受講登録できない', function (): void {
    $response = $this->actingAs(questInstructor())->post(route('courses.attendances.store', questCourse()));

    questExpectStatus($response, 403, 'Step 3。store の最初で AttendancePolicy の create を authorize しましょう');
});

test('[メイン] 統計を遅れて読み込む', function (): void {
    questCourse(['status' => 'active']);
    questCourse(['status' => 'active']);
    questCourse(['status' => 'draft']);

    $this->actingAs(questStudent())->get(route('courses.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('courses/index')
            ->missing('stats')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('stats.active', 2)
                ->where('stats.draft', 1)
                ->where('stats.closed', 0)));
});
