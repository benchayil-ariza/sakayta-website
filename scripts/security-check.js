#!/usr/bin/env node
/**
 * SakayTa security check (evidence for the "Security Implementation" deliverable).
 *
 * What it does: calls your LOCAL API and checks login, roles, input validation and
 * a few "does not leak" rules. It prints a table and writes security-evidence.md.
 *
 * It does NOT send any email, does NOT create a ride, and does NOT change any data.
 * It never prints passwords or tokens.
 *
 * Run (PowerShell, from the project folder; use TEST accounts only):
 *   $env:COMMUTER_ID="commuter@test.com"; $env:COMMUTER_PW="..."
 *   $env:DRIVER_ID="driver@test.com";     $env:DRIVER_PW="..."
 *   $env:ADMIN_ID="admin@test.com";       $env:ADMIN_PW="..."
 *   node scripts/security-check.js
 *
 * Optional: $env:API="http://localhost:8088/api"
 * Needs Node 18 or newer.
 */
const fs = require("fs");
const API = (process.env.API || "http://localhost:8088/api").replace(/\/$/, "");
const ACCOUNTS = {
  commuter: [process.env.COMMUTER_ID, process.env.COMMUTER_PW],
  driver:   [process.env.DRIVER_ID,   process.env.DRIVER_PW],
  admin:    [process.env.ADMIN_ID,    process.env.ADMIN_PW],
};
const results = [];
let section = "";

async function call(method, path, { token, body, headers, raw } = {}) {
  const h = Object.assign({}, headers || {});
  if (token) h["Authorization"] = "Bearer " + token;
  let payload;
  if (raw !== undefined) { payload = raw; h["Content-Type"] = "application/json"; }
  else if (body !== undefined) { payload = JSON.stringify(body); h["Content-Type"] = "application/json"; }
  try {
    const res = await fetch(API + path, { method, headers: h, body: payload });
    const text = await res.text();
    let json = null; try { json = JSON.parse(text); } catch (_) {}
    return { status: res.status, text, json, headers: res.headers };
  } catch (e) {
    return { status: 0, text: String(e.message || e), json: null, headers: new Headers() };
  }
}
function add(id, name, expected, actual, ok, note) {
  results.push({ section, id, name, expected, actual: String(actual), result: ok ? "PASS" : "FAIL", note: note || "" });
}
function expectStatus(id, name, res, accepted, note) {
  const ok = accepted.includes(res.status);
  add(id, name, accepted.join(" or "), res.status, ok, note);
  return ok;
}
function skip(id, name, why) { results.push({ section, id, name, expected: "-", actual: "-", result: "SKIP", note: why }); }
async function login(role) {
  const [id, pw] = ACCOUNTS[role];
  if (!id || !pw) return null;
  const r = await call("POST", "/auth/login", { body: { identifier: id, password: pw } });
  return r.json && r.json.token ? r.json.token : null;
}

