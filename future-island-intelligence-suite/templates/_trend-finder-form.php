<?php if (!defined('ABSPATH')) { exit; } ?>
<form class="ves-form ves-trend-form" data-module="trend" data-ajax="<?php echo esc_url($ajax_url); ?>" onsubmit="return false;">
    <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
    <input type="hidden" name="platform" value="trend_finder">
    <input type="hidden" name="outputLanguage" value="<?php echo esc_attr($output_language ?? 'es'); ?>">
    <input type="hidden" name="allowProviderOverBudget" value="0" data-allow-provider-over-budget>

    <div class="ves-grid-2">
        <div class="ves-field">
            <label class="ves-label">Categoría / tema</label>
            <div class="ves-input-assist-wrap"><input class="ves-input ves-ai-assisted" type="text" name="trendCategory" maxlength="140" placeholder="Bebidas / cerveza"><button type="button" class="ves-ai-refine-button" data-target-field="trendCategory">Mejorar</button></div>
            <div class="ves-input-suggestions" data-suggestions-for="trendCategory"></div>
        </div>
        <div class="ves-field">
            <label class="ves-label">Rango temporal</label>
            <select class="ves-select" name="trendRange">
                <option value="24h">Últimas 24 horas</option>
                <option value="7d" selected>Últimos 7 días</option>
                <option value="30d">Últimos 30 días</option>
                <option value="90d">Últimos 90 días</option>
                <option value="12m">Últimos 12 meses</option>
            </select>
        </div>
        <div class="ves-field">
            <label class="ves-label">País / mercado</label>
            <select class="ves-select" name="trendCountry">
                <option value="ES" selected>España</option><option value="US">Estados Unidos</option><option value="GB">Reino Unido</option><option value="MX">México</option><option value="FR">Francia</option><option value="DE">Alemania</option><option value="IT">Italia</option><option value="None">Global / cualquier país</option>
            </select>
        </div>
        <div class="ves-field">
            <label class="ves-label">Idioma</label>
            <select class="ves-select" name="trendLanguage">
                <option value="es" selected>Español</option><option value="en">Inglés</option><option value="fr">Francés</option><option value="de">Alemán</option><option value="it">Italiano</option><option value="pt">Portugués</option><option value="">Cualquier idioma</option>
            </select>
        </div>
    </div>

    <div class="ves-grid-3" style="margin-top:14px">
        <div class="ves-field"><label class="ves-label">Tipo de tendencia</label><select class="ves-select" name="trendType"><option value="emerging" selected>Emergente</option><option value="viral">Viral</option><option value="seasonal">Estacional</option><option value="competitor_driven">Impulsada por competidores</option><option value="search_driven">Impulsada por búsqueda</option><option value="social_driven">Impulsada por social</option></select></div>
        <div class="ves-field"><label class="ves-label">Alcance de fuentes</label><select class="ves-select" name="sourceBudgetLevel"><option value="low_cost" selected>Bajo coste / lite</option><option value="balanced">Equilibrado (bloqueado en MVP)</option><option value="deep_scan" disabled data-mvp-locked="1">Escaneo profundo — próximamente</option></select><div class="ves-hint">El escaneo profundo puede implicar más coste de proveedor; revisa la estimación antes de ejecutar.</div></div>
        <div class="ves-field"><label class="ves-label">Ordenar por</label><select class="ves-select" name="trendSort"><option value="business_opportunity" selected>Oportunidad de negocio</option><option value="freshness">Recencia</option><option value="growth">Crecimiento</option><option value="relevance">Relevancia</option><option value="engagement">Engagement</option></select></div>
    </div>

    <?php /* v0.9.25.0 — Phase 2 + Phase 8: result count + candidate pool hidden from users.
        System picks the right candidate budget from "Alcance de fuentes" automatically. */ ?>
    <input type="hidden" name="candidatePoolSize" value="18">
    <input type="hidden" name="desiredFinalTrends" value="4">
                <input type="hidden" name="maxSources" value="3">

    <div class="ves-grid-2" style="margin-top:14px">
        <div class="ves-field"><label class="ves-label">Fuentes</label><select class="ves-select" name="trendFuentes"><option value="lite" selected>Lite: búsqueda ligera + señales baratas</option><option value="balanced">Búsqueda + social equilibrado (bloqueado en MVP)</option><option value="search_only">Solo búsqueda</option><option value="social_only">Solo social</option><option value="news_search_social">Noticias + búsqueda + social</option></select><div class="ves-hint">El sistema escanea suficientes candidatos automáticamente — no necesitas configurar la cantidad.</div></div>
    </div>

    <div class="ves-field" style="margin-top:14px">
        <label class="ves-label">Objetivo de negocio / motivo de búsqueda</label>
        <div class="ves-input-assist-wrap"><textarea class="ves-textarea ves-ai-assisted" name="trendReason" maxlength="700" placeholder="Ej. encontrar oportunidades de contenido y campañas para una marca de cerveza en España"></textarea><button type="button" class="ves-ai-refine-button" data-target-field="trendReason">Mejorar</button></div>
        <div class="ves-input-suggestions" data-suggestions-for="trendReason"></div>
    </div>

    <div class="ves-preflight-card ves-cost-estimate-card" data-trend-estimate>
        <span class="ves-section-eyebrow">Estimación de coste</span>
        <strong>Antes de ejecutar, estimamos los créditos necesarios y el riesgo de coste del proveedor.</strong>
        <p>El cargo final depende de la evidencia útil encontrada.</p>
        <button type="button" class="ves-secondary-btn ves-trend-estimate-btn">Estimar créditos de Trend Finder</button>
        <div class="ves-trend-estimate-output" data-ves-trend-estimate-host></div>
    </div>

    <div class="ves-bottom-cta">
        <button type="button" class="ves-btn ves-btn-primary ves-start ves-bottom-start">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
            Ejecutar Trend Finder
        </button>
    </div>
</form>
