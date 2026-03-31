<?php
/**
 * game.php – Main game page (requires authentication)
 * FIXED VERSION – Ready to copy-paste
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
        $authed = true;
        $wallet = $w;
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
  <p id="loading-status" style="color:var(--accent-cyan);font-size:12px;margin:4px 0 0;min-height:1.4em"></p>
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
    <button class="hud-btn" id="btn-research" title="Research Tree" aria-label="Research tree">🔬 Research</button>
    <button class="hud-btn" id="btn-raids" title="PvP Raids" aria-label="PvP Raids">⚔️ Raids</button>
    <button class="hud-btn" id="btn-alliance" title="Alliance" aria-label="Alliance">🏰 Alliance</button>
    <button class="hud-btn" id="btn-mooncoin" title="MoonCoin / Token" aria-label="MoonCoin">🪙 MC</button>
    <button class="hud-btn" id="btn-token" title="$PUMPVILLE Token" aria-label="Token status">💊 Token</button>
    <a href="/leaderboard.php" class="hud-btn" title="Leaderboard" aria-label="Leaderboard" style="text-decoration:none" target="_blank">🏅 Board</a>
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
    <div class="panel-tab active" role="tab" data-panel="build-view" aria-selected="true">🏗</div>
    <div class="panel-tab" role="tab" data-panel="market-view" aria-selected="false">🏪</div>
    <div class="panel-tab" role="tab" data-panel="events-view" aria-selected="false">🏆</div>
    <div class="panel-tab" role="tab" data-panel="research-view" aria-selected="false">🔬</div>
    <div class="panel-tab" role="tab" data-panel="raids-view" aria-selected="false">⚔️</div>
    <div class="panel-tab" role="tab" data-panel="alliance-view" aria-selected="false">🏰</div>
    <div class="panel-tab" role="tab" data-panel="mooncoin-view" aria-selected="false">🪙</div>
    <div class="panel-tab" role="tab" data-panel="token-view" aria-selected="false">💊</div>
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

  <!-- Research view -->
  <div id="research-view" class="panel-body side-panel-view hidden" role="tabpanel">
    <button class="btn btn-secondary w-full mb-2" onclick="UI.refreshResearchPanel()">🔄 Refresh</button>
    <div id="research-list"></div>
  </div>

  <!-- Raids view -->
  <div id="raids-view" class="panel-body side-panel-view hidden" role="tabpanel">
    <div class="flex gap-1 mb-2">
      <button class="btn btn-danger" style="flex:1" onclick="UI.initiateRaid()">⚔️ Launch Raid</button>
      <button class="btn btn-secondary" style="flex:1" onclick="UI.refreshRaidsPanel()">🔄 Refresh</button>
    </div>
    <div id="raids-list"></div>
  </div>

  <!-- Alliance view -->
  <div id="alliance-view" class="panel-body side-panel-view hidden" role="tabpanel">
    <button class="btn btn-secondary w-full mb-2" onclick="UI.refreshAlliancePanel()">🔄 Refresh</button>
    <div id="alliance-content"></div>
  </div>

  <!-- MoonCoin view -->
  <div id="mooncoin-view" class="panel-body side-panel-view hidden" role="tabpanel">
    <div id="mooncoin-status"></div>
  </div>
</aside>

<!-- Toast container -->
<div id="toast-container" role="alert" aria-live="assertive"></div>

<!-- ── Scripts ─────────────────────────────────────────────────────────── -->
<!-- Phaser 3 from CDN (fixed) -->
<script src="https://cdn.jsdelivr.net/npm/phaser@3.70.0/dist/phaser.min.js"></script>

<!-- Session token bridge -->
<script>
(function () {
  var tok = "<?= htmlspecialchars($session ?? '') ?>";
  if (tok) {
    try { localStorage.setItem('moonbase_session', tok); } catch (_) {}
  }
})();
</script>

<!-- Game scripts -->
<script src="/assets/js/wallet.js?v=1774992237"></script>
<script src="/assets/js/ui.js"></script>
<script src="/assets/js/game.js"></script>

<script>
// Bridge fee constant exposed to JS
const MOONCOIN_BRIDGE_FEE = 0.5;

// Loading helpers
function setLoadingStatus(msg, isError = false) {
  const el = document.getElementById('loading-status');
  if (el) {
    el.textContent = msg;
    if (isError) el.style.color = '#ff4444';
  }
  console.log('%c[Loading]', 'color:#00ddff', msg);
}

function _bootstrapFailed(err) {
  console.error('[Moonbase] Bootstrap failed:', err);
  setLoadingStatus('❌ ' + (err.message || err), true);
  setTimeout(() => window.location.href = '/index.php', 5000);
}

// MAIN BOOTSTRAP (fixed + safety net)
(async () => {
  try {
    setLoadingStatus('Fetching game state from server…');

    const state = await apiGet('/api/game_state.php');
    if (!state || state.error) throw new Error(state?.error || 'Server error');

    window.GameState = window.GameState || {};
    GameState.player = state.player;
    GameState.buildings = state.buildings || [];
    GameState.buildingDefs = state.building_defs || {};
    GameState.events = state.events || [];

    setLoadingStatus('Initialising game engine…');

    if (typeof initGame === 'function') {
      initGame();
      console.log('%c✅ Moonbase fully loaded!', 'color:lime;font-size:16px');
    } else {
      throw new Error('initGame() not found – check assets/js/game.js');
    }
  } catch (err) {
    _bootstrapFailed(err);
  } finally {
    // Safety net – force hide overlay after 8 seconds
    setTimeout(() => {
      const lo = document.getElementById('loading-overlay');
      if (lo && !lo.classList.contains('hidden')) {
        lo.classList.add('hidden');
        console.warn('[Moonbase] Forced hide of loading overlay (safety net)');
      }
    }, 8000);
  }
})();
</script>
</body>
</html>
