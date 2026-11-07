/** Opt-in Shopify theme cart reader. Load before embed/v1.js. Writes only cart attributes, and only with data-persist-attribution="true". */
(function () {
  "use strict";
  if (window.ShopBonusCart || !window.Shopify || !window.Shopify.routes || typeof AbortController !== "function") return;
  var root;
  try {
    root = new URL(window.Shopify.routes.root, location.origin);
    if (root.origin !== location.origin || root.search || root.hash || !root.pathname.endsWith("/")) return;
  } catch (e) { return; }
  var storefrontPath = location.pathname || "/";
  if (storefrontPath.indexOf(root.pathname) === 0) storefrontPath = storefrontPath.slice(root.pathname.length - 1);
  if (/^\/(checkout|checkouts|payment|payments|account|my-account|login|register|order-received)(\/|$)/i.test(storefrontPath)) return;

  var persistAttribution = !!(document.currentScript && document.currentScript.getAttribute("data-persist-attribution") === "true");
  // Only verified two-decimal presentment currencies. Other precisions fail closed.
  var currencies = ["EUR", "USD", "GBP", "CAD", "AUD", "NZD", "CHF", "SEK", "NOK", "DKK", "PLN", "CZK"];
  var snapshot = null, subscribers = [], running = false, pending = false, stopped = false;
  var revision = 0, activeController = null;
  function notify() {
    subscribers.slice().forEach(function (fn) { try { fn(); } catch (e) {} });
    window.dispatchEvent(new CustomEvent("shopbonus:cart-changed"));
  }
  function invalidate() { snapshot = null; revision++; notify(); }
  function refresh() {
    if (stopped || document.visibilityState === "hidden") return;
    if (running) { pending = true; return; }
    running = true;
    var generation = revision, started = Date.now(), controller = new AbortController();
    activeController = controller;
    var timeout = setTimeout(function () { controller.abort(); invalidate(); }, 5000);
    fetch(new URL("cart.js", root).href, {
      credentials: "same-origin", cache: "no-store", redirect: "error",
      headers: { Accept: "application/json" }, signal: controller.signal,
    }).then(function (response) {
      if (!response.ok) throw new Error("cart_unavailable");
      return response.json();
    }).then(function (cart) {
      if (stopped || controller.signal.aborted || generation !== revision) return;
      if (!cart || !Number.isSafeInteger(cart.total_price) || cart.total_price < 0 || currencies.indexOf(cart.currency) < 0) throw new Error("cart_invalid");
      snapshot = { schema: "shopbonus.cart.v1", total_minor: cart.total_price, currency: cart.currency, observed_at: started };
      notify();
    }).catch(function () { if (!stopped && generation === revision) invalidate(); }).then(function () {
      clearTimeout(timeout);
      running = false;
      activeController = null;
      if (pending) { pending = false; refresh(); }
    });
  }
  function changed() { invalidate(); refresh(); }
  window.ShopBonusCart = {
    getSnapshot: function () {
      return snapshot && Date.now() >= snapshot.observed_at && Date.now() - snapshot.observed_at <= 30000 ? Object.assign({}, snapshot) : null;
    },
    subscribe: function (fn) {
      if (typeof fn !== "function") return function () {};
      subscribers.push(fn);
      return function () { subscribers = subscribers.filter(function (value) { return value !== fn; }); };
    },
    refresh: changed,
  };
  if (persistAttribution) {
    // Documented Ajax Cart API cart attributes (POST cart/update.js {attributes}); ShopBonus reads them back as the
    // order's note_attributes / customAttributes. Clearing with "" on withdrawal is not documented by Shopify and must
    // be verified on the merchant's store, as must whether the theme/checkout shows these attributes to shoppers.
    window.ShopBonusCart.setAttribution = function (value) {
      var token = value && typeof value.assignment_token === "string" && /^bx_[a-f0-9]{64}$/.test(value.assignment_token) ? value.assignment_token : "";
      var click = value && typeof value.click_id === "string" && /^bc_[A-Za-z0-9]{24}$/.test(value.click_id) ? value.click_id : "";
      return fetch(new URL("cart/update.js", root).href, {
        method: "POST", credentials: "same-origin", redirect: "error",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ attributes: { shopbonus_assignment_token: token, shopbonus_click_id: click } }),
      }).then(function (response) {
        if (!response.ok) throw new Error("attribution_not_stored");
        changed();
      });
    };
  }
  // Theme code dispatches this only AFTER its native cart mutation has completed.
  window.addEventListener("shopbonus:cart-invalidated", changed);
  window.addEventListener("focus", changed);
  document.addEventListener("visibilitychange", changed);
  window.addEventListener("pagehide", function () {
    stopped = true; pending = false; invalidate();
    if (activeController) activeController.abort();
  });
  window.addEventListener("pageshow", function () { stopped = false; changed(); });
  setInterval(refresh, 10000);
  refresh();
})();
