<?php

declare(strict_types=1);

namespace App\Kata;

use DateTimeImmutable;

/**
 * Lesson 13「良いコードを書く」の練習用のクラス。
 *
 * 受講申込みを受け付けられるかを判定し、受け付けられるときは受講料を計算する。
 * リファクタリングの題材にするため、わざと読みにくく書いてある。
 * 振る舞いは tests/Unit/Kata/EnrollmentCheckerTest.php で固定している。
 */
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
