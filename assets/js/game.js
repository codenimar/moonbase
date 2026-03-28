/**
 * game.js – Moonbase 2D Game using Phaser 3
 *
 * Architecture:
 *   BootScene      – preload all assets
 *   GameScene      – main moon-base grid, buildings, resource collection
 */

// ── Global game state ─────────────────────────────────────────────────────
const GameState = {
  player:        null,
  buildings:     [],
  buildingDefs:  {},
  events:        [],
  mode:          'view',   // 'view' | 'build' | 'info'
  selectedType:  null,
  fuelRate:      0,

  async refresh() {
    const data = await apiGet('/api/game_state.php');
    if (data.error) { UI.toast(data.error, 'error'); return; }
    this.player       = data.player;
    this.buildings    = data.buildings;
    this.buildingDefs = data.building_defs;
    this.events       = data.events;
    this.fuelRate     = calcFuelRate(data.player, data.buildings, data.building_defs);
    UI.updateHud(data.player, this.fuelRate);
    UI.renderBuildMenu(data.building_defs, data.player, (type) => {
      this.mode = 'build';
      this.selectedType = type;
      UI.closePanel();
      UI.toast(`Click a tile to place ${data.building_defs[type].name}`, 'info');
    });
    // Refresh game scene buildings
    if (window._gameScene) window._gameScene.syncBuildings(this.buildings);
  },
};

window.GameState = GameState;

function calcFuelRate(player, buildings, defs) {
  let rate = 0;
  buildings.forEach(b => {
    if (b.building_type === 'fuel_plant' && b.is_active) {
      const lvl    = parseInt(b.level);
      const defLvl = defs.fuel_plant?.levels?.[lvl];
      if (defLvl) rate += defLvl.rate;
    }
  });
  return rate * parseFloat(player.fuel_rate_bonus || 1);
}

// ── Constants ─────────────────────────────────────────────────────────────
const TILE    = 64;
const COLS    = 20;
const ROWS    = 15;
const SPRITE_PATH = '/assets/sprites/';

// Building size in tiles
const BUILD_SIZES = {
  command_center: [3, 3],
  fuel_plant:     [2, 2],
  storage:        [2, 2],
  mining_station: [2, 2],
  smelter:        [2, 2],
  market:         [3, 3],
  research_lab:   [2, 2],
  defense_tower:  [1, 1],
};

// ═══════════════════════════════════════════════════════════════════════════
//  BootScene – Preload
// ═══════════════════════════════════════════════════════════════════════════
class BootScene extends Phaser.Scene {
  constructor() { super({ key: 'BootScene' }); }

  preload() {
    if (typeof setLoadingStatus === 'function') setLoadingStatus('Loading assets… 0%');
    console.info('[Moonbase] BootScene.preload() started');

    this.load.on('progress', (v) => {
      const bar = document.getElementById('loading-progress');
      if (bar) bar.style.width = (v * 100).toFixed(0) + '%';
      if (typeof setLoadingStatus === 'function') {
        setLoadingStatus(`Loading assets… ${(v * 100).toFixed(0)}%`);
      }
    });

    this.load.on('loaderror', (file) => {
      const msg = `Asset load failed: ${file.key} (${file.src})`;
      console.error('[Moonbase]', msg, file);
      if (typeof setLoadingStatus === 'function') setLoadingStatus(msg, true);
    });

    this.load.on('complete', () => {
      console.info('[Moonbase] BootScene: all assets loaded');
    });

    // Tiles
    ['moon_surface','crater','rock','buildable','unbuildable','highlight','grid_empty'].forEach(k => {
      this.load.image(k, `${SPRITE_PATH}tiles/${k}.png`);
    });

    // Buildings
    ['command_center','fuel_plant','storage','mining_station',
     'smelter','market','research_lab','defense_tower'].forEach(k => {
      this.load.image(k, `${SPRITE_PATH}buildings/${k}.png`);
    });

    // Animated fuel plant (sprite sheet: 4 frames × 128px wide)
    this.load.spritesheet('fuel_plant_anim', `${SPRITE_PATH}buildings/fuel_plant_anim.png`,
      { frameWidth: 128, frameHeight: 128 });

    // Effects
    ['particle_fuel','particle_mineral','particle_metal','selection_ring',
     'build_preview_ok','build_preview_err'].forEach(k => {
      this.load.image(k, `${SPRITE_PATH}effects/${k}.png`);
    });

    this.load.spritesheet('smoke_sheet', `${SPRITE_PATH}effects/smoke_sheet.png`,
      { frameWidth: 16, frameHeight: 16 });
  }

