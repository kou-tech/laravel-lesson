# Lesson 8 コレクション

## 学習目標

このレッスンでは、LaravelのコレクションとEloquentコレクションを学び、データ操作の幅を広げます。

### 到達目標
- Collection クラスの基本を理解する
- よく使うコレクションメソッドを使いこなせる
- Eloquentコレクションの特徴を理解する
- メソッドチェーンでデータを加工できる


## コレクションとは

Laravelのコレクションは、配列を扱いやすくしたラッパークラスです。

```php
// 通常の配列
$array = [1, 2, 3, 4, 5];

// コレクション
$collection = collect([1, 2, 3, 4, 5]);
```

コレクションを使うと、配列操作が直感的に書けます。

```php
// 配列の場合
$filtered = array_filter($array, fn($n) => $n > 2);
$mapped = array_map(fn($n) => $n * 2, $filtered);

// コレクションの場合
$result = collect($array)
    ->filter(fn($n) => $n > 2)
    ->map(fn($n) => $n * 2);
```


## Step 1 コレクションの作成

### collect ヘルパー

```php
// 配列からコレクションを作成
$collection = collect([1, 2, 3, 4, 5]);

// 連想配列からコレクションを作成
$users = collect([
    ['id' => 1, 'name' => '田中'],
    ['id' => 2, 'name' => '山田'],
    ['id' => 3, 'name' => '佐藤'],
]);
```

### Eloquentコレクション

Eloquentでクエリを実行すると、結果は `Eloquent\Collection` として返されます。

```php
$courses = Course::all();  // Eloquent\Collection
$course = Course::find(1); // Model（単体）
```


## Step 2 よく使うコレクションメソッド

### map - 各要素を変換

```php
$numbers = collect([1, 2, 3]);

$doubled = $numbers->map(fn($n) => $n * 2);
// [2, 4, 6]
```

Eloquentでの例

```php
$courses = Course::all();

$titles = $courses->map(fn($course) => $course->title);
// ['Laravel入門', 'PHP基礎', 'Vue.js講座']
```

### filter - 条件で絞り込み

```php
$numbers = collect([1, 2, 3, 4, 5]);

$filtered = $numbers->filter(fn($n) => $n > 2);
// [3, 4, 5]
```

Eloquentでの例

```php
$activeCourses = $courses->filter(fn($course) => $course->status === 'active');
```

filter後はキーが保持されるため、必要に応じて `values()` でリセットします。

```php
$filtered = $numbers->filter(fn($n) => $n > 2)->values();
// [3, 4, 5] （キーが0から始まる）
```

### pluck - 特定のカラムを抽出

```php
$titles = $courses->pluck('title');
// ['Laravel入門', 'PHP基礎', 'Vue.js講座']

// キーを指定
$titlesById = $courses->pluck('title', 'id');
// [1 => 'Laravel入門', 2 => 'PHP基礎', 3 => 'Vue.js講座']
```

### first / last - 最初/最後の要素

```php
$first = $courses->first();
$last = $courses->last();

// 条件付き
$activeCourse = $courses->first(fn($c) => $c->status === 'active');
```

### contains - 存在チェック

```php
// 値で確認
$hasThree = collect([1, 2, 3])->contains(3);  // true

// キーと値で確認
$hasId1 = $courses->contains('id', 1);  // true

// コールバックで確認
$hasActive = $courses->contains(fn($c) => $c->status === 'active');
```


## Step 3 集計メソッド

### count - 件数

```php
$count = $courses->count();  // 10
```

### sum - 合計

```php
$totalCapacity = $courses->sum('capacity');  // 200

// コールバックで計算
$total = $courses->sum(fn($c) => $c->capacity * $c->price);
```

### avg / average - 平均

```php
$averageCapacity = $courses->avg('capacity');  // 20.0
```

### max / min - 最大/最小

```php
$maxCapacity = $courses->max('capacity');  // 50
$minCapacity = $courses->min('capacity');  // 5
```


## Step 4 グループ化とソート

