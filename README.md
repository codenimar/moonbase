# 🌕 Moonbase — Moon Colony Strategy Game

A **2D browser strategy game** set on the Moon, featuring Solana Web3 wallet
login, resource management, a player marketplace, and community building events
with $PUMPVILLE token integration.

---

## Features

| Feature | Details |
|---|---|
| **Authentication** | Solana wallet sign-message (Phantom, Solflare) |
| **Game Engine** | Phaser 3 – 2D tile-based colony |
| **Resources** | Fuel, Minerals, Metal, MoonCoins |
| **Buildings** | 8 types with up to 5 upgrade levels |
| **Fuel Mechanics** | Production scales with $PUMPVILLE token balance |
| **Marketplace** | Player-to-player trading, 10 % fee |
| **Events** | Community building events with PUMPVILLE prize pool |
| **Token** | $PUMPVILLE (`72FkeF1cpBMtbordhTVNVbBGdaN5DfHcchstHwPWpump`) |

---

## Tech Stack

- **Backend**: PHP 8.3 + MySQL 8
- **Frontend**: Phaser 3, vanilla JS, CSS custom properties
- **Wallet**: Solana Web3 (Phantom / Solflare browser extensions)
- **Token**: SPL token balance queried via Solana JSON-RPC
- **Sprites**: PNG files (generated; replaceable with custom art)

---

## Directory Structure

```
moonbase/
├── index.php               # Login / landing page
├── game.php                # Main game (requires auth)
├── config/
│   ├── config.php          # App configuration (tokens, DB, RPC)
│   └── config.local.php    # Local overrides (git-ignored)
├── includes/
│   ├── db.php              # PDO database singleton
│   ├── auth.php            # Solana signature verification, sessions
│   └── game_helpers.php    # Building defs, fuel calc, XP helpers
├── api/
│   ├── auth.php            # POST – nonce / verify / logout
│   ├── game_state.php      # GET  – full state; POST – collect / username
│   ├── buildings.php       # POST – place / upgrade / remove
│   ├── market.php          # GET/POST – listings, buy, cancel
│   ├── events.php          # GET/POST – events, join, contribute, claim
│   └── wallet.php          # POST – on-demand token balance check
├── assets/
│   ├── css/main.css        # Moon-themed UI
│   ├── js/
│   │   ├── wallet.js       # Wallet connect + auth flow
│   │   ├── ui.js           # HUD, panels, modals, toasts
│   │   └── game.js         # Phaser 3 scenes (Boot, Game)
│   └── sprites/            # PNG sprite files
│       ├── tiles/          # 64×64 surface tiles
│       ├── buildings/      # Building sprites + animation sheets
│       ├── ui/             # HUD elements, icons, buttons
│       └── effects/        # Particles, selection rings
├── db/
│   └── schema.sql          # Full database schema + seed data
└── scripts/
    ├── generate_sprites.py # Regenerate placeholder PNG sprites
    └── cron_token_check.php# Cron: daily randomised token balance check
```

---

## Installation

### 1. Requirements

- PHP 8.1+ with extensions: `pdo_mysql`, `sodium`, `curl`, `gd`, `json`
- MySQL 8.0+
- Web server: Apache / Nginx with PHP-FPM
- Python 3 + Pillow (only for sprite regeneration)

### 2. Database Setup

```bash
mysql -u root -p < db/schema.sql
```

Create a dedicated user:

```sql
CREATE USER 'moonbase_user'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON moonbase.* TO 'moonbase_user'@'localhost';
```

### 3. Configuration

Copy and edit the local config:

```bash
cp config/config.php config/config.local.php
# Edit config.local.php with your DB credentials and Solana RPC URL
```

Key settings in `config/config.local.php`:

```php
// Override constants as needed
define('DB_USER', 'moonbase_user');
define('DB_PASS', 'strong_password');
define('SOLANA_RPC_URL', 'https://your-private-rpc.com');
define('SESSION_SECRET', 'random-256-bit-string');
```

### 4. Web Server

**Apache** – create a VirtualHost pointing to the project root.
Enable `mod_rewrite` if you want clean URLs.

**Nginx** example:

