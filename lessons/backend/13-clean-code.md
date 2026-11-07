# Lesson 13 良いコードを書く

## 学習目標

このレッスンでは、わざと読みにくく書いたクラスを、テストが緑のまま少しずつ読みやすく直していきます。振る舞いを変えずにコードの中身を整理することを、リファクタリングと呼びます。

### 到達目標
- 早期リターン（Early Return）でネストを浅くできる
- マジックナンバーを名前の付いた定数に置き換えられる
- 意味のある名前を付け、コメントに頼らずに意図を伝えられる
- 長いメソッドを private メソッドに分けられる

> このレッスンの題材は `app/Kata/EnrollmentChecker.php` です。配列と文字列だけで動くクラスなので、これまでのレッスンで作ったモデルやデータベースの状態に関係なく試せます。ここで練習した直し方は、次の Lesson 14 で受講登録の処理をサービスクラスに整理するときにも使います。

## なぜ「良いコード」が重要か？

コードは書く時間より読む時間の方が長いです。3か月後の自分が読み返すときも、チームメンバーがレビューするときも、バグの原因を調べるときも、まずコードを読むところから始まります。

読みやすいコードはバグを見つけやすく、変更しやすく、引き継ぎも楽になります。

ただし、動いているコードに手を入れると、うっかり動きを変えてしまうことがあります。そこで、手を入れる前にテストで今の動きを固定しておきます。書き換えた後もテストが緑のままなら、振る舞いが変わっていないと確かめられます。Lesson 12 でテストの書き方を学んだので、このレッスンからはテストを走らせながらコードを直せます。

## Step 0 題材のクラスとテストを確かめる

### EnrollmentChecker の仕様

`EnrollmentChecker` は、受講の申込みを受け付けられるかを判定し、受け付けられるときは受講料を計算するクラスです。`check()` に申込者・講座・申込日を渡すと、次の形の配列を返します。

```php
$checker = new EnrollmentChecker;

$checker->check(
    ['role' => 'student', 'age' => 30],
    ['status' => 'active', 'capacity' => 20, 'attending_count' => 5, 'price' => 10000, 'starts_on' => '2026-04-15'],
    '2026-04-01',
);
// ['ok' => true, 'reason' => null, 'fee' => 11000]

$checker->check(
    ['role' => 'instructor', 'age' => 40],
    ['status' => 'active', 'capacity' => 20, 'attending_count' => 5, 'price' => 10000, 'starts_on' => '2026-04-15'],
    '2026-04-01',
);
// ['ok' => false, 'reason' => '生徒のみ申し込めます。', 'fee' => null]
```

申し込めない理由は次の順に判定し、最初に当てはまったものを返します。

| 順番 | 条件 | reason |
|---|---|---|
| 1 | role が student でない | 生徒のみ申し込めます。 |
| 2 | status が active でない | 公開中の講座ではありません。 |
| 3 | attending_count が capacity 以上 | この講座は定員に達しています。 |
| 4 | 申込日が開講日の当日か、それより後 | 申込期間を過ぎています。 |

受講料は、講座の price（税抜き）をもとに計算します。開講日の30日以上前の申込みなら10%引きにし、申込者が25歳以下ならそこからさらに1000円を引きます（0円より下にはしません）。最後に消費税10%を足します。どの段階でも1円未満は切り捨てます。

### 読みにくいコード

`app/Kata/EnrollmentChecker.php` を開くと、次のコードが書かれています。

