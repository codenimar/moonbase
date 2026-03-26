<?php
/**
 * game.php – Main game page (requires authentication)
 */
require_once __DIR__ . '/includes/auth.php';

// Verify session – redirect to login if not authenticated
$session = $_SERVER['HTTP_X_SESSION_TOKEN']
        ?? ($_COOKIE['moonbase_session'] ?? null);
$authed  = false;
$wallet  = null;

if ($session) {
    $w = verify_session_token($session);
    if ($w) {
        $db    = get_db();
        $stmt  = $db->prepare('SELECT id FROM players WHERE wallet_address = ? AND session_token = ? AND (session_expires IS NULL OR session_expires > NOW())');
        $stmt->execute([$w, $session]);
        if ($stmt->fetch()) {
            $authed = true;
            $wallet = $w;
        }
    }
}

if (!$authed) {
    header('Location: /index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Moonbase – Command Center</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🌕</text></svg>">
  <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>

<!-- Loading overlay -->
<div id="loading-overlay" role="status" aria-live="polite">
  <div class="login-logo" style="margin-bottom:12px">🌕</div>
  <div class="loader-ring"></div>
  <p style="color:var(--text-secondary);font-size:14px">Loading Moonbase…</p>
  <div style="width:200px;height:4px;background:rgba(26,74,122,0.3);border-radius:2px;margin-top:8px;overflow:hidden">
    <div id="loading-progress" style="height:100%;width:0;background:var(--accent-cyan);border-radius:2px;transition:width 0.2s"></div>
  </div>
</div>

<!-- HUD (top bar) -->
<header id="hud" role="banner">
  <div class="hud-brand">🌕 MOONBASE</div>

  <nav class="hud-resources" aria-label="Resources">
    <div class="res-item" title="Fuel / Storage">
      <span class="res-icon">⛽</span>
      <span class="res-val"><span id="hud-fuel">–</span></span>
      <span class="res-cap">/ <span id="hud-fuel-cap">–</span></span>
      <span class="res-rate" id="hud-fuel-rate"></span>
    </div>
    <div class="res-item" title="Minerals">
      <span class="res-icon">💎</span>
      <span class="res-val"><span id="hud-minerals">–</span></span>
    </div>
    <div class="res-item" title="Metal">
      <span class="res-icon">⚙</span>
      <span class="res-val"><span id="hud-metal">–</span></span>
    </div>
    <div class="res-item" title="MoonCoins">
      <span class="res-icon">🪙</span>
      <span class="res-val"><span id="hud-mooncoin">–</span></span>
    </div>
  </nav>

  <div class="hud-right">
    <span class="hud-level" id="hud-level">LVL –</span>
    <button class="hud-btn" id="btn-build" title="Build Menu (B)" aria-label="Open build menu">🏗 Build</button>
    <button class="hud-btn" id="btn-market" title="Marketplace" aria-label="Open marketplace">🏪 Market</button>
    <button class="hud-btn" id="btn-events" title="Events" aria-label="Open events">🏆 Events</button>
    <button class="hud-btn" id="btn-token" title="Token Status" aria-label="Token status">💊 Token</button>
    <span class="hud-wallet" id="hud-wallet" title="Your wallet address" aria-label="Wallet address">
      <?= htmlspecialchars(substr($wallet, 0, 4) . '…' . substr($wallet, -4)) ?>
    </span>
    <button class="hud-btn btn-danger" id="btn-logout" aria-label="Logout">⏻</button>
  </div>
</header>

<!-- Main game canvas -->
<main id="game-container" role="main" aria-label="Game world">
  <!-- Phaser canvas is injected here -->
</main>

<!-- Side panel -->
<aside id="side-panel" role="complementary" aria-label="Game panel">
  <div class="panel-header">
    <h3 id="panel-title">Build Menu</h3>
    <button class="panel-close" id="panel-close-btn" aria-label="Close panel">✕</button>
  </div>
  <div class="panel-tabs" role="tablist">
    <div class="panel-tab active" role="tab" data-panel="build-view" aria-selected="true">🏗 Build</div>
    <div class="panel-tab" role="tab" data-panel="market-view" aria-selected="false">🏪 Market</div>
    <div class="panel-tab" role="tab" data-panel="events-view" aria-selected="false">🏆 Events</div>
    <div class="panel-tab" role="tab" data-panel="token-view" aria-selected="false">💊 Token</div>
  </div>

  <!-- Build view -->
  <div id="build-view" class="panel-body side-panel-view" role="tabpanel">
    <p class="text-dim mb-2" style="font-size:12px">Select a building to place on the map. Press ESC to cancel.</p>
    <div id="build-menu-list"></div>
  </div>

  <!-- Market view -->
  <div id="market-view" class="panel-body side-panel-view hidden" role="tabpanel">
    <div style="display:flex;gap:8px;margin-bottom:12px">
      <button class="btn btn-primary" style="flex:1" id="btn-create-listing">+ List Resource</button>
      <button class="btn btn-secondary" style="flex:1" onclick="UI.refreshMarketPanel()">🔄 Refresh</button>
    </div>
    <div id="market-list"></div>
  </div>

  <!-- Events view -->
  <div id="events-view" class="panel-body side-panel-view hidden" role="tabpanel">
    <button class="btn btn-secondary w-full mb-2" onclick="UI.refreshEventsPanel()">🔄 Refresh</button>
    <div id="events-list"></div>
  </div>

  <!-- Token view -->
  <div id="token-view" class="panel-body side-panel-view hidden" role="tabpanel">
    <div id="token-status"></div>
  </div>
</aside>

<!-- Toast container -->
<div id="toast-container" role="alert" aria-live="assertive"></div>

<!-- ── Scripts ─────────────────────────────────────────────────────────── -->
<!-- bs58 (Base58 for Solana signatures) -->
<script src="https://cdn.jsdelivr.net/npm/bs58@5.0.0/dist/bs58.min.js"></script>
<!-- Phaser 3 game engine -->
<script src="https://cdn.jsdelivr.net/npm/phaser@3.70.0/dist/phaser.min.js"></script>
<!-- Game scripts -->
<script src="/assets/js/wallet.js"></script>
<script src="/assets/js/ui.js"></script>
<script src="/assets/js/game.js"></script>

<script>
// ── Bootstrap ──────────────────────────────────────────────────────────────
(async () => {
  // Restore session from PHP session
  const session = document.cookie.split(';')
    .map(c => c.trim()).find(c => c.startsWith('moonbase_session='));
  if (session) localStorage.setItem('moonbase_session', session.split('=')[1]);

  // Load initial game state
  const state = await apiGet('/api/game_state.php');
  if (state.error) {
    window.location.href = '/index.php';
    return;
  }
  GameState.player      = state.player;
  GameState.buildings   = state.buildings;
  GameState.buildingDefs = state.building_defs;
  GameState.events      = state.events;
  GameState.fuelRate    = calcFuelRate(state.player, state.buildings, state.building_defs);

  UI.updateHud(state.player, GameState.fuelRate);

  // Init Phaser
  initGame();

  // HUD buttons
  document.getElementById('btn-build').addEventListener('click', () => {
    switchTab('build-view');
    UI.openPanel('build-view');
    UI.renderBuildMenu(GameState.buildingDefs, GameState.player, (type, def) => {
      GameState.mode        = 'build';
      GameState.selectedType = type;
      UI.closePanel();
      UI.toast(`Click a tile to place ${def.name}. ESC to cancel.`, 'info');
    });
  });

  document.getElementById('btn-market').addEventListener('click', () => {
    switchTab('market-view');
    UI.openPanel('market-view');
    UI.refreshMarketPanel();
  });

  document.getElementById('btn-events').addEventListener('click', () => {
    switchTab('events-view');
    UI.openPanel('events-view');
    UI.refreshEventsPanel();
  });

  document.getElementById('btn-token').addEventListener('click', () => {
    switchTab('token-view');
    UI.openPanel('token-view');
    UI.refreshTokenPanel(GameState.player);
  });

  document.getElementById('panel-close-btn').addEventListener('click', UI.closePanel);

  document.querySelectorAll('.panel-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      switchTab(tab.dataset.panel);
      const panelFns = {
        'market-view': UI.refreshMarketPanel,
        'events-view': UI.refreshEventsPanel,
        'token-view':  () => UI.refreshTokenPanel(GameState.player),
      };
      panelFns[tab.dataset.panel]?.();
    });
  });

  // Create market listing
  document.getElementById('btn-create-listing').addEventListener('click', () => {
    if (!GameState.buildings.some(b => b.building_type === 'market')) {
      UI.toast('You need to build a Marketplace first!', 'error');
      return;
    }
    UI.showModal({
      title: 'List Resource for Sale',
      body: `
        <div class="mb-3">
          <label style="display:block;font-size:13px;margin-bottom:4px">Resource</label>
          <select id="sell-resource" class="btn btn-secondary w-full" style="cursor:pointer">
            <option value="fuel">⛽ Fuel</option>
            <option value="minerals">💎 Minerals</option>
            <option value="metal">⚙ Metal</option>
          </select>
        </div>
        <div class="mb-3">
          <label style="display:block;font-size:13px;margin-bottom:4px">Amount</label>
          <input id="sell-amount" type="number" min="1" step="1" placeholder="Amount to sell"
            style="width:100%;padding:8px;background:var(--bg-panel-light);border:1px solid var(--border);border-radius:4px;color:var(--text-primary);font-size:14px">
        </div>
        <div class="mb-3">
          <label style="display:block;font-size:13px;margin-bottom:4px">Price per unit (MoonCoins)</label>
          <input id="sell-price" type="number" min="0.01" step="0.01" placeholder="Price per unit"
            style="width:100%;padding:8px;background:var(--bg-panel-light);border:1px solid var(--border);border-radius:4px;color:var(--text-primary);font-size:14px">
        </div>
        <p class="text-dim" style="font-size:11px">Note: A 10% market fee is deducted from the seller's proceeds.</p>`,
      buttons: [
        { label: '📋 List for Sale', action: 'list', class: 'btn-success', onClick: async (close) => {
          const resource = document.getElementById('sell-resource').value;
          const amount   = parseFloat(document.getElementById('sell-amount').value);
          const price    = parseFloat(document.getElementById('sell-price').value);
          if (!amount || !price) { UI.toast('Please fill all fields', 'error'); return; }
          const res = await apiPost('/api/market.php', {
            action: 'list', resource_type: resource, amount, price_per_unit: price
          });
          if (res.success) {
            UI.toast('Listing created!', 'success');
            close();
            UI.refreshMarketPanel();
          } else {
            UI.toast(res.error || 'Failed', 'error');
          }
        }},
        { label: 'Cancel', action: 'close', class: 'btn-secondary' },
      ],
    });
  });

  document.getElementById('btn-logout').addEventListener('click', async () => {
    if (confirm('Log out of Moonbase?')) {
      await WalletManager.disconnect();
      window.location.href = '/index.php';
    }
  });

  // Keyboard shortcut B = build menu
  document.addEventListener('keydown', (e) => {
    if (e.key === 'b' || e.key === 'B') {
      document.getElementById('btn-build').click();
    }
  });
})();

function switchTab(panelId) {
  document.querySelectorAll('.panel-tab').forEach(t => {
    const active = t.dataset.panel === panelId;
    t.classList.toggle('active', active);
    t.setAttribute('aria-selected', active ? 'true' : 'false');
  });
  document.querySelectorAll('.side-panel-view').forEach(v => {
    v.classList.toggle('hidden', v.id !== panelId);
  });
  document.getElementById('panel-title').textContent =
    { 'build-view': '🏗 Build Menu', 'market-view': '🏪 Marketplace',
      'events-view': '🏆 Events', 'token-view': '💊 Token Status' }[panelId] || 'Panel';
  UI.openPanel(panelId);
}
</script>
</body>
</html>
