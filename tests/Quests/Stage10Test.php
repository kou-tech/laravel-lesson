<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * Stage 10 受講登録APIを作る（lessons/backend/10-attendance-api.md）
 */

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

require_once __DIR__.'/QuestHelpers.php';

beforeEach(function (): void {
    questRequireClasses(['App\Models\Course', 'App\Models\User'], 'Lesson 9');
});

test('[前提] 講座一覧APIが動く', function (): void {
    questCourse();

    questExpectStatus($this->getJson('/api/courses'), 200, 'Lesson 9 の GET /api/courses が動くかを確かめましょう');
});

test('[メイン] 生徒は講座に受講登録できる', function (): void {
    questRequireClasses(['App\Models\Attendance', 'App\Enums\AttendanceStatus'], 'Lesson 10 の Step 2');
    $student = questStudent();
    $course = questCourse();

    $response = $this->actingAs($student)->postJson("/api/courses/{$course->id}/attendances");

    questExpectStatus($response, 201, '受講登録の成功時は 201 を返します。routes/api.php のルートと AttendanceController::store を確かめましょう');
    expect(DB::table('attendances')->where('user_id', $student->id)->where('course_id', $course->id)->value('status'))
        ->toBe('attending', 'ヒント: 受講登録した行の status が attending になっていません');
});

test('[メイン] 生徒以外は受講登録できない', function (): void {
    questRequireClasses(['App\Models\Attendance', 'App\Policies\AttendancePolicy'], 'Lesson 10 の Step 4');
    $instructor = questInstructor();
    $course = questCourse();

    $response = $this->actingAs($instructor)->postJson("/api/courses/{$course->id}/attendances");

    questExpectStatus($response, 403, 'AttendancePolicy の create で生徒だけを許可し、store の最初で authorize します');
    expect(DB::table('attendances')->count())->toBe(0, 'ヒント: 403 を返すときに受講の行が作られています');
});

test('[メイン] 定員に達した講座には申し込めない', function (): void {
    questRequireClasses(['App\Models\Attendance'], 'Lesson 10 の Step 2');
    $course = questCourse(['capacity' => 1]);
    questAttendance(questStudent(), $course);

    $response = $this->actingAs(questStudent())->postJson("/api/courses/{$course->id}/attendances");

    questExpectStatus($response, 422, 'Course::hasCapacity() で定員を確かめ、空きがなければ 422 を返します');
    expect(DB::table('attendances')->count())->toBe(1, 'ヒント: 定員を超えて受講の行が作られています');
});

test('[メイン] 同じ講座に2回申し込むと 409 になる', function (): void {
    questRequireClasses(['App\Models\Attendance'], 'Lesson 10 の Step 2');
    $student = questStudent();
    $course = questCourse();
    questAttendance($student, $course);

    $response = $this->actingAs($student)->postJson("/api/courses/{$course->id}/attendances");

    questExpectStatus($response, 409, '複合ユニーク制約の違反（UniqueConstraintViolationException）を catch して 409 を返します');
    expect(DB::table('attendances')->count())->toBe(1, 'ヒント: 同じ講座の受講の行が2つになっています');
});

test('[メイン] キャンセル済みの受講は定員に数えない', function (): void {
    questRequireClasses(['App\Models\Attendance'], 'Lesson 10 の Step 2');
    $course = questCourse(['capacity' => 1]);
    questAttendance(questStudent(), $course, 'cancelled');

    $response = $this->actingAs(questStudent())->postJson("/api/courses/{$course->id}/attendances");

    questExpectStatus($response, 201, 'hasCapacity() は status が attending の受講だけを数えます');
});

test('[サイド] 講座詳細に残席数が出る', function (): void {
    questRequireClasses(['App\Models\Attendance'], 'Lesson 10 の Step 2');
    $course = questCourse(['capacity' => 5]);
    questAttendance(questStudent(), $course);
    questAttendance(questStudent(), $course);
    questAttendance(questStudent(), $course, 'cancelled');

    $response = $this->getJson("/api/courses/{$course->id}");

    questExpectStatus($response, 200, 'Lesson 9 の GET /api/courses/{course} が動くかを確かめましょう');
    expect($response->json('data.remaining_seats'))
        ->toBe(3, 'ヒント: 練習問題1。data.remaining_seats は「定員 − 受講中の人数」で、キャンセル済みは数えません');
});
