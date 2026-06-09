<?php
// v0.9.31.5 — Curated static report template. Renders verbatim narrative
// content. $report is passed by the renderer; an optional title override
// is rendered above the curated hero when present, otherwise the curated
// hero stands on its own.
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/_partials.php';
$curated_title_override = isset($report['title']) ? (string) $report['title'] : '';
?>
<section class="ves-report ves-report-curated ves-report-verano-silencioso" aria-labelledby="ves-report-verano-silencioso-title">
<?php if ($curated_title_override !== '') : ?>
  <h1 id="ves-report-verano-silencioso-title" class="ves-report-curated-title"><?php echo esc_html($curated_title_override); ?></h1>
<?php else : ?>
  <h1 id="ves-report-verano-silencioso-title" class="ves-sr-only"><?php echo esc_html__('Verano Silencioso — análisis de tendencia 2026', 'ves'); ?></h1>
<?php endif; ?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap');
.ves-report-curated.ves-report-verano-silencioso {
  --r:#E24B4A; --rm:#A32D2D; --rlight:rgba(226,75,74,.09);
  --g:#1D9E75; --b:#378ADD; --a:#BA7517; --p:#7F77DD;
  --ink:var(--color-text-primary); --sub:var(--color-text-secondary);
  --bg:var(--color-background-primary); --bg2:var(--color-background-secondary);
  --bd:var(--color-border-tertiary); --bd2:var(--color-border-secondary);
  --ff:'Syne',sans-serif; --fm:'DM Mono',monospace;
}
.ves-report-curated.ves-report-verano-silencioso *{box-sizing:border-box;}
.ves-report-curated.ves-report-verano-silencioso{font-family:var(--ff);}
.ves-report-curated.ves-report-verano-silencioso .root{padding:0 0 2rem;}

/* HERO */
.ves-report-curated.ves-report-verano-silencioso .hero{padding:1.5rem 1.25rem 1rem; border-bottom:0.5px solid var(--bd); display:flex; align-items:flex-start; justify-content:space-between; gap:1rem;}
.ves-report-curated.ves-report-verano-silencioso .hero-left{}
.ves-report-curated.ves-report-verano-silencioso .trend-eyebrow{font-family:var(--fm); font-size:10px; color:var(--sub); letter-spacing:.8px; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:8px;}
.ves-report-curated.ves-report-verano-silencioso .live-dot{width:6px;height:6px;border-radius:50%;background:var(--r);display:inline-block;animation:ves-verano-silencioso-blink 1.2s ease-in-out infinite;}
@keyframes ves-verano-silencioso-blink{0%,100%{opacity:1}50%{opacity:.3}}
.ves-report-curated.ves-report-verano-silencioso .hero-title{font-size:26px; font-weight:800; color:var(--ink); line-height:1.1; letter-spacing:-1px; margin-bottom:.5rem;}
.ves-report-curated.ves-report-verano-silencioso .hero-title span{color:var(--r);}
.ves-report-curated.ves-report-verano-silencioso .hero-tagline{font-size:13px; color:var(--sub); max-width:480px; line-height:1.6;}
.ves-report-curated.ves-report-verano-silencioso .hero-right{display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0;}
.ves-report-curated.ves-report-verano-silencioso .score-ring{text-align:center;}
.ves-report-curated.ves-report-verano-silencioso .score-big{font-size:42px;font-weight:800;color:var(--r);line-height:1;letter-spacing:-2px;}
.ves-report-curated.ves-report-verano-silencioso .score-label{font-family:var(--fm);font-size:9px;color:var(--sub);text-transform:uppercase;letter-spacing:.5px;}
.ves-report-curated.ves-report-verano-silencioso .hero-tags{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;}
.ves-report-curated.ves-report-verano-silencioso .htag{font-family:var(--fm);font-size:9px;padding:3px 8px;border-radius:12px;border:0.5px solid var(--bd);color:var(--sub);}

/* STATS ROW */
.ves-report-curated.ves-report-verano-silencioso .stats-row{display:grid;grid-template-columns:repeat(5,1fr);border-bottom:0.5px solid var(--bd);}
.ves-report-curated.ves-report-verano-silencioso .stat{padding:1rem 1.25rem; border-right:0.5px solid var(--bd);}
.ves-report-curated.ves-report-verano-silencioso .stat:last-child{border-right:none;}
.ves-report-curated.ves-report-verano-silencioso .stat-val{font-size:22px;font-weight:800;color:var(--ink);line-height:1;margin-bottom:3px;}
.ves-report-curated.ves-report-verano-silencioso .stat-val.r{color:var(--r);}
.ves-report-curated.ves-report-verano-silencioso .stat-label{font-family:var(--fm);font-size:9px;color:var(--sub);text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px;}
.ves-report-curated.ves-report-verano-silencioso .stat-note{font-size:11px;color:var(--sub);}

/* SECTION LAYOUT */
.ves-report-curated.ves-report-verano-silencioso .section{padding:1.25rem; border-bottom:0.5px solid var(--bd);}
.ves-report-curated.ves-report-verano-silencioso .sec-head{display:flex;align-items:center;gap:8px;margin-bottom:1rem;}
.ves-report-curated.ves-report-verano-silencioso .sec-num{font-family:var(--fm);font-size:10px;color:var(--sub);width:18px;}
.ves-report-curated.ves-report-verano-silencioso .sec-title{font-size:13px;font-weight:700;color:var(--ink);letter-spacing:-.3px;}
.ves-report-curated.ves-report-verano-silencioso .sec-badge{font-family:var(--fm);font-size:9px;padding:2px 8px;border-radius:10px;margin-left:auto;}

