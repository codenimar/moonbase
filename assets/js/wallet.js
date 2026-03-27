/**
 * wallet.js – Solana wallet connection + authentication
 * Supports Phantom and Solflare browser wallets.
 */

const WalletManager = (() => {
  let _provider  = null;
  let _wallet    = null; // { name, address, provider }
  let _session   = localStorage.getItem('moonbase_session') || null;

  // ── Provider detection ───────────────────────────────────────────────────
  function detectProviders() {
    const providers = [];
    if (window.phantom?.solana?.isPhantom) {
      providers.push({ name: 'Phantom',  icon: 'https://phantom.app/favicon.ico',  provider: window.phantom.solana });
    }
    if (window.solflare?.isSolflare) {
      providers.push({ name: 'Solflare', icon: 'https://solflare.com/favicon.ico', provider: window.solflare });
    }
    // Generic window.solana fallback (Phantom legacy)
    if (!providers.length && window.solana) {
      providers.push({ name: 'Solana Wallet', icon: '', provider: window.solana });
    }
    return providers;
  }

  // ── Connect wallet ────────────────────────────────────────────────────────
  async function connect(provider, walletName) {
    try {
      const resp = await provider.connect();
      const address = resp.publicKey.toString();
      _provider  = provider;
      _wallet    = { name: walletName, address, provider };
      return { success: true, address };
    } catch (err) {
      return { success: false, error: err.message || 'Connection rejected' };
    }
  }

  // ── Sign authentication message ───────────────────────────────────────────
  async function authenticate(address) {
    // Step 1: get nonce from server
    const nonceRes = await fetch('/api/auth.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'nonce', wallet: address }),
    });
    const nonceData = await nonceRes.json();
    if (nonceData.error) throw new Error(nonceData.error);

    const { nonce, message } = nonceData;

    // Step 2: sign message with wallet
    const encodedMsg = new TextEncoder().encode(message);
    let signedMsg;
    try {
      signedMsg = await _provider.signMessage(encodedMsg, 'utf8');
    } catch (err) {
      throw new Error('Message signing rejected: ' + (err.message || err));
    }

    // Encode signature as base58.
    // Phantom returns { signature: Uint8Array }; Solflare returns { data: Uint8Array }.
    const sigBytes = signedMsg.signature ?? signedMsg.data;
    if (!sigBytes) throw new Error('Wallet did not return signature bytes');
    const sigBase58 = bs58.encode(sigBytes);

    // Step 3: verify with server
    const verifyRes = await fetch('/api/auth.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'verify', wallet: address, signature: sigBase58, nonce }),
    });
    const verifyData = await verifyRes.json();
    if (verifyData.error) throw new Error(verifyData.error);

    _session = verifyData.session_token;
    localStorage.setItem('moonbase_session', _session);
    return verifyData;
  }

  // ── Disconnect ────────────────────────────────────────────────────────────
  async function disconnect() {
    if (_provider?.disconnect) {
      try { await _provider.disconnect(); } catch (_) {}
    }
    await fetch('/api/auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Session-Token': _session || '' },
      body: JSON.stringify({ action: 'logout' }),
    }).catch(() => {});
    _session  = null;
    _provider = null;
    _wallet   = null;
    localStorage.removeItem('moonbase_session');
  }

  // ── Validate existing session ────────────────────────────────────────────
  async function validateSession() {
    if (!_session) return false;
    try {
      const res = await fetch('/api/game_state.php', {
        headers: { 'X-Session-Token': _session },
      });
      if (res.ok) return true;
      _session = null;
      localStorage.removeItem('moonbase_session');
      return false;
    } catch (_) {
      return false;
    }
  }

  // ── Check token balance ──────────────────────────────────────────────────
  async function checkTokenBalance() {
    const res = await apiPost('/api/wallet.php', { action: 'check_balance' });
    return res;
  }

  // ── Getters ───────────────────────────────────────────────────────────────
  function getSession()  { return _session; }
  function getWallet()   { return _wallet;  }
  function getAddress()  { return _wallet?.address || null; }

  return {
    detectProviders,
    connect,
    authenticate,
    disconnect,
    validateSession,
    checkTokenBalance,
    getSession,
    getWallet,
    getAddress,
  };
})();

// ── API helper (uses session token) ─────────────────────────────────────────
async function apiPost(url, body) {
  const session = WalletManager.getSession();
  const res = await fetch(url, {
    method:  'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(session ? { 'X-Session-Token': session } : {}),
    },
    body: JSON.stringify(body),
  });
  return res.json();
}

async function apiGet(url, params = {}) {
  const session = WalletManager.getSession();
  const qs = new URLSearchParams(params).toString();
  const res = await fetch(url + (qs ? '?' + qs : ''), {
    headers: {
      ...(session ? { 'X-Session-Token': session } : {}),
    },
  });
  return res.json();
}
