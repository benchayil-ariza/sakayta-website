# SakayTa — GUI Prototype Design Rationale

## Screen coverage (5 screens)

| Screen | Purpose |
|---|---|
| Home | Landing page, explains the system, links to every workflow |
| Book a Ride | Core passenger workflow: set pickup/drop-off, get a fare estimate |
| All Rides Dashboard | Admin/monitoring view: see every ride's status, inspect JSON/XML |
| Driver View | Driver shares (or simulates) live location for an active ride |
| Passenger View | Passenger watches the driver's location update in real time |

Five screens were chosen to cover the three real actors in the system — passenger, driver, and an overview/admin view — without adding screens that don't map to an actual user need. Each screen supports one clear task, rather than combining multiple actions into a single dense screen.

## Navigation

A persistent top navigation bar appears identically on all 5 screens, with the current page marked (`aria-current="page"`) both visually and for assistive technology. This keeps orientation consistent — a tester should always know where they are and how to get anywhere else in one click.

## Input validation

The booking form (the only screen that collects user input) validates:
- Passenger name is required — an inline error appears next to the field if left blank, rather than relying on browser-default validation popups, which are inconsistent across browsers and easy to miss.
- Both a pickup and drop-off point must be set on the map before the Book button is enabled — the button is disabled by default, so a user cannot submit an incomplete booking.
- Server errors (e.g. the API being unreachable) are caught and shown as a clear inline message rather than the page silently failing.

## Feedback messages

Every asynchronous action (booking a ride, refreshing the rides list) shows a loading state (button text changes, spinner appears, list area shows "Loading...") so the user knows the system registered their action instead of wondering if a click did anything. Success and error states are visually distinct (color-coded) and announced to screen readers via `aria-live="polite"` regions.

## Accessibility basics implemented

- All interactive elements have visible keyboard focus outlines
- Form inputs have associated `<label>` elements (not just placeholder text, which disappears on focus and isn't reliably read by screen readers)
- Live status updates (booking confirmation, ride status, tracking distance) use `aria-live="polite"` so screen reader users are notified of changes without needing to re-navigate
- Semantic HTML landmarks (`<header>`, `<nav>`, `<main>`) are used consistently across all 5 screens for predictable screen-reader navigation
- Map areas are labeled with `role="application"` and a descriptive `aria-label`, since an interactive map isn't natively accessible

## What we did NOT address in this prototype (documented limitation)

- Full WCAG color contrast audit — the current palette was chosen for visual clarity but hasn't been formally checked against WCAG AA contrast ratios
- Screen reader testing was not performed with an actual screen reader (e.g. NVDA/VoiceOver) — accessibility attributes were added based on best practice, not verified with assistive technology
- Mobile responsive layout was not a focus for this prototype phase — screens are designed and tested at desktop width

These are reasonable, honestly-documented scope limits for a prototype phase, and can be raised as future work during defense if asked.
