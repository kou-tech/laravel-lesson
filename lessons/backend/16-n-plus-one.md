# Lesson 16 N+1問題を解決する

## 学習目標

このレッスンでは、N+1問題の原因を理解し、Eager Loadingで効率的なクエリを書けるようになります。

### 到達目標
- N+1問題とは何かを説明できる
- `with()` を使ってN+1問題を解決できる
- Telescope/ログでクエリを確認できる
- `preventLazyLoading()` で Eager Loading の書き忘れを見つけて直せる
- `withCount()` で関連するレコードの件数をまとめて数えられる

> このレッスンは2部構成です。
> - 第1部（Step 1〜4）: N+1問題と Eager Loading の原則・機能を学ぶ座学パート
> - 第2部（Step 5〜8）: `preventLazyLoading()` を有効にしてから自分の受講一覧API（`GET /api/me/attendances`）を作り、見つかった遅延ロードを直すハンズオンパート。最後に講座一覧へ受講者数を足し、`Course::hasCapacity()` を改善します

> このレッスンでは「Eloquentのコードを書くと、実際にどんなクエリが飛ぶか」を示すためにSQLが登場します。生のSQLを書けるようになる必要はありません。「クエリが何回飛んでいるか」だけ数えながら読んでください。

## N+1問題とは

### 問題のあるコード

講座一覧と担当講師名を表示するケースを考えます。

```php
public function index()
{
    $courses = Course::all();  // 1回のクエリ

    // 各講座の講師名にアクセス
    return $courses->map(function ($course) {
        return [
            'title' => $course->title,
            'instructor' => $course->instructor->name,  // ここでクエリが発生
        ];
    });
}
```

### 実行されるクエリ

```sql
-- 1. 講座一覧を取得（1回）
SELECT * FROM courses;

-- 2. 各講座の講師を取得（N回）
SELECT * FROM users WHERE id = 1;
SELECT * FROM users WHERE id = 2;
SELECT * FROM users WHERE id = 3;
...
SELECT * FROM users WHERE id = N;
```

合計 1 + N 回のクエリが実行されます。100件の講座があれば101回のクエリが実行されます。

### なぜ問題か

クエリ数が増えるほどパフォーマンスが低下し、DBへの接続・切断のオーバーヘッドも増加します。データが増えるほど悪化するため、スケールしません。

---

# 第1部 N+1対策の基本（座学）

Eager Loading の使い方を一通り押さえます。サンプルコードは原則の説明用で、プロジェクトへの適用は第2部で行います。

## Step 1 Eager Loadingで解決

### with() を使う

```php
public function index()
{
    // instructor を事前に読み込む
    $courses = Course::with('instructor')->get();

    return $courses->map(function ($course) {
        return [
            'title' => $course->title,
            'instructor' => $course->instructor->name,  // クエリは発生しない
        ];
    });
}
```

### 実行されるクエリ

```sql
-- 1. 講座一覧を取得
SELECT * FROM courses;

-- 2. 関連する講師を一括取得
SELECT * FROM users WHERE id IN (1, 2, 3, ..., N);
```

合計2回のクエリで済みます。データ量に関係なく、常に2回のクエリで済みます。

## Step 2 様々なEager Loading

### 複数のリレーションをロード

```php
$courses = Course::with(['instructor', 'attendances'])->get();
```

```sql
SELECT * FROM courses;
SELECT * FROM users WHERE id IN (...);
SELECT * FROM attendances WHERE course_id IN (...);
```

### ネストしたリレーション

```php
// 講座 → 受講 → ユーザー（受講生）
$courses = Course::with('attendances.user')->get();
```

```sql
SELECT * FROM courses;
SELECT * FROM attendances WHERE course_id IN (...);
SELECT * FROM users WHERE id IN (...);
```

### 条件付きロード

