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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Tries(3)]     // 最大試行回数
#[Timeout(60)]  // タイムアウト（秒）
class SendAttendanceConfirmation implements ShouldQueue
{
    use Queueable;

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

> 補足: `make:job` が生成するクラスは `Illuminate\Foundation\Queue\Queueable` トレイトを1つだけ use します。このトレイトが `Dispatchable`・`InteractsWithQueue`・`Queueable`・`SerializesModels` の4つをまとめて読み込むので、自分で並べる必要はありません。古い記事や、以前のバージョンで作られたプロジェクトでは、4つを個別に use する書き方もよく見かけます。
>
> 試行回数やタイムアウトは、Laravel 13 の公式ドキュメントでは `#[Tries(3)]` のようなクラスの属性で指定しています。以前からある `public int $tries = 3;` / `public int $timeout = 60;` というプロパティの書き方も引き続き使えます。

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

キューの接続先は `.env` の `QUEUE_CONNECTION` で決まります。

```env
# その場で同期実行する（キューを使わない）
QUEUE_CONNECTION=sync

# データベースのテーブルにジョブを積む
QUEUE_CONNECTION=database
```

このプロジェクトの `.env` は、Laravel 11 以降の初期設定どおり `QUEUE_CONNECTION=database` になっています。テスト実行時だけは `phpunit.xml` で `sync` に切り替えています（Step 8 で使います）。

### データベースキューの準備

`database` ドライバーが使う `jobs` テーブルは、Laravel 11 以降は最初から入っているマイグレーション `database/migrations/0001_01_01_000002_create_jobs_table.php` で作られます。このプロジェクトでは `make setup` の時点で作成済みなので、追加の作業はいりません。

> 補足: 古い記事では `php artisan queue:table` でマイグレーションを作る手順が紹介されています。現在のコマンド名は `php artisan make:queue-table` で、上のマイグレーションがないプロジェクトでだけ使います。このプロジェクトで実行すると「Migration already exists.」と表示されて何も作られません。

### キューワーカーの起動

キューに積んだジョブは、ワーカーと呼ばれる常駐プロセスが取り出して実行します。このプロジェクトの Docker コンテナは、起動時に次のワーカーを立ち上げています（`docker/app/Dockerfile` の `CMD`）。

```bash
php artisan queue:listen --tries=1
```

そのため、自分でワーカーを起動しなくてもジョブは処理されます。処理の様子は `make logs` の `queue` の行で確認できます。`queue:listen` はジョブを1件処理するたびにアプリケーションを読み込み直すので、コードを書き換えたあとにワーカーを再起動する必要もありません。

本番環境では、読み込み直しをしない分だけ速い `queue:work` を使うのが一般的です。参考までに主なオプションを載せておきます。

```bash
# 基本的な起動
php artisan queue:work

# 特定のキューを処理
php artisan queue:work --queue=emails,default

# メモリ制限とタイムアウト設定
php artisan queue:work --memory=128 --timeout=60

# 1ジョブ処理したら終了する
php artisan queue:work --once
```

> `queue:work` は起動時に読み込んだコードを使い続けます。デプロイでコードを更新したら `php artisan queue:restart` でワーカーを再起動してください。


## Step 5 失敗したジョブの管理

### 失敗ジョブテーブル

最大試行回数を超えて失敗したジョブは `failed_jobs` テーブルに記録されます。このテーブルも `jobs` テーブルと同じマイグレーション（`0001_01_01_000002_create_jobs_table.php`）で作成済みです。

> 補足: マイグレーションがないプロジェクトでは `php artisan make:queue-failed-table` で作ります。古い記事にある `php artisan queue:failed-table` は旧名です。このプロジェクトで実行しても、`queue:table` と同じく「Migration already exists.」と表示されて何も作られません。

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
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]                  // 最大3回試行
#[Backoff([10, 60, 300])]    // 試行間の待機時間（秒）。10秒、1分、5分
class SendAttendanceConfirmation implements ShouldQueue
{
    // ...
}
```

待機時間を一定間隔にしたい場合は `#[Backoff(60)]` のように1つの値を渡します。

> 補足: コンテナのワーカーは `--tries=1` で起動していますが、ジョブクラスに `#[Tries(3)]` を書くとジョブ側の指定が優先されます。プロパティで書く場合は `public int $tries = 3;` と `public array $backoff = [10, 60, 300];` のようにします。


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

> なぜトランザクションの外でディスパッチするのか: トランザクション内でジョブを積むと、まだコミットされていないレコードをワーカーが処理しにいく可能性があります（ワーカーは別プロセスなので、コミット前のデータは見えません）。結果として「該当レコードが見つからない」でジョブが失敗します。次に説明する `afterCommit()` を使う方法でも同じ問題を回避できます。

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

### 送ったメールを確認する

このプロジェクトの `.env` は `MAIL_MAILER=log` になっているため、送ったメールは実際には届かず、本文がログに書き出されます。`make logs` を実行するか、`storage/logs/laravel.log` を開くと確認できます。

受信箱のような画面で見たい場合は、Mailpit を使います。Mailpit は、送られてきたメールを受け取って画面に表示するだけの開発用のメールサーバーで、`make up` でアプリと一緒に起動しています。`.env` を以下のように変えてください。

```env
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

コンテナを起動し直す必要はありません。`php artisan serve` は `.env` の変更を検知して自動で再起動し、コンテナのワーカー（`queue:listen`）はジョブを1件処理するたびに設定を読み込み直すためです。`.env` を保存したら受講登録をしてみてください。http://localhost:8025 の画面にメールが届きます。

> 補足: `MAIL_HOST` は `localhost` ではなく `mailpit` にします。Laravel は Docker コンテナの中で動いているので、`localhost` はアプリのコンテナ自身を指し、Mailpit には届きません。`mailpit` は compose.yml に書いたサービス名で、コンテナ同士はこの名前で通信できます。


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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Mail;

#[Tries(3)]
class SendCourseReminder implements ShouldQueue
{
    use Queueable;

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
