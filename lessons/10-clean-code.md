# Lesson 10 良いコードを書く

## 学習目標

このレッスンでは、可読性の高い保守しやすいコードを書くための原則を学びます。

### 到達目標
- 早期リターン（Early Return）パターンを使える
- マジックナンバーを排除できる
- 適切な変数名・メソッド名を付けられる
- メソッドの責務を分割できる

## なぜ「良いコード」が重要か？

コードは書く時間より読む時間の方が長いです。

- 自分が書いたコードを3ヶ月後に読み返す
- チームメンバーがコードをレビューする
- バグ修正のために調査する

読みやすいコードはバグが発見しやすく、変更が容易で、引き継ぎも楽になります。

## 1. 早期リターン（Early Return）

### 問題のあるコード

```php
public function attend(User $user, Course $course)
{
    if ($user->isStudent()) {
        if ($course->status === CourseStatus::Active) {
            if ($course->hasCapacity()) {
                if (!$user->hasAttendanceFor($course)) {
                    // 出席登録処理
                    $attendance = Attendance::create([
                        'user_id' => $user->id,
                        'course_id' => $course->id,
                    ]);
                    return $attendance;
                } else {
                    throw new AlreadyAttendedException();
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

このコードには問題があります。ネストが深く、正常系が奥深くにあり、条件の追跡が困難です。

### 改善後のコード

```php
public function attend(User $user, Course $course)
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

    if ($user->hasAttendanceFor($course)) {
        throw new AlreadyAttendedException();
    }

    // 正常系（メインの処理）
    return Attendance::create([
        'user_id' => $user->id,
        'course_id' => $course->id,
    ]);
}
```

改善点として、ネストが浅くなり、異常系を先に処理して除外することで、正常系が目立つようになりました。

### 原則

> 異常系を先に処理し、正常系を最後に残す

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

このコードには問題があります。`0.8` や `1.2` が何を意味するか不明で、変更時に全ての箇所を探す必要があり、テストで意図が伝わりません。

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

## 3. 意味のある名前

### 変数名

```php
// ❌ 悪い例
$d = 3;  // 何の日数？
$u = $request->user();  // user なのか url なのか
$temp = $course->capacity - $course->attendances->count();

// ✅ 良い例
$daysUntilStart = 3;
$currentUser = $request->user();
$availableSeats = $course->capacity - $course->attendances->count();
```

### メソッド名

```php
// ❌ 悪い例
public function process($data) { ... }  // 何を処理？
public function doStuff($user) { ... }  // 何をする？
public function handle($course) { ... }  // 曖昧

// ✅ 良い例
public function recordUserAttendance($user, $course) { ... }
public function calculateAttendanceFee($course) { ... }
public function sendAttendanceConfirmationEmail($attendance) { ... }
```

### 名前付けの原則

| 種類 | 命名規則 | 例 |
|------|---------|-----|
| ブール値 | is/has/can で始める | `$isActive`, `$hasCapacity`, `$canAttend` |
| コレクション | 複数形 | `$courses`, `$attendances` |
| 取得メソッド | get で始める | `getActiveCourses()` |
| 判定メソッド | is/has/can で始める | `hasAttendance()`, `hasPermission()` |
| 変換メソッド | to で始める | `toArray()`, `toJson()` |

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

このコードには問題があります。1つのメソッドが多くのことをしており、テストしづらく、再利用できません。

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
    throw new CannotAttendOwnCourseException();
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

### 問題2
以下のコードからマジックナンバーを排除してください。

```php
if ($course->attendances->count() >= $course->capacity * 0.9) {
    // 残り10%になったら警告
}

if ($daysUntilStart <= 7) {
    // 開始1週間前
}
```

## 参考資料

- [リーダブルコード ―より良いコードを書くためのシンプルで実践的なテクニック（オライリー）](https://www.oreilly.co.jp/books/9784873115658/)

## 次のレッスン

[Lesson 11 データベース設計の基礎](./11-database-design.md) では、外部キー、インデックス、NULL制約など堅牢なDB設計の原則を学びます。
