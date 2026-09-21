// server.js
require("dotenv").config();
const express = require("express");
const http = require("http");
const cors = require("cors");
const path = require("path");
const { Server } = require("socket.io");
const jwt = require("jsonwebtoken");

const db = require("./db");
const ridesRouter = require("./routes/rides");
const driversRouter = require("./routes/drivers");
const authRouter = require("./routes/auth");
const notificationsRouter = require("./routes/notifications");
const adminRouter = require("./routes/admin");
const { broadcastRideRequest } = require("./services/queueConsumer");

const app = express();
const server = http.createServer(app);
const io = new Server(server, { cors: { origin: "*" } });

const PORT = process.env.PORT || 3000;

app.use(cors());
app.use(express.json());
app.use(express.static(path.join(__dirname, "public")));

// Pass Socket.io instance to request handlers via middleware
app.use((req, res, next) => {
  req.io = io;
  next();
});

app.use("/api/auth", authRouter);
app.use("/api/rides", ridesRouter);
app.use("/api/drivers", driversRouter);
app.use("/api/notifications", notificationsRouter);
app.use("/api/admin", adminRouter);

// ---------- Real-time location tracking + user-specific notifications (Socket.io) ----------
io.on("connection", (socket) => {
  console.log("Client connected:", socket.id);

  // Authenticate socket and attach user info
  const token = socket.handshake.auth.token;
  if (token) {
    try {
      const decoded = jwt.verify(token, process.env.JWT_SECRET || "sakayta-dev-secret-change-in-production");
      socket.userId = decoded.userId;
      socket.userRole = decoded.role;
      // Join user-specific room for notifications
      socket.join(`user:${decoded.userId}`);
      console.log(`User ${decoded.userId} joined notification room`);
    } catch (err) {
      console.log("Socket authentication failed:", err.message);
    }
  }

  socket.on("joinRide", (rideId) => {
    socket.join(rideId);
  });

  socket.on("driver:location", ({ rideId, lat, lng }) => {
    // FR-10: Persist driver location for nearest-driver matching
    // Validate coordinates before storing
    if (
      typeof lat === "number" && typeof lng === "number" &&
      isFinite(lat) && isFinite(lng) &&
      lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180
    ) {
      if (socket.userId) {
        const driver = db.prepare("SELECT id FROM drivers WHERE user_id = ?").get(socket.userId);
        if (driver) {
          db.prepare("UPDATE drivers SET latitude=?, longitude=?, locationUpdatedAt=? WHERE id=?")
            .run(lat, lng, new Date().toISOString(), driver.id);
        }
      }
    }

    // Existing relay behavior preserved
    io.to(rideId).emit("ride:driverLocation", { lat, lng, timestamp: Date.now() });
  });

  socket.on("disconnect", () => {
    console.log("Client disconnected:", socket.id);
  });
});

server.listen(PORT, () => {
  console.log(`SakayTa website running: http://localhost:${PORT}`);
  console.log(`Remember to also run the queue worker in a separate terminal: npm run worker`);
});
