<?php

declare(strict_types=1);

use App\Kata\EnrollmentChecker;

/*
 * Lesson 13 で EnrollmentChecker をリファクタリングするときに、振る舞いが変わっていないことを確かめるテスト。
 * 申込日はいつも 2026-04-01 にし、開講日を動かして日数の条件を作る。
 */

beforeEach(function () {
    $this->checker = new EnrollmentChecker;
    $this->today = '2026-04-01';
    $this->student = ['role' => 'student', 'age' => 30];
    $this->course = [
        'status' => 'active',
        'capacity' => 20,
        'attending_count' => 5,
        'price' => 10000,
        'starts_on' => '2026-04-15',
    ];
});

describe('申込みの可否', function () {
    test('条件をすべて満たせば申し込め、税込みの受講料を返す', function () {
        $result = $this->checker->check($this->student, $this->course, $this->today);

        expect($result)->toBe(['ok' => true, 'reason' => null, 'fee' => 11000]);
    });

    test('生徒でなければ申し込めない', function () {
        $instructor = [...$this->student, 'role' => 'instructor'];

        $result = $this->checker->check($instructor, $this->course, $this->today);

        expect($result)->toBe(['ok' => false, 'reason' => '生徒のみ申し込めます。', 'fee' => null]);
    });

    test('下書きの講座には申し込めない', function () {
        $course = [...$this->course, 'status' => 'draft'];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result)->toBe(['ok' => false, 'reason' => '公開中の講座ではありません。', 'fee' => null]);
    });

    test('締め切った講座には申し込めない', function () {
        $course = [...$this->course, 'status' => 'closed'];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result)->toBe(['ok' => false, 'reason' => '公開中の講座ではありません。', 'fee' => null]);
    });

    test('残り1席なら申し込める', function () {
        $course = [...$this->course, 'capacity' => 20, 'attending_count' => 19];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result)->toBe(['ok' => true, 'reason' => null, 'fee' => 11000]);
    });

    test('定員に達していると申し込めない', function () {
        $course = [...$this->course, 'capacity' => 20, 'attending_count' => 20];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result)->toBe(['ok' => false, 'reason' => 'この講座は定員に達しています。', 'fee' => null]);
    });

    test('開講日の前日までは申し込める', function () {
        $course = [...$this->course, 'starts_on' => '2026-04-02'];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result)->toBe(['ok' => true, 'reason' => null, 'fee' => 11000]);
    });

    test('開講日の当日は申し込めない', function () {
        $course = [...$this->course, 'starts_on' => '2026-04-01'];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result)->toBe(['ok' => false, 'reason' => '申込期間を過ぎています。', 'fee' => null]);
    });

    test('開講日を過ぎていると申し込めない', function () {
        $course = [...$this->course, 'starts_on' => '2026-03-31'];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result)->toBe(['ok' => false, 'reason' => '申込期間を過ぎています。', 'fee' => null]);
    });
});

describe('申し込めない理由を判定する順番', function () {
    test('生徒でないことを最初に判定する', function () {
        $instructor = [...$this->student, 'role' => 'instructor'];
        $course = [...$this->course, 'status' => 'draft', 'attending_count' => 20, 'starts_on' => '2026-03-31'];

        $result = $this->checker->check($instructor, $course, $this->today);

        expect($result['reason'])->toBe('生徒のみ申し込めます。');
    });

    test('公開中でないことを定員より先に判定する', function () {
        $course = [...$this->course, 'status' => 'draft', 'attending_count' => 20, 'starts_on' => '2026-03-31'];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result['reason'])->toBe('公開中の講座ではありません。');
    });

    test('定員を申込期間より先に判定する', function () {
        $course = [...$this->course, 'attending_count' => 20, 'starts_on' => '2026-03-31'];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result['reason'])->toBe('この講座は定員に達しています。');
    });
});

describe('受講料の計算', function () {
    test('開講日の30日前までに申し込むと10%引きになる', function () {
        $course = [...$this->course, 'starts_on' => '2026-05-01'];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result['fee'])->toBe(9900);
    });

    test('開講日の29日前の申込みは割引にならない', function () {
        $course = [...$this->course, 'starts_on' => '2026-04-30'];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result['fee'])->toBe(11000);
    });

    test('25歳以下は1000円引きになる', function () {
        $student = [...$this->student, 'age' => 25];

        $result = $this->checker->check($student, $this->course, $this->today);

        expect($result['fee'])->toBe(9900);
    });

    test('26歳以上は1000円引きにならない', function () {
        $student = [...$this->student, 'age' => 26];

        $result = $this->checker->check($student, $this->course, $this->today);

        expect($result['fee'])->toBe(11000);
    });

    test('10%引きをしてから1000円を引き、最後に税をかける', function () {
        $student = [...$this->student, 'age' => 20];
        $course = [...$this->course, 'starts_on' => '2026-05-01'];

        $result = $this->checker->check($student, $course, $this->today);

        expect($result['fee'])->toBe(8800);
    });

    test('割引で受講料がマイナスになるときは0円にする', function () {
        $student = [...$this->student, 'age' => 20];
        $course = [...$this->course, 'price' => 800];

        $result = $this->checker->check($student, $course, $this->today);

        expect($result)->toBe(['ok' => true, 'reason' => null, 'fee' => 0]);
    });

    test('税込みの金額の1円未満は切り捨てる', function () {
        $course = [...$this->course, 'price' => 999];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result['fee'])->toBe(1098);
    });

    test('10%引きした金額の1円未満は切り捨ててから税をかける', function () {
        $course = [...$this->course, 'price' => 1111, 'starts_on' => '2026-05-01'];

        $result = $this->checker->check($this->student, $course, $this->today);

        expect($result['fee'])->toBe(1098);
    });
});
