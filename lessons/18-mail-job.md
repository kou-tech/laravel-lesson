# Lesson 18 メールとジョブ機能

## 学習目標

このレッスンでは、メール送信とキュー処理を実装し、非同期処理の基本を理解します。

### 到達目標
- Mailable クラスを作成できる
- メールを送信できる
- Job クラスを作成してキューに投入できる
- 非同期処理の仕組みを理解する

> このレッスンは2部構成です。
> - Step 1〜5: Mailable・Job・キューの書き方を学ぶ座学パート（コマンドを実行してクラスを作りながら読み進めてOKです）
> - Step 6〜8: 受講登録時に確認メールを送る処理を、Lesson 16 で作ったサービスクラスに組み込むハンズオンパート


## なぜ非同期処理が必要か？

### 同期処理のボトルネック

```php
public function store(Request $request, Course $course)
{
    $attendance = Attendance::create([...]);

    // メール送信に3秒かかる...
    Mail::to($user)->send(new AttendanceConfirmation($attendance));

    return response()->json($attendance);  // 合計3秒以上
}
```

ユーザーは3秒以上待たされます。

### 非同期処理による解決

```php
public function store(Request $request, Course $course)
{
    $attendance = Attendance::create([...]);

    // キューに入れてすぐ戻る
    Mail::to($user)->queue(new AttendanceConfirmation($attendance));

    return response()->json($attendance);  // すぐに返却
}
```

メール送信はバックグラウンドで実行されます。


## Step 1 Mailable の作成

### コマンドで生成

`make app` でコンテナに入ってから、以下のコマンドを実行します。

```bash
php artisan make:mail AttendanceConfirmation
```

### Mailable の実装

`app/Mail/AttendanceConfirmation.php` に以下のように実装します。

```php
<?php

namespace App\Mail;

use App\Models\Attendance;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AttendanceConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Attendance $attendance
    ) {}

    /**
     * 件名、送信元などの設定
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【受講確認】' . $this->attendance->course->title,
        );
    }

    /**
     * 本文の設定
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.attendance-confirmation',
            with: [
                'userName' => $this->attendance->user->name,
                'courseName' => $this->attendance->course->title,
                'attendedAt' => $this->attendance->attended_at->format('Y年m月d日'),
            ],
        );
    }
}
```

### メールテンプレート

`resources/views/emails/attendance-confirmation.blade.php` に以下のように記述します。

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body>
    <h1>受講が確認されました</h1>

    <p>{{ $userName }} 様</p>

    <p>
        以下の講座への受講が確認されました。
    </p>

    <table>
        <tr>
            <th>講座名</th>
            <td>{{ $courseName }}</td>
        </tr>
        <tr>
            <th>登録日</th>
            <td>{{ $attendedAt }}</td>
        </tr>
    </table>

    <p>
        引き続きよろしくお願いいたします。
    </p>
</body>
</html>
```


## Step 2 メールの送信

### 同期送信

```php
use App\Mail\AttendanceConfirmation;
use Illuminate\Support\Facades\Mail;

// 即座に送信（レスポンスを待つ）
Mail::to($user)->send(new AttendanceConfirmation($attendance));

// CCやBCCを追加
Mail::to($user)
    ->cc('admin@example.com')
    ->bcc('log@example.com')
    ->send(new AttendanceConfirmation($attendance));
```

### 非同期送信（キュー）

```php
// キューに追加（すぐに返却）
Mail::to($user)->queue(new AttendanceConfirmation($attendance));

// 遅延送信（5分後）
Mail::to($user)->later(
    now()->addMinutes(5),
    new AttendanceConfirmation($attendance)
);
```


## Step 3 Job クラスの作成

### コマンドで生成

```bash
php artisan make:job SendAttendanceConfirmation
```

### Job の実装

`app/Jobs/SendAttendanceConfirmation.php` に以下のように実装します。

```php
<?php

namespace App\Jobs;