```php
use App\Enums\AttendanceStatus;

// 受講中のもののみロード
$courses = Course::with(['attendances' => function ($query) {
    $query->where('status', AttendanceStatus::Attending);
}])->get();
```

> `status` は Enum にキャストしていますが、`where` に Enum を渡すと Laravel が内部で文字列値（`'attending'`）に変換します。素の文字列 `'attending'` を渡しても動作しますが、Enum を渡す方が型安全でリファクタ耐性があります。

## Step 3 N+1問題の検出

### Telescopeで確認

1. Telescopeダッシュボードを開く (`/telescope`)
2. 「Queries」タブを確認
3. 同じテーブルへの繰り返しクエリを探す

### preventLazyLoading() で検出

`app/Providers/AppServiceProvider.php` に以下を追加します。

```php
use Illuminate\Database\Eloquent\Model;

public function boot(): void
{
    // 開発環境でのみ有効化
    Model::preventLazyLoading(!app()->isProduction());
}
```

これにより、Eager Loading忘れがあると例外がスローされます。

```
Attempted to lazy load [instructor] on model [App\Models\Course]
but lazy loading is disabled.
```

### handleLazyLoadingViolationUsing() でログ出力

例外ではなくログに出力したい場合は以下のように設定します。

```php
Model::handleLazyLoadingViolationUsing(function ($model, $relation) {
    Log::warning('Lazy loading detected', [
        'model' => get_class($model),
        'relation' => $relation,
    ]);
});
```

> 補足: Laravel 12.8 から、アクセスしたリレーションをまとめて読み込む `Model::automaticallyEagerLoadRelationships()` が使えるようになりました。コレクションの1件目でリレーションにアクセスした時点で、残りの全件分も1回のクエリで読み込みます。公式ドキュメントにも載っている方法ですが、どのクエリがいつ飛ぶかがコードから読み取りにくくなります。このコースでは、`with()` で読み込むリレーションを明示し、`preventLazyLoading()` で書き忘れに気付く方法で進めます。

## Step 4 カウントの最適化

Lesson 10 の練習問題1と Lesson 11 で少しだけ使った `withCount()` を、ここで詳しく見ます。

### 問題（受講者数を取得）

```php
$courses = Course::all();

$courses->map(function ($course) {
    return [
        'title' => $course->title,
        'count' => $course->attendances->count() . '人',  // 全データをロードしてからカウント
    ];
});
```

これは全ての受講データをロードしてからカウントします。

### 解決（withCount() を使う）

```php
$courses = Course::withCount('attendances')->get();

$courses->map(function ($course) {
    return [
        'title' => $course->title,
        'count' => $course->attendances_count . '人',  // カウント値を直接参照
    ];
});
```

```sql
SELECT courses.*,
       (SELECT COUNT(*) FROM attendances WHERE course_id = courses.id) as attendances_count
FROM courses;
```

### 条件付きカウント

```php
use App\Enums\AttendanceStatus;

$courses = Course::withCount([
    'attendances',
    'attendances as attending_count' => function ($query) {
        $query->where('status', AttendanceStatus::Attending);
    },
])->get();

// $course->attendances_count
// $course->attending_count
```

`withCount('attendances')` は、キャンセル済みの受講も数えます。受講者数のようにキャンセル済みを除いて数えたいときは、条件付きのほうを使います。第2部の Step 7 では、この書き方で講座一覧に受講者数を足します。

---

# 第2部 プロジェクトへの適用

ここからは第1部で学んだテクニックを、実際のプロジェクトコードに適用します。

## Step 5 `preventLazyLoading()` で Eager Loading 忘れを検出

開発中の N+1 を早く見つけるため、`AppServiceProvider::boot` で `preventLazyLoading()` を有効にします。

`app/Providers/AppServiceProvider.php`

```php
use Illuminate\Database\Eloquent\Model;

public function boot(): void
{
    // 本番では無効（万一の例外で500にしないため）、開発環境でのみ N+1 を例外として検出
    Model::preventLazyLoading(! app()->isProduction());
}
```

