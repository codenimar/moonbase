<?php
header('Content-Type: application/json');

// Error handling (same as the other API files)
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

$player = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$db     = get_db();

if ($method === 'GET') {
    // Update accumulated fuel first
    update_player_fuel($player);

    // Reload fresh data
    $stmt = $db->prepare('SELECT * FROM players WHERE id = ?');
    $stmt->execute([$player['id']]);
    $player = $stmt->fetch();

    // Load buildings
    $bstmt = $db->prepare('SELECT * FROM buildings WHERE player_id = ? ORDER BY grid_x, grid_y');
    $bstmt->execute([$player['id']]);
    $buildings = $bstmt->fetchAll();

    // Load active events (player eligible)
    $evts = $db->prepare(
        'SELECT e.*, ep.contribution, ep.reward_amount, ep.reward_claimed
         FROM events e
         LEFT JOIN event_participants ep ON ep.event_id = e.id AND ep.player_id = ?
         WHERE e.status IN (\'upcoming\',\'active\') AND e.min_level <= ?
         ORDER BY e.start_time'
    );
    $evts->execute([$player['id'], $player['level']]);
    $events = $evts->fetchAll();

    // Load leaderboard snippet (top 10 by level/xp)
    $lb = $db->query(
        'SELECT wallet_address, username, level, experience, mooncoin_balance
         FROM players ORDER BY level DESC, experience DESC LIMIT 10'
    )->fetchAll();

    api_response([
        'player'    => sanitize_player($player),
        'buildings' => $buildings,
        'events'    => $events,
        'leaderboard' => $lb,
        'building_defs' => building_definitions(),
    ]);
}

// POST: collect resources from a building
if ($method === 'POST') {
    $action = $input['action'] ?? '';

    if ($action === 'collect') {
        $building_id = (int)($input['building_id'] ?? 0);
        $bstmt = $db->prepare('SELECT * FROM buildings WHERE id = ? AND player_id = ?');
        $bstmt->execute([$building_id, $player['id']]);
        $building = $bstmt->fetch();
        if (!$building) api_error('Building not found', 404);

        $defs = building_definitions();
        $type = $building['building_type'];
        $lvl  = (int)$building['level'];

        if (!isset($defs[$type]['produces'])) {
            api_error('This building does not produce collectible resources');
        }

        $resource    = $defs[$type]['produces'];
        $rate        = $defs[$type]['levels'][$lvl]['rate']; // per minute
        $elapsed     = max(0, time() - strtotime($building['last_collected']));
        $produced    = ($rate / 60.0) * $elapsed;

        if ($produced < 0.01) api_error('Nothing to collect yet');

        // Update player resource (cap at storage limit)
        // Map resource names to their storage-cap column names explicitly.
        // The minerals cap column is 'mineral_storage_cap' (singular), not
        // 'minerals_storage_cap', so string concatenation would be wrong.
        $cap_col_map = ['fuel' => 'fuel_storage_cap', 'minerals' => 'mineral_storage_cap', 'metal' => 'metal_storage_cap'];
        if (!isset($cap_col_map[$resource])) {
            api_error('Unknown resource type');
        }
        $cap_col = $cap_col_map[$resource];
        $res_col = $resource;
        $new_res = min(
            (float)$player[$res_col] + $produced,
            (float)$player[$cap_col]
        );

        $db->prepare("UPDATE players SET {$res_col} = ? WHERE id = ?")
           ->execute([$new_res, $player['id']]);
        $db->prepare('UPDATE buildings SET last_collected = NOW() WHERE id = ?')
           ->execute([$building_id]);

        add_experience($player['id'], 1);

        api_response([
            'success'   => true,
            'collected' => round($produced, 2),
            'resource'  => $resource,
            'new_total' => round($new_res, 2),
        ]);
    }

    if ($action === 'set_username') {
        $username = trim($input['username'] ?? '');
        if (strlen($username) < 3 || strlen($username) > 32) {
            api_error('Username must be 3–32 characters');
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
            api_error('Username may only contain letters, numbers, underscores and hyphens');
        }
        try {
            $db->prepare('UPDATE players SET username = ? WHERE id = ?')
               ->execute([$username, $player['id']]);
            api_response(['success' => true, 'username' => $username]);
        } catch (\PDOException $e) {
            api_error('Username already taken');
        }
    }

    api_error('Unknown action');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function sanitize_player(array $p): array {
    unset($p['nonce'], $p['session_token'], $p['session_expires']);
    return $p;
}