```php
<?php

declare(strict_types=1);

namespace App\Kata;

use DateTimeImmutable;

class EnrollmentChecker
{
    /**
     * @param array{role: string, age: int} $u
     * @param array{status: string, capacity: int, attending_count: int, price: int, starts_on: string} $c
     * @param string $today 申込日（Y-m-d）
     * @return array{ok: bool, reason: string|null, fee: int|null}
     */
    public function check(array $u, array $c, string $today): array
    {
        $r = ['ok' => false, 'reason' => null, 'fee' => null];

        // 生徒かどうかチェック
        if ($u['role'] === 'student') {
            // 講座が公開中かチェック
            if ($c['status'] === 'active') {
                // 定員チェック
                if ($c['attending_count'] < $c['capacity']) {
                    // 開講日までの日数を計算
                    $d = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($c['starts_on']))->format('%r%a');
                    // 日数チェック
                    if ($d >= 1) {
                        $tmp = $c['price'];
                        // 30日以上前なら10%引き
                        if ($d >= 30) {
                            $tmp = intdiv($tmp * 90, 100);
                        }
                        // 25歳以下なら1000円引き
                        if ($u['age'] <= 25) {
                            $tmp = $tmp - 1000;
                            // マイナスにならないようにする
                            if ($tmp < 0) {
                                $tmp = 0;
                            }
                        }
                        // 税込みにする
                        $tmp = intdiv($tmp * 110, 100);
                        $r['ok'] = true;
                        $r['fee'] = $tmp;
                    } else {
                        $r['reason'] = '申込期間を過ぎています。';
                    }
                } else {
                    $r['reason'] = 'この講座は定員に達しています。';
                }
            } else {
                $r['reason'] = '公開中の講座ではありません。';
            }
        } else {
            $r['reason'] = '生徒のみ申し込めます。';
        }

        return $r;
    }
}
```

> 実際のファイルには、練習用のクラスであることを説明する PHPDoc がクラスの上に付いています。

動きは仕様どおりですが、読む人には負担の大きいコードです。if が5段に重なっていて、正常系の処理は一番奥にあります。`30` や `1000` が何の数字かはコメントを読まないと分からず、`$u`、`$c`、`$d`、`$tmp` という名前からは中身を想像できません。申込みの判定と受講料の計算も、1つのメソッドに詰め込まれています。

この先の Step で、次の場所を順に直していきます。

| Step | 直すところ |
|---|---|
| Step 1 早期リターン | 5段のネストと4つの else |
| Step 2 マジックナンバーの排除 | `1`、`30`、`90`、`25`、`1000`、`110` と、`'student'`、`'active'` |
| Step 3 意味のある名前 | `$u`、`$c`、`$d`、`$tmp` |
| Step 4 メソッドの責務を分割 | 判定と計算を1つで行っている、46行の `check()` |
| Step 5 コメントよりコードで語る | コードをなぞっているだけのコメント |

### テストを走らせる

振る舞いは `tests/Unit/Kata/EnrollmentCheckerTest.php` の20個のテストで固定してあります。申し込める場合、4つの理由のそれぞれ、理由を判定する順番、割引の境目（30日前と29日前、25歳と26歳）、1円未満の切り捨てを確かめています。

> テストの先頭にある `beforeEach()` は、各テストの前に毎回実行される準備の処理です。ここで標準の申込者と講座を用意し、各テストでは `[...$this->course, 'attending_count' => 20]` のように、確かめたい項目だけを上書きしています。

まず、直す前の状態でテストが全部緑になることを確かめます。`make app` でコンテナに入ってから実行してください。

```bash
make app
php artisan test --compact tests/Unit/Kata
```

```
  ....................

  Tests:    20 passed (20 assertions)
```

`make test` で全体を走らせても構いません。`tests/Unit` のテストなのでデータベースを使わず、すぐに終わります。

### 進め方

どの Step でも、1か所直すたびにテストを走らせます。赤くなったら、直前の変更で振る舞いが変わっています。原因が分からなければ、その変更を元に戻してからやり直してください。Step を1つ終えるたびにコミットしておくと、失敗したときにその Step の最初へ戻れます。

> テストは `check()` の戻り値を `toBe()` で確かめています。`toBe()` は配列のキーの順番まで比べるので、戻り値の配列はこれまでどおり `ok`、`reason`、`fee` の順に書いてください。

テストが緑のまま、どのメソッドも20行ほどに収まり、else が1つも残っていない状態を、このレッスンのゴールの目安にします。

## Step 1 早期リターン（Early Return）

### 問題点