/* ANATOMY */
.ves-report-curated.ves-report-verano-silencioso .anatomy-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.ves-report-curated.ves-report-verano-silencioso .anat-card{background:var(--bg2);border-radius:var(--border-radius-lg);padding:1rem;}
.ves-report-curated.ves-report-verano-silencioso .anat-icon{font-size:20px;margin-bottom:8px;}
.ves-report-curated.ves-report-verano-silencioso .anat-title{font-size:12px;font-weight:700;color:var(--ink);margin-bottom:5px;}
.ves-report-curated.ves-report-verano-silencioso .anat-text{font-size:11px;color:var(--sub);line-height:1.5;}
.ves-report-curated.ves-report-verano-silencioso .anat-tags{display:flex;flex-wrap:wrap;gap:3px;margin-top:8px;}
.ves-report-curated.ves-report-verano-silencioso .atag{font-family:var(--fm);font-size:9px;padding:2px 5px;border-radius:4px;background:var(--bg);border:0.5px solid var(--bd);color:var(--sub);}

/* TENSION MAP */
.ves-report-curated.ves-report-verano-silencioso .tension-grid{display:grid;grid-template-columns:1fr 40px 1fr;gap:0;align-items:center;}
.ves-report-curated.ves-report-verano-silencioso .tension-side{display:flex;flex-direction:column;gap:8px;}
.ves-report-curated.ves-report-verano-silencioso .tension-vs{text-align:center;font-family:var(--fm);font-size:11px;color:var(--sub);font-weight:500;}
.ves-report-curated.ves-report-verano-silencioso .tens-item{padding:10px 12px;border-radius:var(--border-radius-md);font-size:11px;color:var(--ink);line-height:1.3;}
.ves-report-curated.ves-report-verano-silencioso .tens-old{background:rgba(226,75,74,.07);border-left:2px solid var(--r);}
.ves-report-curated.ves-report-verano-silencioso .tens-new{background:rgba(29,158,117,.07);border-left:2px solid var(--g);}
.ves-report-curated.ves-report-verano-silencioso .tens-label{font-family:var(--fm);font-size:9px;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px;}

