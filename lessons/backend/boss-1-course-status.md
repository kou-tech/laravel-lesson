# ボス戦1 講座のステータスを切り替えるAPI

Part 1 の最後のステージです。ここまでのレッスンと違い、このページにはコードを載せていません。仕様だけを読んで、自分で設計して作ってください。行き詰まったら、ページの下にあるヒントを1段ずつ開いて進めます。

## ボスの説明

講座のステータスは、下書き（draft）から公開中（active）へ、公開中から締切（closed）へと進みます。Lesson 9 の講座更新APIでは status に好きな値を入れられるので、締め切った講座を下書きに戻すこともできてしまいます。このボス戦では、決められた順番でしか進めない、ステータス専用のAPIを作ります。

使う知識は次のレッスンで学んだものです。新しく覚えることはほとんどなく、組み合わせ方が問われます。

| 使う知識 | 学んだレッスン |
|---|---|
| ステータスコードの使い分け（401・403・404・422） | Lesson 3 |
| FormRequest と `Rule::enum()` によるバリデーション | Lesson 5 |
| Policy による認可 | Lesson 7 |
| CourseStatus、CoursePolicy、CourseResource | Lesson 9 |

## 仕様

### エンドポイント

| メソッド | エンドポイント | 説明 | 認証 | 認可 |
|---|---|---|---|---|
| PATCH | /api/courses/{course}/status | 講座のステータスを切り替える | 必要 | 担当講師のみ |

### リクエスト

```json
{
    "status": "active"
}
```

status は必須で、値は CourseStatus（Lesson 9）の値のどれかです。

### 切り替えのルール

進めてよいのは次の2通りだけです。

| 今のステータス | 送ってよい status |
|---|---|
| draft（下書き） | active |
| active（公開中） | closed |
| closed（終了） | なし |

これ以外の組み合わせはすべて 422 にします。下書きから一気に closed へ進めること、公開中から draft へ戻すこと、今と同じステータスを送ること、締め切った講座のステータスを変えることは、どれも許しません。status がない場合と、CourseStatus にない値（例: `published`）の場合も 422 です。

この確認は FormRequest の中で行い、422 のレスポンスは Laravel のバリデーションエラーの形（`message` と `errors.status`）にします。エラーメッセージの文言は自由です。

### 認可

Lesson 9 の CoursePolicy の `update` をそのまま使います。講座の担当講師（`instructor_id` が自分の講座）だけが切り替えられ、ほかの講師と生徒は 403 になります。CoursePolicy に新しいメソッドを足す必要はありません。

ログインしていなければ 401、存在しない講座なら 404 です。

> 補足: 担当でない講師が「切り替えのルールに合わない値」を送ったときに 403 と 422 のどちらを返すかは、このボス戦では決めません。どちらになってもクリアです。

### レスポンス

成功したら 200 で、切り替えた後の講座を CourseResource（Lesson 9）で返します。講師の情報（`instructor`）も含めます。

```json
{
    "data": {
        "id": 1,
        "title": "Laravel入門",
        "description": "...",
        "instructor": {
            "id": 2,
            "name": "Test User 2",
            "email": "test2@example.com",
            "created_at": "2026-01-04 06:55:43"
        },
        "capacity": 20,
        "status": "active",
        "status_label": "公開中",
        "created_at": "2026-01-04 06:55:43"
    }
}
```

### Lesson 9 の講座更新APIとの関係

Lesson 9 の `PATCH /api/courses/{course}` でも、今までどおり status を自由に変えられます。そちらを使えば切り替えのルールを迂回できますが、このボス戦ではそれを仕様として認めます。Lesson 9 のコードは変えないでください。

実務であれば、講座更新APIの UpdateRequest から status を外し、ステータスの変更をこのAPIに一本化するのが自然です。このコースでは、Part 1 で作ったものを後のレッスンが前提にしているので、両方を残しています。動作確認でステータスを最初の状態に戻したいときは、この迂回路が役に立ちます。

## 動作確認のしかた

テストの書き方は Lesson 12 で学ぶので、ここでは Postman で確かめます。コレクションにはこのAPI用のリクエストがないので、`api > courses > {courseId} > 講座更新` を複製して作ります。

