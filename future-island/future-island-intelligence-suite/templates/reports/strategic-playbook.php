<?php
// v0.9.31.8-p4.1 — Strategic playbook report. Rebuilt to match the uploaded
// curated report design system (Space Grotesk / Space Mono, scoped tokens).
// Data-driven from the $report schema: the signal -> brief -> output flow band
// is computed from summary + counts; insights, opportunities, next_actions and
// warnings/discard_reasons drive the sections.
// Self-contained scoped styles; no external JS or icon-font dependency.
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/_partials.php';

$summary       = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$evidence      = is_array($report['evidence'] ?? null) ? $report['evidence'] : [];
$insights      = is_array($report['insights'] ?? null) ? $report['insights'] : [];
$opportunities = is_array($report['opportunities'] ?? null) ? $report['opportunities'] : [];
$next_actions  = is_array($report['next_actions'] ?? null) ? $report['next_actions'] : [];
$warnings      = is_array($report['warnings'] ?? null) ? $report['warnings'] : [];
$discard       = is_array($report['discard_reasons'] ?? null) ? $report['discard_reasons'] : [];

$evidence_count = (int) ($summary['evidence_count'] ?? count($evidence));
$usable_count   = (int) ($summary['usable_count'] ?? $evidence_count);
$conf           = ves_report_confidence_meta((string) ($summary['confidence'] ?? 'limited'));
$is_sample      = !empty($report['sample_mode']);
$limits         = array_slice(array_merge($warnings, $discard), 0, 6);