use App\Mail\AttendanceConfirmation;
use App\Models\Attendance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAttendanceConfirmation implements ShouldQueue
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
        public Attendance $attendance
    ) {}

    /**
     * ジョブの実行
     */
    public function handle(): void
    {
        Mail::to($this->attendance->user)
            ->send(new AttendanceConfirmation($this->attendance));
    }

    /**
     * 失敗時の処理
     */
    public function failed(Throwable $exception): void
    {
        // 失敗をログに記録
        Log::error('メール送信失敗', [
            'attendance_id' => $this->attendance->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

### Job のディスパッチ

```php
use App\Jobs\SendAttendanceConfirmation;

// キューに追加
SendAttendanceConfirmation::dispatch($attendance);

// 遅延実行
SendAttendanceConfirmation::dispatch($attendance)
    ->delay(now()->addMinutes(5));

// 特定のキューに追加
SendAttendanceConfirmation::dispatch($attendance)
    ->onQueue('emails');

// 同期実行（テスト用）
SendAttendanceConfirmation::dispatchSync($attendance);
```


## Step 4 キューの設定

### .env の設定

```env
# 開発環境（同期実行）
QUEUE_CONNECTION=sync

# 本番環境（データベースキュー）
QUEUE_CONNECTION=database
```

### データベースキューの準備

`make app` でコンテナに入ってから実行します。

```bash
php artisan queue:table
php artisan migrate
```

> DB初期化とシーダー実行を一括で行いたい場合は `make fresh` が使えます。

### キューワーカーの起動

コンテナ内（`make app`）でキューワーカーを起動します。

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


## Step 5 失敗したジョブの管理

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
class SendAttendanceConfirmation implements ShouldQueue
{
    // 最大3回試行
    public int $tries = 3;

    // 試行間の待機時間（秒）
    public array $backoff = [10, 60, 300];  // 10秒、1分、5分

    // または一定間隔
    public int $backoff = 60;  // 60秒間隔
}
```


## Step 6 実践 - 受講時のメール送信

### AttendCourse サービスの修正

Lesson 16 の練習問題2で作った `App\Services\Attendance\AttendCourse` に、確認メールのジョブ投入を追加します。

```php
<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Jobs\SendAttendanceConfirmation;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AttendCourse
{
    public function __invoke(User $user, Course $course): Attendance
    {
        $attendance = DB::transaction(function () use ($user, $course) {
            // 定員・重複のチェック（Lesson 16 で実装済み）
            $this->validate($user, $course);

            return Attendance::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'status' => AttendanceStatus::Attending,
                'attended_at' => now(),
            ]);
        });

        // トランザクション外でジョブをディスパッチ
        SendAttendanceConfirmation::dispatch($attendance);

        return $attendance;
    }
}
```

> なぜトランザクションの外でディスパッチするのか: トランザクション内でジョブを積むと、**まだコミットされていないレコード**をワーカーが処理しにいく可能性があります（ワーカーは別プロセスなので、コミット前のデータは見えません）。結果として「該当レコードが見つからない」でジョブが失敗します。次に説明する `afterCommit()` を使う方法でも同じ問題を回避できます。

### afterCommit でトランザクション完了後に実行

```php
SendAttendanceConfirmation::dispatch($attendance)
    ->afterCommit();  // トランザクションがコミットされてから実行
```


## Step 7 メールのプレビュー

### 開発環境でのプレビュー

```php
// routes/web.php（開発環境のみ）
if (app()->environment('local')) {
    Route::get('/mail-preview/attendance', function () {
        $attendance = \App\Models\Attendance::with(['user', 'course'])->first();
        return new \App\Mail\AttendanceConfirmation($attendance);
    });
}
```

ブラウザで `/mail-preview/attendance` にアクセスするとメールをプレビューできます。

### Mailpit / Mailtrap の利用

`.env` に以下のように設定します。

```env
MAIL_MAILER=smtp
MAIL_HOST=localhost
MAIL_PORT=1025
```


## Step 8 テスト

### メール送信のテスト

```php
use App\Mail\AttendanceConfirmation;
use Illuminate\Support\Facades\Mail;

test('受講時にメールが送信される', function () {
    Mail::fake();

    $user = User::factory()->student()->create();
    $course = Course::factory()->active()->create();

    $this->actingAs($user)
        ->postJson("/api/courses/{$course->id}/attendances")
        ->assertCreated();

    Mail::assertSent(AttendanceConfirmation::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});
```

> `assertSent` と `assertQueued` の使い分けに注意してください。
> - `Mail::to($user)->send(...)` で送った → `assertSent`
> - `Mail::to($user)->queue(...)` で積んだ → `assertQueued`
>
> 今回は Job の中で `send()` を呼んでおり、テスト環境の `QUEUE_CONNECTION` は `sync`（その場で実行）なので、ジョブが即座に実行されて `assertSent` で捕まえられます。`Queue::fake()` を併用するとジョブ自体が実行されなくなり、メールは送られない点にも注意してください。

### ジョブのテスト

```php
use App\Jobs\SendAttendanceConfirmation;
use Illuminate\Support\Facades\Queue;

test('受講時にジョブがキューに追加される', function () {
    Queue::fake();

    $user = User::factory()->student()->create();
    $course = Course::factory()->active()->create();

    $this->actingAs($user)
        ->postJson("/api/courses/{$course->id}/attendances")
        ->assertCreated();

    Queue::assertPushed(SendAttendanceConfirmation::class, function ($job) use ($course) {
        return $job->attendance->course_id === $course->id;
    });
});
```

## 練習問題

### 問題1
講座キャンセル時に送信する `AttendanceCancellation` メールを作成してください。

<details>
<summary>解答例</summary>

```php
<?php

namespace App\Mail;

use App\Models\Attendance;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AttendanceCancellation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Attendance $attendance
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【キャンセル完了】' . $this->attendance->course->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.attendance-cancellation',
            with: [
                'userName' => $this->attendance->user->name,
                'courseName' => $this->attendance->course->title,
            ],
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
<?php

namespace App\Jobs;

use App\Enums\AttendanceStatus;
use App\Mail\CourseReminder;
use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendCourseReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Course $course
    ) {}

    public function handle(): void
    {
        $attendees = $this->course->students()
            ->wherePivot('status', AttendanceStatus::Attending)
            ->get();

        foreach ($attendees as $student) {
            Mail::to($student)->send(new CourseReminder($this->course));
        }
    }
}
```

スケジューラーからの呼び出し例（`routes/console.php`）

```php
use App\Jobs\SendCourseReminder;
use App\Models\Course;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    // starts_at は datetime のため、日付部分だけで比較する
    $courses = Course::whereDate('starts_at', now()->addDay()->toDateString())->get();

    foreach ($courses as $course) {
        SendCourseReminder::dispatch($course);
    }
})->dailyAt('09:00');
```
</details>

## 参考資料

- [Laravel 公式ドキュメント - Mail](https://laravel.com/docs/mail)
- [Laravel 公式ドキュメント - Queues](https://laravel.com/docs/queues)

## 次のレッスン

[Lesson 19 TDDで機能を追加する](./19-tdd.md) では、テスト駆動開発（TDD）のサイクルを体験します。
