# 発展 A2 サービスコンテナとDI

## 学習目標

このレッスンでは、Laravelのサービスコンテナの仕組みを理解し、依存性注入（DI）を活用できるようになります。

### 到達目標
- サービスコンテナとは何かを説明できる
- 依存性注入（DI）のメリットを理解する
- コンストラクタインジェクションを使える
- インターフェースとバインディングを設定できる

> この発展レッスンは本線のレッスンとは独立していて、読まなくても本線は進められます。Lesson 14 を終えたあとに読むと理解が深まります。Lesson 14 では、`AttendanceController` のコンストラクタに `AttendCourse` の型を書くだけで Laravel がインスタンスを作って渡してくれること、テストでは `$this->mock()` で差し替えられることを使いました。このレッスンでは、その裏で働いているサービスコンテナの仕組みを詳しく扱います。
>
> 仕組みを理解する座学中心のレッスンです。登場する `MailerInterface` / `SmtpMailer` / `FakeMailer` / `NotificationService` / `AttendanceService` / `AttendanceServiceInterface` は説明用の仮の題材で、プロジェクトのコードには反映しません（練習問題だけは、手を動かしたい人向けに実際に作ってみてOKです）。
>
> 先に方針を示しておくと、本コースが採用しているのは「インターフェースを切らず、具象クラスをそのまま注入する」形です（Lesson 14 の `AttendCourse` など）。インターフェースは常に必要なものではなく、実装を差し替える必要が出たときの引き出しです。使い分けの基準はレッスン末尾の「いつインターフェースを切るか」にまとめています。


## 依存性とは？

### あるクラスが別のクラスを「使う」こと

```php
class AttendanceController extends Controller
{
    public function store(Request $request, Course $course)
    {
        // AttendanceService に「依存」している
        $service = new AttendanceService();
        $attendance = $service->attend($request->user(), $course);

        return new AttendanceResource($attendance);
    }
}
```

このコードには以下の問題点があります。
- `AttendanceService` を直接 new している
- テスト時にモックに差し替えられない
- `AttendanceService` のコンストラクタが変わると、このコードも変更が必要


## 依存性注入（DI）とは？

### 依存するオブジェクトを「外から渡す」

```php
class AttendanceController extends Controller
{
    // コンストラクタで受け取る（注入される）
    public function __construct(
        private AttendanceService $attendanceService
    ) {}

    public function store(Request $request, Course $course)
    {
        // 注入されたサービスを使う
        $attendance = $this->attendanceService->attend($request->user(), $course);

        return new AttendanceResource($attendance);
    }
}
```

依存性注入のメリットは以下の通りです。
- クラス間の結合度が下がる
- テスト時にモックを注入できる
- 依存関係が明確になる


## Step 1 サービスコンテナとは？

### インスタンスを作って渡す仕組み

サービスコンテナは、クラスのインスタンス化と依存関係の解決を自動で行います。Lesson 14 で `AttendanceController` のコンストラクタに `AttendCourse` を書いただけで動いたのは、サービスコンテナが `AttendCourse` を作ってコントローラーに渡していたためです。

```php
// サービスコンテナに「AttendanceService が欲しい」と伝える
$service = app(AttendanceService::class);

// コントローラーのコンストラクタに型宣言すると自動で注入
public function __construct(
    private AttendanceService $attendanceService
) {}
```

### 自動解決の仕組み

```php
class AttendanceService
{
    // NotificationService に依存
    public function __construct(
        private NotificationService $notificationService
    ) {}
}

class NotificationService
{
    // MailService に依存
    public function __construct(
        private MailService $mailService
    ) {}
}
```

`AttendanceService` を要求すると、以下の順序で解決されます。

1. `AttendanceService` のコンストラクタを解析
2. `NotificationService` が必要 → 再帰的に解決
3. `NotificationService` のコンストラクタを解析
4. `MailService` が必要 → 再帰的に解決
5. 全ての依存を解決してインスタンス化


## Step 2 コンストラクタインジェクション

### コントローラーでの使用

