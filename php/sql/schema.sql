-- ============================================================================
-- SakayTa — MariaDB Foundation Schema (FINAL SRS target)
-- ----------------------------------------------------------------------------
-- Additive, idempotent foundation for the SRS migration.
--   * utf8mb4 / InnoDB
--   * primary keys, foreign keys, indexes, CHECK/ENUM constraints
--   * maps the CURRENT SakayTa data model (users, drivers, rides,
--     notifications, fare_config, admin_action_logs) onto MariaDB
--   * fare rates are PER AREA (FINAL SRS), so a new `fare_areas` table is
--     added alongside the legacy global `fare_config` (kept for parity).
--
-- SAFETY: every statement uses IF NOT EXISTS. Running this file repeatedly
-- is a no-op after the first successful run. It never drops or resets any
-- table, and it never touches the existing SQLite database.
--
-- Run through Apache/PHP (php/bin/bootstrap.php) or directly:
--   "C:\xampp\mysql\bin\mysql.exe" -u root < php/sql/schema.sql
-- ============================================================================

USE sakayta;

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            VARCHAR(50)  NOT NULL,
  email         VARCHAR(191) DEFAULT NULL,
  full_name     VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('commuter','driver','admin') NOT NULL DEFAULT 'commuter',
  mobile         VARCHAR(20)  DEFAULT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_mobile (mobile),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- drivers
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS drivers (
  id                VARCHAR(50)   NOT NULL,
  user_id           VARCHAR(50)   DEFAULT NULL,
  name              VARCHAR(255)  NOT NULL,
  plateNumber       VARCHAR(20)   DEFAULT NULL,
  licenseNumber     VARCHAR(50)   DEFAULT NULL,
  licenseStatus     ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  licenseVerifiedAt DATETIME      DEFAULT NULL,
  verified          TINYINT(1)    NOT NULL DEFAULT 0,
  busy              TINYINT(1)    NOT NULL DEFAULT 0,
  availability      ENUM('available','unavailable') NOT NULL DEFAULT 'unavailable',
  latitude          DECIMAL(10,7) DEFAULT NULL,
  longitude         DECIMAL(10,7) DEFAULT NULL,
  locationUpdatedAt DATETIME      DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_drivers_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY uq_drivers_user (user_id),   -- one driver profile per user
  KEY idx_drivers_license_busy (licenseStatus, busy),
  KEY idx_drivers_location (latitude, longitude, locationUpdatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- rides
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rides (
  id                VARCHAR(50)   NOT NULL,
  passengerName     VARCHAR(255)  NOT NULL,
  rideType          VARCHAR(50)   NOT NULL DEFAULT 'Tricycle',
  pickupLat         DECIMAL(10,7) DEFAULT NULL,
  pickupLng         DECIMAL(10,7) DEFAULT NULL,
  dropoffLat        DECIMAL(10,7) DEFAULT NULL,
  dropoffLng        DECIMAL(10,7) DEFAULT NULL,
  driverId          VARCHAR(50)   DEFAULT NULL,
  status            ENUM('pending','assigned','en_route','completed','cancelled') NOT NULL DEFAULT 'pending',
  requestedDriver   VARCHAR(50)   DEFAULT NULL,
  requestStatus     ENUM('pending','accepted','declined') DEFAULT NULL,
  requestCreatedAt  DATETIME      DEFAULT NULL,
  requestExpiresAt  DATETIME      DEFAULT NULL,
  distanceKm        DECIMAL(10,2) DEFAULT NULL,
  fareEstimate      DECIMAL(10,2) DEFAULT NULL,
  createdAt         DATETIME      DEFAULT NULL,
  userId            VARCHAR(50)   DEFAULT NULL,
  updatedAt         DATETIME      DEFAULT NULL,
  startedAt         DATETIME      DEFAULT NULL,
  completedAt       DATETIME      DEFAULT NULL,
  cancelledAt       DATETIME      DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_rides_user
    FOREIGN KEY (userId) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_rides_driver
    FOREIGN KEY (driverId) REFERENCES drivers(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY idx_rides_status (status),
  KEY idx_rides_user (userId),
  KEY idx_rides_driver (driverId),
  KEY idx_rides_request (requestStatus, requestExpiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- notifications
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id        VARCHAR(50)  NOT NULL,
  userId    VARCHAR(50)  NOT NULL,
  rideId    VARCHAR(50)  DEFAULT NULL,
  type      VARCHAR(50)  NOT NULL,
  title     VARCHAR(255) NOT NULL,
  message   TEXT         NOT NULL,
  `read`    TINYINT(1)   NOT NULL DEFAULT 0,
  createdAt DATETIME     NOT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_notifications_user
    FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_notifications_ride
    FOREIGN KEY (rideId) REFERENCES rides(id) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY idx_notifications_user_created (userId, createdAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- fare_config (legacy global, kept for parity with the current data model)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fare_config (
  id         INT           NOT NULL AUTO_INCREMENT,
  baseFare   DECIMAL(10,2) NOT NULL DEFAULT 15.00,
  ratePerKm  DECIMAL(10,2) NOT NULL DEFAULT 8.00,
  updatedAt  DATETIME      NOT NULL,
  updatedBy  VARCHAR(50)   DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT chk_fare_config_base  CHECK (baseFare  >= 0),
  CONSTRAINT chk_fare_config_rate  CHECK (ratePerKm >= 0),
  KEY idx_fare_config_updated (updatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- fare_areas — FINAL SRS: fare rates per area
--   * area (code + name)
--   * base fare per area
--   * rate per kilometer per area
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fare_areas (
  id          INT           NOT NULL AUTO_INCREMENT,
  area_code   VARCHAR(20)   NOT NULL,
  area_name   VARCHAR(120)  NOT NULL,
  base_fare   DECIMAL(10,2) NOT NULL DEFAULT 15.00,
  rate_per_km DECIMAL(10,2) NOT NULL DEFAULT 8.00,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by  VARCHAR(50)   DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fare_areas_code (area_code),
  CONSTRAINT chk_fare_areas_base CHECK (base_fare   >= 0),
  CONSTRAINT chk_fare_areas_rate CHECK (rate_per_km >= 0),
  KEY idx_fare_areas_name (area_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- admin_action_logs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_action_logs (
  id          INT           NOT NULL AUTO_INCREMENT,
  actionType  VARCHAR(80)   NOT NULL,
  description VARCHAR(255)  NOT NULL,
  performedBy VARCHAR(50)   NOT NULL,
  performedAt DATETIME      NOT NULL,
  metadata    JSON          DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_admin_logs_type (actionType),
  KEY idx_admin_logs_performed (performedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;