# Lesson 9 Course APIの実装

## 学習目標

このレッスンでは、これまで学んだ知識を活用してCourse APIを実装し、受講管理システムの基盤を完成させます。

### 到達目標
- Course モデルとマイグレーションを作成できる
- CourseController で CRUD 操作を実装できる
- CourseResource を使ってレスポンスを整形できる
- CoursePolicyで認可を実装できる


## Step 1 モデルの作成

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
            'created_at' => $this->resource->created_at->toISOString(),
        ];
    }
}
```


## Step 3 FormRequestの作成

### StoreCourseRequest

```bash
php artisan make:request StoreCourseRequest
```

```php
<?php

namespace App\Http\Requests;

use App\Enums\CourseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseRequest extends FormRequest
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

### UpdateCourseRequest

同様に `UpdateCourseRequest` も作成します。

```php
<?php

namespace App\Http\Requests;

use App\Enums\CourseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends FormRequest
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


## Step 4 Controllerの実装

### CourseControllerの作成

```bash
php artisan make:controller Api/CourseController
```

`app/Http/Controllers/Api/CourseController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
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
    public function store(StoreCourseRequest $request): CourseResource
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
    public function update(UpdateCourseRequest $request, Course $course): CourseResource
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


## Step 5 ルーティングの設定

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
    Route::patch('/users/{user}', [UserController::class, 'update']);

    // 講座管理
    Route::post('/courses', [CourseController::class, 'store']);
    Route::patch('/courses/{course}', [CourseController::class, 'update']);
    Route::delete('/courses/{course}', [CourseController::class, 'destroy']);
});
```


## Step 6 Policyの作成

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


## Step 8 動作確認
Postmanで動作確認してください。

- 講座一覧
- 講座詳細
- 講座作成（認証必要）
- 講座更新（認証必要）
- 講座削除 （認証必要）

## 練習問題

### 問題1
講座一覧APIに「講師名で検索」機能を追加してください。クエリパラメータ `instructor` で講師名を部分一致検索できるようにしてください。

### 問題2
コレクションメソッドを使って、講座を status ごとにグループ化し、各ステータスの件数を取得するAPIエンドポイントを作成してください。

## 参考資料

- [Laravel 公式ドキュメント - Eloquent](https://laravel.com/docs/eloquent)
- [Laravel 公式ドキュメント - Controllers](https://laravel.com/docs/controllers)


## 次のレッスン

[Lesson 10 良いコードを書く](./10-clean-code.md) では、可読性の高い保守しやすいコードを書くための原則を学びます。
