// routes/drivers.js
const express = require("express");
const router = express.Router();
const db = require("../db");
const { authenticateToken, requireRole } = require("../middleware/auth");
const { verifyDriver } = require("../services/soapClient");

// GET /api/drivers - List all drivers (public, no auth required)
router.get("/", (req, res) => {
  res.json(db.prepare("SELECT * FROM drivers").all());
});

// GET /api/drivers/me - Get the authenticated driver's own profile.
// The driver identity comes from the JWT (req.user.id) resolved through
// drivers.user_id. A driverId is NEVER read from the request, so the
// frontend cannot select another driver's profile.
router.get("/me", authenticateToken, requireRole("driver"), (req, res) => {
  const driver = db.prepare("SELECT * FROM drivers WHERE user_id = ?").get(req.user.id);
  if (!driver) {
    return res.status(404).json({ error: "Driver profile not found" });
  }
  // Defensive: the drivers row never contains password data, but never leak
  // any auth-sensitive field if one is ever added to this table.
  const safe = { ...driver };
  delete safe.password_hash;
  res.json(safe);
});

// GET /api/drivers/:id - Get single driver (public)
router.get("/:id", (req, res) => {
  const driver = db.prepare("SELECT * FROM drivers WHERE id = ?").get(req.params.id);
  if (!driver) return res.status(404).json({ error: "Driver not found" });
  res.json(driver);
});

// POST /api/drivers - Create driver account (authenticated drivers only)
// Links the driver record to the authenticated user
router.post("/", authenticateToken, requireRole("driver"), (req, res) => {
  const id = "d" + Date.now();
  const { name, plateNumber } = req.body;

  // Associate this driver record with the authenticated user
  // A driver can only create one driver record linked to their user account
  const existingDriver = db.prepare("SELECT id FROM drivers WHERE user_id = ?").get(req.user.id);
  if (existingDriver) {
    return res.status(409).json({ error: "You already have a driver record. Only one driver record per user." });
  }

  db.prepare("INSERT INTO drivers (id, user_id, name, plateNumber, licenseStatus, verified, busy) VALUES (?,?,?,?,?,0,0)").run(
    id,
    req.user.id,
    name,
    plateNumber,
    "pending"
  );
  res.status(201).json({ id, user_id: req.user.id, name, plateNumber, licenseStatus: "pending", verified: 0, busy: 0 });
});

// PUT /api/drivers/:id - Update driver (authenticated drivers can update own, admin can update any)
router.put("/:id", authenticateToken, requireRole("driver", "admin"), (req, res) => {
  const driver = db.prepare("SELECT * FROM drivers WHERE id = ?").get(req.params.id);
  if (!driver) return res.status(404).json({ error: "Driver not found" });

  // Role-based access control:
  // - Admins can update any driver
  // - Drivers can only update their own driver record (by user_id match)
  if (req.user.role === "driver" && driver.user_id !== req.user.id) {
    // Driver is trying to modify someone else's record
    return res.status(403).json({ error: "You can only modify your own driver record." });
  }

  // Prevent drivers from reassigning driver records to other users
  if (req.user.role === "driver" && req.body.user_id && req.body.user_id !== req.user.id) {
    return res.status(403).json({ error: "You cannot reassign a driver record to another user." });
  }

  // Prevent drivers from manually changing verification status
  if (req.user.role === "driver" && (req.body.licenseStatus || req.body.verified)) {
    return res.status(403).json({ error: "License status is controlled by verification process only." });
  }

  // FR-10: Block location field injection through API
  // Location is only updated via authenticated Socket.io handler
  if (req.user.role === "driver") {
    delete req.body.latitude;
    delete req.body.longitude;
    delete req.body.locationUpdatedAt;
  }

  const updated = { ...driver, ...req.body };
  db.prepare("UPDATE drivers SET name=?, plateNumber=?, licenseNumber=?, licenseStatus=?, licenseVerifiedAt=?, verified=?, busy=?, user_id=? WHERE id=?").run(
    updated.name,
    updated.plateNumber,
    updated.licenseNumber || driver.licenseNumber,
    updated.licenseStatus || driver.licenseStatus,
    updated.licenseVerifiedAt || driver.licenseVerifiedAt,
    updated.verified ? 1 : 0,
    updated.busy ? 1 : 0,
    updated.user_id || driver.user_id,
    req.params.id
  );
  res.json(db.prepare("SELECT * FROM drivers WHERE id = ?").get(req.params.id));
});

// POST /api/drivers/:id/submit-license - Submit driver license for verification
router.post("/:id/submit-license", authenticateToken, requireRole("driver"), async (req, res) => {
  const { licenseNumber } = req.body;

  if (!licenseNumber) {
    return res.status(400).json({ error: "License number is required." });
  }

  const driver = db.prepare("SELECT * FROM drivers WHERE id = ?").get(req.params.id);
  if (!driver) return res.status(404).json({ error: "Driver not found" });

  // Drivers can only submit licenses for their own driver records
  if (driver.user_id !== req.user.id) {
    return res.status(403).json({ error: "You can only submit licenses for your own driver record." });
  }

  try {
    // Call SOAP verification service
    const { result } = await verifyDriver(req.params.id);
    const isVerified = result && result.verified === true;

    // Persist license information and verification status
    const newStatus = isVerified ? "verified" : "rejected";
    const now = new Date().toISOString();

    db.prepare("UPDATE drivers SET licenseNumber=?, licenseStatus=?, licenseVerifiedAt=?, verified=? WHERE id=?").run(
      licenseNumber,
      newStatus,
      now,
      isVerified ? 1 : 0,
      req.params.id
    );

    const updatedDriver = db.prepare("SELECT * FROM drivers WHERE id = ?").get(req.params.id);

    res.status(200).json({
      message: isVerified ? "License verified successfully." : "License verification failed.",
      driver: updatedDriver,
      verified: isVerified,
    });
  } catch (err) {
    console.error("License verification error:", err.message);
    // Update license number but set status to pending (SOAP unavailable)
    db.prepare("UPDATE drivers SET licenseNumber=?, licenseStatus=? WHERE id=?").run(
      licenseNumber,
      "pending",
      req.params.id
    );
    res.status(503).json({
      error: "License verification service unavailable. License saved with pending status.",
      message: err.message,
    });
  }
});

// DELETE /api/drivers/:id - Delete driver (authenticated admin only)
router.delete("/:id", authenticateToken, requireRole("admin"), (req, res) => {
  db.prepare("DELETE FROM drivers WHERE id = ?").run(req.params.id);
  res.status(204).end();
});

module.exports = router;
