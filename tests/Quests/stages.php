<?php

declare(strict_types=1);

/*
 * ステージの一覧です。make map の表示順もこの順番です。
 *
 * kind の意味
 * - pest:   tests/Quests の判定テスト（file）を走らせて判定する
 * - mutate: 受講者が書いたテスト（test）を、対象のクラス（class）に対するミューテーションテストで判定する
 * - none:   判定テストがない（座学のレッスン、判定をまだ作っていないステージ）
 */

return [
    ['id' => '00', 'title' => 'おすすめのVSCode拡張機能', 'kind' => 'none'],
    ['id' => '01', 'title' => 'はじめてのAPI', 'kind' => 'none'],
    ['id' => '02', 'title' => 'デバッグ手法を身につける', 'kind' => 'none'],
    ['id' => '03', 'title' => 'API設計の基本', 'kind' => 'none'],
    ['id' => '04', 'title' => 'User APIの実装', 'kind' => 'none'],
    ['id' => '05', 'title' => 'バリデーション', 'kind' => 'none'],
    ['id' => '06', 'title' => '認証の仕組みを理解する', 'kind' => 'none'],
    ['id' => '07', 'title' => '認可（Gate/Policy）を実装する', 'kind' => 'none'],
    ['id' => '08', 'title' => 'コレクション', 'kind' => 'none'],
    ['id' => '09', 'title' => 'Course APIの実装', 'kind' => 'none'],
    ['id' => 'boss-1', 'title' => 'ボス戦1 講座のステータスを切り替えるAPI', 'kind' => 'none'],
    ['id' => '10', 'title' => '受講登録APIを作る', 'kind' => 'pest', 'file' => 'tests/Quests/Stage10Test.php'],
    ['id' => '11', 'title' => '安全なモデルの記述', 'kind' => 'pest', 'file' => 'tests/Quests/Stage11Test.php'],
    [
        'id' => '12',
        'title' => '自動テストの書き方',
        'kind' => 'mutate',
        'test' => 'tests/Feature/Api/AttendanceStoreTest.php',
        'class' => 'App\Http\Controllers\Api\AttendanceController',
        'min' => 80,
    ],
    ['id' => '13', 'title' => '良いコードを書く', 'kind' => 'none'],
    ['id' => '14', 'title' => 'サービスクラスの設計', 'kind' => 'none'],
    ['id' => '15', 'title' => 'トランザクション処理', 'kind' => 'none'],
    ['id' => 'boss-2', 'title' => 'ボス戦2 不具合の入った受講登録を直す', 'kind' => 'none'],
    ['id' => '16', 'title' => 'N+1問題を解決する', 'kind' => 'none'],
    ['id' => '17', 'title' => 'メールとジョブ機能', 'kind' => 'none'],
    ['id' => '18', 'title' => 'TDDで機能を追加する', 'kind' => 'none'],
    ['id' => 'boss-3', 'title' => 'ボス戦3 講師向けの受講者一覧APIをTDDで作る', 'kind' => 'none'],
];
