# Lesson 12 N+1問題を解決する

## 学習目標

このレッスンでは、N+1問題の原因を理解し、Eager Loadingで効率的なクエリを書けるようになります。

### 到達目標
- N+1問題とは何かを説明できる
- `with()` を使ってN+1問題を解決できる
- Telescope/ログでクエリを確認できる
- 開発時にN+1問題を検出できる

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
// 受講中のもののみロード
$courses = Course::with(['attendances' => function ($query) {
    $query->where('status', 'attending');
}])->get();
```

## Step 3 遅延Eager Loading

### load()（後からロード）

```php
$courses = Course::all();

// 条件に応じて後からロード
if ($includeInstructor) {
    $courses->load('instructor');
}
```

### loadMissing()（未ロードのみロード）

```php
$courses = Course::with('instructor')->get();

// instructor は既にロード済みなのでスキップ
// attendances のみロード
$courses->loadMissing(['instructor', 'attendances']);
```

## Step 4 N+1問題の検出

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

## Step 5 カウントの最適化

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
$courses = Course::withCount([
    'attendances',
    'attendances as attending_count' => function ($query) {
        $query->where('status', 'attending');
    },
])->get();

// $course->attendances_count
// $course->attending_count
```

## Step 6 実践例

### Before（N+1問題あり）

```php
class CourseController extends Controller
{
    public function index()
    {
        $courses = Course::active()->get();

        return $courses->map(function ($course) {
            return [
                'title' => $course->title,
                'instructor' => $course->instructor->name,                    // N回クエリ
                'capacity' => $course->attendances->count() . ' / ' . $course->capacity . '人',  // N回クエリ（全データ取得）
                'remaining' => $course->capacity - $course->attendances->count() . '席',
            ];
        });
    }
}
```

問題点として、`$course->instructor` でN回クエリ、`$course->attendances->count()` でN回クエリ（さらに全データ取得）が発生しています。

### After（最適化）

```php
class CourseController extends Controller
{
    public function index()
    {
        $courses = Course::active()
            ->with('instructor:id,name')
            ->withCount('attendances')
            ->get();

        return $courses->map(function ($course) {
            return [
                'title' => $course->title,
                'instructor' => $course->instructor->name,
                'capacity' => $course->attendances_count . ' / ' . $course->capacity . '人',
                'remaining' => $course->capacity - $course->attendances_count . '席',
            ];
        });
    }
}
```

改善点として、合計2回のクエリで済み、受講データ自体は取得しない（カウントのみ）ようになりました。

## Step 7 APIでのEager Loading

### CourseResource での対応

```php
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            // リレーションがロードされている場合のみ含める
            'instructor' => $this->whenLoaded('instructor', function () {
                return [
                    'id' => $this->instructor->id,
                    'name' => $this->instructor->name,
                ];
            }),
            'attendances_count' => $this->whenCounted('attendances'),
        ];
    }
}
```

### whenLoaded() のメリット

- ロードされていなければ含めない
- 意図しないN+1を防止
- レスポンスサイズの最適化

## 練習問題

### 問題1
以下のコードにはN+1問題があります。修正してください。

```php
public function index()
{
    $attendances = Attendance::where('status', 'attending')->get();

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
public function index()
{
    $attendances = Attendance::with(['user', 'course'])
        ->where('status', 'attending')
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
講師ごとに担当講座数を取得するクエリを書いてください。

<details>
<summary>解答例</summary>

```php
$instructors = User::withCount('courses')
    ->whereHas('courses')
    ->get();

// $instructor->courses_count で講座数を参照
```
</details>

## 参考資料

- [Laravel 公式ドキュメント - Eager Loading](https://laravel.com/docs/eloquent-relationships#eager-loading)

## 次のレッスン

[Lesson 13 安全なモデルの記述](./13-safe-model.md) では、Mass Assignmentなどのセキュリティ対策を学びます。
