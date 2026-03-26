#!/usr/bin/env php
<?php
/**
 * Cron job: Daily token balance check for all players
 *
 * Run from crontab every 15 minutes:
 *   (asterisk)/15 * * * * php /path/to/moonbase/scripts/cron_token_check.php >> /var/log/moonbase_cron.log 2>&1
 *   (Replace "(asterisk)" with * in your crontab — the slash-star sequence closes a PHP block comment)
 *
 * This script processes players whose next_token_check time has passed.
 * The next check window is set to a random time 23–25 hours later to prevent
 * players from gaming the system by moving tokens around wallet-to-wallet.
 */
require_once __DIR__ . '/../includes/game_helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$db = get_db();

// Find players whose scheduled check time has passed
$stmt = $db->query(
    'SELECT id, wallet_address, token_balance, fuel_rate_bonus
     FROM players
     WHERE next_token_check <= NOW()
     LIMIT 100'
);
$players = $stmt->fetchAll();

if (empty($players)) {
    echo date('c') . " No players to check.\n";
    exit(0);
}

echo date('c') . " Processing " . count($players) . " players...\n";

foreach ($players as $p) {
    try {
        $balance = fetch_token_balance($p['wallet_address']);
        $bonus   = calculate_token_bonus($balance);

        // Next check: random window 23–25 hours from now
        $next = date('Y-m-d H:i:s', time() + rand(82800, 90000));

        $db->prepare(
            'UPDATE players SET token_balance = ?, fuel_rate_bonus = ?, last_token_check = NOW(), next_token_check = ? WHERE id = ?'
        )->execute([$balance, $bonus, $next, $p['id']]);

        echo date('c') . " [{$p['wallet_address']}] balance={$balance} bonus={$bonus}\n";
    } catch (\Exception $e) {
        echo date('c') . " [{$p['wallet_address']}] ERROR: " . $e->getMessage() . "\n";
    }

    // Be polite to the RPC endpoint
    usleep(200_000); // 200ms between requests
}

echo date('c') . " Done.\n";

function fetch_token_balance(string $wallet_address): float {
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
