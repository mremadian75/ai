// ── Future Island Home v2.3 — WPCode PHP Snippet ──
// In WPCode: add a snippet, type "PHP Snippet", Location = "Run Everywhere".
// Do NOT add an opening <?php tag — WPCode adds it for you.
// Use in any page/post with:  [fi_home_v2]
//
// v2.3 changes vs v2.2.1:
//   - Brand-book OFFICIAL colors (azul #2F64D9, lima #D7FF32, rojo #F55A32, arena #DCD4BD).
//   - Content width set to 1200px.
//   - Seamless, gap-free infinite marquees (Signal Band + Channels) via JS.
//   - Mobile-specific layouts per section (not just stacked).
//   - Final CTA points to /demo/.
//   - Removed unused nav/footer/mobile-menu CSS (your header/footer are separate snippets).
//   - Pricing / "Planes" section removed.

if ( ! function_exists( 'fi_home_v2_shortcode' ) ) {
    function fi_home_v2_shortcode( $atts = array(), $content = null, $tag = 'fi_home_v2' ) {
        static $already_rendered = false;
        if ( $already_rendered ) {
            return '<!-- Future Island Home v2 already rendered once on this page. -->';
        }
        $already_rendered = true;

        $atts = shortcode_atts( array( 'class' => '' ), $atts, $tag );
        $extra_class = isset( $atts['class'] ) ? trim( (string) $atts['class'] ) : '';
        $wrap_class  = 'fi-v2';
        if ( '' !== $extra_class ) {
            $wrap_class .= ' ' . esc_attr( $extra_class );
        }

        ob_start();
        ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Instrument+Serif:ital@0;1&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style id="fi-home-v2-style">
.fi-v2{
  --negro:#0F0F0F; --beige:#ECE8DF; --azul:#2F64D9; --arena:#DCD4BD;
  --lime:#D7FF32; --acc-rgb:215 255 50; --rojo:#F55A32; --ink:#0F0F0F;
  --muted:#6c685e; --faint:#9d978a; --line:#dbd6c8; --line-2:#e3dfd1;
  --maxw:1200px; --ease:cubic-bezier(.22,.61,.36,1);
}
.fi-v2 *{box-sizing:border-box;margin:0;padding:0}
.fi-v2{-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
html:has(.fi-v2){scroll-behavior:smooth}
.fi-v2{position:relative;isolation:isolate;width:100%;overflow-x:hidden;background:var(--beige);color:var(--ink);font-family:"Inter",system-ui,sans-serif;font-size:17px;line-height:1.55;font-weight:400}
.fi-v2::selection{background:var(--negro);color:var(--beige)}
.fi-v2::before{content:"";position:absolute;inset:0;z-index:40;pointer-events:none;opacity:.05;mix-blend-mode:multiply;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E")}
.fi-v2 a{color:inherit;text-decoration:none}
/* Keyboard accessibility — visible focus rings (mouse clicks stay clean) */
.fi-v2 a:focus-visible,.fi-v2 .btn:focus-visible,.fi-v2 .tlink:focus-visible,.fi-v2 button:focus-visible{outline:2px solid var(--azul);outline-offset:3px;border-radius:2px}
.fi-v2 .btn-rojo:focus-visible{outline-color:var(--negro)}
.fi-v2 .feed *:focus-visible,.fi-v2 .band *:focus-visible{outline-color:var(--lime)}
.fi-v2 img{display:block;max-width:100%}
.fi-v2 .wrap{max-width:var(--maxw);margin:0 auto;padding:0 40px}
.fi-v2 .display{font-family:"Archivo Black",sans-serif;font-weight:400;text-transform:uppercase;line-height:.96;letter-spacing:-.015em}
.fi-v2 .display em{font-family:"Instrument Serif",serif;font-style:italic;font-weight:400;text-transform:none;color:var(--azul);letter-spacing:0;font-size:1.1em;line-height:.9}
.fi-v2 .eyebrow{font-family:"IBM Plex Mono",monospace;font-size:.7rem;font-weight:500;letter-spacing:.18em;text-transform:uppercase;color:var(--muted);display:inline-flex;align-items:center;gap:.7em}
.fi-v2 .eyebrow .sq{width:7px;height:7px;background:var(--azul);display:inline-block}
.fi-v2 .archid{font-family:"IBM Plex Mono",monospace;font-size:.68rem;letter-spacing:.1em;color:var(--faint)}
.fi-v2 .mono{font-family:"IBM Plex Mono",monospace;font-size:.74rem;letter-spacing:.04em;color:var(--muted)}
.fi-v2 .btn{display:inline-flex;align-items:center;gap:.6em;font-family:"Inter";font-weight:600;font-size:.95rem;padding:15px 26px;border-radius:2px;cursor:pointer;border:1px solid transparent;white-space:nowrap;transition:background .4s var(--ease),color .4s var(--ease),border-color .4s var(--ease),transform .35s var(--ease)}
.fi-v2 .btn .arw{transition:transform .4s var(--ease)}
.fi-v2 .btn:hover .arw{transform:translateX(5px)}
.fi-v2 .btn-dark{background:var(--negro);color:var(--beige)}
.fi-v2 .btn-dark:hover{background:#000;transform:translateY(-1px)}
.fi-v2 .btn-light{background:var(--beige);color:var(--negro)}
.fi-v2 .btn-light:hover{background:#fff;transform:translateY(-1px)}
.fi-v2 .btn-rojo{background:var(--rojo);color:#fff}
.fi-v2 .btn-rojo:hover{background:#e0481f;transform:translateY(-1px)}
.fi-v2 .btn-ghost{background:transparent;color:var(--ink);border-color:var(--line)}
.fi-v2 .btn-ghost:hover{border-color:var(--ink)}
.fi-v2 .btn-ghost-light{background:transparent;color:var(--beige);border-color:rgba(236,232,223,.28)}
.fi-v2 .btn-ghost-light:hover{border-color:var(--beige)}
.fi-v2 .tlink{display:inline-flex;align-items:center;gap:.5em;font-weight:600;font-size:.95rem;position:relative;padding-bottom:2px}
.fi-v2 .tlink::after{content:"";position:absolute;left:0;bottom:0;height:1.5px;width:100%;background:currentColor;transform:scaleX(0);transform-origin:left;transition:transform .45s var(--ease)}
.fi-v2 .tlink:hover::after{transform:scaleX(1)}
.fi-v2 .tlink:hover .arw{transform:translateX(4px)}
.fi-v2 .tlink .arw{transition:transform .4s var(--ease)}
.fi-v2 header.hero{padding:170px 0 0}
.fi-v2 .hero-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:64px;align-items:center}
.fi-v2 .hero .kick{margin-bottom:30px}
.fi-v2 .hero h1{font-size:clamp(2.5rem,6.2vw,5rem);max-width:15ch}
.fi-v2 .hero .lead{max-width:44ch;margin-top:28px;color:var(--muted);font-size:1.12rem;line-height:1.5}
.fi-v2 .hero .lead strong{color:var(--ink);font-weight:600}
.fi-v2 .hero-cta{display:flex;align-items:center;gap:24px;margin-top:38px;flex-wrap:wrap}
.fi-v2 .hero .micro{margin-top:22px;font-family:"IBM Plex Mono",monospace;font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:var(--faint)}
.fi-v2 .radar{background:var(--negro);border-radius:10px;padding:22px 22px 20px;color:var(--beige);position:relative;overflow:hidden;box-shadow:0 30px 70px -30px rgba(15,15,15,.5)}
.fi-v2 .radar::before{content:"";position:absolute;inset:0;opacity:.06;mix-blend-mode:screen;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");pointer-events:none}
.fi-v2 .radar>*{position:relative}
.fi-v2 .radar-top{display:flex;align-items:center;justify-content:space-between;font-family:"IBM Plex Mono",monospace;font-size:.66rem;letter-spacing:.12em;text-transform:uppercase}
.fi-v2 .radar-top .rt-id{color:rgba(236,232,223,.55)}
.fi-v2 .radar-top .rt-live{display:inline-flex;align-items:center;gap:8px;color:var(--lime)}
.fi-v2 .ld{width:7px;height:7px;border-radius:50%;background:var(--lime);position:relative}
.fi-v2 .ld::after{content:"";position:absolute;inset:-4px;border-radius:50%;border:1px solid var(--lime);opacity:.5;animation:ping 2s var(--ease) infinite}
@keyframes ping{0%{transform:scale(.6);opacity:.55}80%,100%{transform:scale(2);opacity:0}}
.fi-v2 .radar-stage{padding:22px 6px 16px;display:flex;justify-content:center}
.fi-v2 .scope{position:relative;width:100%;max-width:380px;aspect-ratio:1/1;border-radius:50%;overflow:hidden}
.fi-v2 .ring{position:absolute;border-radius:50%;border:1px solid rgba(47,100,217,.32)}
.fi-v2 .ring.r1{inset:0}
.fi-v2 .ring.r2{inset:16.5%;border-color:rgba(47,100,217,.24)}
.fi-v2 .ring.r3{inset:33%;border-color:rgba(47,100,217,.18)}
.fi-v2 .ring.r4{inset:45%;border-color:rgba(236,232,223,.18)}
.fi-v2 .cross{position:absolute;background:rgba(236,232,223,.08)}
.fi-v2 .cross.h{top:50%;left:0;width:100%;height:1px}
.fi-v2 .cross.v{left:50%;top:0;height:100%;width:1px}
.fi-v2 .sweep{position:absolute;inset:0;border-radius:50%;background:conic-gradient(from -8deg, rgb(var(--acc-rgb)/.42), rgb(var(--acc-rgb)/.06) 48deg, rgb(var(--acc-rgb)/0) 70deg, transparent 70deg 360deg);animation:sweep 4.4s linear infinite;transform-origin:50% 50%}
.fi-v2 .sweep::after{content:"";position:absolute;top:50%;left:50%;width:50%;height:1.5px;background:linear-gradient(90deg,rgb(var(--acc-rgb)/.65),rgb(var(--acc-rgb)/0));transform-origin:left center}
@keyframes sweep{to{transform:rotate(360deg)}}
.fi-v2 .core{position:absolute;top:50%;left:50%;width:7px;height:7px;border-radius:50%;background:var(--lime);transform:translate(-50%,-50%);box-shadow:0 0 12px rgb(var(--acc-rgb)/.8)}
.fi-v2 .blip{position:absolute;width:8px;height:8px;border-radius:50%;transform:translate(-50%,-50%) scale(.6);opacity:.2;animation:blip 4.4s var(--ease) infinite}
.fi-v2 .blip b{position:absolute;left:13px;top:-3px;font-family:"IBM Plex Mono",monospace;font-size:.52rem;letter-spacing:.08em;color:rgba(236,232,223,.7);white-space:nowrap;font-weight:400}
.fi-v2 .blip.lime{background:var(--lime);box-shadow:0 0 10px rgb(var(--acc-rgb)/.7)}
.fi-v2 .blip.azul{background:#6f8dff;box-shadow:0 0 10px rgba(47,100,217,.7)}
.fi-v2 .blip.rojo{background:var(--rojo);box-shadow:0 0 10px rgba(245,90,50,.7)}
@keyframes blip{0%{opacity:.12;transform:translate(-50%,-50%) scale(.55)}8%{opacity:1;transform:translate(-50%,-50%) scale(1.25)}45%{opacity:.45}100%{opacity:.12;transform:translate(-50%,-50%) scale(.55)}}
.fi-v2 .rim{position:absolute;font-family:"IBM Plex Mono",monospace;font-size:.56rem;letter-spacing:.14em;color:rgba(236,232,223,.4);transform:translate(-50%,-50%)}
.fi-v2 .radar-read{border-top:1px solid rgba(236,232,223,.1);padding-top:14px;display:flex;align-items:center;justify-content:space-between;gap:14px}
.fi-v2 .rr-line{font-family:"IBM Plex Mono",monospace;font-size:.72rem;color:rgba(236,232,223,.85);overflow:hidden;white-space:nowrap;text-overflow:ellipsis;transition:opacity .4s}
.fi-v2 .rr-line .ch{color:var(--lime);margin-right:8px}
.fi-v2 .rr-meta{font-family:"IBM Plex Mono",monospace;font-size:.68rem;color:rgba(236,232,223,.5);display:flex;gap:12px;flex-shrink:0}
.fi-v2 .rr-meta .up{color:var(--lime)}
.fi-v2 .sigband{margin-top:80px;border-top:1px solid var(--line);border-bottom:1px solid var(--line);overflow:hidden}
.fi-v2 .sigband .sb-head{display:flex;align-items:center;gap:10px;max-width:var(--maxw);margin:0 auto;padding:13px 40px;border-bottom:1px solid var(--line-2)}
.fi-v2 .sigband .sb-head .ld{background:var(--lime)}
.fi-v2 .sigband .sb-head .lbl{font-family:"IBM Plex Mono",monospace;font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;color:var(--ink)}
.fi-v2 .sigband .sb-head .sub{font-family:"IBM Plex Mono",monospace;font-size:.66rem;color:var(--faint);margin-left:auto}
.fi-v2 .ticker{display:flex;white-space:nowrap}
.fi-v2 .ticker .tk{display:inline-flex;align-items:center;gap:11px;padding:15px 0;font-family:"IBM Plex Mono",monospace;font-size:.76rem;color:var(--ink)}
.fi-v2 .ticker .tk .ch{color:var(--faint);text-transform:uppercase;letter-spacing:.12em;font-size:.64rem}
.fi-v2 .ticker .sep{margin:0 28px;color:var(--line)}

/* ─────────── LIVE FEED · VIDEO ─────────── */
.fi-v2 .feed{background:var(--negro);color:var(--beige);position:relative;overflow:hidden;border-bottom:1px solid rgba(236,232,223,.12)}
.fi-v2 .feed::before{content:"";position:absolute;inset:0;opacity:.05;mix-blend-mode:screen;pointer-events:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E")}
.fi-v2 .feed .wrap{position:relative}
.fi-v2 .feed-head{display:flex;align-items:flex-end;justify-content:space-between;gap:30px;margin-bottom:34px;padding-bottom:20px;border-bottom:1px solid rgba(236,232,223,.14)}
.fi-v2 .feed-head .eyebrow{color:rgba(236,232,223,.62)}
.fi-v2 .feed-head .eyebrow .sq{background:var(--lime)}
.fi-v2 .feed-head h2{font-family:"Archivo Black",sans-serif;font-weight:400;text-transform:uppercase;line-height:.98;letter-spacing:-.015em;font-size:clamp(1.7rem,3.6vw,2.7rem);margin-top:18px;max-width:18ch}
.fi-v2 .feed-head h2 em{font-family:"Instrument Serif",serif;font-style:italic;font-weight:400;text-transform:none;color:var(--lime);font-size:1.1em;letter-spacing:0}
.fi-v2 .screen{position:relative;border-radius:10px;overflow:hidden;background:#000;box-shadow:0 40px 90px -40px rgba(0,0,0,.7);border:1px solid rgba(236,232,223,.1)}
.fi-v2 .screen .ratio{position:relative;width:100%;aspect-ratio:16/9}
.fi-v2 .screen iframe{position:absolute;inset:0;width:100%;height:100%;border:0;display:block}
.fi-v2 .screen .crn{position:absolute;width:18px;height:18px;border:1px solid rgb(var(--acc-rgb)/.55);z-index:2;pointer-events:none}
.fi-v2 .screen .crn.tl{top:14px;left:14px;border-right:none;border-bottom:none}
.fi-v2 .screen .crn.tr{top:14px;right:14px;border-left:none;border-bottom:none}
.fi-v2 .screen .crn.bl{bottom:14px;left:14px;border-right:none;border-top:none}
.fi-v2 .screen .crn.br{bottom:14px;right:14px;border-left:none;border-top:none}
.fi-v2 .screen .scan{position:absolute;inset:0;z-index:1;pointer-events:none;background:repeating-linear-gradient(to bottom,rgba(0,0,0,0) 0,rgba(0,0,0,0) 2px,rgba(0,0,0,.06) 3px);mix-blend-mode:multiply}

/* ─────────── LIVE DASHBOARD DEMO (trace) ─────────── */
.fi-v2 .demo{margin-top:46px;background:var(--negro);color:var(--beige);border-radius:10px;position:relative;overflow:hidden;box-shadow:0 40px 90px -45px rgba(0,0,0,.7);border:1px solid rgba(236,232,223,.1)}
.fi-v2 .demo::before{content:"";position:absolute;inset:0;opacity:.05;mix-blend-mode:screen;pointer-events:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E")}
.fi-v2 .demo>*{position:relative;z-index:2}
.fi-v2 .demo .dscan{position:absolute;left:0;right:0;top:0;height:120px;z-index:1;pointer-events:none;background:linear-gradient(to bottom,rgb(var(--acc-rgb)/.12),transparent);animation:dscan 7s var(--ease) infinite}
@keyframes dscan{0%{transform:translateY(-130px);opacity:0}12%{opacity:1}55%{opacity:.5}92%{opacity:0}100%{transform:translateY(620px);opacity:0}}
.fi-v2 .demo-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 22px;border-bottom:1px solid rgba(236,232,223,.12)}
.fi-v2 .demo-bar .db-left{display:flex;align-items:center;gap:8px}
.fi-v2 .demo-bar .db-dot{width:9px;height:9px;border-radius:50%;background:rgba(236,232,223,.22)}
.fi-v2 .demo-bar .db-dot.lm{background:var(--lime)}
.fi-v2 .demo-bar .db-title{font-family:"IBM Plex Mono",monospace;font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;color:rgba(236,232,223,.6);margin-left:10px}
.fi-v2 .demo-bar .db-right{display:flex;align-items:center;gap:18px;font-family:"IBM Plex Mono",monospace;font-size:.64rem;letter-spacing:.12em;text-transform:uppercase}
.fi-v2 .demo-bar .db-live{display:inline-flex;align-items:center;gap:8px;color:var(--lime)}
.fi-v2 .demo-bar .db-time{color:rgba(236,232,223,.5)}
.fi-v2 .pipe{position:relative;padding:26px 22px 6px}
.fi-v2 .pipe-track{position:absolute;left:calc(10% + 22px);right:calc(10% + 22px);top:33px;height:2px;background:rgba(236,232,223,.13);z-index:1}
.fi-v2 .pipe-fill{position:absolute;left:0;top:0;height:100%;width:0;background:var(--lime);transition:width .7s var(--ease);box-shadow:0 0 8px rgb(var(--acc-rgb)/.6)}
.fi-v2 .pipe-nodes{position:relative;z-index:2;display:grid;grid-template-columns:repeat(5,1fr)}
.fi-v2 .pnode{display:flex;flex-direction:column;align-items:center;gap:11px}
.fi-v2 .pnode .pn-dot{width:14px;height:14px;border-radius:50%;background:var(--negro);border:2px solid rgba(236,232,223,.25);transition:border-color .4s var(--ease),background .4s,box-shadow .4s}
.fi-v2 .pnode.active .pn-dot{border-color:var(--lime);box-shadow:0 0 0 5px rgb(var(--acc-rgb)/.14)}
.fi-v2 .pnode.done .pn-dot{background:var(--lime);border-color:var(--lime)}
.fi-v2 .pnode .pn-lbl{font-family:"IBM Plex Mono",monospace;font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;color:rgba(236,232,223,.4);transition:color .4s}
.fi-v2 .pnode.active .pn-lbl,.fi-v2 .pnode.done .pn-lbl{color:rgba(236,232,223,.85)}
.fi-v2 .demo-rows{padding:10px 22px 4px}
.fi-v2 .drow{display:grid;grid-template-columns:90px 1fr 16px;gap:18px;align-items:start;padding:14px 0;border-top:1px solid rgba(236,232,223,.1);font-family:"IBM Plex Mono",monospace;font-size:.82rem;opacity:.26;filter:grayscale(.5);transition:opacity .55s var(--ease),filter .55s var(--ease),transform .55s var(--ease);transform:translateY(6px)}
.fi-v2 .drow:first-child{border-top:none}
.fi-v2 .drow.show{opacity:1;filter:none;transform:none}
.fi-v2 .dr-k{color:rgba(236,232,223,.5);letter-spacing:.1em;text-transform:uppercase;font-size:.66rem;padding-top:3px}
.fi-v2 .drow.active .dr-k{color:var(--lime)}
.fi-v2 .dr-v{color:rgba(236,232,223,.92);line-height:1.55}
.fi-v2 .dr-v .hl{color:var(--lime)}
.fi-v2 .drow.out .dr-v{color:#fff}
.fi-v2 .drow.active .dr-v::after{content:"▌";color:var(--lime);margin-left:1px;animation:dcaret 1s steps(1) infinite}
@keyframes dcaret{50%{opacity:0}}
.fi-v2 .dr-stat{justify-self:center;margin-top:2px;width:14px;height:14px;position:relative}
.fi-v2 .dr-stat .spin{position:absolute;inset:0;border-radius:50%;border:1.5px solid rgba(236,232,223,.2);border-top-color:var(--lime);opacity:0}
.fi-v2 .drow.proc .dr-stat .spin{opacity:1;animation:dspin .75s linear infinite}
@keyframes dspin{to{transform:rotate(360deg)}}
.fi-v2 .dr-stat .chk{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--lime);font-size:.72rem;line-height:1;opacity:0;transform:scale(.5);transition:opacity .3s,transform .3s var(--ease)}
.fi-v2 .drow.done2 .dr-stat .chk{opacity:1;transform:scale(1)}
.fi-v2 .demo-foot{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:15px 22px;border-top:1px solid rgba(236,232,223,.12);font-family:"IBM Plex Mono",monospace;font-size:.64rem;letter-spacing:.1em;text-transform:uppercase;color:rgba(236,232,223,.5)}
.fi-v2 .demo-foot b{color:var(--lime);font-weight:500}
.fi-v2 .demo-foot .df-state{color:rgba(236,232,223,.72);text-transform:none;letter-spacing:.04em}

/* ─────────── STEPS · auto-advancing live highlight ─────────── */
.fi-v2 .step{position:relative}
.fi-v2 .step::before{content:"";position:absolute;left:0;top:0;bottom:0;width:2px;background:var(--lime);transform:scaleY(0);transform-origin:center;transition:transform .55s var(--ease)}
.fi-v2 .step.live{background:rgba(220,212,189,.5)}
.fi-v2 .step.live::before{transform:scaleY(1)}
.fi-v2 .step.live .st-no{color:var(--azul)}
.fi-v2 .step .st-no{transition:color .5s var(--ease)}

/* ─────────── LIVE pulse marker (Señal layer) ─────────── */
.fi-v2 .world .tag .dotlive{width:6px;height:6px;border-radius:50%;background:var(--lime);display:inline-block;margin-right:7px;position:relative;vertical-align:middle}
.fi-v2 .world .tag .dotlive::after{content:"";position:absolute;inset:-3px;border-radius:50%;border:1px solid var(--lime);opacity:.5;animation:ping 2s var(--ease) infinite}

/* ─────────── METRICS ─────────── */
.fi-v2 .metrics{border-bottom:1px solid var(--line)}
.fi-v2 .metrics-in{display:grid;grid-template-columns:repeat(4,1fr)}
.fi-v2 .metric{padding:46px 30px;border-left:1px solid var(--line)}
.fi-v2 .metric:first-child{border-left:none;padding-left:0}
.fi-v2 .metric .num{font-family:"Archivo Black",sans-serif;font-size:2.7rem;line-height:1;letter-spacing:-.02em}
.fi-v2 .metric .num.word{font-size:1.55rem;line-height:1.05;text-transform:uppercase}
.fi-v2 .metric .cap{margin-top:12px;font-family:"IBM Plex Mono",monospace;font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.fi-v2 .metric .cap.desc{text-transform:none;letter-spacing:.01em;font-size:.74rem;color:var(--muted);line-height:1.5;max-width:30ch}

/* ─────────── SECTION SHELL ─────────── */
.fi-v2 section{padding:clamp(92px,10.5vw,160px) 0}
/* Offset anchor jumps so headings don't hide under a sticky header */
.fi-v2 section[id],.fi-v2 header[id]{scroll-margin-top:90px}
.fi-v2 .kick{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:46px;padding-bottom:18px;border-bottom:1px solid var(--line)}
.fi-v2 .sec-head{display:grid;grid-template-columns:1fr 1.12fr;gap:50px;align-items:end;margin-bottom:70px}
.fi-v2 .sec-head h2{font-size:clamp(1.9rem,4.3vw,3.3rem);max-width:15ch}
.fi-v2 .sec-head .sh-copy{color:var(--muted);font-size:1.06rem;max-width:46ch;padding-bottom:6px}
.fi-v2 .sec-head .sh-copy .ln{font-family:"IBM Plex Mono",monospace;font-size:.74rem;letter-spacing:.12em;color:var(--ink);display:inline-block;margin-bottom:12px}

/* ─────────── WORLDS ─────────── */
.fi-v2 .worlds{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid var(--line)}
.fi-v2 .world{padding:52px 56px 52px 0}
.fi-v2 .world:last-child{padding:52px 0 52px 56px;border-left:1px solid var(--line)}
.fi-v2 .world .tag{font-family:"IBM Plex Mono",monospace;font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;color:var(--faint)}
.fi-v2 .world h3{font-family:"Archivo Black",sans-serif;text-transform:uppercase;font-size:1.7rem;letter-spacing:-.01em;margin:16px 0 16px}
.fi-v2 .world p{color:var(--muted);max-width:38ch}
.fi-v2 .world dl{margin-top:32px;border-top:1px solid var(--line-2)}
.fi-v2 .world dl div{display:grid;grid-template-columns:120px 1fr;gap:20px;padding:14px 0;border-bottom:1px solid var(--line-2)}
.fi-v2 .world dt{font-family:"IBM Plex Mono",monospace;font-size:.66rem;letter-spacing:.12em;text-transform:uppercase;color:var(--faint);padding-top:2px}
.fi-v2 .world dd{font-size:.95rem;color:var(--ink)}

/* ─────────── CHANNELS ─────────── */
.fi-v2 .channels{border-top:1px solid var(--line);border-bottom:1px solid var(--line);overflow:hidden}
.fi-v2 .ch-row{display:flex;align-items:center;white-space:nowrap}
.fi-v2 .ch-item{display:inline-flex;align-items:center;font-family:"Archivo Black",sans-serif;text-transform:uppercase;font-size:1.9rem;letter-spacing:-.01em;color:var(--ink);padding:28px 0}
.fi-v2 .ch-item .x{font-family:"IBM Plex Mono";font-size:.85rem;color:var(--azul);margin:0 40px;font-weight:500}

/* ─────────── STEPS grid + trace ─────────── */
.fi-v2 .steps{border-top:1px solid var(--line)}
.fi-v2 .step{display:grid;grid-template-columns:130px 1fr auto;gap:50px;align-items:start;padding:44px 0;border-bottom:1px solid var(--line);transition:background .5s var(--ease)}
.fi-v2 .step:hover{background:rgba(220,212,189,.4)}
.fi-v2 .step .st-no{font-family:"Archivo Black",sans-serif;font-size:2.8rem;line-height:.85;color:var(--ink);letter-spacing:-.02em}
.fi-v2 .step .st-body h3{font-family:"Archivo Black",sans-serif;text-transform:uppercase;font-size:1.4rem;margin-bottom:10px;letter-spacing:-.01em}
.fi-v2 .step .st-body p{color:var(--muted);max-width:52ch;font-size:1.01rem}
.fi-v2 .step .st-meta{font-family:"IBM Plex Mono",monospace;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);text-align:right;max-width:20ch;line-height:1.7}

/* ─────────── QUOTE ─────────── */
.fi-v2 .quote{text-align:center;padding:clamp(84px,10vw,140px) 0}
.fi-v2 .quote blockquote{font-family:"Instrument Serif",serif;font-style:italic;font-size:clamp(2rem,5vw,3.6rem);line-height:1.12;max-width:20ch;margin:0 auto;color:var(--ink)}
.fi-v2 .quote .attr{margin-top:30px;font-family:"IBM Plex Mono",monospace;font-size:.7rem;letter-spacing:.16em;text-transform:uppercase;color:var(--faint)}

/* ─────────── POSITIONING ─────────── */
.fi-v2 .posit-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
.fi-v2 .pcard{border:1px solid var(--line);background:var(--arena);padding:32px 28px;min-height:228px;display:flex;flex-direction:column;transition:transform .5s var(--ease)}
.fi-v2 .pcard:hover{transform:translateY(-4px)}
.fi-v2 .pcard .ptag{font-family:"IBM Plex Mono",monospace;font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:8px}
.fi-v2 .pcard h3{font-family:"Archivo Black",sans-serif;text-transform:uppercase;font-size:1.25rem;margin:auto 0 12px;letter-spacing:-.01em;line-height:1.05}
.fi-v2 .pcard p{color:var(--muted);font-size:.93rem}
.fi-v2 .pcard.yes{background:var(--negro);border-color:var(--negro);color:var(--beige);grid-column:1/-1;flex-direction:row;align-items:center;gap:48px;min-height:auto;padding:40px 44px}
.fi-v2 .pcard.yes .yes-l{flex-shrink:0;max-width:30ch}
.fi-v2 .pcard.yes .ptag{color:var(--lime)}
.fi-v2 .pcard.yes h3{margin:14px 0 0;font-size:1.9rem}
.fi-v2 .pcard.yes p{color:rgba(236,232,223,.72);font-size:1.05rem;max-width:46ch}

/* ─────────── DARK BAND ─────────── */
.fi-v2 .band{background:var(--negro);color:var(--beige);text-align:center;position:relative;overflow:hidden}
.fi-v2 .band::before{content:"";position:absolute;inset:0;opacity:.05;mix-blend-mode:screen;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E")}
.fi-v2 .band .wrap{position:relative}
.fi-v2 .band blockquote{font-family:"Instrument Serif",serif;font-style:italic;font-size:clamp(1.7rem,3.6vw,2.9rem);line-height:1.22;max-width:22ch;margin:0 auto;color:#f4f1ea}
.fi-v2 .band .pulse{margin-top:50px;display:inline-flex;align-items:center;gap:11px;font-family:"IBM Plex Mono",monospace;font-size:.68rem;letter-spacing:.16em;text-transform:uppercase;color:rgba(236,232,223,.5)}
.fi-v2 .band .pulse .ld{background:var(--lime)}

/* ─────────── USE CASES ─────────── */
.fi-v2 .usecases-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
.fi-v2 .uc{border:1px solid var(--line);background:var(--arena);padding:30px 26px;display:flex;flex-direction:column;transition:transform .5s var(--ease)}
.fi-v2 .uc:hover{transform:translateY(-4px)}
.fi-v2 .uc .uc-no{font-family:"IBM Plex Mono",monospace;font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;color:var(--faint)}
.fi-v2 .uc h3{font-family:"Archivo Black",sans-serif;text-transform:uppercase;font-size:1.15rem;margin:13px 0 14px;letter-spacing:-.01em;line-height:1.05}
.fi-v2 .uc .uc-lead{font-weight:600;color:var(--ink);font-size:.96rem;margin-bottom:10px;line-height:1.35}
.fi-v2 .uc p{color:var(--muted);font-size:.9rem;flex:1}
.fi-v2 .uc .entrega{margin-top:20px;padding-top:14px;border-top:1px solid var(--line-2);font-family:"IBM Plex Mono",monospace;font-size:.64rem;letter-spacing:.04em;color:var(--azul);line-height:1.6}
.fi-v2 .uc .entrega b{color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.1em;display:block;margin-bottom:4px}
.fi-v2 .uc-cta{margin-top:46px;text-align:center}

/* ─────────── BRAND X-RAY ─────────── */
.fi-v2 .xray-grid{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid var(--line);border-left:1px solid var(--line)}
.fi-v2 .xr{padding:38px 32px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);transition:background .5s var(--ease)}
.fi-v2 .xr:hover{background:rgba(220,212,189,.4)}
.fi-v2 .xr .xn{font-family:"IBM Plex Mono",monospace;font-size:.68rem;letter-spacing:.12em;color:var(--faint)}
.fi-v2 .xr h3{font-family:"Archivo Black",sans-serif;text-transform:uppercase;font-size:1.2rem;margin:14px 0 10px;letter-spacing:-.01em}
.fi-v2 .xr p{color:var(--muted);font-size:.92rem;max-width:34ch}
.fi-v2 .xr .out{margin-top:20px;font-family:"IBM Plex Mono",monospace;font-size:.68rem;letter-spacing:.04em;color:var(--azul);display:flex;align-items:center;gap:8px}
.fi-v2 .xr .out::before{content:"→";color:var(--azul)}

/* ─────────── FINAL CTA ─────────── */
.fi-v2 .final{text-align:center}
.fi-v2 .final h2{font-size:clamp(2.1rem,5.4vw,4.2rem);max-width:18ch;margin:24px auto 0}
.fi-v2 .final .fcopy{color:var(--muted);max-width:48ch;margin:26px auto 0;font-size:1.12rem;line-height:1.5}
.fi-v2 .final-cta{display:flex;gap:22px;justify-content:center;align-items:center;margin-top:42px;flex-wrap:wrap}
.fi-v2 .final .reassure{margin-top:32px;font-family:"IBM Plex Mono",monospace;font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;color:var(--faint)}

/* ─────────── REVEAL ─────────── */
.fi-v2 .reveal{opacity:0;transform:translateY(26px);transition:opacity .9s var(--ease),transform .9s var(--ease)}
.fi-v2 .reveal.in{opacity:1;transform:none}

/* ─────────── SEAMLESS INFINITE MARQUEE ─────────── */
.fi-v2 .fi-mq{overflow:hidden;position:relative;width:100%}
.fi-v2 .fi-mq__track{display:flex;flex-wrap:nowrap;width:max-content;will-change:transform;animation:fi-mq-scroll var(--fi-mq-dur,40s) linear infinite}
.fi-v2 .fi-mq:hover .fi-mq__track{animation-play-state:paused}
@keyframes fi-mq-scroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}

/* ─────────── TABLET (≤1000) ─────────── */
@media (max-width:1000px){
  .fi-v2 .hero-grid{grid-template-columns:1fr;gap:50px}
  .fi-v2 .scope{max-width:340px}
  .fi-v2 .sec-head{grid-template-columns:1fr;gap:22px;align-items:start}
  .fi-v2 .feed-head{flex-direction:column;align-items:flex-start;gap:14px}
  .fi-v2 .metrics-in{grid-template-columns:repeat(2,1fr)}
  .fi-v2 .metric:nth-child(3){padding-left:0;border-left:none}
  .fi-v2 .metric{border-top:1px solid var(--line)}
  .fi-v2 .metric:nth-child(-n+2){border-top:none}
  .fi-v2 .posit-grid, .fi-v2 .usecases-grid{grid-template-columns:repeat(2,1fr)}
  .fi-v2 .xray-grid{grid-template-columns:repeat(2,1fr)}
  .fi-v2 .pcard.yes{flex-direction:column;align-items:flex-start;gap:18px}
}

/* ─────────── MOBILE — section-specific (≤760) ─────────── */
@media (max-width:760px){
  .fi-v2{font-size:16px}
  .fi-v2 section{padding:62px 0}
  .fi-v2 .wrap{padding-left:20px;padding-right:20px}
  .fi-v2 .kick{margin-bottom:28px;padding-bottom:14px}
  .fi-v2 .sec-head{margin-bottom:38px;gap:14px}
  .fi-v2 .sec-head h2{font-size:clamp(1.7rem,8vw,2.3rem)}
  .fi-v2 .sec-head .sh-copy{font-size:1rem}

  .fi-v2 header.hero{padding:54px 0 0}
  .fi-v2 .hero-grid{gap:36px}
  .fi-v2 .hero h1{font-size:clamp(2.2rem,11vw,3.1rem);max-width:16ch}
  .fi-v2 .hero .lead{font-size:1.04rem;margin-top:22px;max-width:none}
  .fi-v2 .hero-cta{flex-direction:column;align-items:stretch;gap:14px;margin-top:30px}
  .fi-v2 .hero-cta .btn{width:100%;justify-content:center}
  .fi-v2 .hero-cta .tlink{justify-content:center}
  .fi-v2 .radar{padding:18px 16px 16px;border-radius:12px}
  .fi-v2 .radar-stage{padding:14px 2px 10px}
  .fi-v2 .scope{max-width:min(78vw,300px)}
  .fi-v2 .blip b{display:none}
  .fi-v2 .rim{font-size:.5rem}
  .fi-v2 .radar-read{flex-wrap:wrap;gap:8px}

  .fi-v2 .sigband{margin-top:56px}
  .fi-v2 .sigband .sb-head{padding:11px 20px}
  .fi-v2 .sigband .sb-head .sub{display:none}
  .fi-v2 .ticker .tk{font-size:.72rem;padding:13px 0}
  .fi-v2 .ticker .sep{margin:0 20px}

  .fi-v2 .feed-head{margin-bottom:26px;padding-bottom:16px}
  .fi-v2 .feed-head h2{font-size:clamp(1.5rem,7vw,2rem)}

  .fi-v2 .metrics-in{grid-template-columns:1fr}
  .fi-v2 .metric{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:18px;padding:20px 0;border-left:none;border-top:1px solid var(--line)}
  .fi-v2 .metric:first-child{border-top:none;padding-left:0}
  .fi-v2 .metric:nth-child(2){border-top:1px solid var(--line)}
  .fi-v2 .metric .num{font-size:2.5rem}
  .fi-v2 .metric .num.word{font-size:1.5rem}
  .fi-v2 .metric .cap.desc{margin-top:0;max-width:none;font-size:.78rem}

  .fi-v2 .quote{padding:60px 0}
  .fi-v2 .quote blockquote{font-size:clamp(1.9rem,8vw,2.6rem)}

  .fi-v2 .worlds{grid-template-columns:1fr}
  .fi-v2 .world{padding:38px 0;border-top:1px solid var(--line)}
  .fi-v2 .world:first-child{border-top:none}
  .fi-v2 .world:last-child{padding:38px 0;border-left:none}
  .fi-v2 .world h3{font-size:1.5rem}
  .fi-v2 .world dl div{grid-template-columns:1fr;gap:4px;padding:12px 0}

  .fi-v2 .ch-item{font-size:1.35rem;padding:22px 0}
  .fi-v2 .ch-item .x{margin:0 22px}

  .fi-v2 .step{grid-template-columns:1fr;gap:10px;padding:30px 0}
  .fi-v2 .step .st-no{font-size:2.1rem}
  .fi-v2 .step .st-body h3{font-size:1.25rem}
  .fi-v2 .step .st-body p{font-size:.98rem;max-width:none}
  .fi-v2 .step .st-meta{text-align:left;max-width:none;font-size:.62rem;opacity:.85}

  .fi-v2 .demo{margin-top:34px}
  .fi-v2 .demo-bar{padding:12px 16px}
  .fi-v2 .demo-bar .db-title{font-size:.6rem;margin-left:8px}
  .fi-v2 .demo-bar .db-right{gap:12px;font-size:.58rem}
  .fi-v2 .pipe{padding:22px 14px 4px}
  .fi-v2 .pipe-track{left:calc(10% + 14px);right:calc(10% + 14px)}
  .fi-v2 .pnode .pn-dot{width:12px;height:12px}
  .fi-v2 .pnode .pn-lbl{font-size:.5rem}
  .fi-v2 .demo-rows{padding:8px 16px 2px}
  .fi-v2 .drow{grid-template-columns:1fr auto;grid-template-areas:"k stat" "v v";gap:6px 12px;font-size:.8rem}
  .fi-v2 .dr-k{grid-area:k}
  .fi-v2 .dr-stat{grid-area:stat;justify-self:end}
  .fi-v2 .dr-v{grid-area:v}
  .fi-v2 .demo-foot{flex-direction:column;align-items:flex-start;gap:6px;padding:14px 16px}

  .fi-v2 .posit-grid{grid-template-columns:1fr;gap:14px}
  .fi-v2 .pcard{min-height:0;padding:26px 22px}
  .fi-v2 .pcard.yes{flex-direction:column;align-items:flex-start;gap:16px;padding:30px 24px}
  .fi-v2 .pcard.yes h3{font-size:1.6rem}
  .fi-v2 .pcard.yes p{font-size:1rem}

  .fi-v2 .band blockquote{font-size:clamp(1.6rem,7vw,2.2rem)}
  .fi-v2 .band .pulse{margin-top:36px}

  .fi-v2 .usecases-grid{grid-template-columns:1fr;gap:14px}
  .fi-v2 .uc{padding:28px 24px}

  .fi-v2 .xray-grid{grid-template-columns:1fr}
  .fi-v2 .xr{border-left:none;padding:30px 24px}

  .fi-v2 .final h2{font-size:clamp(2rem,9vw,3rem)}
  .fi-v2 .final .fcopy{font-size:1.04rem}
  .fi-v2 .final-cta{flex-direction:column;align-items:stretch;gap:14px}
  .fi-v2 .final-cta .btn{width:100%;justify-content:center}
  .fi-v2 .final-cta .tlink{justify-content:center}
}
@media (max-width:480px){
  .fi-v2 .wrap{padding-left:18px;padding-right:18px}
  .fi-v2 .hero h1{font-size:clamp(2rem,12.5vw,2.6rem)}
  .fi-v2 .scope{max-width:min(84vw,260px)}
  .fi-v2 .metric .num{font-size:2.15rem}
}
@media (prefers-reduced-motion:reduce){
  .fi-v2 *{animation:none!important;transition:none!important}
  .fi-v2 .reveal{opacity:1;transform:none}
}
</style>

<div class="<?php echo $wrap_class; ?>" id="fi-home-v2">
<!-- HERO -->
<header class="hero" id="fi-top" aria-labelledby="fi-hero-h">
  <div class="wrap hero-grid">
    <div class="hero-text">
      <div class="kick reveal" style="margin-bottom:30px">
        <span class="eyebrow"><span class="sq"></span> Sistema de inteligencia cultural</span>
        <span class="archid">F.I/UNIV/01</span>
      </div>
      <h1 class="display reveal" id="fi-hero-h">Convierte señales públicas en <em>decisiones</em> de marketing.</h1>
      <p class="lead reveal">Future Island™ captura señales de búsqueda, social, competidores, reviews e IA, las interpreta en insights y las transforma en <strong>briefs, ángulos y contenidos</strong> listos para actuar.</p>
      <div class="hero-cta reveal">
        <a href="https://future-island.club/demo/" class="btn btn-dark">Solicitar Brand X-Ray <span class="arw">→</span></a>
        <a href="#ciclo" class="tlink">Ver cómo funciona <span class="arw">↓</span></a>
      </div>
      <p class="micro reveal">Demo editorial de 30 min · Aplicada a tu marca · Sin compromiso</p>
    </div>

    <!-- RADAR -->
    <div class="radar reveal" aria-hidden="true">
      <div class="radar-top">
        <span class="rt-id">F.I / LIVE SIGNAL ROOM</span>
        <span class="rt-live"><span class="ld"></span> LIVE · <span id="clock">21:04</span></span>
      </div>
      <div class="radar-stage">
        <div class="scope">
          <div class="ring r1"></div><div class="ring r2"></div><div class="ring r3"></div><div class="ring r4"></div>
          <div class="cross h"></div><div class="cross v"></div>
          <div class="sweep"></div>
          <div class="core"></div>
          <span class="blip lime" style="top:30%;left:63%;animation-delay:.2s"><b>SOC · quiet luxury</b></span>
          <span class="blip azul" style="top:58%;left:71%;animation-delay:1.1s"></span>
          <span class="blip rojo" style="top:70%;left:41%;animation-delay:2.4s"><b>NEW · creator economy</b></span>
          <span class="blip lime" style="top:43%;left:29%;animation-delay:1.7s"></span>
          <span class="blip azul" style="top:23%;left:46%;animation-delay:.7s"><b>AI · brand codes</b></span>
          <span class="blip lime" style="top:62%;left:54%;animation-delay:3.2s"></span>
          <span class="rim" style="top:4%;left:50%">AI</span>
          <span class="rim" style="top:26%;left:92%">SOC</span>
          <span class="rim" style="top:72%;left:94%">SEA</span>
          <span class="rim" style="top:96%;left:50%">ADS</span>
          <span class="rim" style="top:72%;left:7%">WEB</span>
          <span class="rim" style="top:26%;left:8%">REV</span>
        </div>
      </div>
      <div class="radar-read">
        <div class="rr-line" id="rrLine"><span class="ch">SOC</span>"quiet luxury" +340% LATAM</div>
        <div class="rr-meta"><span id="rrNum">señal → brief</span><span class="up">↑ activo</span></div>
      </div>
    </div>
  </div>

  <!-- SIGNAL BAND / ticker (seamless infinite) -->
  <div class="sigband reveal">
    <div class="sb-head">
      <span class="ld"></span><span class="lbl">Signal Band · Live</span>
      <span class="sub">6 fuentes públicas · lectura en vivo</span>
    </div>
    <div class="fi-mq" data-fi-mq-speed="50">
      <div class="fi-mq__track ticker">
        <span class="tk"><span class="ch">Social</span> "quiet luxury" acelera en LATAM +340%</span><span class="sep">/</span>
        <span class="tk"><span class="ch">Search</span> sube "identidad cultural post-digital"</span><span class="sep">/</span>
        <span class="tk"><span class="ch">Ads</span> nostalgia futurista en creatividad de lujo</span><span class="sep">/</span>
        <span class="tk"><span class="ch">Reviews</span> microcultura por encima del minimalismo</span><span class="sep">/</span>
        <span class="tk"><span class="ch">AI</span> prompts sobre códigos de marca al alza</span><span class="sep">/</span>
        <span class="tk"><span class="ch">Web</span> marcas ajustan tono hacia comunidad</span><span class="sep">/</span>
      </div>
    </div>
  </div>
</header>

<!-- ════ LIVE FEED · VIDEO (autoplay + loop) ════ -->
<section class="feed" id="feed" aria-labelledby="fi-feed-h">
  <div class="wrap">
    <div class="feed-head">
      <div>
        <span class="eyebrow"><span class="sq"></span> Feed en vivo</span>
        <h2 id="fi-feed-h">El sistema, en <em>movimiento</em>.</h2>
      </div>
    </div>
    <div class="screen reveal">
      <span class="crn tl"></span><span class="crn tr"></span><span class="crn bl"></span><span class="crn br"></span>
      <span class="scan" aria-hidden="true"></span>
      <div class="ratio">
        <iframe
          src="https://player.vimeo.com/video/1198372904?h=c84ca79e2f&background=1&autoplay=1&loop=1&muted=1&autopause=0&playsinline=1"
          title="Future Island"
          frameborder="0"
          referrerpolicy="strict-origin-when-cross-origin"
          allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media; web-share"
          allowfullscreen></iframe>
      </div>
    </div>
  </div>
</section>

<!-- METRICS -->
<div class="metrics">
  <div class="wrap metrics-in">
    <div class="metric reveal"><div class="num">6</div><div class="cap desc">Fuentes públicas — search · social · ads · reviews · web · IA</div></div>
    <div class="metric reveal"><div class="num">1</div><div class="cap desc">Flujo operativo — source → signal → insight → brief → output</div></div>
    <div class="metric reveal"><div class="num word">Trazable</div><div class="cap desc">Cada recomendación conserva su señal de origen</div></div>
    <div class="metric reveal"><div class="num word">Memoria</div><div class="cap desc">Cada run mejora el análisis del siguiente</div></div>
  </div>
</div>

<!-- MANIFESTO -->
<section class="quote" aria-labelledby="fi-quote-h">
  <div class="wrap">
    <blockquote class="reveal" id="fi-quote-h">No necesitas otro dashboard. Necesitas saber qué hacer después.</blockquote>
    <div class="attr reveal">F.I. Manifesto · §02</div>
  </div>
</section>

<!-- SISTEMA — DOS CAPAS -->
<section id="sistema" aria-labelledby="fi-sistema-h">
  <div class="wrap">
    <div class="kick reveal">
      <span class="eyebrow"><span class="sq"></span> Por qué existe Future Island</span>
      <span class="archid">F.I/SYS/01</span>
    </div>
    <div class="sec-head">
      <h2 class="display reveal" id="fi-sistema-h">El mercado se mueve en <em>dos</em> capas.</h2>
      <p class="sh-copy reveal">Tu equipo ya tiene datos: SEO, social, competidores, campañas y reportes. Lo que falta es una lectura común: qué está cambiando, por qué importa y qué acción merece ejecutar.</p>
    </div>
    <div class="worlds">
      <div class="world reveal">
        <span class="tag">Capa 01 — Cultura</span>
        <h3>Cultura</h3>
        <p>Lo que la audiencia dice, imita, pregunta, guarda, rechaza y convierte en tendencia. Insight humano, no algorítmico.</p>
        <dl>
          <div><dt>Cobertura</dt><dd>Arte, moda, arquitectura, gastronomía, música, cine</dd></div>
          <div><dt>Frecuencia</dt><dd>Briefings semanales + alertas en tiempo real</dd></div>
          <div><dt>Formato</dt><dd>Reports, señales visuales, mapas de tendencias</dd></div>
        </dl>
      </div>
      <div class="world reveal">
        <span class="tag"><span class="dotlive"></span>Capa 02 — Señal</span>
        <h3>Señal</h3>
        <p>Lo que aparece en búsqueda, social, competidores, ads, reviews, comunidades e interfaces de IA. El dato, ya capturado.</p>
        <dl>
          <div><dt>Canales</dt><dd>Social · Search · Ads · Reviews · Web · IA</dd></div>
          <div><dt>Actualización</dt><dd>Cada 2 horas, 24/7, 365 días al año</dd></div>
          <div><dt>Entrega</dt><dd>Briefs, ángulos, outputs y alertas accionables</dd></div>
        </dl>
      </div>
    </div>
  </div>
</section>

<!-- CHANNELS (seamless infinite) -->
<div class="channels reveal" aria-hidden="true">
  <div class="fi-mq" data-fi-mq-speed="66">
    <div class="fi-mq__track ch-row">
      <span class="ch-item">Social<span class="x">+</span></span>
      <span class="ch-item">Search<span class="x">+</span></span>
      <span class="ch-item">Ads<span class="x">+</span></span>
      <span class="ch-item">Reviews<span class="x">+</span></span>
      <span class="ch-item">Web<span class="x">+</span></span>
      <span class="ch-item">AI Interfaces<span class="x">+</span></span>
    </div>
  </div>
</div>

<!-- CICLO — 5 STEPS + OUTPUT TRACE -->
<section id="ciclo" aria-labelledby="fi-ciclo-h">
  <div class="wrap">
    <div class="kick reveal">
      <span class="eyebrow"><span class="sq"></span> Cómo funciona</span>
      <span class="archid">F.I/SYS/02</span>
    </div>
    <div class="sec-head">
      <h2 class="display reveal" id="fi-ciclo-h">De señal pública a <em>contenido</em> defendible.</h2>
      <p class="sh-copy reveal"><span class="ln">SOURCE → SIGNAL → INSIGHT → BRIEF → OUTPUT</span><br>Cada input conserva su evidencia. Cada insight se convierte en una decisión. No necesitas otro dashboard: necesitas saber qué hacer después.</p>
    </div>
    <div class="steps">
      <div class="step reveal">
        <div class="st-no">01</div>
        <div class="st-body"><h3>Source</h3><p>Una URL, un TikTok, un competidor, una búsqueda, una review, una nota o una campaña: cualquier señal pública entra al sistema.</p></div>
        <div class="st-meta">Inputs públicos<br>Material crudo</div>
      </div>
      <div class="step reveal">
        <div class="st-no">02</div>
        <div class="st-body"><h3>Signal</h3><p>Normalizamos lo relevante y descartamos el ruido: qué se repite, qué acelera y qué empieza a cambiar en tu categoría.</p></div>
        <div class="st-meta">Normalización<br>Filtrado de ruido</div>
      </div>
      <div class="step reveal">
        <div class="st-no">03</div>
        <div class="st-body"><h3>Insight</h3><p>Interpretamos el patrón con criterio cultural: oportunidad, riesgo, tensión o saturación. Lectura humana, no solo algorítmica.</p></div>
        <div class="st-meta">Criterio cultural<br>Edición humana</div>
      </div>
      <div class="step reveal">
        <div class="st-no">04</div>
        <div class="st-body"><h3>Brief</h3><p>Convertimos la lectura en una dirección accionable, con la señal de origen siempre trazable detrás de cada recomendación.</p></div>
        <div class="st-meta">Dirección clara<br>Evidencia trazable</div>
      </div>
      <div class="step reveal">
        <div class="st-no">05</div>
        <div class="st-body"><h3>Output</h3><p>Producimos el material listo para usar: hook, caption, script, ángulo, calendario o recomendación de siguiente campaña.</p></div>
        <div class="st-meta">Hooks · Captions<br>Scripts · Plan</div>
      </div>
    </div>

    <!-- LIVE DASHBOARD DEMO (animated, loops) -->
    <div class="demo reveal" id="fiDemo" aria-label="Demo: una señal trazada de principio a fin">
      <span class="dscan" aria-hidden="true"></span>
      <div class="demo-bar">
        <div class="db-left">
          <span class="db-dot lm"></span><span class="db-dot"></span><span class="db-dot"></span>
          <span class="db-title">F.I · SIGNAL TRACE</span>
        </div>
        <div class="db-right">
          <span class="db-live"><span class="ld"></span> <span id="demoLive">PROCESANDO</span></span>
          <span class="db-time" id="demoTime">00:00</span>
        </div>
      </div>

      <div class="pipe">
        <div class="pipe-track"><div class="pipe-fill" id="pipeFill"></div></div>
        <div class="pipe-nodes">
          <div class="pnode"><span class="pn-dot"></span><span class="pn-lbl">Source</span></div>
          <div class="pnode"><span class="pn-dot"></span><span class="pn-lbl">Signal</span></div>
          <div class="pnode"><span class="pn-dot"></span><span class="pn-lbl">Insight</span></div>
          <div class="pnode"><span class="pn-dot"></span><span class="pn-lbl">Brief</span></div>
          <div class="pnode"><span class="pn-dot"></span><span class="pn-lbl">Output</span></div>
        </div>
      </div>

      <div class="demo-rows">
        <div class="drow"><span class="dr-k">Source</span><span class="dr-v"><span class="hl">SOC</span> "quiet luxury" +340% en LATAM (social + search + reviews)</span><span class="dr-stat"><span class="spin"></span><span class="chk">✓</span></span></div>
        <div class="drow"><span class="dr-k">Signal</span><span class="dr-v">Patrón confirmado en 3 canales · ruido descartado · señal acelerando</span><span class="dr-stat"><span class="spin"></span><span class="chk">✓</span></span></div>
        <div class="drow"><span class="dr-k">Insight</span><span class="dr-v">El lujo silencioso se satura; abre hueco un "lujo con raíz" — estatus por pertenencia cultural, no por discreción importada.</span><span class="dr-stat"><span class="spin"></span><span class="chk">✓</span></span></div>
        <div class="drow"><span class="dr-k">Brief</span><span class="dr-v">Reposicionar el tono hacia identidad local y comunidad, no hacia minimalismo aspiracional. Evitar el código ya saturado.</span><span class="dr-stat"><span class="spin"></span><span class="chk">✓</span></span></div>
        <div class="drow out"><span class="dr-k">Output</span><span class="dr-v">3 hooks listos · 1 ángulo de campaña · recomendación del próximo test A/B</span><span class="dr-stat"><span class="spin"></span><span class="chk">✓</span></span></div>
      </div>

      <div class="demo-foot">
        <span class="df-prog"><b id="demoPct">0%</b> procesado</span>
        <span class="df-state" id="demoState">Leyendo señal pública…</span>
      </div>
    </div>
  </div>
</section>

<!-- POSITIONING -->
<section id="posicionamiento" aria-labelledby="fi-posit-h">
  <div class="wrap">
    <div class="kick reveal">
      <span class="eyebrow"><span class="sq"></span> Posicionamiento</span>
      <span class="archid">F.I/SYS/03</span>
    </div>
    <div class="sec-head">
      <h2 class="display reveal" id="fi-posit-h">No es otro <em>dashboard</em>.</h2>
      <p class="sh-copy reveal">No es social listening. No es un dashboard SEO. No es un AI writer. Es el workflow que convierte señales públicas en decisiones de marketing.</p>
    </div>
    <div class="posit-grid">
      <div class="pcard reveal"><span class="ptag">✕ No es</span><h3>Social Listening</h3><p>No solo monitorea menciones. Detecta señales que pueden cambiar una decisión de marca.</p></div>
      <div class="pcard reveal"><span class="ptag">✕ No es</span><h3>Google Trends</h3><p>No muestra búsquedas sueltas. Cruza señales de búsqueda, social, competencia, reviews e IA.</p></div>
      <div class="pcard reveal"><span class="ptag">✕ No es</span><h3>Consultoría tradicional</h3><p>No depende de semanas de análisis manual. Convierte el research en briefs reutilizables.</p></div>
      <div class="pcard reveal"><span class="ptag">✕ No es</span><h3>AI writer</h3><p>No empieza en una página en blanco. Genera outputs desde evidencia real de mercado.</p></div>
      <div class="pcard yes reveal">
        <div class="yes-l"><span class="ptag">✓ Sí es</span><h3>Future Island</h3></div>
        <p>Una capa de interpretación entre los datos públicos, la estrategia y la producción de contenido. El paso que convierte "tenemos datos" en "sabemos qué hacer".</p>
      </div>
    </div>
  </div>
</section>

<!-- DARK BAND -->
<section class="band" aria-labelledby="fi-band-h">
  <div class="wrap">
    <blockquote class="reveal" id="fi-band-h">El presente es un <span style="color:var(--lime)">archivo</span>. Future Island™ es la herramienta para leerlo — y escribir el siguiente capítulo antes que nadie.</blockquote>
    <div class="pulse reveal"><span class="ld"></span> Pulso cultural · captura en vivo</div>
  </div>
</section>

<!-- USE CASES -->
<section id="casos" aria-labelledby="fi-casos-h">
  <div class="wrap">
    <div class="kick reveal">
      <span class="eyebrow"><span class="sq"></span> Casos de uso</span>
      <span class="archid">F.I/SYS/05</span>
    </div>
    <div class="sec-head">
      <h2 class="display reveal" id="fi-casos-h">Un sistema. Cuatro formas de convertir señales en <em>dirección</em>.</h2>
      <p class="sh-copy reveal">Cada equipo entra con una pregunta distinta. Future Island la convierte en una lectura, un brief y un output accionable.</p>
    </div>
    <div class="usecases-grid">
      <div class="uc reveal">
        <span class="uc-no">01 · Agencia</span>
        <h3>Propuestas defendibles</h3>
        <p class="uc-lead">De investigación dispersa a propuesta que el cliente aprueba.</p>
        <p>Convierte señales de categoría, cultura y competencia en territorios creativos, benchmarks y briefs sólidos.</p>
        <div class="entrega"><b>Entrega</b>propuesta · campaña · insight · benchmark</div>
      </div>
      <div class="uc reveal">
        <span class="uc-no">02 · Contenido</span>
        <h3>Contenido con razón de existir</h3>
        <p class="uc-lead">De calendario lleno a publicar con criterio.</p>
        <p>Detecta qué preguntas, formatos, hooks y conversaciones mueven a tu audiencia antes de decidir qué publicar.</p>
        <div class="entrega"><b>Entrega</b>hooks · captions · scripts · calendario</div>
      </div>
      <div class="uc reveal">
        <span class="uc-no">03 · Performance</span>
        <h3>Probar con señales</h3>
        <p class="uc-lead">De testear por intuición a hipótesis con evidencia.</p>
        <p>Encuentra ángulos, claims, tensiones y mensajes repetidos en el mercado para alimentar nuevas pruebas creativas.</p>
        <div class="entrega"><b>Entrega</b>A/B tests · copies · hooks · saturación</div>
      </div>
      <div class="uc reveal">
        <span class="uc-no">04 · CMO / Estrategia</span>
        <h3>Prioridades claras</h3>
        <p class="uc-lead">De ruido de mercado a decisiones de marca.</p>
        <p>Lee señales públicas para decidir qué merece atención, qué amenaza la categoría y dónde puede moverse la marca.</p>
        <div class="entrega"><b>Entrega</b>mapa de mercado · oportunidades · riesgos · brief ejecutivo</div>
      </div>
    </div>
    <div class="uc-cta reveal">
      <a href="https://future-island.club/demo/" class="btn btn-dark">Ver Future Island aplicado a mi equipo <span class="arw">→</span></a>
    </div>
  </div>
</section>

<!-- BRAND X-RAY -->
<section id="xray" aria-labelledby="fi-xray-h">
  <div class="wrap">
    <div class="kick reveal">
      <span class="eyebrow"><span class="sq"></span> Piloto estratégico · 30 días</span>
      <span class="archid">F.I/XRAY/01</span>
    </div>
    <div class="sec-head">
      <h2 class="display reveal" id="fi-xray-h">Brand <em>X-Ray</em>™</h2>
      <p class="sh-copy reveal">En una sesión de 30 minutos revisamos tu marca, categoría y competidores, y te mostramos cómo Future Island convierte señales públicas en insights, briefs y outputs accionables — con todo lo que te llevas al terminar.</p>
    </div>
    <div class="xray-grid">
      <div class="xr reveal"><span class="xn">01 · Diagnóstico</span><h3>Mapa Cultural</h3><p>Dónde se ubica tu marca hoy. Fortalezas, tensiones y oportunidades.</p><div class="out">Informe PDF + presentación</div></div>
      <div class="xr reveal"><span class="xn">02 · Señales</span><h3>Radar de Tendencias</h3><p>Las 20 señales más relevantes para tu categoría, con velocidad e impacto.</p><div class="out">Dashboard en vivo · 30 días</div></div>
      <div class="xr reveal"><span class="xn">03 · Estrategia</span><h3>Hoja de Ruta</h3><p>Recomendaciones accionables para los próximos 3, 6 y 12 meses.</p><div class="out">Workshop 2h + deck</div></div>
      <div class="xr reveal"><span class="xn">04 · Competencia</span><h3>Benchmark Cultural</h3><p>3 competidores directos: qué capturan, qué ignoran y dónde hay territorio libre.</p><div class="out">Comparativa ejecutiva</div></div>
      <div class="xr reveal"><span class="xn">05 · Audiencias</span><h3>ICP Culturales</h3><p>El ADN cultural de tu audiencia ideal, más allá de la demografía.</p><div class="out">3 fichas de perfil</div></div>
      <div class="xr reveal"><span class="xn">06 · Contenido</span><h3>Calendario Cultural</h3><p>Plan editorial de 90 días basado en momentos culturales reales.</p><div class="out">Calendario + briefs</div></div>
    </div>
    <div class="reveal" style="margin-top:46px;display:flex;align-items:center;gap:24px;flex-wrap:wrap">
      <a href="https://future-island.club/demo/" class="btn btn-dark">Solicitar Brand X-Ray <span class="arw">→</span></a>
      <span class="mono" style="color:var(--faint)">Propuesta bajo presupuesto · Q2 2026</span>
    </div>
  </div>
</section>

<!-- FINAL CTA -->
<section id="demo" class="final" aria-labelledby="fi-final-h">
  <div class="wrap">
    <span class="eyebrow reveal" style="justify-content:center"><span class="sq"></span> Brand X-Ray · 30 minutos</span>
    <h2 class="display reveal" id="fi-final-h" style="margin-top:22px">Convierte tu próxima señal de mercado en un <em>brief</em> accionable.</h2>
    <p class="fcopy reveal">Solicita un Brand X-Ray de 30 minutos y descubre cómo Future Island lee tu categoría, interpreta oportunidades y produce dirección lista para tu equipo.</p>
    <div class="final-cta reveal">
      <a href="https://future-island.club/demo/" class="btn btn-rojo">Solicitar Brand X-Ray <span class="arw">→</span></a>
      <a href="#casos" class="tlink">Ver casos de uso <span class="arw">→</span></a>
    </div>
    <div class="reassure reveal">30 min · Aplicado a tu marca · Sin compromiso</div>
  </div>
</section>
</div>

<script id="fi-home-v2-script">
(function(){
  'use strict';
  var root = document.getElementById('fi-home-v2');
  if(!root) return;

  window.fiTempo = window.fiTempo || {proc:1500, gap:380, hold:2800, step:1900};

  /* ── Seamless infinite marquees (Signal Band + Channels) ── */
  (function(){
    var REDUCE = window.matchMedia && window.matchMedia('(prefers-reduced-motion:reduce)').matches;
    function build(mq){
      var track = mq.querySelector('.fi-mq__track'); if(!track) return;
      if(track.__fiBase === undefined){ track.__fiBase = track.innerHTML; }
      track.innerHTML = track.__fiBase;
      if(REDUCE){ track.style.animation = 'none'; return; }
      var guard = 0;
      while(track.scrollWidth < mq.offsetWidth + 60 && guard < 60){
        track.insertAdjacentHTML('beforeend', track.__fiBase); guard++;
      }
      track.insertAdjacentHTML('beforeend', track.innerHTML); /* two identical halves */
      var speed = parseFloat(mq.getAttribute('data-fi-mq-speed')) || 55;
      track.style.setProperty('--fi-mq-dur', ((track.scrollWidth / 2) / speed).toFixed(1) + 's');
    }
    function rebuildAll(){
      var mqs = root.querySelectorAll('.fi-mq');
      for(var i = 0; i < mqs.length; i++){ build(mqs[i]); }
    }
    rebuildAll();
    if(document.fonts && document.fonts.ready){ document.fonts.ready.then(rebuildAll); }
    var t; window.addEventListener('resize', function(){ clearTimeout(t); t = setTimeout(rebuildAll, 250); }, {passive:true});
  })();

  /* Scroll reveal */
  var io = new IntersectionObserver(function(es){
    es.forEach(function(e){
      if(e.isIntersecting){
        var parent = e.target.parentElement;
        var sibs = parent ? Array.prototype.slice.call(parent.querySelectorAll(':scope > .reveal')) : [];
        var i = sibs.indexOf(e.target);
        e.target.style.transitionDelay = (i > 0 ? Math.min(i*85,340) : 0) + 'ms';
        e.target.classList.add('in');
        io.unobserve(e.target);
      }
    });
  }, {threshold:.12, rootMargin:'0px 0px -8% 0px'});
  root.querySelectorAll('.reveal').forEach(function(el){ io.observe(el); });

  /* Radar live readout */
  var reads = [
    ['SOC','"quiet luxury" +340% LATAM'],
    ['SEA','identidad cultural post-digital'],
    ['ADS','nostalgia futurista en lujo'],
    ['REV','microcultura > minimalismo'],
    ['AI','prompts sobre códigos de marca'],
    ['WEB','marcas ajustan tono a comunidad']
  ];
  var states = ['señal → brief','insight → output','lectura activa','brief listo'];
  var ri = 0;
  var line = root.querySelector('#rrLine');
  var numEl = root.querySelector('#rrNum');
  if(line){
    setInterval(function(){
      ri = (ri+1) % reads.length;
      line.style.opacity = 0;
      setTimeout(function(){
        line.innerHTML = '<span class="ch">' + reads[ri][0] + '</span>' + reads[ri][1];
        if(numEl) numEl.textContent = states[ri % states.length];
        line.style.opacity = 1;
      }, 380);
    }, 2600);
  }

  /* Live clock-ish */
  var clock = root.querySelector('#clock');
  if(clock){
    var m = 4;
    setInterval(function(){
      m = (m+1) % 60;
      clock.textContent = '21:' + String(m).padStart(2,'0');
    }, 4000);
  }

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion:reduce)').matches;

  /* ── Live dashboard demo (loops) ── */
  (function(){
    var demo = root.querySelector('#fiDemo');
    if(!demo) return;
    var rows  = demo.querySelectorAll('.drow');
    var nodes = demo.querySelectorAll('.pnode');
    var fill  = demo.querySelector('#pipeFill');
    var pct   = demo.querySelector('#demoPct');
    var stateEl = demo.querySelector('#demoState');
    var timeEl  = demo.querySelector('#demoTime');
    var liveEl  = demo.querySelector('#demoLive');
    var states = [
      'Leyendo señal pública…',
      'Normalizando · descartando ruido…',
      'Interpretando patrón cultural…',
      'Redactando dirección accionable…',
      'Produciendo outputs…',
      'Listo — señal trazada de principio a fin'
    ];
    var n = rows.length, timers = [], tick = null, t0 = 0;
    function clearAll(){ timers.forEach(clearTimeout); timers = []; }
    function reset(){
      rows.forEach(function(r){ r.classList.remove('show','active','proc','done2'); });
      nodes.forEach(function(nd){ nd.classList.remove('active','done'); });
      fill.style.width = '0%'; pct.textContent = '0%'; stateEl.textContent = states[0];
    }
    function step(i){
      if(i >= n){
        stateEl.textContent = states[5];
        if(liveEl) liveEl.textContent = 'LISTO';
        timers.push(setTimeout(function(){ if(liveEl) liveEl.textContent = 'PROCESANDO'; cycle(); }, window.fiTempo.hold));
        return;
      }
      var r = rows[i], nd = nodes[i];
      nd.classList.add('active');
      r.classList.add('show','active','proc');
      fill.style.width = (i/(n-1)*100) + '%';
      pct.textContent = Math.round((i+1)/n*100) + '%';
      stateEl.textContent = states[i];
      timers.push(setTimeout(function(){
        r.classList.remove('proc','active'); r.classList.add('done2');
        nd.classList.remove('active'); nd.classList.add('done');
        timers.push(setTimeout(function(){ step(i+1); }, window.fiTempo.gap));
      }, window.fiTempo.proc));
    }
    function cycle(){ clearAll(); reset(); timers.push(setTimeout(function(){ step(0); }, 520)); }
    function startTimer(){
      t0 = Date.now();
      if(tick) clearInterval(tick);
      tick = setInterval(function(){
        var s = Math.floor((Date.now()-t0)/1000) % 600;
        timeEl.textContent = String(Math.floor(s/60)).padStart(2,'0') + ':' + String(s%60).padStart(2,'0');
      }, 1000);
    }
    if(reduce){
      rows.forEach(function(r){ r.classList.add('show','done2'); });
      nodes.forEach(function(nd){ nd.classList.add('done'); });
      fill.style.width = '100%'; pct.textContent = '100%'; stateEl.textContent = states[5];
      if(liveEl) liveEl.textContent = 'LISTO';
      return;
    }
    var started = false;
    var dio = new IntersectionObserver(function(es){
      es.forEach(function(e){ if(e.isIntersecting && !started){ started = true; startTimer(); cycle(); } });
    }, {threshold:.3});
    dio.observe(demo);
  })();

  /* ── Metric count-ups ── */
  (function(){
    var nums = root.querySelectorAll('.metric .num:not(.word)');
    if(!nums.length) return;
    var mio = new IntersectionObserver(function(es){
      es.forEach(function(e){
        if(!e.isIntersecting) return;
        var el = e.target, target = parseInt(el.textContent, 10) || 0;
        mio.unobserve(el);
        if(reduce){ el.textContent = target; return; }
        var dur = 900, start = performance.now();
        (function run(now){
          var p = Math.min((now-start)/dur, 1);
          el.textContent = Math.round((1-Math.pow(1-p,3)) * target);
          if(p < 1) requestAnimationFrame(run);
        })(start);
      });
    }, {threshold:.6});
    nums.forEach(function(el){ mio.observe(el); });
  })();

  /* ── Steps · auto-advancing live highlight ── */
  (function(){
    var steps = root.querySelectorAll('.steps .step');
    if(!steps.length || reduce) return;
    var idx = -1, running = false, iv = null;
    function advance(){
      steps.forEach(function(s){ s.classList.remove('live'); });
      idx = (idx + 1) % steps.length;
      steps[idx].classList.add('live');
      if(running) iv = setTimeout(advance, window.fiTempo.step);
    }
    var sio = new IntersectionObserver(function(es){
      es.forEach(function(e){
        if(e.isIntersecting && !running){ running = true; advance(); }
        else if(!e.isIntersecting && running){ running = false; clearTimeout(iv); steps.forEach(function(s){ s.classList.remove('live'); }); idx = -1; }
      });
    }, {threshold:.25});
    sio.observe(root.querySelector('.steps'));
  })();
})();
</script>
        <?php
        return ob_get_clean();
    }
}

if ( ! shortcode_exists( 'fi_home_v2' ) ) {
    add_shortcode( 'fi_home_v2', 'fi_home_v2_shortcode' );
}
