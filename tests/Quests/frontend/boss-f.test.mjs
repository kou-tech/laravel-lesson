// ボス戦F 自分の受講一覧（lessons/frontend/boss-f-my-attendances.md）
//
// キャンセルできるかどうかの判定（純粋な関数）を node --test で判定します。
// now は Z を付けないローカル時刻の文字列で作るので、タイムゾーンに関係なく同じ結果になります。

import assert from 'node:assert/strict';
import { test } from 'node:test';

const attendance = await import('../../../resources/js/lib/attendance.ts').catch(() => null);

test('[メイン] canCancel は開始の3日前まで true を返す', () => {
    assert.ok(attendance, 'ヒント: resources/js/lib/attendance.ts がないか、import type 以外の import が入っています');
    assert.equal(typeof attendance.canCancel, 'function', 'ヒント: attendance.ts に canCancel を export しましょう');

    const { canCancel } = attendance;

    assert.equal(canCancel('2026-11-20 10:00:00', new Date('2026-11-10T00:00:00')), true, 'ヒント: 開始まで10日あるならキャンセルできます');
    assert.equal(canCancel('2026-11-20 10:00:00', new Date('2026-11-17T10:00:00')), true, 'ヒント: ちょうど3日前はまだキャンセルできます（Lesson 18 の締切と同じ）');
    assert.equal(canCancel('2026-11-20 10:00:00', new Date('2026-11-17T10:00:01')), false, 'ヒント: 3日前を過ぎたらキャンセルできません');
    assert.equal(canCancel('2026-11-20 10:00:00', new Date('2026-11-21T00:00:00')), false, 'ヒント: 開始したあとはキャンセルできません');
});
