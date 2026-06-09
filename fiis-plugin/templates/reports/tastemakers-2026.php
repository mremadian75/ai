<?php
// v0.9.31.5 — Curated static report template. Renders verbatim narrative
// content. $report is passed by the renderer; an optional title override
// is rendered above the curated hero when present, otherwise the curated
// hero stands on its own.
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/_partials.php';
$curated_title_override = isset($report['title']) ? (string) $report['title'] : '';
?>
<section class="ves-report ves-report-curated ves-report-tastemakers-2026" aria-labelledby="ves-report-tastemakers-2026-title">
<?php if ($curated_title_override !== '') : ?>
  <h1 id="ves-report-tastemakers-2026-title" class="ves-report-curated-title"><?php echo esc_html($curated_title_override); ?></h1>
<?php else : ?>
  <h1 id="ves-report-tastemakers-2026-title" class="ves-sr-only"><?php echo esc_html__('Del influencer al tastemaker — economía de confianza 2026', 'ves'); ?></h1>
<?php endif; ?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap');
.ves-report-curated.ves-report-tastemakers-2026{
  --tk:#E24B4A; --tk2:#A32D2D; --tkl:rgba(226,75,74,.09);
  --g:#1D9E75; --gl:rgba(29,158,117,.09);
  --b:#378ADD; --bl:rgba(55,138,221,.09);
  --p:#7F77DD; --pl:rgba(127,119,221,.09);
  --a:#BA7517; --al:rgba(186,117,23,.09);
  --pk:#D4537E;
  --ink:var(--color-text-primary); --sub:var(--color-text-secondary);
  --bg:var(--color-background-primary); --bg2:var(--color-background-secondary);
  --bd:var(--color-border-tertiary); --bd2:var(--color-border-secondary);
  --ff:'Bricolage Grotesque',sans-serif; --fm:'IBM Plex Mono',monospace;
}
.ves-report-curated.ves-report-tastemakers-2026 *{box-sizing:border-box;}
.ves-report-curated.ves-report-tastemakers-2026 .root{font-family:var(--ff);}

/* HERO */
.ves-report-curated.ves-report-tastemakers-2026 .hero{padding:1.5rem 1.25rem 1.25rem;border-bottom:0.5px solid var(--bd);}
.ves-report-curated.ves-report-tastemakers-2026 .hero-eyebrow{display:flex;align-items:center;gap:8px;margin-bottom:.75rem;}
.ves-report-curated.ves-report-tastemakers-2026 .eyebrow-tag{font-family:var(--fm);font-size:9px;color:var(--sub);letter-spacing:.6px;text-transform:uppercase;}
.ves-report-curated.ves-report-tastemakers-2026 .eyebrow-sep{width:20px;height:0.5px;background:var(--bd2);}
.ves-report-curated.ves-report-tastemakers-2026 .hero-h1{font-size:28px;font-weight:800;color:var(--ink);letter-spacing:-1.2px;line-height:1.05;margin-bottom:.875rem;}
.ves-report-curated.ves-report-tastemakers-2026 .hero-h1 em{font-style:normal;color:var(--tk);}
.ves-report-curated.ves-report-tastemakers-2026 .hero-sub{font-size:13px;color:var(--sub);line-height:1.65;max-width:560px;margin-bottom:1rem;}
.ves-report-curated.ves-report-tastemakers-2026 .hero-stats{display:flex;gap:1.5rem;flex-wrap:wrap;}
.ves-report-curated.ves-report-tastemakers-2026 .hstat{display:flex;flex-direction:column;}
.ves-report-curated.ves-report-tastemakers-2026 .hstat-val{font-size:22px;font-weight:800;color:var(--ink);letter-spacing:-1px;line-height:1;}
.ves-report-curated.ves-report-tastemakers-2026 .hstat-val.r{color:var(--tk);}
.ves-report-curated.ves-report-tastemakers-2026 .hstat-val.g{color:var(--g);}
.ves-report-curated.ves-report-tastemakers-2026 .hstat-label{font-family:var(--fm);font-size:9px;color:var(--sub);margin-top:3px;}

/* SECCIÓN */
.ves-report-curated.ves-report-tastemakers-2026 .sec{padding:1.25rem;border-bottom:0.5px solid var(--bd);}
.ves-report-curated.ves-report-tastemakers-2026 .sec-hd{display:flex;align-items:center;gap:8px;margin-bottom:1rem;}
.ves-report-curated.ves-report-tastemakers-2026 .sec-n{font-family:var(--fm);font-size:10px;color:var(--sub);}
.ves-report-curated.ves-report-tastemakers-2026 .sec-t{font-size:13px;font-weight:700;color:var(--ink);letter-spacing:-.4px;}
.ves-report-curated.ves-report-tastemakers-2026 .sec-aside{font-family:var(--fm);font-size:9px;color:var(--sub);margin-left:auto;}

