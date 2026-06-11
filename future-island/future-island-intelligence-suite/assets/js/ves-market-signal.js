
(function vesMarketSignalLoader(){
    let attempts = 0;
    function showBootError(message) {
        try {
            document.querySelectorAll('[data-ves-market-signal-root]').forEach(function(el){
                if (el.__vesMarketSignalMounted) return;
                el.innerHTML = '<div style="padding:22px;color:#0f172a;background:#ffffff;font-family:Inter,system-ui,sans-serif;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 18px 45px rgba(15,23,42,.08)"><strong>MarketSignal could not start.</strong><br><span style="color:#64748b">' + String(message || 'WordPress React runtime is not available.').replace(/[<>]/g, '') + '</span></div>';
            });
        } catch (_) {}
    }
    function startWhenReady(){
        attempts += 1;
        if (!window.wp || !window.wp.element || !window.wp.element.createContext || !window.wp.element.useState) {
            if (attempts < 100) {
                setTimeout(startWhenReady, 100);
            } else {
                showBootError('wp.element was not loaded. Check theme wp_footer(), script optimization/minification, or blocked WordPress core scripts.');
                console.error('[MarketSignal] wp.element is missing. The shortcode rendered, but the React runtime did not load.');
            }
            return;
        }
        const { useState, useEffect, useCallback, useContext, createContext } = window.wp.element;
        const React = window.wp.element;

function msCfg() {
    return window.VES_MARKET_SIGNAL || {};
}
async function msFetch(url, body) {
    const cfg = msCfg();
    const headers = { 'Content-Type': 'application/json' };
    if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
    const res = await fetch(url, {
        method: body ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers,
        body: body ? JSON.stringify(body) : undefined
    });
    let data = null;
    try { data = await res.json(); } catch (_) { data = null; }
    if (!res.ok) {
        const msg = data && data.message ? data.message : `Request failed (${res.status})`;
        const err = new Error(msg);
        err.status = res.status;
        err.code = data && data.code;
        throw err;
    }
    return data;
}
function msPersistState(state) {
    const cfg = msCfg();
    if (!cfg.stateUrl) return;
    clearTimeout(window.__VES_MS_SAVE_TIMER);
    window.__VES_MS_SAVE_TIMER = setTimeout(() => {
        msFetch(cfg.stateUrl, { state }).catch(err => {
            console.warn('MarketSignal state save failed', err);
            window.dispatchEvent(new CustomEvent('ves-market-signal-save-error', { detail: { message: err.message || 'Save failed' } }));
        });
    }, 450);
}
/* --- Functional local AI fallback -------------------------------------------------
   Keeps the MVP usable without exposing API keys in the browser. This deterministic
   generator turns user input into structured signals, insights, briefs and drafts.
   A backend ChatGPT/OpenAI + Apify endpoint handles production generation without exposing keys in the browser. */
function getPromptField(prompt, label) {
    const re = new RegExp(label + ':\\s*([\\s\\S]*?)(?:\\n[A-Z][A-Za-z ]{1,30}:|\\nReturn ONLY valid JSON|$)', 'i');
    const m = String(prompt || '').match(re);
    return m ? m[1].trim() : '';
}
function cleanWords(text) {
    const stop = new Set(['the', 'and', 'for', 'with', 'from', 'that', 'this', 'into', 'para', 'con', 'una', 'uno', 'los', 'las', 'del', 'por', 'que', 'como', 'brand', 'audience', 'general', 'source', 'input']);
    return String(text || '').toLowerCase().replace(/https?:\/\/\S+/g, ' ').replace(/[^a-z0-9áéíóúñü#@ ]+/gi, ' ').split(/\s+/).filter(w => w.length > 3 && !stop.has(w)).slice(0, 8);
}
function titleCase(s) { return String(s || '').replace(/[_-]/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); }
function summarize(text, max = 170) {
    const s = String(text || '').replace(/\s+/g, ' ').trim();
    if (!s)
        return 'The workspace needs clearer market evidence before execution.';
    return s.length > max ? s.slice(0, max).replace(/\s+\S*$/, '') + '…' : s;
}
function buildSignals(prompt) {
    const input = getPromptField(prompt, 'Input') || prompt;
    const brand = getPromptField(prompt, 'Brand') || 'the brand';
    const audience = getPromptField(prompt, 'Audience') || 'the target audience';
    const kws = cleanWords(input + ' ' + brand + ' ' + audience);
    const main = kws[0] || 'market';
    const second = kws[1] || 'audience';
    const third = kws[2] || 'content';
    return {
        signals: [
            { title: `${titleCase(main)} demand signal`, summary: `The input suggests active interest around ${main}. Treat this as a signal to validate search, social and competitor evidence before creating content.`, type: 'trend', confidence: 'medium' },
            { title: `Audience question around ${titleCase(second)}`, summary: `People likely need clearer answers about ${second}. Use this as a question-led content angle rather than a generic brand message.`, type: 'audience_question', confidence: 'medium' },
            { title: `Content gap: ${titleCase(third)} proof`, summary: `Current context points to a possible proof gap. Stronger examples, comparisons or demonstrations could reduce uncertainty.`, type: 'content_gap', confidence: 'high' },
            { title: `Competitor positioning pressure`, summary: `If competitors already own the obvious category language, the workspace should build a sharper angle tied to ${main} and measurable value.`, type: 'competitor_move', confidence: 'low' },
            { title: `Action opportunity: insight-to-brief flow`, summary: `The most useful next step is to convert this research into a short brief with audience, message, proof points and output format.`, type: 'opportunity', confidence: 'high' }
        ],
        insights: [
            { title: `Do not jump from ${titleCase(main)} data to content too fast`, body: `The strongest path is to interpret the signal first: what changed, who cares, what gap exists, and what decision the team should make. Input summary: ${summarize(input, 220)}`, type: 'pattern' },
            { title: `The useful middle layer is the brief`, body: `This workspace should turn research into a structured brief before generating drafts. That keeps outputs tied to evidence instead of random prompting.`, type: 'next_action' },
            { title: `Potential gap: proof and specificity`, body: `The material points to a need for more concrete claims, examples and audience-specific proof. Avoid vague content until this is validated.`, type: 'gap' }
        ]
    };
}
function buildBrief(prompt) {
    const input = getPromptField(prompt, 'Input') || prompt;
    const brand = getPromptField(prompt, 'Brand') || 'Professional, evidence-led brand';
    const audience = getPromptField(prompt, 'Audience') || 'Marketing teams and agencies';
    const kws = cleanWords(input + ' ' + brand + ' ' + audience);
    const topic = titleCase(kws.slice(0, 3).join(' ') || 'Market Signal');
    return {
        title: `${topic} Brief`,
        objective: `Turn the selected signal into an actionable marketing output that explains the opportunity, reduces noise, and gives the team a clear next move.`,
        target_audience: audience,
        key_messages: `1. Start from evidence, not assumptions. 2. Interpret the signal before creating. 3. Use the output as a testable campaign/input, not a final truth.`,
        tone_and_style: `Clear, strategic, practical, direct. Avoid hype and unsupported claims.`,
        context_notes: `Source context: ${summarize(input, 260)} Brand context: ${summarize(brand, 120)}`
    };
}
function buildDraft(prompt) {
    const ctx = getPromptField(prompt, 'Context') || prompt;
    const voice = getPromptField(prompt, 'Brand voice') || 'professional and engaging';
    const kws = cleanWords(ctx + ' ' + voice);
    const topic = titleCase(kws.slice(0, 3).join(' ') || 'Market Signal');
    return {
        title: `${topic}: from signal to action`,
        content: `Most teams do not need more raw data. They need a clearer read on what the signal means.\n\nThe useful workflow is simple: capture the signal, interpret the pattern, define the brief, then create the output.\n\nFor this case, the next move is to test a focused message around ${kws[0] || 'the core audience need'} and support it with concrete proof, not generic content.\n\nContext used: ${summarize(ctx, 260)}`
    };
}
async function callMarketSignalAI(prompt) {
    const cfg = msCfg();
    const p = String(prompt || '');
    if (cfg.generateUrl) {
        try {
            const data = await msFetch(cfg.generateUrl, { prompt: p });
            if (data && data.payload) return data.payload;
        }
        catch (err) {
            // Permission / credit failures must not be bypassed by local fallback.
            if (err && (err.status === 401 || err.status === 403 || err.code === 'insufficient_credits' || err.code === 'plan_limit_reached')) {
                throw err;
            }
            if (cfg.browserFallback === false || cfg.mode === 'commercial-production') {
                throw err;
            }
            console.warn('MarketSignal backend generator unavailable.', err);
        }
    }
    await new Promise(r => setTimeout(r, 220));
    if (p.includes('"signals"') && p.includes('"insights"'))
        return buildSignals(p);
    if (/senior content strategist/i.test(p))
        return buildBrief(p);
    if (/expert copywriter/i.test(p))
        return buildDraft(p);
    return buildSignals(p);
}
/* ─── Store ───────────────────────────────────────────────────────────────── */
const KEY = "ms_v2";
const EMPTY = { workspaces: [], sources: [], signals: [], insights: [], briefs: [], drafts: [], runs: [], usage: [] };
const uid = () => Math.random().toString(36).slice(2, 10);
const now = () => Date.now();
function useStore() {
    const [s, set] = useState(() => { try {
        return JSON.parse(localStorage.getItem(KEY)) || EMPTY;
    }
    catch (_a) {
        return EMPTY;
    } });
    useEffect(() => {
        const cfg = msCfg();
        if (!cfg.stateUrl) return;
        let alive = true;
        msFetch(cfg.stateUrl).then(data => {
            if (!alive || !data || !data.state) return;
            const remote = { ...EMPTY, ...data.state };
            let localCached = null;
            try { localCached = { ...EMPTY, ...(JSON.parse(localStorage.getItem(KEY)) || EMPTY) }; } catch (_) { localCached = null; }
            const count = st => Object.keys(EMPTY).reduce((sum, k) => sum + (((st || {})[k] || []).length || 0), 0);
            if (count(remote) === 0 && localCached && count(localCached) > 0) {
                // Upgrade path: preserve pre-production local MVP data and sync it server-side.
                msPersistState(localCached);
                set(localCached);
                return;
            }
            localStorage.setItem(KEY, JSON.stringify(remote));
            set(remote);
        }).catch(err => {
            console.warn('MarketSignal state load failed; using local cache.', err);
            window.dispatchEvent(new CustomEvent('ves-market-signal-load-error', { detail: { message: err.message || 'Load failed' } }));
        });
        return () => { alive = false; };
    }, []);
    const save = useCallback(fn => set(p => {
        const base = p || EMPTY;
        const n = { ...EMPTY, ...fn(base) };
        localStorage.setItem(KEY, JSON.stringify(n));
        msPersistState(n);
        return n;
    }), []);
    return [s, save];
}
/* ─── Toast ───────────────────────────────────────────────────────────────── */
const ToastCtx = createContext(null);
function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const add = useCallback((msg, type = "success") => {
        const id = uid();
        setToasts(p => [...p, { id, msg, type }]);
        setTimeout(() => setToasts(p => p.filter(t => t.id !== id)), 3500);
    }, []);
    return (React.createElement(ToastCtx.Provider, { value: add },
        children,
        React.createElement("div", { className: "toast-stack" }, toasts.map(t => (React.createElement("div", { key: t.id, className: `toast toast-${t.type}` },
            React.createElement("span", { className: "toast-dot" }),
            t.msg))))));
}
const useToast = () => useContext(ToastCtx);
/* ─── Helpers ─────────────────────────────────────────────────────────────── */
const fmtDate = d => new Date(d).toLocaleDateString("en-US", { month: "short", day: "numeric" });
const fmtFull = d => new Date(d).toLocaleString("en-US", { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
const cn = (...a) => a.filter(Boolean).join(" ");
const trunc = (s, n = 100) => s && s.length > n ? s.slice(0, n) + "…" : s;
/* ─── Animated Counter ────────────────────────────────────────────────────── */
function Counter({ value }) {
    const [d, setD] = useState(0);
    useEffect(() => {
        const end = Number(value) || 0;
        if (!end) {
            setD(0);
            return;
        }
        const step = Math.max(1, Math.ceil(end / 25));
        let cur = 0;
        const t = setInterval(() => { cur += step; if (cur >= end) {
            setD(end);
            clearInterval(t);
        }
        else
            setD(cur); }, 35);
        return () => clearInterval(t);
    }, [value]);
    return React.createElement(React.Fragment, null, d);
}
/* ─── SVG Icon ────────────────────────────────────────────────────────────── */
function Ic({ d, s = 16, ...p }) {
    const paths = Array.isArray(d) ? d : [d];
    return (React.createElement("svg", { width: s, height: s, viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: "1.75", strokeLinecap: "round", strokeLinejoin: "round", ...p }, paths.map((path, i) => React.createElement("path", { key: i, d: path }))));
}
const ic = {
    dashboard: "M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6",
    workspaces: "M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4",
    runs: "M13 10V3L4 14h7v7l9-11h-7",
    signals: "M22 12h-4l-3 9L9 3l-3 9H2",
    insights: "M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M17.657 17.657l-.707-.707M12 21v-1M4.343 17.657l.707-.707M1 12h1M6.343 6.343l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z",
    briefs: "M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z",
    drafts: "M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z",
    plus: "M12 5v14M5 12h14",
    check: "M20 6L9 17l-5-5",
    x: "M18 6L6 18M6 6l12 12",
    eye: "M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8zM12 9a3 3 0 100 6 3 3 0 000-6z",
    copy: "M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3",
    trash: "M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16",
    archive: "M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4",
    search: "M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0",
    menu: "M4 6h16M4 12h16M4 18h16",
    zap: "M13 2L3 14h9l-1 8 10-12h-9l1-8z",
    arrow: "M5 12h14m-7-7l7 7-7 7",
    chevD: "M19 9l-7 7-7-7",
    chevU: "M5 15l7-7 7 7",
    chevL: "M15 19l-7-7 7-7",
    globe: ["M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z", "M2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"],
    credit: "M3 10h18M7 15h.01M11 15h2M3 6h18a2 2 0 012 2v8a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2z",
    type: "M4 7V4h16v3M9 20h6M12 4v16",
    target: "M12 22a10 10 0 100-20 10 10 0 000 20zM12 18a6 6 0 100-12 6 6 0 000 12zM12 14a2 2 0 100-4 2 2 0 000 4z",
    hash: "M4 9h16M4 15h16M10 3L8 21M16 3l-2 18",
    trend: "M23 6l-9.5 9.5-5-5L1 18M17 6h6v6",
    source: "M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4",
    activity: "M22 12h-4l-3 9L9 3l-3 9H2",
};
/* ─── Color maps ──────────────────────────────────────────────────────────── */
const SC = { draft: "slate", reviewed: "blue", approved: "green", rejected: "red", new: "accent", completed: "green", failed: "red", running: "blue", queued: "yellow", regenerated: "purple" };
const TC = { trend: "blue", competitor_move: "red", audience_question: "purple", keyword_gap: "violet", content_gap: "amber", market_shift: "orange", opportunity: "green", risk: "red", pattern: "blue", gap: "amber", next_action: "green", other: "slate" };
const TCC = { trend: "#79c0ff", competitor_move: "#f85149", audience_question: "#bc8cff", keyword_gap: "#a371f7", content_gap: "#ffa657", market_shift: "#ff7b72", opportunity: "#3fb950", risk: "#f85149", pattern: "#79c0ff", gap: "#ffa657", next_action: "#3fb950", other: "#656d76" };
/* ─── Base UI ─────────────────────────────────────────────────────────────── */
const Badge = ({ text, color = "slate" }) => React.createElement("span", { className: `badge badge-${color}` }, text);
function Btn({ v = "primary", sz = "md", icon, spin, onClick, disabled, children, style, full, title }) {
    return (React.createElement("button", { className: cn(`btn btn-${v} btn-${sz}`, full && "btn-full"), onClick: onClick, disabled: disabled || spin, style: style, title: title },
        spin ? React.createElement("svg", { width: "14", height: "14", className: "spin-ico", viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: "2" },
            React.createElement("path", { d: "M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4" }))
            : icon && React.createElement(Ic, { d: ic[icon] || icon, s: 14 }),
        children));
}
function Fld({ label, children }) {
    return React.createElement("div", { className: "fld" },
        label && React.createElement("label", { className: "flbl" }, label),
        children);
}
const Inp = ({ label, ...p }) => React.createElement(Fld, { label: label },
    React.createElement("input", { className: "inp", ...p }));
const Txa = ({ label, rows = 3, ...p }) => React.createElement(Fld, { label: label },
    React.createElement("textarea", { className: "inp", rows: rows, ...p }));
const Sel = ({ label, options, ...p }) => (React.createElement(Fld, { label: label },
    React.createElement("select", { className: "inp", ...p }, options.map(([v, l]) => React.createElement("option", { key: v, value: v }, l)))));
function Modal({ open, onClose, title, width = 520, children, footer }) {
    useEffect(() => {
        const h = e => e.key === "Escape" && onClose();
        if (open)
            window.addEventListener("keydown", h);
        return () => window.removeEventListener("keydown", h);
    }, [open, onClose]);
    if (!open)
        return null;
    return (React.createElement("div", { className: "backdrop", onClick: e => e.target === e.currentTarget && onClose() },
        React.createElement("div", { className: "modal", style: { maxWidth: width } },
            React.createElement("div", { className: "mhd" },
                React.createElement("span", { className: "mtitle" }, title),
                React.createElement("button", { className: "ibtn", onClick: onClose },
                    React.createElement(Ic, { d: ic.x, s: 15 }))),
            React.createElement("div", { className: "mbody" }, children),
            footer && React.createElement("div", { className: "mfoot" }, footer))));
}
const Empty = ({ icon = "search", title, sub, cta }) => (React.createElement("div", { className: "empty" },
    React.createElement("div", { className: "empty-ring" },
        React.createElement(Ic, { d: ic[icon] || icon, s: 22 })),
    React.createElement("p", { className: "empty-t" }, title),
    sub && React.createElement("p", { className: "empty-s" }, sub),
    cta && React.createElement("div", { style: { marginTop: 16 } }, cta)));
const SearchBar = ({ value, onChange, placeholder = "Search…" }) => (React.createElement("div", { className: "sbar" },
    React.createElement(Ic, { d: ic.search, s: 13, className: "sbar-ico" }),
    React.createElement("input", { className: "sbar-inp", value: value, onChange: e => onChange(e.target.value), placeholder: placeholder }),
    value && React.createElement("button", { className: "ibtn", style: { position: "absolute", right: 4, top: "50%", transform: "translateY(-50%)" }, onClick: () => onChange("") },
        React.createElement(Ic, { d: ic.x, s: 11 }))));
const PanelHd = ({ title, action }) => (React.createElement("div", { className: "phd" },
    title && React.createElement("h3", { className: "ptitle" }, title),
    action && React.createElement("div", { className: "pact" }, action)));
/* ─── FI Data Viz Components ─────────────────────────────────────────────── */
const Sparkline = ({ data = [], w = 80, h = 28, color = 'var(--ac)' }) => {
    if (!data || data.length < 2) return null;
    const min = Math.min(...data), max = Math.max(...data), range = max - min || 1;
    const pts = data.map((v, i) => `${(i / (data.length - 1)) * w},${h - ((v - min) / range) * (h - 4) - 2}`).join(' ');
    return React.createElement('svg', { width: w, height: h, viewBox: `0 0 ${w} ${h}`, 'aria-hidden': 'true', style: { display: 'block', flexShrink: 0 } },
        React.createElement('polyline', { points: pts, fill: 'none', stroke: color, strokeWidth: '2', strokeLinecap: 'round', strokeLinejoin: 'round' }));
};
const DonutStat = ({ pct = 0, size = 44, color = 'var(--ac)', bg = 'var(--b1)', children }) => {
    const r = (size - 7) / 2, cx = size / 2, cy = size / 2;
    const circ = 2 * Math.PI * r;
    const dash = (Math.min(Math.max(pct, 0), 100) / 100) * circ;
    return React.createElement('div', { style: { width: size, height: size, position: 'relative', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 } },
        React.createElement('svg', { width: size, height: size, viewBox: `0 0 ${size} ${size}`, 'aria-hidden': 'true' },
            React.createElement('circle', { cx, cy, r, fill: 'none', stroke: bg, strokeWidth: '5.5' }),
            pct > 0 && React.createElement('circle', { cx, cy, r, fill: 'none', stroke: color, strokeWidth: '5.5', strokeDasharray: `${dash} ${circ - dash}`, strokeDashoffset: circ * 0.25, strokeLinecap: 'round' })),
        React.createElement('div', { style: { position: 'absolute', fontSize: size < 42 ? 9 : 11, fontWeight: 700, color: 'var(--t1)', fontFamily: "'IBM Plex Mono',monospace", lineHeight: 1 } }, children || `${Math.round(pct)}%`));
};
const TrendDelta = ({ value, suffix = '%' }) => {
    if (value == null) return null;
    const pos = value >= 0;
    return React.createElement('span', { className: `trend-delta ${pos ? 'trend-up' : 'trend-down'}` }, pos ? '▲' : '▼', ' ', Math.abs(value), suffix);
};
const MiniBar = ({ value = 0, max = 100, color = 'var(--ac)' }) => {
    const pct = Math.min(Math.round((value / (max || 1)) * 100), 100);
    return React.createElement('div', { className: 'mini-bar-wrap', 'aria-hidden': 'true' },
        React.createElement('div', { className: 'mini-bar-fill', style: { width: `${pct}%`, background: color } }));
};
/* ─── Sidebar ─────────────────────────────────────────────────────────────── */
const NAV = [{ id: "dashboard", label: "Dashboard", icon: "dashboard" }, { id: "workspaces", label: "Workspaces", icon: "workspaces" }, { id: "runs", label: "Runs", icon: "runs" }, { id: "signals", label: "Signals", icon: "signals" }, { id: "insights", label: "Insights", icon: "insights" }, { id: "briefs", label: "Briefs", icon: "briefs" }, { id: "drafts", label: "Drafts", icon: "drafts" }];
function Sidebar({ page, setPage, mob, setMob, store }) {
    const base = page.split("/")[0];
    const counts = {
        workspaces: store.workspaces.filter(w => w.status !== "archived").length,
        signals: store.signals.filter(s => s.status === "new").length,
        insights: store.insights.filter(i => i.status === "draft").length,
        runs: store.runs.length, briefs: store.briefs.length, drafts: store.drafts.length,
    };
    return (React.createElement(React.Fragment, null,
        mob && React.createElement("div", { className: "mob-ov", onClick: () => setMob(false) }),
        React.createElement("aside", { className: cn("sidebar", mob && "sb-open") },
            React.createElement("div", { className: "sb-brand" },
                React.createElement("div", { className: "sb-logo" },
                    React.createElement("svg", { width: "17", height: "17", viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: "2" },
                        React.createElement("path", { d: "M22 12h-4l-3 9L9 3l-3 9H2" }))),
                React.createElement("div", null,
                    React.createElement("div", { className: "sb-name" }, "MarketSignal"),
                    React.createElement("div", { className: "sb-tag" }, "Intelligence Platform"))),
            React.createElement("nav", { className: "sb-nav" },
                React.createElement("div", { className: "sb-sec" }, "Navigation"),
                NAV.map(n => (React.createElement("button", { key: n.id, className: cn("sbi", base === n.id && "sbi-on"), onClick: () => { setPage(n.id); setMob(false); } },
                    React.createElement(Ic, { d: ic[n.icon], s: 15 }),
                    React.createElement("span", null, n.label),
                    counts[n.id] > 0 && React.createElement("span", { className: cn("sb-ct", n.id === "signals" && "sb-ct-hot") }, counts[n.id]))))),
            React.createElement("div", { className: "sb-foot" },
                React.createElement("div", { className: "sb-ver" }, "v0.9.31 \u00B7 Source \u2192 Signal \u2192 Draft")))));
}
/* ─── Dashboard ───────────────────────────────────────────────────────────── */
function Dashboard({ store, setPage }) {
    const { workspaces, runs, insights, drafts, usage, signals } = store;
    const totalCr = usage.reduce((s, e) => s + (e.credits_used || 0), 0);
    const newSig = signals.filter(s => s.status === "new").length;
    const totalSig = signals.length;
    const sigPct = totalSig > 0 ? Math.round((newSig / totalSig) * 100) : 0;
    const STATS = [
        { label: "Workspaces", value: workspaces.filter(w => w.status !== "archived").length, icon: "workspaces", accent: "#3A61E1", barColor: "#3A61E1", barMax: 20 },
        { label: "Total Runs", value: runs.length, icon: "runs", accent: "#F59E0B", barColor: "#F59E0B", barMax: 50 },
        { label: "New Signals", value: newSig, icon: "signals", accent: "#4a5000", accentBg: "rgba(210,240,80,.18)", barColor: "#D2F050", barMax: totalSig || 10, extra: sigPct > 0 ? React.createElement(TrendDelta, { value: sigPct }) : null },
        { label: "Credits Used", value: totalCr, icon: "credit", accent: "#16A34A", barColor: "#22C55E", barMax: Math.max(totalCr * 1.5, 100) },
    ];
    const activity = [...runs, ...signals.filter(s => s.status === "new"), ...insights].sort((a, b) => b.created_at - a.created_at).slice(0, 10);
    return (React.createElement("div", { className: "page pg-anim" },
        React.createElement("div", { className: "phd2" },
            React.createElement("div", null,
                React.createElement("h1", { className: "ptitle2" }, "Intelligence Hub"),
                React.createElement("p", { className: "psub" }, "Market signals \u00B7 Insights \u00B7 Briefs \u00B7 Drafts")),
            React.createElement(Btn, { icon: "plus", onClick: () => setPage("workspaces") }, "New Workspace")),
        React.createElement("div", { className: "sg" }, STATS.map((s, i) => (React.createElement("div", { key: s.label, className: "sc", style: { animationDelay: `${i * .07}s` } },
            React.createElement("div", { className: "si", style: { background: s.accentBg || `${s.accent}18`, color: s.accent } },
                React.createElement(Ic, { d: ic[s.icon] || s.icon, s: 16 })),
            React.createElement("div", { style: { display: 'flex', alignItems: 'baseline', gap: 8, flexWrap: 'wrap' } },
                React.createElement("div", { className: "sv" }, React.createElement(Counter, { value: s.value })),
                s.extra || null),
            React.createElement("div", { className: "sl" }, s.label),
            React.createElement(MiniBar, { value: s.value, max: s.barMax, color: s.barColor }))))),
        React.createElement("div", { className: "dcols" },
            React.createElement("div", { className: "dmain" }, [
                { title: "Recent Runs", items: runs.slice(0, 5), empty: "No runs yet", nav: "runs", row: r => React.createElement("div", { key: r.id, className: "arow" },
                        React.createElement("div", null,
                            React.createElement("p", { className: "at" }, r.label || r.type),
                            React.createElement("p", { className: "am" }, fmtFull(r.created_at))),
                        React.createElement(Badge, { text: r.status, color: SC[r.status] })) },
                { title: "Recent Insights", items: insights.slice(0, 5), empty: "No insights yet", nav: "insights", row: i => { var _a; return React.createElement("div", { key: i.id, className: "arow" },
                        React.createElement("div", null,
                            React.createElement("p", { className: "at" }, i.title),
                            React.createElement("p", { className: "am", style: { textTransform: "capitalize" } }, (_a = i.type) === null || _a === void 0 ? void 0 : _a.replace("_", " "))),
                        React.createElement(Badge, { text: i.status, color: SC[i.status] })); } },
                { title: "Recent Drafts", items: drafts.slice(0, 5), empty: "No drafts yet", nav: "drafts", row: d => React.createElement("div", { key: d.id, className: "arow" },
                        React.createElement("div", null,
                            React.createElement("p", { className: "at" }, d.title),
                            React.createElement("p", { className: "am" },
                                d.type,
                                " \u00B7 ",
                                d.platform)),
                        React.createElement(Badge, { text: d.status, color: SC[d.status] })) },
            ].map(col => (React.createElement("div", { key: col.title, className: "panel" },
                React.createElement("div", { className: "phd3" },
                    React.createElement("span", { className: "ptitle3" }, col.title),
                    React.createElement("button", { className: "tlink", onClick: () => setPage(col.nav) },
                        "All ",
                        React.createElement(Ic, { d: ic.arrow, s: 12 }))),
                React.createElement("div", { className: "pbody" }, col.items.length === 0 ? React.createElement("p", { className: "mt" }, col.empty) : col.items.map(col.row)))))),
            React.createElement("div", { className: "dside" },
                React.createElement("div", { className: "panel" },
                    React.createElement("div", { className: "phd3" },
                        React.createElement("span", { className: "ptitle3" }, "Activity Feed")),
                    React.createElement("div", { className: "pbody" }, activity.length === 0 ? React.createElement("p", { className: "mt" }, "No activity yet") : activity.map((item, i) => (React.createElement("div", { key: item.id, className: "act-row", style: { animationDelay: `${i * .05}s` } },
                        React.createElement("div", { className: "act-dot", style: { background: item.status === "new" ? "#f85149" : item.status === "completed" ? "#3fb950" : "#4493f8" } }),
                        React.createElement("div", null,
                            React.createElement("p", { className: "at" }, item.title || item.label || item.type),
                            React.createElement("p", { className: "am" }, fmtFull(item.created_at)))))))),
                React.createElement("div", { className: "cta-card" },
                    React.createElement("div", { className: "cta-glow" }),
                    React.createElement("h3", { className: "cta-t" }, "Start an intelligence run"),
                    React.createElement("p", { className: "cta-s" }, "Source \u2192 Signal \u2192 Insight \u2192 Brief \u2192 Draft"),
                    React.createElement("div", { className: "flow-row" }, ["Source", "→", "Signal", "→", "Insight", "→", "Brief", "→", "Draft"].map((s, i) => (React.createElement("span", { key: i, className: s === "→" ? "farrow" : "fpill" }, s)))),
                    React.createElement(Btn, { v: "accent", icon: "zap", onClick: () => setPage("workspaces"), style: { marginTop: 16 } }, "Start Research"))))));
}
/* ─── Workspaces ──────────────────────────────────────────────────────────── */
const GRADS = ["linear-gradient(135deg,#1e3a5f,#0c4a6e)", "linear-gradient(135deg,#1a1a2e,#16213e)", "linear-gradient(135deg,#1e1b4b,#312e81)", "linear-gradient(135deg,#14532d,#166534)", "linear-gradient(135deg,#431407,#7c2d12)", "linear-gradient(135deg,#1c1917,#3c2a1e)"];
function Workspaces({ store, save, setPage }) {
    const toast = useToast();
    const [open, setOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [search, setSearch] = useState("");
    const [form, setForm] = useState({ name: "", description: "", brand_context: "", target_audience: "", industry: "", website_url: "" });
    const active = store.workspaces.filter(w => w.status !== "archived").filter(w => !search || w.name.toLowerCase().includes(search.toLowerCase()));
    const create = async () => {
        if (!form.name.trim())
            return;
        setSaving(true);
        save(s => ({ ...s, workspaces: [{ id: uid(), ...form, credit_balance: 100, status: "active", created_at: now(), gradient: GRADS[s.workspaces.length % GRADS.length] }, ...s.workspaces] }));
        toast("Workspace created");
        setOpen(false);
        setForm({ name: "", description: "", brand_context: "", target_audience: "", industry: "", website_url: "" });
        setSaving(false);
    };
    const archive = id => { save(s => ({ ...s, workspaces: s.workspaces.map(w => w.id === id ? { ...w, status: "archived" } : w) })); toast("Archived"); };
    const wsRuns = id => store.runs.filter(r => r.workspace_id === id).length;
    const wsSig = id => store.signals.filter(s => s.workspace_id === id && s.status === "new").length;
    return (React.createElement("div", { className: "page pg-anim" },
        React.createElement("div", { className: "phd2" },
            React.createElement("div", null,
                React.createElement("h1", { className: "ptitle2" }, "Workspaces"),
                React.createElement("p", { className: "psub" }, "Clients, brands & campaign areas")),
            React.createElement(Btn, { icon: "plus", onClick: () => setOpen(true) }, "New Workspace")),
        React.createElement(SearchBar, { value: search, onChange: setSearch, placeholder: "Search workspaces\u2026" }),
        active.length === 0
            ? React.createElement(Empty, { icon: "workspaces", title: "No workspaces yet", sub: "Create your first workspace to begin capturing market intelligence.", cta: React.createElement(Btn, { icon: "plus", onClick: () => setOpen(true) }, "Create Workspace") })
            : React.createElement("div", { className: "wsgrid" }, active.map((w, i) => (React.createElement("div", { key: w.id, className: "wscard", style: { animationDelay: `${i * .06}s` }, onClick: () => setPage(`workspace/${w.id}`) },
                React.createElement("div", { className: "wstop", style: { background: w.gradient || GRADS[0] } },
                    React.createElement(Ic, { d: ic.workspaces, s: 22, style: { color: "rgba(255,255,255,.55)" } }),
                    React.createElement("button", { className: "ibtn", style: { color: "rgba(255,255,255,.4)" }, onClick: e => { e.stopPropagation(); archive(w.id); } },
                        React.createElement(Ic, { d: ic.archive, s: 13 }))),
                React.createElement("div", { className: "wsbody" },
                    React.createElement("h3", { className: "wsname" }, w.name),
                    w.description && React.createElement("p", { className: "wsdesc" }, trunc(w.description)),
                    React.createElement("div", { className: "wschips" },
                        w.industry && React.createElement("span", { className: "chip" }, w.industry),
                        React.createElement("span", { className: "chip chip-cr" },
                            "\u26A1 ",
                            w.credit_balance,
                            " cr")),
                    React.createElement("div", { className: "wsstats" },
                        React.createElement("span", null,
                            wsRuns(w.id),
                            " runs"),
                        wsSig(w.id) > 0 && React.createElement("span", { className: "hotsig" },
                            "\uD83D\uDD34 ",
                            wsSig(w.id),
                            " new signals")),
                    React.createElement("div", { className: "wsopen" },
                        "Open workspace ",
                        React.createElement(Ic, { d: ic.arrow, s: 12 }))))))),
        React.createElement(Modal, { open: open, onClose: () => setOpen(false), title: "New Workspace", footer: React.createElement(React.Fragment, null,
                React.createElement(Btn, { v: "ghost", onClick: () => setOpen(false) }, "Cancel"),
                React.createElement(Btn, { spin: saving, onClick: create, disabled: !form.name.trim() }, "Create")) },
            React.createElement(Inp, { label: "Name *", value: form.name, onChange: e => setForm({ ...form, name: e.target.value }), placeholder: "Client or brand name" }),
            React.createElement(Txa, { label: "Description", value: form.description, onChange: e => setForm({ ...form, description: e.target.value }), placeholder: "What is this workspace for?" }),
            React.createElement("div", { className: "twoc" },
                React.createElement(Inp, { label: "Industry", value: form.industry, onChange: e => setForm({ ...form, industry: e.target.value }), placeholder: "e.g. SaaS" }),
                React.createElement(Inp, { label: "Website", value: form.website_url, onChange: e => setForm({ ...form, website_url: e.target.value }), placeholder: "https://\u2026" })),
            React.createElement(Txa, { label: "Brand Context", value: form.brand_context, onChange: e => setForm({ ...form, brand_context: e.target.value }), placeholder: "Voice, tone, positioning notes\u2026" }),
            React.createElement(Inp, { label: "Target Audience", value: form.target_audience, onChange: e => setForm({ ...form, target_audience: e.target.value }), placeholder: "Who are we talking to?" }))));
}
/* ─── WS Detail ───────────────────────────────────────────────────────────── */
const TABS = ["Sources", "Runs", "Signals", "Insights", "Briefs", "Drafts", "Credits"];
function WorkspaceDetail({ wsId, store, save, setPage }) {
    const [tab, setTab] = useState("Sources");
    const ws = store.workspaces.find(w => w.id === wsId);
    if (!ws)
        return React.createElement("div", { className: "page" },
            React.createElement(Btn, { v: "ghost", icon: "chevL", onClick: () => setPage("workspaces") }, "Back"));
    const upd = data => save(s => ({ ...s, workspaces: s.workspaces.map(w => w.id === wsId ? { ...w, ...data } : w) }));
    const counts = {
        Sources: store.sources.filter(s => s.workspace_id === wsId && s.status !== "archived").length,
        Runs: store.runs.filter(r => r.workspace_id === wsId).length,
        Signals: store.signals.filter(s => s.workspace_id === wsId).length,
        Insights: store.insights.filter(i => i.workspace_id === wsId).length,
        Briefs: store.briefs.filter(b => b.workspace_id === wsId).length,
        Drafts: store.drafts.filter(d => d.workspace_id === wsId).length,
    };
    return (React.createElement("div", { className: "page pg-anim" },
        React.createElement("button", { className: "backbtn", onClick: () => setPage("workspaces") },
            React.createElement(Ic, { d: ic.chevL, s: 13 }),
            " All Workspaces"),
        React.createElement("div", { className: "wsdethd" },
            React.createElement("div", { className: "wsdetinfo" },
                React.createElement("div", { className: "wsdetname" }, ws.name),
                ws.industry && React.createElement("span", { className: "chip chip-dim" }, ws.industry)),
            React.createElement("div", { className: "wscr" },
                React.createElement(Ic, { d: ic.zap, s: 13 }),
                ws.credit_balance,
                " credits")),
        React.createElement("div", { className: "tabrow" }, TABS.map(t => (React.createElement("button", { key: t, className: cn("tab", tab === t && "tbon"), onClick: () => setTab(t) },
            t,
            counts[t] > 0 && React.createElement("span", { className: "tbct" }, counts[t]))))),
        tab === "Sources" && React.createElement(SourcesPanel, { ws: ws, store: store, save: save }),
        tab === "Runs" && React.createElement(RunsPanel, { ws: ws, store: store, save: save, upd: upd }),
        tab === "Signals" && React.createElement(SignalsPanel, { ws: ws, store: store, save: save }),
        tab === "Insights" && React.createElement(InsightsPanel, { ws: ws, store: store, save: save }),
        tab === "Briefs" && React.createElement(BriefsPanel, { ws: ws, store: store, save: save, upd: upd }),
        tab === "Drafts" && React.createElement(DraftsPanel, { ws: ws, store: store, save: save, upd: upd }),
        tab === "Credits" && React.createElement(CreditsPanel, { ws: ws, store: store, save: save })));
}
/* ─── Sources ─────────────────────────────────────────────────────────────── */
const SRC = [["manual_text", "Manual Text"], ["url", "URL / Page"], ["competitor", "Competitor"], ["keyword", "Keyword"], ["trend_topic", "Trend Topic"]];
const SRCI = { manual_text: ic.type, url: ic.globe, competitor: ic.target, keyword: ic.hash, trend_topic: ic.trend };
const SRCC = { manual_text: "#64748b", url: "#3b82f6", competitor: "#ef4444", keyword: "#8b5cf6", trend_topic: "#f59e0b" };
function SourcesPanel({ ws, store, save }) {
    const toast = useToast();
    const [open, setOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [search, setSearch] = useState("");
    const [form, setForm] = useState({ type: "manual_text", label: "", content: "", notes: "" });
    const sources = store.sources.filter(s => s.workspace_id === ws.id && s.status !== "archived").filter(s => !search || s.label.toLowerCase().includes(search.toLowerCase()));
    const add = () => { if (!form.label.trim())
        return; setSaving(true); save(s => ({ ...s, sources: [{ id: uid(), workspace_id: ws.id, status: "active", created_at: now(), ...form }, ...s.sources] })); toast("Source added"); setOpen(false); setForm({ type: "manual_text", label: "", content: "", notes: "" }); setSaving(false); };
    const del = id => { save(s => ({ ...s, sources: s.sources.map(x => x.id === id ? { ...x, status: "archived" } : x) })); toast("Removed"); };
    return (React.createElement("div", null,
        React.createElement(PanelHd, { title: `Sources · ${sources.length}`, action: React.createElement(React.Fragment, null,
                React.createElement(SearchBar, { value: search, onChange: setSearch }),
                React.createElement(Btn, { sz: "sm", icon: "plus", onClick: () => setOpen(true) }, "Add")) }),
        sources.length === 0 ? React.createElement(Empty, { icon: "source", title: "No sources yet", sub: "Add URLs, competitors, keywords or manual text.", cta: React.createElement(Btn, { icon: "plus", onClick: () => setOpen(true) }, "Add Source") })
            : React.createElement("div", { className: "srcg" }, sources.map(s => {
                var _a;
                return (React.createElement("div", { key: s.id, className: "srccard" },
                    React.createElement("div", { className: "srcico", style: { background: `${SRCC[s.type]}18`, color: SRCC[s.type] } },
                        React.createElement(Ic, { d: SRCI[s.type] || ic.globe, s: 14 })),
                    React.createElement("div", { className: "srcinf" },
                        React.createElement("p", { className: "srcn" }, s.label),
                        React.createElement("p", { className: "srct" }, (_a = SRC.find(t => t[0] === s.type)) === null || _a === void 0 ? void 0 : _a[1]),
                        s.content && React.createElement("p", { className: "srcc" },
                            s.content.slice(0, 65),
                            s.content.length > 65 ? "…" : "")),
                    React.createElement("button", { className: "ibtn muted", onClick: () => del(s.id) },
                        React.createElement(Ic, { d: ic.trash, s: 13 }))));
            })),
        React.createElement(Modal, { open: open, onClose: () => setOpen(false), title: "Add Source", footer: React.createElement(React.Fragment, null,
                React.createElement(Btn, { v: "ghost", onClick: () => setOpen(false) }, "Cancel"),
                React.createElement(Btn, { spin: saving, onClick: add, disabled: !form.label.trim() }, "Add Source")) },
            React.createElement(Sel, { label: "Type", value: form.type, onChange: e => setForm({ ...form, type: e.target.value }), options: SRC }),
            React.createElement(Inp, { label: "Label *", value: form.label, onChange: e => setForm({ ...form, label: e.target.value }), placeholder: "Short name" }),
            React.createElement(Txa, { label: form.type === "url" ? "URL" : "Content / Value", value: form.content, onChange: e => setForm({ ...form, content: e.target.value }), placeholder: form.type === "url" ? "https://…" : "Enter text, keyword, topic…" }),
            React.createElement(Inp, { label: "Notes", value: form.notes, onChange: e => setForm({ ...form, notes: e.target.value }), placeholder: "Optional context" }))));
}
/* ─── Runs ────────────────────────────────────────────────────────────────── */
const COSTS = { research: 5, insight: 3, brief: 2, draft: 2 };
const STEPS = ["Initializing", "Fetching context", "Running AI analysis", "Extracting signals", "Generating insights", "Saving results"];
function RunsPanel({ ws, store, save, upd }) {
    const toast = useToast();
    const [open, setOpen] = useState(false);
    const [running, setRunning] = useState(false);
    const [step, setStep] = useState(0);
    const [expanded, setExpanded] = useState(null);
    const [form, setForm] = useState({ label: "", type: "research", source_id: "", input_summary: "" });
    const runs = store.runs.filter(r => r.workspace_id === ws.id);
    const sources = store.sources.filter(s => s.workspace_id === ws.id && s.status !== "archived");
    const start = async () => {
        var _a, _b, _c, _d;
        const cost = COSTS[form.type] || 3;
        if ((ws.credit_balance || 0) < cost) {
            toast("Not enough credits", "error");
            return;
        }
        setRunning(true);
        setStep(0);
        const rid = uid();
        save(s => ({ ...s, runs: [{ id: rid, workspace_id: ws.id, label: form.label || `${form.type} run`, type: form.type, source_id: form.source_id || null, input_summary: form.input_summary, status: "running", credits_used: cost, created_at: now(), log: "Run started…" }, ...s.runs] }));
        upd({ credit_balance: Math.max(0, (ws.credit_balance || 0) - cost) });
        save(s => ({ ...s, usage: [{ id: uid(), workspace_id: ws.id, action: `${form.type}_run`, credits_used: cost, created_at: now() }, ...s.usage] }));
        const src = sources.find(s => s.id === form.source_id);
        const ctx = src ? `Source: ${src.label} (${src.type})\n${src.content || ""}` : form.input_summary;
        for (let i = 1; i < STEPS.length; i++) {
            await new Promise(r => setTimeout(r, 450));
            setStep(i);
        }
        try {
            const result = await callMarketSignalAI(`You are a market intelligence analyst. Generate detailed market signals and strategic insights.

Input: ${ctx || "General research for: " + ws.name}
Brand: ${ws.brand_context || ws.industry || "general"}
Audience: ${ws.target_audience || "general"}

Return ONLY valid JSON:
{"signals":[{"title":"string","summary":"string","type":"trend|competitor_move|audience_question|keyword_gap|content_gap|market_shift|opportunity|risk|other","confidence":"high|medium|low"}],"insights":[{"title":"string","body":"string","type":"pattern|gap|opportunity|risk|next_action"}]}

Generate 4-6 specific actionable signals and 2-4 strategic insights.`);
            let log = "Run completed.\n";
            if ((_a = result.signals) === null || _a === void 0 ? void 0 : _a.length) {
                save(s => ({ ...s, signals: [...result.signals.map(sig => ({ id: uid(), workspace_id: ws.id, run_id: rid, status: "new", created_at: now(), ...sig })), ...s.signals] }));
                log += `✓ ${result.signals.length} signals generated\n`;
            }
            if ((_b = result.insights) === null || _b === void 0 ? void 0 : _b.length) {
                save(s => ({ ...s, insights: [...result.insights.map(ins => ({ id: uid(), workspace_id: ws.id, run_id: rid, status: "draft", created_at: now(), ...ins })), ...s.insights] }));
                log += `✓ ${result.insights.length} insights generated\n`;
            }
            save(s => ({ ...s, runs: s.runs.map(r => r.id === rid ? { ...r, status: "completed", log } : r) }));
            toast(`Run complete · ${((_c = result.signals) === null || _c === void 0 ? void 0 : _c.length) || 0} signals · ${((_d = result.insights) === null || _d === void 0 ? void 0 : _d.length) || 0} insights`);
        }
        catch (e) {
            save(s => ({ ...s, runs: s.runs.map(r => r.id === rid ? { ...r, status: "failed", log: "Failed: " + e.message } : r) }));
            toast("Run failed: " + (e && e.message ? e.message : "Unknown error"), "error");
        }
        setOpen(false);
        setForm({ label: "", type: "research", source_id: "", input_summary: "" });
        setRunning(false);
        setStep(0);
    };
    return (React.createElement("div", null,
        React.createElement(PanelHd, { title: `Runs · ${runs.length}`, action: React.createElement(Btn, { sz: "sm", icon: "plus", onClick: () => setOpen(true) }, "New Run") }),
        runs.length === 0 ? React.createElement(Empty, { icon: "runs", title: "No runs yet", sub: "Start a research run to capture market intelligence.", cta: React.createElement(Btn, { icon: "zap", onClick: () => setOpen(true) }, "Start Run") })
            : React.createElement("div", { className: "lst" }, runs.map(r => (React.createElement("div", { key: r.id, className: "item" },
                React.createElement("div", { className: "irow", onClick: () => setExpanded(expanded === r.id ? null : r.id) },
                    React.createElement("div", { className: "riw" }, r.status === "running" ? React.createElement("svg", { width: "14", height: "14", className: "spin-ico", viewBox: "0 0 24 24", fill: "none", stroke: "#4493f8", strokeWidth: "2" },
                        React.createElement("path", { d: "M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4" })) : r.status === "completed" ? React.createElement(Ic, { d: ic.check, s: 14, style: { color: "#3fb950" } }) : r.status === "failed" ? React.createElement(Ic, { d: ic.x, s: 14, style: { color: "#f85149" } }) : React.createElement(Ic, { d: ic.zap, s: 14 })),
                    React.createElement("div", { className: "ibody" },
                        React.createElement("span", { className: "it" }, r.label || r.type),
                        React.createElement("span", { className: "im" },
                            fmtFull(r.created_at),
                            " \u00B7 ",
                            r.credits_used,
                            " cr \u00B7 ",
                            r.type)),
                    React.createElement(Badge, { text: r.status, color: SC[r.status] }),
                    React.createElement("button", { className: "ibtn muted" },
                        React.createElement(Ic, { d: expanded === r.id ? ic.chevU : ic.chevD, s: 13 }))),
                expanded === r.id && r.log && React.createElement("div", { className: "runlog" },
                    React.createElement("pre", null, r.log)))))),
        React.createElement(Modal, { open: open, onClose: () => setOpen(false), title: "New Intelligence Run", width: 480, footer: running ? null : React.createElement(React.Fragment, null,
                React.createElement(Btn, { v: "ghost", onClick: () => setOpen(false) }, "Cancel"),
                React.createElement(Btn, { spin: running, icon: "zap", onClick: start }, "Start Run")) }, running ? (React.createElement("div", { className: "rp" },
            React.createElement("div", { className: "rp-lbl" }, "Analysing\u2026"),
            STEPS.map((s, i) => (React.createElement("div", { key: i, className: cn("rps", i < step && "rp-done", i === step && "rp-cur") },
                React.createElement("div", { className: "rpd" }, i < step ? React.createElement(Ic, { d: ic.check, s: 10 }) : i === step ? React.createElement("svg", { width: "10", height: "10", className: "spin-ico", viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: "2" },
                    React.createElement("path", { d: "M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4" })) : null),
                React.createElement("span", null, s)))))) : (React.createElement(React.Fragment, null,
            React.createElement(Sel, { label: "Type", value: form.type, onChange: e => setForm({ ...form, type: e.target.value }), options: [["research", "Research (5 cr)"], ["insight", "Insight Generation (3 cr)"], ["brief", "Brief Creation (2 cr)"], ["draft", "Draft Generation (2 cr)"]] }),
            React.createElement(Inp, { label: "Label", value: form.label, onChange: e => setForm({ ...form, label: e.target.value }), placeholder: "e.g. Competitor research Q2" }),
            sources.length > 0 && React.createElement(Sel, { label: "Source (optional)", value: form.source_id, onChange: e => setForm({ ...form, source_id: e.target.value }), options: [["", "Select a source"], ...sources.map(s => [s.id, `${s.label} (${s.type})`])] }),
            React.createElement(Txa, { label: "Context / Input", value: form.input_summary, onChange: e => setForm({ ...form, input_summary: e.target.value }), placeholder: "Describe what to research or analyse\u2026", rows: 4 }),
            React.createElement("div", { className: "costnote" },
                "Cost: ",
                React.createElement("strong", null,
                    COSTS[form.type] || 3,
                    " credits"),
                " \u00B7 Balance: ",
                React.createElement("strong", null,
                    ws.credit_balance || 0,
                    " credits")))))));
}
/* ─── Signals ─────────────────────────────────────────────────────────────── */
function SignalsPanel({ ws, store, save }) {
    const toast = useToast();
    const [filter, setFilter] = useState("all");
    const [search, setSearch] = useState("");
    const all = store.signals.filter(s => s.workspace_id === ws.id);
    const filtered = all.filter(s => filter === "all" || s.status === filter || s.type === filter).filter(s => { var _a, _b; return !search || ((_a = s.title) === null || _a === void 0 ? void 0 : _a.toLowerCase().includes(search.toLowerCase())) || ((_b = s.summary) === null || _b === void 0 ? void 0 : _b.toLowerCase().includes(search.toLowerCase())); });
    const review = id => { save(s => ({ ...s, signals: s.signals.map(x => x.id === id ? { ...x, status: "reviewed" } : x) })); toast("Marked reviewed"); };
    return (React.createElement("div", null,
        React.createElement(PanelHd, { title: `Signals · ${all.length}`, action: React.createElement(React.Fragment, null,
                React.createElement(SearchBar, { value: search, onChange: setSearch }),
                React.createElement("select", { className: "inp fsel", value: filter, onChange: e => setFilter(e.target.value) }, [["all", "All"], ["new", "New"], ["reviewed", "Reviewed"], ["opportunity", "Opportunity"], ["risk", "Risk"], ["trend", "Trend"]].map(([v, l]) => React.createElement("option", { key: v, value: v }, l)))) }),
        filtered.length === 0 ? React.createElement(Empty, { icon: "signals", title: "No signals", sub: "Run research to capture market signals." })
            : React.createElement("div", { className: "lst" }, filtered.map((s, i) => {
                var _a;
                return (React.createElement("div", { key: s.id, className: "item sigitem", style: { animationDelay: `${i * .04}s` } },
                    React.createElement("div", { className: "sigstripe", style: { background: TCC[s.type] || "#656d76" } }),
                    React.createElement("div", { className: "sigbody" },
                        React.createElement("div", { className: "sigtags" },
                            React.createElement(Badge, { text: (_a = s.type) === null || _a === void 0 ? void 0 : _a.replace(/_/g, " "), color: TC[s.type] || "slate" }),
                            s.confidence && React.createElement("span", { className: `conf conf-${s.confidence}` }, s.confidence),
                            s.status === "new" && React.createElement(Badge, { text: "NEW", color: "accent" })),
                        React.createElement("p", { className: "it" }, s.title),
                        s.summary && React.createElement("p", { className: "ib" }, s.summary),
                        React.createElement("p", { className: "im" }, fmtDate(s.created_at))),
                    s.status === "new" && React.createElement(Btn, { v: "ghost", sz: "sm", icon: "eye", onClick: () => review(s.id) }, "Review")));
            }))));
}
/* ─── Insights ────────────────────────────────────────────────────────────── */
function InsightsPanel({ ws, store, save }) {
    const toast = useToast();
    const [filter, setFilter] = useState("all");
    const [search, setSearch] = useState("");
    const all = store.insights.filter(i => i.workspace_id === ws.id);
    const filtered = all.filter(i => filter === "all" || i.status === filter || i.type === filter).filter(i => { var _a; return !search || ((_a = i.title) === null || _a === void 0 ? void 0 : _a.toLowerCase().includes(search.toLowerCase())); });
    const upd = (id, status) => { save(s => ({ ...s, insights: s.insights.map(x => x.id === id ? { ...x, status } : x) })); toast(`Insight ${status}`); };
    return (React.createElement("div", null,
        React.createElement(PanelHd, { title: `Insights · ${all.length}`, action: React.createElement(React.Fragment, null,
                React.createElement(SearchBar, { value: search, onChange: setSearch }),
                React.createElement("select", { className: "inp fsel", value: filter, onChange: e => setFilter(e.target.value) }, [["all", "All"], ["draft", "Draft"], ["approved", "Approved"], ["rejected", "Rejected"]].map(([v, l]) => React.createElement("option", { key: v, value: v }, l)))) }),
        filtered.length === 0 ? React.createElement(Empty, { icon: "insights", title: "No insights yet", sub: "Run research to generate strategic insights." })
            : React.createElement("div", { className: "lst" }, filtered.map(i => (React.createElement("div", { key: i.id, className: "item" },
                React.createElement("div", { className: "irow" },
                    React.createElement("div", { className: "ibody" },
                        React.createElement("div", { className: "sigtags" },
                            React.createElement(Badge, { text: i.status, color: SC[i.status] }),
                            i.type && React.createElement(Badge, { text: i.type.replace("_", " "), color: TC[i.type] || "slate" })),
                        React.createElement("p", { className: "it" }, i.title),
                        i.body && React.createElement("p", { className: "ib" }, i.body),
                        React.createElement("p", { className: "im" }, fmtDate(i.created_at))),
                    React.createElement("div", { className: "acol" },
                        i.status !== "approved" && React.createElement("button", { className: "ibtn green", onClick: () => upd(i.id, "approved") },
                            React.createElement(Ic, { d: ic.check, s: 14 })),
                        i.status !== "rejected" && React.createElement("button", { className: "ibtn red", onClick: () => upd(i.id, "rejected") },
                            React.createElement(Ic, { d: ic.x, s: 14 }))))))))));
}
/* ─── Briefs ──────────────────────────────────────────────────────────────── */
const FMT = [["social_post", "Social Post"], ["article", "Article"], ["email", "Email"], ["ad_copy", "Ad Copy"], ["landing_page", "Landing Page"], ["video_script", "Video Script"], ["general", "General"]];
function BriefsPanel({ ws, store, save, upd }) {
    const toast = useToast();
    const [open, setOpen] = useState(false);
    const [gen, setGen] = useState(false);
    const [expanded, setExpanded] = useState(null);
    const [search, setSearch] = useState("");
    const [form, setForm] = useState({ title: "", objective: "", target_audience: "", key_messages: "", tone_and_style: "", format: "general", context_notes: "", insight_id: "", prompt: "" });
    const briefs = store.briefs.filter(b => b.workspace_id === ws.id).filter(b => { var _a; return !search || ((_a = b.title) === null || _a === void 0 ? void 0 : _a.toLowerCase().includes(search.toLowerCase())); });
    const insights = store.insights.filter(i => i.workspace_id === ws.id && i.status === "approved");
    const generate = async () => {
        if ((ws.credit_balance || 0) < 2) {
            toast("Not enough credits", "error");
            return;
        }
        setGen(true);
        const ins = insights.find(i => i.id === form.insight_id);
        const ctx = ins ? `Insight: ${ins.title}\n${ins.body}` : form.prompt;
        const result = await callMarketSignalAI(`You are a senior content strategist. Create a detailed marketing brief.
Input: ${ctx}
Brand: ${ws.brand_context || "general"}
Audience: ${ws.target_audience || "general"}
Return ONLY valid JSON: {"title":"string","objective":"string","target_audience":"string","key_messages":"string","tone_and_style":"string","context_notes":"string"}`);
        if (result.error) {
            toast("Generation failed: " + (e && e.message ? e.message : "Unknown error"), "error");
        }
        else {
            setForm(f => ({ ...f, ...result }));
            upd({ credit_balance: Math.max(0, (ws.credit_balance || 0) - 2) });
            save(s => ({ ...s, usage: [{ id: uid(), workspace_id: ws.id, action: "brief_generation", credits_used: 2, created_at: now() }, ...s.usage] }));
            toast("Brief generated (2 credits)");
        }
        setGen(false);
    };
    const saveB = () => { if (!form.title.trim())
        return; save(s => ({ ...s, briefs: [{ id: uid(), workspace_id: ws.id, ...form, status: "draft", created_at: now() }, ...s.briefs] })); toast("Brief saved"); setOpen(false); setForm({ title: "", objective: "", target_audience: "", key_messages: "", tone_and_style: "", format: "general", context_notes: "", insight_id: "", prompt: "" }); };
    const upStatus = (id, status) => { save(s => ({ ...s, briefs: s.briefs.map(b => b.id === id ? { ...b, status } : b) })); toast(`Brief ${status}`); };
    return (React.createElement("div", null,
        React.createElement(PanelHd, { title: `Briefs · ${briefs.length}`, action: React.createElement(React.Fragment, null,
                React.createElement(SearchBar, { value: search, onChange: setSearch }),
                React.createElement(Btn, { sz: "sm", icon: "plus", onClick: () => setOpen(true) }, "New Brief")) }),
        briefs.length === 0 ? React.createElement(Empty, { icon: "briefs", title: "No briefs yet", sub: "Create strategic briefs from insights or prompts.", cta: React.createElement(Btn, { icon: "plus", onClick: () => setOpen(true) }, "New Brief") })
            : React.createElement("div", { className: "lst" }, briefs.map(b => {
                var _a;
                return (React.createElement("div", { key: b.id, className: "item" },
                    React.createElement("div", { className: "irow clickr", onClick: () => setExpanded(expanded === b.id ? null : b.id) },
                        React.createElement("div", { className: "ibody" },
                            React.createElement("div", { className: "sigtags" },
                                React.createElement(Badge, { text: b.status, color: SC[b.status] }),
                                React.createElement(Badge, { text: ((_a = FMT.find(f => f[0] === b.format)) === null || _a === void 0 ? void 0 : _a[1]) || b.format, color: "slate" })),
                            React.createElement("p", { className: "it" }, b.title),
                            b.objective && React.createElement("p", { className: cn("ib", expanded !== b.id && "cl2") }, b.objective),
                            expanded === b.id && React.createElement("div", { className: "expd" },
                                b.target_audience && React.createElement("div", null,
                                    React.createElement("strong", null, "Audience:"),
                                    " ",
                                    b.target_audience),
                                b.key_messages && React.createElement("div", null,
                                    React.createElement("strong", null, "Key Messages:"),
                                    " ",
                                    b.key_messages),
                                b.tone_and_style && React.createElement("div", null,
                                    React.createElement("strong", null, "Tone:"),
                                    " ",
                                    b.tone_and_style)),
                            React.createElement("p", { className: "im" }, fmtDate(b.created_at))),
                        React.createElement("div", { className: "acol", onClick: e => e.stopPropagation() },
                            b.status !== "approved" && React.createElement("button", { className: "ibtn green", onClick: () => upStatus(b.id, "approved") },
                                React.createElement(Ic, { d: ic.check, s: 14 })),
                            b.status !== "rejected" && React.createElement("button", { className: "ibtn red", onClick: () => upStatus(b.id, "rejected") },
                                React.createElement(Ic, { d: ic.x, s: 14 }))))));
            })),
        React.createElement(Modal, { open: open, onClose: () => setOpen(false), title: "New Brief", footer: React.createElement(React.Fragment, null,
                React.createElement(Btn, { v: "ghost", onClick: () => setOpen(false) }, "Cancel"),
                React.createElement(Btn, { onClick: saveB, disabled: !form.title.trim() }, "Save Brief")) },
            insights.length > 0 && React.createElement(Sel, { label: "Based on Insight (optional)", value: form.insight_id, onChange: e => setForm({ ...form, insight_id: e.target.value }), options: [["", "Select an approved insight"], ...insights.map(i => [i.id, i.title])] }),
            React.createElement(Txa, { label: "Prompt / Context", value: form.prompt, onChange: e => setForm({ ...form, prompt: e.target.value }), placeholder: "Describe what this brief is about\u2026" }),
            React.createElement(Btn, { v: "secondary", full: true, spin: gen, onClick: generate, disabled: !form.prompt && !form.insight_id },
                !gen && React.createElement(Ic, { d: ic.zap, s: 13 }),
                "Generate with AI (2 credits)"),
            React.createElement("hr", { className: "div" }),
            React.createElement(Inp, { label: "Title *", value: form.title, onChange: e => setForm({ ...form, title: e.target.value }) }),
            React.createElement(Sel, { label: "Format", value: form.format, onChange: e => setForm({ ...form, format: e.target.value }), options: FMT }),
            React.createElement(Txa, { label: "Objective", value: form.objective, onChange: e => setForm({ ...form, objective: e.target.value }) }),
            React.createElement(Txa, { label: "Key Messages", value: form.key_messages, onChange: e => setForm({ ...form, key_messages: e.target.value }) }),
            React.createElement(Inp, { label: "Tone & Style", value: form.tone_and_style, onChange: e => setForm({ ...form, tone_and_style: e.target.value }) }),
            React.createElement(Inp, { label: "Target Audience", value: form.target_audience, onChange: e => setForm({ ...form, target_audience: e.target.value }) }))));
}
/* ─── Drafts ──────────────────────────────────────────────────────────────── */
const DT = [["social_post", "Social Post"], ["hook", "Hook"], ["article_outline", "Article Outline"], ["caption", "Caption"], ["campaign_angle", "Campaign Angle"], ["ad_copy", "Ad Copy"], ["email", "Email"], ["other", "Other"]];
const PL = [["linkedin", "LinkedIn"], ["twitter", "Twitter/X"], ["instagram", "Instagram"], ["facebook", "Facebook"], ["general", "General"], ["email", "Email"], ["blog", "Blog"]];
function DraftsPanel({ ws, store, save, upd }) {
    const toast = useToast();
    const [open, setOpen] = useState(false);
    const [gen, setGen] = useState(false);
    const [expanded, setExpanded] = useState(null);
    const [search, setSearch] = useState("");
    const [form, setForm] = useState({ title: "", content: "", type: "social_post", platform: "linkedin", brief_id: "", prompt: "" });
    const drafts = store.drafts.filter(d => d.workspace_id === ws.id).filter(d => { var _a; return !search || ((_a = d.title) === null || _a === void 0 ? void 0 : _a.toLowerCase().includes(search.toLowerCase())); });
    const briefs = store.briefs.filter(b => b.workspace_id === ws.id && b.status === "approved");
    const generate = async () => {
        var _a, _b;
        if ((ws.credit_balance || 0) < 2) {
            toast("Not enough credits", "error");
            return;
        }
        setGen(true);
        const brief = briefs.find(b => b.id === form.brief_id);
        const ctx = brief ? `Brief: ${brief.title}\nObjective: ${brief.objective}\nKey messages: ${brief.key_messages}\nTone: ${brief.tone_and_style}` : form.prompt;
        const result = await callMarketSignalAI(`You are an expert copywriter. Write a ${(_a = DT.find(t => t[0] === form.type)) === null || _a === void 0 ? void 0 : _a[1]} for ${(_b = PL.find(p => p[0] === form.platform)) === null || _b === void 0 ? void 0 : _b[1]}.
Context: ${ctx}
Brand voice: ${ws.brand_context || "professional and engaging"}
Return ONLY valid JSON: {"title":"string","content":"string"}`);
        if (result.error) {
            toast("Generation failed: " + (e && e.message ? e.message : "Unknown error"), "error");
        }
        else {
            setForm(f => ({ ...f, title: result.title || f.title, content: result.content || "" }));
            upd({ credit_balance: Math.max(0, (ws.credit_balance || 0) - 2) });
            save(s => ({ ...s, usage: [{ id: uid(), workspace_id: ws.id, action: "draft_generation", credits_used: 2, created_at: now() }, ...s.usage] }));
            toast("Draft generated (2 credits)");
        }
        setGen(false);
    };
    const saveD = () => { if (!form.title.trim() || !form.content.trim())
        return; save(s => ({ ...s, drafts: [{ id: uid(), workspace_id: ws.id, ...form, status: "draft", created_at: now() }, ...s.drafts] })); toast("Draft saved"); setOpen(false); setForm({ title: "", content: "", type: "social_post", platform: "linkedin", brief_id: "", prompt: "" }); };
    const upStatus = (id, status) => { save(s => ({ ...s, drafts: s.drafts.map(d => d.id === id ? { ...d, status } : d) })); toast(`Draft ${status}`); };
    const copy = c => { navigator.clipboard.writeText(c); toast("Copied to clipboard"); };
    return (React.createElement("div", null,
        React.createElement(PanelHd, { title: `Drafts · ${drafts.length}`, action: React.createElement(React.Fragment, null,
                React.createElement(SearchBar, { value: search, onChange: setSearch }),
                React.createElement(Btn, { sz: "sm", icon: "plus", onClick: () => setOpen(true) }, "New Draft")) }),
        drafts.length === 0 ? React.createElement(Empty, { icon: "drafts", title: "No drafts yet", sub: "Generate content from briefs or prompts.", cta: React.createElement(Btn, { icon: "plus", onClick: () => setOpen(true) }, "New Draft") })
            : React.createElement("div", { className: "lst" }, drafts.map(d => {
                var _a, _b;
                return (React.createElement("div", { key: d.id, className: "item" },
                    React.createElement("div", { className: "irow clickr", onClick: () => setExpanded(expanded === d.id ? null : d.id) },
                        React.createElement("div", { className: "ibody" },
                            React.createElement("div", { className: "sigtags" },
                                React.createElement(Badge, { text: d.status, color: SC[d.status] }),
                                React.createElement("span", { className: "im" }, (_a = DT.find(t => t[0] === d.type)) === null || _a === void 0 ? void 0 :
                                    _a[1],
                                    " \u00B7 ", (_b = PL.find(p => p[0] === d.platform)) === null || _b === void 0 ? void 0 :
                                    _b[1])),
                            React.createElement("p", { className: "it" }, d.title),
                            d.content && React.createElement("p", { className: cn("ib", expanded !== d.id && "cl2") }, d.content),
                            React.createElement("p", { className: "im" }, fmtDate(d.created_at))),
                        React.createElement("div", { className: "acol", onClick: e => e.stopPropagation() },
                            React.createElement("button", { className: "ibtn muted", title: "Copy", onClick: () => copy(d.content) },
                                React.createElement(Ic, { d: ic.copy, s: 13 })),
                            d.status !== "approved" && React.createElement("button", { className: "ibtn green", onClick: () => upStatus(d.id, "approved") },
                                React.createElement(Ic, { d: ic.check, s: 14 })),
                            d.status !== "rejected" && React.createElement("button", { className: "ibtn red", onClick: () => upStatus(d.id, "rejected") },
                                React.createElement(Ic, { d: ic.x, s: 14 }))))));
            })),
        React.createElement(Modal, { open: open, onClose: () => setOpen(false), title: "Generate Draft", footer: React.createElement(React.Fragment, null,
                React.createElement(Btn, { v: "ghost", onClick: () => setOpen(false) }, "Cancel"),
                React.createElement(Btn, { onClick: saveD, disabled: !form.title.trim() || !form.content.trim() }, "Save Draft")) },
            React.createElement("div", { className: "twoc" },
                React.createElement(Sel, { label: "Type", value: form.type, onChange: e => setForm({ ...form, type: e.target.value }), options: DT }),
                React.createElement(Sel, { label: "Platform", value: form.platform, onChange: e => setForm({ ...form, platform: e.target.value }), options: PL })),
            briefs.length > 0 && React.createElement(Sel, { label: "From Brief (optional)", value: form.brief_id, onChange: e => setForm({ ...form, brief_id: e.target.value }), options: [["", "Select approved brief"], ...briefs.map(b => [b.id, b.title])] }),
            React.createElement(Txa, { label: "Prompt / Context", value: form.prompt, onChange: e => setForm({ ...form, prompt: e.target.value }), placeholder: "Describe the content\u2026" }),
            React.createElement(Btn, { v: "secondary", full: true, spin: gen, onClick: generate, disabled: !form.prompt && !form.brief_id },
                !gen && React.createElement(Ic, { d: ic.drafts, s: 13 }),
                "Generate with AI (2 credits)"),
            React.createElement("hr", { className: "div" }),
            React.createElement(Inp, { label: "Title *", value: form.title, onChange: e => setForm({ ...form, title: e.target.value }) }),
            React.createElement(Txa, { label: "Content *", value: form.content, onChange: e => setForm({ ...form, content: e.target.value }), rows: 6, placeholder: "Generated or manual content\u2026" }),
            form.content && React.createElement("p", { className: "chrcnt" },
                form.content.length,
                " characters"))));
}
/* ─── Credits ─────────────────────────────────────────────────────────────── */
function CreditsPanel({ ws, store, save }) {
    const toast = useToast();
    const events = store.usage.filter(e => e.workspace_id === ws.id).slice(0, 50);
    const totalUsed = events.reduce((s, e) => s + (e.credits_used || 0), 0);
    const addCr = n => { save(s => ({ ...s, workspaces: s.workspaces.map(w => w.id === ws.id ? { ...w, credit_balance: (w.credit_balance || 0) + n } : w) })); toast(`+${n} credits added`); };
    const AL = { research_run: "Research Run", brief_generation: "Brief Gen", draft_generation: "Draft Gen", insight_generation: "Insight Gen" };
    const bal = ws.credit_balance || 0;
    const pct = Math.min(100, Math.round(bal / (bal + totalUsed + 1) * 100));
    const R = 38;
    const C = 2 * Math.PI * R;
    return (React.createElement("div", null,
        React.createElement(PanelHd, { title: "Credits" }),
        React.createElement("div", { className: "crgrid" },
            React.createElement("div", { className: "crhero" },
                React.createElement("svg", { viewBox: "0 0 100 100", className: "crring" },
                    React.createElement("circle", { cx: "50", cy: "50", r: R, fill: "none", stroke: "var(--b2)", strokeWidth: "7" }),
                    React.createElement("circle", { cx: "50", cy: "50", r: R, fill: "none", stroke: "var(--ac)", strokeWidth: "7", strokeLinecap: "round", strokeDasharray: `${C * pct / 100} ${C}`, strokeDashoffset: `${C * .25}`, style: { transition: "stroke-dasharray .6s" } })),
                React.createElement("div", { className: "crlab" },
                    React.createElement("span", { className: "crnum" }, bal),
                    React.createElement("span", { className: "crsub" }, "remaining")),
                React.createElement("p", { className: "crused" },
                    totalUsed,
                    " used \u00B7 ",
                    events.length,
                    " actions")),
            React.createElement("div", { className: "crtopup" },
                React.createElement("p", { className: "ptitle", style: { marginBottom: 12 } }, "Add Credits"),
                React.createElement("div", { className: "crbtns" }, [25, 50, 100, 200].map(n => React.createElement("button", { key: n, className: "crbtn", onClick: () => addCr(n) },
                    "+",
                    n))),
                React.createElement("hr", { className: "div", style: { margin: "14px 0" } }),
                React.createElement("p", { className: "ptitle", style: { marginBottom: 10 } }, "Cost Reference"),
                React.createElement("div", { className: "costg" }, Object.entries({ "Research": 5, "Insight": 3, "Brief": 2, "Draft": 2 }).map(([k, v]) => React.createElement("div", { key: k, className: "costi" },
                    React.createElement("span", null, k),
                    React.createElement("strong", null, v)))))),
        React.createElement("div", { style: { marginTop: 16 } },
            React.createElement(PanelHd, { title: "Usage History" }),
            events.length === 0 ? React.createElement("p", { className: "mt" }, "No usage yet.") :
                React.createElement("div", { className: "lst" }, events.map(e => (React.createElement("div", { key: e.id, className: "item" },
                    React.createElement("div", { className: "irow" },
                        React.createElement(Ic, { d: ic.zap, s: 14, style: { color: "#f59e0b", flexShrink: 0 } }),
                        React.createElement("div", { className: "ibody" },
                            React.createElement("span", { className: "it" }, AL[e.action] || e.action),
                            React.createElement("span", { className: "im" }, fmtFull(e.created_at))),
                        React.createElement("span", { className: "crcost" },
                            "\u2212",
                            e.credits_used)))))))));
}
/* ─── Global list page ────────────────────────────────────────────────────── */
function GlobalList({ title, sub, icon, items, setPage, emptyMsg, filterOpts, renderRow }) {
    const [search, setSearch] = useState("");
    const [filter, setFilter] = useState("all");
    const filtered = items.filter(i => filter === "all" || i.status === filter || i.type === filter).filter(i => !search || JSON.stringify(i).toLowerCase().includes(search.toLowerCase()));
    return (React.createElement("div", { className: "page pg-anim" },
        React.createElement("div", { className: "phd2" },
            React.createElement("div", null,
                React.createElement("h1", { className: "ptitle2" }, title),
                React.createElement("p", { className: "psub" }, sub)),
            React.createElement("div", { style: { display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" } },
                React.createElement(SearchBar, { value: search, onChange: setSearch }),
                filterOpts && React.createElement("select", { className: "inp fsel", value: filter, onChange: e => setFilter(e.target.value) }, filterOpts.map(([v, l]) => React.createElement("option", { key: v, value: v }, l))))),
        filtered.length === 0 ? React.createElement(Empty, { icon: icon, title: emptyMsg, cta: React.createElement(Btn, { onClick: () => setPage("workspaces") }, "Go to Workspaces") })
            : React.createElement("div", { className: "lst" }, filtered.map(renderRow))));
}
/* ─── App ─────────────────────────────────────────────────────────────────── */
function App() {
    const [store, save] = useStore();
    const [page, setPage] = useState("dashboard");
    const [mob, setMob] = useState(false);
    const render = () => {
        const [base, id] = page.split("/");
        switch (base) {
            case "dashboard": return React.createElement(Dashboard, { store: store, setPage: setPage });
            case "workspaces": return React.createElement(Workspaces, { store: store, save: save, setPage: setPage });
            case "workspace": return React.createElement(WorkspaceDetail, { wsId: id, store: store, save: save, setPage: setPage });
            case "runs": return React.createElement(GlobalList, { title: "Runs", sub: "All intelligence runs", icon: "runs", items: store.runs, setPage: setPage, emptyMsg: "No runs yet", filterOpts: [["all", "All"], ["completed", "Completed"], ["failed", "Failed"]], renderRow: r => React.createElement("div", { key: r.id, className: "item" },
                    React.createElement("div", { className: "irow" },
                        React.createElement("div", { className: "riw" }, r.status === "completed" ? React.createElement(Ic, { d: ic.check, s: 14, style: { color: "#3fb950" } }) : r.status === "failed" ? React.createElement(Ic, { d: ic.x, s: 14, style: { color: "#f85149" } }) : React.createElement(Ic, { d: ic.zap, s: 14 })),
                        React.createElement("div", { className: "ibody" },
                            React.createElement("div", { className: "sigtags" },
                                React.createElement(Badge, { text: r.status, color: SC[r.status] }),
                                React.createElement("span", { className: "im" }, r.type)),
                            React.createElement("span", { className: "it" }, r.label || r.type),
                            React.createElement("span", { className: "im" },
                                fmtFull(r.created_at),
                                " \u00B7 ",
                                r.credits_used,
                                " cr")))) });
            case "signals": return React.createElement(GlobalList, { title: "Signals", sub: "Normalized market signals from all workspaces", icon: "signals", items: store.signals, setPage: setPage, emptyMsg: "No signals yet \u2014 run research in a workspace", filterOpts: [["all", "All"], ["new", "New"], ["reviewed", "Reviewed"], ["opportunity", "Opportunity"], ["risk", "Risk"], ["trend", "Trend"]], renderRow: s => { var _a; return React.createElement("div", { key: s.id, className: "item sigitem" },
                    React.createElement("div", { className: "sigstripe", style: { background: TCC[s.type] || "#656d76" } }),
                    React.createElement("div", { className: "sigbody" },
                        React.createElement("div", { className: "sigtags" },
                            React.createElement(Badge, { text: (_a = s.type) === null || _a === void 0 ? void 0 : _a.replace(/_/g, " "), color: TC[s.type] || "slate" }),
                            s.confidence && React.createElement("span", { className: `conf conf-${s.confidence}` }, s.confidence),
                            s.status === "new" && React.createElement(Badge, { text: "NEW", color: "accent" })),
                        React.createElement("p", { className: "it" }, s.title),
                        s.summary && React.createElement("p", { className: "ib" }, s.summary),
                        React.createElement("p", { className: "im" }, fmtDate(s.created_at)))); } });
            case "insights": return React.createElement(GlobalList, { title: "Insights", sub: "Strategic intelligence across all workspaces", icon: "insights", items: store.insights, setPage: setPage, emptyMsg: "No insights yet", filterOpts: [["all", "All"], ["draft", "Draft"], ["approved", "Approved"], ["rejected", "Rejected"]], renderRow: i => React.createElement("div", { key: i.id, className: "item" },
                    React.createElement("div", { className: "irow" },
                        React.createElement("div", { className: "ibody" },
                            React.createElement("div", { className: "sigtags" },
                                React.createElement(Badge, { text: i.status, color: SC[i.status] }),
                                i.type && React.createElement(Badge, { text: i.type.replace("_", " "), color: TC[i.type] || "slate" })),
                            React.createElement("p", { className: "it" }, i.title),
                            i.body && React.createElement("p", { className: "ib cl2" }, i.body),
                            React.createElement("p", { className: "im" }, fmtDate(i.created_at))),
                        React.createElement("div", { className: "acol" },
                            React.createElement("button", { className: "ibtn green", onClick: () => save(s => ({ ...s, insights: s.insights.map(x => x.id === i.id ? { ...x, status: "approved" } : x) })) },
                                React.createElement(Ic, { d: ic.check, s: 14 })),
                            React.createElement("button", { className: "ibtn red", onClick: () => save(s => ({ ...s, insights: s.insights.map(x => x.id === i.id ? { ...x, status: "rejected" } : x) })) },
                                React.createElement(Ic, { d: ic.x, s: 14 }))))) });
            case "briefs": return React.createElement(GlobalList, { title: "Briefs", sub: "Structured marketing briefs across all workspaces", icon: "briefs", items: store.briefs, setPage: setPage, emptyMsg: "No briefs yet", filterOpts: [["all", "All"], ["draft", "Draft"], ["approved", "Approved"], ["rejected", "Rejected"]], renderRow: b => { var _a; return React.createElement("div", { key: b.id, className: "item" },
                    React.createElement("div", { className: "irow" },
                        React.createElement("div", { className: "ibody" },
                            React.createElement("div", { className: "sigtags" },
                                React.createElement(Badge, { text: b.status, color: SC[b.status] }),
                                React.createElement(Badge, { text: ((_a = FMT.find(f => f[0] === b.format)) === null || _a === void 0 ? void 0 : _a[1]) || b.format, color: "slate" })),
                            React.createElement("p", { className: "it" }, b.title),
                            b.objective && React.createElement("p", { className: "ib cl2" }, b.objective),
                            React.createElement("p", { className: "im" }, fmtDate(b.created_at))),
                        React.createElement("div", { className: "acol" },
                            React.createElement("button", { className: "ibtn green", onClick: () => save(s => ({ ...s, briefs: s.briefs.map(x => x.id === b.id ? { ...x, status: "approved" } : x) })) },
                                React.createElement(Ic, { d: ic.check, s: 14 })),
                            React.createElement("button", { className: "ibtn red", onClick: () => save(s => ({ ...s, briefs: s.briefs.map(x => x.id === b.id ? { ...x, status: "rejected" } : x) })) },
                                React.createElement(Ic, { d: ic.x, s: 14 }))))); } });
            case "drafts": return React.createElement(GlobalList, { title: "Drafts", sub: "All generated content drafts across workspaces", icon: "drafts", items: store.drafts, setPage: setPage, emptyMsg: "No drafts yet", filterOpts: [["all", "All"], ["draft", "Draft"], ["approved", "Approved"], ["rejected", "Rejected"]], renderRow: d => { var _a, _b; return React.createElement("div", { key: d.id, className: "item" },
                    React.createElement("div", { className: "irow" },
                        React.createElement("div", { className: "ibody" },
                            React.createElement("div", { className: "sigtags" },
                                React.createElement(Badge, { text: d.status, color: SC[d.status] }),
                                React.createElement("span", { className: "im" }, (_a = DT.find(t => t[0] === d.type)) === null || _a === void 0 ? void 0 :
                                    _a[1],
                                    " \u00B7 ", (_b = PL.find(p => p[0] === d.platform)) === null || _b === void 0 ? void 0 :
                                    _b[1])),
                            React.createElement("p", { className: "it" }, d.title),
                            d.content && React.createElement("p", { className: "ib cl2" }, d.content),
                            React.createElement("p", { className: "im" }, fmtDate(d.created_at))),
                        React.createElement("button", { className: "ibtn muted", onClick: () => navigator.clipboard.writeText(d.content) },
                            React.createElement(Ic, { d: ic.copy, s: 13 })))); } });
            default: return React.createElement(Dashboard, { store: store, setPage: setPage });
        }
    };
    return (React.createElement(ToastProvider, null,
        React.createElement("style", null, CSS),
        React.createElement("div", { className: "app" },
            React.createElement(Sidebar, { page: page, setPage: setPage, mob: mob, setMob: setMob, store: store }),
            React.createElement("div", { className: "main" },
                React.createElement("div", { className: "mobbar" },
                    React.createElement("div", { className: "sb-logo", style: { width: 28, height: 28 } },
                        React.createElement("svg", { width: "15", height: "15", viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: "2" },
                            React.createElement("path", { d: "M22 12h-4l-3 9L9 3l-3 9H2" }))),
                    React.createElement("span", { className: "sb-name" }, "MarketSignal"),
                    React.createElement("button", { className: "ibtn", onClick: () => setMob(!mob) },
                        React.createElement(Ic, { d: ic.menu, s: 18 }))),
                render()))));
}
/* ─── Styles ──────────────────────────────────────────────────────────────── */
const CSS = `
@import url('https://fonts.googleapis.com/css2?family=Archivo:wght@900&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap');
.ves-market-signal-holder,.ves-market-signal-holder *,.ves-market-signal-holder *::before,.ves-market-signal-holder *::after{box-sizing:border-box;}
.ves-market-signal-holder *{min-width:0;}
.app{--bg:#F8F7F4;--s1:#ffffff;--s2:#F8F7F4;--s3:#EDF3FF;--b1:#e5e0d8;--b2:#c8c2b8;--t1:#0F0F0F;--t2:#4A4A4A;--t3:#737373;--ac:#3A61E1;--acg:rgba(58,97,225,.10);--gr:#16a34a;--re:#F15D31;--re-bg:rgba(241,93,49,.08);--ye:#d97706;--pu:#7c3aed;--am:#ea580c;--or:#f97316;--vi:#8b5cf6;--bl:#0ea5e9;--lime:#D2F050;--lime-bg:rgba(210,240,80,.12);--r:14px;}
.app{height:100%;min-height:560px;background:var(--bg);color:var(--t1);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:14px;-webkit-font-smoothing:antialiased;border:1px solid var(--b1);border-radius:18px;box-shadow:0 22px 60px rgba(15,23,42,.10);overflow:hidden;}
.app{display:flex;height:100%;overflow:hidden;}
.main{flex:1;overflow-y:auto;overflow-x:hidden;background:linear-gradient(180deg,#ffffff 0%,#F8F7F4 50%,#F3F1EC 100%);}
/* Sidebar */
.sidebar{width:210px;background:var(--s1);border-right:1px solid var(--b1);display:flex;flex-direction:column;flex-shrink:0;height:100%;overflow-y:auto;overflow-x:hidden;}
.mob-ov{display:none;}
@media(max-width:768px){.sidebar{position:fixed;top:0;left:0;height:100%;transform:translateX(-100%);transition:transform .22s cubic-bezier(.4,0,.2,1);z-index:50;}.sb-open{transform:translateX(0)!important;}.mob-ov{display:block;position:fixed;inset:0;z-index:40;background:rgba(15,23,42,.22);backdrop-filter:blur(4px);}}
.mobbar{display:none;align-items:center;gap:10px;padding:12px 14px;background:var(--s1);border-bottom:1px solid var(--b1);position:sticky;top:0;z-index:20;}
@media(max-width:768px){.mobbar{display:flex;}.mobbar .sb-name{flex:1;}}
.sb-brand{padding:14px 14px 12px;border-bottom:1px solid var(--b1);display:flex;align-items:center;gap:10px;}
.sb-logo{width:28px;height:28px;background:linear-gradient(135deg,var(--ac),var(--bl));border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;box-shadow:0 8px 18px rgba(37,99,235,.18);}
.sb-name{font-family:Inter,system-ui,sans-serif;font-size:14px;font-weight:700;color:var(--t1);letter-spacing:-.3px;}
.sb-tag{font-size:10px;color:var(--t3);margin-top:1px;}
.sb-sec{font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:9.5px;font-weight:600;color:var(--t3);letter-spacing:.1em;text-transform:uppercase;padding:14px 12px 5px;}
.sb-nav{flex:1;padding:4px 7px;display:flex;flex-direction:column;gap:1px;overflow-y:auto;}
.sbi{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:7px;font-size:13px;font-weight:500;color:var(--t2);background:none;border:none;cursor:pointer;width:100%;text-align:left;transition:all .14s;position:relative;}
.sbi:hover{background:var(--s2);color:var(--t1);}
.sbi-on{background:var(--s3)!important;color:var(--t1)!important;font-weight:600;}
.sbi-on::before{content:'';position:absolute;left:0;top:22%;bottom:22%;width:2.5px;background:var(--ac);border-radius:0 2px 2px 0;box-shadow:none;}
.sb-ct{margin-left:auto;background:var(--s3);color:var(--t3);font-size:10px;font-weight:600;padding:1px 6px;border-radius:999px;border:1px solid var(--b1);}
.sb-ct-hot{background:rgba(248,81,73,.15);color:var(--re);border-color:rgba(248,81,73,.3);}
.sb-foot{padding:12px 14px;border-top:1px solid var(--b1);}
.sb-ver{font-size:10px;color:var(--t3);}
/* Page */
.page{max-width:1040px;margin:0 auto;padding:22px 22px 36px;}
@media(max-width:700px){.page{padding:18px 13px 64px;}}
.phd2{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:22px;flex-wrap:wrap;}
.ptitle2{font-family:'Archivo','Arial Black',system-ui,sans-serif;font-size:21px;font-weight:900;color:var(--t1);letter-spacing:-.045em;}
.psub{font-size:13px;color:var(--t3);margin-top:3px;}
/* Animations */
@keyframes fadeUp{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}
@keyframes spin{to{transform:rotate(360deg);}}
@keyframes pulse{0%,100%{opacity:.5}50%{opacity:1}}
.pg-anim{animation:fadeUp .28s ease;}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;border-radius:7px;font-size:13px;font-weight:500;cursor:pointer;border:none;transition:all .14s;white-space:nowrap;font-family:Inter,system-ui,sans-serif;}
.btn:disabled{opacity:.42;cursor:not-allowed;}
.btn-full{width:100%;justify-content:center;}
.btn-md{padding:8px 15px;}
.btn-sm{padding:5px 11px;font-size:12px;}
.btn-primary{background:var(--ac);color:#fff;box-shadow:0 8px 18px rgba(37,99,235,.18);}
.btn-primary:hover:not(:disabled){background:#5aa6ff;}
.btn-secondary{background:var(--s3);color:var(--t1);border:1px solid var(--b1);}
.btn-secondary:hover:not(:disabled){background:var(--b1);}
.btn-outline{background:transparent;color:var(--t2);border:1px solid var(--b1);}
.btn-outline:hover:not(:disabled){border-color:var(--b2);color:var(--t1);background:var(--s2);}
.btn-ghost{background:transparent;color:var(--t2);}
.btn-ghost:hover:not(:disabled){background:var(--s2);color:var(--t1);}
.btn-accent{background:linear-gradient(135deg,var(--ac),var(--bl));color:#fff;font-weight:600;box-shadow:0 10px 22px rgba(37,99,235,.18);}
.btn-accent:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 14px 28px rgba(37,99,235,.22);}
.spin-ico{animation:spin .7s linear infinite;flex-shrink:0;}
.ibtn{background:none;border:none;cursor:pointer;padding:5px;border-radius:6px;display:flex;align-items:center;justify-content:center;transition:all .14s;color:var(--t2);}
.ibtn:hover{background:var(--s3);color:var(--t1);}
.ibtn.muted{color:var(--t3);}.ibtn.muted:hover{color:var(--t2);background:var(--s2);}
.ibtn.green{color:var(--gr);}.ibtn.green:hover{background:rgba(63,185,80,.1);}
.ibtn.red{color:var(--re);}.ibtn.red:hover{background:rgba(248,81,73,.1);}
.tlink{background:none;border:none;cursor:pointer;display:flex;align-items:center;gap:4px;font-size:11px;color:var(--t3);font-family:inherit;transition:color .14s;}
.tlink:hover{color:var(--ac);}
.backbtn{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--t3);background:none;border:none;cursor:pointer;margin-bottom:12px;font-family:inherit;}
.backbtn:hover{color:var(--t2);}
/* Badge */
.badge{display:inline-flex;align-items:center;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:600;letter-spacing:.2px;}
.badge-slate{background:var(--s3);color:var(--t2);}
.badge-blue{background:rgba(121,192,255,.12);color:var(--bl);}
.badge-green{background:rgba(63,185,80,.12);color:var(--gr);}
.badge-red{background:rgba(248,81,73,.12);color:var(--re);}
.badge-yellow{background:rgba(210,153,34,.12);color:var(--ye);}
.badge-purple{background:rgba(188,140,255,.12);color:var(--pu);}
.badge-violet{background:rgba(163,113,247,.12);color:var(--vi);}
.badge-amber{background:rgba(255,166,87,.12);color:var(--am);}
.badge-orange{background:rgba(255,123,114,.12);color:var(--or);}
.badge-accent{background:rgba(68,147,248,.2);color:var(--ac);}
/* Stats */
.sg{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:20px;}
@media(max-width:700px){.sg{grid-template-columns:repeat(2,minmax(0,1fr));}}
.sc{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);padding:16px;animation:fadeUp .4s ease both;transition:box-shadow .14s,transform .14s;}
.si{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;}
.sv{font-family:Inter,system-ui,sans-serif;font-size:26px;font-weight:700;color:var(--t1);line-height:1;}
.sl{font-size:11px;color:var(--t3);margin-top:3px;}
/* Dashboard */
.dcols{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,330px);gap:14px;}
@media(max-width:900px){.dcols{grid-template-columns:1fr;}}
.dmain{display:flex;flex-direction:column;gap:11px;}
.dside{display:flex;flex-direction:column;gap:11px;}
.panel{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);overflow:hidden;}
.phd3{display:flex;align-items:center;justify-content:space-between;padding:12px 14px 8px;border-bottom:1px solid var(--b1);}
.ptitle3{font-size:11px;font-weight:600;color:var(--t2);letter-spacing:.5px;text-transform:uppercase;}
.pbody{padding:4px 0;}
.arow{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 14px;border-bottom:1px solid var(--b1);}
.arow:last-child{border-bottom:none;}
.at{font-size:12px;font-weight:500;color:var(--t1);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.am{font-size:11px;color:var(--t3);margin-top:1px;}
.mt{font-size:13px;color:var(--t3);padding:10px 14px;}
.act-row{display:flex;align-items:flex-start;gap:9px;padding:7px 14px;animation:fadeUp .3s ease both;}
.act-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;margin-top:5px;}
.cta-card{background:linear-gradient(135deg,#ffffff 0%,#f8fbff 60%,#eef6ff 100%);border:1px solid var(--b1);border-radius:var(--r);padding:20px;position:relative;overflow:hidden;}
.cta-glow{position:absolute;top:-30px;right:-30px;width:100px;height:100px;background:radial-gradient(circle,var(--acg) 0%,transparent 70%);pointer-events:none;}
.cta-t{font-family:Inter,system-ui,sans-serif;font-size:14px;font-weight:700;color:var(--t1);}
.cta-s{font-size:12px;color:var(--t3);margin-top:5px;line-height:1.5;}
.flow-row{display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-top:12px;}
.fpill{background:rgba(255,255,255,.07);color:var(--t2);font-size:10px;font-weight:600;padding:2px 8px;border-radius:999px;border:1px solid var(--b1);}
.farrow{color:var(--t3);font-size:11px;}
/* Workspaces */
.wsgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin-top:14px;}
@media(max-width:900px){.wsgrid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:560px){.wsgrid{grid-template-columns:1fr;}}
.wscard{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);overflow:hidden;cursor:pointer;transition:all .18s;animation:fadeUp .4s ease both;}
.wscard:hover{border-color:var(--b2);transform:translateY(-2px);box-shadow:0 14px 30px rgba(15,23,42,.08);}
.wstop{height:65px;display:flex;align-items:flex-start;justify-content:space-between;padding:11px;}
.wsbody{padding:13px;}
.wsname{font-family:Inter,system-ui,sans-serif;font-size:14px;font-weight:700;color:var(--t1);margin-bottom:5px;}
.wsdesc{font-size:12px;color:var(--t3);margin-bottom:9px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.wschips{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:9px;}
.chip{background:var(--s3);color:var(--t3);font-size:10px;padding:2px 7px;border-radius:999px;border:1px solid var(--b1);}
.chip-cr{color:var(--ye);background:rgba(210,153,34,.1);border-color:rgba(210,153,34,.2);}
.chip-dim{color:rgba(255,255,255,.45);background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);}
.wsstats{display:flex;align-items:center;gap:10px;font-size:11px;color:var(--t3);margin-bottom:10px;}
.hotsig{color:var(--re);font-weight:600;}
.wsopen{font-size:11px;color:var(--ac);display:flex;align-items:center;gap:4px;font-weight:600;}
/* WS Detail */
.wsdethd{background:linear-gradient(135deg,var(--s2),var(--s1));border:1px solid var(--b1);border-radius:var(--r);padding:18px 22px;display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:12px;flex-wrap:wrap;}
.wsdetname{font-family:Inter,system-ui,sans-serif;font-size:19px;font-weight:700;color:var(--t1);}
.wsdetinfo{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.wscr{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:var(--ye);background:rgba(210,153,34,.1);padding:5px 11px;border-radius:999px;border:1px solid rgba(210,153,34,.2);}
/* Tabs */
.tabrow{display:flex;gap:0;overflow-x:auto;border-bottom:1px solid var(--b1);margin-bottom:18px;scrollbar-width:none;}
.tabrow::-webkit-scrollbar{display:none;}
.tab{padding:9px 14px;font-size:12px;font-weight:500;color:var(--t3);background:none;border:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap;transition:all .14s;display:flex;align-items:center;gap:5px;font-family:Inter,system-ui,sans-serif;}
.tab:hover{color:var(--t2);}
.tbon{color:var(--t1);border-bottom-color:var(--ac);font-weight:600;}
.tbct{background:var(--s3);color:var(--t3);font-size:9px;padding:1px 5px;border-radius:999px;border:1px solid var(--b1);}
/* List & Items */
.lst{display:flex;flex-direction:column;gap:7px;}
.item{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);overflow:hidden;transition:border-color .14s;animation:fadeUp .3s ease both;}
.item:hover{border-color:var(--b2);}
.irow{display:flex;align-items:center;gap:10px;padding:12px 13px;}
.clickr{cursor:pointer;}
.ibody{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px;}
.it{font-size:13px;font-weight:600;color:var(--t1);}
.ib{font-size:12px;color:var(--t3);line-height:1.5;}
.im{font-size:11px;color:var(--t3);}
.acol{display:flex;flex-direction:column;gap:3px;flex-shrink:0;}
.expd{background:var(--s2);border-radius:7px;padding:9px 11px;margin-top:7px;font-size:12px;color:var(--t3);display:flex;flex-direction:column;gap:4px;}
.expd strong{color:var(--t2);}
.cl2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.riw{width:26px;height:26px;background:var(--s2);border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--t2);}
.runlog{background:var(--bg);margin:0 13px 13px;border-radius:7px;padding:11px;}
.runlog pre{font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:11px;color:var(--t3);white-space:pre-wrap;line-height:1.6;}
/* Signals */
.sigitem{position:relative;padding-left:3px;}
.sigstripe{position:absolute;left:0;top:0;bottom:0;width:3px;border-radius:3px 0 0 3px;}
.sigbody{padding:11px 13px;display:flex;flex-direction:column;gap:4px;}
.sigbody .btn{align-self:flex-start;margin-top:4px;}
.sigtags{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.conf{font-size:10px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;}
.conf-high{color:var(--gr);}.conf-medium{color:var(--ye);}.conf-low{color:var(--re);}
/* Sources */
.srcg{display:grid;grid-template-columns:repeat(2,1fr);gap:9px;}
@media(max-width:700px){.srcg{grid-template-columns:1fr;}}
.srccard{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);padding:11px 13px;display:flex;align-items:flex-start;gap:10px;transition:border-color .14s;}
.srccard:hover{border-color:var(--b2);}
.srcico{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.srcinf{flex:1;min-width:0;}
.srcn{font-size:13px;font-weight:600;color:var(--t1);}
.srct{font-size:11px;color:var(--t3);margin-top:1px;}
.srcc{font-size:11px;color:var(--t3);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
/* Form */
.fld{display:flex;flex-direction:column;gap:5px;}
.flbl{font-size:10px;font-weight:600;color:var(--t2);letter-spacing:.4px;text-transform:uppercase;}
.inp{background:var(--s2);border:1px solid var(--b1);border-radius:7px;padding:8px 11px;font-size:13px;color:var(--t1);width:100%;font-family:Inter,system-ui,sans-serif;outline:none;transition:border-color .14s;}
.inp:focus{border-color:var(--ac);box-shadow:0 0 0 3px var(--acg);}
.inp::placeholder{color:var(--t3);}
textarea.inp{resize:vertical;}
.twoc{display:grid;grid-template-columns:1fr 1fr;gap:11px;}
.costnote{background:rgba(210,153,34,.07);border:1px solid rgba(210,153,34,.18);border-radius:7px;padding:9px 13px;font-size:12px;color:var(--t2);}
.costnote strong{color:var(--ye);}
.div{border:none;border-top:1px solid var(--b1);margin:2px 0;}
.chrcnt{font-size:11px;color:var(--t3);text-align:right;}
.fsel{width:auto;padding:5px 9px;font-size:12px;}
.phd{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;gap:10px;flex-wrap:wrap;}
.ptitle{font-size:13px;font-weight:700;color:var(--t1);}
.pact{display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
/* Search */
.sbar{position:relative;display:flex;align-items:center;}
.sbar-ico{position:absolute;left:9px;color:var(--t3);pointer-events:none;}
.sbar-inp{background:var(--s2);border:1px solid var(--b1);border-radius:7px;padding:6px 9px 6px 28px;font-size:12px;color:var(--t1);outline:none;width:170px;font-family:Inter,system-ui,sans-serif;transition:all .15s;}
.sbar-inp:focus{border-color:var(--ac);box-shadow:0 0 0 3px var(--acg);width:210px;}
.sbar-inp::placeholder{color:var(--t3);}
/* Modal */
.backdrop{position:fixed;inset:0;background:rgba(15,23,42,.28);backdrop-filter:blur(6px);z-index:100;display:flex;align-items:center;justify-content:center;padding:14px;animation:fadeUp .15s ease;}
.modal{background:var(--s1);border:1px solid var(--b1);border-radius:13px;width:100%;max-width:520px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(15,23,42,.16);}
.mhd{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 0;}
.mtitle{font-family:Inter,system-ui,sans-serif;font-size:15px;font-weight:700;color:var(--t1);}
.mbody{overflow-y:auto;padding:14px 18px;display:flex;flex-direction:column;gap:13px;}
.mfoot{padding:13px 18px;border-top:1px solid var(--b1);display:flex;justify-content:flex-end;gap:7px;}
/* Run progress */
.rp{display:flex;flex-direction:column;gap:9px;padding:6px 0;}
.rp-lbl{font-size:12px;color:var(--t3);margin-bottom:6px;}
.rps{display:flex;align-items:center;gap:9px;font-size:13px;color:var(--t3);padding:5px 0;transition:all .28s;}
.rp-done{color:var(--gr);}
.rp-cur{color:var(--ac);font-weight:500;}
.rpd{width:16px;height:16px;border-radius:50%;background:var(--s3);border:1px solid var(--b1);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:10px;}
.rp-done .rpd{background:rgba(63,185,80,.15);border-color:var(--gr);color:var(--gr);}
.rp-cur .rpd{background:var(--acg);border-color:var(--ac);color:var(--ac);}
/* Empty */
.empty{text-align:center;padding:56px 22px;display:flex;flex-direction:column;align-items:center;}
.empty-ring{width:52px;height:52px;background:var(--s2);border:1px solid var(--b1);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--t3);margin-bottom:12px;}
.empty-t{font-size:14px;font-weight:600;color:var(--t2);}
.empty-s{font-size:12px;color:var(--t3);margin-top:4px;max-width:280px;line-height:1.5;}
/* Credits */
.crgrid{display:grid;grid-template-columns:1fr 1fr;gap:13px;}
@media(max-width:700px){.crgrid{grid-template-columns:1fr;}}
.crhero{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);padding:22px;display:flex;flex-direction:column;align-items:center;}
.crring{width:96px;height:96px;transform:rotate(-90deg);}
.crlab{position:absolute;display:flex;flex-direction:column;align-items:center;justify-content:center;inset:0;}
.crnum{font-family:Inter,system-ui,sans-serif;font-size:20px;font-weight:700;color:var(--t1);}
.crsub{font-size:10px;color:var(--t3);}
.crused{font-size:11px;color:var(--t3);margin-top:9px;}
.crtopup{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);padding:16px;}
.crbtns{display:flex;gap:7px;flex-wrap:wrap;}
.crbtn{background:var(--s2);border:1px solid var(--b1);border-radius:7px;color:var(--t1);font-size:13px;font-weight:600;padding:6px 13px;cursor:pointer;transition:all .14s;font-family:Inter,system-ui,sans-serif;}
.crbtn:hover{background:var(--ac);border-color:var(--ac);color:#fff;box-shadow:0 0 12px var(--acg);}
.costg{display:grid;grid-template-columns:1fr 1fr;gap:5px;}
.costi{display:flex;justify-content:space-between;background:var(--s2);border-radius:6px;padding:5px 9px;font-size:12px;color:var(--t3);}
.costi strong{color:var(--ye);}
.crcost{font-size:12px;font-weight:700;color:var(--re);font-family:'IBM Plex Mono',ui-monospace,monospace;}
/* Toasts */
.toast-stack{position:fixed;bottom:22px;right:22px;z-index:9999;display:flex;flex-direction:column;gap:7px;}
.toast{display:flex;align-items:center;gap:8px;background:var(--s1);border:1px solid var(--b1);border-radius:9px;padding:9px 14px;font-size:12px;color:var(--t1);box-shadow:0 16px 36px rgba(15,23,42,.12);animation:fadeUp .25s ease;min-width:190px;}
.toast-dot{width:6px;height:6px;border-radius:50%;background:var(--gr);flex-shrink:0;}
.toast-error .toast-dot{background:var(--re);}
.toast-error{border-color:rgba(248,81,73,.3);}
/* Credit ring positioning fix */
.crhero{position:relative;}
.crhero .crlab{position:relative;width:96px;height:96px;margin-bottom:4px;}
/* Misc */
.capitalize{text-transform:capitalize;}

/* Light embed hardening */
.ves-market-signal-holder{width:100%;height:100%;display:block;overflow:visible;isolation:isolate;}
.ves-market-signal-holder .app p,.ves-market-signal-holder .app h1,.ves-market-signal-holder .app h2,.ves-market-signal-holder .app h3{margin:0;}
.ves-market-signal-holder .app button,.ves-market-signal-holder .app input,.ves-market-signal-holder .app select,.ves-market-signal-holder .app textarea{font:inherit;}
.sc,.panel,.item,.srccard,.wscard,.crhero,.crtopup,.modal{box-shadow:0 10px 28px rgba(15,23,42,.05);}
.sc:hover,.panel:hover,.item:hover,.srccard:hover,.wscard:hover{box-shadow:0 14px 34px rgba(15,23,42,.08);}
.sidebar{box-shadow:8px 0 30px rgba(15,23,42,.025);}
.phd2{padding-bottom:14px;border-bottom:1px solid var(--b1);}
.sc{min-height:112px;}
.sv{font-size:24px;}
.panel{overflow:hidden;}
.inp,.sbar-inp{background:#ffffff;}
.sbi-on{background:#eff6ff!important;color:#1d4ed8!important;}
.sb-foot{background:#f8fafc;}
.badge{font-weight:700;}
@media(max-width:900px){.sg{grid-template-columns:repeat(2,minmax(0,1fr));}.grid2,.dcols{grid-template-columns:1fr;}.page{padding:18px 16px 32px;}}
@media(max-width:768px){.app{border-radius:14px;}.sidebar{height:100%;}.mob-ov{background:rgba(15,23,42,.22);}.page{padding:16px 14px 28px;}.ptitle2{font-size:19px;}}
@media(max-width:560px){.sg{grid-template-columns:1fr;}.twoc{grid-template-columns:1fr;}.crgrid{grid-template-columns:1fr;}}

/* ── v0.9.31.0: Future Island™ brand + data-viz polish ─────── */
/* Sidebar */
.sidebar{width:220px;box-shadow:none;}
.sbi{border-radius:8px;min-height:36px;}
.sbi-on{background:rgba(58,97,225,.08)!important;color:#3A61E1!important;font-weight:600;}
.sbi-on::before{background:#3A61E1;}
.sb-logo{background:linear-gradient(135deg,#3A61E1,#5B7FE8);box-shadow:0 8px 18px rgba(58,97,225,.18);}
/* Buttons */
.btn-primary{background:#3A61E1;box-shadow:0 8px 18px rgba(58,97,225,.18);}
.btn-primary:hover:not(:disabled){background:#2D4DC5;}
.btn-accent{background:linear-gradient(135deg,#3A61E1,#5B7FE8);box-shadow:0 10px 22px rgba(58,97,225,.18);}
.btn-accent:hover:not(:disabled){box-shadow:0 14px 28px rgba(58,97,225,.22);}
/* Hot badge: orange (FI urgency) */
.sb-ct-hot{background:rgba(241,93,49,.12);color:#F15D31;border-color:rgba(241,93,49,.28);}
/* Stat cards */
.sc{border-radius:16px;padding:18px;transition:box-shadow .15s,transform .15s;border-left:3px solid transparent;}
.sc:hover{transform:translateY(-1px);box-shadow:0 14px 34px rgba(15,10,0,.08);}
.sc:nth-child(1){border-left-color:#3A61E1;}
.sc:nth-child(2){border-left-color:#F59E0B;}
.sc:nth-child(3){border-left-color:#D2F050;}
.sc:nth-child(4){border-left-color:#22C55E;}
.si{width:36px;height:36px;border-radius:10px;margin-bottom:12px;}
.sv{font-size:26px;font-weight:700;letter-spacing:-.025em;}
.sl{font-size:11.5px;font-weight:500;font-family:'IBM Plex Mono',ui-monospace,monospace;}
/* Panel / cards */
.panel{border-radius:16px;}
.phd3{padding:13px 16px 10px;}
.ptitle3{font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:10px;letter-spacing:.08em;text-transform:uppercase;}
/* Empty state: FI editorial */
.empty{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:52px 24px;}
.empty::before{content:'F.I/UI/EMPTY';display:block;font-family:'IBM Plex Mono',monospace;font-size:9px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:#B0A898;margin-bottom:10px;}
.empty-ring{width:52px;height:52px;border-radius:50%;background:var(--s3);border:1px solid var(--b1);display:flex;align-items:center;justify-content:center;margin-bottom:14px;color:var(--ac);}
.empty-t{font-family:'Archivo','Arial Black',sans-serif;font-size:16px;font-weight:900;color:var(--t1);margin-bottom:4px;letter-spacing:-.02em;}
.empty-s{font-size:13px;color:var(--t3);max-width:280px;line-height:1.5;margin-bottom:14px;}
/* FI signal/new badge: lime */
.badge-accent{background:rgba(210,240,80,.15);color:#4a5000;border:1px solid rgba(210,240,80,.30);}
/* FI error/reject badge: orange */
.badge-red{background:rgba(241,93,49,.12);color:#C24520;border:1px solid rgba(241,93,49,.22);}
/* Running badge: azul */
.badge-blue{background:rgba(58,97,225,.10);color:#3A61E1;}
/* Viz components */
.trend-delta{display:inline-flex;align-items:center;gap:2px;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700;font-family:'IBM Plex Mono',monospace;white-space:nowrap;}
.trend-up{background:rgba(210,240,80,.15);color:#4a5000;}
.trend-down{background:rgba(241,93,49,.10);color:#C24520;}
.mini-bar-wrap{width:100%;height:4px;background:var(--b1);border-radius:99px;overflow:hidden;margin-top:6px;}
.mini-bar-fill{height:100%;border-radius:99px;background:var(--ac);transition:width .4s ease;}
/* Input and form warmth */
.inp{background:#fff;border-color:var(--b1);color:var(--t1);}
.inp:focus{border-color:var(--ac);box-shadow:0 0 0 3px rgba(58,97,225,.12);}
/* Misc */
.app{border-radius:20px;}
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:#c8c2b8;border-radius:99px;}
::-webkit-scrollbar-thumb:hover{background:#a89e92;}
/* Responsive */
@media(max-width:900px){.sg{grid-template-columns:repeat(2,minmax(0,1fr));}.grid2,.dcols{grid-template-columns:1fr;}.page{padding:18px 16px 32px;}}
@media(max-width:768px){.app{border-radius:14px;}.sidebar{height:100%;}.page{padding:16px 14px 28px;}.ptitle2{font-size:19px;}}
@media(max-width:640px){.app{border-radius:0;border:none;}.sg{grid-template-columns:1fr;}.wsgrid{grid-template-columns:1fr!important;}}
@media(max-width:560px){.twoc{grid-template-columns:1fr;}.crgrid{grid-template-columns:1fr;}}

`;
(function mountMarketSignal() {
    function wpElementReady() {
        return !!(window.wp && wp.element && React && (typeof wp.element.createRoot === 'function' || typeof wp.element.render === 'function'));
    }
    function mountOne(el) {
        if (!el || el.__vesMarketSignalMounted || !wpElementReady())
            return;
        el.__vesMarketSignalMounted = true;
        const useShadow = el.getAttribute('data-shadow') === '1' && !!el.attachShadow;
        if (!useShadow) {
            el.innerHTML = '';
        }
        const rootTarget = useShadow ? el.attachShadow({ mode: 'open' }) : el;
        const holder = document.createElement('div');
        holder.className = 'ves-market-signal-holder';
        rootTarget.appendChild(holder);
        if (wp.element && typeof wp.element.createRoot === 'function') {
            wp.element.createRoot(holder).render(React.createElement(App));
        }
        else if (wp.element && typeof wp.element.render === 'function') {
            wp.element.render(React.createElement(App), holder);
        }
    }
    function boot() {
        if (!wpElementReady()) {
            window.__VES_MS_BOOT_RETRIES = (window.__VES_MS_BOOT_RETRIES || 0) + 1;
            if (window.__VES_MS_BOOT_RETRIES < 80) setTimeout(boot, 100);
            return;
        }
        document.querySelectorAll('[data-ves-market-signal-root]').forEach(mountOne);
    }
    window.VESMarketSignalBoot = boot;
    if (document.readyState === 'loading')
        document.addEventListener('DOMContentLoaded', boot);
    else
        boot();
    if (window.MutationObserver) {
        const observer = new MutationObserver(() => boot());
        observer.observe(document.documentElement || document.body, { childList: true, subtree: true });
    }
})();
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', startWhenReady); } else { startWhenReady(); }
})();
