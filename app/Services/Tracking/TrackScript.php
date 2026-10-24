<?php

namespace App\Services\Tracking;

class TrackScript
{
    public function source(): string
    {
        return <<<'JS'
(function () {
  var script = document.currentScript;
  if (!script) return;
  var key = script.getAttribute("data-track") || "";
  var pageId = script.getAttribute("data-page") || "";
  var endpoint = script.src.replace(/\/t\.js(?:\?.*)?$/, "/t/collect");
  var storageKey = "flowhub_" + key;
  var started = Date.now();
  var sessionId = "";
  try { sessionId = sessionStorage.getItem(storageKey) || ""; } catch (e) {}
  var affiliate = "";
  var pageType = "";
  var scroll = 0;
  var cta = 0;
  var buttons = 0;
  var forms = 0;
  var exitIntent = false;
  var sentClick = false;
  var hopped = false;
  var pending = null;
  var params = new URLSearchParams(location.search);

  function engagement() {
    return {
      scroll_depth: scroll,
      cta_clicks: cta,
      button_clicks: buttons,
      form_interactions: forms,
      exit_intent: exitIntent,
      presell_hop: hopped || cta > 0
    };
  }

  function payload() {
    return {
      key: key,
      session_id: sessionId || null,
      page_id: pageId ? Number(pageId) : null,
      time_on_page: Math.round((Date.now() - started) / 1000),
      gclid: params.get("gclid"),
      gbraid: params.get("gbraid"),
      wbraid: params.get("wbraid"),
      referrer: document.referrer || null,
      presell_hop: hopped || cta > 0,
      engagement: engagement()
    };
  }

  function send(keepalive) {
    return fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify(payload()),
      keepalive: !!keepalive
    }).then(function (response) {
      if (!response.ok) throw new Error("collect");
      return response.json();
    }).then(function (data) {
      sessionId = data.session_id || sessionId;
      affiliate = data.affiliate_url || affiliate;
      pageType = data.page_type || pageType;
      try { sessionStorage.setItem(storageKey, sessionId); } catch (e) {}
      return data;
    }).catch(function () { return null; });
  }

  function ensure() {
    if (!pending) pending = send(false);
    return pending;
  }

  function go() {
    if (sentClick) return;
    sentClick = true;
    cta += 1;
    hopped = true;
    ensure().then(function () {
      send(true);
      if (affiliate) location.href = affiliate;
    });
  }

  function onScroll() {
    var el = document.documentElement;
    var max = el.scrollHeight - el.clientHeight;
    var depth = max <= 0 ? 100 : Math.round((el.scrollTop / max) * 100);
    if (depth > scroll) scroll = Math.min(100, depth);
  }

  document.addEventListener("scroll", onScroll, { passive: true });
  document.addEventListener("focusin", function (event) {
    var tag = (event.target.tagName || "").toLowerCase();
    if (tag === "input" || tag === "textarea" || tag === "select") forms += 1;
  });
  document.addEventListener("mouseout", function (event) {
    if (event.clientY < 8) exitIntent = true;
  });
  document.addEventListener("click", function (event) {
    var target = event.target;
    if (!target || !target.closest) return;
    var age = target.closest("[data-age-confirm]");
    if (age && pageType !== "popup_cookie") {
      var gate = document.querySelector("[data-age-gate]");
      if (gate) gate.hidden = true;
      event.preventDefault();
      return;
    }
    var outbound = pageType === "popup_cookie" || target.closest("[data-outbound]");
    if (!outbound) {
      if (target.closest("button, a")) buttons += 1;
      return;
    }
    event.preventDefault();
    go();
  }, true);

  ensure();
  function ping() { send(false); setTimeout(ping, 12000); }
  setTimeout(ping, 12000);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") send(true);
  });
  window.addEventListener("pagehide", function () { send(true); });
})();
JS;
    }
}
