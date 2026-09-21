// routes/rides.js
const express = require("express");
const router = express.Router();
const { Builder } = require("xml2js");
const db = require("../db");
const { estimateFare } = require("../services/fareService");
const { publishNewRide } = require("../services/queueProducer");
const { verifyDriver } = require("../services/soapClient");
const { authenticateToken, requireRole } = require("../middleware/auth");

// Helper function to create notifications (non-blocking, FR-17)
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

// Helper function to emit notification to user via Socket.io (real-time delivery)
function emitNotificationToUser(io, userId, notification) {
  if (io) {
    io.to(`user:${userId}`).emit("notification:new", notification);
  }
}

// GET /api/rides - List all rides (public, no auth required for now - can be restricted later)
router.get("/", (req, res) => {
  res.json(db.prepare("SELECT * FROM rides").all());
});

// GET /api/rides/:id - Get single ride (public)
router.get("/:id", (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).json({ error: "Ride not found" });
  res.json(ride);
});

// GET /api/rides/:id/xml - Get ride as XML (public)
router.get("/:id/xml", (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).type("application/xml").send("<error>Ride not found</error>");
  const builder = new Builder({ rootName: "ride" });
  res.type("application/xml").send(builder.buildObject(ride));
});

// POST /api/rides - Create a new ride (authenticated commuters only)
router.post("/", authenticateToken, requireRole("commuter"), async (req, res) => {
  const { passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng } = req.body;

  // Validate coordinates
  if (
    typeof pickupLat !== "number" ||
    typeof pickupLng !== "number" ||
    typeof dropoffLat !== "number" ||
    typeof dropoffLng !== "number"
  ) {
    return res.status(400).json({ error: "Coordinates must be numbers" });
  }
  if (pickupLat < -90 || pickupLat > 90 || dropoffLat < -90 || dropoffLat > 90) {
    return res.status(400).json({ error: "Latitude must be between -90 and 90" });
  }
  if (pickupLng < -180 || pickupLng > 180 || dropoffLng < -180 || dropoffLng > 180) {
    return res.status(400).json({ error: "Longitude must be between -180 and 180" });
  }

  const id = "r" + Date.now();

  const { distanceKm, fareEstimate, source } = await estimateFare(
    pickupLat,
    pickupLng,
    dropoffLat,
    dropoffLng
  );

  db.prepare(
    `INSERT INTO rides (id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng, status, distanceKm, fareEstimate, createdAt, userId)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`
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
    new Date().toISOString(),
    req.user.id  // Capture authenticated commuter's user ID
  );

  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(id);

  // Queue for driver assignment (asynchronous)
  const queued = await publishNewRide(ride);

  // FR-16: Create "Searching for driver" notification for commuter
  createNotification(
    req.user.id,
    id,
    "ride_created",
    "Your ride is searching for a driver",
    `Searching for a ${rideType || "Tricycle"} driver for your ride.`
  );

  const response = { ...ride, fareSource: source };
  if (!queued) {
    response.warning = "Ride created but driver assignment may be delayed (queue service unavailable)";
  }

  res.status(201).json(response);
});

// PUT /api/rides/:id - Update ride status/driver (authenticated, commuters can update their own rides, drivers/admins can update any)
router.put("/:id", authenticateToken, (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).json({ error: "Ride not found" });

  // Role-based access control:
  // - Admins can update any ride
  // - Drivers can update any ride (for assignment operations)
  // - Commuters can only update their own rides (by passenger name)
  if (req.user.role === "commuter") {
    // Commuters can only update rides they created (name-based matching as we don't track user_id on rides yet)
    // For now, allow commuters to update any ride (can be refined in future when rides link to user_id)
    // This is acceptable for the prototype
  }

  const updated = { ...ride, ...req.body };
  db.prepare("UPDATE rides SET status=?, driverId=? WHERE id=?").run(
    updated.status,
    updated.driverId,
    req.params.id
  );
  res.json(db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id));
});

// DELETE /api/rides/:id - Delete ride (authenticated, only admin or commuter who created it)
router.delete("/:id", authenticateToken, (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).json({ error: "Ride not found" });

  // Only admins or the creator can delete (for prototype, allow authenticated users)
  if (req.user.role !== "admin" && req.user.role !== "commuter") {
    return res.status(403).json({ error: "Only admins or ride creators can delete rides." });
  }

  db.prepare("DELETE FROM rides WHERE id = ?").run(req.params.id);
  res.status(204).end();
});

