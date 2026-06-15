/*
 * FIIS Signal Report productization (Phase 5C-UX).
 *
 * Pure, deterministic, UI-only helpers that turn an analysis payload into the
 * canonical Future Island workflow surface: Source -> Signal -> Insight -> Brief
 * -> Draft -> Memory -> Usage. No DOM, no network, no secrets. Every dynamic
 * value is HTML-escaped. Honest by construction:
 *   - low confidence / insufficient evidence is NEVER shown as directly approvable;
 *   - object CTAs are DISABLED with an honest reason unless a real backend service
 *     is explicitly flagged as wired (meta.services);
 *   - usage/credit numbers are only shown when present — never fabricated.
 *
 * Works in the browser (window.FIIS_Productization) and under Node (module.exports)
 * so the rules can be unit-tested directly.
 */
(function (root) {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function isObj(v) { return v && typeof v === 'object' && !Array.isArray(v); }
  function truthy(v) {
    if (v === true) return true;
    if (typeof v === 'number') return v > 0;
    if (typeof v === 'string') { var t = v.trim().toLowerCase(); return t === 'yes' || t === 'true' || t === 'available' || t === 'present' || t === 'sufficient'; }
    return false;
  }

  // Spanish-first labels (UI default for this surface).
  var L = {
    routeTitle: 'Flujo del objeto',
    source: 'Fuente capturada', signals: 'Señales normalizadas', insight: 'Insight generado',
    brief: 'Brief candidato', draft: 'Borrador', memory: 'Memoria', usage: 'Uso registrado',
    completed: 'completado', current: 'en curso', pending: 'pendiente', blocked: 'bloqueado', not_available: 'no disponible',
    high: 'alta', medium: 'media', low: 'baja',
    gateTitle: 'Revisión de evidencia', confidence: 'Confianza', evidenceStatus: 'Estado de evidencia',
    gateUiOnly: 'Pre-chequeo en interfaz — no sustituye la revisión del lifecycle',
    gateServer: 'Estado del lifecycle (servidor)',
    available: 'Evidencia disponible', missing: 'Evidencia faltante', approvalRule: 'Regla de aprobación',
    sufficient: 'suficiente', limited: 'limitada', insufficient: 'insuficiente',
    actionsTitle: 'Próximas acciones', usageTitle: 'Uso y créditos', recsTitle: 'Recomendaciones (candidatas a brief)',
    approve: 'Aprobar insight', createBrief: 'Crear brief', generateDraft: 'Generar borrador',
    saveMemory: 'Guardar en memoria', viewUsage: 'Ver evento de uso',
    convertBrief: 'Convertir en brief', saveAsMemory: 'Guardar como memoria', markUseful: 'Marcar útil', markRejected: 'Marcar descartada',
    notConnected: 'No conectado todavía', requiresStaging: 'Requiere validación en staging',
    cannotApprove: 'No puede aprobarse como insight canónico', reviewRequired: 'Puede aprobarse tras confirmación del revisor',
    briefCaution: 'Puede crear brief con precaución', needsApproved: 'Requiere insight o brief aprobado',
    memoryNotConnected: 'Escritura en memoria no conectada', usageMissing: 'Evento de uso no encontrado para este informe',
    usageNotConnected: 'Seguimiento de uso no conectado para esta acción', noCost: 'sin coste registrado'
  };

  var EVIDENCE_CHANNELS = [
    ['caption', 'Caption'], ['metrics', 'Métricas'], ['comments', 'Comentarios'],
    ['transcript', 'Transcript'], ['video_frames', 'Frames de vídeo'], ['audio', 'Audio']
  ];

  function normalizeConfidence(data) {
    var c = data && (data.confidence != null ? data.confidence : data.confidence_level);
    var level = isObj(c) ? c.level : c;
    level = String(level || '').trim().toLowerCase();
    if (level === 'high' || level === 'alta') return 'high';
    if (level === 'medium' || level === 'media' || level === 'med') return 'medium';
    return 'low'; // conservative default
  }

  // Available / missing evidence channels from the structured payload.
  function evidenceChannels(data) {
    var ev = (data && (data.evidence_quality || data.evidence_available)) || {};
    if (!isObj(ev)) ev = {}; // a top-level string like 'none' carries no per-channel data
    var available = [], missing = [];
    EVIDENCE_CHANNELS.forEach(function (pair) {
      (truthy(ev[pair[0]]) ? available : missing).push(pair[1]);
    });
    return { available: available, missing: missing };
  }

  // Deterministic evidence status + canonical approval rule. Never "auto".
  function evidenceGate(data, meta) {
    meta = isObj(meta) ? meta : {};
    var conf = normalizeConfidence(data);
    var ch = evidenceChannels(data);
    var n = ch.available.length;
    // server hint: explicit block on confident recommendations forces conservative.
    var blocked = (meta && meta.confident_recommendations_allowed === false) ||
                  (data && data.confident_recommendations_allowed === false);

    var status;
    if (n === 0 || (conf === 'low' && n <= 1) || blocked) status = 'insufficient';
    else if (conf === 'high' && n >= 3) status = 'sufficient';
    else status = 'limited';

    var rule, canBrief;
    if (status === 'insufficient') { rule = 'cannot_approve'; canBrief = false; }
    else { rule = 'review_required'; canBrief = true; } // reviewer confirmation always; no silent approval

    // Honest enforcement source: only "server" when a real lifecycle status is
    // threaded in meta; otherwise this is an interface pre-check, not enforcement.
    var serverStatus = meta.lifecycle_status || meta.server_evidence_gate || (data && data.lifecycle_status) || null;
    var enforced = !!serverStatus;

    return {
      confidence: conf, status: status, available: ch.available, missing: ch.missing,
      approval_rule: rule, can_create_brief_with_caution: canBrief,
      approval_label: rule === 'cannot_approve' ? L.cannotApprove : L.reviewRequired,
      enforced: enforced, source: enforced ? 'server' : 'ui',
      server_status: serverStatus ? String(serverStatus) : null
    };
  }

  // Canonical route stages with explicit states (data-aware where possible).
  function routeStages(data, meta, gate) {
    meta = meta || {};
    var hasSignals = (meta.usable_signal_count > 0) || (meta.normalized_signal_count > 0) ||
                     (data && Array.isArray(data.signals) && data.signals.length > 0);
    var hasAnalysis = !!(data && (data.executive_summary || data.exec || data.executive_read || data.recommendations || data.insights));
    var usage = (isObj(meta.usage) ? meta.usage : (isObj(meta.usage_event) ? meta.usage_event : null));
    var usagePosted = usage && String(usage.status || '').toLowerCase() === 'posted';
    // A rendered structured analysis implies its source/signals were captured.
    var captured = hasSignals || hasAnalysis;

    return [
      { key: 'source',  label: L.source,  state: captured ? 'completed' : 'not_available' },
      { key: 'signals', label: L.signals, state: captured ? 'completed' : 'not_available' },
      { key: 'insight', label: L.insight, state: hasAnalysis ? 'current' : 'pending' },
      { key: 'brief',   label: L.brief,   state: gate.can_create_brief_with_caution ? 'pending' : 'blocked' },
      { key: 'draft',   label: L.draft,   state: 'not_available' },
      { key: 'memory',  label: L.memory,  state: 'pending' },
      { key: 'usage',   label: L.usage,   state: usagePosted ? 'completed' : (usage ? 'pending' : 'not_available') }
    ];
  }

  // A service is only "wired" when meta carries a real action contract — a nonce
  // AND an action id or URL. A bare boolean (services.x === true) does NOT enable a
  // button, so nothing becomes clickable without real backend wiring.
  function serviceWired(svc, key) {
    var s = svc && svc[key];
    return !!(s && typeof s === 'object' && s.nonce && (s.action || s.url));
  }

  // Object CTAs. Disabled + honest reason unless a real service contract exists.
  function actions(meta, gate) {
    meta = isObj(meta) ? meta : {};
    var svc = isObj(meta.services) ? meta.services : {};
    var usage = isObj(meta.usage) ? meta.usage : (isObj(meta.usage_event) ? meta.usage_event : null);
    var hasApproved = !!(meta.approved_insight || meta.approved_brief);
    function act(key, label, wired, enabledRule, reason) {
      var enabled = !!wired && !!enabledRule;
      return { key: key, label: label, enabled: enabled, reason: enabled ? '' : reason };
    }
    return [
      act('approve', L.approve, serviceWired(svc, 'approve'), gate.approval_rule !== 'cannot_approve',
          gate.approval_rule === 'cannot_approve' ? L.cannotApprove : (serviceWired(svc, 'approve') ? L.reviewRequired : L.requiresStaging)),
      act('brief', L.createBrief, serviceWired(svc, 'brief'), gate.can_create_brief_with_caution,
          gate.can_create_brief_with_caution ? (serviceWired(svc, 'brief') ? L.briefCaution : L.requiresStaging) : L.cannotApprove),
      act('draft', L.generateDraft, serviceWired(svc, 'draft'), hasApproved, L.needsApproved),
      act('memory', L.saveMemory, serviceWired(svc, 'memory'), true, L.memoryNotConnected),
      // "View usage" is a read-only view of a usage event already in the payload;
      // enabled only when such an event exists, otherwise honest missing.
      act('usage', L.viewUsage, !!usage, true, L.usageMissing)
    ];
  }

  function build(data, meta) {
    data = isObj(data) ? data : {};
    meta = isObj(meta) ? meta : {};
    var gate = evidenceGate(data, meta);
    return { gate: gate, route: routeStages(data, meta, gate), actions: actions(meta, gate), usage: usageModel(meta) };
  }

  // Usage panel — only real numbers; honest missing states; never fabricated.
  function usageModel(meta) {
    meta = isObj(meta) ? meta : {};
    var u = isObj(meta.usage) ? meta.usage : (isObj(meta.usage_event) ? meta.usage_event : null);
    if (!u) return { present: false, message: L.usageMissing };
    function num(v) { return (typeof v === 'number' && isFinite(v)) ? v : null; }
    return {
      present: true,
      status: String(u.status || 'missing').toLowerCase(),
      estimated_credits: num(u.estimated_credits),
      actual_credits: num(u.actual_credits),
      provider_cost: num(u.provider_cost),
      operation_type: u.operation_type ? String(u.operation_type) : null,
      timestamp: u.timestamp ? String(u.timestamp) : null
    };
  }

  // ── Render (HTML strings; all dynamic values escaped) ──────────────────────
  function stateLabel(s) { return L[s] || s; }

  function renderRouteBar(model) {
    var steps = model.route.map(function (st) {
      return '<li class="fiis-route-step is-' + esc(st.state) + '">' +
        '<span class="fiis-route-dot"></span>' +
        '<span class="fiis-route-label">' + esc(st.label) + '</span>' +
        '<span class="fiis-route-state">' + esc(stateLabel(st.state)) + '</span></li>';
    }).join('');
    return '<nav class="fiis-route-bar" aria-label="' + esc(L.routeTitle) + '">' +
      '<div class="fiis-route-bar-title">' + esc(L.routeTitle) + '</div>' +
      '<ol class="fiis-route-track">' + steps + '</ol></nav>';
  }

  function renderEvidenceGate(model) {
    var g = model.gate;
    var avail = g.available.length ? g.available.map(function (a) { return '<span class="fiis-chip is-ok">' + esc(a) + '</span>'; }).join('') : '<span class="fiis-muted">—</span>';
    var miss = g.missing.length ? g.missing.map(function (a) { return '<span class="fiis-chip is-muted">' + esc(a) + '</span>'; }).join('') : '<span class="fiis-muted">—</span>';
    // Honest source label: server-reflected only when a real lifecycle status was
    // threaded; otherwise an explicit UI-only pre-check (no implied enforcement).
    var sourceLine = g.enforced
      ? '<div class="fiis-eg-row"><span>' + esc(L.gateServer) + '</span><strong>' + esc(g.server_status) + '</strong></div>'
      : '<p class="fiis-eg-note fiis-muted">' + esc(L.gateUiOnly) + '</p>';
    return '<section class="fiis-evidence-gate is-' + esc(g.status) + ' is-source-' + esc(g.source) + '">' +
      '<h3 class="fiis-eg-title">' + esc(L.gateTitle) + '</h3>' +
      sourceLine +
      '<div class="fiis-eg-row"><span>' + esc(L.confidence) + '</span><strong class="fiis-conf is-' + esc(g.confidence) + '">' + esc(stateLabel(g.confidence)) + '</strong></div>' +
      '<div class="fiis-eg-row"><span>' + esc(L.evidenceStatus) + '</span><strong class="fiis-evstatus is-' + esc(g.status) + '">' + esc(stateLabel(g.status)) + '</strong></div>' +
      '<div class="fiis-eg-block"><span>' + esc(L.available) + '</span><div class="fiis-chip-row">' + avail + '</div></div>' +
      '<div class="fiis-eg-block"><span>' + esc(L.missing) + '</span><div class="fiis-chip-row">' + miss + '</div></div>' +
      '<div class="fiis-eg-rule"><span>' + esc(L.approvalRule) + '</span> <strong>' + esc(g.approval_label) + '</strong></div>' +
      '</section>';
  }

  function renderActions(model) {
    var btns = model.actions.map(function (a) {
      var attrs = a.enabled
        ? 'data-fiis-action="' + esc(a.key) + '"'
        : 'disabled aria-disabled="true" title="' + esc(a.reason) + '"';
      return '<button type="button" class="fiis-act-btn' + (a.enabled ? '' : ' is-disabled') + '" ' + attrs + '>' +
        esc(a.label) + (a.enabled ? '' : '<small class="fiis-act-reason">' + esc(a.reason) + '</small>') + '</button>';
    }).join('');
    return '<section class="fiis-action-panel"><h3>' + esc(L.actionsTitle) + '</h3><div class="fiis-act-grid">' + btns + '</div></section>';
  }

  function renderUsage(model) {
    var u = model.usage;
    if (!u.present) return '<section class="fiis-usage-panel"><h3>' + esc(L.usageTitle) + '</h3><p class="fiis-muted">' + esc(u.message) + '</p></section>';
    function row(label, val) { return '<div class="fiis-usage-row"><span>' + esc(label) + '</span><strong>' + (val == null ? '<span class="fiis-muted">—</span>' : esc(val)) + '</strong></div>'; }
    return '<section class="fiis-usage-panel"><h3>' + esc(L.usageTitle) + '</h3>' +
      row('Estado', u.status) +
      row('Operación', u.operation_type) +
      row('Créditos estimados', u.estimated_credits) +
      row('Créditos reales', u.actual_credits) +
      row('Coste proveedor', u.provider_cost == null ? L.noCost : u.provider_cost) +
      row('Timestamp', u.timestamp) +
      '</section>';
  }

  function renderRecommendations(recs, model) {
    if (!Array.isArray(recs) || !recs.length) return '';
    var canBrief = model.gate.can_create_brief_with_caution;
    var cards = recs.filter(Boolean).map(function (r) {
      var conf = esc(stateLabel(r.confidence || model.gate.confidence || 'low'));
      var risk = esc(r.risk || '—');
      var type = esc(r.type || r.recommendation_type || 'acción');
      var basis = esc(r.evidence_basis || r.why || '');
      var nextObj = esc(r.suggested_next || (canBrief ? 'Brief' : 'Test A/B'));
      var ctas =
        '<button type="button" class="fiis-rec-cta is-disabled" disabled title="' + esc(canBrief ? L.requiresStaging : L.cannotApprove) + '">' + esc(L.convertBrief) + '</button>' +
        '<button type="button" class="fiis-rec-cta is-disabled" disabled title="' + esc(L.memoryNotConnected) + '">' + esc(L.saveAsMemory) + '</button>' +
        '<button type="button" class="fiis-rec-cta is-disabled" disabled title="' + esc(L.requiresStaging) + '">' + esc(L.markUseful) + '</button>' +
        '<button type="button" class="fiis-rec-cta is-disabled" disabled title="' + esc(L.requiresStaging) + '">' + esc(L.markRejected) + '</button>';
      return '<article class="fiis-rec-card">' +
        '<header><strong>' + esc(r.action || 'Acción') + '</strong><span class="fiis-rec-type">' + type + '</span></header>' +
        (basis ? '<p class="fiis-rec-basis">' + basis + '</p>' : '') +
        '<div class="fiis-rec-meta"><span>' + esc(L.confidence) + ': ' + conf + '</span><span>Riesgo: ' + risk + '</span><span>Siguiente objeto: ' + nextObj + '</span></div>' +
        '<div class="fiis-rec-ctas">' + ctas + '</div></article>';
    }).join('');
    return '<section class="fiis-structured-analysis-section"><h3>' + esc(L.recsTitle) + '</h3><div class="fiis-rec-grid">' + cards + '</div></section>';
  }

  // Full top-of-report productization block (route + gate + actions + usage).
  function renderProductization(data, meta) {
    var model = build(data, meta);
    return '<div class="fiis-productization">' +
      renderRouteBar(model) +
      '<div class="fiis-prod-rail">' + renderEvidenceGate(model) + renderActions(model) + renderUsage(model) + '</div>' +
      '</div>';
  }

  var api = {
    L: L, esc: esc, build: build, evidenceGate: evidenceGate, routeStages: routeStages,
    actions: actions, usageModel: usageModel, normalizeConfidence: normalizeConfidence,
    renderProductization: renderProductization, renderRecommendations: renderRecommendations,
    renderRouteBar: renderRouteBar, renderEvidenceGate: renderEvidenceGate, renderActions: renderActions, renderUsage: renderUsage
  };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  root.FIIS_Productization = api;
})(typeof window !== 'undefined' ? window : this);