$fmt = static function ($n) {
    if (!is_numeric($n)) { return "0"; }
    $n = (float) $n;
    if (floor($n) == $n) {
        $n = (int) $n;
        return function_exists("number_format_i18n") ? number_format_i18n($n) : number_format($n);
    }
    return function_exists("number_format_i18n") ? number_format_i18n($n, 1) : number_format($n, 1);
};
?>
<section class="ves-report ves-report-playbook" aria-labelledby="ves-report-playbook-title">
<style>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap');
.ves-report.ves-report-playbook{padding:0;background:transparent;border:0;box-shadow:none;border-radius:var(--border-radius-lg,16px);overflow:hidden;}
.ves-report-playbook{
  --r:#E24B4A; --r2:#A32D2D; --g:#1D9E75; --b:#378ADD; --pp:#7F77DD; --a:#BA7517; --nt:#888780;
  --ink:var(--color-text-primary); --sub:var(--color-text-secondary);
  --bg:var(--color-background-primary); --bg2:var(--color-background-secondary);
  --bd:var(--color-border-tertiary); --bd2:var(--color-border-secondary);
  --ff:'Space Grotesk',sans-serif; --fm:'Space Mono',monospace;
  font-family:var(--ff);background:var(--bg);border:0.5px solid var(--bd);border-radius:var(--border-radius-lg,16px);
}
.ves-report-playbook *{box-sizing:border-box;margin:0;padding:0;}
.ves-report-playbook .hero{padding:1.5rem 1.25rem 1.25rem;border-bottom:0.5px solid var(--bd);display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;}
.ves-report-playbook .hero-kicker{font-family:var(--fm);font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--r);font-weight:700;margin-bottom:8px;}
.ves-report-playbook .hero-title{font-size:25px;font-weight:800;color:var(--ink);line-height:1.12;letter-spacing:-.9px;margin-bottom:.5rem;}
.ves-report-playbook .hero-tagline{font-size:13px;color:var(--sub);max-width:520px;line-height:1.6;}
.ves-report-playbook .hero-badge{font-family:var(--fm);font-size:9px;padding:5px 10px;border-radius:12px;background:rgba(226,75,74,.1);color:var(--r);border:0.5px solid rgba(226,75,74,.25);flex-shrink:0;}
.ves-report-playbook .hero-badge.is-sample{background:rgba(186,117,23,.12);color:var(--a);border-color:rgba(186,117,23,.3);}
.ves-report-playbook .flow{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:0.5px solid var(--bd);}
.ves-report-playbook .flow-step{padding:1.1rem 1.25rem;border-right:0.5px solid var(--bd);position:relative;}
.ves-report-playbook .flow-step:last-child{border-right:none;}
.ves-report-playbook .flow-step::after{content:"\2192";position:absolute;right:-9px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--sub);background:var(--bg);width:18px;text-align:center;z-index:1;}
.ves-report-playbook .flow-step:last-child::after{content:none;}
.ves-report-playbook .flow-n{font-family:var(--fm);font-size:10px;color:var(--sub);margin-bottom:6px;}
.ves-report-playbook .flow-name{font-size:11px;font-weight:700;color:var(--ink);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;}
.ves-report-playbook .flow-val{font-size:24px;font-weight:800;color:var(--ink);line-height:1;}
.ves-report-playbook .flow-foot{font-family:var(--fm);font-size:9px;color:var(--sub);margin-top:5px;}
.ves-report-playbook .section{padding:1.25rem;border-bottom:0.5px solid var(--bd);}
.ves-report-playbook .section:last-child{border-bottom:none;}
.ves-report-playbook .sec-head{display:flex;align-items:center;gap:8px;margin-bottom:1rem;flex-wrap:wrap;}
.ves-report-playbook .sec-n{font-family:var(--fm);font-size:10px;color:var(--sub);}
.ves-report-playbook .sec-t{font-size:13px;font-weight:700;color:var(--ink);letter-spacing:-.3px;}
.ves-report-playbook .sec-meta{font-family:var(--fm);font-size:9px;color:var(--sub);margin-left:auto;}
.ves-report-playbook .claim-list{display:grid;gap:8px;}
.ves-report-playbook .claim{display:flex;gap:10px;border:0.5px solid var(--bd);border-radius:var(--border-radius-md,10px);padding:.75rem .875rem;background:var(--bg2);}
.ves-report-playbook .claim-rail{width:3px;border-radius:2px;background:var(--g);flex-shrink:0;}
.ves-report-playbook .claim-txt{font-size:11px;color:var(--ink);line-height:1.55;}
.ves-report-playbook .brief-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;}
.ves-report-playbook .brief-card{border:0.5px solid var(--bd);border-radius:var(--border-radius-lg,12px);padding:1rem;}
.ves-report-playbook .brief-label{font-family:var(--fm);font-size:9px;color:var(--r);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.ves-report-playbook .brief-txt{font-size:11px;color:var(--ink);line-height:1.55;}
.ves-report-playbook .test-list{display:grid;gap:7px;}
.ves-report-playbook .test-item{display:flex;gap:9px;background:var(--bg2);border-radius:var(--border-radius-md,8px);padding:.7rem .85rem;}
.ves-report-playbook .test-n{font-family:var(--fm);font-size:10px;font-weight:700;color:#fff;background:var(--r);width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.ves-report-playbook .test-txt{font-size:11px;color:var(--ink);line-height:1.45;}
.ves-report-playbook .limit-box{background:rgba(186,117,23,.06);border:0.5px solid rgba(186,117,23,.25);border-radius:var(--border-radius-md,10px);padding:.875rem 1rem;}
.ves-report-playbook .limit-row{display:flex;gap:8px;font-size:11px;color:var(--ink);line-height:1.5;margin-bottom:7px;}
.ves-report-playbook .limit-row:last-child{margin-bottom:0;}
.ves-report-playbook .limit-mark{color:var(--a);flex-shrink:0;font-weight:700;}
.ves-report-playbook .empty{font-size:11px;color:var(--sub);font-style:italic;}
.ves-report-playbook .footer{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:1rem 1.25rem;border-top:0.5px solid var(--bd);}
.ves-report-playbook .footer-note{font-family:var(--fm);font-size:10px;color:var(--sub);}
@media (max-width: 900px){
  .ves-report-playbook .flow{grid-template-columns:1fr;}
  .ves-report-playbook .flow-step{border-right:none;border-bottom:0.5px solid var(--bd);}
  .ves-report-playbook .flow-step:last-child{border-bottom:none;}
  .ves-report-playbook .flow-step::after{content:none;}
  .ves-report-playbook .brief-grid{grid-template-columns:1fr;}
}
@media (max-width: 560px){
  .ves-report-playbook .hero{flex-direction:column;}
  .ves-report-playbook .hero-title{font-size:22px;}
}
</style>

  <div class="hero">
    <div class="hero-left">
      <div class="hero-kicker">Señal → Brief → Output</div>
      <h2 id="ves-report-playbook-title" class="hero-title"><?php echo esc_html($report['title']); ?></h2>
      <p class="hero-tagline"><?php echo esc_html($report['subtitle']); ?></p>
    </div>
    <span class="hero-badge <?php echo $is_sample ? 'is-sample' : ''; ?>"><?php echo $is_sample ? 'Modo muestra' : 'Playbook accionable'; ?></span>
  </div>

  <div class="flow">
    <div class="flow-step">
      <div class="flow-n">FASE 01</div>
      <div class="flow-name">Evidencia</div>
      <div class="flow-val"><?php echo esc_html($fmt($evidence_count)); ?></div>
      <div class="flow-foot"><?php echo esc_html($fmt($usable_count)); ?> registros usables</div>
    </div>
    <div class="flow-step">
      <div class="flow-n">FASE 02</div>
      <div class="flow-name">Interpretación</div>
      <div class="flow-val"><?php echo (int) $conf['pct']; ?>°</div>
      <div class="flow-foot">confianza <?php echo esc_html($conf['label']); ?></div>
    </div>
    <div class="flow-step">
      <div class="flow-n">FASE 03</div>
      <div class="flow-name">Acción</div>
      <div class="flow-val"><?php echo esc_html($fmt(count($next_actions))); ?></div>
      <div class="flow-foot">pasos definidos</div>
    </div>
  </div>

  <div class="section">
    <div class="sec-head">
      <span class="sec-n">01</span><span class="sec-t">Lo que podemos afirmar</span>
      <span class="sec-meta"><?php echo esc_html($fmt(count($insights))); ?> lecturas</span>
    </div>
    <div class="claim-list">
      <?php if ($insights): foreach (array_slice($insights, 0, 6) as $ins): ?>
        <div class="claim"><span class="claim-rail" aria-hidden="true"></span><span class="claim-txt"><?php echo esc_html($ins); ?></span></div>
      <?php endforeach; else: ?>
        <p class="empty">Nada concluyente todavía con la evidencia disponible.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="section">
    <div class="sec-head"><span class="sec-n">02</span><span class="sec-t">Oportunidades de campaña</span></div>
    <?php if ($opportunities): ?>
    <div class="brief-grid">
      <?php foreach (array_slice($opportunities, 0, 6) as $i => $op): ?>
        <div class="brief-card">
          <div class="brief-label">Brief <?php echo str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?></div>
          <div class="brief-txt"><?php echo esc_html($op); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p class="empty">No hay oportunidad de campaña concluible sin evidencia más fuerte.</p>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="sec-head"><span class="sec-n">03</span><span class="sec-t">Qué testear ahora</span></div>
    <div class="test-list">
      <?php if ($next_actions): $n = 0; foreach (array_slice($next_actions, 0, 6) as $act): $n++; ?>
        <div class="test-item"><span class="test-n"><?php echo (int) $n; ?></span><span class="test-txt"><?php echo esc_html($act); ?></span></div>
      <?php endforeach; else: ?>
        <p class="empty">Reúne ejemplos comparables más sólidos antes de definir tests.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="section">
    <div class="sec-head"><span class="sec-n">04</span><span class="sec-t">Límites de la evidencia</span></div>
    <?php if ($limits): ?>
    <div class="limit-box">
      <?php foreach ($limits as $lim): ?>
        <div class="limit-row"><span class="limit-mark" aria-hidden="true">!</span><span><?php echo esc_html($lim); ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p class="empty">No se registraron avisos ni descartes que limiten esta lectura.</p>
    <?php endif; ?>
  </div>

  <div class="footer">
    <span class="footer-note">Playbook sobre <?php echo esc_html($fmt($evidence_count)); ?> registros · confianza <?php echo esc_html($conf['label']); ?> · revisión humana recomendada</span>
  </div>
</section>