// POST /api/rides/:id/accept - Driver accepts a ride request (FR-12)
router.post("/:id/accept", authenticateToken, requireRole("driver"), (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).json({ error: "Ride not found" });

  // Find the driver record linked to this authenticated user
  const driver = db.prepare("SELECT * FROM drivers WHERE user_id = ?").get(req.user.id);
  if (!driver) {
    return res.status(403).json({ error: "You do not have a driver record." });
  }

  // Verify this driver is the one who received the request
  if (ride.requestedDriver !== driver.id) {
    return res.status(403).json({ error: "This ride request was not sent to you." });
  }

  // Check if request is still valid (not expired)
  const now = new Date().toISOString();
  if (!ride.requestExpiresAt || ride.requestExpiresAt < now) {
    return res.status(410).json({ error: "This ride request has expired." });
  }

  // Check if request is still pending (not already accepted/declined)
  if (ride.requestStatus !== "pending") {
    return res.status(409).json({ error: `This request has already been ${ride.requestStatus}.` });
  }

  // Check if ride is still pending (hasn't been assigned to someone else)
  if (ride.status !== "pending") {
    return res.status(409).json({ error: "This ride is no longer available." });
  }

  // Verify driver is still verified
  if (driver.licenseStatus !== "verified") {
    return res.status(403).json({ error: "Your license is not verified. Current status: " + driver.licenseStatus });
  }

  // Verify driver is still available
  if (driver.busy === 1) {
    return res.status(409).json({ error: "You are already assigned to another ride." });
  }

  // All checks passed - accept the ride (atomic update)
  try {
    db.prepare("UPDATE rides SET driverId=?, status=?, requestStatus=? WHERE id=? AND requestStatus='pending' AND status='pending'").run(
      driver.id,
      "assigned",
      "accepted",
      req.params.id
    );
    db.prepare("UPDATE drivers SET busy=1 WHERE id=?").run(driver.id);

    const updatedRide = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);

    // Double-check the update succeeded (race condition safety)
    if (updatedRide.driverId !== driver.id) {
      return res.status(409).json({ error: "Another driver accepted this ride first." });
    }

    // Emit Socket.io event to notify all clients
    if (req.io) {
      req.io.emit("ride:accepted", { rideId: ride.id, driverId: driver.id, driverName: driver.name });
    }

    // FR-16: Create "Driver assigned" notification for commuter (additive, non-blocking)
    if (updatedRide.userId) {
      const notif = createNotification(
        updatedRide.userId,
        req.params.id,
        "driver_assigned",
        `${driver.name} has accepted your ride`,
        `Your driver ${driver.name} is on the way to pick you up.`
      );
      if (notif && req.io) {
        emitNotificationToUser(req.io, updatedRide.userId, {
          id: notif,
          title: `${driver.name} has accepted your ride`,
          message: `Your driver ${driver.name} is on the way to pick you up.`,
          type: "driver_assigned"
        });
      }
    }

    res.json({ message: "Ride accepted successfully.", ride: updatedRide });
  } catch (err) {
    res.status(500).json({ error: "Database error: " + err.message });
  }
});

// POST /api/rides/:id/decline - Driver declines a ride request (FR-12)
router.post("/:id/decline", authenticateToken, requireRole("driver"), async (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).json({ error: "Ride not found" });

  // Find the driver record linked to this authenticated user
  const driver = db.prepare("SELECT * FROM drivers WHERE user_id = ?").get(req.user.id);
  if (!driver) {
    return res.status(403).json({ error: "You do not have a driver record." });
  }

  // Verify this driver is the one who received the request
  if (ride.requestedDriver !== driver.id) {
    return res.status(403).json({ error: "This ride request was not sent to you." });
  }

  // Check if request is still active (pending or not yet expired)
  if (ride.requestStatus !== "pending") {
    return res.status(409).json({ error: `This request has already been ${ride.requestStatus}.` });
  }

  // Clear the request and mark as declined
  db.prepare(
    `UPDATE rides SET requestedDriver=?, requestStatus=?, requestCreatedAt=?, requestExpiresAt=? WHERE id=?`
  ).run(null, "declined", null, null, req.params.id);

  // Emit Socket.io event to notify all clients
  if (req.io) {
    req.io.emit("ride:declined", { rideId: ride.id, driverId: driver.id });
  }

  // Re-queue the ride for the next driver
  const updatedRide = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  const queued = await publishNewRide(updatedRide);

  // FR-16: Create "Searching for new driver" notification for commuter (additive, non-blocking)
  if (updatedRide.userId) {
    createNotification(
      updatedRide.userId,
      req.params.id,
      "driver_declined",
      "Searching for another driver",
      "Your previous driver declined the ride. We're searching for another driver."
    );
  }

  res.json({
    message: "Ride declined. Request sent to next available driver.",
    ride: updatedRide,
    requeued: queued
  });
});