今の `check()` は、条件を満たすたびに1段ずつ内側へ入っていく形です。正常系の受講料の計算は5段目にあり、各条件に対応する else は、ずっと下の方に逆の順番で並んでいます。定員に達しているときの reason を探すには、閉じかっこを数えて、どの if の else なのかを確かめる必要があります。

### 直し方

異常系を先に処理してメソッドを抜け、正常系を最後に残します。一番外側の if から順に、条件を反転して `return` に書き換えます。

```php
// 直す前
if ($u['role'] === 'student') {
    // ...（残りの処理すべて）
} else {
    $r['reason'] = '生徒のみ申し込めます。';
}

// 直した後
if ($u['role'] !== 'student') {
    return ['ok' => false, 'reason' => '生徒のみ申し込めます。', 'fee' => null];
}

// ...（残りの処理は1段浅くなる）
```

2つ目以降の if も同じように書き換えます。最後は受講料を計算して `return ['ok' => true, 'reason' => null, 'fee' => $tmp];` で終わる形になり、`$r` の変数は要らなくなります。

条件を反転するときは、比べる記号の向きに注意してください。`$c['attending_count'] < $c['capacity']` の反対は `>=` で、`>` ではありません。`>` と書くと、定員ちょうどのときにも申し込めてしまいます。その状態でテストを走らせると、次のように失敗します。

```
  FAILED  Tests\Unit\Kata\EnrollmentCheckerTest > `申込みの可否` → 定員に達…
  Failed asserting that two arrays are identical.
   Array &0 [
  -    'ok' => false,
  -    'reason' => 'この講座は定員に達しています。',
  -    'fee' => null,
  +    'ok' => true,
  +    'reason' => null,
  +    'fee' => 11000,
   ]
```

`-` の行がテストの期待した値、`+` の行が実際に返ってきた値です。開講日までの日数の条件も同じで、`$d >= 1` の反対は `$d < 1` です。

書き換えたらテストを走らせ、20個とも緑であることを確かめます。

```bash
php artisan test --compact tests/Unit/Kata
```

### 原則

> 異常系を先に処理し、正常系を最後に残す

## Step 2 マジックナンバーの排除

### 問題点

コードの中に直接書かれた数字を、マジックナンバーと呼びます。`$d >= 30` の `30` が早割の日数だと分かるのは、上のコメントを読んだときだけです。`intdiv($tmp * 90, 100)` の `90` は、10%引きを「90%を払う」と言い換えた数字なので、さらに読み取りにくくなっています。早割を15%引きに変えることになったとき、この `90` を `85` に直せばよいとすぐ気づける人は多くありません。

`'student'` や `'active'` のような文字列も同じです。つづりを1文字間違えても PHP はエラーを出さず、条件が成り立たないだけになります。

### 直し方

クラスの先頭に定数を用意し、数字と文字列に名前を付けます。

```php
class EnrollmentChecker
{
    private const STUDENT_ROLE = 'student';

    private const ACTIVE_STATUS = 'active';

    private const MIN_DAYS_BEFORE_START = 1;

    private const EARLY_BIRD_DAYS = 30;

    private const EARLY_BIRD_DISCOUNT_PERCENT = 10;

    private const YOUTH_MAX_AGE = 25;

    private const YOUTH_DISCOUNT_AMOUNT = 1000;

    private const TAX_PERCENT = 10;

    // ...
}
```

割引率と税率は、仕様の言い方に合わせて「10%引き」「10%」の `10` を定数にし、計算の式の中で `100 -` や `100 +` を組み立てます。

```php
// 直す前
$tmp = intdiv($tmp * 90, 100);
$tmp = intdiv($tmp * 110, 100);

// 直した後
$tmp = intdiv($tmp * (100 - self::EARLY_BIRD_DISCOUNT_PERCENT), 100);
$tmp = intdiv($tmp * (100 + self::TAX_PERCENT), 100);
```

ほかの数字と文字列も、同じように定数に置き換えます。`0` や、パーセントの計算に使う `100` のように、見ればすぐ意味の分かる数字はそのままで構いません。

