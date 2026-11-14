<?php

require_once('./assets/init.php');

if (!defined('IS_LOGGED') || !IS_LOGGED || !PT_IsAdmin()) {

    $site_url  = !empty($pt->config->site_url) ? rtrim($pt->config->site_url, '/') : '';
    $last_url  = $site_url . '/db.php';
    $login_url = $site_url . '/login?last_url=' . urlencode($last_url);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
	      <link rel="shortcut icon" type="image/png" href="https://test-videos.connersclinic.com/themes/youplay/img/icon.png"/>
        <meta charset="utf-8">
        <title>Admin Access Required</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

        <style>
            :root {
                --primary: #2563eb;
                --primary2: #7c3aed;
                --success: #10b981;
                --danger: #ef4444;
                --dark: #0f172a;
                --muted: #64748b;
                --soft: #f8fafc;
                --border: #e5e7eb;
            }

            * {
                box-sizing: border-box;
            }

            body {
                min-height: 100vh;
                margin: 0;
                font-family: "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                background:
                    radial-gradient(circle at 10% 12%, rgba(37,99,235,.16), transparent 28%),
                    radial-gradient(circle at 90% 18%, rgba(124,58,237,.14), transparent 30%),
                    radial-gradient(circle at 70% 88%, rgba(16,185,129,.12), transparent 26%),
                    linear-gradient(135deg, #ffffff 0%, #f8fafc 44%, #eef4ff 100%);
                color: var(--dark);
                overflow-x: hidden;
            }

            .page {
                min-height: 100vh;
                position: relative;
                display: flex;
                align-items: center;
                padding: 38px 16px;
            }

            .grid-bg {
                position: fixed;
                inset: 0;
                background-image:
                    linear-gradient(rgba(15,23,42,.045) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(15,23,42,.045) 1px, transparent 1px);
                background-size: 38px 38px;
                mask-image: radial-gradient(circle at center, black, transparent 76%);
                pointer-events: none;
            }

            .shape {
                position: fixed;
                border-radius: 999px;
                filter: blur(3px);
                opacity: .85;
                pointer-events: none;
                animation: floatShape 7s ease-in-out infinite alternate;
            }

            .shape.one {
                width: 92px;
                height: 92px;
                left: 7%;
                top: 12%;
                background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            }

            .shape.two {
                width: 130px;
                height: 130px;
                right: 8%;
                top: 16%;
                background: linear-gradient(135deg, #ede9fe, #ddd6fe);
                animation-delay: 1s;
            }

            .shape.three {
                width: 78px;
                height: 78px;
                right: 18%;
                bottom: 10%;
                background: linear-gradient(135deg, #d1fae5, #a7f3d0);
                animation-delay: 1.8s;
            }

            @keyframes floatShape {
                from {
                    transform: translateY(0) rotate(0deg);
                }
                to {
                    transform: translateY(-22px) rotate(8deg);
                }
            }

            .main-card {
                width: 100%;
                max-width: 1040px;
                margin: auto;
                position: relative;
                z-index: 2;
                background: rgba(255,255,255,.82);
                border: 1px solid rgba(226,232,240,.9);
                border-radius: 34px;
                box-shadow:
                    0 30px 90px rgba(15,23,42,.12),
                    inset 0 1px 0 rgba(255,255,255,.85);
                backdrop-filter: blur(22px);
                overflow: hidden;
            }

            .top-strip {
                height: 7px;
                background: linear-gradient(90deg, var(--primary), var(--primary2), var(--success));
            }

            .left-panel {
                padding: 48px;
                height: 100%;
                background:
                    radial-gradient(circle at top left, rgba(37,99,235,.10), transparent 38%),
                    linear-gradient(180deg, rgba(255,255,255,.7), rgba(248,250,252,.85));
            }

            .right-panel {
                padding: 48px;
                background:
                    linear-gradient(135deg, #0f172a, #1e1b4b);
                color: #fff;
                min-height: 100%;
                position: relative;
                overflow: hidden;
            }

            .right-panel:before {
                content: "";
                position: absolute;
                width: 260px;
                height: 260px;
                border-radius: 999px;
                background: rgba(59,130,246,.28);
                right: -80px;
                top: -80px;
                filter: blur(8px);
            }

            .right-panel:after {
                content: "";
                position: absolute;
                width: 210px;
                height: 210px;
                border-radius: 999px;
                background: rgba(16,185,129,.20);
                left: -70px;
                bottom: -90px;
                filter: blur(10px);
            }

            .content-layer {
                position: relative;
                z-index: 2;
            }

            .brand-pill {
                display: inline-flex;
                align-items: center;
                gap: 9px;
                padding: 9px 14px;
                border-radius: 999px;
                background: #eff6ff;
                color: #1d4ed8;
                border: 1px solid #dbeafe;
                font-size: 13px;
                font-weight: 800;
                margin-bottom: 22px;
            }

            .security-icon {
                width: 92px;
                height: 92px;
                border-radius: 30px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: linear-gradient(135deg, var(--primary), var(--primary2));
                color: #fff;
                font-size: 44px;
                box-shadow: 0 24px 55px rgba(37,99,235,.28);
                margin-bottom: 25px;
                animation: iconPulse 2.6s ease-in-out infinite;
            }

            @keyframes iconPulse {
                0%, 100% {
                    transform: scale(1);
                    box-shadow: 0 24px 55px rgba(37,99,235,.26);
                }
                50% {
                    transform: scale(1.045);
                    box-shadow: 0 28px 72px rgba(124,58,237,.30);
                }
            }

            h1 {
                font-size: clamp(34px, 5vw, 58px);
                line-height: .98;
                font-weight: 900;
                letter-spacing: -.055em;
                margin-bottom: 18px;
            }

            .lead-text {
                color: var(--muted);
                font-size: 17px;
                line-height: 1.75;
                max-width: 590px;
                margin-bottom: 30px;
            }

            .login-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 11px;
                min-height: 58px;
                padding: 0 28px;
                border-radius: 18px;
                background: linear-gradient(135deg, var(--primary), var(--primary2));
                color: #fff;
                text-decoration: none;
                font-weight: 900;
                box-shadow: 0 22px 45px rgba(37,99,235,.24);
                border: 0;
                transition: all .25s ease;
                position: relative;
                overflow: hidden;
            }

            .login-btn:before {
                content: "";
                position: absolute;
                top: 0;
                left: -80%;
                width: 60%;
                height: 100%;
                background: linear-gradient(90deg, transparent, rgba(255,255,255,.35), transparent);
                transform: skewX(-18deg);
                transition: .5s;
            }

            .login-btn:hover {
                color: #fff;
                transform: translateY(-3px);
                box-shadow: 0 28px 65px rgba(37,99,235,.32);
            }

            .login-btn:hover:before {
                left: 125%;
            }

            .back-link {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: var(--muted);
                text-decoration: none;
                font-weight: 700;
                margin-left: 14px;
            }

            .back-link:hover {
                color: var(--dark);
            }

            .feature-card {
                background: #fff;
                border: 1px solid var(--border);
                border-radius: 22px;
                padding: 18px;
                box-shadow: 0 16px 40px rgba(15,23,42,.06);
                height: 100%;
            }

            .feature-icon {
                width: 44px;
                height: 44px;
                border-radius: 15px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: #eff6ff;
                color: var(--primary);
                font-size: 22px;
                margin-bottom: 14px;
            }

            .feature-card h3 {
                font-size: 15px;
                font-weight: 900;
                margin-bottom: 6px;
            }

            .feature-card p {
                margin: 0;
                color: var(--muted);
                font-size: 13px;
                line-height: 1.6;
            }

            .status-box {
                border-radius: 26px;
                background: rgba(255,255,255,.10);
                border: 1px solid rgba(255,255,255,.16);
                padding: 22px;
                margin-bottom: 18px;
                backdrop-filter: blur(18px);
            }

            .status-box .icon {
                width: 48px;
                height: 48px;
                border-radius: 16px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: rgba(255,255,255,.14);
                font-size: 24px;
                margin-bottom: 16px;
            }

            .status-box h4 {
                font-size: 16px;
                font-weight: 900;
                margin-bottom: 8px;
            }

            .status-box p {
                color: rgba(255,255,255,.72);
                margin: 0;
                font-size: 13px;
                line-height: 1.65;
            }

            .mini-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 13px 0;
                border-bottom: 1px solid rgba(255,255,255,.12);
                color: rgba(255,255,255,.78);
                font-size: 14px;
            }

            .mini-row:last-child {
                border-bottom: 0;
            }

            .mini-row strong {
                color: #fff;
            }

            .alert-soft {
                margin-top: 20px;
                padding: 14px 16px;
                border-radius: 18px;
                background: #fff7ed;
                border: 1px solid #fed7aa;
                color: #9a3412;
                font-size: 14px;
                font-weight: 700;
            }

            .device-frame {
                margin-top: 28px;
                border-radius: 24px;
                background: rgba(255,255,255,.10);
                border: 1px solid rgba(255,255,255,.16);
                padding: 14px;
            }

            .fake-window {
                border-radius: 18px;
                background: rgba(15,23,42,.72);
                overflow: hidden;
                box-shadow: 0 20px 40px rgba(0,0,0,.16);
            }

            .fake-top {
                height: 36px;
                display: flex;
                align-items: center;
                gap: 7px;
                padding: 0 13px;
                background: rgba(255,255,255,.08);
            }

            .dot {
                width: 10px;
                height: 10px;
                border-radius: 50%;
                display: inline-block;
            }

            .dot.red { background: #fb7185; }
            .dot.yellow { background: #fbbf24; }
            .dot.green { background: #34d399; }

            .fake-code {
                padding: 18px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
                font-size: 12px;
                color: rgba(255,255,255,.76);
                line-height: 1.8;
            }

            .code-blue { color: #93c5fd; }
            .code-green { color: #86efac; }
            .code-purple { color: #c4b5fd; }

            @media (max-width: 991px) {
                .left-panel,
                .right-panel {
                    padding: 34px 24px;
                }

                .main-card {
                    border-radius: 28px;
                }

                .back-link {
                    margin-left: 0;
                    margin-top: 15px;
                }

                .login-btn {
                    width: 100%;
                }

                .back-link {
                    width: 100%;
                    justify-content: center;
                }
            }

            @media (max-width: 575px) {
                .page {
                    padding: 20px 10px;
                }

                .security-icon {
                    width: 78px;
                    height: 78px;
                    font-size: 36px;
                    border-radius: 24px;
                }

                .feature-card {
                    padding: 16px;
                }

                .shape {
                    display: none;
                }
            }
        </style>
    </head>

    <body>
        <div class="grid-bg"></div>
        <div class="shape one" data-speed="2"></div>
        <div class="shape two" data-speed="4"></div>
        <div class="shape three" data-speed="3"></div>

        <main class="page">
            <section class="main-card animate__animated ">
                <div class="top-strip"></div>

                <div class="row g-0">
                    <div class="col-lg-7">
                        <div class="left-panel">
                            <div class="brand-pill" data-aos="fade-down">
                                <i class="bi bi-database-lock"></i>
                                Protected Database Utility
                            </div>

                            <div class="security-icon" data-aos="zoom-in" data-aos-delay="100">
                                <i class="bi bi-shield-lock-fill"></i>
                            </div>

                            <h1 data-aos="fade-up" data-aos-delay="180">
                                Admin Access Required
                            </h1>

                            <p class="lead-text" data-aos="fade-up" data-aos-delay="260">
                                This schema sync tool can create live database tables and columns.
                                To continue safely, please login with an administrator account.
                            </p>

                            <div data-aos="fade-up" data-aos-delay="340">
                                <a href="<?= htmlspecialchars($login_url, ENT_QUOTES, 'UTF-8') ?>" class="login-btn">
                                    <i class="bi bi-box-arrow-in-right"></i>
                                    Login as Admin
                                </a>

                                <a href="<?= htmlspecialchars($site_url, ENT_QUOTES, 'UTF-8') ?>" class="back-link">
                                    <i class="bi bi-arrow-left"></i>
                                    Back to website
                                </a>
                            </div>

                            <div class="alert-soft" data-aos="fade-up" data-aos-delay="420">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                Only authorized admins can run database schema operations.
                            </div>

                            <div class="row g-3 mt-4">
                                <div class="col-md-4" data-aos="fade-up" data-aos-delay="500">
                                    <div class="feature-card">
                                        <div class="feature-icon">
                                            <i class="bi bi-person-check"></i>
                                        </div>
                                        <h3>Admin Only</h3>
                                        <p>Access is blocked until admin login is verified.</p>
                                    </div>
                                </div>

                                <div class="col-md-4" data-aos="fade-up" data-aos-delay="600">
                                    <div class="feature-card">
                                        <div class="feature-icon">
                                            <i class="bi bi-database-check"></i>
                                        </div>
                                        <h3>Live DB Safe</h3>
                                        <p>Prevents accidental public access to sync actions.</p>
                                    </div>
                                </div>

                                <div class="col-md-4" data-aos="fade-up" data-aos-delay="700">
                                    <div class="feature-card">
                                        <div class="feature-icon">
                                            <i class="bi bi-lock"></i>
                                        </div>
                                        <h3>Protected</h3>
                                        <p>Secure gateway before schema management starts.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="right-panel">
                            <div class="content-layer">
                                <div class="status-box" data-aos="fade-left" data-aos-delay="200">
                                    <div class="icon">
                                        <i class="bi bi-shield-exclamation"></i>
                                    </div>
                                    <h4>Security Checkpoint</h4>
                                    <p>
                                        Your current session does not have administrator permission.
                                        Login again to continue to the database sync report.
                                    </p>
                                </div>

                                <div class="status-box" data-aos="fade-left" data-aos-delay="320">
                                    <div class="mini-row">
                                        <span><i class="bi bi-person me-2"></i>Login Status</span>
                                        <strong><?= (defined('IS_LOGGED') && IS_LOGGED) ? 'Logged In' : 'Guest' ?></strong>
                                    </div>

                                    <div class="mini-row">
                                        <span><i class="bi bi-award me-2"></i>Admin Permission</span>
                                        <strong>Required</strong>
                                    </div>

                                    <div class="mini-row">
                                        <span><i class="bi bi-hdd-network me-2"></i>Tool</span>
                                        <strong>Schema Sync</strong>
                                    </div>

                                    <div class="mini-row">
                                        <span><i class="bi bi-clock-history me-2"></i>Request Time</span>
                                        <strong><?= date('H:i:s') ?></strong>
                                    </div>
                                </div>

                                <div class="device-frame" data-aos="zoom-in" data-aos-delay="460">
                                    <div class="fake-window">
                                        <div class="fake-top">
                                            <span class="dot red"></span>
                                            <span class="dot yellow"></span>
                                            <span class="dot green"></span>
                                        </div>

                                        <div class="fake-code">
                                            <div><span class="code-purple">if</span> (!IS_LOGGED) {</div>
                                            <div>&nbsp;&nbsp;<span class="code-blue">status</span>: <span class="code-green">"blocked"</span>;</div>
                                            <div>&nbsp;&nbsp;<span class="code-blue">action</span>: <span class="code-green">"login_required"</span>;</div>
                                            <div>}</div>
                                            <div class="mt-2"><span class="code-green">Secure access enabled.</span></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-center mt-4" data-aos="fade-up" data-aos-delay="560">
                                    <span class="badge rounded-pill text-bg-light px-3 py-2">
                                        <i class="bi bi-stars me-1"></i>
                                        Clean Admin Protection
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>

        <script>
            AOS.init({
                duration: 750,
                once: true,
                offset: 40,
                easing: 'ease-out-cubic'
            });

            document.addEventListener('mousemove', function(e) {
                var x = (e.clientX / window.innerWidth - 0.5);
                var y = (e.clientY / window.innerHeight - 0.5);

                document.querySelectorAll('.shape').forEach(function(el) {
                    var speed = parseFloat(el.getAttribute('data-speed') || 2);
                    el.style.transform = 'translate(' + (x * speed * 10) + 'px,' + (y * speed * 10) + 'px)';
                });
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host   = 'localhost';
$user   = 'saqib';
$pass   = 'q_7enWuil)Yo^dfVe>n^SILEANC#d^';


$stgDb  = 'stg_vid';   // development/source
$liveDb = 'super_db';  // live/target

$startedAt = microtime(true);

$summary = [
    'stg_total_cols'      => 0,
    'live_total_cols'     => 0,
    'final_live_cols'     => 0,
    'missing_tables'      => 0,
    'created_tables'      => 0,
    'missing_columns'     => 0,
    'added_columns'       => 0,
    'checked_tables'      => 0,
    'status'              => 'pending',
    'message'             => '',
];

$logs = [];
$createdTables = [];
$addedColumns = [];
$errors = [];

function h($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function qi($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

function addLog(&$logs, $type, $title, $message = '') {
    $logs[] = [
        'time'    => date('H:i:s'),
        'type'    => $type,
        'title'   => $title,
        'message' => $message,
    ];
}

function one($mysqli, $sql) {
    $res = $mysqli->query($sql);
    $row = $res->fetch_row();
    return $row[0] ?? null;
}

function listTables($mysqli, $db) {
    $tables = [];
    $dbEsc = $mysqli->real_escape_string($db);

    $res = $mysqli->query("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '{$dbEsc}'
          AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
    ");

    while ($row = $res->fetch_assoc()) {
        $tables[] = $row['TABLE_NAME'];
    }

    return $tables;
}

function listColumns($mysqli, $db, $table) {
    $cols = [];
    $dbEsc = $mysqli->real_escape_string($db);
    $tableEsc = $mysqli->real_escape_string($table);

    $res = $mysqli->query("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '{$dbEsc}'
          AND TABLE_NAME = '{$tableEsc}'
        ORDER BY ORDINAL_POSITION
    ");

    while ($row = $res->fetch_assoc()) {
        $cols[] = $row['COLUMN_NAME'];
    }

    return $cols;
}

function showCreateTable($mysqli, $db, $table) {
    $res = $mysqli->query("SHOW CREATE TABLE " . qi($db) . "." . qi($table));
    $row = $res->fetch_assoc();
    return $row['Create Table'];
}

function columnDefinitionsFromCreate($createSql) {
    $defs = [];

    foreach (explode("\n", $createSql) as $line) {
        $line = trim($line);

        if (preg_match('/^`((?:``|[^`])+)`\s+.+?,?$/', $line, $m)) {
            $col = str_replace('``', '`', $m[1]);
            $defs[$col] = rtrim($line, ',');
        }
    }

    return $defs;
}

try {
    addLog($logs, 'info', 'Connection started', 'Connecting to MySQL server with one shared connection.');

    $mysqli = new mysqli($host, $user, $pass);
    $mysqli->set_charset('utf8mb4');

    addLog($logs, 'success', 'Connected successfully', 'Database server connection is active.');

    $stgEsc  = $mysqli->real_escape_string($stgDb);
    $liveEsc = $mysqli->real_escape_string($liveDb);

    $summary['stg_total_cols'] = (int) one($mysqli, "
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '{$stgEsc}'
    ");

    $summary['live_total_cols'] = (int) one($mysqli, "
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '{$liveEsc}'
    ");

    addLog($logs, 'info', 'Column count checked', "Development: {$summary['stg_total_cols']} columns, Live: {$summary['live_total_cols']} columns.");

    if ($summary['stg_total_cols'] === $summary['live_total_cols']) {
        $summary['final_live_cols'] = $summary['live_total_cols'];
        $summary['status'] = 'synced';
        $summary['message'] = 'Both databases already have the same total column count. No further sync needed.';
        addLog($logs, 'success', 'Already synced', 'Column counts are equal, script stopped safely.');
    } else {
        $summary['status'] = 'syncing';
        $summary['message'] = 'Column count is different. Checking missing tables and columns.';

        $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
        addLog($logs, 'warning', 'Foreign key checks disabled', 'Temporary disabled while creating missing tables and columns.');

        try {
            $stgTables  = listTables($mysqli, $stgDb);
            $liveTables = listTables($mysqli, $liveDb);

            $liveTableMap = array_flip($liveTables);

            foreach ($stgTables as $table) {
                $summary['checked_tables']++;

                if (!isset($liveTableMap[$table])) {
                    $summary['missing_tables']++;

                    $createSql = showCreateTable($mysqli, $stgDb, $table);

                    $mysqli->select_db($liveDb);
                    $mysqli->query($createSql);

                    $summary['created_tables']++;

                    $createdTables[] = [
                        'table'  => $table,
                        'status' => 'Created',
                    ];

                    addLog($logs, 'success', 'Table created', "{$liveDb}.{$table} was missing and has been created.");
                    continue;
                }

                $stgCols  = listColumns($mysqli, $stgDb, $table);
                $liveCols = listColumns($mysqli, $liveDb, $table);

                if (count($stgCols) === count($liveCols)) {
                    continue;
                }

                $liveColMap = array_flip($liveCols);

                $createSql = showCreateTable($mysqli, $stgDb, $table);
                $defs = columnDefinitionsFromCreate($createSql);

                $previousCol = null;

                foreach ($stgCols as $col) {
                    if (!isset($liveColMap[$col])) {
                        $summary['missing_columns']++;

                        if (!isset($defs[$col])) {
                            $errors[] = [
                                'table'   => $table,
                                'column'  => $col,
                                'message' => 'Column definition was not found in SHOW CREATE TABLE output.',
                            ];

                            addLog($logs, 'danger', 'Column skipped', "{$table}.{$col} definition was not found.");
                            continue;
                        }

                        $position = $previousCol
                            ? " AFTER " . qi($previousCol)
                            : " FIRST";

                        $sql = "ALTER TABLE " . qi($liveDb) . "." . qi($table)
                             . " ADD COLUMN " . $defs[$col] . $position;

                        $mysqli->query($sql);

                        $summary['added_columns']++;

                        $addedColumns[] = [
                            'table'      => $table,
                            'column'     => $col,
                            'position'   => $previousCol ? 'After ' . $previousCol : 'First',
                            'definition' => $defs[$col],
                        ];

                        $liveColMap[$col] = true;

                        addLog($logs, 'success', 'Column added', "{$liveDb}.{$table}.{$col} was added successfully.");
                    }

                    $previousCol = $col;
                }
            }

        } finally {
            $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
            addLog($logs, 'info', 'Foreign key checks enabled', 'Database checks restored.');
        }

        $summary['final_live_cols'] = (int) one($mysqli, "
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = '{$liveEsc}'
        ");

        $summary['status'] = empty($errors) ? 'completed' : 'completed_with_warnings';
        $summary['message'] = empty($errors)
            ? 'Schema sync completed successfully.'
            : 'Schema sync completed, but some items need review.';
    }

} catch (Throwable $e) {
    $summary['status'] = 'failed';
    $summary['message'] = $e->getMessage();

    $errors[] = [
        'table'   => '-',
        'column'  => '-',
        'message' => $e->getMessage(),
    ];

    addLog($logs, 'danger', 'Script failed', $e->getMessage());
}

$duration = round(microtime(true) - $startedAt, 3);

$statusClass = [
    'synced'                  => 'success',
    'completed'               => 'success',
    'completed_with_warnings' => 'warning',
    'failed'                  => 'danger',
    'syncing'                 => 'primary',
    'pending'                 => 'secondary',
][$summary['status']] ?? 'secondary';

$progress = 100;

?>
<!doctype html>
<html lang="en">
<head>
      <link rel="shortcut icon" type="image/png" href="https://test-videos.connersclinic.com/themes/youplay/img/icon.png"/>
    <meta charset="utf-8">
    <title>Database Schema Sync Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- AOS Animation -->
    <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet">

    <style>
        :root {
            --bg1: #07111f;
            --bg2: #101b33;
            --glass: rgba(255,255,255,.08);
            --glass2: rgba(255,255,255,.13);
            --border: rgba(255,255,255,.14);
            --text: #eaf1ff;
            --muted: rgba(234,241,255,.68);
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(13,110,253,.45), transparent 35%),
                radial-gradient(circle at top right, rgba(111,66,193,.38), transparent 35%),
                linear-gradient(135deg, var(--bg1), var(--bg2));
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .page-wrap {
            position: relative;
            overflow: hidden;
        }

        .orb {
            position: fixed;
            width: 360px;
            height: 360px;
            border-radius: 999px;
            filter: blur(70px);
            opacity: .28;
            z-index: 0;
            pointer-events: none;
            animation: floatOrb 9s ease-in-out infinite alternate;
        }

        .orb.one {
            left: -120px;
            top: 100px;
            background: #0dcaf0;
        }

        .orb.two {
            right: -120px;
            bottom: 100px;
            background: #d63384;
            animation-delay: 1.4s;
        }

        @keyframes floatOrb {
            from { transform: translateY(0) scale(1); }
            to { transform: translateY(-35px) scale(1.08); }
        }

        .content {
            position: relative;
            z-index: 1;
        }

        .glass-card {
            background: linear-gradient(145deg, rgba(255,255,255,.13), rgba(255,255,255,.055));
            border: 1px solid var(--border);
            box-shadow: 0 24px 80px rgba(0,0,0,.26);
            backdrop-filter: blur(18px);
            border-radius: 24px;
        }

        .hero-card {
            padding: 34px;
        }

        .brand-icon {
            width: 64px;
            height: 64px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 20px;
            background: linear-gradient(135deg, #0d6efd, #6f42c1);
            box-shadow: 0 16px 40px rgba(13,110,253,.35);
            font-size: 30px;
        }

        .status-pill {
            border-radius: 999px;
            padding: 9px 15px;
            font-weight: 700;
            letter-spacing: .2px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .soft-muted {
            color: var(--muted);
        }

        .metric-card {
            padding: 22px;
            transition: .25s ease;
            height: 100%;
        }

        .metric-card:hover {
            transform: translateY(-5px);
            border-color: rgba(255,255,255,.24);
        }

        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: rgba(255,255,255,.12);
        }

        .metric-number {
            font-size: 32px;
            font-weight: 800;
            line-height: 1;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }

        .section-title i {
            width: 38px;
            height: 38px;
            border-radius: 13px;
            background: rgba(255,255,255,.11);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .table {
            --bs-table-bg: transparent;
            --bs-table-color: var(--text);
            --bs-table-border-color: rgba(255,255,255,.12);
        }

        .table thead th {
            color: rgba(234,241,255,.78);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .08em;
            border-bottom-color: rgba(255,255,255,.2);
        }

        .table td {
            vertical-align: middle;
        }

        .code-chip {
            display: inline-block;
            max-width: 720px;
            padding: 7px 10px;
            border-radius: 10px;
            background: rgba(0,0,0,.25);
            color: #d9e7ff;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .timeline {
            position: relative;
        }

        .timeline-item {
            position: relative;
            padding-left: 46px;
            padding-bottom: 22px;
        }

        .timeline-item:before {
            content: "";
            position: absolute;
            left: 17px;
            top: 33px;
            bottom: 0;
            width: 1px;
            background: rgba(255,255,255,.16);
        }

        .timeline-item:last-child:before {
            display: none;
        }

        .timeline-icon {
            position: absolute;
            left: 0;
            top: 0;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.15);
        }

        .timeline-title {
            font-weight: 700;
        }

        .timeline-msg {
            color: var(--muted);
            font-size: 14px;
        }

        .progress {
            height: 10px;
            background: rgba(255,255,255,.12);
        }

        .progress-bar {
            background: linear-gradient(90deg, #0d6efd, #20c997, #6f42c1);
        }

        .copy-btn {
            border-color: rgba(255,255,255,.22);
            color: var(--text);
        }

        .copy-btn:hover {
            background: rgba(255,255,255,.12);
            color: #fff;
        }

        .footer-note {
            color: rgba(234,241,255,.55);
            font-size: 13px;
        }

        @media (max-width: 767px) {
            .hero-card {
                padding: 24px;
            }

            .metric-number {
                font-size: 27px;
            }

            .code-chip {
                max-width: 260px;
            }
        }
.deployment-flow {
    position: relative;
}

.deploy-step {
    position: relative;
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 17px 18px;
    margin-bottom: 12px;
    border-radius: 16px;
    background: rgba(255,255,255,.055);
    border: 1px solid rgba(255,255,255,.10);
    transition: all .25s ease;
}

.deploy-step:last-child {
    margin-bottom: 0;
}

.deploy-step-icon {
    width: 44px;
    height: 44px;
    min-width: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: rgba(255,255,255,.10);
    font-size: 20px;
}

.step-status {
    width: 32px;
    text-align: center;
    font-size: 20px;
    color: rgba(255,255,255,.35);
}

.step-message {
    display: none;
    margin-top: 6px;
    font-size: 12px;
    word-break: break-word;
}

.deploy-step.running {
    background: rgba(13,110,253,.13);
    border-color: rgba(13,110,253,.45);
}

.deploy-step.running .deploy-step-icon,
.deploy-step.running .step-status {
    color: #6ea8fe;
}

.deploy-step.success {
    background: rgba(25,135,84,.12);
    border-color: rgba(25,135,84,.38);
}

.deploy-step.success .deploy-step-icon,
.deploy-step.success .step-status {
    color: #75b798;
}

.deploy-step.success .step-message {
    display: block;
    color: #a3cfbb;
}

.deploy-step.failed {
    background: rgba(220,53,69,.12);
    border-color: rgba(220,53,69,.40);
}

.deploy-step.failed .deploy-step-icon,
.deploy-step.failed .step-status {
    color: #ea868f;
}

.deploy-step.failed .step-message {
    display: block;
    color: #f1aeb5;
}

.deploy-result {
    padding: 18px;
    border-radius: 16px;
}

.deploy-result.success {
    background: rgba(25,135,84,.13);
    border: 1px solid rgba(25,135,84,.35);
}

.deploy-result.failed {
    background: rgba(220,53,69,.13);
    border: 1px solid rgba(220,53,69,.35);
}

.deploy-sha {
    display: block;
    margin-top: 10px;
    padding: 8px 11px;
    border-radius: 10px;
    background: rgba(0,0,0,.25);
    font-family: monospace;
    font-size: 12px;
    word-break: break-all;
}

#deployProductionBtn {
    min-height: 56px;
    border-radius: 16px;
    font-weight: 800;
}
    </style>
</head>
<body>

<div class="page-wrap py-4 py-md-5">
    <div class="orb one"></div>
    <div class="orb two"></div>

    <div class="container content">

        <div class="glass-card hero-card mb-4" data-aos="fade-up">
            <div class="row align-items-center g-4">
                <div class="col-lg-8">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="brand-icon">
                            <i class="bi bi-database-check"></i>
                        </div>
                        <div>
                            <h1 class="h2 mb-1 fw-bold">Database Schema Sync Report</h1>
                            <div class="soft-muted">
                                Development <strong><?= h($stgDb) ?></strong> → Live <strong><?= h($liveDb) ?></strong>
                            </div>
                        </div>
                    </div>

                    <p class="mb-0 soft-muted">
                        <?= h($summary['message']) ?>
                    </p>
                </div>

                <div class="col-lg-4 text-lg-end">
                    <div class="status-pill bg-<?= h($statusClass) ?> text-white mb-3">
                        <?php if ($summary['status'] === 'failed'): ?>
                            <i class="bi bi-x-circle-fill"></i>
                        <?php elseif ($summary['status'] === 'completed_with_warnings'): ?>
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        <?php else: ?>
                            <i class="bi bi-check-circle-fill"></i>
                        <?php endif; ?>
                        <?= h(strtoupper(str_replace('_', ' ', $summary['status']))) ?>
                    </div>

                    <div class="soft-muted small">
                        Runtime: <strong><?= h($duration) ?>s</strong>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <div class="d-flex justify-content-between small mb-2">
                    <span class="soft-muted">Sync Progress</span>
                    <strong><?= h($progress) ?>%</strong>
                </div>
                <div class="progress">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: <?= h($progress) ?>%"></div>
                </div>
            </div>
        </div>
<div class="glass-card p-4 mt-4" id="gitDeploymentCard">

    <div class="section-title">
        <i class="bi bi-rocket-takeoff"></i>
        <h2 class="h5 mb-0 fw-bold">
            Production Deployment
        </h2>
    </div>

    <p class="soft-muted mb-4">
        Commit development changes, push
        <strong>main</strong>, force-update
        <strong>super</strong>, then deploy production.
    </p>

    <div class="deployment-flow">

        <div class="deploy-step" data-step="prepare">
            <div class="deploy-step-icon">
                <i class="bi bi-git"></i>
            </div>
            <div class="flex-grow-1">
                <strong>1. Prepare Main</strong>
                <div class="soft-muted small">
                    Switch to main and stage all changes
                </div>
                <div class="step-message"></div>
            </div>
            <div class="step-status">
                <i class="bi bi-circle"></i>
            </div>
        </div>

        <div class="deploy-step" data-step="commit">
            <div class="deploy-step-icon">
                <i class="bi bi-check2-square"></i>
            </div>
            <div class="flex-grow-1">
                <strong>2. Create Commit</strong>
                <div class="soft-muted small">
                    git add + automatic dated merge commit
                </div>
                <div class="step-message"></div>
            </div>
            <div class="step-status">
                <i class="bi bi-circle"></i>
            </div>
        </div>

        <div class="deploy-step" data-step="push_main">
            <div class="deploy-step-icon">
                <i class="bi bi-cloud-arrow-up"></i>
            </div>
            <div class="flex-grow-1">
                <strong>3. Push Main</strong>
                <div class="soft-muted small">
                    Local main → origin/main
                </div>
                <div class="step-message"></div>
            </div>
            <div class="step-status">
                <i class="bi bi-circle"></i>
            </div>
        </div>

        <div class="deploy-step" data-step="push_super">
            <div class="deploy-step-icon">
                <i class="bi bi-bezier2"></i>
            </div>
            <div class="flex-grow-1">
                <strong>4. Promote to Super</strong>
                <div class="soft-muted small">
                    Force main → origin/super
                </div>
                <div class="step-message"></div>
            </div>
            <div class="step-status">
                <i class="bi bi-circle"></i>
            </div>
        </div>

        <div class="deploy-step" data-step="deploy">
            <div class="deploy-step-icon">
                <i class="bi bi-server"></i>
            </div>
            <div class="flex-grow-1">
                <strong>5. Update Production</strong>
                <div class="soft-muted small">
                    Production → latest super
                </div>
                <div class="step-message"></div>
            </div>
            <div class="step-status">
                <i class="bi bi-circle"></i>
            </div>
        </div>

        <div class="deploy-step" data-step="verify">
            <div class="deploy-step-icon">
                <i class="bi bi-shield-check"></i>
            </div>
            <div class="flex-grow-1">
                <strong>6. Verify Deployment</strong>
                <div class="soft-muted small">
                    Compare main, super and production commits
                </div>
                <div class="step-message"></div>
            </div>
            <div class="step-status">
                <i class="bi bi-circle"></i>
            </div>
        </div>

    </div>

    <div id="deploymentFinalResult" class="mt-4 d-none"></div>

    <button
        type="button"
        id="deployProductionBtn"
        class="btn btn-success btn-lg w-100 mt-4">

        <i class="bi bi-rocket-takeoff me-2"></i>

        <span class="deploy-button-text">
            Deploy Main to Production
        </span>

        <span
            class="spinner-border spinner-border-sm ms-2 d-none"
            id="deploySpinner">
        </span>

    </button>

</div>
        <div class="row g-3 mb-4 mt-2">
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="50">
                <div class="glass-card metric-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="metric-icon text-info">
                            <i class="bi bi-columns-gap"></i>
                        </div>
                    </div>
                    <div class="metric-number"><?= h($summary['stg_total_cols']) ?></div>
                    <div class="soft-muted mt-2">Development Columns</div>
                </div>
            </div>

            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="100">
                <div class="glass-card metric-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="metric-icon text-primary">
                            <i class="bi bi-server"></i>
                        </div>
                    </div>
                    <div class="metric-number"><?= h($summary['live_total_cols']) ?></div>
                    <div class="soft-muted mt-2">Live Columns Before</div>
                </div>
            </div>

            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="150">
                <div class="glass-card metric-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="metric-icon text-success">
                            <i class="bi bi-table"></i>
                        </div>
                    </div>
                    <div class="metric-number"><?= h($summary['created_tables']) ?></div>
                    <div class="soft-muted mt-2">Tables Created</div>
                </div>
            </div>

            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="200">
                <div class="glass-card metric-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="metric-icon text-warning">
                            <i class="bi bi-plus-square-dotted"></i>
                        </div>
                    </div>
                    <div class="metric-number"><?= h($summary['added_columns']) ?></div>
                    <div class="soft-muted mt-2">Columns Added</div>
                </div>
            </div>
        </div>

        <div class="row g-4">

            <div class="col-lg-7">
                <div class="glass-card p-4 mb-4" data-aos="fade-up">
                    <div class="section-title">
                        <i class="bi bi-plus-circle"></i>
                        <h2 class="h5 mb-0 fw-bold">Added Columns</h2>
                    </div>

                    <?php if (empty($addedColumns)): ?>
                        <div class="alert alert-success border-0 mb-0">
                            <i class="bi bi-check-circle me-1"></i>
                            No missing columns found or column counts were already equal.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Table</th>
                                        <th>Column</th>
                                        <th>Position</th>
                                        <th>Definition</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($addedColumns as $row): ?>
                                    <tr>
                                        <td>
                                            <span class="badge text-bg-primary">
                                                <?= h($row['table']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?= h($row['column']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-dark">
                                                <?= h($row['position']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="code-chip" title="<?= h($row['definition']) ?>">
                                                <?= h($row['definition']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="glass-card p-4 mb-4" data-aos="fade-up">
                    <div class="section-title">
                        <i class="bi bi-table"></i>
                        <h2 class="h5 mb-0 fw-bold">Created Tables</h2>
                    </div>

                    <?php if (empty($createdTables)): ?>
                        <div class="alert alert-info border-0 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            No missing tables found.
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($createdTables as $row): ?>
                                <div class="col-md-6">
                                    <div class="p-3 rounded-4 bg-success bg-opacity-10 border border-success border-opacity-25">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-check-circle-fill text-success"></i>
                                            <strong><?= h($row['table']) ?></strong>
                                        </div>
                                        <div class="soft-muted small mt-1">
                                            Table created in <?= h($liveDb) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="glass-card p-4 mb-4" data-aos="fade-up">
                        <div class="section-title">
                            <i class="bi bi-exclamation-octagon"></i>
                            <h2 class="h5 mb-0 fw-bold">Warnings / Errors</h2>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Table</th>
                                        <th>Column</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($errors as $row): ?>
                                    <tr>
                                        <td><?= h($row['table']) ?></td>
                                        <td><?= h($row['column']) ?></td>
                                        <td>
                                            <span class="text-warning">
                                                <?= h($row['message']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-5">
                <div class="glass-card p-4 mb-4" data-aos="fade-up">
                    <div class="section-title">
                        <i class="bi bi-activity"></i>
                        <h2 class="h5 mb-0 fw-bold">Sync Timeline</h2>
                    </div>

                    <div class="timeline">
                        <?php foreach ($logs as $log): ?>
                            <?php
                                $icon = 'bi-info-circle';
                                $color = 'text-info';

                                if ($log['type'] === 'success') {
                                    $icon = 'bi-check-circle-fill';
                                    $color = 'text-success';
                                } elseif ($log['type'] === 'warning') {
                                    $icon = 'bi-exclamation-triangle-fill';
                                    $color = 'text-warning';
                                } elseif ($log['type'] === 'danger') {
                                    $icon = 'bi-x-circle-fill';
                                    $color = 'text-danger';
                                }
                            ?>

                            <div class="timeline-item">
                                <div class="timeline-icon <?= h($color) ?>">
                                    <i class="bi <?= h($icon) ?>"></i>
                                </div>

                                <div class="d-flex justify-content-between gap-3">
                                    <div class="timeline-title"><?= h($log['title']) ?></div>
                                    <div class="soft-muted small"><?= h($log['time']) ?></div>
                                </div>

                                <?php if (!empty($log['message'])): ?>
                                    <div class="timeline-msg mt-1">
                                        <?= h($log['message']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="glass-card p-4" data-aos="fade-up">
                    <div class="section-title">
                        <i class="bi bi-bar-chart"></i>
                        <h2 class="h5 mb-0 fw-bold">Final Summary</h2>
                    </div>

                    <div class="d-grid gap-3">
                        <div class="d-flex justify-content-between border-bottom border-light border-opacity-10 pb-2">
                            <span class="soft-muted">Checked Tables</span>
                            <strong><?= h($summary['checked_tables']) ?></strong>
                        </div>

                        <div class="d-flex justify-content-between border-bottom border-light border-opacity-10 pb-2">
                            <span class="soft-muted">Missing Tables</span>
                            <strong><?= h($summary['missing_tables']) ?></strong>
                        </div>

                        <div class="d-flex justify-content-between border-bottom border-light border-opacity-10 pb-2">
                            <span class="soft-muted">Missing Columns</span>
                            <strong><?= h($summary['missing_columns']) ?></strong>
                        </div>

                        <div class="d-flex justify-content-between border-bottom border-light border-opacity-10 pb-2">
                            <span class="soft-muted">Live Columns After</span>
                            <strong><?= h($summary['final_live_cols']) ?></strong>
                        </div>

                        <div class="d-flex justify-content-between">
                            <span class="soft-muted">Execution Time</span>
                            <strong><?= h($duration) ?>s</strong>
                        </div>
                    </div>

                    <div class="alert alert-warning border-0 mt-4 mb-0">
                        <i class="bi bi-shield-exclamation me-1"></i>
                        This script only creates missing tables and adds missing columns. It does not modify existing column types, remove columns, or sync indexes for existing tables.
                    </div>
                </div>
            </div>

        </div>

        <div class="text-center footer-note mt-4">
            Schema Sync UI · <?= h(date('Y-m-d H:i:s')) ?>
        </div>

    </div>
</div>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


<script>
AOS.init({
    duration: 650,
    once: true,
    offset: 40
});

$(function () {

    const steps = [
        'prepare',
        'commit',
        'push_main',
        'push_super',
        'deploy',
        'verify'
    ];

    const stepLabels = {
        prepare:    'Prepare Main',
        commit:     'Create Commit',
        push_main:  'Push Main',
        push_super: 'Promote to Super',
        deploy:     'Update Production',
        verify:     'Verify Deployment'
    };

    let deploying = false;

    /*
    |--------------------------------------------------------------------------
    | SweetAlert Toast
    |--------------------------------------------------------------------------
    */

    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 4500,
        timerProgressBar: true,
        didOpen: function (toast) {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    function escapeHtml(value) {
        return $('<div>')
            .text(value == null ? '' : String(value))
            .html();
    }

    function getStepLabel(step) {
        return stepLabels[step] || step;
    }

    function setDeployingState(state) {

        deploying = state;

        $('#deployProductionBtn')
            .prop('disabled', state);

        $('#deploySpinner')
            .toggleClass('d-none', !state);

        $('.deploy-button-text')
            .text(
                state
                    ? 'Deployment Running...'
                    : 'Deploy Main to Production'
            );
    }

    function resetSteps() {

        $('.deploy-step')
            .removeClass('running success failed');

        $('.deploy-step .step-status')
            .html('<i class="bi bi-circle"></i>');

        $('.deploy-step .step-message')
            .hide()
            .html('');

        $('#deploymentFinalResult')
            .addClass('d-none')
            .removeClass('deploy-result success failed')
            .html('');
    }

    /*
    |--------------------------------------------------------------------------
    | Step Running
    |--------------------------------------------------------------------------
    */

    function setRunning(step) {

        const $step =
            $('.deploy-step[data-step="' + step + '"]');

        $step
            .removeClass('success failed')
            .addClass('running');

        $step.find('.step-status').html(
            '<span class="spinner-border spinner-border-sm" role="status"></span>'
        );

        $step.find('.step-message')
            .html(
                '<i class="bi bi-hourglass-split me-1"></i>' +
                'Processing...'
            )
            .show();

        /*
         * Scroll current step into view if needed.
         */
        if ($step.length) {
            $step[0].scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step Success
    |--------------------------------------------------------------------------
    */

    function setSuccess(step, response) {

        const $step =
            $('.deploy-step[data-step="' + step + '"]');

        $step
            .removeClass('running failed')
            .addClass('success');

        $step.find('.step-status').html(
            '<i class="bi bi-check-circle-fill"></i>'
        );

        let message =
            escapeHtml(response.message || 'Completed successfully.');

        if (
            response.data &&
            response.data.commit
        ) {
            message +=
                '<br>' +
                '<code class="d-inline-block mt-1">' +
                escapeHtml(response.data.commit) +
                '</code>';
        }

        if (
            response.data &&
            response.data.message &&
            step === 'commit'
        ) {
            message +=
                '<br>' +
                '<small class="opacity-75">' +
                escapeHtml(response.data.message) +
                '</small>';
        }

        $step.find('.step-message')
            .html(message)
            .show();
    }

    /*
    |--------------------------------------------------------------------------
    | Step Failure
    |--------------------------------------------------------------------------
    */

    function setFailed(step, response) {

        const $step =
            $('.deploy-step[data-step="' + step + '"]');

        $step
            .removeClass('running success')
            .addClass('failed');

        $step.find('.step-status').html(
            '<i class="bi bi-x-circle-fill"></i>'
        );

        let message =
            escapeHtml(
                response.message ||
                'Deployment step failed.'
            );

        if (
            response.data &&
            response.data.output
        ) {
            message += `
                <pre
                    class="mt-2 mb-0 p-2 rounded bg-dark text-light"
                    style="
                        white-space:pre-wrap;
                        max-height:220px;
                        overflow:auto;
                        font-size:11px;
                    "
                >${escapeHtml(response.data.output)}</pre>
            `;
        }

        if (
            response.data &&
            response.data.command
        ) {
            message += `
                <div class="mt-2">
                    <small>
                        Command:
                        <code>${escapeHtml(response.data.command)}</code>
                    </small>
                </div>
            `;
        }

        $step.find('.step-message')
            .html(message)
            .show();
    }

    /*
    |--------------------------------------------------------------------------
    | Final Success
    |--------------------------------------------------------------------------
    */

    function finishDeployment(response) {

        const commit =
            response.data &&
            response.data.commit
                ? response.data.commit
                : '';

        $('#deploymentFinalResult')
            .removeClass('d-none failed')
            .addClass('deploy-result success')
            .html(`
                <div class="d-flex align-items-start gap-3">

                    <div class="fs-3 text-success">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>

                    <div class="flex-grow-1">

                        <div class="fw-bold text-success mb-2">
                            Deployment Successful
                        </div>

                        <div class="soft-muted">
                            Local main, origin/main, origin/super
                            and production are all running the
                            same Git commit.
                        </div>

                        ${
                            commit
                                ? `
                                    <div class="mt-3">
                                        <small class="soft-muted">
                                            Production Commit
                                        </small>

                                        <span class="deploy-sha">
                                            ${escapeHtml(commit)}
                                        </span>
                                    </div>
                                `
                                : ''
                        }

                    </div>

                </div>
            `);

        /*
         * Success toast
         */

        Toast.fire({
            icon: 'success',
            title: 'Production deployed successfully'
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Final Failure
    |--------------------------------------------------------------------------
    */

    function failDeployment(step, response) {

        const label = getStepLabel(step);

        $('#deploymentFinalResult')
            .removeClass('d-none success')
            .addClass('deploy-result failed')
            .html(`
                <div class="d-flex align-items-start gap-3">

                    <div class="fs-3 text-danger">
                        <i class="bi bi-x-circle-fill"></i>
                    </div>

                    <div class="flex-grow-1">

                        <div class="fw-bold text-danger mb-2">
                            Deployment Stopped
                        </div>

                        <div>
                            Failed at:
                            <strong>
                                ${escapeHtml(label)}
                            </strong>
                        </div>

                        <div class="soft-muted mt-2">
                            ${escapeHtml(
                                response.message ||
                                'Unknown deployment error.'
                            )}
                        </div>

                    </div>

                </div>
            `);

        Toast.fire({
            icon: 'error',
            title:
                escapeHtml(label) +
                ': ' +
                escapeHtml(
                    response.message ||
                    'Deployment failed'
                )
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Parse AJAX Error
    |--------------------------------------------------------------------------
    */

    function parseAjaxError(xhr) {

        let response = {
            success: false,
            status: xhr.status || 400,
            message: 'Server request failed.',
            data: {}
        };

        if (xhr.responseJSON) {
            return xhr.responseJSON;
        }

        if (xhr.responseText) {

            try {

                const parsed =
                    JSON.parse(xhr.responseText);

                if (parsed) {
                    response = parsed;
                }

            } catch (e) {

                response.message =
                    xhr.status
                        ? 'Server returned HTTP ' + xhr.status + '.'
                        : 'Invalid response received from server.';

                response.data = {
                    output: xhr.responseText
                };
            }
        }

        return response;
    }

    /*
    |--------------------------------------------------------------------------
    | Run Deployment Step
    |--------------------------------------------------------------------------
    */

    function runStep(index) {

        /*
         * All steps completed
         */

        if (index >= steps.length) {

            setDeployingState(false);

            $('.deploy-button-text')
                .text('Deploy Again');

            return;
        }

        const step = steps[index];

        setRunning(step);

        $.ajax({

            url: '/merge.php',

            method: 'POST',

            dataType: 'json',

            timeout: 120000,

            cache: false,

            data: {
                action: step
            },

            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': <?php echo json_encode(PT_CreateSession()); ?>
            },

            /*
            |--------------------------------------------------------------------------
            | Success
            |--------------------------------------------------------------------------
            */

            success: function (response) {

                if (
                    !response ||
                    response.success !== true
                ) {

                    const errorResponse =
                        response || {
                            success: false,
                            message: 'Invalid server response.'
                        };

                    setFailed(
                        step,
                        errorResponse
                    );

                    failDeployment(
                        step,
                        errorResponse
                    );

                    setDeployingState(false);

                    $('.deploy-button-text')
                        .text('Retry Deployment');

                    return;
                }

                setSuccess(
                    step,
                    response
                );

                /*
                 * Final verification completed.
                 */

                if (step === 'verify') {

                    finishDeployment(
                        response
                    );
                }

                /*
                 * Continue to next deployment action.
                 */

                setTimeout(function () {

                    runStep(
                        index + 1
                    );

                }, 400);
            },

            /*
            |--------------------------------------------------------------------------
            | Error
            |--------------------------------------------------------------------------
            */

            error: function (
                xhr,
                textStatus,
                errorThrown
            ) {

                let response =
                    parseAjaxError(xhr);

                /*
                 * Handle request timeout separately.
                 */

                if (textStatus === 'timeout') {

                    response = {
                        success: false,
                        status: 400,
                        message:
                            'The deployment request timed out. ' +
                            'Check the server before retrying.',
                        data: {}
                    };
                }

                /*
                 * Network failure
                 */

                if (
                    xhr.status === 0 &&
                    textStatus !== 'timeout'
                ) {

                    response = {
                        success: false,
                        status: 400,
                        message:
                            'Unable to connect to the deployment server.',
                        data: {}
                    };
                }

                setFailed(
                    step,
                    response
                );

                failDeployment(
                    step,
                    response
                );

                setDeployingState(false);

                $('.deploy-button-text')
                    .text('Retry Deployment');
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Deployment Button
    |--------------------------------------------------------------------------
    */

    $('#deployProductionBtn').on('click', function () {

        if (deploying) {

            Toast.fire({
                icon: 'info',
                title: 'Deployment is already running'
            });

            return;
        }

        /*
         * SweetAlert confirmation
         */

        Swal.fire({

            title: 'Deploy to Production?',

            html: `
                <div class="text-start">

                    <p class="mb-3">
                        This will promote the current
                        <strong>main</strong>
                        development version to production.
                    </p>

                    <div
                        class="p-3 rounded-3 bg-light text-dark"
                        style="font-size:14px;"
                    >

                        <div class="d-flex gap-2 mb-3">
                            <strong>1.</strong>
                            <div>
                                <strong>Prepare Main</strong>
                                <div class="text-muted small">
                                    Switch to main and stage all files
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mb-3">
                            <strong>2.</strong>
                            <div>
                                <strong>Create Commit</strong>
                                <div class="text-muted small">
                                    Create automatic dated Git commit
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mb-3">
                            <strong>3.</strong>
                            <div>
                                <strong>Push Main</strong>
                                <div class="text-muted small">
                                    main → origin/main
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mb-3">
                            <strong>4.</strong>
                            <div>
                                <strong>Promote to Super</strong>
                                <div class="text-muted small">
                                    FORCE main → origin/super
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mb-3">
                            <strong>5.</strong>
                            <div>
                                <strong>Update Production</strong>
                                <div class="text-muted small">
                                    Production becomes origin/super
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <strong>6.</strong>
                            <div>
                                <strong>Verify Deployment</strong>
                                <div class="text-muted small">
                                    Compare all Git commit SHAs
                                </div>
                            </div>
                        </div>

                    </div>

                    <div
                        class="alert alert-danger mt-3 mb-0 text-start"
                        style="font-size:13px;"
                    >
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>

                        <strong>Important:</strong>

                        any commits that exist only on
                        <code>super</code>
                        will be overwritten by
                        <code>main</code>.
                    </div>

                </div>
            `,

            icon: 'warning',

            showCancelButton: true,

            confirmButtonText:
                '<i class="bi bi-rocket-takeoff me-2"></i>' +
                'Deploy Now',

            cancelButtonText:
                '<i class="bi bi-x-lg me-2"></i>' +
                'Cancel',

            confirmButtonColor: '#198754',

            cancelButtonColor: '#6c757d',

            reverseButtons: true,

            focusCancel: true,

            allowOutsideClick: false,

            allowEscapeKey: true,

            showLoaderOnConfirm: false,

            customClass: {
                popup: 'rounded-4',
                confirmButton: 'px-4 py-2',
                cancelButton: 'px-4 py-2'
            },

            width: 600

        }).then(function (result) {

            if (!result.isConfirmed) {
                return;
            }

            /*
             * Start deployment.
             */

            resetSteps();

            setDeployingState(true);

            Toast.fire({
                icon: 'info',
                title: 'Production deployment started'
            });

            runStep(0);
        });
    });

});
</script>
</body>
</html>