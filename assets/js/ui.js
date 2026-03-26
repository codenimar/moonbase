/**
 * ui.js – HUD, panels, modals, toasts
 */

const UI = (() => {
  // ── Toast notifications ──────────────────────────────────────────────────
  function toast(message, type = 'info', duration = 4000) {
    const container = document.getElementById('toast-container');
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    const icons = { success: '✅', error: '❌', info: 'ℹ️' };
    el.innerHTML = `<span>${icons[type] || '•'}</span><span>${escapeHtml(message)}</span>`;
    container.appendChild(el);
    setTimeout(() => {
      el.style.animation = 'none';
      el.style.opacity   = '0';
      el.style.transition = 'opacity 0.3s';
      setTimeout(() => el.remove(), 350);
    }, duration);
  }

  // ── Modal ─────────────────────────────────────────────────────────────────
  let _modalStack = [];

  function showModal({ title, body, buttons = [], onClose }) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
      <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-header">
          <h2>${escapeHtml(title)}</h2>
          <button class="btn btn-secondary panel-close" data-action="close">✕</button>
        </div>
        <div class="modal-body">${body}</div>
        ${buttons.length ? `<div class="modal-footer">${buttons.map(b =>
          `<button class="btn ${b.class || 'btn-secondary'}" data-action="${b.action}">${escapeHtml(b.label)}</button>`
        ).join('')}</div>` : ''}
      </div>`;

    document.body.appendChild(overlay);
    requestAnimationFrame(() => overlay.classList.add('visible'));

    const close = () => {
      overlay.classList.remove('visible');
      setTimeout(() => overlay.remove(), 220);
      onClose?.();
      _modalStack.pop();
    };

    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) close();
    });

    overlay.querySelectorAll('[data-action]').forEach(btn => {
      btn.addEventListener('click', () => {
        const action = btn.dataset.action;
        if (action === 'close') { close(); return; }
        const def = buttons.find(b => b.action === action);
        if (def?.onClick) def.onClick(close);
      });
    });

    _modalStack.push({ overlay, close });
    return { close };
  }

  function closeTopModal() {
    _modalStack[_modalStack.length - 1]?.close();
  }

  // ── HUD update ────────────────────────────────────────────────────────────
  function updateHud(player, fuelRate) {
    const set = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };

    const fmt = (n) => parseFloat(n).toLocaleString(undefined, { maximumFractionDigits: 0 });

    set('hud-fuel',     fmt(player.fuel));
    set('hud-fuel-cap', fmt(player.fuel_storage_cap));
    set('hud-minerals', fmt(player.minerals));
    set('hud-metal',    fmt(player.metal));
    set('hud-mooncoin', fmt(player.mooncoin_balance));
    set('hud-level',    `LVL ${player.level}`);
    set('hud-wallet',   shortenAddress(player.wallet_address));

    if (fuelRate !== undefined) {
      set('hud-fuel-rate', `+${fuelRate.toFixed(2)}/min`);
    }
  }

  // ── Side panel management ─────────────────────────────────────────────────
  function openPanel(panelId) {
    document.querySelectorAll('.side-panel-view').forEach(el => el.classList.add('hidden'));
    const view = document.getElementById(panelId);
    if (view) view.classList.remove('hidden');
    document.getElementById('side-panel')?.classList.add('open');
  }

  function closePanel() {
    document.getElementById('side-panel')?.classList.remove('open');
  }

  // ── Build menu (building list) ────────────────────────────────────────────
  function renderBuildMenu(defs, player, onSelect) {
    const container = document.getElementById('build-menu-list');
    if (!container) return;
    container.innerHTML = '';
    Object.entries(defs).forEach(([type, def]) => {
      const locked  = player.level < def.requires_level;
      const cost    = def.levels[1].cost;
      const canFuel = parseFloat(player.fuel)             >= cost.fuel;
      const canMin  = parseFloat(player.minerals)         >= (cost.minerals || 0);
      const canMoon = parseFloat(player.mooncoin_balance) >= cost.mooncoin;

      const card = document.createElement('div');
      card.className = `building-card ${locked ? 'locked' : ''}`;
      card.dataset.type = type;
      card.innerHTML = `
        <div class="building-card-header">
          <img class="building-card-icon"
               src="/assets/sprites/buildings/${type}.png"
               alt="${escapeHtml(def.name)}">
          <div>
            <div class="building-card-name">${escapeHtml(def.name)}</div>
            <div class="building-card-level">${locked ? `🔒 Req. Level ${def.requires_level}` : 'Available'}</div>
          </div>
        </div>
        <div class="building-costs">
          ${cost.fuel     ? `<span class="cost-tag ${canFuel ? 'affordable' : 'unaffordable'}">⛽ ${cost.fuel}</span>`      : ''}
          ${cost.minerals ? `<span class="cost-tag ${canMin  ? 'affordable' : 'unaffordable'}">💎 ${cost.minerals}</span>` : ''}
          ${cost.mooncoin ? `<span class="cost-tag ${canMoon ? 'affordable' : 'unaffordable'}">🪙 ${cost.mooncoin}</span>` : ''}
        </div>
        <div class="building-desc">${escapeHtml(def.description)}</div>`;

      if (!locked) {
        card.addEventListener('click', () => onSelect(type, def));
      }
      container.appendChild(card);
    });
  }

  // ── Market panel ──────────────────────────────────────────────────────────
  async function refreshMarketPanel() {
    const container = document.getElementById('market-list');
    if (!container) return;
    container.innerHTML = '<p class="text-dim text-center">Loading...</p>';
    const data = await apiGet('/api/market.php');
    if (data.error) { container.innerHTML = `<p class="text-red">${escapeHtml(data.error)}</p>`; return; }

    if (!data.listings.length) {
      container.innerHTML = '<p class="text-dim text-center">No active listings</p>';
      return;
    }
    container.innerHTML = data.listings.map(l => `
      <div class="market-listing">
        <div>
          <div class="resource">${resourceIcon(l.resource_type)} ${l.resource_type}</div>
          <div class="amount">${parseFloat(l.amount).toLocaleString()} units</div>
          <div class="seller">${shortenAddress(l.wallet_address || '')}</div>
        </div>
        <div>
          <div class="price">🪙 ${(parseFloat(l.amount) * parseFloat(l.price_per_unit)).toLocaleString()}</div>
          <div class="text-dim" style="font-size:11px">${parseFloat(l.price_per_unit).toFixed(2)}/unit</div>
          <button class="btn btn-primary mt-1" style="padding:5px 10px;font-size:11px"
                  onclick="GameUI.buyListing(${l.id}, ${parseFloat(l.amount) * parseFloat(l.price_per_unit)})">
            Buy
          </button>
        </div>
      </div>`).join('');
  }

  async function buyListing(listingId, total) {
    const ok = confirm(`Buy this listing for ${total.toLocaleString()} MoonCoins? (10% fee applies to seller)`);
    if (!ok) return;
    const res = await apiPost('/api/market.php', { action: 'buy', listing_id: listingId });
    if (res.success) {
      toast(`Purchase complete! Fee paid to market: ${res.fee_paid} MC`, 'success');
      refreshMarketPanel();
    } else {
      toast(res.error || 'Purchase failed', 'error');
    }
  }

  // ── Events panel ─────────────────────────────────────────────────────────
  async function refreshEventsPanel() {
    const container = document.getElementById('events-list');
    if (!container) return;
    container.innerHTML = '<p class="text-dim text-center">Loading...</p>';
    const data = await apiGet('/api/events.php');
    if (data.error) { container.innerHTML = `<p class="text-red">${escapeHtml(data.error)}</p>`; return; }

    if (!data.events.length) {
      container.innerHTML = '<p class="text-dim text-center">No active events</p>';
      return;
    }
    container.innerHTML = data.events.map(ev => {
      const pct = Math.min(100, (parseFloat(ev.current_amount) / parseFloat(ev.target_amount)) * 100);
      const myContrib = parseFloat(ev.contribution || 0);
      return `
        <div class="event-card">
          <h4>${escapeHtml(ev.name)}</h4>
          <p>${escapeHtml(ev.description || '')}</p>
          <div class="event-progress">
            <div class="event-progress-fill" style="width:${pct.toFixed(1)}%"></div>
          </div>
          <div class="event-meta">
            <span>📊 ${pct.toFixed(1)}% complete</span>
            <span>👥 ${ev.participant_count} players</span>
            <span>⏰ ${ev.status === 'upcoming' ? 'Starts ' + formatDate(ev.start_time) : 'Ends ' + formatDate(ev.end_time)}</span>
          </div>
          <div class="flex items-center justify-between mb-2">
            <span class="prize-badge">🏆 ${parseFloat(ev.prize_pool).toLocaleString()} PUMPVILLE</span>
            ${myContrib > 0 ? `<span class="text-green text-mono" style="font-size:12px">My contribution: ${myContrib.toLocaleString()}</span>` : ''}
          </div>
          ${ev.status === 'active' ? `
            <div class="flex gap-1">
              <button class="btn btn-success" onclick="GameUI.contributeEvent(${ev.id}, 'fuel')">⛽ Contribute Fuel</button>
              ${ev.reward_amount > 0 && !ev.reward_claimed
                ? `<button class="btn btn-primary" onclick="GameUI.claimEventReward(${ev.id})">🏆 Claim Reward</button>`
                : ''}
            </div>` : `<span class="text-dim" style="font-size:12px">${ev.status.toUpperCase()}</span>`}
        </div>`;
    }).join('');
  }

  async function contributeEvent(eventId, resource) {
    const amount = parseFloat(prompt(`How much ${resource} to contribute?`) || '0');
    if (!amount || amount <= 0) return;
    const res = await apiPost('/api/events.php', {
      action: 'contribute', event_id: eventId, resource_type: resource, amount
    });
    if (res.success) {
      toast(`Contributed ${amount} ${resource} to event!`, 'success');
      refreshEventsPanel();
    } else {
      toast(res.error || 'Contribution failed', 'error');
    }
  }

  async function claimEventReward(eventId) {
    const res = await apiPost('/api/events.php', { action: 'claim', event_id: eventId });
    if (res.success) {
      toast(`Reward of ${parseFloat(res.reward_amount).toLocaleString()} PUMPVILLE will be sent to your wallet!`, 'success');
      refreshEventsPanel();
    } else {
      toast(res.error || 'Claim failed', 'error');
    }
  }

  // ── Token status panel ────────────────────────────────────────────────────
  async function refreshTokenPanel(player) {
    const container = document.getElementById('token-status');
    if (!container) return;

    const tiers = [
      [0,       '1.00×'],
      [100,     '1.10×'],
      [1000,    '1.25×'],
      [10000,   '1.50×'],
      [100000,  '2.00×'],
      [1000000, '3.00×'],
    ];
    const balance = parseFloat(player.token_balance);
    const bonus   = parseFloat(player.fuel_rate_bonus);

    container.innerHTML = `
      <div class="token-card">
        <h4>$PUMPVILLE Holdings</h4>
        <div class="token-balance">${balance.toLocaleString(undefined, {maximumFractionDigits: 2})}</div>
        <div class="token-bonus">Fuel Bonus: ${bonus.toFixed(2)}× rate</div>
        <div class="token-tiers mt-2">
          <div style="font-size:11px;color:var(--text-dim);margin-bottom:4px">Bonus Tiers</div>
          ${tiers.map(([min, mult]) => `
            <div class="tier-row ${balance >= min ? 'active' : ''}">
              <span>${min.toLocaleString()}+ tokens</span>
              <span>${mult}</span>
            </div>`).join('')}
        </div>
        <button class="btn btn-secondary w-full mt-3" onclick="GameUI.refreshTokenBalance()">
          🔄 Check Balance
        </button>
        <p style="font-size:10px;color:var(--text-dim);margin-top:8px">
          Balance is checked on demand (max once/hour) and automatically once per day at a random time.
        </p>
      </div>`;
  }

  async function refreshTokenBalance() {
    const res = await apiPost('/api/wallet.php', { action: 'check_balance' });
    if (res.error) { toast(res.error, 'error'); return; }
    if (res.cached) {
      const mins = Math.ceil(res.next_check_in / 60);
      toast(`Cached (refreshes in ~${mins} min). Balance: ${res.token_balance.toLocaleString()} PUMPVILLE`, 'info');
    } else {
      toast(`Balance updated: ${res.token_balance.toLocaleString()} PUMPVILLE (${res.fuel_rate_bonus}× bonus)`, 'success');
    }
    // Trigger state refresh
    window.GameState?.refresh?.();
  }

  // ── Helpers ───────────────────────────────────────────────────────────────
  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function shortenAddress(addr) {
    if (!addr || addr.length < 10) return addr;
    return addr.slice(0, 4) + '…' + addr.slice(-4);
  }

  function resourceIcon(type) {
    return { fuel: '⛽', minerals: '💎', metal: '⚙️', mooncoin: '🪙' }[type] || '•';
  }

  function formatDate(dateStr) {
    return new Date(dateStr).toLocaleDateString(undefined, {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
  }

  return {
    toast,
    showModal,
    closeTopModal,
    updateHud,
    openPanel,
    closePanel,
    renderBuildMenu,
    refreshMarketPanel,
    buyListing,
    refreshEventsPanel,
    contributeEvent,
    claimEventReward,
    refreshTokenPanel,
    refreshTokenBalance,
    escapeHtml,
    shortenAddress,
    resourceIcon,
  };
})();

// Expose as GameUI for inline handlers
const GameUI = UI;
