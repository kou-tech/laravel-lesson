# Lesson 16: メールとジョブ機能

## 学習目標

このレッスンでは、メール送信とキュー処理を実装し、非同期処理の基本を理解します。

### 到達目標
- Mailable クラスを作成できる
- メールを送信できる
- Job クラスを作成してキューに投入できる
- 非同期処理の仕組みを理解する

---

## なぜ非同期処理が必要か？

### 問題: 同期処理のボトルネック

```php
public function store(Request $request, Course $course)
{
    $enrollment = Enrollment::create([...]);

    // メール送信に3秒かかる...
    Mail::to($user)->send(new EnrollmentConfirmation($enrollment));

    return response()->json($enrollment);  // 合計3秒以上
}
```

ユーザーは3秒以上待たされます。

### 解決: 非同期処理

```php
public function store(Request $request, Course $course)
{
    $enrollment = Enrollment::create([...]);

    // キューに入れてすぐ戻る
    Mail::to($user)->queue(new EnrollmentConfirmation($enrollment));

    return response()->json($enrollment);  // すぐに返却
}
```

メール送信はバックグラウンドで実行されます。

---

## Step 1: Mailable の作成

### コマンドで生成

```bash
php artisan make:mail EnrollmentConfirmation
```

### Mailable の実装

`app/Mail/EnrollmentConfirmation.php`:

```php
<?php

namespace App\Mail;

use App\Models\Enrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EnrollmentConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Enrollment $enrollment
    ) {}

    /**
     * 件名、送信元などの設定
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【受講登録完了】' . $this->enrollment->course->title,
        );
    }

    /**
     * 本文の設定
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.enrollment-confirmation',
            with: [
                'userName' => $this->enrollment->user->name,
                'courseName' => $this->enrollment->course->title,
                'enrolledAt' => $this->enrollment->enrolled_at->format('Y年m月d日'),
            ],
        );
    }
}
```

### メールテンプレート

`resources/views/emails/enrollment-confirmation.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body>
    <h1>受講登録が完了しました</h1>

    <p>{{ $userName }} 様</p>

    <p>
        以下の講座への受講登録が完了しました。
    </p>

    <table>
        <tr>
            <th>講座名</th>
            <td>{{ $courseName }}</td>
        </tr>
        <tr>
            <th>登録日</th>
            <td>{{ $enrolledAt }}</td>
        </tr>
    </table>

    <p>
        引き続きよろしくお願いいたします。
    </p>
</body>
</html>
```

---

## Step 2: メールの送信

### 同期送信

```php
use App\Mail\EnrollmentConfirmation;
use Illuminate\Support\Facades\Mail;

// 即座に送信（レスポンスを待つ）
Mail::to($user)->send(new EnrollmentConfirmation($enrollment));

// CCやBCCを追加
Mail::to($user)
    ->cc('admin@example.com')
    ->bcc('log@example.com')
    ->send(new EnrollmentConfirmation($enrollment));
```

### 非同期送信（キュー）

```php
// キューに追加（すぐに返却）
Mail::to($user)->queue(new EnrollmentConfirmation($enrollment));

// 遅延送信（5分後）
Mail::to($user)->later(
    now()->addMinutes(5),
    new EnrollmentConfirmation($enrollment)
);
```

---

## Step 3: Job クラスの作成

### コマンドで生成

```bash
php artisan make:job SendEnrollmentConfirmation
```

### Job の実装

`app/Jobs/SendEnrollmentConfirmation.php`:

```php
<?php

namespace App\Jobs;

use App\Mail\EnrollmentConfirmation;
use App\Models\Enrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendEnrollmentConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 最大試行回数
     */
    public int $tries = 3;

    /**
     * タイムアウト（秒）
     */
    public int $timeout = 60;

    public function __construct(
        public Enrollment $enrollment
    ) {}

    /**
     * ジョブの実行
     */
    public function handle(): void
    {
        Mail::to($this->enrollment->user)
            ->send(new EnrollmentConfirmation($this->enrollment));
    }

    /**
     * 失敗時の処理
     */
    public function failed(\Throwable $exception): void
    {
        // 失敗をログに記録
        \Log::error('メール送信失敗', [
            'enrollment_id' => $this->enrollment->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

### Job のディスパッチ

```php
use App\Jobs\SendEnrollmentConfirmation;

// キューに追加
SendEnrollmentConfirmation::dispatch($enrollment);

// 遅延実行
SendEnrollmentConfirmation::dispatch($enrollment)
    ->delay(now()->addMinutes(5));

// 特定のキューに追加
SendEnrollmentConfirmation::dispatch($enrollment)
    ->onQueue('emails');

// 同期実行（テスト用）
SendEnrollmentConfirmation::dispatchSync($enrollment);
```

---

## Step 4: キューの設定

### .env の設定

```env
# 開発環境（同期実行）
QUEUE_CONNECTION=sync

# 本番環境（データベースキュー）
QUEUE_CONNECTION=database
```

### データベースキューの準備

```bash
php artisan queue:table
php artisan migrate
```

### キューワーカーの起動

```bash
# 基本的な起動
php artisan queue:work

# 特定のキューを処理
php artisan queue:work --queue=emails,default

# メモリ制限とタイムアウト設定
php artisan queue:work --memory=128 --timeout=60

