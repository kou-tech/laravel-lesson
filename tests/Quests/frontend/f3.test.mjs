// Stage F3 React で宣言的に書き直す（lessons/frontend/03-react.md）
//
// 絞り込みと件数の計算（純粋な関数）を node --test で判定します。

import assert from 'node:assert/strict';
import { test } from 'node:test';

const courseFilter = await import('../../../resources/js/lib/course-filter.ts').catch(() => null);
const favorites = await import('../../../resources/js/lib/favorites.ts').catch(() => null);

const sample = () => [
    { id: 1, title: 'React 入門', status: 'active' },
    { id: 2, title: 'TypeScript 実践', status: 'draft' },
    { id: 3, title: 'Inertia でつくる画面', status: 'active' },
    { id: 4, title: 'JavaScript 基礎', status: 'closed' },
];

function requireCourseFilter(name) {
    assert.ok(courseFilter, 'ヒント: Step 3。resources/js/lib/course-filter.ts がないか、import type 以外の import が入っています');
    assert.equal(typeof courseFilter[name], 'function', `ヒント: course-filter.ts に ${name} を export しましょう`);

    return courseFilter[name];
}

test('[メイン] filterCourses はステータスで絞り込む', () => {
    const filterCourses = requireCourseFilter('filterCourses');
    const courses = sample();

    assert.deepEqual(
        filterCourses(courses, 'active').map((course) => course.id),
        [1, 3],
        'ヒント: status が一致する講座だけを、元の順番のまま返します',
    );
    assert.equal(filterCourses(courses, 'all').length, 4, "ヒント: 'all' のときは全件を返します");
    assert.deepEqual(filterCourses([], 'draft'), [], 'ヒント: 空の配列なら空の配列を返します');
    assert.equal(courses.length, 4, 'ヒント: 元の配列を書き換えないようにしましょう');
});

test('[メイン] countByStatus はステータスごとの件数を返す', () => {
    const countByStatus = requireCourseFilter('countByStatus');

    assert.deepEqual(countByStatus(sample()), { draft: 1, active: 2, closed: 1 }, 'ヒント: ステータスごとに件数を数えます');
    assert.deepEqual(
        countByStatus([]),
        { draft: 0, active: 0, closed: 0 },
        'ヒント: 講座がないステータスも 0 で返します。最初に3つのキーを 0 で用意しましょう',
    );
});

test('[サイド] toggleFavorite は元の配列を書き換えずに付け外しする', () => {
    assert.ok(favorites, 'ヒント: 練習問題1。resources/js/lib/favorites.ts に toggleFavorite を作りましょう');
    assert.equal(typeof favorites.toggleFavorite, 'function', 'ヒント: favorites.ts に toggleFavorite を export しましょう');

    const ids = [1, 2];
    const added = favorites.toggleFavorite(ids, 3);

    assert.deepEqual(added, [1, 2, 3], 'ヒント: 含まれていない id は足します');
    assert.deepEqual(ids, [1, 2], 'ヒント: 元の配列が書き換わっています。push ではなくスプレッド構文で新しい配列を作りましょう');
    assert.notEqual(added, ids, 'ヒント: 元の配列とは別の新しい配列を返します');
    assert.deepEqual(favorites.toggleFavorite([1, 2, 3], 2), [1, 3], 'ヒント: 含まれている id は外します');
});
