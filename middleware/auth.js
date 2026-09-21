// middleware/auth.js
const jwt = require("jsonwebtoken");
const db = require("../db");

const JWT_SECRET = process.env.JWT_SECRET || "sakayta-dev-secret-change-in-production";

/**
 * Middleware: Authenticate user via JWT token in Authorization header
 * Validates token and attaches user info to req.user
 * Returns 401 if missing, invalid, or expired
 */
const authenticateToken = (req, res, next) => {
  const authHeader = req.headers["authorization"];
  const token = authHeader && authHeader.split(" ")[1]; // Bearer TOKEN

  if (!token) {
    return res.status(401).json({ error: "No token provided. Authorization required." });
  }

  try {
    const decoded = jwt.verify(token, JWT_SECRET);

    // Fetch full user from database to ensure user still exists and get complete info
    const user = db.prepare("SELECT id, email, full_name, role, created_at FROM users WHERE id = ?").get(decoded.userId);

    if (!user) {
      return res.status(401).json({ error: "User not found. Token may be invalid." });
    }

    // Attach user to request (without password_hash)
    req.user = user;
    next();
  } catch (err) {
    if (err.name === "TokenExpiredError") {
      return res.status(401).json({ error: "Token expired. Please log in again." });
    }
    return res.status(401).json({ error: "Invalid token." });
  }
};

/**
 * Middleware: Authorize user by role(s)
 * Rejects with 403 if user's role is not in the allowed list
 */
const requireRole = (...allowedRoles) => {
  return (req, res, next) => {
    if (!req.user) {
      return res.status(401).json({ error: "No user authenticated." });
    }

    if (!allowedRoles.includes(req.user.role)) {
      return res.status(403).json({
        error: `Access denied. Required role(s): ${allowedRoles.join(" or ")}. Your role: ${req.user.role}`
      });
    }

    next();
  };
};

module.exports = { authenticateToken, requireRole };
