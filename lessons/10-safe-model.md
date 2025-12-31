# Lesson 10: 安全なモデルの記述

## 学習目標

このレッスンでは、Mass Assignment脆弱性を理解し、安全なモデル設計を行います。

### 到達目標
- Mass Assignment脆弱性を理解する
- `$fillable` / `$guarded` を適切に設定できる
- `$casts` でデータ型を変換できる
- アクセサ/ミューテタを活用できる

---

## Mass Assignment脆弱性とは？

### 問題のあるコード

```php
public function store(Request $request)
{
    // リクエストの全データをそのまま保存
    $user = User::create($request->all());

    return new UserResource($user);
}
```

### 攻撃シナリオ

正規のリクエスト:
```json
{
    "name": "田中太郎",
    "email": "tanaka@example.com"
}
```

悪意のあるリクエスト:
```json
{
    "name": "田中太郎",
    "email": "tanaka@example.com",
    "role": "admin",
    "is_verified": true
}
```

`$request->all()` を使うと、`role` や `is_verified` も設定されてしまいます。

---

## Step 1: $fillable で許可するカラムを指定

### $fillable の設定

```php
class User extends Authenticatable
{
    /**
     * Mass Assignmentで設定可能なカラム
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];
}
```

これにより、`role` や `is_verified` は `create()` や `update()` で無視されます。

### 安全なコントローラー

```php
public function store(Request $request)
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
        'password' => 'required|min:8',
    ]);

    // $fillable に含まれるカラムのみ設定される
    $user = User::create($validated);

    return new UserResource($user);
}
```

---

## Step 2: $guarded で禁止するカラムを指定

### $guarded の設定

```php
class User extends Authenticatable
{
    /**
     * Mass Assignmentで設定禁止のカラム
     */
    protected $guarded = [
        'id',
        'role',
        'is_admin',
        'email_verified_at',
    ];
}
```

### $fillable vs $guarded

| 設定 | 考え方 | 適切なケース |
|------|--------|-------------|
| `$fillable` | ホワイトリスト | カラム数が少ない、セキュリティ重視 |
| `$guarded` | ブラックリスト | カラム数が多い、柔軟性重視 |

**推奨**: `$fillable` を使う（明示的で安全）

### $guarded = [] は危険

```php
// ❌ 全てのカラムが Mass Assignment 可能
protected $guarded = [];
```

テストやシーダーで使うことはありますが、本番コードでは避けてください。

---

## Step 3: $casts でデータ型を変換

### なぜキャストが必要？

データベースからの取得値は基本的に文字列です。

```php
$course = Course::find(1);

// キャストなし
var_dump($course->capacity);  // string(2) "20"
var_dump($course->is_active); // string(1) "1"

// キャストあり
var_dump($course->capacity);  // int(20)
var_dump($course->is_active); // bool(true)
```

### $casts の設定

```php
class Course extends Model
{
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_published' => 'boolean',
            'settings' => 'array',
            'starts_at' => 'datetime',
            'price' => 'decimal:2',
            'status' => CourseStatus::class,  // Enum
        ];
    }
}
```

### 主要なキャスト型

| 型 | 説明 | 例 |
|---|------|-----|
| `integer` | 整数に変換 | `"20"` → `20` |
| `boolean` | 真偽値に変換 | `"1"` → `true` |
| `array` | JSON を配列に | `'{"a":1}'` → `['a' => 1]` |
| `object` | JSON をオブジェクトに | `'{"a":1}'` → `stdClass` |
| `datetime` | Carbon インスタンスに | `"2025-01-01"` → `Carbon` |
| `date` | 日付のみの Carbon | 時刻を切り捨て |
| `decimal:N` | 小数点N桁の文字列 | `1000` → `"1000.00"` |
| `encrypted` | 暗号化して保存 | 自動で暗号化/復号 |

### Enumキャスト

```php
// app/Enums/CourseStatus.php
enum CourseStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';
}

// Course.php
protected function casts(): array
{
    return [
        'status' => CourseStatus::class,
    ];
}

// 使用例
$course->status = CourseStatus::Active;
$course->save();

if ($course->status === CourseStatus::Active) {
    // 公開中の講座
}
```

---

