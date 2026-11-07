# ボス戦3 講師向けの受講者一覧APIをTDDで作る

バックエンドのレッスンの最後のステージです。講師が自分の講座の受講者を一覧で見るAPIを、仕様だけを見て作ります。Lesson 18 で体験した TDD の流れで、テストを先に書き、そのテストを通すコードを書いてください。

## ボスの説明

[API仕様一覧](../reference/api-spec.md) で発展課題にしていた `GET /api/courses/{course}/attendances` を作ります。これまでのレッスンで作ったAPIと比べると、認可、取り出す受講の絞り込み、N+1 の3つを同時に満たす必要があります。どれか1つを忘れても、ほかのテストは緑のままなので、仕様の項目ごとにテストを用意することが大事になります。

使う知識は次のレッスンで学んだものです。

| 使う知識 | 学んだレッスン |
|---|---|
| Policy による認可 | Lesson 7 |
| API Resource でレスポンスの形を決める | Lesson 9 |
| Attendance の active スコープ | Lesson 11 |
| Feature テスト、`describe()`、`assertJsonCount()` など | Lesson 12 |
| Eager Loading と `preventLazyLoading()` | Lesson 16 |
| Red → Green → Refactor の進め方 | Lesson 18 |

## 仕様

### エンドポイント

| メソッド | エンドポイント | 説明 | 認証 | 認可 |
|---|---|---|---|---|
| GET | /api/courses/{course}/attendances | 講座の受講者一覧 | 必要 | 担当講師のみ |

ルートは `routes/api.php` の `auth:sanctum` グループの中に足します。同じ URL の POST（受講登録）と DELETE（キャンセル）は AttendanceController にありますが、この一覧は講師向けで役割が違うので、別のコントローラーにします。

| 作るもの | 名前 |
|---|---|
| コントローラー | `App\Http\Controllers\Api\CourseAttendanceController` の `index` |
| 認可 | CoursePolicy に足す `viewAttendances(User $user, Course $course): bool` |
| テスト | `tests/Feature/Boss/CourseAttendanceTest.php` |

Resource のクラス名は自由です（解答例では `CourseAttendanceResource` にしています）。

### 認可

講座の担当講師だけが一覧を見られます。担当でない講師と生徒は 403 です。その講座を受講している生徒も 403 になります。ログインしていなければ 401、存在しない講座なら 404 です。

### 一覧に含める受講

その講座の受講のうち、受講中（attending）のものだけを返します。Lesson 11 の active スコープを使ってください。キャンセル済みは含めません（修了済みも active スコープの条件から外れるので含まれません）。ほかの講座の受講が混ざらないことも確かめてください。

並び順は申し込んだ順（`id` の昇順）です。ページネーションは付けず、該当するものをすべて返します。

### レスポンス

200 で、次の形の JSON を返します。

```json
{
    "data": [
        {
            "id": 12,
            "user": {
                "id": 3,
                "name": "Test User",
                "email": "test@example.com"
            },
            "status": "attending",
            "status_label": "受講中",
            "attended_at": "2026-11-07 10:00:00"
        }
    ]
}
```

`user` には受講者の `id`・`name`・`email` の3つだけを入れます。講座の情報は URL で分かっているので、各要素には含めません。

Lesson 10 の AttendanceResource をそのまま使わないのはこのためです。AttendanceResource は要素ごとに `course` を出力するので、講座が何十件も同じ内容で繰り返されるうえに、`course` を読み込まずに使うと遅延ロードが起きます。また `user` に入る UserResource は `created_at` も出力します。この一覧のための Resource を新しく作ってください。

### N+1 を起こさない

受講が何件あっても、受講者を取り出すクエリの数が増えないようにします。Lesson 16 で AppServiceProvider に入れた `Model::preventLazyLoading()` はテストでも有効なので、遅延ロードが起きれば LazyLoadingViolationException で 500 になります。

ただし、遅延ロードの違反は、2件以上のモデルをまとめて取り出したときにだけ報告されます。受講1件のデータでは N+1 があっても 200 になるので、N+1 を確かめるテストでは受講を2件以上作ってください。

## 進め方

Lesson 18 と同じく、1つの仕様ごとに次の3つを繰り返します。

