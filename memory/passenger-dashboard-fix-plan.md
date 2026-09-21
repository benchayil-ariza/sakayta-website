---
name: passenger-dashboard-fix-plan
description: Implementation plan for fixing passenger dashboard sidebar clickability and logout functionality
metadata:
  type: plan
---

# Passenger Dashboard Fix Implementation Plan

## Problem Summary
1. Sidebar navigation buttons are not clickable due to DOM timing issue - event listeners attached before elements exist
2. Logout button in sidebar doesn't function properly due to incorrect delegation to hidden navbar-auth.js logout button

## Root Causes
1. Script tag in passenger.html head attempts to query DOM elements before they are parsed
2. Sidebar logout handler tries to trigger click on #nav-logout-btn which may not be properly handled

## Solution Approach
1. Move script initialization to DOMContentLoaded event or move script to end of body
2. Fix sidebar logout handler to directly perform logout instead of delegating
3. Add logout confirmation modal to prevent accidental logout

## Files to Modify
- public/passenger.html - Main fix location
- public/driver.html - Apply similar fixes for consistency
- public/css/style.css - Add styles for logout confirmation modal

## Implementation Details

### Fix 1: Sidebar Clickability (Highest Priority)
- Wrap all JavaScript initialization in DOMContentLoaded event listener
- OR move script tag to just before closing </body> tag
- Chosen approach: Wrap in DOMContentLoaded for minimal disruption

### Fix 2: Logout Functionality (High Priority)
- Replace sidebar logout handler's delegation to #nav-logout-btn with direct logout logic
- Clear tokens and redirect to homepage

### Fix 3: Logout Confirmation (Medium Priority)
- Create reusable confirmation modal
- Integrate with sidebar logout buttons in both dashboards
- Ensure confirmation prevents accidental logout

## Implementation Steps

### Step 1: Fix Passenger.html Script Timing
1. Wrap entire script content (lines 274-1066) in DOMContentLoaded event listener
2. Keep all existing functionality intact

### Step 2: Fix Sidebar Logout Handler
1. Replace lines 575-587 with direct logout logic:
   ```javascript
   if (sidebarLogoutBtn) {
     sidebarLogoutBtn.addEventListener("click", (e) => {
       e.preventDefault();
       localStorage.removeItem("sakayta_token");
       localStorage.removeItem("sakayta_user");
       window.location.href = "/index.html";
     });
   }
   ```

### Step 3: Add Logout Confirmation Modal
1. Add modal HTML structure at end of body
2. Add modal CSS to style.css
3. Modify sidebar logout handler to show modal instead of direct logout
4. Add modal button handlers for confirm/cancel actions

### Step 4: Apply Similar Fixes to Driver.html
1. Apply same DOMContentLoaded wrapping
2. Apply same logout confirmation integration
3. Ensure consistency between passenger and driver dashboards

## Verification Steps
1. Verify sidebar buttons are clickable after page load
2. Verify logout confirmation modal appears when clicking logout
3. Verify confirmation proceeds with logout and cancel dismisses modal
4. Verify tokens are cleared and redirect occurs on confirmation
5. Verify no regression in other functionality (notifications, booking, etc.)

## Estimated Effort
- Fix 1: 15 minutes
- Fix 2: 10 minutes
- Fix 3: 25 minutes
- Step 4: 20 minutes
- Total: ~70 minutes
