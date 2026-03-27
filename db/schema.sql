-- Moonbase Game Database Schema
-- PHP/MySQL Browser Game with Solana Web3 Login

CREATE DATABASE IF NOT EXISTS moonbase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE moonbase;

-- Players table
CREATE TABLE IF NOT EXISTS players (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wallet_address  VARCHAR(64) NOT NULL UNIQUE,
    username        VARCHAR(32) DEFAULT NULL,
    level           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    experience      INT UNSIGNED NOT NULL DEFAULT 0,
    mooncoin_balance DECIMAL(20,4) NOT NULL DEFAULT 1000.0000,
    fuel            DECIMAL(20,4) NOT NULL DEFAULT 100.0000,
    minerals        DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
    metal           DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
    fuel_storage_cap DECIMAL(20,4) NOT NULL DEFAULT 500.0000,
    mineral_storage_cap DECIMAL(20,4) NOT NULL DEFAULT 500.0000,
    metal_storage_cap DECIMAL(20,4) NOT NULL DEFAULT 500.0000,
    token_balance   DECIMAL(30,6) NOT NULL DEFAULT 0.000000,
    fuel_rate_bonus FLOAT NOT NULL DEFAULT 1.0 COMMENT 'Multiplier from token holdings',
    last_fuel_update DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_token_check DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    next_token_check DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Next scheduled daily check time (randomised)',
    nonce           VARCHAR(64) DEFAULT NULL COMMENT 'Challenge nonce for wallet auth',
    session_token   VARCHAR(256) DEFAULT NULL,
    session_expires DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wallet (wallet_address),
    INDEX idx_session (session_token),
    INDEX idx_token_check (next_token_check)
) ENGINE=InnoDB;

-- Buildings table
CREATE TABLE IF NOT EXISTS buildings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    building_type   ENUM(
        'fuel_plant',
        'storage',
        'mining_station',
        'smelter',
        'market',
        'research_lab',
        'defense_tower',
        'command_center'
    ) NOT NULL,
    level           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    grid_x          TINYINT UNSIGNED NOT NULL,
    grid_y          TINYINT UNSIGNED NOT NULL,
    is_upgrading    TINYINT(1) NOT NULL DEFAULT 0,
    upgrade_finish  DATETIME DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_collected  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_grid (player_id, grid_x, grid_y),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Market listings
CREATE TABLE IF NOT EXISTS market_listings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_id       INT UNSIGNED NOT NULL,
    resource_type   ENUM('fuel','minerals','metal') NOT NULL,
    amount          DECIMAL(20,4) NOT NULL,
    price_per_unit  DECIMAL(20,4) NOT NULL COMMENT 'Price in MoonCoins',
    status          ENUM('active','sold','cancelled') NOT NULL DEFAULT 'active',
    buyer_id        INT UNSIGNED DEFAULT NULL,
    sold_at         DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES players(id) ON DELETE SET NULL,
    INDEX idx_status_type (status, resource_type),
    INDEX idx_seller (seller_id)
) ENGINE=InnoDB;

-- Market transactions log
CREATE TABLE IF NOT EXISTS market_transactions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id      INT UNSIGNED NOT NULL,
    seller_id       INT UNSIGNED NOT NULL,
    buyer_id        INT UNSIGNED NOT NULL,
    resource_type   ENUM('fuel','minerals','metal') NOT NULL,
    amount          DECIMAL(20,4) NOT NULL,
    total_price     DECIMAL(20,4) NOT NULL,
    fee             DECIMAL(20,4) NOT NULL COMMENT '10% market fee',
    seller_receives DECIMAL(20,4) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_seller (seller_id),
    INDEX idx_buyer (buyer_id)
) ENGINE=InnoDB;

-- Building events (community events)
CREATE TABLE IF NOT EXISTS events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    description     TEXT,
    event_type      ENUM('build','collect','defend') NOT NULL DEFAULT 'build',
    target_amount   DECIMAL(20,4) NOT NULL COMMENT 'Total contribution goal',
    current_amount  DECIMAL(20,4) NOT NULL DEFAULT 0,
    prize_pool      DECIMAL(30,6) NOT NULL DEFAULT 0 COMMENT 'PUMPVILLE token reward',
    min_level       SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    start_time      DATETIME NOT NULL,
    end_time        DATETIME NOT NULL,
    status          ENUM('upcoming','active','distributing','completed') NOT NULL DEFAULT 'upcoming',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_times (start_time, end_time)
) ENGINE=InnoDB;