## Step 4: アクセサとミューテタ

### アクセサ（取得時の加工）

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

class User extends Authenticatable
{
    /**
     * フルネームを取得（仮想的な属性）
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => "{$this->last_name} {$this->first_name}",
        );
    }

    /**
     * メールアドレスを小文字で取得
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => strtolower($value),
        );
    }
}
```

```php
$user = User::find(1);
echo $user->full_name;  // "山田 太郎"
echo $user->email;      // "yamada@example.com"（小文字）
```

### ミューテタ（保存時の加工）

```php
class User extends Authenticatable
{
    /**
     * パスワードを自動でハッシュ化
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => bcrypt($value),
        );
    }

    /**
     * 電話番号をハイフンなしで保存
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => str_replace('-', '', $value),
        );
    }
}
```

```php
$user->password = 'plain_password';  // 自動でハッシュ化
$user->phone = '090-1234-5678';      // "09012345678" で保存
```

### アクセサ + ミューテタ

```php
protected function name(): Attribute
{
    return Attribute::make(
        get: fn (string $value) => ucfirst($value),
        set: fn (string $value) => strtolower($value),
    );
}
```

---

## Step 5: $hidden と $visible

### APIレスポンスから除外

```php
class User extends Authenticatable
{
    /**
     * シリアライズ時に隠すカラム
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];
}
```

```php
$user = User::find(1);
return response()->json($user);
// password や remember_token は含まれない
```

### 特定のカラムのみ公開

```php
class User extends Authenticatable
{
    /**
     * シリアライズ時に公開するカラム
     */
    protected $visible = [
        'id',
        'name',
        'email',
    ];
}
```

**注意**: `$visible` を設定すると、それ以外は全て隠れます。

### 一時的に変更

```php
// 一時的に追加
$user->makeVisible(['password']);

// 一時的に隠す
$user->makeHidden(['email']);
```

---

## Step 6: 属性の追加

### $appends で仮想属性を追加

```php
class Course extends Model
{
    /**
     * シリアライズ時に追加する属性
     */
    protected $appends = [
        'available_seats',
        'is_full',
    ];

    protected function availableSeats(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->capacity - $this->enrollments_count,
        );
    }

    protected function isFull(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->enrollments_count >= $this->capacity,
        );
    }
}
```

```json
{
    "id": 1,
    "title": "Laravel入門",
    "capacity": 20,
    "available_seats": 5,
    "is_full": false
}
```

---

## Step 7: Enrollmentモデルの完成

```php
<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'status',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $appends = [
        'status_label',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'enrolled_at' => 'datetime',
        ];
    }

    // リレーション
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    // アクセサ
    protected function statusLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status->label(),
        );
    }

    // スコープ
    public function scopeActive($query)
    {
        return $query->where('status', EnrollmentStatus::Enrolled);
    }
}
```

---

## まとめ

このレッスンで学んだこと：

1. **Mass Assignment脆弱性**
   - 意図しないカラムが更新される危険
   - `$fillable` で許可リストを指定

2. **$fillable vs $guarded**
   - `$fillable`: ホワイトリスト（推奨）
   - `$guarded`: ブラックリスト

3. **$casts**
   - データ型を自動変換
   - Enum、datetime、array など

4. **アクセサ/ミューテタ**
   - 取得/保存時にデータを加工
   - 仮想属性の作成

5. **$hidden / $visible / $appends**
   - シリアライズの制御

---

## 練習問題

### 問題1
User モデルに「登録からの日数」を返す `days_since_registration` アクセサを追加してください。

<details>
<summary>解答例</summary>

```php
protected function daysSinceRegistration(): Attribute
{
    return Attribute::make(
        get: fn () => $this->created_at->diffInDays(now()),
    );
}
```
</details>

### 問題2
Course モデルに、タイトルを保存時に前後の空白を除去するミューテタを追加してください。

<details>
<summary>解答例</summary>

```php
protected function title(): Attribute
{
    return Attribute::make(
        set: fn (string $value) => trim($value),
    );
}
```
</details>

---

## 次のレッスン

[Lesson 11: トランザクション処理](./11-transaction.md) では、データの整合性を保つためのトランザクション処理を学びます。
