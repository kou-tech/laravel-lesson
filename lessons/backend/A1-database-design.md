# 発展 A1 データベース設計の原則

## 学習目標

このレッスンでは、堅牢なデータベース設計の原則を理解し、適切なマイグレーションを書けるようになります。

### 到達目標
- 外部キー制約を正しく設定し、onDelete を使い分けられる
- インデックスの役割と追加タイミングを理解する
- NULL許可のデメリットを理解し適切に設計できる
- 本番のデータを壊さないマイグレーションを書ける

> この発展レッスンは本線のレッスンとは独立していて、いつ読んでもかまいません。Lesson 10 で attendances テーブルを作るときに使った外部キー制約・複合ユニーク制約・NOT NULL・段階的なカラム追加について、その背景を詳しく扱います。本線のレッスンは、A1 を読んでいなくても進められます。
>
> 原則の説明が中心なので、サンプルコードには `phone` / `nickname` など、プロジェクトにない仮の題材が含まれます。プロジェクトのコードには反映しません（練習問題だけは手を動かして試せます。問題2の注意を読んでから取り組んでください）。

## データベース設計の重要性

データベースはアプリケーションの土台です。

設計を間違えると、データの整合性が崩れたり、パフォーマンスが悪化したり、後からの修正が困難になります。

## Step 1 外部キー制約

### 外部キー制約とは

外部キー制約は、テーブル間の参照整合性を保証する仕組みです。

```php
$table->foreignIdFor(User::class)->constrained();
```

これにより、`user_id` に存在しないユーザーIDを入れようとするとエラーになり、データの整合性が保証されます。

### onDelete オプション

参照先が削除された時の動作を指定します。

```php
// CASCADE: 親が削除されたら子も削除
$table->foreignIdFor(User::class)
    ->constrained()
    ->onDelete('cascade');

// SET NULL: 親が削除されたらNULLに
$table->foreignIdFor(User::class, 'instructor_id')
    ->nullable()
    ->constrained('users')
    ->onDelete('set null');

// RESTRICT: 子がいる場合は削除を拒否（デフォルト）
$table->foreignIdFor(Category::class)
    ->constrained()
    ->onDelete('restrict');
```

### 使い分けの指針

| オプション | 用途例 |
|-----------|--------|
| cascade | 親が消えたら子も不要（コメントなど） |
| set null | 参照は消えるが履歴は残したい（担当者の退職など） |
| restrict | 削除させたくない（カテゴリに商品があれば削除不可） |

> Lesson 10 の attendances テーブルは、受講履歴を残したいので `restrict` を選んでいます。受講は「誰がいつ受けたか、修了したか」を記録する監査データに近く、講座やユーザーを削除したときに履歴だけ静かに消えると困るためです。

## Step 2 インデックス

### インデックスとは

インデックスは、検索を高速化するための索引です。

本の索引をイメージしてください。索引がない場合は本を最初から読んで探す必要がありますが（フルスキャン）、索引があれば該当箇所を特定してジャンプできます。

### インデックスが必要な場面

```php
// WHERE句で頻繁に使うカラム
$table->string('status')->index();

// 検索・ソートに使うカラム
$table->datetime('attended_at')->index();
```

### 外部キー・unique 制約とインデックス

制約を付けたときに、インデックスも一緒に張られることがあります。

- unique 制約は、どの DB でもインデックスとして作られる
- 外部キー制約を付けたとき、MySQL はそのカラムにインデックスがなければ自動で作る
- SQLite は、外部キー制約を付けてもインデックスを自動では作らない

開発環境（SQLite）と本番（MySQL）で DB が違うと、同じマイグレーションでもインデックスの有無が変わります。検索に使う外部キーのカラムには、必要に応じて `$table->index('course_id')` のように明示して張っておくと確実です。

### 複合インデックス

複数カラムを組み合わせた検索に有効です。

```php
// user_id と status の組み合わせで検索することが多い場合
$table->index(['user_id', 'status']);
```

複合インデックスは、左側のカラムから順に使われます。`['user_id', 'status']` のインデックスは、`user_id` だけで絞る検索には使えますが、`status` だけで絞る検索には基本的に使えません。