```php
class CourseController extends Controller
{
    public function __construct(
        private CourseService $courseService,
        private NotificationService $notificationService
    ) {}

    public function store(StoreRequest $request)
    {
        $course = $this->courseService->create(
            $request->validated(),
            $request->user()
        );

        $this->notificationService->notifyAdmins($course);

        return new CourseResource($course);
    }
}
```

Lesson 14 の `CourseController` が `CreateCourse` を受け取っているのも、この書き方です。

### メソッドインジェクション

メソッドの引数でも依存性を受け取れます。

```php
public function store(
    StoreRequest $request,
    CourseService $courseService  // メソッドで注入
)
{
    $course = $courseService->create(...);
}
```


## Step 3 バインディング

### 基本的なバインド

`app/Providers/AppServiceProvider.php` に以下のように記述します。

```php
use App\Services\AttendanceService;

public function register(): void
{
    $this->app->bind(AttendanceService::class, function ($app) {
        return new AttendanceService(
            $app->make(NotificationService::class),
            config('attendance.max_capacity')
        );
    });
}
```

### シングルトン

アプリケーション全体で1つのインスタンスを共有します。

```php
$this->app->singleton(CacheService::class, function ($app) {
    return new CacheService(
        $app->make('cache.store')
    );
});
```

### bind vs singleton

| メソッド | 動作 | 用途 |
|---------|------|------|
| `bind` | 毎回新しいインスタンス | 状態を持つサービス |
| `singleton` | 1つのインスタンスを共有 | 状態を持たない、またはキャッシュしたいサービス |


## Step 4 インターフェースへのバインド

### なぜインターフェースを使うか？

具象クラスに直接依存すると、実装を差し替えられません。

```php
// ❌ 具象クラスに依存
class AttendanceService
{
    public function __construct(
        private SmtpMailer $mailer  // SMTPに固定
    ) {}
}

// ✅ インターフェースに依存
class AttendanceService
{
    public function __construct(
        private MailerInterface $mailer  // 実装は後から決める
    ) {}
}
```

### インターフェースの定義

`app/Contracts/MailerInterface.php` に以下のように定義します。

```php
<?php

namespace App\Contracts;

interface MailerInterface
{
    public function send(string $to, string $subject, string $body): void;
}
```

### 実装クラス

`app/Services/SmtpMailer.php` に以下のように実装します。

```php
<?php

namespace App\Services;

use App\Contracts\MailerInterface;

class SmtpMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $body): void
    {
        // SMTP経由でメール送信
    }
}
```

### バインディング

```php
use App\Contracts\MailerInterface;
use App\Services\SmtpMailer;

public function register(): void
{
    $this->app->bind(MailerInterface::class, SmtpMailer::class);
}
```

これで `MailerInterface` を要求すると `SmtpMailer` が注入されます。

### 環境ごとに実装を切り替え

```php
public function register(): void
{
    if (app()->environment('testing')) {
        $this->app->bind(MailerInterface::class, FakeMailer::class);
    } else {
        $this->app->bind(MailerInterface::class, SmtpMailer::class);
    }
}
```

> 補足: Laravel 13 では、サービスプロバイダーに書く代わりに、インターフェースに `#[Bind]` 属性を付けてバインドすることもできます。`environments` を指定すれば、上の環境ごとの切り替えも属性だけで書けます。
>
> ```php
> use App\Services\FakeMailer;
> use App\Services\SmtpMailer;
> use Illuminate\Container\Attributes\Bind;
>
> #[Bind(SmtpMailer::class)]
> #[Bind(FakeMailer::class, environments: ['testing'])]
> interface MailerInterface
> {
>     public function send(string $to, string $subject, string $body): void;
> }
> ```
>
> 同じように、クラスに `#[Singleton]` 属性を付けると `singleton()` で登録したのと同じ扱いになります。どちらも結果はサービスプロバイダーに書いた場合と同じです。このレッスンでは、バインドの仕組みが見えやすいサービスプロバイダーの書き方で進めます。


## Step 5 実践 - AttendanceService のリファクタリング