> 実際のアプリでは、Lesson 7 の `UserRole` や Lesson 9 の `CourseStatus` のように、決まった値の集まりは Enum で表します。このクラスは外から文字列を受け取る練習用なので、定数で済ませています。

書き換えたらテストを走らせます。定数の値を写し間違えていれば、境目を確かめるテストが赤くなります。

## Step 3 意味のある名前

### 問題点

`$u` は申込者、`$c` は講座、`$d` は開講日までの日数、`$tmp` は計算途中の受講料です。書いた本人には分かっていても、読む人は使われ方から推測するしかありません。特に `$tmp` は、計算が進むにつれて税抜きの価格、割引後の金額、税込みの金額と中身が変わっていくのに、名前からはそのどれも分かりません。

### 直し方

変数と引数の名前を、中身が分かる名前に変えます。

| 直す前 | 直した後 |
|---|---|
| `$u` | `$user` |
| `$c` | `$course` |
| `$d` | `$daysUntilStart` |
| `$tmp` | `$fee` |

引数の名前を変えたときは、PHPDoc の `@param` の名前もそろえます。エディタの一括置換は、同じ文字で始まる別の名前まで書き換えてしまうことがあるので、1か所ずつ確かめながら置き換えてください。

一方で、`check()` というメソッド名と、戻り値の配列のキー（`ok`、`reason`、`fee`）は変えません。テストや、このクラスを使う側のコードがその名前で呼んでいるためです。外から使われている名前を変えるときは、呼び出している側もすべて直す必要があります。引数の名前のほうは、テストが順番で値を渡しているので、変えても呼び出す側に影響しません。

書き換えたらテストを走らせます。

### 名前付けの原則

| 種類 | 命名規則 | 例 |
|------|---------|-----|
| ブール値 | is/has/can で始める | `$isActive`, `$hasCapacity`, `$canAttend` |
| コレクション | 複数形 | `$courses`, `$attendances` |
| 取得メソッド | get で始める | `getActiveCourses()` |
| 判定メソッド | is/has/can で始める | `hasAttendance()`, `hasPermission()` |
| 変換メソッド | to で始める | `toArray()`, `toJson()` |

メソッド名は、何をするかが分かる動詞や、何を返すかが分かる名詞にします。`process()`、`doStuff()`、`handle()` のように何にでも当てはまる名前は、読む人に中身を読ませることになります。次の Step 4 でメソッドを切り出すときも、この点を意識して名前を付けます。

## Step 4 メソッドの責務を分割

### 問題点

`check()` は、開講日までの日数を求める、申し込めない理由を調べる、受講料を計算する、という3つの仕事を1つのメソッドでしています。受講料の計算方法だけを確かめたいときにも、判定の部分を読み飛ばしながら探す必要があります。

### 直し方

3つの仕事を、それぞれ private メソッドに切り出します。

| メソッド | 仕事 | 戻り値 |
|---|---|---|
| `daysUntilStart(string $today, string $startsOn): int` | 申込日から開講日までの日数を求める | 日数 |
| `rejectionReason(array $user, array $course, int $daysUntilStart): ?string` | 申し込めない理由を調べる | 理由。申し込めるときは null |
| `calculateFee(int $price, int $age, int $daysUntilStart): int` | 受講料を計算する | 税込みの受講料 |

一度に全部を切り出さず、1つ切り出すたびにテストを走らせます。たとえば `rejectionReason()` には、Step 1 で作った4つの早期リターンを移し、配列ではなく理由の文字列を返す形にします。

```php
private function rejectionReason(array $user, array $course, int $daysUntilStart): ?string
{
    if ($user['role'] !== self::STUDENT_ROLE) {
        return '生徒のみ申し込めます。';
    }

    // ...（残りの3つの条件も同じ形で書く）

    return null;
}
```

3つとも切り出すと、`check()` は仕事の順番だけを書いたメソッドになります。

```php
public function check(array $user, array $course, string $today): array
{
    $daysUntilStart = $this->daysUntilStart($today, $course['starts_on']);
    $rejectionReason = $this->rejectionReason($user, $course, $daysUntilStart);

    if ($rejectionReason !== null) {
        return ['ok' => false, 'reason' => $rejectionReason, 'fee' => null];
    }

    $fee = $this->calculateFee($course['price'], $user['age'], $daysUntilStart);

    return ['ok' => true, 'reason' => null, 'fee' => $fee];
}
```

