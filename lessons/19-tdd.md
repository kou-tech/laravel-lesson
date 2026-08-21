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

`make app` でコンテナに入ってから、以下のコマンドを実行します。

```bash
php artisan make:test Api/AttendanceCancelTest
```

`make:test` はPest形式のテストファイルを生成します（Lesson 17 Step 0 で `RefreshDatabase` を有効にしてあることを前提にしています）。

### 最初のテスト

```php
// tests/Feature/Api/AttendanceCancelTest.php

<?php

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;

test('生徒は自分の受講をキャンセルできる', function () {
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
        ->deleteJson("/api/courses/{$course->id}/attendances");

    // Assert: 結果を検証
    $response->assertOk();

    $this->assertDatabaseHas('attendances', [
        'id' => $attendance->id,
        'status' => AttendanceStatus::Cancelled->value,
    ]);
});
```

### テストを実行（失敗する）

```bash
php artisan test --filter="生徒は自分の受講をキャンセルできる"
```

```
FAILED  Tests\Feature\Api\AttendanceCancelTest > 生徒は自分の受講をキャンセルできる
404 Not Found
```

ルートが存在しないので404エラー。これがRed状態です。


## Step 2 Green - テストを通す最小限のコード

### ルートを追加

```php
// routes/api.php

Route::middleware('auth:sanctum')->group(function () {
    // ... 既存のルート

    Route::delete('/courses/{course}/attendances', [AttendanceController::class, 'destroy']);
});
```

