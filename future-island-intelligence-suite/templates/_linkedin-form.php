<?php if (!defined('ABSPATH')) { exit; } ?>
<form class="ves-form ves-linkedin-form" data-module="linkedin" data-ajax="<?php echo esc_url($ajax_url); ?>" onsubmit="return false;">
    <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
    <input type="hidden" name="outputLanguage" value="<?php echo esc_attr($output_language ?? 'es'); ?>">
    <input type="hidden" name="platform" value="linkedin">
    <input type="hidden" name="mode" value="search">
    <input type="hidden" name="option" value="ALL">
    <input type="hidden" name="sortPriority" value="relevance">
    <input type="hidden" name="country" value="None">
    <input type="hidden" name="language" value="">
    <input type="hidden" name="datePreset" value="any">
    <input type="hidden" name="dateFromIso" value="">
    <input type="hidden" name="linkedinProfileMode" value="Short">

    <div class="ves-module-intro ves-linkedin-intro">
        <strong>LinkedIn Intelligence</strong>
        <span>Esta instalación soporta principalmente análisis de posts. Los modos de personas/empresa quedan bloqueados hasta activar una fuente compatible.</span>
    </div>

    <div class="ves-row ves-primary-controls-row">
        <div class="ves-field">
            <label class="ves-label">Objetivo de LinkedIn</label>
            <select class="ves-select" name="linkedinObjective" data-linkedin-objective>
                <option value="analyze_posts" selected>Analizar posts</option>
                <option value="thought_leadership_topic_scan">Escaneo de liderazgo temático</option>
                <option value="competitor_company_scan" disabled data-requires-compatible-source="1">Escaneo de empresa competidora — requiere fuente compatible</option>
                <option value="find_people" disabled data-requires-compatible-source="1">Buscar personas — requiere fuente compatible</option>
                <option value="scan_profile" disabled data-requires-compatible-source="1">Analizar perfil — requiere fuente compatible</option>
                <option value="scan_company" disabled data-requires-compatible-source="1">Analizar página de empresa — requiere fuente compatible</option>
                <option value="search_companies" disabled data-requires-compatible-source="1">Buscar empresas — requiere fuente compatible</option>
                <option value="find_leads" disabled data-requires-compatible-source="1">Prospección de leads — requiere revisión legal y fuente compatible</option>
            </select>
            <div class="ves-hint">Primero elige el objetivo. El formulario se reduce a los campos necesarios para ese modo.</div>
        </div>
        <?php /* v0.9.26.0 — Phase 2: user-facing result count removed. */ ?>
        <input type="hidden" name="limit" value="<?php echo esc_attr(defined('VES_DEFAULT_PROVIDER_REQUEST_COUNT') ? (int) VES_DEFAULT_PROVIDER_REQUEST_COUNT : 50); ?>" data-internal-default-limit>
    </div>

    <section class="ves-linkedin-objective-panel" data-linkedin-objective-panel="analyze_posts thought_leadership_topic_scan">
        <div class="ves-row" style="margin-top:14px">
            <div class="ves-field">
                <label class="ves-label ves-targets-label">Tema, URL de post o keywords</label>
                <div class="ves-input-assist-wrap">
                    <textarea class="ves-textarea ves-ai-assisted" name="targets" maxlength="1000" spellcheck="false" placeholder="marketing cervecero España&#10;https://www.linkedin.com/company/example/&#10;liderazgo de opinión en bebidas"></textarea>
                    <button type="button" class="ves-ai-refine-button" data-target-field="targets">Mejorar</button>
                </div>
                <div class="ves-input-suggestions" data-suggestions-for="targets"></div>
                <div class="ves-hint ves-target-hint">Una línea por tema, URL de compañía o consulta. No pegues credenciales ni datos privados.</div>
            </div>
            <div class="ves-field">
                <label class="ves-label">Objetivo del análisis</label>
                <div class="ves-input-assist-wrap">
                    <textarea class="ves-textarea ves-ai-assisted" name="searchContext" maxlength="700" placeholder="Detectar patrones de contenido LinkedIn que puedan inspirar posicionamiento B2B de bebidas en España."></textarea>
                    <button type="button" class="ves-ai-refine-button" data-target-field="searchContext">Mejorar</button>
                </div>
                <div class="ves-input-suggestions" data-suggestions-for="searchContext"></div>
            </div>
        </div>
        <div class="ves-row" style="margin-top:14px">
            <div class="ves-field">
                <label class="ves-label">Objetivo del análisis AI</label>
                <div class="ves-input-assist-wrap">
                    <textarea class="ves-textarea ves-ai-assisted" name="aiAnalysisObjetivo" data-field-type="ai_analysis_goal" maxlength="600" placeholder="Identificar patrones B2B, gaps de evidencia, objeciones de audiencia y próximos pasos de validación."></textarea>
                    <button type="button" class="ves-ai-refine-button" data-target-field="aiAnalysisObjetivo">Mejorar</button>
                </div>
                <div class="ves-input-suggestions" data-suggestions-for="aiAnalysisObjetivo"></div>
                <div class="ves-hint">Instrucción solo para el análisis AI; no se envía como consulta de búsqueda.</div>
            </div>
        </div>
        <div class="ves-grid-3" style="margin-top:14px">
            <div class="ves-field">
                <label class="ves-label">Rango de fechas</label>
                <select class="ves-select" name="linkedinDateRange">
                    <option value="any" selected>Cualquier fecha</option>
                    <option value="30d">Últimos 30 días</option>
                    <option value="90d">Últimos 90 días</option>
                    <option value="365d">Último año</option>
                </select>
            </div>
            <div class="ves-field">
                <label class="ves-label">Idioma del contenido</label>
                <select class="ves-select" name="linkedinContentLanguage">
                    <option value="" selected>Cualquier idioma</option>
                    <option value="es">Español</option>
                    <option value="en">Inglés</option>
                    <option value="fr">Francés</option>
                    <option value="de">Alemán</option>
                </select>
            </div>
            <div class="ves-field">
                <label class="ves-label">Modo de ordenación</label>
                <select class="ves-select" name="rankingMode">
                    <option value="best_match" selected>Mejor ajuste al tema</option>
                    <option value="highest_engagement">Mayor engagement</option>
                    <option value="newest">Más reciente</option>
                    <option value="thought_leadership">Calidad de liderazgo de opinión</option>
                </select>
            </div>
        </div>
        <div class="ves-grid-4 ves-linkedin-preset-filters" style="margin-top:14px">
            <div class="ves-field"><label class="ves-label">Industria</label><select class="ves-select" name="linkedinIndustriaPreset"><option value="">Cualquier industria</option><option value="marketing">Marketing</option><option value="saas">SaaS / Software</option><option value="automotive">Automotive</option><option value="ecommerce">Ecommerce</option><option value="healthcare">Healthcare</option><option value="finance">Finance</option><option value="education">Education</option></select></div>
            <div class="ves-field"><label class="ves-label">Senioridad</label><select class="ves-select" name="linkedinSenioridadPreset"><option value="">Cualquier senioridad</option><option value="founder">Founder</option><option value="c_level">C-Level</option><option value="vp">VP</option><option value="director">Director</option><option value="manager">Manager</option><option value="specialist">Specialist</option></select></div>
            <div class="ves-field"><label class="ves-label">Función</label><select class="ves-select" name="linkedinFunciónPreset"><option value="">Cualquier función</option><option value="marketing">Marketing</option><option value="sales">Sales</option><option value="product">Product</option><option value="operations">Operations</option><option value="hr">HR</option><option value="finance">Finance</option></select></div>
            <div class="ves-field"><label class="ves-label">Tamaño de empresa</label><select class="ves-select" name="linkedinCompanySizePreset"><option value="">Cualquier tamaño</option><option value="1-10">1–10</option><option value="11-50">11–50</option><option value="51-200">51–200</option><option value="201-500">201–500</option><option value="501-1000">501–1000</option><option value="1000+">1000+</option></select></div>
        </div>
        <div class="ves-grid-2 ves-linkedin-preset-filters" style="margin-top:14px">
            <div class="ves-field"><label class="ves-label">Ubicación</label><select class="ves-select" name="linkedinLocationPreset"><option value="">Cualquier ubicación</option><option value="Spain">Spain</option><option value="Madrid">Madrid</option><option value="Barcelona">Barcelona</option><option value="EU">European Union</option><option value="United States">United States</option></select></div>
            <div class="ves-field"><label class="ves-label">Actividad pública</label><select class="ves-select" name="linkedinActivityPreset"><option value="recent_posts" selected>Recently posted</option><option value="any">Any public activity</option><option value="thought_leaders">Thought leaders only</option></select></div>
        </div>
        <details class="ves-advanced-panel" style="margin-top:14px">
            <summary>Filtros avanzados de posts</summary>
            <div class="ves-row" style="margin-top:14px">
                <div class="ves-field"><label class="ves-label">URLs públicas relevantes</label><textarea class="ves-textarea ves-textarea-small" name="linkedinCurrentCompanies" placeholder="https://www.linkedin.com/company/example/"></textarea></div>
                <div class="ves-field"><label class="ves-label">Cargo / keywords de autor</label><textarea class="ves-textarea ves-textarea-small" name="linkedinCurrentJobTitles" placeholder="CMO&#10;Marketing Director&#10;Founder"></textarea></div>
            </div>
            <label class="ves-checkline"><input type="checkbox" name="linkedinRecentlyPostedOnLinkedIn" value="1" checked> Priorizar autores activos recientemente</label>
            <label class="ves-checkline"><input type="checkbox" name="linkedinAutoQuerySegmentation" value="1" checked> Segmentación automática de consultas</label>
        </details>
    </section>

    <section class="ves-linkedin-objective-panel" data-linkedin-objective-panel="find_people scan_profile scan_company search_companies find_leads" hidden>
        <div class="ves-setup-required-card">
            <strong>Modo bloqueado: requiere fuente compatible de LinkedIn</strong>
            <p>Esta instalación solo tiene confirmada la fuente de posts. Para activar este objetivo, un administrador debe configurar una fuente compatible y revisar permisos, coste, legalidad y límites del caso de uso.</p>
        </div>
        <div class="ves-grid-2" style="margin-top:14px">
            <div class="ves-field"><label class="ves-label">Rol, cargo, empresa o URL pública</label><textarea class="ves-textarea" name="targets_setup_preview" disabled placeholder="Marketing Director Spain&#10;https://www.linkedin.com/in/...&#10;https://www.linkedin.com/company/..."></textarea></div>
            <div class="ves-field"><label class="ves-label">Objetivo</label><textarea class="ves-textarea" name="searchContext_setup_preview" disabled placeholder="¿Qué necesitas aprender de esta fuente de LinkedIn?"></textarea></div>
        </div>
    </section>

    <?php
    // v0.9.26.0 — Phase 8: raw provider filters hidden unless admin debug mode
    // is explicitly toggled via ?ves_debug=1. The previous version showed them
    // to every manage_options user, which is too aggressive.
    $show_li_raw = current_user_can('manage_options')
        && isset($_GET['ves_debug'])
        && sanitize_text_field(wp_unslash($_GET['ves_debug'])) === '1';
    ?>
    <?php if ($show_li_raw) : ?>
    <details class="ves-advanced-panel ves-provider-raw-panel" style="margin-top:16px">
        <summary>Advanced raw source filters</summary>
        <p class="ves-hint">Estos campos son para admins/equipos técnicos. No son necesarios para el flujo normal y no deberían mostrarse como el producto principal.</p>
        <div class="ves-row" style="margin-top:14px">
            <div class="ves-field"><label class="ves-label">Locations</label><textarea class="ves-textarea ves-textarea-small" name="linkedinLocations" maxlength="1200" placeholder="Spain&#10;Madrid&#10;Barcelona"></textarea></div>
            <div class="ves-field"><label class="ves-label">Current company URLs</label><textarea class="ves-textarea ves-textarea-small" name="linkedinCurrentCompaniesRaw" maxlength="2000" placeholder="https://www.linkedin.com/company/company-name/"></textarea></div>
        </div>
        <div class="ves-row" style="margin-top:14px">
            <div class="ves-field"><label class="ves-label">Industria IDs</label><textarea class="ves-textarea ves-textarea-small" name="linkedinIndustriaIds" placeholder="4&#10;96"></textarea></div>
            <div class="ves-field"><label class="ves-label">Senioridad IDs</label><textarea class="ves-textarea ves-textarea-small" name="linkedinSenioridadLevelIds" placeholder="120"></textarea></div>
        </div>
        <div class="ves-row" style="margin-top:14px">
            <div class="ves-field"><label class="ves-label">Función IDs</label><textarea class="ves-textarea ves-textarea-small" name="linkedinFunciónIds" placeholder="8"></textarea></div>
            <div class="ves-field"><label class="ves-label">Company headcount</label><textarea class="ves-textarea ves-textarea-small" name="linkedinCompanyHeadcount" placeholder="B&#10;C&#10;D"></textarea></div>
        </div>
        <div class="ves-row" style="margin-top:14px">
            <label class="ves-checkline"><input type="checkbox" name="linkedinRecentlyChangedJobs" value="1"> Recently changed jobs</label>
            <label class="ves-checkline"><input type="checkbox" name="linkedinAutoQuerySegmentation" value="1"> Segmentación automática de consultas</label>
        </div>
    </details>
    <?php endif; ?>

    <div class="ves-bottom-cta">
        <button type="button" class="ves-btn ves-btn-primary ves-start ves-bottom-start">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
            Ejecutar LinkedIn
        </button>
    </div>
</form>
