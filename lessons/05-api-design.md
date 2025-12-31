# Lesson 5: API設計の基本

## 学習目標

このレッスンでは、RESTful APIの設計原則を学び、受講管理システム全体のAPI設計を行います。

### 到達目標
- RESTful APIの基本原則を理解する
- 適切なエンドポイント設計ができる
- HTTPメソッドとステータスコードを正しく使える
- 受講管理システムのAPI仕様を設計できる

---

## RESTful APIとは？

**REST**（Representational State Transfer）は、Web APIを設計するためのアーキテクチャスタイルです。

### RESTの6原則

1. **クライアント・サーバー分離**: フロントエンドとバックエンドを分離
2. **ステートレス**: サーバーはリクエスト間で状態を保持しない
3. **キャッシュ可能**: レスポンスをキャッシュできる
4. **統一インターフェース**: 一貫したURL設計
5. **レイヤードシステム**: 中間層（ロードバランサー等）を挟める
6. **オンデマンドコード**（オプション）

### リソース指向

RESTful APIでは、**リソース**（データ）を中心に設計します。

```
リソース例:
- ユーザー (users)
- 講座 (courses)
- 受講 (enrollments)
```

---

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

---

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

---

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

---

## 受講管理システムのAPI設計

### エンティティ関係図（ER図）

```
┌─────────────┐       ┌─────────────────┐       ┌─────────────┐
│   users     │       │   enrollments   │       │   courses   │
├─────────────┤       ├─────────────────┤       ├─────────────┤
│ id          │───┐   │ id              │   ┌───│ id          │
│ name        │   │   │ user_id     ────│───┘   │ title       │
│ email       │   └───│─course_id       │       │ description │
│ password    │       │ enrolled_at     │       │ instructor_id│──┐
│ role        │       │ status          │       │ capacity    │  │
└─────────────┘       └─────────────────┘       │ created_at  │  │
      ▲                                         └─────────────┘  │
      │                                                          │
      └──────────────────────────────────────────────────────────┘
              講師（instructor）もUserテーブルの一員
```

### 関係性

- **User** (1) → (N) **Enrollment**: 1人のユーザーは複数の講座を受講できる
- **Course** (1) → (N) **Enrollment**: 1つの講座には複数の受講者がいる
- **User** (1) → (N) **Course**: 1人の講師は複数の講座を担当できる

---

## API仕様一覧

### 認証関連

| メソッド | エンドポイント | 説明 | 認証 |
|---------|---------------|------|------|
| POST | /login | ログイン | 不要 |
| POST | /logout | ログアウト | 必要 |
| POST | /register | ユーザー登録 | 不要 |

### ユーザー関連

| メソッド | エンドポイント | 説明 | 認証 | 認可 |
|---------|---------------|------|------|------|
| GET | /api/me | 自分の情報を取得 | 必要 | - |
| GET | /api/users | ユーザー一覧 | 必要 | 講師のみ |
| GET | /api/users/{id} | ユーザー詳細 | 必要 | 自分 or 講師 |
| PATCH | /api/users/{id} | ユーザー更新 | 必要 | 自分のみ |

### 講座関連

| メソッド | エンドポイント | 説明 | 認証 | 認可 |
|---------|---------------|------|------|------|
| GET | /api/courses | 講座一覧 | 不要 | - |
| GET | /api/courses/{id} | 講座詳細 | 不要 | - |
| POST | /api/courses | 講座作成 | 必要 | 講師のみ |
| PATCH | /api/courses/{id} | 講座更新 | 必要 | 担当講師のみ |
| DELETE | /api/courses/{id} | 講座削除 | 必要 | 担当講師のみ |

### 受講関連

| メソッド | エンドポイント | 説明 | 認証 | 認可 |
|---------|---------------|------|------|------|
| GET | /api/courses/{id}/enrollments | 講座の受講者一覧 | 必要 | 担当講師のみ |
| POST | /api/courses/{id}/enroll | 講座に申し込む | 必要 | 生徒のみ |
| DELETE | /api/courses/{id}/enroll | 受講をキャンセル | 必要 | 自分のみ |
| GET | /api/me/enrollments | 自分の受講一覧 | 必要 | - |

