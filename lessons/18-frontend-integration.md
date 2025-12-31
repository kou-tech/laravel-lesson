# Lesson 18: フロントエンドとの統合

## 学習目標

このレッスンでは、Inertiaを使ってフロントエンドと統合し、受講管理システムを完成させます。

### 到達目標
- Inertia::render() でデータを渡せる
- Inertia のフォーム送信を理解する
- バリデーションエラーの表示ができる
- 共有データ（Shared Data）を活用できる

---

## Inertiaとは？

### モダンなモノリス

Inertiaは、**サーバーサイドルーティング**と**SPAのUX**を両立させるアダプターです。

```
従来のSPA:
フロントエンド（React） ←→ API（Laravel）
- 別々のプロジェクト
- 認証の複雑さ
- CORS対応

Inertia:
Laravel + React（同一プロジェクト）
- サーバーサイドルーティング
- セッション認証
- 完全なTypeScript対応
```

---

## Step 1: Inertia の基本

### コントローラーからページを表示

```php
use Inertia\Inertia;

class CourseController extends Controller
{
    public function index()
    {
        $courses = Course::with('instructor')
            ->withCount('enrollments')
            ->active()
            ->paginate(12);

        return Inertia::render('Courses/Index', [
            'courses' => CourseResource::collection($courses),
        ]);
    }

    public function show(Course $course)
    {
        return Inertia::render('Courses/Show', [
            'course' => new CourseResource($course->load('instructor')),
            'isEnrolled' => auth()->check()
                ? $course->enrollments()->where('user_id', auth()->id())->exists()
                : false,
        ]);
    }
}
```

### React コンポーネント

```tsx
// resources/js/pages/Courses/Index.tsx

import { Head, Link } from '@inertiajs/react';

interface Course {
    id: number;
    title: string;
    instructor: { name: string };
    enrollments_count: number;
    capacity: number;
}

interface Props {
    courses: {
        data: Course[];
        meta: { current_page: number; last_page: number };
    };
}

export default function CoursesIndex({ courses }: Props) {
    return (
        <>
            <Head title="講座一覧" />

            <div className="container mx-auto px-4 py-8">
                <h1 className="text-2xl font-bold mb-6">講座一覧</h1>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {courses.data.map((course) => (
                        <div key={course.id} className="border rounded-lg p-4">
                            <h2 className="text-lg font-semibold">
                                <Link href={`/courses/${course.id}`}>
                                    {course.title}
                                </Link>
                            </h2>
                            <p className="text-gray-600">
                                講師: {course.instructor.name}
                            </p>
                            <p className="text-sm">
                                受講者: {course.enrollments_count} / {course.capacity}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
```

---

## Step 2: フォームの送信

### useForm フック

```tsx
// resources/js/pages/Courses/Create.tsx

import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function CoursesCreate() {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        capacity: 20,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/courses');
    };

    return (
        <>
            <Head title="講座作成" />

            <div className="container mx-auto px-4 py-8">
                <h1 className="text-2xl font-bold mb-6">講座作成</h1>

                <form onSubmit={submit} className="max-w-lg">
                    <div className="mb-4">
                        <label className="block text-sm font-medium mb-1">
                            タイトル
                        </label>
                        <input
                            type="text"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            className="w-full border rounded px-3 py-2"
                        />
                        {errors.title && (
                            <p className="text-red-500 text-sm mt-1">
                                {errors.title}
                            </p>
                        )}
                    </div>

                    <div className="mb-4">
                        <label className="block text-sm font-medium mb-1">
                            説明
                        </label>
                        <textarea
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            className="w-full border rounded px-3 py-2"
                            rows={4}
                        />
                    </div>

                    <div className="mb-4">
                        <label className="block text-sm font-medium mb-1">
                            定員
                        </label>
                        <input
                            type="number"
                            value={data.capacity}
                            onChange={(e) => setData('capacity', parseInt(e.target.value))}
                            className="w-full border rounded px-3 py-2"
                            min={1}
                            max={100}
                        />
                        {errors.capacity && (
                            <p className="text-red-500 text-sm mt-1">
                                {errors.capacity}
                            </p>
                        )}
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-blue-600 text-white px-4 py-2 rounded disabled:opacity-50"
                    >
                        {processing ? '作成中...' : '作成する'}
                    </button>
                </form>
            </div>
        </>
    );
}
```

