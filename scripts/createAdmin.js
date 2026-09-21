// scripts/createAdmin.js
// One-time development bootstrap: creates the first admin user.
//
// PURPOSE
//   SakayTa has no runtime admin bootstrap: public registration correctly blocks
//   role=admin (routes/auth.js), and this script is the intended dev/test path for
//   provisioning the initial admin account. It is deliberately OUTSIDE the runtime:
//     - Never imported by server.js, db.js, or any route or service.
//   - Refuses to run when NODE_ENV=production.
//   - Does not expose or weaken the public registration role restriction.
//
// USAGE (NOT a command-line argument - the password comes from the environment):
//   ADMIN_EMAIL=admin@example.com ADMIN_PASSWORD='a strong password' node scripts/createAdmin.js
//   (or set the same variables in .env - dotenv is loaded like the rest of the app)
//
// SAFETY
//   Idempotent: if any admin already exists, exits successfully with NO database change.
//   Only ever writes ONE new row in `users`. Never touches drivers, rides,
//   notifications, fare_config, admin_action_logs, or existing users.

require("dotenv").config();

// Refuse production runs BEFORE touching the database or bcrypt.
// Note: dotenv is loaded first so a NODE_ENV=production in .env is also honored.
if (process.env.NODE_ENV === "production") {
  console.error("[createAdmin] Refusing to create an admin account in NODE_ENV=production.");
  process.exit(1);
}

const bcrypt = require("bcrypt");
const db = require("../db");

// Same conventions as routes/auth.js
const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const SALT_ROUNDS = 10; // matches auth.js
const MIN_PASSWORD_LENGTH = 6; // matches auth.js

(async () => {
  const email = process.env.ADMIN_EMAIL;
  const password = process.env.ADMIN_PASSWORD;

  // Credentials must come from the environment - never positional arguments.
  if (!email || !password) {
    console.error(
      "[createAdmin] ADMIN_EMAIL and ADMIN_PASSWORD must be set as environment variables."
    );
    process.exit(1);
  }

  // Idempotency: if an admin already exists, make no change and succeed.
  const existingAdmin = db
    .prepare("SELECT id, email FROM users WHERE role = 'admin' LIMIT 1")
    .get();
  if (existingAdmin) {
    console.log(
      `[createAdmin] Admin account already exists (${existingAdmin.email}). No change made.`
    );
    process.exit(0);
  }

  // Validate email (same regex as auth.js registration)
  if (!EMAIL_REGEX.test(email)) {
    console.error("[createAdmin] Invalid email format.");
    process.exit(1);
  }

  // Validate password length (same minimum as auth.js registration)
  if (password.length < MIN_PASSWORD_LENGTH) {
    console.error(`[createAdmin] Password must be at least ${MIN_PASSWORD_LENGTH} characters long.`);
    process.exit(1);
  }

  // Guard against inserting over an existing email (UNIQUE constraint in users)
  const normalizedEmail = email.toLowerCase();
  const emailTaken = db
    .prepare("SELECT id, role FROM users WHERE email = ?")
    .get(normalizedEmail);
  if (emailTaken) {
    console.error(
      `[createAdmin] Email ${normalizedEmail} is already registered (role: ${emailTaken.role}). No change made.`
    );
    process.exit(1);
  }

  // Hash with the exact same bcrypt settings used by auth.js
  const password_hash = await bcrypt.hash(password, SALT_ROUNDS);

  // Same user ID / timestamp conventions as auth.js registration
  const userId = "u_" + Date.now() + "_" + Math.random().toString(36).substr(2, 9);
  const createdAt = new Date().toISOString();

  db.prepare(
    `INSERT INTO users (id, email, full_name, password_hash, role, created_at)
     VALUES (?, ?, ?, ?, 'admin', ?)`
  ).run(userId, normalizedEmail, "Administrator", password_hash, createdAt);

  console.log(`[createAdmin] Admin account created successfully (${normalizedEmail}).`);
  console.log(`[createAdmin] Sign in at /login with this email and the ADMIN_PASSWORD you set.`);
  process.exit(0);
})().catch((err) => {
  console.error("[createAdmin] Failed to create admin account:", err.message);
  process.exit(1);
});