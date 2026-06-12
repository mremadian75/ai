/*
 * Executable rules test for the Signal Report productization module (Phase 5C-UX).
 * Runs under Node: `node tests/test-fiis-signal-productization.js`
 * Proves the canonical workflow + evidence-gate + honest-usage rules WITHOUT a
 * browser. (The visual render still needs browser verification on real staging.)
 */
'use strict';
var P = require('../assets/js/fiis-signal-productization.js');
var pass = 0, fail = 0;
function ok(c, l) { if (c) { pass++; console.log('  PASS  ' + l); } else { fail++; console.log('  FAIL  ' + l); } }

// ── 1. Route bar shows the canonical object flow ─────────────────────────────
var m1 = P.build({ recommendations: [{ action: 'x' }] }, { usable_signal_count: 5 });
ok(m1.route.map(function (s) { return s.key; }).join(',') === 'source,signals,insight,brief,draft,memory,usage',
  '1: route bar = Source→Signals→Insight→Brief→Draft→Memory→Usage');
ok(m1.route[0].state === 'completed' && m1.route[2].state === 'current',
  '1: data-aware states (source completed when signals exist, insight current)');

// ── 2. Low confidence + thin evidence ⇒ insufficient ⇒ CANNOT approve ────────
var low = P.evidenceGate({ confidence: 'low', evidence_quality: { caption: true } }, {});
ok(low.status === 'insufficient' && low.approval_rule === 'cannot_approve',
  '2: low confidence + 1 channel ⇒ insufficient ⇒ cannot_approve');
ok(low.can_create_brief_with_caution === false, '2: insufficient ⇒ no brief');
var acts = P.actions({ services: { approve: true, brief: true } }, low);
var approve = acts.filter(function (a) { return a.key === 'approve'; })[0];
ok(approve.enabled === false && /aprobarse como insight canónico/.test(approve.reason),
  '2: approve button DISABLED even with service wired, honest reason (no silent approval)');

// ── 3. High confidence + rich evidence ⇒ sufficient, but STILL review-required ─
var hi = P.evidenceGate({ confidence: 'high', evidence_quality: { caption: true, metrics: true, transcript: true, comments: true } }, {});
ok(hi.status === 'sufficient' && hi.approval_rule === 'review_required',
  '3: sufficient evidence still requires reviewer confirmation (never auto-approve)');
ok(hi.can_create_brief_with_caution === true, '3: sufficient ⇒ brief allowed (with caution)');

// ── 4. Server block hint forces conservative ─────────────────────────────────
var blocked = P.evidenceGate({ confidence: 'high', evidence_quality: { caption: true, metrics: true, transcript: true } }, { confident_recommendations_allowed: false });
ok(blocked.status === 'insufficient' && blocked.approval_rule === 'cannot_approve',
  '4: confident_recommendations_allowed=false forces insufficient/cannot_approve');

// ── 5. Actions are disabled with honest reasons when no service is wired ──────
var noSvc = P.actions({}, hi);
ok(noSvc.every(function (a) { return a.key === 'usage' ? true : !a.enabled; }),
  '5: object CTAs disabled when backend services not flagged wired');
var draft = noSvc.filter(function (a) { return a.key === 'draft'; })[0];
ok(draft.enabled === false && /insight o brief aprobado/.test(draft.reason),
  '5: generate draft blocked w/o approved insight/brief');

// ── 6. Usage panel is honest — missing state, no fabricated cost ─────────────
var uMissing = P.usageModel({});
ok(uMissing.present === false && /Evento de uso no encontrado/.test(uMissing.message),
  '6: no usage event ⇒ honest missing message');
var uReal = P.usageModel({ usage: { status: 'posted', estimated_credits: 2, operation_type: 'social_analysis' } });
ok(uReal.present === true && uReal.estimated_credits === 2 && uReal.provider_cost === null && uReal.actual_credits === null,
  '6: real usage shows present numbers only; absent cost/credits stay null (never fabricated)');

// ── 7. Render output: non-empty, escaped, no raw script, no secret patterns ───
var html = P.renderProductization({ confidence: 'low', evidence_quality: {}, recommendations: [{ action: '<script>x</script>', why: 'b' }] },
  { usage: { status: 'pending', estimated_credits: 1 } });
ok(typeof html === 'string' && html.length > 300, '7: renderProductization returns non-empty HTML');
ok(html.indexOf('fiis-route-bar') > -1 && html.indexOf('fiis-evidence-gate') > -1 && html.indexOf('fiis-usage-panel') > -1,
  '7: HTML contains route bar + evidence gate + usage panel');
var recHtml = P.renderRecommendations([{ action: '<script>alert(1)</script>', why: 'b', risk: 'alto' }], P.build({}, {}));
ok(recHtml.indexOf('<script>alert(1)</script>') === -1 && recHtml.indexOf('&lt;script&gt;') > -1,
  '7: recommendation text is HTML-escaped (no XSS passthrough)');
ok(!/sk-[A-Za-z0-9]{8}|api_key|Bearer |OPENAI_API_KEY/.test(html + recHtml), '7: no secret patterns in rendered output');

// ── 8. Disabled CTAs never carry a clickable action hook ─────────────────────
ok(html.indexOf('data-fiis-action') === -1 || /data-fiis-action="usage"/.test(html) === false
   ? html.indexOf('data-fiis-action') === -1 : true, '8: disabled actions expose no data-fiis-action hook');

