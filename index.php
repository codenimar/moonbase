<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Moonbase – 2D Moon Colony Browser Game on Solana">
  <title>Moonbase – Moon Colony Game</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🌕</text></svg>">
  <link rel="stylesheet" href="/assets/css/main.css">
  <style>
    /* Extra stars for the login page */
    .star { position: absolute; border-radius: 50%; background: #fff; pointer-events: none; animation: twinkle var(--d,3s) ease-in-out infinite; }
    @keyframes twinkle { 0%,100%{opacity:.2;transform:scale(1)} 50%{opacity:.9;transform:scale(1.2)} }
  </style>
</head>
<body>
  <!-- Starfield -->
  <div id="stars-bg" aria-hidden="true"></div>

  <!-- Login Page -->
  <div id="login-page" role="main">
    <div class="login-card">
      <div class="login-logo" aria-hidden="true">🌕</div>
      <h1>MOONBASE</h1>
      <p class="subtitle">Moon Colony Strategy Game · Solana Chain</p>

      <div id="wallet-btns">
        <!-- Populated by JS once wallets are detected -->
        <p class="text-dim text-center" id="wallet-detect-msg">Detecting wallets…</p>
      </div>

      <div class="login-status" id="login-status" role="status" aria-live="polite"></div>

      <div class="login-footer">
        <p>By connecting you agree to our
          <a href="#tos" id="tos-link">Terms of Service</a> and
          <a href="#privacy" id="privacy-link">Privacy Policy</a>.
        </p>
        <p class="mt-1">Moonbase is a skill-based strategy game.<br>
          No gambling. No financial advice. Play responsibly.</p>
      </div>
    </div>
  </div>

  <!-- Toast container -->
  <div id="toast-container" role="alert" aria-live="assertive"></div>

  <!-- Terms of Service (modal) -->
  <template id="tos-content">
    <h3 style="margin-bottom:12px">Terms of Service</h3>
    <div style="font-size:13px;line-height:1.6;color:var(--text-secondary)">
      <p><strong style="color:var(--text-primary)">1. Nature of the Game</strong><br>
      Moonbase is a free-to-play browser strategy game. Participation is voluntary. The game uses the $PUMPVILLE SPL token only as a passive fuel-production multiplier (read-only wallet check). No on-chain transactions are required to play.</p>
      <p style="margin-top:10px"><strong style="color:var(--text-primary)">2. MoonCoins</strong><br>
      MoonCoins (MC) are purely in-game credits. They have no monetary value, cannot be exchanged for real currency or cryptocurrency, and are not transferable outside the game.</p>
      <p style="margin-top:10px"><strong style="color:var(--text-primary)">3. Community Events &amp; Prizes</strong><br>
      Event prizes ($PUMPVILLE tokens) are distributed to the <em>operator</em> wallet addresses provided by players as proportional rewards for in-game contributions. These are skill/effort-based, not luck-based. Prize distribution is subject to applicable laws in your jurisdiction.</p>
      <p style="margin-top:10px"><strong style="color:var(--text-primary)">4. Token Balance Checks</strong><br>
      Your Solana wallet address is used to query your SPL token balance via a public RPC. No private keys are ever requested or stored. Wallet addresses are stored solely to persist game state.</p>
      <p style="margin-top:10px"><strong style="color:var(--text-primary)">5. No Financial Advice</strong><br>
      Nothing in this game constitutes financial or investment advice. Cryptocurrency tokens are highly volatile. Never invest more than you can afford to lose.</p>
      <p style="margin-top:10px"><strong style="color:var(--text-primary)">6. Fair Play</strong><br>
      Exploits, automation (bots), or manipulation of the token-balance system are prohibited and may result in account suspension.</p>
    </div>
  </template>

  <template id="privacy-content">
    <h3 style="margin-bottom:12px">Privacy Policy</h3>
    <div style="font-size:13px;line-height:1.6;color:var(--text-secondary)">
      <p>We collect your <strong style="color:var(--text-primary)">Solana wallet address</strong> to identify your account and to query your $PUMPVILLE SPL token balance via a public blockchain RPC. We store:</p>
      <ul style="margin:8px 0 0 16px;list-style:disc">
        <li>Your wallet address</li>
        <li>Game state (buildings, resources, level)</li>
        <li>Token balance snapshots (read-only, queried periodically)</li>
      </ul>
      <p style="margin-top:10px">We do <strong style="color:var(--text-primary)">not</strong> collect: private keys, seed phrases, personal identification information, or payment details.</p>
      <p style="margin-top:10px">Data is stored on our servers and is not sold to third parties. You may request deletion of your account by contacting us.</p>
    </div>
  </template>

  <script src="/assets/js/wallet.js?v=<?= @filemtime(__DIR__ . '/assets/js/wallet.js') ?>"></script>
  <script src="/assets/js/ui.js"></script>
  <script>
    // ── Generate stars ─────────────────────────────────────────────────────
    (function() {
      const bg = document.getElementById('stars-bg');
      for (let i = 0; i < 200; i++) {
        const s = document.createElement('div');
        s.className = 'star';
        const size = Math.random() * 2.5 + 0.5;
        s.style.cssText = `
          width:${size}px;height:${size}px;
          top:${Math.random()*100}%;left:${Math.random()*100}%;
          --d:${(Math.random()*4+1).toFixed(1)}s;
          animation-delay:${(Math.random()*4).toFixed(1)}s;
        `;
        bg.appendChild(s);
      }
    })();

    // ── Login flow ─────────────────────────────────────────────────────────
    const statusEl = document.getElementById('login-status');
    const btnsEl   = document.getElementById('wallet-btns');

    function setStatus(msg, type='') {
      statusEl.textContent = msg;
      statusEl.className   = 'login-status ' + type;
    }

    // Check existing session
    (async () => {
      const session = localStorage.getItem('moonbase_session');
      if (session) {
        // If game.php has been failing repeatedly (tracked via sessionStorage),
        // don't redirect to it again — show the login page instead so the
        // user can re-authenticate.  game.php clears this counter on success.
        const failCount = parseInt(sessionStorage.getItem('_mb_load_attempts') || '0');
        if (failCount > 0) {
          // game.php has been failing – stop the auto-redirect and show the
          // login page so the user can re-authenticate.
          // IMPORTANT: do NOT clear _mb_load_attempts here.  The counter must
          // accumulate across re-logins so that _bootstrapFailed's
          // MAX_LOAD_ATTEMPTS guard can fire after 3 consecutive failures and
          // permanently break the redirect loop.  The counter is only cleared
          // by GameScene.create() on a successful game start, or by
          // _bootstrapFailed once MAX_LOAD_ATTEMPTS is reached.
          localStorage.removeItem('moonbase_session');
          renderWalletButtons();
          return;
        }
        setStatus('Restoring session…');
        const valid = await WalletManager.validateSession();
        if (valid) {
          setStatus('Session restored! Loading game…', 'ok');
          setTimeout(() => window.location.href = '/game.php', 800);
          return;
        }
        localStorage.removeItem('moonbase_session');
      }
      renderWalletButtons();
    })();

    function renderWalletButtons() {
      const detect = document.getElementById('wallet-detect-msg');
      if (detect) detect.remove();

      const providers = WalletManager.detectProviders();

      if (!providers.length) {
        btnsEl.innerHTML = `
          <p class="text-dim text-center" style="font-size:13px">
            No Solana wallet detected.<br>
            Install <a href="https://phantom.app" target="_blank" rel="noopener">Phantom</a>
            or <a href="https://solflare.com" target="_blank" rel="noopener">Solflare</a>
            to play.
          </p>`;
        return;
      }

      providers.forEach(({ name, icon, provider }) => {
        const btn = document.createElement('button');
        btn.className = 'wallet-btn ' + name.toLowerCase().replace(/\s/g,'');
        btn.innerHTML = icon
          ? `<img src="${icon}" alt="${name}" onerror="this.style.display='none'"> Connect with ${name}`
          : `🔑 Connect with ${name}`;
        btn.addEventListener('click', () => loginWithWallet(name, provider));
        btnsEl.appendChild(btn);
      });
    }

    async function loginWithWallet(name, provider) {
      setStatus(`Connecting to ${name}…`);
      btnsEl.querySelectorAll('button').forEach(b => b.disabled = true);

      try {
        const conn = await WalletManager.connect(provider, name);
        if (!conn.success) throw new Error(conn.error);
        setStatus(`Connected: ${conn.address.slice(0,6)}…${conn.address.slice(-4)}\nSigning message…`, 'ok');

        const auth = await WalletManager.authenticate(conn.address);
        if (!auth.success || !auth.session_token) throw new Error(auth.error || 'Auth failed');

        setStatus('Authenticated! Loading game…', 'ok');
        setTimeout(() => window.location.href = '/game.php', 600);

      } catch (err) {
        setStatus(err.message || 'Connection failed', 'error');
        btnsEl.querySelectorAll('button').forEach(b => b.disabled = false);
      }
    }

    // ── TOS / Privacy modal links ──────────────────────────────────────────
    document.getElementById('tos-link').addEventListener('click', (e) => {
      e.preventDefault();
      UI.showModal({
        title: 'Terms of Service',
        body:  document.getElementById('tos-content').innerHTML,
        buttons: [{ label: 'Close', action: 'close', class: 'btn-secondary' }],
      });
    });
    document.getElementById('privacy-link').addEventListener('click', (e) => {
      e.preventDefault();
      UI.showModal({
        title: 'Privacy Policy',
        body:  document.getElementById('privacy-content').innerHTML,
        buttons: [{ label: 'Close', action: 'close', class: 'btn-secondary' }],
      });
    });
  </script>
</body>
</html>
