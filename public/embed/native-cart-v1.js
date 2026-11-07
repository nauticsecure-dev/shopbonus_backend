/** Opt-in WooCommerce Store API / BigCommerce Stencil cart reader. Load before v1.js. */
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
    } else return;
    if (endpoint.origin !== location.origin || endpoint.username || endpoint.password || endpoint.hash) return;
  } catch (e) { return; }
  function excluded() {
    return /(?:^|\/)(checkout|checkouts|checkout\.php|payment|payments|account|account\.php|my-account|login|login\.php|register|order-received)(\/|$)/i.test(location.pathname);
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
  window.addEventListener("pagehide", function () {
    stopped = true; pending = false; invalidate();
    if (activeController) activeController.abort();
  });
  window.addEventListener("pageshow", function () { stopped = false; changed(); });
  setInterval(refresh, 10000);
  refresh();
})();
