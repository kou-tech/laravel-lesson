# Lesson 11: トランザクション処理

## 学習目標

このレッスンでは、データの整合性を保つためのトランザクション処理を適切に実装できるようになります。

### 到達目標
- トランザクションの必要性を理解する
- `DB::transaction()` を使える
- 例外発生時のロールバックを理解する
- デッドロック対策ができる

---

## トランザクションとは？

### 問題のあるコード

受講登録処理を考えます。

```php
public function enroll(User $user, Course $course)
{
    // 1. 受講レコードを作成
    $enrollment = Enrollment::create([
        'user_id' => $user->id,
        'course_id' => $course->id,
    ]);

    // 2. 講座の受講者数を更新
    $course->increment('enrolled_count');

    // 3. 通知メールを送信（ここで例外発生！）
    Mail::to($user)->send(new EnrollmentConfirmation($enrollment));
    // ↑ メール送信に失敗すると例外がスローされる

    return $enrollment;
}
```

**問題**: メール送信で失敗した場合

- 受講レコードは作成済み ✓
- 講座の受講者数は更新済み ✓
- メールは送信されていない ✗

**データの不整合**が発生します。

---

## Step 1: DB::transaction() で解決

### 基本的な使い方

```php
use Illuminate\Support\Facades\DB;

public function enroll(User $user, Course $course)
{
    return DB::transaction(function () use ($user, $course) {
        // 1. 受講レコードを作成
        $enrollment = Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);

        // 2. 講座の受講者数を更新
        $course->increment('enrolled_count');

        // 3. メール送信（DB操作の外で行う）
        // ここでは行わない

        return $enrollment;
    });
}
```

`DB::transaction()` 内で例外が発生すると、全ての変更がロールバックされます。

### メール送信はトランザクションの外で

```php
public function enroll(User $user, Course $course)
{
    // トランザクション内でDB操作
    $enrollment = DB::transaction(function () use ($user, $course) {
        $enrollment = Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);

        $course->increment('enrolled_count');

        return $enrollment;
    });

    // トランザクションの外でメール送信
    Mail::to($user)->send(new EnrollmentConfirmation($enrollment));

    return $enrollment;
}
```

### 例外の再スロー

トランザクション内で例外をキャッチして処理する場合：

```php
DB::transaction(function () {
    try {
        // 処理
    } catch (SomeException $e) {
        // ログに記録
        Log::error('エラー発生', ['message' => $e->getMessage()]);

        // 必ず再スローしてロールバック
        throw $e;
    }
});
```

---

## Step 2: 手動トランザクション制御

### より細かい制御が必要な場合

```php
use Illuminate\Support\Facades\DB;

public function complexOperation()
{
    DB::beginTransaction();

    try {
        // 操作1
        $user = User::create([...]);

        // 操作2
        $course = Course::create([...]);

        // 操作3（条件付き）
        if ($someCondition) {
            Enrollment::create([...]);
        }

        // 全て成功したらコミット
        DB::commit();

        return $user;

    } catch (\Exception $e) {
        // エラー時はロールバック
        DB::rollBack();

        Log::error('操作失敗', ['error' => $e->getMessage()]);

        throw $e;
    }
}
```

### DB::transaction() vs 手動制御

| 方法 | 利点 | 欠点 |
|------|------|------|
| `DB::transaction()` | シンプル、例外時に自動ロールバック | 細かい制御が難しい |
| 手動（begin/commit/rollback） | 柔軟な制御が可能 | 書き忘れのリスク |

**推奨**: 特別な理由がなければ `DB::transaction()` を使う

---

## Step 3: 排他制御（ロック）

### 問題: 競合状態

2人のユーザーが同時に残り1席の講座に申し込む場合：

```
ユーザーA: 残席確認 → 1席 → 登録実行
ユーザーB: 残席確認 → 1席 → 登録実行
→ 2人とも登録できてしまう（定員オーバー）
```

### 悲観的ロック（lockForUpdate）

```php
public function enroll(User $user, int $courseId)
{
    return DB::transaction(function () use ($user, $courseId) {
        // 行ロックを取得（他のトランザクションは待機）
        $course = Course::lockForUpdate()->find($courseId);

        if (!$course->hasCapacity()) {
            throw new CapacityExceededException();
        }

        $enrollment = Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);

        $course->increment('enrolled_count');

        return $enrollment;
    });
}
```

**lockForUpdate()** は、そのレコードを他のトランザクションが更新できないようにロックします。

### sharedLock（共有ロック）

読み取りのみの場合に使用：

```php
$course = Course::sharedLock()->find($courseId);
// 他のトランザクションは読み取り可能、更新は不可
```

---

## Step 4: デッドロック対策

### デッドロックとは？

2つのトランザクションが互いにロックを待ち合う状態：

```
トランザクションA: users をロック → courses のロックを待つ
トランザクションB: courses をロック → users のロックを待つ
→ 永久に待ち続ける（デッドロック）
```

### 対策1: ロック順序を統一

```php
// ✅ 常に同じ順序でロック
DB::transaction(function () {
    $course = Course::lockForUpdate()->find($courseId);
    $user = User::lockForUpdate()->find($userId);
    // ...
});
```

### 対策2: リトライ処理

