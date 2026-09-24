/* ============================================================
   CityPulse - main.js
   Minimal, framework-free enhancements for the base frontend.
   ============================================================ */

(function () {
    "use strict";

    // Mark the page as JS-ready once scripts run.
    document.body.classList.add("js-ready");

    // Fill every element using data-year with the current year.
    document.querySelectorAll("[data-year]").forEach(function (el) {
        el.textContent = new Date().getFullYear();
    });
})();