/* COMPARATIVA INFLUENCER VS TASTEMAKER */
.ves-report-curated.ves-report-tastemakers-2026 .compare-wrap{display:grid;grid-template-columns:1fr 40px 1fr;gap:0;align-items:start;}
.ves-report-curated.ves-report-tastemakers-2026 .compare-col{display:flex;flex-direction:column;gap:0;}
.ves-report-curated.ves-report-tastemakers-2026 .compare-divider{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding-top:44px;}
.ves-report-curated.ves-report-tastemakers-2026 .divider-line{width:0.5px;background:var(--bd2);flex:1;}
.ves-report-curated.ves-report-tastemakers-2026 .divider-vs{font-family:var(--fm);font-size:10px;color:var(--sub);}
.ves-report-curated.ves-report-tastemakers-2026 .col-header{padding:.75rem 1rem;border-radius:var(--border-radius-lg) var(--border-radius-lg) 0 0;margin-bottom:0;}
.ves-report-curated.ves-report-tastemakers-2026 .col-header-inf{background:rgba(226,75,74,.07);border:0.5px solid rgba(226,75,74,.2);}
.ves-report-curated.ves-report-tastemakers-2026 .col-header-tm{background:rgba(29,158,117,.07);border:0.5px solid rgba(29,158,117,.2);}
.ves-report-curated.ves-report-tastemakers-2026 .col-header-label{font-family:var(--fm);font-size:9px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.ves-report-curated.ves-report-tastemakers-2026 .col-header-name{font-size:14px;font-weight:700;color:var(--ink);}
.ves-report-curated.ves-report-tastemakers-2026 .compare-row{display:contents;}
.ves-report-curated.ves-report-tastemakers-2026 .cell{padding:10px 12px;font-size:11px;color:var(--ink);line-height:1.4;border:0.5px solid var(--bd);border-top:none;}
.ves-report-curated.ves-report-tastemakers-2026 .cell:first-child{border-radius:0;}
.ves-report-curated.ves-report-tastemakers-2026 .cell-label{font-family:var(--fm);font-size:9px;color:var(--sub);margin-bottom:3px;text-transform:uppercase;letter-spacing:.3px;}
.ves-report-curated.ves-report-tastemakers-2026 .cell-val{font-size:11px;color:var(--ink);line-height:1.4;}
.ves-report-curated.ves-report-tastemakers-2026 .cell-inf{background:rgba(226,75,74,.03);}
.ves-report-curated.ves-report-tastemakers-2026 .cell-tm{background:rgba(29,158,117,.03);}
.ves-report-curated.ves-report-tastemakers-2026 .cell-last{border-radius:0 0 var(--border-radius-lg) var(--border-radius-lg);}

/* PIRAMIDE TIERS */
.ves-report-curated.ves-report-tastemakers-2026 .tiers{display:flex;flex-direction:column;gap:8px;}
.ves-report-curated.ves-report-tastemakers-2026 .tier-row{display:grid;grid-template-columns:120px 1fr 80px;gap:12px;align-items:center;}
.ves-report-curated.ves-report-tastemakers-2026 .tier-bar-wrap{height:36px;border-radius:var(--border-radius-md);display:flex;align-items:center;padding:0 12px;position:relative;overflow:hidden;}
.ves-report-curated.ves-report-tastemakers-2026 .tier-bar-fill{position:absolute;left:0;top:0;height:100%;border-radius:var(--border-radius-md);}
.ves-report-curated.ves-report-tastemakers-2026 .tier-bar-text{position:relative;font-size:11px;font-weight:600;z-index:1;}
.ves-report-curated.ves-report-tastemakers-2026 .tier-label{font-family:var(--fm);font-size:10px;color:var(--sub);}
.ves-report-curated.ves-report-tastemakers-2026 .tier-label strong{font-size:11px;color:var(--ink);display:block;}
.ves-report-curated.ves-report-tastemakers-2026 .tier-meta{font-family:var(--fm);font-size:10px;text-align:right;}
.ves-report-curated.ves-report-tastemakers-2026 .tier-eng{font-weight:700;}

/* TRUST ECONOMY */
.ves-report-curated.ves-report-tastemakers-2026 .trust-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.ves-report-curated.ves-report-tastemakers-2026 .trust-card{border-radius:var(--border-radius-lg);padding:1rem;border:0.5px solid var(--bd);}
.ves-report-curated.ves-report-tastemakers-2026 .tc-num{font-size:32px;font-weight:800;line-height:1;letter-spacing:-2px;margin-bottom:4px;}
.ves-report-curated.ves-report-tastemakers-2026 .tc-label{font-size:12px;font-weight:700;color:var(--ink);margin-bottom:6px;}
.ves-report-curated.ves-report-tastemakers-2026 .tc-desc{font-size:11px;color:var(--sub);line-height:1.5;}
.ves-report-curated.ves-report-tastemakers-2026 .tc-src{font-family:var(--fm);font-size:9px;color:var(--sub);margin-top:8px;}

/* CÓMO ENCONTRARLOS */
.ves-report-curated.ves-report-tastemakers-2026 .find-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:12px;}
.ves-report-curated.ves-report-tastemakers-2026 .find-card{background:var(--bg2);border-radius:var(--border-radius-lg);padding:1rem;}
.ves-report-curated.ves-report-tastemakers-2026 .find-step{font-family:var(--fm);font-size:9px;color:var(--sub);margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px;}
.ves-report-curated.ves-report-tastemakers-2026 .find-title{font-size:12px;font-weight:700;color:var(--ink);margin-bottom:5px;}
.ves-report-curated.ves-report-tastemakers-2026 .find-body{font-size:11px;color:var(--sub);line-height:1.5;}
.ves-report-curated.ves-report-tastemakers-2026 .find-tip{font-family:var(--fm);font-size:9px;color:var(--b);margin-top:6px;display:flex;align-items:center;gap:4px;}

/* TIPOS DE TASTEMAKER EN ESPAÑA */
.ves-report-curated.ves-report-tastemakers-2026 .tipos-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.ves-report-curated.ves-report-tastemakers-2026 .tipo-card{border:0.5px solid var(--bd);border-radius:var(--border-radius-lg);padding:1rem;cursor:pointer;transition:border-color .15s;}
.ves-report-curated.ves-report-tastemakers-2026 .tipo-card:hover{border-color:var(--tk);}
.ves-report-curated.ves-report-tastemakers-2026 .tipo-icon{font-size:22px;margin-bottom:8px;}
.ves-report-curated.ves-report-tastemakers-2026 .tipo-name{font-size:12px;font-weight:700;color:var(--ink);margin-bottom:4px;}
.ves-report-curated.ves-report-tastemakers-2026 .tipo-seg{font-family:var(--fm);font-size:9px;padding:2px 7px;border-radius:10px;display:inline-block;margin-bottom:6px;}
.ves-report-curated.ves-report-tastemakers-2026 .tipo-desc{font-size:10px;color:var(--sub);line-height:1.5;margin-bottom:8px;}
.ves-report-curated.ves-report-tastemakers-2026 .tipo-ex{background:var(--bg2);border-radius:6px;padding:6px 8px;font-family:var(--fm);font-size:9px;color:var(--sub);}
.ves-report-curated.ves-report-tastemakers-2026 .tipo-ex strong{color:var(--ink);font-size:9px;}