1. Red: 仕様を1つ選んでテストを書き、走らせて赤になることを確かめる
2. Green: そのテストが緑になる最小限のコードを書く
3. Refactor: テストが緑のまま、コードを読みやすく整える

最初のテストは「担当講師は受講者の一覧を取得できる」にすると、ルート・コントローラー・Policy・Resource の骨組みが一通りそろいます。そのあとで、403・401、キャンセル済みを含めないこと、N+1 の順にテストを足していくと進めやすくなります。

テストは次のコマンドで走らせます。

```bash
php artisan test --compact tests/Feature/Boss/CourseAttendanceTest.php
```

## クリア条件

次のことを確かめるテストがあり、すべて緑ならクリアです。

- [ ] 担当講師が一覧を取得すると 200 になり、各要素が仕様の形（`id`、`user` の `id`・`name`・`email`、`status`、`status_label`、`attended_at`）になっている
- [ ] 担当でない講師は 403 になる
- [ ] 生徒は 403 になる（その講座を受講している生徒も含む）
- [ ] ログインしていないと 401 になる
- [ ] キャンセル済みの受講が一覧に含まれない
- [ ] ほかの講座の受講が一覧に含まれない
- [ ] 受講2件以上のデータで、遅延ロードが起きずに 200 になる
- [ ] `make test` で、これまでのテストも緑のまま

TDD の手順そのものは、テストからは判定できません。次のセルフチェックで振り返ってください。

- [ ] どの仕様も、先にテストを書き、赤になるのを見てからコードを書いた
- [ ] 一度に1つのテストだけを赤から緑にした
- [ ] 緑になった後に、テストが緑のままコードを整理する時間をとった

## ヒント

<details>
<summary>ヒント1 最初のテストの書き方</summary>

テストに必要なデータは、講師、その講師の講座、その講座の受講の3つです。Factory を組み合わせると次のように作れます。

```php
$instructor = User::factory()->instructor()->create();
$course = Course::factory()->for($instructor, 'instructor')->create();
$attendance = Attendance::factory()->for($course)->create();
```

レスポンスの形をまとめて確かめたいときは、`assertExactJson()` が使えます。JSON がその配列と完全に一致するかを確かめるので、余計なキー（たとえば `user.created_at`）が混ざっていても気付けます。

</details>

<details>
<summary>ヒント2 キャンセル済みを除くテストが緑にならないとき</summary>

`$course->attendances` のようにプロパティで受け取ると、すべての受講が読み込まれます。条件を付けたいときは、メソッドとして呼んでクエリを組み立てます。

```php
$course->attendances()->active()->...
```

テストのデータには、受講中とキャンセル済みを1件ずつ入れ、返ってくるのが受講中の1件だけであることを `assertJsonCount()` と `assertJsonPath()` で確かめます。

</details>

<details>
<summary>ヒント3 N+1 のテストが赤のまま、または赤にならないとき</summary>

Resource の中で `$this->resource->user` を使うと、受講ごとに users テーブルへのクエリが走ります。一覧を取り出すときに `with('user')` を付けて、受講者をまとめて読み込んでください。

逆に、N+1 のテストがはじめから緑になってしまうときは、データが1件しかないか、AppServiceProvider の `preventLazyLoading()` が効いていない可能性があります。テストの先頭で `Model::preventLazyLoading();` を呼んでおくと、AppServiceProvider の設定に関係なく確かめられます。

</details>

## 解答例

<details>
<summary>解答例</summary>

### テスト

`tests/Feature/Boss/CourseAttendanceTest.php`

