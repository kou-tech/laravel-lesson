# Lesson 4: 認可（Gate/Policy）を実装する

## 学習目標

このレッスンでは、GateとPolicyを使った認可制御を理解し、「誰が何をできるか」を適切に制御できるようになります。

### 到達目標
- 認証と認可の違いを理解する
- Gate を使った認可チェックができる
- Policy を使ったモデルベースの認可ができる
- ユーザーに役割（role）を追加できる

---

## 認証 vs 認可

| 概念 | 英語 | 質問 | 例 |
|------|------|------|-----|
| 認証 | Authentication | この人は誰？ | ログイン処理 |
| 認可 | Authorization | この人は何ができる？ | 管理者のみ削除可能 |

前回のレッスンで「認証」を学びました。今回は「認可」です。

### なぜ認可が必要か？

認証だけでは不十分なケースがあります。

```
シナリオ: ユーザー情報の編集API

❌ 認証のみ
→ ログインしていれば誰でも他人の情報を編集できてしまう

✅ 認証 + 認可
→ ログインしている かつ 自分自身の情報のみ編集可能
```

---

## Step 1: ユーザーに役割を追加する

### マイグレーションの作成

まず、ユーザーに `role` フィールドを追加します。

```bash
php artisan make:migration add_role_to_users_table
```

### マイグレーションの実装

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('student')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
```

### マイグレーションの実行

```bash
php artisan migrate
```

### Userモデルの修正

`app/Models/User.php` に `role` を追加します。

```php
protected $fillable = [
    'name',
    'email',
    'password',
    'role',  // 追加
];
```

### 役割の定義（Enum）

PHP 8.1以降では Enum を使うのがベストプラクティスです。

`app/Enums/UserRole.php` を作成します。

```php
<?php

namespace App\Enums;

enum UserRole: string
{
    case Student = 'student';
    case Instructor = 'instructor';

    public function label(): string
    {
        return match($this) {
            self::Student => '生徒',
            self::Instructor => '講師',
        };
    }
}
```

### Userモデルでのキャスト

```php
use App\Enums\UserRole;

protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,  // 追加
    ];
}

// 便利メソッドを追加
public function isInstructor(): bool
{
    return $this->role === UserRole::Instructor;
}

public function isStudent(): bool
{
    return $this->role === UserRole::Student;
}
```

---

## Step 2: Gateを使った認可

### Gateとは？

Gate は、特定のアクションに対する認可を定義するシンプルな方法です。

### Gateの定義

`app/Providers/AppServiceProvider.php` の `boot` メソッドに追加します。

```php
use Illuminate\Support\Facades\Gate;
use App\Enums\UserRole;

public function boot(): void
{
    // 講師のみアクセス可能なアクション
    Gate::define('manage-courses', function ($user) {
        return $user->isInstructor();
    });

    // 自分自身の情報のみアクセス可能
    Gate::define('access-own-data', function ($user, $targetUser) {
        return $user->id === $targetUser->id;
    });
}
```

### Gateの使用

#### コントローラーで使う

```php
use Illuminate\Support\Facades\Gate;

public function manageCourses()
{
    // 認可チェック（失敗時は403エラー）
    Gate::authorize('manage-courses');

    // ここに到達 = 認可OK
    return response()->json(['message' => '講座管理画面']);
}
```

#### 条件分岐で使う

```php
if (Gate::allows('manage-courses')) {
    // 認可OK
}

if (Gate::denies('manage-courses')) {
    // 認可NG
}
```

#### パラメータ付きで使う

```php
Gate::authorize('access-own-data', $targetUser);
```

---

## Step 3: Policyを使った認可

### Policyとは？

Policy は、**特定のモデル**に対する認可ルールをまとめたクラスです。

Gate との違い：
- Gate: 汎用的なアクション（「管理画面にアクセスできるか」）
- Policy: モデルに紐づくアクション（「このユーザーを編集できるか」）

### Policyの作成

```bash
php artisan make:policy UserPolicy --model=User
```

`app/Policies/UserPolicy.php` が作成されます。

### Policyの実装

```php
<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * ユーザー一覧を閲覧できるか
     */
    public function viewAny(User $user): bool
    {
        // 講師のみ全ユーザーを閲覧可能
        return $user->isInstructor();
    }

    /**
     * 特定のユーザーを閲覧できるか
     */
    public function view(User $user, User $model): bool
    {
        // 自分自身 または 講師なら閲覧可能
        return $user->id === $model->id || $user->isInstructor();
    }

    /**
     * ユーザーを作成できるか
     */
    public function create(User $user): bool
    {
        // 講師のみ作成可能
        return $user->isInstructor();
    }

    /**
     * ユーザーを更新できるか
     */
    public function update(User $user, User $model): bool
    {
        // 自分自身のみ更新可能
        return $user->id === $model->id;
    }

    /**
     * ユーザーを削除できるか
     */
    public function delete(User $user, User $model): bool
    {
        // 誰も削除できない（または管理者のみ）
        return false;
    }
}
```

### Policyの登録

Laravel 11では、モデル名と一致するPolicyは自動的に登録されます。

- `User` モデル → `UserPolicy` が自動的に紐づく

手動で登録する場合は `AppServiceProvider` で：

```php
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::policy(User::class, UserPolicy::class);
}
```

---

## Step 4: Policyをコントローラーで使う

### authorize メソッド

```php
public function show(User $user)
{
    // Policyの view メソッドをチェック
    $this->authorize('view', $user);

    return new UserResource($user);
}

