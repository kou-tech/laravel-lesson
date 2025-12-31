# Lesson 7: 良いコードを書く

## 学習目標

このレッスンでは、可読性の高い保守しやすいコードを書くための原則を学びます。

### 到達目標
- 早期リターン（Early Return）パターンを使える
- マジックナンバーを排除できる
- 適切な変数名・メソッド名を付けられる
- メソッドの責務を分割できる

---

## なぜ「良いコード」が重要か？

コードは**書く時間より読む時間の方が長い**です。

- 自分が書いたコードを3ヶ月後に読み返す
- チームメンバーがコードをレビューする
- バグ修正のために調査する

読みやすいコードは：
- **バグが発見しやすい**
- **変更が容易**
- **引き継ぎが楽**

---

## 1. 早期リターン（Early Return）

### 問題のあるコード

```php
public function enroll(User $user, Course $course)
{
    if ($user->isStudent()) {
        if ($course->status === CourseStatus::Active) {
            if ($course->hasCapacity()) {
                if (!$user->isEnrolledIn($course)) {
                    // 受講登録処理
                    $enrollment = Enrollment::create([
                        'user_id' => $user->id,
                        'course_id' => $course->id,
                    ]);
                    return $enrollment;
                } else {
                    throw new AlreadyEnrolledException();
                }
            } else {
                throw new CapacityExceededException();
            }
        } else {
            throw new CourseNotActiveException();
        }
    } else {
        throw new NotStudentException();
    }
}
```

**問題点:**
- ネストが深い（インデントが多い）
- 正常系が奥深くにある
- 条件の追跡が困難

### 改善後のコード

```php
public function enroll(User $user, Course $course)
{
    // 条件チェック → 早期リターン
    if (!$user->isStudent()) {
        throw new NotStudentException();
    }

    if ($course->status !== CourseStatus::Active) {
        throw new CourseNotActiveException();
    }

    if (!$course->hasCapacity()) {
        throw new CapacityExceededException();
    }

    if ($user->isEnrolledIn($course)) {
        throw new AlreadyEnrolledException();
    }

    // 正常系（メインの処理）
    return Enrollment::create([
        'user_id' => $user->id,
        'course_id' => $course->id,
    ]);
}
```

**改善点:**
- ネストが浅い
- 異常系を先に処理して除外
- 正常系が目立つ

### 原則

> 異常系を先に処理し、正常系を最後に残す

---

## 2. マジックナンバーの排除

### 問題のあるコード

```php
public function calculateFee(Course $course, User $user)
{
    $baseFee = 10000;

    if ($user->role === 'student') {
        return $baseFee * 0.8;  // 何の0.8？
    }

    if ($course->capacity > 30) {
        return $baseFee * 1.2;  // なぜ1.2？
    }

    return $baseFee;
}
```

**問題点:**
- `0.8` や `1.2` が何を意味するか不明
- 変更時に全ての箇所を探す必要がある
- テストで意図が伝わらない

### 改善後のコード（定数を使用）

```php
class CourseService
{
    private const BASE_FEE = 10000;
    private const STUDENT_DISCOUNT_RATE = 0.8;
    private const LARGE_CLASS_PREMIUM_RATE = 1.2;
    private const LARGE_CLASS_THRESHOLD = 30;

    public function calculateFee(Course $course, User $user): int
    {
        if ($user->isStudent()) {
            return (int) (self::BASE_FEE * self::STUDENT_DISCOUNT_RATE);
        }

        if ($course->capacity > self::LARGE_CLASS_THRESHOLD) {
            return (int) (self::BASE_FEE * self::LARGE_CLASS_PREMIUM_RATE);
        }

        return self::BASE_FEE;
    }
}
```

### さらに改善（Enumを使用）

```php
// app/Enums/DiscountType.php
enum DiscountType: string
{
    case Student = 'student';
    case EarlyBird = 'early_bird';
    case None = 'none';

    public function rate(): float
    {
        return match($this) {
            self::Student => 0.8,
            self::EarlyBird => 0.9,
            self::None => 1.0,
        };
    }
}
```

---

## 3. 意味のある名前

### 変数名

