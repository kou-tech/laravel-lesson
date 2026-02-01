# Lesson 19 TDDで機能を追加する

## 学習目標

このレッスンでは、テスト駆動開発（TDD）のサイクルを体験し、品質の高い機能追加を実践します。

### 到達目標
- TDDの基本サイクル（Red-Green-Refactor）を理解する
- テストを先に書いて機能を実装できる
- リファクタリングの安全性を体験する


## TDDとは？

### Test-Driven Development（テスト駆動開発）

テストを先に書いてから、そのテストを通すコードを書く開発手法です。

### TDDの3ステップサイクル

```
1. Red（赤）: 失敗するテストを書く
          ↓
2. Green（緑）: テストを通す最小限のコードを書く
          ↓
3. Refactor（リファクタリング）: コードを整理する
          ↓
     （1に戻る）
```


## 実践 受講キャンセル機能をTDDで実装

### 要件

- 生徒は自分の受講をキャンセルできる
- 講座開始3日前以降はキャンセル不可
- キャンセル済みの受講は再キャンセル不可
- キャンセル時にメール通知を送信


## Step 1 Red - 失敗するテストを書く

### テストファイルの作成

```bash
php artisan make:test Api/AttendanceCancelTest
```

### 最初のテスト

```php
// tests/Feature/Api/AttendanceCancelTest.php

<?php

namespace Tests\Feature\Api;

use App\Enums\AttendanceStatus;
use App\Models\Course;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_cancel_attendance(): void
    {
        // Arrange: テストデータ準備
        $student = User::factory()->student()->create();
        $course = Course::factory()->create([
            'starts_at' => now()->addDays(7),  // 7日後開始
        ]);
        $attendance = Attendance::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => AttendanceStatus::Attending,
        ]);

        // Act: APIを呼び出す
        $response = $this->actingAs($student)
            ->deleteJson("/api/courses/{$course->id}/attend");

        // Assert: 結果を検証
        $response->assertOk();

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'status' => AttendanceStatus::Cancelled->value,
        ]);
    }
}
```

### テストを実行（失敗する）

```bash
php artisan test --filter=test_student_can_cancel_attendance
```

```
FAILED  Tests\Feature\Api\AttendanceCancelTest > student can cancel attendance
404 Not Found
```

ルートが存在しないので404エラー。これがRed状態です。


## Step 2 Green - テストを通す最小限のコード

### ルートを追加

```php
// routes/api.php

Route::middleware('auth:sanctum')->group(function () {
    // ... 既存のルート

    Route::delete('/courses/{course}/attend', [AttendanceController::class, 'destroy']);
});
```

### コントローラーにメソッドを追加

```php
// app/Http/Controllers/Api/AttendanceController.php

public function destroy(Request $request, Course $course)
{
    $attendance = Attendance::where('user_id', $request->user()->id)
        ->where('course_id', $course->id)
        ->where('status', AttendanceStatus::Attending)
        ->firstOrFail();

    $attendance->update([
        'status' => AttendanceStatus::Cancelled,
    ]);

    return response()->json(['message' => 'キャンセルしました']);
}
```

### テストを実行（成功する）

```bash
php artisan test --filter=test_student_can_cancel_attendance
```

```
PASS  Tests\Feature\Api\AttendanceCancelTest > student can cancel attendance
```

Green状態になりました。


## Step 3 次のテストを追加（Red）

### 3日前以降はキャンセル不可

```php
public function test_cannot_cancel_within_3_days_before_start(): void
{
    $student = User::factory()->student()->create();
    $course = Course::factory()->create([
        'starts_at' => now()->addDays(2),  // 2日後開始（3日を切っている）
    ]);
    $attendance = Attendance::factory()->create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'status' => AttendanceStatus::Attending,
    ]);

    $response = $this->actingAs($student)
        ->deleteJson("/api/courses/{$course->id}/attend");

    $response->assertUnprocessable()
        ->assertJsonPath('message', '講座開始3日前以降はキャンセルできません');

    // ステータスが変わっていないことを確認
    $this->assertDatabaseHas('attendances', [
        'id' => $attendance->id,
        'status' => AttendanceStatus::Attending->value,
    ]);
}
```