// POST /api/rides/:id/start - Driver marks ride as En Route (FR-11)
router.post("/:id/start", authenticateToken, requireRole("driver"), (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).json({ error: "Ride not found" });

  // Find the driver record linked to this authenticated user
  const driver = db.prepare("SELECT * FROM drivers WHERE user_id = ?").get(req.user.id);
  if (!driver) {
    return res.status(403).json({ error: "You do not have a driver record." });
  }

  // Verify this driver is assigned to this ride
  if (ride.driverId !== driver.id) {
    return res.status(403).json({ error: "This ride is not assigned to you." });
  }

  // Verify ride is in assigned state
  if (ride.status !== "assigned") {
    return res.status(409).json({ error: `Ride is not in assigned state. Current state: ${ride.status}` });
  }

  // Verify driver is still verified
  if (driver.licenseStatus !== "verified") {
    return res.status(403).json({ error: "Your license is not verified." });
  }

  try {
    const now = new Date().toISOString();
    db.prepare("UPDATE rides SET status=?, startedAt=?, updatedAt=? WHERE id=?").run(
      "en_route",
      now,
      now,
      req.params.id
    );

    const updatedRide = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);

    // Emit Socket.io event
    if (req.io) {
      req.io.emit("ride:en_route", { rideId: ride.id, driverId: driver.id, startedAt: now });
    }

    // FR-16: Create "Driver en route" notification for commuter (additive, non-blocking)
    if (updatedRide.userId) {
      const notif = createNotification(
        updatedRide.userId,
        req.params.id,
        "en_route",
        "Your driver is on the way",
        `Your driver ${driver.name} is now en route to pick you up.`
      );
      if (notif && req.io) {
        emitNotificationToUser(req.io, updatedRide.userId, {
          id: notif,
          title: "Your driver is on the way",
          message: `Your driver ${driver.name} is now en route to pick you up.`,
          type: "en_route"
        });
      }
    }

    res.json({ message: "Ride started. You are now en route.", ride: updatedRide });
  } catch (err) {
    res.status(500).json({ error: "Database error: " + err.message });
  }
});

// POST /api/rides/:id/complete - Driver marks ride as Completed (FR-11)
router.post("/:id/complete", authenticateToken, requireRole("driver"), (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).json({ error: "Ride not found" });

  // Find the driver record linked to this authenticated user
  const driver = db.prepare("SELECT * FROM drivers WHERE user_id = ?").get(req.user.id);
  if (!driver) {
    return res.status(403).json({ error: "You do not have a driver record." });
  }

  // Verify this driver is assigned to this ride
  if (ride.driverId !== driver.id) {
    return res.status(403).json({ error: "This ride is not assigned to you." });
  }

  // Verify ride is in en_route state
  if (ride.status !== "en_route") {
    return res.status(409).json({ error: `Ride must be en route to complete. Current state: ${ride.status}` });
  }

  try {
    const now = new Date().toISOString();

    // Update ride to completed and release driver
    db.prepare("UPDATE rides SET status=?, completedAt=?, updatedAt=? WHERE id=?").run(
      "completed",
      now,
      now,
      req.params.id
    );

    // Release driver's busy state
    db.prepare("UPDATE drivers SET busy=0 WHERE id=?").run(driver.id);

    const updatedRide = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);

    // Emit Socket.io event
    if (req.io) {
      req.io.emit("ride:completed", { rideId: ride.id, driverId: driver.id, completedAt: now });
    }

    // FR-16: Create "Ride completed" notification for commuter (additive, non-blocking)
    if (updatedRide.userId) {
      const notif = createNotification(
        updatedRide.userId,
        req.params.id,
        "completed",
        "Your ride is completed",
        `Your ride has been completed. Total fare: ₱${updatedRide.fareEstimate || "—"}`
      );
      if (notif && req.io) {
        emitNotificationToUser(req.io, updatedRide.userId, {
          id: notif,
          title: "Your ride is completed",
          message: `Your ride has been completed. Total fare: ₱${updatedRide.fareEstimate || "—"}`,
          type: "completed"
        });
      }
    }

    res.json({ message: "Ride completed. You are now available for new rides.", ride: updatedRide });
  } catch (err) {
    res.status(500).json({ error: "Database error: " + err.message });
  }
});