> Lesson 10 の attendances テーブルの複合ユニーク制約 `['user_id', 'course_id']` も、`user_id` から始まるインデックスになります。そのため「あるユーザーの受講一覧」の検索には使えますが、`hasCapacity()` のように `course_id` だけで絞る検索には、SQLite では使えません。受講のデータが増えて件数の取得が遅くなったら、`course_id` にインデックスを足すマイグレーションを書きます。

### インデックスのデメリット

インデックスには書き込みが遅くなる（INSERT/UPDATE時にインデックスも更新）、ストレージを消費する（インデックス分の容量が必要）というデメリットがあります。

原則として、検索に使うカラムにのみ追加してください。

## Step 3 NULL許可のデメリット

### NULLとは

`NULL` は「値がない」状態を表します。空文字 `''` とは異なります。

### NULLの問題点

#### 1. 比較が直感的でない

`NULL` は「＝」で比較できず、専用の書き方が必要です。

```sql
-- NULL は等価比較できない
SELECT * FROM users WHERE email = NULL;  -- 結果は常に0件
SELECT * FROM users WHERE email IS NULL; -- これが正しい
```

Eloquent で書くと以下に対応します。SQLを書けるようになる必要はありませんが、「`where('email', null)` ではなく `whereNull('email')` を使う」という対応関係だけ押さえてください。

```php
User::where('email', null)->get(); // 期待通りに動かない
User::whereNull('email')->get();   // これが正しい
```

#### 2. コードが複雑になる

```php
// NULLチェックが必要
if ($user->phone !== null) {
    // 電話番号がある場合の処理
}

// NULLセーフな書き方
$phone = $user->phone ?? 'なし';
```

### NULL vs NOT NULL + デフォルト値

```php
// ❌ NULLを許可
$table->string('nickname')->nullable();

// ✅ NOT NULL + デフォルト値
$table->string('nickname')->default('');

// ❌ NULLを許可
$table->integer('login_count')->nullable();

// ✅ NOT NULL + デフォルト値
$table->integer('login_count')->default(0);
```

### NULLが適切なケース

- 「未設定」と「空」を区別する必要がある
- 外部キーで「関連なし」を表現する

## Step 4 複合ユニーク制約

### 問題（重複登録を防ぐ）

同じユーザーが同じ講座に2回登録することを防ぎたい。

### アプリケーションレベルでの対策

```php
// ❌ 不十分（競合状態で漏れる可能性）
$isAttending = Attendance::where('user_id', $userId)
    ->where('course_id', $courseId)
    ->exists();

if ($isAttending) {
    return response()->json(['message' => 'すでにこの講座に登録済みです。'], 409);
}

Attendance::create([...]);
```

同じユーザーのリクエストがほぼ同時に2つ届くと、どちらも `exists()` の時点ではまだ登録がないので確認を通り抜け、2件とも保存されてしまいます。

### データベースレベルでの対策

```php
// ✅ 複合ユニーク制約
$table->unique(['user_id', 'course_id']);
```

これにより、重複挿入時にDBがエラーを返します。

### 例外処理

複合ユニーク制約違反は、Laravel 10.x の途中で追加された `UniqueConstraintViolationException` でキャッチできます（本プロジェクトは Laravel 13）。DB ドライバ（MySQL / SQLite など）に依存せずに判定できるのが利点です。

```php
use Illuminate\Database\UniqueConstraintViolationException;

try {
    Attendance::create([
        'user_id' => $userId,
        'course_id' => $courseId,
    ]);
} catch (UniqueConstraintViolationException) {
    return response()->json(['message' => 'すでにこの講座に登録済みです。'], 409);
}
```

> 以前はドライバごとのエラーコード（MySQL なら `1062`）を `QueryException::errorInfo` で判定していましたが、SQLite では別のコードが返るため非可搬でした。`UniqueConstraintViolationException` に寄せるのがおすすめです。

> Lesson 10 の `AttendanceController` はこの形で 409 を返しています。Lesson 14 でサービスクラスに切り出すときは、`exists()` による確認を前に置いて分かりやすいエラーを返しつつ、この catch を最後の守りとして残します。

## Step 5 マイグレーションのベストプラクティス

### 1. 小さな単位で作成

