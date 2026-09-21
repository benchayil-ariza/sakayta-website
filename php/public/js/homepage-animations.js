// SakayTa Homepage Animations
// Vanilla JavaScript - no external libraries
// Implements: scroll-reveal, parallax depth, 3D card tilt

(function () {
  "use strict";

  // Check for reduced motion preference
  const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  // ============================================
  // 1. SCROLL REVEAL with IntersectionObserver
  // ============================================
  function initScrollReveal() {
    const revealSections = [
      { selector: ".trust-section", stagger: false },
      { selector: ".stat-block", stagger: true, delay: 100 },
      { selector: ".steps-section > h2, .steps-section > p.section-sub", stagger: false },
      { selector: ".step-card", stagger: true, delay: 120 },
      { selector: ".features-section > h2, .features-section > p", stagger: false },
      { selector: ".feature-card", stagger: true, delay: 130 },
      { selector: ".cta-band", stagger: false },
    ];

    // If reduced motion, show everything immediately
    if (prefersReducedMotion) {
      revealSections.forEach(({ selector }) => {
        document.querySelectorAll(selector).forEach((el) => {
          el.style.opacity = "1";
          el.style.transform = "none";
        });
      });
      return;
    }

    // Set initial hidden state
    revealSections.forEach(({ selector }) => {
      document.querySelectorAll(selector).forEach((el) => {
        el.style.opacity = "0";
        el.style.transform = "translateY(32px)";
        el.style.transition = "opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1), transform 0.7s cubic-bezier(0.22, 1, 0.36, 1)";
      });
    });

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const delay = parseInt(entry.target.dataset.revealDelay || "0", 10);
            setTimeout(() => {
              entry.target.style.opacity = "1";
              entry.target.style.transform = "translateY(0)";
            }, delay);
            observer.unobserve(entry.target); // Only animate once
          }
        });
      },
      {
        threshold: 0.15,
        rootMargin: "0px 0px -80px 0px",
      }
    );

    revealSections.forEach(({ selector, stagger, delay }) => {
      const elements = document.querySelectorAll(selector);
      elements.forEach((el, index) => {
        if (stagger && delay) {
          el.dataset.revealDelay = index * delay;
        }
        observer.observe(el);
      });
    });
  }

  // ============================================
  // 2. PARALLAX DEPTH - Hero background grid
  // ============================================
  function initParallax() {
    if (prefersReducedMotion) return;

    const hero = document.querySelector(".hero");
    if (!hero) return;

    const heroBackground = hero.querySelector("::before") ? hero : null;
    let ticking = false;

    function updateParallax() {
      const scrollY = window.pageYOffset;
      const heroHeight = hero.offsetHeight;

      // Only apply parallax while hero is in view
      if (scrollY < heroHeight) {
        // Move background slower than foreground (0.3 = 30% speed)
        const offset = scrollY * 0.3;
        hero.style.setProperty("--parallax-offset", `${offset}px`);
      }

      ticking = false;
    }

    function requestTick() {
      if (!ticking) {
        requestAnimationFrame(updateParallax);
        ticking = true;
      }
    }

    window.addEventListener("scroll", requestTick, { passive: true });
  }

  // ============================================
  // 3. 3D INTERACTIVE CARDS - Mouse-tracked tilt
  // ============================================
  function init3DCards() {
    if (prefersReducedMotion) return;

    const cards = document.querySelectorAll(".step-card, .feature-card");

    cards.forEach((card) => {
      card.style.transformStyle = "preserve-3d";
      card.style.transition = "transform 0.15s ease-out, box-shadow 0.3s ease";

      card.addEventListener("mouseenter", () => {
        card.style.transition = "box-shadow 0.3s ease";
      });

      card.addEventListener("mousemove", (e) => {
        const rect = card.getBoundingClientRect();
        const cardWidth = rect.width;
        const cardHeight = rect.height;
        const centerX = rect.left + cardWidth / 2;
        const centerY = rect.top + cardHeight / 2;

        // Mouse position relative to card center (-1 to 1)
        const mouseX = (e.clientX - centerX) / (cardWidth / 2);
        const mouseY = (e.clientY - centerY) / (cardHeight / 2);

        // Subtle tilt: max 4 degrees
        const rotateY = mouseX * 4;
        const rotateX = mouseY * -4;

        card.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateZ(8px)`;
      });

      card.addEventListener("mouseleave", () => {
        card.style.transition = "transform 0.4s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.3s ease";
        card.style.transform = "perspective(1000px) rotateX(0deg) rotateY(0deg) translateZ(0)";
      });
    });
  }

  // ============================================
  // INITIALIZATION
  // ============================================
  function init() {
    // Wait for DOM to be ready
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", init);
      return;
    }

    initScrollReveal();
    initParallax();
    init3DCards();
  }

  init();
})();