Lesson 14 では、受講登録の処理を `AttendCourse` に切り出し、具象クラスのままコントローラーに注入しました。ここでは同じ受講登録を、仮の `AttendanceService` とインターフェースを使って書くとどうなるかを見ます。

### Before（密結合）

```php
class AttendanceController extends Controller
{
    public function store(Request $request, Course $course)
    {
        $user = $request->user();

        // ビジネスロジックがコントローラーに
        if (!$course->hasCapacity()) {
            throw new CapacityExceededException();
        }

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);

        // 直接メール送信
        Mail::to($user)->send(new AttendanceConfirmation($attendance));

        return new AttendanceResource($attendance);
    }
}
```

### After（DIを活用）

#### インターフェース

```php
// app/Contracts/AttendanceServiceInterface.php
<?php

namespace App\Contracts;

use App\Models\Course;
use App\Models\Attendance;
use App\Models\User;

interface AttendanceServiceInterface
{
    public function attend(User $user, Course $course): Attendance;
    public function cancel(User $user, Course $course): void;
}
```

#### サービス実装

```php
// app/Services/AttendanceService.php
<?php

namespace App\Services;

use App\Contracts\AttendanceServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Models\Course;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AttendanceService implements AttendanceServiceInterface
{
    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {}

    public function attend(User $user, Course $course): Attendance
    {
        return DB::transaction(function () use ($user, $course) {
            $this->validateAttendance($user, $course);

            $attendance = Attendance::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
            ]);

            $this->notificationService->sendAttendanceConfirmation($attendance);

            return $attendance;
        });
    }

    public function cancel(User $user, Course $course): void
    {
        // キャンセル処理
    }

    private function validateAttendance(User $user, Course $course): void
    {
        // バリデーションロジック
    }
}
```

#### コントローラー

```php
// app/Http/Controllers/Api/AttendanceController.php
<?php

namespace App\Http\Controllers\Api;

use App\Contracts\AttendanceServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceServiceInterface $attendanceService
    ) {}

    public function store(Request $request, Course $course): JsonResponse
    {
        $attendance = $this->attendanceService->attend(
            $request->user(),
            $course
        );

        return (new AttendanceResource($attendance))
            ->response()
            ->setStatusCode(201);
    }
}
```

#### サービスプロバイダーでバインド

```php
// app/Providers/AppServiceProvider.php

use App\Contracts\AttendanceServiceInterface;
use App\Services\AttendanceService;

public function register(): void
{
    $this->app->bind(
        AttendanceServiceInterface::class,
        AttendanceService::class
    );
}
```


## Step 6 テストでのモック

### DIのメリット（テスト容易性）

Lesson 12 で学んだ Pest 形式で書きます。

```php
use App\Contracts\AttendanceServiceInterface;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;

test('受講登録はサービスに処理を委譲する', function () {
    $user = User::factory()->student()->create();
    $course = Course::factory()->active()->create();

    // モックを作成（attend が1回だけ呼ばれることを期待）
    $mockService = Mockery::mock(AttendanceServiceInterface::class);
    $mockService->shouldReceive('attend')
        ->once()
        ->andReturn(Attendance::factory()->make());

    // サービスコンテナにモックをバインド
    $this->app->instance(AttendanceServiceInterface::class, $mockService);

    $this->actingAs($user)
        ->postJson("/api/courses/{$course->id}/attendances")
        ->assertCreated();
});
```

コントローラーが `new AttendanceService()` していたら、この差し替えはできません。コンストラクタで受け取っておけば、テストのときだけサービスコンテナに別のものを登録して、コントローラーに渡るものを変えられます。

Lesson 14 で使った `$this->mock()` は、上の `Mockery::mock()` でモックを作る処理と、`$this->app->instance()` でサービスコンテナに登録する処理を、まとめて行うメソッドです。上のテストは次のようにも書けます。

```php
use Mockery\MockInterface;

$this->mock(AttendanceServiceInterface::class, function (MockInterface $mock) {
    $mock->shouldReceive('attend')
        ->once()
        ->andReturn(Attendance::factory()->make());
});
```