1. `login > ログイン` の Body の email を `test2@example.com`（講師）に変えてログインする
2. `api > courses > 講座登録` を送り、下書き（`"status": "draft"`）の講座を作る。レスポンスの `data.id` を控えておく
3. 複製したリクエストの URL の末尾に `/status` を足し、`courseId` に控えた ID を入れ、Body を `{"status": "active"}` にして送る

ステータスを draft に戻してやり直したいときは、もとの `講座更新` で `{"status": "draft"}` を送ってください。生徒で試すときは、ログインの email を `test@example.com` に戻します。

## クリア条件

すべてにチェックが付いたらクリアです。

- [ ] 担当講師が下書きの講座に `{"status": "active"}` を送ると 200 になり、レスポンスの `data.status` が `active`、`data.instructor` に講師の情報が入っている
- [ ] 担当講師が公開中の講座に `{"status": "closed"}` を送ると 200 になる
- [ ] 下書きの講座に `closed` を送ると 422 になる
- [ ] 公開中の講座に `draft` や `active` を送ると 422 になる
- [ ] 締め切った講座には、何を送っても 422 になる
- [ ] status を送らないとき、`published` のような CourseStatus にない値を送ったときに 422 になる
- [ ] 422 になったとき、講座のステータスが変わっていない（`講座詳細` で確かめる）
- [ ] 担当でない講師と生徒が正しい値を送ると 403 になる
- [ ] ログアウトした状態で送ると 401 になる

## ヒント

<details>
<summary>ヒント1 どこに何を書くか</summary>

作るものは、ルート、コントローラーのメソッド、FormRequest の3つです。

ルートは `auth:sanctum` グループの中に `Route::patch('/courses/{course}/status', ...)` を足します。コントローラーは CourseController に `updateStatus` のようなメソッドを足せば十分で、中身は Lesson 9 の `update` とほとんど同じ流れになります。FormRequest は Lesson 5 の命名規則に合わせて、`php artisan make:request Course/UpdateStatusRequest` で作ります。

認可は `$this->authorize('update', $course);` の1行で済みます。

</details>

<details>
<summary>ヒント2 FormRequest の中で今のステータスを知る</summary>

切り替えのルールを確かめるには、今の講座のステータスが必要です。FormRequest の中では `$this->route('course')` で、ルートモデル結合で取り出した Course を受け取れます（Lesson 5 で `Rule::unique('users')->ignore($this->user)` と書いたのと同じ仕組みです）。

今のステータスから「送ってよい値」を決められれば、あとは `Rule::in()` に渡すだけです。CourseStatus は Enum なので、`match` で分けると書きやすくなります。

```php
match ($this->route('course')->status) {
    CourseStatus::Draft => ...,
    CourseStatus::Active => ...,
    CourseStatus::Closed => ...,
};
```

</details>

<details>
<summary>ヒント3 締切の講座でつまずいたら</summary>

締め切った講座には送ってよい値がないので、「送ってよい値の一覧」は空の配列になります。`Rule::in([])` は、どの値も通さないので期待どおりに 422 を返します。

一方で `Rule::enum(CourseStatus::class)->only([])` のように、`only()` に空の配列を渡すと、制限なしと同じ扱いになってすべての値が通ってしまいます。値が CourseStatus のどれかであることは `Rule::enum()` で、切り替えのルールは `Rule::in()` で、と分けて書くと、この落とし穴を避けられます。

</details>

## 解答例

<details>
<summary>解答例</summary>

ルート（`routes/api.php` の `auth:sanctum` グループの中）

```php
Route::patch('/courses/{course}/status', [CourseController::class, 'updateStatus']);
```

`app/Http/Requests/Course/UpdateStatusRequest.php`

```php
<?php

namespace App\Http\Requests\Course;

use App\Enums\CourseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::enum(CourseStatus::class),
                Rule::in($this->nextStatuses()),
            ],
        ];
    }

    /**
     * 今のステータスから進めるステータス
     *
     * @return list<CourseStatus>
     */
    private function nextStatuses(): array
    {
        return match ($this->route('course')->status) {
            CourseStatus::Draft => [CourseStatus::Active],
            CourseStatus::Active => [CourseStatus::Closed],
            CourseStatus::Closed => [],
        };
    }
}
```

