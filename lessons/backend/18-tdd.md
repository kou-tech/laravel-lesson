# Lesson 18 TDDで機能を追加する

## 学習目標

このレッスンでは、テスト駆動開発（TDD）のサイクルを体験し、品質の高い機能追加を実践します。

### 到達目標
- TDDの基本サイクル（Red-Green-Refactor）を理解する
- テストを先に書いて機能を実装できる
- リファクタリングの安全性を体験する

> このレッスンは、Lesson 12 の Step 0 で `RefreshDatabase` を有効にしてあること、Lesson 14 で作った `AttendCourse` と `BusinessException`、Lesson 15 で足したトランザクションとロック、Lesson 17 で学んだメールとキューを前提にしています。


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
- キャンセルした分の席には、ほかの生徒が申し込める
- キャンセル時にメール通知を送信


## Step 1 Red - 失敗するテストを書く

### テストファイルの作成

`make app` でコンテナに入ってから、以下のコマンドを実行します。

```bash
php artisan make:test Api/AttendanceCancelTest
```

`make:test` はPest形式のテストファイルを生成します（Lesson 12 の Step 0 で `RefreshDatabase` を有効にしてあることを前提にしています）。

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
Expected response status code [200] but received 405.
```

405 は Method Not Allowed で、「このURLはあるが、そのHTTPメソッドは受け付けていない」という意味です。同じURLに Lesson 10 で作った `POST` のルートがあり、`DELETE` のルートはまだないので 405 になります。これがRed状態です。


## Step 2 Green - テストを通す最小限のコード

### ルートを追加

```php
// routes/api.php

Route::middleware('auth:sanctum')->group(function () {
    // ... 既存のルート

    Route::delete('/courses/{course}/attendances', [AttendanceController::class, 'destroy']);
});
```

> URLは Lesson 10 で作った受講登録（`POST /api/courses/{course}/attendances`）と同じにし、HTTPメソッドだけ `DELETE` に変えています。Lesson 3 で学んだ「URLは名詞、操作はHTTPメソッドで表す」という原則の通りです。

### コントローラーにメソッドを追加

`AttendanceController` には、Lesson 14 で `AttendCourse` を受け取るコンストラクタと `store` が入っています。そこに `destroy` を足します。`AttendanceStatus` の `use` 文がなければ追加してください。

```php
// app/Http/Controllers/Api/AttendanceController.php

public function destroy(Request $request, Course $course): JsonResponse
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
FAILED  Tests\Feature\Api\AttendanceCancelTest > 講座開始3日前以降はキャンセルできない
Expected response status code [422] but received 200.
```


## Step 4 実装を追加（Green）

### コントローラーを修正

```php
public function destroy(Request $request, Course $course): JsonResponse
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

### キャンセルすると席が空く

定員1人の講座で、1人目がキャンセルしたら2人目が申し込めることを確かめます。

```php
test('キャンセルすると、ほかの生徒が申し込めるようになる', function () {
    $student = User::factory()->student()->create();
    $otherStudent = User::factory()->student()->create();
    $course = Course::factory()->create([
        'starts_at' => now()->addDays(7),
        'capacity' => 1,
    ]);

    $this->actingAs($student)
        ->postJson("/api/courses/{$course->id}/attendances")
        ->assertCreated();

    // 満席なので申し込めない
    $this->actingAs($otherStudent)
        ->postJson("/api/courses/{$course->id}/attendances")
        ->assertUnprocessable();

    $this->actingAs($student)
        ->deleteJson("/api/courses/{$course->id}/attendances")
        ->assertOk();

    // 席が空いたので申し込める
    $this->actingAs($otherStudent)
        ->postJson("/api/courses/{$course->id}/attendances")
        ->assertCreated();
});
```

ここまでの3つのテストは、既存の実装で通ります。最後のテストが通るのは、Lesson 10 で作った `hasCapacity()` が受講中（`attending`）の件数だけを数え、キャンセル済みを数えないためです。

### 気付きにくい落とし穴: キャンセルすると二度と申し込めない

