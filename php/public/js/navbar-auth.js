// public/js/navbar-auth.js
// Auth-aware navbar: swaps the Log In / Sign Up links for a Log Out link based on
// whether a JWT is present in localStorage. Added to every navbar-bearing page.
// Reuses the existing navbar structure and classes (.nav-login-btn, .nav-signup-btn)
// and a single #nav-logout-btn. Safe to run on any page even if elements are absent.

(function () {
  "use strict";

  function getToken() {
    return localStorage.getItem("sakayta_token");
  }

  function setElementVisible(selector, visible) {
    const elements = document.querySelectorAll(selector);
    elements.forEach((el) => {
      el.style.display = visible ? "" : "none";
    });
  }

  // Update navbar based on whether a JWT exists.
  function updateNavbar() {
    const loggedIn = !!getToken();

    // Only these three targets are toggled. The parent .nav-auth-buttons is left alone,
    // so the notification bell on driver.html / passenger.html keeps working.
    setElementVisible(".nav-login-btn", !loggedIn);
    setElementVisible(".nav-signup-btn", !loggedIn);
    setElementVisible("#nav-logout-btn", loggedIn);
  }

  // Attach the logout click handler (if the element exists).
  function initLogout() {
    const logoutBtn = document.getElementById("nav-logout-btn");
    if (!logoutBtn) return;

    logoutBtn.addEventListener("click", (event) => {
      event.preventDefault();
      // Clear the JWT auth state entirely.
      localStorage.removeItem("sakayta_token");
      localStorage.removeItem("sakayta_user");
      // Return to the homepage. The backend never needs to be contacted here;
      // protected API requests will naturally fail with 401 once the token is gone.
      window.location.href = "/index.html";
    });
  }

  // Run once the DOM is present.
  function init() {
    updateNavbar();
    initLogout();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();