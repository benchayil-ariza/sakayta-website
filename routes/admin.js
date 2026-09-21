// routes/admin.js
const express = require("express");
const router = express.Router();
const db = require("../db");
const { authenticateToken, requireRole } = require("../middleware/auth");
const { verifyDriver } = require("../services/soapClient");

// Helper function to log admin actions (BR-06)
function logAdminAction(actionType, description, adminId, metadata = null) {
  try {
    db.prepare(`
      INSERT INTO admin_action_logs (actionType, description, performedBy, performedAt, metadata)
      VALUES (?, ?, ?, ?, ?)
    `).run(
      actionType,
      description,
      adminId,
      new Date().toISOString(),
      metadata ? JSON.stringify(metadata) : null
    );
  } catch (err) {
    console.error("Failed to log admin action:", err.message);
  }
}

// GET /api/admin/fare - Get current fare settings
router.get("/fare", authenticateToken, requireRole("admin"), (req, res) => {
  const config = db.prepare("SELECT * FROM fare_config ORDER BY updatedAt DESC LIMIT 1").get();
  if (!config) {
    return res.status(404).json({ error: "Fare configuration not found" });
  }
  res.json(config);
});

// PUT /api/admin/fare - Update fare settings (BR-04 compliant)
router.put("/fare", authenticateToken, requireRole("admin"), (req, res) => {
  const { baseFare, ratePerKm } = req.body;

  if (typeof baseFare !== "number" || typeof ratePerKm !== "number") {
    return res.status(400).json({ error: "baseFare and ratePerKm must be numbers" });
  }

  if (baseFare < 0 || ratePerKm < 0) {
    return res.status(400).json({ error: "Fare values cannot be negative" });
  }

  const now = new Date().toISOString();

  // Create a new version of the configuration
  db.prepare(`
    INSERT INTO fare_config (baseFare, ratePerKm, updatedAt, updatedBy)
    VALUES (?, ?, ?, ?)
  `).run(baseFare, ratePerKm, now, req.user.id);

  // Log the action for audit (BR-06)
  logAdminAction(
    "update_fare_settings",
    `Updated fare settings to baseFare: ${baseFare}, ratePerKm: ${ratePerKm}`,
    req.user.id,
    { baseFare, ratePerKm }
  );

  res.json({
    message: "Fare settings updated successfully.",
    config: { baseFare, ratePerKm, updatedAt: now }
  });
});

// GET /api/admin/logs - Get admin action logs
router.get("/logs", authenticateToken, requireRole("admin"), (req, res) => {
  const logs = db.prepare("SELECT * FROM admin_action_logs ORDER BY performedAt DESC").all();
  res.json(logs);
});

// GET /api/admin/stats - Get dashboard statistics
router.get("/stats", authenticateToken, requireRole("admin"), (req, res) => {
  try {
    const stats = {};

    // Total users
    stats.totalUsers = db.prepare("SELECT COUNT(*) as count FROM users").get().count;

    // Total drivers
    stats.totalDrivers = db.prepare("SELECT COUNT(*) as count FROM drivers").get().count;

    // Verified drivers (licenseStatus = 'verified')
    stats.verifiedDrivers = db.prepare("SELECT COUNT(*) as count FROM drivers WHERE licenseStatus = 'verified'").get().count;

    // Pending/unverified/rejected drivers
    stats.pendingDrivers = db.prepare("SELECT COUNT(*) as count FROM drivers WHERE licenseStatus = 'pending'").get().count;
    stats.rejectedDrivers = db.prepare("SELECT COUNT(*) as count FROM drivers WHERE licenseStatus = 'rejected'").get().count;

    // Total rides
    stats.totalRides = db.prepare("SELECT COUNT(*) as count FROM rides").get().count;

    // Rides by status
    stats.pendingRides = db.prepare("SELECT COUNT(*) as count FROM rides WHERE status = 'pending'").get().count;
    stats.assignedRides = db.prepare("SELECT COUNT(*) as count FROM rides WHERE status = 'assigned'").get().count;
    stats.enRouteRides = db.prepare("SELECT COUNT(*) as count FROM rides WHERE status = 'en_route'").get().count;
    stats.completedRides = db.prepare("SELECT COUNT(*) as count FROM rides WHERE status = 'completed'").get().count;
    stats.cancelledRides = db.prepare("SELECT COUNT(*) as count FROM rides WHERE status = 'cancelled'").get().count;

    res.json(stats);
  } catch (err) {
    console.error("Error fetching admin stats:", err.message);
    res.status(500).json({ error: "Failed to fetch statistics" });
  }
});

