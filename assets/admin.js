(function ($) {
  'use strict';

  function renderResult($el, type, message, extraHtml) {
    $el.removeClass('is-success is-error is-loading');
    $el.addClass(type === 'success' ? 'is-success' : 'is-error');
    $el.html('<p><strong>' + message + '</strong></p>' + (extraHtml || ''));
  }

  function post(action, $result, data) {
    $result.addClass('is-loading').text('Running...');
    return $.post(aviaAttributionAdmin.ajaxUrl, $.extend({
      action: action,
      nonce: aviaAttributionAdmin.nonce
    }, data || {}));
  }

	function escapeHtml(value) {
    return $('<div>').text(value == null ? '' : value).html();
  }

  function renderClientTags(tags) {
    if (!tags || !tags.length) {
      return '';
    }
    var html = '<div class="avia-client-tags">';
    tags.forEach(function (tag) {
      var label = (tag && tag.name) ? String(tag.name) : '';
      if (!label) {
        return;
      }
      if (tag.symbol) {
        label = String(tag.symbol) + ' ' + label;
      }
      html += '<span class="avia-client-tag">' + escapeHtml(label) + '</span>';
    });
    html += '</div>';
    return html;
  }

  function eventBadgeClass(eventType) {
    var map = {
      APPOINTMENT_CANCELLED: 'avia-event-cancelled',
      APPOINTMENT_COMPLETED: 'avia-event-completed',
      APPOINTMENT_CREATED: 'avia-event-created',
      CLIENT_CREATED: 'avia-event-client',
      ORDER_COMPLETED: 'avia-event-order',
      APPOINTMENT_UPDATED: 'avia-event-updated',
      CLIENT_UPDATED: 'avia-event-updated'
    };
    return map[eventType] || 'avia-event-default';
  }

  function renderEventBadge(eventType, badgeClass) {
    var cssClass = badgeClass || eventBadgeClass(eventType);
    return '<span class="avia-event-badge ' + cssClass + '">' + escapeHtml(eventType || '—') + '</span>';
  }

  function renderTimelineSection(timeline, heading) {
    if (!timeline || !timeline.length) {
      return '';
    }

    var html = '';
    if (heading) {
      html += '<h4>' + escapeHtml(heading) + '</h4>';
    }
    html += '<ol class="avia-client-timeline">';
    timeline.forEach(function (event) {
      html += '<li class="avia-timeline-step">';
      html += renderEventBadge(event.event_type, event.event_badge_class);
      if (event.tag) {
        html += ' <span class="avia-timeline-tag ' + escapeHtml(event.tag_class || eventBadgeClass(event.event_type)) + '">' + escapeHtml(event.tag) + '</span>';
      }
      if (event.detail) {
        html += ' <span class="avia-timeline-detail">' + escapeHtml(event.detail) + '</span>';
      }
      html += ' <time class="avia-timeline-when">' + escapeHtml(event.created_at || '') + '</time>';
      html += '</li>';
    });
    html += '</ol>';
    return html;
  }

  function renderLookupResult($result, response) {
    if (!response.success) {
      renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Lookup failed.');
      return;
    }

    var lookup = response.data.lookup || {};
    var html = '';
    var writeback = lookup.writeback_ok || {};
    var type = writeback.ok ? 'success' : 'error';

    (lookup.clients || []).forEach(function (client, index) {
      html += '<h3>Client ' + (index + 1) + '</h3>';
      html += '<table class="widefat striped avia-lookup-table"><tbody>';
      html += '<tr><th scope="row">Client ID</th><td><code>' + escapeHtml(client.id) + '</code></td></tr>';
      html += '<tr><th scope="row">Name</th><td>' + escapeHtml(((client.first_name || '') + ' ' + (client.last_name || '')).trim()) + '</td></tr>';
      html += '<tr><th scope="row">Email</th><td>' + escapeHtml(client.email || '—') + '</td></tr>';
      html += '<tr><th scope="row">Phone</th><td>' + escapeHtml(client.mobile_phone || '—') + '</td></tr>';
      html += '<tr><th scope="row">Tags</th><td>' + (renderClientTags(client.tags) || '—') + '</td></tr>';
      html += '<tr><th scope="row">Referral source</th><td>' + escapeHtml(client.referral_source || '—') + '</td></tr>';
      html += '<tr><th scope="row">Created</th><td>' + escapeHtml(client.created_at || '—') + '</td></tr>';
      html += '</tbody></table>';

      html += '<h4>Custom fields (API)</h4>';
      html += '<table class="widefat striped avia-lookup-table"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
      Object.keys(client.custom_fields || {}).forEach(function (key) {
        var value = client.custom_fields[key] || '';
        var rowClass = value ? 'avia-field-ok' : 'avia-field-empty';
        html += '<tr class="' + rowClass + '"><td><code>' + escapeHtml(key) + '</code></td><td>' + (value ? '<code>' + escapeHtml(value) + '</code>' : '<em>empty</em>') + '</td></tr>';
      });
      html += '</tbody></table>';

      html += renderTimelineSection((lookup.client_timelines || {})[client.id] || [], 'Event journey (ledger)');

      (client.matched_local_sessions || []).forEach(function (session) {
        html += '<h4>Matched local session</h4>';
        html += '<table class="widefat striped avia-lookup-table"><tbody>';
        html += '<tr><th scope="row">att_id</th><td><code>' + escapeHtml(session.att_id) + '</code></td></tr>';
        html += '<tr><th scope="row">gclid</th><td><code>' + escapeHtml(session.gclid || '—') + '</code></td></tr>';
        html += '<tr><th scope="row">utm_campaign</th><td><code>' + escapeHtml(session.utm_campaign || '—') + '</code></td></tr>';
        html += '<tr><th scope="row">blvd_client_id</th><td><code>' + escapeHtml(session.blvd_client_id || '—') + '</code></td></tr>';
        html += '<tr><th scope="row">Captured</th><td>' + escapeHtml(session.created_at || '—') + '</td></tr>';
        html += '</tbody></table>';
      });
    });

    if (!(lookup.clients || []).length && (lookup.local_sessions || []).length) {
      html += '<h3>Local attribution session(s)</h3>';
      (lookup.local_sessions || []).forEach(function (session) {
        html += '<table class="widefat striped avia-lookup-table"><tbody>';
        html += '<tr><th scope="row">att_id</th><td><code>' + escapeHtml(session.att_id) + '</code></td></tr>';
        html += '<tr><th scope="row">gclid</th><td><code>' + escapeHtml(session.gclid || '—') + '</code></td></tr>';
        html += '<tr><th scope="row">utm_campaign</th><td><code>' + escapeHtml(session.utm_campaign || '—') + '</code></td></tr>';
        html += '<tr><th scope="row">blvd_client_id</th><td><code>' + escapeHtml(session.blvd_client_id || '—') + '</code></td></tr>';
        html += '<tr><th scope="row">Captured</th><td>' + escapeHtml(session.created_at || '—') + '</td></tr>';
        html += '</tbody></table>';
      });
    }

    if (!(lookup.clients || []).length && (lookup.event_links || []).length) {
      html += '<h3>Webhook ledger links</h3>';
      html += '<table class="widefat striped avia-lookup-table"><thead><tr><th>Event</th><th>Client ID</th><th>att_id</th><th>When</th></tr></thead><tbody>';
      lookup.event_links.forEach(function (row) {
        html += '<tr><td>' + renderEventBadge(row.event_type) + '</td><td><code>' + escapeHtml(row.blvd_client_id) + '</code></td><td><code>' + escapeHtml(row.att_id || '—') + '</code></td><td>' + escapeHtml(row.created_at || '—') + '</td></tr>';
      });
      html += '</tbody></table>';
    }

    renderResult($result, type, response.data.message, html);
  }

  $('#avia-test-endpoint').on('click', function () {
    var $result = $('#avia-test-endpoint-result');
    post('avia_attribution_test_endpoint', $result)
      .done(function (response) {
        if (response.success) {
          renderResult($result, 'success', response.data.message);
        } else {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Test failed.');
        }
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-test-boulevard').on('click', function () {
    var $result = $('#avia-test-boulevard-result');
    post('avia_attribution_test_boulevard', $result)
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Connection failed.');
          return;
        }

        var html = '<ul>';
        (response.data.locations || []).forEach(function (location) {
          html += '<li><strong>' + location.name + '</strong><br><code>' + location.id + '</code></li>';
        });
        html += '</ul>';
        renderResult($result, 'success', response.data.message, html);
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-ping-webhooks').on('click', function () {
    var $result = $('#avia-ping-webhooks-result');
    post('avia_attribution_ping_webhooks', $result)
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Ping failed.');
          return;
        }
        var html = '<ul>';
        (response.data.results || []).forEach(function (row) {
          html += '<li>' + row.webhook_id + ': ' + (row.success ? 'Ping sent' : row.message) + '</li>';
        });
        html += '</ul><p>Wait 5 seconds, then refresh this page and check Recent webhook delivery log.</p>';
        renderResult($result, 'success', response.data.message, html);
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  function renderHealthTable(report) {
    var html = '<p>Checked at: <strong>' + report.checked_at + '</strong>. Disabled subscriptions: <strong>' + report.disabled_count + '</strong></p>';
    html += '<table class="widefat striped avia-health-table"><thead><tr><th>Webhook</th><th>Location</th><th>Event type</th><th>Status</th></tr></thead><tbody>';

    (report.webhooks || []).forEach(function (webhook) {
      (webhook.subscriptions || []).forEach(function (subscription, index) {
        var status = subscription.enabled ? '<span class="avia-ok">Enabled</span>' : '<span class="avia-fail">Disabled</span>';
        var eventType = subscription.eventType || '';
        var rowClass = eventType === 'APPOINTMENT_CREATED' ? ' class="avia-highlight-row"' : '';
        html += '<tr' + rowClass + '>';
        html += '<td>' + (index === 0 ? webhook.name : '') + '</td>';
        html += '<td>' + (index === 0 ? (webhook.location_name || '—') : '') + '</td>';
        html += '<td><code>' + eventType + '</code></td>';
        html += '<td>' + status + '</td>';
        html += '</tr>';
      });
    });

    html += '</tbody></table>';
    return html;
  }

  $('#avia-webhook-health').on('click', function () {
    var $result = $('#avia-webhook-health-result');
    post('avia_attribution_webhook_health', $result)
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Health check failed.');
          return;
        }
        renderResult($result, 'success', response.data.message, renderHealthTable(response.data.report));
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-repair-webhooks').on('click', function () {
    var $result = $('#avia-webhook-health-result');
    if (!window.confirm('Recreate all disabled webhook subscriptions in Boulevard?')) {
      return;
    }
    post('avia_attribution_repair_webhooks', $result)
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Repair failed.');
          return;
        }
        var html = '<ul>';
        (response.data.results || []).forEach(function (row) {
          html += '<li>' + row.webhook_name + ': ' + row.message + '</li>';
        });
        html += '</ul><p>Click <strong>Check subscription health</strong> again to verify.</p>';
        renderResult($result, 'success', response.data.message, html);
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-clean-duplicate-webhooks').on('click', function () {
    var $result = $('#avia-webhook-health-result');
    if (!window.confirm('Delete duplicate Boulevard webhooks that point at this endpoint (keep one per location)?')) {
      return;
    }
    post('avia_attribution_clean_duplicate_webhooks', $result)
      .done(function (response) {
        var html = '';
        if (response.data && response.data.summary) {
          html += '<p>Webhook count: <strong>' + response.data.summary.before_count + ' -> ' + response.data.summary.after_count + '</strong> (expected ' + response.data.summary.expected_count + ')</p>';
        }
        html += '<ul>';
        var hasFailure = !response.success;
        (response.data.results || []).forEach(function (row) {
          var label = row.webhook_name || row.location_key || row.webhook_id;
          var state = row.kept ? 'kept' : (row.success ? 'deleted' : 'failed');
          if (state === 'failed') {
            hasFailure = true;
          }
          html += '<li><strong>' + label + '</strong> (' + state + '): ' + row.message + '</li>';
        });
        html += '</ul><p>Click <strong>Check subscription health</strong> again to verify.</p>';
        renderResult(
          $result,
          hasFailure ? 'error' : 'success',
          response.data && response.data.message ? response.data.message : (response.success ? 'Cleanup completed.' : 'Cleanup failed.'),
          html
        );
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-reregister-webhooks').on('click', function () {
    var $result = $('#avia-webhook-health-result');
    if (!window.confirm('Delete ALL Boulevard webhooks for this app and create fresh ones for every location?')) {
      return;
    }
    post('avia_attribution_reregister_webhooks', $result)
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Re-register failed.');
          return;
        }
        renderResult($result, 'success', response.data.message, '<p>Reload this page, then run Check subscription health.</p>');
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-list-google-ads-accounts').on('click', function () {
    var $result = $('#avia-google-ads-result');
    post('avia_attribution_list_google_ads_accounts', $result)
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Could not list accounts.');
          return;
        }

        var accounts = response.data.accounts || [];
        if (!accounts.length && response.data.customers) {
          accounts = response.data.customers.map(function (customerId) {
            return { id: customerId, name: '' };
          });
        }

        var html = '<ul>';
        accounts.forEach(function (account) {
          var id = account.id || '';
          var formattedId = id.length === 10
            ? id.slice(0, 3) + '-' + id.slice(3, 6) + '-' + id.slice(6)
            : id;
          var label = account.name
            ? '<strong>' + account.name + '</strong><br><code>' + formattedId + '</code>'
            : '<code>' + formattedId + '</code>';
          html += '<li>' + label + '</li>';
        });
        html += '</ul>';
        renderResult($result, 'success', response.data.message, html);
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-test-google-ads').on('click', function () {
    var $result = $('#avia-google-ads-result');
    post('avia_attribution_test_google_ads', $result)
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Connection failed.');
          return;
        }

        var extra = '';
        if (response.data && response.data.data_manager && !response.data.data_manager.ok) {
          extra = '<p>Data Manager is required for auto-upload in v1.7. Regenerate the refresh token with both scopes and enable the Data Manager API in Google Cloud.</p>';
        }
        renderResult($result, 'success', response.data.message, extra);
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-upload-google-ads').on('click', function () {
    if ($(this).prop('disabled')) {
      return;
    }

    var $result = $('#avia-google-ads-result');
    post('avia_attribution_upload_google_ads', $result)
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Upload failed.');
          return;
        }

        var html = '<ul>';
        (response.data.results || []).forEach(function (row) {
          html += '<li>#' + row.upload_id + ': ' + row.message + '</li>';
        });
        html += '</ul><p class="description">Reload this page to refresh the upload queue table.</p>';
        renderResult($result, 'success', response.data.message, html);
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-consolidate-same-click').on('click', function () {
    if ($(this).prop('disabled')) {
      return;
    }

    var $result = $('#avia-google-ads-result');
    post('avia_attribution_consolidate_same_click', $result)
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Merge failed.');
          return;
        }

        renderResult(
          $result,
          'success',
          response.data.message,
          '<p class="description">Google may take 30 minutes to 24 hours to show the restated conversion value. Reload this page to refresh the upload queue.</p>'
        );
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-void-false-attribution').on('click', function () {
    var email = $.trim($('#avia-void-false-email').val() || '');
    var $result = $('#avia-google-ads-result');
    if (!email) {
      renderResult($result, 'error', 'Enter the client email or gclid to void.');
      return;
    }
    if (!window.confirm('Void false ads attribution for ' + email + '?\n\nThis will:\n• Restate $0 to Google (uploaded rows only)\n• Keep real order value in the table\n• Clear Click ID / campaign on the row\n• Clear CRM avia_* fields\n• Unlink session identity')) {
      return;
    }
    post('avia_attribution_void_false_attribution', $result, { email: email })
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Void failed.');
          return;
        }
        renderResult(
          $result,
          'success',
          response.data.message,
          '<p class="description">Hard-refresh Attributed conversions. Google $0 may take up to 24h.</p>'
        );
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-void-false-attribution-auto').on('click', function () {
    if ($(this).prop('disabled')) {
      return;
    }
    var $result = $('#avia-google-ads-result');
    if (!window.confirm('Auto-void ALL likely false ads matches from before Oct 15 (old unlinked-session bug)?\n\nSkips clients that already have a genuine avia: / Book Now link.\nAlso repairs already-voided rows (restores order value, clears Click ID).')) {
      return;
    }
    post('avia_attribution_void_false_attribution_auto', $result)
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Auto-void failed.');
          return;
        }
        renderResult(
          $result,
          'success',
          response.data.message,
          '<p class="description">Hard-refresh Attributed conversions and verify a few clients. Real website bookings with avia referral are left alone.</p>'
        );
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  $('#avia-lookup-client').on('click', function () {
    var $result = $('#avia-lookup-client-result');
    var email = $.trim($('#avia-lookup-email').val());
    var gclid = $.trim($('#avia-lookup-gclid').val());
    var attId = $.trim($('#avia-lookup-att-id').val());

    if (!email && !gclid && !attId) {
      renderResult($result, 'error', 'Enter at least one of email, gclid, or att_id.');
      return;
    }

    post('avia_attribution_lookup_client', $result, {
      email: email,
      gclid: gclid,
      att_id: attId
    })
      .done(function (response) {
        renderLookupResult($result, response);
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });

  function formatMoney(value) {
    var amount = parseFloat(value);
    if (isNaN(amount)) {
      return '—';
    }
    return '$' + amount.toFixed(2);
  }

  function formatRoas(value) {
    if (value === null || value === undefined || value === '') {
      return '—';
    }
    return parseFloat(value).toFixed(2) + 'x';
  }

  var overviewCampaignIds = (aviaAttributionAdmin.campaignIds || []).map(String);
  function updateOverviewCampaignInputs() {
    var $inputs = $('#avia-overview-campaign-inputs').empty();
    overviewCampaignIds.forEach(function (id) { $inputs.append($('<input type="hidden" name="campaign_ids[]">').val(id)); });
    $('#avia-overview-campaign-label').text(overviewCampaignIds.length ? overviewCampaignIds.length + ' campaigns selected' : 'All campaigns');
  }
  function positionOverviewCampaignDropdown() {
    var filter = document.getElementById('avia-overview-campaign-filter');
    if (!filter || !filter.open) return;
    var menu = filter.querySelector('.avia-overview-campaign-options');
    var panel = filter.closest('.avia-attribution-panel');
    var anchorBounds = filter.getBoundingClientRect();
    var panelBounds = panel ? panel.getBoundingClientRect() : { left: 0, right: window.innerWidth };
    var minLeft = Math.max(16, panelBounds.left + 12);
    var maxRight = Math.min(window.innerWidth - 16, panelBounds.right - 12);
    var width = Math.min(560, Math.max(0, maxRight - minLeft));
    var left = Math.max(minLeft, Math.min(anchorBounds.left, maxRight - width));
    menu.style.width = width + 'px';
    menu.style.left = (left - anchorBounds.left) + 'px';
  }
  $('#avia-overview-campaign-filter').on('toggle', positionOverviewCampaignDropdown);
  $(window).on('resize', positionOverviewCampaignDropdown);
  $('#avia-dashboard-range').on('change', function () {
    window.requestAnimationFrame(positionOverviewCampaignDropdown);
  });

  function filterOverviewCampaignOptions() {
    var search = String($('#avia-overview-campaign-search').val() || '').trim().toLowerCase();
    var visible = 0;
    $('#avia-overview-campaign-list label').each(function () {
      var matches = $(this).attr('data-campaign-name').indexOf(search) !== -1;
      $(this).prop('hidden', !matches);
      if (matches) visible++;
    });
    $('#avia-overview-campaign-empty').prop('hidden', visible > 0 || !search);
  }
  $('#avia-overview-campaign-search').on('input', filterOverviewCampaignOptions);
  $(document).on('click', function (event) {
    var $filter = $('#avia-overview-campaign-filter');
    if ($filter.prop('open') && !$(event.target).closest('#avia-overview-campaign-filter').length) {
      $filter.prop('open', false);
    }
  });
  $(document).on('keydown', function (event) {
    if (event.key === 'Escape' && $('#avia-overview-campaign-filter').prop('open')) {
      $('#avia-overview-campaign-filter').prop('open', false);
      $('#avia-overview-campaign-label').trigger('focus');
    }
  });
  // Search Enter must not submit the dashboard date form.
  $('#avia-overview-campaign-search').on('keydown', function (event) {
    if (event.key === 'Enter') event.preventDefault();
  });
  function renderOverviewCampaignOptions(options) {
    var $list = $('#avia-overview-campaign-list').empty();
    (options || []).slice().sort(function (a, b) {
      var enabledA = normalizeCampaignStatus(a.status) === 'ENABLED';
      var enabledB = normalizeCampaignStatus(b.status) === 'ENABLED';
      if (enabledA !== enabledB) return enabledA ? -1 : 1;
      return String(a.name).localeCompare(String(b.name));
    }).forEach(function (item) {
      var status = normalizeCampaignStatus(item.status);
      var $label = $('<label>').attr('data-campaign-name', String(item.name || '').toLowerCase());
      $label.append($('<input type="checkbox" class="avia-overview-campaign-choice">').val(item.id).prop('checked', overviewCampaignIds.indexOf(String(item.id)) !== -1));
      $label.append($('<span class="avia-campaign-filter-dot" aria-hidden="true">').toggleClass('is-enabled', status === 'ENABLED').attr('title', status));
      $label.append($('<span class="screen-reader-text">').text(status + ': '));
      $label.append($('<span class="avia-campaign-filter-name">').text(item.name));
      $list.append($label);
    });
    if (!options || !options.length) $list.text('Campaign list unavailable.');
    filterOverviewCampaignOptions();
  }
  $('#avia-overview-campaign-all').on('click', function () {
    $('.avia-overview-campaign-choice').prop('checked', false);
  });
  $('#avia-overview-campaign-apply').on('click', function () {
    overviewCampaignIds = $('.avia-overview-campaign-choice:checked').map(function () { return String(this.value); }).get();
    updateOverviewCampaignInputs();
    $('#avia-overview-campaign-filter').prop('open', false);
    loadDashboardAdsPerformance();
  });
  updateOverviewCampaignInputs();

  var campaignDataCache = [];

  function normalizeCampaignStatus(status) {
    return String(status || '').toUpperCase();
  }

  function filterCampaigns(campaigns, filterMode) {
    filterMode = filterMode || 'enabled';
    if (!campaigns || !campaigns.length) {
      return [];
    }
    if (filterMode === 'all') {
      return campaigns.slice();
    }
    if (filterMode === 'paused') {
      return campaigns.filter(function (row) {
        return normalizeCampaignStatus(row.status) === 'PAUSED';
      });
    }
    return campaigns.filter(function (row) {
      return normalizeCampaignStatus(row.status) === 'ENABLED';
    });
  }

  function renderCampaignStatusBadge(status) {
    var normalized = normalizeCampaignStatus(status);
    var label = normalized || '—';
    var className = 'avia-campaign-status';
    if (normalized === 'ENABLED') {
      className += ' is-enabled';
    } else if (normalized === 'PAUSED') {
      className += ' is-paused';
    }
    return '<span class="' + className + '">' + escapeHtml(label) + '</span>';
  }

  function renderCampaignStatusDot(status) {
    var normalized = normalizeCampaignStatus(status);
    var className = 'avia-campaign-name-dot';
    var label = normalized || 'Unknown';
    if (normalized === 'ENABLED') {
      className += ' is-enabled';
      label = 'Enabled';
    } else if (normalized === 'PAUSED') {
      className += ' is-paused';
      label = 'Paused';
    }
    return '<span class="' + className + '" title="' + escapeHtml(label) + '" aria-label="' + escapeHtml(label) + '"></span>';
  }

  function isLocalServicesCampaign(row) {
    var name = String((row && row.name) || '');
    return /^LocalServicesCampaign/i.test(name) || /Local\s*Services/i.test(name);
  }

  function renderLocalServicesValueInfo() {
    return '<span class="avia-lsa-value-offline" title="Local Services value is offline — not booking revenue">offline</span>' +
      '<button type="button" class="avia-lsa-value-info" data-open-lsa-value="1" title="Why Local Services has no conversion value" aria-label="Why Local Services has no conversion value" tabindex="-1"><span class="avia-lsa-value-info-icon" aria-hidden="true">i</span></button>';
  }

  function formatConversionStatusLabel(status) {
    var key = String(status || '').toLowerCase();
    if (key === 'uploaded') {
      return 'submission';
    }
    return key;
  }

  function openLsaValueModal() {
    var $modal = $('#avia-lsa-value-modal');
    if (!$modal.length) {
      return;
    }
    $modal.removeAttr('hidden').attr('aria-hidden', 'false').addClass('is-open');
    $('body').addClass('avia-modal-open');
    $modal.find('.avia-modal-close').trigger('focus');
  }

  function closeLsaValueModal() {
    var $modal = $('#avia-lsa-value-modal');
    if (!$modal.length) {
      return;
    }
    $modal.attr('hidden', 'hidden').attr('aria-hidden', 'true').removeClass('is-open');
    if (!$('.avia-modal.is-open').length) {
      $('body').removeClass('avia-modal-open');
    }
  }

  $(document).on('click', '[data-open-lsa-value="1"]', function (event) {
    event.preventDefault();
    openLsaValueModal();
  });
  $(document).on('click', '[data-close-lsa-value="1"]', function () {
    closeLsaValueModal();
  });
  $(document).on('keydown', function (event) {
    if (event.key === 'Escape' && $('#avia-lsa-value-modal.is-open').length) {
      closeLsaValueModal();
    }
  });

  function campaignValueForTotals(row) {
    if (isLocalServicesCampaign(row)) {
      return 0;
    }
    return Number(row.conversions_value || 0);
  }

  function summarizeCampaignRows(rows) {
    var totals = {
      spend: 0,
      clicks: 0,
      impressions: 0,
      conversions: 0,
      conversions_value: 0
    };
    (rows || []).forEach(function (row) {
      totals.spend += Number(row.spend || 0);
      totals.clicks += Number(row.clicks || 0);
      totals.impressions += Number(row.impressions || 0);
      totals.conversions += Number(row.conversions || 0);
      totals.conversions_value += campaignValueForTotals(row);
    });
    totals.spend = Math.round(totals.spend * 100) / 100;
    totals.conversions = Math.round(totals.conversions * 100) / 100;
    totals.conversions_value = Math.round(totals.conversions_value * 100) / 100;
    totals.cpc = totals.clicks > 0 ? Math.round((totals.spend / totals.clicks) * 100) / 100 : 0;
    totals.roas = totals.spend > 0 ? Math.round((totals.conversions_value / totals.spend) * 100) / 100 : null;
    return totals;
  }

  function updateCampaignCount(filterMode, visibleCount, totalCount) {
    var $count = $('#avia-campaign-performance-count');
    if (!$count.length) {
      return;
    }
    var labels = {
      enabled: 'enabled',
      paused: 'paused',
      all: 'total'
    };
    $count.text(
      'Showing ' + visibleCount + ' of ' + totalCount + ' campaigns (' + (labels[filterMode] || filterMode) + ').'
    );
  }

  var campaignPerformanceTable = null;

  function destroyCampaignPerformanceTable() {
    if (campaignPerformanceTable) {
      try {
        campaignPerformanceTable.destroy();
      } catch (e) { /* ignore */ }
      campaignPerformanceTable = null;
    }
    var $existing = $('#avia-campaign-performance-table');
    if ($existing.length && $.fn.DataTable && $.fn.DataTable.isDataTable($existing)) {
      try {
        $existing.DataTable().destroy();
      } catch (e) { /* ignore */ }
    }
  }

  function renderCampaignTable(campaigns, filterMode) {
    filterMode = filterMode || ($('#avia-campaign-status-filter').val() || 'enabled');
    var totalCount = (campaigns || []).length;
    var filtered = filterCampaigns(campaigns, filterMode);
    updateCampaignCount(filterMode, filtered.length, totalCount);

    if (!filtered.length) {
      var emptyMessage = 'No campaigns match this filter for the selected date range.';
      if (filterMode === 'enabled') {
        emptyMessage = 'No enabled campaigns with data in this date range. Try "All campaigns" or a wider date range.';
      } else if (filterMode === 'paused') {
        emptyMessage = 'No paused campaigns found for this date range.';
      }
      return '<p class="description">' + emptyMessage + '</p>';
    }

    var html = '<table id="avia-campaign-performance-table" class="widefat striped avia-campaign-table"><thead><tr>';
    html += '<th>Campaign</th><th>Ad spend</th><th>Clicks</th><th>Impressions</th><th>Conversions</th><th>Conv. value</th><th>ROAS</th><th>CPC</th>';
    html += '</tr></thead><tbody>';

    filtered.forEach(function (row) {
      var isLsa = isLocalServicesCampaign(row);
      html += '<tr' + (isLsa ? ' class="avia-campaign-row-lsa"' : '') + '>';
      html += '<td data-order="' + escapeHtml(String(row.name || '').toLowerCase()) + '">';
      html += '<span class="avia-campaign-name-cell">' + renderCampaignStatusDot(row.status);
      html += '<button type="button" class="button-link avia-view-campaign-conversions" data-campaign-id="' + escapeHtml(String(row.id)) + '">' + escapeHtml(row.name || '—') + '</button>';
      html += '</span></td>';
      html += '<td data-order="' + Number(row.spend || 0) + '">' + formatMoney(row.spend) + '</td>';
      html += '<td data-order="' + Number(row.clicks || 0) + '">' + escapeHtml(String(row.clicks || 0)) + '</td>';
      html += '<td data-order="' + Number(row.impressions || 0) + '">' + escapeHtml(String(row.impressions || 0)) + '</td>';
      var convCount = Number(row.conversions || 0);
      html += '<td data-order="' + convCount + '">';
      if (convCount > 0) {
        html += '<button type="button" class="button-link avia-view-campaign-conversions avia-campaign-conversions-count" data-campaign-id="' + escapeHtml(String(row.id)) + '">' + escapeHtml(String(row.conversions || 0)) + '</button>';
      } else {
        html += escapeHtml(String(row.conversions || 0));
      }
      html += '</td>';
      if (isLsa) {
        html += '<td class="avia-campaign-lsa-value" data-order="-1">' + renderLocalServicesValueInfo() + '</td>';
        html += '<td class="avia-muted" data-order="-1">\u2014</td>';
        html += '<td class="avia-muted" data-order="-1">\u2014</td>';
      } else {
        html += '<td data-order="' + Number(row.conversions_value || 0) + '">' + formatMoney(row.conversions_value) + '</td>';
        html += '<td data-order="' + (row.roas == null || row.roas === '' ? -1 : Number(row.roas)) + '">' + formatRoas(row.roas) + '</td>';
        html += '<td data-order="' + Number(row.cpc || 0) + '">' + formatMoney(row.cpc) + '</td>';
      }
      html += '</tr>';
    });

    var totals = summarizeCampaignRows(filtered);
    html += '</tbody><tfoot><tr class="avia-campaign-total-row">';
    html += '<td><strong>Total</strong></td>';
    html += '<td><strong>' + formatMoney(totals.spend) + '</strong></td>';
    html += '<td><strong>' + escapeHtml(String(totals.clicks)) + '</strong></td>';
    html += '<td><strong>' + escapeHtml(String(totals.impressions)) + '</strong></td>';
    html += '<td><strong>' + escapeHtml(String(totals.conversions)) + '</strong></td>';
    html += '<td><strong>' + formatMoney(totals.conversions_value) + '</strong></td>';
    html += '<td><strong>' + formatRoas(totals.roas) + '</strong></td>';
    html += '<td><strong>' + formatMoney(totals.cpc) + '</strong></td>';
    html += '</tr></tfoot></table>';
    return html;
  }

  function initCampaignPerformanceTable() {
    var $table = $('#avia-campaign-performance-table');
    if (!$table.length || !$.fn.DataTable) {
      return;
    }
    destroyCampaignPerformanceTable();
    campaignPerformanceTable = $table.DataTable({
      order: [],
      paging: true,
      pageLength: 25,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      searching: true,
      info: true,
      autoWidth: false,
      scrollX: false,
      // tfoot Total stays fixed at bottom — DataTables only sorts tbody.
      language: {
        search: 'Search campaigns:',
        emptyTable: 'No campaigns in this filter.',
        zeroRecords: 'No matching campaigns.'
      },
      columnDefs: [
        { targets: [1, 2, 3, 4, 5, 6, 7], type: 'num' }
      ]
    });
  }

  var campaignConversionRequest;
  var campaignConversionTrigger;
  var campaignDailyTable;

  function closeCampaignConversions() {
    if (campaignConversionRequest) campaignConversionRequest.abort();
    if (campaignDailyTable) { campaignDailyTable.destroy(); campaignDailyTable = null; }
    $('#avia-campaign-conversions-modal').remove();
    if (!$('.avia-modal.is-open').length) $('body').removeClass('avia-modal-open');
    if (campaignConversionTrigger) campaignConversionTrigger.focus();
  }

  function conversionCount(value) {
    return Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
  }

  function renderCampaignConversions(details) {
    var range = details.range || {};
    var html = '<p class="description">Range: ' + escapeHtml(range.start_date || '') + ' to ' + escapeHtml(range.end_date || '') + '. Google Ads conversions, grouped by conversion action. Cached for 15 minutes.</p>';
    html += '<div class="avia-campaign-conversion-totals"><div><span>Conversions</span><strong>' + conversionCount(details.conversions) + '</strong></div><div><span>Conversion value</span><strong>' + formatMoney(details.value) + '</strong></div></div>';
    if (!(details.actions || []).length) return html + '<p>No Google Ads conversions for this campaign in the selected date range.</p>';
    html += '<h3>Conversion actions</h3><table class="widefat striped"><thead><tr><th>Conversion action</th><th>Category</th><th>Conversions</th><th>Conversion value</th></tr></thead><tbody>';
    (details.actions || []).forEach(function (row) {
      html += '<tr><td>' + escapeHtml(row.name) + '</td><td>' + escapeHtml((row.category || '').replace(/_/g, ' ')) + '</td><td>' + conversionCount(row.conversions) + '</td><td>' + formatMoney(row.value) + '</td></tr>';
    });
    html += '</tbody></table><h3>Daily conversion breakdown</h3><p class="description">Dates follow Google Ads reporting attribution, using the same Conversions metric as the campaign table.</p>';
    html += '<table id="avia-campaign-daily-conversions" class="widefat striped"><thead><tr><th>Date</th><th>Conversion action</th><th>Conversions</th><th>Conversion value</th></tr></thead><tbody></tbody></table>';
    return html;
  }

  $(document).on('click', '.avia-view-campaign-conversions', function () {
    var campaignId = String($(this).attr('data-campaign-id'));
    var campaign = campaignDataCache.find(function (row) { return String(row.id) === campaignId; });
    if (!campaign) return;
    closeCampaignConversions();
    campaignConversionTrigger = this;
    $('body').append('<div id="avia-campaign-conversions-modal" class="avia-modal is-open" aria-hidden="false"><div class="avia-modal-backdrop" data-close-campaign-conversions="1"></div><div class="avia-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="avia-campaign-conversions-title"><div class="avia-modal-header"><h2 id="avia-campaign-conversions-title">' + escapeHtml(campaign.name) + ' - Google Ads conversions</h2><button type="button" class="avia-modal-close" data-close-campaign-conversions="1" aria-label="Close">&times;</button></div><div class="avia-modal-body" id="avia-campaign-conversions-body" aria-live="polite"><p class="description">Loading Google Ads conversions...</p></div></div></div>');
    $('body').addClass('avia-modal-open');
    $('#avia-campaign-conversions-modal .avia-modal-close').trigger('focus');
    campaignConversionRequest = post('avia_attribution_campaign_conversions', $('<div>'), {
      campaign_id: campaignId,
      range: aviaAttributionAdmin.dashboardRange || 'last_7_days',
      start_date: aviaAttributionAdmin.dashboardStartDate || '',
      end_date: aviaAttributionAdmin.dashboardEndDate || ''
    }).done(function (response) {
      var $body = $('#avia-campaign-conversions-body');
      if (!response.success) {
        var error = response.data || {};
        $body.html(renderAdsAuthError(error.message || 'Could not load campaign conversions.', error.needs_reconnect, error.reconnect_url));
        return;
      }
      var details = response.data.details || {};
      $body.html(renderCampaignConversions(details));
      if ($('#avia-campaign-daily-conversions').length) {
        campaignDailyTable = $('#avia-campaign-daily-conversions').DataTable({
          data: details.daily || [], pageLength: 10,
          lengthMenu: [[10, 20, 30, 50, 100, 200, -1], [10, 20, 30, 50, 100, 200, 'All']], order: [[0, 'desc']],
          columns: [
            { data: 'date', render: $.fn.dataTable.render.text() },
            { data: 'action', render: $.fn.dataTable.render.text() },
            { data: 'conversions', render: function (value, type) { return type === 'display' ? conversionCount(value) : value; } },
            { data: 'value', render: function (value, type) { return type === 'display' ? formatMoney(value) : value; } }
          ]
        });
      }
    }).fail(function (xhr, status) {
      if (status !== 'abort') $('#avia-campaign-conversions-body').html('<p class="avia-fail">Could not load Google Ads conversions. Close and reopen to retry.</p>');
    });
  });

  $(document).on('click', '[data-close-campaign-conversions]', closeCampaignConversions);
  $(document).on('keydown', function (event) {
    var $modal = $('#avia-campaign-conversions-modal');
    if (!$modal.length) return;
    if (event.key === 'Escape') closeCampaignConversions();
    if (event.key === 'Tab') {
      var $focusable = $modal.find('button, a[href], input, select, [tabindex="0"]').filter(':visible');
      var first = $focusable[0];
      var last = $focusable[$focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });

  function syncAdsKeyMetricsFromCampaigns(rows) {
    var totals = summarizeCampaignRows(rows || []);
    if (!$('#avia-dashboard-metrics').length) {
      return;
    }
    $('#avia-dashboard-metrics [data-metric="spend"]').text(formatMoney(totals.spend));
    $('#avia-dashboard-metrics [data-metric="clicks"]').text(totals.clicks);
    $('#avia-dashboard-metrics [data-metric="impressions"]').text(totals.impressions);
    $('#avia-dashboard-metrics [data-metric="ads_conversions"]').text(totals.conversions);
    $('#avia-dashboard-metrics [data-metric="conv_value"]').text(formatMoney(totals.conversions_value));
    $('#avia-dashboard-metrics [data-metric="roas"]').text(formatRoas(totals.roas));
    $('#avia-dashboard-metrics [data-metric="cpc"]').text(formatMoney(totals.cpc));
  }

  function refreshCampaignTableView() {
    if (!campaignDataCache.length) {
      destroyCampaignPerformanceTable();
      $('#avia-campaign-download-csv').prop('disabled', true);
      return;
    }
    var filterMode = $('#avia-campaign-status-filter').val() || 'enabled';
    destroyCampaignPerformanceTable();
    $('#avia-campaign-performance-body').html(renderCampaignTable(campaignDataCache, filterMode));
    initCampaignPerformanceTable();
    var filtered = filterCampaigns(campaignDataCache, filterMode);
    $('#avia-campaign-download-csv').prop('disabled', !filtered.length);
    // Keep Key metrics Ads cards aligned with the visible campaign table (e.g. Enabled only).
    syncAdsKeyMetricsFromCampaigns(filtered);
  }

  function csvEscape(value) {
    var text = value == null ? '' : String(value);
    if (/[",\n\r]/.test(text)) {
      return '"' + text.replace(/"/g, '""') + '"';
    }
    return text;
  }

  function downloadCampaignCsv() {
    var filterMode = $('#avia-campaign-status-filter').val() || 'enabled';
    var filtered = filterCampaigns(campaignDataCache, filterMode);
    if (!filtered.length) {
      return;
    }

    var rangeLabel = $('#avia-campaign-performance-meta').text() || '';
    var headers = [
      'Campaign',
      'Campaign state',
      'Ad spend',
      'Clicks',
      'Impr.',
      'Conversions',
      'Conv. value',
      'ROAS',
      'Avg. CPC'
    ];
    var lines = [headers.map(csvEscape).join(',')];

    filtered.forEach(function (row) {
      var isLsa = isLocalServicesCampaign(row);
      lines.push(
        [
          row.name || '',
          row.status || '',
          row.spend != null ? Number(row.spend).toFixed(2) : '0.00',
          row.clicks != null ? row.clicks : 0,
          row.impressions != null ? row.impressions : 0,
          row.conversions != null ? row.conversions : 0,
          isLsa ? 'See Local Services reports (not booking revenue)' : (row.conversions_value != null ? Number(row.conversions_value).toFixed(2) : '0.00'),
          isLsa ? '' : (row.roas != null ? Number(row.roas).toFixed(2) : ''),
          isLsa ? '' : (row.cpc != null ? Number(row.cpc).toFixed(2) : '0.00')
        ]
          .map(csvEscape)
          .join(',')
      );
    });

    var totals = summarizeCampaignRows(filtered);
    lines.push(
      [
        'Total',
        '',
        totals.spend.toFixed(2),
        totals.clicks,
        totals.impressions,
        totals.conversions,
        totals.conversions_value.toFixed(2),
        totals.roas != null ? totals.roas.toFixed(2) : '',
        totals.cpc.toFixed(2)
      ]
        .map(csvEscape)
        .join(',')
    );

    var blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    var stamp = new Date().toISOString().slice(0, 10);
    link.href = url;
    link.download = 'avia-campaign-performance-' + filterMode + '-' + stamp + '.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  var metricLayoutState = (aviaAttributionAdmin.metricLayout || []).slice();
  var maxMetricsPerRow = Math.max(2, parseInt(aviaAttributionAdmin.maxMetricsPerRow, 10) || 6);

  function countVisibleMetrics(layout) {
    return (layout || []).filter(function (item) { return item.visible; }).length;
  }

  /** Pick columns so rows stay balanced; never more than maxMetricsPerRow, never fewer than 6 when wrapping. */
  function metricsColumnsForCount(count) {
    var n = Math.max(0, parseInt(count, 10) || 0);
    var minCols = Math.min(6, maxMetricsPerRow);
    if (n <= 0) {
      return minCols;
    }
    if (n <= maxMetricsPerRow) {
      return n;
    }
    var best = maxMetricsPerRow;
    var bestScore = Infinity;
    for (var cols = maxMetricsPerRow; cols >= minCols; cols--) {
      var rows = Math.ceil(n / cols);
      var last = n - ((rows - 1) * cols);
      var score = (rows * 100) + (cols - last);
      if (score < bestScore) {
        bestScore = score;
        best = cols;
      }
    }
    return best;
  }

  function applyMetricsGridColumns(layout) {
    var $grid = $('#avia-dashboard-metrics');
    if (!$grid.length) {
      return;
    }
    var cols = metricsColumnsForCount(countVisibleMetrics(layout || metricLayoutState));
    $grid.css('--avia-metrics-cols', String(cols));
  }

  function renderAdsAuthError(message, needsReconnect, reconnectUrl) {
    var html = '<div class="avia-ads-auth-error">';
    html += '<p class="description avia-fail">' + escapeHtml(message || 'Campaign data unavailable.') + '</p>';
    if (needsReconnect && reconnectUrl) {
      // html += '<p><a class="button button-primary" href="' + escapeHtml(reconnectUrl) + '">Reconnect Google Ads</a></p>';
      html += '<p class="description">OAuth refresh token expired or revoked. Reconnect to restore spend, ROAS, and campaign reports.</p>';
    }
    html += '</div>';
    return html;
  }

  var overviewAdsRequest;
  function loadDashboardAdsPerformance() {
    if (!aviaAttributionAdmin.hasAdsApi || !$('#avia-dashboard-metrics').length) {
      return;
    }

    $('#avia-campaign-performance-meta').text('Loading from Google Ads…');
    $('#avia-campaign-performance-body').html('<p class="description">Loading campaign data…</p>');
    $('#avia-campaign-download-csv').prop('disabled', true);

    if (overviewAdsRequest) overviewAdsRequest.abort();
    $('#avia-dashboard-metrics .avia-metric-card[data-metric-group="ads"]').addClass('avia-dashboard-card-loading');
    overviewAdsRequest = post('avia_attribution_dashboard_ads', $('<div>'), {
      range: aviaAttributionAdmin.dashboardRange || 'last_7_days',
      start_date: aviaAttributionAdmin.dashboardStartDate || '',
      end_date: aviaAttributionAdmin.dashboardEndDate || '',
      campaign_ids: overviewCampaignIds
    })
      .done(function (response) {
        if (!response.success) {
          var data = (response && response.data) || {};
          var needsReconnect = !!data.needs_reconnect;
          var reconnectUrl = data.reconnect_url || aviaAttributionAdmin.googleReconnectUrl || '';
          $('#avia-dashboard-metrics .avia-metric-card[data-metric-group="ads"]').removeClass('avia-dashboard-card-loading');
          $('#avia-campaign-performance-meta').text(
            needsReconnect ? 'Google Ads authorization required.' : 'Campaign data unavailable.'
          );
          $('#avia-campaign-performance-body').html(
            renderAdsAuthError(data.message || 'Campaign data unavailable.', needsReconnect, reconnectUrl)
          );
          $('#avia-campaign-download-csv').prop('disabled', true);
          return;
        }

        var account = response.data.account || {};
        $('#avia-dashboard-metrics .avia-metric-card[data-metric-group="ads"]').removeClass('avia-dashboard-card-loading');

        $('#avia-dashboard-metrics [data-metric="spend"]').text(formatMoney(account.spend));
        $('#avia-dashboard-metrics [data-metric="clicks"]').text(account.clicks != null ? account.clicks : '—');
        $('#avia-dashboard-metrics [data-metric="impressions"]').text(account.impressions != null ? account.impressions : '—');
        $('#avia-dashboard-metrics [data-metric="ads_conversions"]').text(account.conversions != null ? account.conversions : '—');
        $('#avia-dashboard-metrics [data-metric="conv_value"]').text(formatMoney(account.conversions_value));
        $('#avia-dashboard-metrics [data-metric="roas"]').text(formatRoas(account.roas));
        $('#avia-dashboard-metrics [data-metric="cpc"]').text(formatMoney(account.cpc));

        var rangeLabel = account.range_label || aviaAttributionAdmin.dashboardRange || '';
        if (account.start_date && account.end_date) {
          rangeLabel = account.start_date + ' to ' + account.end_date;
        }
        $('#avia-campaign-performance-meta').text(
          'Range: ' + rangeLabel + '. Cached for 15 minutes. Last fetched: ' + (account.fetched_at || 'now') + '.'
        );
        renderOverviewCampaignOptions(response.data.campaign_options || []);
        campaignDataCache = response.data.campaigns || [];
        $('#avia-campaign-status-filter').val(overviewCampaignIds.length ? 'all' : 'enabled');
        refreshCampaignTableView();
      })
      .fail(function (xhr, status) {
        if (status === 'abort') return;
        $('#avia-dashboard-metrics .avia-metric-card[data-metric-group="ads"]').removeClass('avia-dashboard-card-loading');
        $('#avia-campaign-performance-meta').text('Campaign data unavailable.');
        $('#avia-campaign-performance-body').html('<p class="description">AJAX request failed.</p>');
        $('#avia-campaign-download-csv').prop('disabled', true);
      });
  }

  $('#avia-campaign-status-filter').on('change', function () {
    refreshCampaignTableView();
  });

  $('#avia-campaign-download-csv').on('click', function () {
    downloadCampaignCsv();
  });

  function applyMetricLayoutToCards(layout) {
    layout.forEach(function (item, index) {
      var $card = $('#avia-dashboard-metrics .avia-metric-card[data-metric-id="' + item.id + '"]');
      if (!$card.length) {
        return;
      }
      $card.css('order', index);
      if (item.visible) {
        $card.removeClass('is-metric-hidden').removeAttr('hidden').attr('data-metric-visible', '1');
      } else {
        $card.addClass('is-metric-hidden').attr('hidden', 'hidden').attr('data-metric-visible', '0');
      }
    });
    applyMetricsGridColumns(layout);
  }

  function renderCustomizeMetricsList() {
    var $list = $('#avia-customize-metrics-list');
    if (!$list.length) {
      return;
    }

    var html = '';
    metricLayoutState.forEach(function (item, index) {
      var eyeClass = item.visible ? 'dashicons-visibility' : 'dashicons-hidden';
      var rowClass = item.visible ? 'is-visible' : 'is-hidden-metric';
      html += '<li class="avia-customize-metric-row ' + rowClass + '" data-metric-id="' + escapeHtml(item.id) + '">';
      html += '<button type="button" class="avia-metric-visibility-toggle" aria-pressed="' + (item.visible ? 'true' : 'false') + '" title="Toggle visibility">';
      html += '<span class="dashicons ' + eyeClass + '" aria-hidden="true"></span>';
      html += '</button>';
      html += '<span class="avia-customize-metric-label">' + escapeHtml(item.label) + '</span>';
      html += '<span class="avia-customize-metric-actions">';
      html += '<button type="button" class="avia-metric-move-up" title="Move up"' + (index === 0 ? ' disabled' : '') + '><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>';
      html += '<button type="button" class="avia-metric-move-down" title="Move down"' + (index === metricLayoutState.length - 1 ? ' disabled' : '') + '><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>';
      html += '</span></li>';
    });
    $list.html(html);
  }

  function saveMetricLayout(options) {
    options = options || {};
    var payload = metricLayoutState.map(function (item, index) {
      return { id: item.id, visible: !!item.visible, order: index };
    });

    return post('avia_attribution_save_dashboard_metrics', $('<div>'), {
      layout: JSON.stringify(payload),
      reset: options.reset ? '1' : ''
    }).done(function (response) {
      if (response.success && response.data && response.data.layout) {
        metricLayoutState = response.data.layout.slice();
        applyMetricLayoutToCards(metricLayoutState);
        renderCustomizeMetricsList();
      }
    });
  }

  function moveMetric(metricId, direction) {
    var index = metricLayoutState.findIndex(function (item) { return item.id === metricId; });
    if (index < 0) {
      return;
    }
    var target = direction === 'up' ? index - 1 : index + 1;
    if (target < 0 || target >= metricLayoutState.length) {
      return;
    }
    var temp = metricLayoutState[target];
    metricLayoutState[target] = metricLayoutState[index];
    metricLayoutState[index] = temp;
    applyMetricLayoutToCards(metricLayoutState);
    renderCustomizeMetricsList();
    saveMetricLayout();
  }

  $('#avia-customize-metrics-toggle').on('click', function (event) {
    event.stopPropagation();
    var $panel = $('#avia-customize-metrics-panel');
    var isOpen = !$panel.prop('hidden');
    $panel.prop('hidden', isOpen);
    $(this).attr('aria-expanded', isOpen ? 'false' : 'true');
    if (!isOpen) {
      renderCustomizeMetricsList();
    }
  });

  $(document).on('click', function () {
    $('#avia-customize-metrics-panel').prop('hidden', true);
    $('#avia-customize-metrics-toggle').attr('aria-expanded', 'false');
  });

  $(document).on('click', '.avia-customize-metrics-panel', function (event) {
    event.stopPropagation();
  });

  $('#avia-customize-metrics-reset').on('click', function () {
    saveMetricLayout({ reset: true });
  });

  $(document).on('click', '.avia-metric-visibility-toggle', function () {
    var metricId = $(this).closest('.avia-customize-metric-row').data('metric-id');
    metricLayoutState = metricLayoutState.map(function (item) {
      if (item.id === metricId) {
        item.visible = !item.visible;
      }
      return item;
    });
    applyMetricLayoutToCards(metricLayoutState);
    renderCustomizeMetricsList();
    saveMetricLayout();
  });

  applyMetricsGridColumns(metricLayoutState);

  $(document).on('click', '.avia-metric-move-up', function () {
    moveMetric($(this).closest('.avia-customize-metric-row').data('metric-id'), 'up');
  });

  $(document).on('click', '.avia-metric-move-down', function () {
    moveMetric($(this).closest('.avia-customize-metric-row').data('metric-id'), 'down');
  });

  function boulevardClientDashboardUrl(clientId) {
    var id = String(clientId || '').trim();
    var match = id.match(/^urn:blvd:Client:([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i)
      || id.match(/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i);
    return match ? 'https://dashboard.boulevard.io/clients/' + match[1] : '';
  }

  function renderModalAccordion(title, bodyHtml, isOpen) {
    return '<details class="avia-modal-section avia-collapsible-panel avia-modal-accordion"' + (isOpen ? ' open' : '') + '>'
      + '<summary><h3>' + escapeHtml(title) + '</h3><span class="avia-collapse-icon" aria-hidden="true"></span></summary>'
      + '<div class="avia-collapsible-body">' + bodyHtml + '</div>'
      + '</details>';
  }

  function renderConversionModal(details) {
    var upload = details.upload || {};
    var client = details.client || {};
    var appointment = details.appointment || {};
    var attribution = details.attribution || {};
    var html = '';
    var blvdUrl = boulevardClientDashboardUrl(client.blvd_client_id);
    var nameHtml = '<strong class="avia-client-name">' + escapeHtml(client.name || '—') + '</strong>';
    if (blvdUrl) {
      nameHtml += ' <a class="button button-small avia-blvd-client-link" href="' + escapeHtml(blvdUrl) + '" target="_blank" rel="noopener noreferrer">View in Boulevard</a>';
    }

    var clientBody = '<table class="widefat striped avia-lookup-table"><tbody>';
    clientBody += '<tr><th scope="row">Name</th><td class="avia-client-name-cell">' + nameHtml + '</td></tr>';
    clientBody += '<tr><th scope="row">Email</th><td><strong class="avia-client-email">' + escapeHtml(client.email || '—') + '</strong></td></tr>';
    clientBody += '<tr><th scope="row">Phone</th><td>' + escapeHtml(client.phone || '—') + '</td></tr>';
    clientBody += '<tr><th scope="row">Tags</th><td>' + (renderClientTags(client.tags) || '—') + '</td></tr>';
    clientBody += '<tr><th scope="row">Referral source</th><td>' + escapeHtml(client.referral_source || '—') + '</td></tr>';
    clientBody += '<tr><th scope="row">Boulevard client ID</th><td><code>' + escapeHtml(client.blvd_client_id || '—') + '</code></td></tr>';
    clientBody += '<tr><th scope="row">Client created</th><td>' + escapeHtml(client.blvd_created_at || '—') + '</td></tr>';
    clientBody += '</tbody></table>';
    html += renderModalAccordion('Client', clientBody, true);

    var conversionBody = '<table class="widefat striped avia-lookup-table"><tbody>';
    conversionBody += '<tr><th scope="row">Status</th><td>' + escapeHtml(upload.status || '—') + '</td></tr>';
    conversionBody += '<tr><th scope="row">Event</th><td><code>' + escapeHtml(upload.event_type || '—') + '</code></td></tr>';
    var clickCell = upload.click_id
      ? '<code>' + escapeHtml(upload.click_id) + '</code>'
      : (upload.is_ads_attributed === false
        ? '<span class="avia-muted">— <em>Not ads-attributed</em></span>'
        : '<code>—</code>');
    conversionBody += '<tr><th scope="row">Click ID</th><td>' + clickCell + '</td></tr>';
    conversionBody += '<tr><th scope="row">Transaction ID</th><td><code>' + escapeHtml(upload.transaction_id || '—') + '</code></td></tr>';
    conversionBody += '<tr><th scope="row">Value</th><td>' + formatMoney(upload.conversion_value) + ' ' + escapeHtml(upload.currency_code || 'USD') + '</td></tr>';
    conversionBody += '<tr><th scope="row">Queued</th><td>' + escapeHtml(upload.created_at || '—') + '</td></tr>';
    conversionBody += '<tr><th scope="row">Lead date</th><td>' + escapeHtml((details.attribution && details.attribution.session_created_at) || '—') + '</td></tr>';
    conversionBody += '<tr><th scope="row">Uploaded</th><td>' + escapeHtml(upload.uploaded_at || '—') + '</td></tr>';
    if (upload.error_message) {
      conversionBody += '<tr><th scope="row">Error</th><td class="avia-error-cell">' + escapeHtml(upload.error_message) + '</td></tr>';
    }
    conversionBody += '</tbody></table>';
    html += renderModalAccordion('Conversion', conversionBody, true);

    var writebackBody = '<p class="description">Boulevard CRM custom fields (written at booking). Separate from the Click ID used for Google Ads above.</p>';
    writebackBody += '<table class="widefat striped avia-lookup-table"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
    if (client.custom_fields && Object.keys(client.custom_fields).length) {
      Object.keys(client.custom_fields).forEach(function (key) {
        var value = client.custom_fields[key] || '';
        writebackBody += '<tr><td><code>' + escapeHtml(key) + '</code></td><td>' + (value ? '<code>' + escapeHtml(value) + '</code>' : '<em>empty</em>') + '</td></tr>';
      });
    } else {
      writebackBody += '<tr><td colspan="2"><em>No write-back fields loaded.</em></td></tr>';
    }
    writebackBody += '</tbody></table>';
    html += renderModalAccordion('Write-back fields (Boulevard CRM)', writebackBody, false);

    var appointmentBody = '<table class="widefat striped avia-lookup-table"><tbody>';
    appointmentBody += '<tr><th scope="row">Appointment ID</th><td><code>' + escapeHtml(appointment.blvd_appointment_id || '—') + '</code></td></tr>';
    appointmentBody += '<tr><th scope="row">State</th><td>' + escapeHtml(appointment.event_status || '—') + '</td></tr>';
    appointmentBody += '<tr><th scope="row">Completed</th><td>' + escapeHtml(appointment.completed_at || '—') + '</td></tr>';
    if (appointment.revenue_cents != null) {
      appointmentBody += '<tr><th scope="row">Revenue</th><td>' + formatMoney(appointment.revenue_cents / 100) + '</td></tr>';
    }
    appointmentBody += '</tbody></table>';
    html += renderModalAccordion('Appointment', appointmentBody, false);

    var attributionBody = '<table class="widefat striped avia-lookup-table"><tbody>';
    attributionBody += '<tr><th scope="row">att_id</th><td><code>' + escapeHtml(upload.att_id || '—') + '</code></td></tr>';
    attributionBody += '<tr><th scope="row">utm_source</th><td><code>' + escapeHtml(attribution.utm_source || '—') + '</code></td></tr>';
    attributionBody += '<tr><th scope="row">utm_medium</th><td><code>' + escapeHtml(attribution.utm_medium || '—') + '</code></td></tr>';
    attributionBody += '<tr><th scope="row">utm_campaign</th><td><code>' + escapeHtml(attribution.utm_campaign || '—') + '</code></td></tr>';
    attributionBody += '<tr><th scope="row">Landing page</th><td>' + escapeHtml(attribution.landing_page || '—') + '</td></tr>';
    attributionBody += '<tr><th scope="row">Session captured</th><td>' + escapeHtml(attribution.session_created_at || '—') + '</td></tr>';
    attributionBody += '</tbody></table>';
    html += renderModalAccordion('Attribution', attributionBody, false);

    var timelineHtml = renderTimelineSection(details.timeline || [], false);
    if (!timelineHtml) {
      timelineHtml = '<p class="description">No ledger events for this client yet.</p>';
    }
    html += renderModalAccordion('Event journey (ledger)', timelineHtml, true);

    return html;
  }

  function openConversionModal() {
    var $modal = $('#avia-conversion-modal');
    $modal.removeAttr('hidden').attr('aria-hidden', 'false').addClass('is-open');
    $('body').addClass('avia-modal-open');
  }

  function closeConversionModal() {
    var $modal = $('#avia-conversion-modal');
    $modal.attr('hidden', 'hidden').attr('aria-hidden', 'true').removeClass('is-open');
    if (!$('.avia-modal.is-open').length) {
      $('body').removeClass('avia-modal-open');
    }
  }

  function openMetricsHelpModal() {
    var $modal = $('#avia-metrics-help-modal');
    $modal.removeAttr('hidden').attr('aria-hidden', 'false').addClass('is-open');
    $('body').addClass('avia-modal-open');
  }

  function closeMetricsHelpModal() {
    var $modal = $('#avia-metrics-help-modal');
    $modal.attr('hidden', 'hidden').attr('aria-hidden', 'true').removeClass('is-open');
    if (!$('.avia-modal.is-open').length) {
      $('body').removeClass('avia-modal-open');
    }
  }

  function openStatusHelpModal() {
    var $modal = $('#avia-status-help-modal');
    if (!$modal.length) {
      return;
    }
    $modal.removeAttr('hidden').attr('aria-hidden', 'false').addClass('is-open');
    $('body').addClass('avia-modal-open');
  }

  function closeStatusHelpModal() {
    var $modal = $('#avia-status-help-modal');
    if (!$modal.length) {
      return;
    }
    $modal.attr('hidden', 'hidden').attr('aria-hidden', 'true').removeClass('is-open');
    if (!$('.avia-modal.is-open').length) {
      $('body').removeClass('avia-modal-open');
    }
  }

  $(document).on('click', '.avia-view-conversion', function () {
    var uploadId = $(this).data('upload-id');
    var $body = $('#avia-conversion-modal-body');
    $body.html('<p class="description">Loading…</p>');
    openConversionModal();

    post('avia_attribution_conversion_details', $('<div>'), { upload_id: uploadId })
      .done(function (response) {
        if (!response.success) {
          $body.html('<p class="avia-fail">' + escapeHtml(response.data && response.data.message ? response.data.message : 'Could not load details.') + '</p>');
          return;
        }
        $body.html(renderConversionModal(response.data.details || {}));
      })
      .fail(function () {
        $body.html('<p class="avia-fail">AJAX request failed.</p>');
      });
  });

  $(document).on('click', '[data-close-modal="1"]', function () {
    closeConversionModal();
  });

  $(document).on('click', '#avia-metrics-help-trigger', function () {
    openMetricsHelpModal();
  });

  $(document).on('click', '[data-close-metrics-help="1"]', function () {
    closeMetricsHelpModal();
  });

  $(document).on('click', '#avia-status-help-trigger', function () {
    openStatusHelpModal();
  });

  $(document).on('click', '[data-close-status-help="1"]', function () {
    closeStatusHelpModal();
  });

  $(document).on('keydown', function (event) {
    if (event.key === 'Escape' && $('#avia-status-help-modal.is-open').length) {
      closeStatusHelpModal();
    }
  });

  $(document).on('keydown', function (event) {
    if (event.key === 'Escape') {
      closeMetricsHelpModal();
      closeConversionModal();
    }
  });

  if ($('#avia-conversions-table').length) {
    var conversionsRequest;
    function conversionText(value, type) {
      return type === 'display' ? escapeHtml(value || '') : (value || '');
    }
    // Prefix+suffix alone collide on real gclids (many start CjwKCAjwo… and end …QAvD_BwE).
    function formatClickIdShort(value) {
      if (!value || value.length <= 40) return value;
      var mid = Math.floor(value.length / 2);
      return value.slice(0, 8) + '\u2026' + value.slice(mid - 6, mid + 6) + '\u2026' + value.slice(-8);
    }
    function conversionsRangePayload() {
      return {
        action: 'avia_attribution_conversions',
        nonce: aviaAttributionAdmin.nonce,
        range: aviaAttributionAdmin.dashboardRange || 'last_7_days',
        start_date: aviaAttributionAdmin.dashboardStartDate || '',
        end_date: aviaAttributionAdmin.dashboardEndDate || '',
        status: $('#avia-conversions-status-filter').val() || ''
      };
    }
    var conversionsTable = $('#avia-conversions-table').DataTable({
      processing: true,
      deferRender: true,
      pageLength: 10,
      lengthMenu: [[10, 20, 30, 50, 100, 200, -1], [10, 20, 30, 50, 100, 200, 'All']],
      order: [[6, 'desc']],
      scrollX: true,
      language: { search: 'Search conversions:', emptyTable: 'No conversions in this date range.', loadingRecords: 'Loading conversions...' },
      ajax: function (request, callback) {
        if (conversionsRequest) conversionsRequest.abort();
        $('#avia-conversions-error').prop('hidden', true);
        conversionsRequest = $.ajax({
          url: aviaAttributionAdmin.ajaxUrl,
          type: 'POST',
          data: conversionsRangePayload()
        }).done(function (response) {
          if (response.success) {
            callback({ data: response.data.rows || [] });
          } else {
            $('#avia-conversions-error').text((response.data || {}).message || 'Could not load conversions. Refresh the page or change the Performance overview date range to retry.').prop('hidden', false);
            callback({ data: [] });
          }
        }).fail(function (xhr, status) {
          if (status === 'abort') return;
          $('#avia-conversions-error').text('Could not load conversions. Refresh the page or change the Performance overview date range to retry.').prop('hidden', false);
          callback({ data: [] });
        });
      },
      columns: [
        { data: null, render: function (row, type) {
          if (type !== 'display') return (row.client_name || '') + ' ' + (row.client_email || '');
          var html = '<strong>' + escapeHtml(row.client_name || '') + '</strong>';
          if (row.client_email) html += '<br><span class="avia-muted">' + escapeHtml(row.client_email) + '</span>';
          if (row.client_referral_source) html += '<br><span class="avia-muted">Referral: ' + escapeHtml(row.client_referral_source) + '</span>';
          return html + renderClientTags(row.client_tags || []);
        } },
        { data: 'click_id', render: function (value, type, row) {
          if (type !== 'display') return value || '';
          if (!value) {
            return '';
          }
          return '<code class="avia-mono avia-click-id" title="' + escapeHtml(value) + '">' + escapeHtml(formatClickIdShort(value)) + '</code>';
        } },
        { data: 'utm_campaign', render: conversionText },
        { data: 'conversion_value', render: function (value, type) {
          return type === 'display' ? (value == null || value === '' ? '' : formatMoney(value)) : value;
        } },
        { data: 'status', render: function (value, type, row) {
          if (type !== 'display') return value || '';
          var msg = String((row && row.error_message) || '');
          var isMerged = /merged into upload #\d+/i.test(msg);
          var raw = isMerged ? 'merged' : (value || '');
          if (!raw) return '';
          var cls = isMerged ? 'merged' : (value || '');
          var label = formatConversionStatusLabel(raw);
          return '<span class="avia-status-badge avia-status-' + escapeHtml(cls) + '" title="' + escapeHtml(msg) + '">' + escapeHtml(label) + '</span>';
        } },
        { data: 'click_at', render: conversionText },
        { data: null, render: function (row, type) { return conversionText(row.event_created_at || row.created_at, type); } },
        { data: 'id', searchable: false, orderable: false, render: function (value) {
          return '<button type="button" class="button button-small avia-view-conversion" data-upload-id="' + Number(value) + '">View details</button>';
        } }
      ],
      initComplete: function () {
        var $filter = $(conversionsTable.table().container()).find('.dt-search');
        if ($filter.length && $('#avia-conversions-status-filter').length) {
          $('#avia-conversions-status-filter').closest('.avia-conversions-toolbar').addClass('avia-conversions-toolbar-inline').insertBefore($filter);
        }
      }
    });

    $('#avia-conversions-status-filter').on('change', function () {
      conversionsTable.ajax.reload();
    });
  }

  function toggleCustomRangeFields() {
    var isCustom = $('#avia-dashboard-range').val() === 'custom';
    $('.avia-custom-range-fields').prop('hidden', !isCustom);
  }

  $('#avia-dashboard-range').on('change', function () {
    toggleCustomRangeFields();
    if ($(this).val() !== 'custom') {
      $('#avia-dashboard-range-form').trigger('submit');
    }
  });

  toggleCustomRangeFields();

  var revenueInfoTrigger;
  function closeRevenueDetails() {
    $('#avia-revenue-details-modal').remove();
    if (!$('.avia-modal.is-open').length) $('body').removeClass('avia-modal-open');
    if (revenueInfoTrigger) revenueInfoTrigger.focus();
  }
  function openRevenueDetails(data, trigger) {
    closeRevenueDetails();
    revenueInfoTrigger = trigger;
    var range = data.range || {};
    var salesUrl = 'https://dashboard.boulevard.io/reports/sales_summary';
    var rows = [
      ['Date range', escapeHtml((range.start_date || '') + ' to ' + (range.end_date || ''))],
      ['Total collected', escapeHtml(formatMoney(data.collected))],
      ['Tax included', escapeHtml(formatMoney(data.tax))],
      ['Tips included', escapeHtml(formatMoney(data.tips))],
      ['Fees included', escapeHtml(formatMoney(data.fees))],
      ['Net order sales (excluding tax, tips and fees)', escapeHtml(formatMoney(data.net_sales))],
      ['Excluded account credit adjustments', escapeHtml(data.excluded_adjustments + ' orders / ' + formatMoney(data.excluded_adjustment_value))],
      ['Prior-period refunds in range', escapeHtml((data.prior_period_refunds || 0) + ' refunds / −' + formatMoney(data.prior_period_refund_value || 0))],
      ['Locations', escapeHtml((data.location_names || []).join(', '))],
      ['Timezone', escapeHtml(String(data.timezone == null ? '' : data.timezone))],
      ['Date basis', escapeHtml('Orders closed in range + refunds closed in range on earlier orders (matches Sales Summary payments)')],
      ['Boulevard report', '<a href="' + salesUrl + '" target="_blank" rel="noopener noreferrer">Open in Boulevard</a>']
    ];
    var html = '<div id="avia-revenue-details-modal" class="avia-modal is-open" aria-hidden="false"><div class="avia-modal-backdrop" data-close-revenue-details="1"></div><div class="avia-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="avia-revenue-details-title"><div class="avia-modal-header"><h2 id="avia-revenue-details-title">Boulevard revenue details</h2><button type="button" class="avia-modal-close" data-close-revenue-details="1" aria-label="Close">&times;</button></div><div class="avia-modal-body"><table class="widefat striped avia-revenue-details-table"><tbody>';
    rows.forEach(function (item) { html += '<tr><th scope="row">' + escapeHtml(item[0]) + '</th><td>' + item[1] + '</td></tr>'; });
    html += '</tbody></table><p class="description">Fetched from Boulevard orders and refunds.</p></div></div></div>';
    $('body').append(html).addClass('avia-modal-open');
    $('#avia-revenue-details-modal .avia-modal-close').trigger('focus');
  }
  $(document).on('click', '[data-close-revenue-details]', closeRevenueDetails);
  $(document).on('keydown', function (event) {
    if (!$('#avia-revenue-details-modal').length) return;
    if (event.key === 'Escape') closeRevenueDetails();
    if (event.key === 'Tab') { event.preventDefault(); $('#avia-revenue-details-modal .avia-modal-close').trigger('focus'); }
  });

  function loadBoulevardRevenue() {
    var $card = $('#avia-dashboard-metrics .avia-metric-card[data-metric-id="upload_value_incl_skips"]');
    if (!$card.length) return;
    var $value = $card.find('[data-metric="upload_value_incl_skips"]');
    $card.addClass('avia-dashboard-card-loading');
    $value.text('Loading...');
    post('avia_attribution_boulevard_revenue', $('<div>'), {
      range: aviaAttributionAdmin.dashboardRange || 'last_7_days',
      start_date: aviaAttributionAdmin.dashboardStartDate || '',
      end_date: aviaAttributionAdmin.dashboardEndDate || ''
    }).done(function (response) {
      if (!response.success) {
        $value.text('Unavailable');
        $card.attr('title', (response.data || {}).message || 'Boulevard net sales unavailable.');
        $card.append($('<p class="description avia-fail">').text((response.data || {}).message || 'Boulevard net sales unavailable.'));
        return;
      }
      $value.text(formatMoney(response.data.collected));
      var data = response.data;
      $card.find('.avia-revenue-info').remove();
      var $info = $('<button type="button" class="avia-revenue-info avia-metrics-help-trigger" aria-label="Boulevard revenue details" title="Revenue details"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span></button>');
      $card.find('.avia-card-label').append($info);
      $info.on('click', function () { openRevenueDetails(data, this); });
      var $auditButton = $('<button type="button" style="display: none !important;" class="button button-small avia-revenue-audit-download">').text('Download details');
      $auditButton.on('click', function () {
        var fields = ['included', 'exclusion_reason', 'order_id', 'order_number', 'order_note', 'line_items', 'location', 'order_date', 'closed_at', 'timezone', 'total', 'tax', 'tips', 'fees'];
        var lines = [fields.map(csvEscape).join(',')];
        (data.order_audit || []).forEach(function (row) {
          lines.push(fields.map(function (field) { return csvEscape(row[field]); }).join(','));
        });
        var blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'boulevard-order-reconciliation.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
      });
      $card.append($auditButton);


    }).fail(function () {
      $value.text('Unavailable');
      $card.append($('<p class="description avia-fail">').text('Could not fetch Boulevard net sales. Reload to retry.'));
    }).always(function () { $card.removeClass('avia-dashboard-card-loading'); });
  }

  var appointmentsInfoTrigger;
  function closeAppointmentsDetails() {
    $('#avia-appointments-details-modal').remove();
    if (!$('.avia-modal.is-open').length) $('body').removeClass('avia-modal-open');
    if (appointmentsInfoTrigger) appointmentsInfoTrigger.focus();
  }
  function openAppointmentsDetails(data, trigger) {
    closeAppointmentsDetails();
    appointmentsInfoTrigger = trigger;
    var range = data.range || {};
    var reportUrl = 'https://dashboard.boulevard.io/reports/appointments';
    var rows = [
      ['Date range', escapeHtml((range.start_date || '') + ' to ' + (range.end_date || ''))],
      ['Completed', escapeHtml(String(data.completed == null ? '' : data.completed))],
      ['Boulevard report', '<a href="' + reportUrl + '" target="_blank" rel="noopener noreferrer">Open in Boulevard</a>'],
      ['Locations', escapeHtml((data.location_names || []).join(', '))],
      ['Timezone', escapeHtml(String(data.timezone == null ? '' : data.timezone))],
      ['Date basis', escapeHtml('Appointment start date; state FINAL = completed')]
    ];
    var html = '<div id="avia-appointments-details-modal" class="avia-modal is-open" aria-hidden="false"><div class="avia-modal-backdrop" data-close-appointments-details="1"></div><div class="avia-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="avia-appointments-details-title"><div class="avia-modal-header"><h2 id="avia-appointments-details-title">Boulevard appointments completed</h2><button type="button" class="avia-modal-close" data-close-appointments-details="1" aria-label="Close">&times;</button></div><div class="avia-modal-body"><table class="widefat striped avia-revenue-details-table"><tbody>';
    rows.forEach(function (item) { html += '<tr><th scope="row">' + escapeHtml(item[0]) + '</th><td>' + item[1] + '</td></tr>'; });
    html += '</tbody></table><p class="description">Fetched from Boulevard appointments (state FINAL). Open the report link and filter Appointment State → Final for the same date range.</p></div></div></div>';
    $('body').append(html).addClass('avia-modal-open');
    $('#avia-appointments-details-modal .avia-modal-close').trigger('focus');
  }
  $(document).on('click', '[data-close-appointments-details]', closeAppointmentsDetails);
  $(document).on('keydown', function (event) {
    if (!$('#avia-appointments-details-modal').length) return;
    if (event.key === 'Escape') closeAppointmentsDetails();
    if (event.key === 'Tab') { event.preventDefault(); $('#avia-appointments-details-modal .avia-modal-close').trigger('focus'); }
  });

  function loadBoulevardAppointments() {
    var $card = $('#avia-dashboard-metrics .avia-metric-card[data-metric-id="appointments_completed"]');
    if (!$card.length) return;
    var $value = $card.find('[data-metric="appointments_completed"]');
    $card.addClass('avia-dashboard-card-loading');
    $value.text('Loading...');
    post('avia_attribution_boulevard_appointments', $('<div>'), {
      range: aviaAttributionAdmin.dashboardRange || 'last_7_days',
      start_date: aviaAttributionAdmin.dashboardStartDate || '',
      end_date: aviaAttributionAdmin.dashboardEndDate || ''
    }).done(function (response) {
      if (!response.success) {
        $value.text('Unavailable');
        $card.attr('title', (response.data || {}).message || 'Boulevard appointments unavailable.');
        $card.append($('<p class="description avia-fail">').text((response.data || {}).message || 'Boulevard appointments unavailable.'));
        return;
      }
      var data = response.data;
      $value.text(String(data.completed));
      $card.find('.avia-revenue-info, .avia-appointments-info').remove();
      var $info = $('<button type="button" class="avia-appointments-info avia-metrics-help-trigger" aria-label="Boulevard appointments details" title="Appointment details"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span></button>');
      $card.find('.avia-card-label').append($info);
      $info.on('click', function () { openAppointmentsDetails(data, this); });
    }).fail(function () {
      $value.text('Unavailable');
      $card.append($('<p class="description avia-fail">').text('Could not fetch Boulevard appointments. Reload to retry.'));
    }).always(function () { $card.removeClass('avia-dashboard-card-loading'); });
  }

  loadBoulevardRevenue();
  loadBoulevardAppointments();
  loadDashboardAdsPerformance();

  $('#avia-register-webhooks').on('click', function () {
    var $result = $('#avia-register-webhooks-result');
    if (!window.confirm('Register webhooks for every Boulevard location returned by the API?')) {
      return;
    }

    post('avia_attribution_register_webhooks', $result)
      .done(function (response) {
        if (!response.success) {
          renderResult($result, 'error', response.data && response.data.message ? response.data.message : 'Registration failed.');
          return;
        }

        var html = '<ul>';
        (response.data.results || []).forEach(function (row) {
          html += '<li>' + row.location_name + ': ' + (row.success ? 'OK' : row.message) + '</li>';
        });
        html += '</ul><p>Reload this page to see stored webhook records.</p>';
        renderResult($result, 'success', response.data.message, html);
      })
      .fail(function () {
        renderResult($result, 'error', 'AJAX request failed.');
      });
  });
})(jQuery);
