# Lesson 15 サービスコンテナとDI

## 学習目標

このレッスンでは、Laravelのサービスコンテナの仕組みを理解し、依存性注入（DI）を活用できるようになります。

### 到達目標
- サービスコンテナとは何かを説明できる
- 依存性注入（DI）のメリットを理解する
- コンストラクタインジェクションを使える
- インターフェースとバインディングを設定できる


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

### Laravelの「依存性解決マシン」

サービスコンテナは、クラスのインスタンス化と依存関係の解決を自動で行います。

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


## Step 5 実践 - AttendanceService のリファクタリング

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
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceServiceInterface $attendanceService
    ) {}

    public function store(Request $request, Course $course)
    {
        $attendance = $this->attendanceService->attend(
            $request->user(),
            $course
        );

        return new AttendanceResource($attendance);
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

```php
use App\Contracts\AttendanceServiceInterface;
use Mockery;

class AttendanceControllerTest extends TestCase
{
    public function test_attendance_success(): void
    {
        // モックを作成
        $mockService = Mockery::mock(AttendanceServiceInterface::class);
        $mockService->shouldReceive('attend')
            ->once()
            ->andReturn(Attendance::factory()->make());

        // サービスコンテナにモックをバインド
        $this->app->instance(AttendanceServiceInterface::class, $mockService);

        // テスト実行
        $response = $this->actingAs($user)
            ->postJson("/api/courses/{$course->id}/attend");

        $response->assertStatus(201);
    }
}
```

## 練習問題

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

[Lesson 16 サービスクラスの設計](./16-service-class.md) では、ビジネスロジックをサービスクラスに分離する設計を学びます。