  create() {
    if (typeof setLoadingStatus === 'function') setLoadingStatus('Assets loaded. Starting game scene…');
    console.info('[Moonbase] BootScene.create() – launching GameScene');
    this.scene.start('GameScene');
  }
}

// ═══════════════════════════════════════════════════════════════════════════
//  GameScene – Main
// ═══════════════════════════════════════════════════════════════════════════
class GameScene extends Phaser.Scene {
  constructor() { super({ key: 'GameScene' }); }

  create() {
    console.info('[Moonbase] GameScene.create() started');
    // Hide the loading overlay as soon as GameScene starts so it is never
    // left on screen if any subsequent setup step throws an error that
    // Phaser catches internally (those errors would not reach the bootstrap
    // try-catch, causing the overlay to remain visible forever).
    const lo = document.getElementById('loading-overlay');
    if (lo) lo.classList.add('hidden');

    // Game started successfully – clear the load-failure counter so the
    // redirect-loop breaker resets for the next session.
    try { sessionStorage.removeItem('_mb_load_attempts'); } catch (_ignored) {}

    window._gameScene = this;

    this._camDrag    = false;
    this._dragStart  = { x: 0, y: 0 };
    this._buildGhost = null;
    this._selected   = null;   // selected building sprite
    this._bldSprites = new Map(); // building_id → Phaser.GameObjects.Container

    console.info('[Moonbase] GameScene: setting up animations');
    this._setupAnimations();
    console.info('[Moonbase] GameScene: building tile map');
    this._buildTileMap();
    console.info('[Moonbase] GameScene: setting up camera');
    this._setupCamera();
    console.info('[Moonbase] GameScene: setting up input');
    this._setupInput();
    console.info('[Moonbase] GameScene: setting up particles');
    this._setupParticles();
    console.info('[Moonbase] GameScene: creating selection indicator');
    this._createSelectionIndicator();

    // Sync initial buildings
    console.info(`[Moonbase] GameScene: syncing buildings (${GameState.buildings.length})`);
    if (GameState.buildings.length) this.syncBuildings(GameState.buildings);
    console.info('[Moonbase] GameScene.create() complete');

    // Tick: collect pending upgrades every 10 s
    this.time.addEvent({
      delay: 10000,
      loop:  true,
      callback: () => {
        apiPost('/api/buildings.php', { action: 'finish_upgrade' }).then(r => {
          if (r.finished?.length) {
            r.finished.forEach(f => UI.toast(`Building upgraded to level ${f.new_level}!`, 'success'));
            GameState.refresh();
          }
        });
      },
    });

    // Tick: refresh HUD resources every 5 s
    this.time.addEvent({
      delay: 5000,
      loop:  true,
      callback: () => GameState.refresh(),
    });
  }

