# Lesson 14: サービスクラスの設計

## 学習目標

このレッスンでは、ビジネスロジックをサービスクラスに分離し、保守性の高い設計を実践します。

### 到達目標
- Fat Controller の問題を理解する
- サービスクラスの責務を設計できる
- カスタム例外クラスを作成できる
- コントローラーを薄く保てる

---

## Fat Controller の問題

### 問題のあるコード

```php
class CourseController extends Controller
{
    public function store(Request $request)
    {
        // バリデーション
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'capacity' => 'required|integer|min:1',
        ]);

        // 重複チェック
        $exists = Course::where('instructor_id', $request->user()->id)
            ->where('title', $validated['title'])
            ->exists();
        if ($exists) {
            return response()->json(['error' => '同じタイトルの講座があります'], 422);
        }

        // 講師の講座数制限チェック
        $count = Course::where('instructor_id', $request->user()->id)->count();
        if ($count >= 10) {
            return response()->json(['error' => '講座数の上限に達しています'], 422);
        }

        // 講座作成
        $course = Course::create([
            ...$validated,
            'instructor_id' => $request->user()->id,
            'status' => 'draft',
        ]);

        // 管理者に通知
        $admins = User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            Mail::to($admin)->send(new NewCourseNotification($course));
        }

        // ログ記録
        Log::info('講座作成', ['course_id' => $course->id]);

        return new CourseResource($course);
    }
}
```

**問題点**:
- コントローラーが肥大化（100行以上になることも）
- ビジネスロジックとHTTP処理が混在
- テストが困難
- 再利用できない

---

## サービスクラスの役割

### 責務の分離

```
┌─────────────────────────────────────────────────────┐
│ Controller                                          │
│ - HTTPリクエストの受付                              │
│ - バリデーション（FormRequest経由）                 │
│ - レスポンスの返却                                  │
└─────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────┐
│ Service                                             │
│ - ビジネスロジック                                  │
│ - トランザクション管理                              │
│ - 複数のリポジトリ/モデルの調整                     │
└─────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────┐
│ Model / Repository                                  │
│ - データアクセス                                    │
│ - リレーション                                      │
└─────────────────────────────────────────────────────┘
```

---

## Step 1: CourseService の作成

### ディレクトリ構成

```
app/
├── Http/
│   └── Controllers/
│       └── Api/
│           └── CourseController.php
├── Services/
│   └── CourseService.php
└── Exceptions/
    ├── DuplicateCourseTitleException.php
    └── CourseLimitExceededException.php
```

### CourseService

```php
<?php

namespace App\Services;

use App\Exceptions\CourseLimitExceededException;
use App\Exceptions\DuplicateCourseTitleException;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CourseService
{
    private const MAX_COURSES_PER_INSTRUCTOR = 10;

    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function create(array $data, User $instructor): Course
    {
        $this->validateCreation($data, $instructor);

        return DB::transaction(function () use ($data, $instructor) {
            $course = Course::create([
                ...$data,
                'instructor_id' => $instructor->id,
                'status' => 'draft',
            ]);

            $this->notificationService->notifyAdminsOfNewCourse($course);
            $this->logCourseCreation($course);

            return $course;
        });
    }

    public function update(Course $course, array $data): Course
    {
        if (isset($data['title'])) {
            $this->validateTitleUniqueness(
                $data['title'],
                $course->instructor_id,
                $course->id
            );
        }

        $course->update($data);

        return $course->fresh();
    }

    public function delete(Course $course): void
    {
        if ($course->enrollments()->exists()) {
            throw new \DomainException('受講者がいる講座は削除できません');
        }

        $course->delete();
    }

    private function validateCreation(array $data, User $instructor): void
    {
        $this->validateTitleUniqueness($data['title'], $instructor->id);
        $this->validateCourseLimit($instructor);
    }

    private function validateTitleUniqueness(
        string $title,
        int $instructorId,
        ?int $excludeId = null
    ): void {
        $query = Course::where('instructor_id', $instructorId)
            ->where('title', $title);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new DuplicateCourseTitleException();
        }
    }

    private function validateCourseLimit(User $instructor): void
    {
        $count = Course::where('instructor_id', $instructor->id)->count();

        if ($count >= self::MAX_COURSES_PER_INSTRUCTOR) {
            throw new CourseLimitExceededException();
        }
    }

    private function logCourseCreation(Course $course): void
    {
        Log::info('講座が作成されました', [
            'course_id' => $course->id,
            'instructor_id' => $course->instructor_id,
            'title' => $course->title,
        ]);
    }
}
```

---

## Step 2: カスタム例外クラス

### 例外クラスの作成

```php
// app/Exceptions/DuplicateCourseTitleException.php
<?php

namespace App\Exceptions;

use Exception;

class DuplicateCourseTitleException extends Exception
{
    protected $message = '同じタイトルの講座が既に存在します。';

    public function render()
    {
        return response()->json([
            'message' => $this->message,
        ], 422);
    }
}
```

```php
// app/Exceptions/CourseLimitExceededException.php
<?php

namespace App\Exceptions;

use Exception;

class CourseLimitExceededException extends Exception
{
    protected $message = '講座数の上限（10講座）に達しています。';

    public function render()
    {
        return response()->json([
            'message' => $this->message,
        ], 422);
    }
}
```

### 例外の階層構造

```php
// app/Exceptions/BusinessException.php
<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

abstract class BusinessException extends Exception
{
    protected int $statusCode = 422;

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->getErrorCode(),
        ], $this->statusCode);
    }

    abstract public function getErrorCode(): string;
}

// app/Exceptions/DuplicateCourseTitleException.php
class DuplicateCourseTitleException extends BusinessException
{
    protected $message = '同じタイトルの講座が既に存在します。';

    public function getErrorCode(): string
    {
        return 'DUPLICATE_COURSE_TITLE';
    }
}
```