(async () => {
  console.log("SakayTa security check against " + API + "\n");
  const ping = await call("GET", "/rides/estimate?pickupLat=0&pickupLng=0&dropoffLat=0&dropoffLng=0");
  if (ping.status === 0) { console.error("Cannot reach the API. Start Apache in XAMPP first. (" + ping.text + ")"); process.exit(1); }

  const tok = {};
  for (const r of ["commuter", "driver", "admin"]) tok[r] = await login(r);

  // ---------- 1. Authentication ----------
  section = "1. Authentication";
  expectStatus("A1", "No token: GET /auth/me", await call("GET", "/auth/me"), [401]);
  expectStatus("A2", "Garbage token: GET /auth/me", await call("GET", "/auth/me", { token: "abc.def.ghi" }), [401]);
  if (tok.commuter) {
    const t = tok.commuter;
    const bad = t.slice(0, -2) + (t.endsWith("AA") ? "BB" : "AA");
    expectStatus("A3", "Token with a changed signature is refused", await call("GET", "/auth/me", { token: bad }), [401], "Shows the token is signed and cannot be edited.");
    const me = await call("GET", "/auth/me", { token: t });
    expectStatus("A4", "Valid token: GET /auth/me", me, [200]);
    const leak = /password|hash/i.test(me.text);
    add("A5", "Profile response holds no password or hash", "no 'password'/'hash' text", leak ? "found" : "none", !leak);
  } else skip("A3-A5", "Token tests", "Set COMMUTER_ID and COMMUTER_PW (login failed or missing).");
  const w1 = await call("POST", "/auth/login", { body: { identifier: "nobody-xyz@example.com", password: "WrongPass123" } });
  const w2 = ACCOUNTS.commuter[0] ? await call("POST", "/auth/login", { body: { identifier: ACCOUNTS.commuter[0], password: "WrongPass123!x" } }) : null;
  expectStatus("A6", "Wrong password / unknown account is refused", w1, [400, 401]);
  if (w2) {
    const same = w1.status === w2.status && (w1.json && w2.json ? w1.json.error === w2.json.error : true);
    add("A7", "Same answer for unknown account and wrong password (no account guessing)", "same status and message", `${w1.status} / ${w2.status}`, same);
  }
  expectStatus("A8", "Register without an email token is refused", await call("POST", "/auth/register", { body: { identifier: "x-test-nomail@example.com", full_name: "Test", password: "LongEnough123", confirm_password: "LongEnough123", role: "commuter" } }), [400, 401, 403, 422]);
  expectStatus("A9", "Wrong one-time code is refused", await call("POST", "/auth/otp/verify", { body: { email: "no-such-user@example.com", code: "000000" } }), [400, 401, 403, 404, 410, 422, 429], "No email is sent by this test.");
  expectStatus("A10", "Admin role cannot be chosen at signup", await call("POST", "/auth/register", { body: { identifier: "x-test-admin@example.com", full_name: "Test", password: "LongEnough123", confirm_password: "LongEnough123", role: "admin" } }), [400, 401, 403, 422]);

  // ---------- 2. Role-based authorization ----------
  section = "2. Role-based authorization";
  const NOTOK = [401];
  expectStatus("R1", "No token: GET /admin/drivers", await call("GET", "/admin/drivers"), NOTOK);
  expectStatus("R2", "No token: POST /rides (book)", await call("POST", "/rides", { body: {} }), NOTOK);
  expectStatus("R3", "No token: GET /drivers/me", await call("GET", "/drivers/me"), NOTOK);
  if (tok.commuter) {
    const t = tok.commuter;
    expectStatus("R4", "Commuter: GET /admin/stats is blocked", await call("GET", "/admin/stats", { token: t }), [403]);
    expectStatus("R5", "Commuter: GET /admin/drivers is blocked", await call("GET", "/admin/drivers", { token: t }), [403]);
    expectStatus("R6", "Commuter: PUT /admin/fare is blocked", await call("PUT", "/admin/fare", { token: t, body: {} }), [403]);
    expectStatus("R7", "Commuter: PUT /admin/drivers/1/verify is blocked", await call("PUT", "/admin/drivers/1/verify", { token: t, body: {} }), [403]);
    expectStatus("R8", "Commuter: GET /drivers/me is blocked", await call("GET", "/drivers/me", { token: t }), [403]);
    expectStatus("R9", "Commuter: POST /rides/1/accept is blocked", await call("POST", "/rides/1/accept", { token: t, body: {} }), [403]);
    expectStatus("R10", "Commuter: GET /drivers/me/stats is blocked", await call("GET", "/drivers/me/stats", { token: t }), [403]);
  } else skip("R4-R10", "Commuter role tests", "Commuter login missing.");
  if (tok.driver) {
    const t = tok.driver;
    expectStatus("R11", "Driver: GET /admin/stats is blocked", await call("GET", "/admin/stats", { token: t }), [403]);
    expectStatus("R12", "Driver: POST /rides (book) is blocked", await call("POST", "/rides", { token: t, body: {} }), [403]);
    expectStatus("R13", "Driver: POST /rides/1/cancel is blocked", await call("POST", "/rides/1/cancel", { token: t, body: {} }), [403]);
    expectStatus("R14", "Driver: GET /drivers/me/stats works", await call("GET", "/drivers/me/stats", { token: t }), [200]);
  } else skip("R11-R14", "Driver role tests", "Driver login missing.");
  if (tok.admin) {
    const t = tok.admin;
    expectStatus("R15", "Admin: GET /admin/stats works", await call("GET", "/admin/stats", { token: t }), [200]);
    expectStatus("R16", "Admin: POST /rides (book) is blocked", await call("POST", "/rides", { token: t, body: {} }), [403]);
    expectStatus("R17", "Admin: GET /drivers/me is blocked", await call("GET", "/drivers/me", { token: t }), [403]);
  } else skip("R15-R17", "Admin role tests", "Admin login missing.");
  // ownership: a commuter must not open a ride that is not theirs
  if (tok.commuter && tok.driver) {
    const mine = await call("GET", "/rides", { token: tok.commuter });
    const theirs = await call("GET", "/drivers/me/rides", { token: tok.driver });
    const ids = (r) => { const a = Array.isArray(r.json) ? r.json : (r.json && (r.json.rides || r.json.data)) || []; return a.map(x => String(x.id)); };
    const myIds = ids(mine), driverIds = ids(theirs).filter(i => !myIds.includes(i));
    if (driverIds.length) expectStatus("R18", "Commuter cannot open a ride that belongs to someone else", await call("GET", "/rides/" + encodeURIComponent(driverIds[0]), { token: tok.commuter }), [403, 404]);
    else skip("R18", "Ownership check", "The driver has no ride that the commuter does not own. Make one with another commuter account.");
  }

  // ---------- 3. Server-side validation ----------
  section = "3. Server-side input validation";
  const sqli = "' OR '1'='1";
  expectStatus("V1", "Login: SQL-injection text is just a wrong login", await call("POST", "/auth/login", { body: { identifier: sqli, password: sqli } }), [400, 401], "Must not be 200 or 500.");
  expectStatus("V2", "Login: broken JSON is refused", await call("POST", "/auth/login", { raw: "{not json" }), [400, 422]);
  expectStatus("V3", "Login: very long password is refused without a crash", await call("POST", "/auth/login", { body: { identifier: "a@b.com", password: "x".repeat(20000) } }), [400, 401, 413, 422]);
  expectStatus("V4", "Fare estimate: text instead of numbers is refused", await call("GET", "/rides/estimate?pickupLat=abc&pickupLng=1&dropoffLat=2&dropoffLng=3"), [400, 422]);
  expectStatus("V5", "Fare estimate: SQL-injection text is refused", await call("GET", "/rides/estimate?pickupLat=" + encodeURIComponent(sqli) + "&pickupLng=1&dropoffLat=2&dropoffLng=3"), [400, 422]);
  expectStatus("V6", "Fare estimate: coordinates outside the world are refused", await call("GET", "/rides/estimate?pickupLat=999&pickupLng=999&dropoffLat=-999&dropoffLng=-999"), [400, 422]);
  if (tok.commuter) {
    const t = tok.commuter;
    expectStatus("V7", "Booking: empty body is refused", await call("POST", "/rides", { token: t, body: {} }), [400, 422]);
    expectStatus("V8", "Booking: text coordinates are refused", await call("POST", "/rides", { token: t, body: { pickupLat: "abc", pickupLng: "x", dropoffLat: "y", dropoffLng: "z" } }), [400, 422]);
    expectStatus("V9", "Booking: unknown ride type is refused", await call("POST", "/rides", { token: t, body: { pickupLat: 999, pickupLng: 999, dropoffLat: 999, dropoffLng: 999, rideType: "Habal-Habal" } }), [400, 409, 422], "Uses impossible coordinates on purpose, so no ride can be created.");
  } else skip("V7-V9", "Booking validation", "Commuter login missing.");

  // ---------- 4. Information leaks and browser rules ----------
  section = "4. Leaks and browser rules";
  const nf = await call("GET", "/this-route-does-not-exist");
  const stack = /stack trace|xampp|\.php on line|fatal error|PDOException/i.test(nf.text);
  add("L1", "Unknown route: no file paths or stack trace in the answer", "404 and no internal details", `${nf.status}${stack ? " (leak)" : ""}`, nf.status === 404 && !stack);
  const bad = await call("POST", "/auth/login", { raw: "{not json" });
  const stack2 = /stack trace|xampp|\.php on line|fatal error|PDOException/i.test(bad.text);
  add("L2", "Bad request: no file paths or stack trace in the answer", "no internal details", stack2 ? "leak" : "none", !stack2);
  const cors = await call("GET", "/rides/estimate?pickupLat=1&pickupLng=1&dropoffLat=2&dropoffLng=2", { headers: { Origin: "http://evil.example" } });
  const acao = cors.headers.get("access-control-allow-origin");
  add("L3", "CORS does not allow a foreign website", "not * and not evil.example", acao || "(header missing)", !(acao === "*" || (acao || "").includes("evil.example")));
  const sniff = nf.headers.get("x-content-type-options");
  add("L4", "Header X-Content-Type-Options is set", "nosniff", sniff || "(missing)", sniff === "nosniff", "Nice to have, not required.");

  // ---------- print + save ----------
  const pass = results.filter(r => r.result === "PASS").length, fail = results.filter(r => r.result === "FAIL").length, sk = results.filter(r => r.result === "SKIP").length;
  let last = "";
  for (const r of results) {
    if (r.section !== last) { console.log("\n" + r.section); last = r.section; }
    console.log(`  ${r.result.padEnd(4)} ${r.id.padEnd(6)} ${r.name}  [expected ${r.expected}, got ${r.actual}]`);
  }
  console.log(`\nPASS ${pass}   FAIL ${fail}   SKIP ${sk}`);
  let md = `# SakayTa security check\n\nRun: ${new Date().toISOString()}\nAPI: ${API}\n\n**PASS ${pass} | FAIL ${fail} | SKIP ${sk}**\n`;
  last = "";
  for (const r of results) {
    if (r.section !== last) { md += `\n## ${r.section}\n\n| ID | Test | Expected | Got | Result | Note |\n|---|---|---|---|---|---|\n`; last = r.section; }
    md += `| ${r.id} | ${r.name} | ${r.expected} | ${r.actual} | ${r.result} | ${r.note} |\n`;
  }
  fs.writeFileSync("security-evidence.md", md);
  console.log("Saved security-evidence.md (no passwords or tokens inside).");
  if (fail) console.log("\nFAIL does not always mean a bug: read the line, then check the code or the expected status.");
})();