```bash
# ✅ 1つの変更 = 1つのマイグレーション
php artisan make:migration add_phone_to_users_table
php artisan make:migration add_index_to_attendances_status

# ❌ 複数の無関係な変更を1つに
php artisan make:migration update_multiple_tables
```

### 2. down() メソッドを実装

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('phone')->nullable()->after('email');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('phone');
    });
}
```

### 3. 本番データがある場合の変更

```php
// NOT NULL カラムを追加する場合
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // 一旦nullable で追加
        $table->string('nickname')->nullable();
    });

    // デフォルト値を設定
    DB::table('users')->whereNull('nickname')->update(['nickname' => '']);

    // NOT NULLに変更
    Schema::table('users', function (Blueprint $table) {
        $table->string('nickname')->nullable(false)->change();
    });
}
```

> Lesson 10 で `courses` テーブルに `starts_at` を追加したときも、この3段階の手順を使っています。

### 4. 破壊的変更は慎重に

本番環境でのカラム削除・リネームには注意が必要です。

```php
// ❌ いきなり削除
$table->dropColumn('old_column');

// ✅ 段階的に
// 1. 新カラム追加
// 2. データ移行
// 3. アプリケーション更新
// 4. 旧カラム削除
```

## 練習問題

### 問題1
以下の要件を満たすマイグレーションを作成してください。

- `course_reviews` テーブル
- 講座に対するレビュー（1講座につき1ユーザー1レビュー）
- 評価（1-5の整数、必須）
- コメント（任意）

<details>
<summary>解答例</summary>

```php
Schema::create('course_reviews', function (Blueprint $table) {
    $table->id();
    $table->foreignIdFor(User::class)->constrained()->onDelete('cascade');
    $table->foreignIdFor(Course::class)->constrained()->onDelete('cascade');
    $table->unsignedTinyInteger('rating');  // 1-5
    $table->text('comment')->nullable();
    $table->datetimes();

    $table->unique(['user_id', 'course_id']);
    $table->index('course_id');  // 講座ごとのレビュー取得用
});
```

`unique(['user_id', 'course_id'])` のインデックスは `user_id` から始まるので、講座ごとにレビューを取得する検索には使えません。そのため `course_id` にも別にインデックスを張っています（Step 2 の「複合インデックス」を参照）。
</details>

### 問題2
既存の `courses` テーブルに `thumbnail_url` カラム（NOT NULL、文字列）を追加するマイグレーションを作成してください。既存データには空文字を設定します。Step 5 の「3. 本番データがある場合の変更」で学んだ「一旦nullableで追加 → データ移行 → NOT NULLに変更」の手順を使ってください。

> 注意: このカラムにはデフォルト値がありません。自分の作業ブランチでマイグレーションを適用したままにすると、`thumbnail_url` を受け取らない `POST /api/courses` や、`CourseFactory` で講座を作る処理（シーダー・テスト・判定テスト）が、すべて NOT NULL 違反で落ちます。
>
> 試すときは、`git switch -c practice/a1-thumbnail` のように別のブランチを作ってから取り組んでください。試し終わったら、ブランチを戻す前に `php artisan migrate:rollback` で取り消します。DB のファイルは git の管理外なので、ブランチを戻しただけではカラムが残ります。作業ブランチの上で試した場合も、`migrate:rollback` で取り消したうえで、マイグレーションのファイルを消してください。ファイルが残っていると、次の `make fresh` やテストの実行でまた適用されます。

<details>
<summary>解答例</summary>

```php
public function up(): void
{
    Schema::table('courses', function (Blueprint $table) {
        $table->string('thumbnail_url')->nullable()->after('description');
    });

    // 既存データにデフォルト値を設定
    DB::table('courses')
        ->whereNull('thumbnail_url')
        ->update(['thumbnail_url' => '']);

    // NOT NULLに変更
    Schema::table('courses', function (Blueprint $table) {
        $table->string('thumbnail_url')->nullable(false)->change();
    });
}

public function down(): void
{
    Schema::table('courses', function (Blueprint $table) {
        $table->dropColumn('thumbnail_url');
    });
}
```
</details>

## 次のレッスン

本線のレッスンに戻りましょう。[レッスン一覧](./README.md) から、読む前に進めていたレッスンの続きを選んでください。
