# Phase 1 Step 5 Inspection & Plan
## Full Ride Lifecycle Implementation (FR-11, BR-05)

**Date**: 2026-09-02  
**Status**: INSPECTION & PLANNING ONLY - NO MODIFICATIONS YET  
**Awaiting**: User approval before implementation

---

## 1. CURRENT RIDE LIFECYCLE IMPLEMENTATION

### Current Status Values in Database

**db.js line 49**: `status TEXT DEFAULT 'pending'`

### Current Status Usage in Code

**routes/rides.js**:
- Line 71: New ride created with `status='pending'`
- Line 106: Generic `PUT /api/rides/:id` endpoint allows updating `status=?` (no validation)
- Line 156: Accept endpoint checks `ride.status !== 'pending'` before accepting
- Line 172-174: Accept endpoint sets `status='assigned'`

**services/queueConsumer.js**:
- Line 87: Checks for rides where `status='pending'` before creating requests

### Current Status Values Observed

From code inspection:
- `'pending'` - Initial state after booking
- `'assigned'` - After driver accepts
- Filter pills in rides.html (line 50-53) show: `pending`, `assigned`, `completed`, `cancelled`

### Status Badge Styles (public/css/style.css)

```css
.status-pending { background: #FDF1DC; color: var(--amber-deep); }     /* Amber/yellow */
.status-assigned { background: #E7ECE8; color: var(--sage-700); }     /* Sage green */
.status-completed { background: #E1EEE5; color: #2F6B44; }            /* Dark green */
```

**Missing**: `.status-cancelled` style (needs to be added)

### Gap Analysis: Current vs FR-11 Required States

| Status | Current? | FR-11 Required | Missing |
|--------|----------|----------------|---------|
| Searching | ❌ NO | ✅ YES | Need to add |
| Assigned | ✅ YES | ✅ YES | OK |
| En Route | ❌ NO | ✅ YES | Need to add |
| Completed | ✅ YES (CSS exists) | ✅ YES | Need endpoint |
| Cancelled | ❌ NO | ✅ YES (BR-05) | Need to add |

---

## 2. CURRENT DATABASE RIDE SCHEMA

### Current Fields

```sql
CREATE TABLE rides (
  id TEXT PRIMARY KEY,
  passengerName TEXT NOT NULL,
  rideType TEXT DEFAULT 'Tricycle',
  pickupLat REAL,
  pickupLng REAL,
  dropoffLat REAL,
  dropoffLng REAL,
  driverId TEXT,                    -- Assigned driver (FK)
  status TEXT DEFAULT 'pending',    -- Main lifecycle state
  
  -- Phase 1 Step 4 Request Fields:
  requestedDriver TEXT,             -- Driver receiving request
  requestStatus TEXT,               -- 'pending', 'accepted', 'declined'
  requestCreatedAt TEXT,
  requestExpiresAt TEXT,
  
  distanceKm REAL,
  fareEstimate REAL,
  createdAt TEXT                    -- Booking timestamp
);
```

### Missing Fields for FR-11 Full Lifecycle

**Required for Phase 1 Step 5**:
- `updatedAt TEXT` - Track last status change
- `completedAt TEXT` - When ride finished
- `cancelledAt TEXT` - When ride was cancelled
- `cancelReason TEXT` - Why ride was cancelled (optional, for logging)
- `userId TEXT` - Link ride to commuter (for ownership validation in BR-05)

**Optional but Useful**:
- `startedAt TEXT` - When driver marked "En Route"
- `cancelledBy TEXT` - 'commuter' or 'driver' or 'admin' (for audit)

---

## 3. CURRENT DRIVER BUSY-STATE BEHAVIOR

### How `drivers.busy` Currently Works

**Database Field**: `drivers.busy INTEGER DEFAULT 0`

**Set to 1 (busy)**: 
- routes/rides.js line 178: After driver successfully accepts ride
  ```sql
  UPDATE drivers SET busy=1 WHERE id=?
  ```

**Set to 0 (available)**: 
- ❌ **NEVER SET BACK IN CURRENT CODE**

**Problem**: Driver remains busy=1 after accepting, but there's NO way to mark them available again when ride completes.

### Current Busy Logic Flow