  // ── Tilemap ────────────────────────────────────────────────────────────
  _buildTileMap() {
    this._tiles = [];

    // Simple deterministic map: craters & rocks from position hash
    for (let y = 0; y < ROWS; y++) {
      for (let x = 0; x < COLS; x++) {
        const hash = ((x * 73856093) ^ (y * 19349663)) % 100;
        let key = 'moon_surface';
        if (hash < 5) key = 'crater';
        else if (hash < 12) key = 'rock';

        const tile = this.add.image(x * TILE + TILE/2, y * TILE + TILE/2, key)
          .setInteractive()
          .setDepth(0);

        tile.gridX = x;
        tile.gridY = y;
        tile.on('pointerover', () => this._onTileHover(tile));
        tile.on('pointerout',  () => this._onTileOut(tile));
        tile.on('pointerdown', () => this._onTileClick(tile));
        this._tiles.push(tile);
      }
    }
  }

  // ── Camera ─────────────────────────────────────────────────────────────
  _setupCamera() {
    const worldW = COLS * TILE;
    const worldH = ROWS * TILE;
    this.cameras.main.setBounds(0, 0, worldW, worldH);
    this.cameras.main.centerOn(worldW / 2, worldH / 2);
    this.cameras.main.setZoom(1);
  }

  // ── Animations ─────────────────────────────────────────────────────────
  _setupAnimations() {
    if (!this.anims.exists('fuel_anim')) {
      this.anims.create({
        key:       'fuel_anim',
        frames:    this.anims.generateFrameNumbers('fuel_plant_anim', { start: 0, end: 3 }),
        frameRate: 4,
        repeat:    -1,
      });
    }
    if (!this.anims.exists('smoke_anim')) {
      this.anims.create({
        key:       'smoke_anim',
        frames:    this.anims.generateFrameNumbers('smoke_sheet', { start: 0, end: 7 }),
        frameRate: 8,
        repeat:    -1,
      });
    }
  }

  // ── Particles ──────────────────────────────────────────────────────────
  _setupParticles() {
    // Particle emitters for resource collection FX
    this._fxEmitter = this.add.particles(0, 0, 'particle_fuel', {
      speed:    { min: 20, max: 60 },
      lifespan: 800,
      scale:    { start: 0.8, end: 0 },
      quantity: 5,
      emitting: false,
      depth:    100,
    });
  }