ここでもう1つテストを書いてみてください。「キャンセルしたあと、同じ講座に申し込み直せる」はずです。

```php
test('キャンセル後に同じ講座へ再登録できる', function () {
    $student = User::factory()->student()->create();
    $course = Course::factory()->create([
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
Failed asserting that 409 is identical to 201.

The following exception occurred during the last request:

App\Exceptions\AlreadyAttendingException: すでにこの講座に登録済みです。 in /var/www/html/app/Services/Attendance/AttendCourse.php:（行番号）
```

失敗のメッセージには、リクエストの中で投げられた例外と、その場所が出ます。行番号をたどると、`AttendCourse` の `validate()` ではなく、`Attendance::create()` を囲んだ `catch` の中を指しているはずです。

2回目の申し込みで `AttendCourse` が何をしているかを順に追うと、原因が分かります。

1. `validate()` の定員チェックは通ります。`hasCapacity()` はキャンセル済みを数えません
2. `validate()` の重複チェックも通ります。Lesson 14 で、`exists()` はキャンセル済みを除いて探す形にしました
3. `Attendance::create()` が、Lesson 10 で付けた複合ユニーク制約 `unique(['user_id', 'course_id'])` に違反します。キャンセル済みの行が残っているためです
4. `catch` が `UniqueConstraintViolationException` を `AlreadyAttendingException` に変えるので、409 が返ります

Lesson 14 の練習問題2の最後のケースで確かめたのも、この経路です。キャンセル機能がなかったころは想定どおりの動きでしたが、利用者がキャンセルできるようになった今は不具合になります。

アプリ側のチェックは「キャンセル済みは登録していないのと同じ」とみなしているのに、DB の制約はキャンセル済みの行も重複とみなしています。この食い違いのせいで、一度キャンセルした受講者はその講座に二度と申し込めません。しかも返ってくるメッセージは「すでにこの講座に登録済みです。」なので、利用者には理由が分かりません。

「キャンセル機能を足したら、意図せず別の機能が壊れた」という典型例です。テストを書いていなければ、実際に受講者から問い合わせが来るまで気付けなかったはずです。

対処にはいくつかの方針があり、どれを選ぶかは要件次第です。

| 方針 | 内容 | トレードオフ |
|------|------|-------------|
| キャンセル行を再利用する | 申込み時にキャンセル済みの行があれば `attending` に戻す | 実装が最も簡単。ただし「いつキャンセルしたか」の履歴が消える |
| ユニーク制約に `status` を含める | `unique(['user_id', 'course_id', 'status'])` にする | 履歴は残るが、同じ講座を2回キャンセルできなくなる |
| キャンセル行を論理削除する | `deleted_at` を追加し、部分ユニークインデックスにする | 履歴も残り制約も正しく効くが、SQLiteでは部分インデックスの扱いに注意が必要 |

このレッスンでは制約はそのままにしておきます。このままでは `make test` が落ち続けるので、テストの閉じ括弧を `})->todo();` に変えて保留にしてください。Pest は `todo()` の付いたテストを実行せず、結果に「todo」として数えます。どう直すかは末尾の練習問題2で考えます。


## Step 6 Refactor - コードを整理

### サービスクラスに抽出

Lesson 14 で決めた「1サービス = 1操作、`__invoke` で呼ぶ」方針に沿って、`App\Services\Attendance\CancelAttendance` に抽出します。Lesson 14 のディレクトリ構成の例で「Lesson 18 で作る」としていたクラスです。

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

`CancellationDeadlineExceededException` は Lesson 14 の `BusinessException` を継承して作ります。これで 422 と、`message`・`error_code` の入ったJSONが返ります。

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

> 更新が1テーブル1行だけなので、ここでは `DB::transaction()` を使っていません。Lesson 15 で学んだ通り、トランザクションが必要になるのは「複数の更新をまとめて成功／失敗させたいとき」です。`AttendCourse` と違ってロックも付けていません。`AttendCourse` は「件数を数えてから行を足す」ので、数えてから足すまでの間に割り込まれないようロックが要りました。キャンセルは自分の1行を `cancelled` に変えるだけなので、同時に2回届いても結果は変わりません。Step 7 でメール送信を足しても、メールはDBの更新ではないのでこの判断は変わりません。

