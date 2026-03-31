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
<!-- Phaser 3 game engine -->
<script src="https://cdn.jsdelivr.net/npm/phaser@3.70.0/dist/phaser.min.js"></script>
<!-- Seed localStorage with the validated session token before wallet.js loads.
     The session cookie is HttpOnly, so document.cookie cannot expose it to JS.
     PHP already verified $session at the top of this file, so it is safe to
     embed it here and let wallet.js pick it up via localStorage. -->
<script>
(function () {
  var tok = <?= json_encode($session, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  if (tok) {
    try { localStorage.setItem('moonbase_session', tok); } catch (_) {}
  }
})();
</script>
<!-- Game scripts -->
<script src="/assets/js/wallet.js?v=<?= @filemtime(__DIR__ . '/assets/js/wallet.js') ?>"></script>
<script src="/assets/js/ui.js"></script>
<script src="/assets/js/game.js"></script>

<script>
// Bridge fee constant exposed to JS
const MOONCOIN_BRIDGE_FEE = <?= (float)MOONCOIN_BRIDGE_FEE_PCT ?>;

// ── Loading overlay helpers ────────────────────────────────────────────────
// Redirect to login, with a short visible error message so the user is never
// silently bounced.  Also tracks repeated failures in sessionStorage so an
// infinite redirect loop (game.php fails → index.php redirects → game.php
// fails → …) is broken after MAX_LOAD_ATTEMPTS consecutive failures.
const MAX_LOAD_ATTEMPTS = 3;

function _safeSessionGet(key) {
  try { return sessionStorage.getItem(key); } catch (_) { return null; }
}
function _safeSessionSet(key, value) {
  try { sessionStorage.setItem(key, value); } catch (_) {}
}
function _safeSessionRemove(key) {
  try { sessionStorage.removeItem(key); } catch (_) {}
}
function _safeLocalRemove(key) {
  try { localStorage.removeItem(key); } catch (_) {}
}

const _loadStart = Date.now();

function setLoadingStatus(msg, isError) {
  const elapsed = ((Date.now() - _loadStart) / 1000).toFixed(2);
  const el = document.getElementById('loading-status');
  if (el) {
    el.textContent = `[+${elapsed}s] ${msg}`;
    el.style.color = isError ? 'var(--accent-red)' : 'var(--accent-cyan)';
  }
  if (isError) {
    console.error(`[Moonbase +${elapsed}s] ${msg}`);
  } else {
    console.info(`[Moonbase +${elapsed}s] Loading step: ${msg}`);
  }
}

// Catch uncaught synchronous errors (e.g. missing globals, script parse errors)
window.onerror = function (msg, src, line, col, err) {
  const detail = `${msg} (${src}:${line}:${col})`;
  setLoadingStatus(`JS error: ${detail}`, true);
  console.error('[Moonbase] Uncaught error:', detail, err);
  return false;
};

// Catch unhandled promise rejections (e.g. network failures not caught by try/catch)
window.addEventListener('unhandledrejection', function (event) {
  const reason = event.reason;
  const msg = reason?.message || String(reason);
  setLoadingStatus(`Unhandled rejection: ${msg}`, true);
  console.error('[Moonbase] Unhandled rejection:', reason);
});

// Log browser capabilities once so the console snapshot includes them
(function _logCapabilities() {
  const webgl = (() => {
    try {
      const c = document.createElement('canvas');
      return !!(c.getContext('webgl') || c.getContext('experimental-webgl'));
    } catch (_) { return false; }
  })();
  const canvas2d = (() => {
    try { return !!document.createElement('canvas').getContext('2d'); } catch (_) { return false; }
  })();
  console.info('[Moonbase] Browser capabilities:', {
    webgl,
    canvas2d,
    language:  navigator.language,
    cookieEnabled: navigator.cookieEnabled,
  });
  if (!webgl) {
    setLoadingStatus('Warning: WebGL not detected – game may not render correctly', true);
  }
})();

function _showLoadError(message, redirectDelay = 4000) {
  const lo = document.getElementById('loading-overlay');
  if (!lo) { window.location.href = '/index.php'; return; }
  lo.innerHTML = `
    <div class="login-logo" style="margin-bottom:12px">🌕</div>
    <p style="color:var(--accent-red);font-size:16px;font-weight:700;margin:0">Loading failed</p>
    <p style="color:var(--text-secondary);font-size:13px;margin:8px 0 0">${message}</p>
    <p style="color:var(--text-dim);font-size:13px;margin:6px 0 0">Redirecting to login in ${redirectDelay / 1000}s…</p>
    <button id="_retry-btn" aria-label="Retry loading the game" style="margin-top:14px;padding:8px 20px;background:var(--accent-cyan);border:none;border-radius:6px;color:#000;font-weight:700;cursor:pointer;font-size:13px">🔄 Retry now</button>
    <a href="/index.php" style="display:block;margin-top:10px;color:var(--text-dim);font-size:12px">← Back to login</a>`;
  document.getElementById('_retry-btn')?.addEventListener('click', () => window.location.reload());
  setTimeout(() => { window.location.href = '/index.php'; }, redirectDelay);
}

function _bootstrapFailed(reason) {
  console.error('[Moonbase] Bootstrap failed:', reason);
  const attempts = parseInt(_safeSessionGet('_mb_load_attempts') || '0', 10) + 1;
  if (attempts >= MAX_LOAD_ATTEMPTS) {
    // Too many consecutive failures – clear state and stop the redirect loop.
    _safeSessionRemove('_mb_load_attempts');
    _safeLocalRemove('moonbase_session');
    _showLoadError(
      `Unable to start the game after ${MAX_LOAD_ATTEMPTS} attempts. Please check the browser console for details.`,
      6000,
    );
  } else {
    _safeSessionSet('_mb_load_attempts', String(attempts));
    _showLoadError(String(reason && reason.message ? reason.message : reason), 3000);
  }
}

// ── Bootstrap ──────────────────────────────────────────────────────────────
(async () => {
  try {
    setLoadingStatus('Fetching game state from server…');
    const state = await apiGet('/api/game_state.php');
    if (!state || state.error) throw new Error(state?.error || 'Server error');

    // ... (rest of your existing code: populate GameState, UI.updateHud, initGame(), wiring buttons ...)

    console.info('[Moonbase] Bootstrap complete – awaiting Phaser scenes…');
  } catch (err) {
    _bootstrapFailed(err);
  } finally {
    // Safety net: force-hide loading if Phaser didn't take over
    setTimeout(() => {
      const lo = document.getElementById('loading-overlay');
      if (lo && !lo.classList.contains('hidden')) {
        lo.classList.add('hidden');
        console.warn('[Moonbase] Forced hide of loading overlay (safety net)');
      }
    }, 8000);
  }
})();

  setLoadingStatus('Initialising game state…');
  GameState.player       = state.player;
  GameState.buildings    = state.buildings;
  GameState.buildingDefs = state.building_defs;
  GameState.events       = state.events;
  GameState.fuelRate     = calcFuelRate(state.player, state.buildings, state.building_defs);
  console.info('[Moonbase] Game state initialised – hasPlayer:', !!state.player,
    '| buildings:', state.buildings?.length,
    '| defs:', Object.keys(state.building_defs || {}).length);

  setLoadingStatus('Updating HUD…');
  UI.updateHud(state.player, GameState.fuelRate);

  // Init Phaser
  setLoadingStatus('Starting game engine…');
  console.info('[Moonbase] Calling initGame() …');
  initGame();
  console.info('[Moonbase] initGame() returned');

  // ── HUD button wiring ───────────────────────────────────────────────────
  setLoadingStatus('Wiring UI controls…');
  console.info('[Moonbase] Wiring HUD buttons…');
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

  document.getElementById('btn-research').addEventListener('click', () => {
    switchTab('research-view');
    UI.openPanel('research-view');
    UI.refreshResearchPanel();
  });

  document.getElementById('btn-raids').addEventListener('click', () => {
    switchTab('raids-view');
    UI.openPanel('raids-view');
    UI.refreshRaidsPanel();
  });

  document.getElementById('btn-alliance').addEventListener('click', () => {
    switchTab('alliance-view');
    UI.openPanel('alliance-view');
    UI.refreshAlliancePanel();
  });

  document.getElementById('btn-mooncoin').addEventListener('click', () => {
    switchTab('mooncoin-view');
    UI.openPanel('mooncoin-view');
    UI.refreshMooncoinPanel(GameState.player);
  });

  document.getElementById('btn-token').addEventListener('click', () => {
    switchTab('token-view');
    UI.openPanel('token-view');
    UI.refreshTokenPanel(GameState.player);
  });

  document.getElementById('panel-close-btn').addEventListener('click', UI.closePanel);

  document.querySelectorAll('.panel-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      const panelFns = {
        'market-view':   UI.refreshMarketPanel,
        'events-view':   UI.refreshEventsPanel,
        'research-view': UI.refreshResearchPanel,
        'raids-view':    UI.refreshRaidsPanel,
        'alliance-view': UI.refreshAlliancePanel,
        'mooncoin-view': () => UI.refreshMooncoinPanel(GameState.player),
        'token-view':    () => UI.refreshTokenPanel(GameState.player),
      };
      panelFns[tab.dataset.panel]?.();
      switchTab(tab.dataset.panel);
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
  console.info('[Moonbase] Bootstrap complete – awaiting Phaser scenes…');
  } catch (err) {
    // Any uncaught error during bootstrap (network failure, API error,
    // missing Phaser, etc.) would otherwise leave the loading screen visible
    // forever.  Show an error message then redirect to login so the user
    // can try again.
    _bootstrapFailed(err);
  }
})();

