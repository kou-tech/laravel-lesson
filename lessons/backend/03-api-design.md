# Lesson 3 API設計の基本

## 学習目標

このレッスンでは、RESTful APIの設計原則を学び、一貫性のあるAPI設計ができるようになります。

### 到達目標
- RESTful APIの基本原則を理解する
- 適切なエンドポイント設計ができる
- HTTPメソッドとステータスコードを正しく使える

> このレッスンはコードを書かない設計・理論中心のレッスンです。手を動かす実装は Lesson 4 以降で行います。末尾の練習問題もエンドポイントを考える設計演習です。


## RESTful APIとは

REST（Representational State Transfer）は、Web APIを設計するためのアーキテクチャスタイルです。

### RESTの基本的な考え方

| 観点 | 内容 |
|------|------|
| リソース指向 | URLは「もの（名詞）」を表す（例: `/users`, `/courses`） |
| HTTPメソッド | GET=取得、POST=作成、PATCH=更新、DELETE=削除 |
| ステートレス | 各リクエストは独立しており、セッションに依存しない |


## HTTPメソッドの使い分け

| メソッド | 用途 | 例 |
|---------|------|-----|
| GET | 取得（読み取り） | ユーザー一覧を取得 |
| POST | 作成 | 新しいユーザーを作成 |
| PUT | 全体更新 | ユーザー情報を完全に置き換え |
| PATCH | 部分更新 | ユーザー名のみ更新 |
| DELETE | 削除 | ユーザーを削除 |

### PUT vs PATCH

```json
// 元のデータ
{
    "name": "田中",
    "email": "tanaka@example.com",
    "role": "student"
}

// PUT: 全フィールドを送る必要がある
PUT /users/1
{
    "name": "田中太郎",
    "email": "tanaka@example.com",
    "role": "student"
}

// PATCH: 変更するフィールドのみでOK
PATCH /users/1
{
    "name": "田中太郎"
}
```


## エンドポイント設計のルール

### 1. リソースは複数形

```
✅ /users
✅ /courses
❌ /user
❌ /course
```

### 2. 名詞を使う（動詞は使わない）

```
✅ POST /users（ユーザー作成）
❌ POST /createUser

✅ GET /users/1/courses（ユーザー1の講座一覧）
❌ GET /getUserCourses/1
```

### 3. ネストは浅く

```
✅ /users/1/courses（ユーザーの受講講座）
✅ /courses/1/students（講座の受講生）
❌ /users/1/courses/2/lessons/3/comments（深すぎる）
```

### 4. フィルタリングはクエリパラメータ

```
✅ GET /courses?status=active
✅ GET /users?role=instructor
❌ GET /courses/active
❌ GET /users/instructors
```


## HTTPステータスコード

### 成功系 (2xx)

| コード | 意味 | 用途 |
|--------|------|------|
| 200 | OK | 取得・更新成功 |
| 201 | Created | 作成成功 |
| 204 | No Content | 削除成功（レスポンスボディなし） |

### クライアントエラー系 (4xx)

| コード | 意味 | 用途 |
|--------|------|------|
| 400 | Bad Request | リクエストが不正 |
| 401 | Unauthorized | 認証が必要 |
| 403 | Forbidden | 認可エラー（権限なし） |
| 404 | Not Found | リソースが存在しない |
| 422 | Unprocessable Entity | バリデーションエラー |

### サーバーエラー系 (5xx)

| コード | 意味 | 用途 |
|--------|------|------|
| 500 | Internal Server Error | サーバー内部エラー |
| 503 | Service Unavailable | メンテナンス中 |


## API設計のベストプラクティス

### 1. 一貫性を保つ

```json
// ✅ 全てのレスポンスで同じ構造
{
    "data": { ... },
    "meta": { ... }
}

// ❌ レスポンスごとに構造が違う
{ "user": { ... } }
{ "result": { ... } }
```

### 2. エラーレスポンスも統一

```json
{
    "message": "エラーの概要",
    "errors": {
        "field_name": ["エラー詳細1", "エラー詳細2"]
    }
}
```

### 3. ページネーション情報を含める

```json
{
    "data": [...],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 100,
        "last_page": 7
    }
}
```

## 練習問題

### 問題1
「生徒が自分の受講履歴を取得する」エンドポイントを設計してください。

<details>
<summary>解答例</summary>

```
GET /api/me/attendances
```

認証済みユーザー自身の受講履歴を返すエンドポイントです。`/api/me` で「自分自身」を表し、そのサブリソースとして `attendances` を取得します。

</details>

### 問題2
以下のエンドポイント設計の問題点を指摘し、正しいエンドポイントを提案してください。

```
GET /api/getCourseById/1
POST /api/createNewCourse
DELETE /api/course/1/delete
```

<details>
<summary>解答例</summary>

| 修正前 | 問題点 | 修正後 |
|--------|--------|--------|
| `GET /api/getCourseById/1` | 動詞を使っている | `GET /api/courses/1` |
| `POST /api/createNewCourse` | 動詞を使っている、単数形 | `POST /api/courses` |
| `DELETE /api/course/1/delete` | 単数形、URLに動詞が含まれている | `DELETE /api/courses/1` |

HTTPメソッドが操作を表すため、URLには名詞（リソース名）のみを使います。リソース名は複数形にします。

</details>


## 参考資料

- [REST API Tutorial](https://restfulapi.net/)
- [HTTP Status Codes](https://developer.mozilla.org/ja/docs/Web/HTTP/Status)


## 次のレッスン

[Lesson 4 User APIの実装](./04-user-api.md) では、ControllerとAPI Resourceを使って本格的なUser APIを実装します。
