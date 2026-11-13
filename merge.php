<?php

require_once('./assets/init.php');

header('Content-Type: application/json; charset=utf-8');

if (!defined('IS_LOGGED') || !IS_LOGGED || !PT_IsAdmin()) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'status'  => 400,
        'message' => 'Only admin can access this action.'
    ]);
    exit;
}

set_time_limit(300);

$mainRepo = '/home/admin/web/test-videos.connersclinic.com/public_html';
$liveRepo = '/home/admin/web/videos.connersclinic.com/public_html';

function response(int $status, bool $success, string $message, array $data = []): void
{
    http_response_code($status);

    echo json_encode([
        'success' => $success,
        'status'  => $status,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    exit;
}

function gitCommand(string $repo, string $command): array
{
    $output = [];
    $code   = 0;

    exec(
        'cd ' . escapeshellarg($repo) .
        ' && git ' . $command . ' 2>&1',
        $output,
        $code
    );

    return [
        'success' => $code === 0,
        'code'    => $code,
        'command' => 'git ' . $command,
        'output'  => trim(implode("\n", $output))
    ];
}

function fail(string $message, array $result = []): void
{
    response(400, false, $message, [
        'command' => $result['command'] ?? null,
        'code'    => $result['code'] ?? null,
        'output'  => $result['output'] ?? null
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(400, false, 'POST request required.');
}

PT_RequireAdminCsrf();

$action = $_POST['action'] ?? '';

$allowedActions = [
    'prepare',
    'commit',
    'push_main',
    'push_super',
    'deploy',
    'verify'
];

if (!in_array($action, $allowedActions, true)) {
    response(400, false, 'Invalid deployment action.');
}

/*
|--------------------------------------------------------------------------
| Validate repositories
|--------------------------------------------------------------------------
*/

if (!is_dir($mainRepo . '/.git')) {
    response(400, false, 'Development Git repository not found.', [
        'path' => $mainRepo
    ]);
}

if (!is_dir($liveRepo . '/.git')) {
    response(400, false, 'Production Git repository not found.', [
        'path' => $liveRepo
    ]);
}

/*
|--------------------------------------------------------------------------
| STEP 1: PREPARE MAIN
|--------------------------------------------------------------------------
|
| git switch main
| git add -A
|
*/

if ($action === 'prepare') {

    $switch = gitCommand(
        $mainRepo,
        'switch main'
    );

    if (!$switch['success']) {
        fail('Failed to switch to main branch.', $switch);
    }

    $branch = gitCommand(
        $mainRepo,
        'branch --show-current'
    );

    if (
        !$branch['success'] ||
        trim($branch['output']) !== 'main'
    ) {
        fail('Repository is not on main branch.', $branch);
    }

    $add = gitCommand(
        $mainRepo,
        'add -A'
    );

    if (!$add['success']) {
        fail('Failed to stage repository changes.', $add);
    }

    $status = gitCommand(
        $mainRepo,
        'status --short'
    );

    if (!$status['success']) {
        fail('Unable to check Git status.', $status);
    }

    response(200, true, 'Main branch ready and all changes staged.', [
        'branch'  => 'main',
        'changes' => $status['output'] !== ''
            ? explode("\n", $status['output'])
            : []
    ]);
}

/*
|--------------------------------------------------------------------------
| STEP 2: COMMIT
|--------------------------------------------------------------------------
*/

if ($action === 'commit') {

    /*
     * Check if git add produced anything to commit.
     *
     * git diff --cached --quiet:
     * 0 = no staged changes
     * 1 = staged changes exist
     */

    $output = [];
    $code   = 0;

    exec(
        'cd ' . escapeshellarg($mainRepo) .
        ' && git diff --cached --quiet 2>&1',
        $output,
        $code
    );

    if ($code === 0) {

        $currentSha = gitCommand(
            $mainRepo,
            'rev-parse HEAD'
        );

        response(200, true, 'No new changes to commit. Using existing main commit.', [
            'committed' => false,
            'commit'    => trim($currentSha['output'])
        ]);
    }

    if ($code !== 1) {
        response(400, false, 'Unable to check staged Git changes.', [
            'output' => implode("\n", $output)
        ]);
    }

    $commitMessage =
        'Merge commit ' . date('Y-m-d H:i:s');

    $commit = gitCommand(
        $mainRepo,
        'commit -m ' . escapeshellarg($commitMessage)
    );

    if (!$commit['success']) {
        fail('Git commit failed.', $commit);
    }

    $sha = gitCommand(
        $mainRepo,
        'rev-parse HEAD'
    );

    if (!$sha['success']) {
        fail('Unable to read committed SHA.', $sha);
    }

    response(200, true, 'Changes committed successfully.', [
        'committed' => true,
        'message'   => $commitMessage,
        'commit'    => trim($sha['output']),
        'output'    => $commit['output']
    ]);
}

/*
|--------------------------------------------------------------------------
| STEP 3: PUSH MAIN
|--------------------------------------------------------------------------
|
| Local main → origin/main
|
*/

if ($action === 'push_main') {

    $branch = gitCommand(
        $mainRepo,
        'branch --show-current'
    );

    if (
        !$branch['success'] ||
        trim($branch['output']) !== 'main'
    ) {
        response(400, false, 'Current branch is not main.');
    }

    $push = gitCommand(
        $mainRepo,
        'push origin main'
    );

    if (!$push['success']) {
        fail('Failed to push main to origin/main.', $push);
    }

    $sha = gitCommand(
        $mainRepo,
        'rev-parse HEAD'
    );

    response(200, true, 'Main successfully pushed to remote.', [
        'branch' => 'origin/main',
        'commit' => trim($sha['output']),
        'output' => $push['output']
    ]);
}

/*
|--------------------------------------------------------------------------
| STEP 4: FORCE MAIN → SUPER
|--------------------------------------------------------------------------
|
| origin/super will be overwritten with main.
|
*/

if ($action === 'push_super') {

    $push = gitCommand(
        $mainRepo,
        'push --force origin main:super'
    );

    if (!$push['success']) {
        fail('Failed to force push main to super.', $push);
    }

    $fetch = gitCommand(
        $mainRepo,
        'fetch origin'
    );

    if (!$fetch['success']) {
        fail('Super pushed, but remote verification fetch failed.', $fetch);
    }

    $mainSha = gitCommand(
        $mainRepo,
        'rev-parse main'
    );

    $superSha = gitCommand(
        $mainRepo,
        'rev-parse origin/super'
    );

    if (
        trim($mainSha['output']) !==
        trim($superSha['output'])
    ) {
        response(400, false, 'Super does not match main after force push.', [
            'main'  => trim($mainSha['output']),
            'super' => trim($superSha['output'])
        ]);
    }

    response(200, true, 'Main forcefully pushed to super.', [
        'branch' => 'origin/super',
        'commit' => trim($superSha['output']),
        'output' => $push['output']
    ]);
}

/*
|--------------------------------------------------------------------------
| STEP 5: DEPLOY PRODUCTION
|--------------------------------------------------------------------------
|
| Production is intentionally reset to exactly origin/super.
|
| cd /home/admin/web/videos.connersclinic.com/public_html
| git fetch origin
| git switch super
| git reset --hard origin/super
|
*/

if ($action === 'deploy') {

    $fetch = gitCommand(
        $liveRepo,
        'fetch origin'
    );

    if (!$fetch['success']) {
        fail('Production fetch failed.', $fetch);
    }

    $switch = gitCommand(
        $liveRepo,
        'switch super'
    );

    if (!$switch['success']) {
        fail('Failed to switch production to super branch.', $switch);
    }

    $reset = gitCommand(
        $liveRepo,
        'reset --hard origin/super'
    );

    if (!$reset['success']) {
        fail('Failed to update production from super.', $reset);
    }

    $sha = gitCommand(
        $liveRepo,
        'rev-parse HEAD'
    );

    if (!$sha['success']) {
        fail('Unable to read production commit.', $sha);
    }

    response(200, true, 'Production successfully updated from super.', [
        'repository' => $liveRepo,
        'branch'     => 'super',
        'commit'     => trim($sha['output']),
        'output'     => $reset['output']
    ]);
}

/*
|--------------------------------------------------------------------------
| STEP 6: FINAL VERIFICATION
|--------------------------------------------------------------------------
*/

if ($action === 'verify') {

    $fetchMain = gitCommand(
        $mainRepo,
        'fetch origin'
    );

    if (!$fetchMain['success']) {
        fail('Final Git fetch failed.', $fetchMain);
    }

    $localMain = gitCommand(
        $mainRepo,
        'rev-parse main'
    );

    $remoteMain = gitCommand(
        $mainRepo,
        'rev-parse origin/main'
    );

    $remoteSuper = gitCommand(
        $mainRepo,
        'rev-parse origin/super'
    );

    $production = gitCommand(
        $liveRepo,
        'rev-parse HEAD'
    );

    if (
        !$localMain['success'] ||
        !$remoteMain['success'] ||
        !$remoteSuper['success'] ||
        !$production['success']
    ) {
        response(400, false, 'Unable to read all deployment commit SHAs.');
    }

    $localMainSha   = trim($localMain['output']);
    $remoteMainSha  = trim($remoteMain['output']);
    $remoteSuperSha = trim($remoteSuper['output']);
    $productionSha  = trim($production['output']);

    $verified =
        $localMainSha === $remoteMainSha &&
        $localMainSha === $remoteSuperSha &&
        $localMainSha === $productionSha;

    if (!$verified) {
        response(400, false, 'Deployment verification failed. Branch commits do not match.', [
            'local_main'   => $localMainSha,
            'origin_main'  => $remoteMainSha,
            'origin_super' => $remoteSuperSha,
            'production'   => $productionSha
        ]);
    }

    response(200, true, 'Deployment completed and fully verified.', [
        'commit' => $localMainSha,

        'branches' => [
            'local_main'   => $localMainSha,
            'origin_main'  => $remoteMainSha,
            'origin_super' => $remoteSuperSha,
            'production'   => $productionSha
        ]
    ]);
}