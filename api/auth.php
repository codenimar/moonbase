<?php
/**
 * API: Wallet Authentication
 * POST /api/auth.php  { action: 'nonce', wallet }
 *                     { action: 'verify', wallet, signature, nonce }
 * POST /api/auth.php  { action: 'logout' }
 */
header('Content-Type: application/json');

set_exception_handler(function (\Throwable $e) {
    http_response_code(500);
    error_log('Moonbase API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $msg = 'An internal server error occurred. Please try again.';
    if (defined('DEBUG') && DEBUG) {
        $msg .= ' [Debug: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
    }
    echo json_encode(['error' => $msg]);
    exit;
});

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {

    // ── Step 1: request a nonce ───────────────────────────────────────────────
    case 'nonce': {
        $wallet = trim($input['wallet'] ?? '');
        if (!is_valid_solana_address($wallet)) {
            api_error('Invalid wallet address');
        }
        $nonce = generate_nonce();
        $db    = get_db();

        // Upsert player row (creates if not exists)
        $db->prepare(
            'INSERT INTO players (wallet_address, nonce) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE nonce = ?'
        )->execute([$wallet, $nonce, $nonce]);

        api_response([
            'nonce'   => $nonce,
            'message' => "Sign this message to log in to Moonbase:\n\nWallet: {$wallet}\nNonce: {$nonce}",
        ]);
        break;
    }

    // ── Step 2: verify signature ──────────────────────────────────────────────
    case 'verify': {
        $wallet    = trim($input['wallet']    ?? '');
        $signature = trim($input['signature'] ?? '');
        $nonce     = trim($input['nonce']     ?? '');

        if (!is_valid_solana_address($wallet) || !$signature || !$nonce) {
            api_error('Missing parameters');
        }

        $db   = get_db();
        $stmt = $db->prepare('SELECT * FROM players WHERE wallet_address = ? AND nonce = ?');
        $stmt->execute([$wallet, $nonce]);
        $player = $stmt->fetch();

        if (!$player) {
            api_error('Nonce mismatch or player not found', 401);
        }

        $message = "Sign this message to log in to Moonbase:\n\nWallet: {$wallet}\nNonce: {$nonce}";

        if (!verify_solana_signature($message, $signature, $wallet)) {
            api_error('Signature verification failed', 401);
        }

        // Invalidate nonce, create session
        $token   = create_session_token($wallet);
        $expires = date('Y-m-d H:i:s', time() + SESSION_TTL);

        $sql = has_session_expires_column()
            ? 'UPDATE players SET nonce = NULL, session_token = ?, session_expires = ? WHERE id = ?'
            : 'UPDATE players SET nonce = NULL, session_token = ? WHERE id = ?';

        $params = has_session_expires_column()
            ? [$token, $expires, $player['id']]
            : [$token, $player['id']];

        $db->prepare($sql)->execute($params);

        // Set cookie
        setcookie(
            'moonbase_session', $token,
            ['expires' => time() + SESSION_TTL, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']
        );

        // Check if new player (needs initial command center)
        $buildings = $db->prepare('SELECT COUNT(*) as cnt FROM buildings WHERE player_id = ?');
        $buildings->execute([$player['id']]);
        $is_new = (int)$buildings->fetch()['cnt'] === 0;

        if ($is_new) {
            // Place initial command center at grid center
            $cx = (int)(GRID_COLS / 2) - 1;
            $cy = (int)(GRID_ROWS / 2) - 1;
            $db->prepare(
                'INSERT INTO buildings (player_id, building_type, level, grid_x, grid_y)
                 VALUES (?, ?, 1, ?, ?)'
            )->execute([$player['id'], 'command_center', $cx, $cy]);
        }

        api_response([
            'success'      => true,
            'session_token' => $token,
            'is_new_player' => $is_new,
            'wallet'       => $wallet,
        ]);
        break;
    }

    // ── Logout ────────────────────────────────────────────────────────────────
    case 'logout': {
        $player = require_auth();
        $db     = get_db();
        if (has_session_expires_column()) {
            $db->prepare('UPDATE players SET session_token = NULL, session_expires = NULL WHERE id = ?')
               ->execute([$player['id']]);
        } else {
            $db->prepare('UPDATE players SET session_token = NULL WHERE id = ?')
               ->execute([$player['id']]);
        }
        setcookie('moonbase_session', '', ['expires' => time() - 3600, 'path' => '/']);
        api_response(['success' => true]);
        break;
    }

    default:
        api_error('Unknown action');
}
