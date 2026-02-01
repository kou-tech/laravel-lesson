# エンティティ関係図（ER図）

```mermaid
erDiagram
    users ||--o{ attendances : "受講する"
    courses ||--o{ attendances : "受講される"
    users ||--o{ courses : "講師として担当"

    users {
        int id PK
        string name
        string email
        string password
        string role
    }

    courses {
        int id PK
        string title
        string description
        int instructor_id FK
        int capacity
        datetime created_at
    }

    attendances {
        int id PK
        int user_id FK
        int course_id FK
        datetime attended_at
        string status
    }
```

講師（instructor）もUserテーブルの一員です。

### 関係性

| 親エンティティ | 子エンティティ | 関係 | 説明 |
|---------------|----------------|------|------|
| User | Attendance | 1 : N | 1人のユーザーは複数の講座を受講できる |
| Course | Attendance | 1 : N | 1つの講座には複数の受講者がいる |
| User | Course | 1 : N | 1人の講師は複数の講座を担当できる |
