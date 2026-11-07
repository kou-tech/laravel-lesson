<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * Stage F0 準備（lessons/frontend/00-setup.md）
 */

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

require_once __DIR__.'/../QuestHelpers.php';

test('[前提] 講座一覧の API が動く', function (): void {
    questRequireClasses(['App\Models\Course'], 'Lesson 9');
    questCourse();

    $response = $this->getJson('/api/courses');

    questExpectStatus($response, 200, 'Lesson 9 の GET /api/courses が動くかを確かめましょう');
    expect($response->json('data'))->toBeArray('ヒント: GET /api/courses の data が配列になっていません');
});

test('[前提] フロントエンド編で使うクラスがそろっている', function (): void {
    $classes = [
        'App\Services\Attendance\AttendCourse' => 'Lesson 14',
        'App\Services\Attendance\CancelAttendance' => 'Lesson 18',
        'App\Services\Course\CreateCourse' => 'Lesson 14',
        'App\Exceptions\BusinessException' => 'Lesson 14',
    ];

    foreach ($classes as $class => $lesson) {
        expect(class_exists($class))->toBeTrue("ヒント: {$class}（{$lesson}）がありません。バックエンドの教材を先に終えましょう");
    }
});

test('[メイン] 練習用のデータが入っている', function (): void {
    // 判定テストの DB（:memory:）ではなく、画面で使っている開発用の DB を読む
    config(['database.connections.lesson_dev' => [
        'driver' => 'sqlite',
        'database' => database_path('database.sqlite'),
        'foreign_key_constraints' => true,
    ]]);

    $exists = DB::connection('lesson_dev')
        ->table('courses')
        ->where('title', 'TypeScript 実践（満席）')
        ->exists();

    expect($exists)->toBeTrue('ヒント: Step 2。make seed-frontend で練習用のデータを入れましょう');
});
