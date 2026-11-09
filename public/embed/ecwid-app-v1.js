/**
 * ShopBonus Ecwid / Lightspeed E-Series app storefront script (app customJsUrl:
 * https://YOUR_BACKEND/embed/ecwid-app-v1.js?client_id=APP_CLIENT_ID).
 * Reads this store's app public config (Ecwid.getAppPublicConfig) written by EcwidAppPublisher and loads
 * the renderer from this script's own origin only. Missing/malformed/foreign config loads nothing.
 */
(function () {
  "use strict";
  var script = document.currentScript;
  if (!script || window.__shopbonusEcwidApp) return;
  window.__shopbonusEcwidApp = true;
  var self, clientId;
  try {
    self = new URL(script.src);
    clientId = self.searchParams.get("client_id");
  } catch (e) { return; }
  if (self.protocol !== "https:" || !clientId || !/^[A-Za-z0-9_-]{1,128}$/.test(clientId)) return;
  var expectedEmbed = self.origin + "/embed/v1.js";
  function config() {
    var raw;
    try { raw = window.Ecwid.getAppPublicConfig(clientId); } catch (e) { return null; }
    if (raw && typeof raw === "object" && typeof raw.value === "string") raw = raw.value;
    if (typeof raw !== "string" || raw.length > 4096) return null;
    var value;
    try { value = JSON.parse(raw); } catch (e) { return null; }
    if (!value || value.schema !== "shopbonus.ecwid-app.v1" || value.embed !== expectedEmbed
      || typeof value.public_key !== "string" || !/^[A-Za-z0-9_-]{8,128}$/.test(value.public_key)) return null;
    return value;
  }
  function load() {
    if (document.querySelector("script[data-shopbonus-key]")) return;
    var value = config();
    if (!value) return;
    var s = document.createElement("script");
    s.async = true;
    s.src = value.embed;
    s.setAttribute("data-shopbonus-key", value.public_key);
    (document.head || document.body).appendChild(s);
  }
  if (window.Ecwid && window.Ecwid.OnAPILoaded && typeof window.Ecwid.OnAPILoaded.add === "function") window.Ecwid.OnAPILoaded.add(load);
})();