```php
use Illuminate\Database\DeadlockException;

$maxAttempts = 3;
$attempt = 0;

while ($attempt < $maxAttempts) {
    try {
        return DB::transaction(function () use ($user, $course) {
            // 処理
        });
    } catch (DeadlockException $e) {
        $attempt++;
        if ($attempt >= $maxAttempts) {
            throw $e;
        }
        // 少し待ってからリトライ
        usleep(100000);  // 100ms
    }
}
```

### DB::transaction() のリトライ機能

`DB::transaction()` は第2引数でリトライ回数を指定できます：

```php
DB::transaction(function () {
    // 処理
}, 5);  // デッドロック時に最大5回リトライ
```

---

## Step 5: 実践 - 受講登録APIの実装

### EnrollmentService

```php
<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Exceptions\AlreadyEnrolledException;
use App\Exceptions\CapacityExceededException;
use App\Exceptions\CourseNotActiveException;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EnrollmentService
{
    public function enroll(User $user, int $courseId): Enrollment
    {
        return DB::transaction(function () use ($user, $courseId) {
            // 講座をロック付きで取得
            $course = Course::lockForUpdate()->findOrFail($courseId);

            // バリデーション
            $this->validateEnrollment($user, $course);

            // 受講レコードを作成
            $enrollment = Enrollment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'status' => EnrollmentStatus::Enrolled,
                'enrolled_at' => now(),
            ]);

            return $enrollment;
        }, 3);  // デッドロック時に3回リトライ
    }

    private function validateEnrollment(User $user, Course $course): void
    {
        // 講座が公開中か確認
        if (!$course->isActive()) {
            throw new CourseNotActiveException();
        }

        // 定員確認
        if (!$course->hasCapacity()) {
            throw new CapacityExceededException();
        }

        // 重複登録確認
        $exists = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->exists();

        if ($exists) {
            throw new AlreadyEnrolledException();
        }
    }
}
```

### EnrollmentController

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EnrollmentResource;
use App\Mail\EnrollmentConfirmation;
use App\Models\Course;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EnrollmentController extends Controller
{
    public function __construct(
        private EnrollmentService $enrollmentService
    ) {}

    public function store(Request $request, Course $course)
    {
        $this->authorize('enroll', $course);

        // トランザクション内でDB操作
        $enrollment = $this->enrollmentService->enroll(
            $request->user(),
            $course->id
        );

        // トランザクション外でメール送信
        Mail::to($request->user())->send(
            new EnrollmentConfirmation($enrollment)
        );

        return new EnrollmentResource($enrollment);
    }
}
```

---

## Step 6: トランザクションのテスト

### テストでのトランザクション確認

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_is_rolled_back_on_error(): void
    {
        $user = User::factory()->student()->create();
        $course = Course::factory()->active()->create(['capacity' => 1]);

        // 1人目は成功
        $this->actingAs($user)
            ->postJson("/api/courses/{$course->id}/enroll")
            ->assertStatus(201);

        // 2人目は失敗（定員オーバー）
        $anotherUser = User::factory()->student()->create();
        $this->actingAs($anotherUser)
            ->postJson("/api/courses/{$course->id}/enroll")
            ->assertStatus(422);

        // 受講レコードは1件のみ
        $this->assertDatabaseCount('enrollments', 1);
    }
}
```

---

## まとめ

このレッスンで学んだこと：

1. **トランザクションの必要性**
   - 複数のDB操作を原子的に実行
   - 失敗時は全てロールバック

2. **DB::transaction()**
   - クロージャ内で操作
   - 例外時に自動ロールバック

3. **排他制御**
   - `lockForUpdate()` で行ロック
   - 競合状態を防止

4. **デッドロック対策**
   - ロック順序の統一
   - リトライ処理

5. **ベストプラクティス**
   - メール送信はトランザクション外
   - 適切な例外処理

---

## 練習問題

### 問題1
以下のコードにトランザクションを追加してください。

```php
public function transfer(User $from, User $to, int $amount)
{
    $from->decrement('balance', $amount);
    $to->increment('balance', $amount);
}
```

<details>
<summary>解答例</summary>

```php
public function transfer(User $from, User $to, int $amount)
{
    DB::transaction(function () use ($from, $to, $amount) {
        // ロック付きで取得
        $from = User::lockForUpdate()->find($from->id);
        $to = User::lockForUpdate()->find($to->id);

        if ($from->balance < $amount) {
            throw new InsufficientBalanceException();
        }

        $from->decrement('balance', $amount);
        $to->increment('balance', $amount);
    });
}
```
</details>

### 問題2
受講キャンセル処理を実装してください。受講ステータスを `cancelled` に変更し、講座の `enrolled_count` を減らします。

<details>
<summary>解答例</summary>

```php
public function cancel(User $user, int $courseId): void
{
    DB::transaction(function () use ($user, $courseId) {
        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->where('status', EnrollmentStatus::Enrolled)
            ->lockForUpdate()
            ->firstOrFail();

        $enrollment->update([
            'status' => EnrollmentStatus::Cancelled,
        ]);
    });
}
```
</details>

---

## 次のレッスン

[Lesson 12: FormRequestによるバリデーション](./12-validation.md) では、堅牢なバリデーション設計を学びます。
