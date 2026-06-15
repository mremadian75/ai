// Future Island — real-browser revalidation harness.
//
// Renders each UI snippet WITH the actual plugin CSS in a real headless Chromium
// (via Playwright) at desktop/tablet/mobile widths, then measures for the specific
// browser-observed bugs: character-by-character columns, mid-word breaks on prose,
// and narrow cards. Writes screenshots + measurements.json.
//
// This validates the shipped CSS + snippet markup in a real browser engine. It is
// NOT a full live WordPress install (no WP runtime, DB, admin chrome, or core admin
// CSS) — see REAL_BROWSER_REVALIDATION_EVIDENCE_INDEX.md for scope.
//
// Run:
//   cd <plugin>/ui-snippets/real-browser-revalidation
//   ln -sfn "$(npm root -g)" node_modules    # or point NODE_PATH at a playwright install
//   PLAYWRIGHT_BROWSERS_PATH=/path/to/pw-browsers node render-harness.mjs
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PLUGIN = path.resolve(HERE, '..', '..');
const CSS_DIR = path.join(PLUGIN, 'assets/css');
const SHOTS = path.join(HERE, 'shots');

// Load CSS in a sensible cascade order (base first).
const CSS_FILES = [
  'fiis-app.css',
  'ves-frontend.css',
  'fiis-ui-system.css',
  'fiis-signal-room.css',
  'fiis-intelligence-map.css',
  'fiis-admin-console.css',
  'fiis-mobile.css',
];
const cssInline = CSS_FILES
  .map(f => { try { return `/* ${f} */\n` + fs.readFileSync(path.join(CSS_DIR, f), 'utf8'); } catch { return ''; } })
  .join('\n\n');

// Snippets to validate (real-browser-ui-bugfix set + top-level set).
const bugfixDir = path.join(PLUGIN, 'ui-snippets/real-browser-ui-bugfix');
const topDir = path.join(PLUGIN, 'ui-snippets');
const snippets = [];
for (const f of fs.readdirSync(bugfixDir)) if (f.endsWith('.html')) snippets.push({ group:'bugfix', name:f.replace('.html',''), file: path.join(bugfixDir, f) });
for (const f of fs.readdirSync(topDir)) if (f.endsWith('.html')) snippets.push({ group:'top', name:f.replace('.html',''), file: path.join(topDir, f) });

const VIEWPORTS = [ {w:1440,h:1000,tag:'desktop'}, {w:768,h:1024,tag:'tablet'}, {w:375,h:812,tag:'mobile'} ];

function pageHtml(frag){
  return `<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--em:#5b3daa;--em30:#5b3daa;--bdr:#d8dee6;--bdr2:#c9d2dd;--t1:#1b2330;--t2:#3a4658;--t3:#74829a;--card:#fff;--surface:#fff;--red:#e23;--r2:10px;}
html,body{margin:0;background:#f3f5f8;color:#1b2330;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;}
.harness-pad{padding:20px;}
${cssInline}
</style></head>
<body class="ves-page-body"><div class="harness-pad ves-page-inner">${frag}</div></body></html>`;
}

const TEXT_SEL = [
  '.ves-platform','.ves-title2','.ves-desc','.ves-text','.ves-meta','.ves-title','.ves-name',
  '.fiis-route-step span','.fiis-route-state','.fiis-route-label','.fi-status-chip','.fiis-act-reason',
  '.ves-kpi small','.ves-kpi strong','.ves-brand-metric-card small','.ves-brand-metric-card strong',
  'h1','h2','h3','h4','p','td','th','strong','small','span','button','li'
].join(',');

