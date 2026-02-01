# Lesson 17 自動テストの書き方

## 学習目標

このレッスンでは、PHPUnit/Pestを使った自動テストを書き、品質を担保できるようになります。

### 到達目標
- Feature テストと Unit テストの違いを理解する
- APIのテストを書ける
- Factory を使ったテストデータ作成ができる
- モック/スタブを活用できる


## なぜテストを書くか？

### テストのメリット

1. バグの早期発見として、コード変更時に既存機能が壊れていないか確認できます
2. リファクタリングの安心感として、テストがあれば大胆に書き換えられます
3. ドキュメント代わりとして、テストコードが仕様書になります
4. 設計の改善として、テストしづらいコード = 設計が悪いことがわかります


## Step 1 テストの種類

### Feature テスト（機能テスト）

HTTPリクエストからレスポンスまでの一連の流れをテストします。

```php
// tests/Feature/CourseControllerTest.php
public function test_can_get_course_list(): void
{
    // Arrange: テストデータ準備
    Course::factory()->count(3)->create();

    // Act: APIを呼び出す
    $response = $this->getJson('/api/courses');

    // Assert: レスポンスを検証
    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
}
```

### Unit テスト（単体テスト）

クラスやメソッド単位でテストします。

```php
// tests/Unit/CourseServiceTest.php
public function test_calculate_available_seats(): void
{
    $course = Course::factory()->make(['capacity' => 20]);
    $course->setRelation('attendances', collect(range(1, 15)));

    $service = new CourseService();

    $this->assertEquals(5, $service->getAvailableSeats($course));
}
```

### 使い分け

| テスト種類 | 対象 | 特徴 |
|-----------|------|------|
| Feature | API、画面遷移 | 統合的、遅い、実際の動作を確認 |
| Unit | サービス、モデル | 単体、速い、ロジックを確認 |


## Step 2 テストの書き方

### Pest の基本構文

このプロジェクトでは Pest を使用しています。

```php
// tests/Feature/CourseTest.php

use App\Models\Course;
use App\Models\User;

test('講座一覧を取得できる', function () {
    Course::factory()->count(3)->create();

    $response = $this->getJson('/api/courses');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

test('認証なしで講座を作成できない', function () {
    $response = $this->postJson('/api/courses', [
        'title' => 'テスト講座',
        'capacity' => 20,
    ]);

    $response->assertStatus(401);
});
```

### PHPUnit スタイル

```php
// tests/Feature/CourseControllerTest.php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_get_course_list(): void
    {
        Course::factory()->count(3)->create();

        $response = $this->getJson('/api/courses');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }
}
```


## Step 3 テストデータの準備

### Factory の基本

```php
// 1件作成（DBに保存）
$course = Course::factory()->create();

// 複数件作成
$courses = Course::factory()->count(5)->create();

// 特定の値を指定
$course = Course::factory()->create([
    'title' => '特定のタイトル',
    'capacity' => 30,
]);

// DBに保存しない（メモリ上のみ）
$course = Course::factory()->make();
```

### Factory の状態（State）

```php
// database/factories/CourseFactory.php

public function active(): static
{
    return $this->state(fn (array $attributes) => [
        'status' => CourseStatus::Active,
    ]);
}

public function full(): static
{
    return $this->state(fn (array $attributes) => [
        'capacity' => 0,
    ]);
}
```

使用方法は以下の通りです。

```php
// 公開中の講座
Course::factory()->active()->create();

// 定員いっぱいの講座
Course::factory()->full()->create();

// 組み合わせ
Course::factory()->active()->full()->create();
```

### リレーションを持つデータ

```php
// 講師と一緒に作成
$course = Course::factory()
    ->for(User::factory()->instructor(), 'instructor')
    ->create();

// 受講者を持つ講座
$course = Course::factory()
    ->has(Attendance::factory()->count(5))
    ->create();
```


## Step 4 認証のテスト

### actingAs() でログイン状態をシミュレート

```php
test('講師は講座を作成できる', function () {
    $instructor = User::factory()->instructor()->create();

    $response = $this->actingAs($instructor)
        ->postJson('/api/courses', [
            'title' => 'テスト講座',
            'capacity' => 20,
        ]);

    $response->assertStatus(201);
});

test('生徒は講座を作成できない', function () {
    $student = User::factory()->student()->create();

    $response = $this->actingAs($student)
        ->postJson('/api/courses', [
            'title' => 'テスト講座',
            'capacity' => 20,
        ]);

    $response->assertStatus(403);
});
```


## Step 5 レスポンスのアサーション

### ステータスコード

```php
$response->assertStatus(200);
$response->assertOk();           // 200
$response->assertCreated();      // 201
$response->assertNoContent();    // 204
$response->assertNotFound();     // 404
$response->assertForbidden();    // 403
$response->assertUnauthorized(); // 401
```

### JSON構造

