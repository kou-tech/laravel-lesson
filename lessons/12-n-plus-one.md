# Lesson 12 N+1問題を解決する

## 学習目標

このレッスンでは、N+1問題の原因を理解し、Eager Loadingで効率的なクエリを書けるようになります。

### 到達目標
- N+1問題とは何かを説明できる
- `with()` を使ってN+1問題を解決できる
- Telescope/ログでクエリを確認できる
- 開発時にN+1問題を検出できる

> このレッスンは2部構成です。
> - 第1部（Step 1〜4）: N+1問題と Eager Loading の原則・機能を学ぶ座学パート
> - 第2部（Step 5〜7）: 第1部で学んだテクニックをプロジェクトの既存コードに適用するハンズオンパート（`Course::hasCapacity()` 改善、`preventLazyLoading` 導入、CourseController 最適化）

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

## Step 4 カウントの最適化

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

---

# 第2部 プロジェクトへの適用

ここからは第1部で学んだテクニックを、実際のプロジェクトコードに適用します。

## Step 5 `Course::hasCapacity()` の改善

Lesson 11 Step 7 で実装した `Course::hasCapacity()` は、呼ぶたびに `COUNT` クエリを発行します。講座一覧で全講座の残席をチェックすると N+1 になります。

### 現状（Lesson 11 の実装）

```php
public function hasCapacity(): bool
{
    return $this->attendances()->count() < $this->capacity;
}
```

### 改善後

`withCount('attendances')` でロード済みの場合は `attendances_count` を優先して使い、そうでなければ従来通りクエリを発行するフォールバックを用意します。

```php
// app/Models/Course.php
public function hasCapacity(): bool
{
    $count = $this->attendances_count ?? $this->attendances()->count();

    return $count < $this->capacity;
}
```

これで呼び出し側で `Course::withCount('attendances')->get()` しておけば、追加クエリなしで定員チェックが行えます。単体取得時（`find` など）は `attendances_count` が無いので従来通り1回のクエリでカウントします。

## Step 6 `preventLazyLoading()` で Eager Loading 忘れを検出

開発中の N+1 を早期発見するため、`AppServiceProvider::boot` に `preventLazyLoading()` を導入します。

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

> 既存コードで例外が出る場合は、その呼び出し箇所で `with()` を追加してください。

## Step 7 `CourseController::index` の最適化

Lesson 9 で作った `CourseController::index` は `with('instructor')` まで入っていますが、受講者数の表示にも耐えるよう `withCount('attendances')` を追加します。

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
public function index(Request $request)
{
    $query = Course::with('instructor')
        ->withCount('attendances');
    // ... フィルタリング
    $courses = $query->latest()->paginate($perPage);

    return CourseResource::collection($courses);
}
```

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

> `whenCounted('attendances')` は `withCount('attendances')` を付けて取得したときだけフィールドが出力されます。意図しないN+1が起きる呼び出しパスではそもそも値が入らないので、レスポンス上で異常に気付けます。
>
> Lesson 9 の `CourseResource` では `instructor` 側で `new UserResource($this->whenLoaded('instructor'))` 形式を使っています。これは `whenLoaded('instructor', fn () => [...])` のようにコールバックを渡す書き方と等価で、どちらもロード済みでなければ出力しません。

### 動作確認

Postman で `api > courses > 講座一覧` を送信し、レスポンスの各要素に `attendances_count` が含まれることを確認してください。`preventLazyLoading` を入れたので、もし今後 N+1 を起こすコードを書くと即座に例外で気付けます。

## 練習問題

### 問題1
以下のコードにはN+1問題があります。修正してください。

```php
use App\Enums\AttendanceStatus;

public function index()
{
    $attendances = Attendance::where('status', AttendanceStatus::Attending)->get();

    return $attendances->map(function ($attendance) {
        return [
            'user_name' => $attendance->user->name,
            'course_title' => $attendance->course->title,
            'attended_at' => $attendance->attended_at,
        ];
    });
}
```

<details>
<summary>解答例</summary>

```php
use App\Enums\AttendanceStatus;

public function index()
{
    $attendances = Attendance::with(['user', 'course'])
        ->where('status', AttendanceStatus::Attending)
        ->get();

    return $attendances->map(function ($attendance) {
        return [
            'user_name' => $attendance->user->name,
            'course_title' => $attendance->course->title,
            'attended_at' => $attendance->attended_at,
        ];
    });
}
```

`with(['user', 'course'])` を追加することで、クエリが N+1 回から 3 回（attendances, users, courses）に削減されます。
</details>

### 問題2
ユーザーごとに受講中（`status = attending`）の件数を取得するクエリを書いてください。

> ヒント: `User` モデルには Lesson 11 で `attendances()` リレーションを追加済みです。`withCount` の条件付きバリエーション（第1部 Step 4）を使います。

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
</details>

## 参考資料

- [Laravel 公式ドキュメント - Eager Loading](https://laravel.com/docs/eloquent-relationships#eager-loading)

## 次のレッスン

[Lesson 13 安全なモデルの記述](./13-safe-model.md) では、Mass Assignmentなどのセキュリティ対策を学びます。
