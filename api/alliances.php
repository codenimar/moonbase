<?php
/**
 * API: Alliances / Guilds
 * GET  /api/alliances.php                              – list alliances
 * GET  /api/alliances.php?id=N                         – get single alliance
 * POST /api/alliances.php { action: 'create', name, tag, description }
 * POST /api/alliances.php { action: 'join',   alliance_id }
 * POST /api/alliances.php { action: 'leave' }
 * POST /api/alliances.php { action: 'promote', player_id }   (founder only)
 * POST /api/alliances.php { action: 'kick',    player_id }   (founder/officer)
 * POST /api/alliances.php { action: 'donate',  amount }      – donate MoonCoins to treasury
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

$player = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$db     = get_db();

// ── GET ────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id > 0) {
        // Single alliance detail
        $stmt = $db->prepare('SELECT * FROM alliances WHERE id = ?');
        $stmt->execute([$id]);
        $alliance = $stmt->fetch();
        if (!$alliance) api_error('Alliance not found', 404);

        $mem_stmt = $db->prepare(
            'SELECT am.role, am.joined_at, p.wallet_address, p.username, p.level
             FROM alliance_members am
             JOIN players p ON p.id = am.player_id
             WHERE am.alliance_id = ?
             ORDER BY FIELD(am.role,"founder","officer","member"), p.level DESC'
        );
        $mem_stmt->execute([$id]);
        $members = $mem_stmt->fetchAll();

        api_response(['alliance' => $alliance, 'members' => $members]);
    }

    // List all alliances (with member count)
    $list = $db->query(
        'SELECT a.id, a.name, a.tag, a.description, a.mooncoin_bank, a.created_at,
                COUNT(am.id) AS member_count,
                MAX(p.level) AS top_level
         FROM alliances a
         LEFT JOIN alliance_members am ON am.alliance_id = a.id
         LEFT JOIN players p ON p.id = am.player_id
         GROUP BY a.id
         ORDER BY member_count DESC, a.mooncoin_bank DESC
         LIMIT 50'
    )->fetchAll();

    // Include the player's own membership
    $my_stmt = $db->prepare(
        'SELECT am.alliance_id, am.role FROM alliance_members am WHERE am.player_id = ?'
    );
    $my_stmt->execute([$player['id']]);
    $my_membership = $my_stmt->fetch();

    api_response(['alliances' => $list, 'my_membership' => $my_membership ?: null]);
}

// ── POST ───────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $action = $input['action'] ?? '';

    // ── Create alliance ────────────────────────────────────────────────────
    if ($action === 'create') {
        $name        = trim($input['name']        ?? '');
        $tag         = strtoupper(trim($input['tag'] ?? ''));
        $description = trim($input['description'] ?? '');

        if (strlen($name) < 3 || strlen($name) > 64) api_error('Alliance name must be 3–64 characters');
        if (!preg_match('/^[A-Z0-9]{2,8}$/', $tag))  api_error('Tag must be 2–8 uppercase letters/digits');

        // Check player is not already in an alliance
        $mem_chk = $db->prepare('SELECT id FROM alliance_members WHERE player_id = ?');
        $mem_chk->execute([$player['id']]);
        if ($mem_chk->fetch()) api_error('You are already in an alliance. Leave first.');

        // Requires level 10
        if ((int)$player['level'] < 10) api_error('You need to reach level 10 to found an alliance');

        $db->beginTransaction();
        try {
            $ins = $db->prepare(
                'INSERT INTO alliances (name, tag, description, founder_id) VALUES (?,?,?,?)'
            );
            $ins->execute([$name, $tag, $description, $player['id']]);
            $alliance_id = (int)$db->lastInsertId();

            $db->prepare(
                'INSERT INTO alliance_members (alliance_id, player_id, role) VALUES (?,?,?)'
            )->execute([$alliance_id, $player['id'], 'founder']);

            // Update players.alliance_id
            $db->prepare('UPDATE players SET alliance_id = ? WHERE id = ?')
               ->execute([$alliance_id, $player['id']]);

            $db->commit();
        } catch (\PDOException $e) {
            $db->rollBack();
            if ($e->getCode() == '23000') {
                api_error('Alliance name or tag already taken');
            }
            api_error('Failed to create alliance');
        }

        add_experience((int)$player['id'], 200);
        api_response(['success' => true, 'alliance_id' => $alliance_id]);
    }

    // ── Join alliance ──────────────────────────────────────────────────────
    if ($action === 'join') {
        $alliance_id = (int)($input['alliance_id'] ?? 0);
        if (!$alliance_id) api_error('alliance_id required');

        // Must not already be a member
        $mem_chk = $db->prepare('SELECT id FROM alliance_members WHERE player_id = ?');
        $mem_chk->execute([$player['id']]);
        if ($mem_chk->fetch()) api_error('You are already in an alliance');

        // Alliance exists?
        $al_stmt = $db->prepare('SELECT id FROM alliances WHERE id = ?');
        $al_stmt->execute([$alliance_id]);
        if (!$al_stmt->fetch()) api_error('Alliance not found', 404);

        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO alliance_members (alliance_id, player_id, role) VALUES (?,?,?)'
            )->execute([$alliance_id, $player['id'], 'member']);

            $db->prepare('UPDATE players SET alliance_id = ? WHERE id = ?')
               ->execute([$alliance_id, $player['id']]);

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            api_error('Failed to join alliance');
        }

        api_response(['success' => true]);
    }

    // ── Leave alliance ─────────────────────────────────────────────────────
    if ($action === 'leave') {
        $mem_stmt = $db->prepare('SELECT * FROM alliance_members WHERE player_id = ?');
        $mem_stmt->execute([$player['id']]);
        $mem = $mem_stmt->fetch();
        if (!$mem) api_error('You are not in an alliance');
        if ($mem['role'] === 'founder') api_error('Founders cannot leave; transfer leadership or disband first');

        $db->prepare('DELETE FROM alliance_members WHERE player_id = ?')->execute([$player['id']]);
        $db->prepare('UPDATE players SET alliance_id = NULL WHERE id = ?')->execute([$player['id']]);
        api_response(['success' => true]);
    }

    // ── Promote member ─────────────────────────────────────────────────────
    if ($action === 'promote') {
        $target_id = (int)($input['player_id'] ?? 0);
        $my_mem    = fetch_my_membership($player['id'], $db);
        if (!$my_mem || $my_mem['role'] !== 'founder') api_error('Only the founder can promote members');

        $target_mem = $db->prepare(
            'SELECT * FROM alliance_members WHERE player_id = ? AND alliance_id = ?'
        );
        $target_mem->execute([$target_id, $my_mem['alliance_id']]);
        $tmem = $target_mem->fetch();
        if (!$tmem) api_error('Player is not in your alliance');

        $new_role = $tmem['role'] === 'member' ? 'officer' : 'founder';
        if ($new_role === 'founder') {
            // Demote current founder to officer
            $db->prepare(
                "UPDATE alliance_members SET role='officer' WHERE player_id = ?"
            )->execute([$player['id']]);
            $db->prepare(
                'UPDATE alliances SET founder_id = ? WHERE id = ?'
            )->execute([$target_id, $my_mem['alliance_id']]);
        }
        $db->prepare(
            'UPDATE alliance_members SET role = ? WHERE player_id = ?'
        )->execute([$new_role, $target_id]);

        api_response(['success' => true, 'new_role' => $new_role]);
    }

    // ── Kick member ────────────────────────────────────────────────────────
    if ($action === 'kick') {
        $target_id = (int)($input['player_id'] ?? 0);
        $my_mem    = fetch_my_membership($player['id'], $db);
        if (!$my_mem || !in_array($my_mem['role'], ['founder','officer'])) {
            api_error('Only founders and officers can kick members');
        }

        $target_mem = $db->prepare(
            'SELECT * FROM alliance_members WHERE player_id = ? AND alliance_id = ?'
        );
        $target_mem->execute([$target_id, $my_mem['alliance_id']]);
        $tmem = $target_mem->fetch();
        if (!$tmem) api_error('Player is not in your alliance');
        if ($tmem['role'] === 'founder') api_error('Cannot kick the founder');
        if ($my_mem['role'] === 'officer' && $tmem['role'] === 'officer') {
            api_error('Officers cannot kick other officers');
        }

        $db->prepare('DELETE FROM alliance_members WHERE player_id = ?')->execute([$target_id]);
        $db->prepare('UPDATE players SET alliance_id = NULL WHERE id = ?')->execute([$target_id]);
        api_response(['success' => true]);
    }

    // ── Donate to treasury ─────────────────────────────────────────────────
    if ($action === 'donate') {
        $amount  = (float)($input['amount'] ?? 0);
        if ($amount <= 0) api_error('Amount must be positive');
        $my_mem  = fetch_my_membership($player['id'], $db);
        if (!$my_mem) api_error('You are not in an alliance');

        if ((float)$player['mooncoin_balance'] < $amount) api_error('Insufficient MoonCoins');

        $db->prepare('UPDATE players SET mooncoin_balance=mooncoin_balance-? WHERE id=?')
           ->execute([$amount, $player['id']]);
        $db->prepare('UPDATE alliances SET mooncoin_bank=mooncoin_bank+? WHERE id=?')
           ->execute([$amount, $my_mem['alliance_id']]);

        api_response(['success' => true]);
    }

    api_error('Unknown action');
}

// ── Helpers ───────────────────────────────────────────────────────────────
function fetch_my_membership(int $player_id, PDO $db): array|false {
    $stmt = $db->prepare('SELECT * FROM alliance_members WHERE player_id = ?');
    $stmt->execute([$player_id]);
    return $stmt->fetch();
}
