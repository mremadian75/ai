<?php
// v0.9.31.5 — Curated static report template. Renders verbatim narrative
// content. $report is passed by the renderer; an optional title override
// is rendered above the curated hero when present, otherwise the curated
// hero stands on its own.
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/_partials.php';
$curated_title_override = isset($report['title']) ? (string) $report['title'] : '';
?>
<section class="ves-report ves-report-curated ves-report-tiktok-trends-2026" aria-labelledby="ves-report-tiktok-trends-2026-title">
<?php if ($curated_title_override !== '') : ?>
  <h1 id="ves-report-tiktok-trends-2026-title" class="ves-report-curated-title"><?php echo esc_html($curated_title_override); ?></h1>
<?php else : ?>
  <h1 id="ves-report-tiktok-trends-2026-title" class="ves-sr-only"><?php echo esc_html__('TikTok — análisis de tendencias 2026', 'ves'); ?></h1>
<?php endif; ?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap');
.ves-report-curated.ves-report-tiktok-trends-2026{
  --tk:#E24B4A; --tk2:#A32D2D;
  --g:#1D9E75; --b:#378ADD; --p:#7F77DD; --a:#BA7517; --pk:#D4537E;
  --ink:var(--color-text-primary); --sub:var(--color-text-secondary);
  --bg:var(--color-background-primary); --bg2:var(--color-background-secondary);
  --bd:var(--color-border-tertiary); --bd2:var(--color-border-secondary);
  --ff:'Space Grotesk',sans-serif; --fm:'Space Mono',monospace;
}
.ves-report-curated.ves-report-tiktok-trends-2026 *{box-sizing:border-box;}
.ves-report-curated.ves-report-tiktok-trends-2026 .root{font-family:var(--ff);}

