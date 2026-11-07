# Lesson 11 安全なモデルの記述

## 学習目標

このレッスンでは、Mass Assignment脆弱性を理解し、安全なモデル設計を行います。最後の Step 7 で、Lesson 10 で作った Attendance モデルを仕上げます。

### 到達目標
- Mass Assignment脆弱性を理解する
- `$fillable` / `$guarded` を適切に設定できる
- `casts()` メソッドでデータ型を変換できる
- アクセサ/ミューテタを活用できる


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


## Step 1 $fillable で許可するカラムを指定

### $fillableの設定

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

> このプロジェクトの `User` モデルがまさにこの形です。[Lesson 7](./07-authorization.md) で `role` を扱えるようにしたときも、`$fillable` にはあえて追加していません。役割の変更は `$user->role = ...; $user->save();` と明示的に書くことで、「権限の変更はリクエストのついでに起きない」ことをコードで保証しています。
>
> 実際に確かめると、`role` を混ぜても無視されることが分かります。
>
> ```bash
> php artisan tinker --execute '
>   $u = App\Models\User::create([
>     "name" => "テスト", "email" => "mass@example.com",
>     "password" => "password", "role" => "instructor",
>   ]);
>   echo $u->fresh()->role->value;   // student （instructor は無視される）
> '
> ```
>
> `create()` は、DBのカラムの既定値（`role` の `student`）をモデルに読み戻しません。作った直後の `$u->role` は `null` なので、`fresh()` でDBから読み直してから確かめています。
>
> なお、シーダーとFactoryは `Model::unguarded()` の中で実行されるため、この制限を受けません。テストデータで自由に `role` を指定できるのはそのためです。

> 補足: Laravel 13 からは、`$fillable` プロパティの代わりにクラスへ `#[Fillable]` 属性を付けても同じ設定ができます。公式ドキュメントの例も属性の書き方に変わりました。`$guarded` / `$hidden` / `$visible` / `$appends` にも、それぞれ `#[Guarded]` / `#[Hidden]` / `#[Visible]` / `#[Appends]` 属性があります。プロパティの書き方も引き続き使えます。本プロジェクトのモデルはプロパティで書いているため、このコースはプロパティの書き方で進めます。
>
> ```php
> use Illuminate\Database\Eloquent\Attributes\Fillable;
>
> #[Fillable(['name', 'email', 'password'])]
> class User extends Authenticatable
> {
>     // ...
> }
> ```

### 安全なコントローラー

```php
public function store(Request $request)
{
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users'],
        'password' => ['required', 'min:8'],
    ]);

    // $fillable に含まれるカラムのみ設定される
    $user = User::create($validated);

    return new UserResource($user);
}
```


## Step 2 $guarded で禁止するカラムを指定

### $guardedの設定

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

推奨は `$fillable` を使うこと（明示的で安全）です。

### $guarded = [] は危険

```php
// ❌ 全てのカラムが Mass Assignment 可能
protected $guarded = [];
```

テストやシーダーで使うことはありますが、本番コードでは避けてください。


## Step 3 casts() でデータ型を変換

### なぜキャストが必要？

データベースから取り出した値は、PHP で扱いたい型と一致するとは限りません。整数のカラムは `int` で返りますが、真偽値は `0` / `1` の整数、日時は文字列のまま返ってきます。

```php
$course = Course::find(1);

// キャストなし
var_dump($course->capacity);   // int(20)
var_dump($course->is_active);  // int(1)
var_dump($course->starts_at);  // string(19) "2026-04-01 10:00:00"

// キャストあり
var_dump($course->capacity);   // int(20)
var_dump($course->is_active);  // bool(true)
var_dump($course->starts_at);  // object(Illuminate\Support\Carbon)
```

> 補足: PHP 8.0 までは、SQLite から取り出した値は整数でも文字列（`"20"`）で返っていました（MySQL も接続の設定によっては同じでした）。PHP 8.1 で PDO の挙動が変わり、整数と浮動小数点数は PHP の `int` / `float` で返るようになっています。古い記事で「DB の値は全部文字列」と説明されているのはこのためです。真偽値・日時・JSON・Enum は今でもキャストしないと期待した型になりません。

### casts() の設定

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

> 補足: Laravel 10 までは `protected $casts = [...];` というプロパティで書くのが標準でした。Laravel 11 から `casts()` メソッドで書く形が標準になり、本プロジェクトのモデルもこの形です。古い記事で `$casts` プロパティを見かけても、同じ設定だと読み替えてください。プロパティの書き方も引き続き動きます。

### 主要なキャスト型

| 型 | 説明 | 例 |
|---|------|-----|
| `integer` | 整数に変換 | `"20"` → `20` |
| `boolean` | 真偽値に変換 | `1` → `true` |
| `array` | JSON を配列に | `'{"a":1}'` → `['a' => 1]` |
| `object` | JSON をオブジェクトに | `'{"a":1}'` → `stdClass` |
| `datetime` | Carbon インスタンスに | `"2025-01-01"` → `Carbon` |
| `date` | 日付のみの Carbon | 時刻を切り捨て |
| `decimal:N` | 小数点N桁の文字列 | `1000` → `"1000.00"` |
| `encrypted` | 暗号化して保存 | 自動で暗号化/復号 |
| `hashed` | 保存時にハッシュ化 | パスワードに使う |

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


## Step 4 アクセサとミューテタ

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

