<?php

declare(strict_types=1);

/*
 * ステージのクリア状況を表示するスクリプトです。Makefile から呼びます。
 *
 *   php tests/Quests/quest.php map        全ステージの進み具合
 *   php tests/Quests/quest.php stage 10   ステージ10のミッションの一覧と、失敗したミッションのヒント
 *
 * ファイル名が *Test.php ではないので、Pest のテストとしては読み込まれません。
 */

const QUEST_ROOT = __DIR__.'/../..';
const QUEST_CACHE_DIR = QUEST_ROOT.'/storage/app/quests';

/** @var list<array{id: string, title: string, kind: string, file?: string, test?: string, class?: string, min?: int}> $stages */
$stages = require __DIR__.'/stages.php';

$command = $argv[1] ?? 'map';

if ($command === 'map') {
    showMap($stages);
    exit(0);
}

if ($command === 'stage' && isset($argv[2])) {
    $stage = findStage($stages, $argv[2]);

    if ($stage === null) {
        fwrite(STDERR, "ステージ {$argv[2]} はありません。make map で一覧を確かめてください。\n");
        exit(1);
    }

    showStage($stage);
    exit(0);
}

fwrite(STDERR, "使い方: php tests/Quests/quest.php map | stage <番号>\n");
exit(1);

/**
 * @param list<array<string, mixed>> $stages
 * @return array<string, mixed>|null
 */
