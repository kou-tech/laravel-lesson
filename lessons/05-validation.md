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


## Step 2 FormRequestを使う理由

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

実際の作成・実装・使い方は Step 4 で `User/UpdateRequest` を作りながら手を動かして学びます。

> 命名・配置の方針: 本コースでは FormRequest をリソース名のサブディレクトリにまとめ、クラス名はアクションのみ（`StoreRequest` / `UpdateRequest` など）に揃えます。`app/Http/Requests/` 直下が肥大化せず、クラス名も短くなるためです。


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

### Enumルール

PHPの `enum` を使っているフィールドは、`Rule::enum()` でそのenumに定義されている値のみを許可できます。

```php
use Illuminate\Validation\Rule;
use App\Enums\CourseStatus;

public function rules(): array
{
    return [
        'status' => ['required', Rule::enum(CourseStatus::class)],
    ];
}
```

| ルール | 説明 | 例 |
|--------|------|-----|
| Rule::enum | 指定したEnumの値のみ許可 | `'status' => [Rule::enum(CourseStatus::class)]` |

ポイント
- 不正な値（enumに定義されていない文字列など）が送られると自動で422エラー
- enum側に値を追加/削除すれば、バリデーションルールも自動的に追従する

## Step 4 実践例 - ユーザー更新API

### User/UpdateRequest の作成

`make app` でコンテナに入ってから、以下のコマンドを実行します。

```bash
php artisan make:request User/UpdateRequest
```

`app/Http/Requests/User/UpdateRequest.php` が生成されます（名前空間は `App\Http\Requests\User`、クラス名は `UpdateRequest`）。

```php
<?php

namespace App\Http\Requests\User;

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
use App\Http\Requests\User\UpdateRequest;

public function update(UpdateRequest $request, User $user): UserResource
{
    $user->update($request->validated());

    return new UserResource($user);
}
```

### 動作確認

Postmanのコレクション内の `api > users > {userId} > users_id`（PATCH）で確認してください。

> 補足
> - `userId` は `Path Variables` に `1` が事前設定されています。
> - Body には有効なサンプル（`name`, `email`）が事前設定されています。バリデーションエラーを発生させるために、`email` の値を以下のように不正な形式に書き換えて送信してください。
>
> ```json
> {
>     "name": "更新後の名前",
>     "email": "invalid-email"
> }
> ```

レスポンス（ステータスコード 422）

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


## Step 5 バリデーションのベストプラクティス

### 1. 命名規則

リソース名のサブディレクトリを切り、クラス名はアクション名のみで表します。

```
app/Http/Requests/
├── User/
│   ├── StoreRequest.php   - 作成用
│   └── UpdateRequest.php  - 更新用
└── Course/
    ├── StoreRequest.php
    └── UpdateRequest.php
```

生成コマンドは `php artisan make:request User/UpdateRequest` のようにスラッシュで区切ります。`app/Http/Requests/` 直下の肥大化を避け、クラス名も短く保てます。

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
講座作成用の `Course/StoreRequest` を作成してください（`php artisan make:request Course/StoreRequest`）。以下のルールを設定してください。
- `title` は必須、文字列、最大255文字
- `description` は任意、文字列
- `capacity` は必須、整数、1以上100以下

<details>
<summary>解答例</summary>

```php
<?php

namespace App\Http\Requests\Course;

use Illuminate\Foundation\Http\FormRequest;

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
