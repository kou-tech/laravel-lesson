<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 練習用の遅延と失敗（フロントエンド編）
    |--------------------------------------------------------------------------
    |
    | App\Http\Middleware\SimulateLessonNetwork が読む設定です。ローカルの環境でだけ効きます。
    |
    | latency_ms: Inertia のリクエストを遅らせるミリ秒。0 なら遅らせない
    | fail: 'attend' なら受講登録を失敗させる。'500' なら受講登録とキャンセルでサーバーのエラーを起こす
    |
    */

    'latency_ms' => (int) env('LESSON_LATENCY_MS', 0),

    'fail' => (string) env('LESSON_FAIL', ''),

];