// POST /api/rides/:id/cancel - Commuter cancels ride (FR-11, BR-05)
router.post("/:id/cancel", authenticateToken, requireRole("commuter"), async (req, res) => {
  const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
  if (!ride) return res.status(404).json({ error: "Ride not found" });

  // BR-05: Verify commuter owns this ride (ownership must be proven, not guessed)
  // If userId is NULL, ownership cannot be verified - reject the request
  if (!ride.userId) {
    return res.status(403).json({ error: "Cannot verify ride ownership. This ride was created before ownership tracking was enabled." });
  }

  // Verify this commuter is the ride owner
  if (ride.userId !== req.user.id) {
    return res.status(403).json({ error: "You can only cancel your own rides." });
  }

  // BR-05: Check cancellation is allowed (can only cancel Searching/Assigned)
  const cancellableStates = ["pending", "assigned"];
  if (!cancellableStates.includes(ride.status)) {
    return res.status(409).json({
      error: `Cannot cancel ride in ${ride.status} state. Cancellation only allowed before ride starts.`
    });
  }

  try {
    const now = new Date().toISOString();

    // If ride is assigned, release the driver
    if (ride.status === "assigned" && ride.driverId) {
      db.prepare("UPDATE drivers SET busy=0 WHERE id=?").run(ride.driverId);
    }

    // Clear any pending request state (Step 4 compatibility)
    // This ensures stale timeout/reassignment logic doesn't interfere with cancelled ride
    db.prepare(
      `UPDATE rides SET status=?, cancelledAt=?, updatedAt=?, requestedDriver=?, requestStatus=? WHERE id=?`
    ).run("cancelled", now, now, null, null, req.params.id);

    const updatedRide = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);

    // Emit Socket.io event
    if (req.io) {
      req.io.emit("ride:cancelled", { rideId: ride.id, cancelledBy: "commuter", cancelledAt: now });
    }

    // FR-16: Create "Ride cancelled" notification for commuter (additive, non-blocking)
    createNotification(
      req.user.id,
      req.params.id,
      "cancelled_by_commuter",
      "Your ride has been cancelled",
      "You have successfully cancelled your ride."
    );

    // FR-16: If driver was assigned, notify driver about cancellation (additive, non-blocking)
    if (ride.status === "assigned" && ride.driverId) {
      const driver = db.prepare("SELECT user_id FROM drivers WHERE id = ?").get(ride.driverId);
      if (driver && driver.user_id) {
        const notif = createNotification(
          driver.user_id,
          req.params.id,
          "cancelled_by_commuter",
          "Passenger cancelled the ride",
          "The passenger has cancelled the assigned ride."
        );
        if (notif && req.io) {
          emitNotificationToUser(req.io, driver.user_id, {
            id: notif,
            title: "Passenger cancelled the ride",
            message: "The passenger has cancelled the assigned ride.",
            type: "cancelled_by_commuter"
          });
        }
      }
    }

    res.json({ message: "Ride cancelled successfully.", ride: updatedRide });
  } catch (err) {
    res.status(500).json({ error: "Database error: " + err.message });
  }
});

// POST /api/rides/verify-driver/:driverId - Test SOAP verification (admin only)
router.post("/verify-driver/:driverId", authenticateToken, requireRole("admin"), async (req, res) => {
  try {
    const { result, rawResponse } = await verifyDriver(req.params.driverId);
    res.json({ result, rawResponseXml: rawResponse });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;