// GET /api/admin/drivers - Get list of drivers with search and filter
router.get("/drivers", authenticateToken, requireRole("admin"), (req, res) => {
  try {
    let query = "SELECT * FROM drivers";
    const params = [];
    const conditions = [];

    // Search by name, plate number, or license number
    const search = req.query.search;
    if (search) {
      conditions.push(`(name LIKE ? OR plateNumber LIKE ? OR licenseNumber LIKE ?)`);
      const searchTerm = `%${search}%`;
      params.push(searchTerm, searchTerm, searchTerm);
    }

    // Filter by license status
    const licenseStatus = req.query.licenseStatus;
    if (licenseStatus) {
      conditions.push("licenseStatus = ?");
      params.push(licenseStatus);
    }

    // Filter by busy status
    const busy = req.query.busy;
    if (busy !== undefined && busy !== '') {
      conditions.push("busy = ?");
      params.push(busy === 'true' ? 1 : 0);
    }

    if (conditions.length > 0) {
      query += " WHERE " + conditions.join(" AND ");
    }

    // Add ordering
    query += " ORDER BY name";

    const drivers = db.prepare(query).all(...params);
    res.json(drivers);
  } catch (err) {
    console.error("Error fetching drivers:", err.message);
    res.status(500).json({ error: "Failed to fetch drivers" });
  }
});

// PUT /api/admin/drivers/:id/verify - Verify driver's license (triggers SOAP verification)
router.put("/drivers/:id/verify", authenticateToken, requireRole("admin"), async (req, res) => {
  try {
    const driverId = req.params.id;

    // Check if driver exists
    const driver = db.prepare("SELECT * FROM drivers WHERE id = ?").get(driverId);
    if (!driver) {
      return res.status(404).json({ error: "Driver not found" });
    }

    // Use the existing SOAP verification service
    const { result } = await verifyDriver(driverId);
    const isVerified = result && result.verified === true;

    const now = new Date().toISOString();
    const newStatus = isVerified ? "verified" : "rejected";

    // Update driver record
    db.prepare(`
      UPDATE drivers
      SET licenseStatus = ?,
          verified = ?,
          licenseVerifiedAt = ?
      WHERE id = ?
    `).run(
      newStatus,
      isVerified ? 1 : 0,
      isVerified ? now : null,
      driverId
    );

    // Log the action
    logAdminAction(
      isVerified ? "verify_driver" : "reject_driver",
      isVerified ? `Verified driver ${driverId}` : `Rejected driver ${driverId} (verification failed)`,
      req.user.id,
      { driverId, newStatus, verificationResult: result }
    );

    const updatedDriver = db.prepare("SELECT * FROM drivers WHERE id = ?").get(driverId);
    res.json({
      message: isVerified ? "Driver verified successfully." : "Driver verification failed.",
      driver: updatedDriver,
      verified: isVerified
    });
  } catch (err) {
    console.error("Error verifying driver:", err.message);
    res.status(500).json({ error: "Failed to verify driver" });
  }
});

// PUT /api/admin/drivers/:id/reject - Reject driver's license (without SOAP)
router.put("/drivers/:id/reject", authenticateToken, requireRole("admin"), (req, res) => {
  try {
    const driverId = req.params.id;

    // Check if driver exists
    const driver = db.prepare("SELECT * FROM drivers WHERE id = ?").get(driverId);
    if (!driver) {
      return res.status(404).json({ error: "Driver not found" });
    }

    const now = new Date().toISOString();

    // Update driver record to rejected
    db.prepare(`
      UPDATE drivers
      SET licenseStatus = 'rejected',
          verified = 0,
          licenseVerifiedAt = NULL
      WHERE id = ?
    `).run(driverId);

    // Log the action
    logAdminAction(
      "reject_driver",
      `Manually rejected driver ${driverId}`,
      req.user.id,
      { driverId }
    );

    const updatedDriver = db.prepare("SELECT * FROM drivers WHERE id = ?").get(driverId);
    res.json({
      message: "Driver rejected successfully.",
      driver: updatedDriver
    });
  } catch (err) {
    console.error("Error rejecting driver:", err.message);
    res.status(500).json({ error: "Failed to reject driver" });
  }
});

module.exports = router;
