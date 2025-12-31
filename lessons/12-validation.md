# Lesson 12: FormRequestによるバリデーション

## 学習目標

このレッスンでは、FormRequestを使った堅牢なバリデーション設計を習得します。

### 到達目標
- FormRequest クラスを作成できる
- バリデーションルールを定義できる
- カスタムバリデーションを作成できる
- エラーメッセージをカスタマイズできる

---

## なぜFormRequestを使うか？

### コントローラー内でのバリデーション（問題あり）

```php
public function store(Request $request)
{
    $validated = $request->validate([
        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
        'capacity' => 'required|integer|min:1|max:100',
        'start_date' => 'required|date|after:today',
    ]);

    $course = Course::create($validated);

    return new CourseResource($course);
}
```

**問題点**:
- コントローラーが肥大化
- 同じルールを複数箇所で使い回しにくい
- 認可ロジックと混在

### FormRequestを使う（推奨）

```php
public function store(StoreCourseRequest $request)
{
    $course = Course::create($request->validated());

    return new CourseResource($course);
}
```

シンプルになり、関心の分離ができます。

---

## Step 1: FormRequestの作成

### コマンドで生成

```bash
php artisan make:request StoreCourseRequest
```

`app/Http/Requests/StoreCourseRequest.php` が作成されます。