> 補足: パスワードをミューテタで `bcrypt()` する書き方は、Laravel 10 で `hashed` キャストが入る前によく使われていた方法です。本プロジェクトの User モデルは `casts()` に `'password' => 'hashed'` を持っていて、代入した平文は保存時に自動でハッシュ化されます。上の `password()` ミューテタは仕組みの説明用なので、User モデルには追加しないでください。ミューテタを定義すると `hashed` キャストは使われなくなり、ハッシュ済みの値までもう一度ハッシュ化されます。`UserFactory` はハッシュ済みのパスワードを渡しているため、Factory で作ったユーザーが `password` でログインできなくなります。

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


## Step 5 $hidden と $visible

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

`$visible` を設定すると、それ以外は全て隠れるため注意してください。

### 一時的に変更

```php
// 一時的に追加
$user->makeVisible(['password']);

// 一時的に隠す
$user->makeHidden(['email']);
```


## Step 6 属性の追加

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
            get: fn () => $this->capacity - $this->attendances_count,
        );
    }

    protected function isFull(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attendances_count >= $this->capacity,
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

`attendances_count` は、講座を取得するときに `withCount('attendances')` を付けると入る値です。`withCount` は関連するレコードの件数を数え、`リレーション名_count` という名前の属性に入れます。

```php
$course = Course::withCount('attendances')->find(1);
$course->attendances_count;  // 例: 15
```

Lesson 10 の練習問題1で使った `loadCount()` は、取得済みのモデルに後から同じ件数を読み込むメソッドです。`withCount` を付けずに取得すると `attendances_count` は null になり、`available_seats` も `is_full` も正しい値になりません。`withCount` の詳しい使い方と、キャンセル済みを除いて数える書き方は Lesson 16 で学びます。

> 補足: この Step の Course は `$appends` を説明するための例です。このプロジェクトの Course モデルには追加しないでください。講座詳細のように `withCount` を付けずに取得する箇所では、上のとおり値がずれます。


## Step 7 Attendanceモデルの完成

ここまでの内容を使って、Lesson 10 で作った `app/Models/Attendance.php` を仕上げます。JSON に変換するときに作成日時・更新日時を隠し、ステータスの日本語ラベルを足し、受講中の受講だけを取り出すスコープを用意します。

```php
<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'status',
        'attended_at',
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
            'status' => AttendanceStatus::class,
            'attended_at' => 'datetime',
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
        return $query->where('status', AttendanceStatus::Attending);
    }
}
```

> Lesson 10 で作ったモデルからの変更点は、`$hidden` / `$appends` / アクセサ / スコープの追加だけです。`$fillable` の `attended_at` は残したままにしてください。Lesson 10 の `AttendanceController` が `'attended_at' => now()` を渡しているため、ここから外すとその指定が無視されます（DB側の `useCurrent()` で値自体は入るので、エラーにならず気付きにくい種類の事故です）。`$fillable` を変えるときは、そのカラムを渡している箇所がないか必ず確認する習慣をつけてください。

### 動作確認

`make app` でコンテナに入り、Lesson 10 の動作確認で登録した受講を1件取り出して確かめます。受講が1件もない場合は、Lesson 10 の手順で1件登録してから試してください。

```bash
php artisan tinker --execute '
  print_r(App\Models\Attendance::first()->toArray());
  echo App\Models\Attendance::active()->count();
'
```

`toArray()` の結果に `status_label`（例: `受講中`）が含まれ、`created_at` と `updated_at` が含まれていなければ成功です。最後の行には、キャンセル済みを除いた受講の件数が表示されます。`active` スコープは、Lesson 16 で自分の受講一覧を作るときに使います。

> 補足: 受講登録APIのレスポンスは、Postman で見ても変わりません。`AttendanceResource` が返すキーを自分で並べているためです。`$hidden` と `$appends` の設定が使われるのは、モデルをそのまま `toArray()` や `response()->json()` に渡したときです。

## 練習問題

### 問題1
User モデルに「登録からの日数」を返す `days_since_registration` アクセサを追加してください。

<details>
<summary>解答例</summary>

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

protected function daysSinceRegistration(): Attribute
{
    return Attribute::make(
        get: fn () => (int) $this->created_at->diffInDays(now()),
    );
}
```

```php
$user->days_since_registration; // 例: 30
```
</details>

### 問題2
Course モデルに、タイトルを保存時に前後の空白を除去するミューテタを追加してください。

<details>
<summary>解答例</summary>

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

protected function title(): Attribute
{
    return Attribute::make(
        set: fn (string $value) => trim($value),
    );
}
```

```php
$course->title = '  Laravel入門  ';
$course->save();
// DB には 'Laravel入門' が保存される
```
</details>

## クリア判定

次のコマンドで、Step 7 の Attendance モデルの仕上げができたかを確かめましょう。

```bash
make quest-11
```

`status_label` が付くこと、`created_at` と `updated_at` が出ないこと、`active` スコープが受講中だけを返すことがメインのミッションです。練習問題1・2はサイドのミッションです。

## 参考資料

- [Laravel 公式ドキュメント - Mass Assignment](https://laravel.com/docs/eloquent#mass-assignment)
- [Laravel 公式ドキュメント - Attribute Casting](https://laravel.com/docs/eloquent-mutators#attribute-casting)
- [Laravel 公式ドキュメント - Accessors & Mutators](https://laravel.com/docs/eloquent-mutators#accessors-and-mutators)

## 次のレッスン

[Lesson 12 自動テストの書き方](./12-testing.md) では、Pest で自動テストを書き、Lesson 10 で作った受講登録APIの動きをテストで確かめます。