/* BRIEFING */
.ves-report-curated.ves-report-tastemakers-2026 .brief-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;}
.ves-report-curated.ves-report-tastemakers-2026 .brief-do{padding:1rem;border-right:0.5px solid var(--bd);}
.ves-report-curated.ves-report-tastemakers-2026 .brief-dont{padding:1rem;}
.ves-report-curated.ves-report-tastemakers-2026 .brief-head{font-family:var(--fm);font-size:10px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.ves-report-curated.ves-report-tastemakers-2026 .brief-item{font-size:11px;color:var(--ink);line-height:1.4;margin-bottom:8px;padding-left:14px;position:relative;}
.ves-report-curated.ves-report-tastemakers-2026 .brief-item::before{position:absolute;left:0;font-size:12px;top:-1px;}
.ves-report-curated.ves-report-tastemakers-2026 .brief-do .brief-item::before{content:'✓';color:var(--g);}
.ves-report-curated.ves-report-tastemakers-2026 .brief-dont .brief-item::before{content:'✕';color:var(--tk);}
.ves-report-curated.ves-report-tastemakers-2026 .brief-item small{display:block;font-size:10px;color:var(--sub);margin-top:2px;}

/* MÉTRICAS */
.ves-report-curated.ves-report-tastemakers-2026 .metrics-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
.ves-report-curated.ves-report-tastemakers-2026 .met{background:var(--bg2);border-radius:var(--border-radius-md);padding:.875rem;}
.ves-report-curated.ves-report-tastemakers-2026 .met-label{font-family:var(--fm);font-size:9px;color:var(--sub);margin-bottom:4px;}
.ves-report-curated.ves-report-tastemakers-2026 .met-val{font-size:20px;font-weight:800;color:var(--ink);letter-spacing:-1px;line-height:1;}
.ves-report-curated.ves-report-tastemakers-2026 .met-sub{font-size:10px;color:var(--sub);margin-top:3px;}

/* MARCAS ES EJEMPLOS */
.ves-report-curated.ves-report-tastemakers-2026 .brands-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;}
.ves-report-curated.ves-report-tastemakers-2026 .br{border-radius:var(--border-radius-lg);border:0.5px solid var(--bd);padding:1rem;cursor:pointer;transition:border-color .15s;}
.ves-report-curated.ves-report-tastemakers-2026 .br:hover{border-color:var(--tk);}
.ves-report-curated.ves-report-tastemakers-2026 .br-top{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
.ves-report-curated.ves-report-tastemakers-2026 .br-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.ves-report-curated.ves-report-tastemakers-2026 .br-name{font-size:12px;font-weight:700;color:var(--ink);}
.ves-report-curated.ves-report-tastemakers-2026 .br-sector{font-family:var(--fm);font-size:9px;color:var(--sub);margin-left:auto;}
.ves-report-curated.ves-report-tastemakers-2026 .br-body{font-size:11px;color:var(--sub);line-height:1.5;margin-bottom:8px;}
.ves-report-curated.ves-report-tastemakers-2026 .br-quote{font-size:11px;color:var(--ink);font-style:italic;background:var(--bg2);border-left:2px solid var(--tk);padding:6px 10px;border-radius:0 6px 6px 0;}

/* CTA */
.ves-report-curated.ves-report-tastemakers-2026 .cta{padding:1rem 1.25rem;display:flex;gap:8px;flex-wrap:wrap;}
.ves-report-curated.ves-report-tastemakers-2026 .btn{font-family:var(--ff);font-size:11px;padding:7px 14px;border-radius:20px;border:0.5px solid var(--bd);background:transparent;color:var(--ink);cursor:pointer;transition:all .15s;}
.ves-report-curated.ves-report-tastemakers-2026 .btn:hover{border-color:var(--tk);color:var(--tk);}
.ves-report-curated.ves-report-tastemakers-2026 .btn.pri{background:var(--tk);border-color:var(--tk);color:#fff;}
.ves-report-curated.ves-report-tastemakers-2026 .btn.pri:hover{background:var(--tk2);}


/* v0.9.31.7 responsive pass — scoped to this curated template only. */
@media (max-width: 900px) {
.ves-report-curated.ves-report-tastemakers-2026 .hero-h1{font-size:clamp(22px,5vw,28px);}
.ves-report-curated.ves-report-tastemakers-2026 .hero-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
.ves-report-curated.ves-report-tastemakers-2026 .compare-wrap{grid-template-columns:1fr;gap:10px;}
.ves-report-curated.ves-report-tastemakers-2026 .compare-divider{display:none;}
.ves-report-curated.ves-report-tastemakers-2026 .tier-row{grid-template-columns:120px 1fr;}
.ves-report-curated.ves-report-tastemakers-2026 .tier-meta{text-align:left;grid-column:2;}
.ves-report-curated.ves-report-tastemakers-2026 .trust-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
.ves-report-curated.ves-report-tastemakers-2026 .find-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-tastemakers-2026 .tipos-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
.ves-report-curated.ves-report-tastemakers-2026 .brief-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-tastemakers-2026 .brief-do{border-right:0;border-bottom:0.5px solid var(--bd);}
.ves-report-curated.ves-report-tastemakers-2026 .metrics-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
.ves-report-curated.ves-report-tastemakers-2026 .brands-grid{grid-template-columns:1fr;}
}
@media (max-width: 560px) {
.ves-report-curated.ves-report-tastemakers-2026 .hero{padding:1.1rem 1rem 1rem;}
.ves-report-curated.ves-report-tastemakers-2026 .sec{padding:1rem;}
.ves-report-curated.ves-report-tastemakers-2026 .hero-h1{font-size:clamp(20px,8vw,25px);letter-spacing:-.8px;}
.ves-report-curated.ves-report-tastemakers-2026 .hero-sub{font-size:12px;}
.ves-report-curated.ves-report-tastemakers-2026 .hero-stats{grid-template-columns:1fr;}
.ves-report-curated.ves-report-tastemakers-2026 .sec-hd{align-items:flex-start;flex-wrap:wrap;}
.ves-report-curated.ves-report-tastemakers-2026 .sec-aside{margin-left:0;width:100%;}
.ves-report-curated.ves-report-tastemakers-2026 .tier-row{grid-template-columns:1fr;gap:6px;}
.ves-report-curated.ves-report-tastemakers-2026 .tier-meta{grid-column:auto;}
.ves-report-curated.ves-report-tastemakers-2026 .tier-bar-wrap{min-height:42px;height:auto;}
.ves-report-curated.ves-report-tastemakers-2026 .trust-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-tastemakers-2026 .tipos-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-tastemakers-2026 .metrics-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-tastemakers-2026 .br-top{align-items:flex-start;flex-wrap:wrap;}
.ves-report-curated.ves-report-tastemakers-2026 .br-sector{margin-left:0;width:100%;}
.ves-report-curated.ves-report-tastemakers-2026 .cta{padding:.875rem 1rem;}
.ves-report-curated.ves-report-tastemakers-2026 .btn{white-space:normal;}
}
</style>

<div class="root">

<!-- HERO -->
<div class="hero">
  <div class="hero-eyebrow">
    <span class="eyebrow-tag">PULSO · Análisis en profundidad</span>
    <span class="eyebrow-sep"></span>
    <span class="eyebrow-tag">Economía de confianza · TikTok 2026</span>
  </div>
  <h1 class="hero-h1">Del influencer al <em>tastemaker</em><br>La autoridad ya no se compra.<br>Se construye.</h1>
  <p class="hero-sub">El movimiento #tastemakers en TikTok representa el colapso del modelo de influencia por alcance y el nacimiento de un nuevo paradigma donde la credibilidad específica, la comunidad pequeña y la recomendación genuina generan más conversión que cualquier macro-campaña. Esta es la guía completa para entenderlo y activarlo en España.</p>
  <div class="hero-stats">
    <div class="hstat"><div class="hstat-val r">92%</div><div class="hstat-label">consumidores confían en recomendaciones de pares vs 38% en contenido de marca</div></div>
    <div class="hstat"><div class="hstat-val">×1.5</div><div class="hstat-label">más probabilidad de recompra con interacción directa tastemaker</div></div>
    <div class="hstat"><div class="hstat-val g">11.9%</div><div class="hstat-label">engagement promedio nano-tastemakers en TikTok (vs <1% macro)</div></div>
    <div class="hstat"><div class="hstat-val">71%</div><div class="hstat-label">marcas aumentando presupuesto creador en 2026</div></div>
  </div>
</div>

<!-- COMPARATIVA -->
<div class="sec">
  <div class="sec-hd">
    <span class="sec-n">01</span>
    <span class="sec-t">Influencer clásico vs tastemaker — la diferencia que lo cambia todo</span>
  </div>
  <div class="compare-wrap">
    <div class="compare-col">
      <div class="col-header col-header-inf">
        <div class="col-header-label" style="color:var(--tk);">Modelo agotado</div>
        <div class="col-header-name">Influencer clásico</div>
      </div>
      <div class="cell cell-inf">
        <div class="cell-label">Fuente de autoridad</div>
        <div class="cell-val">Número de seguidores. La autoridad se construye por volumen visible — más followers = más válido. Se puede comprar y se puede falsear.</div>
      </div>
      <div class="cell cell-inf">
        <div class="cell-label">Relación con la marca</div>
        <div class="cell-val">Transaccional y puntual. Un contrato, un post, un pago. La audiencia lo sabe. El #ad destruye el 80% de la credibilidad del mensaje.</div>
      </div>
      <div class="cell cell-inf">
        <div class="cell-label">Tipo de contenido</div>
        <div class="cell-val">Producción pulida, iluminación de estudio, guión de briefing. Cuanto más perfecto, más patrocinado parece. La audiencia 2026 lo identifica en segundos.</div>
      </div>
      <div class="cell cell-inf">
        <div class="cell-label">Alcance vs conversión</div>
        <div class="cell-val">Alto alcance, baja conversión. Un post de 2M de seguidores puede generar menos ventas reales que uno de 40K en nicho correcto.</div>
      </div>
      <div class="cell cell-inf">
        <div class="cell-label">Audiencia</div>
        <div class="cell-val">Amplia, heterogénea, pasiva. Siguen por entretenimiento, no por pericia. La confianza es limitada porque el influencer opina de todo.</div>
      </div>
      <div class="cell cell-inf cell-last">
        <div class="cell-label">Coste por impacto real</div>
        <div class="cell-val">Alto y decreciente. CPM inflado, engagement en caída libre. Los megainfluencers de 5M+ caen a tasas de interacción del 2.6%.</div>
      </div>
    </div>
    <div class="compare-divider">
      <div class="divider-line"></div>
      <div class="divider-vs">VS</div>
      <div class="divider-line"></div>
    </div>
    <div class="compare-col">
      <div class="col-header col-header-tm">
        <div class="col-header-label" style="color:var(--g);">Modelo emergente</div>
        <div class="col-header-name">Tastemaker de nicho</div>
      </div>
      <div class="cell cell-tm">
        <div class="cell-label">Fuente de autoridad</div>
        <div class="cell-val">Conocimiento específico y coherencia contextual. La autoridad no se compra — emerge de años hablando de lo mismo con rigor. No se puede falsear.</div>
      </div>
      <div class="cell cell-tm">
        <div class="cell-label">Relación con la marca</div>
        <div class="cell-val">Orgánica o de co-creación. Idealmente ya usa el producto antes de la colaboración. La audiencia percibe la recomendación como la de un amigo experto.</div>
      </div>
      <div class="cell cell-tm">
        <div class="cell-label">Tipo de contenido</div>
        <div class="cell-val">Raw, desde el móvil, con opinión genuina. Muestra el producto como parte de su vida real, no como objeto de deseo artificial. La imperfección es la señal de confianza.</div>
      </div>
      <div class="cell cell-tm">
        <div class="cell-label">Alcance vs conversión</div>
        <div class="cell-val">Alcance limitado, conversión altísima. Su audiencia compra porque confía. Un tastemaker de 25K en cosmética puede generar más ventas que uno de 800K generalista.</div>
      </div>
      <div class="cell cell-tm">
        <div class="cell-label">Audiencia</div>
        <div class="cell-val">Pequeña, comprometida, activa. Siguen por pericia en un tema específico. La confianza es máxima porque el tastemaker solo habla de lo que sabe de verdad.</div>
      </div>
      <div class="cell cell-tm cell-last">
        <div class="cell-label">Coste por impacto real</div>
        <div class="cell-val">Bajo y creciente. Los nano-tastemakers (bajo 10K) alcanzan hasta 11.9% de engagement en TikTok — diez veces más que los macro. El CPM real es imbatible.</div>
      </div>
    </div>
  </div>
</div>

<!-- PIRÁMIDE TIERS -->
<div class="sec">
  <div class="sec-hd">
    <span class="sec-n">02</span>
    <span class="sec-t">El espectro del tastemaker — dónde vive el mayor ROI</span>
    <span class="sec-aside">Datos: IAB Spain + Influencer Marketing Factory 2025-26</span>
  </div>
  <div class="tiers">
    <div class="tier-row">
      <div><div class="tier-label"><strong>Nano-tastemaker</strong>1K – 10K seg.</div></div>
      <div class="tier-bar-wrap"><div class="tier-bar-fill" style="width:96%;background:rgba(29,158,117,.18);"></div><span class="tier-bar-text" style="color:var(--g);">Comunidad-tribu. Máxima confianza, casi conversación 1:1</span></div>
      <div class="tier-meta"><div class="tier-eng" style="color:var(--g);">11.9%</div><div style="font-size:9px;color:var(--sub);">engagement TikTok</div></div>
    </div>
    <div class="tier-row">
      <div><div class="tier-label"><strong>Micro-tastemaker</strong>10K – 100K seg.</div></div>
      <div class="tier-bar-wrap"><div class="tier-bar-fill" style="width:80%;background:rgba(55,138,221,.15);"></div><span class="tier-bar-text" style="color:var(--b);">El punto dulce. Escala + credibilidad. Zona ideal para marcas</span></div>
      <div class="tier-meta"><div class="tier-eng" style="color:var(--b);">6.89%</div><div style="font-size:9px;color:var(--sub);">engagement promedio</div></div>
    </div>
    <div class="tier-row">
      <div><div class="tier-label"><strong>Mid-tastemaker</strong>100K – 500K seg.</div></div>
      <div class="tier-bar-wrap"><div class="tier-bar-fill" style="width:55%;background:rgba(127,119,221,.15);"></div><span class="tier-bar-text" style="color:var(--p);">Escala con nicho aún definido. Equilibrio reach-confianza</span></div>
      <div class="tier-meta"><div class="tier-eng" style="color:var(--p);">3.4%</div><div style="font-size:9px;color:var(--sub);">engagement estimado</div></div>
    </div>
    <div class="tier-row">
      <div><div class="tier-label"><strong>Macro-influencer</strong>500K – 5M seg.</div></div>
      <div class="tier-bar-wrap"><div class="tier-bar-fill" style="width:30%;background:rgba(186,117,23,.12);"></div><span class="tier-bar-text" style="color:var(--a);">Awareness masivo, confianza media. Sirve para lanzamientos</span></div>
      <div class="tier-meta"><div class="tier-eng" style="color:var(--a);">2.1%</div><div style="font-size:9px;color:var(--sub);">engagement</div></div>
    </div>
    <div class="tier-row">
      <div><div class="tier-label"><strong>Mega-influencer</strong>+5M seg.</div></div>
      <div class="tier-bar-wrap"><div class="tier-bar-fill" style="width:14%;background:rgba(226,75,74,.10);"></div><span class="tier-bar-text" style="color:var(--tk);">Visibilidad pura. Sin confianza real. Cada vez menos eficiente</span></div>
      <div class="tier-meta"><div class="tier-eng" style="color:var(--tk);">2.61%</div><div style="font-size:9px;color:var(--sub);">y cayendo</div></div>
    </div>
  </div>
  <div style="margin-top:10px;padding:10px 12px;background:rgba(29,158,117,.06);border-radius:var(--border-radius-md);border-left:2px solid var(--g);font-size:11px;color:var(--ink);line-height:1.5;">
    <strong style="font-size:11px;">El insight que define la estrategia:</strong> Una red de 20 micro-tastemakers (20K seg. cada uno) genera más conversiones reales que 1 macro-influencer de 400K, cuesta entre 3 y 8 veces menos, y diversifica el riesgo de reputación. El problema es que requiere más gestión. Por eso muchas marcas siguen apostando por el macro aunque el ROI sea peor.
  </div>
</div>

<!-- TRUST ECONOMY DATOS -->
<div class="sec">
  <div class="sec-hd">
    <span class="sec-n">03</span>
    <span class="sec-t">Los números de la economía de confianza en 2026</span>
  </div>
  <div class="trust-grid">
    <div class="trust-card">
      <div class="tc-num" style="color:var(--tk);">92%</div>
      <div class="tc-label">Confían en personas, no en marcas</div>
      <div class="tc-desc">De los consumidores confían más en las recomendaciones de pares y creadores que en el contenido generado directamente por marcas. Esta brecha no para de crecer.</div>
      <div class="tc-src">Fuente: Influee / Sprout Social 2026</div>
    </div>
    <div class="trust-card">
      <div class="tc-num" style="color:var(--b);">×1.7</div>
      <div class="tc-label">Millennials prueban marcas nuevas</div>
      <div class="tc-desc">Los millennials de TikTok son 1.7 veces más propensos a probar una marca nueva cuando la recomendación viene de su comunidad de confianza específica.</div>
      <div class="tc-src">Fuente: TikTok Next Report 2026</div>
    </div>
    <div class="trust-card">
      <div class="tc-num" style="color:var(--g);">×1.5</div>
      <div class="tc-label">Tasa de recompra con interacción</div>
      <div class="tc-desc">Los usuarios de TikTok recompran 1.5 veces más cuando los anuncios fomentan interacción directa —comentarios, participación activa, co-creación— frente a los que no lo hacen.</div>
      <div class="tc-src">Fuente: TikTok Next Report 2026</div>
    </div>
    <div class="trust-card">
      <div class="tc-num" style="color:var(--p);">80%</div>
      <div class="tc-label">Prefieren contenido auténtico</div>
      <div class="tc-desc">El contenido con producción natural y baja sobreproducción supera al contenido de estudio en métricas de retención y conversión en TikTok. El 70% del contenido efectivo en España es hiperrealista.</div>
      <div class="tc-src">Fuente: IAB Spain / Epsilon 2025</div>
    </div>
    <div class="trust-card">
      <div class="tc-num" style="color:var(--a);">200B$</div>
      <div class="tc-label">Economía de creadores global</div>
      <div class="tc-desc">El mercado de creators supera los 200.000 millones de dólares en 2026 y sigue creciendo. El 71% de las marcas globales incrementa presupuesto de creadores este año.</div>
      <div class="tc-src">Fuente: Grand View Research / Sprout Social</div>
    </div>
    <div class="trust-card">
      <div class="tc-num" style="color:var(--pk);">61%</div>
      <div class="tc-label">Confían en influencers, no en ads</div>
      <div class="tc-desc">El 61% de consumidores confía en las recomendaciones de creadores frente al 38% que confía en el contenido generado directamente por marcas. La brecha de 23 puntos es histórica.</div>
      <div class="tc-src">Fuente: Puro Marketing / Good Rebels 2026</div>
    </div>
  </div>
</div>

<!-- TIPOS DE TASTEMAKER EN ESPAÑA -->
<div class="sec">
  <div class="sec-hd">
    <span class="sec-n">04</span>
    <span class="sec-t">Arquetipos de tastemaker en el ecosistema español</span>
  </div>
  <div class="tipos-grid">
    <div class="tipo-card" data-ves-assistant-question="Dame ejemplos concretos de tastemakers de gastronomía y cocina en TikTok España, cómo identificarlos, cómo contactarlos y qué tipo de marcas tienen más fit con ellos">
      <div class="tipo-icon"><i class="ti ti-chef-hat" aria-hidden="true"></i></div>
      <div class="tipo-name">El experto gastronómico local</div>
      <span class="tipo-seg" style="background:var(--al);color:var(--a);">Alimentación · FMCG · Hostelería</span>
      <div class="tipo-desc">Habla de un producto, un tipo de cocina, un ingrediente. Su audiencia confía en su criterio más que en cualquier guía Michelin. No es chef profesional — es apasionado riguroso.</div>
      <div class="tipo-ex"><strong>Señales de autenticidad:</strong> Publica recetas fallidas. Critica productos cuando no le gustan. Tiene rutinas fijas reconocibles.</div>
    </div>
    <div class="tipo-card" data-ves-assistant-question="Dame ejemplos de tastemakers de moda sostenible o slow fashion en TikTok España, cómo son sus comunidades y cómo pueden trabajar con ellos marcas textiles o de accesorios">
      <div class="tipo-icon"><i class="ti ti-shirt" aria-hidden="true"></i></div>
      <div class="tipo-name">El curador de estilo de nicho</div>
      <span class="tipo-seg" style="background:var(--pl);color:var(--p);">Moda · Belleza · Lifestyle</span>
      <div class="tipo-desc">No hace hauls de fast fashion. Tiene una estética coherente y específica — slow fashion, thrifting, estética rural española, moda de segunda mano. Su audiencia compra exactamente lo que él/ella recomienda.</div>
      <div class="tipo-ex"><strong>Señales de autenticidad:</strong> Repite looks. Muestra el precio real. Rechaza colaboraciones que no encajan.</div>
    </div>
    <div class="tipo-card" data-ves-assistant-question="Cómo son los tastemakers de salud mental, bienestar y autocuidado en TikTok España? Cómo las marcas de wellness pueden trabajar con ellos de forma ética y efectiva">
      <div class="tipo-icon"><i class="ti ti-heart" aria-hidden="true"></i></div>
      <div class="tipo-name">El validador de bienestar</div>
      <span class="tipo-seg" style="background:var(--gl);color:var(--g);">Wellness · Salud mental · Descanso</span>
      <div class="tipo-desc">Psicólogos, nutricionistas, fisios, coaches de salud mental con menos de 80K seguidores pero con comunidades que dependen de su criterio para decisiones de compra de salud.</div>
      <div class="tipo-ex"><strong>Señales de autenticidad:</strong> Tiene formación real. Dice "no sé" cuando no sabe. Recomienda rivales si son mejores.</div>
    </div>
    <div class="tipo-card" data-ves-assistant-question="Qué son los tastemakers culturales o de entretenimiento en TikTok España? Cómo identificar a los que tienen audiencias pequeñas pero muy leales en música, cine o cultura">
      <div class="tipo-icon"><i class="ti ti-music" aria-hidden="true"></i></div>
      <div class="tipo-name">El prescriptor cultural</div>
      <span class="tipo-seg" style="background:var(--bl);color:var(--b);">Cultura · Música · Cine · Libros</span>
      <div class="tipo-desc">Antes se llamaba crítico. Ahora tiene TikTok y 30K seguidores que consideran su opinión sobre discos, películas o libros absolutamente definitiva. El boca-oreja digital en su forma más pura.</div>
      <div class="tipo-ex"><strong>Señales de autenticidad:</strong> Critica lo malo. Tiene posiciones impopulares. Su recomendación positiva es escasa y por eso valiosa.</div>
    </div>
    <div class="tipo-card" data-ves-assistant-question="Cómo son los tastemakers de tecnología y gadgets en TikTok España? Cuál es su perfil, qué marcas tienen más oportunidad y cómo hacer outreach a estos perfiles">
      <div class="tipo-icon"><i class="ti ti-device-laptop" aria-hidden="true"></i></div>
      <div class="tipo-name">El analista técnico accesible</div>
      <span class="tipo-seg" style="background:rgba(226,75,74,.08);color:var(--tk);">Tecnología · Gadgets · Gaming</span>
      <div class="tipo-desc">Reviews honestas y largas que la audiencia ve completas porque confía. Encuentra el defecto que nadie más menciona. Su "sí lo recomiendo" vale por 100 ads. Dominan el sector tech y gaming.</div>
      <div class="tipo-ex"><strong>Señales de autenticidad:</strong> Pone pegas a los productos que le envían. Compara con rivales. Dice el precio real.</div>
    </div>
    <div class="tipo-card" data-ves-assistant-question="Qué son los tastemakers de viaje slow o turismo rural en TikTok España? Cómo se relacionan con destinos, alojamientos y marcas locales y cómo activarlos">
      <div class="tipo-icon"><i class="ti ti-map-2" aria-hidden="true"></i></div>
      <div class="tipo-name">El explorador local auténtico</div>
      <span class="tipo-seg" style="background:var(--al);color:var(--a);">Turismo · Rural · Gastronomía local</span>
      <div class="tipo-desc">Descubre el bar de pueblo que nadie conoce. El sendero que no sale en Google Maps. El mercado de productores local. Su audiencia viaja a donde él/ella dice. Sin hoteles de lujo ni coches de alquiler.</div>
      <div class="tipo-ex"><strong>Señales de autenticidad:</strong> Contenido grabado con móvil. Enseña lo feo también. Menciona el coste real del viaje.</div>
    </div>
  </div>
</div>

<!-- CÓMO ENCONTRARLOS -->
<div class="sec">
  <div class="sec-hd">
    <span class="sec-n">05</span>
    <span class="sec-t">Cómo encontrar y activar tastemakers en España — guía operativa</span>
  </div>
  <div class="find-grid">
    <div class="find-card">
      <div class="find-step">Paso 1 — Descubrimiento</div>
      <div class="find-title">Busca dentro de TikTok, no en plataformas</div>
      <div class="find-body">La mejor forma de encontrar tastemakers es usar el propio buscador de TikTok. Busca hashtags de tu categoría + términos de nicho específicos. Los que aparecen con vídeos de 50K–300K views pero tienen menos de 50K seguidores son los candidatos ideales. Herramientas como YouScan permiten encontrar quién ya habla de tu categoría sin etiquetarte.</div>
      <div class="find-tip"><i class="ti ti-bulb" style="font-size:12px;" aria-hidden="true"></i> Señal de tastemaker: engagement >5%, comentarios con preguntas genuinas</div>
    </div>
    <div class="find-card">
      <div class="find-step">Paso 2 — Validación</div>
      <div class="find-title">Analiza coherencia, no métricas de vanidad</div>
      <div class="find-body">Antes de contactar, revisa 20 vídeos recientes. Preguntas clave: ¿habla siempre del mismo nicho? ¿Ha rechazado alguna colaboración visible? ¿Critica productos o solo los elogia? ¿Responde a comentarios? ¿Sus seguidores hacen preguntas específicas o solo dejan emojis? La coherencia temática y el nivel de conversación son los indicadores más fiables.</div>
      <div class="find-tip"><i class="ti ti-bulb" style="font-size:12px;" aria-hidden="true"></i> Red flag: solo posts de colaboración sin contenido orgánico propio</div>
    </div>
    <div class="find-card">
      <div class="find-step">Paso 3 — Primer contacto</div>
      <div class="find-title">El outreach que funciona empieza por ser fan</div>
      <div class="find-body">El error habitual: enviar un brief estándar. Lo que funciona: comentar sus vídeos durante 2-3 semanas antes de contactar. Cuando hagas outreach, menciona un vídeo específico y explica por qué el producto encaja con su estilo. El tastemaker rechazará cualquier propuesta que huela a transacción pura — necesita sentir que es una elección editorial, no una compra.</div>
      <div class="find-tip"><i class="ti ti-bulb" style="font-size:12px;" aria-hidden="true"></i> Tip: ofrece el producto antes de hablar de dinero</div>
    </div>
    <div class="find-card">
      <div class="find-step">Paso 4 — Activación</div>
      <div class="find-title">El briefing mínimo es el mejor briefing</div>
      <div class="find-body">El tastemaker necesita libertad creativa total. Tu único requisito: que mencione o muestre el producto de forma orgánica. El formato, el tono, el encuadre, el guión — todo es suyo. Las marcas que dan libertad generan contenido que parece orgánico porque lo es. Las que dan un briefing de 4 páginas generan contenido que parece publicidad aunque no lo parezca.</div>
      <div class="find-tip"><i class="ti ti-bulb" style="font-size:12px;" aria-hidden="true"></i> El único requisito legal: que etiquete como #ad si es pagado</div>
    </div>
  </div>
</div>

<!-- BRIEF DO / DON'T -->
<div class="sec">
  <div class="sec-hd">
    <span class="sec-n">06</span>
    <span class="sec-t">El briefing de tastemaker — lo que hacer y lo que destruye la colaboración</span>
  </div>
  <div style="border:0.5px solid var(--bd);border-radius:var(--border-radius-lg);overflow:hidden;">
    <div class="brief-grid">
      <div class="brief-do">
        <div class="brief-head" style="color:var(--g);"><i class="ti ti-check" style="font-size:14px;" aria-hidden="true"></i> Sí hacerlo</div>
        <div class="brief-item">Enviar el producto sin contrato previo, simplemente como regalo
          <small>Si le gusta, lo contará. Si no, al menos conoce la marca.</small>
        </div>
        <div class="brief-item">Dejar que elija cuándo y cómo integrarlo en su contenido
          <small>La integración orgánica tiene 5 veces más retención que la forzada.</small>
        </div>
        <div class="brief-item">Pedir feedback real sobre el producto — y escucharlo
          <small>Sus comentarios son el mejor focus group que puedes tener.</small>
        </div>
        <div class="brief-item">Construir relaciones de largo plazo, no campañas puntuales
          <small>La recurrencia multiplica la credibilidad. El tastemaker se convierte en embajador creíble.</small>
        </div>
        <div class="brief-item">Compartir el vídeo desde el perfil de la marca y responder comentarios
          <small>El algoritmo premia la interacción cruzada. Y demuestra que la marca escucha.</small>
        </div>
        <div class="brief-item">Dar acceso a lanzamientos antes que al público general
          <small>La exclusividad es la mejor moneda para un tastemaker — vale más que el dinero.</small>
        </div>
      </div>
      <div class="brief-dont">
        <div class="brief-head" style="color:var(--tk);"><i class="ti ti-x" style="font-size:14px;" aria-hidden="true"></i> Nunca hacer esto</div>
        <div class="brief-item">Enviar un brief de más de media página
          <small>Si necesitas explicar tanto, el producto no encaja o tú no has hecho la investigación previa.</small>
        </div>
        <div class="brief-item">Exigir que use ciertos mensajes clave o claims concretos
          <small>Los mensajes de marketing suenan a marketing. Su audiencia lo detecta inmediatamente.</small>
        </div>
        <div class="brief-item">Pedir que no mencione puntos negativos del producto
          <small>Una crítica honesta de un tastemaker genera más confianza que 10 reviews perfectas.</small>
        </div>
        <div class="brief-item">Pedirle que cambie su estilo de edición o de cámara
          <small>Estás pagando por su voz y su comunidad, no por un actor de publicidad. Respeta el lenguaje.</small>
        </div>
        <div class="brief-item">Medir el éxito solo en views o likes
          <small>El éxito de un tastemaker se mide en comentarios de intención de compra, tráfico cualificado y recompra.</small>
        </div>
        <div class="brief-item">Cortarle el acceso si el primer vídeo no fue perfecto
          <small>La confianza se construye en el tiempo. Las relaciones de un solo post no son tastemaker marketing.</small>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- MÉTRICAS NUEVAS -->
<div class="sec">
  <div class="sec-hd">
    <span class="sec-n">07</span>
    <span class="sec-t">Las métricas del tastemaker — olvidar los likes, medir lo que importa</span>
  </div>
  <div class="metrics-grid">
    <div class="met">
      <div class="met-label">Comentarios de intención</div>
      <div class="met-val">CIC</div>
      <div class="met-sub">Comentarios con "¿dónde lo compro?", "ya lo tengo", "voy a probarlo". La métrica más honesta de conversión real.</div>
    </div>
    <div class="met">
      <div class="met-label">Tasa de conversación</div>
      <div class="met-val">Cmt/View</div>
      <div class="met-sub">Ratio de comentarios sobre views. Un buen tastemaker tiene 10-20× más ratio que un influencer clásico de mismo tamaño.</div>
    </div>
    <div class="met">
      <div class="met-label">Share rate</div>
      <div class="met-val">% Shares</div>
      <div class="met-sub">Porcentaje de viewers que comparten el vídeo. El share es la señal de valor más potente para el algoritmo de TikTok en 2026.</div>
    </div>
    <div class="met">
      <div class="met-label">Tráfico directo post-publicación</div>
      <div class="met-val">+UTM</div>
      <div class="met-sub">Tráfico a la web con UTM específico en las 72h post-publicación. Más fiable que cualquier otra métrica de atribución para tastemakers.</div>
    </div>
  </div>
</div>

<!-- APLICACIÓN MARCAS ESPAÑA -->
<div class="sec">
  <div class="sec-hd">
    <span class="sec-n">08</span>
    <span class="sec-t">Casos de aplicación para marcas españolas — estrategia concreta</span>
  </div>
  <div class="brands-grid">
    <div class="br" data-ves-assistant-question="Diseña una estrategia completa de tastemaker marketing para una marca de alimentación española (por ejemplo, aceite de oliva, conservas, vino o queso): cómo encontrar los perfiles, qué presupuesto necesito, qué métricas seguir y cómo escalar si funciona">
      <div class="br-top"><div class="br-dot" style="background:var(--a);"></div><div class="br-name">Alimentación y bebidas españolas</div><div class="br-sector">FMCG · Gran consumo</div></div>
      <div class="br-body">Las marcas de aceite, vino, conservas o queso artesano tienen el activo más poderoso del ecosistema: producto con historia real. Los tastemakers gastronómicos de nicho (15K–60K) con audiencias de foodies son los prescriptores más fiables del mercado. KFC, Burger King o Lidl lideran TikTok ES con esta lógica.</div>
      <div class="br-quote">"No busques al creador más grande. Busca al que tiene la audiencia más hambrienta."</div>
    </div>
    <div class="br" data-ves-assistant-question="Cómo puede una marca de belleza o cosmética española activar tastemakers en TikTok: qué perfil de creador buscar, cómo medir la efectividad y qué presupuesto mínimo necesita para empezar a ver resultados">
      <div class="br-top"><div class="br-dot" style="background:var(--pk);"></div><div class="br-name">Cosmética y cuidado personal</div><div class="br-sector">Beauty · Wellness</div></div>
      <div class="br-body">El hashtag #hygiene con 750K publicaciones es el terreno natural de los tastemakers de belleza. Dermatólogos divulgadores, estheticianas con TikTok, farmacéuticas que explican ingredientes — todos tienen audiencias de compra altísima disposición y confían en su criterio más que en ningún ad.</div>
      <div class="br-quote">"El mejor anuncio de cosmética es una dermatóloga de 40K seguidores diciendo que lo usa ella."</div>
    </div>
    <div class="br" data-ves-assistant-question="Estrategia de tastemaker marketing para una marca de turismo rural o alojamiento en España: cómo identificar a los exploradores locales de TikTok, qué tipo de colaboración funciona y qué esperar en términos de resultados">
      <div class="br-top"><div class="br-dot" style="background:var(--g);"></div><div class="br-name">Turismo y alojamiento rural</div><div class="br-sector">Viajes · Experiencias · Hospitality</div></div>
      <div class="br-body">El tastemaker de viaje slow es exactamente el perfil que conecta con el Verano Silencioso. Un creador que documenta su semana en una casa rural de La Mancha o un cortijo en la Axarquía genera reservas directas medibles. La audiencia de estos creadores tiene alta intención de compra y capacidad adquisitiva.</div>
      <div class="br-quote">"Una semana de estancia gratis para un tastemaker de 25K puede valer más que 5.000€ en TikTok Ads."</div>
    </div>
    <div class="br" data-ves-assistant-question="Cómo puede una marca tecnológica o de apps española trabajar con tastemakers en TikTok: qué tipo de creadores buscar, cómo estructurar la colaboración para que parezca orgánica y qué métricas son las más relevantes en este sector">
      <div class="br-top"><div class="br-dot" style="background:var(--b);"></div><div class="br-name">Tecnología y apps de bienestar</div><div class="br-sector">Tech · Productividad · Fintech</div></div>
      <div class="br-body">El sector tech en TikTok ES tiene un déficit de tastemakers honestos que los usuarios buscan activamente. Un analista técnico de nicho que hace reviews largas y rigurosas tiene audiencias de compra con altísimo poder de decisión. La paradoja: en tech, el tastemaker que critica también vende.</div>
      <div class="br-quote">"En tecnología, la crítica honesta de un tastemaker convierte más que el elogio de un influencer."</div>
    </div>
  </div>
</div>

<!-- CTA -->
<div class="cta">
  <button class="btn pri" data-ves-assistant-question="Diseña un programa completo de tastemaker marketing para una marca española con presupuesto de 10.000€ al mes: cuántos tastemakers activar, de qué tier, qué mix de sectores, cómo estructurar los contratos, qué métricas seguir y cómo escalar en 6 meses">Programa tastemaker con presupuesto real ↗</button>
  <button class="btn" data-ves-assistant-question="Dame una plantilla de mensaje de outreach para contactar a un tastemaker de TikTok en España: cómo presentar la marca, qué ofrecer primero, qué no pedir nunca y cómo diferenciarse del spam de colaboraciones que reciben">Plantilla de outreach ↗</button>
  <button class="btn" data-ves-assistant-question="Compara el ROI real de una campaña con 1 macro-influencer de 500K vs 20 micro-tastemakers de 25K en TikTok España: costes estimados, métricas esperadas, riesgos y en qué situaciones tiene sentido cada estrategia">ROI macro vs micro: comparativa ↗</button>
  <button class="btn" data-ves-assistant-question="Cuáles son las herramientas y plataformas disponibles en España para encontrar y gestionar tastemakers en TikTok: qué ofrecen, qué cuestan y cuál recomendarías según el tamaño de marca">Herramientas para encontrarlos ↗</button>
</div>

</div>
</section>