  // ── Input ──────────────────────────────────────────────────────────────
  _setupInput() {
    const cam = this.cameras.main;

    // Track touch pointers for pan/pinch
    this._touchPts  = {};    // pointerId → {x, y}
    this._pinchDist = null;

    // ── Pointer DOWN ────────────────────────────────────────
    this.input.on('pointerdown', (ptr) => {
      this._touchPts[ptr.id] = { x: ptr.x, y: ptr.y };
      const ptCount = Object.keys(this._touchPts).length;

      if (ptCount === 2) {
        // Two fingers: pan + pinch mode
        this._touchPan = true;
        this._singleTapCancelled = true;
        const pts = Object.values(this._touchPts);
        this._pinchDist = Math.hypot(pts[1].x - pts[0].x, pts[1].y - pts[0].y);
        this._dragStart = {
          x: (pts[0].x + pts[1].x) / 2 + cam.scrollX,
          y: (pts[0].y + pts[1].y) / 2 + cam.scrollY,
        };
      } else if (ptr.rightButtonDown() || ptr.middleButtonDown()) {
        this._camDrag   = true;
        this._touchPan  = false;
        this._dragStart = { x: ptr.x + cam.scrollX, y: ptr.y + cam.scrollY };
      } else {
        this._singleTapCancelled = false;
      }
    });

    // ── Pointer MOVE ────────────────────────────────────────
    this.input.on('pointermove', (ptr) => {
      if (this._touchPts[ptr.id]) {
        this._touchPts[ptr.id] = { x: ptr.x, y: ptr.y };
      }
      const ptCount = Object.keys(this._touchPts).length;

      if (ptCount === 2) {
        const pts = Object.values(this._touchPts);
        // Pinch-to-zoom
        const dist = Math.hypot(pts[1].x - pts[0].x, pts[1].y - pts[0].y);
        if (this._pinchDist !== null) {
          cam.zoom = Phaser.Math.Clamp(cam.zoom + (dist - this._pinchDist) * 0.005, 0.4, 2.5);
        }
        this._pinchDist = dist;
        // Two-finger pan
        const mx = (pts[0].x + pts[1].x) / 2;
        const my = (pts[0].y + pts[1].y) / 2;
        cam.scrollX = this._dragStart.x - mx;
        cam.scrollY = this._dragStart.y - my;
        return;
      }

      if (this._camDrag) {
        cam.scrollX = this._dragStart.x - ptr.x;
        cam.scrollY = this._dragStart.y - ptr.y;
      }
      if (GameState.mode === 'build' && this._buildGhost) {
        const wx = cam.scrollX + ptr.x;
        const wy = cam.scrollY + ptr.y;
        const gx = Math.floor(wx / TILE);
        const gy = Math.floor(wy / TILE);
        this._updateGhost(gx, gy);
      }
    });

    // ── Pointer UP ──────────────────────────────────────────
    this.input.on('pointerup', (ptr) => {
      delete this._touchPts[ptr.id];
      const ptCount = Object.keys(this._touchPts).length;
      if (ptCount === 0) {
        this._camDrag             = false;
        this._touchPan            = false;
        this._pinchDist           = null;
        this._singleTapCancelled  = false;  // reset so next tap works
      }
      if (ptCount < 2) { this._pinchDist = null; }
      if (ptr.rightButtonReleased() || ptr.middleButtonReleased()) {
        this._camDrag = false;
      }
    });

    // ── Zoom (mouse wheel) ──────────────────────────────────
    this.input.on('wheel', (_ptr, _go, _dx, dy) => {
      cam.zoom = Phaser.Math.Clamp(cam.zoom - dy * 0.001, 0.5, 2.0);
    });

    // ── Keyboard shortcuts ──────────────────────────────────
    const escKey = this.input.keyboard.addKey(Phaser.Input.Keyboard.KeyCodes.ESC);
    escKey.on('down', () => {
      if (GameState.mode === 'build') {
        GameState.mode       = 'view';
        GameState.selectedType = null;
        this._clearGhost();
        UI.toast('Build mode cancelled', 'info');
      }
    });

    // One-time touch hint on mobile
    if ('ontouchstart' in window && !sessionStorage.getItem('touchHintShown')) {
      sessionStorage.setItem('touchHintShown', '1');
      const hint = document.createElement('div');
      hint.className = 'touch-hint';
      hint.textContent = '✌️ Two fingers to pan & pinch-zoom';
      document.body.appendChild(hint);
      setTimeout(() => hint.remove(), 3200);
    }
  }

  // ── Selection ring ─────────────────────────────────────────────────────
  _createSelectionIndicator() {
    this._selectionRing = this.add.image(0, 0, 'selection_ring')
      .setDepth(90).setVisible(false).setAlpha(0.85);
  }

  // ── Tile events ────────────────────────────────────────────────────────
  _onTileHover(tile) {
    if (GameState.mode === 'build') return; // ghost handles highlight
    tile.setTint(0x4adfff);
  }

  _onTileOut(tile) {
    if (GameState.mode === 'build') return;
    tile.clearTint();
  }

  _onTileClick(tile) {
    if (this._camDrag || this._touchPan || this._singleTapCancelled) return;

    if (GameState.mode === 'build' && GameState.selectedType) {
      this._placeBuilding(tile.gridX, tile.gridY, GameState.selectedType);
      return;
    }

    // Check if tile has a building
    const building = GameState.buildings.find(
      b => b.grid_x === tile.gridX && b.grid_y === tile.gridY
    );
    if (building) {
      this._showBuildingInfo(building);
    }
  }

