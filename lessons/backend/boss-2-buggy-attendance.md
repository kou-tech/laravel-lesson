# ボス戦2 不具合の入った受講登録を直す

Part 2 の最後のステージです。今回の相手は、すでに書かれた受講登録のクラスです。一見すると Lesson 15 で仕上げた AttendCourse と同じように動きますが、仕様と違う動きをする不具合が3つ入っています。テストを書いて不具合を見つけ、直してください。

ほかの人が書いたコードの不具合を、テストで再現してから直す作業は、実務でもよくあります。先にテストを書いておけば、直したことを確かめられるうえに、同じ不具合が戻ってきたときにも気付けます。

## ボスの説明

配るのは `App\Boss\BuggyAttendCourse` というクラスです。Lesson 14 の AttendCourse と同じく `__invoke(User $user, Course $course): Attendance` で受講登録をし、Lesson 15 で足したトランザクションとロックも入っています。ルートには登録しないので、Postman からは呼べません。テストから直接呼び出して確かめます。

使う知識は次のレッスンで学んだものです。

| 使う知識 | 学んだレッスン |
|---|---|
| 受講登録の仕様、複合ユニーク制約と UniqueConstraintViolationException | Lesson 10 |
| Factory の state（`cancelled()` など） | Lesson 10 |
| Pest でテストを書く、RefreshDatabase | Lesson 12 |
| サービスクラスを `app()` で取り出して呼ぶ、`expect(...)->toThrow()`、業務の例外 | Lesson 14 |
| トランザクションとロック | Lesson 15 |

## 準備

### 前提になるもの

BuggyAttendCourse は、Lesson 10〜15 で作った次のクラスとメソッドを使います。どれかが足りないと、クラスを読み込んだ時点でエラーになります。

| 使うもの | 作ったレッスン |
|---|---|
| `App\Models\Attendance` と `AttendanceFactory`（`cancelled()` の state） | Lesson 10 |
| `App\Enums\AttendanceStatus` | Lesson 10 |
| `Course::attendances()`、`Course::hasCapacity()`、`User::attendances()` | Lesson 10 |
| `App\Exceptions\BusinessException`、`CapacityExceededException`、`AlreadyAttendingException` | Lesson 14 |

テストは Lesson 12 の Step 0 で RefreshDatabase を有効にしてある前提で書きます。

### クラスを配置する

ホストのターミナル（コンテナの外）で、次のコマンドを実行します。

```bash
make boss-2
```

`stubs/boss/BuggyAttendCourse.php.stub` が `app/Boss/BuggyAttendCourse.php` にコピーされます。すでに `app/Boss/BuggyAttendCourse.php` があるときは、直している途中のコードを消さないように、上書きせずに止まります。最初からやり直したいときは、そのファイルを消してからもう一度実行してください。

自分の AttendCourse と `routes/api.php` は、このボス戦では触りません。直すのは `app/Boss/BuggyAttendCourse.php` だけです。

## 仕様

BuggyAttendCourse が本来満たすべき仕様です。Lesson 14・15 の AttendCourse と同じです。

申し込みは、次の順番で確かめます。

1. 定員の確認。受講中（attending）の受講の件数が定員（capacity）に達していたら、CapacityExceededException を投げる。キャンセル済みの受講は数えない
2. 重複の確認。その生徒がこの講座にキャンセル済み以外の受講をすでに持っていたら、AlreadyAttendingException を投げる
3. 受講の作成。status を attending、attended_at を今の時刻にして Attendance を作る。同時に申し込まれて複合ユニーク制約の違反（UniqueConstraintViolationException）が起きたときも、AlreadyAttendingException を投げる

成功したら、作った Attendance を `user` と `course.instructor` を読み込んだ状態で返します。

例外は Lesson 14 の BusinessException を継承しているので、コントローラーから呼べば次のレスポンスになります。テストでは例外の型を確かめれば十分です。

| 状況 | 例外 | ステータス | error_code |
|---|---|---|---|
| 定員に達している | CapacityExceededException | 422 | CAPACITY_EXCEEDED |
| すでに登録している | AlreadyAttendingException | 409 | ALREADY_ATTENDING |

トランザクションとロック（`DB::transaction` と `lockForUpdate()`）は仕様どおりに入っています。不具合はそれ以外の場所にあります。

## 進め方

テストのファイルは `tests/Feature/Boss/BuggyAttendCourseTest.php` に作ります。`make app` でコンテナに入ってから、次のコマンドを実行してください。

```bash
php artisan make:test --pest Boss/BuggyAttendCourseTest
```

