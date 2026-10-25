# SAKAYTA — AUTOLOADER BLOCKER ROOT CAUSE ANALYSIS

**Read-only inspection complete.**

---

## A. CURRENTUSER NAMESPACE/CLASS DECLARATION

**File:** `php/Auth/CurrentUser.php`

**Line 18:** `namespace Sakayta\Auth;`

**Line 20:** `final class CurrentUser`

**Namespace:** `Sakayta\Auth`  
**Class name:** `CurrentUser`  
**Full qualified name:** `Sakayta\Auth\CurrentUser`

---

## B. API.PHP IMPORT/BOOTSTRAP

**File:** `php/public/api.php`

**Line 13:** `namespace Sakayta;` ← **CRITICAL: api.php is in namespace `Sakayta`**

**Line 15:** `require __DIR__ . '/../autoload.php';` ← Autoloader is required

**Lines 17–25:** Use statements:
```php
use Sakayta\Auth\AuthController;
use Sakayta\Auth\AuthException;
use Sakayta\Admin\AdminException;
use Sakayta\Admin\AdminController;
use Sakayta\Config\Config;
use Sakayta\Driver\DriverController;
use Sakayta\Driver\DriverException;
use Sakayta\Ride\RideController;
use Sakayta\Ride\RideException;
```

**MISSING:** `use Sakayta\Auth\CurrentUser;` ← **NOT IMPORTED**

**Line 104:** `$claims = CurrentUser::required();`

---

## C. AUTOLOADER BEHAVIOR

**File:** `php/autoload.php` (lines 11–23)

The autoloader:
1. Checks if class starts with `Sakayta\\`
2. If yes, strips the prefix: `Sakayta\Auth\CurrentUser` → `Auth\CurrentUser`
3. Converts namespace separators to forward slashes: `Auth\CurrentUser` → `Auth/CurrentUser`
4. Builds filesystem path: `__DIR__ . '/' . 'Auth/CurrentUser' . '.php'`
   - `__DIR__` = `php/` directory
   - Result: `php/Auth/CurrentUser.php`
5. If file exists, requires it with `require_once`

**Verification:** The file DOES exist at `php/Auth/CurrentUser.php` ✓

---

## D. EXACT MISMATCH/ROOT CAUSE

**The problem is namespace qualification in api.php, NOT the autoloader.**

**Context:**
- `api.php` declares `namespace Sakayta;` (line 13)
- `api.php` is in the `Sakayta` namespace
- When `api.php` calls `CurrentUser::required()` on line 104, PHP looks for the class in the **current namespace first**
- Current namespace is `Sakayta`
- PHP looks for: `Sakayta\CurrentUser` ← **WRONG** (class is actually `Sakayta\Auth\CurrentUser`)
- **NOT found** → exception: "Class 'Sakayta\\CurrentUser' not found"

**Why the autoloader didn't help:**
- The autoloader IS working correctly
- But it only loads if PHP tries to load a class by its fully qualified name
- PHP doesn't request `Sakayta\Auth\CurrentUser` because the code wrote `CurrentUser` (unqualified)
- PHP interprets unqualified `CurrentUser` as `Sakayta\CurrentUser` (relative to current namespace)
- This never gets passed to the autoloader; it's a direct fatal error

**Evidence:**
- Line 108: `new \Sakayta\Ride\RideController();` ← uses fully-qualified name with leading backslash (works)
- Line 104: `CurrentUser::required();` ← uses unqualified name (fails)

---

## E. SMALLEST POSSIBLE PROPOSED FIX

**Option 1 (Recommended):** Add the missing `use` statement

**Change:** Add to the use statements block at top of `api.php` (after line 18):
```php
use Sakayta\Auth\CurrentUser;
```

**Option 2 (Alternative):** Fully-qualify the call site

**Change:** Replace line 104 from:
```php
$claims = CurrentUser::required();
```

To:
```php
$claims = \Sakayta\Auth\CurrentUser::required();
```

(Same for line 114 and any other unqualified CurrentUser calls)

---

## F. EXACT FILE AND LINE THAT WOULD CHANGE

**Option 1 (Add use statement):**
- **File:** `php/public/api.php`
- **Lines:** Insert after line 18 (after `use Sakayta\Auth\AuthController;`)
- **Change:**
  ```php
  use Sakayta\Auth\CurrentUser;
  ```

**Option 2 (Fully-qualify calls):**
- **File:** `php/public/api.php`
- **Lines:** 104, 114 (and any others using `CurrentUser::`)
- **Change:** Replace `CurrentUser::` with `\Sakayta\Auth\CurrentUser::`

---

## G. EXPECTED VERIFICATION RESULT

**After fix is applied:**

1. **Restart Apache**

2. **Make request:**
   ```bash
   curl -H "Authorization: Bearer <fresh-Maeng-token>" \
     http://localhost:8088/api/drivers/me/ride
   ```

3. **Expected response (one of):**

   **Success — ride exists:**
   ```json
   {
     "id": "r_1790743545_4734f81cd",
     "passengerName": "Carla",
     "status": "pending",
     "requestedDriver": "d_1789894964_fa68ad53",
     "fareEstimate": 99.50,
     ...
   }
   ```

   **Success — no pending ride:**
   ```json
   {
     "message": "No pending ride request.",
     "ride": null
   }
   ```

   **HTTP Status:** 200 (not 500) ✓

4. **Check error log:**
   - Should NOT contain `[sakayta-api] Class "Sakayta\\CurrentUser" not found`
   - If a different error appears, it will be the actual PDO/query issue (if any)

---

## SUMMARY

**Root Cause:** Missing `use Sakayta\Auth\CurrentUser;` statement in `api.php`

**Reason:** `api.php` is in namespace `Sakayta`, so unqualified `CurrentUser` resolves to `Sakayta\CurrentUser` (wrong), not `Sakayta\Auth\CurrentUser` (correct).

**Smallest Fix:** Add one line: `use Sakayta\Auth\CurrentUser;`

**Files to modify:** `php/public/api.php` only

**Verification:** Make request to `/api/drivers/me/ride` → expect 200, not 500

---

**READ-ONLY INSPECTION COMPLETE. NO CHANGES MADE.**