---

## リクエスト/レスポンス設計

### 講座一覧 GET /api/courses

**リクエスト**

```http
GET /api/courses?page=1&per_page=10&status=active
```

**レスポンス**

```json
{
    "data": [
        {
            "id": 1,
            "title": "Laravel入門",
            "description": "Laravelの基礎を学びます",
            "instructor": {
                "id": 2,
                "name": "山田先生"
            },
            "capacity": 20,
            "enrolled_count": 15,
            "status": "active",
            "created_at": "2025-01-01T00:00:00Z"
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 10,
        "total": 25,
        "last_page": 3
    }
}
```

### 講座作成 POST /api/courses

**リクエスト**

```http
POST /api/courses
Content-Type: application/json

{
    "title": "Laravel入門",
    "description": "Laravelの基礎を学びます",
    "capacity": 20
}
```

**レスポンス（成功）**

```http
HTTP/1.1 201 Created

{
    "data": {
        "id": 1,
        "title": "Laravel入門",
        "description": "Laravelの基礎を学びます",
        "instructor": {
            "id": 2,
            "name": "山田先生"
        },
        "capacity": 20,
        "enrolled_count": 0,
        "status": "draft",
        "created_at": "2025-01-01T00:00:00Z"
    }
}
```

**レスポンス（バリデーションエラー）**

```http
HTTP/1.1 422 Unprocessable Entity

{
    "message": "The given data was invalid.",
    "errors": {
        "title": ["タイトルは必須です。"],
        "capacity": ["定員は1以上の数値を指定してください。"]
    }
}
```

### 受講申し込み POST /api/courses/{id}/enroll

**リクエスト**

```http
POST /api/courses/1/enroll
```

**レスポンス（成功）**

```http
HTTP/1.1 201 Created

{
    "data": {
        "id": 1,
        "course": {
            "id": 1,
            "title": "Laravel入門"
        },
        "enrolled_at": "2025-01-01T10:00:00Z",
        "status": "enrolled"
    },
    "message": "受講登録が完了しました。"
}
```

**レスポンス（定員オーバー）**

```http
HTTP/1.1 422 Unprocessable Entity

{
    "message": "この講座は定員に達しています。"
}
```

---

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

### 4. 日付はISO 8601形式

```json
{
    "created_at": "2025-01-01T10:00:00Z"  // ✅
    "created_at": "2025/01/01 10:00:00"   // ❌
}
```

---

## まとめ

このレッスンで学んだこと：

1. **RESTful APIの原則**
   - リソース指向
   - 統一インターフェース
   - ステートレス

2. **HTTPメソッド**
   - GET: 取得
   - POST: 作成
   - PATCH: 部分更新
   - DELETE: 削除

3. **エンドポイント設計**
   - 複数形の名詞を使う
   - ネストは浅く
   - フィルタはクエリパラメータ

4. **ステータスコード**
   - 200/201/204: 成功
   - 400/401/403/404/422: クライアントエラー
   - 500: サーバーエラー

---

## 練習問題

### 問題1
「生徒が自分の受講履歴を取得する」エンドポイントを設計してください。

<details>
<summary>解答例</summary>

```
GET /api/me/enrollments
```

または

```
GET /api/users/{id}/enrollments
```
</details>

### 問題2
以下のエンドポイント設計の問題点を指摘してください。

```
GET /api/getCourseById/1
POST /api/createNewCourse
DELETE /api/course/1/delete
```

<details>
<summary>解答</summary>

```
❌ GET /api/getCourseById/1
✅ GET /api/courses/1
→ 動詞を使わない、複数形にする

❌ POST /api/createNewCourse
✅ POST /api/courses
→ 動詞を使わない

❌ DELETE /api/course/1/delete
✅ DELETE /api/courses/1
→ 複数形にする、/deleteは不要（DELETEメソッドで明確）
```
</details>

---

## 次のレッスン

[Lesson 6: Course APIの実装](./06-course-api.md) では、今回設計した講座APIを実装し、Eloquentコレクションの活用を学びます。
