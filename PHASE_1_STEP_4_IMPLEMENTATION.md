# Phase 1 Step 4 Implementation: Driver Accept/Decline with Timeout (FR-12, BR-03)

## Overview
Replaced the automatic ride assignment system with a driver request/response flow. Verified drivers now receive ride requests (instead of immediate assignment), have 15 seconds to accept or decline, and if timeout occurs, the request goes to the next eligible verified driver.

## Changes Made

### 1. Database Schema (db.js)
Added four new fields to the `rides` table to track request lifecycle:

```sql
requestedDriver TEXT,       -- Which driver received this request
requestStatus TEXT,         -- 'pending', 'accepted', 'declined', 'timeout'
requestCreatedAt TEXT,      -- ISO timestamp when request was sent
requestExpiresAt TEXT,      -- ISO timestamp when request expires (15 sec after creation)
```

**Migration**: Uses `CREATE TABLE IF NOT EXISTS` to preserve existing records (d1/d2 drivers and all existing rides).

### 2. Queue Consumer (services/queueConsumer.js)
**Changed dispatch behavior from immediate assignment to pending request creation.**

Key functions:
- `createRideRequest(ride, channel, failedDrivers)`: 
  - Finds next eligible verified driver (excluding previously-failed ones)
  - Verifies driver via SOAP once more before sending request
  - Creates `requestStatus='pending'` with 15-second expiration
  - Does NOT set `driver.busy=1` yet (happens only on acceptance)
  - Logs to audit-log.json

- `checkExpiredRequests()`: 
  - Runs every 2 seconds
  - Finds requests where `requestStatus='pending'` and `requestExpiresAt < now`
  - Clears expired request fields
  - Re-queues ride to RabbitMQ for next driver assignment

**Export**: `{ start, broadcastRideRequest }`

### 3. Accept Endpoint (routes/rides.js POST /api/rides/:id/accept)
**Access Control**: Requires JWT authentication + driver role

**Validations**:
- Ride exists
- Driver has a driver record linked to authenticated user
- This driver is the `requestedDriver`
- Request not expired (`requestExpiresAt >= now`)
- Request still pending
- Ride still pending
- Driver license status is 'verified'
- Driver not already assigned (busy=0)

**Race Condition Safety**: 
- Uses atomic SQL with `WHERE requestStatus='pending' AND status='pending'`
- Double-checks `updatedRide.driverId === driver.id` after update

**On Success**:
- Sets `driverId=driver.id`, `status='assigned'`, `requestStatus='accepted'`
- Sets `driver.busy=1`
- Emits Socket.io `ride:accepted` event
- Returns 200 with updated ride

**Error Cases**:
- 404: Ride not found
- 403: No driver record / not the requested driver / not verified
- 410: Request expired
- 409: Request already answered / ride no longer available / another driver accepted first
- 500: Database error

### 4. Decline Endpoint (routes/rides.js POST /api/rides/:id/decline)
**Access Control**: Requires JWT authentication + driver role

**Validations**:
- Ride exists
- Driver has a driver record linked to authenticated user
- This driver is the `requestedDriver`
- Request still active (status='pending')

**On Success**:
- Clears request fields: `requestedDriver=NULL`, `requestStatus='declined'`
- Emits Socket.io `ride:declined` event
- Re-queues ride via `publishNewRide()` for next driver
- Returns 200 with confirmation

**Error Cases**:
- 404: Ride not found
- 403: No driver record / not the requested driver
- 409: Request already answered

### 5. Driver UI (public/driver.html)
**New Ride Request Card** (displayed when driver receives a pending request):
- Green-themed card with gradient background
- **Countdown timer**: Shows remaining seconds (green → orange at ≤5s → red at 0s)
- **Request details**:
  - Passenger name
  - Pickup coordinates (4 decimal places)
  - Drop-off coordinates (4 decimal places)
  - Fare estimate in ₱
- **Action buttons**:
  - ACCEPT RIDE (green, full-width)
  - DECLINE (red, full-width)
- **Status display**: Shows confirmation messages or errors

**JavaScript Logic**:
- `displayRideRequest(ride)`: Populates request details and shows card
- `startRideRequestTimer()`: Counts down from 15s, updates every 100ms
  - Changes color to orange at ≤5s
  - Changes to red and disables buttons at 0s
