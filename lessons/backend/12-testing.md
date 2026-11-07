# Lesson 12 自動テストの書き方

## 学習目標

このレッスンでは、Pest を使って自動テストを書き、これまで作ったAPIの動きをテストで確かめられるようになります。

### 到達目標
- Feature テストと Unit テストの違いを理解する
- APIのテストを書ける
- Factory を使ったテストデータ作成ができる
- 受講登録APIの成功・失敗の動きをテストで固定できる


## なぜテストを書くか？

### テストのメリット

1. バグの早期発見として、コード変更時に既存機能が壊れていないか確認できます
2. リファクタリングの安心感として、テストがあれば大胆に書き換えられます
3. ドキュメント代わりとして、テストコードが仕様書になります
4. 設計の改善として、テストしづらいコード = 設計が悪いことがわかります

### このあとのレッスンとの関係

Lesson 13〜15 は、動きを変えずにコードを良くするレッスンです。とくに Lesson 10 で作った受講登録APIは、Lesson 14 でサービスクラスに切り出し、Lesson 15 でトランザクションを足します。実務では、こうした書き換えの前に今の動きをテストで固定しておき、テストが通るままであることを確かめながら手を入れます。

このレッスンでは、前半でテストの書き方を学び、後半の Step 8・9 で Course API と受講登録APIのテストを書きます。ここで書くテストは、Lesson 14 以降でコードを書き換えるたびに実行します。


## Step 0 準備 - RefreshDatabase を有効にする

テストは「毎回まっさらなDBで実行する」のが原則です。開発用のデータが残っていると、`assertJsonCount(3, 'data')` のような件数チェックが、シーダーで入れたデータのせいで落ちてしまいます。

`tests/Pest.php` の以下の行のコメントを外してください。

```php
pest()->extend(TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)  // ← コメントを外す
    ->in('Feature');
```

これで `tests/Feature` 配下のテストは、1テストごとにマイグレーション済みの空のDBで実行され、終わると自動でロールバックされます。開発用のDBのデータが消えることはありません。

> `->in('Feature')` は「Feature ディレクトリ配下のテストに適用する」という意味です。`tests/Unit` にはDBを触らないテストを置くため、対象外にしています。


## Step 1 テストの種類

### Feature テスト（機能テスト）

HTTPリクエストからレスポンスまでの一連の流れをテストします。

```php
// tests/Feature/Api/CourseTest.php
test('講座一覧を取得できる', function () {
    // Arrange: テストデータ準備
    Course::factory()->count(3)->create();

    // Act: APIを呼び出す
    $response = $this->getJson('/api/courses');

    // Assert: レスポンスを検証
    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});
```

### Unit テスト（単体テスト）

クラスやメソッド単位でテストします。

`tests/Unit/CourseStatusTest.php`

```php
<?php

use App\Enums\CourseStatus;

test('ステータスに対応する日本語ラベルを返す', function () {
    expect(CourseStatus::Active->label())->toBe('公開中');
    expect(CourseStatus::Draft->label())->toBe('下書き');
});
```

> `tests/Unit` では Laravel のアプリケーションが起動していません。
> `tests/Pest.php` で `Tests\TestCase` を束ねているのは `->in('Feature')` の行だけだからです。そのため `tests/Unit` に置けるのは、Enum やプレーンなクラスのように、フレームワークに依存しないコードのテストに限られます。
>
> よくある失敗は、Unit テストでモデルの Factory を使ってしまうことです。`Course::factory()` はサービスコンテナを必要とするため `Target class [config] does not exist.` になりますし、`CourseFactory` は `'instructor_id' => User::factory()` を持っているので、保存しない `make()` でも講師を作るDBアクセスが発生します。モデルの Factory を使うテストは `tests/Feature` に置いてください。
>
> どうしても `tests/Unit` でモデルを扱いたい場合は、そのファイルの先頭に以下を書けばコンテナとDBが使えますが、実質的に Feature テストと同じ重さになります。
>
> ```php
> uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);
> ```

### 使い分け

| テスト種類 | 対象 | 特徴 |
|-----------|------|------|
| Feature | API、画面遷移 | 統合的、遅い、実際の動作を確認 |
| Unit | サービス、モデル | 単体、速い、ロジックを確認 |


