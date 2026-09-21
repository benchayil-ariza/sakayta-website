// routes/auth.js
const express = require("express");
const bcrypt = require("bcrypt");
const jwt = require("jsonwebtoken");
const db = require("../db");

const { authenticateToken, requireRole } = require("../middleware/auth");

const router = express.Router();

const JWT_SECRET = process.env.JWT_SECRET || "sakayta-dev-secret-change-in-production";
const SALT_ROUNDS = 10;

// Email validation regex
const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// POST /api/auth/register
router.post("/register", async (req, res) => {
  try {
    const { email, full_name, password, confirm_password, role } = req.body;

    // Validate required fields
    if (!email || !full_name || !password || !confirm_password) {
      return res.status(400).json({ error: "All fields are required." });
    }

    // Validate email format
    if (!EMAIL_REGEX.test(email)) {
      return res.status(400).json({ error: "Invalid email format." });
    }

    // Validate password confirmation
    if (password !== confirm_password) {
      return res.status(400).json({ error: "Passwords do not match." });
    }

    // Validate password strength (minimum 6 characters)
    if (password.length < 6) {
      return res.status(400).json({ error: "Password must be at least 6 characters long." });
    }

    // Validate role (only commuter or driver allowed through registration)
    const validRoles = ["commuter", "driver"];
    const userRole = role && validRoles.includes(role.toLowerCase()) ? role.toLowerCase() : "commuter";

    // Admin accounts cannot be created through public registration
    if (role && role.toLowerCase() === "admin") {
      return res.status(403).json({ error: "Admin accounts cannot be registered publicly." });
    }

    // Check if email already exists
    const existing = db.prepare("SELECT id FROM users WHERE email = ?").get(email);
    if (existing) {
      return res.status(409).json({ error: "Email already registered." });
    }

    // Hash password
    const password_hash = await bcrypt.hash(password, SALT_ROUNDS);

    // Generate user ID
    const userId = "u_" + Date.now() + "_" + Math.random().toString(36).substr(2, 9);

    // Insert user into database
    const insert = db.prepare(
      "INSERT INTO users (id, email, full_name, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, ?)"
    );
    insert.run(userId, email.toLowerCase(), full_name, password_hash, userRole, new Date().toISOString());

    // Generate JWT token
    const token = jwt.sign(
      { userId, email: email.toLowerCase(), role: userRole },
      JWT_SECRET,
      { expiresIn: "7d" }
    );

    res.status(201).json({
      message: "Account created successfully.",
      user: {
        id: userId,
        email: email.toLowerCase(),
        full_name,
        role: userRole,
      },
      token,
    });
  } catch (err) {
    console.error("Registration error:", err);
    res.status(500).json({ error: "Registration failed. Please try again." });
  }
});

// POST /api/auth/login
router.post("/login", async (req, res) => {
  try {
    const { email, password } = req.body;

    // Validate required fields
    if (!email || !password) {
      return res.status(400).json({ error: "Email and password are required." });
    }

    // Validate email format
    if (!EMAIL_REGEX.test(email)) {
      return res.status(400).json({ error: "Invalid email format." });
    }

    // Find user by email
    const user = db.prepare("SELECT * FROM users WHERE email = ?").get(email.toLowerCase());
    if (!user) {
      return res.status(401).json({ error: "Invalid email or password." });
    }

    // Verify password
    const isValidPassword = await bcrypt.compare(password, user.password_hash);
    if (!isValidPassword) {
      return res.status(401).json({ error: "Invalid email or password." });
    }

    // Generate JWT token
    const token = jwt.sign(
      { userId: user.id, email: user.email, role: user.role },
      JWT_SECRET,
      { expiresIn: "7d" }
    );

    res.status(200).json({
      message: "Login successful.",
      user: {
        id: user.id,
        email: user.email,
        full_name: user.full_name,
        role: user.role,
      },
      token,
    });
  } catch (err) {
    console.error("Login error:", err);
    res.status(500).json({ error: "Login failed. Please try again." });
  }
});

// GET /api/auth/me - Get current user info
router.get("/me", authenticateToken, (req, res) => {
  // Return user info without password hash
  const { password_hash, ...userWithoutPassword } = req.user;
  res.json(userWithoutPassword);
});

module.exports = router;
