# SakayTa security check

Run: 2026-11-17T10:28:44.489Z
API: http://localhost:8088/api

**PASS 41 | FAIL 0 | SKIP 0**

## 1. Authentication

| ID | Test | Expected | Got | Result | Note |
|---|---|---|---|---|---|
| A1 | No token: GET /auth/me | 401 | 401 | PASS |  |
| A2 | Garbage token: GET /auth/me | 401 | 401 | PASS |  |
| A3 | Token with a changed signature is refused | 401 | 401 | PASS | Shows the token is signed and cannot be edited. |
| A4 | Valid token: GET /auth/me | 200 | 200 | PASS |  |
| A5 | Profile response holds no password or hash | no 'password'/'hash' text | none | PASS |  |
| A6 | Wrong password / unknown account is refused | 400 or 401 | 401 | PASS |  |
| A7 | Same answer for unknown account and wrong password (no account guessing) | same status and message | 401 / 401 | PASS |  |
| A8 | Register without an email token is refused | 400 or 401 or 403 or 422 | 403 | PASS |  |
| A9 | Wrong one-time code is refused | 400 or 401 or 403 or 404 or 410 or 422 or 429 | 400 | PASS | No email is sent by this test. |
| A10 | Admin role cannot be chosen at signup | 400 or 401 or 403 or 422 | 403 | PASS |  |

## 2. Role-based authorization

| ID | Test | Expected | Got | Result | Note |
|---|---|---|---|---|---|
| R1 | No token: GET /admin/drivers | 401 | 401 | PASS |  |
| R2 | No token: POST /rides (book) | 401 | 401 | PASS |  |
| R3 | No token: GET /drivers/me | 401 | 401 | PASS |  |
| R4 | Commuter: GET /admin/stats is blocked | 403 | 403 | PASS |  |
| R5 | Commuter: GET /admin/drivers is blocked | 403 | 403 | PASS |  |
| R6 | Commuter: PUT /admin/fare is blocked | 403 | 403 | PASS |  |
| R7 | Commuter: PUT /admin/drivers/1/verify is blocked | 403 | 403 | PASS |  |
| R8 | Commuter: GET /drivers/me is blocked | 403 | 403 | PASS |  |
| R9 | Commuter: POST /rides/1/accept is blocked | 403 | 403 | PASS |  |
| R10 | Commuter: GET /drivers/me/stats is blocked | 403 | 403 | PASS |  |
| R11 | Driver: GET /admin/stats is blocked | 403 | 403 | PASS |  |
| R12 | Driver: POST /rides (book) is blocked | 403 | 403 | PASS |  |
| R13 | Driver: POST /rides/1/cancel is blocked | 403 | 403 | PASS |  |
| R14 | Driver: GET /drivers/me/stats works | 200 | 200 | PASS |  |
| R15 | Admin: GET /admin/stats works | 200 | 200 | PASS |  |
| R16 | Admin: POST /rides (book) is blocked | 403 | 403 | PASS |  |
| R17 | Admin: GET /drivers/me is blocked | 403 | 403 | PASS |  |
| R18 | Commuter cannot open a ride that belongs to someone else | 403 or 404 | 404 | PASS |  |

## 3. Server-side input validation

| ID | Test | Expected | Got | Result | Note |
|---|---|---|---|---|---|
| V1 | Login: SQL-injection text is just a wrong login | 400 or 401 | 400 | PASS | Must not be 200 or 500. |
| V2 | Login: broken JSON is refused | 400 or 422 | 400 | PASS |  |
| V3 | Login: very long password is refused without a crash | 400 or 401 or 413 or 422 | 401 | PASS |  |
| V4 | Fare estimate: text instead of numbers is refused | 400 or 422 | 400 | PASS |  |
| V5 | Fare estimate: SQL-injection text is refused | 400 or 422 | 400 | PASS |  |
| V6 | Fare estimate: coordinates outside the world are refused | 400 or 422 | 400 | PASS |  |
| V7 | Booking: empty body is refused | 400 or 422 | 400 | PASS |  |
| V8 | Booking: text coordinates are refused | 400 or 422 | 400 | PASS |  |
| V9 | Booking: unknown ride type is refused | 400 or 409 or 422 | 400 | PASS | Uses impossible coordinates on purpose, so no ride can be created. |

## 4. Leaks and browser rules

| ID | Test | Expected | Got | Result | Note |
|---|---|---|---|---|---|
| L1 | Unknown route: no file paths or stack trace in the answer | 404 and no internal details | 404 | PASS |  |
| L2 | Bad request: no file paths or stack trace in the answer | no internal details | none | PASS |  |
| L3 | CORS does not allow a foreign website | not * and not evil.example | http://localhost:3000 | PASS |  |
| L4 | Header X-Content-Type-Options is set | nosniff | nosniff | PASS | Nice to have, not required. |
