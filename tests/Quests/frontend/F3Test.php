<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
 * Stage F3 React で宣言的に書き直す（lessons/frontend/03-react.md）
 */

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

require_once __DIR__.'/../QuestHelpers.php';

beforeEach(function (): void {
    questRequireClasses(['App\Models\Course', 'App\Http\Resources\CourseResource'], 'Lesson 9');
    $this->withoutVite();
});

test('[メイン] 講座一覧のページに講座が届く', function (): void {
    expect(Route::has('courses.index'))->toBeTrue('ヒント: Step 1。routes/web.php に courses.index という名前のルートを足しましょう');

    questCourse(['title' => '一つ目']);
    questCourse(['title' => '二つ目']);

    $response = $this->actingAs(questStudent())->get(route('courses.index'));

    questExpectStatus($response, 200, 'Step 1。auth・verified のグループの中に GET /courses のルートを足しましょう');
    $response->assertInertia(fn (Assert $page) => $page
        ->component('courses/index')
        ->has('courses.data', 2, fn (Assert $course) => $course
            ->hasAll(['id', 'title', 'status', 'status_label'])
            ->etc()));
});
