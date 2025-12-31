# Lesson 6: Course APIの実装

## 学習目標

このレッスンでは、前回設計した講座APIを実装し、Eloquentコレクションの活用を学びます。

### 到達目標
- Course モデルとマイグレーションを作成できる
- CourseController で CRUD 操作を実装できる
- CourseResource / CourseCollection を使ってレスポンスを整形できる
- Eloquent コレクションのメソッドを活用できる

---

## Step 1: マイグレーションとモデルの作成

### マイグレーションの作成

```bash
php artisan make:migration create_courses_table
```

### マイグレーションの実装

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('instructor_id')->constrained('users')->onDelete('cascade');
            $table->unsignedInteger('capacity')->default(20);
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
```

### マイグレーションの実行

```bash
php artisan migrate
```

### モデルの作成

```bash
php artisan make:model Course
```

### Course モデルの実装

`app/Models/Course.php`

```php
<?php

namespace App\Models;

use App\Enums\CourseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'instructor_id',
        'capacity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'status' => CourseStatus::class,
        ];
    }

    /**
     * 講座の担当講師
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /**
     * 公開中の講座のみを取得するスコープ
     */
    public function scopeActive($query)
    {
        return $query->where('status', CourseStatus::Active);
    }
}
```

### CourseStatus Enum の作成

`app/Enums/CourseStatus.php`

```php
<?php

namespace App\Enums;

enum CourseStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';

    public function label(): string
    {
        return match($this) {
            self::Draft => '下書き',
            self::Active => '公開中',
            self::Closed => '終了',
        };
    }
}
```

---

## Step 2: API Resourceの作成

### CourseResource の作成

```bash
php artisan make:resource CourseResource
```

`app/Http/Resources/CourseResource.php`

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'instructor' => [
                'id' => $this->instructor->id,
                'name' => $this->instructor->name,
            ],
            'capacity' => $this->capacity,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
```

### CourseCollection の作成

```bash
php artisan make:resource CourseCollection
```

`app/Http/Resources/CourseCollection.php`

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CourseCollection extends ResourceCollection
{
    /**
     * 各アイテムに使用するリソースクラスを指定
     */
    public $collects = CourseResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total' => $this->total(),
                'per_page' => $this->perPage(),
                'current_page' => $this->currentPage(),
                'last_page' => $this->lastPage(),
            ],
        ];
    }
}
```

---

## Step 3: コントローラーの実装

### コントローラーの作成

```bash
php artisan make:controller Api/CourseController
```

### CourseController の実装

`app/Http/Controllers/Api/CourseController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseCollection;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    /**
     * 講座一覧を取得
     */
    public function index(Request $request)
    {
        $query = Course::with('instructor');

        // ステータスでフィルタリング
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // ページネーション
        $perPage = $request->input('per_page', 15);
        $courses = $query->latest()->paginate($perPage);

        return new CourseCollection($courses);
    }

    /**
     * 講座詳細を取得
     */
    public function show(Course $course)
    {
        // instructorをロードして返す
        $course->load('instructor');

        return new CourseResource($course);
    }

    /**
     * 講座を作成
     */
    public function store(Request $request)
    {
        // 認可チェック（講師のみ）
        $this->authorize('create', Course::class);

        // バリデーション
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // 講座を作成（講師は認証ユーザー）
        $course = Course::create([
            ...$validated,
            'instructor_id' => $request->user()->id,
        ]);

        $course->load('instructor');

        return new CourseResource($course);
    }

    /**
     * 講座を更新
     */
    public function update(Request $request, Course $course)
    {
        // 認可チェック（担当講師のみ）
        $this->authorize('update', $course);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', 'in:draft,active,closed'],
        ]);

        $course->update($validated);
        $course->load('instructor');

        return new CourseResource($course);
    }

    /**
     * 講座を削除
     */
    public function destroy(Course $course)
    {
        // 認可チェック（担当講師のみ）
        $this->authorize('delete', $course);

        $course->delete();

        return response()->json(null, 204);
    }
}
```

---

## Step 4: ルーティングの設定

`routes/api.php`

```php
<?php

use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// 公開API（認証不要）
Route::get('/courses', [CourseController::class, 'index']);
Route::get('/courses/{course}', [CourseController::class, 'show']);

// 認証が必要なAPI
Route::middleware('auth:sanctum')->group(function () {
    // ユーザー関連
    Route::get('/me', [UserController::class, 'me']);

    // 講座管理
    Route::post('/courses', [CourseController::class, 'store']);
    Route::patch('/courses/{course}', [CourseController::class, 'update']);
    Route::delete('/courses/{course}', [CourseController::class, 'destroy']);
});
```

---

## Step 5: Policyの作成

### CoursePolicy の作成

```bash
php artisan make:policy CoursePolicy --model=Course
```

`app/Policies/CoursePolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    /**
     * 講座を作成できるか
     */
    public function create(User $user): bool
    {
        return $user->isInstructor();
    }

    /**
     * 講座を更新できるか
     */
    public function update(User $user, Course $course): bool
    {
        // 担当講師のみ
        return $user->id === $course->instructor_id;
    }

    /**
     * 講座を削除できるか
     */
    public function delete(User $user, Course $course): bool
    {
        // 担当講師のみ
        return $user->id === $course->instructor_id;
    }
}
```

---

## Step 6: Eloquentコレクションの活用

### コレクションとは？

Eloquentでクエリを実行すると、結果は `Collection` オブジェクトとして返されます。

```php
$courses = Course::all();  // Collection
$course = Course::find(1); // Model
```

### よく使うコレクションメソッド

#### map - 各要素を変換

```php
$courses = Course::all();