```
Step 4 Accept:
  - Driver accepts → busy=1

Step 4 Decline:
  - Driver declines → busy NOT changed (remains 0)

Step 4 Timeout:
  - Request times out → busy NOT changed (remains 0)

Step 5 Complete (MISSING):
  - Driver marks ride complete → ??? (need to set busy=0)

Step 5 Cancellation (MISSING):
  - Ride cancelled → ??? (if was assigned, need to set busy=0)
```

### Risk: Permanent Busy State

**Scenario**: 
1. Driver A accepts ride R1 → busy=1
2. Ride R1 never completes (app crashes, connection lost, etc.)
3. Driver A remains busy=1 forever
4. Driver A can never accept another ride

**Mitigation Needed for Step 5**: 
- Completion endpoint must set `busy=0`
- Cancellation endpoint must set `busy=0` if ride was assigned

---

## 4. MISSING FR-11 FUNCTIONALITY

### FR-11: Ride Status Lifecycle

**Missing Components**:

1. **Searching → Assigned State Management**
   - Current: `status='pending'` used for both "searching" and "request pending"
   - Needed: Distinguish `status='searching'` from request state
   - Interaction with Step 4: Queue/request system uses `status='pending'` → must not break

2. **En Route State**
   - Missing endpoint: POST /api/rides/:id/start (driver marks ride as en route)
   - Missing database field: `startedAt TEXT`
   - Missing UI: Driver button to start route
   - Missing Socket.io event: `ride:en_route`

3. **Completed State**
   - Missing endpoint: POST /api/rides/:id/complete (driver marks ride completed)
   - Missing database field: `completedAt TEXT`
   - Missing logic: Set driver `busy=0`
   - Missing UI: Driver button/action to complete
   - Missing Socket.io event: `ride:completed`

4. **Cancelled State**
   - Missing endpoint: POST /api/rides/:id/cancel (commuter cancels)
   - Missing database field: `cancelledAt TEXT`
   - Missing validation: BR-05 rules (only Searching/Assigned allowed)
   - Missing logic: Release driver `busy=0` if assigned
   - Missing Socket.io event: `ride:cancelled`

5. **Commuter Ownership of Rides**
   - Current: Rides don't link to `userId`
   - Needed: Store `userId TEXT` in rides table
   - For BR-05: Only ride creator can cancel

---

## 5. MISSING BR-05 FUNCTIONALITY

### BR-05: Commuter Cancellation Rules

**Current Implementation**: ❌ No cancellation logic exists

**Required**:

```
Commuter can cancel ONLY when:
  - status = 'pending' (still searching for driver)
  - status = 'assigned' (driver accepted, not yet en route)

Commuter CANNOT cancel when:
  - status = 'en_route' (ride in progress)
  - status = 'completed' (already finished)
  - status = 'cancelled' (already cancelled)

Cancellation must:
  - Require authenticated commuter (JWT)
  - Verify commuter owns the ride (ride.userId = req.user.id)
  - Check current status allows cancellation
  - If status='assigned': Release driver (set drivers.busy=0)
  - Set ride.status='cancelled', ride.cancelledAt=NOW
  - Emit Socket.io event
  - If in request state: Clear requestedDriver/requestStatus
```

---

## 6. PROPOSED VALID STATUS TRANSITION DIAGRAM

```
┌─────────────┐
│  SEARCHING  │  (ride created, no driver assigned yet)
└──────┬──────┘
       │
       ├──→ [timeout 15s + no driver available]
       │    └──→ Remains SEARCHING (re-queue attempts)
       │
       ├──→ [driver accepts] ──→ ┌──────────┐
       │                        │ ASSIGNED │
       │                        └────┬─────┘
       │                             │
       └──→ [commuter cancels] ──┐   │
            (Searching)          │   │
                                 │   ├──→ [driver cancels/leaves]
                                 │   │    └──→ SEARCHING (re-queue)
                                 │   │
                                 │   ├──→ [driver starts] ──→ ┌──────────┐
                                 │   │                       │EN ROUTE  │
                                 │   │                       └────┬─────┘
                                 │   │                            │
                                 │   └──→ [commuter cancels]      │
                                 │        (Assigned) ─────────┐   │
                                 │                            │   │
                                 │                            │   ├──→ [driver completes]
                                 │                            │   │    └──→ COMPLETED
                                 │                            │   │
                                 │                            └──→ CANCELLED
                                 │
                                 └──→ CANCELLED

INVALID TRANSITIONS (REJECTED):
  - SEARCHING → EN_ROUTE (must be ASSIGNED first)
  - SEARCHING → COMPLETED (must go through full flow)
  - ASSIGNED → EN_ROUTE (must have accepted first - OK)
  - EN_ROUTE → ASSIGNED (can't go backward)
  - EN_ROUTE → CANCELLED (no cancel once en route - per BR-05)
  - COMPLETED → * (final state)
  - CANCELLED → * (final state)
```

