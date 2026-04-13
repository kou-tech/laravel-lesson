# Lesson 2 デバッグ手法を身につける

## 学習目標

このレッスンでは、Laravelでのデバッグ手法を習得し、問題解決能力を高めます。
手順に沿って実装し、完了したコードと練習問題の回答を含めたプルリクエストを作成しましょう。

### 到達目標
- `Log` ファサードを使ってログを出力できる
- ログレベルを使い分けられる
- ログを確認して問題の原因を特定できる


## なぜデバッグスキルが重要か？

開発中に「なぜ動かないのか分からない」という状況は頻繁に発生します。
効率的なデバッグスキルを身につけて、対応できるようにしましょう。

- 問題の原因を素早く特定できる
- 変数の中身を確認して期待通りの値か検証できる
- 処理の流れを追跡してどこで問題が起きているか把握できる


## デバッグツールの使い分け

Laravelには複数のデバッグ手法があります。状況に応じて使い分けましょう。


## Step 1 準備 - UserControllerを作成する

このレッスンでは、デバッグの題材として `UserController` を使います。
まだ作成していない場合は、以下の手順で準備しましょう。

まず、テストデータが入っていない場合は `make fresh` を実行して、データベースの初期化とテストデータの投入を行ってください。

> Controllerの詳しい説明は [Lesson 4](./04-user-api.md) で学びます。ここではデバッグの練習用として作成します。

### 1. Controllerを生成する

`make app` でコンテナに入ってから、以下のコマンドを実行します。

```bash
php artisan make:controller Api/UserController
```

### 2. UserControllerを編集する

`app/Http/Controllers/Api/UserController.php` を以下の内容に書き換えます。

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;

class UserController extends Controller
{
    public function index()
    {
        return User::all();
    }

    public function show(User $user)
    {
        return $user;
    }
}
```

### 3. ルートを追加する

`routes/api.php` に以下を追加します。

```php
Route::get('/users', [\App\Http\Controllers\Api\UserController::class, 'index']);
Route::get('/users/{user}', [\App\Http\Controllers\Api\UserController::class, 'show']);
```

### 4. Postmanで動作確認

Postmanのコレクション内の以下のリクエストで確認します。

- `api > users > users`（GET）— ユーザー一覧
- `api > users > {userId} > users_id`（GET）— ユーザー詳細

ユーザーデータが返ってくれば準備完了です。


## Step 2 Log ファサードを使う

### なぜログを使うのか？

`dd()` や `dump()` は便利ですが、以下の問題があります。

- 処理が止まる/遅くなる
- APIのレスポンスが壊れる

`Log` ファサードを使えば、ログファイルに出力するため、これらの問題を回避できます。

### 基本的な使い方

`UserController` にログ出力を追加してみましょう。

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function show(User $user)
    {
        Log::info('UserController@show が呼ばれました', [
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);

        return $user;
    }
}
```

### ログの確認

ログは `storage/logs/laravel.log` に出力されます。コンテナ内（`make app`）で確認しましょう。

```bash
tail -f storage/logs/laravel.log
```

APIを呼び出すと、以下のようなログが出力されます。

```
[2025-01-01 12:00:00] local.INFO: UserController@show が呼ばれました {"user_id":1,"user_name":"テストユーザー"}
```

### ログレベル

Logファサードには複数のログレベルがあります。

#### 使い分けの目安

| レベル | 用途例 |
|--------|--------|
| `error` | 例外が発生した、処理が失敗した |
| `warning` | 想定外の値だが処理は継続できる |
| `info` | 処理の開始/終了、重要な操作のログ |
| `debug` | 開発時のデバッグ情報（本番では出力しない） |

#### .env でログレベルを制御

`.env` ファイルで `LOG_LEVEL` を設定すると、そのレベル以上のログのみが出力されます。

```env
# 開発環境
LOG_LEVEL=debug

# 本番環境（debugは出力しない）
LOG_LEVEL=info
```

## Step 3 実践 - デバッグしてみよう

### 課題: 意図的にエラーを起こしてデバッグする

以下のようなバグを仕込んだコードを作成してみましょう。

```php
public function show(User $user)
{
    // 意図的なバグ: 存在しないプロパティにアクセス
    $fullName = $user->full_name;

    Log::debug('取得したフルネーム', ['full_name' => $fullName]);

    return $user;
}
```

### デバッグの手順

1. APIを呼び出す
2. ログを確認する
   ```bash
   tail -f storage/logs/laravel.log
   ```
3. ログの内容から問題を特定する
   - `full_name` の値が `null` になっていないか確認する
   - User モデルに `full_name` プロパティが存在するか確認する

4. 問題を特定して修正する
   - `full_name` は User モデルに存在しない
   - `name` プロパティを使うか、アクセサを追加する

## ベストプラクティス

### 本番環境に残すログは info 以上

```php
// NG: 本番環境でdebugログが大量に出る
Log::debug('ループの中で毎回ログ');

// OK: 重要な操作のみログに残す
Log::info('ユーザーがログインしました', ['user_id' => $user->id]);
```

### ログには必要な情報を含める

```php
// NG: 何のログか分からない
Log::info('処理完了');

// OK: 誰が何をしたか分かる
Log::info('受講登録が完了しました', [
    'user_id' => $user->id,
    'course_id' => $course->id,
]);
```

## 練習問題

### 問題1
`UserController@index`（ユーザー一覧API）に、取得したユーザー数をログ出力する処理を追加してください。

<details>
<summary>解答例</summary>

```php
public function index()
{
    $users = User::all();

    Log::info('ユーザー一覧を取得しました', [
        'count' => $users->count(),
    ]);

    return $users;
}
```
</details>

### 問題2
`UserController@show` で、存在しないユーザーIDが指定された場合に `warning` レベルのログを出力する処理を追加してください。ログには指定されたIDを含めてください。

> ヒント: Route Model Bindingを使っている場合、存在しないIDでは自動的に404が返ります。`User::find()` を使う方法に変えると、自分でハンドリングできます。

<details>
<summary>解答例</summary>

```php
public function show(int $id)
{
    $user = User::find($id);

    if ($user === null) {
        Log::warning('存在しないユーザーIDが指定されました', [
            'user_id' => $id,
        ]);

        return response()->json(['message' => 'ユーザーが見つかりません'], 404);
    }

    return $user;
}
```
</details>


## 応用 Laravel Telescope

> このセクションは応用です。スキップしても以降のレッスンに影響はありません。

Laravel Telescope は、Laravelアプリケーションのデバッグ用ダッシュボードです。
リクエスト/レスポンス、実行されたクエリ、例外、ログなどをブラウザ上で確認できます。

`http://localhost:8000/telescope` にアクセスするとダッシュボードが開きます。

詳しくは [Laravel 公式ドキュメント - Telescope](https://laravel.com/docs/telescope) を参照してください。


## 参考資料

- [Laravel 公式ドキュメント - Logging](https://laravel.com/docs/logging)

## 次のレッスン

[Lesson 3 API設計の基本](./03-api-design.md) では、RESTful APIの設計原則について学びます。
