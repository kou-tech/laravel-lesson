# Lesson 9 Course APIの実装

## 学習目標

このレッスンでは、これまで学んだ知識を活用してCourse APIを実装し、受講管理システムの基盤を完成させます。

### 到達目標
- Course モデルとマイグレーションを作成できる
- CourseController で CRUD 操作を実装できる
- CourseResource を使ってレスポンスを整形できる
- CoursePolicyで認可を実装できる


## Step 1 モデルの作成

> `courses` テーブルのマイグレーション（`2026_01_04_065543_create_courses_table.php`）は既に用意済みです。追加のマイグレーションは不要です。

### CourseStatus Enumの作成

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

### Courseモデル

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
     * 講師
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


## Step 2 API Resourceの作成

### CourseResourceの作成

`make app` でコンテナに入ってから、以下のコマンドを実行します。

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
    /** @var \App\Models\Course */
    public $resource;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'instructor' => new UserResource($this->whenLoaded('instructor')),
            'capacity' => $this->resource->capacity,
            'status' => $this->resource->status,
            'status_label' => $this->resource->status->label(),
            'created_at' => $this->resource->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
```


## Step 3 FormRequestの作成

Lesson 5 で決めた命名規則に従い、`Course/` サブディレクトリに `StoreRequest` / `UpdateRequest` を作成します。

### Course/StoreRequest

```bash
php artisan make:request Course/StoreRequest
```

```php
<?php

namespace App\Http\Requests\Course;

use App\Enums\CourseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100'],
            'status' => ['required', Rule::enum(CourseStatus::class)],
        ];
    }
}
```

### Course/UpdateRequest

同様に `Course/UpdateRequest` も作成します。

```bash
php artisan make:request Course/UpdateRequest
```

```php
<?php

namespace App\Http\Requests\Course;

use App\Enums\CourseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::enum(CourseStatus::class)],
        ];
    }
}
```


## Step 4 Policyの作成

Controller で `$this->authorize()` を呼ぶ前に、先に Policy を用意しておきます。

### CoursePolicyの作成

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
        return $user->id === $course->instructor_id;
    }

    /**
     * 講座を削除できるか
     */
    public function delete(User $user, Course $course): bool
    {
        return $user->id === $course->instructor_id;
    }
}
```


## Step 5 Controllerの実装

### CourseControllerの作成

```bash
php artisan make:controller Api/CourseController
```

`app/Http/Controllers/Api/CourseController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Course\StoreRequest;
use App\Http\Requests\Course\UpdateRequest;
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

        return CourseResource::collection($courses);
    }

    /**
     * 講座詳細を取得
     */
    public function show(Course $course): CourseResource
    {
        $course->load('instructor');

        return new CourseResource($course);
    }

    /**
     * 講座を作成
     */
    public function store(StoreRequest $request): CourseResource
    {
        $this->authorize('create', Course::class);

        $course = Course::create([
            ...$request->validated(),
            'instructor_id' => $request->user()->id,
        ]);

        $course->load('instructor');

        return new CourseResource($course);
    }

    /**
     * 講座を更新
     */
    public function update(UpdateRequest $request, Course $course): CourseResource
    {
        $this->authorize('update', $course);

        $course->update($request->validated());
        $course->load('instructor');

        return new CourseResource($course);
    }

    /**
     * 講座を削除
     */
    public function destroy(Course $course)
    {
        $this->authorize('delete', $course);

        $course->delete();

        return response()->json(null, 204);
    }
}
```


## Step 6 ルーティングの設定

`routes/api.php` に **以下のルートを追加** します（既存の UserController 系ルートはそのまま残します）。

```php
use App\Http\Controllers\Api\CourseController;

// 公開API（認証不要）
Route::get('/courses', [CourseController::class, 'index']);
Route::get('/courses/{course}', [CourseController::class, 'show']);

// 認証が必要なAPI（既存の auth:sanctum グループに追加）
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/courses', [CourseController::class, 'store']);
    Route::patch('/courses/{course}', [CourseController::class, 'update']);
    Route::delete('/courses/{course}', [CourseController::class, 'destroy']);
});
```

> すでに `auth:sanctum` グループが定義されている場合は、講座管理のルート3つを同グループ内に追記してください。


## Step 7 テストデータの作成

### Factoryの作成

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

    /**
     * 下書きの講座
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CourseStatus::Draft,
        ]);
    }
}
```

### Seederの作成

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

> 全テーブルを初期化してシーダーを実行したい場合は `make fresh` が使えます。


## Step 8 動作確認

Postmanのコレクション内の以下のリクエストで確認してください。認証が必要なリクエストは、先に `login > ログイン` を実行してください。

- `api > courses > 講座一覧`（GET）
- `api > courses > {courseId} > 講座詳細`（GET）
- `api > courses > 講座登録`（POST）— 認証必要
- `api > courses > {courseId} > 講座更新`（PATCH）— 認証必要
- `api > courses > {courseId} > 講座削除`（DELETE）— 認証必要

> Postman側の事前設定
>
> | リクエスト | Path Variables | Body（`raw / JSON`） |
> |---|---|---|
> | 講座詳細（GET） | `courseId = 1` | なし |
> | 講座登録（POST） | — | `title`, `description`, `capacity`, `status` を含むサンプルJSON |
> | 講座更新（PATCH） | `courseId = 1` | `title`, `capacity`, `status` を含むサンプルJSON |
> | 講座削除（DELETE） | `courseId = 1` | なし |
>
> `courseId` の値や Body のフィールド（例: `title`, `status` など）を変えたいときは、Postman の `Params > Path Variables` と `Body > raw (JSON)` を直接編集してください。存在しないIDを指定すれば 404、バリデーションに反する値を入れれば 422 の確認もできます。

## 練習問題

> 動作確認用に Postman コレクションへ以下のリクエストを用意しています。
> - 問題1: 既存の `api > courses > 講座一覧` にクエリパラメータ `instructor=山田` を追加して確認
> - 問題2: `api > courses > 講座ステータス別件数`（GET `/api/courses/stats`）

### 問題1
講座一覧APIに「講師名で検索」機能を追加してください。クエリパラメータ `instructor` で講師名を部分一致検索できるようにしてください。

<details>
<summary>解答例</summary>

```php
public function index(Request $request)
{
    $query = Course::with('instructor');

    if ($request->has('instructor')) {
        $query->whereHas('instructor', function ($q) use ($request) {
            $q->where('name', 'like', '%' . $request->input('instructor') . '%');
        });
    }

    $courses = $query->latest()->paginate();

    return CourseResource::collection($courses);
}
```
</details>

### 問題2
コレクションメソッドを使って、講座を status ごとにグループ化し、各ステータスの件数を取得するAPIエンドポイントを作成してください。

<details>
<summary>解答例</summary>

```php
// routes/api.php
Route::get('/courses/stats', [CourseController::class, 'stats']);

// CourseController.php
public function stats()
{
    $counts = Course::all()
        ->groupBy('status')
        ->map(fn($courses) => $courses->count());

    return response()->json(['data' => $counts]);
}
```
</details>

## 参考資料

- [Laravel 公式ドキュメント - Eloquent](https://laravel.com/docs/eloquent)
- [Laravel 公式ドキュメント - Controllers](https://laravel.com/docs/controllers)


## 次のレッスン

[Lesson 10 良いコードを書く](./10-clean-code.md) では、可読性の高い保守しやすいコードを書くための原則を学びます。