const results = [];
const browser = await chromium.launch();
for (const snip of snippets){
  const frag = fs.readFileSync(snip.file,'utf8');
  for (const vp of VIEWPORTS){
    const ctx = await browser.newContext({ viewport:{width:vp.w,height:vp.h}, deviceScaleFactor:1 });
    const page = await ctx.newPage();
    await page.setContent(pageHtml(frag), { waitUntil:'networkidle' });
    // Reveal content that the app's JS would normally unhide (results panels, tabs, etc.)
    await page.evaluate(()=>{
      document.querySelectorAll('.ves-results').forEach(e=>e.classList.add('show'));
      document.querySelectorAll('[hidden]').forEach(e=>e.removeAttribute('hidden'));
      document.querySelectorAll('.is-hidden,.ves-hidden').forEach(e=>e.classList.remove('is-hidden','ves-hidden'));
    });
    await page.waitForTimeout(50);
    const findings = await page.evaluate((sel)=>{
      function isToken(w){ return w.length>24 || /:\/\/|www\.|\.[a-z]{2,}\/|#[\w-]{12,}|[_\/]{2,}/.test(w); }
      // measuring span
      const meas = document.createElement('span');
      meas.style.cssText='position:absolute;left:-9999px;top:-9999px;white-space:nowrap;visibility:hidden;';
      document.body.appendChild(meas);
      function wordWidth(word, cs){
        meas.style.font = cs.font || (cs.fontWeight+' '+cs.fontSize+' '+cs.fontFamily);
        meas.style.fontSize=cs.fontSize; meas.style.fontWeight=cs.fontWeight;
        meas.style.fontFamily=cs.fontFamily; meas.style.letterSpacing=cs.letterSpacing;
        meas.textContent=word; return meas.getBoundingClientRect().width;
      }
      const out={ narrowCards:[], midWordBreak:[], charByChar:[], gridCols:{}, minProseW: 99999 };
      // grid column detection for known containers
      for (const gsel of ['.ves-items','.fiis-route-track','.ves-kpis','.ves-brand-metric-grid']){
        const g=document.querySelector(gsel);
        if(g){ const cs=getComputedStyle(g); out.gridCols[gsel]= cs.gridTemplateColumns ? cs.gridTemplateColumns.split(' ').length : 0; }
      }
      // card width check (direct cards)
      document.querySelectorAll('.ves-item,.ves-card,.ves-result-card,.fiis-route-step,.ves-kpi').forEach(el=>{
        const r=el.getBoundingClientRect();
        if(r.width>0 && r.width<120 && window.innerWidth>=700){ out.narrowCards.push({cls:el.className.slice(0,40), w:Math.round(r.width)}); }
      });
      // text element checks
      const seen=new Set();
      document.querySelectorAll(sel).forEach(el=>{
        if(el.children.length>0) {
          // only check elements whose direct text is meaningful; skip pure containers
          const direct = Array.from(el.childNodes).filter(n=>n.nodeType===3).map(n=>n.textContent).join('').trim();
          if(!direct) return;
        }
        const txt=(el.textContent||'').trim();
        if(txt.length<2) return;
        const r=el.getBoundingClientRect();
        if(r.width<=0||r.height<=0) return;
        const cs=getComputedStyle(el);
        const key=el.tagName+'|'+txt.slice(0,30)+'|'+Math.round(r.left)+'|'+Math.round(r.top);
        if(seen.has(key)) return; seen.add(key);
        const words=txt.split(/\s+/).filter(w=>w.length>1 && !isToken(w));
        if(words.length){
          const padL=parseFloat(cs.paddingLeft)||0, padR=parseFloat(cs.paddingRight)||0;
          const contentW = r.width - padL - padR;
          let longest=0, lw='';
          for(const w of words){ const ww=wordWidth(w,cs); if(ww>longest){longest=ww; lw=w;} }
          // mid-word break: content box narrower than the longest non-token word (with 2px tolerance)
          if(longest>0 && contentW>0 && contentW + 2 < longest){
            out.midWordBreak.push({cls:(el.className||el.tagName).toString().slice(0,40), word:lw, contentW:Math.round(contentW), needW:Math.round(longest), wb:cs.wordBreak, ow:cs.overflowWrap});
          }
          if(contentW>0) out.minProseW=Math.min(out.minProseW, Math.round(contentW));
        }
        // char-by-char: a text element rendered absurdly narrow while holding multi-char content
        if(r.width>0 && r.width<26 && txt.replace(/\s/g,'').length>3 && getComputedStyle(el).display!=='none'){
          out.charByChar.push({cls:(el.className||el.tagName).toString().slice(0,40), w:Math.round(r.width), txt:txt.slice(0,20)});
        }
      });
      meas.remove();
      if(out.minProseW===99999) out.minProseW=null;
      return out;
    }, TEXT_SEL);
    const shot = path.join(SHOTS, `${snip.group}__${snip.name}__${vp.tag}.png`);
    await page.screenshot({ path: shot, fullPage:true });
    results.push({ snippet:snip.name, group:snip.group, vp:vp.tag, ...findings });
    await ctx.close();
  }
}
await browser.close();
fs.writeFileSync(path.join(HERE, 'measurements.json'), JSON.stringify(results,null,2));

// Summarize
let totalViol=0;
const bad=[];
for(const r of results){
  const v=(r.midWordBreak.length)+(r.charByChar.length)+(r.narrowCards.length);
  if(v>0){ totalViol+=v; bad.push(r); }
}
console.log('Rendered snippet x viewport combos:', results.length);
console.log('Screenshots written to:', SHOTS);
console.log('Total layout violations (mid-word break + char-by-char + narrow cards):', totalViol);
if(bad.length){
  console.log('\n--- VIOLATIONS ---');
  for(const r of bad){
    console.log(`\n[${r.snippet} @ ${r.vp}]`);
    if(r.charByChar.length) console.log('  charByChar:', JSON.stringify(r.charByChar));
    if(r.midWordBreak.length) console.log('  midWordBreak:', JSON.stringify(r.midWordBreak));
    if(r.narrowCards.length) console.log('  narrowCards:', JSON.stringify(r.narrowCards));
  }
} else {
  console.log('No character-by-character, mid-word-break, or narrow-card violations detected.');
}
// Grid sanity (desktop)
console.log('\n--- GRID COLUMNS (desktop) ---');
for(const r of results.filter(x=>x.vp==='desktop')){
  if(Object.keys(r.gridCols).length) console.log(` ${r.snippet}:`, JSON.stringify(r.gridCols), ' minProseContentW=', r.minProseW);
}
