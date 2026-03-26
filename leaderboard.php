<?php
/**
 * leaderboard.php – Public leaderboard page (no auth required for viewing)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config/config.php';

$db = get_db();

// Top players by level / XP
$players = $db->query(
    'SELECT wallet_address, username, level, experience, mooncoin_balance,
            a.name AS alliance_name, a.tag AS alliance_tag
     FROM players p
     LEFT JOIN alliances a ON a.id = p.alliance_id
     ORDER BY p.level DESC, p.experience DESC
     LIMIT 50'
)->fetchAll();

// Top alliances by total member levels
$alliances = $db->query(
    'SELECT a.id, a.name, a.tag, a.mooncoin_bank,
            COUNT(am.id) AS member_count,
            SUM(p.level) AS total_level,
            MAX(p.level) AS top_player_level
     FROM alliances a
     JOIN alliance_members am ON am.alliance_id = a.id
     JOIN players p ON p.id = am.player_id
     GROUP BY a.id
     ORDER BY total_level DESC
     LIMIT 20'
)->fetchAll();

// Recent PvP raids
$raids = $db->query(
    'SELECT r.outcome, r.attack_power, r.defense_power,
            r.loot_fuel, r.loot_minerals, r.loot_metal, r.resolved_at,
            a.wallet_address AS attacker_wallet, a.username AS attacker_name,
            d.wallet_address AS defender_wallet, d.username AS defender_name
     FROM pvp_raids r
     JOIN players a ON a.id = r.attacker_id
     JOIN players d ON d.id = r.defender_id
     WHERE r.status = "resolved"
     ORDER BY r.resolved_at DESC
     LIMIT 20'
)->fetchAll();

function shorten(string $addr): string {
    return strlen($addr) > 8 ? substr($addr, 0, 4) . '…' . substr($addr, -4) : $addr;
}
function display_name(array $row, string $wallet_col = 'wallet_address', string $name_col = 'username'): string {
    return htmlspecialchars($row[$name_col] ?? shorten($row[$wallet_col] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Moonbase – Leaderboard</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🌕</text></svg>">
  <link rel="stylesheet" href="/assets/css/main.css">
  <style>
    body { overflow-y: auto; }

    .lb-page {
      max-width: 1100px;
      margin: 0 auto;
      padding: 80px 16px 40px;
    }

    .lb-header {
      text-align: center;
      margin-bottom: 40px;
    }
    .lb-header h1 {
      font-size: 32px; font-weight: 800; letter-spacing: 3px;
      color: var(--accent-cyan); margin-bottom: 6px;
    }
    .lb-header p { color: var(--text-secondary); font-size: 14px; }

    .lb-nav {
      display: flex; gap: 10px; justify-content: center; margin-bottom: 32px;
      flex-wrap: wrap;
    }
    .lb-nav a {
      padding: 8px 20px;
      background: var(--bg-panel-light);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      color: var(--text-secondary);
      font-size: 13px; font-weight: 600; text-decoration: none;
      transition: background var(--transition), color var(--transition);
    }
    .lb-nav a:hover, .lb-nav a.active {
      background: var(--accent-blue); color: #fff;
      border-color: var(--accent-cyan);
    }

    .lb-section { margin-bottom: 48px; }
    .lb-section h2 {
      font-size: 18px; font-weight: 700; letter-spacing: 1px;
      color: var(--accent-cyan); margin-bottom: 16px;
      border-bottom: 1px solid var(--border); padding-bottom: 8px;
    }

    .lb-table {
      width: 100%; border-collapse: collapse;
      background: var(--bg-panel);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
    }
    .lb-table th {
      background: var(--bg-panel-light);
      padding: 10px 14px; text-align: left;
      font-size: 11px; font-weight: 700; letter-spacing: 1px;
      color: var(--text-secondary); text-transform: uppercase;
      border-bottom: 1px solid var(--border);
    }
    .lb-table td {
      padding: 10px 14px; font-size: 13px;
      border-bottom: 1px solid rgba(26,74,122,0.2);
      vertical-align: middle;
    }
    .lb-table tr:last-child td { border-bottom: none; }
    .lb-table tr:hover td { background: rgba(26,74,122,0.1); }

    .rank-badge {
      display: inline-flex; align-items: center; justify-content: center;
      width: 28px; height: 28px; border-radius: 50%;
      font-size: 12px; font-weight: 800;
    }
    .rank-1 { background: rgba(255,215,0,0.2);   color: #ffd700; border: 1px solid #ffd700; }
    .rank-2 { background: rgba(192,192,192,0.2); color: #c0c0c0; border: 1px solid #c0c0c0; }
    .rank-3 { background: rgba(205,127,50,0.2);  color: #cd7f32; border: 1px solid #cd7f32; }
    .rank-n { background: rgba(26,74,122,0.2);   color: var(--text-dim); border: 1px solid var(--border); }

    .alliance-tag {
      display: inline-block; padding: 1px 6px;
      background: rgba(74,223,255,0.1);
      border: 1px solid rgba(74,223,255,0.3);
      border-radius: 3px; font-size: 10px; font-weight: 700;
      color: var(--accent-cyan); margin-left: 6px;
    }

    .outcome-win  { color: var(--accent-green);  font-weight: 700; }
    .outcome-lose { color: var(--accent-red);     font-weight: 700; }
    .outcome-draw { color: var(--accent-orange);  font-weight: 700; }

    .back-link {
      display: inline-block; margin-bottom: 24px;
      color: var(--text-secondary); font-size: 13px;
      text-decoration: none;
    }
    .back-link:hover { color: var(--accent-cyan); }

    @media (max-width: 600px) {
      .lb-table th:nth-child(n+4),
      .lb-table td:nth-child(n+4) { display: none; }
    }
  </style>
</head>
<body>

<header id="hud" role="banner">
  <div class="hud-brand">🌕 MOONBASE</div>
  <div class="hud-right">
    <a href="/game.php" class="hud-btn" style="text-decoration:none">🚀 Play</a>
    <a href="/index.php" class="hud-btn" style="text-decoration:none">🔑 Login</a>
  </div>
</header>

<main class="lb-page">
  <div class="lb-header">
    <h1>🏆 LEADERBOARD</h1>
    <p>Top commanders of the Moon colony</p>
  </div>

  <!-- Players leaderboard -->
  <section class="lb-section" id="players">
    <h2>👤 Top Players</h2>
    <table class="lb-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Commander</th>
          <th>Alliance</th>
          <th>Level</th>
          <th>Experience</th>
          <th>MoonCoins</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($players as $i => $p): ?>
        <tr>
          <td>
            <span class="rank-badge <?= $i === 0 ? 'rank-1' : ($i === 1 ? 'rank-2' : ($i === 2 ? 'rank-3' : 'rank-n')) ?>">
              <?= $i + 1 ?>
            </span>
          </td>
          <td>
            <strong><?= display_name($p) ?></strong>
            <div style="font-size:10px;color:var(--text-dim);font-family:monospace">
              <?= htmlspecialchars(shorten($p['wallet_address'])) ?>
            </div>
          </td>
          <td>
            <?php if ($p['alliance_tag']): ?>
              <span class="alliance-tag"><?= htmlspecialchars($p['alliance_tag']) ?></span>
              <?= htmlspecialchars($p['alliance_name']) ?>
            <?php else: ?>
              <span class="text-dim">–</span>
            <?php endif; ?>
          </td>
          <td><span style="color:var(--accent-cyan);font-weight:700"><?= (int)$p['level'] ?></span></td>
          <td><?= number_format((int)$p['experience']) ?></td>
          <td><span style="color:var(--accent-orange)">🪙 <?= number_format((float)$p['mooncoin_balance'], 0) ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$players): ?>
        <tr><td colspan="6" class="text-center text-dim" style="padding:24px">No players yet</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <!-- Alliances leaderboard -->
  <section class="lb-section" id="alliances">
    <h2>⚔️ Top Alliances</h2>
    <?php if ($alliances): ?>
    <table class="lb-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Alliance</th>
          <th>Tag</th>
          <th>Members</th>
          <th>Total Level</th>
          <th>Treasury 🪙</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($alliances as $i => $al): ?>
        <tr>
          <td>
            <span class="rank-badge <?= $i === 0 ? 'rank-1' : ($i === 1 ? 'rank-2' : ($i === 2 ? 'rank-3' : 'rank-n')) ?>">
              <?= $i + 1 ?>
            </span>
          </td>
          <td><strong><?= htmlspecialchars($al['name']) ?></strong></td>
          <td><span class="alliance-tag"><?= htmlspecialchars($al['tag']) ?></span></td>
          <td><?= (int)$al['member_count'] ?></td>
          <td><span style="color:var(--accent-cyan)"><?= number_format((int)$al['total_level']) ?></span></td>
          <td><?= number_format((float)$al['mooncoin_bank'], 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p class="text-dim text-center" style="padding:24px">No alliances formed yet</p>
    <?php endif; ?>
  </section>

  <!-- Recent PvP raids -->
  <section class="lb-section" id="raids">
    <h2>⚡ Recent Raids</h2>
    <?php if ($raids): ?>
    <table class="lb-table">
      <thead>
        <tr>
          <th>Attacker</th>
          <th>Defender</th>
          <th>Outcome</th>
          <th>Atk / Def</th>
          <th>Loot</th>
          <th>When</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($raids as $r): ?>
        <?php
          $aname = $r['attacker_name'] ?? shorten($r['attacker_wallet'] ?? '');
          $dname = $r['defender_name'] ?? shorten($r['defender_wallet'] ?? '');
          $loot  = [];
          if ($r['loot_fuel']     > 0) $loot[] = '⛽ ' . number_format((float)$r['loot_fuel'],     0);
          if ($r['loot_minerals'] > 0) $loot[] = '💎 ' . number_format((float)$r['loot_minerals'], 0);
          if ($r['loot_metal']    > 0) $loot[] = '⚙️ '  . number_format((float)$r['loot_metal'],    0);
        ?>
        <tr>
          <td><?= htmlspecialchars($aname) ?></td>
          <td><?= htmlspecialchars($dname) ?></td>
          <td>
            <?php if ($r['outcome'] === 'attacker_win'): ?>
              <span class="outcome-win">⚔️ Attacker Win</span>
            <?php elseif ($r['outcome'] === 'defender_win'): ?>
              <span class="outcome-lose">🛡 Defender Win</span>
            <?php else: ?>
              <span class="outcome-draw">🤝 Draw</span>
            <?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:11px">
            <?= (int)$r['attack_power'] ?> / <?= (int)$r['defense_power'] ?>
          </td>
          <td style="font-size:11px">
            <?= $loot ? htmlspecialchars(implode(', ', $loot)) : '<span class="text-dim">none</span>' ?>
          </td>
          <td style="font-size:11px;color:var(--text-dim)">
            <?= htmlspecialchars(date('M j, H:i', strtotime($r['resolved_at'] ?? 'now'))) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p class="text-dim text-center" style="padding:24px">No raids recorded yet</p>
    <?php endif; ?>
  </section>
</main>

</body>
</html>
