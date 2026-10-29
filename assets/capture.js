(function () {
  'use strict';

  var config = window.aviaAttribution || {};
  var cookieName = config.cookieName || 'avia_att';
  var cookieDays = config.cookieDays || 90;
  var lookbackDays = config.clickLookbackDays || 90;

  function readCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function writeCookie(name, value, days) {
    var expires = new Date(Date.now() + days * 86400000).toUTCString();
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax' + secure;
  }

  function parseQuery() {
    var params = new URLSearchParams(window.location.search);
    var keys = ['gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    var out = {};
    keys.forEach(function (key) {
      var value = params.get(key);
      if (value) {
        out[key] = value;
      }
    });
    return out;
  }

  function readSession() {
    try {
      return JSON.parse(readCookie(cookieName) || '{}');
    } catch (e) {
      return {};
    }
  }

  function hasAdClickParams(params) {
    return !!(params.gclid || params.gbraid || params.wbraid);
  }

  function hasClickParams(params) {
    return hasAdClickParams(params)
      || !!(params.utm_source || params.utm_medium || params.utm_campaign);
  }

  function uuid() {
    if (window.crypto && crypto.randomUUID) {
      return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      var v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }

  function nowIso() {
    return new Date().toISOString();
  }

  function isClickFresh(session) {
    if (!session || !(session.gclid || session.gbraid || session.wbraid)) {
      return false;
    }
    if (!session.click_at) {
      return false;
    }
    var captured = Date.parse(session.click_at);
    if (!captured) {
      return false;
    }
    return (Date.now() - captured) <= (lookbackDays * 86400000);
  }

  function stripStaleClicks(session) {
    if (!session) {
      return session;
    }
    if ((session.gclid || session.gbraid || session.wbraid) && !isClickFresh(session)) {
      delete session.gclid;
      delete session.gbraid;
      delete session.wbraid;
      delete session.click_at;
    }
    return session;
  }

  function pushDataLayer(session) {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
      event: 'avia_attribution_ready',
      avia_att_id: session.att_id,
      avia_gclid: session.gclid || '',
      avia_gbraid: session.gbraid || '',
      avia_wbraid: session.wbraid || '',
      avia_utm_source: session.utm_source || '',
      avia_utm_medium: session.utm_medium || '',
      avia_utm_campaign: session.utm_campaign || ''
    });
  }

  function readGa4CookieClientId() {
    try {
      var match = document.cookie.match(/(?:^|; )_ga=([^;]*)/);
      if (!match) {
        return '';
      }
      var parts = decodeURIComponent(match[1]).split('.');
      if (parts.length >= 4) {
        return parts[2] + '.' + parts[3];
      }
    } catch (e) {
      /* noop */
    }
    return '';
  }

  function fetchGa4Ids(callback) {
    var out = {
      ga4_client_id: readGa4CookieClientId(),
      ga4_session_id: ''
    };
    var pending = 0;
    var done = function () {
      if (pending <= 0 && typeof callback === 'function') {
        callback(out);
      }
    };
    try {
      if (typeof window.gtag === 'function') {
        pending = 2;
        window.gtag('get', 'G-16E3BRMYPX', 'client_id', function (clientId) {
          if (clientId) {
            out.ga4_client_id = clientId;
          }
          pending -= 1;
          done();
        });
        window.gtag('get', 'G-16E3BRMYPX', 'session_id', function (sessionId) {
          if (sessionId) {
            out.ga4_session_id = String(sessionId);
          }
          pending -= 1;
          done();
        });
        window.setTimeout(function () {
          if (pending > 0) {
            pending = 0;
            done();
          }
        }, 800);
        return;
      }
    } catch (e) {
      /* noop */
    }
    done();
  }

  function persist(session, refreshExpiry) {
    session = stripStaleClicks(session);
    writeCookie(cookieName, JSON.stringify(session), refreshExpiry ? cookieDays : cookieDays);
    pushDataLayer(session);

    if (!config.restUrl) {
      return;
    }

    fetchGa4Ids(function (ga4) {
      var body = Object.assign({}, session, {
        landing_page: window.location.href,
        referrer: document.referrer || ''
      }, ga4);

      fetch(config.restUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': config.nonce || ''
        },
        credentials: 'same-origin',
        body: JSON.stringify(body)
      }).catch(function () {
        /* server-side init capture is fallback */
      });
    });
  }

  var incoming = parseQuery();
  var existing = stripStaleClicks(readSession());
  var session = existing;
  var shouldPersist = false;
  var refreshExpiry = false;

  if (
    existing.att_id &&
    incoming.gclid &&
    existing.gclid &&
    incoming.gclid === existing.gclid
  ) {
    session = Object.assign({}, existing, incoming, {
      landing_page: window.location.href,
      referrer: document.referrer || '',
      click_at: existing.click_at || nowIso()
    });
    shouldPersist = true;
    refreshExpiry = true;
  } else if (hasClickParams(incoming) || !existing.att_id) {
    session = Object.assign({}, incoming, {
      att_id: uuid(),
      landing_page: window.location.href,
      referrer: document.referrer || '',
      ga4_client_id: existing.ga4_client_id || '',
      ga4_session_id: existing.ga4_session_id || ''
    });
    if (hasAdClickParams(incoming)) {
      session.click_at = nowIso();
    } else {
      delete session.click_at;
      delete session.gclid;
      delete session.gbraid;
      delete session.wbraid;
    }
    shouldPersist = true;
    refreshExpiry = true;
  } else if (Object.keys(incoming).length) {
    session = Object.assign({}, existing, incoming);
    shouldPersist = true;
  } else if (existing.att_id) {
    // Organic revisit: keep att_id, strip stale clicks, do not slide click lifetime.
    session = existing;
    shouldPersist = true;
    refreshExpiry = false;
  }

  if (session.att_id && shouldPersist) {
    persist(session, refreshExpiry);
  }

})();
