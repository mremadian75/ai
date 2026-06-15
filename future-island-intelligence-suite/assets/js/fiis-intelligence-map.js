/* FIIS Intelligence Map — read-only relationship graph (vanilla JS, no deps).
 * Renders an SVG "lane" layout following the product loop:
 *   Evidence → Run → Insight → Opportunity → Brief → Memory
 * Calm and bounded by design: no physics engine, no auto-loading of huge graphs.
 * On small screens it degrades to a readable relationship list. */
(function () {
  'use strict';
  var CFG = window.FIIS_IMAP || {};
  var root = document.getElementById('fiis-imap-root');
  if (!root) { return; }

  var LANES = ['evidence', 'run', 'insight', 'opportunity', 'brief', 'memory'];
  var LANE_LABEL = {
    evidence: 'Evidence', run: 'Run', insight: 'Insight',
    opportunity: 'Opportunity', brief: 'Brief', memory: 'Memory'
  };
  var COLOR = {
    run: '#7c5cff', evidence: '#8a8f98', insight: '#3fb950',
    opportunity: '#d29922', brief: '#4493f8', memory: '#db61a2'
  };

  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    if (attrs) { Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); }); }
    if (text != null) { n.textContent = text; }
    return n;
  }
  function svgEl(tag, attrs) {
    var n = document.createElementNS('http://www.w3.org/2000/svg', tag);
    if (attrs) { Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); }); }
    return n;
  }

  // ── Toolbar / filters ─────────────────────────────────────────────────────
  var state = {
    workspace_id: CFG.default_workspace || 0,
    run_id: '', days: 30, min_confidence: 0, max_nodes: 160, types: []
  };

  function buildToolbar() {
    var bar = el('div', { class: 'fiis-imap-toolbar' });

    function field(labelText, control) {
      var w = el('label', { class: 'fiis-imap-field' });
      w.appendChild(el('span', { class: 'fiis-imap-flabel' }, labelText));
      w.appendChild(control);
      return w;
    }

    var ws = el('input', { type: 'number', min: '0', value: String(state.workspace_id), class: 'fiis-imap-input', 'aria-label': 'Workspace ID' });
    ws.addEventListener('change', function () { state.workspace_id = parseInt(ws.value, 10) || 0; });

    var run = el('input', { type: 'text', value: '', placeholder: 'optional', class: 'fiis-imap-input', 'aria-label': 'Run ID' });
    run.addEventListener('change', function () { state.run_id = (run.value || '').trim(); });

    var days = el('select', { class: 'fiis-imap-input', 'aria-label': 'Date window' });
    [['7', 'Last 7 days'], ['30', 'Last 30 days'], ['90', 'Last 90 days'], ['365', 'Last year']].forEach(function (o) {
      var opt = el('option', { value: o[0] }, o[1]); if (o[0] === '30') { opt.selected = true; } days.appendChild(opt);
    });
    days.addEventListener('change', function () { state.days = parseInt(days.value, 10) || 30; });

    var conf = el('select', { class: 'fiis-imap-input', 'aria-label': 'Minimum confidence' });
    [['0', 'Any confidence'], ['40', 'Confidence ≥ 40'], ['60', 'Confidence ≥ 60'], ['80', 'Confidence ≥ 80']].forEach(function (o) {
      conf.appendChild(el('option', { value: o[0] }, o[1]));
    });
    conf.addEventListener('change', function () { state.min_confidence = parseInt(conf.value, 10) || 0; });

    var typeWrap = el('div', { class: 'fiis-imap-types', role: 'group', 'aria-label': 'Object types' });
    LANES.forEach(function (t) {
      var id = 'fiis-imap-t-' + t;
      var cb = el('input', { type: 'checkbox', id: id, value: t });
      cb.addEventListener('change', function () {
        state.types = Array.prototype.filter.call(typeWrap.querySelectorAll('input:checked'), function () { return true; })
          .map(function (n) { return n.value; });
      });
      var lab = el('label', { for: id, class: 'fiis-imap-chip' });
      var dot = el('span', { class: 'fiis-imap-dot' }); dot.style.background = COLOR[t] || '#999';
      lab.appendChild(dot); lab.appendChild(document.createTextNode(LANE_LABEL[t]));
      typeWrap.appendChild(cb); typeWrap.appendChild(lab);
    });

    var loadBtn = el('button', { type: 'button', class: 'button button-primary fiis-imap-load' }, 'Load map');
    loadBtn.addEventListener('click', load);

    bar.appendChild(field('Workspace', ws));
    bar.appendChild(field('Run (optional)', run));
    bar.appendChild(field('Window', days));
    bar.appendChild(field('Confidence', conf));
    bar.appendChild(field('Types (all if none)', typeWrap));
    bar.appendChild(loadBtn);
    return bar;
  }

  // ── Data load ───────────────────────────────────────────────────────────────
  function load() {
    var canvas = document.getElementById('fiis-imap-canvas');
    if (canvas) { canvas.innerHTML = ''; canvas.appendChild(el('p', { class: 'fiis-imap-loading' }, 'Loading…')); }
    var q = [];
    q.push('workspace_id=' + encodeURIComponent(state.workspace_id));
    if (state.run_id) { q.push('run_id=' + encodeURIComponent(state.run_id)); }
    q.push('days=' + encodeURIComponent(state.days));
    q.push('min_confidence=' + encodeURIComponent(state.min_confidence));
    q.push('max_nodes=' + encodeURIComponent(state.max_nodes));
    if (state.types.length) { q.push('types=' + encodeURIComponent(state.types.join(','))); }
    var url = CFG.rest_url + (CFG.rest_url.indexOf('?') === -1 ? '?' : '&') + q.join('&');

    fetch(url, { headers: { 'X-WP-Nonce': CFG.nonce || '' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(render)
      .catch(function (e) {
        var c = document.getElementById('fiis-imap-canvas');
        if (c) { c.innerHTML = ''; c.appendChild(el('p', { class: 'fiis-imap-error' }, 'Could not load the map: ' + e.message)); }
      });
  }

  // ── Render ────────────────────────────────────────────────────────────────
  function render(data) {
    var canvas = document.getElementById('fiis-imap-canvas');
    if (!canvas) { return; }
    canvas.innerHTML = '';

    if (!data || data.empty || !data.nodes || data.nodes.length < 2) {
      var msg = (data && data.empty_message) || 'This map will become useful once runs, signals, insights, and briefs are connected.';
      canvas.appendChild(el('div', { class: 'fiis-imap-empty' }, msg));
      renderStats(data);
      return;
    }
    renderStats(data);
    canvas.appendChild(renderOperatorSummary(data));
    if (window.matchMedia && window.matchMedia('(max-width: 720px)').matches) {
      canvas.appendChild(renderList(data));
    } else {
      canvas.appendChild(renderGraph(data));
    }
    canvas.appendChild(renderLineageTable(data));
  }

  function renderStats(data) {
    var s = (data && data.stats) || { node_count: 0, edge_count: 0 };
    var bar = el('div', { class: 'fiis-imap-stats' });
    bar.appendChild(el('span', null, (s.node_count || 0) + ' nodes · ' + (s.edge_count || 0) + ' links'));
    if (s.capped) { bar.appendChild(el('span', { class: 'fiis-imap-capped' }, ' (capped — narrow filters for detail)')); }
    var host = document.getElementById('fiis-imap-statsbar');
    if (host) { host.innerHTML = ''; host.appendChild(bar); }
  }

  function renderOperatorSummary(data) {
    var s = (data && data.stats && data.stats.nodes_by_type) || {};
    var box = el('section', { class: 'fiis-imap-summary', 'aria-label': 'Operator summary' });
    box.appendChild(el('h2', null, 'Operator summary'));
    var counts = el('div', { class: 'fiis-imap-counts' });
    LANES.forEach(function (t) {
      var chip = el('span', { class: 'fiis-imap-count' });
      var dot = el('span', { class: 'fiis-imap-dot' }); dot.style.background = COLOR[t] || '#999';
      chip.appendChild(dot);
      chip.appendChild(document.createTextNode(LANE_LABEL[t] + ': ' + (s[t] || 0)));
      counts.appendChild(chip);
    });
    box.appendChild(counts);
    box.appendChild(el('p', { class: 'fiis-imap-muted' }, 'Read-only map. Use the lineage list below when the visual graph is too dense. Viewing does not consume credits.'));
    return box;
  }

  function renderLineageTable(data) {
    var rows = Array.isArray(data.lineage) ? data.lineage : buildLineageRows(data);
    var section = el('section', { class: 'fiis-imap-lineage', 'aria-label': 'Lineage list' });
    section.appendChild(el('h2', null, 'Lineage list'));
    section.appendChild(el('p', { class: 'fiis-imap-muted' }, 'Run → Insight → Brief → Memory, with status, confidence and latest update.'));
    if (!rows.length) {
      section.appendChild(el('div', { class: 'fiis-imap-empty' }, 'No lineage rows yet. Create or review an insight, brief, or memory record to populate this list.'));
      return section;
    }
    var scroller = el('div', { class: 'fiis-imap-tablewrap' });
    var table = el('table', { class: 'fiis-imap-table' });
    var thead = el('thead');
    var trh = el('tr');
    ['Run', 'Insight', 'Brief', 'Memory', 'Status', 'Confidence', 'Last updated', 'Open'].forEach(function (h) { trh.appendChild(el('th', null, h)); });
    thead.appendChild(trh); table.appendChild(thead);
    var tbody = el('tbody');
    rows.slice(0, 80).forEach(function (r) {
      var tr = el('tr');
      tr.appendChild(el('td', null, r.run || r.run_id || 'Run'));
      tr.appendChild(el('td', null, r.insight || (r.insight_count ? (r.insight_count + ' insights') : '—')));
      tr.appendChild(el('td', null, r.brief || (r.brief_count ? (r.brief_count + ' briefs') : '—')));
      tr.appendChild(el('td', null, r.memory || (r.memory_count ? (r.memory_count + ' memories') : '—')));
      tr.appendChild(el('td', null, r.status || '—'));
      tr.appendChild(el('td', null, r.confidence == null || r.confidence === '' ? '—' : String(r.confidence)));
      tr.appendChild(el('td', null, r.last_updated || '—'));
      var td = el('td');
      var btn = el('button', { type: 'button', class: 'button button-small fiis-imap-open' }, 'Inspect');
      btn.addEventListener('click', function () { openLineageRow(data, r); });
      td.appendChild(btn); tr.appendChild(td); tbody.appendChild(tr);
    });
    table.appendChild(tbody); scroller.appendChild(table); section.appendChild(scroller);
    return section;
  }

  function buildLineageRows(data) {
    var byId = {}; (data.nodes || []).forEach(function (n) { byId[n.id] = n; });
    var groups = {};
    (data.nodes || []).forEach(function (n) { if (n.type === 'run') { groups[n.id] = { run: n, insights: [], briefs: [], memory: [] }; } });
    (data.edges || []).forEach(function (e) {
      var from = byId[e.from], to = byId[e.to];
      if (!from || !to) { return; }
      var runId = to.type === 'run' ? to.id : (from.type === 'run' ? from.id : '');
      var other = to.type === 'run' ? from : (from.type === 'run' ? to : null);
      if (!runId || !groups[runId] || !other) { return; }
      if (other.type === 'insight') { groups[runId].insights.push(other); }
      if (other.type === 'brief') { groups[runId].briefs.push(other); }
      if (other.type === 'memory') { groups[runId].memory.push(other); }
    });
    return Object.keys(groups).map(function (id) {
      var g = groups[id], ins = g.insights[0] || null, br = g.briefs[0] || null, mem = g.memory[0] || null;
      return { run: g.run.label || id, run_id: id.replace(/^run:/, ''), insight: ins && ins.label || '', insight_count: g.insights.length, brief: br && br.label || '', brief_count: g.briefs.length, memory: mem && mem.label || '', memory_count: g.memory.length, status: (ins && ins.status) || g.run.status || '', confidence: (ins && ins.confidence != null ? ins.confidence : g.run.confidence), last_updated: (ins && ins.created_at) || (br && br.created_at) || (mem && mem.created_at) || g.run.created_at || '', open_ref: (ins && ins.object_ref) || (br && br.object_ref) || (mem && mem.object_ref) || g.run.object_ref };
    });
  }

  function openLineageRow(data, row) {
    var ref = row.open_ref || {};
    var targetType = ref.type || 'run';
    var targetId = ref.id || row.run_id;
    var match = (data.nodes || []).filter(function (n) {
      var or = n.object_ref || {};
      return or.type === targetType && (String(or.id || or.run_id || '') === String(targetId || ''));
    })[0];
    if (!match) { match = (data.nodes || []).filter(function (n) { return n.type === 'run' && (n.object_ref || {}).id === row.run_id; })[0]; }
    if (match) { openDrawer(match); }
  }

  function renderGraph(data) {
    var wrap = el('div', { class: 'fiis-imap-graphwrap' });
    var lanes = {};
    LANES.forEach(function (t) { lanes[t] = []; });
    data.nodes.forEach(function (n) { if (lanes[n.type]) { lanes[n.type].push(n); } });

    var activeLanes = LANES.filter(function (t) { return lanes[t].length; });
    var colW = 210, rowH = 64, padTop = 30, padLeft = 90;
    var maxRows = activeLanes.reduce(function (m, t) { return Math.max(m, lanes[t].length); }, 1);
    var width = padLeft + activeLanes.length * colW + 40;
    var height = Math.max(260, padTop + maxRows * rowH + 30);

    var svg = svgEl('svg', { class: 'fiis-imap-svg', viewBox: '0 0 ' + width + ' ' + height, width: '100%', height: String(height), role: 'img', 'aria-label': 'Relationship graph' });

    var pos = {}; // node id -> {x,y}
    activeLanes.forEach(function (t, ci) {
      var x = padLeft + ci * colW;
      svg.appendChild(svgEl('text', { x: String(x), y: '16', class: 'fiis-imap-lanelabel', 'text-anchor': 'middle' })).textContent = LANE_LABEL[t] + ' (' + lanes[t].length + ')';
      lanes[t].forEach(function (n, ri) {
        pos[n.id] = { x: x, y: padTop + ri * rowH + 18 };
      });
    });

    // edges first (under nodes)
    (data.edges || []).forEach(function (e) {
      var a = pos[e.from], b = pos[e.to];
      if (!a || !b) { return; }
      var line = svgEl('path', {
        d: 'M' + a.x + ',' + a.y + ' C' + ((a.x + b.x) / 2) + ',' + a.y + ' ' + ((a.x + b.x) / 2) + ',' + b.y + ' ' + b.x + ',' + b.y,
        class: 'fiis-imap-edge', fill: 'none'
      });
      svg.appendChild(line);
    });

    // nodes
    data.nodes.forEach(function (n) {
      var p = pos[n.id]; if (!p) { return; }
      var g = svgEl('g', { class: 'fiis-imap-node', tabindex: '0', role: 'button', 'aria-label': (n.type + ': ' + (n.label || '')) });
      var r = 7 + Math.min(8, (n.relationship_count || 0));
      var c = svgEl('circle', { cx: String(p.x), cy: String(p.y), r: String(r) });
      c.style.fill = COLOR[n.type] || '#999';
      g.appendChild(c);
      var t = svgEl('text', { x: String(p.x + r + 6), y: String(p.y + 4), class: 'fiis-imap-nodelabel' });
      t.textContent = (n.label || n.type).slice(0, 28);
      g.appendChild(t);
      function open() { openDrawer(n); }
      g.addEventListener('click', open);
      g.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); open(); } });
      svg.appendChild(g);
    });

    wrap.appendChild(svg);
    return wrap;
  }

  // Mobile / compact: grouped relationship list (run → its children)
  function renderList(data) {
    var wrap = el('div', { class: 'fiis-imap-list' });
    var byId = {}; data.nodes.forEach(function (n) { byId[n.id] = n; });
    var children = {}; // runId -> [nodes]
    (data.edges || []).forEach(function (e) {
      var from = byId[e.from], to = byId[e.to];
      if (!from || !to) { return; }
      var runId = to.type === 'run' ? to.id : (from.type === 'run' ? from.id : null);
      var other = to.type === 'run' ? from : (from.type === 'run' ? to : null);
      if (runId && other) { (children[runId] = children[runId] || []).push(other); }
    });
    data.nodes.filter(function (n) { return n.type === 'run'; }).forEach(function (run) {
      var card = el('div', { class: 'fiis-imap-card' });
      var h = el('button', { type: 'button', class: 'fiis-imap-cardhead' });
      h.appendChild(el('span', { class: 'fiis-imap-dot' })).style.background = COLOR.run;
      h.appendChild(document.createTextNode(' ' + (run.label || 'Run')));
      h.addEventListener('click', function () { openDrawer(run); });
      card.appendChild(h);
      var kids = children[run.id] || [];
      var ul = el('ul', { class: 'fiis-imap-kids' });
      kids.forEach(function (k) {
        var li = el('li');
        var b = el('button', { type: 'button', class: 'fiis-imap-kid' });
        b.appendChild(el('span', { class: 'fiis-imap-dot' })).style.background = COLOR[k.type] || '#999';
        b.appendChild(document.createTextNode(' ' + LANE_LABEL[k.type] + ': ' + (k.label || '')));
        b.addEventListener('click', function () { openDrawer(k); });
        li.appendChild(b); ul.appendChild(li);
      });
      if (!kids.length) { ul.appendChild(el('li', { class: 'fiis-imap-muted' }, 'No linked objects yet.')); }
      card.appendChild(ul);
      wrap.appendChild(card);
    });
    return wrap;
  }

  // ── Detail drawer ───────────────────────────────────────────────────────────
  function openDrawer(n) {
    closeDrawer();
    var ov = el('div', { class: 'fiis-imap-overlay', id: 'fiis-imap-overlay' });
    ov.addEventListener('click', function (e) { if (e.target === ov) { closeDrawer(); } });
    var d = el('aside', { class: 'fiis-imap-drawer', role: 'dialog', 'aria-label': 'Object details' });
    var close = el('button', { type: 'button', class: 'fiis-imap-close', 'aria-label': 'Close' }, '×');
    close.addEventListener('click', closeDrawer);
    d.appendChild(close);

    var head = el('div', { class: 'fiis-imap-dhead' });
    var dot = el('span', { class: 'fiis-imap-dot' }); dot.style.background = COLOR[n.type] || '#999';
    head.appendChild(dot);
    head.appendChild(el('strong', null, LANE_LABEL[n.type] || n.type));
    d.appendChild(head);

    d.appendChild(el('h2', { class: 'fiis-imap-dtitle' }, n.label || '(untitled)'));
    function row(k, v) { if (v === '' || v == null) { return; } var p = el('p', { class: 'fiis-imap-drow' }); p.appendChild(el('span', { class: 'fiis-imap-dk' }, k)); p.appendChild(el('span', null, String(v))); d.appendChild(p); }
    row('Status', n.status);
    if (n.confidence != null) { row('Confidence', n.confidence); }
    if (n.evidence_ref_count != null) { row('Evidence refs', n.evidence_ref_count); }
    if (n.evidence_validation_status) { row('Evidence', n.evidence_validation_status); }
    row('Connections', n.relationship_count);
    row('Source', n.source);
    row('Created', n.created_at);
    if (n.summary) { var s = el('p', { class: 'fiis-imap-dsummary' }, n.summary); d.appendChild(s); }
    if (n.object_ref) { row('Object', (n.object_ref.type || n.type) + (n.object_ref.id ? (' #' + n.object_ref.id) : '') + (n.object_ref.run_id ? (' · run ' + n.object_ref.run_id) : '')); }

    ov.appendChild(d);
    document.body.appendChild(ov);
    close.focus();
    document.addEventListener('keydown', escClose);
  }
  function escClose(e) { if (e.key === 'Escape') { closeDrawer(); } }
  function closeDrawer() {
    var ov = document.getElementById('fiis-imap-overlay');
    if (ov && ov.parentNode) { ov.parentNode.removeChild(ov); }
    document.removeEventListener('keydown', escClose);
  }

  // ── Mount ───────────────────────────────────────────────────────────────────
  root.appendChild(buildToolbar());
  root.appendChild(el('div', { id: 'fiis-imap-statsbar' }));
  root.appendChild(el('div', { id: 'fiis-imap-canvas', class: 'fiis-imap-canvas' }));
  document.getElementById('fiis-imap-canvas').appendChild(el('p', { class: 'fiis-imap-hint' }, 'Choose a workspace and click “Load map”.'));
  if (state.workspace_id > 0) { load(); }
})();
