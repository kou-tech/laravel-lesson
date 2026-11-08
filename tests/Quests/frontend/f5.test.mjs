// Stage F5 TypeScript で画面を守る（lessons/frontend/05-typescript.md）
//
// 残席の表し方（判別可能なユニオン）を返す純粋な関数を node --test で判定します。

import assert from 'node:assert/strict';
import { test } from 'node:test';

const seats = await import('../../../resources/js/lib/course-seats.ts').catch(() => null);

const base = { id: 1, title: 'a', description: null, capacity: 10, status: 'active', status_label: '公開中', starts_at: 'x', created_at: 'x' };

function requireSeats(name) {
    assert.ok(seats, 'ヒント: Step 6。resources/js/lib/course-seats.ts がないか、import type 以外の import が入っています');
    assert.equal(typeof seats[name], 'function', `ヒント: course-seats.ts に ${name} を export しましょう`);

    return seats[name];
}

test('[メイン] seatAvailability は残席の状態を3通りで返す', () => {
    const seatAvailability = requireSeats('seatAvailability');

    assert.deepEqual(seatAvailability(base), { kind: 'unknown' }, 'ヒント: remaining_seats がないときは unknown です（キーがないことと 0 を区別します）');
    assert.deepEqual(seatAvailability({ ...base, remaining_seats: 0 }), { kind: 'full' }, 'ヒント: 残席が 0 なら full です');
    assert.deepEqual(seatAvailability({ ...base, remaining_seats: 3 }), { kind: 'available', remaining: 3 }, 'ヒント: 残席があれば available と残りの数です');
});

test('[メイン] seatLabel は状態ごとの表示の文を返す', () => {
    const seatLabel = requireSeats('seatLabel');

    assert.equal(seatLabel({ kind: 'available', remaining: 3 }), '残り 3 席');
    assert.equal(seatLabel({ kind: 'full' }), '満席');
    assert.equal(seatLabel({ kind: 'unknown' }), '残席は集計していません');
});
