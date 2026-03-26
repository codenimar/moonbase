<?php
/**
 * Game logic helpers
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';

// ── Building definitions ──────────────────────────────────────────────────────
function building_definitions(): array {
    return [
        'command_center' => [
            'name'        => 'Command Center',
            'description' => 'Your base HQ. Required to build other structures.',
            'size'        => [3, 3],
            'max_level'   => 5,
            'unique'      => true,
            'levels'      => [
                1 => ['cost' => ['fuel' => 0, 'minerals' => 0, 'mooncoin' => 0], 'build_time' => 0],
                2 => ['cost' => ['fuel' => 500, 'minerals' => 300, 'mooncoin' => 200], 'build_time' => 3600],
                3 => ['cost' => ['fuel' => 2000, 'minerals' => 1500, 'mooncoin' => 1000], 'build_time' => 14400],
                4 => ['cost' => ['fuel' => 8000, 'minerals' => 5000, 'mooncoin' => 4000], 'build_time' => 43200],
                5 => ['cost' => ['fuel' => 30000, 'minerals' => 20000, 'mooncoin' => 15000], 'build_time' => 86400],
            ],
            'requires_level' => 1,
        ],
        'fuel_plant' => [
            'name'        => 'Fuel Plant',
            'description' => 'Produces Fuel. Production rate scales with $PUMPVILLE token balance.',
            'size'        => [2, 2],
            'max_level'   => 5,
            'unique'      => false,
            'produces'    => 'fuel',
            'levels'      => [
                1 => ['cost' => ['fuel' => 50, 'minerals' => 0, 'mooncoin' => 100], 'build_time' => 60, 'rate' => 1.0],
                2 => ['cost' => ['fuel' => 200, 'minerals' => 100, 'mooncoin' => 300], 'build_time' => 600, 'rate' => 2.5],
                3 => ['cost' => ['fuel' => 800, 'minerals' => 400, 'mooncoin' => 1000], 'build_time' => 1800, 'rate' => 5.0],
                4 => ['cost' => ['fuel' => 3000, 'minerals' => 1500, 'mooncoin' => 3000], 'build_time' => 7200, 'rate' => 12.0],
                5 => ['cost' => ['fuel' => 10000, 'minerals' => 5000, 'mooncoin' => 8000], 'build_time' => 18000, 'rate' => 25.0],
            ],
            'requires_level' => 1,
        ],
        'storage' => [
            'name'        => 'Storage Silo',
            'description' => 'Increases capacity for all resources.',
            'size'        => [2, 2],
            'max_level'   => 5,
            'unique'      => false,
            'levels'      => [
                1 => ['cost' => ['fuel' => 100, 'minerals' => 50, 'mooncoin' => 150], 'build_time' => 120, 'capacity_bonus' => 500],
                2 => ['cost' => ['fuel' => 400, 'minerals' => 200, 'mooncoin' => 500], 'build_time' => 900, 'capacity_bonus' => 1500],
                3 => ['cost' => ['fuel' => 1500, 'minerals' => 800, 'mooncoin' => 1500], 'build_time' => 3600, 'capacity_bonus' => 5000],
                4 => ['cost' => ['fuel' => 5000, 'minerals' => 3000, 'mooncoin' => 4000], 'build_time' => 10800, 'capacity_bonus' => 15000],
                5 => ['cost' => ['fuel' => 20000, 'minerals' => 10000, 'mooncoin' => 12000], 'build_time' => 28800, 'capacity_bonus' => 50000],
            ],
            'requires_level' => 1,
        ],
        'mining_station' => [
            'name'        => 'Mining Station',
            'description' => 'Extracts minerals from the moon surface.',
            'size'        => [2, 2],
            'max_level'   => 5,
            'unique'      => false,
            'produces'    => 'minerals',
            'levels'      => [
                1 => ['cost' => ['fuel' => 150, 'minerals' => 0, 'mooncoin' => 200], 'build_time' => 300, 'rate' => 0.5],
                2 => ['cost' => ['fuel' => 600, 'minerals' => 200, 'mooncoin' => 500], 'build_time' => 1200, 'rate' => 1.5],
                3 => ['cost' => ['fuel' => 2500, 'minerals' => 800, 'mooncoin' => 1500], 'build_time' => 4800, 'rate' => 4.0],
                4 => ['cost' => ['fuel' => 8000, 'minerals' => 3000, 'mooncoin' => 4000], 'build_time' => 14400, 'rate' => 10.0],
                5 => ['cost' => ['fuel' => 25000, 'minerals' => 10000, 'mooncoin' => 10000], 'build_time' => 36000, 'rate' => 20.0],
            ],
            'requires_level' => 2,
        ],
        'smelter' => [
            'name'        => 'Smelter',
            'description' => 'Converts minerals into metal for advanced construction.',
            'size'        => [2, 2],
            'max_level'   => 5,
            'unique'      => false,
            'converts'    => ['minerals' => 'metal', 'ratio' => 2.0],
            'levels'      => [
                1 => ['cost' => ['fuel' => 200, 'minerals' => 100, 'mooncoin' => 300], 'build_time' => 600],
                2 => ['cost' => ['fuel' => 800, 'minerals' => 500, 'mooncoin' => 800], 'build_time' => 2400],
                3 => ['cost' => ['fuel' => 3000, 'minerals' => 2000, 'mooncoin' => 2000], 'build_time' => 8000],
                4 => ['cost' => ['fuel' => 10000, 'minerals' => 6000, 'mooncoin' => 6000], 'build_time' => 24000],
                5 => ['cost' => ['fuel' => 35000, 'minerals' => 20000, 'mooncoin' => 15000], 'build_time' => 60000],
            ],
            'requires_level' => 3,
        ],
        'market' => [
            'name'        => 'Marketplace',
            'description' => 'Trade resources with other players. 10% fee applies.',
            'size'        => [3, 3],
            'max_level'   => 3,
            'unique'      => true,
            'levels'      => [
                1 => ['cost' => ['fuel' => 300, 'minerals' => 150, 'mooncoin' => 500], 'build_time' => 1200, 'listing_slots' => 5],
                2 => ['cost' => ['fuel' => 1200, 'minerals' => 600, 'mooncoin' => 1500], 'build_time' => 4800, 'listing_slots' => 15],
                3 => ['cost' => ['fuel' => 5000, 'minerals' => 2500, 'mooncoin' => 5000], 'build_time' => 14400, 'listing_slots' => 30],
            ],
            'requires_level' => 3,
        ],
        'research_lab' => [
            'name'        => 'Research Lab',
            'description' => 'Unlock advanced technologies and upgrades.',
            'size'        => [2, 2],
            'max_level'   => 5,
            'unique'      => true,
            'levels'      => [
                1 => ['cost' => ['fuel' => 400, 'minerals' => 200, 'mooncoin' => 600], 'build_time' => 1800],
                2 => ['cost' => ['fuel' => 1600, 'minerals' => 800, 'mooncoin' => 1800], 'build_time' => 7200],
                3 => ['cost' => ['fuel' => 6000, 'minerals' => 3000, 'mooncoin' => 6000], 'build_time' => 21600],
                4 => ['cost' => ['fuel' => 20000, 'minerals' => 10000, 'mooncoin' => 18000], 'build_time' => 57600],
                5 => ['cost' => ['fuel' => 60000, 'minerals' => 30000, 'mooncoin' => 50000], 'build_time' => 115200],
            ],
            'requires_level' => 4,
        ],
        'defense_tower' => [
            'name'        => 'Defense Tower',
            'description' => 'Protects your base during raid events.',
            'size'        => [1, 1],
            'max_level'   => 5,
            'unique'      => false,
            'levels'      => [
                1 => ['cost' => ['fuel' => 100, 'minerals' => 80, 'mooncoin' => 200], 'build_time' => 300, 'defense' => 10],
                2 => ['cost' => ['fuel' => 400, 'minerals' => 300, 'mooncoin' => 600], 'build_time' => 1200, 'defense' => 25],
                3 => ['cost' => ['fuel' => 1500, 'minerals' => 1200, 'mooncoin' => 2000], 'build_time' => 4800, 'defense' => 60],
                4 => ['cost' => ['fuel' => 5000, 'minerals' => 4000, 'mooncoin' => 6000], 'build_time' => 14400, 'defense' => 150],
                5 => ['cost' => ['fuel' => 15000, 'minerals' => 12000, 'mooncoin' => 18000], 'build_time' => 36000, 'defense' => 350],
            ],
            'requires_level' => 5,
        ],
    ];
}

// ── Research tree definitions ──────────────────────────────────────────────
function research_tree_definitions(): array {
    return [
        // ── Production branch ──────────────────────────────────────────
        'mining_efficiency' => [
            'name'        => 'Mining Efficiency',
            'description' => 'Increases mineral production rate by 20% per level.',
            'category'    => 'production',
            'max_level'   => 5,
            'requires_lab_level' => 1,
            'prerequisite'=> null,
            'levels'      => [
                1 => ['cost' => ['fuel' => 500,  'minerals' => 200,  'mooncoin' => 300],  'research_time' => 1800,  'bonus' => 0.20],
                2 => ['cost' => ['fuel' => 1500, 'minerals' => 600,  'mooncoin' => 900],  'research_time' => 5400,  'bonus' => 0.40],
                3 => ['cost' => ['fuel' => 4000, 'minerals' => 1500, 'mooncoin' => 2500], 'research_time' => 14400, 'bonus' => 0.60],
                4 => ['cost' => ['fuel' => 10000,'minerals' => 4000, 'mooncoin' => 7000], 'research_time' => 36000, 'bonus' => 0.80],
                5 => ['cost' => ['fuel' => 25000,'minerals' => 10000,'mooncoin' => 20000],'research_time' => 86400, 'bonus' => 1.00],
            ],
        ],
        'fuel_synthesis' => [
            'name'        => 'Fuel Synthesis',
            'description' => 'Boosts Fuel Plant output by 15% per level.',
            'category'    => 'production',
            'max_level'   => 5,
            'requires_lab_level' => 1,
            'prerequisite'=> null,
            'levels'      => [
                1 => ['cost' => ['fuel' => 400,  'minerals' => 300,  'mooncoin' => 400],  'research_time' => 2400,  'bonus' => 0.15],
                2 => ['cost' => ['fuel' => 1200, 'minerals' => 900,  'mooncoin' => 1200], 'research_time' => 7200,  'bonus' => 0.30],
                3 => ['cost' => ['fuel' => 3500, 'minerals' => 2500, 'mooncoin' => 3500], 'research_time' => 18000, 'bonus' => 0.45],
                4 => ['cost' => ['fuel' => 9000, 'minerals' => 6500, 'mooncoin' => 9000], 'research_time' => 43200, 'bonus' => 0.60],
                5 => ['cost' => ['fuel' => 22000,'minerals' => 15000,'mooncoin' => 22000],'research_time' => 86400, 'bonus' => 0.75],
            ],
        ],
        'storage_compression' => [
            'name'        => 'Storage Compression',
            'description' => 'Increases all storage caps by 25% per level.',
            'category'    => 'production',
            'max_level'   => 3,
            'requires_lab_level' => 2,
            'prerequisite'=> 'mining_efficiency',
            'levels'      => [
                1 => ['cost' => ['fuel' => 2000, 'minerals' => 1000, 'mooncoin' => 1500], 'research_time' => 7200,  'bonus' => 0.25],
                2 => ['cost' => ['fuel' => 6000, 'minerals' => 3000, 'mooncoin' => 5000], 'research_time' => 21600, 'bonus' => 0.50],
                3 => ['cost' => ['fuel' => 15000,'minerals' => 8000, 'mooncoin' => 12000],'research_time' => 57600, 'bonus' => 0.75],
            ],
        ],
        // ── Defense branch ────────────────────────────────────────────
        'tower_reinforcement' => [
            'name'        => 'Tower Reinforcement',
            'description' => 'Increases Defense Tower defense value by 30% per level.',
            'category'    => 'defense',
            'max_level'   => 5,
            'requires_lab_level' => 2,
            'prerequisite'=> null,
            'levels'      => [
                1 => ['cost' => ['fuel' => 600,  'minerals' => 500,  'mooncoin' => 800],  'research_time' => 3600,  'bonus' => 0.30],
                2 => ['cost' => ['fuel' => 2000, 'minerals' => 1800, 'mooncoin' => 2500], 'research_time' => 10800, 'bonus' => 0.60],
                3 => ['cost' => ['fuel' => 6000, 'minerals' => 5000, 'mooncoin' => 7500], 'research_time' => 28800, 'bonus' => 0.90],
                4 => ['cost' => ['fuel' => 15000,'minerals' => 13000,'mooncoin' => 20000],'research_time' => 64800, 'bonus' => 1.20],
                5 => ['cost' => ['fuel' => 35000,'minerals' => 30000,'mooncoin' => 50000],'research_time' => 129600,'bonus' => 1.50],
            ],
        ],
        'shield_matrix' => [
            'name'        => 'Shield Matrix',
            'description' => 'Adds a passive shield that absorbs 10% of incoming raid damage per level.',
            'category'    => 'defense',
            'max_level'   => 3,
            'requires_lab_level' => 3,
            'prerequisite'=> 'tower_reinforcement',
            'levels'      => [
                1 => ['cost' => ['fuel' => 5000, 'minerals' => 4000, 'mooncoin' => 6000], 'research_time' => 14400, 'bonus' => 0.10],
                2 => ['cost' => ['fuel' => 15000,'minerals' => 12000,'mooncoin' => 18000],'research_time' => 43200, 'bonus' => 0.20],
                3 => ['cost' => ['fuel' => 40000,'minerals' => 32000,'mooncoin' => 50000],'research_time' => 115200,'bonus' => 0.30],
            ],
        ],
        // ── Offense branch ────────────────────────────────────────────
        'raid_tactics' => [
            'name'        => 'Raid Tactics',
            'description' => 'Increases raid attack power by 25% per level.',
            'category'    => 'offense',
            'max_level'   => 5,
            'requires_lab_level' => 2,
            'prerequisite'=> null,
            'levels'      => [
                1 => ['cost' => ['fuel' => 800,  'minerals' => 600,  'mooncoin' => 1000], 'research_time' => 4800,  'bonus' => 0.25],
                2 => ['cost' => ['fuel' => 2500, 'minerals' => 2000, 'mooncoin' => 3000], 'research_time' => 14400, 'bonus' => 0.50],
                3 => ['cost' => ['fuel' => 7000, 'minerals' => 5500, 'mooncoin' => 8500], 'research_time' => 36000, 'bonus' => 0.75],
                4 => ['cost' => ['fuel' => 18000,'minerals' => 14000,'mooncoin' => 22000],'research_time' => 86400, 'bonus' => 1.00],
                5 => ['cost' => ['fuel' => 45000,'minerals' => 35000,'mooncoin' => 55000],'research_time' => 172800,'bonus' => 1.25],
            ],
        ],
        // ── Economy branch ────────────────────────────────────────────
        'market_protocols' => [
            'name'        => 'Market Protocols',
            'description' => 'Reduces marketplace fee by 2% per level (min 2%).',
            'category'    => 'economy',
            'max_level'   => 4,
            'requires_lab_level' => 1,
            'prerequisite'=> null,
            'levels'      => [
                1 => ['cost' => ['fuel' => 300,  'minerals' => 200,  'mooncoin' => 500],  'research_time' => 1200,  'bonus' => 2],
                2 => ['cost' => ['fuel' => 1000, 'minerals' => 700,  'mooncoin' => 1500], 'research_time' => 3600,  'bonus' => 4],
                3 => ['cost' => ['fuel' => 3000, 'minerals' => 2000, 'mooncoin' => 4500], 'research_time' => 10800, 'bonus' => 6],
                4 => ['cost' => ['fuel' => 8000, 'minerals' => 5500, 'mooncoin' => 12000],'research_time' => 28800, 'bonus' => 8],
            ],
        ],
        'mooncoin_staking' => [
            'name'        => 'MoonCoin Staking',
            'description' => 'Earn 1% of current balance per hour as passive MoonCoin income per level.',
            'category'    => 'economy',
            'max_level'   => 3,
            'requires_lab_level' => 3,
            'prerequisite'=> 'market_protocols',
            'levels'      => [
                1 => ['cost' => ['fuel' => 4000, 'minerals' => 3000, 'mooncoin' => 5000], 'research_time' => 10800, 'bonus' => 0.01],
                2 => ['cost' => ['fuel' => 12000,'minerals' => 9000, 'mooncoin' => 15000],'research_time' => 32400, 'bonus' => 0.02],
                3 => ['cost' => ['fuel' => 30000,'minerals' => 22000,'mooncoin' => 40000],'research_time' => 86400, 'bonus' => 0.03],
            ],
        ],
    ];
}

// ── Defense power calculation ─────────────────────────────────────────────
/**
 * Returns the total defense power for a player considering their defense towers
 * and any tower_reinforcement research bonus.
 */