- `clearRideRequestTimer()`: Stops timer, resets color
- ACCEPT button: Calls `POST /api/rides/:id/accept` with JWT
- DECLINE button: Calls `POST /api/rides/:id/decline` with JWT
- Socket.io listeners:
  - `ride:request`: Shows request if it's for this driver
  - `ride:accepted`: Logs confirmation
  - `ride:declined`: Logs reassignment
  - `ride:timeout`: Hides request card if it was this request

**Ride ID Tracking**: 
- When driver enters a Ride ID and it has a pending request for them, displays it automatically
- `loadActiveRide()` checks and calls `displayRideRequest()` if applicable

### 6. Server Integration (server.js)
**Socket.io Middleware**: Attached `req.io` to all Express routes so they can emit events

**Modified Routes**:
- Accept endpoint: Emits `ride:accepted` event
- Decline endpoint: Emits `ride:declined` event

### 7. Security & Validation

**Driver Ownership Validation**:
- Drivers can only accept/decline requests sent to them
- Uses `driver.user_id === req.user.id` to verify ownership
- Compares authenticated user ID with driver record's user_id field

**License Status Enforcement**:
- Driver must have `licenseStatus='verified'` (re-verified before accepting)
- Unverified and rejected drivers never receive requests (filtered by queueConsumer)

**Availability Check**:
- Driver must not already be assigned (`busy=0`)
- Ride must still be pending
- Request must not be expired

**Race Condition Prevention**:
- Accept endpoint uses atomic SQL: `WHERE requestStatus='pending' AND status='pending'`
- Double-checks result to detect concurrent accepts
- Returns 409 (Conflict) if another driver accepted first

**XSS Prevention**:
- All passenger names and coordinates escaped with `escapeHtml()`

**SQL Injection Prevention**:
- All queries use parameterized queries with `?` placeholders

### 8. Audit Logging (services/queueConsumer.js)
Events logged to `data/audit-log.json`:
- `ride_request_created`: New request created
- `ride_request_timeout`: Request expired after 15s
- `no_verified_driver_available`: No eligible drivers found
- `driver_verification_failed`: SOAP verification failed
- `soap_error`: SOAP service error

## Request Flow

1. **Commuter books a ride** → POST /api/rides (status='pending')
2. **Ride published to RabbitMQ** → queueConsumer processes queue
3. **Queue consumer finds verified driver** → Creates request (requestStatus='pending', requestExpiresAt=now+15s)
4. **Driver receives request via Socket.io** → Ride request card displays with countdown
5. **Driver accepts OR declines within 15 seconds**:
   - **Accept**: Sets status='assigned', driver.busy=1, emits `ride:accepted`
   - **Decline**: Clears request, re-queues ride for next driver
6. **If timeout (15s expires)** → checkExpiredRequests clears request, re-queues automatically
7. **Next eligible driver receives request** → Repeat from step 4

## Backwards Compatibility

- Existing rides with d1/d2 drivers are preserved
- Existing fields (driverId, status, distanceKm, fareEstimate, etc.) unchanged
- Uses `CREATE TABLE IF NOT EXISTS` so works with existing data
- No migrations required

## Requirements Satisfied

✅ **FR-12 Driver Accept/Decline**: Drivers can accept or decline requests
✅ **BR-03 Request Timeout**: 15-second timeout implemented with auto-reassignment
✅ **FR-09/BR-01 Verified Driver Filtering**: Only verified drivers receive requests, unverified/rejected excluded
✅ **Driver Ownership Validation**: user_id foreign key ensures drivers can only respond to their requests
✅ **Race Condition Safety**: Atomic SQL prevents multiple drivers accepting same ride
✅ **XSS Prevention**: Passenger names escaped in UI
✅ **SQL Injection Prevention**: Parameterized queries throughout
✅ **Audit Logging**: All events logged to separate JSON data store
✅ **Real-time Updates**: Socket.io events notify clients immediately

## Not Implemented (Per User Instructions)

- ❌ Full FR-11 ride lifecycle (FR-11 is a later phase)
- ❌ Phase 1 Step 5 or any later phase

## Testing Checklist

- [ ] Verified driver receives request
- [ ] Unverified driver does not receive request
- [ ] Rejected driver does not receive request
- [ ] Driver can accept own request
- [ ] Driver cannot accept another driver's request
- [ ] Request expires after 15 seconds
- [ ] Expired request cannot be accepted
- [ ] Driver can decline request
- [ ] Declined request reassigns to next driver
- [ ] Commuter cannot accept/decline
- [ ] Another driver cannot hijack a request
- [ ] Accepting sets driver.busy=1
- [ ] Multiple requests don't collide
