# Lesson 17 メールとジョブ機能

## 学習目標

このレッスンでは、メール送信とキュー処理を実装し、非同期処理の基本を理解します。

### 到達目標
- Mailable クラスを作成できる
- メールを送信できる
- Job クラスを作成してキューに投入できる
- 非同期処理の仕組みを理解する
- `Mail::fake()`・`Queue::fake()` を使ってメールとジョブのテストを書ける

> このレッスンは2部構成です。
> - Step 1〜5: Mailable・Job・キューの書き方を学ぶ座学パート（コマンドを実行してクラスを作りながら読み進めてOKです）
> - Step 6〜8: 受講登録時に確認メールを送る処理を、Lesson 14 で作り Lesson 15 でトランザクションとロックを足した `AttendCourse` に組み込み、テストで確かめるハンズオンパート


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

Lesson 14 で作り、Lesson 15 でトランザクションとロックを足した `App\Services\Attendance\AttendCourse` に、確認メールのジョブ投入を追加します。足すのは `use App\Jobs\SendAttendanceConfirmation;` と、`DB::transaction()` のあとの `dispatch` の行だけです。それ以外は Lesson 15 のままにしてください。

```php
<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Exceptions\AlreadyAttendingException;
use App\Exceptions\CapacityExceededException;
use App\Jobs\SendAttendanceConfirmation;  // 追加
use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AttendCourse
{
    public function __invoke(User $user, Course $course): Attendance
    {
        $attendance = DB::transaction(function () use ($user, $course) {
            // ロックを付けて取り直す。受け取った $course の件数（withCount など）は使わない
            $lockedCourse = Course::lockForUpdate()->findOrFail($course->id);

            $this->validate($user, $lockedCourse);

            try {
                return Attendance::create([
                    'user_id' => $user->id,
                    'course_id' => $lockedCourse->id,
                    'status' => AttendanceStatus::Attending,
                    'attended_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new AlreadyAttendingException();
            }
        });

        // トランザクションの外でジョブをディスパッチ（追加）
        SendAttendanceConfirmation::dispatch($attendance);

        return $attendance->load(['user', 'course.instructor']);
    }

    private function validate(User $user, Course $course): void
    {
        if (! $course->hasCapacity()) {
            throw new CapacityExceededException();
        }

        $isAttending = $user->attendances()
            ->where('course_id', $course->id)
            ->where('status', '!=', AttendanceStatus::Cancelled)
            ->exists();

        if ($isAttending) {
            throw new AlreadyAttendingException();
        }
    }
}
```

`dispatch` を呼ぶのは、`DB::transaction()` が返ったあと、つまりコミットが終わってからです。定員に達していたり、すでに登録していたりして例外が投げられたときは、`DB::transaction()` がロールバックして例外を投げ直すので、`dispatch` の行まで進みません。確認メールが送られるのは、受講の登録に成功したときだけです。

> なぜトランザクションの外でディスパッチするのか: トランザクション内でジョブを積むと、まだコミットされていないレコードをワーカーが処理しにいく可能性があります（ワーカーは別プロセスなので、コミット前のデータは見えません）。結果として「該当レコードが見つからない」でジョブが失敗します。Lesson 15 の Step 1「メール送信はトランザクションの外で」と同じ考え方です。次に説明する `afterCommit()` を使う方法でも同じ問題を回避できます。

> 補足: ジョブに渡した `$attendance` は、キューには ID だけが入ります（`SerializesModels` の働きです）。ワーカーはジョブを処理するときに、その ID で受講を DB から読み直します。メールの中で `$this->attendance->user` や `course` を読むと遅延ロードになりますが、読み直す受講は1件だけなので、Lesson 16 で有効にした `preventLazyLoading()` の違反にはなりません。

### afterCommit でトランザクション完了後に実行

```php
SendAttendanceConfirmation::dispatch($attendance)
    ->afterCommit();  // トランザクションがコミットされてから実行
```

### コントローラーは変えない

`AttendanceController` は書き換えません。`AttendCourse` を書き換えたら `php artisan test` を実行し、Lesson 12 で書いた受講登録のテストが緑のままであることを確かめてください。テストではキューが `sync` なので、ジョブはその場で実行され、メールは `phpunit.xml` の `MAIL_MAILER=array` の設定により、どこにも送られずにメモリに残るだけです。


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

確認メールを送ることを、テストで確かめます。書き方は Lesson 12 Step 9 で書いた受講登録のテスト（`tests/Feature/Api/AttendanceStoreTest.php`）と同じで、`actingAs()` で生徒としてログインし、`postJson()` で申し込みます。違いは、テストの最初に `Mail::fake()` や `Queue::fake()` を呼ぶことです。

`Mail::fake()` を呼ぶと、そのテストの間だけ、メールの送信が「送ったことを記録するだけの偽物」に差し替わります。メールは実際には送られず、あとから `Mail::assertSent()` で「このメールが、この宛先に送られたか」を確かめられます。`Queue::fake()` も同じで、ジョブを実行せずに「積まれたこと」だけを記録します。Lesson 14 の `$this->mock()` と同じく、テストの間だけ本物を偽物に入れ替える仕組みです。