### テスト実行（失敗）

```bash
php artisan test --filter=test_cannot_cancel_within_3_days
```

```
FAILED  Expected status code 422 but received 200.
```


## Step 4 実装を追加（Green）

### コントローラーを修正

```php
public function destroy(Request $request, Course $course)
{
    $attendance = Attendance::where('user_id', $request->user()->id)
        ->where('course_id', $course->id)
        ->where('status', AttendanceStatus::Attending)
        ->firstOrFail();

    // 3日前チェックを追加
    if ($course->starts_at->diffInDays(now()) < 3) {
        return response()->json([
            'message' => '講座開始3日前以降はキャンセルできません',
        ], 422);
    }

    $attendance->update([
        'status' => AttendanceStatus::Cancelled,
    ]);

    return response()->json(['message' => 'キャンセルしました']);
}
```

### テスト実行（成功）

```bash
php artisan test tests/Feature/Api/AttendanceCancelTest.php
```

```
PASS  Tests\Feature\Api\AttendanceCancelTest > student can cancel attendance
PASS  Tests\Feature\Api\AttendanceCancelTest > cannot cancel within 3 days before start
```


## Step 5 さらにテストを追加

### 再キャンセル不可

```php
public function test_cannot_cancel_already_cancelled(): void
{
    $student = User::factory()->student()->create();
    $course = Course::factory()->create(['starts_at' => now()->addDays(7)]);
    Attendance::factory()->create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'status' => AttendanceStatus::Cancelled,  // 既にキャンセル済み
    ]);

    $response = $this->actingAs($student)
        ->deleteJson("/api/courses/{$course->id}/attend");

    $response->assertNotFound();
}
```

### 他人の受講はキャンセル不可

```php
public function test_cannot_cancel_others_attendance(): void
{
    $student1 = User::factory()->student()->create();
    $student2 = User::factory()->student()->create();
    $course = Course::factory()->create(['starts_at' => now()->addDays(7)]);
    Attendance::factory()->create([
        'user_id' => $student1->id,
        'course_id' => $course->id,
        'status' => AttendanceStatus::Attending,
    ]);

    // student2 が student1 の受講をキャンセルしようとする
    $response = $this->actingAs($student2)
        ->deleteJson("/api/courses/{$course->id}/attend");

    $response->assertNotFound();
}
```

これらのテストは既存の実装で通ります。


## Step 6 Refactor - コードを整理

### サービスクラスに抽出

```php
// app/Services/AttendanceService.php

public function cancel(User $user, Course $course): void
{
    $attendance = $this->findActiveAttendance($user, $course);

    $this->validateCancellation($course);

    DB::transaction(function () use ($attendance) {
        $attendance->update([
            'status' => AttendanceStatus::Cancelled,
        ]);
    });
}

private function findActiveAttendance(User $user, Course $course): Attendance
{
    return Attendance::where('user_id', $user->id)
        ->where('course_id', $course->id)
        ->where('status', AttendanceStatus::Attending)
        ->firstOrFail();
}

private function validateCancellation(Course $course): void
{
    if ($course->starts_at->diffInDays(now()) < 3) {
        throw new CancellationDeadlineExceededException();
    }
}
```

### コントローラーをシンプルに

```php
public function destroy(Request $request, Course $course)
{
    $this->attendanceService->cancel($request->user(), $course);

    return response()->json(['message' => 'キャンセルしました']);
}
```

### テストを再実行して確認

```bash
php artisan test tests/Feature/Api/AttendanceCancelTest.php
```

全てのテストがパスすれば、リファクタリング成功です。


## Step 7 メール通知のテストを追加