---

## 7. PROPOSED API ENDPOINTS / ROUTES

### New Endpoints Required

1. **POST /api/rides/:id/start** (Driver starts route)
   - Auth: JWT + driver role required
   - Validations:
     - Ride exists
     - Driver owns ride (driverId matches authenticated driver)
     - Current status='assigned'
     - Driver license still verified
   - Actions:
     - Set status='en_route'
     - Set startedAt=NOW
     - Emit Socket.io: `ride:en_route`
   - Response: Updated ride

2. **POST /api/rides/:id/complete** (Driver completes ride)
   - Auth: JWT + driver role required
   - Validations:
     - Ride exists
     - Driver owns ride
     - Current status='en_route'
   - Actions:
     - Set status='completed'
     - Set completedAt=NOW
     - Set drivers.busy=0
     - Emit Socket.io: `ride:completed`
   - Response: Updated ride

3. **POST /api/rides/:id/cancel** (Commuter cancels ride)
   - Auth: JWT + commuter role required
   - Validations:
     - Ride exists
     - Commuter owns ride (ride.userId matches authenticated user)
     - Current status in ['pending', 'assigned'] (BR-05)
     - Current status NOT in ['en_route', 'completed', 'cancelled']
   - Actions:
     - If status='assigned': Set drivers.busy=0
     - Clear request state (if any): requestedDriver=NULL, requestStatus=NULL
     - Set status='cancelled'
     - Set cancelledAt=NOW
     - Emit Socket.io: `ride:cancelled`
   - Response: Updated ride

### Existing Endpoints to Preserve

- GET /api/rides (list all)
- GET /api/rides/:id (get single)
- POST /api/rides (create new) - status still='pending'
- PUT /api/rides/:id (generic update) - should be restricted
- DELETE /api/rides/:id (delete)
- POST /api/rides/:id/accept (Step 4)
- POST /api/rides/:id/decline (Step 4)

### Endpoints to Modify

- **PUT /api/rides/:id** - Currently allows arbitrary status updates without validation
  - **Issue**: Anyone can set any status to anything
  - **Safer Option**: Don't use generic PUT for status changes; require specific endpoints
  - **Action**: Keep PUT but restrict what fields can be updated; route status changes through dedicated endpoints

---

## 8. PROPOSED DATABASE CHANGES

### Migration Strategy

