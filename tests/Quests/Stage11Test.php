<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Stage 11 安全なモデルの記述（lessons/backend/11-safe-model.md）
 *
 * Mass Assignment の対策は Lesson 10 の時点で効いていて、作業の前後で動きの差が出ないため、判定に含めません。
 */

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

require_once __DIR__.'/QuestHelpers.php';

beforeEach(function (): void {
    questRequireClasses(['App\Models\Attendance', 'App\Enums\AttendanceStatus'], 'Lesson 10');
});

test('[前提] 受講登録APIが動く', function (): void {
    $response = $this->actingAs(questStudent())->postJson('/api/courses/'.questCourse()->id.'/attendances');

    questExpectStatus($response, 201, 'Lesson 10 の受講登録API（POST /api/courses/{course}/attendances）が動くかを確かめましょう');
});

test('[メイン] 受講を配列にすると status_label が付く', function (): void {
    questAttendance(questStudent(), questCourse());

    $array = Attendance::query()->firstOrFail()->toArray();

    expect(array_key_exists('status_label', $array))
        ->toBeTrue('ヒント: Step 7。$appends に status_label を入れ、statusLabel アクセサを作ります');
    expect($array['status_label'])->toBe('受講中', 'ヒント: statusLabel アクセサは $this->status->label() を返します');
});

test('[メイン] 受講を配列にすると created_at と updated_at が出ない', function (): void {
    questAttendance(questStudent(), questCourse());

    $array = Attendance::query()->firstOrFail()->toArray();

    expect(array_key_exists('created_at', $array) || array_key_exists('updated_at', $array))
        ->toBeFalse('ヒント: Step 7。$hidden に created_at と updated_at を入れます');
});

test('[メイン] active スコープは受講中だけを返す', function (): void {
    $course = questCourse();
    $attendingId = questAttendance(questStudent(), $course);
    questAttendance(questStudent(), $course, 'cancelled');

    $ids = Attendance::query()->active()->pluck('id')->all();

    expect($ids)->toBe([$attendingId], 'ヒント: Step 7。scopeActive で status が attending の受講だけに絞ります');
});

test('[サイド] ユーザーの登録からの日数が分かる', function (): void {
    $user = questStudent();
    $user->forceFill(['created_at' => now()->subDays(30)])->save();

    expect(User::query()->findOrFail($user->id)->days_since_registration)
        ->toBe(30, 'ヒント: 練習問題1。daysSinceRegistration アクセサで、created_at から今日までの日数を int で返します');
});

test('[サイド] 講座のタイトルは前後の空白を取り除いて保存される', function (): void {
    $course = questCourse(['title' => '  空白つきのタイトル  ']);

    expect($course->fresh()->title)
        ->toBe('空白つきのタイトル', 'ヒント: 練習問題2。title のミューテタで trim します');
});
