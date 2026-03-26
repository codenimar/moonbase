<?php
/**
 * Moonbase Game – Central Configuration
 *
 * Copy this file to config.local.php and fill in your values.
 * config.local.php is git-ignored and should never be committed.
 */

// Load local overrides FIRST so they take precedence over defaults below
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// ── Database ─────────────────────────────────────────────────────────────────
defined('DB_HOST')    || define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
defined('DB_PORT')    || define('DB_PORT',    (int)(getenv('DB_PORT') ?: 3306));
defined('DB_NAME')    || define('DB_NAME',    getenv('DB_NAME')    ?: 'moonbase');
defined('DB_USER')    || define('DB_USER',    getenv('DB_USER')    ?: 'moonbase_user');
defined('DB_PASS')    || define('DB_PASS',    getenv('DB_PASS')    ?: 'change_me_in_production');
defined('DB_SOCKET')  || define('DB_SOCKET',  getenv('DB_SOCKET')  ?: '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

// ── Solana / Token ───────────────────────────────────────────────────────────
// SPL token mint address for $PUMPVILLE (fuel-bonus governance token)
defined('TOKEN_MINT_ADDRESS') || define('TOKEN_MINT_ADDRESS', '72FkeF1cpBMtbordhTVNVbBGdaN5DfHcchstHwPWpump');

// On-chain MoonCoin SPL token – replace in-game credit with real token
// Set MOONCOIN_MINT_ADDRESS in config.local.php once the mint is deployed
defined('MOONCOIN_MINT_ADDRESS') || define('MOONCOIN_MINT_ADDRESS', getenv('MOONCOIN_MINT_ADDRESS') ?: '');

// Bridge fee (% of MoonCoins burned when converting in-game credit to on-chain)
defined('MOONCOIN_BRIDGE_FEE_PCT') || define('MOONCOIN_BRIDGE_FEE_PCT', 5);

// Solana RPC endpoint (use a private RPC in production)
defined('SOLANA_RPC_URL') || define('SOLANA_RPC_URL', getenv('SOLANA_RPC_URL') ?: 'https://api.mainnet-beta.solana.com');

// ── Auth ─────────────────────────────────────────────────────────────────────
// Secret key for signing session tokens – change in production!
defined('SESSION_SECRET') || define('SESSION_SECRET', getenv('SESSION_SECRET') ?: 'moonbase_super_secret_change_me');
defined('SESSION_TTL')    || define('SESSION_TTL',    86400 * 7); // 7 days

// ── Game Constants ───────────────────────────────────────────────────────────
defined('GRID_COLS')    || define('GRID_COLS', 20);
defined('GRID_ROWS')    || define('GRID_ROWS', 15);
defined('TILE_SIZE')    || define('TILE_SIZE', 64);
defined('BASE_FUEL_RATE') || define('BASE_FUEL_RATE', 1.0);

defined('TOKEN_BONUS_TIERS') || define('TOKEN_BONUS_TIERS', [
      0         => 1.00,
    100         => 1.10,
   1_000        => 1.25,
  10_000        => 1.50,
 100_000        => 2.00,
1_000_000       => 3.00,
]);

defined('MARKET_FEE_PCT') || define('MARKET_FEE_PCT', 10);
defined('XP_TO_LEVEL')    || define('XP_TO_LEVEL',    1000);

// ── CORS / Security ──────────────────────────────────────────────────────────
defined('ALLOWED_ORIGINS') || define('ALLOWED_ORIGINS', ['*']);

// ── Environment ──────────────────────────────────────────────────────────────
defined('APP_ENV')  || define('APP_ENV',  getenv('APP_ENV') ?: 'production');
defined('DEBUG')    || define('DEBUG',    APP_ENV === 'development');
defined('BASE_URL') || define('BASE_URL', getenv('BASE_URL') ?: '');
