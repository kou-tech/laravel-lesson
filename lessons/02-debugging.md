# Lesson 2: デバッグ手法を身につける

## 学習目標

このレッスンでは、Laravelでのデバッグ手法を習得し、問題解決能力を高めます。
手順に沿って実装し、完了したコードと練習問題の回答を含めたプルリクエストを作成しましょう。

### 到達目標
- `Log` ファサードを使ってログを出力できる
- Laravel Telescope をインストールして活用できる


## なぜデバッグスキルが重要か？

開発中に「なぜ動かないのか分からない」という状況は頻繁に発生します。
効率的なデバッグスキルを身につけて、対応できるようにしましょう。

- 問題の原因を素早く特定できる
- 変数の中身を確認して期待通りの値か検証できる
- 処理の流れを追跡してどこで問題が起きているか把握できる


## デバッグツールの使い分け

Laravelには複数のデバッグ手法があります。状況に応じて使い分けましょう。


## Step 1: Log ファサードを使う

### なぜログを使うのか？

`dd()` や `dump()` は便利ですが、以下の問題があります。

- 処理が止まる/遅くなる
- APIのレスポンスが壊れる

`Log` ファサードを使えば、ログファイルに出力するため、これらの問題を回避できます。

### 基本的な使い方

`UserController` を修正して、ログを出力してみましょう。

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function show(User $user): UserResource
    {
        Log::info('UserController@show が呼ばれました', [
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);

        return new UserResource($user);
    }
}
```

### ログの確認

ログは `storage/logs/laravel.log` に出力されます。

```bash
tail -f storage/logs/laravel.log
```

APIを呼び出すと、以下のようなログが出力されます。

```
[2025-01-01 12:00:00] local.INFO: UserController@show が呼ばれました {"user_id":1,"user_name":"テストユーザー"}
```

### ログレベル

Logファサードには複数のログレベルがあります。

### 使い分けの目安

| レベル | 用途例 |
|--------|--------|
| `error` | 例外が発生した、処理が失敗した |
| `warning` | 想定外の値だが処理は継続できる |
| `info` | 処理の開始/終了、重要な操作のログ |
| `debug` | 開発時のデバッグ情報（本番では出力しない） |

### .env でログレベルを制御

`.env` ファイルで `LOG_LEVEL` を設定すると、そのレベル以上のログのみが出力されます。

```env
# 開発環境
LOG_LEVEL=debug

# 本番環境（debugは出力しない）
LOG_LEVEL=info
```

## Step 2: Laravel Telescope のインストール

### Telescope とは？

Laravel Telescope は、Laravelアプリケーションのデバッグ用ダッシュボードです。

以下の情報をブラウザで確認できます。
- リクエスト/レスポンス
- 実行されたクエリ（N+1問題の発見にも有用！）
- 例外/エラー
- ログ
- キュー/ジョブ
- メール
- キャッシュ操作
- など

### インストール

```bash
composer require laravel/telescope --dev
```

`--dev` オプションで開発環境のみにインストールします（本番環境には不要）。

### セットアップ

```bash
php artisan telescope:install
php artisan migrate
```

### Telescopeダッシュボードにアクセス

`http://localhost:8000/telescope` にアクセスすると、Telescopeのダッシュボードが表示されます。

### 動作確認

1. ブラウザで `http://localhost:8000/api/user/1` にアクセス
2. Telescope の「Requests」タブを開く
3. リクエストの詳細を確認

以下の情報が見られます。
- リクエストパラメータ
- 実行されたクエリ
- レスポンスの内容
- 処理時間

### Telescope を使ったデバッグの流れ

1. 問題のある操作を行う
2. Telescope でリクエストを確認
3. 「Queries」タブでSQLを確認（N+1問題がないか）
4. 「Logs」タブでログを確認
5. 「Exceptions」タブで例外を確認

## Step 3: 実践 - デバッグしてみよう

### 課題: 意図的にエラーを起こしてデバッグする

以下のようなバグを仕込んだコードを作成してみましょう。

```php
public function show(User $user): UserResource
{
    // 意図的なバグ: 存在しないプロパティにアクセス
    $fullName = $user->full_name;

    Log::debug('取得したフルネーム', ['full_name' => $fullName]);

    return new UserResource($user);
}
```

### デバッグの手順

1. **APIを呼び出す**
   ```bash
   curl http://localhost:8000/api/user/1
   ```

2. **ログを確認する**
   ```bash
   tail -f storage/logs/laravel.log
   ```

3. **Telescopeで確認する**
   - Logs タブで出力されたログを確認
   - Requests タブでリクエストの詳細を確認

4. **問題を特定して修正する**
   - `full_name` は User モデルに存在しない
   - `name` プロパティを使うか、アクセサを追加する

## ベストプラクティス

### 1. 本番環境に残すログは info 以上

```php
// NG: 本番環境でdebugログが大量に出る
Log::debug('ループの中で毎回ログ');

// OK: 重要な操作のみログに残す
Log::info('ユーザーがログインしました', ['user_id' => $user->id]);
```

### 2. ログには必要な情報を含める

```php
// NG: 何のログか分からない
Log::info('処理完了');

// OK: 誰が何をしたか分かる
Log::info('受講登録が完了しました', [
    'user_id' => $user->id,
    'course_id' => $course->id,
]);
```

### 4. Telescopeは開発環境でのみ使う

本番環境では無効化するか、認証で保護してください。

## 練習問題

### 問題1
`UserController@index`（ユーザー一覧API）に、取得したユーザー数をログ出力する処理を追加してください。

### 問題2
Telescopeで、`/api/user/1` へのリクエストで実行されたSQLクエリを確認してください。何件のクエリが実行されましたか？


## 参考資料

- [Laravel 公式ドキュメント - Logging](https://laravel.com/docs/logging)
- [Laravel 公式ドキュメント - Telescope](https://laravel.com/docs/telescope)

## 次のレッスン

[Lesson 3: 認証の仕組みを理解する](./03-authentication.md) では、既に導入されているFortifyの認証機能の仕組みを学びます。
