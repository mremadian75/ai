<?php if (!defined('ABSPATH')) { exit; } ?>
<?php
$form_default_platform = isset($form_default_platform) ? $form_default_platform : 'facebook_ads';
?>
<form class="ves-form ves-scraper-form ves-ads-form" data-module="ads" data-ajax="<?php echo esc_url($ajax_url); ?>" onsubmit="return false;" data-ves-required-any="queryTerms,postUrls,competitorDomains" data-ves-required-any-label="al menos un anunciante/keyword, URL de transparencia o dominio competidor">
    <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
    <input type="hidden" name="outputLanguage" value="<?php echo esc_attr($output_language ?? 'es'); ?>">
    <input type="hidden" name="platform" value="<?php echo esc_attr($form_default_platform); ?>">
    <input type="hidden" name="targets" value="">
    <input type="hidden" name="sortPriority" value="relevance">
    <input type="hidden" name="extraSelect2" value="">
    <input type="hidden" name="extraNumber1" value="">
    <input type="hidden" name="language" value="">
    <input type="hidden" name="dateFromIso" value="">
    <input type="hidden" name="candidatePoolSize" value="">
    <input type="hidden" name="desiredFinalResults" value="">
    <?php /* v0.9.25.0 — Phase 2: user-facing result count removed. */ ?>
    <input type="hidden" name="limit" value="<?php echo esc_attr(defined('VES_DEFAULT_PROVIDER_REQUEST_COUNT') ? (int) VES_DEFAULT_PROVIDER_REQUEST_COUNT : 25); ?>" data-internal-default-limit>

    <div class="ves-row ves-primary-controls-row">
        <div class="ves-field ves-mode-wrap">
            <label class="ves-label">Modo</label>
            <select class="ves-select" name="mode">
                <option value="search" selected>Búsqueda de anunciante / dominio</option>
                <option value="urls">URLs de librería / transparencia</option>
                <option value="competitor_comparison">Comparar competidores</option>
                <option value="creative_pattern">Patrones creativos</option>
            </select>
        </div>
        <div class="ves-field ves-ranking-mode-wrap">
            <label class="ves-label">Ordenar por</label>
            <select class="ves-select" name="rankingMode">
                <option value="best_match" selected>Mejor ajuste al objetivo</option>
                <option value="business_opportunity">Oportunidad de negocio</option>
                <option value="newest">Más recientes</option>
                <option value="highest_engagement">Mayor señal visible</option>
            </select>
        </div>
    </div>

    <?php if (class_exists('VES_Config') && class_exists('VES_Usage_Billing') && is_user_logged_in()): ?>
        <div class="ves-field ves-run-cost-hint" style="padding:6px 0;margin-bottom:10px">
            <span class="ves-hint" style="font-size:12px">
                <?php
                $run_cost = VES_Config::cost_manual_run();
                $balance  = VES_Usage_Billing::get_balance(get_current_user_id());
                $is_unlimited = VES_Usage_Billing::is_unlimited_user(get_current_user_id());
                if ($is_unlimited) {
                    echo '✅ Admin — créditos ilimitados.';
                } elseif ($balance !== null && $balance < $run_cost) {
                    echo '⚠️ <strong>Saldo insuficiente.</strong> Este escaneo cuesta <strong>' . esc_html($run_cost) . ' créditos</strong>. Saldo actual: <strong>' . esc_html(number_format((float)$balance, 2)) . '</strong>.';
                } else {
                    echo 'Estimación previa: <strong>' . esc_html($run_cost) . ' crédito' . ($run_cost == 1 ? '' : 's') . '</strong> de escaneo base. Los resultados útiles y el análisis AI se liquidan aparte.';
                }
                ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="ves-row ves-ads-target-row">
        <div class="ves-field">
            <label class="ves-label">Anunciante, dominio o búsqueda</label>
            <div class="ves-input-assist-wrap">
                <textarea class="ves-textarea ves-ai-assisted" name="queryTerms" data-field-type="ads_target" maxlength="700" spellcheck="false" placeholder="nike.com&#10;competitor brand&#10;marketing intelligence"></textarea>
                <button type="button" class="ves-ai-refine-button" data-target-field="queryTerms">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="queryTerms"></div>
            <div class="ves-hint ves-target-hint">Usa dominios, nombres de anunciantes o keywords cortas. No hashtags.</div>
        </div>
        <div class="ves-field">
            <label class="ves-label">URLs de Ads Library / transparencia</label>
            <textarea class="ves-textarea" name="postUrls" data-field-type="ad_url" maxlength="1400" spellcheck="false" placeholder="https://www.facebook.com/ads/library/?...&#10;https://adstransparency.google.com/...&#10;https://www.facebook.com/brandpage"></textarea>
            <div class="ves-hint">Opcional. Usa URLs públicas de Meta Ads Library, Google Ads Transparency o páginas públicas.</div>
        </div>
    </div>

    <div class="ves-row ves-ads-filter-row">
        <div class="ves-field">
            <label class="ves-label">Dominios / páginas competidoras</label>
            <textarea class="ves-textarea ves-textarea-small" name="competitorDomains" data-field-type="competitor_domain" maxlength="700" spellcheck="false" placeholder="competitor.com&#10;brand.com"></textarea>
        </div>
        <div class="ves-field">
            <label class="ves-label">Formato creativo</label>
            <select class="ves-select" name="extraSelect1">
                <option value="ALL" selected>Todos</option>
                <option value="IMAGE">Imagen</option>
                <option value="VIDEO">Vídeo</option>
                <option value="CAROUSEL">Carrusel</option>
                <option value="TEXT">Búsqueda / texto</option>
                <option value="DISPLAY">Display</option>
            </select>
        </div>
    </div>

    <div class="ves-row ves-ads-filter-row">
        <div class="ves-field">
            <label class="ves-label">País / región</label>
            <select class="ves-select" name="country">
                <option value="ES" selected>España</option>
                <option value="US">Estados Unidos</option>
                <option value="GB">Reino Unido</option>
                <option value="FR">Francia</option>
                <option value="DE">Alemania</option>
                <option value="None">Global / sin país</option>
            </select>
        </div>
        <div class="ves-field">
            <label class="ves-label">Fecha</label>
            <select class="ves-select" name="datePreset">
                <option value="30d" selected>Últimos 30 días</option>
                <option value="90d">Últimos 90 días</option>
                <option value="180d">Últimos 6 meses</option>
                <option value="365d">Último año</option>
                <option value="any">Sin límite</option>
            </select>
        </div>
    </div>

    <div class="ves-row ves-context-row">
        <div class="ves-field">
            <label class="ves-label">Objetivo de campaña</label>
            <div class="ves-input-assist-wrap">
                <textarea class="ves-textarea ves-ai-assisted" name="businessObjective" data-field-type="business_objective" maxlength="900" spellcheck="false" placeholder="Ejemplo: detectar patrones de oferta y CTA que podamos convertir en ángulos de campaña."></textarea>
                <button type="button" class="ves-ai-refine-button" data-target-field="businessObjective">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="businessObjective"></div>
        </div>
        <div class="ves-field">
            <label class="ves-label">Objetivo del análisis AI</label>
            <div class="ves-input-assist-wrap">
                <textarea class="ves-textarea ves-ai-assisted" name="aiAnalysisGoal" data-field-type="ai_analysis_goal" maxlength="900" spellcheck="false" placeholder="Separar hallazgos confirmados, hipótesis débiles, riesgos y próximas acciones basadas en evidencia."></textarea>
                <button type="button" class="ves-ai-refine-button" data-target-field="aiAnalysisGoal">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="aiAnalysisGoal"></div>
        </div>
    </div>

    <div class="ves-bottom-cta">
        <button type="button" class="ves-btn ves-btn-primary ves-start ves-bottom-start">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
            Ejecutar Ads Intelligence
        </button>
    </div>
</form>
