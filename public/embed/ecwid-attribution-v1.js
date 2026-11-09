/** Ecwid / Lightspeed E-Series hidden order fields. Load before embed/v1.js, after the native Ecwid loader. */
(function () {
  "use strict";
  if (window.__shopbonusEcwidAttribution) return;
  window.__shopbonusEcwidAttribution = true;

  var key = "shopbonus_assignment_token", timer = null, token = null, expiresAt = 0;
  function fields() {
    window.ec = window.ec || {};
    window.ec.order = window.ec.order || {};
    window.ec.order.extraFields = window.ec.order.extraFields || {};
    return window.ec.order.extraFields;
  }
  function apply() {
    var values = fields();
    // No title or checkoutDisplaySection: the token is never a shopper input.
    // Explicit hidden section also excludes it from notifications/admin presentation.
    if (token && Date.now() < expiresAt) values[key] = { value: token, orderDetailsDisplaySection: "hidden" };
    else delete values[key];
    if (window.Ecwid && typeof window.Ecwid.refreshConfig === "function") {
      try { window.Ecwid.refreshConfig(); } catch (e) { /* Store checkout remains usable. */ }
    }
  }
  function clear() {
    if (timer) clearTimeout(timer);
    timer = null; token = null; expiresAt = 0;
    apply();
  }
  window.addEventListener("shopbonus:attribution", function (event) {
    var value = event.detail && event.detail.assignment_token;
    var until = event.detail && event.detail.consent_expires_at;
    var consent = null;
    try { consent = window.ShopBonusConsent && window.ShopBonusConsent.getConsentId(); } catch (e) {}
    if (typeof consent !== "string" || !consent || typeof value !== "string" || !/^bx_[a-f0-9]{64}$/.test(value) || value.length !== 67 || !Number.isSafeInteger(until) || until <= Date.now()) return clear();
    if (timer) clearTimeout(timer);
    token = value; expiresAt = Math.min(until, Date.now() + 60000);
    timer = setTimeout(clear, expiresAt - Date.now());
    apply();
  });
  // Clear immediately while the embed revalidates a changed CMP decision.
  window.addEventListener("shopbonus:consent-changed", clear);
  window.addEventListener("pagehide", clear);
  if (window.Ecwid && window.Ecwid.OnAPILoaded && typeof window.Ecwid.OnAPILoaded.add === "function") {
    window.Ecwid.OnAPILoaded.add(apply);
  }
  // Remove stale configuration restored by a host before any consented assignment arrives.
  clear();
})();