> URLは Lesson 11 で作った受講登録（`POST /api/courses/{course}/attendances`）と同じにし、HTTPメソッドだけ `DELETE` に変えています。Lesson 3 で学んだ「URLは名詞、操作はHTTPメソッドで表す」という原則の通りです。

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
php artisan test --filter="生徒は自分の受講をキャンセルできる"
```

```
PASS  Tests\Feature\Api\AttendanceCancelTest > 生徒は自分の受講をキャンセルできる
```

Green状態になりました。


## Step 3 次のテストを追加（Red）

### 3日前以降はキャンセル不可

```php
test('講座開始3日前以降はキャンセルできない', function () {
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
        ->deleteJson("/api/courses/{$course->id}/attendances");

    $response->assertUnprocessable()
        ->assertJsonPath('message', '講座開始3日前以降はキャンセルできません');

    // ステータスが変わっていないことを確認
    $this->assertDatabaseHas('attendances', [
        'id' => $attendance->id,
        'status' => AttendanceStatus::Attending->value,
    ]);
});
```

### テスト実行（失敗）

```bash
php artisan test --filter="講座開始3日前以降はキャンセルできない"
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
    if ($course->starts_at->lt(now()->addDays(3))) {
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

> 日付比較の注意: `$course->starts_at->diffInDays(now())` と書きたくなりますが、Carbon 3（本プロジェクトが使用）の `diffInDays()` は符号付きの小数を返します。開始日が未来の講座では負の値になるため、`< 3` が常に真になり、どの講座もキャンセルできなくなります。「◯日以内か」を判定したいときは、`lt()` / `gt()` で日時そのものを比較するのが安全です。
>
> ```php
> // 開始が「今から3日後」より前 = 3日を切っている
> $course->starts_at->lt(now()->addDays(3));
> ```

### テスト実行（成功）

```bash
php artisan test tests/Feature/Api/AttendanceCancelTest.php
```

```
PASS  Tests\Feature\Api\AttendanceCancelTest > 生徒は自分の受講をキャンセルできる
PASS  Tests\Feature\Api\AttendanceCancelTest > 講座開始3日前以降はキャンセルできない
```


## Step 5 さらにテストを追加

### 再キャンセル不可

```php
test('キャンセル済みの受講は再キャンセルできない', function () {
    $student = User::factory()->student()->create();
    $course = Course::factory()->create(['starts_at' => now()->addDays(7)]);
    Attendance::factory()->cancelled()->create([
        'user_id' => $student->id,
        'course_id' => $course->id,
    ]);

    $response = $this->actingAs($student)
        ->deleteJson("/api/courses/{$course->id}/attendances");

    $response->assertNotFound();
});
```

### 他人の受講はキャンセル不可

```php
test('他人の受講はキャンセルできない', function () {
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
        ->deleteJson("/api/courses/{$course->id}/attendances");

    $response->assertNotFound();
});
```

これらのテストは既存の実装で通ります。

### 気付きにくい落とし穴: キャンセルすると二度と申し込めない

ここでもう1つテストを書いてみてください。「キャンセルしたあと、同じ講座に申し込み直せる」はずです。

```php
test('キャンセル後に同じ講座へ再登録できる', function () {
    $student = User::factory()->student()->create();
    $course = Course::factory()->active()->create([
        'starts_at' => now()->addDays(7),
        'capacity' => 20,
    ]);

    $this->actingAs($student)
        ->postJson("/api/courses/{$course->id}/attendances")
        ->assertCreated();

    $this->actingAs($student)
        ->deleteJson("/api/courses/{$course->id}/attendances")
        ->assertOk();

    // キャンセルしたので、もう一度申し込めるはず
    $this->actingAs($student)
        ->postJson("/api/courses/{$course->id}/attendances")
        ->assertCreated();
});
```

このテストは失敗します。

```
Expected response status code [201] but received 409.
```

原因は Lesson 11 で付けた複合ユニーク制約 `unique(['user_id', 'course_id'])` です。この制約は「キャンセル済み」の行も重複とみなすため、一度キャンセルした受講者はその講座に恒久的に申し込めなくなります。

「キャンセル機能を足したら、意図せず別の機能が壊れた」という典型例です。テストを書いていなければ、実際に受講者から問い合わせが来るまで気付けなかったはずです。

対処にはいくつかの方針があり、どれを選ぶかは要件次第です。

| 方針 | 内容 | トレードオフ |
|------|------|-------------|
| キャンセル行を再利用する | 申込み時にキャンセル済みの行があれば `attending` に戻す | 実装が最も簡単。ただし「いつキャンセルしたか」の履歴が消える |
| ユニーク制約に `status` を含める | `unique(['user_id', 'course_id', 'status'])` にする | 履歴は残るが、同じ講座を2回キャンセルできなくなる |
| キャンセル行を論理削除する | `deleted_at` を追加し、部分ユニークインデックスにする | 履歴も残り制約も正しく効くが、SQLiteでは部分インデックスの扱いに注意が必要 |

このレッスンでは制約はそのままにしておきます。どう直すかは末尾の練習問題で考えてみてください。


## Step 6 Refactor - コードを整理

### サービスクラスに抽出

Lesson 16 で決めた「1サービス = 1操作、`__invoke` で呼ぶ」方針に沿って、`App\Services\Attendance\CancelAttendance` に抽出します（Lesson 16 の練習問題1で作ったものを、ここでは「ユーザーと講座からキャンセルする」形に整えます）。

```php
// app/Services/Attendance/CancelAttendance.php
<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Exceptions\CancellationDeadlineExceededException;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;

class CancelAttendance
{
    private const CANCELLABLE_DAYS_BEFORE_START = 3;

    public function __invoke(User $user, Course $course): void
    {
        $attendance = $this->findActiveAttendance($user, $course);

        $this->validateDeadline($course);

        $attendance->update([
            'status' => AttendanceStatus::Cancelled,
        ]);
    }

    private function findActiveAttendance(User $user, Course $course): Attendance
    {
        return Attendance::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', AttendanceStatus::Attending)
            ->firstOrFail();
    }

    private function validateDeadline(Course $course): void
    {
        if ($course->starts_at->lt(now()->addDays(self::CANCELLABLE_DAYS_BEFORE_START))) {
            throw new CancellationDeadlineExceededException();
        }
    }
}
```

`CancellationDeadlineExceededException` は Lesson 16 の `BusinessException` を継承して作ります。これで 422 とエラーメッセージが自動的に返ります。

```php
// app/Exceptions/CancellationDeadlineExceededException.php
<?php

namespace App\Exceptions;

class CancellationDeadlineExceededException extends BusinessException
{
    protected $message = '講座開始3日前以降はキャンセルできません';

    public function getErrorCode(): string
    {
        return 'CANCELLATION_DEADLINE_EXCEEDED';
    }
}
```

> 更新が1テーブル1行だけなので、ここでは `DB::transaction()` を使っていません。Lesson 14 で学んだ通り、トランザクションが必要になるのは「複数の更新をまとめて成功／失敗させたいとき」です。Step 7 でメール送信を足しても、メールはDBの更新ではないのでこの判断は変わりません。

### コントローラーをシンプルに

```php
public function __construct(
    private CancelAttendance $cancelAttendance,
) {}

public function destroy(Request $request, Course $course): JsonResponse
{
    ($this->cancelAttendance)($request->user(), $course);

    return response()->json(['message' => 'キャンセルしました']);
}
```

### テストを再実行して確認

```bash
# 全テスト実行
make test

# または特定のテストファイルのみ実行（コンテナ内で）
php artisan test tests/Feature/Api/AttendanceCancelTest.php
```

全てのテストがパスすれば、リファクタリング成功です。


## Step 7 メール通知のテストを追加

```php
use App\Mail\AttendanceCancellation;
use Illuminate\Support\Facades\Mail;

test('キャンセル時に通知メールが送信される', function () {
    Mail::fake();

    $student = User::factory()->student()->create();
    $course = Course::factory()->create(['starts_at' => now()->addDays(7)]);
    Attendance::factory()->create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'status' => AttendanceStatus::Attending,
    ]);

    $this->actingAs($student)
        ->deleteJson("/api/courses/{$course->id}/attendances")
        ->assertOk();

    Mail::assertQueued(AttendanceCancellation::class, function ($mail) use ($student) {
        return $mail->hasTo($student->email);
    });
});
```

### サービスにメール送信を追加

`queue()` で送るので、アサーションも `assertQueued` になります（Lesson 18 の使い分けを参照）。

```php
public function __invoke(User $user, Course $course): void
{
    $attendance = $this->findActiveAttendance($user, $course);

    $this->validateDeadline($course);

    $attendance->update([
        'status' => AttendanceStatus::Cancelled,
    ]);

    // メール送信を追加
    Mail::to($user)->queue(new AttendanceCancellation($attendance));
}
```


## TDDのメリット

### 1. 設計が改善される

テストしやすいコード = 良い設計です。

```php
// ❌ テストしにくい（依存が隠れていて差し替えられない）
public function destroy(Request $request, Course $course)
{
    $cancelAttendance = new CancelAttendance();
    $cancelAttendance($request->user(), $course);
}

// ✅ テストしやすい（依存が明示的で、モックに差し替えられる）
public function __construct(
    private CancelAttendance $cancelAttendance,
) {}
```

### 2. 過剰な実装を防ぐ

テストが通るまで次のコードを書かないため、必要最小限の実装になります。

### 3. リファクタリングの安心感

テストがあるので、大胆に書き換えられます。

### 4. ドキュメントになる

テストコードが仕様書の役割を果たします。

## 練習問題

### 問題1
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

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;

test('講師は自分のドラフト講座を公開できる', function () {
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
});

test('公開済みの講座は再公開できない', function () {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->create([
        'instructor_id' => $instructor->id,
        'status' => CourseStatus::Active,
    ]);

    $response = $this->actingAs($instructor)
        ->patchJson("/api/courses/{$course->id}/publish");

    $response->assertUnprocessable();
});

test('他の講師の講座は公開できない', function () {
    $instructor1 = User::factory()->instructor()->create();
    $instructor2 = User::factory()->instructor()->create();
    $course = Course::factory()->create([
        'instructor_id' => $instructor1->id,
        'status' => CourseStatus::Draft,
    ]);

    $response = $this->actingAs($instructor2)
        ->patchJson("/api/courses/{$course->id}/publish");

    $response->assertForbidden();
});
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

### 問題2

Step 5 で見つけた「キャンセル後に同じ講座へ再登録できない」問題を、TDDで直してください。

1. Step 5 に載せた `キャンセル後に同じ講座へ再登録できる` テストを追加する（Red）
2. Step 5 の表から方針を1つ選んで実装する（Green）
3. 既存のテストがすべて通ることを確認する

<details>
<summary>解答例（キャンセル行を再利用する方針）</summary>

`AttendanceController@store` で、キャンセル済みの受講があれば作り直さずに `attending` へ戻します。

```php
public function store(Request $request, Course $course): JsonResponse
{
    if (!$request->user()->isStudent()) {
        return response()->json([
            'message' => '受講登録できるのは生徒のみです。',
        ], 403);
    }

    if (!$course->hasCapacity()) {
        return response()->json([
            'message' => 'この講座は定員に達しています。',
        ], 422);
    }

    // キャンセル済みの受講があれば再開する
    $cancelled = Attendance::where('user_id', $request->user()->id)
        ->where('course_id', $course->id)
        ->where('status', AttendanceStatus::Cancelled)
        ->first();

    if ($cancelled !== null) {
        $cancelled->update([
            'status' => AttendanceStatus::Attending,
            'attended_at' => now(),
        ]);

        $attendance = $cancelled;
    } else {
        try {
            $attendance = Attendance::create([
                'user_id' => $request->user()->id,
                'course_id' => $course->id,
                'status' => AttendanceStatus::Attending,
                'attended_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'すでにこの講座に登録済みです。',
            ], 409);
        }
    }

    $attendance->load(['user', 'course.instructor']);

    return (new AttendanceResource($attendance))
        ->response()
        ->setStatusCode(201);
}
```

`UniqueConstraintViolationException` の `catch` は残しておいてください。「キャンセル済みを探す」→「作成する」の間に別のリクエストが割り込む可能性があるためです（Lesson 14 で学んだ競合状態）。アプリ側のチェックとDB制約の二段構えにしておくのが定石です。

</details>


## 参考資料

- [Laravel 公式ドキュメント - Testing](https://laravel.com/docs/testing)
- [Test-Driven Development by Example（Kent Beck）](https://www.amazon.co.jp/dp/4274217884)