function findStage(array $stages, string $id): ?array
{
    $id = ctype_digit($id) ? mb_str_pad($id, 2, '0', STR_PAD_LEFT) : $id;

    foreach ($stages as $stage) {
        if ($stage['id'] === $id) {
            return $stage;
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $stages
 */
function showMap(array $stages): void
{
    foreach ($stages as $stage) {
        $label = stageLabel($stage);

        if ($stage['kind'] === 'none') {
            printf("%s  %s\n", $label, '-  判定なし');

            continue;
        }

        $missions = $stage['kind'] === 'pest' ? runPestStage($stage) : readCachedMissions($stage);

        if ($missions === null) {
            printf("%s  %s\n", $label, '?  make quest-'.$stage['id'].' で判定します');

            continue;
        }

        printf("%s  %s\n", $label, summarize($missions));
    }

    warnIfQuestsChanged();
}

/**
 * @param array<string, mixed> $stage
 */
function showStage(array $stage): void
{
    if ($stage['kind'] === 'none') {
        printf("%s  このステージには判定テストがありません。本文の手順で確かめてください。\n", stageLabel($stage));

        return;
    }

    $missions = $stage['kind'] === 'pest' ? runPestStage($stage) : runMutationStage($stage);

    printf("%s  %s\n", stageLabel($stage), summarize($missions));

    foreach ($missions as $mission) {
        $mark = match ($mission['result']) {
            'passed' => '✅',
            'failed' => '❌',
            default => '⬜',
        };
        printf("  %s %s\n", $mark, $mission['name']);

        if ($mission['result'] !== 'passed' && $mission['message'] !== '') {
            printf("     %s\n", $mission['message']);
        }
    }

    warnIfQuestsChanged();
}

/**
 * @param array<string, mixed> $stage
 */
function stageLabel(array $stage): string
{
    $id = str_starts_with($stage['id'], 'boss-') ? 'Boss '.mb_substr($stage['id'], 5) : 'Stage '.$stage['id'];

    $title = $stage['title'];
    $padding = max(0, 44 - mb_strwidth($title));

    return sprintf('%-9s %s%s', $id, $title, str_repeat(' ', $padding));
}

/**
 * @param list<array{name: string, result: string, message: string}> $missions
 */
function summarize(array $missions): string
{
    $counted = array_values(array_filter($missions, fn (array $mission): bool => ! str_starts_with($mission['name'], '[前提]')));
    $passed = count(array_filter($counted, fn (array $mission): bool => $mission['result'] === 'passed'));
    $bar = str_repeat('■', $passed).str_repeat('□', count($counted) - $passed);
    $progress = sprintf('%s %d/%d', $bar, $passed, count($counted));

    if ($passed === 0) {
        return $progress.'  未着手';
    }

    if (isCleared($missions)) {
        return $progress.'  STAGE CLEAR';
    }

    $remaining = count(array_filter($counted, fn (array $mission): bool => str_starts_with($mission['name'], '[メイン]') && $mission['result'] !== 'passed'));

    return $progress.'  残りのメイン '.$remaining;
}

/**
 * メインのミッションがすべて緑ならクリアです。サイドはクリアの条件に入りません。
 *
 * @param list<array{name: string, result: string, message: string}> $missions
 */
function isCleared(array $missions): bool
{
    $main = array_filter($missions, fn (array $mission): bool => str_starts_with($mission['name'], '[メイン]'));

    return $main !== [] && array_filter($main, fn (array $mission): bool => $mission['result'] !== 'passed') === [];
}

/**
 * 判定テストを走らせ、JUnit の出力からミッションごとの結果を読み取ります。
 *
 * @param array<string, mixed> $stage
 * @return list<array{name: string, result: string, message: string}>
 */
function runPestStage(array $stage): array
{
    $junit = tempnam(sys_get_temp_dir(), 'quest');
    runCommand(['vendor/bin/pest', $stage['file'], '--log-junit='.$junit]);
    $missions = readJunit($junit);
    @unlink($junit);

    return $missions;
}

/**
 * @return list<array{name: string, result: string, message: string}>
 */
function readJunit(string $path): array
{
    if (! is_file($path) || filesize($path) === 0) {
        return [['name' => '[メイン] 判定テストを実行できる', 'result' => 'failed', 'message' => 'ヒント: 判定テストを実行できませんでした。make test でエラーが出ていないかを確かめましょう']];
    }

    $xml = simplexml_load_file($path);
    $missions = [];

    foreach ($xml->xpath('//testcase') as $testcase) {
        $name = (string) $testcase['name'];
        $failure = $testcase->failure ?? $testcase->error;

        if ($failure !== null && $failure->count() > 0) {
            $missions[] = ['name' => $name, 'result' => 'failed', 'message' => extractHint((string) $failure, $name)];
        } elseif (isset($testcase->skipped)) {
            $missions[] = ['name' => $name, 'result' => 'skipped', 'message' => ''];
        } else {
            $missions[] = ['name' => $name, 'result' => 'passed', 'message' => ''];
        }
    }

    return $missions;
}

function extractHint(string $failure, string $name): string
{
    if (preg_match('/ヒント:[^\n]*/u', $failure, $matches) === 1) {
        return $matches[0];
    }

    // JUnit の失敗の文は、テスト名のすぐあとに例外の文が続くので、テスト名を取り除く
    $firstLine = (string) strtok($failure, "\n");

    if (str_starts_with($firstLine, $name)) {
        $firstLine = mb_substr($firstLine, mb_strlen($name));
    }

    return 'エラー: '.mb_trim($firstLine);
}

/**
 * 受講者のテストを走らせてから、対象のクラスに対するミューテーションテストを行います。
 * 時間がかかるので、結果を storage/app/quests に保存し、make map ではその結果を表示します。
 *
 * @param array<string, mixed> $stage
 * @return list<array{name: string, result: string, message: string}>
 */
function runMutationStage(array $stage): array
{
    $fileMission = '[メイン] '.$stage['test'].' がある';
    $testMission = '[メイン] 自分の書いたテストがすべて通る';
    $mutationMission = sprintf('[メイン] ミューテーションスコアが %d%% 以上', $stage['min']);

    if (! is_file(QUEST_ROOT.'/'.$stage['test'])) {
        $missions = [
            ['name' => $fileMission, 'result' => 'skipped', 'message' => ''],
            ['name' => $testMission, 'result' => 'skipped', 'message' => ''],
            ['name' => $mutationMission, 'result' => 'skipped', 'message' => ''],
        ];
        cacheMissions($stage, $missions);

        return $missions;
    }

    $missions = [['name' => $fileMission, 'result' => 'passed', 'message' => '']];

    [$testExit] = runCommand(['vendor/bin/pest', $stage['test']]);

    if ($testExit !== 0) {
        $missions[] = ['name' => $testMission, 'result' => 'failed', 'message' => 'ヒント: php artisan test '.$stage['test'].' で、落ちているテストを先に直しましょう'];
        $missions[] = ['name' => $mutationMission, 'result' => 'skipped', 'message' => ''];
        cacheMissions($stage, $missions);

        return $missions;
    }

    $missions[] = ['name' => $testMission, 'result' => 'passed', 'message' => ''];

    [, $output] = runCommand([
        'vendor/bin/pest', $stage['test'], '--mutate', '--class='.$stage['class'], '--covered-only', '--min='.$stage['min'], '--no-cache', '--colors=never',
    ]);
    $output = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
    $score = preg_match('/Score:\s*([0-9.]+)%/', $output, $matches) === 1 ? (float) $matches[1] : null;

    if ($score === null) {
        $missions[] = ['name' => $mutationMission, 'result' => 'failed', 'message' => 'ヒント: ミューテーションテストを実行できませんでした。make build でイメージを作り直したか確かめましょう（PCOV が必要です）'];
    } elseif ($score < $stage['min']) {
        $missions[] = ['name' => $mutationMission, 'result' => 'failed', 'message' => sprintf(
            'ヒント: いまのスコアは %.1f%% です。vendor/bin/pest %s --mutate --class=\'%s\' --covered-only を実行して、生き残ったミュータント（テストで見逃した書き換え）を確かめましょう',
            $score,
            $stage['test'],
            $stage['class'],
        )];
    } else {
        $missions[] = ['name' => $mutationMission, 'result' => 'passed', 'message' => sprintf('スコア %.1f%%', $score)];
    }

    cacheMissions($stage, $missions);

    return $missions;
}

/**
 * @param array<string, mixed> $stage
 * @param list<array{name: string, result: string, message: string}> $missions
 */
function cacheMissions(array $stage, array $missions): void
{
    if (! is_dir(QUEST_CACHE_DIR)) {
        mkdir(QUEST_CACHE_DIR, 0777, true);
    }

    file_put_contents(QUEST_CACHE_DIR.'/stage-'.$stage['id'].'.json', json_encode($missions, JSON_UNESCAPED_UNICODE));
}

/**
 * @param array<string, mixed> $stage
 * @return list<array{name: string, result: string, message: string}>|null
 */
function readCachedMissions(array $stage): ?array
{
    $path = QUEST_CACHE_DIR.'/stage-'.$stage['id'].'.json';

    if (! is_file($path)) {
        return null;
    }

    return json_decode((string) file_get_contents($path), true);
}

/**
 * @param list<string> $command
 * @return array{0: int, 1: string}
 */
function runCommand(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, QUEST_ROOT, ['NO_COLOR' => '1'] + getenv());
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

/**
 * 判定テストが書き換えられていないかを確かめます。main と直接比べると、main の更新を取り込んでいないだけで
 * 差分が出るので、分岐したところ（merge-base）と作業ツリーを比べます。
 */
function warnIfQuestsChanged(): void
{
    $git = ['git', '-c', 'safe.directory=*'];

    foreach (['origin/main', 'main'] as $branch) {
        [$exit, $base] = runCommand([...$git, 'merge-base', 'HEAD', $branch]);

        if ($exit === 0) {
            break;
        }
    }

    if ($exit !== 0) {
        return;
    }

    [$diffExit] = runCommand([...$git, 'diff', '--quiet', mb_trim($base), '--', 'tests/Quests']);
    [, $untracked] = runCommand([...$git, 'status', '--porcelain', '--', 'tests/Quests']);

    if ($diffExit !== 0 || mb_trim($untracked) !== '') {
        echo "\n⚠️  tests/Quests の判定テストが書き換えられています。判定テストは変えずに、自分のコードを直してください。\n";
    }
}