これ以降、Eager Loading 忘れがあると `LazyLoadingViolationException` が発生し、開発時点で気付けます。

```
Attempted to lazy load [instructor] on model [App\Models\Course]
but lazy loading is disabled.
```

教材どおりに書いてきたコードでは、有効にしても例外は出ません。Lesson 9 の `CourseController` は一覧で `with('instructor')`、詳細で `load('instructor')` を呼んでいて、Lesson 14 の `CreateCourse` なども講師を読み込んでから講座を返しています。Lesson 14・15 の `AttendCourse` も、最後に `load(['user', 'course.instructor'])` で読み込んでから返しています。Postman で `api > courses > 講座一覧` を送り、これまでどおり 200 が返ることを確かめてください。

> 自分で書き足したコードで例外が出た場合は、その呼び出し箇所で `with()` または `load()` を追加してください。

テストの実行時も `APP_ENV` は `testing` で本番ではないので、`preventLazyLoading()` は有効になります。Lesson 12 から書いてきたテストを実行し、緑のままであることも確かめておきます。

```bash
php artisan test
```

## Step 6 自分の受講一覧APIを作る

[API仕様一覧](../reference/api-spec.md) にある `GET /api/me/attendances`（自分の受講一覧）を作ります。ログイン中のユーザーの受講を返し、キャンセル済みは含めません。まずは素直に書き、`preventLazyLoading()` が見つけた遅延ロードを1つずつ直していきます。

### ルートを追加

`routes/api.php` の `auth:sanctum` グループ内に追加します。

```php
Route::middleware('auth:sanctum')->group(function () {
    // ...既存のルート...

    // 自分の受講一覧
    Route::get('/me/attendances', [AttendanceController::class, 'index']);
});
```

> ファイル先頭の `use` に `use App\Http\Controllers\Api\AttendanceController;` がなければ追加してください。Lesson 10 で受講登録のルートを完全修飾名（`\App\Http\Controllers\Api\AttendanceController::class`）で書いた場合は、それもそのまま動きます。

### index メソッドを追加

`app/Http/Controllers/Api/AttendanceController.php` に `index` メソッドを追加します。Lesson 14 で書き換えた `store` とコンストラクタはそのまま残してください。

```php
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 自分の受講一覧（キャンセル済みは含めない）
 */
public function index(Request $request): AnonymousResourceCollection
{
    $attendances = $request->user()->attendances()->active()->get();

    return AttendanceResource::collection($attendances);
}
```

`attendances()` は Lesson 10 で `User` に追加したリレーション、`active()` は Lesson 11 で `Attendance` に追加したスコープです。キャンセル済みの受講は `active()` で除かれます。

### 動作確認のデータを用意する

動作確認の前に、ログイン中の生徒が2つ以上の講座に受講登録している状態を作ります。

1. `login > ログイン` を実行します（`test@example.com` は生徒です）
2. `api > courses > {courseId} > attendances > 受講登録`（POST）を、`courseId` を変えて2回以上送ります

受講が1件だけだと、遅延ロードがあっても例外になりません。Laravel は、1回のクエリで2件以上まとめて取得したモデルにだけ遅延ロードの禁止を適用するからです。1件だけなら、遅延ロードしてもクエリが1回増えるだけで、件数に比例して増える N+1 にはなりません。違反を確かめたいときは、必ず2件以上のデータで試してください。

### 遅延ロードの違反を確かめる

Postman で `api > me > 自分の受講一覧`（GET）を送ると、500 エラーになります。

```json
{
    "message": "Attempted to lazy load [user] on model [App\\Models\\Attendance] but lazy loading is disabled.",
    "exception": "Illuminate\\Database\\LazyLoadingViolationException",
    ...
}
```