```php
// ❌ 悪い例
$d = 3;  // 何の日数？
$u = $request->user();  // user なのか url なのか
$temp = $course->capacity - $course->enrollments->count();

// ✅ 良い例
$daysUntilStart = 3;
$currentUser = $request->user();
$availableSeats = $course->capacity - $course->enrollments->count();
```

### メソッド名

```php
// ❌ 悪い例
public function process($data) { ... }  // 何を処理？
public function doStuff($user) { ... }  // 何をする？
public function handle($course) { ... }  // 曖昧

// ✅ 良い例
public function enrollUserInCourse($user, $course) { ... }
public function calculateEnrollmentFee($course) { ... }
public function sendEnrollmentConfirmationEmail($enrollment) { ... }
```

### 名前付けの原則

| 種類 | 命名規則 | 例 |
|------|---------|-----|
| ブール値 | is/has/can で始める | `$isActive`, `$hasCapacity`, `$canEnroll` |
| コレクション | 複数形 | `$courses`, `$enrollments` |
| 取得メソッド | get で始める | `getActiveCourses()` |
| 判定メソッド | is/has/can で始める | `isEnrolled()`, `hasPermission()` |
| 変換メソッド | to で始める | `toArray()`, `toJson()` |

---

## 4. メソッドの責務を分割

### 問題のあるコード

```php
public function createCourseAndNotify(Request $request)
{
    // バリデーション
    $validated = $request->validate([
        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
        'capacity' => 'required|integer|min:1',
    ]);

    // 講座作成
    $course = Course::create([
        'title' => $validated['title'],
        'description' => $validated['description'] ?? null,
        'capacity' => $validated['capacity'],
        'instructor_id' => $request->user()->id,
        'status' => CourseStatus::Draft,
    ]);

    // 管理者に通知
    $admins = User::where('role', 'admin')->get();
    foreach ($admins as $admin) {
        Mail::to($admin->email)->send(new NewCourseMail($course));
    }

    // ログ記録
    Log::info('講座が作成されました', [
        'course_id' => $course->id,
        'instructor_id' => $course->instructor_id,
    ]);

    return new CourseResource($course);
}
```

**問題点:**
- 1つのメソッドが多くのことをしている
- テストしづらい
- 再利用できない

### 改善後のコード

```php
class CourseController extends Controller
{
    public function store(StoreCourseRequest $request)
    {
        $course = $this->courseService->create(
            $request->validated(),
            $request->user()
        );

        return new CourseResource($course);
    }
}

class CourseService
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function create(array $data, User $instructor): Course
    {
        $course = Course::create([
            ...$data,
            'instructor_id' => $instructor->id,
            'status' => CourseStatus::Draft,
        ]);

        $this->notificationService->notifyAdminsOfNewCourse($course);
        $this->logCourseCreation($course);

        return $course;
    }

    private function logCourseCreation(Course $course): void
    {
        Log::info('講座が作成されました', [
            'course_id' => $course->id,
            'instructor_id' => $course->instructor_id,
        ]);
    }
}

class NotificationService
{
    public function notifyAdminsOfNewCourse(Course $course): void
    {
        $admins = User::where('role', 'admin')->get();

        foreach ($admins as $admin) {
            Mail::to($admin->email)->send(new NewCourseMail($course));
        }
    }
}
```

### 単一責任原則（SRP）

> 1つのクラス/メソッドは1つのことだけを行う

---

## 5. コメントよりコードで語る

### 不要なコメント

```php
// ❌ コードを説明するだけのコメント
// ユーザーを取得
$user = User::find($id);

// 講座のタイトルを設定
$course->title = $title;
```

### 有用なコメント

```php
// ✅ なぜこうするのかを説明
// キャパシティの10%を予備として確保（キャンセル対応のため）
$availableSeats = (int) ($course->capacity * 0.9);

// ✅ 複雑なビジネスロジックの説明
// 講師は自分の講座を受講できない（利益相反防止）
if ($user->id === $course->instructor_id) {
    throw new CannotEnrollOwnCourseException();
}
```

### コメントが不要になるコード

```php
// ❌ コメントで補足が必要
// 講師かどうかチェック
if ($user->role === 'instructor') {
    // ...
}

// ✅ メソッド名で意図が明確
if ($user->isInstructor()) {
    // ...
}
```

---

## 6. 実践リファクタリング

### Before: Lesson 6 のコードを改善

