// services/queueConsumer.js
// This is a SEPARATE process (run with: npm run worker) that listens to the
// RabbitMQ queue and does the actual work: pick a driver, create a request,
// handle timeouts, and log it.

const amqp = require("amqplib");
const fs = require("fs");
const path = require("path");
const db = require("../db");
const { verifyDriver } = require("./soapClient");
const { haversineKm } = require("./fareService");

const AUDIT_LOG = path.join(__dirname, "..", "data", "audit-log.json");
const REQUEST_TIMEOUT_MS = 15000; // 15 seconds for driver to accept/decline
const TIMEOUT_CHECK_INTERVAL_MS = 2000; // Check for expired requests every 2 seconds

// This JSON file is our SECOND data store (a simple document/log store),
// separate from the SQLite relational database - satisfying the
// "heterogeneous data stores" requirement.
function appendAuditLog(entry) {
  let log = [];
  if (fs.existsSync(AUDIT_LOG)) {
    log = JSON.parse(fs.readFileSync(AUDIT_LOG, "utf-8"));
  }
  log.push({ ...entry, timestamp: new Date().toISOString() });
  fs.writeFileSync(AUDIT_LOG, JSON.stringify(log, null, 2));
}

// Function to create notifications (non-blocking, FR-17)
function createNotification(userId, rideId, type, title, message) {
  try {
    const notificationId = "n" + Date.now() + Math.random().toString(36).substr(2, 9);
    db.prepare(
      `INSERT INTO notifications (id, userId, rideId, type, title, message, read, createdAt)
       VALUES (?, ?, ?, ?, ?, ?, 0, ?)`
    ).run(
      notificationId,
      userId,
      rideId,
      type,
      title,
      message,
      new Date().toISOString()
    );
    return notificationId;
  } catch (err) {
    console.error(`Failed to create notification for user ${userId}:`, err.message);
    return null;
  }
}

// Function to find next eligible driver and create a request
// FR-10: Nearest Available Verified Driver Matching
async function createRideRequest(ride, channel, failedDrivers = []) {
  const LOCATION_STALE_MS = 5 * 60 * 1000; // 5 minutes
  const fiveMinutesAgoISO = new Date(Date.now() - LOCATION_STALE_MS).toISOString();

  // FR-10: Eligible = busy=0, verified, not failed, location present and not stale
  let query = `
    SELECT * FROM drivers
    WHERE busy = 0 AND licenseStatus = 'verified'
    AND latitude IS NOT NULL AND longitude IS NOT NULL
    AND locationUpdatedAt IS NOT NULL
    AND locationUpdatedAt > ?
  `;
  const params = [fiveMinutesAgoISO];

  if (failedDrivers.length > 0) {
    query += ` AND id NOT IN (${failedDrivers.map(() => "?").join(",")})`;
    params.push(...failedDrivers);
  }

  query += ` ORDER BY id ASC`;
  const eligibleDrivers = db.prepare(query).all(...params);

  if (eligibleDrivers.length === 0) {
    console.log("No eligible driver with valid recent location - leaving ride as pending.");
    appendAuditLog({ event: "no_eligible_driver_available", rideId: ride.id });
    return null;
  }

  // FR-10: Calculate Haversine distance from pickup to each eligible driver
  const driversWithDistance = eligibleDrivers.map(driver => ({
    ...driver,
    _distanceKm: haversineKm(ride.pickupLat, ride.pickupLng, driver.latitude, driver.longitude)
  }));

  // FR-10: Sort by distance ascending, then driver ID ascending for determinism
  driversWithDistance.sort((a, b) => {
    if (a._distanceKm !== b._distanceKm) return a._distanceKm - b._distanceKm;
    return a.id.localeCompare(b.id);
  });

  console.log(`FR-10: Found ${driversWithDistance.length} eligible driver(s) with valid location for ride ${ride.id}`);
  driversWithDistance.forEach(d => {
    console.log(`  Driver ${d.id}: ${d._distanceKm.toFixed(2)} km from pickup`);
  });

  // FR-10: Try each driver in order (nearest first), SOAP verify before sending request
  for (const candidate of driversWithDistance) {
    if (failedDrivers.includes(candidate.id)) continue;

    try {
      const { result } = await verifyDriver(candidate.id);
      const verified = result && result.verified === true;

      if (!verified) {
        appendAuditLog({ event: "driver_verification_failed", rideId: ride.id, driverId: candidate.id });
        console.log(`FR-10: Driver ${candidate.id} failed SOAP verification, trying next nearest`);
        failedDrivers.push(candidate.id);
        continue;
      }

      // Create ride request (not assigned yet - waiting for driver response)
      const now = new Date().toISOString();
      const expiresAt = new Date(Date.now() + REQUEST_TIMEOUT_MS).toISOString();

      db.prepare(
        `UPDATE rides SET requestedDriver=?, requestStatus=?, requestCreatedAt=?, requestExpiresAt=? WHERE id=?`
      ).run(candidate.id, "pending", now, expiresAt, ride.id);

      appendAuditLog({
        event: "ride_request_created",
        rideId: ride.id,
        driverId: candidate.id,
        distanceKm: candidate._distanceKm,
        expiresAt,
      });

      // FR-16: Create "New ride request" notification for driver (additive, non-blocking)
      if (candidate.user_id) {
        createNotification(
          candidate.user_id,
          ride.id,
          "ride_request",
          "New ride request available",
          `New ${ride.rideType || "Tricycle"} ride from ${ride.passengerName}. Pickup: (${ride.pickupLat}, ${ride.pickupLng}). Respond within 15 seconds.`
        );
      }

      console.log(`FR-10: Ride request created for nearest driver ${candidate.id} (${candidate._distanceKm.toFixed(2)} km), ride ${ride.id} (expires at ${expiresAt})`);
      return candidate;
    } catch (err) {
      console.warn("SOAP verification error:", err.message);
      appendAuditLog({ event: "soap_error", rideId: ride.id, driverId: candidate.id, error: err.message });
      failedDrivers.push(candidate.id);
      continue;
    }
  }

  // All eligible drivers failed SOAP verification
  console.log(`FR-10: All ${driversWithDistance.length} eligible driver(s) failed SOAP verification for ride ${ride.id}`);
  appendAuditLog({ event: "all_drivers_failed_verification", rideId: ride.id, driverCount: driversWithDistance.length });
  return null;

  }