```nginx
server {
    listen 80;
    server_name moonbase.example.com;
    root /var/www/moonbase;
    index index.php;

    location / { try_files $uri $uri/ /index.php$is_args$args; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### 5. Cron Job (Token Balance)

Add to server crontab for daily randomised token balance checks:

```cron
*/15 * * * * php /var/www/moonbase/scripts/cron_token_check.php >> /var/log/moonbase_cron.log 2>&1
```

### 6. Generate Sprites (Optional)

To regenerate placeholder sprites:

```bash
python3 scripts/generate_sprites.py
```

Replace any PNG in `assets/sprites/` with your own artwork. File names and
dimensions should match the existing files.

---

## Game Economy Design

### Fuel System

| $PUMPVILLE Holdings | Fuel Rate Multiplier |
|---|---|
| 0 tokens | 1.00× |
| 100+ | 1.10× |
| 1,000+ | 1.25× |
| 10,000+ | 1.50× |
| 100,000+ | 2.00× |
| 1,000,000+ | 3.00× |

Fuel production rate = (sum of Fuel Plant level rates) × token multiplier.

**Balance-check anti-gaming**: the daily balance check is scheduled at a random
time 23–25 hours from the last check. On-demand checks are rate-limited to once
per hour. This prevents players from moving tokens between wallets just before
the daily snapshot.

### MoonCoins (MC)

- In-game currency only; no monetary value.
- New players start with 1,000 MC.
- Earned by: resource collection (1 XP → levelling), building upgrades, event
  participation.
- Spent on: building construction, upgrades, marketplace purchases.
- **10 % market fee** is charged on the total sale price (deducted from seller).

### Community Events

- Players contribute resources (Fuel, Minerals, Metal) toward a shared goal.
- Rewards are calculated **proportionally to contribution** (skill/effort-based,
  not luck-based — compliant with most jurisdictions).
- Prize pool is in $PUMPVILLE tokens, distributed from an operator wallet.
- Players must reach the event's minimum level to participate.
- Reward claim triggers an off-chain transfer (logged, fulfilled within 24 h).

### Legal Notes

- No on-chain transactions required to play.
- Token balance check is **read-only** (no wallet permissions needed).
- MoonCoins are non-transferable in-game credits, not securities.
- Events are **skill/contribution-based**, not random/luck-based gambling.
- Operator is responsible for compliance with local laws regarding prize
  distributions. Consult legal counsel before enabling prize events in
  jurisdictions with strict gambling/prize regulations.

---

## Security

- Solana message signatures verified server-side via PHP `sodium` (Ed25519).
- Session tokens are HMAC-SHA256 signed (not JWT, no external dependency).
- All DB queries use PDO prepared statements (no SQL injection surface).
- All user-supplied strings are escaped in HTML output.
- Nonces are single-use and consumed on successful authentication.
- `config.local.php` is git-ignored to prevent secret leakage.

---

## Customising Sprites

All game graphics live in `assets/sprites/`. Replace any PNG with your artwork:

| Directory | Contents | Size |
|---|---|---|
| `tiles/` | Ground tiles | 64 × 64 px |
| `buildings/` | Building sprites | 64–192 px |
| `buildings/fuel_plant_anim.png` | 4-frame animation sheet | 512 × 128 px |
| `effects/smoke_sheet.png` | 8-frame smoke sheet | 128 × 16 px |
| `ui/` | HUD elements, icons | Various |
| `effects/` | Particles, overlays | 16–64 px |

Sprite sheets must maintain frame dimensions as declared in `game.js`.

---

## Development

```bash
# Start PHP built-in server (dev only)
php -S localhost:8080 -t .

# Regenerate sprites after changing generate_sprites.py
python3 scripts/generate_sprites.py
```

---

## Roadmap / Future Features

- [ ] PvP raid events with Defense Tower mechanics
- [ ] Research tree (unlock advanced buildings and bonuses)
- [ ] Alliance / guild system for large-scale community events
- [ ] Mobile-optimised touch controls
- [ ] On-chain MoonCoin SPL token (replace in-game credit with real token)
- [ ] Leaderboard page
- [ ] Push notifications for upgrade completion