  // ── Build ghost ────────────────────────────────────────────────────────
  _updateGhost(gx, gy) {
    const type = GameState.selectedType;
    if (!type) return;
    const [sw, sh] = BUILD_SIZES[type] || [1, 1];
    const px = (gx + sw/2) * TILE;
    const py = (gy + sh/2) * TILE;

    const canPlace = this._canPlace(gx, gy, sw, sh);
    const key = canPlace ? 'build_preview_ok' : 'build_preview_err';

    if (!this._buildGhost) {
      this._buildGhost = this.add.image(px, py, key).setDepth(80).setAlpha(0.7);
    } else {
      this._buildGhost.setTexture(key);
      this._buildGhost.setPosition(px, py);
    }

    this._buildGhost.setDisplaySize(sw * TILE, sh * TILE);
  }

  _clearGhost() {
    if (this._buildGhost) { this._buildGhost.destroy(); this._buildGhost = null; }
  }

  _canPlace(gx, gy, sw, sh) {
    if (gx < 0 || gy < 0 || gx + sw > COLS || gy + sh > ROWS) return false;
    // Check no existing building overlaps (simple: check top-left tile)
    return !GameState.buildings.some(b => b.grid_x === gx && b.grid_y === gy);
  }

  // ── Place building (API call) ──────────────────────────────────────────
  async _placeBuilding(gx, gy, type) {
    const [sw, sh] = BUILD_SIZES[type] || [1, 1];
    if (!this._canPlace(gx, gy, sw, sh)) {
      UI.toast('Cannot place building here', 'error');
      return;
    }
    const res = await apiPost('/api/buildings.php', {
      action: 'place', building_type: type, grid_x: gx, grid_y: gy
    });
    if (res.success) {
      UI.toast(`${GameState.buildingDefs[type].name} placed!`, 'success');
      GameState.mode        = 'view';
      GameState.selectedType = null;
      this._clearGhost();
      await GameState.refresh();
    } else {
      UI.toast(res.error || 'Failed to place building', 'error');
    }
  }

  // ── Sync building sprites with server state ────────────────────────────
  syncBuildings(buildings) {
    // Remove sprites for deleted buildings
    const ids = new Set(buildings.map(b => b.id));
    this._bldSprites.forEach((container, id) => {
      if (!ids.has(id)) { container.destroy(); this._bldSprites.delete(id); }
    });

    buildings.forEach(b => {
      if (this._bldSprites.has(b.id)) return; // already added
      this._addBuildingSprite(b);
    });
  }

  _addBuildingSprite(b) {
    const type  = b.building_type;
    const [sw, sh] = BUILD_SIZES[type] || [1, 1];
    const px = (b.grid_x + sw/2) * TILE;
    const py = (b.grid_y + sh/2) * TILE;

    const spriteKey = type === 'fuel_plant' ? 'fuel_plant_anim' : type;
    let sprite;
    if (type === 'fuel_plant') {
      sprite = this.add.sprite(0, 0, 'fuel_plant_anim').play('fuel_anim');
    } else {
      sprite = this.add.image(0, 0, spriteKey);
    }

    const targetW = sw * TILE * 0.85;
    const targetH = sh * TILE * 0.85;
    sprite.setDisplaySize(targetW, targetH);

    // Level badge
    const badge = this.add.text(0, -targetH/2 - 8,
      `L${b.level}`, { fontSize: '10px', color: '#4adfff', backgroundColor: '#0a1a2a' }
    ).setOrigin(0.5, 1);

    // Upgrade timer if upgrading
    let timerTxt;
    if (b.is_upgrading && b.upgrade_finish) {
      const remaining = Math.max(0, new Date(b.upgrade_finish) - Date.now());
      timerTxt = this.add.text(0, targetH/2 + 4,
        '⬆ ' + formatCountdown(remaining), { fontSize: '9px', color: '#f0a030' }
      ).setOrigin(0.5, 0);
    }

    const container = this.add.container(px, py, [sprite, badge, ...(timerTxt ? [timerTxt] : [])])
      .setDepth(10)
      .setSize(targetW, targetH)
      .setInteractive();

    container.on('pointerover',  () => { sprite.setTint(0xffffff); badge.setColor('#ffffff'); });
    container.on('pointerout',   () => { sprite.clearTint(); badge.setColor('#4adfff'); });
    container.on('pointerdown',  () => this._showBuildingInfo(b));

    // Add collect button for productive buildings
    const defs = GameState.buildingDefs;
    if (defs[type]?.produces) {
      const btn = this.add.text(0, targetH/2 + 16, '⛏ Collect',
        { fontSize: '9px', color: '#2adf6a', backgroundColor: '#0a2a0a', padding: {x:3,y:2} }
      ).setOrigin(0.5, 0).setInteractive().setDepth(20);
      btn.on('pointerdown', (ptr) => {
        ptr.event.stopPropagation();
        this._collectFromBuilding(b.id);
      });
      container.add(btn);
    }

    this._bldSprites.set(b.id, container);
  }

