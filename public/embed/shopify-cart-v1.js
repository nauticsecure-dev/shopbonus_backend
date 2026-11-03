/** Opt-in Shopify theme cart reader. Load before embed/v1.js; never writes to the cart. */
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