### groupBy - グループ化

```php
$coursesByStatus = $courses->groupBy('status');
// [
//     'active' => Collection([Course, Course]),
//     'draft' => Collection([Course]),
// ]

// コールバックでグループ化
$coursesByInstructor = $courses->groupBy(fn($c) => $c->instructor_id);
```

### sortBy / sortByDesc - ソート

```php
$sorted = $courses->sortBy('title');
$sortedDesc = $courses->sortByDesc('created_at');

// コールバックでソート
$sortedByCapacity = $courses->sortBy(fn($c) => $c->capacity);
```

### keyBy - キーを変更

```php
$coursesById = $courses->keyBy('id');
// [
//     1 => Course,
//     2 => Course,
//     3 => Course,
// ]

$course = $coursesById[1];  // ID=1の講座
```


## Step 5 変換メソッド

### toArray - 配列に変換

```php
$array = $courses->toArray();
```

### toJson - JSONに変換

```php
$json = $courses->toJson();
```

### values - キーをリセット

```php
$filtered = $courses->filter(fn($c) => $c->status === 'active')->values();
```

### keys - キーのみ取得

```php
$keys = $courses->keys();  // [0, 1, 2, ...]
```

### unique - 重複を除去

```php
$statuses = $courses->pluck('status')->unique();
// ['active', 'draft']
```


## Step 6 メソッドチェーン

複数のメソッドを連結して、複雑なデータ操作を実現できます。

### 例1 アクティブな講座のタイトル一覧

```php
$titles = Course::all()
    ->filter(fn($course) => $course->status === 'active')
    ->sortBy('title')
    ->pluck('title')
    ->values();
```

### 例2 講師ごとの講座数

```php
$countByInstructor = Course::with('instructor')
    ->get()
    ->groupBy('instructor_id')
    ->map(fn($courses) => $courses->count());
// [1 => 5, 2 => 3, 3 => 7]
```

### 例3 レスポンス用のデータ整形

```php
$result = Course::with('instructor')
    ->get()
    ->filter(fn($course) => $course->capacity > 10)
    ->sortByDesc('created_at')
    ->map(fn($course) => [
        'id' => $course->id,
        'title' => $course->title,
        'instructor_name' => $course->instructor->name,
    ])
    ->values();
```

## Step 7 パフォーマンスの注意点

### DBクエリ vs コレクション操作

```php
// ❌ 全件取得してからフィルタ（非効率）
$activeCourses = Course::all()
    ->filter(fn($c) => $c->status === 'active');

// ✅ DBクエリでフィルタ（効率的）
$activeCourses = Course::where('status', 'active')->get();
```

DBでできる処理はDBで行い、コレクションメソッドは取得後の加工に使いましょう。

### 適切な使い分け

| 処理 | 推奨 |
|------|------|
| 条件による絞り込み | DBクエリ（where） |
| ソート | DBクエリ（orderBy） |
| 件数取得 | DBクエリ（count） |
| データの変換・整形 | コレクション（map） |
| グループ化（複雑な条件） | コレクション（groupBy） |

## 練習問題

### 問題1
以下のコレクションから、価格が1000円以上の商品名を取得してください。

```php
$products = collect([
    ['name' => 'りんご', 'price' => 150],
    ['name' => 'パソコン', 'price' => 80000],
    ['name' => 'ノート', 'price' => 200],
    ['name' => 'キーボード', 'price' => 5000],
]);
```

<details>
<summary>解答例</summary>

```php
$names = $products
    ->filter(fn($product) => $product['price'] >= 1000)
    ->pluck('name')
    ->values();
// ['パソコン', 'キーボード']
```
</details>

## 参考資料

- [Laravel 公式ドキュメント - Collections](https://laravel.com/docs/collections)
- [Laravel 公式ドキュメント - Eloquent Collections](https://laravel.com/docs/eloquent-collections)


## 次のレッスン

[Lesson 9 Course APIの実装](./09-course-api.md) では、これまで学んだ知識を活用してCourse APIを実装します。