Lesson 14 では `CreateCourse` という具象クラスを `$this->mock()` で差し替えました。サービスコンテナは、インターフェースでも具象クラスでも、登録された名前で差し替えるので、どちらでも同じように使えます。

## いつインターフェースを切るか

インターフェースは「あると綺麗」ではなく、「差し替える理由があるときに切る」ものです。判断の目安は以下の通りです。

| 状況 | 判断 |
|------|------|
| 実装が1つしかなく、増える見込みもない | インターフェース不要。具象クラスを直接注入する |
| 環境によって実装を変えたい（本番はSMTP、テストはダミー） | インターフェースを切る価値がある |
| 外部サービス（決済、SMS送信など）に依存する | インターフェースを切る。差し替え・モックがしやすくなる |
| 単にビジネスロジックをControllerから追い出したいだけ | インターフェース不要 |

本コースの `CreateCourse` や `AttendCourse` などのサービスクラス（Lesson 14）は最後のケースにあたるため、インターフェースは切りません。Step 6 で見たとおり、具象クラスのままでも `$this->mock()` で差し替えられるので、テストのためだけにインターフェースを切る必要もありません。実装を差し替える理由ができたときに、後から切っても遅くありません。

## 練習問題

> 練習問題は、手を動かしたい人向けです。問題1の解答例が使う `AttendanceConfirmation` は Lesson 17 で作るメールです。まだ作っていない場合は、メールを送る行の代わりに `Log::info('受講確認', ['attendance_id' => $attendance->id]);` と書いておけば、バインドの動きを確かめられます。ここで作ったクラスは本線のコードからは呼ばれないので、残しておいても本線の進行には影響しません。

### 問題1
`NotificationServiceInterface` とその実装 `EmailNotificationService` を作成し、AppServiceProviderでバインドしてください。

<details>
<summary>解答例</summary>

```php
// app/Contracts/NotificationServiceInterface.php
<?php

namespace App\Contracts;

use App\Models\Attendance;

interface NotificationServiceInterface
{
    public function sendAttendanceConfirmation(Attendance $attendance): void;
}
```

```php
// app/Services/EmailNotificationService.php
<?php

namespace App\Services;

use App\Contracts\NotificationServiceInterface;
use App\Mail\AttendanceConfirmation;
use App\Models\Attendance;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService implements NotificationServiceInterface
{
    public function sendAttendanceConfirmation(Attendance $attendance): void
    {
        Mail::to($attendance->user)->send(new AttendanceConfirmation($attendance));
    }
}
```

```php
// app/Providers/AppServiceProvider.php
use App\Contracts\NotificationServiceInterface;
use App\Services\EmailNotificationService;

public function register(): void
{
    $this->app->bind(
        NotificationServiceInterface::class,
        EmailNotificationService::class
    );
}
```
</details>

### 問題2
テスト環境では通知を送信しない `FakeNotificationService` を作成し、テスト時はこちらが使われるように設定してください。

<details>
<summary>解答例</summary>

```php
// app/Services/FakeNotificationService.php
<?php

namespace App\Services;

use App\Contracts\NotificationServiceInterface;
use App\Models\Attendance;

class FakeNotificationService implements NotificationServiceInterface
{
    public function sendAttendanceConfirmation(Attendance $attendance): void
    {
        // 何もしない
    }
}
```

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    if (app()->environment('testing')) {
        $this->app->bind(
            NotificationServiceInterface::class,
            FakeNotificationService::class
        );
    } else {
        $this->app->bind(
            NotificationServiceInterface::class,
            EmailNotificationService::class
        );
    }
}
```
</details>

## 参考資料

- [Laravel 公式ドキュメント - Service Container](https://laravel.com/docs/container)
- [Laravel 公式ドキュメント - Service Providers](https://laravel.com/docs/providers)

## 次のレッスン

本線のレッスンに戻りましょう。[レッスン一覧](./README.md) から、読む前に進めていたレッスンの続きを選んでください。
