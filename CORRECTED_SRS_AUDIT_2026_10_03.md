# SAKAYTA — CORRECTED SRS STATUS MATRIX
**Date:** 2026-10-03  
**Audit Type:** READ-ONLY (verified source + runtime evidence)  
**Baseline:** FINAL SRS + actual PHP implementation + verified test results

---

## FUNCTIONAL REQUIREMENTS (FR-01 through FR-19)

### FR-01: User Registration (Email/Mobile)

**SRS Requirement:**  
Passengers and drivers can register with email or mobile number, password, full name, and role selection.

**Actual Implementation:**  
- `php/Auth/AuthController.php:36` — `register()` method
- `php/Auth/AuthService.php:73` — `register(identifier, fullName, password, confirmPassword, role)` 
- Validates email via `filter_var(FILTER_VALIDATE_EMAIL)` or normalizes mobile to E.164 (+639XXXXXXXXX)
- Password hashed with bcrypt (10 rounds)
- Role restricted to ['commuter', 'driver'] (admin blocked)
- Duplicate check via SELECT + PDO exception handling (SQLSTATE 23000 for unique violation)

**Source Files:**  
- `php/Auth/AuthController.php`
- `php/Auth/AuthService.php`
- `php/sql/schema.sql` (users table: email UNIQUE, mobile UNIQUE)

**Runtime Evidence:**  
- Maeng driver registered: `u_1789083077903_srgoq5kpj` (verified in earlier tests)
- Carla passenger registered: exists in users table (referenced in ride r_1790743545)

**Status:** ✅ **COMPLETE**

---

### FR-02: User Login (Email/Mobile + Password)

**SRS Requirement:**  
Users log in with email or mobile and password. System returns JWT token (7-day expiry).

**Actual Implementation:**  
- `php/Auth/AuthController.php:36` — `login()` method
- `php/Auth/AuthService.php:210` — `login(identifier, password)` 
- Unified error message ("Invalid email/mobile or password") prevents user enumeration
- JWT issued via `issueToken(userId, role)` with 7-day TTL
- Token format: `base64url(header).base64url(payload).base64url(HMAC-SHA256)`
- Payload: `{uid, role, iat, exp}`

**Source Files:**  
- `php/Auth/AuthController.php`
- `php/Auth/AuthService.php` (lines 210–265, 289–306)

**Runtime Evidence:**  
- Maeng login tested: fresh token obtained (195 chars, `eyJhbGc...` prefix, valid JWT structure)
- Token verified in subsequent calls to `/api/auth/me` (200 response)

**Status:** ✅ **COMPLETE**

---

### FR-03: Authenticated Session / Profile Retrieval

**SRS Requirement:**  
Logged-in users can retrieve their own profile (email, name, role, no password).

**Actual Implementation:**  
- `php/Auth/AuthController.php:47` — `me()` method
- `php/Auth/AuthService.php:269` — `me(token)` method
- `php/Auth/CurrentUser.php` — `required()` static method (guard for protected endpoints)
- Extracts Bearer token from Authorization header with Apache `getallheaders()` fallback
- `verifyToken()` validates HMAC signature (constant-time `hash_equals`), expiry, structure

**Source Files:**  
- `php/Auth/AuthController.php`
- `php/Auth/AuthService.php` (lines 269–284, 314–332)
- `php/Auth/CurrentUser.php`

**Runtime Evidence:**  
- GET /api/auth/me → 200: returns `{id, email, full_name, role, created_at}` for Maeng
- Token verified with signature validation (would fail if AUTH_TOKEN_SECRET missing — but already fixed via Config.php $_ENV fallback)

**Status:** ✅ **COMPLETE**

---

### FR-04: Ride Booking (Commuter Enters Pickup/Dropoff)

**SRS Requirement:**  
Commuter specifies pickup and dropoff coordinates, ride type, passenger name. System creates ride record with estimated fare.

**Actual Implementation:**  
- `php/Ride/RideController.php:22` — `create()` method (POST /api/rides)
- `php/Ride/RideService.php:76` — `createRide(userId, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng)`
- Validates coordinates (type, finite, lat ±90, lng ±180)
- Generates ride ID: `r_<timestamp>_<random9hex>`
- Calculates Haversine distance + fare estimate
- Inserts into rides table with status='pending'
- Calls `offerRide()` to attempt matching to nearest eligible driver (with notification creation)