`make app` でコンテナに入ってから、テストファイルを作ります。

```bash
php artisan make:test Api/AttendanceConfirmationTest
```

以下の2つのテストを、`tests/Feature/Api/AttendanceConfirmationTest.php` に書きます。

### メール送信のテスト

```php
// tests/Feature/Api/AttendanceConfirmationTest.php

use App\Jobs\SendAttendanceConfirmation;
use App\Mail\AttendanceConfirmation;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

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

同じファイルに続けて書きます。`use` はファイルの先頭にまとめてあります。

```php
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

`Queue::assertPushed()` の第2引数のクロージャには、積まれたジョブが渡されます。ここでは、申し込んだ講座の受講がジョブに渡されていることを確かめています。

テストを実行して、2つとも通ることを確かめます。

```bash
php artisan test tests/Feature/Api/AttendanceConfirmationTest.php
```

## 練習問題

### 問題1
定員に達している講座に申し込んだとき（422）と、すでに登録している講座にもう一度申し込んだとき（409）には、確認メールのジョブが積まれないことを確かめるテストを、`tests/Feature/Api/AttendanceConfirmationTest.php` に追加してください。

> ヒント: Step 6 で、ジョブを積むのはトランザクションが成功したあとにしました。`Queue::fake()` を呼んだうえで、何も積まれていないことは `Queue::assertNothingPushed()` で確かめられます。受講のデータは Lesson 12 と同じく `Attendance::factory()` で作ります。

<details>
<summary>解答例</summary>

```php
use App\Models\Attendance;

test('定員に達しているときはジョブが積まれない', function () {
    Queue::fake();

    $student = User::factory()->student()->create();
    $course = Course::factory()->active()->create(['capacity' => 1]);

    // 先に1人登録して定員を埋める
    Attendance::factory()->create(['course_id' => $course->id]);

    $this->actingAs($student)
        ->postJson("/api/courses/{$course->id}/attendances")
        ->assertUnprocessable();

    Queue::assertNothingPushed();
});

test('すでに登録しているときはジョブが積まれない', function () {
    Queue::fake();

    $student = User::factory()->student()->create();
    $course = Course::factory()->active()->create();

    Attendance::factory()->create([
        'user_id' => $student->id,
        'course_id' => $course->id,
    ]);

    $this->actingAs($student)
        ->postJson("/api/courses/{$course->id}/attendances")
        ->assertStatus(409);

    Queue::assertNothingPushed();
});
```

`use App\Models\Attendance;` はファイルの先頭の `use` に足します。`AttendanceFactory` の既定の状態は受講中（`attending`）なので、`status` を指定しなくても定員に数えられ、重複の判定にもかかります。

Step 6 のコードなら、どちらのテストもそのまま通ります。`validate()` が例外を投げると `DB::transaction()` から例外が投げ直され、`dispatch` の行まで進まないためです。あとで `AttendCourse` に手を入れたときに、失敗した申し込みでも確認メールが送られるようになっていないかを、このテストで確かめられます。
</details>

### 問題2
講座の開始1日前にリマインドメールを送信するジョブを作成してください。送る相手は、その講座を受講中（キャンセル済みを除く）の受講者です。

> ヒント: 受講中の受講は、Lesson 11 で作った `active()` スコープで絞れます。受講者のユーザーを読むときは、Lesson 16 で学んだとおり `with()` で読み込んでおきます。

<details>
<summary>解答例</summary>

`CourseReminder` は、Step 1 と同じ手順（`php artisan make:mail CourseReminder`）で作るメールです。ここでは中身を省略します。

```php
<?php

namespace App\Jobs;

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
        $attendances = $this->course->attendances()
            ->active()
            ->with('user')
            ->get();

        foreach ($attendances as $attendance) {
            Mail::to($attendance->user)->send(new CourseReminder($this->course));
        }
    }
}
```

`with('user')` を書き忘れると、受講者が2人以上いる講座では、ループの中の `$attendance->user` が Lesson 16 の `preventLazyLoading()` の違反になり、ジョブが失敗します。ジョブの中でも、コントローラーと同じように Eager Loading が必要です。

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

> 補足: このプロジェクトの Docker コンテナは、キューのワーカーは起動していますが、スケジューラーは起動していません。本番では、`php artisan schedule:run` を1分ごとに実行するよう cron に登録します。手元で試すときは、`php artisan schedule:test` を実行し、一覧から選ぶとその場で1回だけ実行できます。
</details>

## 参考資料

- [Laravel 公式ドキュメント - Mail](https://laravel.com/docs/mail)
- [Laravel 公式ドキュメント - Queues](https://laravel.com/docs/queues)

## 次のレッスン

[Lesson 18 TDDで機能を追加する](./18-tdd.md) では、テスト駆動開発（TDD）のサイクルで受講キャンセル機能を作ります。キャンセルしたときに送るメールも、そこで作ります。