function calculate_player_defense(int $player_id): int {
    $db   = get_db();
    $defs = building_definitions();

    $stmt = $db->prepare(
        "SELECT level FROM buildings WHERE player_id = ? AND building_type = 'defense_tower' AND is_active = 1"
    );
    $stmt->execute([$player_id]);
    $towers = $stmt->fetchAll();

    $base_defense = 0;
    foreach ($towers as $t) {
        $lvl = (int)$t['level'];
        $base_defense += $defs['defense_tower']['levels'][$lvl]['defense'] ?? 0;
    }

    // Apply tower_reinforcement research bonus
    $res_stmt = $db->prepare(
        "SELECT level FROM research WHERE player_id = ? AND tech_key = 'tower_reinforcement'"
    );
    $res_stmt->execute([$player_id]);
    $tech = $res_stmt->fetch();
    $bonus_multiplier = 1.0;
    if ($tech) {
        $tree  = research_tree_definitions();
        $bonus = $tree['tower_reinforcement']['levels'][(int)$tech['level']]['bonus'] ?? 0;
        $bonus_multiplier = 1.0 + $bonus;
    }

    // Shield matrix passive absorption
    $shield_stmt = $db->prepare(
        "SELECT level FROM research WHERE player_id = ? AND tech_key = 'shield_matrix'"
    );
    $shield_stmt->execute([$player_id]);
    $shield_tech = $shield_stmt->fetch();
    $shield_bonus = 0.0;
    if ($shield_tech) {
        $tree         = research_tree_definitions();
        $shield_bonus = $tree['shield_matrix']['levels'][(int)$shield_tech['level']]['bonus'] ?? 0;
    }

    return (int)round($base_defense * $bonus_multiplier * (1.0 + $shield_bonus));
}

