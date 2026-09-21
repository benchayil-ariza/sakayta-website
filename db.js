// db.js
// This is our RELATIONAL data store (SQLite) - one of the two required data stores.
// The second store (a JSON audit log) lives in services/queueConsumer.js
//
// Uses Node's BUILT-IN sqlite module (node:sqlite) - no native compilation,
// no Visual Studio Build Tools needed. Requires Node.js 22.5+ (you have this).

const { DatabaseSync } = require("node:sqlite");
const path = require("path");
const fs = require("fs");

const dataDir = path.join(__dirname, "data");
if (!fs.existsSync(dataDir)) fs.mkdirSync(dataDir);

const db = new DatabaseSync(path.join(dataDir, "sakayta.db"));

db.exec(`
  CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    email TEXT UNIQUE NOT NULL,
    full_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'commuter',
    created_at TEXT NOT NULL
  );

  CREATE TABLE IF NOT EXISTS drivers (
    id TEXT PRIMARY KEY,
    user_id TEXT,
    name TEXT NOT NULL,
    plateNumber TEXT,
    licenseNumber TEXT,
    licenseStatus TEXT DEFAULT 'pending',
    licenseVerifiedAt TEXT,
    verified INTEGER DEFAULT 0,
    busy INTEGER DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id)
  );

  CREATE TABLE IF NOT EXISTS rides (
    id TEXT PRIMARY KEY,
    passengerName TEXT NOT NULL,
    rideType TEXT DEFAULT 'Tricycle',
    pickupLat REAL,
    pickupLng REAL,
    dropoffLat REAL,
    dropoffLng REAL,
    driverId TEXT,
    status TEXT DEFAULT 'pending',
    requestedDriver TEXT,
    requestStatus TEXT,
    requestCreatedAt TEXT,
    requestExpiresAt TEXT,
    distanceKm REAL,
    fareEstimate REAL,
    createdAt TEXT
  );
`);

// SAFE MIGRATION: Add Phase 1 Step 4 columns if they don't exist
// This runs every startup and only adds columns if missing
function migratePhase1Step4() {
  // Check if requestedDriver column exists
  const tableInfo = db.prepare("PRAGMA table_info(rides)").all();
  const hasRequestedDriver = tableInfo.some(col => col.name === "requestedDriver");
  const hasRequestStatus = tableInfo.some(col => col.name === "requestStatus");
  const hasRequestCreatedAt = tableInfo.some(col => col.name === "requestCreatedAt");
  const hasRequestExpiresAt = tableInfo.some(col => col.name === "requestExpiresAt");

  if (!hasRequestedDriver) {
    console.log("🔄 Migrating rides table: Adding requestedDriver column...");
    db.exec("ALTER TABLE rides ADD COLUMN requestedDriver TEXT");
  }

  if (!hasRequestStatus) {
    console.log("🔄 Migrating rides table: Adding requestStatus column...");
    db.exec("ALTER TABLE rides ADD COLUMN requestStatus TEXT");
  }

  if (!hasRequestCreatedAt) {
    console.log("🔄 Migrating rides table: Adding requestCreatedAt column...");
    db.exec("ALTER TABLE rides ADD COLUMN requestCreatedAt TEXT");
  }

  if (!hasRequestExpiresAt) {
    console.log("🔄 Migrating rides table: Adding requestExpiresAt column...");
    db.exec("ALTER TABLE rides ADD COLUMN requestExpiresAt TEXT");
  }

  if (hasRequestedDriver && hasRequestStatus && hasRequestCreatedAt && hasRequestExpiresAt) {
    console.log("✅ Database schema is current (Phase 1 Step 4 columns present)");
  }
}

migratePhase1Step4();