/* TOP NAV */
.ves-report-curated.ves-report-tiktok-trends-2026 .topnav{display:flex;align-items:center;justify-content:space-between;padding:.875rem 1.25rem;border-bottom:0.5px solid var(--bd);}
.ves-report-curated.ves-report-tiktok-trends-2026 .tk-logo{display:flex;align-items:center;gap:8px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tk-icon{width:28px;height:28px;border-radius:6px;background:var(--tk);display:flex;align-items:center;justify-content:center;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tk-icon i{color:#fff;font-size:16px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tk-title{font-size:14px;font-weight:700;color:var(--ink);letter-spacing:-.3px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tk-sub{font-family:var(--fm);font-size:9px;color:var(--sub);}
.ves-report-curated.ves-report-tiktok-trends-2026 .report-badge{font-family:var(--fm);font-size:9px;padding:4px 10px;border-radius:12px;background:rgba(226,75,74,.1);color:var(--tk);border:0.5px solid rgba(226,75,74,.25);}

/* 3 PILARES */
.ves-report-curated.ves-report-tiktok-trends-2026 .pillars{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:0.5px solid var(--bd);}
.ves-report-curated.ves-report-tiktok-trends-2026 .pillar{padding:1.25rem;cursor:pointer;transition:background .15s;border-right:0.5px solid var(--bd);}
.ves-report-curated.ves-report-tiktok-trends-2026 .pillar:last-child{border-right:none;}
.ves-report-curated.ves-report-tiktok-trends-2026 .pillar:hover{background:var(--bg2);}
.ves-report-curated.ves-report-tiktok-trends-2026 .pl-num{font-family:var(--fm);font-size:10px;color:var(--sub);margin-bottom:6px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .pl-name{font-size:14px;font-weight:700;color:var(--ink);line-height:1.2;margin-bottom:6px;letter-spacing:-.4px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .pl-desc{font-size:11px;color:var(--sub);line-height:1.5;margin-bottom:10px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .pl-tags{display:flex;flex-wrap:wrap;gap:4px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .pl-tag{font-family:var(--fm);font-size:9px;padding:3px 7px;border-radius:10px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .pl-stat{font-family:var(--fm);font-size:10px;margin-top:8px;display:flex;align-items:center;gap:4px;}

/* TENDENCIAS CARDS */
.ves-report-curated.ves-report-tiktok-trends-2026 .section{padding:1.25rem;border-bottom:0.5px solid var(--bd);}
.ves-report-curated.ves-report-tiktok-trends-2026 .sec-head{display:flex;align-items:center;gap:8px;margin-bottom:1rem;}
.ves-report-curated.ves-report-tiktok-trends-2026 .sec-n{font-family:var(--fm);font-size:10px;color:var(--sub);}
.ves-report-curated.ves-report-tiktok-trends-2026 .sec-t{font-size:13px;font-weight:700;color:var(--ink);letter-spacing:-.3px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .sec-meta{font-family:var(--fm);font-size:9px;color:var(--sub);margin-left:auto;}

.ves-report-curated.ves-report-tiktok-trends-2026 .trends-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc{border:0.5px solid var(--bd);border-radius:var(--border-radius-lg);padding:1rem;cursor:pointer;transition:border-color .15s;display:flex;flex-direction:column;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc:hover{border-color:var(--tk);}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-top{display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-meta{flex:1;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-hash{font-family:var(--fm);font-size:11px;font-weight:700;color:var(--ink);margin-bottom:2px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-cat{font-family:var(--fm);font-size:9px;color:var(--sub);}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-heat{font-family:var(--fm);font-size:10px;padding:2px 7px;border-radius:10px;margin-left:auto;white-space:nowrap;}
.ves-report-curated.ves-report-tiktok-trends-2026 .heat-viral{background:rgba(226,75,74,.1);color:var(--tk);}
.ves-report-curated.ves-report-tiktok-trends-2026 .heat-rise{background:rgba(29,158,117,.1);color:var(--g);}
.ves-report-curated.ves-report-tiktok-trends-2026 .heat-grow{background:rgba(55,138,221,.1);color:var(--b);}
.ves-report-curated.ves-report-tiktok-trends-2026 .heat-watch{background:rgba(186,117,23,.1);color:var(--a);}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-desc{font-size:11px;color:var(--sub);line-height:1.5;flex:1;margin-bottom:8px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-nums{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-pub{font-family:var(--fm);font-size:10px;color:var(--ink);}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-trend{font-family:var(--fm);font-size:9px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-brand{background:var(--bg2);border-radius:6px;padding:6px 8px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-brand-label{font-family:var(--fm);font-size:9px;color:var(--sub);margin-bottom:2px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-brand-idea{font-size:10px;color:var(--ink);line-height:1.4;}

/* FORMATO CONTENT */
.ves-report-curated.ves-report-tiktok-trends-2026 .format-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .fmt{background:var(--bg2);border-radius:var(--border-radius-lg);padding:.875rem;}
.ves-report-curated.ves-report-tiktok-trends-2026 .fmt-icon{font-size:20px;margin-bottom:8px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .fmt-name{font-size:12px;font-weight:700;color:var(--ink);margin-bottom:4px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .fmt-desc{font-size:10px;color:var(--sub);line-height:1.4;margin-bottom:6px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .fmt-perf{display:flex;align-items:center;gap:6px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .fmt-bar-wrap{flex:1;height:3px;background:var(--bd2);border-radius:2px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .fmt-bar{height:3px;border-radius:2px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .fmt-pct{font-family:var(--fm);font-size:9px;color:var(--sub);}

/* SHIFT TABLE */
.ves-report-curated.ves-report-tiktok-trends-2026 .shift-grid{display:grid;grid-template-columns:1fr 32px 1fr;gap:4px;align-items:center;}
.ves-report-curated.ves-report-tiktok-trends-2026 .shift-out{padding:8px 12px;border-radius:var(--border-radius-md);font-size:11px;line-height:1.3;border-left:2px solid var(--tk);}
.ves-report-curated.ves-report-tiktok-trends-2026 .shift-in{padding:8px 12px;border-radius:var(--border-radius-md);font-size:11px;line-height:1.3;border-left:2px solid var(--g);}
.ves-report-curated.ves-report-tiktok-trends-2026 .shift-arrow{text-align:center;font-size:14px;color:var(--sub);}
.ves-report-curated.ves-report-tiktok-trends-2026 .shift-out-bg{background:rgba(226,75,74,.05);}
.ves-report-curated.ves-report-tiktok-trends-2026 .shift-in-bg{background:rgba(29,158,117,.05);}

/* ALGO CHART */
.ves-report-curated.ves-report-tiktok-trends-2026 .algo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .algo-card{border:0.5px solid var(--bd);border-radius:var(--border-radius-lg);padding:1rem;}
.ves-report-curated.ves-report-tiktok-trends-2026 .algo-pct{font-size:26px;font-weight:700;line-height:1;margin-bottom:4px;letter-spacing:-1px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .algo-label{font-size:11px;font-weight:700;color:var(--ink);margin-bottom:4px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .algo-desc{font-size:10px;color:var(--sub);line-height:1.4;}

/* BRANDS */
.ves-report-curated.ves-report-tiktok-trends-2026 .brands-row{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .br-card{border-radius:var(--border-radius-lg);padding:1rem;border:0.5px solid var(--bd);cursor:pointer;transition:border-color .15s;}
.ves-report-curated.ves-report-tiktok-trends-2026 .br-card:hover{border-color:var(--tk);}
.ves-report-curated.ves-report-tiktok-trends-2026 .br-top{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .br-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.ves-report-curated.ves-report-tiktok-trends-2026 .br-name{font-size:12px;font-weight:700;color:var(--ink);}
.ves-report-curated.ves-report-tiktok-trends-2026 .br-fit{font-family:var(--fm);font-size:9px;margin-left:auto;padding:2px 7px;border-radius:8px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .br-insight{font-size:11px;color:var(--sub);line-height:1.5;margin-bottom:6px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .br-msg{font-style:italic;font-size:11px;color:var(--ink);background:var(--bg2);padding:6px 10px;border-radius:6px;border-left:2px solid var(--tk);}

/* BOTTOM */
.ves-report-curated.ves-report-tiktok-trends-2026 .bottom{display:flex;gap:8px;padding:1rem 1.25rem;flex-wrap:wrap;border-top:0.5px solid var(--bd);}
.ves-report-curated.ves-report-tiktok-trends-2026 .btn{font-family:var(--ff);font-size:11px;padding:7px 14px;border-radius:20px;border:0.5px solid var(--bd);background:transparent;color:var(--ink);cursor:pointer;transition:all .15s;}
.ves-report-curated.ves-report-tiktok-trends-2026 .btn:hover{border-color:var(--tk);color:var(--tk);}
.ves-report-curated.ves-report-tiktok-trends-2026 .btn.pri{background:var(--tk);border-color:var(--tk);color:#fff;}
.ves-report-curated.ves-report-tiktok-trends-2026 .btn.pri:hover{background:var(--tk2);border-color:var(--tk2);}


/* v0.9.31.7 responsive pass — scoped to this curated template only. */
@media (max-width: 900px) {
.ves-report-curated.ves-report-tiktok-trends-2026 .topnav{align-items:flex-start;flex-direction:column;gap:10px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .topnav > div:last-child{flex-wrap:wrap;}
.ves-report-curated.ves-report-tiktok-trends-2026 .pillars{grid-template-columns:1fr;}
.ves-report-curated.ves-report-tiktok-trends-2026 .pillar{border-right:0;border-bottom:0.5px solid var(--bd);}
.ves-report-curated.ves-report-tiktok-trends-2026 .trends-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
.ves-report-curated.ves-report-tiktok-trends-2026 .format-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
.ves-report-curated.ves-report-tiktok-trends-2026 .shift-grid{grid-template-columns:1fr;gap:8px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .shift-arrow{text-align:left;transform:rotate(90deg);transform-origin:left center;margin-left:8px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .algo-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
.ves-report-curated.ves-report-tiktok-trends-2026 .brands-row{grid-template-columns:1fr;}
.ves-report-curated.ves-report-tiktok-trends-2026 .bottom{align-items:flex-start;}
}
@media (max-width: 560px) {
.ves-report-curated.ves-report-tiktok-trends-2026 .topnav{padding:.875rem 1rem;}
.ves-report-curated.ves-report-tiktok-trends-2026 .section{padding:1rem;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tk-title{font-size:clamp(13px,5.5vw,16px);}
.ves-report-curated.ves-report-tiktok-trends-2026 .sec-head{align-items:flex-start;flex-wrap:wrap;}
.ves-report-curated.ves-report-tiktok-trends-2026 .sec-meta{margin-left:0;width:100%;}
.ves-report-curated.ves-report-tiktok-trends-2026 .trends-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-tiktok-trends-2026 .format-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-tiktok-trends-2026 .algo-grid{grid-template-columns:1fr;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-top{flex-wrap:wrap;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-heat{margin-left:0;}
.ves-report-curated.ves-report-tiktok-trends-2026 .tc-nums{align-items:flex-start;flex-direction:column;gap:4px;}
.ves-report-curated.ves-report-tiktok-trends-2026 .br-top{align-items:flex-start;flex-wrap:wrap;}
.ves-report-curated.ves-report-tiktok-trends-2026 .br-fit{margin-left:0;}
.ves-report-curated.ves-report-tiktok-trends-2026 .bottom{padding:.875rem 1rem;}
.ves-report-curated.ves-report-tiktok-trends-2026 .btn{white-space:normal;}
}
</style>

<div class="root">

<!-- TOP -->
<div class="topnav">
  <div class="tk-logo">
    <div class="tk-icon"><i class="ti ti-brand-tiktok" aria-hidden="true"></i></div>
    <div>
      <div class="tk-title">TikTok Radar — Análisis de tendencias 2026</div>
      <div class="tk-sub">Next Report 2026 + señales en tiempo real · Mayo 22, 2026</div>
    </div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <span class="report-badge">Fuente: TikTok Next Report 2026</span>
    <span style="font-family:var(--fm);font-size:9px;color:var(--sub);">800M+ usuarios activos</span>
  </div>
</div>

<!-- 3 GRANDES EJES -->
<div class="pillars">
  <div class="pillar" data-ves-assistant-question="Explícame en profundidad la tendencia Dosis de Realidad en TikTok 2026: qué tipo de contenido está ganando, qué marcas pueden aprovecharlo mejor en España y cómo crear piezas auténticas que conecten">
    <div class="pl-num">EJE 01</div>
    <div class="pl-name">Dosis de Realidad</div>
    <div class="pl-desc">El escapismo digital murió. Las audiencias abandonan el "hacer como si nada" y priorizan límites, bienestar y autenticidad radical. Lo imperfecto se convierte en capital cultural.</div>
    <div class="pl-tags">
      <span class="pl-tag" style="background:rgba(226,75,74,.08);color:var(--tk);">#lockedin 820K</span>
      <span class="pl-tag" style="background:rgba(226,75,74,.08);color:var(--tk);">#hygiene 750K</span>
      <span class="pl-tag" style="background:rgba(226,75,74,.08);color:var(--tk);">#joblife 240K</span>
    </div>
    <div class="pl-stat" style="color:var(--tk);"><i class="ti ti-trending-up" style="font-size:13px;" aria-hidden="true"></i> Hashtags #delulu y #romanticizing en caída libre</div>
  </div>
  <div class="pillar" data-ves-assistant-question="Explícame la tendencia Descubrimientos Fuera de la Ruta en TikTok 2026: cómo TikTok se convirtió en motor de búsqueda cultural, qué significa esto para el SEO de marcas en la plataforma y cómo aprovecharlo en España">
    <div class="pl-num">EJE 02</div>
    <div class="pl-name">Descubrimiento Fuera de la Ruta</div>
    <div class="pl-desc">La curiosidad es la nueva moneda. TikTok ya no es solo entretenimiento, es el motor de búsqueda cultural de su generación. 1 de cada 4 usuarios busca dentro de los primeros 30 segundos de abrir la app.</div>
    <div class="pl-tags">
      <span class="pl-tag" style="background:rgba(55,138,221,.08);color:var(--b);">#whattowear 930K</span>
      <span class="pl-tag" style="background:rgba(55,138,221,.08);color:var(--b);">#cookinghacks 803K</span>
      <span class="pl-tag" style="background:rgba(55,138,221,.08);color:var(--b);">#mymakeuptype 27K</span>
    </div>
    <div class="pl-stat" style="color:var(--b);"><i class="ti ti-search" style="font-size:13px;" aria-hidden="true"></i> 52% sigue explorando DESPUÉS de encontrar lo que buscaba</div>
  </div>
  <div class="pillar" data-ves-assistant-question="Explícame el ROI Emocional en TikTok 2026: cómo ha cambiado la toma de decisiones de compra en la plataforma, qué papel juegan los tastemakers y las reseñas en comentarios, y cómo deben posicionarse las marcas españolas">
    <div class="pl-num">EJE 03</div>
    <div class="pl-name">ROI Emocional</div>
    <div class="pl-desc">El "gustito impulsivo" está muriendo. Los consumidores ahora exigen justificación emocional, comunitaria y de valor para cada compra. Las recomendaciones genuinas desplazan definitivamente a la publicidad clásica.</div>
    <div class="pl-tags">
      <span class="pl-tag" style="background:rgba(29,158,117,.08);color:var(--g);">#tastemakers</span>
      <span class="pl-tag" style="background:rgba(29,158,119,.08);color:var(--g);">Reseñas en comentarios</span>
      <span class="pl-tag" style="background:rgba(29,158,117,.08);color:var(--g);">Comunidad sobre precio</span>
    </div>
    <div class="pl-stat" style="color:var(--g);"><i class="ti ti-users" style="font-size:13px;" aria-hidden="true"></i> Millennials: 1.7× más propensos a comprar por influencia comunitaria</div>
  </div>
</div>

<!-- TENDENCIAS ACTIVAS MAYO 2026 -->
<div class="section">
  <div class="sec-head">
    <span class="sec-n">01</span>
    <span class="sec-t">Tendencias activas — Mayo 2026</span>
    <span class="sec-meta">Clic en cualquier tarjeta para análisis profundo</span>
  </div>
  <div class="trends-grid">

    <div class="tc" data-ves-assistant-question="Analiza en profundidad la tendencia #lockedin de TikTok: qué contenido está funcionando, cómo se diferencia del #hustle culture anterior, qué marcas pueden entrar con credibilidad y qué tipo de piezas crear para España">
      <div class="tc-top">
        <div class="tc-icon" style="background:rgba(226,75,74,.1);"><i class="ti ti-focus-2" style="color:var(--tk);" aria-hidden="true"></i></div>
        <div class="tc-meta">
          <div class="tc-hash">#lockedin</div>
          <div class="tc-cat">Mentalidad · Productividad · Bienestar</div>
        </div>
        <span class="tc-heat heat-viral">🔥 VIRAL</span>
      </div>
      <div class="tc-desc">La mentalidad de foco y determinación que reemplaza al hustle culture tóxico. Tribus que se responsabilizan mutuamente del crecimiento. Rutinas matutinas, disciplina sin perfeccionismo, "accountability partners". Muy distinto al #grindset — aquí hay emoción y comunidad, no ego.</div>
      <div class="tc-nums">
        <span class="tc-pub">820K publicaciones</span>
        <span class="tc-trend" style="color:var(--tk);">↑ +340% desde enero</span>
      </div>
      <div class="tc-brand">
        <div class="tc-brand-label">Oportunidad para marcas</div>
        <div class="tc-brand-idea">Fitness, apps de productividad, café, suplementos. El contenido funciona con rutinas reales y resultados honestos, no aspiracionales imposibles.</div>
      </div>
    </div>

    <div class="tc" data-ves-assistant-question="Analiza la tendencia #hygiene en TikTok 2026: por qué las rutinas de higiene se convirtieron en contenido premium, qué productos y marcas están dominando este espacio en España y cómo entrar en este nicho de forma auténtica">
      <div class="tc-top">
        <div class="tc-icon" style="background:rgba(127,119,221,.1);"><i class="ti ti-sparkles" style="color:var(--p);" aria-hidden="true"></i></div>
        <div class="tc-meta">
          <div class="tc-hash">#hygiene</div>
          <div class="tc-cat">Autocuidado · Rutinas · Belleza</div>
        </div>
        <span class="tc-heat heat-viral">🔥 VIRAL</span>
      </div>
      <div class="tc-desc">La ducha, el skincare, el orden del hogar transformados en rituales de lujo cotidiano. Creadores que convierten lo mundano en ASMR del bienestar. No es vanidad — es la celebración de cuidarse como acto de amor propio. El baño ya es spa, la cocina ya es meditación.</div>
      <div class="tc-nums">
        <span class="tc-pub">750K publicaciones</span>
        <span class="tc-trend" style="color:var(--tk);">↑ +290% en 2026</span>
      </div>
      <div class="tc-brand">
        <div class="tc-brand-label">Oportunidad para marcas</div>
        <div class="tc-brand-idea">Cosmética, higiene personal, homecare. El formato ideal: POV del ritual completo, sin voz en off, solo producto + sensaciones.</div>
      </div>
    </div>

    <div class="tc" data-ves-assistant-question="Analiza la tendencia #joblife en TikTok 2026: qué sectores laborales están generando más contenido, cómo conecta con la audiencia española y qué tipo de marcas o empresas pueden usar este formato para employer branding o marketing">
      <div class="tc-top">
        <div class="tc-icon" style="background:rgba(186,117,23,.1);"><i class="ti ti-briefcase" style="color:var(--a);" aria-hidden="true"></i></div>
        <div class="tc-meta">
          <div class="tc-hash">#joblife</div>
          <div class="tc-cat">Trabajo · Carrera · Autenticidad laboral</div>
        </div>
        <span class="tc-heat heat-rise">↑ CRECIENDO</span>
      </div>
      <div class="tc-desc">La evolución honesta del #worklife. Ya no es "mi vida de CEO glamurosa" — es el fontanero mostrando su día real, la enfermera entre turno y turno, el cocinero de restaurante a las 3am. La brecha entre trabajo idealizado y trabajo real se convierte en contenido viral.</div>
      <div class="tc-nums">
        <span class="tc-pub">240K publicaciones</span>
        <span class="tc-trend" style="color:var(--g);">↑ En expansión activa</span>
      </div>
      <div class="tc-brand">
        <div class="tc-brand-label">Oportunidad para marcas</div>
        <div class="tc-brand-idea">Employer branding, herramientas de trabajo, formación profesional. Mostrar el BTS real de la empresa sin filtros.</div>
      </div>
    </div>

    <div class="tc" data-ves-assistant-question="Explícame qué es el movimiento #tastemakers en TikTok 2026: cómo funciona la nueva economía de recomendación genuina, qué diferencia a un tastemaker de un influencer clásico y cómo pueden las marcas españolas trabajar con ellos">
      <div class="tc-top">
        <div class="tc-icon" style="background:rgba(212,83,126,.1);"><i class="ti ti-star" style="color:var(--pk);" aria-hidden="true"></i></div>
        <div class="tc-meta">
          <div class="tc-hash">#tastemakers</div>
          <div class="tc-cat">Recomendación · Comunidad · Compra</div>
        </div>
        <span class="tc-heat heat-rise">↑ ESTRUCTURAL</span>
      </div>
      <div class="tc-desc">El fin del influencer clásico como fuerza de ventas. Los tastemakers son creadores de nicho con audiencias pequeñas pero de altísima confianza que recomiendan productos como lo haría un amigo experto. Las reseñas en comentarios se vuelven tan poderosas como el vídeo principal.</div>
      <div class="tc-nums">
        <span class="tc-pub">Tendencia estructural 2026</span>
        <span class="tc-trend" style="color:var(--pk);">× 1.5 tasa de recompra</span>
      </div>
      <div class="tc-brand">
        <div class="tc-brand-label">Oportunidad para marcas</div>
        <div class="tc-brand-idea">Activar micro-creadores (5K–80K) con libertad creativa total. El briefing debe ser mínimo — la autenticidad no se puede briefear.</div>
      </div>
    </div>

    <div class="tc" data-ves-assistant-question="Analiza la tendencia de los trends de baile y audio viral en TikTok España mayo 2026: cómo el sonido Six Seven y Sapoperro están funcionando, cómo pueden marcas españolas subirse a trends de baile sin parecer forzadas">
      <div class="tc-top">
        <div class="tc-icon" style="background:rgba(55,138,221,.1);"><i class="ti ti-music" style="color:var(--b);" aria-hidden="true"></i></div>
        <div class="tc-meta">
          <div class="tc-hash">Audio viral · Baile</div>
          <div class="tc-cat">Entretenimiento · Sonido · Challenge</div>
        </div>
        <span class="tc-heat heat-viral">🔥 AHORA</span>
      </div>
      <div class="tc-desc">Mayo arranca en España con "Six Seven" (Laurinha Costa) y "Sapoperro" (Mar Lucas & Juan Magan) como sonidos dominantes. Los creadores replican coreografías cortas con alta naturalidad — cuanto más imperfecto, más auténtico. Marcas que los usan para presentar producto o equipo tienen buena acogida.</div>
      <div class="tc-nums">
        <span class="tc-pub">Ciclo vital: 2–3 semanas</span>
        <span class="tc-trend" style="color:var(--b);">Pico activo ahora</span>
      </div>
      <div class="tc-brand">
        <div class="tc-brand-label">Oportunidad para marcas</div>
        <div class="tc-brand-idea">Unboxing de producto, presentación de equipo, reveal de novedad. Baja producción, alta personalidad. El timing lo es todo.</div>
      </div>
    </div>

    <div class="tc" data-ves-assistant-question="Analiza el fenómeno de TikTok como motor de búsqueda cultural en 2026: qué tipo de consultas hacen los usuarios, cómo optimizar contenido para la búsqueda de TikTok, y qué sectores tienen más oportunidad de ser descubiertos por este canal en España">
      <div class="tc-top">
        <div class="tc-icon" style="background:rgba(29,158,117,.1);"><i class="ti ti-search" style="color:var(--g);" aria-hidden="true"></i></div>
        <div class="tc-meta">
          <div class="tc-hash">TikTok como buscador</div>
          <div class="tc-cat">Descubrimiento · SEO cultural · Intención</div>
        </div>
        <span class="tc-heat heat-grow">◎ ESTRUCTURAL</span>
      </div>
      <div class="tc-desc">1 de cada 4 usuarios abre TikTok con intención de búsqueda en los primeros 30 segundos. El 52% sigue explorando después de encontrar lo que buscaba. #whattowear, #cookinghacks, tutoriales de producto y "cómo hacer X" disparan alcance orgánico sin necesidad de paid media.</div>
      <div class="tc-nums">
        <span class="tc-pub">43% descubre productos en TikTok</span>
        <span class="tc-trend" style="color:var(--g);">51% busca más info ahí mismo</span>
      </div>
      <div class="tc-brand">
        <div class="tc-brand-label">Oportunidad para marcas</div>
        <div class="tc-brand-idea">Crear contenido que responde preguntas reales de búsqueda. "Cómo elegir X", "cuál es mejor para Y", "review honesta de Z". SEO TikTok es orgánico y gratuito.</div>
      </div>
    </div>

  </div>
</div>

<!-- SHIFT CULTURAL -->
<div class="section">
  <div class="sec-head">
    <span class="sec-n">02</span>
    <span class="sec-t">Lo que está muriendo vs lo que toma el relevo</span>
  </div>
  <div class="shift-grid">
    <div class="shift-out shift-out-bg"><strong style="font-size:10px;color:var(--tk);">AGOTADO</strong><br>#delulu — fantasear con una vida idealizada</div>
    <div class="shift-arrow">→</div>
    <div class="shift-in shift-in-bg"><strong style="font-size:10px;color:var(--g);">EMERGENTE</strong><br>#lockedin — objetivos reales con disciplina colectiva</div>

    <div class="shift-out shift-out-bg"><strong style="font-size:10px;color:var(--tk);">AGOTADO</strong><br>#romanticizing — estetizar la vida cotidiana artificialmente</div>
    <div class="shift-arrow">→</div>
    <div class="shift-in shift-in-bg"><strong style="font-size:10px;color:var(--g);">EMERGENTE</strong><br>#hygiene — convertir lo cotidiano en ritual genuino</div>

    <div class="shift-out shift-out-bg"><strong style="font-size:10px;color:var(--tk);">AGOTADO</strong><br>#digitalescapism — huir de la realidad en contenido de fantasía</div>
    <div class="shift-arrow">→</div>
    <div class="shift-in shift-in-bg"><strong style="font-size:10px;color:var(--g);">EMERGENTE</strong><br>#joblife — mostrar el trabajo real sin filtros idealizados</div>

    <div class="shift-out shift-out-bg"><strong style="font-size:10px;color:var(--tk);">AGOTADO</strong><br>Macro-influencer con producto patrocinado obvio</div>
    <div class="shift-arrow">→</div>
    <div class="shift-in shift-in-bg"><strong style="font-size:10px;color:var(--g);">EMERGENTE</strong><br>Tastemaker de nicho con recomendación orgánica de confianza</div>

    <div class="shift-out shift-out-bg"><strong style="font-size:10px;color:var(--tk);">AGOTADO</strong><br>Scroll pasivo sin intención — consumo automático</div>
    <div class="shift-arrow">→</div>
    <div class="shift-in shift-in-bg"><strong style="font-size:10px;color:var(--g);">EMERGENTE</strong><br>Búsqueda activa con intención — TikTok como Google cultural</div>
  </div>
</div>

<!-- FORMATOS QUE FUNCIONAN -->
<div class="section">
  <div class="sec-head">
    <span class="sec-n">03</span>
    <span class="sec-t">Formatos con mayor rendimiento orgánico ahora mismo</span>
  </div>
  <div class="format-grid">
    <div class="fmt">
      <div class="fmt-icon"><i class="ti ti-eye" aria-hidden="true"></i></div>
      <div class="fmt-name">POV sin locución</div>
      <div class="fmt-desc">Punto de vista inmersivo, sin voz en off, solo acción y sonido real o trending audio. El espectador entra en el momento.</div>
      <div class="fmt-perf"><div class="fmt-bar-wrap"><div class="fmt-bar" style="width:92%;background:var(--tk);"></div></div><span class="fmt-pct">92%</span></div>
    </div>
    <div class="fmt">
      <div class="fmt-icon"><i class="ti ti-clock" aria-hidden="true"></i></div>
      <div class="fmt-name">Tutorial de respuesta rápida</div>
      <div class="fmt-desc">"Cómo hacer X en 60 segundos". Contenido de búsqueda que aparece en resultados y genera reconocimiento de marca como experto.</div>
      <div class="fmt-perf"><div class="fmt-bar-wrap"><div class="fmt-bar" style="width:87%;background:var(--b);"></div></div><span class="fmt-pct">87%</span></div>
    </div>
    <div class="fmt">
      <div class="fmt-icon"><i class="ti ti-user-check" aria-hidden="true"></i></div>
      <div class="fmt-name">BTS auténtico</div>
      <div class="fmt-desc">Behind the scenes real del negocio, del proceso, del equipo. Sin guión, sin producción perfecta. Cuanto más crudo, más confianza.</div>
      <div class="fmt-perf"><div class="fmt-bar-wrap"><div class="fmt-bar" style="width:81%;background:var(--g);"></div></div><span class="fmt-pct">81%</span></div>
    </div>
    <div class="fmt">
      <div class="fmt-icon"><i class="ti ti-message-dots" aria-hidden="true"></i></div>
      <div class="fmt-name">Respuesta a comentario</div>
      <div class="fmt-desc">Crear un vídeo respondiendo directamente a un comentario real de la comunidad. Alta retención y señal de autenticidad máxima para el algoritmo.</div>
      <div class="fmt-perf"><div class="fmt-bar-wrap"><div class="fmt-bar" style="width:78%;background:var(--p);"></div></div><span class="fmt-pct">78%</span></div>
    </div>
  </div>
</div>

<!-- ALGORITMO -->
<div class="section">
  <div class="sec-head">
    <span class="sec-n">04</span>
    <span class="sec-t">Cómo puntúa el algoritmo en 2026 — lo que más importa</span>
  </div>
  <div class="algo-grid">
    <div class="algo-card">
      <div class="algo-pct" style="color:var(--tk);">38%</div>
      <div class="algo-label">Retención de vídeo</div>
      <div class="algo-desc">El porcentaje del vídeo que el usuario ve completo es la señal más fuerte. Los primeros 2 segundos lo deciden todo. El hook inicial debe ser la parte más trabajada de cualquier pieza.</div>
    </div>
    <div class="algo-card">
      <div class="algo-pct" style="color:var(--b);">27%</div>
      <div class="algo-label">Interacción en comentarios</div>
      <div class="algo-desc">Los comentarios pesan más que los likes en 2026. Un vídeo que genera conversación real —debate, preguntas, recomendaciones cruzadas— gana distribución masiva aunque tenga pocos seguidores.</div>
    </div>
    <div class="algo-card">
      <div class="algo-pct" style="color:var(--g);">21%</div>
      <div class="algo-label">Shares y guardados</div>
      <div class="algo-desc">Compartir el vídeo fuera de TikTok (WhatsApp, Instagram Stories) es la señal de valor real más potente. "Lo guardo para verlo" indica utilidad duradera y activa el ciclo de descubrimiento.</div>
    </div>
    <div class="algo-card">
      <div class="algo-pct" style="color:var(--p);">8%</div>
      <div class="algo-label">Señales de búsqueda SEO</div>
      <div class="algo-desc">Los hashtags específicos y el texto del caption que coincide con términos de búsqueda reales amplían el alcance a audiencias de intención. Más valioso en nichos que en contenido masivo.</div>
    </div>
    <div class="algo-card">
      <div class="algo-pct" style="color:var(--a);">4%</div>
      <div class="algo-label">Seguidores del perfil</div>
      <div class="algo-desc">El número de followers pesa mínimo en el algoritmo de distribución inicial. TikTok distribuye a audiencias frías primero — el follow es consecuencia, no requisito. Un perfil de 200 seguidores puede viralizarse con 2M de views.</div>
    </div>
    <div class="algo-card">
      <div class="algo-pct" style="color:var(--pk);">2%</div>
      <div class="algo-label">Likes</div>
      <div class="algo-desc">El like es la métrica de vanidad de TikTok. El algoritmo la pondera como la señal más débil — un pulgar arriba no dice nada de la intención real del usuario ni del valor del contenido.</div>
    </div>
  </div>
</div>

<!-- BRAND OPPORTUNITIES -->
<div class="section">
  <div class="sec-head">
    <span class="sec-n">05</span>
    <span class="sec-t">Qué deben hacer las marcas en TikTok ahora mismo — estrategia 2026</span>
  </div>
  <div class="brands-row">
    <div class="br-card" data-ves-assistant-question="Dame una guía completa de estrategia de contenido en TikTok para marcas en 2026: cómo estructurar el calendario, qué tipos de vídeos publicar cada semana, cómo trabajar con tastemakers y cómo medir el éxito más allá de los likes">
      <div class="br-top">
        <div class="br-dot" style="background:var(--tk);"></div>
        <div class="br-name">Abandonar la perfección de producción</div>
        <span class="br-fit" style="background:rgba(226,75,74,.08);color:var(--tk);">Urgente</span>
      </div>
      <div class="br-insight">Las marcas que siguen invirtiendo en producción televisiva para TikTok están pagando más para conectar menos. La audiencia de 2026 detecta lo artificial en menos de 1 segundo. El vídeo grabado con iPhone por el equipo interno genera más confianza que el spot de agencia.</div>
      <div class="br-msg">"No tienes que ser perfecto. Tienes que ser real."</div>
    </div>
    <div class="br-card" data-ves-assistant-question="Explícame cómo construir una estrategia de tastemakers en TikTok para una marca española: cómo encontrarlos, cómo hacer el outreach, qué libertad creativa darles, cómo medir su impacto y cuánto invertir">
      <div class="br-top">
        <div class="br-dot" style="background:var(--pk);"></div>
        <div class="br-name">Invertir en tastemakers de nicho, no en macro-influencers</div>
        <span class="br-fit" style="background:rgba(212,83,126,.08);color:var(--pk);">Alto ROI</span>
      </div>
      <div class="br-insight">Los usuarios de TikTok son 1.5× más propensos a recomprar cuando los anuncios fomentan interacción real con la marca. Un creador con 30K seguidores en un nicho específico tiene más impacto de compra que uno de 2M en general. La clave: confianza > alcance.</div>
      <div class="br-msg">"El mejor embajador es alguien que ya te usa y lo cuenta sin guión."</div>
    </div>
    <div class="br-card" data-ves-assistant-question="Cómo puede una marca española optimizar su contenido de TikTok para aparecer en búsquedas dentro de la plataforma: qué keywords usar, qué estructura de vídeo funciona mejor para búsquedas, y qué categorías tienen menos competencia ahora mismo">
      <div class="br-top">
        <div class="br-dot" style="background:var(--b);"></div>
        <div class="br-name">Crear contenido de búsqueda, no solo de entretenimiento</div>
        <span class="br-fit" style="background:rgba(55,138,221,.08);color:var(--b);">Infrautilizado</span>
      </div>
      <div class="br-insight">La mayoría de marcas solo piensa en TikTok como entretenimiento viral. Pero el 43% de usuarios en España descubre productos buscando activamente en TikTok. Un tutorial de producto bien indexado genera tráfico cualificado de forma orgánica durante semanas.</div>
      <div class="br-msg">"Tu cliente ya está buscando lo que vendes. ¿Apareces en su búsqueda?"</div>
    </div>
    <div class="br-card" data-ves-assistant-question="Cómo pueden las marcas españolas crear comunidad real en TikTok en 2026: qué significa construir una tribu en la plataforma, cómo gestionar los comentarios como activo estratégico y ejemplos de marcas que lo están haciendo bien">
      <div class="br-top">
        <div class="br-dot" style="background:var(--g);"></div>
        <div class="br-name">Convertir los comentarios en estrategia, no en moderación</div>
        <span class="br-fit" style="background:rgba(29,158,117,.08);color:var(--g);">Diferenciador</span>
      </div>
      <div class="br-insight">Los comentarios son el nuevo campo de batalla en TikTok 2026. Las marcas que responden, pinean, crean vídeos de respuesta y leen los comentarios como insights de producto tienen una ventaja enorme. El comentario es la recomendación genuina que el algoritmo premia.</div>
      <div class="br-msg">"La sección de comentarios de tu TikTok es tu mejor focus group gratuito."</div>
    </div>
  </div>
</div>

<!-- BOTTOM ACTIONS -->
<div class="bottom">
  <button class="btn pri" data-ves-assistant-question="Diseña un calendario de contenidos TikTok para una marca española durante las próximas 4 semanas: tipos de vídeo por día, hashtags, formatos, y cómo combinar tendencias estructurales con trends de audio virales del momento">Calendario de contenidos 4 semanas ↗</button>
  <button class="btn" data-ves-assistant-question="Compara TikTok vs Instagram Reels vs YouTube Shorts en 2026 para marcas españolas: alcance orgánico, algoritmo, tipo de audiencia, ROI y en cuál invertir más según el sector">TikTok vs Reels vs Shorts ↗</button>
  <button class="btn" data-ves-assistant-question="Analiza las microestéticas que están surgiendo en TikTok España en 2026: desde el Cordobés core hasta el rural aesthetic, qué significan culturalmente y cómo pueden las marcas de moda o lifestyle conectar con ellas">Microestéticas en TikTok ES ↗</button>
  <button class="btn" data-ves-assistant-question="Cuáles son los sectores con más crecimiento orgánico en TikTok España ahora mismo en 2026: qué nichos están subexplotados, qué tipo de marcas pueden hacerse virales más fácilmente y con menos inversión">Sectores con más oportunidad ↗</button>
</div>

</div>
</section>
