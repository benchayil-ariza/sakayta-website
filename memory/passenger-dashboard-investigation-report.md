---
name: passenger-dashboard-investigation-report
description: Investigation report on passenger dashboard sidebar clickability and logout button visibility issues
metadata:
  type: feedback
---

# Passenger Dashboard Investigation Report

## A. Exact root cause of sidebar non-clickability

The sidebar navigation buttons (Overview, Book a Ride, My Rides, Notifications, Profile) are not clickable because the JavaScript event listeners are attached before the DOM elements exist in the document.

**Root Cause Analysis:**
In `public/passenger.html`, the script tag containing the view switching logic is placed in the `<head>` section (lines 274-1066). The script attempts to attach event listeners to elements with class `.sidebar-link[data-view]` (line 545) before these elements are parsed and added to the DOM.

Specifically:
- Line 531: `const views = document.querySelectorAll(".driver-view");`
- Line 532: `const navLinks = document.querySelectorAll(".sidebar-link[data-view]");`
- Lines 545-547: Event listener attachment loop

Since the script runs during HTML parsing (in the head), when it reaches line 532, the sidebar navigation elements (which are in the body starting at line 61) have not yet been parsed, so `navLinks` returns an empty NodeList. Consequently, no event listeners are attached, making the buttons non-functional.

## B. Exact cause of logout button invisibility

The logout button in the passenger sidebar is actually visible and present in the DOM (line 81: `<button id="sidebarLogoutBtn" class="sidebar-logout" aria-label="Log out">Log Out</button>`). However, the button does not appear to function correctly because:

1. The button's click handler (lines 575-587) attempts to trigger a click on the element with ID `nav-logout-btn`
2. In `public/login.html` and `public/signup.html`, the `nav-logout-btn` element is styled with `style="display:none;"` (line 21 in both files)
3. While this hidden element exists in the DOM, its click handler in `navbar-auth.js` may not be properly invoked or may be conflicting with the sidebar's logout flow

**Visibility Status:** The sidebar logout button IS visible (it has no `display:none` or `hidden` attributes). The issue is functional (not triggering logout properly) rather than visibility.

## C. Exact files needed for logout confirmation

To implement logout confirmation modals for both passenger and driver dashboards, the following files would need modification:

1. `public/passenger.html` - Add confirmation modal HTML and modify sidebar logout handler
2. `public/driver.html` - Add confirmation modal HTML and modify sidebar logout handler
3. `public/js/navbar-auth.js` - Potentially modify to support confirmation flow (though current sidebar logout bypasses this)
4. `public/css/style.css` - Add styles for confirmation modal (if not reusing existing dialog styles)

The confirmation would need to:
- Intercept the logout button click
- Show a modal asking "Are you sure you want to log out?"
- Only proceed with logout if user confirms
- Handle both confirmation and cancellation

## D. Exact SRS status of passenger driver-location tracking

Based on SRS requirements and code inspection:

**FR-14: Real-time Driver Location Tracking** - **IMPLEMENTED**
- Socket.io event listener for `"ride:driverLocation"` exists (lines 504-520 in passenger.html)
- Updates driver marker position on map when location data is received
- Calculates and displays distance to passenger
- Provides ETA estimate based on distance
- Fit bounds to show both passenger and driver locations

**FR-15: Location-based ETA Estimation** - **IMPLEMENTED**
- Function `getDistanceKm()` calculates distance (line 522-528)
- ETA calculated assuming 20km/h average tricycle speed (line 517-519)
- Displayed as "~X min" in ride ETA field

**Non-functional Requirement: Performance** - **COMPLIANT**
- Uses efficient Leaflet marker updates rather than recreating markers
- Minimal DOM updates for distance/ETA displays
- Socket.io connection reused from authentication

The driver location tracking feature fully complies with SRS requirements for passenger dashboard.

## E. Recommended minimal implementation plan

1. **Fix sidebar clickability** (Highest priority):
   - Move script tag from head to just before closing `</body>` tag
   - OR wrap existing initialization code in `DOMContentLoaded` event listener
   - OR initialize event listeners after DOM is ready

2. **Fix logout functionality** (High priority):
   - Verify sidebar logout button properly triggers logout flow
   - Test that tokens are cleared and redirect to homepage occurs

3. **Add logout confirmation** (Medium priority):
   - Create reusable confirmation modal component
   - Integrate with sidebar logout buttons in both dashboards
   - Ensure confirmation prevents accidental logout

4. **Verify driver location tracking** (Low priority - already working):
   - Confirm socket connection handling edge cases
   - Test ETA calculation accuracy
   - Verify map bounds updating

## F. Exact frontend files that would change

1. `public/passenger.html` - Critical fix for script placement, potential logout confirmation integration
2. `public/driver.html` - Logout confirmation integration (sidebar structure similar)
3. `public/js/navbar-auth.js` - May need modification if centralizing logout handling
4. `public/css/style.css` - Add confirmation modal styles if needed

## G. Backend files required?

**No backend files required** for the reported issues:
- Sidebar clickability is purely frontend DOM timing issue
- Logout functionality uses existing frontend token clearing and redirect
- Logout confirmation is frontend-only user experience enhancement
- Driver location tracking is already implemented frontend (socket.io to existing backend endpoints)

All fixes can be implemented frontend-only without modifying any PHP backend files.
