// Stage F6 コンポーネント設計（lessons/frontend/06-component-design.md）
//
// 絞り込みの選択を URL のクエリに変える純粋な関数を node --test で判定します。

import assert from 'node:assert/strict';
import { test } from 'node:test';

const courseFilter = await import('../../../resources/js/lib/course-filter.ts').catch(() => null);

test('[メイン] statusQuery は選択を URL のクエリに変える', () => {
    assert.ok(courseFilter, 'ヒント: resources/js/lib/course-filter.ts がないか、import type 以外の import が入っています');
    assert.equal(typeof courseFilter.statusQuery, 'function', 'ヒント: Step 6。course-filter.ts に statusQuery を export しましょう');

    assert.deepEqual(courseFilter.statusQuery('all'), {}, "ヒント: 'all' のときは、クエリを付けない（空のオブジェクト）にします");
    assert.deepEqual(courseFilter.statusQuery('active'), { status: 'active' }, 'ヒント: ステータスを選んだときは { status } を返します');
});
