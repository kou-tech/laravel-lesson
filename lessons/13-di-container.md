# Lesson 13: サービスコンテナとDI

## 学習目標

このレッスンでは、Laravelのサービスコンテナの仕組みを理解し、依存性注入（DI）を活用できるようになります。

### 到達目標
- サービスコンテナとは何かを説明できる
- 依存性注入（DI）のメリットを理解する
- コンストラクタインジェクションを使える
- インターフェースとバインディングを設定できる

---

## 依存性とは？

### あるクラスが別のクラスを「使う」こと

```php
class EnrollmentController extends Controller
{
    public function store(Request $request, Course $course)
    {
        // EnrollmentService に「依存」している
        $service = new EnrollmentService();
        $enrollment = $service->enroll($request->user(), $course);

        return new EnrollmentResource($enrollment);
    }
}
```

**問題点**:
- `EnrollmentService` を直接 new している
- テスト時にモックに差し替えられない
- `EnrollmentService` のコンストラクタが変わると、このコードも変更が必要

---

## 依存性注入（DI）とは？

### 依存するオブジェクトを「外から渡す」

```php
class EnrollmentController extends Controller
{
    // コンストラクタで受け取る（注入される）
    public function __construct(
        private EnrollmentService $enrollmentService
    ) {}

    public function store(Request $request, Course $course)
    {
        // 注入されたサービスを使う
        $enrollment = $this->enrollmentService->enroll($request->user(), $course);

        return new EnrollmentResource($enrollment);
    }
}
```

**メリット**:
- クラス間の結合度が下がる
- テスト時にモックを注入できる
- 依存関係が明確になる

---

## Step 1: サービスコンテナとは？

### Laravelの「依存性解決マシン」

サービスコンテナは、クラスのインスタンス化と依存関係の解決を自動で行います。

```php
// サービスコンテナに「EnrollmentService が欲しい」と伝える
$service = app(EnrollmentService::class);

// コントローラーのコンストラクタに型宣言すると自動で注入
public function __construct(
    private EnrollmentService $enrollmentService
) {}
```

### 自動解決の仕組み

```php
class EnrollmentService
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

`EnrollmentService` を要求すると:

1. `EnrollmentService` のコンストラクタを解析
2. `NotificationService` が必要 → 再帰的に解決
3. `NotificationService` のコンストラクタを解析
4. `MailService` が必要 → 再帰的に解決
5. 全ての依存を解決してインスタンス化

---

## Step 2: コンストラクタインジェクション

### コントローラーでの使用

```php
class CourseController extends Controller
{
    public function __construct(
        private CourseService $courseService,
        private NotificationService $notificationService
    ) {}