テストでは、ルートを通さずにクラスを直接呼びます。書き方は Lesson 14 で CreateCourse のテストを書いたときと同じです。

```php
use App\Boss\BuggyAttendCourse;

// 申し込みが成功するか
$attendance = app(BuggyAttendCourse::class)($student, $course);

// 例外が投げられるか
expect(fn () => app(BuggyAttendCourse::class)($student, $course))
    ->toThrow(CapacityExceededException::class);
```

おすすめの進め方は、コードを読む前に、仕様からテストを書くことです。仕様の1〜3それぞれについて「こうなるはず」をテストにして走らせ、赤になったテストが不具合の場所を教えてくれます。

```bash
php artisan test --compact tests/Feature/Boss/BuggyAttendCourseTest.php
```

不具合を1つ見つけたら、そのテストが赤のまま直すコードを書き、緑になることを確かめてから次へ進みます。直す前に赤になるところを見ておかないと、テストが本当にその不具合を捕まえているのかが分かりません。

## クリア条件

すべてにチェックが付いたらクリアです。

- [ ] 3つの不具合それぞれについて、直す前は赤、直した後は緑になるテストを書いた
- [ ] 定員に空きがあるときに申し込めることを確かめるテストがある（直しすぎて、申し込めるはずの人が弾かれていないかを見るため）
- [ ] 直した BuggyAttendCourse で、自分の書いたテストがすべて緑になる
- [ ] トランザクションとロックは消さずに残っている
- [ ] `make test` で、これまでのテストも緑のまま

## ヒント

<details>
<summary>ヒント1 どこを狙うか</summary>

不具合は、仕様の1〜3のそれぞれに1つずつあります。まずは3つの項目ごとに、テストを1つずつ書いてみてください。

定員のテストは、境界の値を使うと不具合が見つかりやすくなります。たとえば定員1の講座にすでに1人いる状態で申し込む、というデータです。「定員に1人足りない」「ちょうど定員」のどちらで何が起きるべきかを、仕様から考えてみてください。

</details>

<details>
<summary>ヒント2 テストが緑なのに、不具合がありそうなとき</summary>

不具合が2つ重なると、たまたま正しい結果になることがあります。たとえば「定員1の講座で、キャンセル済みが1人だけ」というデータでは、このクラスは申し込みを受け付けるので、キャンセル済みのテストが緑になります。

キャンセル済みの扱いを確かめたいときは、受講中とキャンセル済みを合わせると定員を超えるデータにしてください。定員2の講座に受講中が1人、キャンセル済みが2人いれば、本来はまだ申し込めるはずです。

</details>

<details>
<summary>ヒント3 それぞれの不具合の場所</summary>

1つ目と2つ目は、`validate()` の中で件数を数えて比べている部分にあります。何を数えているか、どの比較演算子で比べているかを、Course の `hasCapacity()` と見比べてください。`hasCapacity()` をそのまま使って直してもかまいません。

3つ目は、同じ講座に2回申し込んだときに投げられる例外の型を見ると分かります。テストの失敗メッセージに出てくる例外のクラス名と、`catch` が捕まえている例外のクラス名を比べてください。仕様の2にある重複の確認が、`validate()` のどこにも書かれていないことにも気付けるはずです。

</details>

## 解答例

<details>
<summary>解答例</summary>

### 不具合を見つけるテスト

`tests/Feature/Boss/BuggyAttendCourseTest.php`

```php
<?php

use App\Boss\BuggyAttendCourse;
use App\Enums\AttendanceStatus;
use App\Exceptions\AlreadyAttendingException;
use App\Exceptions\CapacityExceededException;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;

test('定員に空きがあれば申し込める', function () {
    $student = User::factory()->student()->create();
    $course = Course::factory()->create(['capacity' => 2]);
    Attendance::factory()->for($course)->create();

    $attendance = app(BuggyAttendCourse::class)($student, $course);

    expect($attendance->user_id)->toBe($student->id)
        ->and($attendance->course_id)->toBe($course->id)
        ->and($attendance->status)->toBe(AttendanceStatus::Attending);
});

test('定員ちょうどまで埋まっている講座には申し込めない', function () {
    $student = User::factory()->student()->create();
    $course = Course::factory()->create(['capacity' => 1]);
    Attendance::factory()->for($course)->create();

    expect(fn () => app(BuggyAttendCourse::class)($student, $course))
        ->toThrow(CapacityExceededException::class);

    expect($course->attendances()->count())->toBe(1);
});

test('キャンセル済みの受講は定員に数えない', function () {
    $student = User::factory()->student()->create();
    $course = Course::factory()->create(['capacity' => 2]);
    Attendance::factory()->for($course)->create();
    Attendance::factory()->for($course)->cancelled()->count(2)->create();

    $attendance = app(BuggyAttendCourse::class)($student, $course);

    expect($attendance->status)->toBe(AttendanceStatus::Attending);
});

test('同じ講座に2回申し込むと AlreadyAttendingException になる', function () {
    $student = User::factory()->student()->create();
    $course = Course::factory()->create(['capacity' => 5]);

    app(BuggyAttendCourse::class)($student, $course);

    expect(fn () => app(BuggyAttendCourse::class)($student, $course))
        ->toThrow(AlreadyAttendingException::class);

    expect($course->attendances()->count())->toBe(1);
});
```