## Step 2 テストの書き方

### Pest の基本構文

このプロジェクトでは Pest を使用しています。

`tests/Feature/CourseTest.php`

```php
<?php

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

### 参考: PHPUnit スタイル

Pest は内部的に PHPUnit の上で動いており、従来のクラス形式でも書けます。既存プロジェクトではこちらを見かけることも多いので、読めるようにしておいてください。

```php
// tests/Feature/CourseControllerTest.php

namespace Tests\Feature;

use App\Models\Course;
use Tests\TestCase;

class CourseControllerTest extends TestCase
{
    public function test_can_get_course_list(): void
    {
        Course::factory()->count(3)->create();

        $response = $this->getJson('/api/courses');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }
}
```

> 本コースで書くテストはすべて Pest 形式に統一します。`php artisan make:test` もPest形式のファイルを生成します。Lesson 13 以降のサンプルもPest形式です。


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

これまでのレッスンで、次の state を作ってきました。

| state | 作ったレッスン | 作られるデータ |
|---|---|---|
| `User::factory()->student()` | Lesson 7 | 生徒のユーザー |
| `User::factory()->instructor()` | Lesson 7 | 講師のユーザー |
| `Course::factory()->active()` / `draft()` | Lesson 9 | 公開中 / 下書きの講座 |
| `Attendance::factory()->cancelled()` | Lesson 10 | キャンセル済みの受講 |

state は自分で足すこともできます。たとえば `CourseFactory` に、定員0の講座を作る `full()` を足すと次のようになります。

```php
// database/factories/CourseFactory.php

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

// 定員0の講座
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

// 受講者を5人持つ講座（Lesson 10 で足した Course::attendances() を使う）
$course = Course::factory()
    ->has(Attendance::factory()->count(5))
    ->create();

// 特定の講座に、キャンセル済みの受講を1件作る
Attendance::factory()->cancelled()->create([
    'course_id' => $course->id,
]);
```


## Step 4 認証と認可のテスト

### actingAs() でログイン状態をシミュレート

```php
test('講師は講座を作成できる', function () {
    $instructor = User::factory()->instructor()->create();

    $response = $this->actingAs($instructor)
        ->postJson('/api/courses', [
            'title' => 'テスト講座',
            'capacity' => 20,
            'status' => 'draft',
            'starts_at' => now()->addWeek()->toDateTimeString(),
        ]);

    $response->assertStatus(201);
});

test('生徒は講座を作成できない', function () {
    $student = User::factory()->student()->create();

    $response = $this->actingAs($student)
        ->postJson('/api/courses', [
            'title' => 'テスト講座',
            'capacity' => 20,
            'status' => 'draft',
            'starts_at' => now()->addWeek()->toDateTimeString(),
        ]);

    $response->assertStatus(403);
});
```

> リクエストボディに `status` と `starts_at` を入れているのは、`Course/StoreRequest` で必須にしているためです（Lesson 9・10）。「バリデーションは認可より先に走る」ので、ここを省くと「403を期待していたのに422が返る」というテスト失敗になります。認可のテストをするときは、「バリデーションを通過する正しいボディ」を送るのがコツです。

### 他人のデータを変更できないことを確かめる

Lesson 7 で `UserPolicy` を使って保護したユーザー更新API（`PATCH /api/users/{user}`）も、同じ書き方でテストできます。自分は更新でき、他人は更新できないことを、2つのテストで確かめます。

```php
test('自分のユーザー情報は更新できる', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->patchJson("/api/users/{$user->id}", [
            'name' => '新しい名前',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', '新しい名前');
});

test('他人のユーザー情報は更新できない', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['name' => '元の名前']);

    $response = $this->actingAs($user)
        ->patchJson("/api/users/{$other->id}", [
            'name' => '書き換えた名前',
        ]);

    $response->assertForbidden();

    $this->assertDatabaseHas('users', [
        'id' => $other->id,
        'name' => '元の名前',
    ]);
});
```

403 が返ったことに加えて、DBの値が変わっていないことも確かめています。認可のチェックをうっかり保存の後ろに書いてしまうと、403 は返るのにデータは書き換わっている、という不具合になるためです。


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

### モデル単位の確認

手元にあるモデルの行が、DBに残っているか消えたかを確かめるときは、モデルを渡すアサーションが便利です。削除APIのテストでよく使います。

```php
// $course の行がDBから消えている
$this->assertModelMissing($course);