    public function store(StoreCourseRequest $request)
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

メソッドの引数でも依存性を受け取れます:

```php
public function store(
    StoreCourseRequest $request,
    CourseService $courseService  // メソッドで注入
)
{
    $course = $courseService->create(...);
}
```

---

## Step 3: バインディング

### 基本的なバインド

`app/Providers/AppServiceProvider.php`:

```php
use App\Services\EnrollmentService;

public function register(): void
{
    $this->app->bind(EnrollmentService::class, function ($app) {
        return new EnrollmentService(
            $app->make(NotificationService::class),
            config('enrollment.max_capacity')
        );
    });
}
```

### シングルトン

アプリケーション全体で1つのインスタンスを共有:

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

---

## Step 4: インターフェースへのバインド

### なぜインターフェースを使うか？

具象クラスに直接依存すると、実装を差し替えられません。

```php
// ❌ 具象クラスに依存
class EnrollmentService
{
    public function __construct(
        private SmtpMailer $mailer  // SMTPに固定
    ) {}
}

// ✅ インターフェースに依存
class EnrollmentService
{
    public function __construct(
        private MailerInterface $mailer  // 実装は後から決める
    ) {}
}
```

### インターフェースの定義

`app/Contracts/MailerInterface.php`:

```php
<?php

namespace App\Contracts;

interface MailerInterface
{
    public function send(string $to, string $subject, string $body): void;
}
```

### 実装クラス

`app/Services/SmtpMailer.php`:

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

---

## Step 5: 実践 - EnrollmentService のリファクタリング

### Before: 密結合

```php
class EnrollmentController extends Controller
{
    public function store(Request $request, Course $course)
    {
        $user = $request->user();

        // ビジネスロジックがコントローラーに
        if (!$course->hasCapacity()) {
            throw new CapacityExceededException();
        }

        $enrollment = Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);

        // 直接メール送信
        Mail::to($user)->send(new EnrollmentConfirmation($enrollment));

        return new EnrollmentResource($enrollment);
    }
}
```

### After: DIを活用

#### インターフェース

```php
// app/Contracts/EnrollmentServiceInterface.php
<?php

namespace App\Contracts;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

interface EnrollmentServiceInterface
{
    public function enroll(User $user, Course $course): Enrollment;
    public function cancel(User $user, Course $course): void;
}
```

#### サービス実装

```php
// app/Services/EnrollmentService.php
<?php

namespace App\Services;

use App\Contracts\EnrollmentServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EnrollmentService implements EnrollmentServiceInterface
{
    public function __construct(
        private NotificationServiceInterface $notificationService
    ) {}

    public function enroll(User $user, Course $course): Enrollment
    {
        return DB::transaction(function () use ($user, $course) {
            $this->validateEnrollment($user, $course);

            $enrollment = Enrollment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
            ]);

            $this->notificationService->sendEnrollmentConfirmation($enrollment);

            return $enrollment;
        });
    }

    public function cancel(User $user, Course $course): void
    {
        // キャンセル処理
    }

    private function validateEnrollment(User $user, Course $course): void
    {
        // バリデーションロジック
    }
}
```

#### コントローラー

```php
// app/Http/Controllers/Api/EnrollmentController.php
<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EnrollmentServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\EnrollmentResource;
use App\Models\Course;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function __construct(
        private EnrollmentServiceInterface $enrollmentService
    ) {}

    public function store(Request $request, Course $course)
    {
        $enrollment = $this->enrollmentService->enroll(
            $request->user(),
            $course
        );

        return new EnrollmentResource($enrollment);
    }
}
```

#### サービスプロバイダーでバインド

```php
// app/Providers/AppServiceProvider.php

use App\Contracts\EnrollmentServiceInterface;
use App\Services\EnrollmentService;

public function register(): void
{
    $this->app->bind(
        EnrollmentServiceInterface::class,
        EnrollmentService::class
    );
}
```

---

## Step 6: テストでのモック

### DIのメリット: テスト容易性

```php
use App\Contracts\EnrollmentServiceInterface;
use Mockery;

class EnrollmentControllerTest extends TestCase
{
    public function test_enrollment_success(): void
    {
        // モックを作成
        $mockService = Mockery::mock(EnrollmentServiceInterface::class);
        $mockService->shouldReceive('enroll')
            ->once()
            ->andReturn(Enrollment::factory()->make());

        // サービスコンテナにモックをバインド
        $this->app->instance(EnrollmentServiceInterface::class, $mockService);

        // テスト実行
        $response = $this->actingAs($user)
            ->postJson("/api/courses/{$course->id}/enroll");

        $response->assertStatus(201);
    }
}
```

---

## まとめ

このレッスンで学んだこと：

1. **依存性注入（DI）**
   - 依存オブジェクトを外から渡す
   - 結合度を下げる

2. **サービスコンテナ**
   - Laravelの依存解決エンジン
   - 自動的に依存を解決

3. **バインディング**
   - `bind`: 毎回新規インスタンス
   - `singleton`: 共有インスタンス

4. **インターフェース**
   - 実装から切り離す
   - 環境ごとに差し替え可能

5. **テスト容易性**
   - モックを簡単に注入
   - 単体テストが書きやすい

---

## 練習問題

### 問題1
`NotificationServiceInterface` とその実装 `EmailNotificationService` を作成し、AppServiceProviderでバインドしてください。

<details>
<summary>解答例</summary>

```php
// app/Contracts/NotificationServiceInterface.php
interface NotificationServiceInterface
{
    public function sendEnrollmentConfirmation(Enrollment $enrollment): void;
}

// app/Services/EmailNotificationService.php
class EmailNotificationService implements NotificationServiceInterface
{
    public function sendEnrollmentConfirmation(Enrollment $enrollment): void
    {
        Mail::to($enrollment->user)->send(
            new EnrollmentConfirmation($enrollment)
        );
    }
}

// app/Providers/AppServiceProvider.php
$this->app->bind(
    NotificationServiceInterface::class,
    EmailNotificationService::class
);
```
</details>

### 問題2
テスト環境では通知を送信しない `FakeNotificationService` を作成し、テスト時はこちらが使われるように設定してください。

<details>
<summary>解答例</summary>

```php
// app/Services/FakeNotificationService.php
class FakeNotificationService implements NotificationServiceInterface
{
    public function sendEnrollmentConfirmation(Enrollment $enrollment): void
    {
        // 何もしない（ログに記録するだけでも可）
        Log::debug('Fake notification sent', ['enrollment_id' => $enrollment->id]);
    }
}

// app/Providers/AppServiceProvider.php
public function register(): void
{
    $implementation = app()->environment('testing')
        ? FakeNotificationService::class
        : EmailNotificationService::class;

    $this->app->bind(NotificationServiceInterface::class, $implementation);
}
```
</details>

---

## 次のレッスン

[Lesson 14: サービスクラスの設計](./14-service-class.md) では、ビジネスロジックをサービスクラスに分離する設計を学びます。
