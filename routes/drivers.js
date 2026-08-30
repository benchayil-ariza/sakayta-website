// routes/drivers.js
const express = require("express");
const router = express.Router();
const db = require("../db");

router.get("/", (req, res) => {
  res.json(db.prepare("SELECT * FROM drivers").all());
});

router.get("/:id", (req, res) => {
  const driver = db.prepare("SELECT * FROM drivers WHERE id = ?").get(req.params.id);
  if (!driver) return res.status(404).json({ error: "Driver not found" });
  res.json(driver);
});

router.post("/", (req, res) => {
  const id = "d" + Date.now();
  const { name, plateNumber } = req.body;
  db.prepare("INSERT INTO drivers (id, name, plateNumber, verified, busy) VALUES (?,?,?,0,0)").run(
    id,
    name,
    plateNumber
  );
  res.status(201).json({ id, name, plateNumber, verified: 0, busy: 0 });
});

router.put("/:id", (req, res) => {
  const driver = db.prepare("SELECT * FROM drivers WHERE id = ?").get(req.params.id);
  if (!driver) return res.status(404).json({ error: "Driver not found" });
  const updated = { ...driver, ...req.body };
  db.prepare("UPDATE drivers SET name=?, plateNumber=?, verified=?, busy=? WHERE id=?").run(
    updated.name,
    updated.plateNumber,
    updated.verified ? 1 : 0,
    updated.busy ? 1 : 0,
    req.params.id
  );
  res.json(db.prepare("SELECT * FROM drivers WHERE id = ?").get(req.params.id));
});

router.delete("/:id", (req, res) => {
  db.prepare("DELETE FROM drivers WHERE id = ?").run(req.params.id);
  res.status(204).end();
});

module.exports = router;
