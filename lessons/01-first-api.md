# Lesson 1 はじめてのAPI

## 学習目標

このレッスンでは、Laravelで最もシンプルなAPIを作成し、APIの基本的な仕組みを理解します。

### 到達目標
- `routes/api.php` でAPIルートを定義できる
- JSONレスポンスを返せる
- Postmanを使ってAPIの動作確認ができる

## Step 1 最初のAPIエンドポイント

### api.phpにルートを追加

`routes/api.php` を開き、以下のコードを追加します。

```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/hello', function () {
    return ['message' => 'Hello, World!'];
});
```

これだけでAPIが完成です。

### URLの確認

APIルートは自動的に `/api` プレフィックスが付きます。

| 定義 | 実際のURL |
|------|----------|
| `/hello` | `/api/hello` |
| `/users` | `/api/users` |

### 動作確認

Postmanを開き、以下のリクエストを送信してください。

```
GET http://localhost:8000/api/hello
```

レスポンス

```json
{
    "message": "Hello, World!"
}
```


## Step 2 配列を返す

複数のデータを返すこともできます。

```php
Route::get('/fruits', function () {
    return [
        ['id' => 1, 'name' => 'りんご', 'price' => 150],
        ['id' => 2, 'name' => 'みかん', 'price' => 100],
        ['id' => 3, 'name' => 'バナナ', 'price' => 200],
    ];
});
```

Postmanで確認

```
GET http://localhost:8000/api/fruits
```

レスポンス

```json
[
    {"id": 1, "name": "りんご", "price": 150},
    {"id": 2, "name": "みかん", "price": 100},
    {"id": 3, "name": "バナナ", "price": 200}
]
```


## Step 3 パスパラメータを使う

URLの一部を変数として受け取れます。

```php
Route::get('/fruits/{id}', function (int $id) {
    $fruits = [
        1 => ['id' => 1, 'name' => 'りんご', 'price' => 150],
        2 => ['id' => 2, 'name' => 'みかん', 'price' => 100],
        3 => ['id' => 3, 'name' => 'バナナ', 'price' => 200],
    ];

    if (!isset($fruits[$id])) {
        return response()->json(['message' => '見つかりません'], 404);
    }

    return $fruits[$id];
});
```

Postmanで確認

```
GET http://localhost:8000/api/fruits/1
```

レスポンス

```json
{"id": 1, "name": "りんご", "price": 150}
```

存在しないIDの場合

```
GET http://localhost:8000/api/fruits/999
```

```json
{"message": "見つかりません"}
```


## Step 4 HTTPメソッドを使い分ける

APIでは、HTTPメソッドで操作の種類を表現します。

| メソッド | 用途 | 例 |
|---------|------|-----|
| GET | データ取得 | 一覧取得、詳細取得 |
| POST | データ作成 | 新規登録 |
| PUT | データ全体の置換 | リソース全体の更新 |
| PATCH | データの部分更新 | 一部の項目だけ変更 |
| DELETE | データ削除 | 削除 |

PUTとPATCHはどちらも更新に使いますが、PUTはリソース全体を送信して置き換える操作、PATCHは変更したい部分だけを送信する操作です。実務ではPATCHを使うことが多いですが、プロジェクトの方針によって異なります。

詳しくは [MDN - HTTPリクエストメソッド](https://developer.mozilla.org/ja/docs/Web/HTTP/Methods) を参照してください。

### POSTの例

```php
Route::post('/fruits', function () {
    // リクエストボディからデータを取得
    $name = request('name');
    $price = request('price');

    // 本来はデータベースに保存する
    return response()->json([
        'message' => '作成しました',
        'data' => [
            'id' => 4,
            'name' => $name,
            'price' => $price,
        ]
    ], 201);
});
```

Postmanで確認

```
POST http://localhost:8000/api/fruits
Content-Type: application/json

{
    "name": "ぶどう",
    "price": 500
}
```

レスポンス（ステータスコード 201）

```json
{
    "message": "作成しました",
    "data": {
        "id": 4,
        "name": "ぶどう",
        "price": 500
    }
}
```


## Step 5 HTTPステータスコード

レスポンスにはステータスコードが含まれます。ステータスコードは3桁の数字で、先頭の数字によって大まかな意味が決まっています。

| 範囲 | 分類 |
|------|------|
| 2xx | 成功 |
| 3xx | リダイレクト |
| 4xx | クライアントエラー（リクエスト側の問題） |
| 5xx | サーバーエラー（サーバー側の問題） |

よく使うステータスコード

| コード | 意味 | 用途 |
|--------|------|------|
| 200 | OK | 成功（デフォルト） |
| 201 | Created | 作成成功 |
| 204 | No Content | 成功（レスポンスボディなし、削除時など） |
| 400 | Bad Request | リクエストが不正 |
| 404 | Not Found | 見つからない |
| 422 | Unprocessable Entity | バリデーションエラー |
| 500 | Internal Server Error | サーバーエラー |

詳しくは [MDN - HTTPレスポンスステータスコード](https://developer.mozilla.org/ja/docs/Web/HTTP/Status) を参照してください。

### ステータスコードを指定する

```php
// 201 Created
return response()->json($data, 201);

// 404 Not Found
return response()->json(['message' => 'Not Found'], 404);

// ステータスコードなし（200がデフォルト）
return $data;
```


## ルート一覧の確認

定義したルートを確認するコマンドがあります。

```bash
php artisan route:list --path=api
```

```
GET|HEAD   api/fruits ........
GET|HEAD   api/fruits/{id} ...
POST       api/fruits ........
GET|HEAD   api/hello .........
```

## 練習問題

### 問題1
`/api/status` にアクセスすると、現在の日時とアプリケーション名を返すAPIを作成してください。

<details>
<summary>解答例</summary>

```php
Route::get('/status', function () {
    return [
        'app_name' => config('app.name'),
        'datetime' => now()->toISOString(),
    ];
});
```
</details>

### 問題2
`/api/users` にアクセスすると、3人分のユーザー情報（id, name, email）を返すAPIを作成してください。

<details>
<summary>解答例</summary>

```php
Route::get('/users', function () {
    return [
        ['id' => 1, 'name' => '田中太郎', 'email' => 'tanaka@example.com'],
        ['id' => 2, 'name' => '山田花子', 'email' => 'yamada@example.com'],
        ['id' => 3, 'name' => '佐藤一郎', 'email' => 'sato@example.com'],
    ];
});
```
</details>


## 参考資料

- [Laravel 公式ドキュメント - Routing](https://laravel.com/docs/routing)
- [Laravel 公式ドキュメント - HTTP Responses](https://laravel.com/docs/responses)


## 次のレッスン

[Lesson 2 デバッグ手法を身につける](./02-debugging.md) では、開発中の問題解決に役立つデバッグ手法を学びます。