> もとのコードは、生徒でないときや定員に達しているときには日数を計算していませんでした。分割後は最初に日数を求めるので、starts_on が日付として読めない文字列だった場合の動きが変わります（もとのコードでは生徒でなければ理由を返し、分割後は例外になります）。テストはこの場合を確かめていないので、緑のまま通ります。テストで固定していない振る舞いは、変わっても気づけないということも覚えておいてください。

切り出したメソッドは、同じクラスの private メソッドにとどめます。処理を別のクラスに移して、コントローラーなどから使えるようにする方法は、次の Lesson 14 でサービスクラスとして学びます。

### 単一責任原則（SRP）

> 1つのクラス/メソッドは1つのことだけを行う

## Step 5 コメントよりコードで語る

### 問題点

もとのコードのコメントは、ほとんどがすぐ下のコードの言い換えです。`// 定員チェック` は if の条件を読めば分かりますし、`// 30日以上前なら10%引き` は Step 2 で付けた定数の名前から読み取れます。このようなコメントは、コードを直したときに一緒に直し忘れると、コードと食い違ったまま残ります。早割を15%引きに変えたのに、コメントには「10%引き」と書かれたままになっている状態を想像してみてください。

### 直し方

Step 1〜4 を終えた時点で残っているコメントを、1つずつ見直します。

コードを読めば分かることを書いたコメントは消します。`// 生徒かどうかチェック`、`// 定員チェック`、`// 開講日までの日数を計算` などは、条件式や `daysUntilStart()` というメソッド名で読み取れるので要りません。

コメントで処理の意味を補っていたところは、書き方を変えて、コメントがなくても読めるようにします。

```php
// 直す前
$fee = $fee - self::YOUTH_DISCOUNT_AMOUNT;
// マイナスにならないようにする
if ($fee < 0) {
    $fee = 0;
}

// 直した後
$fee = max(0, $fee - self::YOUTH_DISCOUNT_AMOUNT);
```

```php
// 直す前
// 税込みにする
$fee = intdiv($fee * (100 + self::TAX_PERCENT), 100);

// 直した後
return $this->includeTax($fee);
```

`includeTax()` は、Step 4 と同じ要領で切り出す小さな private メソッドです。10%引きの計算も `discountByPercent()` のように切り出すと、`calculateFee()` を仕様の文章とほぼ同じ順番で読めるようになります。

一方で、なぜそうしているのかという理由は、コードからは読み取れません。理由はコメントに残します。

```php
// 開講日の当日は教材の準備があるため、申込みは前日までにしている
private const MIN_DAYS_BEFORE_START = 1;
```

```php
// 割引は「早割の10%引き → U25の1000円引き」の順にかける。逆にすると金額が変わる
```

アプリのコードでも考え方は同じです。

```php
// ❌ コメントで補足が必要
// 講師かどうかチェック
if ($user->role === UserRole::Instructor) {
    // ...
}

// ✅ メソッド名で意図が分かる（Lesson 7 で作った isInstructor()）
if ($user->isInstructor()) {
    // ...
}
```

`max()` や `includeTax()` のようにコードを書き換えたところがあるので、最後にもう一度テストを走らせます。そのあと `exit` でコンテナを出て、`make test` で全体のテストも緑のままであることを確かめてください。

```bash
php artisan test --compact tests/Unit/Kata
exit
make test
```

どのメソッドも20行ほどに収まり、else が1つもなくなっていれば、このレッスンのゴールです。完成形の例は練習問題1の解答例に載せています。

## 練習問題

### 問題1

Step 1〜5 をすべて当てはめて、`EnrollmentChecker` を完成させてください。テストが20個とも緑のまま、どのメソッドも20行ほどに収まり、else がない状態を目指します。

<details>
<summary>解答例</summary>

