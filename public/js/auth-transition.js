// Cinematic Login <-> Sign Up transition.
// The exiting page animates its own two panels crossing sides, then navigates.
// The destination page just plays its normal on-load entrance (see style.css keyframes),
// which reads as a continuation of the same motion.

function authNavigate(targetUrl, direction) {
  // direction: "signup" (going to Sign Up) or "login" (going to Log In)
  const section = document.querySelector(".auth-page-section");
  const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  if (!section || reduced) {
    window.location.href = targetUrl;
    return;
  }

  const visual = section.querySelector(".auth-page-visual");
  const formWrap = section.querySelector(".auth-page-form-wrap");

  if (direction === "signup") {
    // Branding moves LEFT -> RIGHT, Login form moves RIGHT -> LEFT
    visual.style.transform = "translateX(100%)";
    visual.style.opacity = "0.15";
    formWrap.style.transform = "translateX(-100%)";
    formWrap.style.opacity = "0";
  } else {
    // Branding moves RIGHT -> LEFT, Sign Up form moves LEFT -> RIGHT
    visual.style.transform = "translateX(-100%)";
    visual.style.opacity = "0.15";
    formWrap.style.transform = "translateX(100%)";
    formWrap.style.opacity = "0";
  }

  setTimeout(() => {
    window.location.href = targetUrl;
  }, 340);
}
