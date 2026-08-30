// server.js
require("dotenv").config();
const express = require("express");
const http = require("http");
const cors = require("cors");
const path = require("path");
const { Server } = require("socket.io");

const ridesRouter = require("./routes/rides");
const driversRouter = require("./routes/drivers");

const app = express();
const server = http.createServer(app);
const io = new Server(server, { cors: { origin: "*" } });

const PORT = process.env.PORT || 3000;

app.use(cors());
app.use(express.json());
app.use(express.static(path.join(__dirname, "public")));

app.use("/api/rides", ridesRouter);
app.use("/api/drivers", driversRouter);

// ---------- Real-time location tracking (Socket.io) - same as your original prototype ----------
io.on("connection", (socket) => {
  console.log("Client connected:", socket.id);

  socket.on("joinRide", (rideId) => {
    socket.join(rideId);
  });

  socket.on("driver:location", ({ rideId, lat, lng }) => {
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