```php
$response->assertJson([
    'data' => [
        'title' => 'テスト講座',
    ],
]);

$response->assertJsonStructure([
    'data' => [
        'id',
        'title',
        'instructor' => ['id', 'name'],
    ],
]);

$response->assertJsonCount(3, 'data');
$response->assertJsonPath('data.0.title', 'テスト講座');
```

### バリデーションエラー

```php
$response->assertUnprocessable()  // 422
    ->assertJsonValidationErrors(['title', 'capacity']);

$response->assertJsonMissingValidationErrors(['description']);
```


## Step 6 データベースのアサーション

### データの存在確認

```php
// テーブルにデータが存在することを確認
$this->assertDatabaseHas('courses', [
    'title' => 'テスト講座',
    'instructor_id' => $instructor->id,
]);

// データが存在しないことを確認
$this->assertDatabaseMissing('courses', [
    'title' => '削除された講座',
]);

// レコード数を確認
$this->assertDatabaseCount('courses', 3);
```

### ソフトデリート

```php
$this->assertSoftDeleted('courses', [
    'id' => $course->id,
]);
```


## Step 7 モックの活用

### サービスをモック

```php
use App\Services\NotificationService;
use Mockery;

test('講座作成時に通知が送信される', function () {
    $mockNotification = Mockery::mock(NotificationService::class);
    $mockNotification->shouldReceive('notifyAdminsOfNewCourse')
        ->once();

    $this->app->instance(NotificationService::class, $mockNotification);

    $instructor = User::factory()->instructor()->create();

    $this->actingAs($instructor)
        ->postJson('/api/courses', [
            'title' => 'テスト講座',
            'capacity' => 20,
        ])
        ->assertCreated();
});
```

### Mail のフェイク

```php
use Illuminate\Support\Facades\Mail;
use App\Mail\AttendanceConfirmation;

test('受講時にメールが送信される', function () {
    Mail::fake();

    $user = User::factory()->student()->create();
    $course = Course::factory()->active()->create();

    $this->actingAs($user)
        ->postJson("/api/courses/{$course->id}/attend")
        ->assertCreated();

    Mail::assertSent(AttendanceConfirmation::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});
```

### Queue のフェイク

```php
use Illuminate\Support\Facades\Queue;
use App\Jobs\SendAttendanceNotification;

test('受講時にジョブがキューに追加される', function () {
    Queue::fake();

    // ... テスト処理 ...

    Queue::assertPushed(SendAttendanceNotification::class);
});
```


## Step 8 実践的なテスト例

### CourseController のテスト

```php
// tests/Feature/Api/CourseControllerTest.php

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;

describe('GET /api/courses', function () {
    test('講座一覧を取得できる', function () {
        Course::factory()->count(3)->create();

        $response = $this->getJson('/api/courses');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'instructor'],
                ],
                'meta' => ['current_page', 'total'],
            ]);
    });

    test('ステータスでフィルタリングできる', function () {
        Course::factory()->active()->count(2)->create();
        Course::factory()->create(['status' => CourseStatus::Draft]);

        $response = $this->getJson('/api/courses?status=active');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });
});

describe('POST /api/courses', function () {
    test('講師は講座を作成できる', function () {
        $instructor = User::factory()->instructor()->create();

        $response = $this->actingAs($instructor)
            ->postJson('/api/courses', [
                'title' => 'Laravel入門',
                'description' => '初心者向け',
                'capacity' => 20,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Laravel入門');

        $this->assertDatabaseHas('courses', [
            'title' => 'Laravel入門',
            'instructor_id' => $instructor->id,
        ]);
    });

    test('タイトルは必須', function () {
        $instructor = User::factory()->instructor()->create();

        $response = $this->actingAs($instructor)
            ->postJson('/api/courses', [
                'capacity' => 20,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    });

    test('未認証ユーザーは作成できない', function () {
        $this->postJson('/api/courses', [
            'title' => 'テスト',
        ])->assertUnauthorized();
    });
});
```


## Step 9 テストの実行

### 全テスト実行

```bash
php artisan test
```

### 特定のファイルのみ

```bash
php artisan test tests/Feature/Api/CourseControllerTest.php
```

### 特定のテストのみ

```bash
php artisan test --filter="講師は講座を作成できる"
```

### カバレッジ

```bash
php artisan test --coverage
```

## 練習問題

### 問題1
受講登録APIのテストを書いてください。以下のケースをカバーしてください。
- 生徒は受講登録できる
- 定員オーバー時は登録できない
- 既に登録済みの場合はエラー


## 参考資料

- [Laravel 公式ドキュメント - Testing](https://laravel.com/docs/testing)
- [Laravel 公式ドキュメント - HTTP Tests](https://laravel.com/docs/http-tests)
- [Laravel 公式ドキュメント - Database Testing](https://laravel.com/docs/database-testing)
- [Pest 公式ドキュメント](https://pestphp.com/docs/installation)

## 次のレッスン

[Lesson 18 メールとジョブ機能](./18-mail-job.md) では、メール送信とキュー処理を実装し、非同期処理の基本を学びます。