# 1ジョブ処理後に終了（デプロイ時に便利）
php artisan queue:work --once
```

---

## Step 5: 失敗したジョブの管理

### 失敗ジョブテーブル

```bash
php artisan queue:failed-table
php artisan migrate
```

### 失敗ジョブの確認

```bash
# 失敗ジョブ一覧
php artisan queue:failed

# 特定のジョブを再試行
php artisan queue:retry <job-id>

# 全ての失敗ジョブを再試行
php artisan queue:retry all

# 失敗ジョブを削除
php artisan queue:forget <job-id>

# 全ての失敗ジョブを削除
php artisan queue:flush
```

### リトライ設定

```php
class SendEnrollmentConfirmation implements ShouldQueue
{
    // 最大3回試行
    public int $tries = 3;

    // 試行間の待機時間（秒）
    public array $backoff = [10, 60, 300];  // 10秒、1分、5分

    // または一定間隔
    public int $backoff = 60;  // 60秒間隔
}
```

---

## Step 6: 実践 - 受講登録時のメール送信

### EnrollmentService の修正

```php
<?php

namespace App\Services;

use App\Jobs\SendEnrollmentConfirmation;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EnrollmentService
{
    public function enroll(User $user, Course $course): Enrollment
    {
        $enrollment = DB::transaction(function () use ($user, $course) {
            // バリデーション
            $this->validateEnrollment($user, $course);

            // 受講レコード作成
            return Enrollment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'enrolled_at' => now(),
            ]);
        });

        // トランザクション外でジョブをディスパッチ
        SendEnrollmentConfirmation::dispatch($enrollment);

        return $enrollment;
    }
}
```

### afterCommit でトランザクション完了後に実行

```php
SendEnrollmentConfirmation::dispatch($enrollment)
    ->afterCommit();  // トランザクションがコミットされてから実行
```

---

## Step 7: メールのプレビュー

### 開発環境でのプレビュー

```php
// routes/web.php（開発環境のみ）
if (app()->environment('local')) {
    Route::get('/mail-preview/enrollment', function () {
        $enrollment = \App\Models\Enrollment::with(['user', 'course'])->first();
        return new \App\Mail\EnrollmentConfirmation($enrollment);
    });
}
```

ブラウザで `/mail-preview/enrollment` にアクセスするとメールをプレビューできます。

### Mailpit / Mailtrap の利用

`.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=localhost
MAIL_PORT=1025
```

---

## Step 8: テスト

### メール送信のテスト

```php
use App\Mail\EnrollmentConfirmation;
use Illuminate\Support\Facades\Mail;

test('受講登録時にメールが送信される', function () {
    Mail::fake();

    $user = User::factory()->student()->create();
    $course = Course::factory()->active()->create();

    $this->actingAs($user)
        ->postJson("/api/courses/{$course->id}/enroll")
        ->assertCreated();

    Mail::assertQueued(EnrollmentConfirmation::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});
```

### ジョブのテスト

```php
use App\Jobs\SendEnrollmentConfirmation;
use Illuminate\Support\Facades\Queue;

test('受講登録時にジョブがキューに追加される', function () {
    Queue::fake();

    $user = User::factory()->student()->create();
    $course = Course::factory()->active()->create();

    $this->actingAs($user)
        ->postJson("/api/courses/{$course->id}/enroll")
        ->assertCreated();

    Queue::assertPushed(SendEnrollmentConfirmation::class, function ($job) use ($course) {
        return $job->enrollment->course_id === $course->id;
    });
});
```

---

## まとめ

このレッスンで学んだこと：

1. **Mailable クラス**
   - メールの件名、本文、送信先を定義
   - Blade テンプレートで本文作成

2. **メール送信**
   - `send()`: 同期送信
   - `queue()`: 非同期送信

3. **Job クラス**
   - バックグラウンド処理を定義
   - `dispatch()` でキューに追加

4. **キュー設定**
   - `QUEUE_CONNECTION` で接続先を設定
   - `queue:work` でワーカー起動

5. **失敗時の処理**
   - リトライ設定
   - `failed()` メソッドでエラーハンドリング

---

## 練習問題

### 問題1
講座キャンセル時に送信する `EnrollmentCancellation` メールを作成してください。

<details>
<summary>解答例</summary>

```php
// app/Mail/EnrollmentCancellation.php
class EnrollmentCancellation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Enrollment $enrollment
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【受講キャンセル】' . $this->enrollment->course->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.enrollment-cancellation',
        );
    }
}
```
</details>

### 問題2
講座の開始1日前にリマインドメールを送信するジョブを作成してください。

<details>
<summary>解答例</summary>

```php
// app/Jobs/SendCourseReminder.php
class SendCourseReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Course $course
    ) {}

    public function handle(): void
    {
        $enrollments = $this->course->enrollments()
            ->with('user')
            ->where('status', EnrollmentStatus::Enrolled)
            ->get();

        foreach ($enrollments as $enrollment) {
            Mail::to($enrollment->user)
                ->send(new CourseReminder($this->course));
        }
    }
}

// スケジューラーで1日前に実行
// app/Console/Kernel.php
$schedule->command('courses:send-reminders')->daily();
```
</details>

---

## 次のレッスン

[Lesson 17: TDDで機能を追加する](./17-tdd.md) では、テスト駆動開発（TDD）のサイクルを体験します。