Lesson 10 で作った `AttendanceResource` は、`$this->resource->user` と `$this->resource->course` を読んで `UserResource`・`CourseResource` に渡しています。`with()` で読み込んでいないので、受講1件ごとに `users` と `courses` へのクエリが1回ずつ飛ぶ書き方です。例外は最初に見つかった1か所で止まるため、まず `user` の違反が表示されます。

`with('user')` を足して送り直すと、今度は `course` の違反が出ます。

```php
$attendances = $request->user()->attendances()->active()->with('user')->get();
```

```
Attempted to lazy load [course] on model [App\Models\Attendance] but lazy loading is disabled.
```

このように、違反を1つ直して送り直し、次の違反を探すことを繰り返します。

### with() で直す

`AttendanceResource` が使うリレーションをすべて `with()` に並べます。講座の講師まで読み込むため、`course.instructor` とネストして書きます。

```php
public function index(Request $request): AnonymousResourceCollection
{
    $attendances = $request->user()
        ->attendances()
        ->active()
        ->with(['user', 'course.instructor'])
        ->get();

    return AttendanceResource::collection($attendances);
}
```

送り直すと 200 が返ります。一覧を作るクエリは、受講の件数に関係なく attendances・users（受講者）・courses・users（講師）の4回で済みます。キャンセルのAPIは Lesson 18 で作るので、キャンセル済みの受講が一覧に出ないことは、このあとのテストで確かめます。

### 講師が JSON から消える落とし穴

`with(['user', 'course'])` のように、`course.instructor` を書き忘れた場合を考えます。このときは例外にならず、200 が返ります。ただし、各講座の `instructor` がレスポンスから消えます。

Lesson 9 の `CourseResource` は、講師を `new UserResource($this->whenLoaded('instructor'))` で出力しています。`whenLoaded()` は、読み込み済みでなければリレーションを読みに行かず、キーごと出力しません。遅延ロードが起きないので、`preventLazyLoading()` でも見つけられません。

Lesson 14 の `AttendCourse` は `load(['user', 'course.instructor'])` で読み込んでいるので、受講登録のレスポンスには講師が含まれます。一覧も同じ形で返すように、`with()` には `course.instructor` まで書きます。レスポンスの各要素の `course` に `instructor` があることを、目でも確かめてください。

### テストで確かめる

`preventLazyLoading()` はテストでも有効なので、受講を2件以上作るテストを書いておけば、あとで `with()` を消してしまったときにテストが 500 で落ちて気付けます。`make app` でコンテナに入ってから、テストファイルを作ります。

```bash
php artisan make:test Api/MyAttendanceTest
```

`tests/Feature/Api/MyAttendanceTest.php`

```php
<?php

use App\Models\Attendance;
use App\Models\User;

test('自分の受講一覧はキャンセル済みと他人の受講を含まない', function () {
    $student = User::factory()->student()->create();

    // 遅延ロードの違反を見つけるため、自分の受講は2件以上にする
    $attendances = Attendance::factory()->for($student, 'user')->count(2)->create();
    Attendance::factory()->for($student, 'user')->cancelled()->create();
    Attendance::factory()->create();  // 他人の受講

    $response = $this->actingAs($student)
        ->getJson('/api/me/attendances');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'status', 'course' => ['id', 'title', 'instructor']],
            ],
        ]);

    expect($response->json('data.*.id'))
        ->toEqualCanonicalizing($attendances->pluck('id')->all());
});
```

`for($student, 'user')` は Lesson 12 Step 3 の「講師と一緒に作成」と同じ書き方で、作る受講の受講者を `$student` にします。`assertJsonStructure()` で `instructor` のキーまで確かめているので、`course.instructor` を書き忘れたときもこのテストで気付けます。一覧の並び順は決めていないので、ID は `toEqualCanonicalizing()` で順番を問わずに比べています。

```bash
php artisan test tests/Feature/Api/MyAttendanceTest.php
```

