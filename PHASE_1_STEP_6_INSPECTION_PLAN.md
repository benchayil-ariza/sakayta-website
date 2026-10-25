# Phase 1 Step 6 - Notification System Inspection Report
## FR-16 Ride Notifications & FR-17 Notification Records

**Date**: 2026-09-03  
**Status**: INSPECTION ONLY - NO MODIFICATIONS YET  
**Inspection Focus**: Notification system requirements for FR-16 and FR-17

---

## EXECUTIVE SUMMARY

After comprehensive inspection of the SakayTa codebase:

- ❌ **NO notification system currently exists**
- ❌ **NO notifications table in database**
- ❌ **NO notification API endpoints**
- ✅ **Socket.io infrastructure exists** (can be leveraged)
- ✅ **JWT authentication in place** (can protect notifications)
- ✅ **Database migration pattern established** (can add notifications table)
- ✅ **Step 4 & Step 5 are fully preserved** (no conflicts)

---

## 1. CURRENT SYSTEM INSPECTION

### 1.1 Database Schema (db.js)

**Current Tables**:
- `users` (id, email, full_name, password_hash, role, created_at)
- `drivers` (id, user_id, name, plateNumber, licenseNumber, licenseStatus, licenseVerifiedAt, verified, busy)
- `rides` (id, passengerName, rideType, pickupLat/Lng, dropoffLat/Lng, driverId, status, requestedDriver, requestStatus, requestCreatedAt, requestExpiresAt, distanceKm, fareEstimate, createdAt, userId, updatedAt, startedAt, completedAt, cancelledAt)

**Observation**:
- ✅ Users table exists (good for linking notifications)
- ✅ Drivers table linked to users via user_id
- ✅ Rides table has userId field (commuter ownership)
- ❌ NO notifications table exists

### 1.2 Socket.io Implementation (server.js)

**Current Setup**:
- ✅ Socket.io initialized with CORS enabled
- ✅ Rooms: `joinRide` creates room for each rideId
- ✅ Events emitted:
  - `ride:request` (driver receives request)
  - `ride:accepted` (ride accepted)
  - `ride:declined` (ride declined)
  - `ride:en_route` (driver marks en_route)
  - `ride:completed` (driver marks completed)
  - `ride:cancelled` (ride cancelled)
  - `driver:location` (location updates)
  - `ride:driverLocation` (broadcast to passenger)

**Limitation**:
- Current Socket.io is broadcast-only (emits to all connected clients)
- No user-specific targeting (cannot send notification to specific authenticated user)
- No persistence (events lost if client disconnected)

### 1.3 Routes Inspection

**routes/rides.js**:
- ✅ POST /api/rides (create ride) - broadcasts to RabbitMQ
- ✅ POST /api/rides/:id/accept (driver accepts)
- ✅ POST /api/rides/:id/decline (driver declines)
- ✅ POST /api/rides/:id/start (driver marks en_route)
- ✅ POST /api/rides/:id/complete (driver marks completed)
- ✅ POST /api/rides/:id/cancel (commuter cancels)
- ❌ NO notification endpoints exist
- ❌ NO notification retrieval endpoints

**routes/drivers.js**:
- ✅ Driver license submission
- ❌ NO notification endpoints

**routes/auth.js**:
- ✅ Login/signup
- ❌ NO notification-related endpoints

### 1.4 Frontend Inspection

**public/driver.html**:
- ✅ Socket.io listeners for ride events
- ❌ NO notification display
- ❌ NO notification UI components

**public/passenger.html**:
- ✅ Socket.io listeners for driver location
- ❌ NO notification display
- ❌ NO notification UI components

**public/rides.html**:
- ✅ Displays ride status
- ❌ NO notification display

### 1.5 Authentication & Authorization

**Current State**:
- ✅ JWT authentication (middleware/auth.js)
- ✅ Role-based access control (commuter, driver, admin)
- ✅ User identity available in req.user
- ✅ Can identify which user/driver is authenticated

**For Notifications**:
- ✅ Can use JWT to identify notification owner
- ✅ Can verify user can only access their own notifications

---

## 2. FR-16 STATUS - RIDE NOTIFICATIONS

### Current Implementation: ❌ MISSING

**What Should Happen** (per SRS):

#### Commuter Notifications:

1. **Searching/Pending State**
   - [ ] "Your ride is searching for a driver"
   - Status: MISSING

2. **Driver Assigned**
   - [ ] "Driver [Name] has accepted your ride"
   - [ ] Driver details (name, vehicle, license plate if available)
   - Status: MISSING

