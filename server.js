// server.js
// Node.js has two small jobs in SakayTa:
//   1) serve the web pages in public/
//   2) carry the live driver position (Socket.io) from the driver to the commuter.
// It has NO database. All data lives in MariaDB behind the PHP API.
//
// Security rules in this file:
//   - A socket can only join a ride after the PHP API confirms the user belongs to that ride.
//   - Only the driver can send a location, and only into a ride room the driver joined.
//   - The login token is checked with JWT_SECRET (the same value as AUTH_TOKEN_SECRET in php/config/.env).
//   - There is no fallback secret in the code. The server refuses to start without JWT_SECRET.

require("dotenv").config();
const express = require("express");
const http = require("http");
const path = require("path");
const { Server } = require("socket.io");
const jwt = require("jsonwebtoken");

const PORT = Number(process.env.PORT) || 3000;
const JWT_SECRET = process.env.JWT_SECRET;
const PHP_API_URL = (process.env.PHP_API_URL || "http://localhost:8088/api").replace(/\/$/, "");
const SITE_ORIGIN = process.env.SITE_ORIGIN || `http://localhost:${PORT}`;

if (!JWT_SECRET || JWT_SECRET.length < 16) {
  console.error("JWT_SECRET is missing or shorter than 16 characters.");
  console.error("Put the same value as AUTH_TOKEN_SECRET (php/config/.env) into JWT_SECRET in the .env file of the project root.");
  process.exit(1);
}

const app = express();
const server = http.createServer(app);
const io = new Server(server, { cors: { origin: SITE_ORIGIN } });

// ---------- web pages ----------
app.disable("x-powered-by");
app.use((req, res, next) => {
  res.setHeader("X-Content-Type-Options", "nosniff");
  res.setHeader("X-Frame-Options", "SAMEORIGIN");
  res.setHeader("Referrer-Policy", "strict-origin-when-cross-origin");
  next();
});
app.use(express.static(path.join(__dirname, "public")));
// The API is NOT on this server. Make a wrong call obvious instead of silent.
app.use("/api", (req, res) => {
  res.status(404).json({ error: `The API is at ${PHP_API_URL}` });
});

// ---------- live tracking ----------
function readUser(token) {
  try {
    const p = jwt.verify(token, JWT_SECRET, { algorithms: ["HS256"] });
    const id = p.uid ?? p.userId ?? p.sub;
    if (!id || !p.role) return null;
    return { id: String(id), role: String(p.role) };
  } catch (_) {
    return null;
  }
}

// Ask the PHP API if this user may see the ride (commuter or driver of that ride).
async function userOwnsRide(token, rideId) {
  try {
    const res = await fetch(`${PHP_API_URL}/rides/${encodeURIComponent(rideId)}`, {
      headers: { Authorization: `Bearer ${token}` },
      signal: AbortSignal.timeout(3000),
    });
    return res.ok;
  } catch (_) {
    return false;
  }
}

const RIDE_ID = /^[A-Za-z0-9_-]{1,64}$/;
const room = (rideId) => `ride:${rideId}`;

io.on("connection", (socket) => {
  const token = socket.handshake.auth && socket.handshake.auth.token;
  socket.data.token = token;
  socket.data.user = token ? readUser(token) : null;
  socket.data.rides = new Set();
  socket.data.lastSent = 0;

  socket.on("joinRide", async (rideId, ack) => {
    const reply = typeof ack === "function" ? ack : () => {};
    if (!socket.data.user) return reply({ ok: false, error: "login required" });
    if (typeof rideId !== "string" || !RIDE_ID.test(rideId)) return reply({ ok: false, error: "bad ride id" });
    if (!(await userOwnsRide(socket.data.token, rideId))) return reply({ ok: false, error: "not your ride" });
    socket.join(room(rideId));
    socket.data.rides.add(rideId);
    reply({ ok: true });
  });

  socket.on("driver:location", (msg) => {
    const u = socket.data.user;
    if (!u || u.role !== "driver" || !msg) return;
    const { rideId, lat, lng } = msg;
    if (typeof rideId !== "string" || !socket.data.rides.has(rideId)) return;
    if (typeof lat !== "number" || typeof lng !== "number" || !isFinite(lat) || !isFinite(lng)) return;
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return;
    const now = Date.now();
    if (now - socket.data.lastSent < 800) return; // at most about 1 update each second
    socket.data.lastSent = now;
    io.to(room(rideId)).emit("ride:driverLocation", { lat, lng, timestamp: now });
  });
});

server.listen(PORT, () => {
  console.log(`SakayTa pages and live tracking: http://localhost:${PORT}`);
  console.log(`PHP API expected at: ${PHP_API_URL}`);
});