`app/Http/Controllers/Api/CourseController.php` に追加

```php
use App\Http\Requests\Course\UpdateStatusRequest;

/**
 * 講座のステータスを切り替える
 */
public function updateStatus(UpdateStatusRequest $request, Course $course): CourseResource
{
    $this->authorize('update', $course);

    $course->update(['status' => $request->validated('status')]);
    $course->load('instructor');

    return new CourseResource($course);
}
```

> 補足: 「どのステータスからどのステータスへ進めるか」は講座そのもののルールなので、`nextStatuses()` を CourseStatus に置く書き方もあります。そうすると、ほかの場所（たとえば将来作る管理画面）でも同じルールを使い回せます。

テスト（`tests/Feature/Boss/CourseStatusTest.php`）

今はまだ読み飛ばしてかまいません。Lesson 12 を終えたあとにこのファイルを置き、`php artisan test --compact tests/Feature/Boss/CourseStatusTest.php` で走らせてみてください。自分のコードが仕様どおりかを、Postman で1つずつ送らずに確かめられます。`->with([...])` は、同じテストを値を変えて何回も走らせる Pest の書き方（データセット）です。

```php
<?php

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;

describe('PATCH /api/courses/{course}/status', function () {
    test('担当講師は下書きの講座を公開できる', function () {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->for($instructor, 'instructor')->draft()->create();

        $this->actingAs($instructor)
            ->patchJson("/api/courses/{$course->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.id', $course->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_label', '公開中')
            ->assertJsonPath('data.instructor.id', $instructor->id);

        expect($course->fresh()->status)->toBe(CourseStatus::Active);
    });

    test('担当講師は公開中の講座を締め切れる', function () {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->for($instructor, 'instructor')->active()->create();

        $this->actingAs($instructor)
            ->patchJson("/api/courses/{$course->id}/status", ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        expect($course->fresh()->status)->toBe(CourseStatus::Closed);
    });

    test('決められた順番以外の切り替えは 422 になる', function (CourseStatus $from, string $to) {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->for($instructor, 'instructor')->create(['status' => $from]);

        $this->actingAs($instructor)
            ->patchJson("/api/courses/{$course->id}/status", ['status' => $to])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        expect($course->fresh()->status)->toBe($from);
    })->with([
        '下書き → 締切' => [CourseStatus::Draft, 'closed'],
        '下書き → 下書き' => [CourseStatus::Draft, 'draft'],
        '公開中 → 下書き' => [CourseStatus::Active, 'draft'],
        '公開中 → 公開中' => [CourseStatus::Active, 'active'],
        '締切 → 下書き' => [CourseStatus::Closed, 'draft'],
        '締切 → 公開中' => [CourseStatus::Closed, 'active'],
        '締切 → 締切' => [CourseStatus::Closed, 'closed'],
        '存在しない値' => [CourseStatus::Draft, 'published'],
    ]);

    test('status を送らないと 422 になる', function () {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->for($instructor, 'instructor')->draft()->create();

        $this->actingAs($instructor)
            ->patchJson("/api/courses/{$course->id}/status", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });

    test('担当でない講師は 403 になる', function () {
        $otherInstructor = User::factory()->instructor()->create();
        $course = Course::factory()->draft()->create();

        $this->actingAs($otherInstructor)
            ->patchJson("/api/courses/{$course->id}/status", ['status' => 'active'])
            ->assertForbidden();

        expect($course->fresh()->status)->toBe(CourseStatus::Draft);
    });

    test('生徒は 403 になる', function () {
        $student = User::factory()->student()->create();
        $course = Course::factory()->draft()->create();

        $this->actingAs($student)
            ->patchJson("/api/courses/{$course->id}/status", ['status' => 'active'])
            ->assertForbidden();
    });

    test('ログインしていないと 401 になる', function () {
        $course = Course::factory()->draft()->create();

        $this->patchJson("/api/courses/{$course->id}/status", ['status' => 'active'])
            ->assertUnauthorized();
    });
});
```

</details>

## クリアしたら次へ

Part 1 はここまでです。[Lesson 10 受講登録APIを作る](./10-attendance-api.md) から Part 2 に入り、生徒が講座に申し込むAPIを作ります。
