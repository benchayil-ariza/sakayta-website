// routes/rides.js
const express = require("express");
const router = express.Router();
const { Builder } = require("xml2js");
const db = require("../db");
const { estimateFare } = require("../services/fareService");
const { publishNewRide } = require("../services/queueProducer");
const { verifyDriver } = require("../services/soapClient");

router.get("/", (req, res) => {
  res.json(db.prepare("SELECT * FROM rides").all());
});

router.get("/:id", (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).json({ error: "Ride not found" });
  res.json(ride);
});

// Same ride, but as XML - this is your "data formats: JSON and XML" evidence.
// Compare this side-by-side with the JSON version above at your defense.
router.get("/:id/xml", (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).type("application/xml").send("<error>Ride not found</error>");
  const builder = new Builder({ rootName: "ride" });
  res.type("application/xml").send(builder.buildObject(ride));
});

// Create a ride: estimates fare (3rd-party API), saves it, and queues it for driver assignment (messaging)
router.post("/", async (req, res) => {
  const { passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng } = req.body;
  const id = "r" + Date.now();

  const { distanceKm, fareEstimate, source } = await estimateFare(
    pickupLat,
    pickupLng,
    dropoffLat,
    dropoffLng
  );

  db.prepare(
    `INSERT INTO rides (id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng, status, distanceKm, fareEstimate, createdAt)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)`
  ).run(
    id,
    passengerName,
    rideType || "Tricycle",
    pickupLat,
    pickupLng,
    dropoffLat,
    dropoffLng,
    "pending",
    distanceKm,
    fareEstimate,
    new Date().toISOString()
  );

  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(id);

  // Don't assign a driver right here - queue it instead (asynchronous, via the worker process)
  publishNewRide(ride);

  res.status(201).json({ ...ride, fareSource: source });
});

router.put("/:id", (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).json({ error: "Ride not found" });
  const updated = { ...ride, ...req.body };
  db.prepare("UPDATE rides SET status=?, driverId=? WHERE id=?").run(
    updated.status,
    updated.driverId,
    req.params.id
  );
  res.json(db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id));
});

router.delete("/:id", (req, res) => {
  db.prepare("DELETE FROM rides WHERE id = ?").run(req.params.id);
  res.status(204).end();
});

// Manually test the SOAP call directly - good for showing the raw request/response envelope at defense
router.post("/verify-driver/:driverId", async (req, res) => {
  try {
    const { result, rawResponse } = await verifyDriver(req.params.driverId);
    res.json({ result, rawResponseXml: rawResponse });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;