// $course の行がDBに残っている
$this->assertModelExists($course);
```

> 補足: `SoftDeletes` トレイトを使うモデル（論理削除）では、削除しても行は消えず、`deleted_at` に日時が入ります。そのようなモデルでは `$this->assertSoftDeleted($course)` で「論理削除されたこと」を確かめます。このプロジェクトの Course と Attendance は `SoftDeletes` を使っていないので、`assertModelMissing` で確かめます。


## Step 7 テストの実行

全テストの実行は `make test` で行えます。特定のテストだけ実行したい場合は、`make app` でコンテナに入ってから実行してください。

### 全テスト実行

```bash
make test
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

カバレッジは、テストを実行したときに、アプリのコードの何行が実際に通ったかの割合です。どこがテストされていないかを探すのに使います。

```bash
php artisan test --coverage
```

このプロジェクトのコンテナには、カバレッジを計測する PHP の拡張機能 PCOV が入っているので、`--coverage` をそのまま使えます。

> 補足: 「No code coverage driver is available.」と表示された場合は、PCOV が入る前のコンテナのイメージを使っています。README の案内に従ってイメージを作り直してから、もう一度実行してください。


## Step 8 Course API のテストを書く

ここからは、これまでに作ったAPIのテストを実際にファイルに書きます。まず Lesson 9 の Course API です。コンテナの中で次のコマンドを実行してファイルを作り、中身を下のコードに置き換えます。

```bash
php artisan make:test Api/CourseControllerTest
```

`tests/Feature/Api/CourseControllerTest.php`

```php
<?php

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
        Course::factory()->draft()->create();

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
                'status' => 'draft',
                'starts_at' => now()->addWeek()->toDateTimeString(),
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

`describe()` は、関係するテストをまとめる Pest の書き方です。実行結果にも `GET /api/courses` のようなまとまりで表示されるので、どのAPIのテストが落ちたのかが読みやすくなります。

書けたら、Step 7 の手順でこのファイルだけを実行し、すべて緑（PASS）になることを確かめてください。

```bash
php artisan test tests/Feature/Api/CourseControllerTest.php
```


## Step 9 受講登録APIのテストを書く

次に、Lesson 10 で作った受講登録API（`POST /api/courses/{course}/attendances`）のテストを書きます。このAPIは Lesson 14 でサービスクラスに切り出し、Lesson 15 でトランザクションを足します。そのたびにこのテストを実行し、次の4つの動きが変わっていないことを確かめます。

| 状況 | ステータス | message |
|---|---|---|
| 生徒が申し込む | 201 | （AttendanceResource） |
| 生徒以外が申し込む | 403 | Policy の既定のメッセージ |
| 定員に達している | 422 | この講座は定員に達しています。 |
| すでに登録している | 409 | すでにこの講座に登録済みです。 |

```bash
php artisan make:test Api/AttendanceStoreTest
```

`tests/Feature/Api/AttendanceStoreTest.php`

```php
<?php

use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;

