(function () {
  'use strict';

  var config = window.aviaAttribution || {};
  var cookieName = config.cookieName || 'avia_att';
  var session = null;

  function readCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function readSession() {
    try {
      return JSON.parse(readCookie(cookieName) || '{}');
    } catch (e) {
      return {};
    }
  }

  var lookbackDays = (window.aviaAttribution && window.aviaAttribution.clickLookbackDays) || 90;

  function isClickFresh(sess) {
    if (!sess || !(sess.gclid || sess.gbraid || sess.wbraid)) {
      return false;
    }
    if (!sess.click_at) {
      return false;
    }
    var captured = Date.parse(sess.click_at);
    if (!captured) {
      return false;
    }
    return (Date.now() - captured) <= (lookbackDays * 86400000);
  }

  function getAttributionPayload() {
    session = readSession();
    if (!session.att_id) {
      return null;
    }

    var fresh = isClickFresh(session);
    // Only pass click IDs + UTMs when this is a fresh Google Ads click.
    // Organic / stale cookie must not stamp campaign labels into Boulevard Book Now.
    return {
      avia_att_id: session.att_id,
      gclid: fresh ? (session.gclid || '') : '',
      gbraid: fresh ? (session.gbraid || '') : '',
      wbraid: fresh ? (session.wbraid || '') : '',
      utm_source: fresh ? (session.utm_source || '') : '',
      utm_medium: fresh ? (session.utm_medium || '') : '',
      utm_campaign: fresh ? (session.utm_campaign || '') : '',
      utm_term: fresh ? (session.utm_term || '') : '',
      utm_content: fresh ? (session.utm_content || '') : ''
    };
  }

  function buildReferralSource(payload) {
    var parts = ['avia:' + payload.avia_att_id];
    if (payload.gclid) {
      parts.push('gclid:' + payload.gclid);
    }
    // Encode campaign — names often contain "|" (Google Ads) which would break pipe-delimited parse.
    if (payload.utm_campaign) {
      parts.push('campaign:' + encodeURIComponent(payload.utm_campaign));
    }
    return parts.join('|');
  }

  function recordBookIntent(payload) {
    if (!payload || !payload.avia_att_id || !config.bookIntentUrl) {
      return;
    }
    try {
      fetch(config.bookIntentUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': config.nonce || ''
        },
        credentials: 'same-origin',
        body: JSON.stringify({ att_id: payload.avia_att_id })
      }).catch(function () { /* webhook claim is best-effort */ });
    } catch (e) { /* noop */ }
  }

  function pushBridgeEvent(eventName, extra) {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(Object.assign({
      event: eventName,
      avia_att_id: session && session.att_id ? session.att_id : '',
      avia_gclid: session && session.gclid ? session.gclid : ''
    }, extra || {}));
  }

  function mergeUrlParams(options, payload) {
    var next = options || {};
    var referral = buildReferralSource(payload);
    // CreateCartInput (not urlParams): referralSource → appointment referral_source custom field;
    // clientInformation.externalId → client.externalId. urlParams alone is often ignored for these.
    next.referralSource = referral;
    next.clientMessage = referral;
    next.clientInformation = Object.assign({}, next.clientInformation || {}, {
      externalId: payload.avia_att_id
    });
    // Also mirror into urlParams for older injector builds that only forward query-ish keys.
    next.urlParams = Object.assign({}, next.urlParams || {}, {
      avia_att_id: payload.avia_att_id,
      avia_gclid: payload.gclid,
      avia_gbraid: payload.gbraid,
      avia_wbraid: payload.wbraid,
      avia_utm_source: payload.utm_source,
      avia_utm_medium: payload.utm_medium,
      avia_utm_campaign: payload.utm_campaign,
      gclid: payload.gclid,
      gbraid: payload.gbraid,
      wbraid: payload.wbraid,
      utm_source: payload.utm_source,
      utm_medium: payload.utm_medium,
      utm_campaign: payload.utm_campaign,
      utm_term: payload.utm_term,
      utm_content: payload.utm_content,
      referral_source: referral,
      referralSource: referral,
      clientMessage: referral,
      externalId: payload.avia_att_id,
      external_id: payload.avia_att_id,
      clientExternalId: payload.avia_att_id
    });
    return next;
  }

  function patchBlvdOpen() {
    if (!window.blvd || typeof window.blvd.openBookingWidget !== 'function') {
      return false;
    }
    if (window.blvd.__aviaBridgePatched) {
      return true;
    }

    var original = window.blvd.openBookingWidget.bind(window.blvd);
    window.blvd.openBookingWidget = function (options) {
      var payload = getAttributionPayload();
      if (payload) {
        recordBookIntent(payload);
        options = mergeUrlParams(options, payload);
        pushBridgeEvent('avia_blvd_open', { avia_bridge: 'openBookingWidget' });
      }
      return original(options);
    };
    window.blvd.__aviaBridgePatched = true;
    return true;
  }

  function openWithAttribution(fallbackOptions) {
    var payload = getAttributionPayload();
    if (!payload) {
      return false;
    }

    patchBlvdOpen();
    pushBridgeEvent('avia_blvd_open', { avia_bridge: 'manual_open' });

    if (window.blvd && typeof window.blvd.openBookingWidget === 'function') {
      window.blvd.openBookingWidget(mergeUrlParams(fallbackOptions || {}, payload));
      return true;
    }

    if (window.location.hash !== '#book-now') {
      window.location.hash = 'book-now';
    }
    return true;
  }

  function onBookIntent() {
    var payload = getAttributionPayload();
    if (!payload) {
      return;
    }
    patchBlvdOpen();
    recordBookIntent(payload);
    pushBridgeEvent('avia_book_now_click', { avia_bridge: 'book_intent' });
    pushBridgeEvent('avia_blvd_bridge_ready', {
      avia_bridge: 'book_intent',
      avia_referral_source: buildReferralSource(payload)
    });
    window.setTimeout(function () {
      if (window.blvd && window.blvd.__aviaBridgePatched) {
        return;
      }
      pushBridgeEvent('avia_blvd_open', { avia_bridge: 'book_intent_fallback' });
    }, 800);
  }

  function isBookNowElement(el) {
    if (!el) {
      return false;
    }
    if (el.id === 'book-now' || (el.closest && el.closest('#book-now'))) {
      return true;
    }
    var label = (el.textContent || el.getAttribute('aria-label') || '').toLowerCase();
    var href = (el.getAttribute('href') || '').toLowerCase();
    return label.indexOf('book now') !== -1
      || label.indexOf('make an appointment') !== -1
      || href.indexOf('blvd') !== -1
      || href.indexOf('#book-now') !== -1
      || href.indexOf('#book') !== -1;
  }

  function openBookingForAttributedClick(fallbackOptions) {
    var payload = getAttributionPayload();
    if (!payload) {
      return false;
    }

    patchBlvdOpen();
    recordBookIntent(payload);
    pushBridgeEvent('avia_book_now_click', { avia_bridge: 'book_intent' });
    pushBridgeEvent('avia_blvd_bridge_ready', {
      avia_bridge: 'book_intent',
      avia_referral_source: buildReferralSource(payload)
    });

    if (window.blvd && typeof window.blvd.openBookingWidget === 'function') {
      window.blvd.openBookingWidget(mergeUrlParams(fallbackOptions || {}, payload));
      pushBridgeEvent('avia_blvd_open', { avia_bridge: 'attributed_click' });
      return true;
    }

    return openWithAttribution(fallbackOptions);
  }

  function bindBookNowHandlers() {
    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) {
        return;
      }
      var el = target.closest('a[href*="#book"], a[href*="#reserve"], a[href*="#gform"], a[href*="blvd"], button, .elementor-button, [role="button"]');
      if (!el || !isBookNowElement(el)) {
        return;
      }

      // No session cookie → let native Book Now / Boulevard handle (organic direct booking).
      if (!getAttributionPayload()) {
        return;
      }
      // Never freeze the button if Boulevard injector is missing/blocked.
      if (!window.blvd || typeof window.blvd.openBookingWidget !== 'function') {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      openBookingForAttributedClick();
    }, true);

    window.addEventListener('hashchange', function () {
      if (window.location.hash === '#book-now' || window.location.hash === '#book') {
        openWithAttribution();
      }
    });
  }

  window.AviaAttributionBridge = {
    getSession: getAttributionPayload,
    openBooking: openWithAttribution,
    pushAttribution: onBookIntent
  };

  bindBookNowHandlers();

  var attempts = 0;
  var timer = window.setInterval(function () {
    attempts += 1;
    if (patchBlvdOpen() || attempts > 40) {
      window.clearInterval(timer);
    }
  }, 500);
})();
