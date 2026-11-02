/**
 * ShopBonus public banner embed (Trello #25 §3).
 * - Visitors: Shadow DOM renderer of published config only.
 * - Editors: postMessage pairing (origin/source/nonce/schema); never stores session tokens.
 * - Cart totals only via window.ShopBonusCart bridge; never invents progress.
 * - Webshop keeps working if the API is down.
 */
(function () {
  "use strict";

  var SCRIPT = document.currentScript;
  if (!SCRIPT) return;
  var KEY = SCRIPT.getAttribute("data-shopbonus-key") || "";
  if (!KEY || KEY.indexOf("pk_") !== 0) return;

  var API =
    SCRIPT.getAttribute("data-shopbonus-api") ||
    (function () {
      try {
        return new URL(SCRIPT.src).origin;
      } catch (e) {
        return "";
      }
    })();
  if (!API) return;

  var REDUCED =
    typeof matchMedia === "function" && matchMedia("(prefers-reduced-motion: reduce)").matches;
  var SESSION = "sb_banner_sess_" + KEY.slice(0, 12);
  var DISMISS = "sb_banner_dismiss_";
  var VISITOR = "sb_banner_vid";
  var ATTRIBUTION = "sb_banner_attr_" + KEY;
  var MSG_SCHEMA = "shopbonus.banner.v1";
  var host = null;
  var shadow = null;
  var campaigns = [];
  var trustedEditorOrigin = null;
  var configSignature = null;
  var configLoading = false;
  var editor = { source: null, expiresAt: 0, pairing: false, active: false, token: null, nonce: null, parentOrigin: null, campaignId: null };
  var shownIds = Object.create(null);
  var eventQueue = [];
  var flushTimer = null;
  var sending = false;
  var consentId = null;
  var consentUntil = 0;
  var visitor = null;
  var assignments = Object.create(null);
  var attribution = {};
  var lifecycle = 0;
  var memorySession = { shown: {} };

  function permitted() { return !!consentId && Date.now() < consentUntil; }

  // The host CMP supplies the opaque ID from the existing consent API, never a boolean.
  function cmpConsentId() {
    try {
      return window.ShopBonusConsent && window.ShopBonusConsent.getConsentId() || null;
    } catch (e) { return null; }
  }

  function bridgeAttribution(value) {
    attribution = value;
    if (permitted() && value.click_id) {
      try {
        var previous = JSON.parse(localStorage.getItem(ATTRIBUTION) || "null");
        var expiry = previous && previous.click_id === value.click_id ? previous.expires_at : Date.now() + 30 * 86400000;
        localStorage.setItem(ATTRIBUTION, JSON.stringify({ click_id: value.click_id, expires_at: expiry }));
      } catch (e) {}
    }
    var bridge = window.ShopBonusCart;
    if (bridge && typeof bridge.setAttribution === "function") {
      try { Promise.resolve(bridge.setAttribution(value)).catch(function () {}); } catch (e) {}
    }
    window.dispatchEvent(new CustomEvent("shopbonus:attribution", { detail: value }));
  }

  function revokeTracking() {
    var oldVisitor = visitor;
    consentId = null;
    consentUntil = 0;
    visitor = null;
    assignments = Object.create(null);
    eventQueue = [];
    if (flushTimer) clearTimeout(flushTimer);
    flushTimer = null;
    bridgeAttribution({});
    try {
      localStorage.removeItem(VISITOR);
      localStorage.removeItem(ATTRIBUTION);
      localStorage.removeItem("sb_returning");
      sessionStorage.removeItem(SESSION);
      Object.keys(localStorage).forEach(function (k) { if (k.indexOf(DISMISS) === 0) localStorage.removeItem(k); });
    } catch (e) {}
    if (oldVisitor) fetch(API + "/api/v1/banners/assignments", {
      method: "POST", credentials: "omit", keepalive: true,
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify({ key: KEY, visitor_key: oldVisitor, consent: false }),
    }).catch(function () {});
  }

  function refreshConsent() {
    var generation = ++lifecycle;
    var id = cmpConsentId();
    // Stop collection immediately while a CMP decision is being revalidated.
    consentUntil = 0;
    return fetch(API + "/api/v1/banners/config?key=" + encodeURIComponent(KEY) + "&consent_id=" + encodeURIComponent(id || ""), {
      credentials: "omit", cache: "no-store", headers: { Accept: "application/json" },
    }).then(function (r) { if (!r.ok) throw new Error("consent"); return r.json(); })
      .then(function (body) {
        if (generation !== lifecycle) return;
        if (!id || !body.data || !body.data.tracking_allowed) { revokeTracking(); return loadConfig(); }
        if (consentId && consentId !== id) revokeTracking();
        consentId = id;
        consentUntil = Date.now() + 60000;
        visitor = visitorKey();
        try {
          var saved = JSON.parse(localStorage.getItem(ATTRIBUTION) || "null");
          if (saved && saved.expires_at > Date.now()) attribution.click_id = saved.click_id;
          else delete attribution.click_id;
        } catch (e) {}
        return fetch(API + "/api/v1/banners/assignments", {
          method: "POST", credentials: "omit",
          headers: { Accept: "application/json", "Content-Type": "application/json" },
          body: JSON.stringify({ key: KEY, visitor_key: visitor, consent: permitted(), consent_id: consentId }),
        }).then(function (r) { if (!r.ok) throw new Error("assignment"); return r.json(); })
          .then(function (result) {
            if (generation !== lifecycle || !permitted()) return;
            assignments = Object.create(null);
            (result.data || []).forEach(function (a) { assignments[a.campaign] = a; });
            var first = (result.data || [])[0];
            bridgeAttribution(Object.assign({}, attribution, { assignment_token: first ? first.assignment_token : null }));
            return loadConfig();
          });
      }).catch(function () { if (generation === lifecycle) { revokeTracking(); loadConfig(); } });
  }

  function uuid() {
    if (crypto && crypto.randomUUID) return crypto.randomUUID();
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  function sessionStore() {
    if (!permitted()) return memorySession;
    try {
      var raw = sessionStorage.getItem(SESSION);
      return raw ? JSON.parse(raw) : { shown: {} };
    } catch (e) {
      return { shown: {} };
    }
  }

  function saveSession(s) {
    if (!permitted()) return;
    try {
      sessionStorage.setItem(SESSION, JSON.stringify(s));
    } catch (e) {}
  }

  function visitorKey() {
    if (!permitted()) return null;
    try {
      var v = localStorage.getItem(VISITOR);
      if (v) return v;
      v = uuid();
      localStorage.setItem(VISITOR, v);
      return v;
    } catch (e) {
      return uuid();
    }
  }

  function device() {
    var w = window.innerWidth || 1024;
    if (w < 768) return "mobile";
    if (w < 1024) return "tablet";
    return "desktop";
  }

  function pathOnly() {
    return (location.pathname || "/").split("?")[0].split("#")[0] || "/";
  }

  function excluded(path) {
    return /^\/(checkout|cart\/checkout|payment|payments|account|my-account|login|register|order-received)(\/|$)/i.test(
      path
    );
  }

  function matchUrl(pattern, path) {
    var re = new RegExp("^" + String(pattern).replace(/[.+?^${}()|[\]\\]/g, "\\$&").replace(/\*/g, ".*") + "$", "i");
    return re.test(path);
  }

  function cartSnapshot() {
    var b = window.ShopBonusCart;
    if (!b || typeof b.getSnapshot !== "function") return null;
    try {
      var n = b.getSnapshot();
      if (!n || n.schema !== "shopbonus.cart.v1" || !Number.isSafeInteger(n.total_minor) || n.total_minor < 0 ||
          !/^[A-Z]{3}$/.test(n.currency) || !Number.isFinite(n.observed_at) || n.observed_at > Date.now() || Date.now() - n.observed_at > 30000) return null;
      return n;
    } catch (e) {
      return null;
    }
  }

  function cartBridge() { var cart = cartSnapshot(); return cart ? cart.total_minor : null; }

  function updateProgress(el, item) {
    var cfg = item.config || {}, reward = item.reward || {};
    var cart = cartSnapshot(), progress = el.querySelector(".sb-progress"), bar = el.querySelector(".sb-bar");
    if (!progress || !bar) return;
    var valid = cart && cart.currency === reward.currency && Number.isSafeInteger(reward.threshold_minor) && reward.threshold_minor > 0;
    bar.style.display = valid ? "block" : "none";
    if (!valid) { progress.textContent = (cfg.content || {}).terms || "Reward terms apply on eligible orders."; return; }
    var format = function (minor) { var formatter = new Intl.NumberFormat(undefined, { style: "currency", currency: cart.currency }); return formatter.format(minor / Math.pow(10, formatter.resolvedOptions().maximumFractionDigits)); };
    bar.firstChild.style.width = Math.min(100, Math.floor(cart.total_minor * 100 / reward.threshold_minor)) + "%";
    progress.textContent = format(cart.total_minor) + " of " + format(reward.threshold_minor) + " · " + format(Math.max(0, reward.threshold_minor - cart.total_minor)) + " to go";
  }

  function updateCarts() {
    if (shadow) Array.prototype.forEach.call(shadow.querySelectorAll(".sb-widget"), function (el) {
      if (el.sbItem && el.sbItem.config.template === "cart_progress") updateProgress(el, el.sbItem);
    });
  }

  function evalRule(rule, ctx) {
    if (!rule || typeof rule !== "object") return false;
    if (rule.all) return rule.all.every(function (r) {
      return evalRule(r, ctx);
    });
    if (rule.any) return rule.any.some(function (r) {
      return evalRule(r, ctx);
    });
    var t = rule.type;
    var v = rule.value;
    var ok = false;
    if (t === "url_match") ok = matchUrl(v, ctx.path);
    else if (t === "category") ok = Array.isArray(v) && v.indexOf(ctx.category) !== -1;
    else if (t === "product_sku") ok = Array.isArray(v) && v.indexOf(ctx.sku) !== -1;
    else if (t === "min_seconds") ok = ctx.seconds >= v;
    else if (t === "min_scroll_pct") ok = ctx.scroll >= v;
    else if (t === "exit_intent") ok = !!ctx.exit && ctx.device === "desktop";
    else if (t === "device") ok = Array.isArray(v) && v.indexOf(ctx.device) !== -1;
    else if (t === "language") ok = Array.isArray(v) && v.indexOf(ctx.lang) !== -1;
    else if (t === "visitor") ok = ctx.visitor === v;
    else if (t === "cart_min_minor") ok = ctx.cart != null && ctx.cart >= v;
    else if (t === "weekday" || t === "hour_between") ok = true;
    if (rule.negate) ok = !ok;
    return ok;
  }

  function enqueue(ev) {
    if (!permitted() || eventQueue.length >= 200) return;
    eventQueue.push({ event: ev, attempts: 0, queuedAt: Date.now() });
    if (!flushTimer) flushTimer = setTimeout(flushEvents, 1200);
  }

  function flushEvents() {
    flushTimer = null;
    if (sending || !permitted()) return;
    eventQueue = eventQueue.filter(function (item) { return item.attempts < 5 && Date.now() - item.queuedAt < 120000; });
    if (!eventQueue.length) return;
    var entries = eventQueue.slice(0, 50);
    var batch = entries.map(function (item) { item.attempts++; return item.event; });
    var generation = lifecycle;
    sending = true;
    fetch(API + "/api/v1/banners/events", {
      method: "POST",
      credentials: "omit",
      keepalive: true,
      headers: { Accept: "application/json", "Content-Type": "application/json", Origin: location.origin },
      body: JSON.stringify({ key: KEY, events: batch }),
    }).then(function (r) {
      if (!r.ok) throw new Error("events " + r.status);
      return r.json();
    }).then(function (body) {
      if (generation !== lifecycle || !permitted()) return;
      eventQueue = eventQueue.filter(function (item) { return entries.indexOf(item) === -1; });
      var clicks = body.data && body.data.click_ids || {};
      Object.keys(clicks).forEach(function (id) { bridgeAttribution(Object.assign({}, attribution, { click_id: clicks[id] })); });
    }).catch(function () {}).then(function () {
      sending = false;
      if (permitted() && eventQueue.length && !flushTimer) flushTimer = setTimeout(flushEvents, Math.min(16000, 1000 * Math.pow(2, entries[0].attempts)));
    });
  }

  function track(campaign, version, type) {
    if (editor.active || !permitted()) return;
    enqueue({
      id: uuid(),
      campaign: campaign,
      version: version,
      type: type,
      occurred_at: new Date().toISOString(),
      device: device(),
      path: pathOnly(),
      category: (window.ShopBonusPage && window.ShopBonusPage.category) || null,
      consent: permitted(),
      consent_id: consentId,
      assignment_token: assignments[campaign] && assignments[campaign].assignment_token || null,
    });
  }

  function ensureHost() {
    if (host) return;
    host = document.createElement("div");
    host.id = "shopbonus-banner-root";
    host.setAttribute("data-shopbonus", "embed");
    host.style.cssText = "all:initial;position:fixed;inset:0;pointer-events:none;z-index:2147483000;";
    document.documentElement.appendChild(host);
    shadow = host.attachShadow({ mode: "open" });
    var style = document.createElement("style");
    style.textContent =
      ":host{all:initial}*{box-sizing:border-box;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}" +
      ".sb-widget{pointer-events:auto;position:fixed;max-width:92vw;width:360px;border-radius:16px;padding:14px 16px;box-shadow:0 12px 40px rgba(15,34,71,.22);line-height:1.35}" +
      ".sb-widget *{font-family:inherit}.sb-ribbon{left:0;right:0;top:0;max-width:none;border-radius:0;text-align:center;padding:10px 40px}" +
      ".sb-heading{font-weight:800;font-size:15px;margin:0 0 4px}.sb-sub{font-size:13px;opacity:.92;margin:0 0 8px}" +
      ".sb-cta{display:inline-block;margin-top:6px;padding:8px 14px;border-radius:10px;font-weight:700;font-size:13px;text-decoration:none;background:#fff;color:inherit}" +
      ".sb-terms{display:block;margin-top:8px;font-size:11px;opacity:.75}.sb-x{position:absolute;top:6px;right:8px;border:0;background:transparent;color:inherit;font-size:18px;cursor:pointer;line-height:1;opacity:.7}" +
      ".sb-bar{height:8px;border-radius:99px;background:rgba(255,255,255,.28);overflow:hidden;margin-top:8px}.sb-bar>i{display:block;height:100%;background:#fff}" +
      ".sb-progress{font-size:12px;margin-top:6px;opacity:.9}" +
      ".sb-fade{animation:sbf .35s ease}.sb-slide{animation:sbs .35s ease}" +
      "@keyframes sbf{from{opacity:0}to{opacity:1}}@keyframes sbs{from{transform:translateY(12px);opacity:0}to{transform:none;opacity:1}}" +
      "@media (prefers-reduced-motion:reduce){.sb-fade,.sb-slide{animation:none}}" +
      ".sb-edit{outline:2px dashed #0b74ff;outline-offset:3px;cursor:move}.sb-handle{position:absolute;width:10px;height:10px;background:#0b74ff;border:2px solid #fff;border-radius:50%;pointer-events:auto}" +
      ".sb-warn{position:fixed;left:12px;bottom:12px;background:#fff;color:#0f2247;padding:10px 12px;border-radius:10px;font-size:12px;box-shadow:0 8px 24px rgba(0,0,0,.15);pointer-events:auto;max-width:280px}";
    shadow.appendChild(style);
  }

  function anchorCss(anchor, ox, oy) {
    var o = "transform:translate(" + (ox || 0) + "px," + (oy || 0) + "px);";
    switch (anchor) {
      case "top":
        return "top:0;left:0;right:0;" + o;
      case "bottom":
        return "bottom:0;left:0;right:0;" + o;
      case "top_left":
        return "top:16px;left:16px;" + o;
      case "top_right":
        return "top:16px;right:16px;" + o;
      case "bottom_left":
        return "bottom:16px;left:16px;" + o;
      case "bottom_right":
        return "bottom:16px;right:16px;" + o;
      default:
        return "bottom:20px;right:20px;" + o;
    }
  }

  function renderWidget(item, opts) {
    ensureHost();
    var cfg = item.config || {};
    var content = cfg.content || {};
    var style = cfg.style || {};
    var place = cfg.placement || {};
    var el = document.createElement("div");
    el.className =
      "sb-widget" +
      (cfg.template === "ribbon" ? " sb-ribbon" : "") +
      (REDUCED || style.animation === "none" ? "" : style.animation === "slide_in" ? " sb-slide" : " sb-fade");
    el.setAttribute("role", "dialog");
    el.setAttribute("aria-label", content.heading || "ShopBonus");
    el.style.cssText =
      anchorCss(place.anchor || "bottom_right", place.offset_x, place.offset_y) +
      "background:" +
      (style.background || "#0b74ff") +
      ";color:" +
      (style.text || "#ffffff") +
      ";";
    var fonts = { system: "system-ui,sans-serif", inter: "Inter,sans-serif", roboto: "Roboto,sans-serif", open_sans: "Open Sans,sans-serif", lato: "Lato,sans-serif", montserrat: "Montserrat,sans-serif" };
    el.style.fontFamily = fonts[style.font] || fonts.system;
    if (Number.isInteger(style.radius)) el.style.borderRadius = Math.max(0, Math.min(32, style.radius)) + "px";
    if (opts && opts.editing) { el.classList.add("sb-edit"); el.style.touchAction = "none"; }

    var close = document.createElement("button");
    close.className = "sb-x";
    close.type = "button";
    close.setAttribute("aria-label", "Close");
    close.textContent = "×";
    close.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      try {
        if (!(opts && opts.preview) && permitted()) localStorage.setItem(DISMISS + item.campaign, "1");
      } catch (err) {}
      el.remove();
      if (!(opts && opts.preview)) track(item.campaign, item.version, "dismiss");
      postParent({ type: "banner.dismissed", campaign: item.campaign });
    });
    el.appendChild(close);

    var imageUrl = content.image_url || (content.image_asset_id && /^[a-zA-Z0-9]{1,40}$/.test(content.image_asset_id) ? API + "/api/v1/banners/assets/" + content.image_asset_id : null);
    if (imageUrl && /^https?:\/\//i.test(imageUrl)) {
      var img = document.createElement("img"); img.src = imageUrl; img.alt = ""; img.style.cssText = "max-width:100%;height:auto;border-radius:8px"; el.appendChild(img);
    }
    if (Number.isInteger(place.width)) el.style.width = Math.max(160, Math.min(720, place.width)) + "px";
    if (Number.isInteger(place.height)) el.style.minHeight = Math.max(80, Math.min(800, place.height)) + "px";
    var h = document.createElement("p");
    h.className = "sb-heading";
    h.textContent = content.heading || "";
    el.appendChild(h);
    if (content.subtitle) {
      var s = document.createElement("p");
      s.className = "sb-sub";
      s.textContent = content.subtitle;
      el.appendChild(s);
    }

    el.sbItem = item;
    if (cfg.template === "cart_progress") {
      var bar = document.createElement("div"); bar.className = "sb-bar";
      bar.appendChild(document.createElement("i")); el.appendChild(bar);
      var progress = document.createElement("div"); progress.className = "sb-progress"; el.appendChild(progress);
      updateProgress(el, item);
    }

    if (content.cta_label && /^https:\/\//i.test(content.cta_url || "")) {
      var a = document.createElement("a");
      a.className = "sb-cta";
      a.href = content.cta_url;
      a.style.color = /^#[0-9a-f]{6}$/i.test(style.background || "") ? style.background : "#0b74ff";
      a.rel = "noopener noreferrer";
      a.target = "_blank";
      a.textContent = content.cta_label;
      a.addEventListener("click", function () {
        if (!(opts && opts.preview)) { track(item.campaign, item.version, "cta_click"); flushEvents(); }
        postParent({ type: "banner.cta", campaign: item.campaign });
      });
      el.appendChild(a);
    }
    if (content.terms && cfg.template !== "cart_progress") {
      var t = document.createElement("span");
      t.className = "sb-terms";
      t.textContent = content.terms;
      el.appendChild(t);
    }

    if (Array.isArray(cfg.layers)) cfg.layers.forEach(function (layer) {
      var wrapper = document.createElement("div");
      wrapper.style.cssText = "display:" + (layer.visible ? "block" : "none") + ";text-align:" + (["left", "center", "right"].indexOf(layer.align) >= 0 ? layer.align : "left") + ";width:" + Math.max(20, Math.min(100, layer.width_pct || 100)) + "%;font-size:" + Math.max(10, Math.min(40, layer.font_size || 16)) + "px";
      var nodes = layer.type === "text" ? [h, s] : layer.type === "image" ? [img] : layer.type === "cta" ? [a] : [];
      nodes.forEach(function (node) { if (node) { if (layer.type !== "image") node.style.fontSize = "inherit"; wrapper.appendChild(node); } });
      el.appendChild(wrapper);
    });
    if (opts && opts.editing) {
      ["nw", "ne", "sw", "se"].forEach(function (corner, i) {
        var hdl = document.createElement("span");
        hdl.className = "sb-handle";
        hdl.setAttribute("data-corner", corner);
        hdl.style.cursor = corner + "-resize";
        hdl.style.cssText =
          (i < 2 ? "top:-5px;" : "bottom:-5px;") + (i % 2 === 0 ? "left:-5px;" : "right:-5px;");
        el.appendChild(hdl);
      });
      enableDrag(el, item);
    }

    el.tabIndex = 0;
    el.addEventListener("keydown", function (e) {
      if (e.key === "Escape") close.click();
    });

    if (place.anchor === "inline") {
      var target = null;
      try { target = place.selector_hint && document.querySelector(place.selector_hint); } catch (e) {}
      if (!target) {
        if (opts && opts.editing) postParent({ type: "banner.target_missing", campaign: item.campaign });
        return el;
      }
      el.sbTarget = target;
      var position = function () {
        if (!target.isConnected) { el.style.display = "none"; postParent({ type: "banner.target_missing", campaign: item.campaign }); return; }
        var rect = target.getBoundingClientRect(); el.style.left = rect.left + "px"; el.style.top = rect.bottom + "px"; el.style.right = "auto"; el.style.bottom = "auto";
      };
      position(); el.sbPosition = position;
    }
    shadow.appendChild(el);
    observeViewable(el, item, opts);
    return el;
  }

  function enableDrag(el, item) {
    var gesture = null;
    el.addEventListener("pointerdown", function (e) {
      if (e.target.closest && e.target.closest("button,a")) return;
      e.preventDefault();
      var rect = el.getBoundingClientRect(), place = item.config.placement || {};
      gesture = { x: e.clientX, y: e.clientY, ox: place.offset_x || 0, oy: place.offset_y || 0,
        width: rect.width, height: rect.height, resize: e.target.classList && e.target.classList.contains("sb-handle"),
        west: e.target.getAttribute && e.target.getAttribute("data-corner") && e.target.getAttribute("data-corner").indexOf("w") >= 0,
        north: e.target.getAttribute && e.target.getAttribute("data-corner") && e.target.getAttribute("data-corner").indexOf("n") >= 0 };
      el.setPointerCapture(e.pointerId);
    });
    el.addEventListener("pointermove", function (e) {
      if (!gesture || !el.hasPointerCapture(e.pointerId)) return;
      gesture.lastX = e.clientX; gesture.lastY = e.clientY;
      var dx = e.clientX - gesture.x, dy = e.clientY - gesture.y;
      if (gesture.resize) {
        el.style.width = Math.max(160, Math.min(720, gesture.width + dx * (gesture.west ? -1 : 1))) + "px";
        el.style.minHeight = Math.max(80, Math.min(800, gesture.height + dy * (gesture.north ? -1 : 1))) + "px";
      } else el.style.transform = "translate(" + Math.max(-500, Math.min(500, gesture.ox + dx)) + "px," + Math.max(-500, Math.min(500, gesture.oy + dy)) + "px)";
    });
    var finish = function (e) {
      if (!gesture) return;
      var g = gesture; gesture = null;
      var place = item.config.placement || {}, rect = el.getBoundingClientRect();
      if (g.resize) {
        postParent({ type: "banner.resized", campaign: item.campaign, width: Math.round(rect.width), height: Math.round(rect.height) });
      } else {
        var x = Math.round(Math.max(-500, Math.min(500, g.ox + e.clientX - g.x)));
        var y = Math.round(Math.max(-500, Math.min(500, g.oy + e.clientY - g.y)));
        var nearLeft = Math.abs(rect.left - 16) <= 24, nearRight = Math.abs(window.innerWidth - rect.right - 16) <= 24;
        var nearTop = Math.abs(rect.top - 16) <= 24, nearBottom = Math.abs(window.innerHeight - rect.bottom - 16) <= 24;
        var anchor = place.anchor;
        if ((nearLeft || nearRight) && (nearTop || nearBottom)) { anchor = (nearTop ? "top_" : "bottom_") + (nearLeft ? "left" : "right"); x = 0; y = 0; }
        postParent({ type: "banner.moved", campaign: item.campaign, anchor: anchor, offset_x: x, offset_y: y });
      }
    };
    el.sbFinish = function () { if (gesture) finish({ clientX: gesture.lastX || gesture.x, clientY: gesture.lastY || gesture.y }); };
    el.addEventListener("pointerup", finish);
    el.addEventListener("lostpointercapture", function () {
      if (gesture) finish({ clientX: gesture.lastX || gesture.x, clientY: gesture.lastY || gesture.y });
    });
    el.addEventListener("pointerout", function (e) {
      if (gesture && !e.relatedTarget) finish({ clientX: gesture.lastX || gesture.x, clientY: gesture.lastY || gesture.y });
    });
    el.addEventListener("pointercancel", function () {
      if (gesture) finish({ clientX: gesture.lastX || gesture.x, clientY: gesture.lastY || gesture.y });
    });
  }

  function observeViewable(el, item, opts) {
    if (opts && opts.preview) return;
    el.setAttribute("data-viewability", "pending");
    if (!("IntersectionObserver" in window)) {
      el.setAttribute("data-viewability", "unsupported");
      // Unsupported viewability is unknown, never an impression.
      return;
    }
    var seen = false;
    var timer = null;
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.intersectionRatio >= 0.5 && document.visibilityState === "visible" && el.isConnected) {
            if (!timer)
              timer = setTimeout(function () {
                if (!seen && document.visibilityState === "visible" && el.isConnected) {
                  seen = true;
                  el.setAttribute("data-viewability", "verified");
                  track(item.campaign, item.version, "viewable_impression");
                  io.disconnect();
                }
              }, 1000);
          } else if (timer) {
            clearTimeout(timer);
            timer = null;
          }
        });
      },
      { threshold: [0.5] }
    );
    io.observe(el);
    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState !== "visible" && timer) { clearTimeout(timer); timer = null; }
    });
  }

  function clearWidgets() {
    if (!shadow) return;
    Array.prototype.slice.call(shadow.querySelectorAll(".sb-widget,.sb-warn")).forEach(function (n) {
      n.remove();
    });
  }

  function context() {
    var page = window.ShopBonusPage || {};
    var sess = sessionStore();
    var start = Number(sess.startedAt || Date.now());
    if (!sess.startedAt) {
      sess.startedAt = start;
      saveSession(sess);
    }
    var scroll = 0;
    try {
      var doc = document.documentElement;
      scroll = Math.min(100, Math.round(((window.scrollY || 0) / Math.max(1, doc.scrollHeight - window.innerHeight)) * 100));
    } catch (e) {}
    return {
      path: pathOnly(),
      category: page.category || null,
      sku: page.sku || null,
      seconds: Math.floor((Date.now() - start) / 1000),
      scroll: scroll,
      exit: false,
      device: device(),
      lang: (document.documentElement.lang || navigator.language || "nl").slice(0, 5),
      visitor: (function () {
        try {
          return permitted() ? (localStorage.getItem("sb_returning") ? "returning" : "first") : "unknown";
        } catch (e) {
          return "first";
        }
      })(),
      cart: cartBridge(),
    };
  }

  function maybeShow(item) {
    if (editor.active && editor.campaignId && item.campaign !== editor.campaignId) return;
    if (shownIds[item.campaign]) return;
    var cfg = item.config || {};
    var ctx = context();
    if (excluded(ctx.path)) return;
    if ((cfg.devices || []).indexOf(ctx.device) === -1) return;
    try {
      if (permitted() && localStorage.getItem(DISMISS + item.campaign)) return;
    } catch (e) {}
    var sess = sessionStore();
    var count = (sess.shown && sess.shown[item.campaign]) || 0;
    var per = (cfg.frequency && cfg.frequency.per_session) || 1;
    if (count >= per && !(editor.active && editor.campaignId === item.campaign)) return;
    if (cfg.triggers && !evalRule(cfg.triggers, ctx) && !(editor.active && editor.campaignId === item.campaign)) return;

    shownIds[item.campaign] = true;
    sess.shown = sess.shown || {};
    sess.shown[item.campaign] = count + 1;
    saveSession(sess);
    try {
      if (permitted()) localStorage.setItem("sb_returning", "1");
    } catch (e) {}

    track(item.campaign, item.version, "eligible");
    renderWidget(item, { editing: editor.active && editor.campaignId === item.campaign });
  }

  function loadConfig() {
    if (configLoading) return Promise.resolve();
    configLoading = true;
    var generation = lifecycle;
    var query = "";
    if (permitted()) Object.keys(assignments).forEach(function (campaign) {
      query += "&assignments[" + encodeURIComponent(campaign) + "]=" + encodeURIComponent(assignments[campaign].assignment_token);
    });
    return fetch(API + "/api/v1/banners/config?key=" + encodeURIComponent(KEY) + query, {
      credentials: "omit", cache: "no-cache",
      headers: { Accept: "application/json", Origin: location.origin },
    })
      .then(function (r) {
        if (r.status === 304) return null;
        if (!r.ok) throw new Error("config " + r.status);
        return r.json();
      })
      .then(function (body) {
        if (generation !== lifecycle || !body || !body.data) return;
        trustedEditorOrigin = body.data.editor_origin || null;
        var signature = JSON.stringify(body.data.campaigns || []);
        if (configSignature === signature || editor.active) return;
        var visible = Object.create(null);
        var next = body.data.campaigns || [];
        if (shadow) Array.prototype.forEach.call(shadow.querySelectorAll(".sb-widget"), function (el) {
          if (!el.sbItem) return;
          var replacement = next.find(function (c) { return c.campaign === el.sbItem.campaign; });
          visible[el.sbItem.campaign] = true;
          if (!replacement || JSON.stringify(replacement) !== JSON.stringify(el.sbItem)) el.remove();
          else visible[el.sbItem.campaign] = el;
        });
        configSignature = signature;
        campaigns = next;
        campaigns.forEach(function (item) {
          if (visible[item.campaign] === true) renderWidget(item);
          else if (!visible[item.campaign]) maybeShow(item);
        });
      })
      .catch(function () {
        /* graceful outage */
      }).then(function () { configLoading = false; });
  }

  function postParent(payload) {
    if (!editor.parentOrigin || !editor.source) return;
    try {
      editor.source.postMessage(
        Object.assign({ schema: MSG_SCHEMA, nonce: editor.nonce, key: KEY }, payload),
        editor.parentOrigin
      );
    } catch (e) {}
  }

  function onMessage(ev) {
    var data = ev.data;
    if (!data || data.schema !== MSG_SCHEMA || data.key !== KEY) return;
    if (typeof data.nonce !== "string" || !data.nonce) return;

    if (ev.origin !== trustedEditorOrigin || (ev.source !== window.opener && ev.source !== window.parent) || ev.source === window) return;
    if (data.type === "editor.handshake") {
      if (editor.active || editor.pairing) return;
      editor.source = ev.source;
      editor.parentOrigin = ev.origin;
      editor.nonce = data.nonce;
      postParent({ type: "editor.ready", origin: location.origin });
      return;
    }

    if (!editor.parentOrigin || ev.origin !== editor.parentOrigin || ev.source !== editor.source || data.nonce !== editor.nonce) return;
    if (editor.active && Date.now() >= editor.expiresAt) {
      editor.active = false; editor.token = null; clearWidgets(); configSignature = null; loadConfig();
      postParent({ type: "editor.expired" }); return;
    }

    if (data.type === "editor.pointer_end" && editor.active && data.campaign === editor.campaignId) {
      if (shadow) Array.prototype.forEach.call(shadow.querySelectorAll(".sb-widget"), function (el) { if (el.sbFinish) el.sbFinish(); });
      return;
    }
    if (data.type === "editor.disconnect" && editor.active) {
      editor.active = false; editor.token = null; clearWidgets(); configSignature = null; shownIds = Object.create(null); loadConfig(); return;
    }
    if (data.type === "editor.pair" && data.pairing_token && !editor.active && !editor.pairing) {
      editor.pairing = true;
      var pairNonce = editor.nonce;
      fetch(API + "/api/v1/banners/editor/pair", {
        method: "POST",
        credentials: "omit",
        headers: { Accept: "application/json", "Content-Type": "application/json", Origin: location.origin },
        body: JSON.stringify({ pairing_token: data.pairing_token }),
      })
        .then(function (r) {
          return r.json().then(function (j) {
            return { ok: r.ok, j: j, status: r.status };
          });
        })
        .then(function (res) {
          if (pairNonce !== editor.nonce) return;
          editor.pairing = false;
          if (!res.ok) {
            postParent({ type: "editor.pair_failed", status: res.status, code: (res.j.error && res.j.error.code) || "pairing_invalid", request_id: res.j.error && res.j.error.request_id });
            return;
          }
          editor.token = res.j.data.editor_token;
          editor.active = true;
          editor.expiresAt = Date.now() + res.j.data.expires_in * 1000;
          editor.campaignId = data.campaign || null;
          if (editor.campaignId) loadEditorDraft(editor.campaignId);
        })
        .catch(function () {
          editor.pairing = false;
          postParent({ type: "editor.pair_failed", status: 0, code: "network_error" });
        });
      return;
    }

    if (data.type === "editor.load_draft" && data.campaign === editor.campaignId && editor.active && editor.token) {
      editor.campaignId = data.campaign;
      loadEditorDraft(data.campaign);
      return;
    }

    if (data.type === "editor.apply_config" && data.campaign === editor.campaignId && editor.active && editor.token && data.config) {
      if (excluded(pathOnly())) return;
      clearWidgets();
      renderWidget({ campaign: data.campaign || "draft", version: 0, config: data.config, reward: editor.reward }, { editing: true, preview: true, thresholdMinor: data.threshold_minor || null });
      return;
    }

    if (data.type === "editor.ping") {
      postParent({ type: "editor.pong", health: "ok", campaigns: campaigns.length });
    }
  }

  function loadEditorDraft(campaignId) {
    var draftNonce = editor.nonce;
    fetch(API + "/api/v1/banners/editor/campaigns/" + encodeURIComponent(campaignId), {
      credentials: "omit",
      headers: {
        Accept: "application/json",
        Origin: location.origin,
        "X-Banner-Editor-Token": editor.token,
      },
    })
      .then(function (r) {
        return r.json().then(function (j) {
          return { ok: r.ok, j: j, status: r.status };
        });
      })
      .then(function (res) {
        if (draftNonce !== editor.nonce || Date.now() >= editor.expiresAt) return;
        if (!res.ok) {
          editor.active = false; editor.token = null;
          postParent({ type: "editor.draft_failed", status: res.status, code: (res.j.error && res.j.error.code) || "error", request_id: res.j.error && res.j.error.request_id });
          return;
        }
        clearWidgets();
        editor.reward = res.j.data.reward || null;
        renderWidget(
          { campaign: res.j.data.id, version: 0, config: res.j.data.draft_config, reward: editor.reward },
          { editing: true, preview: true }
        );
        postParent({ type: "editor.paired", expires_in: Math.max(0, Math.floor((editor.expiresAt - Date.now()) / 1000)) });
        postParent({ type: "editor.draft", draft_revision: res.j.data.draft_revision, draft_config: res.j.data.draft_config });
      })
      .catch(function () {
        editor.active = false; editor.token = null;
        postParent({ type: "editor.draft_failed", status: 0, code: "network_error" });
      });
  }

  var exitArmed = false;
  document.addEventListener("mouseout", function (e) {
    if (!e.relatedTarget && e.clientY < 10) {
      exitArmed = true;
      campaigns.forEach(function (c) {
        if ((c.config || {}).template === "exit_intent") maybeShow(c);
      });
    }
  });

  window.addEventListener("message", onMessage);
  window.addEventListener("shopbonus:consent-changed", refreshConsent);
  window.addEventListener("focus", refreshConsent);
  window.addEventListener("pagehide", flushEvents);
  window.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") flushEvents();
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", refreshConsent);
  } else {
    refreshConsent();
  }

  setInterval(function () {
    if (editor.active && Date.now() >= editor.expiresAt) {
      editor.active = false; editor.token = null; clearWidgets(); configSignature = null;
      postParent({ type: "editor.expired" }); loadConfig();
    }
    updateCarts();
    if (shadow) Array.prototype.forEach.call(shadow.querySelectorAll(".sb-widget"), function (el) { if (el.sbPosition) el.sbPosition(); });
    if (!editor.active) campaigns.forEach(maybeShow);
  }, 4000);
  setInterval(refreshConsent, 55000);
  setInterval(function () { if (!editor.active && document.visibilityState !== "hidden") loadConfig(); }, 15000);
  window.addEventListener("shopbonus:cart-changed", updateCarts);
  if (window.ShopBonusCart && typeof window.ShopBonusCart.subscribe === "function") {
    try { window.ShopBonusCart.subscribe(updateCarts); } catch (e) {}
  }

  window.ShopBonusEmbed = {
    reload: refreshConsent,
    getAttribution: function () { return permitted() ? Object.assign({}, attribution) : {}; },
    version: "1.0.0",
    key: KEY,
  };
})();
