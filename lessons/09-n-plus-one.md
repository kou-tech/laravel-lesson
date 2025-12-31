# Lesson 9: N+1問題を解決する

## 学習目標

このレッスンでは、N+1問題の原因を理解し、Eager Loadingで効率的なクエリを書けるようになります。

### 到達目標
- N+1問題とは何かを説明できる
- `with()` を使ってN+1問題を解決できる
- Telescope/ログでクエリを確認できる
- 開発時にN+1問題を検出できる

---

## N+1問題とは？

### 問題のあるコード

講座一覧と担当講師名を表示するケースを考えます。

```php
public function index()
{
    $courses = Course::all();  // 1回のクエリ

    return view('courses.index', compact('courses'));
}
```

```blade
@foreach($courses as $course)
    <p>{{ $course->title }} - {{ $course->instructor->name }}</p>
@endforeach
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

**合計: 1 + N 回のクエリ**

100件の講座があれば101回のクエリが実行されます。

### なぜ問題か？

- **パフォーマンス低下**: クエリ数が増えるほど遅くなる
- **DBへの負荷**: 接続・切断のオーバーヘッド
- **スケールしない**: データが増えるほど悪化

---

## Step 1: Eager Loadingで解決

### with() を使う

```php
public function index()
{
    // instructor を事前に読み込む
    $courses = Course::with('instructor')->get();

    return view('courses.index', compact('courses'));
}
```

### 実行されるクエリ

```sql
-- 1. 講座一覧を取得
SELECT * FROM courses;

-- 2. 関連する講師を一括取得
SELECT * FROM users WHERE id IN (1, 2, 3, ..., N);
```

**合計: 2回のクエリ**

データ量に関係なく、常に2回のクエリで済みます。

---

## Step 2: 様々なEager Loading

### 複数のリレーションをロード

```php
$courses = Course::with(['instructor', 'enrollments'])->get();
```

```sql
SELECT * FROM courses;
SELECT * FROM users WHERE id IN (...);
SELECT * FROM enrollments WHERE course_id IN (...);
```

### ネストしたリレーション

```php
// 講座 → 受講 → ユーザー（受講生）
$courses = Course::with('enrollments.user')->get();
```

```sql
SELECT * FROM courses;
SELECT * FROM enrollments WHERE course_id IN (...);
SELECT * FROM users WHERE id IN (...);
```

### 条件付きロード

```php
// アクティブな受講のみロード
$courses = Course::with(['enrollments' => function ($query) {
    $query->where('status', 'enrolled');
}])->get();
```

### 特定カラムのみロード

```php
// 講師のid, nameのみ取得
$courses = Course::with('instructor:id,name')->get();
```

**注意**: 必ず外部キーを含める

```php
// ❌ instructor_idがないとリレーションが解決できない
$courses = Course::with('instructor:name')->get();

// ✅ 正しい
$courses = Course::with('instructor:id,name')->get();
```

---

## Step 3: 遅延Eager Loading

### load() - 後からロード

```php
$courses = Course::all();

// 条件に応じて後からロード
if ($includeInstructor) {
    $courses->load('instructor');
}
```

### loadMissing() - 未ロードのみロード

```php
$courses = Course::with('instructor')->get();

// instructor は既にロード済みなのでスキップ
// enrollments のみロード
$courses->loadMissing(['instructor', 'enrollments']);
```

---

## Step 4: N+1問題の検出

### Telescopeで確認

1. Telescopeダッシュボードを開く (`/telescope`)
2. 「Queries」タブを確認
3. 同じテーブルへの繰り返しクエリを探す

### preventLazyLoading() で検出

`app/Providers/AppServiceProvider.php`:

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

例外ではなくログに出力したい場合：

```php
Model::handleLazyLoadingViolationUsing(function ($model, $relation) {
    Log::warning('Lazy loading detected', [
        'model' => get_class($model),
        'relation' => $relation,
    ]);
});
```

---

## Step 5: カウントの最適化

### 問題: 受講者数を取得

```php
@foreach($courses as $course)
    <p>{{ $course->title }} - {{ $course->enrollments->count() }}人</p>
@endforeach
```

これは全ての受講データをロードしてからカウントします。

### 解決: withCount() を使う

```php
$courses = Course::withCount('enrollments')->get();
```

```blade
@foreach($courses as $course)
    <p>{{ $course->title }} - {{ $course->enrollments_count }}人</p>