// ── Phaser / loading fallback ──────────────────────────────────────────────
// If the loading overlay is still visible 25 s after page load (e.g. because
// Phaser failed to initialise its renderer or the scene lifecycle stalled in
// a way that bypasses our try/catch), show a helpful error message so the
// user is never silently stuck.
setTimeout(() => {
  const lo = document.getElementById('loading-overlay');
  if (lo && !lo.classList.contains('hidden')) {
    const elapsed = ((Date.now() - _loadStart) / 1000).toFixed(1);
    console.error(`[Moonbase] Loading timed out after ${elapsed}s – overlay still visible`);
    // Route through _bootstrapFailed so _mb_load_attempts is incremented and
    // the redirect-loop breaker eventually fires (prevents an infinite
    // index.php → game.php loop when the Phaser scene lifecycle stalls).
    _bootstrapFailed(new Error('The game engine failed to start. Check that WebGL/Canvas is enabled in your browser.'));
  }
}, 25000);

function switchTab(panelId) {
  document.querySelectorAll('.panel-tab').forEach(t => {
    const active = t.dataset.panel === panelId;
    t.classList.toggle('active', active);
    t.setAttribute('aria-selected', active ? 'true' : 'false');
  });
  document.querySelectorAll('.side-panel-view').forEach(v => {
    v.classList.toggle('hidden', v.id !== panelId);
  });
  const labels = {
    'build-view':    '🏗 Build Menu',
    'market-view':   '🏪 Marketplace',
    'events-view':   '🏆 Events',
    'research-view': '🔬 Research',
    'raids-view':    '⚔️ PvP Raids',
    'alliance-view': '🏰 Alliance',
    'mooncoin-view': '🪙 MoonCoin',
    'token-view':    '💊 Token Status',
  };
  document.getElementById('panel-title').textContent = labels[panelId] || 'Panel';
  UI.openPanel(panelId);
}
</script>
<script>
  alert("🚀 JS TEST — If you see this popup, JavaScript IS running");
  console.log("%c✅ JS TEST SUCCESS — bootstrap should run next", "color:lime;font-size:18px");
</script>
</body>
</html>
