# SakayTa security check

Run: 2026-11-16T21:29:51.373Z
API: http://localhost:8088/api

**PASS 17 | FAIL 2 | SKIP 5**

## 1. Authentication

| ID | Test | Expected | Got | Result | Note |
|---|---|---|---|---|---|
| A1 | No token: GET /auth/me | 401 | 401 | PASS |  |
| A2 | Garbage token: GET /auth/me | 401 | 401 | PASS |  |
| A3-A5 | Token tests | - | - | SKIP | Set COMMUTER_ID and COMMUTER_PW (login failed or missing). |
| A6 | Wrong password / unknown account is refused | 400 or 401 | 401 | PASS |  |
| A8 | Register without an email token is refused | 400 or 401 or 403 or 422 | 201 | FAIL |  |
| A9 | Wrong one-time code is refused | 400 or 401 or 403 or 404 or 410 or 422 or 429 | 400 | PASS | No email is sent by this test. |
| A10 | Admin role cannot be chosen at signup | 400 or 401 or 403 or 422 | 403 | PASS |  |

## 2. Role-based authorization

| ID | Test | Expected | Got | Result | Note |
|---|---|---|---|---|---|
| R1 | No token: GET /admin/drivers | 401 | 401 | PASS |  |
| R2 | No token: POST /rides (book) | 401 | 401 | PASS |  |
| R3 | No token: GET /drivers/me | 401 | 401 | PASS |  |
| R4-R10 | Commuter role tests | - | - | SKIP | Commuter login missing. |
| R11-R14 | Driver role tests | - | - | SKIP | Driver login missing. |
| R15-R17 | Admin role tests | - | - | SKIP | Admin login missing. |

## 3. Server-side input validation

| ID | Test | Expected | Got | Result | Note |
|---|---|---|---|---|---|
| V1 | Login: SQL-injection text is just a wrong login | 400 or 401 | 400 | PASS | Must not be 200 or 500. |
| V2 | Login: broken JSON is refused | 400 or 422 | 400 | PASS |  |
| V3 | Login: very long password is refused without a crash | 400 or 401 or 413 or 422 | 401 | PASS |  |
| V4 | Fare estimate: text instead of numbers is refused | 400 or 422 | 400 | PASS |  |
| V5 | Fare estimate: SQL-injection text is refused | 400 or 422 | 400 | PASS |  |
| V6 | Fare estimate: coordinates outside the world are refused | 400 or 422 | 400 | PASS |  |
| V7-V9 | Booking validation | - | - | SKIP | Commuter login missing. |

## 4. Leaks and browser rules

| ID | Test | Expected | Got | Result | Note |
|---|---|---|---|---|---|
| L1 | Unknown route: no file paths or stack trace in the answer | 404 and no internal details | 404 | PASS |  |
| L2 | Bad request: no file paths or stack trace in the answer | no internal details | none | PASS |  |
| L3 | CORS does not allow a foreign website | not * and not evil.example | http://localhost:3000 | PASS |  |
| L4 | Header X-Content-Type-Options is set | nosniff | (missing) | FAIL | Nice to have, not required. |