// SAFE MIGRATION: Add Phase 1 Step 5 columns if they don't exist
// This runs every startup and only adds columns if missing
function migratePhase1Step5() {
  const tableInfo = db.prepare("PRAGMA table_info(rides)").all();
  const hasUserId = tableInfo.some(col => col.name === "userId");
  const hasUpdatedAt = tableInfo.some(col => col.name === "updatedAt");
  const hasStartedAt = tableInfo.some(col => col.name === "startedAt");
  const hasCompletedAt = tableInfo.some(col => col.name === "completedAt");
  const hasCancelledAt = tableInfo.some(col => col.name === "cancelledAt");

  if (!hasUserId) {
    console.log("🔄 Migrating rides table: Adding userId column...");
    db.exec("ALTER TABLE rides ADD COLUMN userId TEXT");
  }

  if (!hasUpdatedAt) {
    console.log("🔄 Migrating rides table: Adding updatedAt column...");
    db.exec("ALTER TABLE rides ADD COLUMN updatedAt TEXT");
  }

  if (!hasStartedAt) {
    console.log("🔄 Migrating rides table: Adding startedAt column...");
    db.exec("ALTER TABLE rides ADD COLUMN startedAt TEXT");
  }

  if (!hasCompletedAt) {
    console.log("🔄 Migrating rides table: Adding completedAt column...");
    db.exec("ALTER TABLE rides ADD COLUMN completedAt TEXT");
  }

  if (!hasCancelledAt) {
    console.log("🔄 Migrating rides table: Adding cancelledAt column...");
    db.exec("ALTER TABLE rides ADD COLUMN cancelledAt TEXT");
  }

  if (hasUserId && hasUpdatedAt && hasStartedAt && hasCompletedAt && hasCancelledAt) {
    console.log("✅ Database schema is current (Phase 1 Step 5 columns present)");
  }
}

migratePhase1Step5();

// SAFE MIGRATION: Add Phase 1 Step 6 notifications table if it doesn't exist
// This runs every startup and creates the table if missing
function migratePhase1Step6() {
  const tableInfo = db.prepare("PRAGMA table_info(notifications)").all();

  if (tableInfo.length === 0) {
    console.log("🔄 Creating notifications table...");
    db.exec(`
      CREATE TABLE notifications (
        id TEXT PRIMARY KEY,
        userId TEXT NOT NULL,
        rideId TEXT,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        read INTEGER DEFAULT 0,
        createdAt TEXT NOT NULL,
        FOREIGN KEY (userId) REFERENCES users(id),
        FOREIGN KEY (rideId) REFERENCES rides(id)
      );
    `);
    console.log("✅ Notifications table created");
  } else {
    console.log("✅ Notifications table already exists");
  }
}

migratePhase1Step6();

// SAFE MIGRATION: Add Phase 1 Step 7 columns for nearest-driver matching (FR-10)
// This runs every startup and only adds columns if missing
function migratePhase1Step7() {
  const tableInfo = db.prepare("PRAGMA table_info(drivers)").all();
  const hasLatitude = tableInfo.some(col => col.name === "latitude");
  const hasLongitude = tableInfo.some(col => col.name === "longitude");
  const hasLocationUpdatedAt = tableInfo.some(col => col.name === "locationUpdatedAt");

  if (!hasLatitude) {
    console.log("🔄 Migrating drivers table: Adding latitude column...");
    db.exec("ALTER TABLE drivers ADD COLUMN latitude REAL DEFAULT NULL");
  }

  if (!hasLongitude) {
    console.log("🔄 Migrating drivers table: Adding longitude column...");
    db.exec("ALTER TABLE drivers ADD COLUMN longitude REAL DEFAULT NULL");
  }

  if (!hasLocationUpdatedAt) {
    console.log("🔄 Migrating drivers table: Adding locationUpdatedAt column...");
    db.exec("ALTER TABLE drivers ADD COLUMN locationUpdatedAt TEXT DEFAULT NULL");
  }

  if (hasLatitude && hasLongitude && hasLocationUpdatedAt) {
    console.log("✅ Database schema is current (Phase 1 Step 7 columns present)");
  }
}

migratePhase1Step7();

