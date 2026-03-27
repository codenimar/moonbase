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
    $bytes = [0];
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

// ── Nonce generation ──────────────────────────────────────────────────────────
function generate_nonce(): string {
    return bin2hex(random_bytes(16));
}

// ── Ed25519 signature verification ───────────────────────────────────────────
/**
 * Verify a Solana wallet sign-message response.
 *
 * Phantom encodes the signature in base58 and provides the raw bytes;
 * we accept both base58 and base64 signatures.
 *
 * @param string $message       The plaintext message that was signed
 * @param string $signature_b58 Base58-encoded signature (64 bytes)
 * @param string $pubkey_b58    Base58-encoded public key (32 bytes)
 */
function verify_solana_signature(string $message, string $signature_b58, string $pubkey_b58): bool {
    try {
        $sig_bytes    = base58_decode($signature_b58);
        $pubkey_bytes = base58_decode($pubkey_b58);
        if (strlen($sig_bytes) !== 64 || strlen($pubkey_bytes) !== 32) return false;
        return sodium_crypto_sign_verify_detached($sig_bytes, $message, $pubkey_bytes);
    } catch (\Throwable $e) {
        return false;
    }
}

// ── Session token (HMAC-based) ────────────────────────────────────────────────
function create_session_token(string $wallet_address): string {
    $payload = $wallet_address . '|' . time() . '|' . bin2hex(random_bytes(8));
    return hash_hmac('sha256', $payload, SESSION_SECRET) . '.' . base64_encode($payload);
}

function verify_session_token(string $token): ?string {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;
    [$mac, $b64] = $parts;
    $payload = base64_decode($b64);
    if (!$payload) return null;
    $expected = hash_hmac('sha256', $payload, SESSION_SECRET);
    if (!hash_equals($expected, $mac)) return null;
    $bits = explode('|', $payload);
    if (count($bits) < 3) return null;
    return $bits[0]; // wallet address
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
    if ($row['session_expires'] && strtotime($row['session_expires']) < time()) {
        http_response_code(401);
        echo json_encode(['error' => 'Session expired']);
        exit;
    }
    return $row;
}

// ── Validate wallet address format ───────────────────────────────────────────
function is_valid_solana_address(string $address): bool {
    return (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address);
}
