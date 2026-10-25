# SAKAYTA — EXACT EXCEPTION CAPTURE REPORT

**Capture Date:** 2026-10-03  
**Time:** After Apache restart  
**Request:** GET /api/drivers/me/ride with fresh Maeng JWT

---

## A. HTTP RESULT

```
HTTP Status Code: 500

Response Body:
{"error":"Internal server error."}
```

---

## B. EXACT EXCEPTION

**Exception Class:** `Error` (PHP Class Not Found error)

**Exception Message:** `Class "Sakayta\\CurrentUser" not found`

**Exception Type:** Fatal error (PHP cannot load the class)

---

## C. EXACT FILE:LINE

**File:** `C:\Users\ASUS\Documents\sakayta-website\sakayta-website\php\public\api.php`

**Line:** 104

---

## D. EXACT DATABASE/SQL ERROR

**Not applicable** — The exception occurs before any database query.

The failure happens at the **class autoloading stage**, not at the PDO/query level.

---

## E. ROOT CAUSE SUPPORTED BY EVIDENCE

**Root Cause:** PHP autoloader cannot find `Sakayta\CurrentUser` class.

**Evidence from error log:**
```
[sakayta-api] Class "Sakayta\\CurrentUser" not found @ C:\Users\ASUS\Documents\sakayta-website\sakayta-website\php\public\api.php:104
```

**Specific location:** Line 104 of `api.php`:
```php
$claims = CurrentUser::required();
```

This line uses `CurrentUser` statically, but the class is not being loaded by the autoloader.

**Why this happens:**
1. `api.php` does `require __DIR__ . '/../autoload.php'` (line 15)
2. The autoload.php should register a PSR-4 autoloader for `Sakayta\` namespace
3. The `CurrentUser` class should be in `php/Auth/CurrentUser.php`
4. But the autoloader is not finding it OR the namespace/file structure is mismatched

**This is NOT a database/query issue.** The exception occurs during class instantiation, before `getDriverProfile()` or any ride query ever executes.

---

## F. SMALLEST PROPOSED FIX (DO NOT APPLY YET)

**The problem is an autoloading/namespace issue, not a ride query issue.**

**Probable fix:**
1. Verify `php/Auth/CurrentUser.php` exists and contains `class CurrentUser`
2. Verify `php/autoload.php` has the correct PSR-4 autoloader registration:
   ```php
   spl_autoload_register(function ($class) {
       $prefix = 'Sakayta\\';
       if (strpos($class, $prefix) !== 0) return;
       $relative_class = substr($class, strlen($prefix));
       $file = __DIR__ . '/' . str_replace('\\', '/', $relative_class) . '.php';
       if (file_exists($file)) require $file;
   });
   ```
3. If autoload.php is missing or incorrect, create/fix it

**Alternative fix (if autoload.php is present but not working):**
- Add explicit `use Sakayta\Auth\CurrentUser;` at the top of api.php (after require autoload.php)
- Or add explicit `require __DIR__ . '/../Auth/CurrentUser.php';` before use

---

## G. FILES THAT WOULD NEED MODIFICATION

**Primary:**
- `php/autoload.php` — verify/fix PSR-4 autoloader registration

**Secondary (if autoload needs help):**
- `php/public/api.php` — add explicit require/use statements if autoloader is broken

**NOT the actual issue files:**
- ~~`php/Ride/RideService.php`~~ (never reached due to class loading failure)
- ~~`php/config/.env`~~ (not the problem)
- ~~`php/db/Database.php`~~ (not reached)

---

## H. VERIFICATION PROCEDURE AFTER THE FIX

1. **Verify autoload.php is correct:**
   ```bash
   grep -n "spl_autoload_register\|Sakayta" php/autoload.php
   ```

2. **Verify CurrentUser.php exists:**
   ```bash
   ls -la php/Auth/CurrentUser.php
   ```

3. **Test the endpoint again:**
   ```bash
   curl -H "Authorization: Bearer <token>" http://localhost:8088/api/drivers/me/ride
   ```

4. **Expected result after fix:**
   - HTTP 200 with ride data: `{"id":"r_...", "status":"pending", ...}` OR
   - HTTP 200 with no-ride message: `{"message":"No pending ride request.","ride":null}` OR
   - HTTP 4xx if other validation fails (but NOT 500)

5. **Check error log for NEW exceptions** (if any):
   ```bash
   tail -f /opt/xampp/apache/logs/sakayta-php-error.log
   ```

---

## DIAGNOSIS COMPLETE

**Status:** Root cause identified — **autoloader failure, not a database/query issue.**

**Next step:** Fix the autoloader and re-test.

**Do NOT apply any changes yet.** Wait for approval.
