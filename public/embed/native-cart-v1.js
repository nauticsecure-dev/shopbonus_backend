/** Opt-in native/session-package cart reader. Load before v1.js. */
(function () {
  "use strict";
  if (window.ShopBonusCart || typeof AbortController !== "function" || typeof fetch !== "function") return;
  var script = document.currentScript;
  if (!script) return;
  var platform = script.getAttribute("data-platform"), endpoint;
  try {
    if (platform === "woocommerce") {
      endpoint = new URL(script.getAttribute("data-cart-url"), location.origin);
      // rest_url() supports both pretty permalinks and WordPress's plain routing.
      var route = endpoint.searchParams.get("rest_route");
      if (route !== null) {
        if (route !== "/wc/store/v1/cart" || Array.from(endpoint.searchParams.keys()).length !== 1) return;
      } else if (!/\/wp-json\/wc\/store\/v1\/cart\/?$/.test(endpoint.pathname) || endpoint.search) return;
    } else if (platform === "bigcommerce") {
      endpoint = new URL("/api/storefront/carts", location.origin);
    } else if (["prestashop", "opencart", "magento"].indexOf(platform) >= 0) {
      endpoint = new URL(script.getAttribute("data-cart-url"), location.origin);
      var keys = Array.from(endpoint.searchParams.keys());
      if (platform === "prestashop") {
        var pretty = /\/module\/shopbonus\/cart\/?$/.test(endpoint.pathname);
        var plain = /\/index\.php$/.test(endpoint.pathname) && endpoint.searchParams.get("fc") === "module"
          && endpoint.searchParams.get("module") === "shopbonus" && endpoint.searchParams.get("controller") === "cart";
        if ((!pretty && !plain) || keys.some(function (key) { return ["fc", "module", "controller", "id_lang"].indexOf(key) < 0; })
          || (pretty && keys.some(function (key) { return key !== "id_lang"; }))
          || (endpoint.searchParams.has("id_lang") && !/^[1-9][0-9]*$/.test(endpoint.searchParams.get("id_lang")))) return;
      } else if (platform === "opencart") {
        if (!/\/index\.php$/.test(endpoint.pathname) || endpoint.searchParams.get("route") !== "common/cart.info"
          || keys.some(function (key) { return ["route", "language"].indexOf(key) < 0; })
          || (endpoint.searchParams.has("language") && !/^[a-z]{2}(?:-[a-z]{2})?$/.test(endpoint.searchParams.get("language")))) return;
      } else if (!/\/customer\/section\/load\/?$/.test(endpoint.pathname) || keys.length !== 1 || keys[0] !== "sections"
        || endpoint.searchParams.get("sections") !== "shopbonus_cart") return;
      // Duplicated routing keys are ambiguous even if URLSearchParams.get returns a safe first value.
      if (keys.some(function (key, index) { return keys.indexOf(key) !== index; })) return;
    } else return;
    if (endpoint.origin !== location.origin || endpoint.username || endpoint.password || endpoint.hash) return;
  } catch (e) { return; }
  function excluded() {
    if (/(?:^|\/)(checkout|checkouts|checkout\.php|payment|payments|account|account\.php|my-account|login|login\.php|register|order-received|customer|authentication)(\/|$)/i.test(location.pathname)) return true;
    try {
      var query = new URL(location.pathname + (location.search || ""), location.origin).searchParams;
      if (/(?:^|\/)(checkout|account|payment)(?:\/|\.|$)/i.test(query.get("route") || "")) return true;
      if (/^(order|order-opc|authentication|my-account|identity|addresses|address|history|order-detail|order-slip|order-follow|password|registration)$/.test(query.get("controller") || "")) return true;
    } catch (e) { return true; }
    return false;
  }
  if (excluded()) return;
  // WooCommerce plugin bridge (0.3.0+): same-origin admin-ajax endpoint and nonce, emitted by the plugin.
  var attributionUrl = null, attributionNonce = script.getAttribute("data-attribution-nonce") || "";
  if (platform === "woocommerce" && script.getAttribute("data-attribution-url")) {
    try {
      attributionUrl = new URL(script.getAttribute("data-attribution-url"), location.origin);
      if (attributionUrl.origin !== location.origin || !/\/wp-admin\/admin-ajax\.php$/.test(attributionUrl.pathname) || attributionUrl.search || attributionUrl.hash
        || !/^[A-Za-z0-9]{1,32}$/.test(attributionNonce)) attributionUrl = null;
    } catch (e) { attributionUrl = null; }
  }
  // Match the renderer's currency precision; unknown currencies fail closed.
  var currencies = ["EUR", "USD", "GBP", "CAD", "AUD", "NZD", "CHF", "SEK", "NOK", "DKK", "PLN", "CZK"];
  function normalize(cart) {
    var amount, currency;
    if (platform === "woocommerce") {
      var totals = cart && cart.totals;
      if (!totals || totals.currency_minor_unit !== 2 || typeof totals.total_price !== "string" || !/^(0|[1-9][0-9]*)$/.test(totals.total_price)) return null;
      amount = Number(totals.total_price); currency = totals.currency_code;
    } else if (["prestashop", "opencart", "magento"].indexOf(platform) >= 0) {
      var source = platform === "magento" ? cart && cart.shopbonus_cart : cart;
      if (!source || source.schema !== "shopbonus.cart-source.v1" || source.platform !== platform || source.minor_unit !== 2) return null;
      amount = source.total_minor; currency = source.currency;
    } else {
      // No cart has no reported currency: do not invent a zero/currency pair.
      if (!Array.isArray(cart) || cart.length !== 1 || !cart[0] || !cart[0].currency || cart[0].currency.decimalPlaces !== 2) return null;
      currency = cart[0].currency.code;
      var value = cart[0].cartAmount;
      if (typeof value !== "number" || !Number.isFinite(value) || value < 0) return null;
      // Parse the reported decimal exactly; never round a fractional minor unit.
      var parts = /^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/.exec(String(value));
      if (!parts) return null;
      amount = Number(parts[1]) * 100 + Number(((parts[2] || "") + "00").slice(0, 2));
    }
    return Number.isSafeInteger(amount) && amount >= 0 && currencies.indexOf(currency) >= 0 ? { total_minor: amount, currency: currency } : null;
  }
  var snapshot = null, subscribers = [], running = false, pending = false, stopped = false;
  var revision = 0, activeController = null;
  function notify() {
    subscribers.slice().forEach(function (fn) { try { fn(); } catch (e) {} });
    window.dispatchEvent(new CustomEvent("shopbonus:cart-changed"));
  }
  function invalidate() { snapshot = null; revision++; notify(); }
  function refresh() {
    if (stopped || document.visibilityState === "hidden" || excluded()) return;
    if (running) { pending = true; return; }
    running = true;
    var generation = revision, started = Date.now(), controller = new AbortController();
    activeController = controller;
    var timeout = setTimeout(function () { controller.abort(); invalidate(); }, 5000);
    Promise.resolve().then(function () {
      return fetch(endpoint.href, {
        credentials: "same-origin", cache: "no-store", redirect: "error",
        headers: { Accept: "application/json" }, signal: controller.signal,
      });
    }).then(function (response) {
      if (!response.ok) throw new Error("cart_unavailable");
      if (platform === "opencart") {
        // The extension attaches a tiny structured snapshot to the existing native cart.info response.
        // Never parse its HTML, formatted prices or item/customer data.
        var header = response.headers && response.headers.get("X-ShopBonus-Cart");
        if (typeof header !== "string" || header.length > 512) throw new Error("cart_source_missing");
        return JSON.parse(header);
      }
      return response.json();
    }).then(function (cart) {
      if (stopped || controller.signal.aborted || generation !== revision || excluded()) return;
      var value = normalize(cart);
      if (!value) throw new Error("cart_invalid");
      snapshot = { schema: "shopbonus.cart.v1", total_minor: value.total_minor, currency: value.currency, observed_at: started };
      notify();
    }).catch(function () { if (!stopped && generation === revision) invalidate(); }).then(function () {
      clearTimeout(timeout); running = false; activeController = null;
      if (pending) { pending = false; refresh(); }
    });
  }
  function changed() { invalidate(); refresh(); }
  window.ShopBonusCart = {
    getSnapshot: function () {
      return !stopped && !excluded() && document.visibilityState !== "hidden" && snapshot && Date.now() >= snapshot.observed_at && Date.now() - snapshot.observed_at <= 30000 ? Object.assign({}, snapshot) : null;
    },
    subscribe: function (fn) {
      if (typeof fn !== "function") return function () {};
      subscribers.push(fn);
      return function () { subscribers = subscribers.filter(function (value) { return value !== fn; }); };
    },
    refresh: changed,
  };
  if (attributionUrl) {
    // Only exact-format values are sent; an empty value clears what the store session holds (tracking withdrawn).
    window.ShopBonusCart.setAttribution = function (value) {
      var body = new URLSearchParams({ action: "shopbonus_attribution", nonce: attributionNonce });
      if (value && typeof value.assignment_token === "string" && /^bx_[a-f0-9]{64}$/.test(value.assignment_token)) body.set("assignment_token", value.assignment_token);
      if (value && typeof value.click_id === "string" && /^bc_[A-Za-z0-9]{24}$/.test(value.click_id)) body.set("click_id", value.click_id);
      return fetch(attributionUrl.href, { method: "POST", credentials: "same-origin", redirect: "error", body: body }).then(function (response) {
        if (!response.ok) throw new Error("attribution_not_stored");
      });
    };
  }
  window.addEventListener("shopbonus:cart-invalidated", changed);
  window.addEventListener("focus", changed);
  document.addEventListener("visibilitychange", changed);
  if (platform === "woocommerce") {
    // Classic WooCommerce and Blocks notify only after native cart operations.
    if (window.jQuery && document.body) window.jQuery(document.body).on("added_to_cart removed_from_cart updated_wc_div wc_fragments_refreshed", changed);
    document.addEventListener("wc-blocks_added_to_cart", changed);
    document.addEventListener("wc-blocks_removed_from_cart", changed);
  }
  if (platform === "prestashop" && window.prestashop && typeof window.prestashop.on === "function") {
    window.prestashop.on("updateCart", changed);
    window.prestashop.on("updatedCart", changed);
  }
  if (platform === "magento" && window.jQuery && document.body) {
    window.jQuery(document.body).on("ajax:addToCart ajax:removeFromCart", changed);
  }
  window.addEventListener("pagehide", function () {
    stopped = true; pending = false; invalidate();
    if (activeController) activeController.abort();
  });
  window.addEventListener("pageshow", function () { stopped = false; changed(); });
  setInterval(refresh, 10000);
  refresh();
})();