### コントローラー

```php
public function store(StoreCourseRequest $request)
{
    $course = $this->courseService->create(
        $request->validated(),
        $request->user()
    );

    return redirect()
        ->route('courses.show', $course)
        ->with('success', '講座を作成しました');
}
```

---

## Step 3: 受講登録フロー

### 講座詳細ページ

```tsx
// resources/js/pages/Courses/Show.tsx

import { Head, useForm } from '@inertiajs/react';

interface Props {
    course: {
        id: number;
        title: string;
        description: string;
        instructor: { name: string };
        capacity: number;
        enrollments_count: number;
    };
    isEnrolled: boolean;
}

export default function CoursesShow({ course, isEnrolled }: Props) {
    const enrollForm = useForm({});
    const cancelForm = useForm({});

    const handleEnroll = () => {
        enrollForm.post(`/courses/${course.id}/enroll`);
    };

    const handleCancel = () => {
        if (confirm('本当にキャンセルしますか？')) {
            cancelForm.delete(`/courses/${course.id}/enroll`);
        }
    };

    const availableSeats = course.capacity - course.enrollments_count;
    const isFull = availableSeats <= 0;

    return (
        <>
            <Head title={course.title} />

            <div className="container mx-auto px-4 py-8">
                <h1 className="text-3xl font-bold mb-4">{course.title}</h1>

                <div className="mb-6">
                    <p className="text-gray-600">講師: {course.instructor.name}</p>
                    <p className="text-gray-600">
                        残席: {availableSeats} / {course.capacity}
                    </p>
                </div>

                <div className="prose mb-8">
                    <p>{course.description}</p>
                </div>

                <div>
                    {isEnrolled ? (
                        <div>
                            <p className="text-green-600 mb-2">受講登録済みです</p>
                            <button
                                onClick={handleCancel}
                                disabled={cancelForm.processing}
                                className="bg-red-600 text-white px-4 py-2 rounded"
                            >
                                キャンセルする
                            </button>
                        </div>
                    ) : isFull ? (
                        <p className="text-red-600">満席です</p>
                    ) : (
                        <button
                            onClick={handleEnroll}
                            disabled={enrollForm.processing}
                            className="bg-blue-600 text-white px-4 py-2 rounded"
                        >
                            受講登録する
                        </button>
                    )}
                </div>
            </div>
        </>
    );
}
```

### コントローラー

```php
class EnrollmentController extends Controller
{
    public function store(Request $request, Course $course)
    {
        $this->enrollmentService->enroll($request->user(), $course);

        return back()->with('success', '受講登録が完了しました');
    }

    public function destroy(Request $request, Course $course)
    {
        $this->enrollmentService->cancel($request->user(), $course);

        return back()->with('success', 'キャンセルしました');
    }
}
```

---

## Step 4: 共有データ（Shared Data）

### HandleInertiaRequests ミドルウェア

`app/Http/Middleware/HandleInertiaRequests.php`:

```php
public function share(Request $request): array
{
    return array_merge(parent::share($request), [
        // 認証ユーザー情報
        'auth' => [
            'user' => $request->user() ? [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'role' => $request->user()->role->value,
            ] : null,
        ],

        // フラッシュメッセージ
        'flash' => [
            'success' => fn () => $request->session()->get('success'),
            'error' => fn () => $request->session()->get('error'),
        ],
    ]);
}
```

### React で利用

```tsx
// resources/js/pages/Courses/Show.tsx

import { usePage } from '@inertiajs/react';

interface PageProps {
    auth: {
        user: { id: number; name: string; role: string } | null;
    };
    flash: {
        success: string | null;
        error: string | null;
    };
}

export default function CoursesShow({ course, isEnrolled }: Props) {
    const { auth, flash } = usePage<PageProps>().props;

    return (
        <>
            {flash.success && (
                <div className="bg-green-100 text-green-800 p-4 mb-4 rounded">
                    {flash.success}
                </div>
            )}

            {auth.user?.role === 'instructor' && (
                <p>講師としてログイン中です</p>
            )}

            {/* ... */}
        </>
    );
}
```

---

## Step 5: ルーティングの整理

### web.php