// ── Attack power calculation ──────────────────────────────────────────────
/**
 * Returns the attack power for a player (base = player level × 10,
 * boosted by raid_tactics research).
 */
function calculate_player_attack(int $player_id): int {
    $db     = get_db();
    $player = $db->prepare('SELECT level FROM players WHERE id = ?');
    $player->execute([$player_id]);
    $row = $player->fetch();
    if (!$row) return 0;

    $base_attack = (int)$row['level'] * 10;

    $stmt = $db->prepare(
        "SELECT level FROM research WHERE player_id = ? AND tech_key = 'raid_tactics'"
    );
    $stmt->execute([$player_id]);
    $tech = $stmt->fetch();
    $multiplier = 1.0;
    if ($tech) {
        $tree       = research_tree_definitions();
        $bonus      = $tree['raid_tactics']['levels'][(int)$tech['level']]['bonus'] ?? 0;
        $multiplier = 1.0 + $bonus;
    }

    return (int)round($base_attack * $multiplier);
}

// ── Token bonus calculation ───────────────────────────────────────────────
function calculate_token_bonus(float $token_balance): float {
    $tiers = TOKEN_BONUS_TIERS;
    krsort($tiers);
    foreach ($tiers as $min_tokens => $multiplier) {
        if ($token_balance >= $min_tokens) return (float)$multiplier;
    }
    return 1.0;
}