配った直後のクラスでは、1つ目のテストだけが緑で、残りの3つが赤になります。

```
FAILED  定員ちょうどまで埋まっている講座には申し込めない
  Exception [App\Exceptions\CapacityExceededException] not thrown.
FAILED  キャンセル済みの受講は定員に数えない
  CapacityExceededException  この講座は定員に達しています。
FAILED  同じ講座に2回申し込むと AlreadyAttendingException になる
  Failed asserting that an instance of class Illuminate\Database\UniqueConstraintViolationException
  is an instance of class App\Exceptions\AlreadyAttendingException.
```

重複のテストで定員を5にしているのは、定員の確認で先に止まらないようにするためです。定員1のまま2回申し込むと、1つ目の不具合を直した後は CapacityExceededException が投げられ、重複の確認までたどり着きません。

### 直した後のコード

`app/Boss/BuggyAttendCourse.php`

```php
<?php

declare(strict_types=1);

namespace App\Boss;

use App\Enums\AttendanceStatus;
use App\Exceptions\AlreadyAttendingException;
use App\Exceptions\CapacityExceededException;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ボス戦2: 受講登録（不具合入り）
 *
 * このクラスには不具合が3つあります。テストを書いて見つけ、直してください。
 */
class BuggyAttendCourse
{
    public function __invoke(User $user, Course $course): Attendance
    {
        $attendance = DB::transaction(function () use ($user, $course) {
            $lockedCourse = Course::lockForUpdate()->findOrFail($course->id);

            $this->validate($user, $lockedCourse);

            try {
                return Attendance::create([
                    'user_id' => $user->id,
                    'course_id' => $lockedCourse->id,
                    'status' => AttendanceStatus::Attending,
                    'attended_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new AlreadyAttendingException;
            }
        });

        return $attendance->load(['user', 'course.instructor']);
    }

    /**
     * 申し込めるかを確かめる
     */
    private function validate(User $user, Course $course): void
    {
        if (! $course->hasCapacity()) {
            throw new CapacityExceededException;
        }

        $isAttending = $user->attendances()
            ->where('course_id', $course->id)
            ->where('status', '!=', AttendanceStatus::Cancelled)
            ->exists();

        if ($isAttending) {
            throw new AlreadyAttendingException;
        }
    }
}
```

### 3つの不具合

1つ目は、定員の判定の比較演算子です。もとのコードは `$attendanceCount <= $course->capacity` で「空きがある」と判定していたので、定員1の講座に1人いても `1 <= 1` が真になり、2人目が申し込めました。「件数が定員より少ない」なので `<` が正しい比較です。

2つ目は、数えている件数です。もとのコードは `$course->attendances()->count()` で、キャンセル済みも含めたすべての受講を数えていました。キャンセルしても席が空かないので、定員に空きがあるのに申し込めなくなります。Course の `hasCapacity()` は受講中だけを数えているので、そちらを使えば1つ目と2つ目がまとめて直ります。

3つ目は、重複の扱いです。もとのコードには仕様の2（`exists()` による確認）がなく、`catch` は一意制約の違反ではなく InvalidArgumentException を捕まえていました。そのため2回目の申し込みでは UniqueConstraintViolationException がそのまま外に出て、コントローラーから呼べば 500 になります。`catch` を UniqueConstraintViolationException に直すだけでもテストは緑になりますが、仕様どおり `exists()` の確認も足しておくと、ふだんの重複はデータベースに頼らずに見つけられ、`catch` は同時に申し込まれたときだけの備えになります。

</details>

## クリアしたら次へ

Part 2 はここまでです。[Lesson 16 N+1問題を解決する](./16-n-plus-one.md) から Part 3 に入り、受講一覧のAPIを作りながら、クエリの数を減らす方法を学びます。