$titles = $courses->map(function ($course) {
    return $course->title;
});
// ['Laravel入門', 'PHP基礎', 'Vue.js講座']
```

#### filter - 条件で絞り込み

```php
$activeCourses = $courses->filter(function ($course) {
    return $course->status === CourseStatus::Active;
});
```

#### pluck - 特定のカラムを抽出

```php
$titles = $courses->pluck('title');
// ['Laravel入門', 'PHP基礎', 'Vue.js講座']

$titlesById = $courses->pluck('title', 'id');
// [1 => 'Laravel入門', 2 => 'PHP基礎', 3 => 'Vue.js講座']
```

#### groupBy - グループ化

```php
$coursesByStatus = $courses->groupBy('status');
// [
//     'active' => [Course, Course],
//     'draft' => [Course],
// ]
```

#### sortBy / sortByDesc - ソート

```php
$sortedCourses = $courses->sortBy('title');
$sortedCoursesDesc = $courses->sortByDesc('created_at');
```

#### contains - 存在チェック

```php
if ($courses->contains('id', 1)) {
    // ID=1の講座が含まれている
}
```

#### first / last - 最初/最後の要素

```php
$firstCourse = $courses->first();
$lastCourse = $courses->last();

// 条件付き
$activeCourse = $courses->first(fn ($c) => $c->status === CourseStatus::Active);
```

#### sum / avg / max / min - 集計

```php
$totalCapacity = $courses->sum('capacity');
$averageCapacity = $courses->avg('capacity');
$maxCapacity = $courses->max('capacity');
```

### チェーンして使う

```php
$result = Course::with('instructor')
    ->get()
    ->filter(fn ($course) => $course->capacity > 10)
    ->sortByDesc('created_at')
    ->map(fn ($course) => [
        'id' => $course->id,
        'title' => $course->title,
        'instructor_name' => $course->instructor->name,
    ])
    ->values();  // キーをリセット
```

---

## Step 7: テストデータの作成

### Factory の作成

```bash
php artisan make:factory CourseFactory
```

`database/factories/CourseFactory.php`

```php
<?php

namespace Database\Factories;

use App\Enums\CourseStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CourseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'instructor_id' => User::factory(),
            'capacity' => fake()->numberBetween(10, 30),
            'status' => fake()->randomElement(CourseStatus::cases()),
        ];
    }

    /**
     * 公開中の講座
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CourseStatus::Active,
        ]);
    }
}
```

### Seeder の作成

```bash
php artisan make:seeder CourseSeeder
```

`database/seeders/CourseSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    public function run(): void
    {
        // 講師を作成
        $instructor = User::factory()->create([
            'name' => '山田講師',
            'email' => 'instructor@example.com',
            'role' => UserRole::Instructor,
        ]);

        // 講座を作成
        Course::factory(10)
            ->for($instructor, 'instructor')
            ->active()
            ->create();
    }
}
```

### 実行

```bash
php artisan db:seed --class=CourseSeeder
```

---

## 動作確認

### 講座一覧

```bash
curl http://localhost:8000/api/courses
```

### 講座詳細

```bash
curl http://localhost:8000/api/courses/1
```

### 講座作成（認証必要）

ブラウザでログイン後、開発者ツールのコンソールで：

```javascript
fetch('/api/courses', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
    body: JSON.stringify({
        title: 'テスト講座',
        description: 'テストの説明',
        capacity: 20
    })
})
.then(r => r.json())
.then(console.log);
```

---

## まとめ

このレッスンで学んだこと：

1. **モデルとマイグレーション**
   - リレーション（belongsTo）の定義
   - Enumを使ったステータス管理
   - ローカルスコープ

2. **API Resource**
   - 単一リソース（CourseResource）
   - コレクション（CourseCollection）
   - ページネーション情報の付与

3. **コントローラー**
   - CRUD操作の実装
   - バリデーション
   - 認可チェック

4. **Eloquentコレクション**
   - map, filter, pluck
   - groupBy, sortBy
   - sum, avg, first, last

---

## 練習問題

### 問題1
講座一覧APIに「講師名で検索」機能を追加してください。

<details>
<summary>ヒント</summary>

`whereHas` を使ってリレーション先で検索できます。
</details>

<details>
<summary>解答例</summary>

```php
public function index(Request $request)
{
    $query = Course::with('instructor');

    if ($request->has('instructor_name')) {
        $query->whereHas('instructor', function ($q) use ($request) {
            $q->where('name', 'like', '%' . $request->input('instructor_name') . '%');
        });
    }

    // ...
}
```
</details>

### 問題2
コレクションメソッドを使って、講座を status ごとにグループ化し、各ステータスの件数を取得してください。

<details>
<summary>解答例</summary>

```php
$courses = Course::all();

$countByStatus = $courses
    ->groupBy('status')
    ->map(fn ($group) => $group->count());

// [
//     'active' => 5,
//     'draft' => 3,
//     'closed' => 2,
// ]
```
</details>

---

## 次のレッスン

[Lesson 7: 良いコードを書く](./07-clean-code.md) では、可読性の高い保守しやすいコードを書くための原則を学びます。
