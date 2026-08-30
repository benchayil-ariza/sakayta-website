// services/queueConsumer.js
// This is a SEPARATE process (run with: npm run worker) that listens to the
// RabbitMQ queue and does the actual work: pick a driver, verify them through
// the SOAP service, update the database, and log it.
//
// This one file ties together THREE requirements at once:
//  - Middleware/messaging (it's the queue consumer)
//  - SOAP consumption (calls verifyDriver)
//  - The second data store (writes to audit-log.json, separate from SQLite)

const amqp = require("amqplib");
const fs = require("fs");
const path = require("path");
const db = require("../db");
const { verifyDriver } = require("./soapClient");

const AUDIT_LOG = path.join(__dirname, "..", "data", "audit-log.json");

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

async function start() {
  const conn = await amqp.connect(process.env.RABBITMQ_URL || "amqp://localhost");
  const channel = await conn.createChannel();
  await channel.assertQueue("ride_requests", { durable: true });

  console.log("Queue consumer started, waiting for ride requests...");

  channel.consume("ride_requests", async (msg) => {
    if (!msg) return;
    const ride = JSON.parse(msg.content.toString());
    console.log(`Processing ride ${ride.id}...`);

    const availableDriver = db.prepare("SELECT * FROM drivers WHERE busy = 0 LIMIT 1").get();

    if (!availableDriver) {
      console.log("No available drivers right now - leaving ride as pending.");
      appendAuditLog({ event: "no_driver_available", rideId: ride.id });
      channel.ack(msg);
      return;
    }

    try {
      const { result } = await verifyDriver(availableDriver.id);
      const verified = result && result.verified === true;

      if (verified) {
        db.prepare("UPDATE rides SET driverId=?, status=? WHERE id=?").run(
          availableDriver.id,
          "assigned",
          ride.id
        );
        db.prepare("UPDATE drivers SET busy=1 WHERE id=?").run(availableDriver.id);
        appendAuditLog({
          event: "ride_assigned",
          rideId: ride.id,
          driverId: availableDriver.id,
          licenseNumber: result.licenseNumber,
        });
        console.log(`Ride ${ride.id} assigned to driver ${availableDriver.id} (SOAP verified)`);
      } else {
        appendAuditLog({ event: "driver_verification_failed", rideId: ride.id, driverId: availableDriver.id });
        console.log(`Driver ${availableDriver.id} failed SOAP verification for ride ${ride.id}`);
      }
    } catch (err) {
      console.warn("SOAP verification error:", err.message);
      appendAuditLog({ event: "soap_error", rideId: ride.id, error: err.message });
    }

    channel.ack(msg);
  });
}

start().catch((err) => console.error("Queue consumer failed to start:", err.message));
