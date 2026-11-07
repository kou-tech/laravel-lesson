<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);

    Route::middleware('web')->group(function (): void {
        Route::post('/lesson-test/courses/{course}/attendances', fn () => response('registered'))
            ->name('courses.attendances.store');
        Route::post('/lesson-test/other', fn () => response('other'));
    });
});

test('テストの環境では、失敗の設定があっても何もしない', function (): void {
    config(['lesson.fail' => 'attend', 'lesson.latency_ms' => 0]);

    $this->post('/lesson-test/courses/1/attendances')
        ->assertOk()
        ->assertSee('registered');
});

test('ローカルの環境で attend にすると、受講登録だけをエラー付きで戻す', function (): void {
    app()->detectEnvironment(fn () => 'local');
    config(['lesson.fail' => 'attend', 'lesson.latency_ms' => 0]);

    $this->from('/courses')
        ->post('/lesson-test/courses/1/attendances')
        ->assertRedirect('/courses')
        ->assertSessionHasErrors('attendance');

    $this->post('/lesson-test/other')
        ->assertOk()
        ->assertSee('other');
});

test('ローカルの環境で 500 にすると、受講のルートだけをサーバーのエラーにする', function (): void {
    app()->detectEnvironment(fn () => 'local');
    config(['lesson.fail' => '500', 'lesson.latency_ms' => 0]);

    $this->post('/lesson-test/courses/1/attendances')->assertStatus(500);

    $this->post('/lesson-test/other')
        ->assertOk()
        ->assertSee('other');
});