  async _collectFromBuilding(buildingId) {
    const res = await apiPost('/api/game_state.php', { action: 'collect', building_id: buildingId });
    if (res.success) {
      const icon = res.resource === 'fuel' ? 'particle_fuel' :
                   res.resource === 'minerals' ? 'particle_mineral' : 'particle_metal';
      const container = this._bldSprites.get(buildingId);
      if (container) {
        this._fxEmitter.setTexture(icon);
        this._fxEmitter.setPosition(container.x, container.y);
        this._fxEmitter.explode(8);
      }
      UI.toast(`Collected ${res.collected} ${res.resource}!`, 'success');
      GameState.refresh();
    } else {
      UI.toast(res.error || 'Nothing to collect', 'info');
    }
  }

  // ── Building info modal ────────────────────────────────────────────────
  _showBuildingInfo(b) {
    const def  = GameState.buildingDefs[b.building_type];
    if (!def) return;
    const name = def.name;
    const lvl  = parseInt(b.level);
    const max  = def.max_level;
    const next = def.levels[lvl + 1];
    const canUpgrade = lvl < max && next;
    const p    = GameState.player;

    const canAfford = canUpgrade &&
      parseFloat(p.fuel)             >= (next.cost.fuel     || 0) &&
      parseFloat(p.minerals)         >= (next.cost.minerals || 0) &&
      parseFloat(p.mooncoin_balance) >= next.cost.mooncoin;

    const body = `
      <div class="flex items-center gap-1 mb-3">
        <img src="/assets/sprites/buildings/${b.building_type}.png" width="48" height="48"
             style="border-radius:6px">
        <div>
          <div style="font-size:16px;font-weight:700">${UI.escapeHtml(name)}</div>
          <div class="text-dim" style="font-size:12px">Level ${lvl} / ${max}</div>
        </div>
      </div>
      <p style="font-size:13px;margin-bottom:12px">${UI.escapeHtml(def.description)}</p>
      ${def.levels[lvl].rate ? `<p class="text-green" style="font-size:12px">Production: ${def.levels[lvl].rate}/min</p>` : ''}
      ${b.is_upgrading ? `<p class="text-orange" style="font-size:12px">⬆ Upgrading… finishes ${UI.escapeHtml(b.upgrade_finish || '')}</p>` : ''}
      ${canUpgrade && !b.is_upgrading ? `
        <div style="margin-top:12px;padding:10px;background:rgba(26,74,122,0.2);border-radius:6px">
          <div style="font-size:13px;font-weight:600;margin-bottom:6px">Upgrade to Level ${lvl + 1}</div>
          <div class="building-costs">
            ${next.cost.fuel     ? `<span class="cost-tag ${parseFloat(p.fuel)>=next.cost.fuel ?'affordable':'unaffordable'}">⛽ ${next.cost.fuel}</span>` : ''}
            ${next.cost.minerals ? `<span class="cost-tag ${parseFloat(p.minerals)>=next.cost.minerals?'affordable':'unaffordable'}">💎 ${next.cost.minerals}</span>` : ''}
            ${next.cost.mooncoin ? `<span class="cost-tag ${parseFloat(p.mooncoin_balance)>=next.cost.mooncoin?'affordable':'unaffordable'}">🪙 ${next.cost.mooncoin}</span>` : ''}
          </div>
          <div class="text-dim" style="font-size:11px;margin-top:4px">Build time: ${formatSeconds(next.build_time)}</div>
        </div>` : ''}`;

    const buttons = [
      { label: '✕ Close', action: 'close', class: 'btn-secondary' },
    ];
    if (canUpgrade && !b.is_upgrading) {
      buttons.unshift({
        label:    `⬆ Upgrade (Level ${lvl + 1})`,
        action:   'upgrade',
        class:    canAfford ? 'btn-success' : 'btn-secondary',
        onClick:  (close) => this._upgradeBuilding(b.id, close),
      });
    }
    if (b.building_type !== 'command_center') {
      buttons.push({
        label:   '🗑 Remove',
        action:  'remove',
        class:   'btn-danger',
        onClick: (close) => this._removeBuilding(b.id, close),
      });
    }

    UI.showModal({ title: name, body, buttons });

    // Select ring
    const container = this._bldSprites.get(b.id);
    if (container) {
      const [sw, sh] = BUILD_SIZES[b.building_type] || [1, 1];
      this._selectionRing
        .setPosition(container.x, container.y)
        .setDisplaySize(sw * TILE + 16, sh * TILE + 16)
        .setVisible(true);
    }
  }

