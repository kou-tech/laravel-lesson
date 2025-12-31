# Lesson 3: 認証の仕組みを理解する

## 学習目標

このレッスンでは、既に導入されているFortifyの仕組みを理解し、認証フローを把握します。

### 到達目標
- Laravel Fortifyの役割を理解する
- 認証の流れ（ログイン/ログアウト）を把握する
- ミドルウェア（`auth`）の動作を理解する
- 認証済みユーザーのみアクセス可能なAPIを作成できる

---

## 認証とは？

**認証（Authentication）** とは、「このユーザーは本当に本人か？」を確認するプロセスです。

- ログイン時にメールアドレスとパスワードを入力
- システムがパスワードを照合
- 正しければ「認証済み」としてセッションを発行

次のレッスンで学ぶ**認可（Authorization）** とは異なります。

| 概念 | 質問 | 例 |
|------|------|-----|
| 認証 | この人は誰？ | ログイン処理 |
| 認可 | この人は何ができる？ | 管理者のみアクセス可能 |

---

## Laravel Fortifyとは？

### Fortifyの役割

Laravel Fortify は、認証機能の**バックエンド実装**を提供するパッケージです。

提供する機能：
- ログイン / ログアウト
- ユーザー登録
- パスワードリセット
- メール確認
- 2要素認証

**重要**: FortifyはフロントエンドのUIを提供しません。UIは別途実装する必要があります（このプロジェクトではInertia + Reactで実装済み）。

### プロジェクトの構成

```
認証システムの構成
┌─────────────────────────────────────────────────┐
│  フロントエンド (React + Inertia)               │
│  - resources/js/pages/auth/login.tsx           │
│  - resources/js/pages/auth/register.tsx        │
└─────────────────────────────────────────────────┘
                      ↓ リクエスト
┌─────────────────────────────────────────────────┐
│  Laravel Fortify                                │
│  - ルーティング（/login, /register など）       │
│  - コントローラー（認証処理）                   │
│  - バリデーション                               │
└─────────────────────────────────────────────────┘
                      ↓
┌─────────────────────────────────────────────────┐
│  カスタムアクション                             │
│  - app/Actions/Fortify/CreateNewUser.php       │
│  - app/Actions/Fortify/ResetUserPassword.php   │
└─────────────────────────────────────────────────┘
```

---

## Step 1: FortifyServiceProviderを読み解く

### ファイルの場所

`app/Providers/FortifyServiceProvider.php`

### コードの解説

```php
class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }
```

このServiceProviderで3つの設定を行っています。

### 1. アクションの設定

```php
private function configureActions(): void
{
    Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    Fortify::createUsersUsing(CreateNewUser::class);
}
```

- **パスワードリセット**時に `ResetUserPassword` クラスを使う
- **ユーザー登録**時に `CreateNewUser` クラスを使う

これらのクラスは `app/Actions/Fortify/` にあります。

### 2. ビューの設定

```php
private function configureViews(): void
{
    Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
        'canResetPassword' => Features::enabled(Features::resetPasswords()),
        'canRegister' => Features::enabled(Features::registration()),
        'status' => $request->session()->get('status'),
    ]));
    // ... 他のビューも同様
}
```

- 各認証画面にどのInertiaコンポーネントを使うか指定
- 必要なデータ（リセット機能が有効か等）も渡している

### 3. レートリミットの設定

```php
private function configureRateLimiting(): void
{
    RateLimiter::for('login', function (Request $request) {
        $throttleKey = Str::transliterate(
            Str::lower($request->input(Fortify::username())).'|'.$request->ip()
        );
        return Limit::perMinute(5)->by($throttleKey);
    });
}
```

- ログイン試行を**1分あたり5回**に制限
- ブルートフォース攻撃（総当たり攻撃）を防ぐ

---

## Step 2: 認証の流れを追う

### ログインの流れ

```
1. ユーザーがログイン画面にアクセス
   GET /login
   ↓
2. Fortifyがログインビューを返す
   Inertia::render('auth/login')
   ↓
3. ユーザーがフォームを送信
   POST /login (email, password)
   ↓
4. Fortifyがバリデーション
   - メールアドレスの形式チェック
   - パスワードの存在チェック
   ↓
5. 認証処理
   - DBからユーザーを検索
   - パスワードをハッシュと照合
   ↓
6. 成功時: セッション発行 → リダイレクト
   失敗時: エラーメッセージを返す
```

### 認証済みかどうかの確認

Laravelでは以下の方法で認証状態を確認できます。

```php
// ユーザーが認証済みかどうか
if (Auth::check()) {
    // 認証済み
}

// 認証済みユーザーを取得
$user = Auth::user();

// 認証済みユーザーのID
$userId = Auth::id();
```

---

## Step 3: authミドルウェアを理解する