```php
public function index(Request $request)
{
    $query = Course::with('instructor');

    if ($request->has('status')) {
        $query->where('status', $request->input('status'));
    }

    if ($request->has('instructor_name')) {
        $query->whereHas('instructor', function ($q) use ($request) {
            $q->where('name', 'like', '%' . $request->input('instructor_name') . '%');
        });
    }

    $perPage = $request->input('per_page', 15);
    $courses = $query->latest()->paginate($perPage);

    return new CourseCollection($courses);
}
```

### After: 責務を分離

```php
class CourseController extends Controller
{
    public function index(CourseIndexRequest $request)
    {
        $courses = Course::query()
            ->with('instructor')
            ->filter($request->filters())
            ->latest()
            ->paginate($request->perPage());

        return new CourseCollection($courses);
    }
}

// app/Http/Requests/CourseIndexRequest.php
class CourseIndexRequest extends FormRequest
{
    private const DEFAULT_PER_PAGE = 15;

    public function filters(): array
    {
        return $this->only(['status', 'instructor_name']);
    }

    public function perPage(): int
    {
        return $this->input('per_page', self::DEFAULT_PER_PAGE);
    }
}

// app/Models/Course.php にスコープを追加
public function scopeFilter($query, array $filters)
{
    return $query
        ->when($filters['status'] ?? null, fn ($q, $status) =>
            $q->where('status', $status)
        )
        ->when($filters['instructor_name'] ?? null, fn ($q, $name) =>
            $q->whereHas('instructor', fn ($q) =>
                $q->where('name', 'like', "%{$name}%")
            )
        );
}
```

---

## まとめ

このレッスンで学んだこと：

1. **早期リターン**
   - ネストを浅く保つ
   - 異常系を先に処理
   - 正常系を目立たせる

2. **マジックナンバー排除**
   - 定数やEnumを使う
   - 意味のある名前を付ける

3. **意味のある名前**
   - 変数・メソッドの目的を表す
   - 命名規則に従う

4. **責務の分割**
   - 1メソッド1責務
   - 再利用可能な単位に分ける

5. **コメントよりコード**
   - 「なぜ」を説明
   - コードで意図を表現

---

## 練習問題

### 問題1
以下のコードを早期リターンパターンでリファクタリングしてください。

```php
public function updateProfile(Request $request, User $user)
{
    if ($request->user()->id === $user->id) {
        if ($request->has('name')) {
            if (strlen($request->name) <= 255) {
                $user->name = $request->name;
                $user->save();
                return new UserResource($user);
            } else {
                return response()->json(['error' => '名前が長すぎます'], 422);
            }
        } else {
            return response()->json(['error' => '名前は必須です'], 422);
        }
    } else {
        return response()->json(['error' => '権限がありません'], 403);
    }
}
```

<details>
<summary>解答例</summary>

```php
public function updateProfile(Request $request, User $user)
{
    if ($request->user()->id !== $user->id) {
        return response()->json(['error' => '権限がありません'], 403);
    }

    if (!$request->has('name')) {
        return response()->json(['error' => '名前は必須です'], 422);
    }

    if (strlen($request->name) > 255) {
        return response()->json(['error' => '名前が長すぎます'], 422);
    }

    $user->name = $request->name;
    $user->save();

    return new UserResource($user);
}
```
</details>

### 問題2
以下のコードからマジックナンバーを排除してください。

```php
if ($course->enrollments->count() >= $course->capacity * 0.9) {
    // 残り10%になったら警告
}

if ($daysUntilStart <= 7) {
    // 開始1週間前
}
```

<details>
<summary>解答例</summary>

```php
private const CAPACITY_WARNING_THRESHOLD = 0.9;
private const DAYS_BEFORE_START_WARNING = 7;

$capacityUsageRate = $course->enrollments->count() / $course->capacity;
if ($capacityUsageRate >= self::CAPACITY_WARNING_THRESHOLD) {
    // 残り10%になったら警告
}

if ($daysUntilStart <= self::DAYS_BEFORE_START_WARNING) {
    // 開始1週間前
}
```
</details>

---

## 次のレッスン

[Lesson 8: データベース設計の基礎](./08-database-design.md) では、外部キー、インデックス、NULL制約など堅牢なDB設計の原則を学びます。