---

## Step 3: シンプルなコントローラー

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Services\CourseService;

class CourseController extends Controller
{
    public function __construct(
        private CourseService $courseService
    ) {}

    public function index()
    {
        $courses = Course::with('instructor')
            ->withCount('enrollments')
            ->paginate();

        return CourseResource::collection($courses);
    }

    public function store(StoreCourseRequest $request)
    {
        $course = $this->courseService->create(
            $request->validated(),
            $request->user()
        );

        return new CourseResource($course);
    }

    public function show(Course $course)
    {
        return new CourseResource($course->load('instructor'));
    }

    public function update(UpdateCourseRequest $request, Course $course)
    {
        $this->authorize('update', $course);

        $course = $this->courseService->update($course, $request->validated());

        return new CourseResource($course);
    }

    public function destroy(Course $course)
    {
        $this->authorize('delete', $course);

        $this->courseService->delete($course);

        return response()->noContent();
    }
}
```

---

## Step 4: NotificationService

```php
<?php

namespace App\Services;

use App\Mail\NewCourseNotification;
use App\Mail\EnrollmentConfirmation;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function notifyAdminsOfNewCourse(Course $course): void
    {
        $admins = User::where('role', 'admin')->get();

        foreach ($admins as $admin) {
            Mail::to($admin)->queue(new NewCourseNotification($course));
        }
    }

    public function sendEnrollmentConfirmation(Enrollment $enrollment): void
    {
        Mail::to($enrollment->user)->queue(
            new EnrollmentConfirmation($enrollment)
        );
    }

    public function sendEnrollmentCancellation(Enrollment $enrollment): void
    {
        // キャンセル通知
    }
}
```

---

## Step 5: サービス設計のガイドライン

### 1. 1サービス = 1ドメイン

```
CourseService      - 講座に関するビジネスロジック
EnrollmentService  - 受講に関するビジネスロジック
UserService        - ユーザーに関するビジネスロジック
NotificationService - 通知に関するロジック
```

### 2. サービスはサービスを呼べる

```php
class EnrollmentService
{
    public function __construct(
        private NotificationService $notificationService,
        private CourseService $courseService
    ) {}
}
```

### 3. モデルのロジックはモデルに

```php
// ❌ サービスでやりすぎ
class CourseService
{
    public function hasCapacity(Course $course): bool
    {
        return $course->enrollments()->count() < $course->capacity;
    }
}

// ✅ モデルのメソッドとして定義
class Course extends Model
{
    public function hasCapacity(): bool
    {
        return $this->enrollments()->count() < $this->capacity;
    }
}
```

### 4. 単純なCRUDはサービス不要

```php
// シンプルな取得はコントローラーで直接
public function show(Course $course)
{
    return new CourseResource($course);
}

// 複雑なロジックはサービスへ
public function store(StoreCourseRequest $request)
{
    $course = $this->courseService->create(...);
}
```

---

## Step 6: テストしやすい設計

### サービスの単体テスト

```php
class CourseServiceTest extends TestCase
{
    use RefreshDatabase;

    private CourseService $service;
    private NotificationService $mockNotification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockNotification = Mockery::mock(NotificationService::class);
        $this->mockNotification->shouldReceive('notifyAdminsOfNewCourse');

        $this->service = new CourseService($this->mockNotification);
    }

    public function test_create_course_successfully(): void
    {
        $instructor = User::factory()->instructor()->create();

        $course = $this->service->create([
            'title' => 'テスト講座',
            'capacity' => 20,
        ], $instructor);

        $this->assertDatabaseHas('courses', [
            'title' => 'テスト講座',
            'instructor_id' => $instructor->id,
        ]);
    }

    public function test_cannot_create_duplicate_title(): void
    {
        $instructor = User::factory()->instructor()->create();
        Course::factory()->create([
            'title' => '既存講座',
            'instructor_id' => $instructor->id,
        ]);

        $this->expectException(DuplicateCourseTitleException::class);

        $this->service->create([
            'title' => '既存講座',
            'capacity' => 20,
        ], $instructor);
    }
}
```

---

## まとめ

このレッスンで学んだこと：

1. **Fat Controller の問題**
   - 肥大化、テスト困難、再利用不可

2. **サービスクラスの責務**
   - ビジネスロジックを集約
   - トランザクション管理
   - コントローラーを薄く

3. **カスタム例外**
   - ビジネスエラーを表現
   - `render()` でレスポンスをカスタマイズ

4. **設計ガイドライン**
   - 1サービス = 1ドメイン
   - モデルのロジックはモデルに
   - 単純なCRUDは直接

5. **テスト容易性**
   - モックを注入
   - 単体テストが書きやすい

---

## 練習問題

### 問題1
`EnrollmentService` の `cancel` メソッドを実装してください。以下の要件を満たすこと:
- 既にキャンセル済みの場合は例外
- ステータスを `cancelled` に変更
- キャンセル通知を送信

<details>
<summary>解答例</summary>

```php
public function cancel(User $user, Course $course): void
{
    $enrollment = Enrollment::where('user_id', $user->id)
        ->where('course_id', $course->id)
        ->firstOrFail();

    if ($enrollment->status === EnrollmentStatus::Cancelled) {
        throw new AlreadyCancelledException();
    }

    DB::transaction(function () use ($enrollment) {
        $enrollment->update([
            'status' => EnrollmentStatus::Cancelled,
        ]);

        $this->notificationService->sendEnrollmentCancellation($enrollment);
    });
}
```
</details>

---

## 次のレッスン

[Lesson 15: 自動テストの書き方](./15-testing.md) では、PHPUnit/Pestを使った自動テストを書き、品質を担保する方法を学びます。