// SAFE MIGRATION: Add Phase 1 Step 3 license columns to the drivers table if missing
// Existing databases were created before these columns existed. CREATE TABLE IF NOT EXISTS
// cannot add columns to an existing table, so we add them here every startup if missing.
function migratePhase1Step3LicenseColumns() {
  const tableInfo = db.prepare("PRAGMA table_info(drivers)").all();
  const hasUserId = tableInfo.some(col => col.name === "user_id");
  const hasLicenseNumber = tableInfo.some(col => col.name === "licenseNumber");
  const hasLicenseStatus = tableInfo.some(col => col.name === "licenseStatus");
  const hasLicenseVerifiedAt = tableInfo.some(col => col.name === "licenseVerifiedAt");

  if (!hasUserId) {
    console.log("🔄 Migrating drivers table: Adding user_id column...");
    db.exec("ALTER TABLE drivers ADD COLUMN user_id TEXT");
  }

  if (!hasLicenseNumber) {
    console.log("🔄 Migrating drivers table: Adding licenseNumber column...");
    db.exec("ALTER TABLE drivers ADD COLUMN licenseNumber TEXT");
  }

  if (!hasLicenseStatus) {
    console.log("🔄 Migrating drivers table: Adding licenseStatus column...");
    db.exec("ALTER TABLE drivers ADD COLUMN licenseStatus TEXT DEFAULT 'pending'");
  }

  if (!hasLicenseVerifiedAt) {
    console.log("🔄 Migrating drivers table: Adding licenseVerifiedAt column...");
    db.exec("ALTER TABLE drivers ADD COLUMN licenseVerifiedAt TEXT");
  }

  // Backfill: legacy driver rows that existed before licenseStatus must have the
  // intended 'pending' state (idempotent - only touches rows that are still NULL)
  db.exec("UPDATE drivers SET licenseStatus = 'pending' WHERE licenseStatus IS NULL");

  if (hasUserId && hasLicenseNumber && hasLicenseStatus && hasLicenseVerifiedAt) {
    console.log("✅ Database schema is current (Phase 1 Step 3 license columns present)");
  }
}

migratePhase1Step3LicenseColumns();

// SAFE MIGRATION: Add Phase 1 Step 8A fare configuration table
// This runs every startup and creates the table if missing
function migratePhase1Step8AFareConfig() {
  const tableInfo = db.prepare("PRAGMA table_info(fare_config)").all();

  if (tableInfo.length === 0) {
    console.log("🔄 Creating fare_config table...");
    db.exec(`
      CREATE TABLE fare_config (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        baseFare REAL NOT NULL DEFAULT 15,
        ratePerKm REAL NOT NULL DEFAULT 8,
        updatedAt TEXT NOT NULL,
        updatedBy TEXT
      );
    `);

    // Insert default configuration if table is empty
    const count = db.prepare("SELECT COUNT(*) as c FROM fare_config").get().c;
    if (count === 0) {
      db.exec(`
        INSERT INTO fare_config (baseFare, ratePerKm, updatedAt, updatedBy)
        VALUES (15, 8, ?, 'system')
      `, [new Date().toISOString()]);
      console.log("✅ Default fare configuration inserted");
    }

    console.log("✅ Fare config table created");
  } else {
    console.log("✅ Fare config table already exists");
  }
}

// SAFE MIGRATION: Add Phase 1 Step 8A admin action logs table
// This runs every startup and creates the table if missing
function migratePhase1Step8AAdminLogs() {
  const tableInfo = db.prepare("PRAGMA table_info(admin_action_logs)").all();

  if (tableInfo.length === 0) {
    console.log("🔄 Creating admin_action_logs table...");
    db.exec(`
      CREATE TABLE admin_action_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actionType TEXT NOT NULL,
        description TEXT NOT NULL,
        performedBy TEXT NOT NULL,
        performedAt TEXT NOT NULL,
        metadata TEXT
      );
    `);
    console.log("✅ Admin action logs table created");
  } else {
    console.log("✅ Admin action logs table already exists");
  }
}

migratePhase1Step8AFareConfig();
migratePhase1Step8AAdminLogs();

// Seed a couple of drivers the first time this runs
const count = db.prepare("SELECT COUNT(*) as c FROM drivers").get().c;
if (count === 0) {
  const insert = db.prepare(
    "INSERT INTO drivers (id, name, plateNumber, verified, busy) VALUES (?,?,?,0,0)"
  );
  insert.run("d1", "Mang Ricky", "ABC-1234");
  insert.run("d2", "Aling Nena", "XYZ-5678");
}

module.exports = db;