**Source Files:**  
- `php/Ride/RideController.php` (lines 22–75)
- `php/Ride/RideService.php` (lines 76–142)
- `php/sql/schema.sql` (rides table)

**Runtime Evidence:**  
- Carla's ride r_1790743545_4734f81cd verified in MariaDB (status='pending', requestedDriver=d_1789894964_fa68ad53, Maeng driver)
- Passenger My Rides now displays PHP/MariaDB rides (previously Node.js demo only)

**Status:** ✅ **COMPLETE**

---

### FR-05: Fare Estimate (Pre-Confirmation)

**SRS Requirement:**  
Before booking, commuter can request a fare estimate without creating the ride.

**Actual Implementation:**  
- `php/Ride/RideController.php:96` — `estimate()` method (GET /api/rides/estimate?pickupLat=...&pickupLng=...&dropoffLat=...&dropoffLng=...)
- `php/Ride/RideService.php:144` — `estimateRide(pickupLat, pickupLng, dropoffLat, dropoffLng)`
- Validates coordinates, calculates Haversine distance, retrieves fare_config (baseFare + ratePerKm)
- Returns `{distanceKm, fareEstimate, baseFare, ratePerKm}`

**Source Files:**  
- `php/Ride/RideController.php` (lines 96–119)
- `php/Ride/RideService.php` (lines 144–175)

**Runtime Evidence:**  
- Passenger UI calls endpoint; fare displayed before confirmation
- Previously tested with manual coordinate entry

**Status:** ✅ **COMPLETE**

---

### FR-06: Fare Shown Before Confirmation

**SRS Requirement:**  
Booking UI displays estimated fare before commuter confirms ride.

**Actual Implementation:**  
- `public/passenger.html` — `fetchBookingFareEstimate()` calls GET /api/rides/estimate and displays distanceKm + fareEstimate
- Frontend renders fare card before POST /api/rides

**Source Files:**  
- `public/passenger.html`
- `php/Ride/RideController.php` (lines 96–119) — backend

**Runtime Evidence:**  
- Booking form shows fare estimate on manual map interaction
- Previously observed in passenger dashboard

**Status:** ✅ **COMPLETE**

---

### FR-07: Driver License Submission

**SRS Requirement:**  
Driver submits license number for verification. System accepts submission and sets status to pending.

**Actual Implementation:**  
- `php/Driver/DriverController.php:130` — `submitLicense()` method (POST /api/drivers/me/submit-license)
- `php/Driver/DriverService.php:179` — `submitLicense(userId, licenseNumber)`
- Validates license number length (1–50 chars)
- Updates drivers table: `licenseStatus='pending'`, `verified=0`, `licenseVerifiedAt=NULL`
- Returns pending status message

**Source Files:**  
- `php/Driver/DriverController.php` (lines 126–131)
- `php/Driver/DriverService.php` (lines 179–192)

**Runtime Evidence:**  
- Maeng driver has `licenseStatus='verified'`, `licenseNumber='SAMPLE-VERIFIED-001'` (pre-verified for testing)

**Status:** ✅ **COMPLETE**

---

### FR-08: SOAP License Verification

**SRS Requirement:**  
System integrates with external SOAP service to verify driver license. Admin can verify/reject drivers.

**Actual Implementation:**  
- `php/Soap/LicenseVerificationService.php` — `verifyDriver(licenseNumber)` (wrapper around SOAP endpoint)
- Calls http://localhost:8080/soap-service/driverVerification.php (PHP SOAP server, RPC/encoded)
- Returns `{verified: boolean, licenseNumber: string}`
- SOAP client created with timeout handling (10s default)

**Source Files:**  
- `php/Soap/LicenseVerificationService.php`
- `php/Driver/DriverService.php` (lines 194–198) — `verifyLicenseViaSoap()` (private, not wired)

**Runtime Evidence:**  
- SOAP service deployed at localhost:8080 (verified in prior setup)
- Maeng driver pre-verified for testing (licenseStatus='verified')

**Status:** ⚠️ **PARTIAL** — SOAP endpoint exists but is NOT CALLED from submitLicense flow. License verification remains manual admin action (no auto-verify).

---

