<?php
/**
 * API: Marketplace
 * GET  /api/market.php                         – list active listings
 * POST /api/market.php { action: 'list',   resource_type, amount, price_per_unit }
 * POST /api/market.php { action: 'buy',    listing_id }
 * POST /api/market.php { action: 'cancel', listing_id }
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
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$db     = get_db();

if ($method === 'GET') {
    $type   = $_GET['resource_type'] ?? null;
    $where  = "status = 'active'";
    $params = [];
    if ($type && in_array($type, ['fuel','minerals','metal'], true)) {
        $where .= ' AND resource_type = ?';
        $params[] = $type;
    }
    $rows = $db->prepare(
        "SELECT ml.*, p.wallet_address, p.username
         FROM market_listings ml
         JOIN players p ON p.id = ml.seller_id
         WHERE {$where}
         ORDER BY price_per_unit ASC, created_at ASC
         LIMIT 100"
    );
    $rows->execute($params);
    api_response(['listings' => $rows->fetchAll()]);
}

if ($method === 'POST') {
    $action = $input['action'] ?? '';

    // ── Create listing ────────────────────────────────────────────────────────
    if ($action === 'list') {
        // Must have a Marketplace building
        $has_market = $db->prepare(
            "SELECT COUNT(*) FROM buildings WHERE player_id = ? AND building_type = 'market' AND is_active = 1"
        );
        $has_market->execute([$player['id']]);
        if (!$has_market->fetchColumn()) api_error('You need a Marketplace building to trade');

        $resource = $input['resource_type'] ?? '';
        $amount   = (float)($input['amount'] ?? 0);
        $price    = (float)($input['price_per_unit'] ?? 0);

        if (!in_array($resource, ['fuel','minerals','metal'], true)) api_error('Invalid resource type');
        if ($amount <= 0)  api_error('Amount must be positive');
        if ($price  <= 0)  api_error('Price must be positive');
        if ($amount > (float)$player[$resource]) api_error("Not enough {$resource}");

        // Lock the resource
        $db->prepare("UPDATE players SET {$resource} = {$resource} - ? WHERE id = ?")
           ->execute([$amount, $player['id']]);

        $db->prepare(
            'INSERT INTO market_listings (seller_id, resource_type, amount, price_per_unit) VALUES (?,?,?,?)'
        )->execute([$player['id'], $resource, $amount, $price]);

        api_response(['success' => true, 'listing_id' => $db->lastInsertId()]);
    }

    // ── Buy listing ───────────────────────────────────────────────────────────
    if ($action === 'buy') {
        $listing_id = (int)($input['listing_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM market_listings WHERE id = ? AND status = 'active'");
        $stmt->execute([$listing_id]);
        $listing = $stmt->fetch();
        if (!$listing) api_error('Listing not found or no longer active', 404);
        if ($listing['seller_id'] == $player['id']) api_error('Cannot buy your own listing');

        $total   = (float)$listing['amount'] * (float)$listing['price_per_unit'];
        $fee     = round($total * (MARKET_FEE_PCT / 100.0), 4);
        $net     = $total - $fee;

        if ((float)$player['mooncoin_balance'] < $total) api_error('Not enough MoonCoins');

        $db->beginTransaction();
        try {
            // Deduct from buyer
            $db->prepare('UPDATE players SET mooncoin_balance = mooncoin_balance - ? WHERE id = ?')
               ->execute([$total, $player['id']]);

            // Credit seller (minus fee; fee stays in game pool)
            $db->prepare('UPDATE players SET mooncoin_balance = mooncoin_balance + ? WHERE id = ?')
               ->execute([$net, $listing['seller_id']]);

            // Give resource to buyer (cap at storage)
            $resource  = $listing['resource_type'];
            $cap_col   = $resource . '_storage_cap';
            $new_res   = min(
                (float)$player[$resource] + (float)$listing['amount'],
                (float)$player[$cap_col]
            );
            $db->prepare("UPDATE players SET {$resource} = ? WHERE id = ?")
               ->execute([$new_res, $player['id']]);

            // Mark listing sold
            $db->prepare(
                "UPDATE market_listings SET status = 'sold', buyer_id = ?, sold_at = NOW() WHERE id = ?"
            )->execute([$player['id'], $listing_id]);

            // Log transaction
            $db->prepare(
                'INSERT INTO market_transactions
                 (listing_id, seller_id, buyer_id, resource_type, amount, total_price, fee, seller_receives)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $listing_id,
                $listing['seller_id'],
                $player['id'],
                $resource,
                $listing['amount'],
                $total,
                $fee,
                $net,
            ]);

            $db->commit();
            add_experience($player['id'], 5);
            api_response(['success' => true, 'fee_paid' => $fee]);
        } catch (\Exception $e) {
            $db->rollBack();
            api_error('Transaction failed: ' . (DEBUG ? $e->getMessage() : 'server error'), 500);
        }
    }

    // ── Cancel listing ────────────────────────────────────────────────────────
    if ($action === 'cancel') {
        $listing_id = (int)($input['listing_id'] ?? 0);
        $stmt = $db->prepare(
            "SELECT * FROM market_listings WHERE id = ? AND seller_id = ? AND status = 'active'"
        );
        $stmt->execute([$listing_id, $player['id']]);
        $listing = $stmt->fetch();
        if (!$listing) api_error('Listing not found', 404);

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE market_listings SET status = 'cancelled' WHERE id = ?")
               ->execute([$listing_id]);
            // Return resource to seller
            $resource = $listing['resource_type'];
            $db->prepare("UPDATE players SET {$resource} = {$resource} + ? WHERE id = ?")
               ->execute([$listing['amount'], $player['id']]);
            $db->commit();
            api_response(['success' => true]);
        } catch (\Exception $e) {
            $db->rollBack();
            api_error('Cancellation failed');
        }
    }

    api_error('Unknown action');
}
