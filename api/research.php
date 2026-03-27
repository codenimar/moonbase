<?php
/**
 * API: Research Tree
 * GET  /api/research.php               – list available techs + player progress
 * POST /api/research.php { action: 'start',    tech_key }  – begin research
 * POST /api/research.php { action: 'complete', tech_key }  – collect finished research
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

// ── GET: list research state ───────────────────────────────────────────────
if ($method === 'GET') {
    $tree = research_tree_definitions();

    // Load player's researched techs
    $stmt = $db->prepare('SELECT * FROM research WHERE player_id = ?');
    $stmt->execute([$player['id']]);
    $rows = $stmt->fetchAll();
    $player_tech = [];
    foreach ($rows as $r) {
        $player_tech[$r['tech_key']] = $r;
    }

    // Get Research Lab level (if player has one)
    $lab_stmt = $db->prepare(
        "SELECT level FROM buildings WHERE player_id = ? AND building_type = 'research_lab' AND is_active = 1 LIMIT 1"
    );
    $lab_stmt->execute([$player['id']]);
    $lab = $lab_stmt->fetch();
    $lab_level = $lab ? (int)$lab['level'] : 0;

    // Check for in-progress research (stored in research table with is_researching flag via research_finish)
    // We use an auxiliary approach: store finish time in a separate column
    // For simplicity we check the activity_log for pending items
    $active_stmt = $db->prepare(
        "SELECT details FROM activity_log
         WHERE player_id = ? AND action = 'research_start'
           AND JSON_VALUE(details, '$.finish_time') > NOW()
         ORDER BY created_at DESC LIMIT 1"
    );
    $active_stmt->execute([$player['id']]);
    $active_row    = $active_stmt->fetch();
    $active_research = $active_row
        ? json_decode($active_row['details'], true)
        : null;

    api_response([
        'tree'            => $tree,
        'player_tech'     => $player_tech,
        'lab_level'       => $lab_level,
        'active_research' => $active_research,
    ]);
}

// ── POST ───────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $action   = $input['action']   ?? '';
    $tech_key = $input['tech_key'] ?? '';

    $tree = research_tree_definitions();
    if (!isset($tree[$tech_key])) api_error('Unknown tech key');

    $tech_def = $tree[$tech_key];

    // ── Start research ─────────────────────────────────────────────────────
    if ($action === 'start') {
        // Validate Research Lab exists and is high enough level
        $lab_stmt = $db->prepare(
            "SELECT level FROM buildings WHERE player_id = ? AND building_type = 'research_lab' AND is_active = 1 LIMIT 1"
        );
        $lab_stmt->execute([$player['id']]);
        $lab = $lab_stmt->fetch();
        if (!$lab) api_error('You need a Research Lab to research technologies');
        if ((int)$lab['level'] < $tech_def['requires_lab_level']) {
            api_error("This technology requires a Level {$tech_def['requires_lab_level']} Research Lab");
        }

        // Check prerequisite
        if ($tech_def['prerequisite']) {
            $pre_stmt = $db->prepare('SELECT level FROM research WHERE player_id = ? AND tech_key = ?');
            $pre_stmt->execute([$player['id'], $tech_def['prerequisite']]);
            if (!$pre_stmt->fetch()) {
                api_error('Prerequisite not met: ' . $tech_def['prerequisite']);
            }
        }

        // Current level
        $cur_stmt = $db->prepare('SELECT level FROM research WHERE player_id = ? AND tech_key = ?');
        $cur_stmt->execute([$player['id'], $tech_key]);
        $cur = $cur_stmt->fetch();
        $current_level = $cur ? (int)$cur['level'] : 0;
        $next_level    = $current_level + 1;

        if ($next_level > $tech_def['max_level']) {
            api_error('This technology is already at maximum level');
        }

        $level_def = $tech_def['levels'][$next_level];

        // Check another research isn't already in progress
        $in_prog = $db->prepare(
            "SELECT id FROM activity_log
             WHERE player_id = ? AND action = 'research_start'
               AND JSON_VALUE(details, '$.finish_time') > NOW()
             LIMIT 1"
        );
        $in_prog->execute([$player['id']]);
        if ($in_prog->fetch()) api_error('Another research is already in progress');

        // Check costs
        if ((float)$player['fuel']             < $level_def['cost']['fuel'])     api_error('Not enough fuel');
        if ((float)$player['minerals']         < $level_def['cost']['minerals']) api_error('Not enough minerals');
        if ((float)$player['mooncoin_balance'] < $level_def['cost']['mooncoin']) api_error('Not enough MoonCoins');

        $finish_time = date('Y-m-d H:i:s', time() + $level_def['research_time']);

        $db->beginTransaction();
        try {
            // Deduct costs
            $db->prepare(
                'UPDATE players SET fuel=fuel-?, minerals=minerals-?, mooncoin_balance=mooncoin_balance-? WHERE id=?'
            )->execute([
                $level_def['cost']['fuel'],
                $level_def['cost']['minerals'],
                $level_def['cost']['mooncoin'],
                $player['id'],
            ]);

            // Log research start with finish time
            $db->prepare(
                "INSERT INTO activity_log (player_id, action, details) VALUES (?, 'research_start', ?)"
            )->execute([$player['id'], json_encode([
                'tech_key'    => $tech_key,
                'level'       => $next_level,
                'finish_time' => $finish_time,
            ])]);

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            api_error('Failed to start research');
        }

        api_response([
            'success'     => true,
            'tech_key'    => $tech_key,
            'level'       => $next_level,
            'finish_time' => $finish_time,
        ]);
    }

    // ── Complete research ──────────────────────────────────────────────────
    if ($action === 'complete') {
        // Find the pending research log entry for this tech
        $log_stmt = $db->prepare(
            "SELECT id, details FROM activity_log
             WHERE player_id = ? AND action = 'research_start'
               AND JSON_VALUE(details, '$.tech_key') = ?
               AND JSON_VALUE(details, '$.finish_time') <= NOW()
               AND id NOT IN (
                   SELECT CAST(JSON_VALUE(details, '$.log_id') AS UNSIGNED)
                   FROM activity_log
                   WHERE player_id = ? AND action = 'research_complete'
               )
             ORDER BY created_at DESC LIMIT 1"
        );
        $log_stmt->execute([$player['id'], $tech_key, $player['id']]);
        $log_row = $log_stmt->fetch();
        if (!$log_row) api_error('No completed research found for this technology');

        $details    = json_decode($log_row['details'], true);
        $new_level  = (int)$details['level'];

        $db->beginTransaction();
        try {
            // Upsert research record
            $db->prepare(
                'INSERT INTO research (player_id, tech_key, level)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE level = ?, researched_at = NOW()'
            )->execute([$player['id'], $tech_key, $new_level, $new_level]);

            // Mark as collected
            $db->prepare(
                "INSERT INTO activity_log (player_id, action, details) VALUES (?, 'research_complete', ?)"
            )->execute([$player['id'], json_encode([
                'tech_key' => $tech_key,
                'level'    => $new_level,
                'log_id'   => $log_row['id'],
            ])]);

            $db->commit();
            // XP reward: 50 base + 25 per level, capped at 250
            add_experience((int)$player['id'], min(250, 50 + 25 * $new_level));
        } catch (\Exception $e) {
            $db->rollBack();
            api_error('Failed to complete research');
        }

        api_response([
            'success'  => true,
            'tech_key' => $tech_key,
            'level'    => $new_level,
        ]);
    }

    api_error('Unknown action');
}
