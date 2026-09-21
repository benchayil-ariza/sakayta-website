// routes/notifications.js
const express = require("express");
const router = express.Router();
const db = require("../db");
const { authenticateToken } = require("../middleware/auth");

// GET /api/notifications - List authenticated user's notifications (newest first)
router.get("/", authenticateToken, (req, res) => {
  try {
    const notifications = db
      .prepare("SELECT * FROM notifications WHERE userId = ? ORDER BY createdAt DESC")
      .all(req.user.id);

    res.json({
      count: notifications.length,
      notifications
    });
  } catch (err) {
    res.status(500).json({ error: "Failed to retrieve notifications: " + err.message });
  }
});

// GET /api/notifications/:id - Get single notification (if user owns it)
router.get("/:id", authenticateToken, (req, res) => {
  try {
    const notification = db
      .prepare("SELECT * FROM notifications WHERE id = ?")
      .get(req.params.id);

    if (!notification) {
      return res.status(404).json({ error: "Notification not found" });
    }

    // Verify ownership
    if (notification.userId !== req.user.id) {
      return res.status(403).json({ error: "You do not have access to this notification" });
    }

    res.json(notification);
  } catch (err) {
    res.status(500).json({ error: "Failed to retrieve notification: " + err.message });
  }
});

// PUT /api/notifications/:id/read - Mark notification as read
router.put("/:id/read", authenticateToken, (req, res) => {
  try {
    const notification = db
      .prepare("SELECT * FROM notifications WHERE id = ?")
      .get(req.params.id);

    if (!notification) {
      return res.status(404).json({ error: "Notification not found" });
    }

    // Verify ownership
    if (notification.userId !== req.user.id) {
      return res.status(403).json({ error: "You do not have access to this notification" });
    }

    // Mark as read
    db.prepare("UPDATE notifications SET read = 1 WHERE id = ?").run(req.params.id);

    const updated = db
      .prepare("SELECT * FROM notifications WHERE id = ?")
      .get(req.params.id);

    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: "Failed to update notification: " + err.message });
  }
});

// DELETE /api/notifications/:id - Delete notification (if user owns it)
router.delete("/:id", authenticateToken, (req, res) => {
  try {
    const notification = db
      .prepare("SELECT * FROM notifications WHERE id = ?")
      .get(req.params.id);

    if (!notification) {
      return res.status(404).json({ error: "Notification not found" });
    }

    // Verify ownership
    if (notification.userId !== req.user.id) {
      return res.status(403).json({ error: "You do not have access to this notification" });
    }

    // Delete
    db.prepare("DELETE FROM notifications WHERE id = ?").run(req.params.id);

    res.status(204).end();
  } catch (err) {
    res.status(500).json({ error: "Failed to delete notification: " + err.message });
  }
});

module.exports = router;
