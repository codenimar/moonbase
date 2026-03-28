<?php
/**
 * API: Server Diagnostics
 * GET /api/diagnostic.php  – checks DB connectivity, PHP extensions,
 *                            table existence, column sizes, and simulates
 *                            the complete login flow to identify auth failures.
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

// ── PHP version ───────────────────────────────────────────────────────────────
$phpVersion = PHP_VERSION;
$checks['php']['version'] = $phpVersion;
$checks['php']['minimum_required'] = '8.0.0';
if (version_compare($phpVersion, '8.0.0', '<')) {
    $checks['php']['status'] = 'WARNING: PHP 8.0+ required; named arguments and str_starts_with() will not be available';
} else {
    $checks['php']['status'] = 'ok';
}

// ── PHP extensions ────────────────────────────────────────────────────────────
$required_extensions = ['pdo', 'pdo_mysql', 'sodium', 'json', 'mbstring'];
foreach ($required_extensions as $ext) {
    $checks['extensions'][$ext] = extension_loaded($ext) ? 'ok' : 'MISSING — install php-' . $ext;
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
$checks['config']['SESSION_SECRET']    = SESSION_SECRET === 'moonbase_super_secret_change_me' ? 'WARNING: using default insecure secret — set SESSION_SECRET in config.local.php' : 'ok (custom)';

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
    $checks['database']['hint'] = 'Check DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASS in config.local.php';
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
            $checks['tables'][$table] = $exists ? 'ok' : 'MISSING — run db/schema.sql to create missing tables';
        } catch (\Throwable $e) {
            $checks['tables'][$table] = 'error: ' . $e->getMessage();
        }
    }

    // ── Critical columns for the login flow ───────────────────────────────────
    $login_columns = ['session_token', 'nonce', 'wallet_address', 'session_expires'];
    try {
        $cols = $pdo->query(
            "SELECT COLUMN_NAME, COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'players'
               AND COLUMN_NAME IN ('session_token', 'nonce', 'wallet_address', 'session_expires')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($login_columns as $col) {
            if (isset($cols[$col])) {
                $checks['columns']['players.' . $col] = $cols[$col];
            } else {
                $checks['columns']['players.' . $col] = 'MISSING — run db/schema.sql to add column';
            }
        }

        // Verify session_token is wide enough (token is 64 chars; schema uses VARCHAR(256))
        if (isset($cols['session_token'])) {
            preg_match('/\d+/', $cols['session_token'], $m);
            $width = (int)($m[0] ?? 0);
            if ($width < 256) {
                $checks['columns']['players.session_token'] .= ' — WARNING: too narrow (need ≥ 256); run: ALTER TABLE players MODIFY COLUMN session_token VARCHAR(256) DEFAULT NULL;';
            } else {
                $checks['columns']['players.session_token'] .= ' — ok';
            }
        }

        // Note whether session_expires is present (auth.php handles both cases gracefully)
        if (isset($cols['session_expires'])) {
            $checks['columns']['players.session_expires'] .= ' — ok (session expiry enforced)';
        } else {
            $checks['columns']['players.session_expires'] = 'not present — sessions will not expire (run db/schema.sql to add)';
        }

        // Verify nonce column is present (required for Step 1 of login)
        if (!isset($cols['nonce'])) {
            $checks['columns']['players.nonce'] = 'MISSING — nonce auth will fail; run db/schema.sql';
        } else {
            $checks['columns']['players.nonce'] = $cols['nonce'] . ' — ok';
        }

        // Verify wallet_address column is present (required player identifier)
        if (!isset($cols['wallet_address'])) {
            $checks['columns']['players.wallet_address'] = 'MISSING — player identity broken; run db/schema.sql';
        } else {
            $checks['columns']['players.wallet_address'] = $cols['wallet_address'] . ' — ok';
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

    // ── Login flow simulation ─────────────────────────────────────────────────
    // Simulates the two auth steps (nonce + verify) using a throwaway test row,
    // rolled back so no data is written. Catches the exact errors a real login
    // attempt would encounter.
    try {
        $pdo->beginTransaction();

        // Step 1 – nonce upsert (mirrors api/auth.php action:'nonce')
        $test_wallet = '11111111111111111111111111111111'; // 32-char base58 zero address
        $test_nonce  = bin2hex(random_bytes(16));
        $pdo->prepare(
            'INSERT INTO players (wallet_address, nonce) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE nonce = ?'
        )->execute([$test_wallet, $test_nonce, $test_nonce]);
        $checks['login_flow']['step1_nonce_upsert'] = 'ok';

        // Step 2a – fetch player by wallet+nonce (mirrors the SELECT in action:'verify')
        $stmt = $pdo->prepare('SELECT id FROM players WHERE wallet_address = ? AND nonce = ?');
        $stmt->execute([$test_wallet, $test_nonce]);
        $row = $stmt->fetch();
        $checks['login_flow']['step2a_fetch_by_nonce'] = $row ? 'ok' : 'FAILED: row not found after insert';

        // Step 2b – session token update (mirrors the UPDATE in action:'verify')
        if ($row) {
            $test_token  = bin2hex(random_bytes(32)); // 64-char hex
            $test_expires = date('Y-m-d H:i:s', time() + 86400);
            // Try with session_expires first (preferred schema), fall back without
            try {
                $pdo->prepare(
                    'UPDATE players SET nonce = NULL, session_token = ?, session_expires = ? WHERE id = ?'
                )->execute([$test_token, $test_expires, $row['id']]);
                $checks['login_flow']['step2b_session_update'] = 'ok (with session_expires)';
            } catch (\Throwable $e2) {
                $pdo->prepare(
                    'UPDATE players SET nonce = NULL, session_token = ? WHERE id = ?'
                )->execute([$test_token, $row['id']]);
                $checks['login_flow']['step2b_session_update'] = 'ok (without session_expires — column missing)';
            }

            // Step 2c – verify session lookup (mirrors verify_session_token())
            $stmt2 = $pdo->prepare('SELECT wallet_address FROM players WHERE session_token = ?');
            $stmt2->execute([$test_token]);
            $found = $stmt2->fetch();
            $checks['login_flow']['step2c_session_lookup'] = $found ? 'ok' : 'FAILED: session token not found after update';
        }

        $pdo->rollBack();
        $checks['login_flow']['overall'] = 'ok — all login steps succeeded (rolled back)';
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $checks['login_flow']['overall'] = 'FAILED: ' . $e->getMessage();
        $checks['login_flow']['hint']    = 'This is the likely cause of the "internal server error" on login';
    }

    // ── Game-state load simulation ────────────────────────────────────────────
    // Mirrors the SELECT queries in api/game_state.php (GET) to verify that the
    // game data endpoint can retrieve all data needed to initialise the game
    // client.  Uses the first real player row (if one exists) so this matches
    // production conditions as closely as possible.
    $real_player = $pdo->query('SELECT * FROM players WHERE session_token IS NOT NULL LIMIT 1')->fetch();
    if (!$real_player) {
        // No authenticated player exists yet – just confirm the SELECTs can run
        // with a dummy id that returns zero rows (no error means schema is fine).
        $real_player = ['id' => 0, 'level' => 1];
        $checks['game_state']['note'] = 'no authenticated player found — queries validated against empty result set';
    }
    try {
        $pid = (int)$real_player['id'];

        // Buildings query
        $pdo->prepare('SELECT * FROM buildings WHERE player_id = ? ORDER BY grid_x, grid_y')
            ->execute([$pid]);
        $checks['game_state']['buildings_query'] = 'ok';

        // Events query (LEFT JOIN with event_participants)
        $pdo->prepare(
            'SELECT e.*, ep.contribution, ep.reward_amount, ep.reward_claimed
             FROM events e
             LEFT JOIN event_participants ep ON ep.event_id = e.id AND ep.player_id = ?
             WHERE e.status IN (\'upcoming\',\'active\') AND e.min_level <= ?
             ORDER BY e.start_time'
        )->execute([$pid, (int)$real_player['level']]);
        $checks['game_state']['events_query'] = 'ok';

        // Leaderboard query
        $pdo->query(
            'SELECT wallet_address, username, level, experience, mooncoin_balance
             FROM players ORDER BY level DESC, experience DESC LIMIT 10'
        )->fetchAll();
        $checks['game_state']['leaderboard_query'] = 'ok';

        // Fuel update query (mirrors update_player_fuel SELECT)
        $pdo->prepare(
            "SELECT level FROM buildings WHERE player_id = ? AND building_type = 'fuel_plant' AND is_active = 1"
        )->execute([$pid]);
        $checks['game_state']['fuel_update_query'] = 'ok';

        $checks['game_state']['overall'] = 'ok — all game-state queries succeeded';
    } catch (\Throwable $e) {
        $checks['game_state']['overall'] = 'FAILED: ' . $e->getMessage();
        $checks['game_state']['hint']    = 'This is the likely cause of the game being stuck on "Loading Moonbase…"';
    }
}

// ── Sodium (Ed25519 signature verification) ───────────────────────────────────
if (extension_loaded('sodium')) {
    $checks['sodium']['version']     = SODIUM_LIBRARY_VERSION;
    $checks['sodium']['sign_verify'] = function_exists('sodium_crypto_sign_verify_detached') ? 'ok' : 'MISSING';

    // Functional test: generate a keypair, sign a message, verify it
    try {
        $keypair   = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $testMsg   = "Sign this message to log in to Moonbase:\n\nWallet: test\nNonce: abc123";
        $sig       = sodium_crypto_sign_detached($testMsg, $secretKey);
        $valid     = sodium_crypto_sign_verify_detached($sig, $testMsg, $publicKey);
        $checks['sodium']['functional_test'] = $valid ? 'ok — Ed25519 sign+verify works' : 'FAILED: verify returned false';

        // Also confirm a tampered message fails (sanity check)
        $invalid = sodium_crypto_sign_verify_detached($sig, $testMsg . 'tampered', $publicKey);
        $checks['sodium']['tamper_test'] = (!$invalid) ? 'ok — tampered message correctly rejected' : 'FAILED: tampered message was accepted';

        // Full roundtrip test: base58_encode → base58_decode → verify_solana_signature
        // This tests the complete authentication path the real login flow uses.
        require_once __DIR__ . '/../includes/auth.php';
        // Inline base58 encoder for the diagnostic (base58_encode is not part of the public API)
        $diag_b58_encode = static function (string $data): string {
            $alpha  = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
            $digits = [];
            foreach (array_values(unpack('C*', $data)) as $byte) {
                $carry = $byte;
                for ($j = count($digits) - 1; $j >= 0; $j--) {
                    $carry += $digits[$j] * 256;
                    $digits[$j] = $carry % 58;
                    $carry = intdiv($carry, 58);
                }
                while ($carry > 0) { array_unshift($digits, $carry % 58); $carry = intdiv($carry, 58); }
            }
            $result = '';
            foreach ($digits as $d) $result .= $alpha[$d];
            return $result;
        };
        $nonce     = bin2hex(random_bytes(8));
        $rtMsg     = "Sign this message to log in to Moonbase:\n\nWallet: test_rt\nNonce: {$nonce}";
        $rtSig     = sodium_crypto_sign_detached($rtMsg, $secretKey);
        $rtSigB58  = $diag_b58_encode($rtSig);
        $rtPubB58  = $diag_b58_encode($publicKey);
        $rtVerify  = verify_solana_signature($rtMsg, $rtSigB58, $rtPubB58);
        $checks['sodium']['base58_roundtrip_test'] = $rtVerify
            ? 'ok — base58 encode→decode→verify roundtrip works'
            : 'FAILED: base58 roundtrip verify returned false';
    } catch (\Throwable $e) {
        $checks['sodium']['functional_test'] = 'FAILED: ' . $e->getMessage();
    }
} else {
    $checks['sodium']['status'] = 'MISSING — Ed25519 signature verification will fail; install php-sodium';
}

// ── Session token format ──────────────────────────────────────────────────────
// Verify that create_session_token() output satisfies the regex used by verify_session_token()
try {
    require_once __DIR__ . '/../includes/auth.php';
    $sample_token = create_session_token('test_wallet');
    $regex_ok = (bool)preg_match('/^[0-9a-f]{64}$/', $sample_token);
    $checks['session_token_format']['length']  = strlen($sample_token);
    $checks['session_token_format']['pattern'] = $regex_ok ? 'ok — matches /^[0-9a-f]{64}$/' : 'FAILED: token does not match expected pattern';
} catch (\Throwable $e) {
    $checks['session_token_format']['error'] = 'FAILED: ' . $e->getMessage();
}

// ── Overall status ────────────────────────────────────────────────────────────
// Separate functional failures (MISSING/FAILED – game will not work) from
// security advisories (WARNING – game works but hardening is recommended).
$has_error   = false;
$has_warning = false;
array_walk_recursive($checks, function ($v) use (&$has_error, &$has_warning) {
    if (is_string($v) && (str_starts_with($v, 'MISSING') || str_starts_with($v, 'FAILED'))) {
        $has_error = true;
    } elseif (is_string($v) && str_contains($v, 'WARNING')) {
        $has_warning = true;
    }
});

if ($has_error) {
    $checks['overall'] = 'ISSUES FOUND – one or more functional checks failed; review above';
} elseif ($has_warning) {
    $checks['overall'] = 'WARNINGS FOUND – game is functional but security hardening is recommended; review above';
} else {
    $checks['overall'] = 'all checks passed';
}

http_response_code($has_error ? 503 : 200);
echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
