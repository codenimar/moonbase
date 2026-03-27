<?php
/**
 * API: PvP Raids
 * GET  /api/raids.php                          – list my raids (incoming + outgoing)
 * POST /api/raids.php { action: 'initiate',   target_wallet }  – start a raid
 * POST /api/raids.php { action: 'resolve_all' }                 – resolve pending raids (cron / player-triggered)
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

// ── GET: list raids ────────────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $db->prepare(
        'SELECT r.*,
                a.wallet_address AS attacker_wallet, a.username AS attacker_name,
                d.wallet_address AS defender_wallet, d.username AS defender_name
         FROM pvp_raids r
         JOIN players a ON a.id = r.attacker_id
         JOIN players d ON d.id = r.defender_id
         WHERE r.attacker_id = ? OR r.defender_id = ?
         ORDER BY r.started_at DESC LIMIT 30'
    );
    $stmt->execute([$player['id'], $player['id']]);
    api_response(['raids' => $stmt->fetchAll()]);
}

// ── POST ───────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $action = $input['action'] ?? '';

    // ── Initiate raid ──────────────────────────────────────────────────────
    if ($action === 'initiate') {
        $target_wallet = trim($input['target_wallet'] ?? '');
        if (!$target_wallet) api_error('target_wallet required');

        // Must have level >= 5
        if ((int)$player['level'] < 5) api_error('You need to reach level 5 to initiate raids');

        // Resolve target
        $def_stmt = $db->prepare('SELECT * FROM players WHERE wallet_address = ?');
        $def_stmt->execute([$target_wallet]);
        $defender = $def_stmt->fetch();
        if (!$defender) api_error('Target player not found');
        if ($defender['id'] === $player['id']) api_error('You cannot raid yourself');

        // Cooldown: only one active raid against the same defender per 24 h
        $cool = $db->prepare(
            "SELECT id FROM pvp_raids
             WHERE attacker_id = ? AND defender_id = ?
               AND started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $cool->execute([$player['id'], $defender['id']]);
        if ($cool->fetch()) api_error('You already raided this player in the last 24 hours');

        // Calculate powers
        $attack  = calculate_player_attack((int)$player['id']);
        $defense = calculate_player_defense((int)$defender['id']);

        // Resolve immediately with some randomness (±20%)
        $rand_factor = random_int(80, 120) / 100.0;
        $effective_attack = (int)round($attack * $rand_factor);

        if ($effective_attack > $defense) {
            $outcome      = 'attacker_win';
            // Steal up to 10% of defender resources
            $loot_fuel     = min((float)$defender['fuel']     * 0.10, (float)$player['fuel_storage_cap']     - (float)$player['fuel']);
            $loot_minerals = min((float)$defender['minerals'] * 0.10, (float)$player['mineral_storage_cap']  - (float)$player['minerals']);
            $loot_metal    = min((float)$defender['metal']    * 0.10, (float)$player['metal_storage_cap']    - (float)$player['metal']);
            $loot_fuel     = max(0, round($loot_fuel,     4));
            $loot_minerals = max(0, round($loot_minerals, 4));
            $loot_metal    = max(0, round($loot_metal,    4));
        } elseif ($effective_attack < $defense) {
            $outcome       = 'defender_win';
            $loot_fuel     = 0;
            $loot_minerals = 0;
            $loot_metal    = 0;
        } else {
            $outcome       = 'draw';
            $loot_fuel     = 0;
            $loot_minerals = 0;
            $loot_metal    = 0;
        }

        $db->beginTransaction();
        try {
            // Insert raid record
            $ins = $db->prepare(
                'INSERT INTO pvp_raids
                   (attacker_id, defender_id, attack_power, defense_power,
                    outcome, loot_fuel, loot_minerals, loot_metal, status, resolved_at)
                 VALUES (?,?,?,?,?,?,?,?,"resolved",NOW())'
            );
            $ins->execute([
                $player['id'], $defender['id'],
                $effective_attack, $defense,
                $outcome,
                $loot_fuel, $loot_minerals, $loot_metal,
            ]);

            if ($outcome === 'attacker_win' && ($loot_fuel + $loot_minerals + $loot_metal) > 0) {
                // Transfer loot
                $db->prepare(
                    'UPDATE players SET fuel=fuel-?, minerals=minerals-?, metal=metal-? WHERE id=?'
                )->execute([$loot_fuel, $loot_minerals, $loot_metal, $defender['id']]);

                $db->prepare(
                    'UPDATE players SET fuel=fuel+?, minerals=minerals+?, metal=metal+? WHERE id=?'
                )->execute([$loot_fuel, $loot_minerals, $loot_metal, $player['id']]);
            }

            $db->commit();

            // XP rewards
            if ($outcome === 'attacker_win') {
                add_experience((int)$player['id'],   50);
                add_experience((int)$defender['id'],  5);
            } elseif ($outcome === 'defender_win') {
                add_experience((int)$defender['id'], 25);
                add_experience((int)$player['id'],    5);
            } else {
                add_experience((int)$player['id'],   10);
                add_experience((int)$defender['id'], 10);
            }

            // Activity log
            $db->prepare(
                "INSERT INTO activity_log (player_id, action, details) VALUES (?, 'pvp_raid', ?)"
            )->execute([$player['id'], json_encode([
                'defender'     => $defender['wallet_address'],
                'outcome'      => $outcome,
                'attack_power' => $effective_attack,
                'defense_power'=> $defense,
                'loot'         => ['fuel' => $loot_fuel, 'minerals' => $loot_minerals, 'metal' => $loot_metal],
            ])]);

        } catch (\Exception $e) {
            $db->rollBack();
            api_error('Raid initiation failed');
        }

        api_response([
            'success'       => true,
            'outcome'       => $outcome,
            'attack_power'  => $effective_attack,
            'defense_power' => $defense,
            'loot'          => ['fuel' => $loot_fuel, 'minerals' => $loot_minerals, 'metal' => $loot_metal],
        ]);
    }

    api_error('Unknown action');
}