public function update(Request $request, User $user)
{
    // Policyの update メソッドをチェック
    $this->authorize('update', $user);

    $user->update($request->validated());

    return new UserResource($user);
}
```

### Gate::authorize との違い

```php
// Gate（第1引数がアクション名）
Gate::authorize('manage-courses');

// Policy（$this->authorize はコントローラーのメソッド）
$this->authorize('view', $user);  // UserPolicyのviewメソッド
```

### リソースコントローラーとの統合

リソースコントローラーを使う場合、`authorizeResource` で一括設定できます。

```php
class UserController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(User::class, 'user');
    }

    // 各メソッドに自動的にPolicyが適用される
    public function index() { /* viewAny */ }
    public function show(User $user) { /* view */ }
    public function store(Request $request) { /* create */ }
    public function update(Request $request, User $user) { /* update */ }
    public function destroy(User $user) { /* delete */ }
}
```

---

## Step 5: 認可エラーのレスポンス

### デフォルトの動作

認可に失敗すると、LaravelはHTTP 403エラーを返します。

```json
{
    "message": "This action is unauthorized."
}
```

### カスタムメッセージ

Policyでカスタムレスポンスを返せます。

```php
use Illuminate\Auth\Access\Response;

public function update(User $user, User $model): Response
{
    if ($user->id === $model->id) {
        return Response::allow();
    }

    return Response::deny('他のユーザーの情報は編集できません。');
}
```

---

## Step 6: 実践例 - ユーザー編集APIの保護

### ルートの定義

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [UserController::class, 'me']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
});
```

### コントローラーの実装

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function me(Request $request)
    {
        return new UserResource($request->user());
    }

    public function update(Request $request, User $user)
    {
        // 認可チェック
        $this->authorize('update', $user);

        // バリデーション
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        // 更新
        $user->update($validated);

        return new UserResource($user);
    }
}
```

### 動作確認

**自分自身を更新（成功）**

```bash
# ユーザーID=1でログインしている状態で
curl -X PATCH http://localhost:8000/api/users/1 \
  -H "Content-Type: application/json" \
  -d '{"name": "新しい名前"}'
```

**他人を更新しようとする（失敗）**

```bash
# ユーザーID=1でログインしている状態で、ID=2を更新しようとする
curl -X PATCH http://localhost:8000/api/users/2 \
  -H "Content-Type: application/json" \
  -d '{"name": "新しい名前"}'

# 403 Forbidden が返る
```

---

## まとめ

このレッスンで学んだこと：

1. **認証 vs 認可**
   - 認証: 誰か確認
   - 認可: 何ができるか確認

2. **Gate**
   - 汎用的なアクションの認可
   - `Gate::define()` で定義
   - `Gate::authorize()` でチェック

3. **Policy**
   - モデルに紐づく認可
   - `php artisan make:policy` で作成
   - `$this->authorize()` でチェック

4. **役割（Role）の実装**
   - Enumで役割を定義
   - `$casts` でキャスト
   - ヘルパーメソッドで判定

---

## 練習問題

### 問題1
UserPolicyに「講師のみ生徒の役割を変更できる」という認可を追加してください。

<details>
<summary>ヒント</summary>

新しいメソッド `updateRole` を追加し、講師かどうかをチェックします。
</details>

<details>
<summary>解答例</summary>

```php
public function updateRole(User $user, User $model): bool
{
    // 講師のみ役割を変更可能
    return $user->isInstructor();
}
```

コントローラーで：

```php
$this->authorize('updateRole', $targetUser);
```
</details>

### 問題2
Gateを使って「講師のみが講座を作成できる」という認可を追加してください。

<details>
<summary>解答例</summary>

`AppServiceProvider.php`:

```php
Gate::define('create-course', function ($user) {
    return $user->isInstructor();
});
```

コントローラーで：

```php
Gate::authorize('create-course');
```
</details>

---

## 次のレッスン

[Lesson 5: API設計の基本](./05-api-design.md) では、RESTful APIの設計原則を学び、受講管理システム全体のAPI設計を行います。
