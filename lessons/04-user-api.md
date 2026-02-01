# Lesson 4 User APIの実装

## 学習目標

このレッスンでは、ControllerとAPI Resourceを使って本格的なUser APIを実装します。

### 到達目標
- Controllerを作成してAPIロジックを実装できる
- API Resourceを使ってレスポンスを整形できる
- Route Model Bindingを活用できる


## Step 1 Controllerの作成

### なぜControllerを使うのか

`routes/api.php` に直接ロジックを書くこともできます。

```php
// api.phpに直接書く（小規模なら問題ない）
Route::get('/users/{id}', function (int $id) {
    $user = User::find($id);
    return $user;
});
```

しかし、ロジックが増えるとファイルが肥大化します。Controllerに分離することで、ルーティングとロジックを分離し、コードを整理できます。

```php
// api.php（ルーティングのみ）
Route::get('/users/{user}', [UserController::class, 'show']);

// UserController.php（ロジック）
public function show(User $user)
{
    return new UserResource($user);
}
```


## Step 2 基本的なCRUD実装

### UserControllerの実装

`app/Http/Controllers/Api/UserController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * ユーザー一覧を取得
     */
    public function index()
    {
        $users = User::all();
        return response()->json(['data' => $users]);
    }

    /**
     * ユーザー詳細を取得
     */
    public function show(User $user)
    {
        return response()->json(['data' => $user]);
    }

    /**
     * ユーザーを更新
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $user->update($validated);

        return response()->json(['data' => $user]);
    }
}
```

### Route Model Binding

`show(User $user)` のように型宣言すると、LaravelがURLのパラメータからUserモデルを自動取得します。

```php
// {user} とパラメータ名を合わせる
Route::get('/users/{user}', [UserController::class, 'show']);

// Laravelが自動的に User::findOrFail($id) を実行
public function show(User $user)
{
    // $user にはUserモデルが入っている
}
```

見つからない場合は自動で404エラーが返ります。

### ルーティングの設定

`routes/api.php`

```php
<?php
...
Route::get('/users', [\App\Http\Controllers\Api\UserController::class, 'index']);
Route::get('/users/{user}', [\App\Http\Controllers\Api\UserController::class, 'show']);
Route::patch('/users/{user}', [\App\Http\Controllers\Api\UserController::class, 'update']);
```

### 動作確認

Postmanで確認してください。

- ユーザー一覧
- ユーザー詳細
- ユーザー更新

## Step 3 API Resourceの作成

### なぜAPI Resourceを使うのか

現在のレスポンスには問題があります。

```json
{
    "data": {
        "id": 1,
        "name": "Test User",
        "email": "test@example.com",
        "email_verified_at": null,
        "password": "$2y$12$...",
        "created_at": "2025-01-01T00:00:00.000000Z",
        "updated_at": "2025-01-01T00:00:00.000000Z"
    }
}
```

問題点
- `password` が含まれている（セキュリティリスク）
- `email_verified_at` など不要な情報が含まれる
- 他のエンドポイントでも同じ形式で返したい場合、コードが重複する

API Resourceを使うと、これらの問題を解決できます。

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
    /** @var \App\Models\User */
    public $resource;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'created_at' => $this->resource->created_at->toISOString(),
        ];
    }
}
```

ポイント
- `$this->resource` はUserモデルのインスタンス
- 返したいフィールドだけを指定できる
- 日付のフォーマットも自由に変更できる

### Controllerの修正

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        $users = User::all();
        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(Request $request, User $user): UserResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $user->update($validated);

        return new UserResource($user);
    }
}
```

### 動作確認

Postmanで確認してください。
- ユーザー詳細

レスポンス

```json
{
    "data": {
        "id": 1,
        "name": "Test User",
        "email": "test@example.com",
        "created_at": "2025-01-01T00:00:00.000Z"
    }
}
```

注目ポイント
- レスポンスが `data` でラップされている（API Resourceのデフォルト動作）
- 指定したフィールドのみが含まれている
- `password` は含まれていない


## Step 4 Collectionのラップ

### UserResource::collection

複数のリソースを返す場合は `::collection()` を使います。

```php
public function index()
{
    $users = User::all();
    return UserResource::collection($users);
}
```

レスポンス

```json
{
    "data": [
        {"id": 1, "name": "User 1", ...},
        {"id": 2, "name": "User 2", ...}
    ]
}
```

### ページネーション対応

```php
public function index()
{
    $users = User::paginate(15);
    return UserResource::collection($users);
}
```

レスポンス

```json
{
    "data": [
        {"id": 1, "name": "User 1", ...},
        {"id": 2, "name": "User 2", ...}
    ],
    "links": {
        "first": "http://localhost:8000/api/users?page=1",
        "last": "http://localhost:8000/api/users?page=5",
        "prev": null,
        "next": "http://localhost:8000/api/users?page=2"
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 5,
        "per_page": 15,
        "to": 15,
        "total": 75
    }
}
```

ページネーション情報が自動的に追加されます。

## 練習問題

### 問題1
UserResourceに `full_name` フィールドを追加してください。値は `{name}さん` という形式で返してください。

<details>
<summary>解答例</summary>

```php
public function toArray(Request $request): array
{
    return [
        'id' => $this->resource->id,
        'name' => $this->resource->name,
        'full_name' => $this->resource->name . 'さん',
        'email' => $this->resource->email,
        'created_at' => $this->resource->created_at->toISOString(),
    ];
}
```
</details>

### 問題2
ユーザー一覧APIにページネーションを追加し、1ページあたり10件を返すようにしてください。

<details>
<summary>解答例</summary>

```php
public function index()
{
    $users = User::paginate(10);
    return UserResource::collection($users);
}
```
</details>


## 参考資料

- [Laravel 公式ドキュメント - Controllers](https://laravel.com/docs/controllers)
- [Laravel 公式ドキュメント - Eloquent: API Resources](https://laravel.com/docs/eloquent-resources)


## 次のレッスン

[Lesson 5 バリデーション](./05-validation.md) では、FormRequestを使った堅牢なバリデーション設計を学びます。