## Step 7 `CourseController::index` に受講者数を追加

講座一覧のレスポンスに、受講中の人数を足します。Lesson 9 で作った `CourseController::index` は、Lesson 14 で `store` などを書き換えたあとも次の形のままです。

### Before / After

```php
// Before（Lesson 9 時点）
public function index(Request $request)
{
    $query = Course::with('instructor');
    // ... フィルタリング
    $courses = $query->latest()->paginate($perPage);

    return CourseResource::collection($courses);
}
```

```php
// After（withCount を追加）
use App\Enums\AttendanceStatus;

public function index(Request $request)
{
    $query = Course::with('instructor')
        ->withCount(['attendances' => fn ($query) => $query->where('status', AttendanceStatus::Attending)]);
    // ... フィルタリング
    $courses = $query->latest()->paginate($perPage);

    return CourseResource::collection($courses);
}
```

`withCount()` に条件を付けて、受講中（`attending`）の受講だけを数えます。キャンセル済みを数えると、キャンセルした人がいる講座の人数が実際より多く出てしまいます。条件を付けても、結果は `attendances_count` という名前で読めます。Lesson 10 の練習問題1で `show` に書いた `loadCount()` と同じ条件です。

### CourseResource への `attendances_count` 反映

Lesson 9 で作った `CourseResource` に、ロードされている場合のみカウントを返すフィールドを追加します。

```php
// app/Http/Resources/CourseResource.php
return [
    // ...既存のフィールド
    'status_label' => $this->resource->status->label(),
    'starts_at' => $this->resource->starts_at?->format('Y-m-d H:i:s'),
    'attendances_count' => $this->whenCounted('attendances'),  // 追加
    'created_at' => $this->resource->created_at->format('Y-m-d H:i:s'),
];
```

> `whenCounted('attendances')` は、`withCount()` や `loadCount()` で件数を読み込んだときだけフィールドを出力します。Step 6 の受講一覧の `course` のように件数を読み込んでいないレスポンスには、`attendances_count` は出ません。
>
> Lesson 10 の練習問題1で `remaining_seats` を足した人は、一覧でも件数が読み込まれるようになったので、追加のクエリなしで一覧にも `remaining_seats` が出ます。
>
> 講師の `new UserResource($this->whenLoaded('instructor'))` も同じ考え方で、ロード済みでなければ出力しません。`whenLoaded('instructor', fn () => [...])` のようにコールバックを渡す書き方もあり、どちらもロード済みでなければ出力しません。

### 動作確認

Postman で `api > courses > 講座一覧` を送信し、レスポンスの各要素に `attendances_count` が含まれることを確認してください。Step 6 で受講登録した講座では、その人数が数えられています。

## Step 8 `Course::hasCapacity()` の改善

Lesson 10 で作った `Course::hasCapacity()` は、呼ぶたびに `COUNT` クエリを発行します。講座一覧のように多くの講座で呼ぶと、講座の数だけクエリが増えます。リレーションの遅延ロードではないので、`preventLazyLoading()` では見つかりません。

### 現状（Lesson 10 の実装）

```php
public function hasCapacity(): bool
{
    return $this->attendances()
        ->where('status', AttendanceStatus::Attending)
        ->count() < $this->capacity;
}
```

### 改善後

Step 7 の `withCount()` で件数を読み込み済みの場合は `attendances_count` を使い、そうでなければこれまでどおりクエリで数えるフォールバックを用意します。

```php
// app/Models/Course.php
public function hasCapacity(): bool
{
    $count = $this->attendances_count
        ?? $this->attendances()->where('status', AttendanceStatus::Attending)->count();

    return $count < $this->capacity;
}
```

`??` は、左側が `null` のときだけ右側を評価します。`withCount()` を付けずに取得した講座では `attendances_count` が `null` なので、これまでどおり1回のクエリで数えます。