```php
use Illuminate\Support\Facades\Mail;
use App\Mail\AttendanceCancellation;

public function test_sends_cancellation_email(): void
{
    Mail::fake();

    $student = User::factory()->student()->create();
    $course = Course::factory()->create(['starts_at' => now()->addDays(7)]);
    Attendance::factory()->create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'status' => AttendanceStatus::Attending,
    ]);

    $this->actingAs($student)
        ->deleteJson("/api/courses/{$course->id}/attend")
        ->assertOk();

    Mail::assertQueued(AttendanceCancellation::class, function ($mail) use ($student) {
        return $mail->hasTo($student->email);
    });
}
```

### サービスにメール送信を追加

```php
public function cancel(User $user, Course $course): void
{
    $attendance = $this->findActiveAttendance($user, $course);

    $this->validateCancellation($course);

    DB::transaction(function () use ($attendance) {
        $attendance->update([
            'status' => AttendanceStatus::Cancelled,
        ]);
    });

    // メール送信を追加
    Mail::to($user)->queue(new AttendanceCancellation($attendance));
}
```


## TDDのメリット

### 1. 設計が改善される

テストしやすいコード = 良い設計です。

```php
// ❌ テストしにくい（依存が隠れている）
public function cancel()
{
    $service = new AttendanceService();
    $service->cancel(...);
}

// ✅ テストしやすい（依存が明示的）
public function cancel(AttendanceService $service)
{
    $service->cancel(...);
}
```

### 2. 過剰な実装を防ぐ

テストが通るまで次のコードを書かないため、必要最小限の実装になります。

### 3. リファクタリングの安心感

テストがあるので、大胆に書き換えられます。

### 4. ドキュメントになる

テストコードが仕様書の役割を果たします。

## 練習問題

### 問題
以下の機能をTDDで実装してください。

「講師は自分の講座を公開できる（status を active に変更）」

- ドラフト状態の講座のみ公開可能
- 公開済みの講座は再公開不可
- 他の講師の講座は公開不可

<details>
<summary>解答例</summary>

Step 1 テストを書く

```php
// tests/Feature/Api/CoursePublishTest.php
<?php

namespace Tests\Feature\Api;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoursePublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_instructor_can_publish_own_draft_course(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->create([
            'instructor_id' => $instructor->id,
            'status' => CourseStatus::Draft,
        ]);

        $response = $this->actingAs($instructor)
            ->patchJson("/api/courses/{$course->id}/publish");

        $response->assertOk();
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'status' => CourseStatus::Active->value,
        ]);
    }

    public function test_cannot_publish_already_active_course(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->create([
            'instructor_id' => $instructor->id,
            'status' => CourseStatus::Active,
        ]);

        $response = $this->actingAs($instructor)
            ->patchJson("/api/courses/{$course->id}/publish");

        $response->assertUnprocessable();
    }

    public function test_cannot_publish_other_instructors_course(): void
    {
        $instructor1 = User::factory()->instructor()->create();
        $instructor2 = User::factory()->instructor()->create();
        $course = Course::factory()->create([
            'instructor_id' => $instructor1->id,
            'status' => CourseStatus::Draft,
        ]);

        $response = $this->actingAs($instructor2)
            ->patchJson("/api/courses/{$course->id}/publish");

        $response->assertForbidden();
    }
}
```

Step 2 実装する

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::patch('/courses/{course}/publish', [CourseController::class, 'publish']);
});
```

```php
// CourseController.php
public function publish(Course $course)
{
    $this->authorize('update', $course);

    if ($course->status !== CourseStatus::Draft) {
        return response()->json([
            'message' => 'ドラフト状態の講座のみ公開できます',
        ], 422);
    }

    $course->update(['status' => CourseStatus::Active]);

    return new CourseResource($course);
}
```
</details>


## 参考資料

- [Laravel 公式ドキュメント - Testing](https://laravel.com/docs/testing)
- [Test-Driven Development by Example（Kent Beck）](https://www.amazon.co.jp/dp/4274217884)
