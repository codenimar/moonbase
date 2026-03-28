<?php
/**
 * Authentication helpers – Solana wallet signature verification
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';

// ── Base58 ────────────────────────────────────────────────────────────────────
function base58_decode(string $input): string {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $base = strlen($alphabet);
    $bytes = [];
    for ($i = 0; $i < strlen($input); $i++) {
        $c = strpos($alphabet, $input[$i]);
        if ($c === false) throw new \InvalidArgumentException('Invalid base58 character');
        $carry = $c;
        for ($j = count($bytes) - 1; $j >= 0; $j--) {
            $carry += $base * $bytes[$j];
            $bytes[$j] = $carry & 0xFF;
            $carry >>= 8;
        }
        while ($carry > 0) {
            array_unshift($bytes, $carry & 0xFF);
            $carry >>= 8;
        }
    }
    // Add leading zero bytes for leading '1's
    for ($i = 0; $i < strlen($input) && $input[$i] === '1'; $i++) {
        array_unshift($bytes, 0);
    }
    return implode('', array_map('chr', $bytes));
}

// ── Schema helpers ────────────────────────────────────────────────────────────
function has_session_expires_column(): bool {
    static $hasColumn = null;
    // Note: static cache is request-scoped under PHP-FPM; if the schema is
    // changed at runtime, the next request will re-run this check.
    if ($hasColumn !== null) return $hasColumn;
    try {
        $db = get_db();
        // Lightweight existence check that works without INFORMATION_SCHEMA privileges
        $db->query('SELECT session_expires FROM players LIMIT 0');
        $hasColumn = true;
    } catch (\Throwable $e) {
        $hasColumn = false;
    }
    return $hasColumn;
}

// ── Nonce generation ──────────────────────────────────────────────────────────
function generate_nonce(): string {
    return bin2hex(random_bytes(16));
}

// ── Ed25519 signature verification ───────────────────────────────────────────
/**
 * Verify a Solana wallet sign-message response.
 *
 * @param string $message       The plaintext message that was signed
 * @param string $signature_b58 Base58-encoded Ed25519 signature (64 bytes)
 * @param string $pubkey_b58    Base58-encoded public key / wallet address (32 bytes)
 */
function verify_solana_signature(string $message, string $signature_b58, string $pubkey_b58): bool {
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        throw new \RuntimeException(
            'php-sodium extension is required for wallet authentication. ' .
            'Install it with: apt-get install php-sodium  (or the equivalent for your OS).'
        );
    }
    try {
        $sig_bytes    = base58_decode($signature_b58);
        $pubkey_bytes = base58_decode($pubkey_b58);
        if (strlen($sig_bytes) !== 64 || strlen($pubkey_bytes) !== 32) return false;
        return sodium_crypto_sign_verify_detached($sig_bytes, $message, $pubkey_bytes);
    } catch (\Throwable $e) {
        return false;
    }
}

// ── Session token (random, opaque) ───────────────────────────────────────────
function create_session_token(string $wallet_address): string {
    // 64-char random hex string – fits in VARCHAR(64) and never overflows the column.
    // The wallet address is NOT embedded in the token; it is retrieved via DB lookup.
    return bin2hex(random_bytes(32));
}

function verify_session_token(string $token): ?string {
    // Basic format check before hitting the DB.
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    $db   = get_db();
    $hasExpiry = has_session_expires_column();
    $sql  = 'SELECT wallet_address FROM players WHERE session_token = ?';
    $params = [$token];
    if ($hasExpiry) {
        $sql .= ' AND (session_expires IS NULL OR session_expires > NOW())';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ? $row['wallet_address'] : null;
}

// ── Require authenticated player ─────────────────────────────────────────────
function require_auth(): array {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN']
          ?? ($_COOKIE['moonbase_session'] ?? null);
    if (!$token) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthenticated']);
        exit;
    }
    // verify_session_token does the DB lookup and expiry check in one query.
    $wallet = verify_session_token($token);
    if (!$wallet) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired session']);
        exit;
    }
    $db     = get_db();
    $player = $db->prepare('SELECT * FROM players WHERE wallet_address = ? AND session_token = ?');
    $player->execute([$wallet, $token]);
    $row = $player->fetch();
    if (!$row) {
        http_response_code(401);
        echo json_encode(['error' => 'Session not found']);
        exit;
    }
    return $row;
}

// ── Validate wallet address format ───────────────────────────────────────────
function is_valid_solana_address(string $address): bool {
    return (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address);
}
