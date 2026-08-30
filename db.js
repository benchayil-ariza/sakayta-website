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
  CREATE TABLE IF NOT EXISTS drivers (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    plateNumber TEXT,
    verified INTEGER DEFAULT 0,
    busy INTEGER DEFAULT 0
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
    distanceKm REAL,
    fareEstimate REAL,
    createdAt TEXT
  );
`);

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

