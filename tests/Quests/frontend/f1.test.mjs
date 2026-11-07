// Stage F1 React に必要な JavaScript（lessons/frontend/01-javascript.md）
//
// node --test で走らせる判定テストです。練習問題のファイルがまだないときや、関数を書いていないときでも、
// ほかのミッションまで落ちないよう、名前空間でまとめて読み込みます。

import assert from 'node:assert/strict';
import { test } from 'node:test';

const f1 = await import('../../../resources/js/practice/f1.js').catch(() => null);

function requireFunction(name) {
    assert.ok(f1, 'ヒント: resources/js/practice/f1.js がありません。練習問題1のひな形を写しましょう');
    assert.equal(typeof f1[name], 'function', `ヒント: f1.js に ${name} を export しましょう`);

    return f1[name];
}

const sample = () => [
    { id: 1, title: 'React 入門', status: 'active', capacity: 20, starts_at: '2026-12-10 10:00:00' },
    { id: 2, title: 'TypeScript 実践', status: 'draft', capacity: 10, starts_at: '2026-12-01 10:00:00' },
    { id: 3, title: 'Inertia でつくる画面', status: 'active', capacity: 2, starts_at: '2026-12-20 10:00:00' },
    { id: 4, title: 'JavaScript 基礎', status: 'closed', capacity: 5, starts_at: '2026-11-30 10:00:00' },
];

test('[メイン] activeCourses は公開中の講座だけを返す', () => {
    const activeCourses = requireFunction('activeCourses');
    const courses = sample();

    assert.deepEqual(
        activeCourses(courses).map((course) => course.id),
        [1, 3],
        'ヒント: filter で status が active のものだけを残します',
    );
    assert.deepEqual(activeCourses([]), [], 'ヒント: 空の配列を渡したら空の配列を返します');
    assert.equal(courses.length, 4, 'ヒント: 元の配列を書き換えないようにしましょう');
});

test('[メイン] courseTitles は講座名の配列を返す', () => {
    const courseTitles = requireFunction('courseTitles');

    assert.deepEqual(
        courseTitles(sample()),
        ['React 入門', 'TypeScript 実践', 'Inertia でつくる画面', 'JavaScript 基礎'],
        'ヒント: map で title だけを取り出します',
    );
});

test('[メイン] totalCapacity は定員の合計を返す', () => {
    const totalCapacity = requireFunction('totalCapacity');

    assert.equal(totalCapacity(sample()), 37, 'ヒント: reduce で capacity を足し合わせます');
    assert.equal(totalCapacity([]), 0, 'ヒント: 空の配列なら 0 を返します。reduce の第2引数に最初の値を渡しましょう');
});

test('[メイン] withRemainingSeats は元の講座を書き換えずに残席を足す', () => {
    const withRemainingSeats = requireFunction('withRemainingSeats');
    const course = sample()[0];

    const result = withRemainingSeats(course, 5);

    assert.equal(result.remaining_seats, 15, 'ヒント: 残席は capacity − 受講中の人数です');
    assert.equal(result.title, 'React 入門', 'ヒント: ほかの項目はそのまま残します。スプレッド構文 {...course} を使います');
    assert.notEqual(result, course, 'ヒント: 元のオブジェクトとは別の新しいオブジェクトを返します');
    assert.equal('remaining_seats' in course, false, 'ヒント: 元の講座に remaining_seats が増えています。元のオブジェクトを書き換えないようにしましょう');
    assert.equal(withRemainingSeats(sample()[2], 3).remaining_seats, 0, 'ヒント: 残席がマイナスになるときは 0 にします');
});

test('[メイン] findCourse は見つからなければ null を返す', () => {
    const findCourse = requireFunction('findCourse');
    const courses = sample();

    assert.equal(findCourse(courses, 3), courses[2], 'ヒント: find で id が一致する講座を返します');
    assert.equal(findCourse(courses, 99), null, 'ヒント: find は見つからないと undefined を返すので、?? null で null にします');
});

test('[サイド] sortByStartsAt は元の配列の順番を変えずに並べ替える', () => {
    const sortByStartsAt = requireFunction('sortByStartsAt');
    const courses = sample();

    assert.deepEqual(
        sortByStartsAt(courses).map((course) => course.id),
        [4, 2, 1, 3],
        'ヒント: 練習問題2。starts_at の早い順に並べます',
    );
    assert.deepEqual(
        courses.map((course) => course.id),
        [1, 2, 3, 4],
        'ヒント: 元の配列の順番が変わっています。sort ではなく toSorted を使いましょう',
    );
});
