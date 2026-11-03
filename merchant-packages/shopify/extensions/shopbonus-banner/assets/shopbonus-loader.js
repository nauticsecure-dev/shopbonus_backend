(function () {
  "use strict";
  function install() {
    var marker = document.querySelector("[data-shopbonus-install]");
    if (!marker || document.querySelector("script[data-shopbonus-key]")) return;
    var key = marker.getAttribute("data-key") || "";
    if (!/^pk_[A-Za-z0-9_-]+$/.test(key)) return;
    var origin;
    try {
      origin = new URL(marker.getAttribute("data-origin"));
      if (origin.protocol !== "https:" || origin.username || origin.password || origin.port || origin.search || origin.hash || origin.pathname !== "/") return;
    } catch (_) { return; }
    var script = document.createElement("script");
    script.src = origin.origin + "/embed/v1.js";
    script.defer = true;
    script.setAttribute("data-shopbonus-key", key);
    document.body.appendChild(script);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", install, { once: true });
  else install();
  document.addEventListener("shopify:section:load", install);
})();