-- Event participants
CREATE TABLE IF NOT EXISTS event_participants (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id        INT UNSIGNED NOT NULL,
    player_id       INT UNSIGNED NOT NULL,
    contribution    DECIMAL(20,4) NOT NULL DEFAULT 0,
    reward_share    FLOAT NOT NULL DEFAULT 0 COMMENT 'Fraction of prize pool 0..1',
    reward_claimed  TINYINT(1) NOT NULL DEFAULT 0,
    reward_amount   DECIMAL(30,6) NOT NULL DEFAULT 0,
    joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_participant (event_id, player_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Research / upgrades
CREATE TABLE IF NOT EXISTS research (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    tech_key        VARCHAR(64) NOT NULL,
    level           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    researched_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tech (player_id, tech_key),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Activity log
CREATE TABLE IF NOT EXISTS activity_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    action          VARCHAR(100) NOT NULL,
    details         JSON DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_player_time (player_id, created_at)
) ENGINE=InnoDB;

-- PvP raid log
CREATE TABLE IF NOT EXISTS pvp_raids (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attacker_id     INT UNSIGNED NOT NULL,
    defender_id     INT UNSIGNED NOT NULL,
    attack_power    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Attacker combined offense score',
    defense_power   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Defender combined defense score',
    outcome         ENUM('attacker_win','defender_win','draw') DEFAULT NULL,
    loot_fuel       DECIMAL(20,4) NOT NULL DEFAULT 0,
    loot_minerals   DECIMAL(20,4) NOT NULL DEFAULT 0,
    loot_metal      DECIMAL(20,4) NOT NULL DEFAULT 0,
    status          ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
    started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME DEFAULT NULL,
    FOREIGN KEY (attacker_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (defender_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_attacker (attacker_id),
    INDEX idx_defender (defender_id),
    INDEX idx_status   (status)
) ENGINE=InnoDB;

-- Alliances / guilds
CREATE TABLE IF NOT EXISTS alliances (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(64) NOT NULL UNIQUE,
    tag         VARCHAR(8) NOT NULL UNIQUE COMMENT 'Short tag shown in leaderboard',
    description TEXT,
    founder_id  INT UNSIGNED NOT NULL,
    mooncoin_bank DECIMAL(20,4) NOT NULL DEFAULT 0 COMMENT 'Shared alliance treasury',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (founder_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alliance_members (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alliance_id INT UNSIGNED NOT NULL,
    player_id   INT UNSIGNED NOT NULL UNIQUE COMMENT 'One alliance per player',
    role        ENUM('founder','officer','member') NOT NULL DEFAULT 'member',
    joined_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (alliance_id) REFERENCES alliances(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id)   REFERENCES players(id)   ON DELETE CASCADE,
    INDEX idx_alliance (alliance_id)
) ENGINE=InnoDB;

-- Add alliance_id to players (nullable FK to alliances)
ALTER TABLE players ADD COLUMN IF NOT EXISTS alliance_id INT UNSIGNED DEFAULT NULL AFTER metal_storage_cap;
ALTER TABLE players ADD CONSTRAINT IF NOT EXISTS fk_player_alliance
    FOREIGN KEY (alliance_id) REFERENCES alliances(id) ON DELETE SET NULL;

-- Widen session_token to fit the HMAC-based token (145-161 chars; previously too narrow at 128)
ALTER TABLE players MODIFY COLUMN session_token VARCHAR(256) DEFAULT NULL;

-- Insert a sample community event
INSERT INTO events (name, description, event_type, target_amount, prize_pool, min_level, start_time, end_time, status)
VALUES (
    'Moon Station Alpha Construction',
    'Contribute fuel and minerals to build the first shared Moon Station. All participants earn a share of the PUMPVILLE prize pool proportional to their contribution.',
    'build',
    100000,
    500000,
    5,
    DATE_ADD(NOW(), INTERVAL 7 DAY),
    DATE_ADD(NOW(), INTERVAL 21 DAY),
    'upcoming'
);
