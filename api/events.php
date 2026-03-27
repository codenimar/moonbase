<?php
/**
 * API: Community Events
 * GET  /api/events.php               – list events
 * POST /api/events.php { action: 'join',       event_id }
 * POST /api/events.php { action: 'contribute', event_id, resource_type, amount }
 * POST /api/events.php { action: 'claim',      event_id }
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

if ($method === 'GET') {
    $evts = $db->prepare(
        'SELECT e.*,
                ep.contribution, ep.reward_share, ep.reward_claimed, ep.reward_amount,
                (SELECT COUNT(*) FROM event_participants ep2 WHERE ep2.event_id = e.id) as participant_count
         FROM events e
         LEFT JOIN event_participants ep ON ep.event_id = e.id AND ep.player_id = ?
         WHERE e.status != "completed" OR ep.reward_claimed = 0
         ORDER BY e.start_time DESC
         LIMIT 20'
    );
    $evts->execute([$player['id']]);
    api_response(['events' => $evts->fetchAll()]);
}

if ($method === 'POST') {
    $action = $input['action'] ?? '';

    // ── Join event ────────────────────────────────────────────────────────────
    if ($action === 'join') {
        $event_id = (int)($input['event_id'] ?? 0);
        $event    = fetch_event($event_id, $db);

        if ($player['level'] < $event['min_level']) {
            api_error("This event requires level {$event['min_level']}");
        }
        if ($event['status'] !== 'active') api_error('Event is not currently active');

        try {
            $db->prepare(
                'INSERT INTO event_participants (event_id, player_id) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE event_id = event_id'
            )->execute([$event_id, $player['id']]);
        } catch (\PDOException $e) {
            // Already joined
        }
        api_response(['success' => true]);
    }

    // ── Contribute ────────────────────────────────────────────────────────────
    if ($action === 'contribute') {
        $event_id = (int)($input['event_id']     ?? 0);
        $resource = $input['resource_type'] ?? 'fuel';
        $amount   = (float)($input['amount'] ?? 0);

        if (!in_array($resource, ['fuel','minerals','metal'], true)) api_error('Invalid resource type');
        if ($amount <= 0) api_error('Amount must be positive');

        $event = fetch_event($event_id, $db);
        if ($player['level'] < $event['min_level']) {
            api_error("This event requires level {$event['min_level']}");
        }
        if ($event['status'] !== 'active') api_error('Event is not currently active');
        if ((float)$player[$resource] < $amount) api_error("Not enough {$resource}");

        // Ensure player is a participant
        $db->prepare(
            'INSERT INTO event_participants (event_id, player_id) VALUES (?,?)
             ON DUPLICATE KEY UPDATE event_id = event_id'
        )->execute([$event_id, $player['id']]);

        $db->beginTransaction();
        try {
            // Deduct from player
            $db->prepare("UPDATE players SET {$resource} = {$resource} - ? WHERE id = ?")
               ->execute([$amount, $player['id']]);

            // Add to participant contribution
            $db->prepare(
                'UPDATE event_participants SET contribution = contribution + ? WHERE event_id = ? AND player_id = ?'
            )->execute([$amount, $event_id, $player['id']]);

            // Update event total
            $db->prepare('UPDATE events SET current_amount = current_amount + ? WHERE id = ?')
               ->execute([$amount, $event_id]);

            // Check if goal reached
            $updated = $db->prepare('SELECT current_amount, target_amount FROM events WHERE id = ?');
            $updated->execute([$event_id]);
            $row = $updated->fetch();
            if ((float)$row['current_amount'] >= (float)$row['target_amount']) {
                $db->prepare("UPDATE events SET status = 'distributing' WHERE id = ?")
                   ->execute([$event_id]);
                distribute_event_rewards($event_id, $db);
            }

            $db->commit();
            add_experience($player['id'], (int)ceil($amount / 10));
            api_response(['success' => true]);
        } catch (\Exception $e) {
            $db->rollBack();
            api_error('Contribution failed');
        }
    }

    // ── Claim reward ──────────────────────────────────────────────────────────
    if ($action === 'claim') {
        $event_id = (int)($input['event_id'] ?? 0);
        $ep = $db->prepare(
            'SELECT * FROM event_participants WHERE event_id = ? AND player_id = ?'
        );
        $ep->execute([$event_id, $player['id']]);
        $participant = $ep->fetch();

        if (!$participant) api_error('You did not participate in this event');
        if ($participant['reward_claimed']) api_error('Reward already claimed');
        if ($participant['reward_amount'] <= 0) api_error('No reward available yet');

        $db->prepare(
            'UPDATE event_participants SET reward_claimed = 1 WHERE event_id = ? AND player_id = ?'
        )->execute([$event_id, $player['id']]);

        // In production: trigger on-chain transfer of PUMPVILLE tokens to player wallet
        // For now we log the pending transfer
        $db->prepare(
            "INSERT INTO activity_log (player_id, action, details) VALUES (?, 'event_reward_claim', ?)"
        )->execute([
            $player['id'],
            json_encode([
                'event_id' => $event_id,
                'amount'   => $participant['reward_amount'],
                'wallet'   => $player['wallet_address'],
            ])
        ]);

        api_response([
            'success'       => true,
            'reward_amount' => $participant['reward_amount'],
            'wallet'        => $player['wallet_address'],
            'note'          => 'Token reward will be distributed to your wallet within 24 hours.',
        ]);
    }

    api_error('Unknown action');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fetch_event(int $id, PDO $db): array {
    $stmt = $db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$id]);
    $e = $stmt->fetch();
    if (!$e) {
        http_response_code(404);
        echo json_encode(['error' => 'Event not found']);
        exit;
    }
    return $e;
}

function distribute_event_rewards(int $event_id, PDO $db): void {
    $event = $db->prepare('SELECT * FROM events WHERE id = ?');
    $event->execute([$event_id]);
    $ev = $event->fetch();

    $participants = $db->prepare(
        'SELECT * FROM event_participants WHERE event_id = ? AND contribution > 0'
    );
    $participants->execute([$event_id]);
    $rows  = $participants->fetchAll();
    $total = array_sum(array_column($rows, 'contribution'));
    if ($total <= 0) return;

    foreach ($rows as $row) {
        $share  = (float)$row['contribution'] / $total;
        $reward = $share * (float)$ev['prize_pool'];
        $db->prepare(
            'UPDATE event_participants SET reward_share = ?, reward_amount = ? WHERE id = ?'
        )->execute([$share, $reward, $row['id']]);
    }

    $db->prepare("UPDATE events SET status = 'completed' WHERE id = ?")
       ->execute([$event_id]);
}
