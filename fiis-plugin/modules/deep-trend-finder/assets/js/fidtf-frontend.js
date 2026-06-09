(function () {
  'use strict';

  var pollers = {};
  var pollStates = {};
  var FINAL_STATUSES = ['completed', 'completed_no_relevant_evidence', 'evidence_ingested', 'failed', 'skipped'];
  var SECONDARY_SOURCES = ['instagram', 'reddit', 'google_trends', 'google_news', 'ai_research'];

  function collectForm(form) {
    var data = new FormData(form);
    var channels = [];
    form.querySelectorAll('input[name="channels[]"]:checked').forEach(function (input) {
      if (input.value && channels.indexOf(input.value) === -1) { channels.push(input.value); }
    });
    var keywordsRaw = data.get('keywords') || '';
    return {
      user_brief: data.get('user_brief') || '',
      objective: data.get('objective') || '',
      research_mode: data.get('research_mode') || 'creative_validation',
      market: data.get('market') || '',
      language: data.get('language') || 'es',
      date_range: data.get('date_range') || 'last_30_days',
      brand: data.get('brand') || '',
      company: data.get('company') || data.get('brand') || '',
      competitors: splitList(data.get('competitors') || '', 20),
      audience: data.get('audience') || '',
      exclude_terms: splitList(data.get('exclude_terms') || '', 30),
      keywords: splitList(keywordsRaw, 30),
      channels: channels
    };
  }

  function splitList(value, limit) {
    return String(value || '').split(',').map(function (item) { return item.trim(); }).filter(Boolean).slice(0, limit || 30);
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>'"]/g, function (char) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#039;',
        '"': '&quot;'
      }[char];
    });
  }

  function sourceLabel(source) {
    var labels = {
      tiktok: 'TikTok',
      instagram: 'Instagram',
      reddit: 'Reddit',
      google_trends: 'Google Trends',
      google_news: 'Google News',
      ai_research: 'AI Research'
    };
    return labels[source] || String(source || '').replace(/_/g, ' ');
  }

  function normalizeJobs(payload) {
    var jobs = (payload && payload.jobs) || [];
    return jobs.map(function (job) {
      return {
        id: job.id || 0,
        source_key: job.source_key || '',
        status: job.status || 'planned',
        raw_count: job.raw_count || 0,
        provider_dataset_rows: job.provider_dataset_rows || 0,
        provider_dataset_total_rows: job.provider_dataset_total_rows || job.provider_dataset_rows || 0,
        flattened_raw_items: job.flattened_raw_items || job.raw_count || 0,
        normalized_count: job.normalized_count || 0,
        relevant_count: job.relevant_count || 0,
        retryable: !!job.retryable,
        error_code: job.error_code || '',
        planned_queries: job.planned_queries || [],
        planned_hashtags: job.planned_hashtags || [],
        plan_summary: job.plan_summary || {},
        limit: job.limit || 0,
        sort_strategy: job.sort_strategy || '',
        dispatch_diagnostic: job.dispatch_diagnostic || null
      };
    });
  }

  function firstNonEmpty() {
    for (var i = 0; i < arguments.length; i++) {
      var value = arguments[i];
      if (Array.isArray(value) && value.length) { return value; }
      if (typeof value === 'string' && value) { return [value]; }
    }
    return [];
  }

  function compactList(values, limit) {
    values = Array.isArray(values) ? values.filter(Boolean).slice(0, limit || 3) : [];
    return values;
  }

  function smallList(label, values, fallback) {
    values = compactList(values, 3);
    if (!values.length && fallback) { values = [fallback]; }
    return '<span><b>' + escapeHtml(label) + '</b> ' + escapeHtml(values.join(', ') || 'planned terms pending') + '</span>';
  }

  function updatePanel(root, selector, html) {
    var el = root && root.querySelector(selector);
    if (el) { el.innerHTML = html; }
  }

  function sidebarMetric(label, value) {
    return '<span><strong>' + escapeHtml(value == null || value === '' ? '-' : value) + '</strong>' + escapeHtml(label) + '</span>';
  }

  function selectedCounts(payload) {
    var channels = (payload && payload.channels) || [];
    var live = channels.filter(function (source) { return sourceLiveReady(source); }).length;
    return { selected: channels.length, live: live, planned: Math.max(0, channels.length - live) };
  }

  function updateSidebarPlanning(root, payload) {
    var summary = root && root.querySelector('[data-fidtf-sidebar-summary]');
    var actions = root && root.querySelector('[data-fidtf-sidebar-actions]');
    if (!summary) { return; }
    var counts = selectedCounts(payload || {});
    var brief = String((payload && payload.user_brief) || '').trim();
    var mode = String((payload && payload.research_mode) || 'creative_validation').replace(/_/g, ' ');
    var market = String((payload && payload.market) || 'market pending');
    summary.innerHTML = '<strong>Planning snapshot</strong>' +
      '<p>' + escapeHtml(brief ? brief.slice(0, 120) : 'Define the brief and then start the run.') + '</p>' +
      '<div class="fidtf-sidebar-kpis">' +
      sidebarMetric('selected sources', counts.selected) +
      sidebarMetric('live ready', counts.live) +
      sidebarMetric('planned only', counts.planned) +
      sidebarMetric('market', market) +
      '</div>' +
      '<p><small><strong>Mode:</strong> ' + escapeHtml(mode) + '</small></p>';
    if (actions && !actions.getAttribute('data-fidtf-run-actions-bound')) {
      actions.innerHTML = '<span class="fidtf-chip">Run actions appear here after creation</span>';
    }
  }

  function updateSidebarRun(root, run, jobs) {
    var summary = root && root.querySelector('[data-fidtf-sidebar-summary]');
    var actions = root && root.querySelector('[data-fidtf-sidebar-actions]');
    if (!summary) { return; }
    run = run || {};
    jobs = Array.isArray(jobs) ? jobs : [];
    var raw = 0;
    var normalized = 0;
    var relevant = 0;
    var liveRunning = 0;
    jobs.forEach(function (job) {
      raw += parseInt(job.provider_dataset_rows || job.raw_count || 0, 10) || 0;
      normalized += parseInt(job.normalized_count || 0, 10) || 0;
      relevant += parseInt(job.relevant_count || 0, 10) || 0;
      if (job.status === 'queued' || job.status === 'running') { liveRunning += 1; }
    });
    var runId = run.run_id || run.id || '';
    var status = run.status || 'created';
    summary.innerHTML = '<strong>Run #' + escapeHtml(runId || '-') + '</strong>' +
      '<p>Status: ' + escapeHtml(String(status).replace(/_/g, ' ')) + '</p>' +
      '<div class="fidtf-sidebar-kpis">' +
      sidebarMetric('provider rows', raw) +
      sidebarMetric('normalized', normalized) +
      sidebarMetric('relevant', relevant) +
      sidebarMetric('running sources', liveRunning) +
      '</div>';
    if (actions) {
      actions.setAttribute('data-fidtf-run-actions-bound', '1');
      actions.innerHTML = '<strong>Run actions</strong><div class="fidtf-sidebar-actions-row">' +
        '<button type="button" class="fidtf-secondary" data-fidtf-load-report data-run-id="' + escapeHtml(runId) + '">Load report</button>' +
        '<button type="button" class="fidtf-secondary" data-fidtf-load-items data-run-id="' + escapeHtml(runId) + '" data-page="1">Show evidence</button>' +
        '</div>';
    }
  }

  function updateSidebarReportLoaded(root) {
    var actions = root && root.querySelector('[data-fidtf-sidebar-actions]');
    if (!actions) { return; }
    actions.insertAdjacentHTML('beforeend', '<span class="fidtf-chip is-live">Report loaded</span>');
  }



  function setActiveSidebarLink(root, href) {
    if (!root) { return; }
    root.querySelectorAll('.fidtf-sidebar-nav a').forEach(function (link) {
      if (link.getAttribute('href') === href) { link.classList.add('is-active'); }
      else { link.classList.remove('is-active'); }
    });
  }

  function basePreflight() {
    return (window.FIDTF && FIDTF.preflight) || {};
  }

  function tiktokReady() {
    return !!(basePreflight().tiktok_live_ready || (window.FIDTF && FIDTF.tiktokLiveEnabled));
  }

  function sourceLiveReady(source) {
    var live = basePreflight().live_sources || [];
    return live.indexOf(source) !== -1 || (source === 'tiktok' && tiktokReady());
  }

  function blockingReasonText(reason) {
    switch (reason) {
      case 'global_live_disabled': return 'Global live dispatch is off.';
      case 'tiktok_live_bridge_disabled': return 'Enable TikTok bridge in add-on settings to start live TikTok collection.';
      case 'core_apify_unavailable': return 'Core Apify client is not ready.';
      case 'direct_token_missing': return 'Direct Apify token is missing.';
      case 'external_filter_missing': return 'External TikTok provider filter is missing.';
      case 'source_live_bridge_disabled': return 'The selected source bridge is disabled or its actor mapping is not ready.';
      default: return 'Live provider is not ready for the selected source.';
    }
  }

  function preflightWarningHtml(payload) {
    payload = payload || { channels: [] };
    var liveSelected = (payload.channels || []).filter(function (source) { return sourceLiveReady(source); });
    if (liveSelected.length) {
      return '<div class="fidtf-notice fidtf-success" data-fidtf-preflight-warning><strong>Live collection enabled.</strong><p>Submitting will start provider runs for: ' + escapeHtml(liveSelected.map(sourceLabel).join(', ')) + '.</p></div>';
    }
    var reasons = (basePreflight().blocking_reasons || []);
    var text = blockingReasonText(reasons[0] || 'provider_not_ready');
    return '<div class="fidtf-notice fidtf-warning" data-fidtf-preflight-warning><strong>No live source will run with the current settings.</strong><p>Planned-only sources remain labelled. ' + escapeHtml(text) + '</p></div>';
  }

  function sourceBadge(source) {
    if (sourceLiveReady(source)) { return '<span class="fidtf-chip is-live">Ready for live</span>'; }
    if (source === 'ai_research') { return '<span class="fidtf-chip">Bridge only</span>'; }
    return '<span class="fidtf-chip is-warning">Live disabled</span>';
  }

  function sourceCardText(source) {
    if (sourceLiveReady(source)) { return 'Live provider collection can start when submitted.'; }
    if (source === 'ai_research') { return 'AI research requires an explicit trusted bridge. It is not automatically scraped.'; }
    return 'Provider call is disabled until global live dispatch and the source bridge are ready.';
  }

  function selectedSourcePlanHtml(payload) {
    payload = payload || {};
    var channels = payload.channels || [];
    if (!channels.length) { return '<p class="fidtf-empty">Select at least one source.</p>'; }
    var live = [];
    var planned = [];
    channels.forEach(function (source) {
      if (sourceLiveReady(source)) { live.push(source); }
      else { planned.push(source); }
    });
    var html = '<div class="fidtf-source-readiness">';
    live.concat(planned).forEach(function (source) {
      html += '<article class="fidtf-source-card" data-fidtf-source-card="' + escapeHtml(source) + '">';
      html += '<div class="fidtf-source-card-head"><strong>' + escapeHtml(sourceLabel(source)) + '</strong>' + sourceBadge(source) + '</div>';
      html += '<p>' + escapeHtml(sourceCardText(source)) + '</p>';
      html += '</article>';
    });
    html += '</div>';
    return html;
  }

  function sourcePlanSummaryHtml(source, planRow, job) {
    planRow = planRow || {};
    job = job || {};
    var summary = job.plan_summary || {};
    var merged = Object.assign({}, planRow, summary);
    var sourceKey = String(source || job.source_key || '').toLowerCase();
    var html = '<div class="fidtf-source-meta">';

    if (sourceKey === 'tiktok') {
      html += smallList('Queries', firstNonEmpty(merged.queries, job.planned_queries), 'planned query pending');
      html += smallList('Hashtags', firstNonEmpty(merged.hashtags, job.planned_hashtags), 'planned hashtags pending');
      html += smallList('Formats', merged.creator_or_format_hints, 'creator/content hints pending');
    } else if (sourceKey === 'instagram') {
      html += smallList('Hashtags', firstNonEmpty(merged.hashtags, job.planned_hashtags), 'planned hashtags pending');
      html += smallList('Topic hints', merged.profile_or_topic_hints, 'planned profile hints pending');
    } else if (sourceKey === 'reddit') {
      html += smallList('Queries', firstNonEmpty(merged.queries, job.planned_queries), 'planned query pending');
      html += smallList('Subreddits', merged.subreddits, 'subreddit discovery pending');
    } else if (sourceKey === 'google_trends') {
      html += smallList('Seed keywords', firstNonEmpty(merged.seed_keywords, job.planned_queries), 'planned keyword pending');
      html += smallList('Related hints', merged.related_query_hints, 'related query pending');
    } else if (sourceKey === 'google_news') {
      html += smallList('Queries', firstNonEmpty(merged.queries, job.planned_queries), 'planned query pending');
      html += smallList('Publisher hints', merged.publisher_or_topic_hints, 'publisher hints pending');
    } else if (sourceKey === 'ai_research') {
      html += smallList('Research questions', firstNonEmpty(merged.research_questions, job.planned_queries), 'research question pending');
      html += smallList('Assumptions', merged.assumptions_to_test, 'assumption pending');
    } else {
      html += smallList('Planned', firstNonEmpty(job.planned_queries, merged.queries, merged.hashtags), 'planned query pending');
    }

    html += '</div>';
    return html;
  }

  function statusBadge(status, errorCode) {
    if (status === 'queued' || status === 'running') { return '<span class="fidtf-status is-live">Live collection</span>'; }
    if (status === 'completed' || status === 'completed_no_relevant_evidence') { return '<span class="fidtf-status is-done">Completed</span>'; }
    if (errorCode && errorCode.indexOf('disabled') !== -1) { return '<span class="fidtf-status is-warning">Live bridge disabled</span>'; }
    if (status === 'waiting_for_provider' || status === 'planned') { return '<span class="fidtf-status">Planned only</span>'; }
    return '<span class="fidtf-status is-warning">' + escapeHtml(status || 'planned') + '</span>';
  }

  function sourceShortMessage(source, status, errorCode, job) {
    job = job || {};
    var flattened = parseInt(job.flattened_raw_items || job.raw_count || 0, 10) || 0;
    var providerRows = parseInt(job.provider_dataset_rows || 0, 10) || 0;
    var normalized = parseInt(job.normalized_count || 0, 10) || 0;
    var relevant = parseInt(job.relevant_count || 0, 10) || 0;
    if (source === 'tiktok' && (status === 'queued' || status === 'running')) { return 'TikTok provider run has started. Waiting for provider result.'; }
    if (source === 'tiktok' && status === 'retryable_failed') {
      if (errorCode === 'provider_rate_limited') { return 'TikTok provider is rate limited. The job will retry automatically.'; }
      if (errorCode === 'provider_busy') { return 'TikTok provider is temporarily busy. The job will retry automatically.'; }
      var diagMsg = job.dispatch_diagnostic && job.dispatch_diagnostic.error_message ? job.dispatch_diagnostic.error_message : '';
      return diagMsg || 'TikTok provider dispatch failed temporarily. The job will retry automatically.';
    }
    if (source === 'tiktok' && status === 'failed') {
      var failedMsg = job.dispatch_diagnostic && job.dispatch_diagnostic.error_message ? job.dispatch_diagnostic.error_message : '';
      return failedMsg || 'TikTok provider dispatch failed before usable evidence was returned.';
    }
    if (source === 'tiktok' && status === 'skipped') {
      var skippedMsg = job.dispatch_diagnostic && job.dispatch_diagnostic.error_message ? job.dispatch_diagnostic.error_message : '';
      return skippedMsg || 'TikTok live dispatch was skipped before provider collection. Check admin diagnostics.';
    }
    if (source === 'tiktok' && (status === 'waiting_for_provider' || status === 'paused_by_configuration')) {
      var waitingErrorCode = errorCode || (job.dispatch_diagnostic && job.dispatch_diagnostic.error_code ? job.dispatch_diagnostic.error_code : '');
      if (waitingErrorCode === 'backend_token_missing' || waitingErrorCode === 'missing_backend_token') {
        return 'TikTok collection requires the main plugin Apify token. Set the token in Vietnam Estudio Social settings.';
      }
      if (waitingErrorCode === 'core_apify_client_unavailable' || waitingErrorCode === 'core_apify_unavailable') {
        return 'Core Apify client is not ready. TikTok run is waiting for provider configuration.';
      }
      return 'TikTok provider is not fully configured. Live dispatch is enabled but the provider could not start.';
    }
    if (source === 'tiktok' && errorCode === 'tiktok_live_bridge_disabled') { return 'TikTok live bridge is disabled. This job is planned only.'; }
    if (source === 'tiktok' && errorCode === 'global_live_disabled') { return 'Global live dispatch is off. TikTok is planned-only.'; }
    if (source === 'tiktok' && (errorCode === 'core_apify_unavailable' || errorCode === 'core_apify_client_unavailable' || errorCode === 'core_apify_client_incomplete')) { return 'Core Apify client is not ready. TikTok is planned-only.'; }
    if (source === 'tiktok' && errorCode === 'direct_token_missing') { return 'Direct Apify token is missing. TikTok is planned-only.'; }
    if (source === 'tiktok' && errorCode === 'external_filter_missing') { return 'External TikTok provider filter is missing. TikTok is planned-only.'; }
    if (source === 'tiktok' && (status === 'completed' || status === 'completed_no_relevant_evidence')) {
      if (flattened === 0 && providerRows > 0) { return 'TikTok provider returned dataset rows, but the add-on could not extract usable posts yet.'; }
      if (flattened === 0) { return 'Discovery returned zero posts'; }
      if (normalized === 0) { return 'TikTok data was returned, but the normalizer could not extract usable post fields.'; }
      if (relevant === 0) { return 'Discovery returned posts but relevance filter rejected them'; }
      if (job.dispatch_diagnostic && job.dispatch_diagnostic.enrichment_attempted) { return 'TikTok discovery completed. TikTok enrichment completed.'; }
      if (job.dispatch_diagnostic && job.dispatch_diagnostic.enrichment_attempted === false) { return 'TikTok discovery completed. Enrichment skipped: no selected post aweme_id'; }
      return 'TikTok discovery completed';
    }
    if (source !== 'tiktok' && (status === 'queued' || status === 'running')) { return sourceLabel(source) + ' provider run has started. Waiting for provider result.'; }
    if (source !== 'tiktok' && status === 'retryable_failed') { return sourceLabel(source) + ' provider is temporarily unavailable. The job will retry automatically.'; }
    if (source !== 'tiktok' && status === 'failed') { return (job.dispatch_diagnostic && job.dispatch_diagnostic.error_message) || (sourceLabel(source) + ' provider dispatch failed.'); }
    if (status === 'completed_no_relevant_evidence') { return source === 'tiktok' ? 'Discovery returned posts but relevance filter rejected them' : sourceLabel(source) + ' returned data, but no item passed the relevance filter.'; }
    if (status === 'completed') { return 'Relevant evidence was ingested.'; }
    return sourceLiveReady(source) ? 'Ready for live collection.' : 'Planned only / bridge disabled.';
  }

  function orderSources(sources) {
    var unique = [];
    sources.forEach(function (source) {
      if (source && unique.indexOf(source) === -1) { unique.push(source); }
    });
    unique.sort(function (a, b) {
      if (a === 'tiktok') { return -1; }
      if (b === 'tiktok') { return 1; }
      return SECONDARY_SOURCES.indexOf(a) - SECONDARY_SOURCES.indexOf(b);
    });
    return unique;
  }

  function sourceRowCard(source, planRow, job) {
    job = job || {};
    var status = job.status || 'planned';
    var errorCode = job.error_code || '';
    var totalRows = parseInt(job.provider_dataset_total_rows || job.provider_dataset_rows || 0, 10) || 0;
    var countLine = 'Raw ' + escapeHtml(job.flattened_raw_items || job.raw_count || 0) + ' · Normalized ' + escapeHtml(job.normalized_count || 0) + ' · Relevant ' + escapeHtml(job.relevant_count || 0);
    if (totalRows) { countLine = 'Provider rows ' + escapeHtml(job.provider_dataset_rows || totalRows) + (totalRows > (parseInt(job.provider_dataset_rows || 0, 10) || 0) ? '/' + escapeHtml(totalRows) : '') + ' · ' + countLine; }
    var html = '<article class="fidtf-source-row" data-fidtf-source-card="' + escapeHtml(source) + '">';
    html += '<div class="fidtf-source-row-main"><div><strong>' + escapeHtml(sourceLabel(source)) + '</strong><p>' + escapeHtml(sourceShortMessage(source, status, errorCode, job)) + '</p></div>' + statusBadge(status, errorCode) + '</div>';
    html += '<small class="fidtf-count-line">' + countLine + '</small>';
    html += '<details class="fidtf-source-details"><summary>View planned terms</summary>' + sourcePlanSummaryHtml(source, planRow, job);
    if (errorCode) { html += '<small class="fidtf-error-code">Configuration state: ' + escapeHtml(errorCode) + '</small>'; }
    html += '</details>';
    html += dispatchDiagnosticPanelHtml(job);
    html += '</article>';
    return html;
  }

  function isAdminContext() {
    return !!(window.FIDTF && (FIDTF.isAdmin === true || FIDTF.adminContext === true));
  }

  function dispatchDiagnosticPanelHtml(job) {
    var diag = job && job.dispatch_diagnostic;
    if (!diag || typeof diag !== 'object') { return ''; }
    if (!isAdminContext()) { return ''; }
    var parts = [];
    function row(label, value) {
      if (value === undefined || value === null || value === '') { return; }
      parts.push('<div><b>' + escapeHtml(label) + '</b> ' + escapeHtml(String(value)) + '</div>');
    }
    row('Version', diag.addon_version);
    row('Request attempted', diag.request_attempted ? 'yes' : 'no');
    row('Provider mode (configured)', diag.provider_mode);
    row('Provider mode used', diag.provider_mode_used);
    row('Error code', diag.error_code);
    row('Error message', diag.error_message);
    if (diag.retryable) { row('Retryable', 'yes' + (diag.retry_after ? ' · retry after ' + diag.retry_after + 's' : '')); }
    row('Discovery actor', diag.discovery_actor_id);
    row('Enrichment actor', diag.enrichment_actor_id);
    row('Discovery run id', diag.discovery_run_id);
    row('Discovery rows fetched', diag.discovery_dataset_rows);
    row('Discovery rows total', diag.discovery_dataset_total_rows);
    row('Discovery flattened', diag.discovery_flattened_items);
    row('Normalized items', diag.normalized_items);
    row('Relevant items', diag.relevant_items);
    row('Enrichment attempted', diag.enrichment_attempted ? 'yes' : 'no');
    if (diag.enrichment_run_ids && diag.enrichment_run_ids.length) { row('Enrichment runs', diag.enrichment_run_ids.join(', ')); }
    row('Enrichment comments', diag.enrichment_comments_count);
    row('Outcome reason', diag.outcome_reason || diag.reason);
    var counts = diag.output_counts || {};
    if (counts && Object.keys(counts).length) {
      parts.push('<div><b>Output counts</b> dataset rows ' + escapeHtml(counts.provider_dataset_rows || 0)
        + (counts.provider_dataset_total_rows ? '/' + escapeHtml(counts.provider_dataset_total_rows) : '')
        + ' · flattened ' + escapeHtml(counts.flattened_raw_items || 0)
        + ' · normalized ' + escapeHtml(counts.normalized_items || 0)
        + ' · relevant ' + escapeHtml(counts.relevant_items || 0) + '</div>');
    }
    var views = diag.view_diagnostics;
    if (views && typeof views === 'object') {
      parts.push('<div><b>View gate</b> missing ' + escapeHtml(views.view_count_missing || 0)
        + ' · below ' + escapeHtml(views.below_min_views || 0)
        + ' · passed ' + escapeHtml(views.view_gate_passed || 0)
        + ' (threshold ' + escapeHtml(views.configured_min_views || 0) + ')</div>');
    }
    var preview = diag.discovery_actor_input || diag.actor_input_preview;
    if (preview && typeof preview === 'object') {
      var keys = Object.keys(preview).slice(0, 8);
      var pairs = keys.map(function (k) { return k + '=' + (typeof preview[k] === 'object' ? JSON.stringify(preview[k]) : preview[k]); });
      parts.push('<div><b>Discovery actor input</b> ' + escapeHtml(pairs.join(' · ')) + '</div>');
    }
    if (!parts.length) { return ''; }
    return '<details class="fidtf-source-details fidtf-dispatch-diagnostic" data-fidtf-dispatch-diagnostic><summary>Admin dispatch diagnostic</summary>'
      + '<div class="fidtf-diagnostic-grid">' + parts.join('') + '</div></details>';
  }

  function sourceRowsHtml(jobs, plan) {
    var planSources = (plan && plan.sources) || {};
    var sources = Object.keys(planSources);
    jobs.forEach(function (job) {
      if (job.source_key && sources.indexOf(job.source_key) === -1) { sources.push(job.source_key); }
    });
    sources = orderSources(sources);
    if (!sources.length) { return '<p class="fidtf-muted">No source jobs were planned.</p>'; }

    var jobsBySource = {};
    jobs.forEach(function (job) { jobsBySource[job.source_key] = job; });
    var primary = [];
    var planned = [];
    sources.forEach(function (source) {
      if (sourceLiveReady(source) || (jobsBySource[source] && ['queued','running','completed','completed_no_relevant_evidence','retryable_failed','failed'].indexOf(jobsBySource[source].status) !== -1)) { primary.push(source); } else { planned.push(source); }
    });
    var html = '<div class="fidtf-source-rows" data-fidtf-source-rows>';
    if (primary.length) {
      html += '<div class="fidtf-source-stack">';
      primary.forEach(function (source) { html += sourceRowCard(source, planSources[source] || {}, jobsBySource[source] || {}); });
      html += '</div>';
    }
    if (planned.length) {
      html += '<details class="fidtf-source-group"><summary>Inactive / bridge-only sources (' + planned.length + ')</summary><div class="fidtf-status-grid">';
      planned.forEach(function (source) { html += sourceRowCard(source, planSources[source] || {}, jobsBySource[source] || {}); });
      html += '</div></details>';
    }
    html += '</div>';
    return html;
  }

  function allJobsWaitingForProvider(jobs) {
    return jobs.length > 0 && jobs.every(function (job) {
      return job.status === 'waiting_for_provider' || job.status === 'planned';
    });
  }

  function plannedWaitingMessage(jobs, status, runIntent) {
    if (runIntent === 'planned_brief_only' || status === 'planned_waiting_for_sources' || allJobsWaitingForProvider(jobs)) {
      return '<div class="fidtf-notice fidtf-warning" data-fidtf-poll-status><strong>Planned brief only.</strong><p>No live source will run with the current settings.</p></div>';
    }
    return '<div class="fidtf-notice fidtf-warning" data-fidtf-poll-status hidden></div>';
  }

  function stableProviderBridgeMessage() {
    return '<strong>Planned brief only.</strong><p>No live source will run with the current settings.</p>';
  }

  function plannerDiagnosticsHtml(plan) {
    plan = plan || {};
    var mode = plan.planner_mode || 'local_fallback';
    var label = mode === 'external_model_bridge' ? 'AI planner bridge' : (mode === 'local_fallback_after_invalid_ai' ? 'Local fallback after invalid AI' : 'Local fallback planner');
    var html = '<div class="fidtf-notice" data-fidtf-planner-diagnostics>';
    html += '<strong>Planner mode: ' + escapeHtml(label) + '</strong>';
    if (plan.planner_notice) { html += '<p>' + escapeHtml(plan.planner_notice) + '</p>'; }
    if (plan.model_label) { html += '<small>Model: ' + escapeHtml(plan.model_label) + '</small>'; }
    if (plan.validation_error) { html += '<small>Validation: ' + escapeHtml(plan.validation_error) + '</small>'; }
    html += '</div>';
    return html;
  }

  function totalRelevant(jobs) {
    return jobs.reduce(function (sum, job) { return sum + (parseInt(job.relevant_count || 0, 10) || 0); }, 0);
  }

  function updateEvidenceButton(container, jobs) {
    var btn = container.querySelector('[data-fidtf-load-items]');
    if (!btn) { return; }
    if (totalRelevant(jobs) <= 0) {
      btn.disabled = true;
      btn.textContent = 'No evidence yet';
      btn.title = 'No relevant evidence has been ingested yet.';
    } else {
      btn.disabled = false;
      btn.textContent = 'Show evidence';
      btn.title = '';
    }
  }

  function renderRun(output, result) {
    var breakdown = (result.credit_estimate && result.credit_estimate.breakdown) || {};
    var jobs = normalizeJobs(result);
    var root = output.closest('[data-fidtf-root]');
    var runIntent = result.run_intent || 'planned_brief_only';
    output.hidden = false;
    output.innerHTML = '';
    output.setAttribute('data-fidtf-current-run-id', String(result.run_id || ''));
    output.fidtfPlan = result.plan || {};
    if (root) {
      updatePanel(root, '[data-fidtf-progressive-rows]', sourceRowsHtml(jobs, result.plan || {}));
      updatePanel(root, '[data-fidtf-report-shell]', '<div class="fidtf-warning-box"><strong>Run created.</strong><p>Report interpretation will stay limited until relevant evidence exists.</p></div>');
      updateSidebarRun(root, result, jobs);
    }

    var html = '';
    html += '<div class="fidtf-result-card">';
    html += '<p class="fidtf-kicker">Run #' + escapeHtml(result.run_id || '-') + '</p>';
    html += '<h3>' + escapeHtml(result.message || (runIntent.indexOf('live_') === 0 ? 'Deep trend run started' : 'Planned trend brief created')) + '</h3>';
    html += plannerDiagnosticsHtml(result.plan || {});
    html += '<p class="fidtf-muted" data-fidtf-run-status>Status: ' + escapeHtml(result.status || 'created') + '</p>';
    html += '<p class="fidtf-muted" data-fidtf-run-intent>Run intent: ' + escapeHtml(runIntent.replace(/_/g, ' ')) + '</p>';
    html += '<p class="fidtf-muted" data-fidtf-credit-mode>Credit mode: ' + escapeHtml(result.credit_mode || 'planning_only') + '</p>';
    if ((result.credit_mode || 'planning_only') === 'planning_only') {
      html += '<div class="fidtf-notice fidtf-warning" data-fidtf-credit-planning>Planning estimate only. No final credits were settled.</div>';
    }
    html += plannedWaitingMessage(jobs, result.status || '', runIntent);
    html += sourceRowsHtml(jobs, result.plan || {});
    html += '<h4>Estimated credits</h4><ul class="fidtf-inline-list">';
    Object.keys(breakdown).forEach(function (key) {
      html += '<li>' + escapeHtml(key.replace(/_/g, ' ')) + ': ' + escapeHtml(breakdown[key]) + '</li>';
    });
    html += '</ul>';
    html += '<div class="fidtf-actions">';
    html += '<button type="button" class="fidtf-secondary" data-fidtf-load-report data-run-id="' + escapeHtml(result.run_id || '') + '">Load report</button>';
    html += '<button type="button" class="fidtf-secondary" data-fidtf-load-items data-run-id="' + escapeHtml(result.run_id || '') + '" data-page="1">Show evidence</button>';
    html += '</div>';
    html += '<div class="fidtf-items" data-fidtf-items></div>';
    html += '</div>';
    output.innerHTML = html;
    updateEvidenceButton(output, jobs);
    startPolling(output, result.run_id);
  }

  function updateSourceRows(output, jobs, plan) {
    var rows = output.querySelector('[data-fidtf-source-rows]');
    if (rows) {
      rows.outerHTML = sourceRowsHtml(jobs, plan || output.fidtfPlan || {});
    }
    updateEvidenceButton(output, jobs);
  }

  function updatePollStatus(output, html, hidden) {
    var el = output.querySelector('[data-fidtf-poll-status]');
    if (!el) { return; }
    el.hidden = !!hidden;
    if (html) { el.innerHTML = html; }
  }

  function updateRunView(output, payload) {
    if (!payload || !payload.run) { return; }
    var statusEl = output.querySelector('[data-fidtf-run-status]');
    if (statusEl) { statusEl.textContent = 'Status: ' + (payload.run.status || 'unknown'); }
    var creditModeEl = output.querySelector('[data-fidtf-credit-mode]');
    if (creditModeEl) { creditModeEl.textContent = 'Credit mode: ' + (payload.run.credit_mode || 'planning_only'); }
    var jobs = normalizeJobs(payload);
    var root = output.closest('[data-fidtf-root]');
    updateSourceRows(output, jobs, payload.plan || output.fidtfPlan || {});
    if (root) {
      updatePanel(root, '[data-fidtf-progressive-rows]', sourceRowsHtml(jobs, payload.plan || output.fidtfPlan || {}));
      updateSidebarRun(root, payload.run, jobs);
    }
    var hasRunningTikTok = jobs.some(function (job) { return job.source_key === 'tiktok' && (job.status === 'queued' || job.status === 'running'); });
    var retryableTikTok = jobs.find(function (job) { return job.source_key === 'tiktok' && job.status === 'retryable_failed'; });
    var tiktokFinished = jobs.filter(function (job) { return job.source_key === 'tiktok' && job.status === 'completed_no_relevant_evidence'; });
    var hasProviderRowsButNoFlattenTikTok = tiktokFinished.some(function (job) {
      var flat = parseInt(job.flattened_raw_items || job.raw_count || 0, 10) || 0;
      var providerRows = parseInt(job.provider_dataset_rows || 0, 10) || 0;
      return providerRows > 0 && flat === 0;
    });
    var hasZeroRawTikTok = tiktokFinished.some(function (job) {
      var flat = parseInt(job.flattened_raw_items || job.raw_count || 0, 10) || 0;
      var providerRows = parseInt(job.provider_dataset_rows || 0, 10) || 0;
      return flat === 0 && providerRows === 0;
    });
    var hasNormalizerFailureTikTok = tiktokFinished.some(function (job) {
      var flat = parseInt(job.flattened_raw_items || job.raw_count || 0, 10) || 0;
      var norm = parseInt(job.normalized_count || 0, 10) || 0;
      return flat > 0 && norm === 0;
    });
    var hasNoRelevantTikTok = tiktokFinished.some(function (job) {
      var norm = parseInt(job.normalized_count || 0, 10) || 0;
      var rel = parseInt(job.relevant_count || 0, 10) || 0;
      return norm > 0 && rel === 0;
    });
    if (hasRunningTikTok) {
      updatePollStatus(output, '<strong>TikTok collection started.</strong><p>Waiting for provider result. Other sources remain planned-only.</p>', false);
    } else if (retryableTikTok) {
      updatePollStatus(output, '<strong>TikTok provider is temporarily unavailable.</strong><p>This is retryable. The add-on will keep polling and retry the provider dispatch instead of treating the source as planned-only.</p>', false);
    } else if (hasProviderRowsButNoFlattenTikTok) {
      updatePollStatus(output, '<strong>TikTok provider returned rows, but no usable posts were extracted.</strong><p>Use force_provider_refresh=1 from the admin run URL, or check admin diagnostics for the output shape.</p>', false);
    } else if (hasZeroRawTikTok) {
      updatePollStatus(output, '<strong>Discovery returned zero posts</strong><p>The discovery actor finished without returning posts for the current query. Try widening the brief, hashtags, market, or limit.</p>', false);
    } else if (hasNormalizerFailureTikTok) {
      updatePollStatus(output, '<strong>TikTok data was returned, but the normalizer could not extract usable post fields.</strong><p>The actor returned dataset rows in an unexpected shape. Check admin diagnostics for the actor output sample.</p>', false);
    } else if (hasNoRelevantTikTok) {
      updatePollStatus(output, '<strong>Discovery returned posts but relevance filter rejected them</strong><p>Try widening the brief or keywords, or lower the minimum-views threshold in TikTok settings.</p>', false);
    } else if (payload.run.status === 'planned_waiting_for_sources' || allJobsWaitingForProvider(jobs)) {
      updatePollStatus(output, stableProviderBridgeMessage(), false);
    }
  }

  function isLiveDispatchEnabled() {
    return !!(window.FIDTF && FIDTF.liveDispatchEnabled);
  }

  function shouldStopPolling(payload, state) {
    var status = payload && payload.run && payload.run.status;
    var jobs = normalizeJobs(payload);
    if (jobs.some(function (job) { return job.status === 'retryable_failed'; })) { return false; }
    if (FINAL_STATUSES.indexOf(status) !== -1) { return true; }
    if (status === 'planned_waiting_for_sources' || allJobsWaitingForProvider(jobs)) { return true; }
    return state.attempts >= state.maxAttempts;
  }

  function startPolling(output, runId) {
    if (!runId) { return; }
    stopPolling(runId);
    pollStates[runId] = {
      attempts: 0,
      maxAttempts: isLiveDispatchEnabled() ? 30 : 2,
      intervalMs: 6000,
      stopped: false
    };

    function pollOnce() {
      var state = pollStates[runId];
      if (!state || state.stopped) { return; }
      state.attempts += 1;
      request('/runs/' + encodeURIComponent(runId), { method: 'GET' }).then(function (payload) {
        updateRunView(output, payload);
        if (shouldStopPolling(payload, state)) {
          if (allJobsWaitingForProvider(normalizeJobs(payload))) { updatePollStatus(output, stableProviderBridgeMessage(), false); }
          stopPolling(runId);
          return;
        }
        pollers[runId] = window.setTimeout(pollOnce, state.intervalMs);
      }).catch(function (err) {
        renderWarning(output, 'Polling warning: ' + (err.message || 'Could not refresh run status.'));
        stopPolling(runId);
      });
    }

    pollers[runId] = window.setTimeout(pollOnce, 1000);
  }

  function stopPolling(runId) {
    if (pollers[runId]) {
      window.clearTimeout(pollers[runId]);
      delete pollers[runId];
    }
    if (pollStates[runId]) {
      pollStates[runId].stopped = true;
      delete pollStates[runId];
    }
  }

  function mediaThumb(item) {
    var media = item.media || {};
    var providerVideo = media.provider_video || {};
    return media.thumbnail_url || providerVideo.thumbnail || providerVideo.cover || media.image_url || '';
  }

  function metricChips(metrics) {
    metrics = metrics || {};
    var keys = ['views', 'likes', 'comments', 'shares', 'bookmarks'];
    var out = [];
    keys.forEach(function (key) {
      var value = parseFloat(metrics[key] || 0);
      if (value > 0) { out.push('<span>' + escapeHtml(value.toLocaleString()) + ' ' + escapeHtml(key) + '</span>'); }
    });
    return out.length ? '<div class="fidtf-evidence-meta">' + out.join('') + '</div>' : '';
  }

  function evidenceGroupLabel(item) {
    var evidenceTier = String(item.evidence_tier || '').toLowerCase();
    var relevanceTier = String(item.relevance_tier || '').toLowerCase();
    var use = String(item.recommended_use || '').toLowerCase();
    if (evidenceTier === 'strong') { return 'Strong evidence'; }
    if (evidenceTier === 'support') { return 'Support evidence'; }
    if (evidenceTier === 'creative_only') { return 'Creative inspiration'; }
    if (evidenceTier === 'noise' || use === 'hide') { return 'Excluded / noise'; }
    if (use === 'maybe' || relevanceTier === 'weak') { return 'Weak / background signals'; }
    if (relevanceTier === 'direct') { return 'Support evidence'; }
    if (relevanceTier === 'adjacent') { return 'Creative inspiration'; }
    return 'Weak / background signals';
  }

  function evidenceFitBadge(item) {
    var tier = String(item.evidence_tier || item.relevance_tier || 'unrated');
    var use = String(item.marketing_allowed_use || item.recommended_use || 'review');
    var safe = item.client_safe ? 'client-safe after review' : 'internal review only';
    return '<span>' + escapeHtml(tier) + '</span><span>' + escapeHtml(use) + '</span><span>' + escapeHtml(safe) + '</span>';
  }

  function renderEvidenceCard(item) {
    var thumb = mediaThumb(item);
    var cardClass = thumb ? 'fidtf-card fidtf-evidence-card has-thumb' : 'fidtf-card fidtf-evidence-card';
    var html = '<article class="' + cardClass + '">';
    if (thumb) { html += '<div class="fidtf-evidence-thumb"><img src="' + escapeHtml(thumb) + '" alt="" loading="lazy"></div>'; }
    html += '<div class="fidtf-evidence-body">';
    html += '<p class="fidtf-kicker">' + escapeHtml(sourceLabel(item.source_key)) + ' · ' + escapeHtml(item.signal_type || 'signal') + '</p>';
    html += '<h5 class="fidtf-evidence-title">' + escapeHtml(item.title || item.author || 'Evidence item') + '</h5>';
    html += '<p class="fidtf-evidence-text">' + escapeHtml(item.caption_or_text || '') + '</p>';
    html += '<div class="fidtf-evidence-meta">' + evidenceFitBadge(item) + '</div>';
    html += metricChips(item.metrics || {});
    html += '<details class="fidtf-evidence-rationale"><summary>Why this evidence is shown</summary><small class="fidtf-evidence-score">Score: ' + escapeHtml(item.relevance_score || 0) + ' · ' + escapeHtml(item.relevance_reason || '') + '</small></details>';
    if (item.risk_note) { html += '<p class="fidtf-muted"><small><strong>Risk note:</strong> ' + escapeHtml(item.risk_note) + '</small></p>'; }
    if (item.provider_query || (item.hashtags && item.hashtags.length)) {
      html += '<p class="fidtf-muted"><small>';
      if (item.provider_query) { html += 'Query: ' + escapeHtml(item.provider_query) + ' '; }
      if (item.hashtags && item.hashtags.length) { html += 'Tags: ' + escapeHtml(item.hashtags.slice(0, 6).join(', ')); }
      html += '</small></p>';
    }
    if (item.url) { html += '<p><a href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener noreferrer">Open source</a></p>'; }
    html += '</div></article>';
    return html;
  }

  function renderItems(container, result, append) {
    if (!container) { return; }
    var items = result.items || [];
    var html = append ? container.innerHTML : '';
    if (!append) { html += '<div class="fidtf-section"><h4>Relevant evidence</h4>'; }
    if (!items.length && !append) { html += '<p class="fidtf-muted">No relevant evidence has been ingested yet.</p>'; }
    if (items.length) {
      var groups = {};
      var order = ['Strong evidence', 'Support evidence', 'Creative inspiration', 'Weak / background signals', 'Excluded / noise'];
      items.forEach(function (item) {
        var label = evidenceGroupLabel(item);
        if (!groups[label]) { groups[label] = []; }
        groups[label].push(item);
      });
      order.forEach(function (label) {
        if (!groups[label] || !groups[label].length) { return; }
        var isWeak = label.indexOf('Weak') === 0 || label.indexOf('Excluded') === 0;
        html += isWeak ? '<details class="fidtf-evidence-group"><summary>' + escapeHtml(label) + ' (' + groups[label].length + ')</summary>' : '<section class="fidtf-evidence-group"><h5>' + escapeHtml(label) + ' (' + groups[label].length + ')</h5>';
        html += '<div class="fidtf-insight-stack">';
        groups[label].forEach(function (item) { html += renderEvidenceCard(item); });
        html += '</div>';
        html += isWeak ? '</details>' : '</section>';
      });
    }
    if (!append) { html += '</div>'; }
    container.innerHTML = html;
  }

  function renderWarning(output, message) {
    var container = output.querySelector('[data-fidtf-items]') || output;
    var html = '<div class="fidtf-notice fidtf-warning"><strong>Warning</strong><p>' + escapeHtml(message) + '</p></div>';
    container.insertAdjacentHTML('beforeend', html);
  }

  function renderError(output, err) {
    output.hidden = false;
    output.innerHTML = '<div class="fidtf-notice fidtf-warning"><strong>Deep Trend Finder error</strong><p>' + escapeHtml(err.message || err) + '</p></div>';
  }

  async function request(path, options) {
    var res = await fetch(FIDTF.restUrl + path, Object.assign({
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': FIDTF.nonce
      }
    }, options || {}));
    var body = await res.json().catch(function () { return {}; });
    if (!res.ok) {
      throw new Error(body.message || res.statusText || 'Request failed');
    }
    return body;
  }

  function updateLiveUx(root) {
    var form = root && root.querySelector('[data-fidtf-form]');
    if (!form) { return; }
    var payload = collectForm(form);
    updatePanel(root, '[data-fidtf-source-plan-panel]', selectedSourcePlanHtml(payload));
    updatePanel(root, '[data-fidtf-preflight-panel]', preflightWarningHtml(payload));
    updateSidebarPlanning(root, payload);
    var button = form.querySelector('button[type="submit"]');
    if (button) {
      button.textContent = (payload.channels || []).some(function (source) { return sourceLiveReady(source); }) ? 'Start deep trend run' : 'Create planned trend brief';
    }
  }



  document.addEventListener('click', function (event) {
    var link = event.target.closest('.fidtf-sidebar-nav a');
    if (!link) { return; }
    var root = link.closest('[data-fidtf-root]');
    setActiveSidebarLink(root, link.getAttribute('href'));
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-fidtf-root]').forEach(updateLiveUx);
  });

  document.addEventListener('change', function (event) {
    var root = event.target.closest('[data-fidtf-root]');
    if (root) { updateLiveUx(root); }
  });

  document.addEventListener('submit', async function (event) {
    var form = event.target.closest('[data-fidtf-form]');
    if (!form) { return; }
    event.preventDefault();
    var root = form.closest('[data-fidtf-root]');
    var output = root.querySelector('[data-fidtf-output]');
    var button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    output.hidden = false;
    var payload = collectForm(form);
    updateLiveUx(root);
    updatePanel(root, '[data-fidtf-progressive-rows]', '<p class="fidtf-empty">Brief is being planned. Source rows will update after run creation.</p>');
    updatePanel(root, '[data-fidtf-evidence-grid]', '<p class="fidtf-empty">No evidence has been ingested yet.</p>');
    updatePanel(root, '[data-fidtf-report-shell]', '<div class="fidtf-warning-box">Planning in progress. No recommendations are generated before evidence exists.</div>');
    output.innerHTML = '<div class="fidtf-result-card"><p>' + escapeHtml((FIDTF.strings && FIDTF.strings.creating) || 'Creating trend brief...') + '</p></div>';
    try {
      var result = await request('/runs', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      renderRun(output, result);
    } catch (err) {
      renderError(output, err);
    } finally {
      button.disabled = false;
      updateLiveUx(root);
    }
  });

  document.addEventListener('click', async function (event) {
    var button = event.target.closest('[data-fidtf-load-report]');
    if (!button) { return; }
    var root = button.closest('[data-fidtf-root]');
    var output = root.querySelector('[data-fidtf-output]');
    var runId = button.getAttribute('data-run-id');
    stopPolling(runId);
    button.disabled = true;
    try {
      var result = await request('/runs/' + encodeURIComponent(runId) + '/report', { method: 'GET' });
      var reportHtml = result.html || '<pre>' + escapeHtml(JSON.stringify(result.report || {}, null, 2)) + '</pre>';
      root.classList.add('fidtf-has-full-report');
      updatePanel(root, '[data-fidtf-report-shell]', '<div class="fidtf-notice fidtf-success"><strong>Professional report loaded.</strong><p>The full synthesis is shown below in a wider reading area.</p></div>');
      output.innerHTML = reportHtml;
      updateSidebarReportLoaded(root);
    } catch (err) {
      renderWarning(output, err.message || err);
    } finally {
      button.disabled = false;
    }
  });

  document.addEventListener('click', async function (event) {
    var button = event.target.closest('[data-fidtf-load-items]');
    if (!button || button.disabled) { return; }
    var root = button.closest('[data-fidtf-root]');
    var output = root.querySelector('[data-fidtf-output]');
    var itemContainer = root.querySelector('[data-fidtf-evidence-grid]') || root.querySelector('[data-fidtf-items]') || output.querySelector('[data-fidtf-items]');
    var runId = button.getAttribute('data-run-id');
    var page = parseInt(button.getAttribute('data-page') || '1', 10);
    button.disabled = true;
    try {
      var result = await request('/runs/' + encodeURIComponent(runId) + '/items?page=' + encodeURIComponent(page) + '&per_page=20&relevant_only=1', { method: 'GET' });
      renderItems(itemContainer, result, page > 1);
      if (result.has_more) {
        button.setAttribute('data-page', String(page + 1));
        button.textContent = 'Show more evidence';
      } else {
        button.textContent = 'No more evidence';
        button.disabled = true;
        return;
      }
    } catch (err) {
      renderWarning(output, err.message || err);
    } finally {
      if (button.textContent !== 'No more evidence') { button.disabled = false; }
    }
  });
}());
