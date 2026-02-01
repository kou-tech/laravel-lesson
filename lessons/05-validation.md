# Lesson 5 バリデーション

## 学習目標

このレッスンでは、FormRequestを使った堅牢なバリデーション設計を学びます。

### 到達目標
- FormRequestクラスを作成できる
- バリデーションルールを適切に設定できる
- 条件付きバリデーションを実装できる


## なぜバリデーションが重要か

ユーザーからの入力を信頼してはいけません。

```php
// 悪い例: バリデーションなし
public function store(Request $request)
{
    User::create([
        'name' => $request->name,     // 空かもしれない
        'email' => $request->email,   // 無効な形式かもしれない
        'age' => $request->age,       // 文字列かもしれない
    ]);
}
```

問題点
- 不正なデータがデータベースに保存される
- アプリケーションがクラッシュする可能性
- セキュリティリスク


## Step 1 Controllerでの基本的なバリデーション

### validate メソッド

```php
public function store(Request $request)
{
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users'],
    ]);

    // バリデーション成功時のみここに到達
    $user = User::create($validated);

    return new UserResource($user);
}
```

バリデーションに失敗すると、自動的に422エラーが返ります。

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "name": ["名前は必須です。"],
        "email": ["このメールアドレスは既に使用されています。"]
    }
}
```


## Step 2 FormRequestの作成

### なぜFormRequestを使うのか

Controllerにバリデーションを書くと、

```php
public function store(Request $request)
{
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users'],
        'password' => ['required', 'string', 'min:8', 'confirmed'],
        // ... 10行以上のルール
    ]);

    // 実際のロジック
}
```

Controllerが肥大化し、テストも難しくなります。

FormRequestに分離すると、
- Controllerがシンプルになる
- バリデーションロジックを再利用できる
- 単体テストが書きやすい

### FormRequestの生成

```bash
php artisan make:request StoreUserRequest
```

`app/Http/Requests/StoreUserRequest.php` が作成されます。

### FormRequestの実装

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    /**
     * リクエストの認可判定
     */
    public function authorize(): bool
    {
        // 認可ロジックはPolicyで行うため、ここではtrueを返す
        return true;
    }

    /**
     * バリデーションルール
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
```

### Controllerでの使用

```php
use App\Http\Requests\StoreUserRequest;

public function store(StoreUserRequest $request)
{
    // バリデーション済みのデータを取得
    $validated = $request->validated();

    $user = User::create($validated);

    return new UserResource($user);
}
```

型宣言でFormRequestを指定するだけで、自動的にバリデーションが実行されます。


## Step 3 よく使うバリデーションルール

### 基本ルール

| ルール | 説明 | 例 |
|--------|------|-----|
| required | 必須 | `'name' => ['required']` |
| nullable | null許可 | `'bio' => ['nullable', 'string']` |
| string | 文字列 | `'name' => ['string']` |
| integer | 整数 | `'age' => ['integer']` |
| boolean | 真偽値 | `'active' => ['boolean']` |
| array | 配列 | `'tags' => ['array']` |

### 文字列ルール

| ルール | 説明 | 例 |
|--------|------|-----|
| max:255 | 最大文字数 | `'name' => ['max:255']` |
| min:8 | 最小文字数 | `'password' => ['min:8']` |
| email | メール形式 | `'email' => ['email']` |
| url | URL形式 | `'website' => ['url']` |
| regex | 正規表現 | `'code' => ['regex:/^[A-Z]{3}$/']` |

### 数値ルール

| ルール | 説明 | 例 |
|--------|------|-----|
| min:1 | 最小値 | `'capacity' => ['integer', 'min:1']` |
| max:100 | 最大値 | `'capacity' => ['integer', 'max:100']` |
| between:1,100 | 範囲 | `'capacity' => ['between:1,100']` |

### データベースルール

| ルール | 説明 | 例 |
|--------|------|-----|
| unique:users | 一意制約 | `'email' => ['unique:users']` |
| exists:courses,id | 存在確認 | `'course_id' => ['exists:courses,id']` |

### 更新時のuniqueルール

更新時は自分自身を除外する必要があります。

```php
use Illuminate\Validation\Rule;

public function rules(): array
{
    return [
        'email' => [
            'required',
            'email',
            Rule::unique('users')->ignore($this->user),
        ],
    ];
}
```

## Step 4 条件付きバリデーション

### sometimes ルール

フィールドが存在する場合のみバリデーション

```php
public function rules(): array
{
    return [
        'name' => ['sometimes', 'string', 'max:255'],
        'email' => ['sometimes', 'email'],
    ];
}
```

PATCH リクエストで部分更新する場合に便利です。

### 動的なルール

```php
public function rules(): array
{
    $rules = [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email'],
    ];

    // 新規作成時のみパスワード必須
    if ($this->isMethod('post')) {
        $rules['password'] = ['required', 'string', 'min:8'];
    }

    return $rules;
}
```

### withValidator メソッド

複雑な条件付きバリデーション

```php
public function withValidator($validator)
{
    $validator->sometimes('capacity', 'min:10', function ($input) {
        return $input->status === 'active';
    });
}
```


## Step 5 実践例 - ユーザー更新API

### UpdateUserRequestの作成

```bash
php artisan make:request UpdateUserRequest
```

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                Rule::unique('users')->ignore($this->user),
            ],
        ];
    }
}
```

### Controllerでの使用

```php
use App\Http\Requests\UpdateUserRequest;

public function update(UpdateUserRequest $request, User $user): UserResource
{
    $user->update($request->validated());

    return new UserResource($user);
}
```

### 動作確認

- ユーザー更新

```json
{
    "message": "The email field must be a valid email address.",
    "errors": {
        "email": [
            "The email field must be a valid email address."
        ]
    }
}
```


## Step 6 バリデーションのベストプラクティス

### 1. 命名規則

```
Store{Model}Request  - 作成用
Update{Model}Request - 更新用
```

### 2. 適切なルールの選択

```php
// 悪い例: 文字列なのにmax:255がない
'name' => ['required', 'string'],

// 良い例: データベースのカラム長に合わせる
'name' => ['required', 'string', 'max:255'],
```

### 3. nullable vs sometimes

```php
// nullable: 値がnullでもOK（フィールドは必須）
'bio' => ['nullable', 'string'],

// sometimes: フィールド自体がなくてもOK（あればバリデーション）
'bio' => ['sometimes', 'string'],
```

## 練習問題

### 問題1
講座作成用の `StoreCourseRequest` を作成してください。以下のルールを設定してください。
- `title` は必須、文字列、最大255文字
- `description` は任意、文字列
- `capacity` は必須、整数、1以上100以下

<details>
<summary>解答例</summary>

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
```
</details>

### 問題2
`sometimes` ルールと `required` ルールの違いを説明してください。PATCHリクエストではどちらが適切ですか。

<details>
<summary>解答例</summary>

- `required` はリクエストにそのフィールドが必ず含まれている必要がある
- `sometimes` はフィールドが存在する場合のみバリデーションを実行する（フィールド自体がなくてもエラーにならない）

PATCHリクエストでは `sometimes` が適切です。PATCHは部分更新のため、変更したいフィールドのみ送信します。`required` にすると全フィールドの送信が必要になり、PUTと同じ動作になってしまいます。

</details>

## 参考資料

- [Laravel 公式ドキュメント - Validation](https://laravel.com/docs/validation)
- [Laravel 公式ドキュメント - Form Request Validation](https://laravel.com/docs/validation#form-request-validation)


## 次のレッスン

[Lesson 6 認証の仕組みを理解する](./06-authentication.md) では、Fortifyを使った認証機能の仕組みを学びます。
