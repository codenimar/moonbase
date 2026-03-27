<?php
/**
 * API: Buildings
 * POST /api/buildings.php
 *   { action: 'place',   building_type, grid_x, grid_y }
 *   { action: 'upgrade', building_id }
 *   { action: 'remove',  building_id }
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
$defs   = building_definitions();

switch ($action) {

    case 'place': {
        $type  = $input['building_type'] ?? '';
        $gx    = (int)($input['grid_x'] ?? -1);
        $gy    = (int)($input['grid_y'] ?? -1);

        if (!isset($defs[$type])) api_error('Unknown building type');
        $def = $defs[$type];

        // Level requirement
        if ($player['level'] < $def['requires_level']) {
            api_error("Requires player level {$def['requires_level']}");
        }

        // Unique check
        if ($def['unique']) {
            $u = $db->prepare('SELECT id FROM buildings WHERE player_id = ? AND building_type = ?');
            $u->execute([$player['id'], $type]);
            if ($u->fetch()) api_error('You can only have one ' . $def['name']);
        }

        // Grid bounds
        [$sw, $sh] = $def['size'];
        if ($gx < 0 || $gy < 0 || $gx + $sw > GRID_COLS || $gy + $sh > GRID_ROWS) {
            api_error('Building does not fit at this position');
        }

        // Collision check (simple: top-left tile only for DB unique key)
        // (A more robust check would verify all occupied tiles)
        $cost = $def['levels'][1]['cost'];

        // Deduct costs
        if ($player['fuel']     < $cost['fuel'])     api_error('Not enough fuel');
        if ($player['mooncoin_balance'] < $cost['mooncoin']) api_error('Not enough MoonCoins');
        $minerals = (float)$player['minerals'];
        if ($minerals < ($cost['minerals'] ?? 0)) api_error('Not enough minerals');

        $db->prepare(
            'UPDATE players SET fuel = fuel - ?, mooncoin_balance = mooncoin_balance - ?, minerals = minerals - ? WHERE id = ?'
        )->execute([$cost['fuel'], $cost['mooncoin'], $cost['minerals'] ?? 0, $player['id']]);

        $db->prepare(
            'INSERT INTO buildings (player_id, building_type, level, grid_x, grid_y) VALUES (?,?,1,?,?)'
        )->execute([$player['id'], $type, $gx, $gy]);

        $new_id = $db->lastInsertId();
        add_experience($player['id'], 10);

        api_response(['success' => true, 'building_id' => $new_id]);
        break;
    }

    case 'upgrade': {
        $building_id = (int)($input['building_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM buildings WHERE id = ? AND player_id = ?');
        $stmt->execute([$building_id, $player['id']]);
        $building = $stmt->fetch();
        if (!$building) api_error('Building not found', 404);

        $type = $building['building_type'];
        $def  = $defs[$type];
        $cur  = (int)$building['level'];
        $max  = $def['max_level'];

        if ($cur >= $max) api_error('Building is already at maximum level');
        if ($building['is_upgrading']) api_error('Building is already upgrading');

        $next_lvl = $cur + 1;
        $cost     = $def['levels'][$next_lvl]['cost'];
        $time_s   = $def['levels'][$next_lvl]['build_time'];

        if ($player['fuel']             < $cost['fuel'])     api_error('Not enough fuel');
        if ($player['mooncoin_balance'] < $cost['mooncoin']) api_error('Not enough MoonCoins');
        if ((float)$player['minerals']  < ($cost['minerals'] ?? 0)) api_error('Not enough minerals');

        $db->prepare(
            'UPDATE players SET fuel = fuel - ?, mooncoin_balance = mooncoin_balance - ?, minerals = minerals - ? WHERE id = ?'
        )->execute([$cost['fuel'], $cost['mooncoin'], $cost['minerals'] ?? 0, $player['id']]);

        $finish = date('Y-m-d H:i:s', time() + $time_s);
        $db->prepare(
            'UPDATE buildings SET is_upgrading = 1, upgrade_finish = ? WHERE id = ?'
        )->execute([$finish, $building_id]);

        add_experience($player['id'], 50);

        api_response(['success' => true, 'upgrade_finish' => $finish, 'new_level' => $next_lvl]);
        break;
    }

    case 'finish_upgrade': {
        // Check and apply finished upgrades
        $done = $db->prepare(
            'SELECT * FROM buildings WHERE player_id = ? AND is_upgrading = 1 AND upgrade_finish <= NOW()'
        );
        $done->execute([$player['id']]);
        $finished = $done->fetchAll();
        $updated  = [];
        foreach ($finished as $b) {
            $db->prepare(
                'UPDATE buildings SET level = level + 1, is_upgrading = 0, upgrade_finish = NULL WHERE id = ?'
            )->execute([$b['id']]);
            $updated[] = ['id' => $b['id'], 'new_level' => $b['level'] + 1];
        }

        // Recalculate storage cap from all storage buildings
        recalculate_storage_caps($player['id'], $db, $defs);

        api_response(['success' => true, 'finished' => $updated]);
        break;
    }

    case 'remove': {
        $building_id = (int)($input['building_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM buildings WHERE id = ? AND player_id = ?');
        $stmt->execute([$building_id, $player['id']]);
        $building = $stmt->fetch();
        if (!$building) api_error('Building not found', 404);
        if ($building['building_type'] === 'command_center') api_error('Cannot remove Command Center');

        $db->prepare('DELETE FROM buildings WHERE id = ?')->execute([$building_id]);
        recalculate_storage_caps($player['id'], $db, $defs);
        api_response(['success' => true]);
        break;
    }

    default:
        api_error('Unknown action');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function recalculate_storage_caps(int $player_id, PDO $db, array $defs): void {
    $base = 500.0;
    $stmt = $db->prepare(
        "SELECT level FROM buildings WHERE player_id = ? AND building_type = 'storage' AND is_active = 1"
    );
    $stmt->execute([$player_id]);
    $bonus = 0.0;
    foreach ($stmt->fetchAll() as $s) {
        $lvl    = (int)$s['level'];
        $bonus += $defs['storage']['levels'][$lvl]['capacity_bonus'] ?? 0;
    }
    $cap = $base + $bonus;
    $db->prepare(
        'UPDATE players SET fuel_storage_cap = ?, mineral_storage_cap = ?, metal_storage_cap = ? WHERE id = ?'
    )->execute([$cap, $cap, $cap, $player_id]);
}