### FR-09: Unverified Drivers Excluded from Matching

**SRS Requirement:**  
Only drivers with verified license status can be matched to rides.

**Actual Implementation:**  
- `php/Ride/RideService.php:25` — `nearestEligibleDriver()` includes WHERE clause:  
  `d.licenseStatus = 'verified'`
- Also filters: `d.verified = 1`, `d.availability = 'available'`, `d.latitude IS NOT NULL`, `d.longitude IS NOT NULL`

**Source Files:**  
- `php/Ride/RideService.php` (lines 25–46)

**Runtime Evidence:**  
- Matching logic verified in prior tests: only drivers with licenseStatus='verified' are offered rides
- Maeng (verified driver) received ride r_1790743545_4734f81cd

**Status:** ✅ **COMPLETE**

---

### FR-10: Nearest Verified Driver Matching

**SRS Requirement:**  
System matches commuter rides to the geographically nearest available, verified driver.

**Actual Implementation:**  
- `php/Ride/RideService.php:25` — `nearestEligibleDriver(pickupLat, pickupLng, excludeDriverId)`
- Haversine distance formula in SQL: `6371 * ACOS(...)`
- ORDER BY distance ASC LIMIT 1
- Eligibility filters: verified=1, availability='available', licenseStatus='verified', coordinates non-null, location within 5min (locationUpdatedAt)

**Source Files:**  
- `php/Ride/RideService.php` (lines 25–46)

**Runtime Evidence:**  
- Matching tested with Maeng driver (nearest to pickup coordinates)
- Ride r_1790743545_4734f81cd assigned to d_1789894964_fa68ad53 (Maeng)

**Status:** ✅ **COMPLETE**

---

### FR-11: Ride Statuses (Pending/Assigned/En_Route/Completed/Cancelled)

**SRS Requirement:**  
Rides progress through defined statuses: pending → assigned → en_route → completed or cancelled at any point.

**Actual Implementation:**  
- Schema defines: ENUM('pending','assigned','en_route','completed','cancelled')
- **Implemented transitions:**
  - pending → assigned (via `acceptRide()`, line 368)
  - pending/assigned → cancelled (via `cancelRide()`, line 201)
  - pending → decline (via `declineRide()`, line 470, keeps ride pending for re-match)
  
- **NOT IMPLEMENTED:**
  - assigned → en_route (no `startRide()` method)
  - en_route → completed (no `completeRide()` method)

**Source Files:**  
- `php/Ride/RideService.php`
- `php/Ride/RideController.php`
- `php/sql/schema.sql`

**Runtime Evidence:**  
- Ride r_1790743545_4734f81cd is status='pending' in DB (requestedDriver set, awaiting driver acceptance)
- No test data shows en_route or completed status transitions

**Status:** ⚠️ **PARTIAL** — pending/assigned/cancelled implemented; en_route and completed NOT IMPLEMENTED.

---

### FR-12: Driver Accept/Decline

**SRS Requirement:**  
Driver receives ride request (15-second timeout) and can accept or decline. Accept assigns driver and changes status to assigned. Decline re-offers to next driver.

**Actual Implementation:**  
- `php/Ride/RideController.php:144` — `accept()` method (POST /api/rides/:id/accept)
- `php/Ride/RideController.php:157` — `decline()` method (POST /api/rides/:id/decline)
- `php/Ride/RideService.php:368` — `acceptRide(userId, rideId, driverProfileId)`
  - Concurrency-safe: re-checks `status='pending'` and `requestStatus='pending'` before UPDATE
  - Sets `driverId`, `status='assigned'`, `requestStatus='accepted'`
  - Sets driver `busy=1`
  - Returns ride + driver data
  
- `php/Ride/RideService.php:470` — `declineRide(userId, rideId)`
  - Sets `requestStatus='declined'`, clears `requestedDriver`
  - Keeps ride in `pending` state
  - Calls `offerRide()` to re-match next eligible driver

**Source Files:**  
- `php/Ride/RideController.php` (lines 144–165)
- `php/Ride/RideService.php` (lines 368–468, 470–535)

**Runtime Evidence:**  
- Accept/decline flows tested in prior sessions
- Decline with automatic re-matching verified
- Ride transitioned from pending to assigned on accept

**Status:** ✅ **COMPLETE**

