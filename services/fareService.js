// services/fareService.js
// This is your CONSUMED THIRD-PARTY API requirement.
// Uses OpenRouteService (free tier) to get real driving distance.
// If you haven't set up an API key yet, it automatically falls back to a
// straight-line distance estimate so the app still works while you're testing.

const axios = require("axios");
const db = require("../db");

function getFareConfig() {
  try {
    const row = db.prepare("SELECT baseFare, ratePerKm FROM fare_config ORDER BY updatedAt DESC LIMIT 1").get();
    if (row) {
      return { baseFare: row.baseFare, ratePerKm: row.ratePerKm };
    }
  } catch (err) {
    // If there's an error (e.g., table doesn't exist), we'll fall back to defaults
    console.warn("Could not read fare config, using defaults:", err.message);
  }
  return { baseFare: 15, ratePerKm: 8 }; // Default fallback
}

function haversineKm(lat1, lon1, lat2, lon2) {
  const R = 6371;
  const dLat = ((lat2 - lat1) * Math.PI) / 180;
  const dLon = ((lon2 - lon1) * Math.PI) / 180;
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

async function estimateFare(pickupLat, pickupLng, dropoffLat, dropoffLng) {
  const { baseFare, ratePerKm } = getFareConfig();
  const apiKey = process.env.ORS_API_KEY;

  if (apiKey) {
    try {
      const response = await axios.get(
        "https://api.openrouteservice.org/v2/directions/driving-car",
        {
          params: {
            api_key: apiKey,
            start: `${pickupLng},${pickupLat}`,
            end: `${dropoffLng},${dropoffLat}`,
          },
        }
      );
      const distanceMeters = response.data.features[0].properties.segments[0].distance;
      const distanceKm = distanceMeters / 1000;
      return {
        distanceKm,
        fareEstimate: Math.round(baseFare + distanceKm * ratePerKm),
        source: "OpenRouteService (real distance)",
      };
    } catch (err) {
      console.warn("OpenRouteService call failed, using straight-line fallback:", err.message);
    }
  }

  const distanceKm = haversineKm(pickupLat, pickupLng, dropoffLat, dropoffLng);
  return {
    distanceKm,
    fareEstimate: Math.round(baseFare + distanceKm * ratePerKm),
    source: "straight-line estimate (set ORS_API_KEY in .env for real road distance)",
  };
}

module.exports = { estimateFare, haversineKm };
