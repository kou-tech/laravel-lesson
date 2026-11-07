<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * フロントエンド編（F7・ボス戦F）の練習用に、応答を遅らせたり失敗させたりします。
 *
 * 手元の環境では応答が一瞬で返るため、送信中の表示や、楽観的な更新が元に戻る動きを目で確かめられません。
 * .env の LESSON_LATENCY_MS と LESSON_FAIL で、ローカルの環境でだけ待ち時間と失敗を起こします。
 */
class SimulateLessonNetwork
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isLocal()) {
            return $next($request);
        }

        $latency = (int) config('lesson.latency_ms');

        if ($latency > 0 && $request->header('X-Inertia')) {
            usleep($latency * 1000);
        }

        $fail = (string) config('lesson.fail');

        if ($fail === 'attend' && $request->routeIs('courses.attendances.store')) {
            return back()->withErrors(['attendance' => '（練習用に受講登録を失敗させています。.env の LESSON_FAIL を空にすると元に戻ります）']);
        }

        if ($fail === '500' && $request->routeIs('courses.attendances.*')) {
            abort(500, '練習用にサーバーのエラーを起こしています。.env の LESSON_FAIL を空にすると元に戻ります。');
        }

        return $next($request);
    }
}