### コントローラーをシンプルに

コンストラクタに `CancelAttendance` を足し、`destroy` はサービスを呼ぶだけにします。`store` は Lesson 15 までの形のままです。

```php
public function __construct(
    private AttendCourse $attendCourse,
    private CancelAttendance $cancelAttendance,
) {}

public function destroy(Request $request, Course $course): JsonResponse
{
    ($this->cancelAttendance)($request->user(), $course);

    return response()->json(['message' => 'キャンセルしました']);
}
```

`destroy` で使わなくなった `AttendanceStatus` の `use` 文は、`store` でも使っていなければ消してかまいません。

### テストを再実行して確認

```bash
# 全テスト実行
make test

# または特定のテストファイルのみ実行（コンテナ内で）
php artisan test tests/Feature/Api/AttendanceCancelTest.php
```

全てのテストがパスすれば、リファクタリング成功です。3日前を過ぎたときのレスポンスには、`BusinessException` のおかげで `"error_code": "CANCELLATION_DEADLINE_EXCEEDED"` も付くようになりました。Step 3 のテストに `->assertJsonPath('error_code', 'CANCELLATION_DEADLINE_EXCEEDED')` を足しておくと、この動きもテストで守れます。

Postman では `api > courses > {courseId} > attendances > 受講キャンセル` で動作を確かめられます。受講登録した講座のIDを指定して送ってください。


## Step 7 メール通知のテストを追加

最後の要件「キャンセル時にメール通知を送信」も、テストから書きます。

### テストを書く（Red）

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

`use` 文はファイルの先頭にまとめてください。実行すると、次のように失敗します。

```
The expected [App\Mail\AttendanceCancellation] mailable was not queued.
```

`AttendanceCancellation::class` はクラス名の文字列になるだけなので、クラスをまだ作っていなくてもエラーにはならず、「キューに積まれていない」という失敗になります。

### Mailable を作る

Lesson 17 の確認メールと同じ形で、キャンセルの通知メールを作ります。`--view` を付けると、メールの本文になるビューのファイルも一緒にできます。

```bash
php artisan make:mail AttendanceCancellation --view=emails.attendance-cancellation
```