3. **En Route**
   - [ ] "Your driver is on the way"
   - Status: MISSING

4. **Completed**
   - [ ] "Ride completed"
   - [ ] Fare confirmation
   - Status: MISSING

5. **Cancelled by Commuter**
   - [ ] Cancellation confirmation
   - Status: MISSING

6. **Cancelled by Driver / Timeout**
   - [ ] "Driver unavailable, searching for new driver"
   - [ ] "No drivers available, will retry"
   - Status: MISSING

#### Driver Notifications:

1. **New Ride Request**
   - [ ] "New ride request"
   - [ ] Passenger name, pickup/dropoff, fare
   - [ ] 15-second countdown
   - Status: PARTIALLY IMPLEMENTED (Socket.io event only, no persistence)

2. **Commuter Cancellation**
   - [ ] "Ride cancelled by passenger"
   - [ ] Driver becomes available
   - Status: MISSING (only Socket.io event, no notification)

3. **Ride Status Changes**
   - [ ] Related status updates
   - Status: MISSING

### Current Delivery Method: ❌ Socket.io only (not persistent)

**Problem**:
- If driver/commuter disconnects, they don't see events
- No notification history
- No unread status tracking
- No way to retrieve missed notifications

---

## 3. FR-17 STATUS - NOTIFICATION RECORDS

### Current Implementation: ❌ MISSING

**What Should Be Stored**:
- Notification for each user (commuter/driver)
- Persistent database record
- Read/unread status
- Timestamp
- Related ride information

### Missing Components:

#### Database Table
- ❌ NO notifications table
- ❌ NO notification schema