```php
// routes/web.php

use App\Http\Controllers\CourseController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\DashboardController;

Route::get('/', function () {
    return Inertia::render('welcome');
});

// 認証不要
Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
Route::get('/courses/{course}', [CourseController::class, 'show'])->name('courses.show');

// 認証必要
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // 講座管理（講師のみ）
    Route::middleware('can:create,App\Models\Course')->group(function () {
        Route::get('/courses/create', [CourseController::class, 'create'])->name('courses.create');
        Route::post('/courses', [CourseController::class, 'store'])->name('courses.store');
        Route::get('/courses/{course}/edit', [CourseController::class, 'edit'])->name('courses.edit');
        Route::patch('/courses/{course}', [CourseController::class, 'update'])->name('courses.update');
    });

    // 受講管理（生徒のみ）
    Route::post('/courses/{course}/enroll', [EnrollmentController::class, 'store'])->name('enrollments.store');
    Route::delete('/courses/{course}/enroll', [EnrollmentController::class, 'destroy'])->name('enrollments.destroy');

    // マイページ
    Route::get('/my/enrollments', [EnrollmentController::class, 'index'])->name('my.enrollments');
});
```

---

## Step 6: マイページの実装

### 受講一覧ページ

```php
// app/Http/Controllers/EnrollmentController.php

public function index(Request $request)
{
    $enrollments = $request->user()
        ->enrollments()
        ->with('course.instructor')
        ->latest('enrolled_at')
        ->paginate(10);

    return Inertia::render('My/Enrollments', [
        'enrollments' => EnrollmentResource::collection($enrollments),
    ]);
}
```

```tsx
// resources/js/pages/My/Enrollments.tsx

export default function MyEnrollments({ enrollments }: Props) {
    return (
        <>
            <Head title="受講一覧" />

            <div className="container mx-auto px-4 py-8">
                <h1 className="text-2xl font-bold mb-6">受講中の講座</h1>

                {enrollments.data.length === 0 ? (
                    <p>受講中の講座はありません</p>
                ) : (
                    <div className="space-y-4">
                        {enrollments.data.map((enrollment) => (
                            <div
                                key={enrollment.id}
                                className="border rounded-lg p-4 flex justify-between items-center"
                            >
                                <div>
                                    <h2 className="font-semibold">
                                        <Link href={`/courses/${enrollment.course.id}`}>
                                            {enrollment.course.title}
                                        </Link>
                                    </h2>
                                    <p className="text-sm text-gray-600">
                                        登録日: {enrollment.enrolled_at}
                                    </p>
                                </div>
                                <span className={`px-2 py-1 rounded text-sm ${
                                    enrollment.status === 'enrolled'
                                        ? 'bg-green-100 text-green-800'
                                        : 'bg-gray-100 text-gray-800'
                                }`}>
                                    {enrollment.status_label}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
```

---

## Step 7: 動作確認

### 全体の流れを確認

1. **講座一覧を表示**: `/courses`
2. **講座詳細を表示**: `/courses/1`
3. **受講登録**: 「受講登録する」ボタン
4. **マイページで確認**: `/my/enrollments`
5. **受講キャンセル**: 「キャンセルする」ボタン

---

## まとめ

このレッスンで学んだこと：

1. **Inertia::render()**
   - サーバーからReactにデータを渡す
   - 型安全なpropsで受け取り

2. **useForm フック**
   - フォームの状態管理
   - バリデーションエラーの自動処理

3. **共有データ**
   - 認証情報の共有
   - フラッシュメッセージ

4. **SPA的なUX**
   - ページ遷移が高速
   - フォーム送信がスムーズ

---

## カリキュラム完了

これで全18レッスンが完了しました。

### 完成した受講管理システム

- **ユーザー認証**: Fortify
- **API**: RESTful設計
- **認可**: Gate/Policy
- **データベース**: 適切な制約
- **ビジネスロジック**: サービスクラス
- **テスト**: Feature/Unit テスト
- **非同期処理**: メール/キュー
- **フロントエンド**: Inertia + React

### 今後の学習

- TypeScript の深掘り
- Laravel のキャッシュ戦略
- CI/CD パイプライン
- Docker での開発環境
- AWS/GCP へのデプロイ

お疲れ様でした！
