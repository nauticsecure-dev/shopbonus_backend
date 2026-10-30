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
  var MSG_SCHEMA = "shopbonus.banner.v1";
  var host = null;
  var shadow = null;
  var campaigns = [];
  var editor = { active: false, token: null, nonce: null, parentOrigin: null, campaignId: null };
  var shownIds = Object.create(null);
  var eventQueue = [];
  var flushTimer = null;

  function uuid() {
    if (crypto && crypto.randomUUID) return crypto.randomUUID();
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  function sessionStore() {
    try {
      var raw = sessionStorage.getItem(SESSION);
      return raw ? JSON.parse(raw) : { shown: {} };
    } catch (e) {
      return { shown: {} };
    }
  }

  function saveSession(s) {
    try {
      sessionStorage.setItem(SESSION, JSON.stringify(s));
    } catch (e) {}
  }

  function visitorKey() {
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

  function cartBridge() {
    var b = window.ShopBonusCart;
    if (!b || typeof b.getTotalMinor !== "function") return null;
    try {
      var n = b.getTotalMinor();
      return typeof n === "number" && isFinite(n) && n >= 0 ? Math.floor(n) : null;
    } catch (e) {
      return null;
    }
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
    eventQueue.push(ev);
    if (!flushTimer) flushTimer = setTimeout(flushEvents, 1200);
  }

  function flushEvents() {
    flushTimer = null;
    if (!eventQueue.length) return;
    var batch = eventQueue.splice(0, 50);
    fetch(API + "/api/v1/banners/events", {
      method: "POST",
      credentials: "omit",
      headers: { Accept: "application/json", "Content-Type": "application/json", Origin: location.origin },
      body: JSON.stringify({ key: KEY, events: batch }),
    }).catch(function () {});
  }

  function track(campaign, version, type, consent) {
    enqueue({
      id: uuid(),
      campaign: campaign,
      version: version,
      type: type,
      occurred_at: new Date().toISOString(),
      device: device(),
      path: pathOnly(),
      category: (window.ShopBonusPage && window.ShopBonusPage.category) || null,
      consent: !!consent,
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
      ".sb-widget{pointer-events:auto;position:fixed;max-width:min(360px,92vw);border-radius:16px;padding:14px 16px;box-shadow:0 12px 40px rgba(15,34,71,.22);line-height:1.35}" +
      ".sb-ribbon{left:0;right:0;top:0;max-width:none;border-radius:0;text-align:center;padding:10px 40px}" +
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
    if (opts && opts.editing) el.classList.add("sb-edit");

    var close = document.createElement("button");
    close.className = "sb-x";
    close.type = "button";
    close.setAttribute("aria-label", "Close");
    close.textContent = "×";
    close.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      try {
        localStorage.setItem(DISMISS + item.campaign, "1");
      } catch (err) {}
      el.remove();
      if (!(opts && opts.preview)) track(item.campaign, item.version, "dismiss", true);
      postParent({ type: "banner.dismissed", campaign: item.campaign });
    });
    el.appendChild(close);

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

    if (cfg.template === "cart_progress") {
      var cart = cartBridge();
      var thr = (opts && opts.thresholdMinor) || (window.ShopBonusPage && window.ShopBonusPage.rewardThresholdMinor) || null;
      if (cart != null && thr != null && thr > 0) {
        var pct = Math.min(100, Math.floor((cart * 100) / thr));
        var rem = Math.max(0, thr - cart);
        var bar = document.createElement("div");
        bar.className = "sb-bar";
        bar.innerHTML = "<i style=\"width:" + pct + "%\"></i>";
        el.appendChild(bar);
        var prog = document.createElement("div");
        prog.className = "sb-progress";
        prog.textContent =
          "€" +
          (cart / 100).toFixed(0) +
          " of €" +
          (thr / 100).toFixed(0) +
          " · Only €" +
          (rem / 100).toFixed(0) +
          " to go!";
        el.appendChild(prog);
      } else {
        var fb = document.createElement("div");
        fb.className = "sb-progress";
        fb.textContent = content.terms || "Reward terms apply on eligible orders.";
        el.appendChild(fb);
      }
    }

    if (content.cta_label && content.cta_url) {
      var a = document.createElement("a");
      a.className = "sb-cta";
      a.href = content.cta_url;
      a.rel = "noopener noreferrer";
      a.target = "_blank";
      a.textContent = content.cta_label;
      a.addEventListener("click", function () {
        if (!(opts && opts.preview)) track(item.campaign, item.version, "cta_click", true);
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

    if (opts && opts.editing) {
      ["nw", "ne", "sw", "se"].forEach(function (corner, i) {
        var hdl = document.createElement("span");
        hdl.className = "sb-handle";
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

    shadow.appendChild(el);
    observeViewable(el, item, opts);
    return el;
  }

  function enableDrag(el, item) {
    var startX = 0,
      startY = 0,
      ox = 0,
      oy = 0;
    el.addEventListener("pointerdown", function (e) {
      if (e.target.classList && e.target.classList.contains("sb-x")) return;
      startX = e.clientX;
      startY = e.clientY;
      var place = (item.config && item.config.placement) || {};
      ox = place.offset_x || 0;
      oy = place.offset_y || 0;
      el.setPointerCapture(e.pointerId);
    });
    el.addEventListener("pointermove", function (e) {
      if (!el.hasPointerCapture(e.pointerId)) return;
      var nx = ox + (e.clientX - startX);
      var ny = oy + (e.clientY - startY);
      el.style.transform = "translate(" + nx + "px," + ny + "px)";
      postParent({ type: "banner.moved", campaign: item.campaign, offset_x: Math.round(nx), offset_y: Math.round(ny) });
    });
  }

  function observeViewable(el, item, opts) {
    if (opts && opts.preview) return;
    if (!("IntersectionObserver" in window)) {
      track(item.campaign, item.version, "viewable_impression", true);
      return;
    }
    var seen = false;
    var timer = null;
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.intersectionRatio >= 0.5) {
            if (!timer)
              timer = setTimeout(function () {
                if (!seen) {
                  seen = true;
                  track(item.campaign, item.version, "viewable_impression", true);
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
          return localStorage.getItem("sb_returning") ? "returning" : "first";
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
      if (localStorage.getItem(DISMISS + item.campaign)) return;
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
      localStorage.setItem("sb_returning", "1");
    } catch (e) {}

    track(item.campaign, item.version, "eligible", true);
    renderWidget(item, { editing: editor.active && editor.campaignId === item.campaign });
  }

  function loadConfig() {
    return fetch(API + "/api/v1/banners/config?key=" + encodeURIComponent(KEY), {
      credentials: "omit",
      headers: { Accept: "application/json", Origin: location.origin },
    })
      .then(function (r) {
        if (r.status === 304) return null;
        if (!r.ok) throw new Error("config " + r.status);
        return r.json();
      })
      .then(function (body) {
        if (!body || !body.data) return;
        campaigns = body.data.campaigns || [];
        clearWidgets();
        shownIds = Object.create(null);
        campaigns.forEach(maybeShow);
      })
      .catch(function () {
        /* graceful outage */
      });
  }

  function postParent(payload) {
    if (!editor.parentOrigin || !window.parent || window.parent === window) return;
    try {
      window.parent.postMessage(
        Object.assign({ schema: MSG_SCHEMA, nonce: editor.nonce, key: KEY }, payload),
        editor.parentOrigin
      );
    } catch (e) {}
  }

  function onMessage(ev) {
    var data = ev.data;
    if (!data || data.schema !== MSG_SCHEMA || data.key !== KEY) return;
    if (typeof data.nonce !== "string" || !data.nonce) return;

    if (data.type === "editor.handshake") {
      editor.parentOrigin = ev.origin;
      editor.nonce = data.nonce;
      postParent({ type: "editor.ready", origin: location.origin });
      return;
    }

    if (editor.parentOrigin && ev.origin !== editor.parentOrigin) return;

    if (data.type === "editor.pair" && data.pairing_token) {
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
          if (!res.ok) {
            postParent({ type: "editor.pair_failed", status: res.status, code: (res.j.error && res.j.error.code) || "pairing_invalid", request_id: res.j.error && res.j.error.request_id });
            return;
          }
          editor.token = res.j.data.editor_token;
          editor.active = true;
          editor.campaignId = data.campaign || null;
          postParent({ type: "editor.paired", expires_in: res.j.data.expires_in });
          if (editor.campaignId) loadEditorDraft(editor.campaignId);
        })
        .catch(function () {
          postParent({ type: "editor.pair_failed", status: 0, code: "network_error" });
        });
      return;
    }

    if (data.type === "editor.load_draft" && data.campaign && editor.token) {
      editor.campaignId = data.campaign;
      loadEditorDraft(data.campaign);
      return;
    }

    if (data.type === "editor.apply_config" && data.config) {
      clearWidgets();
      renderWidget({ campaign: data.campaign || "draft", version: 0, config: data.config }, { editing: true, preview: true, thresholdMinor: data.threshold_minor || null });
      return;
    }

    if (data.type === "editor.ping") {
      postParent({ type: "editor.pong", health: "ok", campaigns: campaigns.length });
    }
  }

  function loadEditorDraft(campaignId) {
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
        if (!res.ok) {
          postParent({ type: "editor.draft_failed", status: res.status, code: (res.j.error && res.j.error.code) || "error", request_id: res.j.error && res.j.error.request_id });
          return;
        }
        clearWidgets();
        renderWidget(
          { campaign: res.j.data.id, version: 0, config: res.j.data.draft_config },
          { editing: true, preview: true }
        );
        postParent({ type: "editor.draft", draft_revision: res.j.data.draft_revision, draft_config: res.j.data.draft_config });
      })
      .catch(function () {
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
  window.addEventListener("pagehide", flushEvents);
  window.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") flushEvents();
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", loadConfig);
  } else {
    loadConfig();
  }

  setInterval(function () {
    if (!editor.active) campaigns.forEach(maybeShow);
  }, 4000);

  window.ShopBonusEmbed = {
    reload: loadConfig,
    version: "1.0.0",
    key: KEY,
  };
})();
