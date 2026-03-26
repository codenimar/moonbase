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

  // ── Research panel ────────────────────────────────────────────────────────
  async function refreshResearchPanel() {
    const container = document.getElementById('research-list');
    if (!container) return;
    container.innerHTML = '<p class="text-dim text-center">Loading…</p>';
    const data = await apiGet('/api/research.php');
    if (data.error) { container.innerHTML = `<p class="text-red">${escapeHtml(data.error)}</p>`; return; }

    const { tree, player_tech, lab_level, active_research } = data;
    const categoryOrder = ['production', 'offense', 'defense', 'economy'];
    const catLabels = { production: '⚗️ Production', offense: '⚔️ Offense', defense: '🛡 Defense', economy: '💹 Economy' };

    if (lab_level === 0) {
      container.innerHTML = '<p class="text-dim text-center" style="padding:16px">Build a Research Lab to unlock technologies.</p>';
      return;
    }

    // Show active research timer
    let activeHtml = '';
    if (active_research) {
      const finish = new Date(active_research.finish_time);
      const remaining = Math.max(0, finish - Date.now());
      activeHtml = `<div class="research-card researching mb-2">
        <div class="research-card-header">
          <span class="research-card-name">⏳ ${escapeHtml(tree[active_research.tech_key]?.name || active_research.tech_key)}</span>
          <span class="text-orange" style="font-size:11px">Level ${active_research.level}</span>
        </div>
        <div class="research-progress-bar">
          <div class="research-progress-fill" id="research-active-fill" style="width:0%"></div>
        </div>
        <div id="research-timer" class="text-dim" style="font-size:11px;margin-top:4px">Finishes ${finish.toLocaleTimeString()}</div>
        <button class="btn btn-success w-full mt-2" style="font-size:11px"
                onclick="GameUI.completeResearch('${escapeHtml(active_research.tech_key)}')">
          ✅ Collect if Ready
        </button>
      </div>`;
    }

    const byCategory = {};
    for (const [key, def] of Object.entries(tree)) {
      if (!byCategory[def.category]) byCategory[def.category] = [];
      byCategory[def.category].push([key, def]);
    }

    let html = activeHtml;
    for (const cat of categoryOrder) {
      const techs = byCategory[cat] || [];
      html += `<div class="research-category-header">${catLabels[cat] || cat}</div>`;
      for (const [key, def] of techs) {
        const pt        = player_tech[key];
        const cur_level = pt ? (int_like(pt.level)) : 0;
        const next_lvl  = cur_level + 1;
        const maxed     = cur_level >= def.max_level;
        const prereq_ok = !def.prerequisite || !!player_tech[def.prerequisite];
        const lab_ok    = lab_level >= def.requires_lab_level;
        const locked    = !prereq_ok || !lab_ok;
        const is_active = active_research && active_research.tech_key === key;
        const lvl_def   = !maxed ? def.levels[next_lvl] : null;

        html += `<div class="research-card ${maxed ? 'maxed' : ''} ${locked ? 'locked' : ''} ${is_active ? 'researching' : ''}">
          <div class="research-card-header">
            <span class="research-card-name">${escapeHtml(def.name)}</span>
            <span class="research-card-level">${maxed ? '✅ MAX' : `${cur_level}/${def.max_level}`}</span>
          </div>
          <div class="research-card-desc">${escapeHtml(def.description)}</div>
          ${locked ? `<div class="text-dim" style="font-size:11px">
            ${!lab_ok ? `🔬 Requires Lab Level ${def.requires_lab_level}` : ''}
            ${!prereq_ok ? `🔒 Requires: ${escapeHtml(tree[def.prerequisite]?.name || def.prerequisite)}` : ''}
          </div>` : ''}
          ${!maxed && !locked && !is_active && lvl_def ? `
            <div class="research-card-costs">
              ${lvl_def.cost.fuel     ? `<span class="cost-tag">⛽ ${lvl_def.cost.fuel}</span>`      : ''}
              ${lvl_def.cost.minerals ? `<span class="cost-tag">💎 ${lvl_def.cost.minerals}</span>` : ''}
              ${lvl_def.cost.mooncoin ? `<span class="cost-tag">🪙 ${lvl_def.cost.mooncoin}</span>` : ''}
            </div>
            <button class="btn btn-primary w-full" style="font-size:11px;padding:6px 8px"
                    onclick="GameUI.startResearch('${escapeHtml(key)}')">
              🔬 Research Level ${next_lvl}
            </button>` : ''}
        </div>`;
      }
    }
    container.innerHTML = html;
  }

  async function startResearch(techKey) {
    const res = await apiPost('/api/research.php', { action: 'start', tech_key: techKey });
    if (res.success) {
      toast(`Research started! Finishes at ${res.finish_time}`, 'success');
      refreshResearchPanel();
    } else {
      toast(res.error || 'Research failed', 'error');
    }
  }

  async function completeResearch(techKey) {
    const res = await apiPost('/api/research.php', { action: 'complete', tech_key: techKey });
    if (res.success) {
      toast(`Research complete! ${techKey} is now Level ${res.level}`, 'success');
      refreshResearchPanel();
      window.GameState?.refresh?.();
    } else {
      toast(res.error || 'Not ready yet', 'info');
    }
  }

  // ── Raids panel ──────────────────────────────────────────────────────────
  async function refreshRaidsPanel() {
    const container = document.getElementById('raids-list');
    if (!container) return;
    container.innerHTML = '<p class="text-dim text-center">Loading…</p>';
    const data = await apiGet('/api/raids.php');
    if (data.error) { container.innerHTML = `<p class="text-red">${escapeHtml(data.error)}</p>`; return; }

    if (!data.raids.length) {
      container.innerHTML = '<p class="text-dim text-center">No raids yet</p>';
      return;
    }
    const me = window.GameState?.player?.wallet_address;
    container.innerHTML = data.raids.map(r => {
      const isAttacker  = r.attacker_wallet === me;
      const otherName   = isAttacker
        ? (r.defender_name  || shortenAddress(r.defender_wallet))
        : (r.attacker_name  || shortenAddress(r.attacker_wallet));
      const outcomeClass = r.outcome === 'attacker_win'
        ? (isAttacker ? 'raid-outcome-win' : 'raid-outcome-lose')
        : r.outcome === 'defender_win'
          ? (isAttacker ? 'raid-outcome-lose' : 'raid-outcome-win')
          : 'raid-outcome-draw';
      const outcomeLabel = r.outcome === 'attacker_win'
        ? (isAttacker ? '⚔️ Victory' : '🛡 Defended')
        : r.outcome === 'defender_win'
          ? (isAttacker ? '🛡 Repelled' : '⚔️ Victory')
          : '🤝 Draw';
      const lootParts = [];
      if (parseFloat(r.loot_fuel)     > 0) lootParts.push(`⛽ ${parseFloat(r.loot_fuel).toFixed(0)}`);
      if (parseFloat(r.loot_minerals) > 0) lootParts.push(`💎 ${parseFloat(r.loot_minerals).toFixed(0)}`);
      if (parseFloat(r.loot_metal)    > 0) lootParts.push(`⚙️ ${parseFloat(r.loot_metal).toFixed(0)}`);
      return `
        <div class="raid-card">
          <div class="raid-card-header">
            <span>${isAttacker ? '⚔️ Raided' : '🛡 Attacked by'} <strong>${escapeHtml(otherName)}</strong></span>
            <span class="${outcomeClass}">${outcomeLabel}</span>
          </div>
          <div class="raid-meta">
            <span>⚡ ${r.attack_power} vs 🛡 ${r.defense_power}</span>
            ${lootParts.length ? `<span>Loot: ${lootParts.join(' ')}</span>` : ''}
            <span>${r.resolved_at ? new Date(r.resolved_at).toLocaleDateString() : 'Pending'}</span>
          </div>
        </div>`;
    }).join('');
  }

  async function initiateRaid(targetWallet) {
    if (!targetWallet) {
      targetWallet = prompt('Enter target player wallet address:');
      if (!targetWallet) return;
    }
    if (!confirm(`Raid ${shortenAddress(targetWallet)}?`)) return;
    const res = await apiPost('/api/raids.php', { action: 'initiate', target_wallet: targetWallet });
    if (res.success) {
      const loot = res.loot;
      const lootStr = [
        loot.fuel     > 0 ? `⛽ ${loot.fuel.toFixed(0)}`     : '',
        loot.minerals > 0 ? `💎 ${loot.minerals.toFixed(0)}` : '',
        loot.metal    > 0 ? `⚙️ ${loot.metal.toFixed(0)}`    : '',
      ].filter(Boolean).join(' ');
      const msg = res.outcome === 'attacker_win'
        ? `⚔️ Raid successful! Looted: ${lootStr || 'nothing'}`
        : res.outcome === 'defender_win'
          ? `🛡 Raid failed – defender's towers held!`
          : '🤝 Draw! Both sides held their ground.';
      toast(msg, res.outcome === 'attacker_win' ? 'success' : 'info');
      refreshRaidsPanel();
      window.GameState?.refresh?.();
    } else {
      toast(res.error || 'Raid failed', 'error');
    }
  }

  // ── Alliance panel ────────────────────────────────────────────────────────
  async function refreshAlliancePanel() {
    const container = document.getElementById('alliance-content');
    if (!container) return;
    container.innerHTML = '<p class="text-dim text-center">Loading…</p>';
    const data = await apiGet('/api/alliances.php');
    if (data.error) { container.innerHTML = `<p class="text-red">${escapeHtml(data.error)}</p>`; return; }

    const { alliances, my_membership } = data;

    let html = '';
    if (my_membership) {
      // Show my alliance + leave/donate
      const myAl = alliances.find(a => a.id === my_membership.alliance_id);
      html += `<div class="alliance-card" style="border-color:var(--accent-cyan)">
        <div class="alliance-card-header">
          <span class="alliance-card-name">${myAl ? escapeHtml(myAl.name) : '?'}
            <span class="alliance-tag">${myAl ? escapeHtml(myAl.tag) : ''}</span>
          </span>
          <span class="role-badge role-${escapeHtml(my_membership.role)}">${my_membership.role}</span>
        </div>
        <div class="alliance-card-meta">🪙 Treasury: ${myAl ? parseFloat(myAl.mooncoin_bank).toLocaleString() : 0} MC</div>
        <div class="flex gap-1 mt-2">
          <button class="btn btn-primary" style="flex:1;font-size:11px" onclick="GameUI.donateToAlliance()">🪙 Donate</button>
          ${my_membership.role !== 'founder' ? `<button class="btn btn-danger" style="flex:1;font-size:11px" onclick="GameUI.leaveAlliance()">🚪 Leave</button>` : ''}
        </div>
      </div>`;
    } else {
      html += `<div class="mb-2">
        <button class="btn btn-success w-full" onclick="GameUI.showCreateAllianceModal()">⚔️ Found Alliance</button>
      </div>`;
    }

    html += `<div style="font-size:12px;font-weight:700;color:var(--text-secondary);margin:10px 0 6px">All Alliances</div>`;

    if (!alliances.length) {
      html += '<p class="text-dim text-center">No alliances yet</p>';
    } else {
      html += alliances.map(al => `
        <div class="alliance-card">
          <div class="alliance-card-header">
            <span class="alliance-card-name">${escapeHtml(al.name)}
              <span class="alliance-tag">${escapeHtml(al.tag)}</span>
            </span>
            <span class="text-dim" style="font-size:11px">👥 ${al.member_count}</span>
          </div>
          <div class="alliance-card-meta">
            ⭐ Top Level: ${al.top_level || 0}
            &nbsp;&nbsp;🪙 ${parseFloat(al.mooncoin_bank).toLocaleString()} MC
          </div>
          ${!my_membership ? `
            <button class="btn btn-secondary w-full mt-1" style="font-size:11px"
                    onclick="GameUI.joinAlliance(${al.id}, '${escapeHtml(al.name)}')">
              Join
            </button>` : ''}
        </div>`).join('');
    }

    container.innerHTML = html;
  }

  async function joinAlliance(id, name) {
    if (!confirm(`Join "${name}"?`)) return;
    const res = await apiPost('/api/alliances.php', { action: 'join', alliance_id: id });
    if (res.success) { toast('Joined alliance!', 'success'); refreshAlliancePanel(); }
    else toast(res.error || 'Failed', 'error');
  }

  async function leaveAlliance() {
    if (!confirm('Leave your alliance?')) return;
    const res = await apiPost('/api/alliances.php', { action: 'leave' });
    if (res.success) { toast('Left alliance', 'info'); refreshAlliancePanel(); }
    else toast(res.error || 'Failed', 'error');
  }

  async function donateToAlliance() {
    const amount = parseFloat(prompt('How many MoonCoins to donate to treasury?') || '0');
    if (!amount || amount <= 0) return;
    const res = await apiPost('/api/alliances.php', { action: 'donate', amount });
    if (res.success) { toast(`Donated ${amount} MC to treasury!`, 'success'); refreshAlliancePanel(); }
    else toast(res.error || 'Failed', 'error');
  }

  function showCreateAllianceModal() {
    showModal({
      title: '⚔️ Found an Alliance',
      body: `
        <div class="mb-3">
          <label style="display:block;font-size:13px;margin-bottom:4px">Alliance Name</label>
          <input id="al-name" type="text" maxlength="64" placeholder="e.g. Moon Wolves"
            style="width:100%;padding:8px;background:var(--bg-panel-light);border:1px solid var(--border);border-radius:4px;color:var(--text-primary);font-size:14px">
        </div>
        <div class="mb-3">
          <label style="display:block;font-size:13px;margin-bottom:4px">Tag (2–8 uppercase)</label>
          <input id="al-tag" type="text" maxlength="8" placeholder="e.g. WOLF"
            style="width:100%;padding:8px;background:var(--bg-panel-light);border:1px solid var(--border);border-radius:4px;color:var(--text-primary);font-size:14px">
        </div>
        <div class="mb-3">
          <label style="display:block;font-size:13px;margin-bottom:4px">Description</label>
          <textarea id="al-desc" maxlength="500" rows="3" placeholder="Your alliance mission…"
            style="width:100%;padding:8px;background:var(--bg-panel-light);border:1px solid var(--border);border-radius:4px;color:var(--text-primary);font-size:13px;resize:vertical"></textarea>
        </div>
        <p class="text-dim" style="font-size:11px">Requires Level 10. You become the founder.</p>`,
      buttons: [
        { label: '⚔️ Found', action: 'create', class: 'btn-success', onClick: async (close) => {
          const name = document.getElementById('al-name').value.trim();
          const tag  = document.getElementById('al-tag').value.trim().toUpperCase();
          const desc = document.getElementById('al-desc').value.trim();
          const res  = await apiPost('/api/alliances.php', { action: 'create', name, tag, description: desc });
          if (res.success) { toast('Alliance created!', 'success'); close(); refreshAlliancePanel(); }
          else toast(res.error || 'Failed', 'error');
        }},
        { label: 'Cancel', action: 'close', class: 'btn-secondary' },
      ],
    });
  }

  // ── MoonCoin bridge panel ─────────────────────────────────────────────────
  async function refreshMooncoinPanel(player) {
    const container = document.getElementById('mooncoin-status');
    if (!container) return;

    container.innerHTML = `
      <div class="token-card">
        <h4>🪙 MoonCoin Balance</h4>
        <div class="token-balance">${parseFloat(player.mooncoin_balance).toLocaleString(undefined,{maximumFractionDigits:2})}</div>
        <div class="token-bonus" style="color:var(--text-secondary)">In-game credit (non-transferable)</div>
        <hr style="border-color:var(--border);margin:12px 0">
        <h4 style="font-size:12px;color:var(--accent-cyan)">On-chain MoonCoin SPL Token</h4>
        <p style="font-size:11px;color:var(--text-dim);margin-bottom:10px">
          Bridge your in-game MoonCoins to real on-chain SPL tokens.
          A ${typeof MOONCOIN_BRIDGE_FEE !== 'undefined' ? MOONCOIN_BRIDGE_FEE : ''}% burn fee applies.
        </p>
        <button class="btn btn-primary w-full" onclick="GameUI.showBridgeModal()">
          🌉 Bridge to On-chain
        </button>
      </div>`;
  }

  function showBridgeModal() {
    showModal({
      title: '🌉 Bridge MoonCoins On-chain',
      body: `
        <p style="font-size:13px;margin-bottom:12px;color:var(--text-secondary)">
          Convert in-game MoonCoins to on-chain SPL tokens sent to your Solana wallet.
          A <strong>5% burn fee</strong> is deducted from the bridged amount.
        </p>
        <div class="mb-3">
          <label style="display:block;font-size:13px;margin-bottom:4px">Amount to Bridge</label>
          <input id="bridge-amount" type="number" min="100" step="1" placeholder="Min. 100 MoonCoins"
            style="width:100%;padding:8px;background:var(--bg-panel-light);border:1px solid var(--border);border-radius:4px;color:var(--text-primary);font-size:14px">
        </div>
        <p class="text-dim" style="font-size:11px">
          Tokens will be airdropped to your connected Solana wallet within 24 hours once the MoonCoin SPL token launches.
        </p>`,
      buttons: [
        { label: '🌉 Bridge', action: 'bridge', class: 'btn-primary', onClick: async (close) => {
          const amount = parseFloat(document.getElementById('bridge-amount').value || '0');
          if (!amount || amount < 100) { toast('Minimum bridge amount is 100 MoonCoins', 'error'); return; }
          const res = await apiPost('/api/wallet.php', { action: 'bridge_mooncoin', amount });
          if (res.success) {
            toast(`Bridge request submitted! ${res.after_fee} MC queued for on-chain transfer.`, 'success');
            close();
            window.GameState?.refresh?.();
          } else {
            toast(res.error || 'Bridge failed', 'error');
          }
        }},
        { label: 'Cancel', action: 'close', class: 'btn-secondary' },
      ],
    });
  }

  // helper used in research panel
  function int_like(v) { return parseInt(v, 10) || 0; }

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
    refreshResearchPanel,
    startResearch,
    completeResearch,
    refreshRaidsPanel,
    initiateRaid,
    refreshAlliancePanel,
    joinAlliance,
    leaveAlliance,
    donateToAlliance,
    showCreateAllianceModal,
    refreshMooncoinPanel,
    showBridgeModal,
    refreshTokenPanel,
    refreshTokenBalance,
    escapeHtml,
    shortenAddress,
    resourceIcon,
  };
})();

// Expose as GameUI for inline handlers
const GameUI = UI;
