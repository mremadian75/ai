<?php if (!defined('ABSPATH')) { exit; } ?>
<?php
// v0.9.13.0 — simplified shared form for Social Media + Ads scrapers.
// Public UI keeps only high-signal controls. Legacy payload fields are hidden
// with safe defaults so older backend paths stay compatible.
$form_default_platform = isset($form_default_platform) ? $form_default_platform : 'tiktok';
?>
<form class="ves-form ves-scraper-form" data-module="social" data-ajax="<?php echo esc_url($ajax_url); ?>" onsubmit="return false;" data-ves-required-any="queryTerms,hashtags,profileUrls,postUrls" data-ves-required-any-label="al menos una consulta, hashtag, perfil o URL de post">
    <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
    <input type="hidden" name="outputLanguage" value="<?php echo esc_attr($output_language ?? 'es'); ?>">
    <input type="hidden" name="platform" value="<?php echo esc_attr($form_default_platform); ?>">
    <input type="hidden" name="option" value="ALL">
    <input type="hidden" name="sortPriority" value="relevance">
    <input type="hidden" name="extraSelect1" value="ALL">
    <input type="hidden" name="extraSelect2" value="">
    <input type="hidden" name="extraNumber1" value="">
    <input type="hidden" name="extraCheckbox1" value="">
    <input type="hidden" name="extraCheckbox2" value="">
    <input type="hidden" name="minLikes" value="">
    <input type="hidden" name="dateFromIso" value="">
    <input type="hidden" name="includeTranscript" value="1">
    <input type="hidden" name="candidatePoolSize" value="">
    <input type="hidden" name="desiredFinalResults" value="">
    <?php /* v0.9.25.0 — Phase 2: user-facing result count removed.
        The system scans enough candidates and shows the most relevant usable
        results. Admins can override DEFAULT_PROVIDER_REQUEST_COUNT in settings. */ ?>
    <input type="hidden" name="limit" value="<?php echo esc_attr(defined('VES_DEFAULT_PROVIDER_REQUEST_COUNT') ? (int) VES_DEFAULT_PROVIDER_REQUEST_COUNT : 50); ?>" data-internal-default-limit>

    <div class="ves-row ves-primary-controls-row">
        <div class="ves-field ves-mode-wrap">
            <label class="ves-label">Tipo de objetivo</label>
            <select class="ves-select" name="mode">
                <option value="search" selected>Por búsqueda</option>
                <option value="urls">Por URLs</option>
            </select>
        </div>
        <div class="ves-field ves-ranking-mode-wrap">
            <label class="ves-label">Modo de ordenación</label>
            <select class="ves-select" name="rankingMode">
                <option value="best_match" selected>Mejor ajuste a marca/tema</option>
                <option value="highest_engagement">Mayor engagement</option>
                <option value="newest">Más reciente</option>
                <option value="controversial">Controversia</option>
                <option value="competitor_examples">Ejemplos de competidores</option>
            </select>
        </div>
    </div>

    <?php if (current_user_can('manage_options') && isset($_GET['ves_debug']) && sanitize_text_field(wp_unslash($_GET['ves_debug'])) === '1') : ?>
        <div class="ves-run-preflight" data-run-preflight aria-live="polite">
            <div class="ves-run-preflight-head">
                <span class="ves-run-preflight-kicker">Estimación previa</span>
                <strong class="ves-run-preflight-title">Preparando fuente</strong>
            </div>
            <div class="ves-run-preflight-grid" data-run-preflight-grid></div>
            <div class="ves-run-preflight-note" data-run-preflight-note></div>
            <div class="ves-run-preflight-actions">
                <button type="button" class="ves-mini-btn ves-payload-preview-btn">Vista previa del payload</button>
                <span class="ves-hint">Prueba solo para admin. Sin llamada externa y sin consumo de créditos.</span>
            </div>
            <div class="ves-payload-preview-host" data-payload-preview-host hidden></div>
        </div>
    <?php endif; ?>
    <?php /* v0.9.24.64: pre-run credit estimate hint for non-admin users */ ?>
    <?php if (class_exists('VES_Config') && class_exists('VES_Usage_Billing') && is_user_logged_in()): ?>
        <div class="ves-field ves-run-cost-hint" style="padding:6px 0;margin-bottom:2px">
            <span class="ves-hint" style="font-size:12px">
                <?php
                $run_cost = VES_Config::cost_manual_run();
                $balance  = VES_Usage_Billing::get_balance(get_current_user_id());
                $is_unlimited = VES_Usage_Billing::is_unlimited_user(get_current_user_id());
                if ($is_unlimited) {
                    echo '✅ Admin — créditos ilimitados.';
                } elseif ($balance !== null && $balance < $run_cost) {
                    echo '⚠️ <strong>Saldo insuficiente.</strong> Este run cuesta <strong>' . esc_html($run_cost) . ' créditos</strong>. Saldo actual: <strong>' . esc_html(number_format((float)$balance, 2)) . '</strong>.';
                } else {
                    echo 'Coste estimado: <strong>' . esc_html($run_cost) . ' crédito' . ($run_cost == 1 ? '' : 's') . '</strong> por run. El análisis AI por ítem se cobra aparte.';
                }
                ?>
            </span>
        </div>
    <?php endif; ?>

    <input type="hidden" name="targets" value="">
    <div class="ves-row" style="margin-top:14px">
        <div class="ves-field">
            <label class="ves-label">Términos de búsqueda</label>
            <div class="ves-input-assist-wrap">
                <textarea class="ves-textarea ves-ai-assisted" name="queryTerms" data-field-type="scraper_query" maxlength="500" spellcheck="false" placeholder="marketing AI&#10;tendencias de contenido&#10;estrategia de marca"></textarea>
                <button type="button" class="ves-ai-refine-button" data-target-field="queryTerms">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="queryTerms"></div>
            <div class="ves-hint ves-target-hint">Usa términos cortos. Los objetivos largos se separan y no se envían como consulta de búsqueda.</div>
        </div>
        <div class="ves-field">
            <label class="ves-label">Hashtags</label>
            <div class="ves-input-assist-wrap">
                <textarea class="ves-textarea ves-ai-assisted" name="hashtags" data-field-type="hashtag" maxlength="500" spellcheck="false" placeholder="#marketing&#10;#socialmedia&#10;#brandstrategy"></textarea>
                <button type="button" class="ves-ai-refine-button" data-target-field="hashtags">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="hashtags"></div>
        </div>
    </div>

    <details class="ves-advanced-panel" style="margin-top:14px">
        <summary>Opciones avanzadas de segmentación</summary>
        <div class="ves-row" style="margin-top:14px">
            <div class="ves-field">
                <label class="ves-label">URLs de perfiles / handles</label>
                <textarea class="ves-textarea ves-textarea-small" name="profileUrls" data-field-type="profile_url" maxlength="1000" placeholder="https://www.instagram.com/brandname/&#10;@brandname"></textarea>
            </div>
            <div class="ves-field">
                <label class="ves-label">URLs de posts / vídeos</label>
                <textarea class="ves-textarea ves-textarea-small" name="postUrls" data-field-type="post_url" maxlength="1200" placeholder="https://www.instagram.com/reel/...&#10;https://www.tiktok.com/@user/video/..."></textarea>
            </div>
        </div>
    </details>

    <div class="ves-row" style="margin-top:14px">
        <div class="ves-field">
            <label class="ves-label">Objetivo de negocio</label>
            <div class="ves-input-assist-wrap">
                <textarea class="ves-textarea ves-ai-assisted" name="businessObjective" data-field-type="business_objective" maxlength="700" placeholder="Encontrar posts en español con alta señal sobre cultura cervecera que puedan inspirar hooks de campaña."></textarea>
                <button type="button" class="ves-ai-refine-button" data-target-field="businessObjective">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="businessObjective"></div>
        </div>
        <div class="ves-field ves-search-context-wrap">
            <label class="ves-label">Contexto de búsqueda</label>
            <div class="ves-input-assist-wrap">
                <textarea class="ves-textarea ves-ai-assisted" name="searchContext" data-field-type="search_context" maxlength="600" placeholder="Marca, mercado, competidores, campaña, audiencia o contexto cultural."></textarea>
                <button type="button" class="ves-ai-refine-button" data-target-field="searchContext">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="searchContext"></div>
        </div>
    </div>

    <div class="ves-row" style="margin-top:14px">
        <div class="ves-field">
            <label class="ves-label">Objetivo del análisis AI</label>
            <div class="ves-input-assist-wrap">
                <textarea class="ves-textarea ves-ai-assisted" name="aiAnalysisGoal" data-field-type="ai_analysis_goal" maxlength="600" placeholder="Explicar patrones de contenido, oportunidades de hooks, fuerza de evidencia y próximos pasos de validación."></textarea>
                <button type="button" class="ves-ai-refine-button" data-target-field="aiAnalysisGoal">Mejorar</button>
            </div>
            <div class="ves-input-suggestions" data-suggestions-for="aiAnalysisGoal"></div>
            <div class="ves-hint">Se usa solo después de recopilar datos. Nunca se envía como consulta de búsqueda.</div>
        </div>
    </div>

    <div class="ves-row ves-date-from-row" style="margin-top:14px">
        <div class="ves-field ves-date-preset-wrap">
            <label class="ves-label ves-date-label">Fecha</label>
            <select class="ves-select" name="datePreset">
                <option value="any" selected>Cualquier fecha</option>
                <option value="1d">Últimas 24 horas</option>
                <option value="7d">Últimos 7 días</option>
                <option value="30d">Últimos 30 días</option>
                <option value="90d">Últimos 3 meses</option>
                <option value="180d">Últimos 6 meses</option>
                <option value="365d">Último año</option>
            </select>
        </div>
        <div class="ves-field ves-country-wrap">
            <label class="ves-label ves-country-label">País</label>
            <select class="ves-select" name="country">
                <option value="None" selected>Cualquier país</option>
                <option value="ES">España</option>
                <option value="US">Estados Unidos</option>
                <option value="GB">Reino Unido</option>
                <option value="MX">México</option>
                <option value="FR">Francia</option>
                <option value="DE">Alemania</option>
                <option value="IT">Italia</option>
                <option value="PT">Portugal</option>
            </select>
        </div>
        <div class="ves-field ves-language-wrap">
            <label class="ves-label ves-language-label">Idioma</label>
            <select class="ves-select" name="language">
                <option value="">Cualquier idioma</option>
                <option value="es" selected>Español</option>
                <option value="en">Inglés</option>
                <option value="fr">Francés</option>
                <option value="de">Alemán</option>
                <option value="it">Italiano</option>
                <option value="pt">Portugués</option>
                <option value="nl">Neerlandés</option>
                <option value="tr">Turco</option>
                <option value="ar">Árabe</option>
                <option value="ja">Japonés</option>
                <option value="ko">Coreano</option>
                <option value="zh">Chino</option>
            </select>
        </div>
    </div>

    <div class="ves-row ves-metric-row" style="margin-top:14px">
        <div class="ves-field ves-minviews-wrap">
            <label class="ves-label ves-minviews-label">Visualizaciones mínimas</label>
            <input class="ves-input" type="number" min="0" max="999999999" step="1" name="minViews" placeholder="0">
        </div>
    </div>


    <div class="ves-reddit-options" style="display:none">
        <details class="ves-advanced-block" open>
            <summary>Opciones Reddit</summary>
            <div class="ves-grid-2 ves-advanced-grid">
                <div class="ves-field">
                    <label class="ves-label">Community optional</label>
                    <input class="ves-input" type="text" name="redditCommunity" maxlength="80" placeholder="pasta, marketing, SaaS">
                    <div class="ves-hint">Para búsqueda dentro de una comunidad. Acepta r/pasta o pasta.</div>
                </div>
                <div class="ves-field">
                    <label class="ves-label">Sort search</label>
                    <select class="ves-select" name="redditSort">
                        <option value="new" selected>New</option>
                        <option value="relevance">Relevance</option>
                        <option value="hot">Hot</option>
                        <option value="top">Top</option>
                        <option value="rising">Rising</option>
                        <option value="comments">Comments</option>
                    </select>
                </div>
                <div class="ves-field">
                    <label class="ves-label">Filter by date</label>
                    <select class="ves-select" name="redditTime">
                        <option value="all" selected>All time</option>
                        <option value="hour">Last hour</option>
                        <option value="day">Last day</option>
                        <option value="week">Last week</option>
                        <option value="month">Last month</option>
                        <option value="year">Último año</option>
                    </select>
                </div>
                <?php if (current_user_can('manage_options')) : ?>
                <div class="ves-field">
                    <label class="ves-label">Max comments per post</label>
                    <input class="ves-input" type="number" min="0" max="200" step="1" name="redditMaxComments" value="10">
                    <div class="ves-hint">Control técnico solo para admin. Los usuarios normales usan el selector de comentarios.</div>
                </div>
                <div class="ves-field">
                    <label class="ves-label">URL result type</label>
                    <select class="ves-select" name="redditUrlResultMode">
                        <option value="posts" selected>Posts from subreddit / URL</option>
                        <option value="community">Community/user metadata only</option>
                        <option value="both">Both metadata and posts</option>
                    </select>
                    <div class="ves-hint">Live test: subreddit URLs can return only community metadata unless this is set to posts.</div>
                </div>
                <div class="ves-field">
                    <label class="ves-label">Proxy</label>
                    <select class="ves-select" name="redditProxyGroup">
                        <option value="RESIDENTIAL" selected>Residential</option>
                        <option value="DATACENTER">Datacenter</option>
                    </select>
                    <div class="ves-hint">Ajuste técnico solo admin. Residential suele ser más estable; Datacenter es más barato.</div>
                </div>
                <?php endif; ?>
            </div>
            <div class="ves-reddit-checks">
                <label class="ves-checkline"><input type="checkbox" name="redditSearchPosts" value="1" checked> Buscar posts</label>
                <label class="ves-checkline"><input type="checkbox" name="redditSearchComments" value="1"> Incluir comentarios</label>
                <label class="ves-checkline"><input type="checkbox" name="redditSearchCommunities" value="1"> Buscar comunidades</label>
                <?php if (current_user_can('manage_options')) : ?>
                <label class="ves-checkline"><input type="checkbox" name="redditSearchUsers" value="1"> Buscar usuarios</label>
                <label class="ves-checkline"><input type="checkbox" name="redditSkipComments" value="1"> Omitir comentarios</label>
                <label class="ves-checkline"><input type="checkbox" name="redditSkipUserPosts" value="1"> Omitir posts de usuario</label>
                <label class="ves-checkline"><input type="checkbox" name="redditSkipCommunity" value="1"> Omitir comunidad</label>
                <label class="ves-checkline"><input type="checkbox" name="redditIncludeNSFW" value="1"> Incluir NSFW</label>
                <?php endif; ?>
            </div>
        </details>
    </div>

    <div class="ves-pinterest-options" style="display:none">
        <details class="ves-advanced-block" open>
            <summary>Opciones Pinterest</summary>
            <p class="ves-hint">Recopila pins, boards, perfiles, búsquedas y comentarios públicos de Pinterest. Nota: esta fuente puede requerir acceso activo configurado; si no está disponible, el run se bloqueará antes de consumir créditos.</p>
            <p class="ves-hint" style="color:#b45309"><strong>⚠ Solo se usa la primera línea como búsqueda.</strong> Pinterest acepta un único término principal por ejecución. Si introduces varias líneas, solo se enviará la primera.</p>
            <div class="ves-grid-2 ves-advanced-grid">
                <div class="ves-field">
                    <label class="ves-label">Página final de listado</label>
                    <input class="ves-input" type="number" min="0" max="100" step="1" name="pinterestEndPage" value="1">
                    <div class="ves-hint">0 = sin límite. Usa 1–3 para controlar coste y tiempo.</div>
                </div>
                <div class="ves-field">
                    <label class="ves-label">Proxy</label>
                    <select class="ves-select" name="pinterestProxyGroup">
                        <option value="RESIDENTIAL" selected>Residential</option>
                        <option value="DATACENTER">Datacenter</option>
                    </select>
                    <div class="ves-hint">Residential es más estable para Pinterest; Datacenter es más barato pero puede bloquearse.</div>
                </div>
            </div>
            <div class="ves-reddit-checks">
                <label class="ves-checkline"><input type="checkbox" name="pinterestIncludeComments" value="1"> Incluir comentarios</label>
                <label class="ves-checkline"><input type="checkbox" name="pinterestIncludeUserInfoOnly" value="1"> Incluir solo información de usuario</label>
            </div>
        </details>
    </div>

    <div class="ves-semrush-options" style="display:none">
        <details class="ves-advanced-block ves-semrush-toolbox" open>
            <summary>Opciones Semrush</summary>
            <p class="ves-hint">El selector principal "Tipo de análisis" elige automáticamente la fuente correcta. Así evitamos enviar campos incompatibles.</p>

            <?php if (current_user_can('manage_options')) : ?>
            <div class="ves-semrush-mode-note" data-semrush-panel="keyword_simple">
                <strong>Actor:</strong> <code>marceli/semrush-keyworlds-scraper</code>
                <div class="ves-hint">Usa este modo para una lista rápida de keywords. Payload: <code>{ "d1": "keyword1, keyword2" }</code>.</div>
            </div>
            <div class="ves-semrush-mode-note" data-semrush-panel="keyword_magic" style="display:none">
                <strong>Actor:</strong> <code>burbn/semrush-keyword-magic-tool</code>
                <div class="ves-hint">Keyword Magic necesita una keyword principal, país e idioma. El sistema decide automáticamente cuántos candidatos recoger.</div>
            </div>
            <div class="ves-semrush-mode-note" data-semrush-panel="domain_seo" style="display:none">
                <strong>Actor:</strong> <code>radeance/semrush-scraper</code>
                <div class="ves-hint">Para dominios/URLs y módulos SEO: autoridad, tráfico, backlinks, AI visibility, SERP, keyword volume, ranking y SEO audit.</div>
            </div>
            <div class="ves-semrush-mode-note" data-semrush-panel="top_websites" style="display:none">
                <strong>Actor:</strong> <code>radeance/semrush-scraper</code>
                <div class="ves-hint">Top Websites no requiere URLs. Usa industria y país del ranking.</div>
            </div>

            <?php endif; ?>

            <div class="ves-grid-2 ves-advanced-grid" data-semrush-panel="keyword_magic">
                <div class="ves-field">
                    <label class="ves-label">País</label>
                    <select class="ves-select" name="semrushCountry">
                        <option value="us">United States</option>
                        <option value="es" selected>Spain</option>
                        <option value="gb">United Kingdom</option>
                        <option value="de">Alemány</option>
                        <option value="fr">France</option>
                        <option value="it">Italy</option>
                        <option value="pt">Portugal</option>
                        <option value="mx">Mexico</option>
                        <option value="ca">Canada</option>
                        <option value="au">Australia</option>
                        <option value="br">Brazil</option>
                        <option value="in">India</option>
                    </select>
                </div>
                <div class="ves-field">
                    <label class="ves-label">Idioma</label>
                    <select class="ves-select" name="semrushLanguage">
                        <option value="en">Inglés</option>
                        <option value="es" selected>Español</option>
                        <option value="de">Alemán</option>
                        <option value="fr">Francés</option>
                        <option value="it">Italian</option>
                        <option value="pt">Portuguese</option>
                        <option value="ja">Japanese</option>
                    </select>
                </div>
                <?php
                // v0.9.27.0 — Phase 1: Semrush Máximo resultados moved behind admin debug.
                // Normal users see a fixed system default via hidden input; admins in
                // diagnostics mode (?ves_debug=1) can still tune it.
                $ves_show_semrush_max = current_user_can('manage_options')
                    && isset($_GET['ves_debug'])
                    && sanitize_text_field(wp_unslash($_GET['ves_debug'])) === '1';
                ?>
                <?php if ($ves_show_semrush_max) : ?>
                <div class="ves-field">
                    <label class="ves-label">Máximo resultados <span style="font-size:10px;opacity:0.6;text-transform:uppercase">admin</span></label>
                    <select class="ves-select" name="semrushMaxResults">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="250">250</option>
                        <option value="500">500</option>
                        <option value="1000">1000</option>
                    </select>
                    <div class="ves-hint">Solo admin. La fuente permite 25–1000.</div>
                </div>
                <?php else : ?>
                <input type="hidden" name="semrushMaxResults" value="50">
                <?php endif; ?>
            </div>

            <div class="ves-grid-2 ves-advanced-grid" data-semrush-panel="domain_seo" style="display:none">
                <div class="ves-field">
                    <label class="ves-label">País para keyword/SERP</label>
                    <select class="ves-select" name="semrushSeoCountry">
                        <option value="us">United States</option>
                        <option value="es" selected>Spain</option>
                        <option value="gb">United Kingdom</option>
                        <option value="de">Alemány</option>
                        <option value="fr">France</option>
                        <option value="it">Italy</option>
                        <option value="pt">Portugal</option>
                        <option value="mx">Mexico</option>
                        <option value="ca">Canada</option>
                        <option value="au">Australia</option>
                        <option value="br">Brazil</option>
                        <option value="in">India</option>
                    </select>
                </div>
                <div class="ves-field">
                    <label class="ves-label">Keyword opcional</label>
                    <input class="ves-input" type="text" name="semrushSeoKeyword" maxlength="160" placeholder="agentic ai">
                    <div class="ves-hint">Necesaria para Keyword Volume/CPC y SERP. Para ranking puedes poner keyword aquí y dominio en Objetivos.</div>
                </div>
            </div>

            <div class="ves-reddit-checks" data-semrush-panel="domain_seo" style="display:none">
                <label class="ves-checkline"><input type="checkbox" name="semrushIncludeAuthority" value="1" checked> Domain Authority</label>
                <label class="ves-checkline"><input type="checkbox" name="semrushIncludeOverview" value="1"> Website stats overview</label>
                <label class="ves-checkline"><input type="checkbox" name="semrushIncludeTraffic" value="1"> Web traffic</label>
                <label class="ves-checkline"><input type="checkbox" name="semrushIncludeBacklinks" value="1"> Backlinks</label>
                <label class="ves-checkline"><input type="checkbox" name="semrushIncludeAiVisibility" value="1"> AI visibility</label>
                <label class="ves-checkline"><input type="checkbox" name="semrushIncludeCompetitors" value="1"> Competitors</label>
                <label class="ves-checkline"><input type="checkbox" name="semrushIncludeKeywordVolume" value="1"> Keyword Volume / CPC</label>
                <label class="ves-checkline"><input type="checkbox" name="semrushIncludeKeywordsRanking" value="1"> Keywords Ranking</label>
                <label class="ves-checkline"><input type="checkbox" name="semrushIncludeSeo" value="1"> SEO Audit</label>
                <label class="ves-checkline"><input type="checkbox" name="semrushIncludeSerp" value="1"> SERP</label>
            </div>

            <div class="ves-grid-2 ves-advanced-grid" data-semrush-panel="top_websites" style="display:none">
                <div class="ves-field">
                    <label class="ves-label">Industria</label>
                    <select class="ves-select" name="semrushTopIndustria">
                        <option value="all" selected>All industries</option>
                        <option value="advertising-and-marketing">Advertising & Marketing</option>
                        <option value="automotive">Automotive</option>
                        <option value="beauty-and-cosmetics">Beauty & Cosmetics</option>
                        <option value="computer-software-and-development">Computer Software & Development</option>
                        <option value="education">Education</option>
                        <option value="entertainment">Entertainment</option>
                        <option value="finance">Finance</option>
                        <option value="food-and-beverages">Food & Beverages</option>
                        <option value="healthcare">Healthcare</option>
                        <option value="information-technology">Information Technology</option>
                        <option value="market-research">Market Research</option>
                        <option value="online-services">Online Services</option>
                        <option value="retail">Retail</option>
                        <option value="social-and-charitable-organizations">Social & Charitable Organizations</option>
                        <option value="travel-and-tourism">Travel & Tourism</option>
                    </select>
                </div>
                <div class="ves-field">
                    <label class="ves-label">País ranking</label>
                    <select class="ves-select" name="semrushTopCountry">
                        <option value="global" selected>Worldwide</option>
                        <option value="es">Spain</option>
                        <option value="us">United States</option>
                        <option value="gb">United Kingdom</option>
                        <option value="de">Alemány</option>
                        <option value="fr">France</option>
                        <option value="it">Italy</option>
                        <option value="pt">Portugal</option>
                        <option value="mx">Mexico</option>
                        <option value="ca">Canada</option>
                        <option value="au">Australia</option>
                        <option value="br">Brazil</option>
                        <option value="in">India</option>
                    </select>
                </div>
            </div>
        </details>
    </div>

    <div class="ves-moz-options" style="display:none">
        <details class="ves-advanced-block" open>
            <summary>Opciones MOZ</summary>
            <div class="ves-reddit-checks">
                <label class="ves-checkline"><input type="checkbox" name="mozIncludeAuthority" value="1" checked> Include Domain / Page Authority</label>
                <label class="ves-checkline"><input type="checkbox" name="mozIncludeHistory" value="1" checked> Include Authority History</label>
            </div>
        </details>
    </div>

    <div class="ves-ahrefs-options" style="display:none">
        <details class="ves-advanced-block" open>
            <summary>Opciones Ahrefs</summary>
            <p class="ves-hint">Usa el selector "Tipo de objetivo" para enviar una sola búsqueda SEO por ejecución y mantener el coste predecible.</p>

            <div class="ves-grid-2 ves-advanced-grid">
                <div class="ves-field">
                    <label class="ves-label">URL mode</label>
                    <select class="ves-select" name="ahrefsDomainMode">
                        <option value="subdomains" selected>Subdomains</option>
                        <option value="exact">Exact URL/domain</option>
                        <option value="prefix">Prefix</option>
                        <option value="domain">Entire domain</option>
                    </select>
                </div>
                <div class="ves-field">
                    <label class="ves-label">Search engine</label>
                    <select class="ves-select" name="ahrefsSearchEngine">
                        <option value="Google" selected>Google</option>
                        <option value="Bing">Bing</option>
                        <option value="YouTube">YouTube</option>
                        <option value="Amazon">Amazon</option>
                        <option value="Yahoo">Yahoo</option>
                        <option value="Yandex">Yandex</option>
                        <option value="Baidu">Baidu</option>
                        <option value="ChatGPT">ChatGPT</option>
                        <option value="Gemini">Gemini</option>
                        <option value="Perplexity">Perplexity</option>
                        <option value="Copilot">Copilot</option>
                        <option value="Grok">Grok</option>
                        <option value="GoogleAIMode">Google AI Mode</option>
                        <option value="GoogleImages">Google Images</option>
                        <option value="GoogleNews">Google News</option>
                        <option value="GoogleVideos">Google Videos</option>
                        <option value="Daum">Daum</option>
                        <option value="Naver">Naver</option>
                        <option value="Seznam">Seznam</option>
                        <option value="Yep">Yep</option>
                    </select>
                    <p class="ves-hint">Solo aplica a Keyword Ideas. Para otros search types se ignora.</p>
                </div>
                <div class="ves-field">
                    <label class="ves-label">Top websites mode</label>
                    <select class="ves-select" name="ahrefsTopMode">
                        <option value="ranking" selected>Ranking</option>
                        <option value="trending">Trending</option>
                    </select>
                </div>
                <div class="ves-field">
                    <label class="ves-label">Top websites country</label>
                    <input class="ves-input" type="text" name="ahrefsTopCountry" value="worldwide" placeholder="worldwide, united-states, spain, germany">
                </div>
                <div class="ves-field">
                    <label class="ves-label">Top websites category</label>
                    <select class="ves-select" name="ahrefsTopCategory">
                        <option value="all" selected>All categories</option>
                        <option value="arts-and-entertainment">Arts & entertainment</option>
                        <option value="autos-and-vehicles">Autos & vehicles</option>
                        <option value="beauty-and-fitness">Beauty & fitness</option>
                        <option value="books-and-literature">Books & literature</option>
                        <option value="business">Business</option>
                        <option value="computers-and-electronics">Computers & electronics</option>
                        <option value="finance">Finance</option>
                        <option value="food-and-drink">Food & drink</option>
                        <option value="games">Games</option>
                        <option value="health">Health</option>
                        <option value="hobbies-and-leisure">Hobbies & leisure</option>
                        <option value="home-and-garden">Home & garden</option>
                        <option value="internet-and-telecom">Internet & telecom</option>
                        <option value="jobs-and-education">Jobs & education</option>
                        <option value="law-and-government">Law & government</option>
                        <option value="news">News</option>
                        <option value="people-and-society">People & society</option>
                        <option value="pets-and-animals">Pets & animals</option>
                        <option value="real-estate">Real estate</option>
                        <option value="reference">Reference</option>
                        <option value="science">Science</option>
                        <option value="shopping">Shopping</option>
                        <option value="social-networks">Social networks</option>
                        <option value="sports">Sports</option>
                        <option value="travel-and-transportation">Travel & transportation</option>
                    </select>
                </div>
                <?php
                $ves_show_ahrefs_advanced = current_user_can('manage_options')
                    && isset($_GET['ves_debug'])
                    && sanitize_text_field(wp_unslash($_GET['ves_debug'])) === '1';
                ?>
                <?php if ($ves_show_ahrefs_advanced) : ?>
                <div class="ves-field">
                    <label class="ves-label">Top websites results limit <span style="font-size:10px;opacity:0.6;text-transform:uppercase">admin</span></label>
                    <input class="ves-input" type="number" name="ahrefsTopLimit" value="100" min="1" max="1000" step="1">
                </div>
                <div class="ves-field">
                    <label class="ves-label">Max retries <span style="font-size:10px;opacity:0.6;text-transform:uppercase">admin</span></label>
                    <input class="ves-input" type="number" name="ahrefsMaxRetries" value="5" min="1" max="10" step="1">
                </div>
                <?php else : ?>
                <input type="hidden" name="ahrefsTopLimit" value="100">
                <input type="hidden" name="ahrefsMaxRetries" value="5">
                <?php endif; ?>
            </div>

            <div class="ves-reddit-checks">
                <label class="ves-checkline"><input type="checkbox" name="ahrefsIncludeDetails" value="1"> Include website details for Top Websites</label>
            </div>
        </details>
    </div>


    <div class="ves-bottom-cta">
        <button type="button" class="ves-btn ves-btn-primary ves-start ves-bottom-start">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
            Ejecutar social intelligence
        </button>
    </div>
</form>