### ミドルウェアとは？

ミドルウェアは、リクエストがコントローラーに到達する前に実行される処理です。

```
リクエスト → [ミドルウェア] → コントローラー → レスポンス
```

### authミドルウェア

`auth` ミドルウェアは、認証済みのユーザーのみアクセスを許可します。

未認証の場合：
- Webリクエスト → ログイン画面にリダイレクト
- APIリクエスト → 401 Unauthorized エラー

### ルートへの適用

```php
// routes/web.php
Route::get('/dashboard', function () {
    return Inertia::render('dashboard');
})->middleware(['auth', 'verified']);
```

- `auth`: 認証済みユーザーのみ
- `verified`: メール確認済みユーザーのみ

---

## Step 4: 認証が必要なAPIを作成する

### 認証済みユーザーの情報を返すAPI

`routes/api.php` に以下を追加します。

```php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;

// 認証不要（公開API）
Route::get('/user/{user}', [UserController::class, 'show']);

// 認証必要（要ログイン）
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [UserController::class, 'me']);
});
```

### コントローラーにメソッドを追加

`app/Http/Controllers/Api/UserController.php`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function show(User $user)
    {
        return new UserResource($user);
    }

    /**
     * 認証済みユーザー自身の情報を返す
     */
    public function me(Request $request)
    {
        // $request->user() で認証済みユーザーを取得
        return new UserResource($request->user());
    }
}
```

### 動作確認

**未認証でアクセス**

```bash
curl http://localhost:8000/api/me
```

```json
{
    "message": "Unauthenticated."
}
```

401エラーが返ります。

**認証してアクセス**

ブラウザでログインした状態で、開発者ツールから確認できます（セッションCookieが自動送信されるため）。

---

## Step 5: Sanctumについて

### Sanctumとは？

Laravel Sanctumは、API認証のためのパッケージです。

2つの認証方式を提供：
1. **SPAセッション認証**: 同一ドメインのSPAからのリクエスト
2. **APIトークン認証**: モバイルアプリや外部サービスからのリクエスト

このプロジェクトでは、InertiaがSPAとして動作するため、主にセッション認証を使います。

### auth:sanctum ミドルウェア

```php
Route::middleware('auth:sanctum')->group(function () {
    // このグループ内はSanctumで認証
});
```

このミドルウェアは：
- セッション認証（SPA用）
- トークン認証（API用）

の両方に対応しています。

---

## Guard と Provider

### 概念の理解

Laravelの認証システムは、**Guard** と **Provider** で構成されています。

```
Guard: 認証状態の管理方法を決める
  - session: セッションで認証状態を保持
  - token: トークンで認証

Provider: ユーザー情報の取得方法を決める
  - database: DBから直接取得
  - eloquent: Eloquentモデル経由で取得
```

### 設定ファイル

`config/auth.php` で設定されています。

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class,
    ],
],
```

通常、この設定を変更する必要はありません。

---

## まとめ

このレッスンで学んだこと：

1. **認証とは**
   - 「この人は誰？」を確認するプロセス
   - 認可（何ができる？）とは別物

2. **Laravel Fortify**
   - 認証のバックエンド実装を提供
   - UIは提供しない（Inertiaで実装）

3. **FortifyServiceProvider**
   - アクション、ビュー、レートリミットを設定
   - カスタマイズ可能

4. **authミドルウェア**
   - 認証済みユーザーのみアクセス許可
   - 未認証は401エラー or リダイレクト

5. **Sanctum**
   - API認証パッケージ
   - セッション認証とトークン認証の両方に対応

---

## 練習問題

### 問題1
`FortifyServiceProvider` のレートリミット設定を確認し、ログイン試行が何回/分に制限されているか答えてください。

<details>
<summary>解答</summary>

5回/分です。

```php
return Limit::perMinute(5)->by($throttleKey);
```
</details>

### 問題2
認証済みユーザーのメールアドレスをログに出力する処理を `me` メソッドに追加してください。

<details>
<summary>解答例</summary>

```php
public function me(Request $request)
{
    $user = $request->user();

    Log::info('自身の情報を取得', [
        'user_id' => $user->id,
        'email' => $user->email,
    ]);

    return new UserResource($user);
}
```
</details>

### 問題3
以下のコードで、認証済みユーザーを取得する3つの方法を試してみてください。

```php
// 方法1: Requestから
$user = $request->user();

// 方法2: Authファサード
$user = Auth::user();

// 方法3: auth()ヘルパー
$user = auth()->user();
```

これらは同じ結果を返します。プロジェクトのコーディング規約に合わせて使い分けてください。

---

## 次のレッスン

[Lesson 4: 認可（Gate/Policy）を実装する](./04-authorization.md) では、「誰が何をできるか」を制御する認可機能を学びます。
