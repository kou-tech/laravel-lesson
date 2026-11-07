<?php

declare(strict_types=1);

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
 * Stage F4 Inertia でページをつなぐ（lessons/frontend/04-inertia.md）
 */

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

require_once __DIR__.'/../QuestHelpers.php';

beforeEach(function (): void {
    questRequireClasses(['App\Http\Controllers\Web\CourseController'], 'F4 の Step 1');
    $this->withoutVite();
});

if (! function_exists('questCoursePayload')) {
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    function questCoursePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => '判定で作る講座',
            'description' => '判定テストで作る講座です。',
            'capacity' => 20,
            'status' => 'draft',
            'starts_at' => now()->addMonth()->format('Y-m-d H:i:s'),
        ], $overrides);
    }
}

test('[メイン] 講座一覧はコントローラーから講師と残席を渡す', function (): void {
    $course = questCourse(['capacity' => 5]);
    questAttendance(questStudent(), $course);
    questAttendance(questStudent(), $course, 'cancelled');

    $response = $this->actingAs(questStudent())->get(route('courses.index'));

    questExpectStatus($response, 200, 'Step 1。courses.index を Web\CourseController@index につなぎましょう');
    $response->assertInertia(fn (Assert $page) => $page
        ->component('courses/index')
        ->where('filters.status', null)
        ->has('courses.data', 1, fn (Assert $item) => $item
            ->has('instructor.name')
            ->where('remaining_seats', 4)
            ->etc()));
});

test('[メイン] ?status= で講座を絞り込める', function (): void {
    questCourse(['status' => 'active']);
    questCourse(['status' => 'draft']);
    $student = questStudent();

    $this->actingAs($student)->get(route('courses.index', ['status' => 'active']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'active')
            ->has('courses.data', 1)
            ->where('courses.data.0.status', 'active'));

    $this->actingAs($student)->get(route('courses.index', ['status' => 'published']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', null)
            ->has('courses.data', 2));
});

test('[メイン] 講師だけが講座作成のページを開ける', function (): void {
    expect(Route::has('courses.create'))->toBeTrue('ヒント: Step 4。courses.create という名前のルートを足しましょう');

    $this->actingAs(questInstructor())->get(route('courses.create'))
        ->assertInertia(fn (Assert $page) => $page->component('courses/create'));

    questExpectStatus($this->actingAs(questStudent())->get(route('courses.create')), 403, 'Step 4。create で CoursePolicy の create を authorize しましょう');
});

test('[メイン] 講師は講座を作れて、入力の誤りはエラーになる', function (): void {
    $instructor = questInstructor();

    $this->actingAs($instructor)->post(route('courses.store'), questCoursePayload())
        ->assertRedirect(route('courses.index'));
    expect(DB::table('courses')->where('title', '判定で作る講座')->exists())->toBeTrue('ヒント: Step 4。store で CreateCourse を呼んで講座を作ります');

    $this->actingAs($instructor)->post(route('courses.store'), questCoursePayload(['title' => '', 'capacity' => 200]))
        ->assertSessionHasErrors(['title', 'capacity']);

    questExpectStatus($this->actingAs(questStudent())->post(route('courses.store'), questCoursePayload(['title' => '生徒の講座'])), 403, 'Step 4。store でも authorize しましょう');
});

test('[メイン] 業務例外はエラーメッセージ付きで戻る', function (): void {
    $instructor = questInstructor();
    questCourse(['title' => '重複するタイトル', 'instructor_id' => $instructor->id]);

    $response = $this->actingAs($instructor)
        ->withHeaders(['X-Inertia' => 'true'])
        ->from(route('courses.create'))
        ->post(route('courses.store'), questCoursePayload(['title' => '重複するタイトル']));

    questExpectStatus($response, 302, 'Step 5。業務例外を catch して back()->withErrors() にしましょう（catch しないと JSON が返り、画面にエラーのモーダルが出ます）');
    $response->assertSessionHasErrors('title');
    expect(Course::query()->where('title', '重複するタイトル')->count())->toBe(1);
});

test('[サイド] 講座を作ったらフラッシュのメッセージを出す', function (): void {
    $this->actingAs(questInstructor())->post(route('courses.store'), questCoursePayload(['title' => 'フラッシュの講座']))
        ->assertInertiaFlash('message', '講座を作りました。');
});