/* BRAND OPPS */
.ves-report-curated.ves-report-verano-silencioso .brands-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;}
.ves-report-curated.ves-report-verano-silencioso .brand-card{border:0.5px solid var(--bd);border-radius:var(--border-radius-lg);padding:1rem;cursor:pointer;transition:border-color .15s;}
.ves-report-curated.ves-report-verano-silencioso .brand-card:hover{border-color:var(--r);}
.ves-report-curated.ves-report-verano-silencioso .brand-top{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.ves-report-curated.ves-report-verano-silencioso .brand-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.ves-report-curated.ves-report-verano-silencioso .brand-name{font-size:13px;font-weight:700;color:var(--ink);}
.ves-report-curated.ves-report-verano-silencioso .brand-sector{font-family:var(--fm);font-size:9px;color:var(--sub);}
.ves-report-curated.ves-report-verano-silencioso .brand-fit{font-family:var(--fm);font-size:10px;padding:2px 8px;border-radius:10px;margin-left:auto;}
.ves-report-curated.ves-report-verano-silencioso .fit-high{background:rgba(29,158,117,.1);color:var(--g);}
.ves-report-curated.ves-report-verano-silencioso .fit-med{background:rgba(186,117,23,.1);color:var(--a);}
.ves-report-curated.ves-report-verano-silencioso .brand-msg{font-size:11px;color:var(--sub);line-height:1.5;margin-bottom:8px;}
.ves-report-curated.ves-report-verano-silencioso .brand-hook{background:var(--bg2);border-radius:6px;padding:8px 10px;font-size:11px;color:var(--ink);font-style:italic;line-height:1.4;border-left:2px solid var(--r);}
.ves-report-curated.ves-report-verano-silencioso .brand-warn{font-family:var(--fm);font-size:9px;color:var(--a);margin-top:6px;display:flex;align-items:center;gap:4px;}

/* MESSAGING */
.ves-report-curated.ves-report-verano-silencioso .msg-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.ves-report-curated.ves-report-verano-silencioso .msg-card{border-radius:var(--border-radius-lg);padding:1rem;}
.ves-report-curated.ves-report-verano-silencioso .msg-type{font-family:var(--fm);font-size:9px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.ves-report-curated.ves-report-verano-silencioso .msg-headline{font-size:13px;font-weight:700;line-height:1.3;margin-bottom:6px;}
.ves-report-curated.ves-report-verano-silencioso .msg-body{font-size:10px;line-height:1.5;}
.ves-report-curated.ves-report-verano-silencioso .msg-do{margin-top:8px;}
.ves-report-curated.ves-report-verano-silencioso .msg-do-item{font-size:10px;line-height:1.5;display:flex;gap:5px;}
.ves-report-curated.ves-report-verano-silencioso .msg-dont{font-family:var(--fm);font-size:9px;margin-top:6px;padding:5px 8px;border-radius:5px;}

/* TIMELINE */
.ves-report-curated.ves-report-verano-silencioso .timeline{position:relative;padding-left:0;}
.ves-report-curated.ves-report-verano-silencioso .tl-row{display:grid;grid-template-columns:100px 1fr;gap:0;margin-bottom:0;}
.ves-report-curated.ves-report-verano-silencioso .tl-month{padding:.875rem 1rem .875rem 0;border-right:0.5px solid var(--bd);text-align:right;}
.ves-report-curated.ves-report-verano-silencioso .tl-month-label{font-size:13px;font-weight:700;color:var(--ink);}
.ves-report-curated.ves-report-verano-silencioso .tl-month-sub{font-family:var(--fm);font-size:9px;color:var(--sub);}
.ves-report-curated.ves-report-verano-silencioso .tl-content{padding:.875rem 0 .875rem 1.25rem;border-bottom:0.5px solid var(--bd);}
.ves-report-curated.ves-report-verano-silencioso .tl-row:last-child .tl-content{border-bottom:none;}
.ves-report-curated.ves-report-verano-silencioso .tl-phase{font-family:var(--fm);font-size:10px;color:var(--r);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.ves-report-curated.ves-report-verano-silencioso .tl-events{display:flex;flex-direction:column;gap:5px;}
.ves-report-curated.ves-report-verano-silencioso .tl-ev{display:flex;align-items:flex-start;gap:8px;font-size:11px;color:var(--ink);line-height:1.4;}
.ves-report-curated.ves-report-verano-silencioso .tl-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;margin-top:4px;}
.ves-report-curated.ves-report-verano-silencioso .tl-action{background:var(--bg2);border-radius:6px;padding:6px 10px;margin-top:6px;font-family:var(--fm);font-size:9px;color:var(--sub);display:flex;align-items:center;gap:6px;}
.ves-report-curated.ves-report-verano-silencioso .tl-action-label{font-size:10px;color:var(--ink);}

/* RIESGOS */
.ves-report-curated.ves-report-verano-silencioso .risk-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;}
.ves-report-curated.ves-report-verano-silencioso .risk-card{padding:.875rem;border-radius:var(--border-radius-md);border:0.5px solid var(--bd);}
.ves-report-curated.ves-report-verano-silencioso .risk-level{font-family:var(--fm);font-size:9px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.ves-report-curated.ves-report-verano-silencioso .risk-title{font-size:12px;font-weight:700;color:var(--ink);margin-bottom:4px;}
.ves-report-curated.ves-report-verano-silencioso .risk-body{font-size:10px;color:var(--sub);line-height:1.4;}
.ves-report-curated.ves-report-verano-silencioso .lvl-high{color:var(--r);}
.ves-report-curated.ves-report-verano-silencioso .lvl-med{color:var(--a);}
.ves-report-curated.ves-report-verano-silencioso .lvl-low{color:var(--g);}

/* BOTTOM ACTION */
.ves-report-curated.ves-report-verano-silencioso .cta-bar{padding:1rem 1.25rem;display:flex;gap:8px;flex-wrap:wrap;}
.ves-report-curated.ves-report-verano-silencioso .cta-btn{font-family:var(--ff);font-size:11px;padding:7px 16px;border-radius:20px;border:0.5px solid var(--bd);background:transparent;color:var(--ink);cursor:pointer;transition:all .15s;}
.ves-report-curated.ves-report-verano-silencioso .cta-btn:hover{border-color:var(--r);color:var(--r);}
.ves-report-curated.ves-report-verano-silencioso .cta-btn.primary{background:var(--r);border-color:var(--r);color:#fff;}
.ves-report-curated.ves-report-verano-silencioso .cta-btn.primary:hover{background:var(--rm);border-color:var(--rm);}


/* v0.9.31.7 responsive pass — scoped to this curated template only. */
@media (max-width: 900px) {
.ves-report-curated.ves-report-verano-silencioso .hero{flex-direction:column;align-items:flex-start;}
.ves-report-curated.ves-report-verano-silencioso .hero-right{align-items:flex-start;width:100%;}
.ves-report-curated.ves-report-verano-silencioso .hero-tags{justify-content:flex-start;}
.ves-report-curated.ves-report-verano-silencioso .hero-title{font-size:clamp(22px,5vw,26px);}
.ves-report-curated.ves-report-verano-silencioso .stats-row{grid-template-columns:repeat(2,minmax(0,1fr));}
.ves-report-curated.ves-report-verano-silencioso .stat{border-right:0;border-bottom:0.5px solid var(--bd);}
.ves-report-curated.ves-report-verano-silencioso .anatomy-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
.ves-report-curated.ves-report-verano-silencioso .tension-grid{grid-template-columns:1fr;gap:8px;}
.ves-report-curated.ves-report-verano-silencioso .tension-vs{text-align:left;padding:2px 0;}
.ves-report-curated.ves-report-verano-silencioso .brands-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-verano-silencioso .msg-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-verano-silencioso .risk-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
.ves-report-curated.ves-report-verano-silencioso .tl-row{grid-template-columns:84px 1fr;}
}
@media (max-width: 560px) {
.ves-report-curated.ves-report-verano-silencioso .hero{padding:1.1rem 1rem .9rem;}
.ves-report-curated.ves-report-verano-silencioso .section{padding:1rem;}
.ves-report-curated.ves-report-verano-silencioso .hero-title{font-size:clamp(20px,8vw,24px);letter-spacing:-.7px;}
.ves-report-curated.ves-report-verano-silencioso .hero-tagline{font-size:12px;}
.ves-report-curated.ves-report-verano-silencioso .stats-row{grid-template-columns:1fr;}
.ves-report-curated.ves-report-verano-silencioso .anatomy-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-verano-silencioso .risk-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-verano-silencioso .brand-top{align-items:flex-start;flex-wrap:wrap;}
.ves-report-curated.ves-report-verano-silencioso .brand-fit{margin-left:0;}
.ves-report-curated.ves-report-verano-silencioso .tl-row{grid-template-columns:1fr;}
.ves-report-curated.ves-report-verano-silencioso .tl-month{text-align:left;border-right:0;padding:.875rem 0 .25rem;}
.ves-report-curated.ves-report-verano-silencioso .tl-content{padding:.5rem 0 .875rem;}
.ves-report-curated.ves-report-verano-silencioso .cta-bar{padding:.875rem 1rem;}
.ves-report-curated.ves-report-verano-silencioso .cta-btn{white-space:normal;}
}
</style>

<div class="root">

<!-- HERO -->
<div class="hero">
  <div class="hero-left">
    <div class="trend-eyebrow"><span class="live-dot"></span> Análisis de tendencia · PULSO · May 2026</div>
    <h1 class="hero-title">El <span>Verano Silencioso</span><br>en España</h1>
    <p class="hero-tagline">La generación que vivió el FOMO como identidad está liderando su propio antídoto: el silencio, la lentitud y la desconexión como nuevo estatus cultural. No es una moda pasajera — es el síntoma de un agotamiento estructural.</p>
  </div>
  <div class="hero-right">
    <div class="score-ring">
      <div class="score-big">9.1</div>
      <div class="score-label">Relevancia cultural</div>
    </div>
    <div class="hero-tags">
      <span class="htag">Gen Z</span>
      <span class="htag">Millennials</span>
      <span class="htag">Slow living</span>
      <span class="htag">Anti-FOMO</span>
    </div>
  </div>
</div>

<!-- STATS -->
<div class="stats-row">
  <div class="stat">
    <div class="stat-label">Crecimiento TikTok ES</div>
    <div class="stat-val r">+340%</div>
    <div class="stat-note">últimas 3 semanas</div>
  </div>
  <div class="stat">
    <div class="stat-label">Menciones semanales</div>
    <div class="stat-val">128K</div>
    <div class="stat-note">RRSS + foros ES</div>
  </div>
  <div class="stat">
    <div class="stat-label">Búsquedas "slow living"</div>
    <div class="stat-val r">×4.2</div>
    <div class="stat-note">vs mismo período 2024</div>
  </div>
  <div class="stat">
    <div class="stat-label">Audiencia primaria</div>
    <div class="stat-val">22–35</div>
    <div class="stat-note">urbana, estudios superiores</div>
  </div>
  <div class="stat">
    <div class="stat-label">Ventana de oportunidad</div>
    <div class="stat-val r">9 sem</div>
    <div class="stat-note">antes de saturación</div>
  </div>
</div>

<!-- ANATOMÍA -->
<div class="section">
  <div class="sec-head">
    <span class="sec-num">01</span>
    <span class="sec-title">Anatomía de la tendencia — qué hay debajo</span>
  </div>
  <div class="anatomy-grid">
    <div class="anat-card">
      <div class="anat-icon"><i class="ti ti-brain" aria-hidden="true"></i></div>
      <div class="anat-title">La raíz psicológica</div>
      <div class="anat-text">El agotamiento cognitivo post-pandemia, el burnout laboral y la fatiga de scroll convergieron. Más del 59% de trabajadores españoles no desconectan en vacaciones. La paradoja es que la generación que más consume redes sociales es la que más verbaliza querer escapar de ellas.</div>
      <div class="anat-tags"><span class="atag">Fatiga digital</span><span class="atag">Burnout</span><span class="atag">Ansiedad crónica</span></div>
    </div>
    <div class="anat-card">
      <div class="anat-icon"><i class="ti ti-plant" aria-hidden="true"></i></div>
      <div class="anat-title">El detonante cultural</div>
      <div class="anat-text">En 2025 el "Convent Summer" viral en TikTok global —estancias en conventos y monasterios— llegó a España fusionado con la tradición del veraneo rural. Influencers de campo como el ganadero José Bustos o Magdalenita conectaron audiencias urbanas con la vida pausada y cotidiana.</div>
      <div class="anat-tags"><span class="atag">Rural core</span><span class="atag">Convent Summer</span><span class="atag">Slow travel</span></div>
    </div>
    <div class="anat-card">
      <div class="anat-icon"><i class="ti ti-map-pin" aria-hidden="true"></i></div>
      <div class="anat-title">El matiz español</div>
      <div class="anat-text">En España el silencio ya no es sinónimo de pobreza o aburrimiento. La siesta, el aperitivo sin prisa, los pueblos de interior — elementos que se avergonzaban de referenciar ahora son marcadores de identidad cool. Lo local se revaloriza como resistencia al ritmo global.</div>
      <div class="anat-tags"><span class="atag">Orgullo local</span><span class="atag">Interior España</span><span class="atag">Gastronomía lenta</span></div>
    </div>
  </div>
</div>

<!-- TENSIÓN CULTURAL -->
<div class="section">
  <div class="sec-head">
    <span class="sec-num">02</span>
    <span class="sec-title">El mapa de tensión — lo que está muriendo vs lo que nace</span>
  </div>
  <div class="tension-grid">
    <div class="tension-side">
      <div class="tens-item tens-old"><div class="tens-label" style="color:var(--r);">Agotado</div>Acumular experiencias para contar en RRSS</div>
      <div class="tens-item tens-old"><div class="tens-label" style="color:var(--r);">Agotado</div>Agenda de verano llena como señal de éxito social</div>
      <div class="tens-item tens-old"><div class="tens-label" style="color:var(--r);">Agotado</div>Viajes de Instagram: muchas fotos, poca presencia</div>
      <div class="tens-item tens-old"><div class="tens-label" style="color:var(--r);">Agotado</div>Notificaciones como compañía constante</div>
    </div>
    <div class="tension-vs">VS</div>
    <div class="tension-side">
      <div class="tens-item tens-new"><div class="tens-label" style="color:var(--g);">Emergente</div>El silencio como lujo accesible, no como vacío</div>
      <div class="tens-item tens-new"><div class="tens-label" style="color:var(--g);">Emergente</div>El aburrimiento productivo como práctica de salud mental</div>
      <div class="tens-item tens-new"><div class="tens-label" style="color:var(--g);">Emergente</div>Slow travel: un pueblo, una semana, sin agenda</div>
      <div class="tens-item tens-new"><div class="tens-label" style="color:var(--g);">Emergente</div>Modo avión como acto de autocuidado radical</div>
    </div>
  </div>
</div>

<!-- MARCAS -->
<div class="section">
  <div class="sec-head">
    <span class="sec-num">03</span>
    <span class="sec-title">Marcas con mayor oportunidad de entrada</span>
    <span class="sec-badge" style="background:var(--rlight);color:var(--r);">6 sectores identificados</span>
  </div>
  <div class="brands-grid">
    <div class="brand-card" data-ves-assistant-question="Dame una estrategia concreta para una marca de turismo rural o alojamiento en España para conectar con la tendencia del Verano Silencioso: campañas, mensajes, formatos, influencers y timing ideal">
      <div class="brand-top">
        <div class="brand-icon" style="background:rgba(29,158,117,.1);"><i class="ti ti-home-2" style="color:#1D9E75;font-size:20px;" aria-hidden="true"></i></div>
        <div><div class="brand-name">Turismo rural & alojamiento</div><div class="brand-sector">Hostelería · Experiencias</div></div>
        <span class="brand-fit fit-high">Fit muy alto</span>
      </div>
      <div class="brand-msg">Casas rurales, hoteles con filosofía slow (tipo SHA Wellness Alicante, Vivood) y plataformas tipo Rusticae tienen un encaje perfecto. La conversación ya habla el mismo idioma que su producto.</div>
      <div class="brand-hook">"Aquí no pasan cosas. Y eso es exactamente lo que necesitabas."</div>
      <div class="brand-warn"><i class="ti ti-alert-triangle" style="font-size:12px;" aria-hidden="true"></i> No usar imágenes de lujo ostentoso — rompe la credibilidad</div>
    </div>
    <div class="brand-card" data-ves-assistant-question="Qué estrategia debería seguir una marca de bebidas (agua, infusiones, kombucha) o alimentación en España para conectar con la tendencia del Verano Silencioso y el slow living sin parecer oportunista">
      <div class="brand-top">
        <div class="brand-icon" style="background:rgba(186,117,23,.1);"><i class="ti ti-leaf" style="color:#BA7517;font-size:20px;" aria-hidden="true"></i></div>
        <div><div class="brand-name">Bebidas & alimentación natural</div><div class="brand-sector">Gran consumo · FMCG</div></div>
        <span class="brand-fit fit-high">Fit muy alto</span>
      </div>
      <div class="brand-msg">Infusiones, aguas con propiedades, kombucha, snacks artesanos. El ritual del desayuno sin móvil o el vermut sin prisa son momentos culturalmente cargados que estas marcas pueden hacer suyos.</div>
      <div class="brand-hook">"Para los momentos que no se fotografían. Solo se sienten."</div>
      <div class="brand-warn"><i class="ti ti-alert-triangle" style="font-size:12px;" aria-hidden="true"></i> Evitar el greenwashing: credenciales reales o silencio</div>
    </div>
    <div class="brand-card" data-ves-assistant-question="Cómo puede una marca de moda sostenible o una marca de ropa española conectar con el Verano Silencioso: qué colecciones, qué storytelling, qué plataformas y qué colaboraciones tendría sentido activar">
      <div class="brand-top">
        <div class="brand-icon" style="background:rgba(127,119,221,.1);"><i class="ti ti-shirt" style="color:#7F77DD;font-size:20px;" aria-hidden="true"></i></div>
        <div><div class="brand-name">Moda slow & lencería natural</div><div class="brand-sector">Moda · Textil</div></div>
        <span class="brand-fit fit-high">Fit alto</span>
      </div>
      <div class="brand-msg">Slow Fashion Next ya opera en España. La estética del verano silencioso favorece lino, algodón orgánico, colores tierra. Las prendas que "no gritan" son el nuevo lujo. Oportunidad para marcas españolas mid-market que abandonen el fast fashion visual.</div>
      <div class="brand-hook">"Ropa para quedarte. No para que te vean."</div>
      <div class="brand-warn"><i class="ti ti-alert-triangle" style="font-size:12px;" aria-hidden="true"></i> La audiencia detecta inconsistencias de cadena de producción</div>
    </div>
    <div class="brand-card" data-ves-assistant-question="Dame ideas concretas de producto, campaña y mensajes para una marca de tecnología o app (bienestar digital, meditación, control de pantalla) que quiera conectar con el Verano Silencioso en España">
      <div class="brand-top">
        <div class="brand-icon" style="background:rgba(55,138,221,.1);"><i class="ti ti-device-mobile-off" style="color:#378ADD;font-size:20px;" aria-hidden="true"></i></div>
        <div><div class="brand-name">Apps de bienestar digital</div><div class="brand-sector">Tech · Wellness</div></div>
        <span class="brand-fit fit-med">Fit medio-alto</span>
      </div>
      <div class="brand-msg">La paradoja más interesante: apps de meditación, control de tiempo de pantalla o descanso del sueño (el sector creció enormemente en 2025). TikTok lanzó su propio espacio de bienestar. El usuario quiere herramientas para desconectar… desde el móvil.</div>
      <div class="brand-hook">"La mejor tecnología es la que te ayuda a no necesitarla."</div>
      <div class="brand-warn"><i class="ti ti-alert-triangle" style="font-size:12px;" aria-hidden="true"></i> El mensaje debe ser honesto, no performativo</div>
    </div>
    <div class="brand-card" data-ves-assistant-question="Qué oportunidades tiene una marca de colchones, descanso o bienestar del hogar en España para conectar con el Verano Silencioso y la cultura slow living: estrategia, mensajes, canales y posicionamiento">
      <div class="brand-top">
        <div class="brand-icon" style="background:rgba(226,75,74,.08);"><i class="ti ti-moon" style="color:var(--r);font-size:20px;" aria-hidden="true"></i></div>
        <div><div class="brand-name">Descanso & hogar</div><div class="brand-sector">Colchones · Home · Spa</div></div>
        <span class="brand-fit fit-high">Fit muy alto</span>
      </div>
      <div class="brand-msg">El sector descanso en España ya detectó la tendencia: el consumidor no busca solo un colchón, sino una experiencia completa de descanso. Marcas como Pikolin reorientaron su propósito. El sueño como acto político anti-productividad es un mensaje poderoso.</div>
      <div class="brand-hook">"Dormir bien ya no es perder el tiempo. Es el único plan que importa."</div>
      <div class="brand-warn"><i class="ti ti-alert-triangle" style="font-size:12px;" aria-hidden="true"></i> Cuidado con medicalizar demasiado: debe sentirse placer, no obligación</div>
    </div>
    <div class="brand-card" data-ves-assistant-question="Cómo puede una editorial, plataforma de podcasts o marca de contenido cultural en España conectar con la tendencia del Verano Silencioso: qué tipo de contenido crear, qué comunidades activar y cómo posicionarse">
      <div class="brand-top">
        <div class="brand-icon" style="background:rgba(29,158,117,.1);"><i class="ti ti-book" style="color:#1D9E75;font-size:20px;" aria-hidden="true"></i></div>
        <div><div class="brand-name">Editoriales & contenido lento</div><div class="brand-sector">Cultura · Podcasts · Prensa</div></div>
        <span class="brand-fit fit-med">Fit medio-alto</span>
      </div>
      <div class="brand-msg">Podcasts longform, newsletters de lectura pausada, libros de ensayo sobre vida lenta. El mercado editorial español tiene un nicho sin explotar entre 25-38 años que consume contenido Substack y podcasts de largo formato como alternativa al scroll.</div>
      <div class="brand-hook">"Para los que ya no tienen tiempo de leer. Y necesitan encontrarlo."</div>
      <div class="brand-warn"><i class="ti ti-alert-triangle" style="font-size:12px;" aria-hidden="true"></i> No caer en el tono elitista o intelectual distante</div>
    </div>
  </div>
</div>

<!-- MENSAJES -->
<div class="section">
  <div class="sec-head">
    <span class="sec-num">04</span>
    <span class="sec-title">Arquitectura de mensajes — qué resuena, qué rechina</span>
  </div>
  <div class="msg-grid">
    <div class="msg-card" style="background:rgba(29,158,117,.06);border:0.5px solid rgba(29,158,117,.2);">
      <div class="msg-type" style="color:var(--g);">Territorio A — Permiso</div>
      <div class="msg-headline" style="color:var(--ink);">El descanso como acto de valentía</div>
      <div class="msg-body" style="color:var(--sub);">Esta audiencia tiene mucha culpa acumulada por no hacer nada. El mensaje que libera esa culpa es enormemente poderoso. No se trata de "merecerlo" —eso huele a condescendencia— sino de que parar es, de hecho, difícil y radical.</div>
      <div class="msg-do">
        <div class="msg-do-item"><span style="color:var(--g);">+</span> "Apagar el móvil es el gesto más subversivo de 2026"</div>
        <div class="msg-do-item"><span style="color:var(--g);">+</span> "No tienes que justificar un verano sin planes"</div>
        <div class="msg-do-item"><span style="color:var(--g);">+</span> "El aburrimiento es tu sistema nervioso recuperándose"</div>
      </div>
      <div class="msg-dont" style="background:rgba(226,75,74,.06);color:var(--r);">✕ No usar: "Te lo mereces" — suena a anuncio de yogur</div>
    </div>
    <div class="msg-card" style="background:rgba(127,119,221,.06);border:0.5px solid rgba(127,119,221,.2);">
      <div class="msg-type" style="color:var(--p);">Territorio B — Identidad</div>
      <div class="msg-headline" style="color:var(--ink);">Lo lento como posicionamiento personal</div>
      <div class="msg-body" style="color:var(--sub);">La tendencia tiene una dimensión de identidad muy fuerte. Ser de los que "se dan el lujo de aburrirse" es un nuevo marcador de sofisticación. Las marcas que entienden esto no hablan de precio ni de calidad — hablan de pertenencia a un grupo que ha "despertado".</div>
      <div class="msg-do">
        <div class="msg-do-item"><span style="color:var(--p);">+</span> Contenido sin VOZ en OFF — solo imágenes y sonido ambiente</div>
        <div class="msg-do-item"><span style="color:var(--p);">+</span> Planos largos, sin cortes rápidos, sin música de hype</div>
        <div class="msg-do-item"><span style="color:var(--p);">+</span> Silencio como elemento creativo de la pieza</div>
      </div>
      <div class="msg-dont" style="background:rgba(226,75,74,.06);color:var(--r);">✕ No usar: música motivacional ni planos de 1 segundo</div>
    </div>
    <div class="msg-card" style="background:rgba(186,117,23,.06);border:0.5px solid rgba(186,117,23,.2);">
      <div class="msg-type" style="color:var(--a);">Territorio C — Localismo</div>
      <div class="msg-headline" style="color:var(--ink);">España como paisaje de lo lento</div>
      <div class="msg-body" style="color:var(--sub);">El interior de España —La Mancha, Extremadura, Sierra de Gredos, la Axarquía malagueña, Priorat— se está convirtiendo en símbolo de esta estética. Las marcas que anclan sus mensajes en la geografía específica española conectan más que las que usan imágenes genéricas de playa.</div>
      <div class="msg-do">
        <div class="msg-do-item"><span style="color:var(--a);">+</span> Geografías concretas, nombres de pueblos reales</div>
        <div class="msg-do-item"><span style="color:var(--a);">+</span> Colaborar con creadores locales pequeños (5K–50K)</div>
        <div class="msg-do-item"><span style="color:var(--a);">+</span> Sonidos reales: grillos, agua, viento, silencio</div>
      </div>
      <div class="msg-dont" style="background:rgba(226,75,74,.06);color:var(--r);">✕ No usar: playa de Ibiza con DJ — totalmente opuesto</div>
    </div>
  </div>
</div>

<!-- EVOLUCIÓN 3 MESES -->
<div class="section">
  <div class="sec-head">
    <span class="sec-num">05</span>
    <span class="sec-title">Hoja de ruta — los próximos 3 meses</span>
  </div>
  <div class="timeline">
    <div class="tl-row">
      <div class="tl-month" style="padding-top:.875rem;">
        <div class="tl-month-label">Jun</div>
        <div class="tl-month-sub">2026</div>
      </div>
      <div class="tl-content">
        <div class="tl-phase">Fase 1 — Aceleración masiva</div>
        <div class="tl-events">
          <div class="tl-ev"><span class="tl-dot" style="background:var(--r);"></span>La tendencia pasa de nicho digital a conversación mainstream en medios y prensa generalista española</div>
          <div class="tl-ev"><span class="tl-dot" style="background:var(--r);"></span>Primeras marcas grandes (turismo, gran consumo) activan campañas — riesgo de saturación temprana</div>
          <div class="tl-ev"><span class="tl-dot" style="background:var(--r);"></span>Los pueblos de interior españoles registran pico de búsquedas y reservas — oportunidad turismo rural</div>
        </div>
        <div class="tl-action"><i class="ti ti-bolt" style="color:var(--r);font-size:14px;" aria-hidden="true"></i><span class="tl-action-label">Acción urgente: lanzar comunicación ahora — la ventana de autenticidad se cierra en ~8 semanas</span></div>
      </div>
    </div>
    <div class="tl-row">
      <div class="tl-month">
        <div class="tl-month-label">Jul</div>
        <div class="tl-month-sub">2026</div>
      </div>
      <div class="tl-content">
        <div class="tl-phase">Fase 2 — Pico y primeras grietas</div>
        <div class="tl-events">
          <div class="tl-ev"><span class="tl-dot" style="background:var(--a);"></span>Aparece la contratendencia: el "quiet quitting del slow living" — ironía sobre quienes lo hacen performativo</div>
          <div class="tl-ev"><span class="tl-dot" style="background:var(--a);"></span>Las marcas que entraron oportunistamente empiezan a recibir crítica de la comunidad original</div>
          <div class="tl-ev"><span class="tl-dot" style="background:var(--a);"></span>Pico de cobertura en medios: El País, Vogue ES, podcasts de cultura publican reportajes sobre el fenómeno</div>
          <div class="tl-ev"><span class="tl-dot" style="background:var(--a);"></span>Señales de bifurcación: lujo silencioso (SHA, Six Senses) vs slow accesible (casas rurales, mercados locales)</div>
        </div>
        <div class="tl-action"><i class="ti ti-adjustments" style="color:var(--a);font-size:14px;" aria-hidden="true"></i><span class="tl-action-label">Ajustar: ser más específico y local — la generalidad mata la credibilidad</span></div>
      </div>
    </div>
    <div class="tl-row">
      <div class="tl-month">
        <div class="tl-month-label">Ago</div>
        <div class="tl-month-sub">2026</div>
      </div>
      <div class="tl-content">
        <div class="tl-phase">Fase 3 — Cristalización cultural</div>
        <div class="tl-events">
          <div class="tl-ev"><span class="tl-dot" style="background:var(--g);"></span>El concepto deja de ser tendencia y se convierte en valor estable — como lo fue el "bienestar" en 2018-2020</div>
          <div class="tl-ev"><span class="tl-dot" style="background:var(--g);"></span>Las marcas que invirtieron en comunidad (no en campaña) son las que retienen capital cultural</div>
          <div class="tl-ev"><span class="tl-dot" style="background:var(--g);"></span>Nueva señal emergente a vigilar: el "slow work" — aplicación del silencio al entorno laboral y productividad</div>
          <div class="tl-ev"><span class="tl-dot" style="background:var(--g);"></span>El otoño relanza la tendencia en clave interior: hogares cálidos, rutinas lentas, vuelta a la cocina de temporada</div>
        </div>
        <div class="tl-action"><i class="ti ti-seedling" style="color:var(--g);font-size:14px;" aria-hidden="true"></i><span class="tl-action-label">Anticipar: preparar campaña otoño con "slow home" — la tendencia muta pero no muere</span></div>
      </div>
    </div>
  </div>
</div>

<!-- RIESGOS -->
<div class="section">
  <div class="sec-head">
    <span class="sec-num">06</span>
    <span class="sec-title">Riesgos para marcas — cómo no arruinarlo</span>
  </div>
  <div class="risk-grid">
    <div class="risk-card">
      <div class="risk-level lvl-high">Riesgo alto</div>
      <div class="risk-title">El greenwashing del silencio</div>
      <div class="risk-body">Usar la estética slow sin cambiar nada en producto, cadena o valores. La Gen Z tiene memoria perfecta y Twitter/X funciona como tribunal. Una inconsistencia detectada anula meses de inversión.</div>
    </div>
    <div class="risk-card">
      <div class="risk-level lvl-high">Riesgo alto</div>
      <div class="risk-title">Producción gritona</div>
      <div class="risk-body">El error más común: hablar de silencio con una pieza audiovisual con música acelerada, cortes cada segundo y voz en off con tono urgente. La forma es el mensaje. Si la producción contradice el valor, la audiencia lo ve inmediatamente.</div>
    </div>
    <div class="risk-card">
      <div class="risk-level lvl-med">Riesgo medio</div>
      <div class="risk-title">Elitismo involuntario</div>
      <div class="risk-body">El "lujo del silencio" puede percibirse como algo solo para quien puede permitirse no trabajar. Las marcas de gran consumo deben anclar el mensaje en lo cotidiano y accesible, no en retiros de 2.000€ en Mallorca.</div>
    </div>
    <div class="risk-card">
      <div class="risk-level lvl-med">Riesgo medio</div>
      <div class="risk-title">Llegar tarde al pico</div>
      <div class="risk-body">Activar en julio cuando todo el mundo ya está hablando de ello. A esa altura ya no eres relevante, solo eres otra marca más. El valor está en llegar antes de la saturación, no cuando el trending topic ya está viejo.</div>
    </div>
    <div class="risk-card">
      <div class="risk-level lvl-low">Riesgo bajo</div>
      <div class="risk-title">Sobredosis de beige</div>
      <div class="risk-body">La estética slow tiende al lino, la tierra y el tono monocromo. Si todas las marcas del sector van al mismo territorio visual, la diferenciación se complica. Hay espacio para slow con color, humor o rareza.</div>
    </div>
    <div class="risk-card">
      <div class="risk-level lvl-low">Riesgo bajo</div>
      <div class="risk-title">Ignorar el segmento 40+</div>
      <div class="risk-body">Aunque Gen Z y Millennials lideran la narrativa digital, la filosofía slow resonó antes y más profundamente en mayores de 40. Muchas marcas desperdician este segmento con alta capacidad de gasto y muy alineado con los valores.</div>
    </div>
  </div>
</div>

<!-- CTA -->
<div class="cta-bar">
  <button class="cta-btn primary" data-ves-assistant-question="Diseña una estrategia completa de 8 semanas para una marca española de turismo rural que quiera activar el Verano Silencioso: briefing creativo, calendario de contenidos, perfil de influencer ideal, KPIs y presupuesto orientativo">Estrategia completa para turismo rural ↗</button>
  <button class="cta-btn" data-ves-assistant-question="Analiza la próxima tendencia emergente que podría venir después del Verano Silencioso en España: el slow work o la desaceleración laboral. Señales actuales, timing y oportunidades de marca">Próxima señal: slow work ↗</button>
  <button class="cta-btn" data-ves-assistant-question="Dame 10 ideas concretas de branded content (formatos, plataformas, tono) que una marca pueda producir ahora mismo para conectar con el Verano Silencioso en España sin parecer oportunista">10 ideas de branded content ↗</button>
</div>

</div>
</section>