  async _upgradeBuilding(buildingId, closeModal) {
    const res = await apiPost('/api/buildings.php', { action: 'upgrade', building_id: buildingId });
    if (res.success) {
      UI.toast(`Upgrade started! Finishes at ${res.upgrade_finish}`, 'success');
      closeModal();
      GameState.refresh();
    } else {
      UI.toast(res.error || 'Upgrade failed', 'error');
    }
  }

  async _removeBuilding(buildingId, closeModal) {
    if (!confirm('Remove this building? This cannot be undone.')) return;
    const res = await apiPost('/api/buildings.php', { action: 'remove', building_id: buildingId });
    if (res.success) {
      closeModal();
      GameState.refresh();
    } else {
      UI.toast(res.error || 'Removal failed', 'error');
    }
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────
function formatCountdown(ms) {
  const s = Math.floor(ms / 1000);
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sc = s % 60;
  return `${h > 0 ? h + 'h ' : ''}${m}m ${sc}s`;
}

function formatSeconds(s) {
  if (s < 60)   return s + 's';
  if (s < 3600) return Math.floor(s/60) + 'm';
  return Math.floor(s/3600) + 'h ' + Math.floor((s%3600)/60) + 'm';
}

// ── Phaser Game Config ────────────────────────────────────────────────────
function initGame() {
  const container = document.getElementById('game-container');
  const config = {
    type:   Phaser.AUTO,
    parent: 'game-container',
    width:  container.clientWidth,
    height: container.clientHeight,
    backgroundColor: '#050510',
    scene:  [BootScene, GameScene],
    input:  {
      mouse:  { preventDefaultWheel: true },
      touch:  { capture: true },         // enable multi-touch
    },
    scale: {
      mode:       Phaser.Scale.RESIZE,
      autoCenter: Phaser.Scale.CENTER_BOTH,
    },
  };
  console.info('[Moonbase] initGame: Phaser config', {
    type: config.type === Phaser.WEBGL ? 'WEBGL' : config.type === Phaser.CANVAS ? 'CANVAS' : 'AUTO',
    width: config.width,
    height: config.height,
    phaserVersion: Phaser.VERSION,
  });
  const game = new Phaser.Game(config);
  console.info('[Moonbase] Phaser.Game instance created');
  return game;
}