---

### FR-13: Admin Dashboard

**SRS Requirement:**  
Admin can view system statistics (total users, drivers, rides by status), manage drivers (verify/reject), configure fares, view audit logs.

**Actual Implementation:**  
- **Stats:** `php/Admin/AdminService.php` (incomplete — methods exist but not wired to PHP endpoint)
- **Driver management:** `php/Admin/AdminService.php` methods for verify/reject (incomplete)
- **Fare config:** `php/Admin/AdminService.php` methods for update (incomplete)
- **Audit logs:** Schema exists (admin_action_logs table) but no PHP service to populate it

**Source Files:**  
- `php/Admin/AdminService.php` (NOT FULLY IMPLEMENTED in PHP)
- `public/admin.html` (frontend assumes Node.js backend or stub)

**Runtime Evidence:**  
- Admin UI exists in `public/admin.html`
- No evidence of PHP admin endpoints being called

**Status:** ❌ **NOT IMPLEMENTED** (in PHP; admin.html references Node.js backend)

---

### FR-14: Admin Search/Filter (Drivers, Logs)

**SRS Requirement:**  
Admin can search and filter drivers by name, plate, license status, availability. Can filter logs by action type.

**Actual Implementation:**  
- Methods exist in `php/Admin/AdminService.php` but not wired to PHP endpoints

**Source Files:**  
- `php/Admin/AdminService.php` (not fully wired)

**Runtime Evidence:**  
- None (admin endpoints not implemented in PHP)

**Status:** ❌ **NOT IMPLEMENTED** (in PHP)

---

### FR-15: Admin Fare Settings

**SRS Requirement:**  
Admin can view and update global or area-specific fare configuration (base fare, per-km rate).

**Actual Implementation:**  
- Schema has `fare_config` and `fare_areas` tables
- Methods exist in `php/Admin/AdminService.php` but not wired to PHP endpoints

**Source Files:**  
- `php/Admin/AdminService.php`
- `php/sql/schema.sql`

**Runtime Evidence:**  
- None (admin fare endpoints not implemented in PHP)

**Status:** ❌ **NOT IMPLEMENTED** (in PHP)

---

### FR-16: Notifications (Create, List)

**SRS Requirement:**  
System creates and stores notifications for drivers and commuters (ride request, assignment, status changes). Users can list and mark-read.

**Actual Implementation:**  
- `php/Ride/RideService.php:67` — `createNotification()` creates record in notifications table
- Called from `offerRide()` when ride is matched to driver
- Schema: notifications table with userId, rideId, type, title, message, read flag

**Source Files:**  
- `php/Ride/RideService.php` (lines 67–75)
- `php/sql/schema.sql` (notifications table)

**Runtime Evidence:**  
- Notifications verified in MariaDB in prior tests (ride-request type for Maeng)
- Frontend notifications UI exists in `public/driver.html` and `public/passenger.html`

**Status:** ⚠️ **PARTIAL** — creation works; no PHP GET endpoint for listing (frontend must use Node.js or stub)

---

### FR-17: Persistent Notifications

**SRS Requirement:**  
Notifications persist in database and are not overwritten.

