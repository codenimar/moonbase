<?php
/**
 * API: Wallet / Token balance
 * POST /api/wallet.php { action: 'check_balance' }     – query $PUMPVILLE balance
 * POST /api/wallet.php { action: 'get_status' }        – return cached balances
 * POST /api/wallet.php { action: 'bridge_mooncoin', amount } – queue on-chain bridge
 */
header('Content-Type: application/json');

set_exception_handler(function (\Throwable $e) {
    http_response_code(500);
    error_log('Moonbase API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['error' => 'An internal server error occurred. Please try again.']);
    exit;
});

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

    $balance = fetch_token_balance($player['wallet_address'], TOKEN_MINT_ADDRESS);
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
    // Also return on-chain MoonCoin balance if mint is configured
    $mc_onchain = 0.0;
    if (MOONCOIN_MINT_ADDRESS !== '') {
        $mc_onchain = fetch_token_balance($player['wallet_address'], MOONCOIN_MINT_ADDRESS);
    }
    api_response([
        'wallet_address'    => $player['wallet_address'],
        'token_balance'     => (float)$player['token_balance'],
        'fuel_rate_bonus'   => (float)$player['fuel_rate_bonus'],
        'mooncoin_balance'  => (float)$player['mooncoin_balance'],
        'mooncoin_onchain'  => $mc_onchain,
        'last_checked'      => $player['last_token_check'],
    ]);
}

// ── Bridge in-game MoonCoins to on-chain SPL ─────────────────────────────────
if ($action === 'bridge_mooncoin') {
    $amount = (float)($input['amount'] ?? 0);
    if ($amount < 100) api_error('Minimum bridge amount is 100 MoonCoins');
    if ((float)$player['mooncoin_balance'] < $amount) api_error('Insufficient MoonCoin balance');

    $fee_pct   = MOONCOIN_BRIDGE_FEE_PCT / 100.0;
    $fee       = round($amount * $fee_pct, 4);
    $after_fee = round($amount - $fee, 4);

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE players SET mooncoin_balance = mooncoin_balance - ? WHERE id = ?')
           ->execute([$amount, $player['id']]);

        // Log the bridge request (processed by cron / admin once token is live)
        // Use NOW() from DB to avoid PHP/MySQL timezone inconsistencies
        $db->prepare(
            "INSERT INTO activity_log (player_id, action, details)
             SELECT ?, 'mooncoin_bridge_request', JSON_SET(?, '$.requested_at', DATE_FORMAT(NOW(),'%Y-%m-%dT%T'))"
        )->execute([$player['id'], json_encode([
            'amount'    => $amount,
            'fee'       => $fee,
            'after_fee' => $after_fee,
            'wallet'    => $player['wallet_address'],
            'status'    => 'pending',
        ])]);

        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        api_error('Bridge request failed');
    }

    api_response([
        'success'   => true,
        'amount'    => $amount,
        'fee'       => $fee,
        'after_fee' => $after_fee,
        'wallet'    => $player['wallet_address'],
        'note'      => 'Bridge request queued. Tokens will be airdropped once the MoonCoin SPL token launches.',
    ]);
}

api_error('Unknown action');

// ── Solana RPC balance query ───────────────────────────────────────────────────
function fetch_token_balance(string $wallet_address, string $mint_address): float {
    if (!$mint_address) return 0.0;

    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'getTokenAccountsByOwner',
        'params'  => [
            $wallet_address,
            ['mint' => $mint_address],
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

    $data     = json_decode($result, true);
    $accounts = $data['result']['value'] ?? [];
    $total    = 0.0;
    foreach ($accounts as $acc) {
        $ui = $acc['account']['data']['parsed']['info']['tokenAmount']['uiAmount'] ?? 0;
        $total += (float)$ui;
    }
    return $total;
}