#### API Endpoints
- ❌ GET /api/notifications (list user's notifications)
- ❌ GET /api/notifications/:id (get single notification)
- ❌ PUT /api/notifications/:id/read (mark as read)
- ❌ DELETE /api/notifications/:id (delete notification)

#### Frontend
- ❌ NO notification list UI
- ❌ NO notification badge/counter
- ❌ NO mark-as-read functionality

---

## 4. NOTIFICATION OWNERSHIP & SECURITY

### Current State:

**Authentication**:
- ✅ JWT available on all endpoints
- ✅ Can identify authenticated user
- ✅ Can link notifications to userId

**Authorization Needed**:
- ❌ Commuter should only see their own notifications
- ❌ Driver should only see their own notifications
- ❌ Admin access (optional, not in FR-16/17)

### Proposed Security Model (for later implementation):

```javascript
// Get notifications for authenticated user
GET /api/notifications
- Requires: authenticateToken
- Returns: notifications where notifications.userId = req.user.id OR
           notifications.driverId = req.user.id
- Rejects: unauthenticated (401), wrong user (403)

// Mark as read
PUT /api/notifications/:id/read
- Requires: authenticateToken
- Verifies: notification belongs to authenticated user
- Returns: updated notification
```

---

## 5. STEP 4 COMPATIBILITY ANALYSIS

### Step 4 Features (Request/Accept/Decline/Timeout):

**Current Request Flow**:
1. Ride created → status='pending'
2. Published to RabbitMQ queue
3. queueConsumer finds verified driver
4. Creates request: requestStatus='pending', requestExpiresAt=NOW+15s
5. Driver receives (Socket.io event `ride:request`)
6. Driver accepts/declines within 15s
7. Timeout reassigns to next driver

**How Notifications Would Integrate** (non-breaking):

```
Step 4 Event                    → Notification Created
─────────────────────────────────────────────────────
ride created                     → "Searching for driver"
request sent to driver           → (internal, no user notification yet)
driver accepts                   → "Driver assigned"
driver declines                  → "Searching for new driver"
timeout (reassign)               → "Searching for new driver" (update)
no drivers available             → "Waiting for drivers"
```

**Safety Checks**:
- ✅ No modification to requestStatus/requestExpiresAt needed
- ✅ No modification to requestedDriver needed
- ✅ No modification to queueConsumer needed
- ✅ No modification to accept/decline endpoints needed
- ✅ No modification to timeout logic needed
- ✅ Notifications are purely additive (read-only from Step 4)

---

## 6. STEP 5 COMPATIBILITY ANALYSIS

### Step 5 Features (Lifecycle: Searching → Assigned → En Route → Completed/Cancelled):

**Current Lifecycle**:
- pending (Searching) 
- assigned (Driver found)
- en_route (Started)
- completed (Finished)
- cancelled (Stopped)

**How Notifications Would Integrate**:

```
Step 5 Status Change            → Notifications Created
─────────────────────────────────────────────────────
pending → assigned              → "Driver [Name] assigned"
assigned → en_route             → "Driver on the way"
en_route → completed            → "Ride completed"
pending/assigned → cancelled    → "Ride cancelled by you/driver"
```

**Safety Checks**:
- ✅ No modification to status values needed
- ✅ No modification to start/complete/cancel endpoints needed
- ✅ Notifications triggered by existing events
- ✅ Read-only notifications don't affect ride state

---

## 7. SOCKET.IO INSPECTION - DETAILED

### Current Socket.io Setup (server.js):

```javascript
io.on("connection", (socket) => {
  // Room join
  socket.on("joinRide", (rideId) => {
    socket.join(rideId);
  });

  // Events broadcast to room
  socket.on("driver:location", ({ rideId, lat, lng }) => {
    io.to(rideId).emit("ride:driverLocation", { lat, lng, timestamp });
  });
});
```

### Issues for FR-16/17:

1. **No User-Specific Targeting**
   - Current: `io.emit()` or `io.to(rideId).emit()` (broadcasts to room)
   - Needed: Send notification only to specific authenticated user
   - Solution: Create rooms like `user:userId` for user-specific events

2. **No Persistence**
   - Current: Events lost if client disconnected
   - Needed: Notifications persisted in database
   - Solution: When creating notification, emit Socket.io + save to DB

3. **No Socket ID → User Mapping**
   - Current: No way to identify which socket belongs to which user
   - Needed: Map socket.id → userId during handshake
   - Solution: Use Socket.io middleware to authenticate and attach userId

### Proposed Socket.io Changes (for later):

```javascript
io.on("connection", (socket) => {
  // Authenticate and attach user info
  const token = socket.handshake.auth.token;
  const user = verifyJWT(token);
  socket.userId = user.id;
  socket.userRole = user.role;

  // Join user-specific room
  socket.join(`user:${user.id}`);

  // Send notification to specific user
  io.to(`user:${user.id}`).emit("notification", { ... });
});
```

---

## 8. MINIMAL IMPLEMENTATION PLAN

### A. Database Changes

**New Table: notifications**

```sql
CREATE TABLE notifications (
  id TEXT PRIMARY KEY,
  userId TEXT NOT NULL,
  rideId TEXT,
  type TEXT NOT NULL,              -- 'ride_created', 'driver_assigned', etc.
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  read INTEGER DEFAULT 0,
  createdAt TEXT NOT NULL,
  FOREIGN KEY (userId) REFERENCES users(id),
  FOREIGN KEY (rideId) REFERENCES rides(id)
);
```

**Fields**:
- `id`: Unique identifier (e.g., "n" + timestamp)
- `userId`: Owner of notification (commuter or driver user_id)
- `rideId`: Related ride (for grouping)
- `type`: Notification type (ride_created, driver_assigned, en_route, completed, cancelled, etc.)
- `title`: Display title ("Your driver assigned")
- `message`: Display message ("John Doe has accepted your ride")
- `read`: 0 (unread) or 1 (read)
- `createdAt`: ISO timestamp

**Migration**: Safe ALTER TABLE (similar to Step 4/5 pattern)

### B. Backend/API Changes

**New Endpoints**:

1. **GET /api/notifications**
   - Returns: All notifications for authenticated user
   - Filters by: userId = req.user.id
   - Ordered by: createdAt DESC
   - Includes read/unread counts

2. **GET /api/notifications/:id**
   - Returns: Single notification (if user owns it)
   - Verifies: notification.userId = req.user.id

3. **PUT /api/notifications/:id/read**
   - Updates: notification.read = 1
   - Returns: Updated notification

4. **DELETE /api/notifications/:id**
   - Deletes notification
   - Only if user owns it

**Notification Creation** (in existing endpoints):

- When ride created → Create "Searching for driver" notification
- When driver accepts → Create "Driver assigned" notification
- When driver starts → Create "En Route" notification
- When driver completes → Create "Completed" notification
- When ride cancelled → Create "Cancelled" notification

### C. Socket.io Changes

**Add**:
- Middleware to authenticate socket and attach userId
- Room per user: `user:{userId}`
- Event: `notification` (emit to user's room)

**Preserve**:
- Existing ride rooms (`joinRide`)
- Existing events (ride:request, ride:accepted, etc.)
- Existing location tracking

### D. Frontend Commuter Changes (passenger.html)

**Add**:
- Notification bell icon (top right)
- Unread count badge
- Notification dropdown/panel
- Mark-as-read functionality
- Delete notification

**JavaScript**:
- Poll GET /api/notifications on page load
- Listen for Socket.io `notification` event
- Update UI when notification received
- PUT notification/:id/read when user clicks

### E. Frontend Driver Changes (driver.html)

**Add**:
- Notification bell icon
- Notification dropdown
- Mark-as-read functionality

**JavaScript**:
- Similar to commuter (poll + Socket.io)

### F. Security Changes

**Endpoints**:
- All notification endpoints require: `authenticateToken`
- All endpoints verify: `notification.userId === req.user.id`
- Parameterized SQL queries (already pattern in codebase)

**Socket.io**:
- Authenticate socket during connection
- Attach userId to socket
- Send notifications to user-specific room only

### G. Testing

**Unit Tests**:
- [ ] Create notification with correct userId
- [ ] Cannot retrieve another user's notification
- [ ] Mark-as-read updates read=1
- [ ] Delete removes notification

**Integration Tests**:
- [ ] Notification created when ride booked
- [ ] Notification created when driver accepts
- [ ] Socket.io event received by correct user
- [ ] Unread count accurate

---

## 9. SAFE MIGRATION REQUIREMENTS

### Migration Function (for db.js):

```javascript
function migratePhase1Step6() {
  const tableInfo = db.prepare("PRAGMA table_info(notifications)").all();
  
  if (tableInfo.length === 0) {
    // Table doesn't exist, create it
    console.log("🔄 Creating notifications table...");
    db.exec(`
      CREATE TABLE notifications (
        id TEXT PRIMARY KEY,
        userId TEXT NOT NULL,
        rideId TEXT,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        read INTEGER DEFAULT 0,
        createdAt TEXT NOT NULL,
        FOREIGN KEY (userId) REFERENCES users(id),
        FOREIGN KEY (rideId) REFERENCES rides(id)
      );
    `);
  } else {
    console.log("✅ Notifications table already exists");
  }
}

migratePhase1Step6();
```

**Safety Properties**:
- ✅ Idempotent (checks if table exists first)
- ✅ Non-destructive (creates new table only)
- ✅ Data-preserving (no deletes, no overwrites)
- ✅ Compatible (uses FOREIGN KEY references to existing tables)

---

## 10. TEST PLAN

### A. Tests That Can Be Executed Now

**CODE INSPECTION ONLY** (Cannot execute without full environment):

1. ✅ Verify notification table schema valid
2. ✅ Verify foreign keys reference existing tables
3. ✅ Verify migration is idempotent
4. ✅ Verify Socket.io can target user-specific room
5. ✅ Verify JWT can extract userId

### B. Code-Inspection Tests

1. ✅ **Commuter receives driver-assigned notification**
   - Inspect: POST /api/rides/:id/accept creates notification
   - Check: notification.type='driver_assigned', notification.userId=ride.userId

2. ✅ **Driver receives new ride request notification**
   - Inspect: queueConsumer creates notification when request sent
   - Check: notification.type='ride_request', notification.userId=driver.user_id

3. ✅ **Commuter receives en-route notification**
   - Inspect: POST /api/rides/:id/start creates notification
   - Check: notification.type='en_route', notification.userId=ride.userId

4. ✅ **Commuter receives completed notification**
   - Inspect: POST /api/rides/:id/complete creates notification
   - Check: notification.type='completed', notification.userId=ride.userId

5. ✅ **Commuter receives cancellation notification**
   - Inspect: POST /api/rides/:id/cancel creates notification
   - Check: notification.type='cancelled', notification.userId=ride.userId

6. ✅ **Driver receives cancellation notification**
   - Inspect: POST /api/rides/:id/cancel creates notification if assigned
   - Check: notification.type='ride_cancelled', notification.userId=driver.user_id

7. ✅ **Notifications persisted in database**
   - Inspect: All notifications use db.prepare().run()
   - Check: No in-memory-only storage

8. ✅ **Unread/read behavior**
   - Inspect: GET endpoint returns read status
   - Inspect: PUT endpoint updates read=1
   - Check: Filtering by read status works

9. ✅ **User cannot retrieve another user's notifications**
   - Inspect: GET endpoint verifies notification.userId=req.user.id
   - Check: 403 returned if mismatch

10. ✅ **Old ride data remains intact**
    - Inspect: Migration only creates new table
    - Check: No ALTER to existing rides table

11. ✅ **Step 4 accept/decline/timeout remains functional**
    - Inspect: No changes to routes/rides.js accept/decline
    - Inspect: No changes to queueConsumer.js
    - Check: Request/timeout logic untouched

12. ✅ **Step 5 lifecycle remains functional**
    - Inspect: No changes to start/complete/cancel logic
    - Check: Status transitions untouched

### C. Integration/Browser Tests NOT Executable in Current Environment

**Cannot Execute** (Requires Full Environment):

1. **Actual notification sent to driver**
   - Requires: RabbitMQ, live database, two authenticated users
   - Requires: Socket.io connection

2. **Notification UI displays correctly**
   - Requires: Browser, server running, WebSocket connection

3. **Concurrent accept + notification race**
   - Requires: Coordinated timing

4. **Socket.io user-specific delivery**
   - Requires: Two WebSocket clients, server routing

5. **Notification list updates in real-time**
   - Requires: Live Socket.io server

---

## 11. RISKS & LIMITATIONS

### Risk 1: Notification Explosion

**Problem**: Could create thousands of notifications (one per event)

**Mitigation**:
- Implement retention policy (delete old notifications after 30 days)
- Implement max notification per user (keep only last 100)
- Add filtering/search

**For Phase 1 Step 6**: Accept as limitation, document for future

### Risk 2: Notification Delivery Race

**Problem**: Notification created in DB after Socket.io sent

**Mitigation**:
- Create notification FIRST, then emit Socket.io
- Or create notification and immediately emit (atomic-ish)

### Risk 3: Socket.io Connection Loss

**Problem**: User goes offline, misses notification

**Mitigation**:
- Database persistence solves this
- User sees missed notifications when reconnect/reload

### Limitation 1: No Push Notifications

**Current**: Web-only (Socket.io + polling)

**Not Implemented**: Mobile push, email, SMS

**Acceptable**: Out of scope for Phase 1

### Limitation 2: No Notification Preferences

**Current**: All notifications sent

**Not Implemented**: User can opt-out of certain notification types

**Acceptable**: Out of scope for Phase 1

### Limitation 3: No Notification Timestamps Grouping

**Current**: Each notification separate

**Not Implemented**: "5 new notifications about ride R123"

**Acceptable**: Can be added later

---

## 12. FILES THAT WOULD NEED MODIFICATION

### During Implementation (NOT NOW):

| File | Changes |
|------|---------|
| db.js | Add migratePhase1Step6() function + migration call |
| routes/rides.js | Create notification records in endpoints |
| routes/drivers.js | Create notification records in endpoints |
| server.js | Add Socket.io middleware for user authentication |
| services/queueConsumer.js | Create notification when request sent |
| middleware/auth.js | (No changes, already available) |
| public/driver.html | Add notification UI + listeners |
| public/passenger.html | Add notification UI + listeners |
| public/rides.html | (Optional: add notification indicator) |
| public/css/style.css | (Add notification styles) |

### NOT Modified:
- middleware/auth.js (already complete)
- services/soapClient.js (unchanged)
- services/fareService.js (unchanged)
- routes/auth.js (unchanged)
- package.json (unchanged)

---

## SUMMARY TABLE

| Requirement | Current | Status |
|-------------|---------|--------|
| **FR-16: Ride Notifications** | | |
| Commuter notified when driver assigned | ❌ Missing | MISSING |
| Driver notified when new request | ⚠️ Socket.io only | NOT PERSISTENT |
| Commuter notified when en_route | ❌ Missing | MISSING |
| Commuter notified when completed | ❌ Missing | MISSING |
| Commuter notified when cancelled | ❌ Missing | MISSING |
| **FR-17: Notification Records** | | |
| Notifications persisted in DB | ❌ No table | MISSING |
| User can retrieve own notifications | ❌ No endpoint | MISSING |
| Unread/read status | ❌ Not tracked | MISSING |
| User cannot access other's notifications | ✅ Auth exists | IMPLEMENTABLE |
| **Infrastructure** | | |
| Socket.io available | ✅ Yes | EXISTS |
| JWT authentication | ✅ Yes | EXISTS |
| Database migration pattern | ✅ Yes | EXISTS |
| RBAC system | ✅ Yes | EXISTS |

---

## CONCLUSION

**Phase 1 Step 6 is feasible** with minimal complexity:

1. ✅ Add notifications table (safe migration)
2. ✅ Create 4 API endpoints (GET, PUT, DELETE)
3. ✅ Create notifications in existing endpoints (rides.js)
4. ✅ Add Socket.io middleware for user auth
5. ✅ Add notification UI (driver.html, passenger.html)
6. ✅ No breaking changes to Step 4 or Step 5

**Ready for implementation upon approval**