**Type**: Safe ALTER TABLE (add columns, don't recreate table)

**New Columns to Add**:

```sql
ALTER TABLE rides ADD COLUMN userId TEXT;           -- Commuter ownership
ALTER TABLE rides ADD COLUMN updatedAt TEXT;        -- Track last change
ALTER TABLE rides ADD COLUMN startedAt TEXT;        -- When en_route started
ALTER TABLE rides ADD COLUMN completedAt TEXT;      -- When ride finished
ALTER TABLE rides ADD COLUMN cancelledAt TEXT;      -- When ride cancelled
ALTER TABLE rides ADD COLUMN cancelReason TEXT;     -- Why cancelled (optional)
ALTER TABLE rides ADD COLUMN cancelledBy TEXT;      -- 'commuter' or 'driver' (optional)
```

**Migration Function** (similar to Step 4):

```javascript
function migratePhase1Step5() {
  const tableInfo = db.prepare("PRAGMA table_info(rides)").all();
  
  const columnsNeeded = [
    { name: 'userId', type: 'TEXT' },
    { name: 'updatedAt', type: 'TEXT' },
    { name: 'startedAt', type: 'TEXT' },
    { name: 'completedAt', type: 'TEXT' },
    { name: 'cancelledAt', type: 'TEXT' },
    { name: 'cancelReason', type: 'TEXT' },
    { name: 'cancelledBy', type: 'TEXT' }
  ];
  
  columnsNeeded.forEach(col => {
    const exists = tableInfo.some(c => c.name === col.name);
    if (!exists) {
      console.log(`Adding ${col.name} to rides table...`);
      db.exec(`ALTER TABLE rides ADD COLUMN ${col.name} ${col.type}`);
    }
  });
}
```

**Data Preservation**:
- ✅ All existing rides preserved
- ✅ New columns NULL for existing rides (backward compatible)
- ✅ No table recreation
- ✅ No data deletion

---

## 9. PROPOSED FRONTEND CHANGES

### book.html

**Current**: Creates rides with `status='pending'`

**Change**:
- No change to booking flow needed
- Rides still created with `status='pending'` → becomes `'searching'` after queued

**Actually**: Need to clarify: Should initial status be `'searching'` or `'pending'`?

**Recommendation**: Keep initial status as `'pending'` (backward compat with Step 4), but in UI/documentation call it "Searching"

### driver.html

**Current**: Shows ride request card, share GPS

**New Elements Needed**:
- "Start Route" button (when status='assigned')
  - Calls POST /api/rides/:id/start
  - Changes button state to "En Route"
- "Complete Ride" button (when status='en_route')
  - Calls POST /api/rides/:id/complete
  - Success shows confirmation

**UI Changes**:
```html
<!-- After ride accepted and showing in active ride section -->
<button id="startRouteBtn" class="green">Start Route</button>
<button id="completeBtn" class="green" style="display:none;">Complete Ride</button>
```

**JavaScript Logic**:
```javascript
// Monitor ride status from API or Socket.io
socket.on("ride:status", (ride) => {
  if (ride.status === 'assigned') {
    document.getElementById("startRouteBtn").style.display = "block";
  } else if (ride.status === 'en_route') {
    document.getElementById("startRouteBtn").style.display = "none";
    document.getElementById("completeBtn").style.display = "block";
  } else if (ride.status === 'completed') {
    // Hide both buttons
  }
});
```

### passenger.html

**Current**: Shows driver location when ride is active

**New Elements Needed**:
- Display current ride status: Searching → Assigned → En Route → Completed
- "Cancel Ride" button (when status in ['pending', 'assigned'])
- Status message changes as ride progresses

**UI Changes**:
```html
<div id="rideStatus" style="margin-top: 12px; font-size: 14px; font-weight: 700;">
  Status: <span id="statusValue">Searching for driver...</span>
</div>
<button id="cancelBtn" class="red">Cancel Ride</button>
```

**JavaScript Logic**:
```javascript
socket.on("ride:status", (ride) => {
  const statusMap = {
    'pending': 'Searching for driver...',
    'assigned': 'Driver on the way',
    'en_route': 'Driver is here - ride in progress',
    'completed': 'Ride completed',
    'cancelled': 'Ride cancelled'
  };
  document.getElementById("statusValue").textContent = statusMap[ride.status] || ride.status;
  
  // Show/hide cancel button
  document.getElementById("cancelBtn").style.display = 
    ['pending', 'assigned'].includes(ride.status) ? 'block' : 'none';
});
```

### rides.html

**Current**: Shows rides with filter pills and status badges

**Changes Needed**:
- Add `.status-cancelled` CSS style
- Status badges already work if status values are correct
- May need to update filter pills to show all 5 states

**CSS to Add**:
```css
.status-cancelled { background: #FFE7E7; color: #D32F2F; }
.status-searching { background: #FDF1DC; color: var(--amber-deep); }
.status-en_route { background: #E0E7FF; color: #3949AB; }
```

---

## 10. PROPOSED SOCKET.IO CHANGES

### New Events to Emit

1. **ride:status** (whenever status changes)
   ```javascript
   req.io.emit("ride:status", { 
     rideId: ride.id, 
     status: ride.status,
     updatedAt: ride.updatedAt,
     driverId: ride.driverId
   });
   ```

2. **ride:started** (when driver marks en_route)
   ```javascript
   req.io.emit("ride:started", { rideId: ride.id, driverId: driver.id });
   ```

3. **ride:completed** (when driver marks complete)
   ```javascript
   req.io.emit("ride:completed", { rideId: ride.id, driverId: driver.id, completedAt: ride.completedAt });
   ```

4. **ride:cancelled** (when ride cancelled)
   ```javascript
   req.io.emit("ride:cancelled", { rideId: ride.id, cancelledBy: 'commuter' | 'driver' });
   ```

### Existing Events to Preserve

- `joinRide` (joining room)
- `driver:location` (driver sharing GPS)
- `ride:driverLocation` (passenger receives location)
- `ride:request` (Step 4)
- `ride:accepted` (Step 4)
- `ride:declined` (Step 4)

---

## 11. SECURITY & OWNERSHIP CHECKS

### Driver Ownership in Start/Complete Endpoints

```javascript
// Start endpoint
const driver = db.prepare("SELECT * FROM drivers WHERE user_id = ?").get(req.user.id);
if (ride.driverId !== driver.id) {
  return res.status(403).json({ error: "This ride is not assigned to you." });
}
```

**Same pattern** for complete endpoint.

### Commuter Ownership in Cancel Endpoint

**Current Problem**: Rides don't have userId field

**Solution**: Add rides.userId when ride is created

**routes/rides.js POST /api/rides** (line 60-75):
```javascript
db.prepare(
  `INSERT INTO rides (..., userId) VALUES (..., ?)`
).run(
  ...,
  req.user.id  // Commuter's user ID
);
```

**Cancel endpoint**:
```javascript
const ride = db.prepare("SELECT * FROM rides WHERE id = ?").get(req.params.id);
if (ride.userId !== req.user.id) {
  return res.status(403).json({ error: "You can only cancel your own rides." });
}
```

### Preserved Security Measures

- ✅ JWT required for all state-change endpoints
- ✅ Role checks (driver vs commuter)
- ✅ Ownership validated server-side (not trusting client ID)
- ✅ Parameterized SQL throughout
- ✅ Status validation before transitions
- ✅ License verification for drivers

---

## 12. INTERACTION WITH STEP 4

### Step 4 Queue/Request System

**Current**:
- Ride created with `status='pending'`
- Queue consumer creates request with `requestStatus='pending'`
- Driver accepts → `status='assigned'`, `requestStatus='accepted'`

**Step 5 Design Must**:
- ✅ NOT break Step 4 request/timeout logic
- ✅ Keep `requestedDriver`, `requestStatus`, `requestCreatedAt`, `requestExpiresAt` fields
- ✅ Accept still works as-is
- ✅ Decline still re-queues as-is
- ✅ Timeout still works as-is

**How Step 5 Starts After Step 4**:
```
Step 4: Ride 'pending' → Accept → 'assigned'
Step 5: Ride 'assigned' → Start → 'en_route' → Complete → 'completed'
        or Ride 'assigned' → Cancel → 'cancelled'
```

**No Conflict**: Step 4 manages request lifecycle, Step 5 manages post-assignment lifecycle.

---

## 13. FILES THAT WOULD NEED MODIFICATION

| File | Type | Change |
|------|------|--------|
| db.js | Backend | Add migration for Phase 1 Step 5 columns |
| routes/rides.js | Backend | Add 3 new endpoints (start, complete, cancel) |
| routes/rides.js | Backend | Modify POST /api/rides to capture userId |
| public/driver.html | Frontend | Add Start Route and Complete buttons + logic |
| public/passenger.html | Frontend | Add Cancel button + status display logic |
| public/rides.html | Frontend | Add .status-cancelled CSS |
| public/css/style.css | Frontend | Add status badge styles |
| server.js | Backend | May need to add Socket.io event handling (optional) |

**No changes needed**:
- routes/drivers.js
- services/queueProducer.js
- services/queueConsumer.js
- services/soapClient.js
- middleware/auth.js
- services/fareService.js
- public/book.html (keep as-is)
- public/rides.html (only CSS, filter pills work as-is)

---

## 14. POTENTIAL RISKS / REGRESSIONS

### Risk 1: Breaking Step 4 Request Lifecycle

**Scenario**: Modifying `status` field breaks request/timeout logic

**Mitigation**:
- Keep `requestStatus` field separate from `status`
- Request handling uses `requestStatus` (Step 4)
- Lifecycle handling uses `status` (Step 5)
- No conflict if designed correctly

**Testing**: Verify accept/decline/timeout still work after Step 5 changes

### Risk 2: Driver Stays Busy Forever

**Scenario**: Driver completes ride but code doesn't set busy=0

**Mitigation**:
- Complete endpoint MUST set `drivers.busy=0`
- Test that driver can accept next ride after completing

**Testing**: Book 2 rides, complete first, accept second

### Risk 3: Commuter Cancels Other's Ride

**Scenario**: rides table missing userId, commuter can cancel any ride

**Mitigation**:
- Add userId field at booking time
- Verify userId match in cancel endpoint

**Testing**: User A books ride, User B tries to cancel (should fail)

### Risk 4: Invalid Status Transitions

**Scenario**: Someone directly calls PUT /api/rides/:id with invalid status

**Mitigation**:
- Restrict generic PUT endpoint
- Route all status changes through dedicated endpoints
- Validate status in each endpoint

**Testing**: Try invalid transitions (COMPLETED→ASSIGNED), should fail

### Risk 5: Race Condition - Accept vs Cancel

**Scenario**:
- Commuter cancels ride (status → cancelled)
- Driver accepts simultaneously (status → assigned)
- Both succeed, ride in unknown state

**Mitigation**:
- Cancel endpoint checks status is in [pending, assigned]
- Accept endpoint checks status is pending
- Only one can succeed

**Testing**: Simulate concurrent accept/cancel

### Risk 6: Lost Data During Migration

**Scenario**: ALTER TABLE fails, existing rides lost

**Mitigation**:
- Use safe idempotent ALTER TABLE (like Step 4)
- Never recreate table
- New columns are NULL for existing rides (OK)

**Testing**: Verify existing rides still exist after migration

---

## 15. TESTING PLAN

### Tests That Can Be Executed in Current Environment

#### ACTUAL TESTS (Code Inspection + Manual Execution if Environment Permits)

**A. Database Migration Test**
```javascript
// Can execute if we can run node
// Check PRAGMA table_info before/after migration
const before = db.prepare("PRAGMA table_info(rides)").all();
console.log(before.map(c => c.name)); // Should show old columns
// Run migration
migratePhase1Step5();
const after = db.prepare("PRAGMA table_info(rides)").all();
console.log(after.map(c => c.name)); // Should include userId, updatedAt, etc.
// Verify no data loss
const rideCount = db.prepare("SELECT COUNT(*) as c FROM rides").get().c;
console.log(rideCount); // Should equal count before migration
```
**Status**: EXECUTABLE (if Bash permission granted)

**B. Start Endpoint Security Test (CODE INSPECTION)**
```javascript
// Verify: Only assigned driver can start
// Verify: Wrong driver gets 403
// Verify: Non-driver gets 403
// Verify: Ride must be status='assigned'
```
**Status**: CODE INSPECTION ONLY

**C. Complete Endpoint Security Test (CODE INSPECTION)**
```javascript
// Verify: Only assigned driver can complete
// Verify: Ride must be status='en_route'
// Verify: busy=0 set after complete
```
**Status**: CODE INSPECTION ONLY

**D. Cancel Endpoint Security Test (CODE INSPECTION)**
```javascript
// Verify: Only commuter owner can cancel
// Verify: Can cancel in 'pending' state
// Verify: Can cancel in 'assigned' state
// Verify: Cannot cancel in 'en_route' state
// Verify: Cannot cancel in 'completed' state
// Verify: If assigned, driver.busy=0 released
```
**Status**: CODE INSPECTION ONLY

**E. Status Transition Validation (CODE INSPECTION)**
```javascript
// Verify: pending → assigned (accept)
// Verify: assigned → en_route (start)
// Verify: en_route → completed (complete)
// Verify: pending → cancelled (cancel)
// Verify: assigned → cancelled (cancel)
// Verify: INVALID: en_route → cancelled (rejected)
// Verify: INVALID: completed → anything (rejected)
```
**Status**: CODE INSPECTION ONLY

### Tests Requiring RabbitMQ / Live Database / Browser

#### ACTUAL INTEGRATION TESTS (Require Full Environment)

**F. End-to-End: Book → Accept → Start → Complete**
- Requires: RabbitMQ, live database, driver app
- Steps:
  1. Commuter books ride
  2. Queue consumer finds driver
  3. Driver accepts
  4. Driver starts route
  5. Driver completes
  6. Verify: rider.status='completed', driver.busy=0
**Status**: CANNOT EXECUTE (no RabbitMQ in current environment)

**G. End-to-End: Commuter Cancels Before Assignment**
- Requires: RabbitMQ, live database, driver app
- Steps:
  1. Commuter books ride (status='pending')
  2. Commuter cancels before driver accepts
  3. Verify: rider.status='cancelled', request cleared
**Status**: CANNOT EXECUTE (no RabbitMQ)

**H. End-to-End: Commuter Cancels After Assignment**
- Requires: RabbitMQ, live database, driver app
- Steps:
  1. Commuter books ride
  2. Driver accepts
  3. Commuter cancels
  4. Verify: rider.status='cancelled', driver.busy=0
**Status**: CANNOT EXECUTE (no RabbitMQ)

**I. UI Test: Status Badges Display Correctly**
- Requires: Browser, live server
- Verify: Rides show correct colored badges for each status
**Status**: CANNOT EXECUTE (no browser automation)

**J. Socket.io Test: Status Updates Broadcast Correctly**
- Requires: Live server, Socket.io client
- Verify: ride:status event fires when status changes
**Status**: CANNOT EXECUTE (requires live server + Socket.io)

---

## 16. IMPLEMENTATION ORDER (If Approved)

### Phase 1: Database & Backend API

1. **db.js**: Add migration function for Phase 1 Step 5 columns
2. **routes/rides.js POST /api/rides**: Capture userId from authenticated user
3. **routes/rides.js POST /api/rides/:id/start**: Implement start endpoint
4. **routes/rides.js POST /api/rides/:id/complete**: Implement complete endpoint
5. **routes/rides.js POST /api/rides/:id/cancel**: Implement cancel endpoint
6. **Audit logging**: Add entries for start/complete/cancel events

### Phase 2: Frontend UI

7. **public/driver.html**: Add Start Route and Complete buttons
8. **public/passenger.html**: Add Cancel button and status display
9. **public/rides.html**: Add .status-cancelled CSS

### Phase 3: Real-Time Updates

10. **server.js**: Add Socket.io event emission from endpoints
11. **driver.html**: Listen for ride:status updates
12. **passenger.html**: Listen for ride:status updates

### Phase 4: Testing

13. Verify Step 4 still works (accept/decline/timeout)
14. Test each new endpoint with valid/invalid inputs
15. Test security (ownership, role, licensing)
16. Test invalid transitions are rejected
17. Test data preservation

---

## SUMMARY

### Current State

- ✅ Step 1-4 complete and working
- ✅ Rides have `status='pending'` (used for both "searching" and "request pending")
- ✅ Step 4: Accept sets `status='assigned'` and driver `busy=1`
- ❌ No "Searching" / "En Route" / "Completed" state management
- ❌ No Cancellation logic (FR-11, BR-05)
- ❌ Driver remains busy forever after accepting
- ❌ Rides don't track commuter ownership (userId missing)

### What Step 5 Adds

1. **Full Lifecycle**: pending → en_route → completed (+ cancel option)
2. **Driver Availability**: Set busy=0 when ride completes or is cancelled
3. **Commuter Cancellation**: BR-05 rules enforced
4. **Database Migration**: Safe addition of 7 new fields
5. **UI Updates**: Buttons for start/complete/cancel
6. **Socket.io Events**: Real-time status broadcast

### No Breaking Changes

- ✅ Step 4 request/timeout logic preserved
- ✅ Existing data preserved
- ✅ Existing endpoints still work
- ✅ Authentication/RBAC unchanged
- ✅ SOAP verification unchanged
- ✅ Fare calculation unchanged

---

## RECOMMENDATION

**Phase 1 Step 5 is feasible** with the proposed design:

1. Database migration follows approved safe pattern
2. New endpoints follow existing security patterns
3. No conflicts with Step 4 request/timeout system
4. All required FR-11 and BR-05 features covered
5. UI changes are additive (no breaking changes to existing pages)

**Ready for user approval to proceed with implementation.**

