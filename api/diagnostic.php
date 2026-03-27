<?php
/**
 * API: Server Diagnostics
 * GET /api/diagnostic.php  – checks DB connectivity, PHP extensions,
 *                            table existence, and column sizes.
 *
 * Only available when APP_ENV=development or DEBUG=true.
 * To enable on a production server temporarily, add to config.local.php:
 *   define('DEBUG', true);
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

if (!defined('DEBUG') || !DEBUG) {
    http_response_code(403);
    echo json_encode(['error' => 'Diagnostic endpoint is only available in debug/development mode. Set APP_ENV=development or DEBUG=true in config.local.php.']);
    exit;
}

$checks = [];

// ── PHP extensions ────────────────────────────────────────────────────────────
$required_extensions = ['pdo', 'pdo_mysql', 'sodium', 'json', 'mbstring'];
foreach ($required_extensions as $ext) {
    $checks['extensions'][$ext] = extension_loaded($ext) ? 'ok' : 'MISSING';
}

// ── Config file ───────────────────────────────────────────────────────────────
$checks['config']['config.local.php']  = file_exists(__DIR__ . '/../config/config.local.php')  ? 'found' : 'not found (using defaults)';
// 'cofig.local.php' is the historical typo filename; intentionally checked here
$checks['config']['cofig.local.php (typo)'] = file_exists(__DIR__ . '/../config/cofig.local.php') ? 'found – rename to config.local.php' : 'not found';
$checks['config']['APP_ENV']           = APP_ENV;
$checks['config']['DEBUG']             = DEBUG ? 'true' : 'false';
$checks['config']['DB_HOST']           = DB_HOST;
$checks['config']['DB_PORT']           = DB_PORT;
$checks['config']['DB_NAME']           = DB_NAME;
$checks['config']['DB_USER']           = DB_USER;
$checks['config']['DB_SOCKET']         = DB_SOCKET ?: '(none)';
$checks['config']['SESSION_SECRET']    = SESSION_SECRET === 'moonbase_super_secret_change_me' ? 'WARNING: using default insecure secret' : 'ok (custom)';

// ── Database connection ───────────────────────────────────────────────────────
$pdo = null;
try {
    require_once __DIR__ . '/../includes/db.php';
    $pdo = get_db();
    $checks['database']['connection'] = 'ok';
    $ver = $pdo->query('SELECT VERSION() as v')->fetch()['v'];
    $checks['database']['server_version'] = $ver;
} catch (\Throwable $e) {
    $checks['database']['connection'] = 'FAILED: ' . $e->getMessage();
}

// ── Required tables ───────────────────────────────────────────────────────────
$required_tables = ['players', 'buildings', 'market_listings', 'market_transactions', 'events', 'event_participants', 'research', 'activity_log', 'pvp_raids', 'alliances', 'alliance_members'];

if ($pdo) {
    $check_table = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    foreach ($required_tables as $table) {
        try {
            $check_table->execute([$table]);
            $exists = (int)$check_table->fetchColumn() > 0;
            $checks['tables'][$table] = $exists ? 'ok' : 'MISSING';
        } catch (\Throwable $e) {
            $checks['tables'][$table] = 'error: ' . $e->getMessage();
        }
    }

    // ── Critical column sizes ─────────────────────────────────────────────────
    try {
        $cols = $pdo->query(
            "SELECT COLUMN_NAME, COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'players'
               AND COLUMN_NAME IN ('session_token', 'nonce', 'wallet_address')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($cols as $col => $type) {
            $checks['columns']['players.' . $col] = $type;
        }

        // Verify session_token is wide enough (needs ≥ 256)
        if (isset($cols['session_token'])) {
            preg_match('/\d+/', $cols['session_token'], $m);
            $width = (int)($m[0] ?? 0);
            if ($width < 256) {
                $checks['columns']['players.session_token'] .= ' — WARNING: too narrow (need ≥ 256); run: ALTER TABLE players MODIFY COLUMN session_token VARCHAR(256) DEFAULT NULL;';
            } else {
                $checks['columns']['players.session_token'] .= ' — ok';
            }
        } else {
            $checks['columns']['players.session_token'] = 'MISSING — schema migration may not have been run';
        }
    } catch (\Throwable $e) {
        $checks['columns']['error'] = $e->getMessage();
    }

    // ── Row counts ────────────────────────────────────────────────────────────
    try {
        $checks['row_counts']['players']   = (int)$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn();
        $checks['row_counts']['buildings'] = (int)$pdo->query('SELECT COUNT(*) FROM buildings')->fetchColumn();
    } catch (\Throwable $e) {
        $checks['row_counts']['error'] = $e->getMessage();
    }
}

// ── Sodium (Ed25519 verification) ─────────────────────────────────────────────
if (extension_loaded('sodium')) {
    $checks['sodium']['version']     = SODIUM_LIBRARY_VERSION;
    $checks['sodium']['sign_verify'] = function_exists('sodium_crypto_sign_verify_detached') ? 'ok' : 'MISSING';
} else {
    $checks['sodium']['status'] = 'MISSING — Ed25519 signature verification will fail';
}

// ── Overall status ────────────────────────────────────────────────────────────
$has_error = false;
array_walk_recursive($checks, function ($v) use (&$has_error) {
    if (is_string($v) && (str_starts_with($v, 'MISSING') || str_starts_with($v, 'FAILED') || str_contains($v, 'WARNING'))) {
        $has_error = true;
    }
});
$checks['overall'] = $has_error ? 'ISSUES FOUND – review above' : 'all checks passed';

http_response_code($has_error ? 503 : 200);
echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