### 基本的な実装

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCourseRequest extends FormRequest
{
    /**
     * リクエストの認可チェック
     */
    public function authorize(): bool
    {
        // 認可チェックをここで行う場合
        return $this->user()->isInstructor();

        // Policyで行う場合はtrueを返す
        // return true;
    }

    /**
     * バリデーションルール
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100'],
            'start_date' => ['required', 'date', 'after:today'],
        ];
    }
}
```

---

## Step 2: よく使うバリデーションルール

### 文字列系

```php
'name' => ['required', 'string', 'max:255'],
'email' => ['required', 'email', 'unique:users,email'],
'url' => ['nullable', 'url'],
'slug' => ['required', 'alpha_dash'],  // 英数字、-、_のみ
```

### 数値系

```php
'age' => ['required', 'integer', 'min:0', 'max:150'],
'price' => ['required', 'numeric', 'min:0'],
'rating' => ['required', 'integer', 'between:1,5'],
```

### 日付系

```php
'start_date' => ['required', 'date'],
'end_date' => ['required', 'date', 'after:start_date'],
'birth_date' => ['required', 'date', 'before:today'],
```

### 配列系

```php
'tags' => ['required', 'array', 'min:1', 'max:5'],
'tags.*' => ['string', 'max:50'],  // 各要素のルール
```

### ファイル系

```php
'avatar' => ['nullable', 'image', 'max:2048'],  // 2MBまで
'document' => ['required', 'file', 'mimes:pdf,doc,docx'],
```

### 存在チェック

```php
'user_id' => ['required', 'exists:users,id'],
'course_id' => ['required', 'exists:courses,id,status,active'],  // 条件付き
```

### 条件付きルール

```php
'nickname' => ['required_if:is_public,true'],
'reason' => ['required_unless:status,active'],
```

---

## Step 3: カスタムエラーメッセージ

### messages() メソッド

```php
public function messages(): array
{
    return [
        'title.required' => 'タイトルは必須です。',
        'title.max' => 'タイトルは:max文字以内で入力してください。',
        'capacity.min' => '定員は:min人以上で設定してください。',
        'start_date.after' => '開始日は明日以降の日付を指定してください。',
    ];
}
```

### attributes() メソッド（フィールド名の表示）

```php
public function attributes(): array
{
    return [
        'title' => '講座タイトル',
        'capacity' => '定員',
        'start_date' => '開始日',
    ];
}
```

これにより、エラーメッセージが:

- Before: `The title field is required.`
- After: `講座タイトルは必須です。`

---

## Step 4: prepareForValidation()

### バリデーション前のデータ加工

```php
protected function prepareForValidation(): void
{
    $this->merge([
        // スラッグを自動生成
        'slug' => Str::slug($this->title),

        // 空文字をnullに変換
        'description' => $this->description ?: null,

        // 電話番号のハイフンを除去
        'phone' => str_replace('-', '', $this->phone ?? ''),
    ]);
}
```

---

## Step 5: カスタムバリデーションルール

### クロージャを使う（シンプルなケース）

```php
public function rules(): array
{
    return [
        'coupon_code' => [
            'nullable',
            'string',
            function (string $attribute, mixed $value, Closure $fail) {
                if (!Coupon::where('code', $value)->where('is_active', true)->exists()) {
                    $fail('無効なクーポンコードです。');
                }
            },
        ],
    ];
}
```

### Ruleクラスを使う（再利用したいケース）

```bash
php artisan make:rule ValidCouponCode
```

`app/Rules/ValidCouponCode.php`:

```php
<?php

namespace App\Rules;

use App\Models\Coupon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidCouponCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $coupon = Coupon::where('code', $value)
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->first();

        if (!$coupon) {
            $fail('無効または期限切れのクーポンコードです。');
        }
    }
}
```

使用:

```php
use App\Rules\ValidCouponCode;

public function rules(): array
{
    return [
        'coupon_code' => ['nullable', 'string', new ValidCouponCode],
    ];
}
```

---

## Step 6: 更新用のFormRequest

### UpdateCourseRequest

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Policyで認可チェックするため true
        return true;
    }

    public function rules(): array
    {
        return [
            // sometimes: フィールドが存在する場合のみバリデーション
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'closed'])],
        ];
    }
}
```

### unique ルールで自分自身を除外

```php
use Illuminate\Validation\Rule;

public function rules(): array
{
    return [
        'email' => [
            'sometimes',
            'email',
            Rule::unique('users')->ignore($this->user),  // ルートパラメータ
        ],
    ];
}
```

---

## Step 7: 複雑なバリデーション

### withValidator() でカスタムロジック

```php
public function withValidator($validator): void
{
    $validator->after(function ($validator) {
        if ($this->hasConflictingSchedule()) {
            $validator->errors()->add(
                'start_date',
                '同じ時間帯に別の講座が予定されています。'
            );
        }
    });
}

private function hasConflictingSchedule(): bool
{
    return Course::where('instructor_id', $this->user()->id)
        ->where('start_date', $this->start_date)
        ->exists();
}
```

### failedValidation() でレスポンスをカスタマイズ

```php
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

protected function failedValidation(Validator $validator): void
{
    throw new HttpResponseException(
        response()->json([
            'success' => false,
            'message' => 'バリデーションエラー',
            'errors' => $validator->errors(),
        ], 422)
    );
}
```

---

## Step 8: EnrollmentRequest の実装

```php
<?php

namespace App\Http\Requests;

use App\Models\Course;
use App\Models\Enrollment;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class EnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 生徒のみ受講可能
        return $this->user()->isStudent();
    }

    public function rules(): array
    {
        return [
            // ルートパラメータのcourseを検証
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $course = $this->route('course');

            // 講座が公開中か
            if (!$course->isActive()) {
                $validator->errors()->add('course', 'この講座は現在受付していません。');
            }

            // 定員に空きがあるか
            if (!$course->hasCapacity()) {
                $validator->errors()->add('course', 'この講座は定員に達しています。');
            }

            // 既に受講していないか
            if ($this->isAlreadyEnrolled($course)) {
                $validator->errors()->add('course', '既にこの講座に登録されています。');
            }
        });
    }

    private function isAlreadyEnrolled(Course $course): bool
    {
        return Enrollment::where('user_id', $this->user()->id)
            ->where('course_id', $course->id)
            ->exists();
    }

    public function messages(): array
    {
        return [
            'course.required' => '講座を指定してください。',
        ];
    }
}
```

---

## Step 9: バリデーションエラーのレスポンス

### デフォルトのレスポンス（API）

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "title": [
            "タイトルは必須です。"
        ],
        "capacity": [
            "定員は1人以上で設定してください。"
        ]
    }
}
```

### フロントエンドでの処理

```typescript
// React/Inertiaの例
const { errors } = usePage().props;

return (
    <form>
        <input name="title" />
        {errors.title && <span className="error">{errors.title}</span>}
    </form>
);
```

---

## まとめ

このレッスンで学んだこと：

1. **FormRequest**
   - コントローラーからバリデーションを分離
   - `authorize()` で認可チェック

2. **バリデーションルール**
   - 文字列、数値、日付、配列など
   - `exists`, `unique` でDB確認

3. **カスタマイズ**
   - `messages()` でエラーメッセージ
   - `attributes()` でフィールド名

4. **カスタムルール**
   - クロージャ（シンプル）
   - Ruleクラス（再利用可能）

5. **高度な使い方**
   - `prepareForValidation()` で前処理
   - `withValidator()` で複雑なロジック

---

## 練習問題

### 問題1
ユーザー登録用の `RegisterUserRequest` を作成してください。以下のルールを実装:
- name: 必須、255文字以内
- email: 必須、メール形式、ユニーク
- password: 必須、8文字以上、確認用と一致

<details>
<summary>解答例</summary>

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '名前は必須です。',
            'email.unique' => 'このメールアドレスは既に登録されています。',
            'password.confirmed' => 'パスワードが確認用と一致しません。',
        ];
    }
}
```
</details>

### 問題2
講座のタイトルが重複していないか確認するカスタムルール `UniqueCourseTitle` を作成してください（同じ講師の講座内で重複不可）。

<details>
<summary>解答例</summary>

```php
<?php

namespace App\Rules;

use App\Models\Course;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueCourseTitle implements ValidationRule
{
    public function __construct(
        private int $instructorId,
        private ?int $excludeCourseId = null
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = Course::where('instructor_id', $this->instructorId)
            ->where('title', $value);

        if ($this->excludeCourseId) {
            $query->where('id', '!=', $this->excludeCourseId);
        }

        if ($query->exists()) {
            $fail('同じタイトルの講座が既に存在します。');
        }
    }
}
```

使用:

```php
'title' => ['required', 'string', new UniqueCourseTitle($this->user()->id)],
```
</details>

---

## 次のレッスン

[Lesson 13: サービスコンテナとDI](./13-di-container.md) では、Laravelのサービスコンテナと依存性注入を学びます。