// Check for expired requests and reassign
function checkExpiredRequests() {
  const now = new Date().toISOString();

  // Find requests that have expired and are still pending
  const expiredRequests = db.prepare(
    `SELECT * FROM rides WHERE requestStatus = 'pending' AND requestExpiresAt < ? AND status = 'pending'`
  ).all(now);

  expiredRequests.forEach((ride) => {
    console.log(`Request timeout for ride ${ride.id}, driver ${ride.requestedDriver}`);
    appendAuditLog({
      event: "ride_request_timeout",
      rideId: ride.id,
      driverId: ride.requestedDriver,
    });

    // Clear the expired request
    db.prepare(
      `UPDATE rides SET requestedDriver=?, requestStatus=?, requestCreatedAt=?, requestExpiresAt=? WHERE id=?`
    ).run(null, null, null, null, ride.id);

    // Requeue the ride for the next driver
    if (amqpChannel) {
      amqpChannel.sendToQueue("ride_requests", Buffer.from(JSON.stringify(ride)), { durable: true });
      console.log(`Re-queued ride ${ride.id} for next eligible driver`);
    }
  });
}

let amqpChannel;

// Export a function to broadcast ride request events (called by API endpoints via server.js)
function broadcastRideRequest(io, ride) {
  if (!io || !ride.requestedDriver) return;
  io.emit("ride:request", ride);
  console.log(`Broadcasted ride request for driver ${ride.requestedDriver}`);
}

async function start() {
  const conn = await amqp.connect(process.env.RABBITMQ_URL || "amqp://localhost");
  const channel = await conn.createChannel();
  amqpChannel = channel;
  await channel.assertQueue("ride_requests", { durable: true });

  console.log("Queue consumer started, waiting for ride requests...");

  // Start timeout check interval
  setInterval(checkExpiredRequests, TIMEOUT_CHECK_INTERVAL_MS);

  channel.consume("ride_requests", async (msg) => {
    if (!msg) return;
    const ride = JSON.parse(msg.content.toString());
    console.log(`Processing ride ${ride.id}...`);

    // Create request for first available verified driver
    const driver = await createRideRequest(ride, channel);

    channel.ack(msg);
  });
}

module.exports = { start, broadcastRideRequest };

start().catch((err) => console.error("Queue consumer failed to start:", err.message));