// ── Fuel accumulation ─────────────────────────────────────────────────────────
/**
 * Compute how much fuel a player has accumulated since last_fuel_update.
 * Updates the player row in DB and returns the new fuel amount.
 */
function update_player_fuel(array &$player): float {
    $db       = get_db();
    $defs     = building_definitions();
    $now      = time();
    $elapsed  = $now - strtotime($player['last_fuel_update']); // seconds
    if ($elapsed <= 0) return (float)$player['fuel'];

    // Sum production rates from all active fuel plants
    $stmt = $db->prepare(
        "SELECT level FROM buildings WHERE player_id = ? AND building_type = 'fuel_plant' AND is_active = 1"
    );
    $stmt->execute([$player['id']]);
    $plants = $stmt->fetchAll();

    $rate_per_second = 0.0;
    foreach ($plants as $plant) {
        $lvl  = (int)$plant['level'];
        $rate = $defs['fuel_plant']['levels'][$lvl]['rate'] ?? BASE_FUEL_RATE;
        $rate_per_second += $rate / 60.0; // rate is per-minute
    }

    // Apply token bonus
    $token_bonus     = calculate_token_bonus((float)$player['token_balance']);
    $rate_per_second *= $token_bonus;

    $new_fuel = min(
        (float)$player['fuel'] + $rate_per_second * $elapsed,
        (float)$player['fuel_storage_cap']
    );

    $db->prepare('UPDATE players SET fuel = ?, last_fuel_update = NOW() WHERE id = ?')
       ->execute([$new_fuel, $player['id']]);

    $player['fuel']             = $new_fuel;
    $player['last_fuel_update'] = date('Y-m-d H:i:s', $now);
    return $new_fuel;
}

// ── Experience / leveling ─────────────────────────────────────────────────────
function add_experience(int $player_id, int $xp): void {
    $db     = get_db();
    $player = $db->prepare('SELECT level, experience FROM players WHERE id = ?');
    $player->execute([$player_id]);
    $row    = $player->fetch();
    if (!$row) return;

    $new_xp    = (int)$row['experience'] + $xp;
    // XP-to-level: level 1 at 0–999 XP, level 2 at 1000–1999 XP, etc.
    $new_level = max(1, (int)floor($new_xp / XP_TO_LEVEL) + 1);
    $new_level = min($new_level, 50); // level cap

    $db->prepare('UPDATE players SET experience = ?, level = ? WHERE id = ?')
       ->execute([$new_xp, $new_level, $player_id]);
}

// ── API response helpers ──────────────────────────────────────────────────────
function api_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function api_error(string $message, int $code = 400): void {
    api_response(['error' => $message], $code);
}