```php
<?php

use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

describe('GET /api/courses/{course}/attendances', function () {
    test('担当講師は受講者の一覧を取得できる', function () {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->for($instructor, 'instructor')->create();
        $student = User::factory()->student()->create();
        $attendance = Attendance::factory()->for($course)->for($student)->create();

        $this->actingAs($instructor)
            ->getJson("/api/courses/{$course->id}/attendances")
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => $attendance->id,
                        'user' => [
                            'id' => $student->id,
                            'name' => $student->name,
                            'email' => $student->email,
                        ],
                        'status' => 'attending',
                        'status_label' => '受講中',
                        'attended_at' => $attendance->attended_at->format('Y-m-d H:i:s'),
                    ],
                ],
            ]);
    });

    test('キャンセル済みの受講と、ほかの講座の受講は含まない', function () {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->for($instructor, 'instructor')->create();
        $attending = Attendance::factory()->for($course)->create();
        Attendance::factory()->for($course)->cancelled()->create();
        Attendance::factory()->create();

        $this->actingAs($instructor)
            ->getJson("/api/courses/{$course->id}/attendances")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $attending->id);
    });

    test('受講が何件あっても N+1 が起きない', function () {
        Model::preventLazyLoading();

        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->for($instructor, 'instructor')->create();
        Attendance::factory()->for($course)->count(3)->create();

        $this->actingAs($instructor)
            ->getJson("/api/courses/{$course->id}/attendances")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    test('担当でない講師は 403 になる', function () {
        $otherInstructor = User::factory()->instructor()->create();
        $course = Course::factory()->create();

        $this->actingAs($otherInstructor)
            ->getJson("/api/courses/{$course->id}/attendances")
            ->assertForbidden();
    });

    test('受講している生徒も 403 になる', function () {
        $course = Course::factory()->create();
        $student = User::factory()->student()->create();
        Attendance::factory()->for($course)->for($student)->create();

        $this->actingAs($student)
            ->getJson("/api/courses/{$course->id}/attendances")
            ->assertForbidden();
    });

    test('ログインしていないと 401 になる', function () {
        $course = Course::factory()->create();

        $this->getJson("/api/courses/{$course->id}/attendances")
            ->assertUnauthorized();
    });
});
```

> 補足: `Model::preventLazyLoading()` は、一度呼ぶとそのテストの実行が終わるまで有効なままです。Lesson 16 から AppServiceProvider でも同じ設定にしているので、ほかのテストの結果は変わりません。

### コード

ルート（`routes/api.php` の `auth:sanctum` グループの中。`use` はファイル先頭に足す）

```php
use App\Http\Controllers\Api\CourseAttendanceController;

Route::get('/courses/{course}/attendances', [CourseAttendanceController::class, 'index']);
```

`app/Policies/CoursePolicy.php` に追加

```php
/**
 * 講座の受講者一覧を見られるか
 */
public function viewAttendances(User $user, Course $course): bool
{
    return $user->id === $course->instructor_id;
}
```

`app/Http/Resources/CourseAttendanceResource.php`（`php artisan make:resource CourseAttendanceResource` で作る）

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseAttendanceResource extends JsonResource
{
    /** @var \App\Models\Attendance */
    public $resource;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'user' => [
                'id' => $this->resource->user->id,
                'name' => $this->resource->user->name,
                'email' => $this->resource->user->email,
            ],
            'status' => $this->resource->status,
            'status_label' => $this->resource->status->label(),
            'attended_at' => $this->resource->attended_at->format('Y-m-d H:i:s'),
        ];
    }
}
```

`app/Http/Controllers/Api/CourseAttendanceController.php`（`php artisan make:controller Api/CourseAttendanceController` で作る）

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseAttendanceResource;
use App\Models\Course;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CourseAttendanceController extends Controller
{
    /**
     * 講座の受講者一覧
     */
    public function index(Course $course): AnonymousResourceCollection
    {
        $this->authorize('viewAttendances', $course);

        $attendances = $course->attendances()
            ->active()
            ->with('user')
            ->orderBy('id')
            ->get();

        return CourseAttendanceResource::collection($attendances);
    }
}
```

`->with('user')` を消してテストを走らせると、N+1 のテストだけが LazyLoadingViolationException で赤になります。同じように `->active()` を消すとキャンセル済みのテストが、`authorize` の行を消すと 403 の2つのテストが赤になります。それぞれのテストが、仕様の1つずつを守っていることを確かめられます。

</details>

## クリアしたら次へ

バックエンドのレッスンはこれで終わりです。続けて [フロントエンド資料](../frontend/README.md) を読むと、作ってきたAPIを画面から呼ぶときの設計とデータ取得の考え方を押さえられます。発展の [A1 データベース設計の原則](./A1-database-design.md) と [A2 サービスコンテナとDI](./A2-di-container.md) は、まだ読んでいなければここで読んでおくと、これまでのコードの背景が分かります。