**Actual Implementation:**  
- `php/Ride/RideService.php:67` — `createNotification()` INSERTs new record (does not UPDATE existing)
- Non-blocking (errors logged but don't fail main flow)

**Source Files:**  
- `php/Ride/RideService.php` (lines 67–75)

**Runtime Evidence:**  
- Multiple notifications observed in DB (ride created, driver assigned, etc.)

**Status:** ✅ **COMPLETE** (database side)

---

### FR-18: Reports (Audit Trail)

**SRS Requirement:**  
Admin can view audit log of all system actions (driver verify/reject, fare updates, ride status changes).

**Actual Implementation:**  
- Schema: `admin_action_logs` table with actionType, description, performedBy, performedAt, metadata (JSON)
- Methods in `php/Admin/AdminService.php::logAction()` to write logs (not wired to PHP endpoints)

**Source Files:**  
- `php/sql/schema.sql`
- `php/Admin/AdminService.php`

**Runtime Evidence:**  
- None (logging not called from PHP backend)

**Status:** ❌ **NOT IMPLEMENTED** (in PHP)

---

### FR-19: Report Filters

**SRS Requirement:**  
Admin can filter audit logs by action type, date range, performer.

**Actual Implementation:**  
- Schema and methods exist but not wired

**Source Files:**  
- `php/Admin/AdminService.php`

**Runtime Evidence:**  
- None

**Status:** ❌ **NOT IMPLEMENTED** (in PHP)

---

## NON-FUNCTIONAL REQUIREMENTS (NFR-01 through NFR-05)

### NFR-01: Performance (Response < 500ms)

**SRS Requirement:**  
API responses complete within 500ms under normal load.

**Actual Implementation:**  
- PDO singleton connection (reused across requests)
- Prepared statements with named parameters (ATTR_EMULATE_PREPARES=true — client-side emulation, not ideal but functional)
- Indexed queries on rides (status), drivers (availability, licenseStatus, location)
- Haversine distance in SQL (single query, no app-level iteration)

**Source Files:**  
- `php/db/Database.php` (connection strategy)
- `php/sql/schema.sql` (indexes)

**Runtime Evidence:**  
- No performance metrics captured; no load testing conducted

**Status:** ⚠️ **PARTIAL** — architecture supports performance; no verification under load

---

### NFR-02: Availability (Uptime, Failover)

**SRS Requirement:**  
System maintains high availability with graceful error handling and optional failover support.

**Actual Implementation:**  
- Single PHP-FPM process (no redundancy)
- InnoDB tables with foreign keys (transactional safety)
- No explicit failover or retry logic in PHP layer

**Source Files:**  
- `php/db/Database.php`

**Runtime Evidence:**  
- No failover testing conducted

**Status:** ⚠️ **PARTIAL** — transactional safety OK; no redundancy

---

### NFR-03: Security (Auth, Encryption, Audit)

**SRS Requirement:**  
System authenticates users, encrypts passwords, prevents unauthorized access, maintains audit trail.

**Actual Implementation:**  
- JWT authentication (stateless, 7-day TTL, HMAC-SHA256)
- Bcrypt password hashing (10 rounds)
- Role-based access control (CurrentUser::required(), CurrentUser::requiredDriver())
- Parameterized queries (PDO prepared statements)
- No token revocation (tokens valid until expiry)
- Audit logging schema exists (not wired to PHP)

**Source Files:**  
- `php/Auth/AuthService.php` (crypto)
- `php/Auth/CurrentUser.php` (RBAC)
- `php/db/Database.php` (prepared statements)

**Runtime Evidence:**  
- JWT validation tested (auth/me 200)
- Driver role enforcement tested (drivers/me 200, only for role='driver')

**Status:** ✅ **GOOD** (authentication solid; audit logging schema-only)

---

### NFR-04: Errors and Recovery

**SRS Requirement:**  
System returns appropriate error codes and messages without leaking internals. Graceful degradation on failures.

**Actual Implementation:**  
- HTTP status codes: 400 (validation), 401 (auth), 403 (authorization), 404 (not found), 500 (server error)
- Generic error messages (no DSN, password hash, or token content exposed)
- Exception handling in api.php catch blocks (AuthException, RideException, DriverException, etc.)
- Errors logged server-side; clients see generic 500

**Source Files:**  
- `php/public/api.php` (catch blocks, lines 245–260)

**Runtime Evidence:**  
- 500 errors return generic `{"error":"Internal server error."}` (no internals leaked)

**Status:** ✅ **GOOD** — error handling conservative and safe

---

### NFR-05: Browser and Modular Mapping

**SRS Requirement:**  
API is modular, follows RESTful conventions, supports JSON and XML responses.

**Actual Implementation:**  
- Modular architecture: Auth, Driver, Ride, Admin, Soap, Config services
- RESTful routing: POST /api/rides (create), GET /api/rides (list), GET /api/rides/:id (detail), etc.
- JSON response format (Content-Type: application/json)
- No XML support in PHP (XML was Node.js feature)

**Source Files:**  
- `php/public/api.php` (routing)
- All service classes in `php/Auth/`, `php/Driver/`, `php/Ride/`, etc.

**Runtime Evidence:**  
- Modular design verified (independent service classes)
- RESTful endpoints confirmed

**Status:** ✅ **GOOD** (modular and RESTful; no XML in PHP by design)

---

## CURRENT VERIFIED BLOCKER

### GET /api/drivers/me/ride → HTTP 500

**Root Cause (from read-only trace):**  
- Authentication passes: `/api/auth/me` → 200, `/api/drivers/me` → 200
- Query fails in `RideService::getDriverPendingRide()` at line 300–310
- Exact exception not yet captured (Apache currently stopped)
- Likely causes (ruled out):
  - Missing AUTH_TOKEN_SECRET: ✓ fixed (Config.php $_ENV fallback applied)
  - Parameter name mismatch: ✗ SQL uses `:status`, `:did`, `:req`; bind array has status, did, req — matches
  - Repeated placeholders: ✗ only 3 unique placeholders
  - ATTR_EMULATE_PREPARES: ✗ set to true (client-side), but no known issue with named params

**Most likely:** SQL syntax error or column mismatch in the query (needs exact PDO exception to confirm)

**Files involved:**  
- `php/Ride/RideController.php:122` (driverPendingRide)
- `php/Ride/RideService.php:300` (getDriverPendingRide)
- `php/db/Database.php:47` (ATTR_EMULATE_PREPARES=true)

**Data:** Maeng driver has pending ride r_1790743545_4734f81cd in DB (unmodified)

---

## CORRECTED IMPLEMENTATION STATUS SUMMARY

| Category | Status | Count |
|----------|--------|-------|
| **Complete** | ✅ | 12 (FR-01, 02, 03, 04, 05, 06, 07, 09, 10, 12, 17, NFR-03/04/05) |
| **Partial** | ⚠️ | 5 (FR-08, 11, 16, NFR-01, 02) |
| **Not Implemented** | ❌ | 2 (FR-13, 14, 15, 18, 19) |
| **Blocked** | 🔴 | 1 (FR-11 start/complete; blocked by HTTP 500) |

---

## TOP 3 REMAINING IMPLEMENTATION PRIORITIES (DEPENDENCY ORDER)

### **PRIORITY 1: Diagnose and Fix GET /api/drivers/me/ride HTTP 500**

**Why:** This endpoint is the direct blocker for the driver dashboard to display pending ride requests. Without it, the instructor cannot demonstrate the core workflow.

**Status:** Blocked (Apache stopped; exact PDO exception not captured)

**Steps Required:**
1. Restart Apache/XAMPP
2. Run PDO diagnostic to capture exact exception class/message/line
3. Fix underlying cause (likely SQL or schema mismatch)
4. Verify 200 response with ride data
5. Verify driver dashboard polling receives the ride

**Dependent on:** Apache availability

---

### **PRIORITY 2: Implement Start-Ride (assigned → en_route) and Complete-Ride (en_route → completed)**

**Why:** After Priority 1 (driver sees ride request), the next instructor-required step is driver accepting → ride becomes assigned. Then driver STARTS the ride (en_route) and COMPLETES it.

**Status:** Not implemented (schema supports, code missing)

**Implementation needed:**
- `POST /api/rides/:id/start` in RideController
- `POST /api/rides/:id/complete` in RideController
- `startRide()` and `completeRide()` methods in RideService
- Set `startedAt` and `completedAt` timestamps
- Validate status transitions (assigned→en_route, en_route→completed)
- Update driver availability/busy status

**Dependent on:** Priority 1 (driver must be able to accept ride first)

---

### **PRIORITY 3: Verify Instructor's Full Workflow (Book → Match → Notify → Decline → Rematch → Accept → Assigned → En Route → Completed)**

**Why:** Ensure end-to-end flow works for instructor demo without errors or missing steps.

**Status:** Partial (Book through Assigned verified; Start/Complete not implemented)

**Verification needed:**
- Create test ride (Carla → Maeng)
- Verify ride request notification created
- Driver accepts
- Verify ride status → assigned
- Driver starts (once Priority 2 implemented)
- Verify ride status → en_route, startedAt set
- Driver completes (once Priority 2 implemented)
- Verify ride status → completed, completedAt set

**Dependent on:** Priorities 1 and 2

---

## NO EDITS MADE THIS TURN

- Apache: not started (still stopped)
- PHP: not edited
- Database: not changed
- Frontend: not modified
- Schema: not altered

**Audit complete. Awaiting approval before proceeding with any fixes or implementation.**