`app/Mail/AttendanceCancellation.php` を以下のようにします。

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

    /**
     * 件名、送信元などの設定
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【キャンセル完了】' . $this->attendance->course->title,
        );
    }

    /**
     * 本文の設定
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.attendance-cancellation',
            with: [
                'userName' => $this->attendance->user->name,
                'courseName' => $this->attendance->course->title,
                'startsAt' => $this->attendance->course->starts_at->format('Y年m月d日 H:i'),
            ],
        );
    }
}
```

> `make:mail` が生成したファイルにある `attachments()` メソッドと、使っていない `use` 文（`ShouldQueue`・`Attachment`）は、残しておいても動きます。

`resources/views/emails/attendance-cancellation.blade.php` を以下のようにします。

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body>
    <h1>受講のキャンセルを受け付けました</h1>

    <p>{{ $userName }} 様</p>

    <p>
        以下の講座の受講をキャンセルしました。
    </p>

    <table>
        <tr>
            <th>講座名</th>
            <td>{{ $courseName }}</td>
        </tr>
        <tr>
            <th>開始日時</th>
            <td>{{ $startsAt }}</td>
        </tr>
    </table>

    <p>
        ご利用ありがとうございました。
    </p>
</body>
</html>
```

### サービスにメール送信を追加（Green）

`CancelAttendance` の最後で、通知メールをキューに積みます。`App\Mail\AttendanceCancellation` と `Illuminate\Support\Facades\Mail` の `use` 文も追加してください。

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

テストを実行して、通ることを確かめてください。Lesson 17 の Step 7 で Mailpit を使う設定にしてあれば、Postman でキャンセルしたあと http://localhost:8025 に通知メールが届きます。

### Lesson 17 は assertSent、ここは assertQueued になる理由

Lesson 17 の確認メールは `assertSent` で、このレッスンの通知メールは `assertQueued` で確かめています。違いは、メールをどう送っているかにあります。

Lesson 17 では、ジョブ `SendAttendanceConfirmation` を `dispatch` し、ジョブの `handle()` の中で `Mail::to(...)->send(...)` を呼びました。テストでは `phpunit.xml` で `QUEUE_CONNECTION` が `sync` になっているので、ジョブがその場で実行され、メールは「送信された」として記録されます。だから `assertSent` で捕まえられます。

このレッスンでは、ジョブを作らずに `Mail::to($user)->queue(...)` で Mailable そのものをキューに積んでいます。`queue()` に渡すと、Laravel が送信用のジョブを内部で作ってキューに積みます。`Mail::fake()` の中では、`queue()` で積んだメールは「キューに積まれた」として記録されるので、`assertQueued` で確かめます。`send()` で送ったのに `assertQueued` で確かめる（またはその逆）と、メールの処理は動いていてもテストは落ちます。

ジョブを作るかどうかは、ジョブで何をしたいかで決めます。Lesson 17 でジョブを作ったのは、試行回数（`#[Tries]`）や失敗時の処理（`failed()`）を書くためでした。キャンセルの通知はそこまで作り込まないので、`queue()` で足ります。


## TDDのメリット

### 1. 設計が改善される

テストを先に書くと、テストしにくい書き方に、実装する前に気づけます。

```php
// ❌ テストしにくい（メソッドの中で new しているので、差し替えられない）
public function destroy(Request $request, Course $course): JsonResponse
{
    $cancelAttendance = new CancelAttendance();
    $cancelAttendance($request->user(), $course);

    return response()->json(['message' => 'キャンセルしました']);
}

// ✅ テストしやすい（コンストラクタで受け取るので、テストで差し替えられる）
public function __construct(
    private CancelAttendance $cancelAttendance,
) {}
```

Lesson 14 で見たとおり、コンストラクタに型を書いて受け取ったクラスは Laravel のサービスコンテナが作って渡すので、テストでは `$this->mock()` で差し替えられます。メソッドの中で `new` していると、テストからは差し替えられません。サービスコンテナの仕組みをもっと知りたい人は、[発展 A2 サービスコンテナとDI](./A2-di-container.md) を読んでください。

### 2. 過剰な実装を防ぐ

テストが通るまで次のコードを書かないため、必要最小限の実装になります。

### 3. リファクタリングの安心感

Step 6 では、コントローラーに書いた処理をサービスクラスに移しても、テストを流せば動きが変わっていないことをすぐ確かめられました。

### 4. ドキュメントになる

テストコードが仕様書の役割を果たします。テスト名を並べると、キャンセル機能の要件がそのまま読めます。

## 練習問題

### 問題1

以下の機能をTDDで実装してください。

「講師が講座の定員を変更するとき、いま受講中の人数より少なくはできない」

- 定員は Lesson 9 の `PATCH /api/courses/{course}` で変更する
- 受講中の人数より少ない定員を送ると 422 になり、`errors.capacity` にメッセージが入る。定員は変わらない
- 受講中の人数と同じ定員には変更できる
- キャンセル済みの受講は人数に数えない

<details>
<summary>解答例</summary>

Step 1 テストを書く

```php
// tests/Feature/Api/CourseCapacityUpdateTest.php
<?php

use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;

test('受講中の人数より少ない定員には変更できない', function () {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->create([
        'instructor_id' => $instructor->id,
        'capacity' => 10,
    ]);
    Attendance::factory()->count(3)->create(['course_id' => $course->id]);

    $response = $this->actingAs($instructor)
        ->patchJson("/api/courses/{$course->id}", ['capacity' => 2]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['capacity']);

    $this->assertDatabaseHas('courses', [
        'id' => $course->id,
        'capacity' => 10,
    ]);
});

test('受講中の人数と同じ定員には変更できる', function () {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->create([
        'instructor_id' => $instructor->id,
        'capacity' => 10,
    ]);
    Attendance::factory()->count(3)->create(['course_id' => $course->id]);

    $this->actingAs($instructor)
        ->patchJson("/api/courses/{$course->id}", ['capacity' => 3])
        ->assertOk()
        ->assertJsonPath('data.capacity', 3);
});

test('キャンセル済みの受講は人数に数えない', function () {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->create([
        'instructor_id' => $instructor->id,
        'capacity' => 10,
    ]);
    Attendance::factory()->count(2)->create(['course_id' => $course->id]);
    Attendance::factory()->cancelled()->create(['course_id' => $course->id]);

    $this->actingAs($instructor)
        ->patchJson("/api/courses/{$course->id}", ['capacity' => 2])
        ->assertOk();
});
```

1つ目のテストは、今の実装では 200 が返って失敗します（Red）。2つ目と3つ目は今の実装でも通りますが、実装を足したあとに壊れていないことを確かめるために書いておきます。

Step 2 実装する

ほかの入力エラーと同じ形（422 で `errors` にメッセージが入る）で返したいので、Lesson 9 の `Course/UpdateRequest` の `capacity` にクロージャのルールを足します。

```php
// app/Http/Requests/Course/UpdateRequest.php
use App\Models\Course;
use Closure;

public function rules(): array
{
    return [
        'title' => ['sometimes', 'string', 'max:255'],
        'description' => ['nullable', 'string'],
        'capacity' => [
            'sometimes',
            'integer',
            'min:1',
            'max:100',
            function (string $attribute, mixed $value, Closure $fail) {
                /** @var Course $course */
                $course = $this->route('course');

                // Lesson 11 の active スコープ（受講中だけ）で数える
                $attendingCount = $course->attendances()->active()->count();

                if ($value < $attendingCount) {
                    $fail("定員は、いま受講中の人数（{$attendingCount}人）より少なくできません。");
                }
            },
        ],
        'status' => ['sometimes', Rule::enum(CourseStatus::class)],
    ];
}
```

`$this->route('course')` は、ルートの `{course}` に入った値を返します。ルートモデルバインディングが先に働くので、IDではなく `Course` のモデルが返ります。

> 補足: Lesson 12 で学んだとおり、バリデーションはコントローラーの `$this->authorize()` より先に走ります。そのため、ほかの講師が少ない定員を送ると、403 ではなく 422 が返り、メッセージから受講中の人数が分かってしまいます。気になる場合は、`UpdateRequest` の `authorize()` を `return $this->user()->can('update', $this->route('course'));` にすると、認可がバリデーションより先に行われて 403 になります。

</details>

### 問題2

Step 5 で見つけた「キャンセル後に同じ講座へ再登録できない」問題を、TDDで直してください。

1. Step 5 で保留にした `キャンセル後に同じ講座へ再登録できる` テストを有効に戻す（Red）
2. Step 5 の表から方針を1つ選んで実装する（Green）
3. 既存のテストがすべて通ることを確認する

Lesson 14 の練習問題2で「自分のキャンセル済みの受講が残っている講座に申し込むと `AlreadyAttendingException` が投げられる」テストを書いた人は、手順3でそのテストが落ちます。仕様を「再登録できる」に変えたので、そのテストも新しい仕様に合わせて書き換えてください。

<details>
<summary>解答例（キャンセル行を再利用する方針）</summary>

Step 5 のテストに加えて、再登録しても行が増えないことも確かめておきます。

```php
test('キャンセル後に再登録しても、受講の行は増えない', function () {
    $student = User::factory()->student()->create();
    $course = Course::factory()->create([
        'starts_at' => now()->addDays(7),
        'capacity' => 20,
    ]);

    $this->actingAs($student)->postJson("/api/courses/{$course->id}/attendances")->assertCreated();
    $this->actingAs($student)->deleteJson("/api/courses/{$course->id}/attendances")->assertOk();
    $this->actingAs($student)->postJson("/api/courses/{$course->id}/attendances")->assertCreated();

    $this->assertDatabaseCount('attendances', 1);
    $this->assertDatabaseHas('attendances', [
        'user_id' => $student->id,
        'course_id' => $course->id,
        'status' => AttendanceStatus::Attending->value,
    ]);
});
```

直すのは、受講登録の処理をまとめた `AttendCourse` です。トランザクションの中で、キャンセル済みの受講があれば作り直さずに `attending` へ戻し、なければ今までどおり作ります。

```php
// app/Services/Attendance/AttendCourse.php
<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Exceptions\AlreadyAttendingException;
use App\Exceptions\CapacityExceededException;
use App\Jobs\SendAttendanceConfirmation;
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
            // ロックを付けて取り直す（Lesson 15）
            $lockedCourse = Course::lockForUpdate()->findOrFail($course->id);

            $this->validate($user, $lockedCourse);

            // キャンセル済みの受講があれば再開し、なければ新しく作る
            return $this->resumeCancelled($user, $lockedCourse)
                ?? $this->createAttendance($user, $lockedCourse);
        });

        // トランザクションの外でジョブを投げる（Lesson 17）
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

    /**
     * キャンセル済みの受講があれば、受講中に戻して返す
     */
    private function resumeCancelled(User $user, Course $course): ?Attendance
    {
        $cancelled = $user->attendances()
            ->where('course_id', $course->id)
            ->where('status', AttendanceStatus::Cancelled)
            ->first();

        if ($cancelled === null) {
            return null;
        }

        $cancelled->update([
            'status' => AttendanceStatus::Attending,
            'attended_at' => now(),
        ]);

        return $cancelled;
    }

    private function createAttendance(User $user, Course $course): Attendance
    {
        try {
            return Attendance::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'status' => AttendanceStatus::Attending,
                'attended_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new AlreadyAttendingException();
        }
    }
}
```

`validate()` は変えていません。定員チェックはキャンセル済みを数えず、重複チェックもキャンセル済みを除いて探すので、再登録のときは両方とも通ります。定員のチェックを通ってから行を戻すので、満席の講座に再登録しようとした場合は今までどおり 422 になります。再開した受講にも、Lesson 17 の確認メールのジョブが投げられます。コントローラーは `AttendCourse` を呼ぶだけなので、書き換える必要はありません。

`createAttendance()` の `UniqueConstraintViolationException` の `catch` は残しておいてください。「キャンセル済みを探す」から「作成する」までの間に、別の処理が同じ受講の行を作る可能性があるためです（Lesson 15 で学んだ競合状態）。Lesson 15 でロックを付けたので、`AttendCourse` どうしは MySQL では順番に処理されますが、ロックが防げるのは同じロックを取る処理どうしだけです。シーダーやあとから増える別の処理が `AttendCourse` を通らずに行を足すことはありますし、SQLite では `lockForUpdate()` が何もしません。アプリ側のチェックとDB制約の二段構えにしておくのが定石です。

</details>


## 参考資料

- [Laravel 公式ドキュメント - Testing](https://laravel.com/docs/testing)
- [Laravel 公式ドキュメント - Mail](https://laravel.com/docs/mail)
- [Test-Driven Development by Example（Kent Beck）](https://www.amazon.co.jp/dp/4274217884)

## 次のレッスン

次は [ボス戦3 講師向けの受講者一覧APIをTDDで作る](./boss-3-course-attendances.md) です。コードは載っておらず、渡される仕様だけを見て、このレッスンと同じ Red → Green → Refactor の順で `GET /api/courses/{course}/attendances` を作ります。

ボス戦3 まで終えたら、バックエンドのレッスンは終わりです。続けて [フロントエンド資料](../frontend/README.md) を読むと、作ってきたAPIを画面から呼ぶときの設計とデータ取得の考え方を押さえられます。
