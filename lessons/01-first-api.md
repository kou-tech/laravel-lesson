# Lesson 1: はじめてのAPI実装

## 学習目標

このレッスンでは、LaravelでシンプルなAPIエンドポイントを作成し、API Resourceを使ったレスポンス整形の基本を理解します。

### 到達目標
- `/api/user` エンドポイントを作成できる
- `UserController` でユーザー情報を取得して返せる
- `UserResource` を使ってレスポンス形式を整えられる

---

## Step 1: APIルーティングの作成

### ルートの定義

`routes/api.php` を開き、以下のルートを追加します。

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;

Route::get('/user', [UserController::class, 'show']);
```

**ポイント**
- APIルートは自動的に `/api` プレフィックスが付きます
- つまり、実際のURLは `/api/user` になります

---

## Step 2: コントローラーの作成

### artisan コマンドでコントローラーを生成

```bash
php artisan make:controller Api/UserController
```

`app/Http/Controllers/Api/UserController.php` が作成されます。

### コントローラーの実装

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;

class UserController extends Controller
{
    public function show()
    {
        // ID=1のユーザーを取得
        $user = User::find(1);

        // ユーザーが見つからない場合は404を返す
        if (!$user) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        // ユーザー情報をJSONで返す
        return response()->json($user);
    }
}
```

### 動作確認

ブラウザまたはターミナルで確認してみましょう。

```bash
curl http://localhost:8000/api/user
```

以下のようなレスポンスが返ってくれば成功です。

```json
{
    "id": 1,
    "name": "テストユーザー",
    "email": "test@example.com",
    "email_verified_at": null,
    "created_at": "2025-01-01T00:00:00.000000Z",
    "updated_at": "2025-01-01T00:00:00.000000Z"
}
```

---

## Step 3: API Resourceの作成

### なぜAPI Resourceを使うのか？

現在のレスポンスには問題があります。

1. `email_verified_at` や `updated_at` など不要な情報が含まれる可能性がある
2. 将来的にフィールドを追加・変更したい場合、コントローラーを修正する必要がある
3. 他のエンドポイントでも同じ形式でユーザー情報を返したい場合、コードが重複する

**API Resource** を使うと、これらの問題を解決できます。

### API Resourceの生成

```bash
php artisan make:resource UserResource
```

`app/Http/Resources/UserResource.php` が作成されます。

### UserResourceの実装

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
```

**ポイント**
- `$this` はUserモデルのインスタンスを指します
- 返したいフィールドだけを指定できます
- 日付のフォーマットも自由に変更できます

### コントローラーの修正

`UserController.php` を修正して、`UserResource` を使うようにします。

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;

class UserController extends Controller
{
    public function show()
    {
        $user = User::find(1);

        if (!$user) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        // UserResourceを使ってレスポンスを返す
        return new UserResource($user);
    }
}
```

### 動作確認

再度APIを呼び出してみましょう。

```bash
curl http://localhost:8000/api/user
```

```json
{
    "data": {
        "id": 1,
        "name": "テストユーザー",
        "email": "test@example.com",
        "created_at": "2025-01-01T00:00:00.000Z"
    }
}
```

**注目ポイント**
- レスポンスが `data` でラップされています（API Resourceのデフォルト動作）
- 指定したフィールドのみが含まれています
- 日付のフォーマットが変わっています

---

## Step 4: 発展 - パスパラメータでユーザーを指定

現在は ID=1 のユーザーを固定で返していますが、URLで任意のユーザーIDを指定できるようにしましょう。

### ルートの修正

`routes/api.php` を修正します。

```php
Route::get('/user/{id}', [UserController::class, 'show']);
```

### コントローラーの修正

```php
public function show(int $id)
{
    $user = User::find($id);

    if (!$user) {
        return response()->json([
            'message' => 'User not found'
        ], 404);
    }

    return new UserResource($user);
}
```

### さらに改善: Route Model Binding

Laravelには「Route Model Binding」という便利な機能があります。これを使うと、IDからモデルの取得を自動化できます。

```php
// ルート（api.php）
Route::get('/user/{user}', [UserController::class, 'show']);

// コントローラー
public function show(User $user)
{
    // Laravelが自動的にIDからUserを取得してくれる
    // 見つからない場合は自動で404を返す
    return new UserResource($user);
}
```

これにより、コントローラーのコードがシンプルになります。

---

## まとめ

このレッスンで学んだこと：

1. **APIルーティング** (`routes/api.php`)
   - `Route::get()` でGETエンドポイントを定義
   - 自動的に `/api` プレフィックスが付く

2. **コントローラー** (`php artisan make:controller`)
   - `Api/` ディレクトリに整理して配置
   - `response()->json()` でJSONレスポンスを返す

3. **API Resource** (`php artisan make:resource`)
   - レスポンス形式を一元管理
   - 必要なフィールドのみを公開
   - 再利用可能

4. **Route Model Binding**
   - URLパラメータから自動的にモデルを取得
   - 存在しない場合は自動で404

---

## 練習問題

### 問題1
`UserResource` に `full_name` というフィールドを追加してください。このフィールドは `{name}さん` という形式で値を返すようにしてください。

<details>
<summary>解答例</summary>

```php
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'full_name' => $this->name . 'さん',
        'email' => $this->email,
        'created_at' => $this->created_at->toISOString(),
    ];
}
```
</details>

### 問題2
ユーザー一覧を返す `/api/users` エンドポイントを作成してください。

<details>
<summary>ヒント</summary>

- `User::all()` で全ユーザーを取得できます
- 一覧の場合は `UserResource::collection($users)` を使います
</details>

<details>
<summary>解答例</summary>

```php
// routes/api.php
Route::get('/users', [UserController::class, 'index']);

// UserController.php
public function index()
{
    $users = User::all();
    return UserResource::collection($users);
}
```
</details>

---

## 次のレッスン

[Lesson 2: デバッグ手法を身につける](./02-debugging.md) では、今回作成したAPIを使ってデバッグ方法を学びます。