describe('POST /api/courses/{course}/attendances', function () {
    test('生徒は受講登録できる', function () {
        $student = User::factory()->student()->create();
        $course = Course::factory()->active()->create(['capacity' => 20]);

        $response = $this->actingAs($student)
            ->postJson("/api/courses/{$course->id}/attendances");

        $response->assertCreated()
            ->assertJsonPath('data.user.id', $student->id)
            ->assertJsonPath('data.course.id', $course->id)
            ->assertJsonPath('data.course.instructor.id', $course->instructor_id)
            ->assertJsonPath('data.status', 'attending');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'attending',
        ]);
    });

    test('生徒以外は受講登録できない', function () {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->active()->create(['capacity' => 20]);

        $response = $this->actingAs($instructor)
            ->postJson("/api/courses/{$course->id}/attendances");

        $response->assertForbidden();

        $this->assertDatabaseCount('attendances', 0);
    });

    test('定員に達している講座には登録できない', function () {
        $student = User::factory()->student()->create();
        $course = Course::factory()->active()->create(['capacity' => 1]);

        // ほかの生徒が1人登録して、定員を埋めておく
        Attendance::factory()->create(['course_id' => $course->id]);

        $response = $this->actingAs($student)
            ->postJson("/api/courses/{$course->id}/attendances");

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'この講座は定員に達しています。');

        $this->assertDatabaseMissing('attendances', [
            'user_id' => $student->id,
        ]);
    });

    test('同じ講座に2回申し込むと 409 になる', function () {
        $student = User::factory()->student()->create();
        $course = Course::factory()->active()->create(['capacity' => 20]);

        Attendance::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);

        $response = $this->actingAs($student)
            ->postJson("/api/courses/{$course->id}/attendances");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'すでにこの講座に登録済みです。');

        $this->assertDatabaseCount('attendances', 1);
    });
});
```

どのテストも、ステータスコードに加えて、DBに受講の行ができたか、できなかったかを確かめています。失敗したときに行が増えていないことまで確かめておけば、処理の順番を入れ替える書き換えで「エラーを返したのに行だけ残る」不具合が出ても気付けます。

確かめる中身は、変わらないものだけに絞っています。

- 403 の message は確かめません。Policy の既定のメッセージ（`This action is unauthorized.`）は Laravel が決めている文言で、受講登録の仕様として決めたものではないためです
- Lesson 14 でサービスクラスに切り出すと、422 と 409 のレスポンスに `error_code` が加わります。今の受講登録APIはまだ `error_code` を返さないので、このテストでは確かめません

書けたら実行して、4つとも緑になることを確かめてください。

```bash
php artisan test tests/Feature/Api/AttendanceStoreTest.php
```

> 補足: テストが本当に動きを守っているかは、わざとコードを壊して試すと分かります。たとえば `Course::hasCapacity()` の `<` を `<=` に書き換えて実行すると、定員の人数より1人多く申し込めるようになり、「定員に達している講座には登録できない」が落ちます。落ちることを確かめたら、`<` に戻しておいてください。


## フェイクとモックについて

テストでは、メールの送信や別のクラスの処理を、本物の代わりに偽物へ差し替えることがあります。Laravel には、そのための道具が2種類あります。

1つ目はフェイクです。`Mail::fake()` や `Queue::fake()` のように呼ぶと、メールやジョブを実際には送らずに「送ろうとしたか」だけを記録する状態に切り替わります。使い方は、Lesson 17 でメールとジョブを作るときに学びます。

2つ目はモックです。Laravel には Mockery というライブラリが入っていて、テストの中で `$this->mock(クラス名::class, ...)` と書くと、そのクラスの偽物を作り、本物の代わりにコントローラーへ渡されるように登録できます。差し替える対象になるサービスクラスは Lesson 14 で作るので、モックを使ったテストも Lesson 14 で書きます。


## 練習問題

### 問題1
受講登録APIで、キャンセル済みの受講は定員に数えないことを確かめるテストを、`tests/Feature/Api/AttendanceStoreTest.php` に追加してください。定員1の講座にキャンセル済みの受講が1件だけあるとき、生徒が申し込むと 201 になることを確かめます。

> ヒント: Step 3 で紹介した `Attendance::factory()->cancelled()` を使います。

<details>
<summary>解答例</summary>

`describe()` の中に、次のテストを足します。

```php
test('キャンセル済みの受講は定員に数えない', function () {
    $student = User::factory()->student()->create();
    $course = Course::factory()->active()->create(['capacity' => 1]);

    // ほかの生徒が申し込んだあとキャンセルした受講
    Attendance::factory()->cancelled()->create(['course_id' => $course->id]);

    $response = $this->actingAs($student)
        ->postJson("/api/courses/{$course->id}/attendances");

    $response->assertCreated();

    $this->assertDatabaseHas('attendances', [
        'user_id' => $student->id,
        'course_id' => $course->id,
        'status' => 'attending',
    ]);
});
```

`hasCapacity()` はこのあとのレッスンでも書き換えます。そのときに「受講中だけを数える」条件をうっかり落とすと、このテストが落ちて気付けます。
</details>

### 問題2
Course API の削除（`DELETE /api/courses/{course}`）のテストを、`tests/Feature/Api/CourseControllerTest.php` に追加してください。次の2つを確かめます。

- 担当講師は講座を削除でき、204 が返って講座がDBから消える
- ほかの講師が削除しようとすると 403 が返り、講座はDBに残る

受講者のいる講座を削除したときの動きは Lesson 14 で決めるので、ここでは受講者のいない講座で確かめます。

> ヒント: Step 6 の `assertModelMissing` と `assertModelExists` を使います。

<details>
<summary>解答例</summary>

```php
describe('DELETE /api/courses/{course}', function () {
    test('担当講師は講座を削除できる', function () {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->for($instructor, 'instructor')->create();

        $response = $this->actingAs($instructor)
            ->deleteJson("/api/courses/{$course->id}");

        $response->assertNoContent();

        $this->assertModelMissing($course);
    });

    test('ほかの講師は講座を削除できない', function () {
        $owner = User::factory()->instructor()->create();
        $course = Course::factory()->for($owner, 'instructor')->create();
        $otherInstructor = User::factory()->instructor()->create();

        $response = $this->actingAs($otherInstructor)
            ->deleteJson("/api/courses/{$course->id}");

        $response->assertForbidden();

        $this->assertModelExists($course);
    });
});
```

`Course::factory()->for($instructor, 'instructor')` は、Step 3 の「講師と一緒に作成」と同じ書き方で、作った講師をその講座の担当講師にします。2つ目のテストでは、担当講師の `$owner` とは別に講師をもう1人作り、その講師で削除を試しています。
</details>


## クリア判定

ここまでのレッスンで使ってきた `make quest-10` などの判定は、実は Pest のテストです。`tests/Quests/Stage10Test.php` を開いてみてください。Lesson 10 の受講登録APIに対して、このレッスンで学んだ `postJson()` やステータスコードの確認を使ったテストが並んでいます。テストは「こう動くはず」という仕様を、実行できる形で書いたものだということが分かると思います。

このレッスンの判定は、少し違う方法で行います。判定テストではなく、あなたが Step 9 で書いた `tests/Feature/Api/AttendanceStoreTest.php` そのものを確かめます。

```bash
make quest-12
```

判定は3段階です。テストのファイルがあること、テストがすべて通ること、そしてミューテーションスコアが 80% 以上であることです。

ミューテーションテストは、`AttendanceController` のコードをわざと少しずつ書き換え（たとえば `201` を `200` に、`<` を `<=` に）、そのたびにあなたのテストを走らせる仕組みです。書き換えたコードはミュータントと呼ばれます。ミュータントでもテストが通ってしまったら、テストがその書き換えを見逃したことになります。スコアは、テストで捕まえられたミュータントの割合です。判定では、あなたのテストが実際に通った行だけを書き換えます（`--covered-only`）。このあとのレッスンで `AttendanceController` にメソッドが増えても、受講登録のテストの判定は変わりません。

スコアが足りないときは、生き残ったミュータントを確かめましょう。

```bash
docker compose exec app vendor/bin/pest tests/Feature/Api/AttendanceStoreTest.php --mutate --class='App\Http\Controllers\Api\AttendanceController' --covered-only
```

たとえば「`assertStatus(201)` の代わりに `assertSuccessful()` を使っていたので、201 が 200 に変わっても気づけなかった」のように、テストの確かめ方が甘い箇所が分かります。

> 補足: ミューテーションテストには、カバレッジを取るための PCOV が必要です。このプロジェクトの Docker イメージには入っています。「Mutation testing requires code coverage」と表示されたら、イメージが古いままなので、リポジトリの README の手順で作り直してください。

## 参考資料

- [Laravel 公式ドキュメント - Testing](https://laravel.com/docs/testing)
- [Laravel 公式ドキュメント - HTTP Tests](https://laravel.com/docs/http-tests)
- [Laravel 公式ドキュメント - Database Testing](https://laravel.com/docs/database-testing)
- [Pest 公式ドキュメント](https://pestphp.com/docs/installation)

## 次のレッスン

[Lesson 13 良いコードを書く](./13-clean-code.md) では、早期リターンや名前の付け方など、動きを変えずにコードを読みやすく整える書き方を学びます。