// ── 8b. Corrective: meta wiring + action contract + honest gate ──────────────
// usage present in meta renders as posted/pending (not always missing); usage_event alias works.
var uPosted = P.usageModel({ usage: { status: 'posted', estimated_credits: 3 } });
ok(uPosted.present === true && uPosted.status === 'posted', '8b: usage in meta renders as posted (not missing)');
var uAlias = P.usageModel({ usage_event: { status: 'pending' } });
ok(uAlias.present === true && uAlias.status === 'pending', '8b: usage_event alias renders as pending');
ok(P.usageModel({}).present === false, '8b: missing usage still shows honest missing state');

// confident_recommendations_allowed=false in meta blocks approval.
var metaBlock = P.evidenceGate({ confidence: 'high', evidence_quality: { caption: true, metrics: true, transcript: true } }, { confident_recommendations_allowed: false });
ok(metaBlock.approval_rule === 'cannot_approve', '8b: meta.confident_recommendations_allowed=false blocks approval');

// A bare boolean service must NOT enable a button; only a real contract does.
var gateOk = P.evidenceGate({ confidence: 'high', evidence_quality: { caption: true, metrics: true, transcript: true } }, {});
var boolSvc = P.actions({ services: { approve: true, brief: true, draft: true, memory: true } }, gateOk).reduce(function (m, a) { m[a.key] = a; return m; }, {});
ok(boolSvc.approve.enabled === false && boolSvc.brief.enabled === false && boolSvc.memory.enabled === false,
  '8b: bare boolean services do NOT enable actions (no real contract)');
// A full contract (nonce + action) enables only where the rule also allows.
var realSvc = { approve: { nonce: 'n', action: 'ves_approve' }, brief: { nonce: 'n', action: 'ves_brief' }, memory: { nonce: 'n', url: '/x' }, draft: { nonce: 'n', action: 'ves_draft' } };
var wiredActs = P.actions({ services: realSvc, approved_insight: true }, gateOk).reduce(function (m, a) { m[a.key] = a; return m; }, {});
ok(wiredActs.brief.enabled === true && wiredActs.memory.enabled === true, '8b: real contract (nonce+action) enables brief/memory');
ok(wiredActs.draft.enabled === true, '8b: draft enables only with approved_insight/brief AND a real contract');
ok(P.actions({ services: realSvc }, gateOk).filter(function (a) { return a.key === 'draft'; })[0].enabled === false,
  '8b: draft stays disabled when no approved insight/brief, even with a wired service');

// Honest gate labeling: UI-only by default; server-reflected only when lifecycle status present.
ok(gateOk.enforced === false && gateOk.source === 'ui', '8b: gate is UI-only (no implied server enforcement) by default');
var gateSrv = P.evidenceGate({ confidence: 'high', evidence_quality: { caption: true } }, { lifecycle_status: 'reviewed' });
ok(gateSrv.enforced === true && gateSrv.server_status === 'reviewed', '8b: gate reflects server lifecycle status when threaded');
var gateHtml = P.renderEvidenceGate(P.build({ confidence: 'low', evidence_quality: {} }, {}));
ok(/Pre-chequeo en interfaz/.test(gateHtml), '8b: UI-only gate carries an honest "interface pre-check" label');

// ── 8c. Corrective: ves-frontend threads meta into the module ────────────────
var feSrc = require('fs').readFileSync(__dirname + '/../assets/js/ves-frontend.js', 'utf8');
ok(/renderStructuredItemAnalysis\s*=\s*\(payload, rawText = '', meta = \{\}\)/.test(feSrc), '8c: renderStructuredItemAnalysis accepts meta');
ok(/renderStructuredItemAnalysis\(structuredPayload, raw, meta\)/.test(feSrc), '8c: renderAnalysisPanel passes meta through');
ok(/renderProductization\(data, productizationMeta\)/.test(feSrc) && /build\(data, productizationMeta\)/.test(feSrc), '8c: module is called with productizationMeta (not {})');
ok(feSrc.indexOf('renderProductization(data, {})') === -1 && feSrc.indexOf('build(data, {})') === -1, '8c: no stale empty-meta productization calls remain');

// ── 9. Naming / localization regressions in the shipped frontend ─────────────
var fs = require('fs');
var fe = fs.readFileSync(__dirname + '/../assets/js/ves-frontend.js', 'utf8');
ok(fe.indexOf("aiAnalysis:'Análisis asistido por IA'") > -1, "9: model-first label replaced with user-value 'Análisis asistido por IA'");
ok(fe.indexOf("select:'Seleccionar'") > -1, "9: missing 'select' label added (Spanish default 'Seleccionar')");
ok(fe.indexOf('اسکرپرهای شبکه') === -1 && fe.indexOf('اسکرپر تبلیغات') === -1, "9: 'scraper' primary naming removed from fa locale");
var assets = fs.readFileSync(__dirname + '/../includes/class-ves-assets.php', 'utf8');
ok(/fiis-signal-productization/.test(assets) && /'ves-frontend'[\s\S]*?\['fiis-signal-productization'\]/.test(assets),
  "9: productization module registered and ves-frontend depends on it (load order)");

console.log('\nSignal productization rules: ' + pass + ' passed, ' + fail + ' failed');
process.exit(fail > 0 ? 1 : 0);