```php
<?php

declare(strict_types=1);

namespace App\Kata;

use DateTimeImmutable;

class EnrollmentChecker
{
    private const STUDENT_ROLE = 'student';

    private const ACTIVE_STATUS = 'active';

    // 開講日の当日は教材の準備があるため、申込みは前日までにしている
    private const MIN_DAYS_BEFORE_START = 1;

    private const EARLY_BIRD_DAYS = 30;

    private const EARLY_BIRD_DISCOUNT_PERCENT = 10;

    private const YOUTH_MAX_AGE = 25;

    private const YOUTH_DISCOUNT_AMOUNT = 1000;

    private const TAX_PERCENT = 10;

    /**
     * @param array{role: string, age: int} $user
     * @param array{status: string, capacity: int, attending_count: int, price: int, starts_on: string} $course
     * @param string $today 申込日（Y-m-d）
     * @return array{ok: bool, reason: string|null, fee: int|null}
     */
    public function check(array $user, array $course, string $today): array
    {
        $daysUntilStart = $this->daysUntilStart($today, $course['starts_on']);
        $rejectionReason = $this->rejectionReason($user, $course, $daysUntilStart);

        if ($rejectionReason !== null) {
            return ['ok' => false, 'reason' => $rejectionReason, 'fee' => null];
        }

        $fee = $this->calculateFee($course['price'], $user['age'], $daysUntilStart);

        return ['ok' => true, 'reason' => null, 'fee' => $fee];
    }

    /**
     * @param array{role: string, age: int} $user
     * @param array{status: string, capacity: int, attending_count: int, price: int, starts_on: string} $course
     */
    private function rejectionReason(array $user, array $course, int $daysUntilStart): ?string
    {
        if ($user['role'] !== self::STUDENT_ROLE) {
            return '生徒のみ申し込めます。';
        }

        if ($course['status'] !== self::ACTIVE_STATUS) {
            return '公開中の講座ではありません。';
        }

        if ($course['attending_count'] >= $course['capacity']) {
            return 'この講座は定員に達しています。';
        }

        if ($daysUntilStart < self::MIN_DAYS_BEFORE_START) {
            return '申込期間を過ぎています。';
        }

        return null;
    }

    private function calculateFee(int $price, int $age, int $daysUntilStart): int
    {
        $fee = $price;

        // 割引は「早割の10%引き → U25の1000円引き」の順にかける。逆にすると金額が変わる
        if ($daysUntilStart >= self::EARLY_BIRD_DAYS) {
            $fee = $this->discountByPercent($fee, self::EARLY_BIRD_DISCOUNT_PERCENT);
        }

        if ($age <= self::YOUTH_MAX_AGE) {
            $fee = max(0, $fee - self::YOUTH_DISCOUNT_AMOUNT);
        }

        return $this->includeTax($fee);
    }

    private function daysUntilStart(string $today, string $startsOn): int
    {
        $interval = (new DateTimeImmutable($today))->diff(new DateTimeImmutable($startsOn));

        return (int) $interval->format('%r%a');
    }

    private function discountByPercent(int $amount, int $percent): int
    {
        return intdiv($amount * (100 - $percent), 100);
    }

    private function includeTax(int $amount): int
    {
        return intdiv($amount * (100 + self::TAX_PERCENT), 100);
    }
}
```

> 分け方は1通りではありません。たとえば `rejectionReason()` の条件を `isStudent()` のような判定メソッドに分ける書き方もあります。テストが緑のままで、読んだ人が仕様を追いやすければ、どの形でも構いません。
>
> `daysUntilStart()` の `format('%r%a')` は、日数（`%a`）に、開講日を過ぎているときのマイナスの符号（`%r`）を付けた文字列を返します。
</details>

### 問題2

問題1で整理した `EnrollmentChecker` に、次の仕様を足してください。

- 開講日の60日以上前の申込みは20%引きにする。30日以上前の10%引きとは重ねない
- 25歳以下の1000円引きは、これまでどおり早割の後に引く