`withCount()` の条件と、フォールバックのクエリの条件は、どちらも「受講中（`attending`）だけ」にそろえてください。条件がずれていると、一覧から呼んだときと単体で呼んだときとで、同じ講座の判定が変わってしまいます。

> 注意: `withCount()` で読んだ件数を、ロックの代わりに使わないでください。講座一覧の `attendances_count` は、読んだ瞬間の件数です。そのあとに別の人が申し込めば古くなるので、「残りわずか」のような表示には使えても、申し込めるかどうかの判定には使えません。
>
> Lesson 15 の `AttendCourse` は、受け取った `$course` ではなく、トランザクションの中で `Course::lockForUpdate()->findOrFail($course->id)` で取り直した講座で `hasCapacity()` を呼んでいます。取り直した講座には `attendances_count` がないので、ロックを取ったあとにフォールバックのクエリで数え直します。`AttendCourse` を書き換えて、受け取った講座の件数を使ったり、ロックを付けた取り直しを外したりすると、Lesson 15 で防いだ定員超過がまた起きます。

書き換えたら、Lesson 12 で書いた受講登録のテスト（定員に達していると 422 になるテストなど）を実行し、緑のままであることを確かめます。

```bash
php artisan test
```

## 練習問題

### 問題1
Step 6 で作った自分の受講一覧のレスポンスで、各講座に受講中の人数（`attendances_count`）も出るようにしてください。Step 7 で `CourseResource` に `whenCounted('attendances')` を足してあるので、変えるのは `AttendanceController::index` のクエリだけです。

> ヒント: `with()` の配列では、リレーション名をキーにしてクロージャを渡すと、そのリレーションを読み込むクエリに条件を足せます（第1部 Step 2 の条件付きロード）。

<details>
<summary>解答例</summary>

```php
use App\Enums\AttendanceStatus;

public function index(Request $request): AnonymousResourceCollection
{
    $attendances = $request->user()
        ->attendances()
        ->active()
        ->with([
            'user',
            'course' => fn ($query) => $query->withCount([
                'attendances' => fn ($query) => $query->where('status', AttendanceStatus::Attending),
            ]),
            'course.instructor',
        ])
        ->get();

    return AttendanceResource::collection($attendances);
}
```

`course` を読み込むクエリに `withCount()` を足すと、各講座に `attendances_count` が付き、`CourseResource` の `whenCounted('attendances')` が出力します。数える条件は、Step 7 の講座一覧と同じく受講中だけにそろえます。`'course.instructor'` と並べて書いても、講座を読み込むクエリは1回のままです。
</details>

### 問題2
ユーザーごとに受講中（`status = attending`）の件数を取得するクエリを書いてください。

> ヒント: `User` モデルには Lesson 10 で `attendances()` リレーションを追加済みです。`withCount` の条件付きバリエーション（第1部 Step 4）を使います。

<details>
<summary>解答例</summary>

```php
use App\Enums\AttendanceStatus;

$users = User::withCount([
    'attendances as attending_count' => function ($query) {
        $query->where('status', AttendanceStatus::Attending);
    },
])->get();

// $user->attending_count で受講中の件数を参照
```

`as attending_count` で別名を付けているので、結果は `attendances_count` ではなく `attending_count` で読みます。ユーザーが何人いても、クエリは1回です。
</details>

## 参考資料

- [Laravel 公式ドキュメント - Eager Loading](https://laravel.com/docs/eloquent-relationships#eager-loading)
- [Laravel 公式ドキュメント - Preventing Lazy Loading](https://laravel.com/docs/eloquent-relationships#preventing-lazy-loading)
- [Laravel 公式ドキュメント - Counting Related Models](https://laravel.com/docs/eloquent-relationships#counting-related-models)

## 次のレッスン

[Lesson 17 メールとジョブ機能](./17-mail-job.md) では、受講登録のときに確認メールを送る処理を作り、キューを使って非同期で実行する方法を学びます。
