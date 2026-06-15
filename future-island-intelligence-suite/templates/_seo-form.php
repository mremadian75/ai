<?php if (!defined('ABSPATH')) { exit; } ?>
<?php
$show_seo_debug = current_user_can('manage_options')
    && isset($_GET['ves_debug'])
    && sanitize_text_field(wp_unslash($_GET['ves_debug'])) === '1';
?>
<form class="ves-form ves-scraper-form ves-seo-form" data-module="seo" data-ajax="<?php echo esc_url($ajax_url); ?>" onsubmit="return false;" data-ves-required-any="seedKeyword,semrushSeoKeyword,competitorDomains,queryTerms,targets" data-ves-required-any-label="una keyword semilla, keyword SEO o dominio">
    <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
    <input type="hidden" name="outputLanguage" value="<?php echo esc_attr($output_language ?? 'es'); ?>">
    <input type="hidden" name="platform" value="semrush">
    <input type="hidden" name="targets" value="">
    <input type="hidden" name="limit" value="<?php echo esc_attr(defined('VES_DEFAULT_PROVIDER_REQUEST_COUNT') ? (int) VES_DEFAULT_PROVIDER_REQUEST_COUNT : 50); ?>" data-internal-default-limit>
    <input type="hidden" name="candidatePoolSize" value="50">
    <input type="hidden" name="semrushMaxResults" value="50">
    <input type="hidden" name="semrushSeoCountry" value="<?php echo esc_attr($semrush_country ?? 'es'); ?>">
    <input type="hidden" name="semrushCountry" value="<?php echo esc_attr($semrush_country ?? 'es'); ?>">
    <input type="hidden" name="semrushLanguage" value="<?php echo esc_attr($semrush_language ?? 'es'); ?>">
    <input type="hidden" name="semrushIncludeAuthority" value="1">
    <input type="hidden" name="semrushIncludeOverview" value="1">
    <input type="hidden" name="semrushIncludeTraffic" value="1">
    <input type="hidden" name="semrushIncludeBacklinks" value="1">
    <input type="hidden" name="semrushIncludeCompetitors" value="1">
    <input type="hidden" name="semrushIncludeKeywordVolume" value="1">
    <input type="hidden" name="semrushIncludeSerp" value="1">
    <?php if (!$show_seo_debug) : ?>
        <input type="hidden" name="targetDomain" value="">
        <input type="hidden" name="competitorDomainsAdvanced" value="">
    <?php endif; ?>

    <div class="ves-module-intro ves-seo-intro">
        <strong>SEO / SEM / AEO Intelligence</strong>
        <span>Investiga keywords, dominios, SERP, gaps de contenido y oportunidades AEO sin exponer controles técnicos del proveedor.</span>
    </div>

    <div class="ves-row ves-primary-controls-row">
        <div class="ves-field">
            <label class="ves-label">Fuente SEO</label>
            <select class="ves-select" name="seoProvider" data-seo-provider>
                <option value="semrush" selected>Semrush</option>
                <option value="ahrefs">Ahrefs</option>
                <option value="moz">MOZ</option>
                <option value="google_search">Google Search fallback</option>
            </select>
        </div>
        <div class="ves-field">
            <label class="ves-label">Flujo</label>
            <select class="ves-select" name="mode" data-seo-mode>
                <option value="keyword_magic" selected>Investigación de keywords</option>
                <option value="domain_seo">Datos de dominio / SEO</option>
                <option value="top_websites">Sitios líderes del mercado</option>
                <option value="serp_overview">Análisis SERP</option>
                <option value="keyword_ideas">AEO / gap de contenido</option>
            </select>
        </div>
    </div>

    <div class="ves-row" style="margin-top:14px">
        <div class="ves-field">
            <label class="ves-label">Keyword semilla <span class="ves-tip" tabindex="0" data-tip="Keyword corta para Semrush. El objetivo de negocio y contexto se usan solo para el análisis AI, no para la búsqueda.">?</span></label>
            <div class="ves-input-assist-wrap">
                <input class="ves-input ves-ai-assisted" type="text" name="seedKeyword" data-field-type="seo_keyword_seed" maxlength="90" spellcheck="false" placeholder="cerveza mahou">
                <button type="button" class="ves-ai-refine-button" data-target-field="seedKeyword">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="seedKeyword"></div>
        </div>
        <div class="ves-field">
            <label class="ves-label ves-targets-label">Dominio / dominios competidores <span class="ves-tip" tabindex="0" data-tip="Para flujo Domain/SEO: pega dominios como mahou.es. No incluyas el objetivo de negocio aquí.">?</span></label>
            <textarea class="ves-textarea" name="competitorDomains" data-field-type="competitor_list" maxlength="1000" spellcheck="false" placeholder="mahou.es&#10;competidor.com"></textarea>
        </div>
    </div>

    <div class="ves-row" style="margin-top:14px">
        <div class="ves-field">
            <label class="ves-label">Objetivo de negocio</label>
            <div class="ves-input-assist-wrap">
                <textarea class="ves-textarea ves-ai-assisted" name="businessObjective" data-field-type="business_objective" maxlength="700" placeholder="Encontrar oportunidades SEO para una marca de cerveza en España."></textarea>
                <button type="button" class="ves-ai-refine-button" data-target-field="businessObjective">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="businessObjective"></div>
        </div>
        <div class="ves-field ves-search-context-wrap">
            <label class="ves-label">Contexto de búsqueda</label>
            <div class="ves-input-assist-wrap">
                <textarea class="ves-textarea ves-ai-assisted" name="searchContext" data-field-type="search_context" maxlength="700" placeholder="Mercado España, categoría cerveza, competidores y búsquedas informacionales/comerciales relevantes."></textarea>
                <button type="button" class="ves-ai-refine-button" data-target-field="searchContext">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="searchContext"></div>
        </div>
    </div>

    <div class="ves-row" style="margin-top:14px">
        <div class="ves-field">
            <label class="ves-label">Objetivo del análisis AI <span class="ves-tip" tabindex="0" data-tip="Instrucción para el análisis AI. Nunca se envía como keyword a Semrush.">?</span></label>
            <div class="ves-input-assist-wrap">
                <textarea class="ves-textarea ves-ai-assisted" name="aiAnalysisGoal" data-field-type="ai_analysis_goal" maxlength="600" placeholder="Agrupar keywords por intención, detectar oportunidades de contenido y separar evidencia fuerte de hipótesis débiles."></textarea>
                <button type="button" class="ves-ai-refine-button" data-target-field="aiAnalysisGoal">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="aiAnalysisGoal"></div>
        </div>
    </div>

    <div class="ves-grid-3" style="margin-top:14px">
        <div class="ves-field"><label class="ves-label">Mercado / país</label><select class="ves-select" name="country" data-country-master><option value="ES" selected>España</option><option value="US">Estados Unidos</option><option value="GB">Reino Unido</option><option value="MX">México</option><option value="FR">Francia</option><option value="DE">Alemania</option><option value="IT">Italia</option><option value="PT">Portugal</option><option value="None">Global / cualquiera</option></select></div>
        <div class="ves-field"><label class="ves-label">Idioma</label><select class="ves-select" name="language" data-language-master><option value="es" selected>Español</option><option value="en">Inglés</option><option value="fr">Francés</option><option value="de">Alemán</option><option value="it">Italiano</option><option value="pt">Portugués</option><option value="">Cualquier idioma</option></select></div>
        <div class="ves-field"><label class="ves-label">Ordenar por</label><select class="ves-select" name="sortPriority"><option value="opportunity_score" selected>Puntuación de oportunidad</option><option value="relevance">Relevancia</option><option value="search_volume">Volumen de búsqueda</option><option value="commercial_intent">Intención comercial</option><option value="low_competition">Baja competencia</option><option value="cpc">CPC</option><option value="questions_first">Preguntas primero</option><option value="long_tail">Long-tail primero</option></select></div>
    </div>

    <details class="ves-advanced-panel ves-optional-filters" style="margin-top:16px">
        <summary>Filtros opcionales</summary>
        <div class="ves-reddit-checks" style="margin-top:14px">
            <label class="ves-checkline"><input type="checkbox" name="strictLanguageFilter" value="1"> Filtrar estrictamente por idioma</label>
            <label class="ves-checkline"><input type="checkbox" name="allowEnglishInSpanishMarket" value="1" checked> Permitir keywords SEO en inglés en mercado español</label>
            <span class="ves-hint">Mercado e idioma no siempre coinciden: términos como “seo tools” pueden tener demanda en España.</span>
        </div>
        <div class="ves-grid-3" style="margin-top:14px">
            <div class="ves-field"><label class="ves-label">Volumen mínimo</label><input class="ves-input" type="number" min="0" max="10000000" step="1" name="minSearchVolume" placeholder="0"></div>
            <div class="ves-field"><label class="ves-label">Dificultad máxima</label><input class="ves-input" type="number" min="0" max="100" step="1" name="maxDifficulty" placeholder="100"></div>
            <div class="ves-field"><label class="ves-label">Intención</label><select class="ves-select" name="seoIntent"><option value="any" selected>Cualquier intención</option><option value="informational">Informacional</option><option value="commercial">Comercial</option><option value="transactional">Transaccional</option><option value="navigational">Navegacional</option><option value="question">Preguntas</option></select></div>
        </div>
        <div class="ves-grid-3" style="margin-top:14px">
            <div class="ves-field"><label class="ves-label">CPC mínimo</label><input class="ves-input" type="number" min="0" step="0.01" name="minCpc" placeholder="0.00"></div>
            <div class="ves-field"><label class="ves-label">CPC máximo</label><input class="ves-input" type="number" min="0" step="0.01" name="maxCpc" placeholder=""></div>
            <div class="ves-field ves-field-info"><label class="ves-label">Alcance</label><div class="ves-hint" style="padding:8px 10px;background:var(--soft,#f5f7fb);border-radius:8px;font-size:12px">El sistema recopila candidatos suficientes y muestra solo los útiles.</div></div>
        </div>
        <div class="ves-reddit-checks" style="margin-top:14px">
            <label class="ves-checkline"><input type="checkbox" name="includeRelatedKeywords" value="1" checked> Keywords relacionadas</label>
            <label class="ves-checkline"><input type="checkbox" name="includeQuestions" value="1" checked> Preguntas</label>
            <label class="ves-checkline"><input type="checkbox" name="includeComparisons" value="1" checked> Comparaciones</label>
            <label class="ves-checkline"><input type="checkbox" name="includeSerpUrls" value="1"> URLs SERP</label>
        </div>
    </details>

    <?php if ($show_seo_debug) : ?>
    <details class="ves-advanced-panel ves-provider-raw-panel" style="margin-top:16px">
        <summary>Overrides técnicos del proveedor</summary>
        <p class="ves-hint">Solo admin/debug. El flujo normal usa mercado, idioma y dominios canónicos de arriba.</p>
        <div class="ves-grid-2 ves-advanced-grid" style="margin-top:14px">
            <div class="ves-field"><label class="ves-label">Semrush country</label><input class="ves-input" name="semrushCountry" value="es" maxlength="2"></div>
            <div class="ves-field"><label class="ves-label">Semrush language</label><input class="ves-input" name="semrushLanguage" value="es" maxlength="2"></div>
            <div class="ves-field"><label class="ves-label">Competitor domains override</label><textarea class="ves-textarea ves-textarea-small" name="competitorDomainsAdvanced" placeholder="competitor.com&#10;anothercompetitor.com"></textarea></div>
            <div class="ves-field"><label class="ves-label">Target domain override</label><input class="ves-input" type="text" name="targetDomain" placeholder="yourbrand.com"></div>
        </div>
    </details>
    <?php endif; ?>

    <div class="ves-bottom-cta">
        <button type="button" class="ves-btn ves-btn-primary ves-start ves-bottom-start">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
            Ejecutar SEO
        </button>
    </div>
</form>
