<?php
/**
 * API: Wallet / Token balance check
 * POST /api/wallet.php { action: 'check_balance' }
 *
 * Queries the Solana RPC for the player's $PUMPVILLE SPL token balance.
 * Updates the player's fuel_rate_bonus based on token tiers.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/game_helpers.php';

$player = require_auth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';
$db     = get_db();

if ($action === 'check_balance') {
    // Rate-limit: on-demand checks, at most once per hour per player
    $last = strtotime($player['last_token_check']);
    if (time() - $last < 3600) {
        api_response([
            'token_balance'    => (float)$player['token_balance'],
            'fuel_rate_bonus'  => (float)$player['fuel_rate_bonus'],
            'cached'           => true,
            'next_check_in'    => 3600 - (time() - $last),
        ]);
    }

    $balance = fetch_token_balance($player['wallet_address']);
    $bonus   = calculate_token_bonus($balance);

    // Schedule next daily check at a random time to prevent gaming
    $next = date('Y-m-d H:i:s', time() + rand(82800, 90000)); // 23–25 hours

    $db->prepare(
        'UPDATE players SET token_balance = ?, fuel_rate_bonus = ?, last_token_check = NOW(), next_token_check = ? WHERE id = ?'
    )->execute([$balance, $bonus, $next, $player['id']]);

    api_response([
        'token_balance'   => $balance,
        'fuel_rate_bonus' => $bonus,
        'cached'          => false,
    ]);
}

if ($action === 'get_status') {
    api_response([
        'wallet_address'  => $player['wallet_address'],
        'token_balance'   => (float)$player['token_balance'],
        'fuel_rate_bonus' => (float)$player['fuel_rate_bonus'],
        'last_checked'    => $player['last_token_check'],
    ]);
}

api_error('Unknown action');

// ── Solana RPC balance query ───────────────────────────────────────────────────
function fetch_token_balance(string $wallet_address): float {
    // getTokenAccountsByOwner: find token accounts for this mint
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'getTokenAccountsByOwner',
        'params'  => [
            $wallet_address,
            ['mint' => TOKEN_MINT_ADDRESS],
            ['encoding' => 'jsonParsed'],
        ],
    ]);

    $ch = curl_init(SOLANA_RPC_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $result = curl_exec($ch);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err || !$result) return 0.0;

    $data = json_decode($result, true);
    $accounts = $data['result']['value'] ?? [];
    $total = 0.0;
    foreach ($accounts as $acc) {
        $ui = $acc['account']['data']['parsed']['info']['tokenAmount']['uiAmount'] ?? 0;
        $total += (float)$ui;
    }
    return $total;
}