@endforeach
```

```sql
SELECT courses.*,
       (SELECT COUNT(*) FROM enrollments WHERE course_id = courses.id) as enrollments_count
FROM courses;
```

### 条件付きカウント

```php
$courses = Course::withCount([
    'enrollments',
    'enrollments as active_enrollments_count' => function ($query) {
        $query->where('status', 'enrolled');
    },
])->get();

// $course->enrollments_count
// $course->active_enrollments_count
```

---

## Step 6: 実践例

### Before: N+1問題あり

```php
class CourseController extends Controller
{
    public function index()
    {
        $courses = Course::active()->get();

        return view('courses.index', compact('courses'));
    }
}
```

```blade
@foreach($courses as $course)
<div class="course-card">
    <h3>{{ $course->title }}</h3>
    <p>講師: {{ $course->instructor->name }}</p>
    <p>受講者数: {{ $course->enrollments->count() }} / {{ $course->capacity }}人</p>
    <p>残り: {{ $course->capacity - $course->enrollments->count() }}席</p>
</div>
@endforeach
```

**問題**:
- `$course->instructor` でN回クエリ
- `$course->enrollments->count()` でN回クエリ（さらに全データ取得）

### After: 最適化

```php
class CourseController extends Controller
{
    public function index()
    {
        $courses = Course::active()
            ->with('instructor:id,name')
            ->withCount('enrollments')
            ->get();

        return view('courses.index', compact('courses'));
    }
}
```

```blade
@foreach($courses as $course)
<div class="course-card">
    <h3>{{ $course->title }}</h3>
    <p>講師: {{ $course->instructor->name }}</p>
    <p>受講者数: {{ $course->enrollments_count }} / {{ $course->capacity }}人</p>
    <p>残り: {{ $course->capacity - $course->enrollments_count }}席</p>
</div>
@endforeach
```

**改善**:
- 合計2回のクエリ
- 受講データ自体は取得しない（カウントのみ）

---

## Step 7: APIでのEager Loading

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
            'enrollments_count' => $this->whenCounted('enrollments'),
        ];
    }
}
```

### whenLoaded() のメリット

- ロードされていなければ含めない
- 意図しないN+1を防止
- レスポンスサイズの最適化

---

## まとめ

このレッスンで学んだこと：

1. **N+1問題**
   - 1 + N回のクエリが発生
   - データ量に比例して悪化

2. **Eager Loading**
   - `with()` で事前ロード
   - ネスト・条件付きも可能

3. **カウントの最適化**
   - `withCount()` でサブクエリ
   - データ自体は取得しない

4. **検出方法**
   - Telescopeで確認
   - `preventLazyLoading()` で例外

5. **ベストプラクティス**
   - コントローラーで `with()` を明示
   - `whenLoaded()` で安全にレスポンス

---

## 練習問題

### 問題1
以下のコードにはN+1問題があります。修正してください。

```php
public function index()
{
    $enrollments = Enrollment::where('status', 'enrolled')->get();

    return $enrollments->map(function ($enrollment) {
        return [
            'user_name' => $enrollment->user->name,
            'course_title' => $enrollment->course->title,
            'enrolled_at' => $enrollment->enrolled_at,
        ];
    });
}
```

<details>
<summary>解答例</summary>

```php
public function index()
{
    $enrollments = Enrollment::where('status', 'enrolled')
        ->with(['user:id,name', 'course:id,title'])
        ->get();

    return $enrollments->map(function ($enrollment) {
        return [
            'user_name' => $enrollment->user->name,
            'course_title' => $enrollment->course->title,
            'enrolled_at' => $enrollment->enrolled_at,
        ];
    });
}
```
</details>

### 問題2
講師ごとに担当講座数を取得するクエリを書いてください。

<details>
<summary>解答例</summary>

```php
$instructors = User::where('role', 'instructor')
    ->withCount('courses')
    ->get();

foreach ($instructors as $instructor) {
    echo "{$instructor->name}: {$instructor->courses_count}講座\n";
}
```

※ User モデルに `courses()` リレーションを追加する必要があります：

```php
public function courses(): HasMany
{
    return $this->hasMany(Course::class, 'instructor_id');
}
```
</details>

---

## 次のレッスン

[Lesson 10: 安全なモデルの記述](./10-safe-model.md) では、Mass Assignmentなどのセキュリティ対策を学びます。