先にテストを `tests/Unit/Kata/SuperEarlyBirdTest.php` に書き、実装する前に赤くなることを確かめてから実装してください。付属の `EnrollmentCheckerTest.php` は書き換えず、新しいファイルに書きます。あとで付属のテストが更新されたときに、取り込みで衝突しないようにするためです。申込日を 2026-04-01 にしたとき、開講日 2026-05-31 が60日前、2026-05-30 が59日前です。

> テストを先に書いてから実装する進め方は、Lesson 18 で詳しく学びます。

<details>
<summary>解答例</summary>

```php
<?php
// tests/Unit/Kata/SuperEarlyBirdTest.php

declare(strict_types=1);

use App\Kata\EnrollmentChecker;

beforeEach(function () {
    $this->checker = new EnrollmentChecker;
    $this->student = ['role' => 'student', 'age' => 30];
    $this->course = [
        'status' => 'active',
        'capacity' => 20,
        'attending_count' => 5,
        'price' => 10000,
        'starts_on' => '2026-04-15',
    ];
});

test('開講日の60日前までに申し込むと20%引きになる', function () {
    $course = [...$this->course, 'starts_on' => '2026-05-31'];

    $result = $this->checker->check($this->student, $course, '2026-04-01');

    expect($result['fee'])->toBe(8800);
});

test('開講日の59日前の申込みは10%引きのまま', function () {
    $course = [...$this->course, 'starts_on' => '2026-05-30'];

    $result = $this->checker->check($this->student, $course, '2026-04-01');

    expect($result['fee'])->toBe(9900);
});

test('20%引きのあとに25歳以下の1000円引きをする', function () {
    $student = [...$this->student, 'age' => 25];
    $course = [...$this->course, 'starts_on' => '2026-05-31'];

    $result = $this->checker->check($student, $course, '2026-04-01');

    expect($result['fee'])->toBe(7700);
});
```

実装する前に走らせると、60日前の2つのテストが赤くなり、59日前のテストは緑になります。

`EnrollmentChecker` には、定数を2つと、早割の割引率を決めるメソッドを足します。

```php
private const SUPER_EARLY_BIRD_DAYS = 60;

private const SUPER_EARLY_BIRD_DISCOUNT_PERCENT = 20;
```

```php
private function calculateFee(int $price, int $age, int $daysUntilStart): int
{
    // 割引は「早割の%引き → U25の1000円引き」の順にかける。逆にすると金額が変わる
    $fee = $this->discountByPercent($price, $this->earlyBirdDiscountPercent($daysUntilStart));

    if ($age <= self::YOUTH_MAX_AGE) {
        $fee = max(0, $fee - self::YOUTH_DISCOUNT_AMOUNT);
    }

    return $this->includeTax($fee);
}

private function earlyBirdDiscountPercent(int $daysUntilStart): int
{
    if ($daysUntilStart >= self::SUPER_EARLY_BIRD_DAYS) {
        return self::SUPER_EARLY_BIRD_DISCOUNT_PERCENT;
    }

    if ($daysUntilStart >= self::EARLY_BIRD_DAYS) {
        return self::EARLY_BIRD_DISCOUNT_PERCENT;
    }

    return 0;
}
```

`earlyBirdDiscountPercent()` は、長い日数の条件から順に早期リターンで判定します。30日の条件を先に書くと、60日以上前の申込みも10%引きで返ってしまいます。

最初の読みにくいコードに同じ仕様を足そうとすると、5段目の if の中にさらに if を重ね、`90` の隣に `80` を書くことになります。整理した後のコードなら、定数を2つとメソッドを1つ足し、`calculateFee()` の1か所を書き換えるだけで済みます。そのうえ、付属の20個のテストで既存の割引が壊れていないことも確かめられます。
</details>

## 参考資料

- [リーダブルコード ―より良いコードを書くためのシンプルで実践的なテクニック（オライリー）](https://www.oreilly.co.jp/books/9784873115658/)

## 次のレッスン

[Lesson 14 サービスクラスの設計](./14-service-class.md) では、コントローラーに書いた講座作成や受講登録の処理をサービスクラスに切り出し、テストが緑のままコントローラーを細くします。
