(function () {
    const VES_LOG = (...a) => { if (window.VES_DEBUG === true) try { console.log('[VES]', ...a); } catch (e) {} };
    const VES_WARN = (...a) => { try { console.warn('[VES]', ...a); } catch (e) {} };

    // v0.9.24.75 — filterable brand labels injected by PHP (VES_Branding helper).
    // Falls back to neutral SaaS defaults if the inline script did not run.
    const VES_BRAND = (typeof window !== 'undefined' && window.VES_BRAND && typeof window.VES_BRAND === 'object')
        ? window.VES_BRAND
        : {};
    const brandLabel = (key, fallback) => {
        const v = VES_BRAND && typeof VES_BRAND[key] === 'string' ? VES_BRAND[key].trim() : '';
        return v !== '' ? v : fallback;
    };
    const assistantName  = () => brandLabel('assistantName',  'AI Assistant');
    const workspaceName  = () => brandLabel('workspaceName',  'Intelligence Workspace');
    const productName    = () => brandLabel('productName',    'Intelligence Suite');

    const escapeHtml = (str) => String(str ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/\"/g,'&quot;').replace(/'/g,'&#039;');

    // v0.9.24.9: admin-only UI gate — blocks visible only to manage_options users.
    const isAdminUI = (widget) => {
        const w = widget || document.querySelector('.ves-wrap');
        return w && (w.dataset.adminUi === '1' || window.VES_ADMIN_UI === true);
    };

    const isDebugMode = (widget) => {
        const w = widget || document.querySelector('.ves-wrap');
        // Debug panels can expose provider payloads, query diagnostics and raw error traces.
        // Never enable them for non-admin users, even if a global VES_DEBUG flag leaks to the page.
        if (!isAdminUI(w)) return false;
        try {
            const params = new URLSearchParams(window.location.search || '');
            if (params.get('ves_debug') === '1') {
                if (w) w.dataset.debugMode = '1';
                return true;
            }
        } catch (e) {}
        return !!(window.VES_DEBUG === true || (w && (w.dataset.debugMode === '1' || w.dataset.vesDebug === '1')));
    };

    // v0.9.24.9: sanitize user-facing error messages — never show raw PHP errors,
    // HTTP status codes, or internal identifiers to non-admin users.
    const safeUserMessage = (raw, widget) => {
        if (!raw) return '';
        if (isDebugMode(widget)) return String(raw); // raw diagnostics only in explicit admin/debug mode
        const s = String(raw);
        const sourceAccessMessage = 'This source could not be accessed. Ask an admin to review Diagnostics or try another configured source.';
        const creditMessage = 'This run requires more credits or a higher resource allowance. Reduce sources/results or increase your limit.';
        const vendorWord = ['api', 'fy'].join('');
        const roleWord = ['act', 'or'].join('');
        const providerWord = ['prov', 'ider'].join('');
        const internalAccessPattern = new RegExp([
            providerWord + ' access failed',
            'source access failed',
            'http logi?c(?:o|al)?\\b',
            'logical http',
            'request_id',
            'source_execution_key',
            providerWord + '_run_id',
            'dataset_id',
            roleWord + '_slug',
            roleWord + '\\b',
            vendorWord,
            providerWord
        ].join('|'), 'i');
        const internalAfterStripPattern = new RegExp([
            providerWord,
            vendorWord,
            roleWord + '\\b',
            'dataset',
            'source_execution_key',
            'request_id',
            providerWord + '_run_id',
            'raw ' + providerWord,
            'http logi?c(?:o|al)?'
        ].join('|'), 'i');

        // v0.9.30.6: product-safe mapping for internal access failures.
        // Normal users must not see internal access mechanics or raw IDs.
        if (/requires full access|approve its permissions|approvalUrl|linkedin source|permissions/i.test(s) && internalAccessPattern.test(s)) {
            return 'This LinkedIn source is not approved for this account. Ask an admin to review Diagnostics or choose another configured source.';
        }
        if (internalAccessPattern.test(s)) {
            return sourceAccessMessage;
        }
        if (/http logical?\s*402|credit\/provider limit|provider-cost|exceeds your current credit|payment required|insufficient credits/i.test(s)) {
            return creditMessage;
        }
        if (/payload was valid but no rows|no rows|returned no usable rows/i.test(s)) {
            return 'The source returned no usable rows for this market/language. Try a broader keyword or another source.';
        }
        if (/403|401|500|token|unauthorized|forbidden/i.test(s) && /json|http|token|access/i.test(s)) {
            return sourceAccessMessage;
        }
        let stripped = s
            .replace(/\[Ref:\s*[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\]/gi, '')
            .replace(/\[Ref:\s*[\w-]+\]/gi, '')
            .replace(/\(HTTP\s*(?:logico|logical)?\s*\d+\)/gi, '')
            .replace(/HTTP\s*(?:logico|logical)?\s*\d{3}/gi, '')
            .replace(/HTTP\s*\d{3}/gi, '')
            .replace(/request_id[:\s]+[\w-]+/gi, '')
            .replace(/source_execution_key[:\s]+[\w-]+/gi, '')
            .replace(/provider_run_id[:\s]+[\w-]+/gi, '')
            .replace(/dataset_id[:\s]+[\w-]+/gi, '')
            .replace(/actor_slug[:\s]+[^\s,;]+/gi, '')
            .replace(/run_id[:\s]+[\w-]+/gi, '')
            .replace(/bundle_id[:\s]+[\w-]+/gi, '')
            .replace(/\{[\s\S]{0,1200}\}/g, '')
            .trim();
        if (internalAfterStripPattern.test(stripped)) {
            return sourceAccessMessage;
        }
        const isSessionError = /sesión caducada|session expired|nonce|403 forbidden/i.test(stripped);
        if (isSessionError) {
            return 'La sesión ha expirado. Recarga la página e inténtalo de nuevo.';
        }
        const jsTypeError = /cannot read|properties of|is not a function|is not defined/i.test(stripped);
        if (jsTypeError) {
            return 'No pudimos mostrar el resultado en pantalla. Reintenta; si persiste, revisa Diagnóstico.';
        }
        const technical = /php error|fatal error|wp_error|exception|stack trace|undefined|null|NaN|server devolvio html|approvalurl|trace|payload/i.test(stripped);
        if (technical || stripped.length < 2) {
            return 'Something went wrong. Please try again in a few minutes or contact support.';
        }
        return stripped.length > 220 ? stripped.slice(0, 220) + '…' : stripped;
    };

    const formatConfidence = (value) => {
        if (value === null || value === undefined || value === '') return '—';
        const n = Number(value);
        if (Number.isFinite(n)) return n <= 1 ? `${Math.round(n * 100)}%` : `${Math.round(n)}%`;
        return escapeHtml(String(value));
    };

    const cssEscape = (v) => (window.CSS && typeof window.CSS.escape === 'function') ? window.CSS.escape(String(v || '')) : String(v || '').replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);

    const safeUrl = (v) => {
        try {
            const u = new URL(String(v || ''), window.location.origin);
            if (u.protocol === 'http:' || u.protocol === 'https:') return u.href;
        } catch (e) {}
        return '';
    };

    const cssUrlValue = (v) => {
        const u = safeUrl(v);
        if (!u) return '';
        return 'url("' + u.replace(/[\\"]/g, '\\$&') + '")';
    };

    const nfmt = (n) => {
        if (n === null || n === undefined || n === '') return '—';
        const num = Number(String(n).replace(/,/g, ''));
        if (Number.isNaN(num)) return String(n);
        if (!Number.isFinite(num)) return '—';
        if (num >= 1000000) return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
        return String(num);
    };

    const metricCell = (value, available) => available ? nfmt(value) : '—';

    const safeArray = (value) => Array.isArray(value) ? value : [];
    const safeObject = (value) => (value && typeof value === 'object' && !Array.isArray(value)) ? value : {};
    const safeString = (value, fallback = '') => (value === null || value === undefined) ? fallback : String(value);
    function fieldEnabled(el) {
        if (!el) return false;
        const type = String(el.type || '').toLowerCase();
        if (type === 'checkbox' || type === 'radio') return !!el.checked;
        const value = String(el.value || '').trim().toLowerCase();
        return ['1', 'true', 'yes', 'on', 'enabled'].includes(value);
    }
    const fieldIsUserToggle = (el) => {
        const type = String(el?.type || '').toLowerCase();
        return type === 'checkbox' || type === 'radio';
    };
    const safeTrendLabel = (value) => {
        const v = String(value ?? '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        if (!v || v.length > 140) return '';
        if (/^https?:\/\//i.test(v) || /ads\.tiktok\.com\/business\/creativecenter/i.test(v)) return '';
        if (/^\$?\d+(?:[\.,]\d+)?\s*[kmb]?$/i.test(v)) return '';
        if (/^\d{4}-\d{2}-\d{2}/.test(v) || /^\d{1,2}:\d{2}(?::\d{2})?$/.test(v)) return '';
        if (/^\d+\s*(s|sec|secs|seg|seconds?|m|min|mins|h|hr|hrs)$/i.test(v)) return '';
        if (!/[\p{L}#@]/u.test(v)) return '';
        if (/^(views?|likes?|comments?|shares?|orders?|revenue|visits?|duration|rank|score|count|total|followers?|plays?|vv)\s*[:\-]/i.test(v)) return '';
        return v;
    };
    const safeTrendList = (items, limit = 20) => {
        const seen = new Set();
        const out = [];
        safeArray(items).forEach((item) => {
            const label = safeTrendLabel(typeof item === 'object' && item ? (item.trend_name || item.name || item.title || item.label || '') : item);
            const key = label.toLowerCase();
            if (label && !seen.has(key)) { seen.add(key); out.push(label); }
        });
        return out.slice(0, limit);
    };


    const valueAtPath = (obj, path) => {
        if (!obj || !path) return undefined;
        return String(path).split('.').reduce((acc, key) => {
            if (acc === undefined || acc === null) return undefined;
            if (Array.isArray(acc) && /^\d+$/.test(key)) return acc[Number(key)];
            if (Object.prototype.hasOwnProperty.call(Object(acc), key)) return acc[key];
            return undefined;
        }, obj);
    };

    const cleanTemplateText = (value) => {
        if (value === null || value === undefined || value === false) return '';
        let text = String(value).replace(/<[^>]*>/g, ' ');
        text = text.replace(/\{\{\s*[^}]+\s*\}\}/g, ' ');
        text = text.replace(/\s+/g, ' ').trim();
        if (!text) return '';
        const lower = text.toLowerCase();
        if (['null', 'undefined', 'none', 'n/a', 'na', '-', '—'].includes(lower)) return '';
        if (!/[\p{L}\p{N}]/u.test(text)) return '';
        return text;
    };

    const textFromMixed = (value) => {
        if (value === null || value === undefined || value === false) return '';
        if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return cleanTemplateText(value);
        if (Array.isArray(value)) return value.map(textFromMixed).filter(Boolean).slice(0, 4).join(' · ');
        return '';
    };

    const firstPathText = (obj, paths = []) => {
        for (const path of paths) {
            const txt = textFromMixed(valueAtPath(obj, path));
            if (txt) return txt;
        }
        return '';
    };

    const findImageUrl = (value, depth = 0, fromHint = false) => {
        if (depth > 6 || value === null || value === undefined) return '';
        if (typeof value === 'string') {
            const v = value.trim();
            if (/^https?:\/\//i.test(v) && (/(\.jpg|\.jpeg|\.png|\.webp|\.gif)(\?|$)|\/safe_image\.php|scontent\.|fbcdn\.net|twimg\.com|ytimg\.com|ggpht\.com|googleusercontent\.com|cdninstagram\.com|tiktokcdn\.com|muscdn\.com/i.test(v) || (fromHint && !/\.(mp4|mov|m3u8)(\?|$)/i.test(v)))) return v;
            return '';
        }
        if (Array.isArray(value)) {
            for (const child of value) {
                const found = findImageUrl(child, depth + 1);
                if (found) return found;
            }
            return '';
        }
        if (typeof value === 'object') {
            const preferred = Object.keys(value).filter(k => /image|img|thumbnail|thumb|picture|photo|poster|cover|avatar|creative|media|original_image_url|resized_image_url|coverurl|cover_url|thumbnailurl|thumbnail_url|dynamiccover|thumbnaildynamic|origincover/i.test(k));
            for (const key of preferred) {
                const found = findImageUrl(value[key], depth + 1, true);
                if (found) return found;
            }
            for (const key of Object.keys(value)) {
                const found = findImageUrl(value[key], depth + 1);
                if (found) return found;
            }
        }
        return '';
    };

    const fbAdsImpressions = (item) => {
        const candidates = [
            item?.impressions, item?.impressionsCount, item?.impression_count,
            item?.impressions_lower_bound, item?.impressions_with_index?.impressions_text,
            item?.impressions_with_index?.lower_bound, item?.impressions_with_index?.lowerBound,
            item?.impressions_with_index?.min, item?.impressions?.lowerBound, item?.impressions?.upperBound, item?.regionStats?.[0]?.impressions?.lowerBound, item?.regionStats?.[0]?.impressions?.upperBound
        ];
        for (const c of candidates) {
            const n = parseNum(c);
            if (n > 0) return n;
        }
        if (item?.impressions_with_index && typeof item.impressions_with_index === 'object') {
            for (const v of Object.values(item.impressions_with_index)) {
                const n = parseNum(v);
                if (n > 0) return n;
            }
        }
        return 0;
    };

    const hasFbAdsImpressionField = (item) => {
        if (!item || typeof item !== 'object') return false;
        return firstPathText(item, [
            'impressions', 'impressionsCount', 'impression_count', 'impressions_lower_bound',
            'impressions_with_index.impressions_text', 'impressions_with_index.lower_bound',
            'impressions_with_index.lowerBound', 'impressions_with_index.min', 'impressions.lowerBound', 'impressions.upperBound', 'regionStats.0.impressions.lowerBound', 'regionStats.0.impressions.upperBound'
        ]) !== '';
    };

    const isSourceErrorItem = (item) => {
        if (!item || typeof item !== 'object') return false;
        const err = firstPathText(item, ['errorDescription', 'error_description', 'error', 'requestErrorMessages.0', 'requestErrorMessages']);
        if (!err) return false;
        const meaningful = firstPathText(item, [
            'title', 'name', 'text', 'fullText', 'caption', 'description', 'body',
            'ad_creative_bodies.0', 'ad_creative_bodies', 'adCreativeBody',
            'pageName', 'page_name', 'advertiserName', 'advertiser_name', 'ad_archive_id',
            'thumbnail', 'thumbnail_url', 'image', 'displayUrl', 'videoUrl',
            'snapshot.body.text', 'snapshot.cards.0.body', 'snapshot.cards.0.text',
            'snapshot.cards.0.title', 'snapshot.images.0.original_image_url'
        ]);
        const metric = parseNum(firstNonEmpty(item.viewCount, item.playCount, item.likesCount, item.likeCount, item.commentCount, item.commentsCount, item.impressions, item.impressionsCount));
        return !meaningful && metric <= 0;
    };

    const isNoResultMarkerItem = (item) => {
        if (!item || typeof item !== 'object') return false;
        if (item.discard_reason === 'no_results_marker' || item.ves_discard_reason === 'no_results_marker') return true;
        const markerKeys = ['noResults', 'no_results', 'noResult', 'no_results_marker', 'isNoResults'];
        for (const key of markerKeys) {
            if (Object.prototype.hasOwnProperty.call(item, key)) {
                const v = item[key];
                if (v === true || v === 1 || v === '1' || v === '' || v === null || String(v).toLowerCase() === 'true') return true;
            }
        }
        const label = firstPathText(item, ['title','label','name','message','status','reason']);
        if (!label) return false;
        const norm = String(label).trim().toLowerCase().replace(/[\s_\-]+/g, '');
        if (!['noresults','noresult','noresultsfound'].includes(norm) && !String(label).toLowerCase().includes('no results')) return false;
        const content = firstPathText(item, ['webVideoUrl','videoUrl','shareUrl','postPage','url','text','fullText','caption','description','body','authorName','username']);
        const metric = parseNum(firstNonEmpty(item.viewCount, item.views, item.playCount, item.diggCount, item.likeCount, item.likes, item.commentCount, item.comments, item.shareCount, item.shares));
        return !content && metric <= 0;
    };

    const itemHasDisplayPayload = (platform, item) => {
        if (!item || typeof item !== 'object') return false;
        if (isSourceErrorItem(item) || isNoResultMarkerItem(item)) return false;
        if (platform === 'instagram') {
            const url = String(item.url || item.ves_url || '');
            const looksDirectory = item.searchSource !== undefined || item.postsPerDay !== undefined || item.topPosts !== undefined || item.latestPosts !== undefined || item.postsCount !== undefined || item.edge_owner_to_timeline_media !== undefined;
            const isPostUrl = /instagram\.com\/(?:p|reel|tv)\//i.test(url);
            const hasPostIdentity = !!(item.shortCode || item.shortcode || item.code || isPostUrl || (item.ownerUsername && (item.caption || item.displayUrl || item.ves_text)));
            if (looksDirectory && !hasPostIdentity) return false;
        }
        const content = firstPathText(item, [
            'ves_title','ves_text','title','name','text','fullText','caption','description','body','message','postText','content',
            'shortCode','id','videoId','webVideoUrl','videoUrl','url','postUrl','permalink','facebookUrl',
            'ownerUsername','username','authorName','channelName','pageName','page_name','advertiserName','ad_archive_id','adArchivarId',
            'ad_creative_bodies.0','ad_creative_bodies','adCreativeBody','ad_creative_link_titles.0',
            'fullName','headline','profileUrl','linkedinUrl','linkedInUrl','publicIdentifier','currentPosition','currentCompany','currentCompanyName','location',
            'communityName','parsedCommunityName','username','upVotes','numberOfComments','numberOfMembers','postKarma','commentKarma','dataType',
            'richSummary','seoTitle','seoDescription','altText','autoAltText','imageUrl','imageUrls.original','imageUrls.736x','pinUrl','saves','saveCount','repinCount','pinner.username','pinner.name','user.username','user.name',
            'snapshot.body.text','snapshot.body.markup.__html','snapshot.cards.0.body','snapshot.cards.0.text','snapshot.cards.0.title','snapshot.title',
            'displayUrl','thumbnailUrl','thumbnail_url','coverUrl','cover_url','thumbnailDynamic','dynamicCover','originCover','image','videoMeta.coverUrl','video.cover','video.thumbnail','channel.avatar','snapshot.images.0.original_image_url'
        ]);
        if (content) return true;
        const metric = parseNum(firstNonEmpty(item.ves_primary_metric_value, item.viewCount, item.views, item.playCount, item.videoViewCount, item.saves, item.saveCount, item.repinCount, item.likesCount, item.likeCount, item.commentCount, item.commentsCount, item.impressions, item.impressionsCount, fbAdsImpressions(item)));
        return metric > 0 || !!findImageUrl(item);
    };

    const displayFallbackTitle = (platform, author = '', id = '') => {
        const a = cleanTemplateText(author);
        const ident = cleanTemplateText(id);
        const labels = { tiktok:'TikTok video', youtube:'YouTube video', facebook:'Facebook post', instagram:'Instagram result', facebook_ads: ident ? `Ad #${ident}` : 'Facebook Ad', google_ads: ident ? `Google Ad #${ident}` : 'Google Ad', twitter:'X post', linkedin:'LinkedIn profile', reddit:'Reddit result', pinterest:'Pinterest pin', semrush:'Semrush result', moz:'MOZ domain', ahrefs:'Ahrefs result' };
        const label = labels[platform] || 'Result';
        return a ? `${a} — ${label}` : label;
    };
    const parseHumanNumberMaybe = (v) => {
        if (v === null || v === undefined || Array.isArray(v)) return null;
        if (typeof v === 'number') return Number.isFinite(v) ? Math.round(v) : null;
        let raw = String(v).trim().toLowerCase();
        if (!raw) return null;
        raw = raw.replace(/\s+/g, '').replace(/[٬،]/g, ',');
        const suffix = raw.match(/^([0-9]+(?:[\.,][0-9]+)?)([kmb])$/i);
        if (suffix) {
            const num = Number(suffix[1].replace(',', '.'));
            if (!Number.isFinite(num)) return null;
            const mult = suffix[2].toLowerCase() === 'k' ? 1000 : (suffix[2].toLowerCase() === 'm' ? 1000000 : 1000000000);
            return Math.round(num * mult);
        }
        if (/^[0-9]+(?:[\.,][0-9]{3})+$/.test(raw)) {
            const grouped = Number(raw.replace(/[\.,]/g, ''));
            return Number.isFinite(grouped) ? grouped : null;
        }
        if (/^[0-9]+(?:[\.,][0-9]+)?$/.test(raw)) {
            const num = Number(raw.replace(',', '.'));
            return Number.isFinite(num) ? Math.round(num) : null;
        }
        const fallback = Number(raw);
        return Number.isFinite(fallback) ? Math.round(fallback) : null;
    };

    const parseHumanNumber = (v) => {
        const n = parseHumanNumberMaybe(v);
        return n === null ? 0 : n;
    };

    const parseNum = (v) => parseHumanNumber(v);

    const firstNonEmpty = (...values) => {
        for (const v of values) {
            if (Array.isArray(v) && v.length) return v;
            if (v !== undefined && v !== null && String(v).trim() !== '') return v;
        }
        return '';
    };


    const q = (root, selector) => root ? root.querySelector(selector) : null;
    const setText = (root, selector, value) => { const el = q(root, selector); if (el) el.textContent = value; };
    const setDisplay = (root, selector, value) => { const el = q(root, selector); if (el) el.style.display = value; };
    const closestField = (el) => el ? el.closest('.ves-field') : null;

    const UI_LANG_STORAGE_KEY = 'ves_ui_language_v2';
    const UI_LANGS = ['es', 'en', 'fa'];
    const UI_TEXT = {
        es: { socialTitle:'Social Intelligence', socialSub:'Encuentra contenido público en redes sociales con foco en relevancia de marca.', trendTitle:'Trend Finder', trendSub:'Detecta señales de tendencia y convierte datos públicos en lectura estratégica.', brandAuditTitle:'Brand Deep Audit', brandAuditSub:'Analiza una marca desde web, social, búsqueda, maps y research para generar insights estratégicos y una presentación.', runBrandAudit:'Ejecutar Brand Audit', creativeTitle:'Creative Intelligence', creativeSub:'Analiza assets creativos, screenshots y vídeos cortos con AI para extraer hook, claridad, plataforma y próximos pasos.', googleTitle:'Google Intelligence', googleSub:'Búsqueda, Noticias, Trends e Imágenes con parámetros seguros por defecto.', adsTitle:'Ads Intelligence', adsSub:'Analiza señales publicitarias públicas de las principales plataformas sin exponer controles técnicos.', memoryTitle:'Memory', memorySub:'Tus búsquedas guardadas, resultados anteriores y análisis AI. Accede a cualquier resultado sin repetir la recolección.', knowledgeTitle:'Base de conocimiento', knowledgeSub:'Contexto, documentos y referencias que alimentan los análisis AI del workspace.', run:'Ejecutar', runTrend:'Ejecutar Trend Finder', runGoogle:'Ejecutar Google Intelligence', cancel:'Cancelar', credits:'créditos', targetType:'Tipo de objetivo', quantity:'Cantidad', targets:'Objetivos', searchContext:'Contexto de búsqueda', date:'Fecha', country:'País', language:'Idioma', minViews:'Visualizaciones mínimas', searchPlaceholder:'Buscar por texto, autor o URL', aiFocus:'Foco opcional para el análisis AI (ej. hook, CTA, retención, tono)', select:'Seleccionar', selected:'seleccionados', selectedHelp:'Elige varios resultados y pide una lectura comparativa.', analyzeSelected:'Analizar seleccionados', clearSelection:'Limpiar selección', open:'Ver fuente', detail:'Ver detalles', analyze:'Analizar intención', generateBrief:'Generar brief', save:'Guardar', aiAnalysis:'Análisis asistido por IA', generating:'Generando análisis…', generatingBatch:'Generando análisis comparativo de {n} resultados…', memoryLoading:'Cargando memoria...' },
        en: { socialTitle:'Social Intelligence', socialSub:'Find public social media content with brand relevance as the primary signal.', trendTitle:'Trend Finder', trendSub:'Detect trend signals and turn public data into strategic reads.', brandAuditTitle:'Brand Deep Audit', brandAuditSub:'Analyze a brand across web, social, search, maps and research to generate strategic insights and a presentation-ready output.', runBrandAudit:'Run Brand Audit', creativeTitle:'Creative Intelligence', creativeSub:'Analyze creative assets, screenshots and short videos with AI to extract hook, clarity, platform fit and next actions.', googleTitle:'Google Intelligence', googleSub:'Search, News, Trends and Images with safe defaults.', adsTitle:'Ads Intelligence', adsSub:'Analyze public advertising signals from major platforms without technical noise.', memoryTitle:'Memory', memorySub:'Saved searches, previous results and AI analyses. Access any result without running collection again.', knowledgeTitle:'Base de conocimiento', knowledgeSub:'Context, documents and references used by the workspace AI analyses.', run:'Run', runTrend:'Run Trend Finder', runGoogle:'Run Google Intelligence', cancel:'Cancel', credits:'credits', targetType:'Target type', quantity:'Quantity', targets:'Targets', searchContext:'Search context', date:'Date', country:'Country', language:'Content language', minViews:'Minimum views', searchPlaceholder:'Search by text, author or URL', aiFocus:'Optional AI analysis focus, e.g. hook, CTA, retention or tone', select:'Select', selected:'selected', selectedHelp:'Select multiple results and ask for a comparative read.', analyzeSelected:'Analizar seleccionados', clearSelection:'Limpiar selección', open:'Ver fuente', detail:'Ver detalles', analyze:'Analizar intención', generateBrief:'Generar brief', save:'Guardar', aiAnalysis:'AI analysis', generating:'Generating analysis…', generatingBatch:'Generating comparative analysis of {n} results…', memoryLoading:'Loading memory...' },
        fa: { socialTitle:'Social Intelligence', socialSub:'پست‌های عمومی را با تنظیمات کمتر و خروجی متمرکز بر ارتباط پیدا کن.', trendTitle:'ترند فایندر', trendSub:'سیگنال‌های ترند را شناسایی کن و داده عمومی را به تحلیل استراتژیک تبدیل کن.', brandAuditTitle:'Brand Deep Audit', brandAuditSub:'برند را از وب، شبکه‌های اجتماعی، سرچ، maps و research تحلیل کن و insight استراتژیک و خروجی آماده ارائه بساز.', runBrandAudit:'اجرای Brand Audit', creativeTitle:'Creative Intelligence', creativeSub:'Assetهای خلاقه، اسکرین‌شات‌ها و ویدئوهای کوتاه را با AI تحلیل کن تا hook، clarity، platform fit و next action مشخص شود.', googleTitle:'هوش گوگل', googleSub:'Search، News، Trends و Images با تنظیمات امن پیش‌فرض.', adsTitle:'Ads Intelligence', adsSub:'سیگنال‌های عمومی Meta Ads Library و Google Ads Transparency را بدون گزینه‌های فنی اضافه تحلیل کن.', memoryTitle:'حافظه', memorySub:'جست‌وجوها، نتایج قبلی و تحلیل‌های AI ذخیره‌شده. هر run را بدون پرداخت دوباره هزینه اسکرپ باز کن.', knowledgeTitle:'پایگاه دانش', knowledgeSub:'کانتکست، سندها و رفرنس‌هایی که تحلیل‌های AI ورک‌اسپیس از آن‌ها استفاده می‌کند.', run:'اجرا', runTrend:'اجرای ترند فایندر', runGoogle:'اجرای هوش گوگل', cancel:'لغو', credits:'اعتبار', targetType:'نوع هدف', quantity:'تعداد', targets:'اهداف', searchContext:'زمینه جست‌وجو', date:'تاریخ', country:'کشور', language:'زبان محتوا', minViews:'حداقل بازدید', searchPlaceholder:'جست‌وجو بر اساس متن، نویسنده یا URL', aiFocus:'تمرکز اختیاری تحلیل AI، مثل hook، CTA، retention یا tone', select:'انتخاب', selected:'انتخاب شده', selectedHelp:'چند نتیجه را انتخاب کن و تحلیل مقایسه‌ای بگیر.', analyzeSelected:'Analizar seleccionados', clearSelection:'Limpiar selección', open:'Ver fuente', detail:'Ver detalles', analyze:'Analizar intención', generateBrief:'Generar brief', save:'Guardar', aiAnalysis:'تحلیل AI', generating:'در حال تولید تحلیل…', generatingBatch:'در حال تولید تحلیل مقایسه‌ای {n} نتیجه…', memoryLoading:'در حال بارگذاری حافظه...' }
    };
    const getUILanguage = (widget) => {
        const fromSelect = widget?.querySelector?.('.ves-ui-language')?.value;
        const stored = localStorage.getItem(UI_LANG_STORAGE_KEY) || '';
        const lang = fromSelect || stored || 'es';
        return UI_LANGS.includes(lang) ? lang : 'es';
    };
    const uiText = (widget, key, vars = {}) => {
        const lang = getUILanguage(widget);
        let text = (UI_TEXT[lang] && UI_TEXT[lang][key]) || UI_TEXT.es[key] || key;
        Object.entries(vars).forEach(([k,v]) => { text = text.split('{' + k + '}').join(String(v)); });
        return text;
    };
    const syncOutputLanguageInputs = (widget) => {
        const lang = getUILanguage(widget);
        widget?.querySelectorAll?.('form').forEach((form) => {
            let input = form.querySelector('[name="outputLanguage"]');
            if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'outputLanguage'; form.appendChild(input); }
            input.value = lang;
        });
        return lang;
    };
    const applyUILanguage = (widget) => {
        if (!widget) return;
        const lang = syncOutputLanguageInputs(widget);
        widget.setAttribute('data-ui-language', lang);
        widget.classList.toggle('is-rtl', lang === 'fa');
        const selector = widget.querySelector('.ves-ui-language');
        if (selector && selector.value !== lang) selector.value = lang;
        const pageTexts = { social:['socialTitle','socialSub'], trend:['trendTitle','trendSub'], 'brand-audit':['brandAuditTitle','brandAuditSub'], creative:['creativeTitle','creativeSub'], google:['googleTitle','googleSub'], ads:['adsTitle','adsSub'], memory:['memoryTitle','memorySub'], knowledge:['knowledgeTitle','knowledgeSub'] };
        Object.entries(pageTexts).forEach(([page, keys]) => { const section = widget.querySelector(`.ves-page[data-page="${page}"]`); if (!section) return; const title = section.querySelector('.ves-page-title'); const sub = section.querySelector('.ves-page-sub'); if (title) title.textContent = uiText(widget, keys[0]); if (sub) sub.textContent = uiText(widget, keys[1]); });
        widget.querySelectorAll('.ves-start').forEach((btn) => { const page = btn.closest('.ves-page')?.dataset.page || ''; const svg = btn.querySelector('svg')?.outerHTML || ''; const kbd = btn.querySelector('kbd')?.outerHTML || ''; const key = page === 'trend' ? 'runTrend' : (page === 'brand-audit' ? 'runBrandAudit' : (page === 'google' ? 'runGoogle' : 'run')); btn.innerHTML = svg + uiText(widget, key) + (kbd ? ' ' + kbd : ''); });
        widget.querySelectorAll('.ves-abort').forEach((btn) => { btn.textContent = uiText(widget, 'cancel'); });
        const credit = widget.querySelector('.ves-credit-pill-label'); if (credit) credit.textContent = uiText(widget,'credits');
        // v0.9.27.0 — Phase 1: .ves-limit-label setter dropped. The visible
        // Cantidad/Limit field no longer exists in normal user UI.
        widget.querySelectorAll('.ves-targets-label').forEach(el => el.textContent = uiText(widget,'targets'));
        widget.querySelectorAll('.ves-date-label').forEach(el => el.textContent = uiText(widget,'date'));
        widget.querySelectorAll('.ves-country-label').forEach(el => el.textContent = uiText(widget,'country'));
        widget.querySelectorAll('.ves-language-label').forEach(el => el.textContent = uiText(widget,'language'));
        widget.querySelectorAll('.ves-minviews-label').forEach(el => el.textContent = uiText(widget,'minViews'));
        widget.querySelectorAll('.ves-search-context-wrap .ves-label').forEach(el => el.textContent = uiText(widget,'searchContext'));
        widget.querySelectorAll('.ves-mode-wrap .ves-label').forEach(el => el.textContent = uiText(widget,'targetType'));
        widget.querySelectorAll('.ves-filter-query').forEach(el => el.placeholder = uiText(widget,'searchPlaceholder'));
        widget.querySelectorAll('.ves-analysis-focus').forEach(el => el.placeholder = uiText(widget,'aiFocus'));
        const loading = widget.querySelector('.ves-sidebar-memory-loading'); if (loading) loading.textContent = uiText(widget,'memoryLoading');
        widget.querySelectorAll('.ves-analyze-selected').forEach(el => el.textContent = uiText(widget,'analyzeSelected'));
        widget.querySelectorAll('.ves-clear-selection').forEach(el => el.textContent = uiText(widget,'clearSelection'));
        widget.querySelectorAll('.ves-show-item').forEach(el => el.textContent = uiText(widget,'detail'));
        widget.querySelectorAll('.ves-analyze-item').forEach(el => el.textContent = uiText(widget,'analyze'));
        widget.querySelectorAll('.ves-select-card span').forEach(el => el.textContent = uiText(widget,'select'));
        widget.querySelectorAll('.ves-card-actions a.ves-mini-btn').forEach(el => { if (/^(Abrir|Open|باز کردن)$/i.test(String(el.textContent).trim())) el.textContent = uiText(widget,'open'); });
        widget.querySelectorAll('.ves-batch-analysis-bar strong').forEach(el => { const count = el.querySelector('.ves-selected-count')?.outerHTML || '<span class="ves-selected-count">0</span>'; el.innerHTML = count + ' ' + escapeHtml(uiText(widget,'selected')); });
        widget.querySelectorAll('.ves-batch-analysis-bar > div > span').forEach(el => { el.textContent = uiText(widget,'selectedHelp'); });
    };


    // v0.9.11.8: Generic legacy Extra fields are internal adapter defaults, not UI.
    // Some cached/older templates can still render legacy generic fields.
    // This normalizer keeps hidden defaults for the backend and removes any visible controls.
    const LEGACY_EXTRA_DEFAULTS = {
        extraSelect1: 'ALL',
        extraSelect2: '',
        extraNumber1: '',
        extraCheckbox1: '',
        extraCheckbox2: ''
    };

    const ensureHiddenLegacyExtraInputs = (form) => {
        if (!form) return;
        Object.entries(LEGACY_EXTRA_DEFAULTS).forEach(([name, value]) => {
            const hidden = Array.from(form.querySelectorAll(`[name="${name}"]`)).find((el) => String(el.type || '').toLowerCase() === 'hidden');
            if (hidden) {
                hidden.value = value;
                return;
            }
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            input.className = 'ves-legacy-extra-hidden';
            form.appendChild(input);
        });
    };

    const removeLegacyExtraFields = (root) => {
        if (!root || !root.querySelectorAll) return;
        const forms = root.matches && root.matches('form') ? [root] : Array.from(root.querySelectorAll('form'));
        forms.forEach((form) => {
            ensureHiddenLegacyExtraInputs(form);

            form.querySelectorAll('.ves-extra-row, .ves-extra-row-2, .ves-extra-select1-wrap, .ves-extra-select2-wrap, .ves-extra-number1-wrap, .ves-extra-checkboxes-wrap, .ves-extra-checkbox1-wrap, .ves-extra-checkbox2-wrap').forEach((el) => {
                el.remove();
            });

            Object.keys(LEGACY_EXTRA_DEFAULTS).forEach((name) => {
                form.querySelectorAll(`[name="${name}"]`).forEach((el) => {
                    if (String(el.type || '').toLowerCase() === 'hidden') return;
                    const removable = el.closest('.ves-row, .ves-row-3, .ves-field, label') || el;
                    removable.remove();
                });
            });
        });
    };

    const downloadFile = (name, content, mime) => {
        const blob = new Blob([content], { type: mime });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 500);
    };

    const setOptions = (el, options, value = '') => {
        if (!el) return;
        const tag = String(el.tagName || '').toLowerCase();
        if (tag === 'select') {
            el.innerHTML = (options || []).map(o => `<option value="${escapeHtml(o.v)}">${escapeHtml(o.l)}</option>`).join('');
        }
        if (value !== undefined && value !== null && value !== '') el.value = String(value);
    };

    const itemsToCsv = (items) => {
        if (!Array.isArray(items) || !items.length) return '';
        const keys = new Set();
        items.forEach(it => { if (it && typeof it === 'object') Object.keys(it).forEach(k => keys.add(k)); });
        const head = Array.from(keys);
        const esc = (v) => {
            if (v === null || v === undefined) return '';
            if (typeof v === 'object') v = JSON.stringify(v);
            v = String(v);
            if (/[",\n\r]/.test(v)) v = '"' + v.replace(/"/g, '""') + '"';
            return v;
        };
        return head.join(',') + '\n' + items.map(it => head.map(k => esc(it?.[k])).join(',')).join('\n');
    };

    const LIMIT_PRESETS = [5,10,15,20,30,50].map(v => ({ v, l: `${v} resultados` }));
    const TREND_TIME_PRESETS = [
        { v:'1d', l:'Últimas 24 horas' },
        { v:'7d', l:'Últimos 7 días' },
        { v:'30d', l:'Últimos 30 días' },
        { v:'90d', l:'Últimos 90 días' }
    ];
    const DATE_PRESETS = [
        { v:'any', l:'Cualquier fecha' },
        { v:'1d', l:'Últimas 24 horas' },
        { v:'7d', l:'Últimos 7 días' },
        { v:'30d', l:'Últimos 30 días' },
        { v:'90d', l:'Últimos 3 meses' },
        { v:'180d', l:'Últimos 6 meses' },
        { v:'365d', l:'Último año' }
    ];
    const FB_ADS_DATE_PRESETS = [
        { v:'any', l:'Cualquier fecha' },
        { v:'1d', l:'Últimas 24 horas' },
        { v:'7d', l:'Últimos 7 días' },
        { v:'14d', l:'Últimos 14 días' },
        { v:'30d', l:'Últimos 30 días' }
    ];
    const COUNTRY_PRESETS = [
        { v:'None', l:'Cualquier país' },{ v:'ES', l:'España' },{ v:'US', l:'Estados Unidos' },{ v:'GB', l:'Reino Unido' },{ v:'FR', l:'Francia' },{ v:'DE', l:'Alemania' },{ v:'IT', l:'Italia' },{ v:'PT', l:'Portugal' },{ v:'NL', l:'Países Bajos' },{ v:'IE', l:'Irlanda' },{ v:'BE', l:'Bélgica' },{ v:'CH', l:'Suiza' },{ v:'CA', l:'Canadá' },{ v:'MX', l:'México' },{ v:'BR', l:'Brasil' },{ v:'AR', l:'Argentina' },{ v:'CL', l:'Chile' },{ v:'CO', l:'Colombia' },{ v:'PE', l:'Perú' },{ v:'JP', l:'Japón' },{ v:'KR', l:'Corea del Sur' },{ v:'CN', l:'China' },{ v:'HK', l:'Hong Kong' },{ v:'TW', l:'Taiwán' },{ v:'SG', l:'Singapur' },{ v:'MY', l:'Malasia' },{ v:'ID', l:'Indonesia' },{ v:'TH', l:'Tailandia' },{ v:'PH', l:'Filipinas' },{ v:'VN', l:'Vietnam' },{ v:'IN', l:'India' },{ v:'AU', l:'Australia' },{ v:'NZ', l:'Nueva Zelanda' },{ v:'AE', l:'Emiratos Árabes' },{ v:'SA', l:'Arabia Saudí' },{ v:'TR', l:'Turquía' },{ v:'MA', l:'Marruecos' },{ v:'EG', l:'Egipto' },{ v:'ZA', l:'Sudáfrica' },{ v:'IL', l:'Israel' },{ v:'SE', l:'Suecia' },{ v:'NO', l:'Noruega' },{ v:'DK', l:'Dinamarca' },{ v:'FI', l:'Finlandia' },{ v:'PL', l:'Polonia' }
    ];
    const LANGUAGE_PRESETS = [
        { v:'', l:'Cualquier idioma' },{ v:'es', l:'Español' },{ v:'en', l:'Inglés' },{ v:'fr', l:'Francés' },{ v:'de', l:'Alemán' },{ v:'it', l:'Italiano' },{ v:'pt', l:'Portugués' },{ v:'nl', l:'Neerlandés' },{ v:'tr', l:'Turco' },{ v:'ar', l:'Árabe' },{ v:'ja', l:'Japonés' },{ v:'ko', l:'Coreano' },{ v:'zh', l:'Chino' },{ v:'ru', l:'Ruso' },{ v:'vi', l:'Vietnamita' },{ v:'id', l:'Indonesio' },{ v:'th', l:'Tailandés' },{ v:'hi', l:'Hindi' }
    ];

    const CONFIG = {
        tiktok: {
            label:'TikTok', defaultModo:'search', defaultLimit:20, primaryMetricLabel:'views',
            modes:[{v:'search',l:'Por búsqueda'},{v:'url_posts',l:'URL de post(s)'},{v:'url_profile',l:'URL de perfil'}],
            placeholders:{ search:'#fyp\ndigital marketing\nai tools', url_posts:'https://www.tiktok.com/@user/video/123456789', url_profile:'https://www.tiktok.com/@user' },
            hints:{ search:'Introduce hashtags con # o frases de búsqueda, una por línea.', url_posts:'Uno o varios enlaces directos de videos de TikTok, uno por línea. No se aplican filtros de fecha, país o plays.', url_profile:'Pega perfiles públicos de TikTok para recuperar sus videos y aplicar filtros de fecha, país o plays.' },
            optionsByModo:{ search:[], url_posts:[], url_profile:[] },
            showsCountry:true, showsLanguage:true, showsTranscripción:false, showMinViews:true, showSearchContext:true, minViewsHidden:false, minLikesHidden:true
        },
        youtube: {
            label:'YouTube', defaultModo:'search', defaultLimit:10, primaryMetricLabel:'views',
            modes:[{v:'search',l:'Por búsqueda'},{v:'url_posts',l:'URL de video(s)'},{v:'url_profile',l:'URL de canal/perfil'}],
            placeholders:{ search:'saas marketing\nweb design trends', url_posts:'https://www.youtube.com/watch?v=VIDEOID\nhttps://www.youtube.com/shorts/VIDEOID', url_profile:'https://www.youtube.com/@channel\nhttps://www.youtube.com/c/channel' },
            hints:{ search:'Una búsqueda o título por línea.', url_posts:'Pega videos o Shorts concretos. No se aplican filtros de fecha, país o plays.', url_profile:'Pega canales públicos para recuperar videos y aplicar filtros de fecha, idioma o plays.' },
            optionsByModo:{ search:[], url_posts:[], url_profile:[] },
            showsCountry:true, showsLanguage:true, showsTranscripción:false, showMinViews:true, showSearchContext:true, minViewsHidden:false, minLikesHidden:true
        },
        facebook: {
            label:'Facebook Posts', defaultModo:'url_profile', defaultLimit:20, primaryMetricLabel:'likes',
            modes:[{v:'url_posts',l:'URL de post(s)'},{v:'url_profile',l:'URL de página/perfil'}],
            placeholders:{ url_posts:'https://www.facebook.com/page/posts/123456789\nhttps://www.facebook.com/reel/123456789', url_profile:'https://www.facebook.com/humansofnewyork/\nhttps://www.facebook.com/natgeo/' },
            hints:{ url_posts:'Pega posts o reels públicos concretos. No se aplican filtros de fecha o país.', url_profile:'Pega páginas o perfiles públicos para recuperar posts y aplicar filtros de fecha y país.' },
            optionsByModo:{ url_posts:[], url_profile:[] },
            showsCountry:true, showsLanguage:true, showsTranscripción:false, showMinViews:false, showSearchContext:false, minViewsHidden:true, minLikesHidden:true
        },
        instagram: {
            label:'Instagram', defaultModo:'search', defaultLimit:24, primaryMetricLabel:'likes',
            modes:[{v:'search',l:'Por búsqueda'},{v:'url_posts',l:'URL de post/reel(s)'},{v:'url_profile',l:'URL de perfil'}],
            placeholders:{ search:'#travel\n@natgeo\nmadrid food', url_posts:'https://www.instagram.com/p/ABC123xyz/\nhttps://www.instagram.com/reel/ABC123xyz/', url_profile:'https://www.instagram.com/natgeo/\nnatgeo' },
            hints:{ search:'Usa # para hashtags, @usuario para perfiles, o frases de búsqueda. Un término suelto como una marca se tratará como búsqueda, no como perfil.', url_posts:'Pega posts o reels concretos. No se aplican filtros de fecha o país.', url_profile:'Pega perfiles públicos o handles para recuperar publicaciones y aplicar filtros de fecha y país.' },
            optionsByModo:{ search:[], url_posts:[], url_profile:[] },
            showsCountry:true, showsLanguage:true, showsTranscripción:false, showMinViews:false, showSearchContext:false, minViewsHidden:true, minLikesHidden:true
        },
        facebook_ads: {
            label:'Facebook Ads', defaultModo:'search', defaultLimit:20, countryDefault:'None', primaryMetricLabel:'impressions',
            modes:[{v:'search',l:'Por búsqueda'},{v:'urls',l:'Por URLs'}],
            placeholders:{ search:'zapier\nshopify', urls:'https://www.facebook.com/ads/library/?active_status=all&ad_type=all&country=ALL&q=zapier&search_type=keyword_unordered&media_type=all\nhttps://www.facebook.com/ZapierApp' },
            hints:{ search:'Introduce marcas o frases para construir automáticamente la búsqueda de Ads Library.', urls:'Pega URLs de Ads Library o páginas de Facebook.' },
            optionsByModo:{ search:[], urls:[] },
            showsCountry:true, showsLanguage:false, showsTranscripción:false, showMinViews:false, showSearchContext:false, minViewsHidden:true, minLikesHidden:true
        },
        google_ads: {
            label:'Google Ads', defaultModo:'search', defaultLimit:10, countryDefault:'ES', primaryMetricLabel:'impressions',
            modes:[{v:'search',l:'Por keyword / dominio'},{v:'urls',l:'Por URL Transparency'}],
            placeholders:{ search:'your-brand\nyour-brand.com\ncompetitor brand', urls:'https://adstransparency.google.com/advertiser/AR00000000000000000000' },
            hints:{ search:'Introduce marcas, dominios, advertiser IDs o keywords. El sistema consulta Google Ads Transparency.', urls:'Pega URLs completas de Google Ads Transparency, una por línea.' },
            optionsByModo:{ search:[], urls:[] },
            showsCountry:true, showsLanguage:false, showsTranscripción:false, showMinViews:false, showSearchContext:false, minViewsHidden:true, minLikesHidden:true
        },
        twitter: {
            label:'X / Twitter', defaultModo:'search', defaultLimit:20, primaryMetricLabel:'likes',
            modes:[{v:'search',l:'Por búsqueda'},{v:'url_posts',l:'URL de tweet(s)'},{v:'url_profile',l:'URL de perfil/búsqueda'}],
            placeholders:{ search:'#marketing\nweb scraping\nsaas growth', url_posts:'https://twitter.com/user/status/123456789', url_profile:'https://twitter.com/example\nhttps://twitter.com/search?q=marketing&src=typed_query' },
            hints:{ search:'Una búsqueda o hashtag por línea.', url_posts:'Pega tweets concretos. No se aplican filtros de fecha o país.', url_profile:'Pega perfiles o búsquedas públicas para recuperar tweets y aplicar filtros de fecha o país.' },
            optionsByModo:{ search:[], url_posts:[], url_profile:[] },
            showsCountry:true, showsLanguage:true, showsTranscripción:false, showMinViews:false, showSearchContext:false, minViewsHidden:true, minLikesHidden:true
        },
        linkedin: {
            label:'LinkedIn Profiles', defaultModo:'search', defaultLimit:20, primaryMetricLabel:'profiles',
            modes:[],
            placeholders:{ search:'Marketing Manager Spain\nFounder SaaS Madrid\nHead of Growth ecommerce' },
            hints:{ search:'Busca personas por rol, seniority, mercado, empresa o ICP. Usa filtros para afinar.' },
            optionsByModo:{ search:[] },
            showsCountry:false, showsLanguage:false, showsTranscripción:false, showMinViews:false, showSearchContext:true, minViewsHidden:true, minLikesHidden:true
        },
        reddit: {
            label:'Reddit', defaultModo:'search', defaultLimit:20, primaryMetricLabel:'upvotes',
            modes:[{v:'search',l:'Por búsqueda'},{v:'urls',l:'Por URLs'}],
            placeholders:{ search:'pasta recipes\nbrand name\nmarketing automation', urls:'https://www.reddit.com/r/pasta/\nhttps://www.reddit.com/r/pasta/comments/vwi6jx/pasta_peperoni_and_ricotta_cheese_how_to_make/' },
            hints:{ search:'Una búsqueda por línea. Opcionalmente limita por comunidad en Opciones Reddit.', urls:'Pega URLs públicas de Reddit: post, subreddit, usuario, popular o search URL.' },
            optionsByModo:{ search:[], urls:[] },
            showsCountry:false, showsLanguage:false, showsTranscripción:false, showMinViews:false, showSearchContext:true, minViewsHidden:true, minLikesHidden:true
        },

        pinterest: {
            label:'Pinterest', defaultModo:'search', defaultLimit:20, primaryMetricLabel:'saves',
            modes:[{v:'search',l:'Por búsqueda'},{v:'urls',l:'Por URLs'}],
            placeholders:{ search:'home decor trends\nhealthy recipes\nbrand moodboard', urls:'https://www.pinterest.com/pin/123456789/\nhttps://www.pinterest.com/user/board-name/\nhttps://www.pinterest.com/search/pins/?q=marketing%20automation' },
            hints:{ search:'Una keyword principal por ejecución. Pinterest usa una búsqueda pública por ejecución.', urls:'Pega URLs públicas de Pinterest: pin, idea pin, board, perfil o search URL.' },
            optionsByModo:{ search:[], urls:[] },
            showsCountry:false, showsLanguage:false, showsTranscripción:false, showMinViews:false, showSearchContext:true, minViewsHidden:true, minLikesHidden:true
        },

        semrush: {
            label:'Semrush', defaultModo:'keyword_magic', defaultLimit:20, primaryMetricLabel:'SEO metric', targetsLabel:'Keyword / dominio / URL', countryDefault:'None', languageDefault:'',
            modes:[
                {v:'keyword_simple',l:'Keywords Scraper'},
                {v:'keyword_magic',l:'Keyword Magic Tool'},
                {v:'domain_seo',l:'Domain / SEO data'},
                {v:'top_websites',l:'Top Websites'}
            ],
            placeholders:{
                keyword_simple:'semrush tools\nmarketing automation\nai search optimization',
                keyword_magic:'free ai image generator',
                domain_seo:'zapier.com\nmake.com',
                top_websites:'Opcional: deja vacío para Top Websites'
            },
            hints:{
                keyword_simple:'Lista rápida de keywords. Una por línea o separadas por coma.',
                keyword_magic:'Keyword principal para obtener variaciones, volumen, CPC, competencia, intent y tendencias.',
                domain_seo:'Dominios/URLs para autoridad, tráfico, backlinks, competitors, ranking, SEO audit. Añade keyword si activas Keyword Volume o SERP.',
                top_websites:'Ranking de sitios por industria y país. No necesita dominio ni keyword.'
            },
            optionsByModo:{ keyword_simple:[], keyword_magic:[], domain_seo:[], top_websites:[] },
            showsCountry:false, showsLanguage:false, showsTranscripción:false, showMinViews:false, showSearchContext:true, minViewsHidden:true, minLikesHidden:true
        },
        moz: {
            label:'MOZ', defaultModo:'urls', defaultLimit:10, primaryMetricLabel:'authority', targetsLabel:'Dominios / URLs', countryDefault:'None', languageDefault:'',
            modes:[{v:'urls',l:'Dominios / URLs'}],
            placeholders:{ urls:'example.com\nhttps://example.com/page' },
            hints:{ urls:'Un dominio o URL por línea. MOZ devuelve autoridad e histórico según las opciones.' },
            optionsByModo:{ urls:[] },
            showsCountry:false, showsLanguage:false, showsTranscripción:false, showMinViews:false, showSearchContext:false, minViewsHidden:true, minLikesHidden:true
        },
        ahrefs: {
            label:'Ahrefs', defaultModo:'website_authority', defaultLimit:10, primaryMetricLabel:'SEO metric', targetsLabel:'Dominio, URL o keyword', countryDefault:'US', languageDefault:'',
            modes:[
                {v:'website_authority',l:'Website Authority'},
                {v:'backlinks_overview',l:'Backlinks overview'},
                {v:'backlinks_list',l:'Backlinks list'},
                {v:'broken_links',l:'Broken links'},
                {v:'traffic_overview',l:'Traffic overview'},
                {v:'ai_visibility',l:'AI visibility'},
                {v:'website_details',l:'Website details'},
                {v:'keyword_ideas',l:'Keyword ideas'},
                {v:'keyword_difficulty',l:'Keyword difficulty'},
                {v:'keyword_metrics',l:'Keyword metrics'},
                {v:'keyword_rank',l:'Keyword rank'},
                {v:'serp_overview',l:'SERP overview'},
                {v:'top_websites',l:'Top websites'}
            ],
            placeholders:{
                website_authority:'example.com\ncompetitor.com', backlinks_overview:'example.com', backlinks_list:'example.com', broken_links:'example.com', traffic_overview:'example.com', ai_visibility:'brand name or keyword', website_details:'example.com', keyword_ideas:'seo tools', keyword_difficulty:'seo tools', keyword_metrics:'seo tools', keyword_rank:'seo tools\nexample.com', serp_overview:'seo tools', top_websites:'Opcional: deja vacío para Top Websites'
            },
            hints:{
                website_authority:'Domain Rating, backlinks, referring domains y métricas de autoridad.', backlinks_overview:'Resumen de backlinks y referring domains para un dominio.', backlinks_list:'Lista de backlinks con anchors y URLs origen/destino.', broken_links:'Detección de broken links para el dominio objetivo.', traffic_overview:'Estimación de tráfico, valor, top pages y keywords.', ai_visibility:'Visibilidad/citaciones en modelos AI para una marca o keyword.', website_details:'Ficha completa de website con señales SEO principales.', keyword_ideas:'Ideas de keywords por motor de búsqueda, incluido ChatGPT/Gemini/Perplexity.', keyword_difficulty:'Keyword difficulty, volumen y potencial.', keyword_metrics:'Métricas de keyword: volumen, CPC, clicks y potencial.', keyword_rank:'Ranking exacto para keyword + dominio + país.', serp_overview:'Top SERP con métricas SEO por resultado.', top_websites:'Ranking o trending websites por país/categoría.'
            },
            optionsByModo:{ website_authority:[], backlinks_overview:[], backlinks_list:[], broken_links:[], traffic_overview:[], ai_visibility:[], website_details:[], keyword_ideas:[], keyword_difficulty:[], keyword_metrics:[], keyword_rank:[], serp_overview:[], top_websites:[] },
            showsCountry:true, showsLanguage:false, showsTranscripción:false, showMinViews:false, showSearchContext:true, minViewsHidden:true, minLikesHidden:true
        },
        google_intel: {
            label:'Google Intelligence', defaultModo:'google', defaultLimit:20,
            modes:[], placeholders:{ google:'' }, hints:{ google:'SERP, News, Trends e Images.' },
            optionsByModo:{}, showsCountry:false, showsLanguage:false, showsTranscripción:false, minViewsHidden:true, minLikesHidden:true, isGoogleIntel:true
        },
        trend_finder: {
            label:'Trend Finder', defaultModo:'trend', defaultLimit:20,
            modes:[],
            placeholders:{ trend:'' },
            hints:{ trend:'Cruza Google Trends, YouTube, TikTok y X en una sola lectura estratégica.' },
            optionsByModo:{},
            showsCountry:false, showsLanguage:false, showsTranscripción:false, minViewsHidden:true, minLikesHidden:true,
            isTrendFinder:true
        }
    };

    const widgetState = new WeakMap();
    const isPostUrlMode = (mode) => mode === 'url_posts' || mode === 'post_urls' || mode === 'post_url';
    const isUrlMode = (mode) => isPostUrlMode(mode) || mode === 'url_profile' || mode === 'urls';
    const modeExplainerCopy = (cfg, mode) => {
        const metric = cfg.primaryMetricLabel || 'views';
        if (isPostUrlMode(mode)) {
            return { kind: 'exact', title: 'URLs exactas', body: 'Pega piezas concretas. Los filtros se omiten para mantener el análisis exacto.' };
        }
        if (mode === 'url_profile') {
            return { kind: 'profile', title: 'Perfil filtrado', body: 'Pega un perfil o canal. Fecha, país, idioma y mínimo de ' + metric + ' siguen activos.' };
        }
        if (mode === 'urls') {
            return { kind: 'url', title: 'Modo URL', body: 'Pega URLs válidas. Los filtros dependen de la fuente y del tipo de contenido.' };
        }
        return { kind: 'search', title: 'Búsqueda guiada', body: 'Añade marcas, hashtags o temas. Usa filtros solo para afinar.' };
    };

    const renderModeExplainer = (scope, cfg, mode) => {
        const el = q(scope, '[data-mode-explainer]');
        if (!el) return;
        const copy = modeExplainerCopy(cfg, mode);
        el.className = 'ves-mode-explainer is-' + copy.kind;
        el.innerHTML = `<strong>${escapeHtml(copy.title)}</strong><span>${escapeHtml(copy.body)}</span>`;
    };

    const sortPriorityLabel = (value, metric = 'views') => ({
        relevance: 'Mejor coincidencia',
        latest: 'Más reciente',
        top_metric: 'Más ' + String(metric || 'views'),
        comments: 'Más comentarios'
    }[String(value || 'relevance')] || 'Mejor coincidencia');

    const renderActiveSettingsSummary = (scope, form, cfg, mode) => {
        const el = q(scope, '[data-active-settings]');
        if (!el || !form) return;
        const chips = [];
        const addChip = (label, value, locked = false) => {
            value = String(value || '').trim();
            if (!value) return;
            chips.push(`<span class="ves-filter-chip${locked ? ' is-locked' : ''}"><b>${escapeHtml(label)}</b>${escapeHtml(value)}</span>`);
        };
        const metric = cfg.primaryMetricLabel || 'views';
        addChip('Modo', (cfg.modes || []).find(m => m.v === mode)?.l || mode);
        if (isPostUrlMode(mode)) {
            addChip('Filtros', 'desactivados para URLs exactas', true);
        } else {
            // v0.9.27.0 — Phase 1: "Cantidad" chip removed. The system decides
            // result volume automatically; surfacing it as a user-facing chip
            // re-creates the "user chooses count" mental model we removed.
            const datePresetEl = q(form, '[name="datePreset"]');
            const datePresetLabel = datePresetEl?.selectedOptions?.[0]?.textContent || '';
            addChip('Fecha', datePresetLabel && datePresetEl.value !== 'any' ? datePresetLabel : 'sin límite');
            const country = q(form, '[name="country"]')?.selectedOptions?.[0]?.textContent || '';
            addChip('País', country && country !== 'Cualquier país' ? country : 'cualquiera');
            const langEl = q(form, '[name="language"]');
            const lang = langEl?.selectedOptions?.[0]?.textContent || '';
            if (langEl && langEl.value) addChip('Idioma', lang);
            const min = q(form, '[name="minViews"]')?.value || '';
            addChip('Mín. ' + metric, min && Number(min) > 0 ? min : 'sin mínimo');
            addChip('Orden', sortPriorityLabel(q(form, '[name="sortPriority"]')?.value || 'relevance', metric));
        }
        el.innerHTML = chips.join('');
    };

    const renderPlatformChecklist = (scope, cfg, mode) => {
        const el = q(scope, '[data-platform-checklist]');
        if (!el || !cfg) return;
        const metric = cfg.primaryMetricLabel || 'views';
        const modeLabel = (cfg.modes || []).find(m => m.v === mode)?.l || mode || 'search';
        const items = isPostUrlMode(mode) ? [
            ['Entrada', 'URLs exactas de posts/videos/tweets'],
            ['Filtros', 'Se omiten'],
            ['Métrica', 'Sólo contexto'],
            ['Salida', 'Piezas concretas']
        ] : (mode === 'url_profile' ? [
            ['Entrada', 'URL de perfil/canal/página'],
            ['Filtros', 'Fecha / país'],
            ['Métrica', 'Ranking por ' + metric],
            ['Orden', sortPriorityLabel(q(scope, '[name="sortPriority"]')?.value || 'relevance', metric)],
            ['Salida', 'Posts filtrados']
        ] : [
            ['Entrada', 'Búsqueda contextual'],
            ['Modo', modeLabel],
            ['Métrica', 'Ranking por ' + metric],
            ['Orden', sortPriorityLabel(q(scope, '[name="sortPriority"]')?.value || 'relevance', metric)],
            ['Consejo', 'Añade contexto']
        ]);
        el.innerHTML = items.map(([label, value]) => `<span><b>${escapeHtml(label)}</b>${escapeHtml(value)}</span>`).join('');
    };


    const runPreflightProfile = (platform, mode, form) => {
        // v0.9.30.3 Phase 0 correction: the frontend bundle must not expose
        // provider mechanics (actor slugs, vendor-specific routes, dataset ids).
        // Exact provider diagnostics remain available only server-side for admins.
        const byPlatform = {
            trend_finder: { sourceRoute:'Trend signal collector', input:'topic / market / time range', output:'trend candidates + interpreted signals', risk:'medium', note:'Trend runs combine multiple source routes and may consume more credits.' },
            brand_audit: { sourceRoute:'Review signal collector', input:'brand / competitors / public URLs', output:'evidence, gaps, recommendations', risk:'medium', note:'Brand runs combine public source routes and evidence quality can vary by market.' },
            reviews: { sourceRoute:'Review signal collector', input:'product / brand / public review URLs', output:'review signals + sentiment patterns', risk:'medium', note:'Use public sources and specific product or brand context for better signal quality.' },
            tiktok: { sourceRoute:'Social video collector', input:'keyword / hashtag / profile / post URL', output:'candidate videos + metrics', risk:'medium', note:'Works best with clear topics, public URLs, and reasonable candidate limits.' },
            youtube: { sourceRoute:'Social video collector', input:'search or YouTube URL', output:'videos / shorts / channels', risk:'low', note:'Use direct URLs for specific videos or concise searches for discovery.' },
            facebook: { sourceRoute:'Social post collector', input:'public page/post URL', output:'posts + reactions', risk:'medium', note:'Only public pages/posts are supported. Private or personal profiles may return no results.' },
            instagram: { sourceRoute:'Social visual collector', input:'hashtag / profile / post URL', output:'posts/reels/profile rows', risk:'medium', note:'Direct post/profile URLs are more predictable than broad hashtags.' },
            twitter: { sourceRoute:'Social conversation collector', input:'search / profile / post URL', output:'posts + author metrics', risk:'medium', note:'If a narrow query returns little signal, broaden the topic or switch sort mode.' },
            reddit: { sourceRoute:'Community signal collector', input:'search / community / post URL', output:'posts, comments, communities, users', risk:'medium', note:'Public community content is more reliable than narrow URL-only runs.' },
            pinterest: { sourceRoute:'Visual discovery collector', input:'search or public Pinterest URLs', output:'pins / boards / user info / comments', risk:'high', note:'Access can vary. Start with a broader query or direct public URLs.' },
            linkedin: { sourceRoute:'Professional signal collector', input:'query + people filters', output:'profile intelligence', risk:'medium', note:'Use fewer filters first, then narrow. Multi-value filters are handled server-side.' },
            facebook_ads: { sourceRoute:'Ads transparency collector', input:'brand/page/ads transparency URL', output:'ads + creative snapshots', risk:'medium', note:'Use specific countries and active/all status when results are sparse.' },
            google_ads: { sourceRoute:'Ads transparency collector', input:'domain / advertiser / transparency URL', output:'ads transparency rows', risk:'medium', note:'Transparency data can be sparse by country and advertiser.' },
            moz: { sourceRoute:'SEO signal collector', input:'domain / URL', output:'authority + backlink/keyword context', risk:'low', note:'Domain-only input is safest for quick checks.' },
            ahrefs: { sourceRoute:'SEO signal collector', input:'search type + URL/keyword', output:'SEO metrics by selected mode', risk:'medium', note:'Each run sends one selected SEO mode. Some modes need both keyword and URL/domain.' }
        };
        if (platform === 'semrush') {
            const notes = {
                keyword_simple: 'Quick keyword list mode. Broader seeds produce more useful candidates.',
                keyword_magic: 'Keyword expansion mode. The UI and server clamp candidates to a safe range.',
                domain_seo: 'Use URLs/domains for authority, traffic, backlinks, keyword volume or SERP context.',
                top_websites: 'Targetless mode. Uses industry and country parameters, not the Objetivos textarea.'
            };
            return { sourceRoute:'SEO signal collector', input: mode === 'top_websites' ? 'industry + country' : (mode === 'domain_seo' ? 'domain/URL + optional keyword' : 'keyword'), output:'SEO / keyword intelligence', risk: mode === 'keyword_simple' ? 'high' : 'medium', note: notes[mode] || notes.keyword_magic };
        }
        return byPlatform[platform] || { sourceRoute:'Configured signal collector', input:'targets', output:'normalized records', risk:'medium', note:'Check source settings before high-volume runs.' };
    };

    const renderRunPreflight = (scope, form, cfg, mode) => {
        const box = q(scope, '[data-run-preflight]');
        if (!box || !form) return;
        const platform = q(form, '[name="platform"]')?.value || '';
        const profile = runPreflightProfile(platform, mode, form);
        const modeLabel = (cfg?.modes || []).find(m => m.v === mode)?.l || mode || 'search';
        const riskClass = profile.risk === 'high' ? 'is-risk' : (profile.risk === 'medium' ? 'is-warn' : '');
        const title = q(box, '.ves-run-preflight-title');
        if (title) title.textContent = `${cfg?.label || platform} · ${modeLabel}`;
        const limitValue = q(form, '[name="limit"]')?.value || q(form, '[name="semrushMaxResults"]')?.value || q(form, '[name="ahrefsTopLimit"]')?.value || '—';
        const chips = [
            ['Source route', profile.sourceRoute || 'Configured signal collector', riskClass],
            ['Input', profile.input, ''],
            ['Output', profile.output, ''],
            // v0.9.27.0 — Phase 1: "Limit" relabeled to "Candidates" so the user
            // understands this is system-decided collection volume, not a knob
            // they control.
            ['Candidates', limitValue, Number(limitValue) >= 50 ? 'is-warn' : ''],
            ['Reliability / access note', profile.risk === 'high' ? 'Variable access · use specific public inputs' : (profile.risk === 'medium' ? 'Check filters and source availability' : 'Stable route'), riskClass]
        ];
        const grid = q(box, '[data-run-preflight-grid]');
        if (grid) grid.innerHTML = chips.map(([label, value, cls]) => `<span class="ves-run-preflight-chip ${cls || ''}"><b>${escapeHtml(label)}</b>${escapeHtml(value)}</span>`).join('');
        const note = q(box, '[data-run-preflight-note]');
        if (note) note.textContent = profile.note;
    };

    const renderTrendActiveSummary = (scope, form) => {
        const el = q(scope, '[data-trend-active-settings]');
        if (!el || !form) return;
        const chips = [];
        const chip = (label, value) => {
            value = String(value || '').trim();
            if (!value) return;
            chips.push(`<span class="ves-filter-chip"><b>${escapeHtml(label)}</b>${escapeHtml(value)}</span>`);
        };
        chip('Tema', q(form, '[name="trendCategory"]')?.value || 'sin tema');
        chip('Rango', q(form, '[name="trendRange"], [name="trendTimeRange"], [name="duration"]')?.selectedOptions?.[0]?.textContent || 'Últimos 7 días');
        chip('País', q(form, '[name="trendCountry"]')?.selectedOptions?.[0]?.textContent || 'Worldwide');
        const trendLangEl = q(form, '[name="trendLanguage"]');
        if (trendLangEl && trendLangEl.value) chip('Idioma', trendLangEl.selectedOptions?.[0]?.textContent || trendLangEl.value);
        chip('Desde', q(form, '[name="dateFromIso"]')?.value || 'sin fecha fija');
        el.innerHTML = chips.join('');
    };

    const updateFormModeUI = (widget, form, cfg, mode) => {
        const scope = form.closest('.ves-page') || widget;
        renderModeExplainer(scope, cfg, mode);
        renderActiveSettingsSummary(scope, form, cfg, mode);
        renderPlatformChecklist(scope, cfg, mode);
        renderRunPreflight(scope, form, cfg, mode);
    };
    const reflectTopbarRunState = (widget, type = 'info', html = '', spinner = false) => {
        const pill = widget?.querySelector?.('.ves-run-state[data-run-state]');
        if (!pill) return;
        const labelEl = pill.querySelector('.ves-run-state-label');
        const text = String(html || '').replace(/<[^>]+>/g, ' ').toLowerCase();
        let state = 'idle';
        let label = '';
        if (type === 'error' || /failed|error|fall[oó]|rechazad/.test(text)) {
            state = 'error'; label = 'Error';
        } else if (spinner || /iniciando|ejecutando|en curso|buscando|generando|running|queued|provider_running|normalizing|ai_processing|procesando/.test(text)) {
            state = 'running'; label = 'Ejecutando';
        } else if (/no results|no usable|sin resultados|no hay resultados|completed_no_usable|completed_no_usable_results/.test(text)) {
            state = 'success'; label = 'Sin resultados útiles';
        } else if (/low confidence|baja confianza|validation|validaci[oó]n|needs_validation|completed_low_confidence/.test(text)) {
            state = 'success'; label = 'Necesita validación';
        } else if (type === 'success' || /completed|completado|finalizado|finished|terminad|listo|lista/.test(text)) {
            state = 'success'; label = 'Completado';
        }
        if (state === 'idle') return;
        pill.removeAttribute('hidden');
        pill.classList.remove('is-running', 'is-success', 'is-error');
        pill.classList.add('is-' + state);
        if (labelEl) labelEl.textContent = label;
        if (state !== 'running') {
            clearTimeout(pill._vesStateTimer);
            pill._vesStateTimer = setTimeout(() => { pill.setAttribute('hidden', ''); }, 4500);
        }
    };

    const setStatus = (widget, html, type = 'info', spinner = false) => {
        // v0.9.9.2: target the active page's .ves-status when present.
        const activePage = widget.querySelector('.ves-page.is-active');
        const box = (activePage && activePage.querySelector('.ves-status')) || q(widget, '.ves-status');
        if (!box) return;
        box.className = 'ves-status show ' + type;
        box.innerHTML = `${spinner ? '<div class="ves-spinner"></div>' : ''}<div>${html}</div>`;
        const shouldScrollStatus = type === 'error' || /completad|terminó|finaliz|limitad|fall/i.test(String(html || ''));
        if (shouldScrollStatus) {
            setTimeout(() => { try { box.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (_) {} }, 40);
        }
        reflectTopbarRunState(widget, type, html, spinner);
    };

    const updateModeDependentFields = (widget, form, cfg, currentMode) => {
        if (!widget || !form || !cfg) return;
        const scope = form.closest('.ves-page') || widget;
        removeLegacyExtraFields(scope);
        const postUrlMode = isPostUrlMode(currentMode);
        const anyUrlMode = isUrlMode(currentMode);

        const targets = q(form, '[name="targets"]');
        if (targets) targets.placeholder = (cfg.placeholders && cfg.placeholders[currentMode]) ? cfg.placeholders[currentMode] : '';
        setText(scope, '.ves-target-hint', (cfg.hints && cfg.hints[currentMode]) ? cfg.hints[currentMode] : '');

        setDisplay(scope, '.ves-option-wrap', 'none');
        setDisplay(scope, '.ves-extra-row', 'none');
        setDisplay(scope, '.ves-extra-row-2', 'none');
        setDisplay(scope, '.ves-search-context-wrap', (anyUrlMode || cfg.showSearchContext === false) ? 'none' : '');
        setDisplay(scope, '.ves-date-from-row', postUrlMode ? 'none' : '');
        setDisplay(scope, '.ves-metric-row', (postUrlMode || !cfg.showMinViews) ? 'none' : '');
        setDisplay(scope, '.ves-country-language-row', postUrlMode ? 'none' : '');
        setDisplay(scope, '.ves-country-wrap', (!postUrlMode && cfg.showsCountry !== false) ? '' : 'none');
        setDisplay(scope, '.ves-language-wrap', (!postUrlMode && cfg.showsLanguage !== false) ? '' : 'none');
        setDisplay(scope, '.ves-minviews-wrap', (!postUrlMode && cfg.showMinViews) ? '' : 'none');
        setDisplay(scope, '.ves-minlikes-wrap', 'none');
        setDisplay(scope, '.ves-row-3', postUrlMode ? 'none' : '');
        setDisplay(scope, '.ves-sort-priority-wrap', 'none');
        const platformFieldForMode = q(form, '[name="platform"]');
        const currentPlatformForMode = platformFieldForMode ? platformFieldForMode.value : '';
        setDisplay(scope, '.ves-reddit-options', currentPlatformForMode === 'reddit' ? '' : 'none');
        setDisplay(scope, '.ves-pinterest-options', currentPlatformForMode === 'pinterest' ? '' : 'none');
        setDisplay(scope, '.ves-semrush-options', currentPlatformForMode === 'semrush' ? '' : 'none');
        scope.querySelectorAll('[data-semrush-panel]').forEach((panel) => {
            if (currentPlatformForMode !== 'semrush') {
                panel.style.display = 'none';
                return;
            }
            const modes = String(panel.getAttribute('data-semrush-panel') || '').split(/\s+/).filter(Boolean);
            panel.style.display = modes.includes(currentMode) ? '' : 'none';
        });
        setDisplay(scope, '.ves-moz-options', currentPlatformForMode === 'moz' ? '' : 'none');
        setDisplay(scope, '.ves-ahrefs-options', currentPlatformForMode === 'ahrefs' ? '' : 'none');

        const optionEl = q(form, '[name="option"]');
        if (optionEl) optionEl.value = 'ALL';
        const extraSelect1 = q(form, '[name="extraSelect1"]');
        if (extraSelect1) extraSelect1.value = 'ALL';
        const extraSelect2 = q(form, '[name="extraSelect2"]');
        if (extraSelect2) extraSelect2.value = '';
        const extraNumber1 = q(form, '[name="extraNumber1"]');
        if (extraNumber1) extraNumber1.value = '';
        const extraCheckbox1 = q(form, '[name="extraCheckbox1"]');
        if (extraCheckbox1) { if (extraCheckbox1.type === 'checkbox') extraCheckbox1.checked = false; else extraCheckbox1.value = ''; }
        const extraCheckbox2 = q(form, '[name="extraCheckbox2"]');
        if (extraCheckbox2) { if (extraCheckbox2.type === 'checkbox') extraCheckbox2.checked = false; else extraCheckbox2.value = ''; }
        const includeTranscript = q(form, '[name="includeTranscript"]');
        if (includeTranscript) { if (includeTranscript.type === 'checkbox') includeTranscript.checked = true; else includeTranscript.value = '1'; }

        if (postUrlMode) {
            const dateFrom = q(form, '[name="dateFromIso"]');
            if (dateFrom) dateFrom.value = '';
            const datePreset = q(form, '[name="datePreset"]');
            if (datePreset) datePreset.value = 'any';
            const minViews = q(form, '[name="minViews"]');
            if (minViews) minViews.value = '';
            const minLikes = q(form, '[name="minLikes"]');
            if (minLikes) minLikes.value = '';
            const country = q(form, '[name="country"]');
            if (country) country.value = 'None';
            const language = q(form, '[name="language"]');
            if (language) language.value = '';
        }
        if (anyUrlMode) {
            const context = q(form, '[name="searchContext"]');
            if (context) context.value = '';
        }
        updateFormModeUI(widget, form, cfg, currentMode);
    };


    const applyPlatform = (widget, platform) => {
        const cfg = CONFIG[platform];
        // v0.9.9.2: prefer the form inside the active page so multi-page
        // shells correctly retarget when switching between Social/Trend/Ads.
        const activePage = widget.querySelector('.ves-page.is-active');
        const form = (activePage && activePage.querySelector('.ves-form')) || q(widget, '.ves-form');
        const state = widgetState.get(widget);
        if (!cfg || !form || !state) return;
        // Keep state.form pointing at whatever form we're acting on.
        state.form = form;
        // v0.9.9.2: scope all toggles to the active page when present, so
        // hidden pages don't get stale display state.
        const scope = activePage || widget;
        removeLegacyExtraFields(scope);

        try {
            form.reset();
        } catch (e) {}

        const platformField = q(form, '[name="platform"]');
        if (platformField) {
            platformField.value = platform;
            // v0.9.9.3: form.reset() snaps inputs back to their HTML default.
            // Update defaultValue so future resets keep the correct platform.
            try { platformField.defaultValue = platform; } catch (e) {}
        }
        // v0.9.9.3: only toggle the active class on tabs WITHIN the active
        // page. Previous version queried widget.querySelectorAll('.ves-tab')
        // which stripped active state from tabs in other pages, breaking the
        // platform routing when the user switched sub-pages.
        scope.querySelectorAll('.ves-tab').forEach((t) => t.classList.toggle('active', t.dataset.platform === platform));

        const modeWrap = q(scope, '.ves-mode-wrap');
        const modeSelect = q(form, '[name="mode"]');
        if (modeSelect) {
            if (cfg.modes && cfg.modes.length) {
                if (modeWrap) modeWrap.style.display = '';
                setOptions(modeSelect, cfg.modes, cfg.defaultMode);
            } else {
                if (modeWrap) modeWrap.style.display = 'none';
                setOptions(modeSelect, [{ v: cfg.defaultMode, l: cfg.defaultMode }], cfg.defaultMode);
            }
        }

        const currentMode = (modeSelect && modeSelect.value) ? modeSelect.value : cfg.defaultMode;
        // v0.9.9.5: only re-populate options for the regular scraper form. The
        // trend-finder form is rendered fully by its own template with a richer
        // country list and pre-selected defaults; rebuilding them here would
        // wipe the user's selection on every applyPlatform pass.
        if (!cfg.isTrendFinder) {
            // v0.9.27.0 — Phase 1: only populate options on the limit field if it's
            // actually a <select> in the DOM (admin debug mode). The hidden-input
            // form factor is the new normal — setOptions on a hidden input would
            // do nothing useful and pollute the form.
            const limitField = q(form, '[name="limit"]');
            if (limitField && limitField.tagName === 'SELECT') {
                setOptions(limitField, LIMIT_PRESETS, cfg.defaultLimit || 20);
            } else if (limitField && limitField.tagName === 'INPUT' && !limitField.value) {
                limitField.value = String(cfg.defaultLimit || 50);
            }
            setOptions(q(form, '[name="datePreset"]'), cfg.datePresets || DATE_PRESETS, 'any');
            setOptions(q(form, '[name="country"]'), COUNTRY_PRESETS, cfg.countryDefault || 'None');
            setOptions(q(form, '[name="language"]'), LANGUAGE_PRESETS, 'es');
            setOptions(q(form, '[name="sortPriority"]'), [
                { v:'relevance', l:'Mejor coincidencia' },
                { v:'latest', l:'Más reciente' },
                { v:'top_metric', l:'Más ' + (cfg.primaryMetricLabel || 'views') },
                { v:'comments', l:'Más comentarios' }
            ], 'relevance');
        }
        const optionField = q(form, '[name="option"]');
        if (optionField) optionField.value = 'ALL';
        setDisplay(scope, '.ves-option-wrap', 'none');
        setDisplay(scope, '.ves-reddit-options', platform === 'reddit' ? '' : 'none');
        setDisplay(scope, '.ves-pinterest-options', platform === 'pinterest' ? '' : 'none');
        setDisplay(scope, '.ves-semrush-options', platform === 'semrush' ? '' : 'none');
        scope.querySelectorAll('[data-semrush-panel]').forEach((panel) => {
            if (platform !== 'semrush') {
                panel.style.display = 'none';
                return;
            }
            const modes = String(panel.getAttribute('data-semrush-panel') || '').split(/\s+/).filter(Boolean);
            panel.style.display = modes.includes(currentMode) ? '' : 'none';
        });
        setDisplay(scope, '.ves-moz-options', platform === 'moz' ? '' : 'none');
        setDisplay(scope, '.ves-ahrefs-options', platform === 'ahrefs' ? '' : 'none');

        setText(scope, '.ves-targets-label', cfg.targetsLabel || 'Objetivos');
        const targets = q(form, '[name="targets"]');
        if (targets) targets.placeholder = (cfg.placeholders && cfg.placeholders[currentMode]) ? cfg.placeholders[currentMode] : '';
        setText(scope, '.ves-target-hint', (cfg.hints && cfg.hints[currentMode]) ? cfg.hints[currentMode] : '');
        // v0.9.27.0 — Phase 1: ".ves-limit-label" label setter removed.
        // Visible Cantidad / limit selects no longer exist in normal user UI.
        setText(scope, '.ves-date-label', 'Fecha');
        const minMetricLabel = cfg.primaryMetricLabel || 'views';
        setText(scope, '.ves-minviews-label', 'Mínimo ' + minMetricLabel);
        setText(scope, '.ves-minviews-hint', 'Vacío o 0 = sin filtro.');
        setText(scope, '.ves-country-label', 'País');
        setText(scope, '.ves-language-label', 'Idioma');
        setText(scope, '.ves-transcript-label', 'Incluir transcripción o subtítulos cuando estén disponibles');
        setText(scope, '.ves-transcript-hint', 'Se solicita automáticamente cuando la plataforma lo soporta.');

        const trendFields = q(scope, '.ves-trend-fields');
        const targetsField = closestField(targets);
        if (cfg.isTrendFinder) {
            if (modeWrap) modeWrap.style.display = 'none';
            if (targetsField) targetsField.style.display = 'none';
            setDisplay(scope, '.ves-country-wrap', 'none');
            setDisplay(scope, '.ves-language-wrap', 'none');
            setDisplay(scope, '.ves-transcript-wrap', 'none');
            setDisplay(scope, '.ves-minviews-wrap', 'none');
            setDisplay(scope, '.ves-minlikes-wrap', 'none');
            setDisplay(scope, '.ves-row-3', '');
            if (trendFields) trendFields.style.display = '';
            renderTrendActiveSummary(scope, form);
        } else {
            if (targetsField) targetsField.style.display = '';
            setDisplay(scope, '.ves-country-wrap', cfg.showsCountry === false ? 'none' : '');
            setDisplay(scope, '.ves-language-wrap', cfg.showsLanguage === false ? 'none' : '');
            const languageControl = q(form, '[name="language"]');
            if (languageControl && cfg.showsLanguage === false) languageControl.value = '';
            else if (languageControl && !languageControl.value) languageControl.value = 'es';
            setDisplay(scope, '.ves-transcript-wrap', 'none');
            setDisplay(scope, '.ves-minviews-wrap', cfg.showMinViews ? '' : 'none');
            setDisplay(scope, '.ves-minlikes-wrap', 'none');
            setDisplay(scope, '.ves-row-3', '');
            if (trendFields) trendFields.style.display = 'none';
            updateModeDependentFields(widget, form, cfg, currentMode);
        }
        const includeTranscript = q(form, '[name="includeTranscript"]');
        if (includeTranscript) { if (includeTranscript.type === 'checkbox') includeTranscript.checked = true; else includeTranscript.value = '1'; }

        state.lastData = null;
        state.filteredItems = [];
        state.panelHtml = '';
        state.currentRunId = null;
        state.dashboardComparison = null;
        state.dashboardSelected = [];
        state.currentBrief = null;
        const results = q(scope, '.ves-results');
        if (results) {
            results.classList.remove('show');
            results.innerHTML = '';
        }
        const dashboardHost = q(scope, '.ves-trend-dashboard');
        if (dashboardHost) {
            if (cfg.isTrendFinder) {
                dashboardHost.style.display = '';
                renderTrendDashboard(widget, Array.isArray(state.dashboardData) ? state.dashboardData : [], state.dashboardFilters || {
                    topic: q(form, '[name="trendCategory"]')?.value || '',
                    country: q(form, '[name="trendCountry"]')?.value || 'None',
                    duration: q(form, '[name="trendRange"], [name="trendTimeRange"], [name="duration"]')?.value || '7d',
                    date_from: '',
                    date_to: '',
                    limit: 24,
                }, state.dashboardComparison || null);
                if (!state.monitorsLoaded && typeof loadTrendMonitors === 'function') { loadTrendMonitors(widget, true); }
            } else {
                dashboardHost.style.display = 'none';
                dashboardHost.innerHTML = '';
            }
        }
        const statusBox = q(widget, '.ves-status');
        if (statusBox) statusBox.className = 'ves-status';
    };



    const collectProjectContextPayload = (widget) => {
        const payload = {};
        ['brand_name','website_url','brand_summary','products_services','target_audience','primary_goal','tone_of_voice','markets','competitors','notes'].forEach((key) => {
            const el = widget.querySelector(`.ves-project-context-form [name="${key}"]`);
            payload[key] = el ? (el.value || '') : '';
        });
        return payload;
    };

    const fillProjectContextForm = (widget, context = {}) => {
        ['brand_name','website_url','brand_summary','products_services','target_audience','primary_goal','tone_of_voice','markets','competitors','notes'].forEach((key) => {
            const el = widget.querySelector(`.ves-project-context-form [name="${key}"]`);
            if (el) el.value = context[key] || '';
        });
        const label = widget.querySelector('[data-current-project-label]');
        if (label) {
            const projectLabel = context.project_label || context.brand_name || 'Default Project';
            label.textContent = `Project: ${projectLabel}`;
        }
    };

    const loadProjectContext = async (widget, silent = false) => {
        const state = widgetState.get(widget);
        if (!widget.querySelector('.ves-project-context-form')) return;
        try {
            const payload = await ajaxProjectContextGet(state.form);
            state.projectContext = payload.context || {};
            fillProjectContextForm(widget, state.projectContext);
            if (!silent) setStatus(widget, '✅ Contexto del proyecto cargado.', 'success');
        } catch (err) {
            if (!silent) setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo cargar el contexto del proyecto.', widget)), 'error');
        }
    };

    const bindProjectContextControls = (widget) => {
        const saveBtn = widget.querySelector('.ves-project-context-save');
        const refreshBtn = widget.querySelector('.ves-project-context-refresh');
        const statusBadge = widget.querySelector('[data-context-status]');
        const showSavedBadge = () => {
            if (!statusBadge) return;
            statusBadge.removeAttribute('hidden');
            clearTimeout(statusBadge._hideTimer);
            statusBadge._hideTimer = setTimeout(() => statusBadge.setAttribute('hidden', ''), 2400);
        };
        if (saveBtn) {
            saveBtn.addEventListener('click', async () => {
                const state = widgetState.get(widget);
                const original = saveBtn.textContent;
                saveBtn.disabled = true;
                saveBtn.textContent = 'Guardando…';
                try {
                    setStatus(widget, '<strong>Guardando contexto del proyecto…</strong>', 'info', true);
                    const payload = await ajaxProjectContextSave(state.form, collectProjectContextPayload(widget));
                    state.projectContext = payload.context || {};
                    fillProjectContextForm(widget, state.projectContext);
                    setStatus(widget, '✅ Contexto guardado. La IA lo usará cuando sea relevante.', 'success');
                    showSavedBadge();
                    if (typeof showToast === 'function') showToast(widget, 'Contexto del proyecto guardado', 'success', 2400);
                } catch (err) {
                    setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo guardar el contexto del proyecto.', widget)), 'error');
                    if (typeof showToast === 'function') showToast(widget, safeUserMessage(err.message || 'No se pudo guardar el contexto', widget), 'error', 3500);
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.textContent = original;
                }
            });
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', async () => { await loadProjectContext(widget, false); });
        }
    };

    const renderWorkspaceKnowledgeList = (widget, items = []) => {
        const host = widget.querySelector('.ves-knowledge-list');
        if (!host) return;
        const list = Array.isArray(items) ? items : [];
        if (!list.length) {
            host.innerHTML = '<div class="ves-empty">Aún no has guardado knowledge assets. Sube un archivo o ingiere una URL de marca para enriquecer las próximas respuestas.</div>';
            return;
        }
        host.innerHTML = `<div class="ves-knowledge-grid">${list.map((item) => {
            const href = safeUrl(item.source_url || item.file_url || '');
            const tags = Array.isArray(item.tags) ? item.tags : [];
            return `<div class="ves-knowledge-item" data-knowledge-id="${escapeHtml(item.id || '')}">
                <div class="ves-knowledge-head">
                    <div>
                        <div class="ves-knowledge-title">${escapeHtml(item.title || 'Knowledge item')}</div>
                        <div class="ves-meta">${escapeHtml(item.source_type || '')}${item.parse_status ? ` · ${escapeHtml(item.parse_status)}` : ''}${item.created_at ? ` · ${escapeHtml(item.created_at)}` : ''}</div>
                    </div>
                    <button type="button" class="ves-btn ves-btn-danger ves-knowledge-delete" data-knowledge-id="${escapeHtml(item.id || '')}">Eliminar</button>
                </div>
                <div class="ves-knowledge-summary">${escapeHtml(item.summary_text || item.summary || 'Sin resumen.')}</div>
                ${href ? `<div class="ves-knowledge-link"><a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer">Ver fuente</a></div>` : ''}
                ${tags.length ? `<div class="ves-plan-chip-row">${tags.slice(0,8).map((tag) => `<span class="ves-chip">${escapeHtml(tag)}</span>`).join('')}</div>` : ''}
            </div>`;
        }).join('')}</div>`;
    };

    const loadWorkspaceKnowledge = async (widget, silent = false) => {
        const state = widgetState.get(widget);
        const host = widget.querySelector('.ves-knowledge-list');
        if (!host) return;
        try {
            const payload = await ajaxWorkspaceKnowledgeList(state.form);
            state.workspaceKnowledge = Array.isArray(payload.items) ? payload.items : [];
            renderWorkspaceKnowledgeList(widget, state.workspaceKnowledge);
            if (!silent) setStatus(widget, '✅ Workspace knowledge actualizado.', 'success');
        } catch (err) {
            if (!silent) setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo cargar el workspace knowledge.', widget)), 'error');
        }
    };

    const bindWorkspaceKnowledgeControls = (widget) => {
        const uploadBtn = widget.querySelector('.ves-knowledge-upload');
        const ingestBtn = widget.querySelector('.ves-knowledge-ingest');
        if (uploadBtn) {
            uploadBtn.addEventListener('click', async () => {
                const state = widgetState.get(widget);
                const fileInput = widget.querySelector('[name="knowledge_file"]');
                const titleInput = widget.querySelector('[name="knowledge_title"]');
                const file = fileInput?.files?.[0];
                if (!file) {
                    setStatus(widget, 'Selecciona un archivo antes de subirlo.', 'error');
                    return;
                }
                try {
                    setStatus(widget, '<strong>Subiendo knowledge asset…</strong>', 'info', true);
                    const payload = await ajaxWorkspaceKnowledgeUpload(state.form, file, titleInput?.value || '');
                    state.workspaceKnowledge = Array.isArray(payload.items) ? payload.items : [];
                    renderWorkspaceKnowledgeList(widget, state.workspaceKnowledge);
                    if (fileInput) fileInput.value = '';
                    if (titleInput) titleInput.value = '';
                    setStatus(widget, '✅ Archivo de knowledge guardado. La IA podrá usarlo cuando encaje con la consulta.', 'success');
                } catch (err) {
                    setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo subir el archivo.', widget)), 'error');
                }
            });
        }
        if (ingestBtn) {
            ingestBtn.addEventListener('click', async () => {
                const state = widgetState.get(widget);
                const urlInput = widget.querySelector('[name="knowledge_url"]');
                const titleInput = widget.querySelector('[name="knowledge_url_title"]');
                const url = urlInput?.value || '';
                if (!url) {
                    setStatus(widget, 'Introduce una URL válida para ingerir.', 'error');
                    return;
                }
                try {
                    setStatus(widget, '<strong>Ingeriendo URL de marca…</strong>', 'info', true);
                    const payload = await ajaxWorkspaceKnowledgeIngest(state.form, { url, title: titleInput?.value || '' });
                    state.workspaceKnowledge = Array.isArray(payload.items) ? payload.items : [];
                    renderWorkspaceKnowledgeList(widget, state.workspaceKnowledge);
                    if (urlInput) urlInput.value = '';
                    if (titleInput) titleInput.value = '';
                    setStatus(widget, '✅ URL ingerida. Su perfil ya puede enriquecer próximos análisis.', 'success');
                } catch (err) {
                    setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo ingerir la URL.', widget)), 'error');
                }
            });
        }
        widget.addEventListener('click', async (ev) => {
            const btn = ev.target.closest('.ves-knowledge-delete');
            if (!btn) return;
            ev.preventDefault();
            const state = widgetState.get(widget);
            const id = btn.getAttribute('data-knowledge-id') || '';
            if (!id) return;
            if (!window.confirm('¿Eliminar este knowledge asset del workspace?')) return;
            try {
                const payload = await ajaxWorkspaceKnowledgeDelete(state.form, id);
                state.workspaceKnowledge = Array.isArray(payload.items) ? payload.items : [];
                renderWorkspaceKnowledgeList(widget, state.workspaceKnowledge);
                setStatus(widget, '✅ Knowledge asset eliminado.', 'success');
            } catch (err) {
                setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo eliminar el asset.', widget)), 'error');
            }
        });
    };

    /* v0.9.16.15: derive the active scraper platform without snapping back
       to the page default after the user switches tabs. Active UI state wins;
       data-default-platform is only the last fallback for cold initial render. */
    const derivePagePlatform = (page, form) => {
        if (!page) return '';
        const activeTab = page.querySelector('.ves-tab.active[data-platform]');
        if (activeTab && activeTab.dataset.platform) return activeTab.dataset.platform;
        const platformInput = (form || page).querySelector('[name="platform"]');
        if (platformInput && platformInput.value) return platformInput.value;
        const firstTab = page.querySelector('.ves-tab[data-platform]:not(.is-disabled):not([disabled])');
        if (firstTab && firstTab.dataset.platform) return firstTab.dataset.platform;
        const fromAttr = page.dataset.defaultPlatform;
        return fromAttr || '';
    };

    /* v0.9.9.2: helper used by switchPage to re-point the active form on
       sub-page changes within scrapers mode. */
    let bindActiveScraperForm = null;

    const setupWidget = (widget) => {
        if (!widget || widget.dataset.ready === '1') return;
        // v0.9.9.2: there can be 0, 1, or many .ves-form inside a widget. The
        // panel-mode page has a hidden form for nonces; scraper-mode has 3.
        // We pick the form that lives inside .ves-page.is-active first; fall
        // back to the first form for the legacy single-form layout.
        const pickActiveForm = () => {
            const activePage = widget.querySelector('.ves-page.is-active');
            return activePage?.querySelector('.ves-form') || widget.querySelector('.ves-form');
        };
        const form = pickActiveForm();
        if (!form) return;
        widget.dataset.ready = '1';
        try {
            widgetState.set(widget, { currentRunId:null, currentRunTipo:'standard', form, lastData:null, filteredItems:[], selectedItemKeys:[], panelHtml:'', dashboardData:[], dashboardFilters:null, dashboardComparison:null, dashboardSelected:[], memoryActivity:[], memoryEntries:[], memoryCounts:{}, memoryFilters:null, memorySelectedId:0, memoryGoogleItems:[], monitors:[], monitorsLoaded:false, projectContext:null, workspaceKnowledge:[] });

            // v0.9.9.2: bindings need to scope to the active page's form so a
            // click on a TikTok tab in the Social page doesn't toggle a tab in
            // the Ads page. We re-run the binding whenever the active page changes.
            bindActiveScraperForm = (widget, activePage, activeForm) => {
                removeLegacyExtraFields(activePage || widget);
                if (!activePage || !activeForm) return;
                if (activePage.dataset.scraperBound === '1') {
                    // Already bound — re-point state.form. Only re-apply the
                    // platform if the hidden input has somehow drifted, so we
                    // don't wipe user-entered form values on every page switch.
                    const st = widgetState.get(widget);
                    if (st) st.form = activeForm;
                    const expected = derivePagePlatform(activePage, activeForm);
                    const current = activeForm.querySelector('[name="platform"]')?.value || '';
                    if (expected && current !== expected) {
                        applyPlatform(widget, expected);
                    }
                    return;
                }
                activePage.dataset.scraperBound = '1';
                activePage.querySelectorAll('.ves-tab').forEach((tab) => {
                    if (tab.disabled || tab.classList.contains('is-disabled')) return;
                    tab.addEventListener('click', () => {
                        const platform = tab.dataset.platform || 'tiktok';
                        // Update active state within this page only
                        activePage.querySelectorAll('.ves-tab').forEach((t) => t.classList.toggle('active', t === tab));
                        applyPlatform(widget, platform);
                    });
                });
                const modeEl = q(activeForm, '[name="mode"]');
                if (modeEl) {
                    modeEl.addEventListener('change', () => {
                        const platform = q(activeForm, '[name="platform"]')?.value || 'tiktok';
                        const mode = q(activeForm, '[name="mode"]')?.value || 'search';
                        const cfg = CONFIG[platform] || CONFIG.tiktok;
                        updateModeDependentFields(widget, activeForm, cfg, mode);
                    });
                }
                const refreshFormSummary = () => {
                    const platform = q(activeForm, '[name="platform"]')?.value || derivePagePlatform(activePage, activeForm) || 'tiktok';
                    if (platform === 'trend_finder') {
                        renderTrendActiveSummary(activePage, activeForm);
                        return;
                    }
                    if (platform === 'google_intel') {
                        renderGoogleActiveSummary(activePage, activeForm);
                        return;
                    }
                    const cfg = CONFIG[platform] || CONFIG.tiktok;
                    const mode = q(activeForm, '[name="mode"]')?.value || cfg.defaultMode || 'search';
                    renderActiveSettingsSummary(activePage, activeForm, cfg, mode);
                    renderPlatformChecklist(activePage, cfg, mode);
                    renderRunPreflight(activePage, activeForm, cfg, mode);
                };
                // Date quickset buttons (v0.9.9.2)
                activePage.querySelectorAll('[data-date-quickset]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const days = btn.dataset.dateQuickset;
                        const input = activePage.querySelector('.ves-date-from-input');
                        if (!input) return;
                        if (days === '' || days == null) {
                            input.value = '';
                        } else {
                            const d = new Date();
                            d.setDate(d.getDate() - parseInt(days, 10));
                            input.value = d.toISOString().slice(0, 10);
                        }
                        refreshFormSummary();
                    });
                });
                activeForm.querySelectorAll('input, select, textarea').forEach((field) => {
                    field.addEventListener('input', refreshFormSummary);
                    field.addEventListener('change', refreshFormSummary);
                });
                // Update widget-state form pointer + apply current platform.
                const st = widgetState.get(widget);
                if (st) st.form = activeForm;
                const platform = derivePagePlatform(activePage, activeForm);
                if (platform === 'google_intel') {
                    bindGoogleFormControls(widget, activePage, activeForm);
                } else if (platform) {
                    applyPlatform(widget, platform);
                }
            };

            // Initial bind for the page that's already active.
            const activePage = widget.querySelector('.ves-page.is-active');
            if (activePage) {
                bindActiveScraperForm(widget, activePage, activePage.querySelector('.ves-form') || form);
            } else {
                // Panel-mode (no scraper sub-pages): keep legacy single-form binding.
                widget.querySelectorAll('.ves-tab').forEach((tab) => tab.addEventListener('click', () => applyPlatform(widget, tab.dataset.platform || 'tiktok')));
                const modeEl = q(form, '[name="mode"]');
                if (modeEl) {
                    modeEl.addEventListener('change', () => {
                        const platform = q(form, '[name="platform"]')?.value || 'tiktok';
                        const mode = q(form, '[name="mode"]')?.value || 'search';
                        const cfg = CONFIG[platform] || CONFIG.tiktok;
                        updateModeDependentFields(widget, form, cfg, mode);
                    });
                }
                applyPlatform(widget, q(widget, '.ves-tab.active')?.dataset.platform || 'tiktok');
            }

            bindProjectContextControls(widget);
            bindWorkspaceKnowledgeControls(widget);
            loadProjectContext(widget, true);
            loadWorkspaceKnowledge(widget, true);
            if (typeof refreshUsageSummary === 'function') refreshUsageSummary(widget);
            setupUILanguageSwitcher(widget);
            setupShellV3(widget);
            setTimeout(() => { if (!isProductionMvpWidget(widget) && widget.dataset.activePage === 'brand-audit') checkBrandAuditActiveRuns(widget, true); }, 350);
        } catch (err) {
            widget.dataset.ready = '0';
            console.error('[VES] setupWidget failed', err);
            setStatus(widget, '⚠️ La interfaz no se pudo inicializar completamente. Recarga la página. Si persiste, instala el hotfix más reciente.', 'error');
        }
    };

    /* ===== v0.9.8.0 — Sidebar nav + command palette + dark mode + toasts ===== */
    /* v0.9.9.2: support two modes — "scrapers" (social/trend/ads) and "panel"
       (knowledge/memory/usage). Each widget declares its mode via data-shell-mode. */
    const PAGE_STORAGE_KEY_SCRAPERS = 'ves_active_page_scrapers_v1';
    const PAGE_STORAGE_KEY_PANEL = 'ves_active_page_panel_v1';
    const PAGE_STORAGE_KEY_APP = 'fiis_active_route_v1';
    const THEME_STORAGE_KEY = 'ves_theme_v1';
    const SIDEBAR_STORAGE_KEY = 'ves_sidebar_collapsed_v1';
    // v1.1.0 — UNIFIED APP ROUTE MAP. One shell, one set of routes; the old
    // panel/scrapers split is gone. APP_PAGES is the single source of truth for
    // which routes the sidebar can switch between. Legacy const names are kept
    // as aliases so any downstream reference keeps resolving.
    const APP_PAGES = new Set(['dashboard', 'projects', 'memory', 'knowledge', 'usage',
        'social', 'linkedin', 'seo', 'google', 'trend', 'deep-trend',
        'brand-audit', 'ads', 'creative', 'reports', 'brief-library', 'evidence']);
    const MVP_APP_PAGES = new Set(['dashboard', 'social', 'trend', 'google', 'memory', 'usage', 'knowledge']);
    const SCRAPER_RUN_PAGES = new Set(['social', 'linkedin', 'seo', 'trend', 'brand-audit', 'google', 'ads']);
    const MVP_SCRAPER_RUN_PAGES = new Set(['social', 'trend', 'google']);
    // Back-compat aliases (referenced by older code paths).
    const SCRAPER_PAGES = APP_PAGES;
    const MVP_SCRAPER_PAGES = MVP_APP_PAGES;
    const PANEL_PAGES = APP_PAGES;
    const VALID_PAGES = new Set([...APP_PAGES, 'scrapers']);
    const PAGE_LABELS = {
        dashboard: 'Dashboard',
        projects: 'Projects',
        social: 'Social Media',
        linkedin: 'LinkedIn',
        seo: 'SEO/SEM/AEO',
        trend: 'Trend Finder',
        'deep-trend': 'Deep Trend Finder',
        'brand-audit': 'Brand Deep Audit',
        creative: 'Creative Intelligence',
        google: 'Google Intelligence',
        ads: 'Ads Intelligence',
        knowledge: 'Knowledge base',
        memory: 'Memory',
        usage: 'Usage',
        reports: 'Reports',
        'brief-library': 'Brief Library',
        evidence: 'Evidence Library',
        // legacy fallback for users coming from the older shell
        scrapers: 'Intelligence Suite',
    };
    const MVP_DISABLED_MESSAGE = 'Este módulo no está habilitado en el MVP de producción.';
    const MVP_DISABLED_PAGES = new Set(['linkedin', 'seo', 'brand-audit', 'creative', 'ads']);
    const MVP_DISABLED_PLATFORMS = new Set(['linkedin', 'facebook_ads', 'google_ads', 'semrush', 'moz', 'ahrefs']);
    const MVP_DISABLED_GOOGLE_MODULES = new Set(['news', 'trends', 'images', 'maps', 'crawler']);
    const isProductionMvpWidget = (widget) => widget?.dataset?.productionMvp === '1';
    // Unified: route set no longer depends on a shell mode — one app, one map.
    const widgetPagesFor = (widget) => isProductionMvpWidget(widget) ? MVP_APP_PAGES : APP_PAGES;
    const widgetRunPagesFor = (widget) => isProductionMvpWidget(widget) ? MVP_SCRAPER_RUN_PAGES : SCRAPER_RUN_PAGES;
    const widgetStorageKey = () => PAGE_STORAGE_KEY_APP;

    const safeStorage = {
        get(key) { try { return window.localStorage?.getItem(key); } catch (e) { return null; } },
        set(key, val) { try { window.localStorage?.setItem(key, val); } catch (e) {} },
        remove(key) { try { window.localStorage?.removeItem(key); } catch (e) {} },
    };

    /* Toast helper */
    const showToast = (widget, message, type = 'info', timeout = 3500) => {
        const host = widget.querySelector('.ves-toast-host');
        if (!host) return;
        const toast = document.createElement('div');
        toast.className = 'ves-toast is-' + (type === 'success' || type === 'error' ? type : 'info');
        const icoSvg = type === 'success'
            ? '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
            : type === 'error'
                ? '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>'
                : '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>';
        toast.innerHTML = `<span class="ves-toast-ico">${icoSvg}</span><div class="ves-toast-body">${escapeHtml(message)}</div>`;
        host.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(20px)';
            toast.style.transition = 'opacity .2s ease, transform .2s ease';
            setTimeout(() => toast.remove(), 220);
        }, timeout);
    };

    /* Theme management removed: fixed minimal palette. */
    const initTheme = (widget) => {
        if (!widget) return;
        widget.removeAttribute("data-theme");
    };

    /* Sidebar collapse + mobile toggle */
    const initSidebar = (widget) => {
        const stored = safeStorage.get(SIDEBAR_STORAGE_KEY);
        if (stored === '1') widget.classList.add('is-sidebar-collapsed');
        const collapseBtn = widget.querySelector('.ves-sidebar-collapse');
        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => {
                widget.classList.toggle('is-sidebar-collapsed');
                safeStorage.set(SIDEBAR_STORAGE_KEY, widget.classList.contains('is-sidebar-collapsed') ? '1' : '0');
            });
        }
        const mobileToggle = widget.querySelector('.ves-sidebar-toggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => widget.classList.toggle('is-sidebar-open'));
        }
        // Close mobile sidebar after nav-item click
        widget.querySelectorAll('.ves-nav-item').forEach((btn) => {
            btn.addEventListener('click', () => widget.classList.remove('is-sidebar-open'));
        });
    };

    /* User menu */
    const initUserMenu = (widget) => {
        const trigger = widget.querySelector('.ves-user-avatar');
        const dropdown = widget.querySelector('.ves-user-dropdown');
        if (!trigger || !dropdown) return;
        const setOpen = (open) => {
            if (open) {
                dropdown.removeAttribute('hidden');
                trigger.setAttribute('aria-expanded', 'true');
            } else {
                dropdown.setAttribute('hidden', '');
                trigger.setAttribute('aria-expanded', 'false');
            }
        };
        trigger.addEventListener('click', (ev) => {
            ev.stopPropagation();
            setOpen(dropdown.hasAttribute('hidden'));
        });
        document.addEventListener('click', (ev) => {
            if (!widget.contains(ev.target)) return;
            if (!trigger.contains(ev.target) && !dropdown.contains(ev.target)) setOpen(false);
        });
        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') setOpen(false);
        });
    };

    /* Page switching */
    const switchPage = (widget, page) => {
        if (!widget) return;
        const allowed = widgetPagesFor(widget);
        if (!allowed.has(page)) return;
        widget.querySelectorAll('.ves-page').forEach((p) => {
            const match = p.dataset.page === page;
            p.classList.toggle('is-active', match);
            if (match) p.removeAttribute('hidden');
            else p.setAttribute('hidden', '');
        });
        widget.querySelectorAll('.ves-nav-item').forEach((btn) => {
            const match = btn.dataset.page === page;
            btn.classList.toggle('is-active', match);
            if (btn.tagName === 'BUTTON') {
                btn.setAttribute('aria-selected', match ? 'true' : 'false');
                btn.setAttribute('tabindex', match ? '0' : '-1');
            }
        });
        const labelEl = widget.querySelector('[data-page-label]');
        if (labelEl) labelEl.textContent = PAGE_LABELS[page] || 'Workspace';
        widget.dataset.activePage = page;
        safeStorage.set(widgetStorageKey(widget), page);
        if (page === 'memory' && widget.dataset.memoryLoaded !== '1') {
            maybeLoadMemoryPage(widget);
        }
        if (page === 'usage' && typeof refreshUsageSummary === 'function') {
            try { refreshUsageSummary(widget); } catch (e) {}
        }
        if (page === 'brief-library') {
            try { maybeLoadBriefLibraryPage(widget); } catch (e) {}
        }
        if (!isProductionMvpWidget(widget) && page === 'brand-audit') {
            try { setTimeout(() => checkBrandAuditActiveRuns(widget, true), 250); } catch (e) {}
        }
        // v0.9.9.2: when switching scraper sub-pages, the active form (and its
        // platform tab) needs to be re-bound so that ves-start/abort/etc. reach
        // the right form. setupShellV3 handles initial wiring; this re-points.
        if (widgetRunPagesFor(widget).has(page)) {
            const activePage = widget.querySelector('.ves-page.is-active');
            const activeForm = activePage?.querySelector('.ves-form');
            if (activeForm && typeof bindActiveScraperForm === 'function') {
                bindActiveScraperForm(widget, activePage, activeForm);
            }
        }
        if (typeof applyUILanguage === 'function') applyUILanguage(widget);
    };


    function renderBriefLibraryHost(widget, payload) {
        const page = widget.querySelector('.ves-page[data-page="brief-library"]');
        const host = page?.querySelector('[data-brief-library-host]');
        if (!host) return;
        const items = Array.isArray(payload?.items) ? payload.items : [];
        if (!items.length) {
            host.innerHTML = '<div class="ves-card"><div class="ves-hint">No hay briefs para estos filtros todavía.</div></div>';
            return;
        }
        host.innerHTML = `<div class="ves-evidence-card-grid">${items.map((brief) => {
            const status = escapeHtml(brief.status || 'draft');
            const type = escapeHtml(brief.brief_type || 'campaign');
            const createdBy = escapeHtml(brief.created_by || '');
            const refs = Array.isArray(brief.evidence_refs) ? brief.evidence_refs.slice(0, 6).map(r => `<code>${escapeHtml(r)}</code>`).join(' ') : '';
            return `<article class="ves-brief-card ${brief.created_by === 'local_fallback' ? 'is-fallback' : ''}">
                <div class="ves-card-title">${escapeHtml(brief.title || 'Untitled brief')}</div>
                <div class="ves-card-meta"><span class="ves-brief-status-pill ${status}">${status}</span> <span>${type}</span> ${isAdminUI() ? `<span>Run ID: ${escapeHtml(brief.run_id || '')}</span>` : ''} <span>${createdBy}</span></div>
                ${brief.created_by === 'local_fallback' ? '<div class="ves-fallback-label">Local fallback</div>' : ''}
                <div class="ves-brief-section"><strong>Objective</strong><br>${escapeHtml(brief.objective || '')}</div>
                <div class="ves-brief-section"><strong>Key insight</strong><br>${escapeHtml(brief.key_insight || '')}</div>
                ${refs ? `<div class="ves-evidence-ref-row">${refs}</div>` : ''}
                <div class="ves-brief-actions"><button type="button" class="ves-mini-btn" data-ves-assistant-question="How should we improve this brief before execution?" data-brief-id="${escapeHtml(String(brief.id || ''))}" data-bundle-id="${escapeHtml(brief.run_id || '')}">Preguntar al asistente</button></div>
            </article>`;
        }).join('')}</div>`;
    }

    async function maybeLoadBriefLibraryPage(widget, force = false) {
        const page = widget.querySelector('.ves-page[data-page="brief-library"]');
        const form = page?.querySelector('.ves-brief-library-form');
        const host = page?.querySelector('[data-brief-library-host]');
        if (!page || !form || !host) return;
        if (!force && widget.dataset.briefLibraryLoaded === '1') return;
        widget.dataset.briefLibraryLoaded = '1';
        host.innerHTML = '<div class="ves-card"><div class="ves-hint">Cargando Brief Library…</div></div>';
        try {
            const status = page.querySelector('[name="briefLibraryStatus"]')?.value || '';
            const briefType = page.querySelector('[name="briefLibraryType"]')?.value || '';
            const payload = await ajaxBriefLibraryList(form, { status, briefType, page: 1, perPage: 30 });
            renderBriefLibraryHost(widget, payload);
        } catch (err) {
            widget.dataset.briefLibraryLoaded = '0';
            host.innerHTML = `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'No se pudo cargar Brief Library.'))}</div>`;
        }
    }

    const maybeLoadMemoryPage = async (widget, force = false) => {
        const state = widgetState.get(widget);
        if (!state || !state.form) return;
        const memoryPage = widget.querySelector('.ves-page[data-page="memory"]');
        const skeleton = memoryPage?.querySelector('[data-memory-skeleton]');
        const host = memoryPage?.querySelector('.ves-memory-host') || memoryPage?.querySelector('.ves-trend-dashboard');
        if (!host) return;
        if (!force) widget.dataset.memoryLoaded = '1';
        try {
            const filters = state.memoryFilters || { entry_type:'all', platform:'', q:'', limit:60 };
            const payload = await ajaxMemoryList(state.form, filters);
            state.memoryEntries = Array.isArray(payload?.items) ? payload.items : [];
            state.memoryCounts = payload?.counts || {};
            state.memoryFilters = payload?.filters || filters;
            renderMemoryExplorer(widget);
            renderSidebarMemory(widget);
            if (skeleton) skeleton.setAttribute('hidden', '');
        } catch (err) {
            widget.dataset.memoryLoaded = '0';
            if (skeleton) skeleton.setAttribute('hidden', '');
            renderSidebarMemory(widget);
            if (host && !host.innerHTML) {
                host.innerHTML = '<div class="ves-card"><div class="ves-hint">No se pudo cargar la memoria: ' + escapeHtml(safeUserMessage(err.message || 'error', widget)) + '.</div></div>';
            }
        }
    };

    const memoryTypeLabel = (type) => ({
        search: 'Collection',
        ai_analysis: 'AI',
        trend_report: 'Trend',
        trend_brief: 'Brief',
        brand_audit_report: 'Brand Audit',
    }[type] || 'Memory');

    const memoryPlatformLabel = (platform) => ({
        tiktok: 'TikTok', youtube: 'YouTube', facebook: 'Facebook', instagram: 'Instagram', twitter: 'X / Twitter', linkedin: 'LinkedIn', reddit: 'Reddit', pinterest: 'Pinterest', semrush: 'Semrush', moz: 'MOZ', ahrefs: 'Ahrefs',
        facebook_ads: 'Meta Ads', google_ads: 'Google Ads', google_intel: 'Google Intelligence', trend_finder: 'Trend Finder', brand_audit: 'Brand Audit',
        tiktok_batch: 'TikTok batch', youtube_batch: 'YouTube batch', facebook_batch: 'Facebook batch', instagram_batch: 'Instagram batch', twitter_batch: 'X batch',
    }[platform] || platform || '—');

    const memoryActiveEntries = (widget) => {
        const state = widgetState.get(widget) || {};
        const filters = state.memoryFilters || { entry_type:'all', q:'' };
        const type = filters.entry_type || 'all';
        const q = String(filters.q || '').trim().toLowerCase();
        return (state.memoryEntries || []).filter((entry) => {
            if (type !== 'all' && entry.entry_type !== type) return false;
            if (!q) return true;
            return [entry.title, entry.summary, entry.platform, entry.country_code, ...(entry.signals || [])].join(' ').toLowerCase().includes(q);
        });
    };

    const memoryEntryMarkup = (entry, selectedId, compact = false) => {
        const count = Number(entry.filtered_items || entry.raw_items || 0);
        const title = entry.title || entry.summary || memoryPlatformLabel(entry.platform);
        const meta = `${memoryPlatformLabel(entry.platform)}${entry.country_code ? ' · ' + entry.country_code : ''}${entry.language_code ? ' · ' + entry.language_code : ''}${count ? ' · ' + String(count) + ' items' : ''}`;
        const memoryStatus = String(entry.memory_status || entry.report_status || entry.confidence_mode || '').toLowerCase();
        const fallbackMemory = entry.fallback_used || entry.memory_type === 'fallback_diagnostic' || entry.report_status === 'fallback_diagnostic' || entry.confidence_mode === 'diagnostic_fallback';
        const needsRepair = memoryStatus === 'corrupted_legacy' || memoryStatus === 'needs_repair' || entry.needs_repair;
        const partialMemory = memoryStatus === 'partial' || entry.memory_type === 'partial_report';
        const validatedMemory = memoryStatus === 'validated' || entry.memory_type === 'validated_ai_report';
        const statusText = needsRepair ? 'Needs repair' : (fallbackMemory ? 'Fallback diagnostic' : (partialMemory ? 'Partial' : (validatedMemory ? 'Validated' : '')));
        const statusBadge = statusText ? `<span class="ves-memory-status-badge ${needsRepair ? 'is-repair' : (fallbackMemory ? 'is-fallback' : (partialMemory ? 'is-partial' : 'is-validated'))}">${escapeHtml(statusText)}</span>` : '';
        return `<button type="button" class="ves-memory-entry ${compact ? 'is-compact' : ''} ${fallbackMemory ? 'is-fallback-diagnostic' : ''} ${needsRepair ? 'is-corrupted-legacy' : ''} ${selectedId === Number(entry.id) ? 'is-active' : ''}" data-memory-id="${escapeHtml(entry.id)}">
            <span class="ves-memory-entry-top"><strong>${escapeHtml(title)}</strong>${statusBadge}${compact ? '' : `<em>${escapeHtml(memoryTypeLabel(entry.entry_type))}</em>`}</span>
            <span class="ves-memory-entry-meta">${escapeHtml(meta)}</span>
            ${(!compact && entry.summary) ? `<span class="ves-memory-entry-summary">${escapeHtml(String(entry.summary).slice(0, 150))}</span>` : ''}
            <span class="ves-memory-entry-date">${escapeHtml(formatDisplayDate(entry.created_at))}</span>
        </button>`;
    };

    const renderSidebarMemory = (widget) => {
        const host = widget.querySelector('[data-sidebar-memory]');
        if (!host) return;
        const state = widgetState.get(widget) || {};
        const filters = state.memoryFilters || { entry_type:'all', q:'' };
        const counts = state.memoryCounts || {};
        const selectedId = Number(state.memorySelectedId || 0);
        const entries = memoryActiveEntries(widget).slice(0, 24);
        const tabs = [
            ['all', 'Todo'],
            ['search', 'Collected'],
            ['ai_analysis', 'AI'],
            ['trend_report', 'Trends'],
            ['trend_brief', 'Briefs'],
            ['brand_audit_report', 'Brand Audits'],
        ];
        host.innerHTML = `<div class="ves-sidebar-memory-separator" aria-hidden="true"></div>
            <div class="ves-sidebar-memory-head">
                <div><span>Memory</span><small>Recientes</small></div>
                <button type="button" class="ves-sidebar-memory-refresh ves-memory-refresh" aria-label="Actualizar memoria">↻</button>
            </div>
            <div class="ves-sidebar-memory-tabs" role="group" aria-label="Filtrar memoria">
                ${tabs.map(([id,label]) => `<button type="button" class="ves-memory-tab ves-sidebar-memory-tab ${String(filters.entry_type || 'all') === id ? 'is-active' : ''}" data-memory-type="${id}">${escapeHtml(label)}<span>${escapeHtml(String(counts[id] || 0))}</span></button>`).join('')}
            </div>
            <div class="ves-sidebar-memory-search"><input class="ves-memory-search" type="search" placeholder="Buscar memoria…" value="${escapeHtml(filters.q || '')}" aria-label="Buscar en memoria"></div>
            <div class="ves-sidebar-memory-list">${entries.length ? entries.map((entry) => memoryEntryMarkup(entry, selectedId, true)).join('') : '<div class="ves-sidebar-memory-empty">Sin memoria todavía.</div>'}</div>`;
    };

    const renderMemoryListOnly = (widget) => {
        const state = widgetState.get(widget) || {};
        const selectedId = Number(state.memorySelectedId || 0);
        const entries = memoryActiveEntries(widget);
        const memoryPage = widget.querySelector('.ves-page[data-page="memory"]');
        const listHost = memoryPage?.querySelector('.ves-memory-list');
        if (listHost) {
            listHost.innerHTML = entries.length ? entries.map((entry) => memoryEntryMarkup(entry, selectedId, false)).join('') : '<div class="ves-empty"><strong>No hay memoria para este filtro.</strong><span>Ejecuta una búsqueda o cambia los filtros.</span></div>';
        }
        renderSidebarMemory(widget);
    };

    const renderMemoryExplorer = (widget) => {
        const state = widgetState.get(widget);
        if (!state) return;
        const memoryPage = widget.querySelector('.ves-page[data-page="memory"]');
        const host = memoryPage?.querySelector('.ves-memory-host') || memoryPage?.querySelector('.ves-trend-dashboard');
        if (!host) return;
        host.innerHTML = `<div class="ves-memory-app ves-memory-app-integrated">
            <section class="ves-memory-detail">
                <div class="ves-card ves-memory-empty-state">
                    <div class="ves-item-panel-title">Selecciona una búsqueda anterior desde la barra lateral</div>
                    <p class="ves-hint">Los resultados, análisis y briefs guardados aparecen en la sidebar. Accede a ellos en cualquier momento y vuelve a analizarlos cuando lo necesites.</p>
                </div>
            </section>
        </div>`;
        renderMemoryListOnly(widget);
    };

    const normalizeMemorySearchPayload = (entry, payload) => {
        payload = payload && typeof payload === 'object' ? payload : {};
        const platform = String(payload.platform || entry.platform || 'tiktok');
        const items = Array.isArray(payload.items) ? payload.items : (Array.isArray(payload.display_items) ? payload.display_items : []);
        const best = Array.isArray(payload.best_match_items) ? payload.best_match_items : items;
        const other = Array.isArray(payload.other_items) ? payload.other_items : [];
        return {
            ...payload,
            platform,
            status: payload.status || 'SUCCEEDED',
            items,
            best_match_items: best,
            other_items: other,
            item_count_preview: Number(payload.item_count_preview || items.length || best.length || 0),
            all_items_count: Number(payload.all_items_count || items.length || best.length + other.length || 0),
            runtime_secs: payload.runtime_secs ?? null,
            from_memory: true,
        };
    };

    const mapGoogleMemoryItemsForAi = (items) => (Array.isArray(items) ? items : []).slice(0, 12).map((item) => ({
        title: item.title || item.name || item.query || 'Google result',
        url: item.url || item.link || '',
        author: item.source || item.domain || '',
        published_at: item.date || item.createdAt || '',
        caption_or_text: item.text || item.snippet || item.description || '',
        transcript: '',
        metrics: { views: 0, likes: 0, comments: 0, shares: 0, duration: '', hasPrimaryMetric: false, hasLikeMetric: false },
        raw: item,
    }));

    const renderMemoryDetail = async (widget, entryId) => {
        const state = widgetState.get(widget);
        if (!state || !state.form) return;
        const memoryPage = widget.querySelector('.ves-page[data-page="memory"]');
        const detailHost = memoryPage?.querySelector('.ves-memory-detail');
        if (!detailHost) return;
        state.memorySelectedId = Number(entryId || 0);
        renderMemoryListOnly(widget);
        detailHost.innerHTML = '<div class="ves-card"><div class="ves-hint">Cargando memoria…</div></div>';
        try {
            const res = await ajaxMemoryGet(state.form, entryId);
            const entry = res.entry || {};
            const payload = res.payload || {};
            const meta = `${memoryPlatformLabel(entry.platform)}${entry.country_code ? ' · ' + entry.country_code : ''}${entry.language_code ? ' · ' + entry.language_code : ''}${entry.created_at ? ' · ' + entry.created_at : ''}`;
            detailHost.innerHTML = `<div class="ves-memory-detail-head">
                <div><div class="ves-item-panel-title">${escapeHtml(entry.title || 'Memory record')}</div><div class="ves-meta">${escapeHtml(meta)}</div></div>
                <div class="ves-memory-detail-actions"><button type="button" class="ves-mini-btn ves-memory-refresh-current" data-memory-id="${escapeHtml(entry.id)}">Recargar</button></div>
            </div>
            <div class="ves-memory-results-wrap"><div class="ves-results show"></div></div>`;
            if (entry.entry_type === 'search') {
                const restored = normalizeMemorySearchPayload(entry, payload);
                if (restored.platform === 'google_intel') {
                    renderGoogleResults(widget, restored);
                    const resultsBox = detailHost.querySelector('.ves-results');
                    const displayItems = Array.isArray(restored.display_items) ? restored.display_items : restored.items;
                    const bar = document.createElement('div');
                    bar.className = 'ves-batch-analysis-bar ves-memory-google-analysis-bar';
                    bar.innerHTML = `<div><strong>${escapeHtml(String(displayItems.length || 0))} resultados guardados</strong><span>Analiza estos resultados sin volver a ejecutar Google Intelligence.</span></div><div><button type="button" class="ves-mini-btn ves-memory-analyze-google" data-platform="google_intel">Analizar resultados guardados</button></div>`;
                    resultsBox?.querySelector('.ves-result-card')?.prepend(bar);
                    state.memoryGoogleItems = displayItems;
                } else {
                    renderResults(widget, restored);
                }
            } else if (entry.entry_type === 'ai_analysis') {
                detailHost.querySelector('.ves-results').innerHTML = renderAnalysisPanel(payload.analysis || payload.analysis_excerpt || 'Sin análisis guardado.', payload.model || '', { project_context_used:false, related_reports_used:0 });
            } else if (entry.entry_type === 'trend_report') {
                detailHost.querySelector('.ves-results').innerHTML = `<div class="ves-item-panel"><div class="ves-item-panel-head"><div><div class="ves-item-panel-title">Trend report guardado</div><div class="ves-meta">${escapeHtml(meta)}</div></div></div><div class="ves-trend-ai-report">${renderAnalysisRichText(payload.analysis || '')}</div>${isDebugMode(detailHost) ? `<details class="ves-source-input-details"><summary>Admin debug: raw saved payload</summary><pre>${escapeHtml(JSON.stringify(payload, null, 2).slice(0, 8000))}</pre></details>` : ''}</div>`;
            } else if (entry.entry_type === 'brand_audit_report') {
                detailHost.querySelector('.ves-results').innerHTML = `<div class="ves-item-panel"><div class="ves-item-panel-head"><div><div class="ves-item-panel-title">Brand Audit guardado</div><div class="ves-meta">${escapeHtml(meta)}</div></div></div><div class="ves-trend-ai-report">${renderAnalysisRichText(payload.analysis || '')}</div>${renderBrandAuditDeck(payload.deck || payload.analysis_structured?.deck_outline || [])}${isDebugMode(detailHost) ? `<details class="ves-source-input-details"><summary style="font-size:11px;opacity:.55;cursor:pointer">Admin debug: raw payload</summary><pre style="font-size:10px;max-height:200px;overflow:auto;opacity:.8">${escapeHtml(JSON.stringify(payload, null, 2).slice(0, 4000))}</pre></details>` : ""}</div>`;
            } else if (entry.entry_type === 'trend_brief') {
                detailHost.querySelector('.ves-results').innerHTML = `<div class="ves-item-panel"><div class="ves-item-panel-head"><div><div class="ves-item-panel-title">Brief guardado</div><div class="ves-meta">${escapeHtml(meta)}</div></div></div><div class="ves-trend-ai-report">${renderAnalysisRichText(payload.brief_text || '')}</div>${isDebugMode(detailHost) ? `<details class="ves-source-input-details"><summary style="font-size:11px;opacity:.55;cursor:pointer">Admin debug: structure</summary><pre style="font-size:10px;max-height:200px;overflow:auto;opacity:.8">${escapeHtml(JSON.stringify(payload.brief_structured || payload, null, 2).slice(0, 4000))}</pre></details>` : ""}</div>`;
            } else {
                detailHost.querySelector('.ves-results').innerHTML = `<div class="ves-pre">${escapeHtml(JSON.stringify(payload, null, 2))}</div>`;
            }
        } catch (err) {
            detailHost.innerHTML = `<div class="ves-card"><div class="ves-hint">No se pudo abrir este registro. El registro puede estar incompleto o ser ilegible, pero la lista de memoria sigue disponible. ${escapeHtml(safeUserMessage(err.message || 'report_template_unreadable', widget))}</div></div>`;
        }
    };

    /* Sidebar nav binding */
    const setupPageNavigation = (widget) => {
        const navBtns = widget.querySelectorAll('.ves-nav-item:not(.ves-nav-link)');
        if (!navBtns.length) return;
        const allowed = widgetPagesFor(widget);
        navBtns.forEach((btn) => {
            // Anchor links (cross-page) skip switchPage handling
            if (btn.tagName === 'A') return;
            btn.addEventListener('click', (ev) => {
                ev.preventDefault();
                switchPage(widget, btn.dataset.page);
            });
            btn.addEventListener('keydown', (ev) => {
                if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp') return;
                ev.preventDefault();
                const list = Array.from(navBtns).filter((b) => b.tagName !== 'A');
                const idx = list.indexOf(btn);
                const nextIdx = ev.key === 'ArrowDown' ? (idx + 1) % list.length : (idx - 1 + list.length) % list.length;
                list[nextIdx]?.focus();
                switchPage(widget, list[nextIdx]?.dataset.page);
            });
        });
        const stored = safeStorage.get(widgetStorageKey(widget));
        const defaultPage = widget.dataset.defaultPage || 'dashboard';
        // Unified routing: ?fiis_page= / #/route deep-links win over storage.
        let urlRoute = '';
        try {
            const qp = new URLSearchParams(window.location.search).get('fiis_page');
            const hash = (window.location.hash || '').replace(/^#\/?/, '');
            urlRoute = (qp || hash || '').trim();
        } catch (e) { urlRoute = ''; }
        // A shortcode alias (e.g. [ves_seo]) sets data-force-route and is
        // authoritative; otherwise URL deep-link, then stored, then default.
        const forced = (widget.dataset.forceRoute || '').trim();
        const initial = (forced && allowed.has(forced)) ? forced
            : (urlRoute && allowed.has(urlRoute)) ? urlRoute
            : (stored && allowed.has(stored) ? stored : defaultPage);
        switchPage(widget, initial);
    };

    /* Credit pill — derives value from refreshUsageSummary AJAX side-effect */
    const updateCreditPill = (widget, balance) => {
        const pill = widget.querySelector('.ves-credit-pill[data-credit-pill]');
        if (!pill) return;
        const valueEl = pill.querySelector('.ves-credit-pill-value');
        let state = '';
        let value = balance;
        if (balance && typeof balance === 'object') {
            state = String(balance.state || '');
            value = balance.value;
        }
        const num = Number(value);
        // v0.9.31.2: make the credit state explicit. Normal users should never see
        // a fake huge number for admin/unlimited mode, and billing failures should
        // render as unavailable/degraded rather than an apparently valid zero.
        const isUnlimited = state === 'admin' || (Number.isFinite(num) && num >= 100000000);
        if (valueEl) {
            if (isUnlimited) valueEl.textContent = 'Admin';
            else if (state === 'degraded' || state === 'unavailable' || value == null) valueEl.textContent = 'Unavailable';
            else valueEl.textContent = nfmt(value);
        }
        pill.classList.remove('is-low', 'is-empty', 'is-unavailable', 'is-admin');
        if (isUnlimited) {
            pill.classList.add('is-admin');
        } else if (state === 'degraded' || state === 'unavailable' || value == null || Number.isNaN(num)) {
            pill.classList.add('is-unavailable');
        } else if (!Number.isNaN(num)) {
            if (num <= 0) pill.classList.add('is-empty');
            else if (num < 50) pill.classList.add('is-low');
        }
    };


    const setupUILanguageSwitcher = (widget) => {
        const select = widget.querySelector('.ves-ui-language');
        const stored = localStorage.getItem(UI_LANG_STORAGE_KEY);
        if (select && stored && UI_LANGS.includes(stored)) select.value = stored;
        applyUILanguage(widget);
        select?.addEventListener('change', () => {
            const lang = UI_LANGS.includes(select.value) ? select.value : 'es';
            localStorage.setItem(UI_LANG_STORAGE_KEY, lang);
            document.querySelectorAll('.ves-wrap').forEach((w) => applyUILanguage(w));
        });
    };

    /* Command palette — page targets adjusted to v0.9.9.2 layout. */
    const COMMAND_LIST = (widget) => {
        const isPanel = false; // unified app — always expose tool navigation commands
        const items = [];
        if (!isPanel) {
            items.push(
                { id: 'goto-social', label: 'Ir a Social Media', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'social') },
                { id: 'goto-trend', label: 'Ir a Trend Finder', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'trend') },
                { id: 'goto-google', label: 'Ir a Google Intelligence', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'google') },
                { id: 'goto-memory', label: 'Ir a Memory', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'memory') },
                { id: 'platform-tiktok', label: 'Cambiar a TikTok', hint: 'Plataforma', group: 'Plataformas', action: () => { switchPage(widget, 'social'); setTimeout(() => applyPlatform(widget, 'tiktok'), 0); } },
                { id: 'platform-youtube', label: 'Cambiar a YouTube', hint: 'Plataforma', group: 'Plataformas', action: () => { switchPage(widget, 'social'); setTimeout(() => applyPlatform(widget, 'youtube'), 0); } },
                { id: 'platform-facebook', label: 'Cambiar a Facebook', hint: 'Plataforma', group: 'Plataformas', action: () => { switchPage(widget, 'social'); setTimeout(() => applyPlatform(widget, 'facebook'), 0); } },
                { id: 'platform-instagram', label: 'Cambiar a Instagram', hint: 'Plataforma', group: 'Plataformas', action: () => { switchPage(widget, 'social'); setTimeout(() => applyPlatform(widget, 'instagram'), 0); } },
                { id: 'platform-twitter', label: 'Cambiar a X / Twitter', hint: 'Plataforma', group: 'Plataformas', action: () => { switchPage(widget, 'social'); setTimeout(() => applyPlatform(widget, 'twitter'), 0); } },
                { id: 'platform-reddit', label: 'Cambiar a Reddit', hint: 'Plataforma', group: 'Plataformas', action: () => { switchPage(widget, 'social'); setTimeout(() => applyPlatform(widget, 'reddit'), 0); } },
                { id: 'platform-pinterest', label: 'Cambiar a Pinterest', hint: 'Plataforma', group: 'Plataformas', action: () => { switchPage(widget, 'social'); setTimeout(() => applyPlatform(widget, 'pinterest'), 0); } },
                { id: 'run', label: 'Ejecutar run', hint: '⌘↵', group: 'Acciones', action: () => { widget.querySelector('.ves-page.is-active .ves-start')?.click(); } }
            );
            if (!isProductionMvpWidget(widget)) {
                items.push(
                    { id: 'goto-linkedin', label: 'Ir a LinkedIn', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'linkedin') },
                    { id: 'goto-seo', label: 'Ir a SEO/SEM/AEO', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'seo') },
                    { id: 'goto-brand-audit', label: 'Ir a Brand Deep Audit', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'brand-audit') },
                    { id: 'goto-creative', label: 'Ir a Creative Intelligence', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'creative') },
                    { id: 'goto-ads', label: 'Ir a Ads Intelligence', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'ads') },
                    { id: 'platform-semrush', label: 'Cambiar a Semrush', hint: 'SEO/SEM/AEO', group: 'SEO', action: () => { switchPage(widget, 'seo'); setTimeout(() => applyPlatform(widget, 'semrush'), 0); } },
                    { id: 'platform-moz', label: 'Cambiar a MOZ', hint: 'SEO/SEM/AEO', group: 'SEO', action: () => { switchPage(widget, 'seo'); setTimeout(() => applyPlatform(widget, 'moz'), 0); } },
                    { id: 'platform-ahrefs', label: 'Cambiar a Ahrefs', hint: 'SEO/SEM/AEO', group: 'SEO', action: () => { switchPage(widget, 'seo'); setTimeout(() => applyPlatform(widget, 'ahrefs'), 0); } },
                    { id: 'platform-meta-ads', label: 'Cambiar a Meta Ads', hint: 'Plataforma', group: 'Ads', action: () => { switchPage(widget, 'ads'); setTimeout(() => applyPlatform(widget, 'facebook_ads'), 0); } },
                    { id: 'platform-google-ads', label: 'Cambiar a Google Ads', hint: 'Plataforma', group: 'Ads', action: () => { switchPage(widget, 'ads'); setTimeout(() => applyPlatform(widget, 'google_ads'), 0); } }
                );
            }
        } else {
            items.push(
                { id: 'goto-knowledge', label: 'Ir a Knowledge base', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'knowledge') },
                { id: 'goto-memory', label: 'Ir a Memory', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'memory') },
                { id: 'goto-usage', label: 'Ir a Usage', hint: 'Página', group: 'Navegación', action: () => switchPage(widget, 'usage') }
            );
        }
        items.push(
            { id: 'refresh-usage', label: 'Refrescar créditos', hint: 'Acción', group: 'Acciones', action: () => { try { refreshUsageSummary(widget); } catch(e) {} showToast(widget, 'Refrescando créditos…', 'info', 1800); } }
        );
        return items;
    };

    const renderCmdkResults = (widget, items, activeIdx) => {
        const host = widget.querySelector('.ves-cmdk-results');
        if (!host) return;
        if (!items.length) {
            host.innerHTML = '<div class="ves-cmdk-empty">Sin resultados</div>';
            return;
        }
        const grouped = items.reduce((acc, it, idx) => {
            (acc[it.group] = acc[it.group] || []).push({ ...it, idx });
            return acc;
        }, {});
        let html = '';
        for (const [group, list] of Object.entries(grouped)) {
            html += `<div class="ves-cmdk-group-label">${escapeHtml(group)}</div>`;
            html += list.map((it) => `
                <div class="ves-cmdk-item ${it.idx === activeIdx ? 'is-active' : ''}" data-cmdk-id="${escapeHtml(it.id)}" role="option">
                    <span class="ves-cmdk-item-ico"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    <span class="ves-cmdk-item-label">${escapeHtml(it.label)}</span>
                    <span class="ves-cmdk-item-hint">${escapeHtml(it.hint || '')}</span>
                </div>
            `).join('');
        }
        host.innerHTML = html;
    };

    const initCmdk = (widget) => {
        const palette = widget.querySelector('.ves-cmdk');
        const input = palette?.querySelector('.ves-cmdk-input');
        const trigger = widget.querySelector('.ves-cmdk-btn');
        if (!palette || !input) return;

        let allCommands = COMMAND_LIST(widget);
        let filtered = allCommands.slice();
        let activeIdx = 0;

        const refresh = () => {
            const q = input.value.trim().toLowerCase();
            filtered = q
                ? allCommands.filter((c) => c.label.toLowerCase().includes(q) || (c.hint || '').toLowerCase().includes(q) || (c.group || '').toLowerCase().includes(q))
                : allCommands.slice();
            activeIdx = 0;
            renderCmdkResults(widget, filtered, activeIdx);
        };

        const open = () => {
            allCommands = COMMAND_LIST(widget);
            input.value = '';
            refresh();
            palette.removeAttribute('hidden');
            setTimeout(() => input.focus(), 30);
        };

        const close = () => {
            palette.setAttribute('hidden', '');
        };

        const exec = (idx) => {
            const cmd = filtered[idx];
            if (!cmd) return;
            close();
            try { cmd.action(); } catch (e) { console.warn('[VES] cmdk action failed', e); }
        };

        trigger?.addEventListener('click', open);
        palette.querySelector('[data-cmdk-close]')?.addEventListener('click', close);
        input.addEventListener('input', refresh);
        input.addEventListener('keydown', (ev) => {
            if (ev.key === 'ArrowDown') { ev.preventDefault(); activeIdx = (activeIdx + 1) % filtered.length; renderCmdkResults(widget, filtered, activeIdx); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); activeIdx = (activeIdx - 1 + filtered.length) % filtered.length; renderCmdkResults(widget, filtered, activeIdx); }
            else if (ev.key === 'Enter') { ev.preventDefault(); exec(activeIdx); }
            else if (ev.key === 'Escape') { ev.preventDefault(); close(); }
        });
        palette.querySelector('.ves-cmdk-results')?.addEventListener('click', (ev) => {
            const item = ev.target.closest('.ves-cmdk-item');
            if (!item) return;
            const id = item.dataset.cmdkId;
            const idx = filtered.findIndex((c) => c.id === id);
            exec(idx >= 0 ? idx : 0);
        });

        // Global hotkeys
        document.addEventListener('keydown', (ev) => {
            const isCmdK = (ev.metaKey || ev.ctrlKey) && (ev.key === 'k' || ev.key === 'K');
            if (isCmdK) { ev.preventDefault(); palette.hasAttribute('hidden') ? open() : close(); return; }
            // Number shortcuts (1-4) when not typing in an input
            if (!palette.hasAttribute('hidden')) return;
            const target = ev.target;
            const isTyping = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);
            if (isTyping) return;
            const map = isProductionMvpWidget(widget)
                ? { '1': 'dashboard', '2': 'social', '3': 'trend', '4': 'google', '5': 'memory' }
                : { '1': 'dashboard', '2': 'social', '3': 'linkedin', '4': 'seo', '5': 'google', '6': 'trend', '7': 'brand-audit', '8': 'ads', '9': 'creative' };
            if (map[ev.key]) { ev.preventDefault(); switchPage(widget, map[ev.key]); }
            // Cmd+Enter to run
            if ((ev.metaKey || ev.ctrlKey) && ev.key === 'Enter') {
                ev.preventDefault();
                widget.querySelector('.ves-page.is-active .ves-start')?.click();
            }
        });
    };

    /* Knowledge search filter */
    const initKnowledgeSearch = (widget) => {
        const input = widget.querySelector('.ves-knowledge-search-input');
        const list = widget.querySelector('.ves-knowledge-list');
        if (!input || !list) return;
        input.addEventListener('input', () => {
            const q = input.value.trim().toLowerCase();
            list.querySelectorAll('.ves-knowledge-item').forEach((row) => {
                if (!q) { row.classList.remove('is-hidden'); return; }
                const text = row.textContent.toLowerCase();
                row.classList.toggle('is-hidden', !text.includes(q));
            });
        });
    };

    /* Master init for v3 chrome */
    const setupShellV3 = (widget) => {
        if (widget.classList.contains('ves-shell-v3') !== true) return;
        if (widget.dataset.shellV3Ready === '1') return; // v0.9.9.1: idempotency guard
        widget.dataset.shellV3Ready = '1';
        initTheme(widget);
        initSidebar(widget);
        initUserMenu(widget);
        initCmdk(widget);
        initKnowledgeSearch(widget);
        setupPageNavigation(widget);
        // v0.9.9.0 pro polish hooks
        initDensity(widget);
        initRunStateBridge(widget);
        initButtonLoadingHelpers(widget);
        setTimeout(() => { try { maybeLoadMemoryPage(widget, true); } catch (e) {} }, 180);
    };

    /* === v0.9.9.0 pro polish === */

    const DENSITY_KEY = 'ves_density_v1';
    const initDensity = (widget) => {
        const stored = safeStorage.get(DENSITY_KEY);
        if (stored === 'compact') widget.classList.add('is-density-compact');
        const btn = widget.querySelector('.ves-density-toggle');
        if (!btn) return;
        const apply = () => {
            const isCompact = widget.classList.toggle('is-density-compact');
            safeStorage.set(DENSITY_KEY, isCompact ? 'compact' : 'comfortable');
            if (typeof showToast === 'function') {
                showToast(widget, isCompact ? 'Densidad compacta' : 'Densidad estándar', 'info', 1600);
            }
        };
        btn.addEventListener('click', apply);
    };

    /* Run state pill: derives from .ves-status content + ves-start disabled state */
    const setRunState = (widget, state, label) => {
        const pill = widget.querySelector('.ves-run-state[data-run-state]');
        if (!pill) return;
        const labelEl = pill.querySelector('.ves-run-state-label');
        pill.classList.remove('is-running', 'is-success', 'is-error');
        if (state === 'idle' || !state) {
            pill.setAttribute('hidden', '');
            return;
        }
        pill.removeAttribute('hidden');
        pill.classList.add('is-' + state);
        if (labelEl) labelEl.textContent = label || (state === 'running' ? 'Ejecutando' : state === 'success' ? 'Completado' : 'Error');
    };

    const syncRunStateFromData = (widget, data) => {
        const lifecycle = String(data?.run_lifecycle_status || data?.lifecycle_status || '').toLowerCase();
        const providerStatus = String(data?.status || '').toUpperCase();
        if (['queued'].includes(lifecycle)) { setRunState(widget, 'running', 'En cola'); return; }
        if (['provider_running', 'normalizing', 'ai_processing'].includes(lifecycle) || ['RUNNING','READY','QUEUED'].includes(providerStatus)) { setRunState(widget, 'running', 'Ejecutando'); return; }
        if (['completed_no_usable_results'].includes(lifecycle)) { setRunState(widget, 'success', 'Sin resultados útiles'); setTimeout(() => setRunState(widget, 'idle'), 4200); return; }
        if (['completed_low_confidence', 'needs_validation'].includes(lifecycle)) { setRunState(widget, 'success', 'Necesita validación'); setTimeout(() => setRunState(widget, 'idle'), 4600); return; }
        if (['completed'].includes(lifecycle) || providerStatus === 'SUCCEEDED') { setRunState(widget, 'success', 'Completado'); setTimeout(() => setRunState(widget, 'idle'), 4000); return; }
        if (['failed', 'canceled'].includes(lifecycle) || ['FAILED','ABORTED','TIMED-OUT','TIMEOUT'].includes(providerStatus)) { setRunState(widget, 'error', lifecycle === 'canceled' ? 'Cancelado' : 'Error'); setTimeout(() => setRunState(widget, 'idle'), 4800); }
    };

    const initRunStateBridge = (widget) => {
        // v0.9.9.3: there can be multiple .ves-status (one per scraper sub-page).
        // Observe all of them so the topbar pill reflects whichever is active.
        const statusEls = widget.querySelectorAll('.ves-status');
        if (!statusEls.length) return;
        const map = () => {
            // Find any visible status with a class change.
            let activeStatus = null;
            for (const s of statusEls) {
                if (s.classList.contains('show') && s.offsetParent !== null) {
                    activeStatus = s;
                    break;
                }
            }
            if (!activeStatus) {
                setRunState(widget, 'idle');
                return;
            }
            if (activeStatus.classList.contains('error')) {
                setRunState(widget, 'error', 'Error');
                setTimeout(() => setRunState(widget, 'idle'), 4500);
            } else if (activeStatus.classList.contains('success')) {
                setRunState(widget, 'success', 'Completado');
                setTimeout(() => setRunState(widget, 'idle'), 4000);
            } else if (activeStatus.classList.contains('info')) {
                const text = String(activeStatus.textContent || '').toLowerCase();
                if (/no results|no usable|sin resultados|no hay resultados|completed|completado|finalizado|finished|low confidence|baja confianza/.test(text)) {
                    setRunState(widget, 'success', /no results|sin resultados|no hay resultados|no usable/.test(text) ? 'Sin resultados útiles' : 'Completado');
                    setTimeout(() => setRunState(widget, 'idle'), 4000);
                } else {
                    setRunState(widget, 'running', 'Ejecutando');
                }
            }
        };
        statusEls.forEach((status) => {
            const observer = new MutationObserver(map);
            observer.observe(status, { attributes: true, attributeFilter: ['class'], childList: true, characterData: true, subtree: true });
        });
        map();
    };

    /* v0.9.9.1 perf fix: previous build attached a MutationObserver to every
       async button to mirror disabled→loading. That's a lot of observers.
       Use a single delegated click handler and a class-toggle scheduled
       on the next animation frame. */
    const initButtonLoadingHelpers = (widget) => {
        const SELECTOR = '.ves-start, .ves-knowledge-upload, .ves-knowledge-ingest, .ves-project-context-save';
        widget.addEventListener('click', (ev) => {
            const btn = ev.target.closest(SELECTOR);
            if (!btn || btn.disabled) return;
            // Add the spinner class; if the button gets re-enabled later,
            // an "input"-like check on each animation frame strips it.
            btn.classList.add('is-loading');
            const tick = () => {
                if (!btn.disabled) {
                    btn.classList.remove('is-loading');
                    return;
                }
                requestAnimationFrame(tick);
            };
            // Wait until the next frame in case the click handler synchronously
            // sets disabled = true. If it never does (e.g. validation failed),
            // strip the spinner class within ~150ms.
            requestAnimationFrame(() => {
                if (!btn.disabled) {
                    btn.classList.remove('is-loading');
                    return;
                }
                requestAnimationFrame(tick);
            });
            setTimeout(() => {
                if (!btn.disabled) btn.classList.remove('is-loading');
            }, 200);
        });
    };


    /* v0.9.9.1 perf fix: previous build observed mutations on the entire
       document body with subtree:true to keep the credit pill in sync.
       That ran on every DOM change anywhere on the page, including toasts,
       results streams, etc. Replace with a targeted refresh: pull the value
       once when the user lands on the Usage page (already covered by
       refreshUsageSummary in switchPage) and once after every successful run.
       Cheap, no observer at all. */
    const __vesPatchUsageRefresh = () => {
        const pull = () => {
            // v0.9.31.1: prefer the server-localized balance (window.VES_CREDIT), which is
            // present on EVERY page. The DOM KPI (.ves-account-kpis) only exists on the Usage
            // page, so relying on it alone left the pill stuck at "—" on Ads/SEO/Trend pages.
            const credit = (typeof window !== 'undefined' && window.VES_CREDIT) ? window.VES_CREDIT : null;
            document.querySelectorAll('.ves-shell-v3').forEach((widget) => {
                const kpi = widget.querySelector('.ves-account-kpis .ves-kpi:first-child strong');
                // 1) Live DOM KPI wins when the Usage page is rendered (freshest value).
                if (kpi) {
                    const raw = (kpi.textContent || '').trim();
                    if (raw === '∞' || raw.indexOf('∞') !== -1) {
                        updateCreditPill(widget, { state: 'admin', value: null });
                        return;
                    }
                    // Locale-aware: determine decimal vs thousands separator by which appears last.
                    const stripped = raw.replace(/[^0-9.,]/g, '');
                    const lastComma = stripped.lastIndexOf(',');
                    const lastDot   = stripped.lastIndexOf('.');
                    let num;
                    if (lastComma > lastDot) {
                        num = parseFloat(stripped.replace(/\./g, '').replace(',', '.'));
                    } else if (lastDot > lastComma) {
                        num = parseFloat(stripped.replace(/,/g, ''));
                    } else {
                        num = parseFloat(stripped.replace(/[.,]/g, ''));
                    }
                    if (!Number.isNaN(num)) {
                        updateCreditPill(widget, num);
                        return;
                    }
                    // DOM kpi text is non-numeric (e.g. "—"): signal unavailable so the
                    // pill renders styled rather than silently showing a stale value.
                    updateCreditPill(widget, null);
                    return;
                }
                // 2) Fall back to the localized balance so the pill works off the Usage page.
                if (credit && credit.known) {
                    updateCreditPill(widget, credit.unlimited ? { state: 'admin', value: null } : credit.balance);
                } else {
                    // Unknown/degraded billing: leave an explicit "—" (styled), do not blank.
                    updateCreditPill(widget, { state: 'unavailable', value: null });
                }
            });
        };
        // Run once shortly after init (so the pill picks up the initial value
        // rendered server-side or by the first refreshUsageSummary call).
        setTimeout(pull, 600);
        setTimeout(pull, 1800);
        // And expose it so other code can call it after a run finishes.
        window.__vesPullCredit = pull;
    };
    __vesPatchUsageRefresh();




    const normalizeItem = (platform, item) => {

        if (platform === 'semrush' || platform === 'moz' || platform === 'ahrefs') {
            const title = cleanTemplateText(firstNonEmpty(item.ves_title, item.keyword, item.phrase, item.query, item.name, item.companyName, item.agencyName, item.domain, item.url, platform === 'semrush' ? 'Semrush result' : (platform === 'moz' ? 'MOZ result' : 'Ahrefs result')));
            const author = cleanTemplateText(firstNonEmpty(item.ves_author, item.name, item.companyName, item.agencyName, item.domain, item.url, ''));
            const intentBits = [item.intent, item.competitionValue ? `Competition: ${item.competitionValue}` : '', item.lowCPC || item.highCPC ? `CPC: ${firstNonEmpty(item.lowCPC, '')}–${firstNonEmpty(item.highCPC, '')}` : ''].filter(Boolean).join(' · ');
            const text = cleanTemplateText(firstNonEmpty(item.ves_text, item.description, item.summary, item.services, item.industries, item.location, item.country, intentBits, item.keyword, ''));
            const url = safeUrl(firstNonEmpty(item.ves_url, item.website, item.websiteUrl, item.url, item.domain, '#'));
            const metricVal = parseNum(firstNonEmpty(item.ves_primary_metric_value, item.avgMonthlySearches, item.searchVolume, item.search_volume, item.volume, item.monthlySearchVolume, item.monetizationScore, item.domainRating, item.domain_rating, item.domain_authority, item.domainAuthority, item.da, item.rating, item.organicTraffic, item.traffic, item.backlinks, 0));
            let metricLabel = firstNonEmpty(item.ves_primary_metric_label, platform === 'semrush' ? 'SEO metric' : (platform === 'moz' ? 'Authority' : 'SEO metric'));
            let metricKey = firstNonEmpty(item.ves_primary_metric_key, platform === 'semrush' ? 'authority' : 'authority');
            return {
                url,
                thumb: safeUrl(firstNonEmpty(item.ves_thumb, item.logo, item.image, item.avatar, findImageUrl(item), '')),
                title,
                author,
                date: firstNonEmpty(item.ves_date, item.createdAt, item.updatedAt, item.scrapedAt, ''),
                text,
                transcript: '',
                views: metricVal,
                likes: 0,
                comments: parseNum(firstNonEmpty(item.reviewCount, item.reviews, 0)),
                shares: 0,
                duration: '',
                primaryMetricLabel: metricLabel,
                primaryMetricKey: metricKey,
                primaryMetricValue: metricVal,
                hasPrimaryMetric: metricVal > 0,
                hasLikeMetric: false,
                relevanceScore: parseNum(firstNonEmpty(item.ves_relevance_score, 0)),
                relevanceClass: String(firstNonEmpty(item.ves_relevance_class, '')),
                relevanceLabel: String(firstNonEmpty(item.ves_relevance_label, '')),
                relevanceFlag: String(firstNonEmpty(item.ves_relevance_flag, '')),
                relevanceExplanation: String(firstNonEmpty(item.ves_relevance_explanation, '')),
                raw: item,
            };
        }
        if (platform === 'reddit') {
            const dataType = String(firstNonEmpty(item.dataType, item.type, '')).toLowerCase();
            const title = cleanTemplateText(firstNonEmpty(item.ves_title, item.title, item.name, item.communityName, dataType ? `Reddit ${dataType}` : 'Reddit result'));
            const text = cleanTemplateText(firstNonEmpty(item.ves_text, item.body, item.text, item.selftext, item.description, item.html, ''));
            const author = cleanTemplateText(firstNonEmpty(item.username, item.author, item.userName, item.communityName, item.parsedCommunityName, ''));
            const url = safeUrl(firstNonEmpty(item.ves_url, item.url, item.postUrl, item.permalink, item.commentUrl, '#'));
            const thumb = safeUrl(firstNonEmpty(item.ves_thumb, item.thumbnail, item.headerImage, item.userIcon, item.image, findImageUrl(item), ''));
            let primaryMetricLabel = 'Upvotes';
            let primaryMetricKey = 'likes';
            let primaryMetricValue = parseNum(firstNonEmpty(item.ves_primary_metric_value, item.upVotes, item.score, item.ups, 0));
            if (dataType === 'community') {
                primaryMetricLabel = 'Members';
                primaryMetricKey = 'members';
                primaryMetricValue = parseNum(firstNonEmpty(item.numberOfMembers, item.subscribers, primaryMetricValue));
            } else if (dataType === 'user') {
                primaryMetricLabel = 'Karma';
                primaryMetricKey = 'karma';
                primaryMetricValue = parseNum(firstNonEmpty(item.postKarma, item.commentKarma, primaryMetricValue));
            }
            return {
                url,
                thumb,
                title,
                author,
                date: firstNonEmpty(item.ves_date, item.createdAt, item.scrapedAt, ''),
                text,
                transcript: '',
                views: 0,
                likes: parseNum(firstNonEmpty(item.upVotes, item.score, item.ups, 0)),
                comments: parseNum(firstNonEmpty(item.numberOfComments, item.numComments, item.commentsCount, item.numberOfreplies, 0)),
                shares: 0,
                duration: '',
                primaryMetricLabel: firstNonEmpty(item.ves_primary_metric_label, primaryMetricLabel),
                primaryMetricKey: firstNonEmpty(item.ves_primary_metric_key, primaryMetricKey),
                primaryMetricValue,
                hasPrimaryMetric: true,
                hasLikeMetric: true,
                relevanceScore: parseNum(firstNonEmpty(item.ves_relevance_score, 0)),
                relevanceClass: String(firstNonEmpty(item.ves_relevance_class, '')),
                relevanceLabel: String(firstNonEmpty(item.ves_relevance_label, '')),
                relevanceFlag: String(firstNonEmpty(item.ves_relevance_flag, '')),
                relevanceExplanation: String(firstNonEmpty(item.ves_relevance_explanation, '')),
                raw: item,
            };
        }

        if (platform === 'linkedin') {
            const first = firstNonEmpty(item.firstName, item.first_name, '');
            const last = firstNonEmpty(item.lastName, item.last_name, '');
            const fullName = firstNonEmpty(item.ves_title, item.fullName, `${first} ${last}`.trim(), item.name, item.title, 'LinkedIn profile');
            const headline = firstNonEmpty(item.ves_text, item.headline, item.summary, item.about, item.currentPosition, item.currentCompanyName, item.currentCompany, item.location, '');
            let profileUrl = firstNonEmpty(item.ves_url, item.profileUrl, item.linkedinUrl, item.linkedInUrl, item.url, '');
            const publicId = firstNonEmpty(item.publicIdentifier, item.public_identifier, '');
            if (!profileUrl && publicId) profileUrl = `https://www.linkedin.com/in/${encodeURIComponent(publicId)}/`;
            const thumb = safeUrl(firstNonEmpty(item.ves_thumb, item.profilePicture, item.profilePictureUrl, item.profileImageUrl, item.imageUrl, item.image, findImageUrl(item), ''));
            return {
                url: safeUrl(profileUrl || '#'),
                thumb,
                title: cleanTemplateText(fullName),
                author: cleanTemplateText(firstNonEmpty(item.headline, item.currentCompanyName, item.currentCompany, item.location, '')),
                date: firstNonEmpty(item.ves_date, item.updatedAt, item.createdAt, ''),
                text: cleanTemplateText(headline),
                transcript: '',
                views: 1,
                likes: 0,
                comments: 0,
                shares: 0,
                duration: '',
                primaryMetricLabel: firstNonEmpty(item.ves_primary_metric_label, 'Profiles'),
                primaryMetricKey: firstNonEmpty(item.ves_primary_metric_key, 'profiles'),
                primaryMetricValue: parseNum(firstNonEmpty(item.ves_primary_metric_value, 1)),
                hasPrimaryMetric: true,
                hasLikeMetric: false,
                relevanceScore: parseNum(firstNonEmpty(item.ves_relevance_score, 0)),
                relevanceClass: String(firstNonEmpty(item.ves_relevance_class, '')),
                relevanceLabel: String(firstNonEmpty(item.ves_relevance_label, '')),
                relevanceFlag: String(firstNonEmpty(item.ves_relevance_flag, '')),
                relevanceExplanation: String(firstNonEmpty(item.ves_relevance_explanation, '')),
                raw: item,
            };
        }
        const isAd = platform === 'facebook_ads' || platform === 'google_ads';
        const isFbAd = platform === 'facebook_ads';
        const isGoogleAd = platform === 'google_ads';
        const archiveId = firstNonEmpty(item.ad_archive_id, item.adArchivarId, item.archive_id, item.creativeId, item.advertiserId, item.id, '');
        const fbAuthor = firstNonEmpty(
            cleanTemplateText(item.ves_author),
            firstPathText(item, ['pageName', 'page_name', 'advertiserName', 'advertiser_name', 'page.name', 'snapshot.page_name']),
            isGoogleAd ? 'Google Ad' : 'Facebook Ad'
        );
        const fbTitle = firstNonEmpty(
            cleanTemplateText(item.ves_title),
            firstPathText(item, ['ad_creative_link_titles.0', 'ad_creative_link_titles', 'adCreativeLinkTitle', 'title', 'snapshot.title', 'snapshot.cards.0.title', 'snapshot.link_title']),
            archiveId ? `${fbAuthor} — ${isGoogleAd ? 'Google Ad #' : 'Ad #'}${archiveId}` : `${fbAuthor} — ${isGoogleAd ? 'Google Ad' : 'Facebook Ad'}`
        );
        const fbText = firstNonEmpty(
            cleanTemplateText(item.ves_text),
            firstPathText(item, ['description', 'text', 'adText', 'format', 'ad_creative_bodies.0', 'ad_creative_bodies', 'adCreativeBody', 'body', 'adSnapshotBody', 'snapshot.body.text', 'snapshot.cards.0.body', 'snapshot.cards.0.text', 'snapshot.link_description']),
            archiveId ? `Archivar ID: ${archiveId}` : ''
        );
        const views = parseNum(firstNonEmpty(item.ves_views, item.viewCount, item.views, item.playCount, item.videoViewCount, item.videoPlayCount, firstPathText(item, ['impressions.lowerBound','regionStats.0.impressions.lowerBound','regionStats.0.impressions.upperBound']), item.impressions, isFbAd ? fbAdsImpressions(item) : 0));
        const likes = isAd ? 0 : parseNum(firstNonEmpty(item.ves_likes, item.likeCount, item.likesCount, item.diggCount, item.favoriteCount, item.saves, item.saveCount, item.repinCount, 0));
        const comments = isAd ? 0 : parseNum(firstNonEmpty(item.ves_comments, item.commentCount, item.commentsCount, item.replyCount, Array.isArray(item.commentsList) ? item.commentsList.length : 0, 0));
        const shares = isAd ? 0 : parseNum(firstNonEmpty(item.ves_shares, item.shareCount, item.sharesCount, item.retweetCount, item.repinCount, 0));
        const saves = isAd ? 0 : parseNum(firstNonEmpty(item.ves_saves, item.saves, item.saves_or_collects, item.collects, item.collectCount, item.bookmarks, item.bookmarkCount, item.saveCount, item.save_count, item.repinCount, 0));
        const defaultMetric = ({
            tiktok: ['Plays', 'plays'],
            youtube: ['Plays', 'plays'],
            instagram: ['Likes', 'likes'],
            facebook: ['Likes', 'likes'],
            twitter: ['Likes', 'likes'],
            linkedin: ['Profiles', 'profiles'],
            reddit: ['Upvotes', 'likes'],
            pinterest: ['Saves', 'saves'],
            semrush: ['SEO metric', 'authority'],
            moz: ['Authority', 'authority'],
            ahrefs: ['SEO metric', 'authority'],
            facebook_ads: ['Impressions', 'views'],
            google_ads: ['Impressions', 'views']
        })[platform] || ['Views', 'views'];
        const primaryMetricLabel = firstNonEmpty(item.ves_primary_metric_label, defaultMetric[0]);
        const primaryMetricKey = firstNonEmpty(item.ves_primary_metric_key, defaultMetric[1]);
        const primaryMetricValue = parseNum(firstNonEmpty(item.ves_primary_metric_value, (primaryMetricKey === 'saves') ? saves : (primaryMetricKey === 'likes') ? likes : (primaryMetricKey === 'shares' ? shares : views)));
        let title = isAd ? fbTitle : firstNonEmpty(cleanTemplateText(item.ves_title), firstPathText(item, ['title', 'subtitle', 'name', 'text', 'caption', 'caption.text', 'message', 'description', 'richSummary', 'seoTitle', 'seoDescription', 'fullText', 'pageName', 'page_name']), '');
        const author = isAd ? fbAuthor : firstNonEmpty(cleanTemplateText(item.ves_author), firstPathText(item, ['authorMeta.nickName','authorMeta.nickname','authorMeta.name','author.name','author.username','channel.name','channel.username','pinner.username','pinner.name','user.username','user.name','creator.username','creator.name','authorName','username','channelName','channelTitle','channelUsername','pageName','page.name','user.name','from.name','ownerUsername','ownerFullName','advertiserName']), '');
        if (!title || title.toLowerCase() === 'resultado') title = displayFallbackTitle(platform, author, firstNonEmpty(item.id, item.shortCode, item.videoId, ''));
        // v0.9.27.0 — Phase 2: thread the canonical media normalizer in front
        // of the legacy findImageUrl path. We try normalizeMedia first; if it
        // returns a usable thumbnail, use it. Otherwise we fall back to the
        // legacy chain so we don't regress any working provider adapter.
        let mediaCanonical = null;
        try {
            // VES_normalizeMedia is exposed on window from the v0.9.26.0 block.
            const mn = (typeof normalizeMedia === 'function') ? normalizeMedia : (typeof window !== 'undefined' ? window.VES_normalizeMedia : null);
            if (typeof mn === 'function') {
                mediaCanonical = mn(item, { platform });
            }
        } catch (e) { /* defensive */ }
        const canonicalThumb = mediaCanonical && mediaCanonical.thumbnail_url
            ? safeUrl(mediaCanonical.thumbnail_url)
            : '';
        const thumb = canonicalThumb || (isAd
            ? safeUrl(firstNonEmpty(item.ves_thumb, firstPathText(item, ['imageUrl', 'image', 'images.0', 'thumbnail', 'thumbnailUrl', 'thumbnail_url', 'previewImageUrl', 'snapshot.images.0.original_image_url', 'snapshot.images.0.resized_image_url', 'snapshot.cards.0.original_image_url']), findImageUrl(item), ''))
            : safeUrl(firstNonEmpty(item.ves_thumb, firstPathText(item, ['coverUrl','cover_url','cover','thumbnailUrl','thumbnail_url','thumbnail','thumbnailDynamic','dynamicCover','originCover','videoMeta.coverUrl','videoMeta.originalCoverUrl','video.cover','video.thumbnail','channel.avatar','displayUrl','imageUrl','imageUrls.original','imageUrls.736x','image','images.0.url','images.0','covers.0','media.0.thumbnail','author.profilePictureUrl','profilePicUrl','channelAvatarUrl']), findImageUrl(item), '')));
        const mediaStatus = mediaCanonical ? mediaCanonical.preview_status : (thumb ? 'available' : 'missing');
        const mediaIsVideo = !!(mediaCanonical && mediaCanonical.type === 'video');
        return {
            url: safeUrl(isAd ? firstNonEmpty(item.ves_url, item.landingPageUrl, item.landingUrl, item.adTransparencyUrl, firstPathText(item, ['previewUrls.0']), item.ad_library_url, item.adLibraryUrl, item.ad_snapshot_url, item.adSnapshotUrl, item.url, isFbAd && archiveId ? `https://www.facebook.com/ads/library/?id=${archiveId}` : '') : firstNonEmpty(item.ves_url, item.postPage, item.url, item.link, item.webVideoUrl, item.videoUrl, item.ad_snapshot_url, item.adSnapshotUrl, '#')),
            thumb,
            // v0.9.27.0 — Phase 2: canonical media object exposed for card renderers.
            media: mediaCanonical || { type: thumb ? 'image' : 'unknown', thumbnail_url: thumb || null, preview_url: thumb || null, source_url: null, embed_url: null, duration: null, width: null, height: null, alt: title || null, provider_media_fields_found: [], preview_status: mediaStatus },
            mediaStatus,
            mediaIsVideo,
            title,
            author,
            date: isAd ? firstNonEmpty(item.ves_date, item.end_date_formatted, item.ad_delivery_start_time, item.ad_creation_time, item.createdAt, '') : firstNonEmpty(item.ves_date, item.uploadedAtFormatted, item.uploadedAt, item.timestamp, item.createdAt, item.ad_creation_time, ''),
            text: isAd ? fbText : firstNonEmpty(cleanTemplateText(item.ves_text), firstPathText(item, ['subtitle', 'description', 'caption', 'caption.text', 'text', 'message', 'postText', 'content', 'fullText', 'body', 'ad_creative_bodies.0']), ''),
            transcript: firstNonEmpty(item.ves_transcript, item.transcript, item.captionText, ''),
            views,
            likes,
            comments,
            shares,
            saves,
            duration: firstNonEmpty(item.ves_duration, item.duration, item.videoDuration, firstPathText(item, ['video.duration','videoMeta.duration']), ''),
            primaryMetricLabel,
            primaryMetricKey,
            primaryMetricValue,
            hasPrimaryMetric: isAd ? (primaryMetricValue > 0 || hasFbAdsImpressionField(item)) : !!parseNum(firstNonEmpty(item.ves_has_primary_metric, primaryMetricValue > 0 ? 1 : 0)),
            hasLikeMetric: isAd ? false : !!parseNum(firstNonEmpty(item.ves_has_like_metric, likes > 0 ? 1 : 0)),
            relevanceScore: parseNum(firstNonEmpty(item.ves_relevance_score, 0)),
            relevanceClass: String(firstNonEmpty(item.ves_relevance_class, '')),
            relevanceLabel: String(firstNonEmpty(item.ves_relevance_label, '')),
            relevanceFlag: String(firstNonEmpty(item.ves_relevance_flag, '')),
            relevanceExplanation: String(firstNonEmpty(item.ves_relevance_explanation, '')),
            raw: item,
        };
    };

    const metricPreference = (platform) => ({
        tiktok: { key:'plays', label:'plays' },
        youtube: { key:'plays', label:'plays' },
        instagram: { key:'likes', label:'likes' },
        facebook: { key:'likes', label:'likes' },
        twitter: { key:'likes', label:'likes' },
        linkedin: { key:'profiles', label:'profiles' },
        reddit: { key:'likes', label:'upvotes' },
        pinterest: { key:'saves', label:'saves' },
        semrush: { key:'authority', label:'seo metric' },
        moz: { key:'authority', label:'authority' },
        ahrefs: { key:'authority', label:'seo metric' },
        facebook_ads: { key:'impressions', label:'impressions' },
        google_ads: { key:'impressions', label:'impressions' }
    }[platform] || { key:'views', label:'views' });

    const filterMetricValue = (platform, c) => {
        const pref = metricPreference(platform);
        if (pref.key === 'likes') return c.likes;
        if (pref.key === 'plays') return c.primaryMetricKey === 'plays' ? c.primaryMetricValue : c.views;
        if (pref.key === 'impressions') return c.primaryMetricValue;
        if (pref.key === 'profiles') return c.primaryMetricValue || 1;
        return c.primaryMetricValue || c.views;
    };

    const hasFilterMetric = (platform, c) => {
        const pref = metricPreference(platform);
        if (pref.key === 'likes') return c.hasLikeMetric || c.likes > 0 || c.primaryMetricKey === 'likes';
        if (pref.key === 'profiles') return true;
        return c.hasPrimaryMetric || filterMetricValue(platform, c) > 0;
    };

    const responseMinimumMetric = (data) => {
        if (!data || typeof data !== 'object') return 0;
        const locked = data.locked_constraints || data.execution_plan?.locked_constraints || {};
        const raw = firstNonEmpty(
            locked.minimum_metric,
            data.minimum_metric,
            data.metricThreshold,
            data.query_diagnostics?.minimum_metric,
            data.query_diagnostics?.metricThreshold,
            0
        );
        const n = parseHumanNumber(raw);
        return n > 0 ? n : 0;
    };

    const currentFormMinimumMetric = (widget) => {
        const activePage = widget?.querySelector?.('.ves-page.is-active') || widget;
        const form = activePage?.querySelector?.('form.ves-scraper-form') || widget?.querySelector?.('form.ves-scraper-form') || null;
        const raw = firstNonEmpty(
            form?.querySelector?.('[name="minViews"]')?.value,
            form?.querySelector?.('[name="min_views"]')?.value,
            form?.querySelector?.('[name="minimumViews"]')?.value,
            form?.querySelector?.('[name="minimum_views"]')?.value,
            form?.querySelector?.('[name="minMetric"]')?.value,
            form?.querySelector?.('[name="min_metric"]')?.value,
            form?.querySelector?.('[name="minimumMetric"]')?.value,
            form?.querySelector?.('[name="minimum_metric"]')?.value,
            form?.querySelector?.('[name="minLikes"]')?.value,
            form?.querySelector?.('[name="min_likes"]')?.value,
            0
        );
        const n = parseHumanNumber(raw);
        return n > 0 ? n : 0;
    };

    const responseBypassesMetricGate = (data) => {
        const locked = data?.locked_constraints || data?.execution_plan?.locked_constraints || {};
        const mode = String(firstNonEmpty(locked.target_type, data?.target_type, data?.mode, '')).toLowerCase();
        return ['url_posts','post_urls','post_url'].includes(mode);
    };

    const applyResponseMetricGate = (platform, items, data) => {
        items = Array.isArray(items) ? items : [];
        const minMetric = responseMinimumMetric(data);
        if (!minMetric || responseBypassesMetricGate(data)) return items;
        return items.filter((item) => {
            const c = normalizeItem(platform, item);
            return hasFilterMetric(platform, c) && filterMetricValue(platform, c) >= minMetric;
        });
    };

    const primaryMetricText = (c) => {
        if (!c?.hasPrimaryMetric) return '';
        const label = String(c.primaryMetricLabel || 'Views');
        const key = String(c.primaryMetricKey || '').toLowerCase();
        const low = label.toLowerCase();
        if (key === 'likes' || low === 'likes') return `♥ ${escapeHtml(nfmt(c.primaryMetricValue))}`;
        if (key === 'plays' || low === 'plays') return `▶ ${escapeHtml(nfmt(c.primaryMetricValue))}`;
        if (low === 'views') return `👁 ${escapeHtml(nfmt(c.primaryMetricValue))}`;
        if (low === 'retweets') return `↗ ${escapeHtml(nfmt(c.primaryMetricValue))}`;
        if (key === 'profiles' || low === 'profiles') return `👤 ${escapeHtml(nfmt(c.primaryMetricValue || 1))}`;
        return `${escapeHtml(label)}: ${escapeHtml(nfmt(c.primaryMetricValue))}`;
    };

    const itemSelectionKey = (platform, item) => {
        const c = normalizeItem(platform, item || {});
        return [platform, c.url || '', c.title || '', c.author || '', c.date || ''].join('|').slice(0, 420);
    };


    const isSeoPlatform = (platform) => ['semrush', 'ahrefs', 'moz', 'answer_the_public', 'google_search', 'seo'].includes(String(platform || '').toLowerCase());

    const seoField = (item, keys, fallback = '') => {
        item = item || {};
        for (const key of keys) {
            const v = key.includes('.') ? firstPathText(item, [key]) : firstNonEmpty(item?.[key], '');
            if (v !== null && v !== undefined && String(v).trim() !== '') return v;
        }
        return fallback;
    };

    const seoNumber = (value) => {
        if (value === null || value === undefined || value === '') return 0;
        if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
        const raw = String(value).replace(/[^0-9.,-]/g, '').replace(/,(?=\d{3}\b)/g, '').replace(',', '.');
        const n = Number(raw);
        return Number.isFinite(n) ? n : 0;
    };

    const inferSeoIntent = (keyword, item = {}) => {
        const explicit = seoField(item, ['intent', 'searchIntent', 'keywordIntent', 'ves_intent'], '');
        if (explicit) return String(explicit).toLowerCase();
        const k = String(keyword || '').toLowerCase();
        if (/precio|price|cost|comprar|buy|coupon|discount|software|tool|agency|service|near me|demo/.test(k)) return 'commercial';
        if (/best|mejor|vs|versus|compare|comparativa|alternativa|review|opiniones/.test(k)) return 'commercial / comparison';
        if (/how|what|why|qué|como|cómo|guía|tutorial|examples|ideas|ejemplos/.test(k)) return 'informational';
        return 'informational';
    };

    const seoDataType = (item = {}) => String(firstNonEmpty(item.data_type, item.type, item.ves_data_type, item._ves_seo_data_type, item._ves_canonical_schema, '')).toLowerCase();

    const isSeoDomainRow = (item = {}) => {
        const type = seoDataType(item);
        if (/domain_authority|domain_overview|domain_seo|website_traffic|backlinks|competitors|seo_domain/.test(type)) return true;
        const hasDomainEvidence = firstNonEmpty(item.domain, item.root_domain, item.target_domain, item.url, '') && firstNonEmpty(item.authority_score, item.authorityScore, item.moz_domain_authority, item.mozDomainAuthority, item.moz_spam_score, item.traffic, item.backlinks, '') !== '';
        const hasKeyword = firstNonEmpty(item.keyword, item.query, item.searchQuery, item.term, item.phrase, '');
        return !!hasDomainEvidence && !hasKeyword;
    };

    const normalizeSeoItem = (platform, item = {}) => {
        const type = seoDataType(item);
        if (isSeoDomainRow(item)) {
            const domainRaw = firstNonEmpty(seoField(item, ['domain', 'root_domain', 'target_domain', 'url'], ''), '—');
            const domain = cleanTemplateText(String(domainRaw).replace(/^https?:\/\//i, '').replace(/\/.*$/, ''));
            const authority = seoNumber(firstNonEmpty(seoField(item, ['authority_score', 'authorityScore', 'authority', 'authorityScoreValue'], ''), 0));
            const mozDa = seoNumber(firstNonEmpty(seoField(item, ['moz_domain_authority', 'mozDomainAuthority', 'moz_da', 'domain_authority', 'da'], ''), 0));
            const spam = firstNonEmpty(seoField(item, ['moz_spam_score', 'mozSpamScore', 'spam_score', 'spamScore'], ''), '—');
            const traffic = seoNumber(firstNonEmpty(seoField(item, ['traffic', 'organic_traffic', 'organicTraffic', 'estimated_traffic'], ''), 0));
            const backlinks = seoNumber(firstNonEmpty(seoField(item, ['backlinks', 'total_backlinks', 'backlinks_count', 'referring_domains'], ''), 0));
            return {
                platform,
                rowType: 'domain',
                dataType: type || 'domain_authority',
                domain,
                keyword: domain,
                cluster: 'Dominio',
                intent: 'domain evidence',
                volume: traffic,
                difficulty: 0,
                cpc: 0,
                serpType: '—',
                url: safeUrl(firstNonEmpty(seoField(item, ['url', 'domain_url', 'sourceUrl'], ''), domain ? 'https://' + domain : '')),
                opportunityScore: Math.max(authority, mozDa, traffic > 0 ? 60 : 0),
                contentType: 'Informe de dominio',
                priority: authority || mozDa ? 'Valid' : 'Partial',
                why: cleanTemplateText(firstNonEmpty(item.ves_relevance_explanation, item.reason, 'Evidencia de dominio válida; no se filtra como keyword.')),
                authorityScore: authority,
                mozDomainAuthority: mozDa,
                mozSpamScore: String(spam || '—'),
                traffic,
                backlinks,
                competitors: firstNonEmpty(item.competitors, item.competitor_domains, item.competitorDomains, ''),
                capturedAt: firstNonEmpty(item.captured_at, item.data_captured_at, item.scrapedAt, ''),
                lastUpdated: firstNonEmpty(item.last_updated, item.lastUpdated, item.updatedAt, ''),
                moduleAvailability: item.module_availability || item.modules || null,
                raw: item
            };
        }
        if (/ai_visibility|aeo|geo_visibility/.test(type)) {
            return {
                platform, rowType: 'ai_visibility', dataType: type || 'ai_visibility',
                model: firstNonEmpty(item.model, item.source, item.engine, 'AI Search'),
                query: cleanTemplateText(firstNonEmpty(item.query, item.keyword, item.prompt, '—')),
                citedDomain: cleanTemplateText(firstNonEmpty(item.cited_domain, item.citedDomain, item.domain, item.url, '—')),
                status: cleanTemplateText(firstNonEmpty(item.mention_status, item.citation_status, item.status, '—')),
                visibilityScore: seoNumber(firstNonEmpty(item.visibility_score, item.visibilityScore, item.score, 0)),
                keyword: cleanTemplateText(firstNonEmpty(item.query, item.keyword, 'AI visibility')),
                cluster: 'AEO/GEO', intent: 'visibility', volume: 0, difficulty: 0, cpc: 0, serpType: 'AI', url: safeUrl(firstNonEmpty(item.url, '')), opportunityScore: seoNumber(firstNonEmpty(item.visibility_score, item.visibilityScore, item.score, 0)), contentType: 'Visibilidad AI', priority: 'Valid', why: cleanTemplateText(firstNonEmpty(item.reason, item.ves_relevance_explanation, 'Evidencia de visibilidad AI')), raw: item
            };
        }
        if (/top_websites|top_website/.test(type)) {
            return {
                platform, rowType: 'top_websites', dataType: type || 'top_websites',
                rank: seoNumber(firstNonEmpty(item.rank, item.position, 0)),
                domain: cleanTemplateText(firstNonEmpty(item.domain, item.root_domain, item.url, '—')),
                category: cleanTemplateText(firstNonEmpty(item.category, item.industry, '—')),
                country: cleanTemplateText(firstNonEmpty(item.country, item.database, '—')),
                traffic: seoNumber(firstNonEmpty(item.traffic, item.visits, item.estimated_traffic, 0)),
                authorityScore: seoNumber(firstNonEmpty(item.authority_score, item.authorityScore, 0)),
                keyword: cleanTemplateText(firstNonEmpty(item.domain, item.url, 'Top website')),
                cluster: 'Top websites', intent: 'market', volume: seoNumber(firstNonEmpty(item.traffic, item.visits, 0)), difficulty: 0, cpc: 0, serpType: '—', url: safeUrl(firstNonEmpty(item.url, '')), opportunityScore: seoNumber(firstNonEmpty(item.authority_score, item.authorityScore, 0)), contentType: 'Top websites', priority: 'Valid', why: 'Ranking de sitios.', raw: item
            };
        }
        const keyword = cleanTemplateText(firstNonEmpty(
            seoField(item, ['ves_keyword', 'keyword', 'query', 'searchQuery', 'term', 'phrase', 'title', 'name'], ''),
            'Keyword'
        ));
        const volume = seoNumber(firstNonEmpty(seoField(item, ['ves_search_volume', 'searchVolume', 'search_volume', 'volume', 'avgMonthlySearches', 'monthlySearches'], ''), 0));
        const difficulty = seoNumber(firstNonEmpty(seoField(item, ['ves_difficulty', 'keywordDifficulty', 'difficulty', 'seoDifficulty', 'kd', 'competition'], ''), 0));
        const cpc = seoNumber(firstNonEmpty(seoField(item, ['ves_cpc', 'cpc', 'costPerClick', 'averageCpc'], ''), 0));
        const intent = inferSeoIntent(keyword, item);
        const cluster = cleanTemplateText(firstNonEmpty(seoField(item, ['ves_cluster', 'cluster', 'group', 'topic', 'parentTopic'], ''), keyword.split(' ').slice(0, 2).join(' ')));
        const serpType = cleanTemplateText(firstNonEmpty(seoField(item, ['ves_serp_type', 'serpType', 'serpFeatures', 'features'], ''), 'SERP estándar'));
        const url = safeUrl(firstNonEmpty(seoField(item, ['ves_url', 'url', 'serpUrl', 'sourceUrl', 'link'], ''), ''));
        const relevance = seoNumber(firstNonEmpty(item.ves_relevance_score, item.relevanceScore, 0));
        const commercialBoost = /commercial|comparison|transactional/i.test(intent) ? 18 : 0;
        const lowCompetitionBoost = difficulty ? Math.max(0, 35 - Math.min(35, difficulty)) : 12;
        const opportunityScore = Math.max(0, Math.min(100, Math.round(relevance || (Math.log10(Math.max(volume, 1)) * 13 + commercialBoost + lowCompetitionBoost + (cpc > 0 ? Math.min(15, cpc * 2) : 0)))));
        const contentType = /question|how|what|why|qué|como|cómo/i.test(keyword) ? 'FAQ / guía práctica' : (/vs|versus|best|mejor|alternativa|compare|comparativa/i.test(keyword) ? 'Página comparativa' : (/precio|comprar|buy|service|agency|tool/i.test(keyword) ? 'Landing comercial' : 'Artículo educativo'));
        const priority = opportunityScore >= 75 ? 'Alta' : (opportunityScore >= 45 ? 'Media' : 'Baja');
        const why = cleanTemplateText(firstNonEmpty(item.ves_relevance_explanation, item.why, item.reason, item.description, `${volume ? nfmt(volume) + ' búsquedas' : 'Demanda de búsqueda'} · intención ${intent}`));
        return { platform, rowType: 'keyword', dataType: type || 'keyword_volume', keyword, cluster, intent, volume, difficulty, cpc, serpType, url, opportunityScore, contentType, priority, why, raw: item };
    };

    const renderDomainSeoResults = (entries, discarded = []) => {
        const renderCard = (entry) => {
            const seo = entry.seo;
            const key = itemSelectionKey('semrush', entry.item);
            const mods = seo.moduleAvailability ? JSON.stringify(seo.moduleAvailability).slice(0, 180) : '';
            return `<article class="ves-seo-domain-card${entry.bucket === 'discarded' ? ' is-discarded-match' : ''}" data-selection-key="${escapeHtml(key)}"><div class="ves-seo-domain-head"><label class="ves-select-row"><input type="checkbox" class="ves-select-item" data-index="${entry.idx}" data-key="${escapeHtml(key)}"><span></span></label><div><h3>${escapeHtml(seo.domain || 'Dominio')}</h3><p>${escapeHtml(seo.dataType || 'domain_authority')}</p></div>${seo.url ? `<a class="ves-mini-btn" href="${escapeHtml(seo.url)}" target="_blank" rel="noopener">Ver dominio</a>` : ''}</div><div class="ves-seo-domain-grid"><div><small>Authority score</small><strong>${escapeHtml(seo.authorityScore || '—')}</strong></div><div><small>MOZ DA</small><strong>${escapeHtml(seo.mozDomainAuthority || '—')}</strong></div><div><small>Spam score</small><strong>${escapeHtml(seo.mozSpamScore || '—')}</strong></div><div><small>Tráfico</small><strong>${seo.traffic ? escapeHtml(nfmt(seo.traffic)) : '—'}</strong></div><div><small>Backlinks</small><strong>${seo.backlinks ? escapeHtml(nfmt(seo.backlinks)) : '—'}</strong></div><div><small>Competidores</small><strong>${seo.competitors ? escapeHtml(String(Array.isArray(seo.competitors) ? seo.competitors.length : seo.competitors)) : '—'}</strong></div></div><p class="ves-seo-domain-note">${escapeHtml(seo.why || 'Evidencia parcial pero válida de dominio.')}</p>${(seo.capturedAt || seo.lastUpdated) ? `<p class="ves-meta">Capturado: ${escapeHtml(seo.capturedAt || '—')} · Actualizado: ${escapeHtml(seo.lastUpdated || '—')}</p>` : ''}${mods ? `<details class="ves-source-input-details"><summary>Estado de módulos</summary><pre>${escapeHtml(mods)}</pre></details>` : ''}<div class="ves-card-actions"><button type="button" class="ves-mini-btn ves-analyze-item" data-index="${entry.idx}">Analizar dominio</button></div></article>`;
        };
        let html = `<div class="ves-seo-results ves-seo-domain-results"><div class="ves-seo-results-head"><div><span class="ves-section-eyebrow">SEO / SEM / AEO</span><h3>Datos de dominio / SEO</h3><p>Evidencia de autoridad, tráfico y módulos disponibles. Las filas de dominio no se filtran como keywords.</p></div><strong>${escapeHtml(String(entries.length))}</strong></div><div class="ves-seo-domain-list">${entries.map(renderCard).join('')}</div>`;
        if (discarded.length) html += `<details class="ves-result-section ves-discarded-section"><summary><span><span class="ves-section-eyebrow">Filtrado</span><strong>Ver filas SEO descartadas</strong><small>Filas de dominio o SEO no usadas por baja calidad o duplicado.</small></span><b>${escapeHtml(String(discarded.length))}</b></summary><div class="ves-seo-domain-list">${discarded.map(renderCard).join('')}</div></details>`;
        return html + '</div>';
    };

    const renderKeywordSeoResults = (platform, entries, discarded = []) => {
        if (!entries.length && !discarded.length) {
            return `<div class="ves-empty ves-empty-results ves-seo-empty"><strong>No hay filas SEO útiles con los filtros actuales.</strong><span>La fuente pudo devolver datos parciales o los filtros locales eliminaron las keywords. Prueba una keyword más amplia, otro país/idioma o un fallback de Google Search.</span><div class="ves-empty-actions"><button type="button" class="ves-mini-btn ves-rerun-suggestion" data-suggestion="broaden_keyword">Probar keyword más amplia</button><button type="button" class="ves-mini-btn ves-rerun-suggestion" data-suggestion="google_search">Probar Google Search</button><button type="button" class="ves-mini-btn ves-ai-seed-ideas">Generar ideas relacionadas</button></div></div>`;
        }
        const renderRow = (entry) => {
            const seo = entry.seo;
            const key = itemSelectionKey(platform, entry.item);
            const badgeClass = String(seo.priority || '').toLowerCase();
            return `<tr class="${entry.bucket === 'discarded' ? 'is-discarded-match' : ''}" data-selection-key="${escapeHtml(key)}"><td><label class="ves-select-row"><input type="checkbox" class="ves-select-item" data-index="${entry.idx}" data-key="${escapeHtml(key)}"><span></span></label></td><td><strong>${escapeHtml(seo.keyword)}</strong>${seo.url ? `<a href="${escapeHtml(seo.url)}" target="_blank" rel="noopener">Ver fuente</a>` : ''}<small>${escapeHtml(seo.why)}</small></td><td>${escapeHtml(seo.cluster || '—')}</td><td><span class="ves-intent-pill">${escapeHtml(seo.intent || '—')}</span></td><td class="is-number">${escapeHtml(nfmt(seo.volume))}</td><td class="is-number">${seo.cpc ? '€' + escapeHtml(String(seo.cpc.toFixed ? seo.cpc.toFixed(2) : seo.cpc)) : '—'}</td><td class="is-number">${seo.difficulty ? escapeHtml(String(Math.round(seo.difficulty))) : '—'}</td><td><span class="ves-score-pill">${escapeHtml(String(seo.opportunityScore))}</span></td><td>${escapeHtml(seo.serpType || '—')}</td><td>${escapeHtml(seo.contentType)}</td><td><span class="ves-priority-pill is-${escapeHtml(badgeClass)}">${escapeHtml(seo.priority)}</span></td><td class="ves-seo-actions"><button type="button" class="ves-mini-btn ves-analyze-item" data-index="${entry.idx}">Analizar keyword</button><button type="button" class="ves-mini-btn ves-generate-brief" data-index="${entry.idx}">Generar brief SEO</button><button type="button" class="ves-mini-btn ves-build-cluster" data-index="${entry.idx}">Crear cluster</button></td></tr>`;
        };
        const header = `<thead><tr><th></th><th>Keyword</th><th>Cluster</th><th>Intención</th><th>Volumen de búsqueda</th><th>CPC</th><th>Dificultad</th><th>Puntuación de oportunidad</th><th>Tipo SERP</th><th>Contenido recomendado</th><th>Prioridad</th><th>Acciones</th></tr></thead>`;
        let html = `<div class="ves-seo-results"><div class="ves-seo-results-head"><div><span class="ves-section-eyebrow">SEO / SEM / AEO</span><h3>Oportunidades de keywords</h3><p>Keywords priorizadas con campos SEO. No se muestran métricas sociales ni placeholders de preview.</p></div><strong>${escapeHtml(String(entries.length))}</strong></div><div class="ves-seo-table-wrap"><table class="ves-seo-table">${header}<tbody>${entries.map(renderRow).join('')}</tbody></table></div>`;
        if (discarded.length) html += `<details class="ves-result-section ves-discarded-section"><summary><span><span class="ves-section-eyebrow">Filtrado</span><strong>Mostrar keywords descartadas</strong><small>Filas eliminadas por filtros, duplicado o evidencia débil.</small></span><b>${escapeHtml(String(discarded.length))}</b></summary><div class="ves-seo-table-wrap"><table class="ves-seo-table">${header}<tbody>${discarded.map(renderRow).join('')}</tbody></table></div></details>`;
        return html + '</div>';
    };

    const renderAiVisibilitySeoResults = (entries) => `<div class="ves-seo-results"><div class="ves-seo-results-head"><div><span class="ves-section-eyebrow">AEO / GEO</span><h3>Visibilidad en IA</h3><p>Menciones o citas detectadas en fuentes AI-search cuando la fuente las entrega.</p></div><strong>${escapeHtml(String(entries.length))}</strong></div><div class="ves-seo-table-wrap"><table class="ves-seo-table"><thead><tr><th>Modelo/fuente</th><th>Consulta</th><th>Dominio citado</th><th>Estado</th><th>Score</th></tr></thead><tbody>${entries.map(e => `<tr><td>${escapeHtml(e.seo.model)}</td><td>${escapeHtml(e.seo.query)}</td><td>${escapeHtml(e.seo.citedDomain)}</td><td>${escapeHtml(e.seo.status)}</td><td>${escapeHtml(String(e.seo.visibilityScore || '—'))}</td></tr>`).join('')}</tbody></table></div></div>`;

    const renderTopWebsitesSeoResults = (entries) => `<div class="ves-seo-results"><div class="ves-seo-results-head"><div><span class="ves-section-eyebrow">Top websites</span><h3>Sitios principales</h3><p>Ranking de dominios cuando la fuente devuelve este tipo de salida.</p></div><strong>${escapeHtml(String(entries.length))}</strong></div><div class="ves-seo-table-wrap"><table class="ves-seo-table"><thead><tr><th>Rank</th><th>Dominio</th><th>Categoría</th><th>País</th><th>Tráfico</th><th>Autoridad</th></tr></thead><tbody>${entries.map(e => `<tr><td>${escapeHtml(String(e.seo.rank || '—'))}</td><td>${escapeHtml(e.seo.domain)}</td><td>${escapeHtml(e.seo.category)}</td><td>${escapeHtml(e.seo.country)}</td><td>${e.seo.traffic ? escapeHtml(nfmt(e.seo.traffic)) : '—'}</td><td>${escapeHtml(String(e.seo.authorityScore || '—'))}</td></tr>`).join('')}</tbody></table></div></div>`;

    const renderSeoResults = (platform, items, discardedItems = []) => {
        items = Array.isArray(items) ? items : [];
        discardedItems = Array.isArray(discardedItems) ? discardedItems : [];
        const all = items.map((item, idx) => ({ item, idx, seo: normalizeSeoItem(platform, item), bucket: String(item?.ves_result_bucket || 'best') }));
        const discarded = discardedItems.map((item, i) => ({ item: { ...(item || {}), ves_result_bucket: 'discarded' }, idx: items.length + i, seo: normalizeSeoItem(platform, item), bucket: 'discarded' }));
        const domainRows = all.filter(e => e.seo.rowType === 'domain');
        if (domainRows.length && domainRows.length >= all.length) return renderDomainSeoResults(domainRows, discarded.filter(e => e.seo.rowType === 'domain'));
        const aiRows = all.filter(e => e.seo.rowType === 'ai_visibility');
        if (aiRows.length && aiRows.length >= all.length) return renderAiVisibilitySeoResults(aiRows);
        const topRows = all.filter(e => e.seo.rowType === 'top_websites');
        if (topRows.length && topRows.length >= all.length) return renderTopWebsitesSeoResults(topRows);
        const keywordRows = all.filter(e => e.seo.rowType === 'keyword');
        let html = '';
        if (domainRows.length) html += renderDomainSeoResults(domainRows, discarded.filter(e => e.seo.rowType === 'domain'));
        html += renderKeywordSeoResults(platform, keywordRows, discarded.filter(e => e.seo.rowType === 'keyword'));
        if (aiRows.length) html += renderAiVisibilitySeoResults(aiRows);
        if (topRows.length) html += renderTopWebsitesSeoResults(topRows);
        return html;
    };

    const selectedCountForWidget = (widget) => {
        const state = widgetState.get(widget);
        return Array.isArray(state?.selectedItemKeys) ? state.selectedItemKeys.length : 0;
    };

    const updateSelectionControls = (widget) => {
        const activePage = widget?.querySelector?.('.ves-page.is-active') || widget;
        const results = activePage?.querySelector?.('.ves-results') || widget?.querySelector?.('.ves-results');
        if (!results) return;
        const count = selectedCountForWidget(widget);
        results.querySelectorAll('.ves-selected-count').forEach((el) => { el.textContent = String(count); });
        const analyzeBtns = results.querySelectorAll('.ves-analyze-selected');
        const compareBtns = results.querySelectorAll('.ves-compare-selected');
        const briefBtns = results.querySelectorAll('.ves-brief-selected');
        const clearBtns = results.querySelectorAll('.ves-clear-selection');
        [...analyzeBtns, ...compareBtns, ...briefBtns, ...clearBtns].forEach((btn) => { btn.hidden = true; btn.disabled = true; });
        analyzeBtns.forEach((btn) => { btn.hidden = count === 0; btn.disabled = count === 0; btn.textContent = count > 1 ? 'Analizar seleccionados' : 'Analizar seleccionados'; });
        compareBtns.forEach((btn) => { btn.hidden = count < 2; btn.disabled = count < 2; btn.textContent = 'Comparar seleccionados'; });
        briefBtns.forEach((btn) => { btn.hidden = count < 2; btn.disabled = count < 2; btn.textContent = 'Generar brief de la selección'; });
        clearBtns.forEach((btn) => { btn.hidden = count < 2; btn.disabled = count < 2; btn.textContent = uiText(widget, 'clearSelection'); });
    };

    const redditLabelForItem = (raw, c) => {
        const text = String(firstNonEmpty(raw?.ves_text, raw?.body, raw?.text, raw?.selftext, c?.text, '')).toLowerCase();
        if (/\b(problem|pain|struggle|frustrat|annoy|hate|issue|bloqueo|dolor|problema|frustr)/i.test(text)) return 'Pain point';
        if (/\?\s*$|\b(how|why|what|where|when|which|can i|should i|cómo|por qué|que|qué|dónde|cuándo)\b/i.test(String(firstNonEmpty(raw?.title, c?.title, text)))) return 'Question';
        if (/\b(expensive|too much|doesn't work|does not work|no funciona|caro|objec|concern|doubt|risk)\b/i.test(text)) return 'Objection';
        return firstNonEmpty(raw?.ves_discussion_label, raw?.discussionLabel, raw?.intentLabel, 'Discussion');
    };

    const renderRedditResults = (platform, items, discardedItems = []) => {
        items = Array.isArray(items) ? items : [];
        discardedItems = Array.isArray(discardedItems) ? discardedItems : [];
        const all = [
            ...items.map((item, idx) => ({ item, idx, bucket: String(item?.ves_result_bucket || 'best') })),
            ...discardedItems.map((item, i) => ({ item: { ...(item || {}), ves_result_bucket: 'discarded' }, idx: items.length + i, bucket: 'discarded' }))
        ];
        if (!all.length) {
            return '<div class="ves-empty ves-empty-results"><strong>No hay discusiones útiles con los filtros actuales.</strong><span>Prueba una consulta más concreta, añade subreddits o baja el mínimo de upvotes/comentarios.</span></div>';
        }
        let html = '<div class="ves-reddit-list">';
        all.forEach((entry) => {
            const raw = entry.item || {};
            const c = normalizeItem('reddit', raw);
            const key = itemSelectionKey('reddit', raw);
            const subreddit = firstNonEmpty(raw.subreddit, raw.communityName, raw.parsedCommunityName, raw.subredditName, '—');
            const author = firstNonEmpty(c.author, raw.author, raw.username, '—');
            const quality = firstNonEmpty(raw.discussion_quality, raw.discussionCalidad, raw.ves_discussion_quality, c.relevanceScore ? `${Math.round(c.relevanceScore)}/10` : '—');
            const reason = firstNonEmpty(raw.relevance_reason, raw.ves_relevance_explanation, c.relevanceExplanation, raw.ves_relevance_label, 'Matched the requested Reddit query or community.');
            const sourceQuery = firstNonEmpty(raw.source_query, raw.searchQuery, raw.query, raw.input, '—');
            const label = redditLabelForItem(raw, c);
            const excluded = entry.bucket === 'discarded';
            html += `<article class="ves-reddit-card${excluded ? ' is-discarded-match' : ''}" data-selection-key="${escapeHtml(key)}">`;
            html += `<div class="ves-reddit-head"><label class="ves-select-row">${excluded ? '<span class="ves-excluded-card">Excluido del AI por defecto</span>' : `<input type="checkbox" class="ves-select-item" data-index="${entry.idx}" data-key="${escapeHtml(key)}"><span></span>`}</label><div><h3>${escapeHtml(c.title)}</h3><p>${escapeHtml([`r/${subreddit}`, `u/${author}`, c.date].filter(Boolean).join(' · '))}</p></div><span class="ves-reddit-label">${escapeHtml(label)}</span></div>`;
            if (c.text) html += `<p class="ves-reddit-excerpt">${escapeHtml(String(c.text).slice(0, 280))}${String(c.text).length > 280 ? '…' : ''}</p>`;
            html += `<div class="ves-reddit-metrics"><span>⬆ ${escapeHtml(nfmt(c.likes))}</span><span>💬 ${escapeHtml(nfmt(c.comments))}</span><span>Calidad ${escapeHtml(String(quality))}</span><span>Query ${escapeHtml(String(sourceQuery))}</span></div>`;
            html += `<div class="ves-reddit-reason"><strong>Why useful:</strong> ${escapeHtml(String(reason).slice(0, 220))}${String(reason).length > 220 ? '…' : ''}</div>`;
            html += `<div class="ves-card-actions">${c.url ? `<a class="ves-mini-btn" href="${escapeHtml(c.url)}" target="_blank" rel="noopener">Open source</a>` : ''}${excluded ? '<span class="ves-mini-note">Descartado: no analizar por defecto</span>' : `<button type="button" class="ves-mini-btn ves-analyze-item" data-index="${entry.idx}">Analyze discussion</button><button type="button" class="ves-mini-btn ves-use-as-insight" data-index="${entry.idx}">Use as insight</button><button type="button" class="ves-mini-btn ves-show-item" data-index="${entry.idx}">View details</button>`}</div>`;
            html += '</article>';
        });
        html += '</div>';
        return html;
    };

    // v0.9.25.0 — Phase 10: cleaner empty state with reason summary.
    // Maps internal discard codes to human-readable Spanish reasons.
    // Avoids leaking provider-specific labels (e.g. "no coin..." truncations).
    const DISCARD_REASON_LABELS = {
        low_relevance: 'No coincide con la marca o el tema buscado',
        language_mismatch: 'Idioma distinto al solicitado',
        country_mismatch: 'País distinto al solicitado',
        date_out_of_range: 'Fuera del rango de fechas',
        below_min_metric: 'Engagement por debajo del mínimo configurado',
        wrong_content_type: 'Tipo de contenido no compatible',
        missing_metadata: 'Faltan metadatos obligatorios',
        duplicate: 'Resultado duplicado',
        unsupported_format: 'Formato no compatible con esta vista',
        provider_noise: 'Resultado considerado ruido por la fuente',
        no_coin: 'No coincide con el filtro local',          // ← maps the leak
        nocoincide: 'No coincide con el filtro local',
        other: 'Otro motivo (ver diagnóstico admin)'
    };
    const summarizeDiscardReasons = (data) => {
        if (!data) return [];
        const out = [];
        if (data.dropped_by_language > 0) out.push({ count: data.dropped_by_language, label: DISCARD_REASON_LABELS.language_mismatch });
        if (data.dropped_by_country > 0)  out.push({ count: data.dropped_by_country,  label: DISCARD_REASON_LABELS.country_mismatch });
        if (data.dropped_by_date > 0)     out.push({ count: data.dropped_by_date,     label: DISCARD_REASON_LABELS.date_out_of_range });
        if (data.dropped_by_metric > 0)   out.push({ count: data.dropped_by_metric,   label: DISCARD_REASON_LABELS.below_min_metric });
        if (data.dropped_by_relevance > 0)out.push({ count: data.dropped_by_relevance, label: DISCARD_REASON_LABELS.low_relevance });
        return out.sort((a, b) => b.count - a.count);
    };
    const renderEmptyResultsCard = (platform, data, opts) => {
        const markerCount = Number(data?.no_result_marker_count ?? data?.discarded_no_result_marker_count ?? 0) || 0;
        const usableCount = Number(data?.usable_signal_count ?? data?.useful_delivered_rows ?? data?.displayed_rows ?? data?.all_items_count ?? 0) || 0;
        if (markerCount > 0 && usableCount === 0) {
            return `<div class="ves-empty ves-empty-results ves-empty-x-style">
                <strong>No hay señales utilizables.</strong>
                <span>La fuente no devolvió resultados utilizables para esta consulta.</span>
                <div class="ves-empty-tips"><small>Prueba con términos más amplios, menos filtros, otro idioma o un rango de fechas más extenso.</small></div>
            </div>`;
        }
        const received = Number(data?.raw_provider_returned_count || data?.fetched_dataset_count || 0) || 0;
        const reasons = summarizeDiscardReasons(data);
        const platformName = (CONFIG[platform]?.label) || platform || 'esta fuente';
        const reasonsHtml = reasons.length
            ? `<ul class="ves-empty-reasons">${reasons.slice(0, 4).map(r => `<li><b>${escapeHtml(String(r.count))}</b> ${escapeHtml(r.label)}</li>`).join('')}</ul>`
            : '';
        const isXLike = /twitter|^x$/i.test(platform);
        const hasLanguageMismatch = Number(data?.language_mismatch_count || data?.dropped_by_language || 0) > 0;
        const hasMetricDrop = Number(data?.dropped_by_metric || 0) > 0;
        const hasRelevanceDrop = Number(data?.dropped_by_relevance || 0) > 0;
        const tips = hasLanguageMismatch
            ? ['Reintentar con consulta en español', 'Permitir señales en otros idiomas', 'Cambiar idioma a “cualquier idioma”', 'Ampliar mercado', 'Usar búsqueda más específica']
            : (hasMetricDrop
                ? ['Reduce el mínimo de visualizaciones/engagement', 'Amplía la consulta o usa variantes cercanas', 'Cambia el orden a “mejor ajuste”']
                : (hasRelevanceDrop
                    ? ['Usa términos más específicos', 'Incluye marca, categoría o caso de uso', 'Prueba variantes en español e inglés']
                    : (isXLike
                        ? ['Prueba consultas en español e inglés', 'Usa hashtags o handles concretos (#mahou, @mahou)', 'Reduce el mínimo de engagement', 'Considera TikTok o Google Intelligence si X tiene señal débil']
                        : ['Amplía la consulta o usa variantes en otros idiomas', 'Reduce el mínimo de likes/views a 0', 'Cambia el orden a “mejor ajuste”', 'Prueba otra fuente si el tema es nicho'])));
        const bodyCopy = received > 0
            ? `La fuente devolvió resultados, pero ninguno pasó los filtros de relevancia, idioma, mercado o calidad.`
            : `La fuente no devolvió resultados para esta consulta.`;
        return `<div class="ves-empty ves-empty-results ves-empty-x-style">
            <strong>No hay señales utilizables para esta consulta.</strong>
            <span>${escapeHtml(bodyCopy)}</span>
            ${reasonsHtml}
            <div class="ves-empty-tips">
                <small>Sugerencias para volver a intentarlo:</small>
                <ul>${tips.map(t => `<li>${escapeHtml(t)}</li>`).join('')}</ul>
            </div>
            <div class="ves-empty-actions">
                <button type="button" class="ves-mini-btn ves-reset-local-filters">Borrar filtros locales</button>
                <button type="button" class="ves-mini-btn ves-rerun-suggestion" data-suggestion="lower_metric">Reintentar con filtros recomendados</button>
            </div>
        </div>`;
    };

    const renderCards = (platform, items, discardedItems = []) => {
        items = Array.isArray(items) ? items : [];
        discardedItems = Array.isArray(discardedItems) ? discardedItems : [];
        if (isSeoPlatform(platform)) return renderSeoResults(platform, items, discardedItems);
        if (platform === 'reddit') return renderRedditResults(platform, items, discardedItems);
        if (!items.length && !discardedItems.length) {
            // v0.9.25.0: try to surface backend discard summary in the empty card.
            const widget = document.querySelector('.ves-wrap');
            const state = widget ? widgetState.get(widget) : null;
            return renderEmptyResultsCard(platform, state?.lastData || {});
        }
        const renderGrid = (gridItems, offset = 0) => {
            let html = '<div class="ves-items">';
            gridItems.forEach((entry, localIdx) => {
                const hasStableIndex = entry && typeof entry === 'object' && Object.prototype.hasOwnProperty.call(entry, '__vesItem');
                const item = hasStableIndex ? entry.__vesItem : entry;
                const idx = hasStableIndex ? entry.__vesIndex : offset + localIdx;
                const c = normalizeItem(platform, item);
                const primary = primaryMetricText(c);
                const bucket = String(item?.ves_result_bucket || '');
                const key = itemSelectionKey(platform, item);
                const mediaBg = c.thumb ? cssUrlValue(c.thumb) : '';
                // v0.9.27.0 — Phase 2: render canonical media. Add a small video
                // badge when the normalizer flagged this as a video item, and
                // surface the title as alt text for accessibility.
                const videoBadge = c.mediaIsVideo
                    ? `<span class="ves-thumb-video-badge" aria-label="video">▶</span>`
                    : '';
                const altText = String(c.title || c.author || 'preview').slice(0, 120);
                const placeholderText = c.mediaStatus === 'blocked'
                    ? 'Vista previa bloqueada por la fuente'
                    : (platform === 'google_ads' ? 'Google Ad' : (platform === 'facebook_ads' ? 'Facebook Ad' : (platform === 'linkedin' ? 'LinkedIn' : (platform === 'reddit' ? 'Reddit' : (platform === 'semrush' ? 'Semrush' : (platform === 'pinterest' ? 'Pinterest' : (platform === 'moz' ? 'MOZ' : (platform === 'ahrefs' ? 'Ahrefs' : 'Sin preview'))))))));
                const media = c.thumb
                    ? `<div class="ves-thumb has-media"${mediaBg ? ` style="--ves-thumb-url:${escapeHtml(mediaBg)}"` : ''}><img src="${escapeHtml(c.thumb)}" alt="${escapeHtml(altText)}" loading="lazy" onerror="this.closest('.ves-thumb').classList.add('is-broken');this.remove();">${videoBadge}</div>`
                    : `<div class="ves-thumb ves-thumb-placeholder ves-thumb-compact"><span class="ves-platform-badge">${escapeHtml(platformLabel(platform))}</span><small>${escapeHtml(placeholderText || 'sin preview')}</small></div>`;
                const showSecondaryLikes = c.hasLikeMetric && !['likes','saves'].includes(c.primaryMetricKey);
                const stats = (platform === 'facebook_ads' || platform === 'google_ads')
                    ? `${primary ? `<span>${primary}</span>` : ''}`
                    : `${primary ? `<span>${primary}</span>` : ''}${showSecondaryLikes ? `<span>♥ ${escapeHtml(nfmt(c.likes))}</span>` : ''}<span>💬 ${escapeHtml(nfmt(c.comments))}</span>${c.primaryMetricKey !== 'shares' && c.shares ? `<span>↗ ${escapeHtml(nfmt(c.shares))}</span>` : ''}${c.duration ? `<span>⏱ ${escapeHtml(String(c.duration))}</span>` : ''}`;
                // v0.9.24.5: strip internal classifier tokens from Why shown text.
                // The explanation often contains raw tokens like 'canonical_brand_content:<brand>,
                // phrase_content:<brand>' which are internal signals and confuse end users.
                const cleanExplanation = c.relevanceExplanation
                    ? c.relevanceExplanation
                        .replace(/\s*[··]?\s*(canonical_brand_content|phrase_content|token_content|ves_score|semantic_match|context_match|brand_match|category_match|adjacent_context)[^,··;]*[,]?/gi, '')
                        .replace(/\s*[··]+\s*$/g, '').replace(/^\s*[··]+\s*/g, '').trim()
                    : '';
                // v0.9.31.5 BUG-02: surface the incidental-tag-match badge
                // when the backend flagged a post whose brand match comes only
                // from a hashtag/caption while the author is a different brand.
                const incidentalFlagHtml = c.relevanceFlag === 'incidental_tag_match'
                    ? `<span class="ves-relevance-flag ves-relevance-flag-incidental-tag">Mención de hashtag — no contenido de marca.</span>`
                    : '';
                const relevanceBadge = c.relevanceClass ? `<div class="ves-relevance-row"><span class="ves-relevance-badge is-${escapeHtml(c.relevanceClass)}">${escapeHtml(c.relevanceLabel || c.relevanceClass)}</span>${incidentalFlagHtml}${cleanExplanation ? `<small class="ves-why-shown">${escapeHtml(cleanExplanation)}</small>` : ''}</div>` : '';
                const cardClass = bucket === 'best' ? ' is-best-match' : (bucket === 'discarded' ? ' is-discarded-match' : ' is-secondary-match');
                const selectionControl = bucket === 'discarded' ? '<div class="ves-excluded-card">Excluido del AI por defecto</div>' : `<label class="ves-select-card"><input type="checkbox" class="ves-select-item" data-index="${idx}" data-key="${escapeHtml(key)}"><span>Seleccionar</span></label>`;
                const isAdPlatform = platform === 'facebook_ads' || platform === 'google_ads';
                const isArchiveIdOnly = isAdPlatform && c.text && String(c.text).startsWith('Archivar ID:');
                const adDegradedNote = isArchiveIdOnly ? `<div class="ves-hint" style="margin-top:6px;font-size:11px;color:var(--ves-muted,#888)">Datos limitados: solo se recibió identificador/archivo, sin copy ni métricas.</div>` : '';
                const cardActions = bucket === 'discarded'
                    ? `<div class="ves-card-actions">${c.url ? `<a class="ves-mini-btn" href="${escapeHtml(c.url)}" target="_blank" rel="noopener">Ver fuente</a>` : ''}<span class="ves-mini-note">Descartado: no analizar por defecto</span></div>`
                    : `<div class="ves-card-actions">${c.url ? `<a class="ves-mini-btn" href="${escapeHtml(c.url)}" target="_blank" rel="noopener">Ver fuente</a>` : ''}<button type="button" class="ves-mini-btn ves-show-item" data-index="${idx}">Ver detalles</button><button type="button" class="ves-mini-btn ves-analyze-item" data-index="${idx}">Analizar intención</button></div>`;
                html += `<div class="ves-item${cardClass}" data-selection-key="${escapeHtml(key)}">${bucket === 'best' ? '<div class="ves-match-ribbon">Mejor coincidencia</div>' : ''}${selectionControl}${media}<div class="ves-body"><div class="ves-title2">${escapeHtml(c.title)}</div>${relevanceBadge}${(c.author || c.date) ? `<div class="ves-meta">${escapeHtml([c.author, c.date].filter(Boolean).join(' · '))}</div>` : ''}${!isArchiveIdOnly && c.text ? `<div class="ves-text">${escapeHtml(c.text)}</div>` : ''}${adDegradedNote}<div class="ves-stats">${stats}</div>${c.transcript ? `<div class="ves-text"><strong>Transcripción:</strong> ${escapeHtml(String(c.transcript).slice(0, 220))}${String(c.transcript).length > 220 ? '…' : ''}</div>` : ''}${cardActions}</div></div>`;
            });
            return html + '</div>';
        };
        const best = [];
        const other = [];
        items.forEach((item, idx) => {
            const entry = { __vesItem: item, __vesIndex: idx };
            if (String(item?.ves_result_bucket || '') === 'other') other.push(entry);
            else best.push(entry);
        });
        const discarded = Array.isArray(discardedItems) ? discardedItems.map((item, idx) => ({ __vesItem: { ...(item || {}), ves_result_bucket: 'discarded' }, __vesIndex: items.length + idx })) : [];
        if (!other.length && !discarded.length) {
            return renderGrid(items.map((item, idx) => ({ __vesItem: item, __vesIndex: idx })), 0);
        }
        let html = '';
        let offset = 0;
        if (best.length) {
            html += `<section class="ves-result-section ves-best-section"><div class="ves-result-section-head"><div><span class="ves-section-eyebrow">Coincidencia directa</span><h3>Señales directas</h3><p>Estos resultados contienen coincidencia directa de marca/tema y son la primera capa de evidencia.</p></div><strong>${escapeHtml(String(best.length))}</strong></div>${renderGrid(best, offset)}</section>`;
            offset += best.length;
        }
        if (other.length) {
            html += `<section class="ves-result-section ves-other-section"><div class="ves-result-section-head"><div><span class="ves-section-eyebrow">Señales relacionadas</span><h3>Señales relacionadas o útiles</h3><p>Patrones cercanos o contexto útil. Úsalos como señal, no como conclusión.</p></div><strong>${escapeHtml(String(other.length))}</strong></div>${renderGrid(other, offset)}</section>`;
            offset += other.length;
        }
        if (discarded.length) {
            html += `<details class="ves-result-section ves-discarded-section"><summary><span><span class="ves-section-eyebrow">Descartados / ruido</span><strong>Ver descartados</strong><small>No alimentan el análisis por defecto.</small></span><b>${escapeHtml(String(discarded.length))}</b></summary>${renderGrid(discarded, offset)}</details>`;
        }
        return html;
    };

    const renderDetailPanel = (platform, item) => {
        const c = normalizeItem(platform, item);
        return `<div class="ves-item-panel"><div class="ves-item-panel-head"><div class="ves-item-panel-title">${escapeHtml(c.title)}</div></div><div class="ves-item-grid"><div class="ves-item-kv"><small>Autor</small><strong>${escapeHtml(c.author || '—')}</strong></div><div class="ves-item-kv"><small>Fecha</small><strong>${escapeHtml(c.date || '—')}</strong></div><div class="ves-item-kv"><small>Duración</small><strong>${escapeHtml(String(c.duration || '—'))}</strong></div>${c.hasPrimaryMetric ? `<div class="ves-item-kv"><small>${escapeHtml(c.primaryMetricLabel || 'Views')}</small><strong>${escapeHtml(nfmt(c.primaryMetricValue))}</strong></div>` : ''}${(c.hasLikeMetric && !['likes','saves'].includes(c.primaryMetricKey)) ? `<div class="ves-item-kv"><small>Likes</small><strong>${escapeHtml(nfmt(c.likes))}</strong></div>` : ''}<div class="ves-item-kv"><small>Comentarios</small><strong>${escapeHtml(nfmt(c.comments))}</strong></div></div>${c.text ? `<div class="ves-pre" style="margin-bottom:12px">${escapeHtml(c.text)}</div>` : ''}${c.transcript ? `<div class="ves-transcript-box"><strong style="display:block;margin-bottom:8px">Transcripción</strong>${escapeHtml(c.transcript)}</div>` : '<div class="ves-empty">No hay transcripción disponible en este resultado.</div>'}</div>`;
    };

    const analysisSectionTitle = (line) => {
        const t = String(line || '').replace(/^#{1,4}\s*/, '').replace(/[:：]\s*$/, '').trim().toLowerCase();
        const map = [
            ['overall verdict', 'Lectura general'], ['verdict', 'Lectura general'], ['winner', 'Mejor ejemplo'], ['best example', 'Mejor ejemplo'],
            ['pattern comparison', 'Comparación de patrón'], ['comparison', 'Comparación de patrón'], ['comparación', 'Comparación de patrón'], ['metric comparison', 'Comparación de métricas'], ['hook comparison', 'Comparación de hooks'],
            ['executive', 'Lectura ejecutiva'], ['resumen', 'Lectura ejecutiva'], ['lectura', 'Lectura ejecutiva'],
            ['hook', 'Hook y retención'], ['retention', 'Hook y retención'], ['retención', 'Hook y retención'],
            ['creative pattern', 'Patrón creativo'], ['pattern', 'Patrón creativo'], ['patrón', 'Patrón creativo'],
            ['metric', 'Métricas'], ['métrica', 'Métricas'], ['metrics', 'Métricas'],
            ['brand fit', 'Encaje de marca'], ['encaje', 'Encaje de marca'], ['marca', 'Encaje de marca'],
            ['audience', 'Audiencia / comentarios'], ['comment', 'Audiencia / comentarios'], ['audiencia', 'Audiencia / comentarios'], ['comentario', 'Audiencia / comentarios'],
            ['recommend', 'Recomendaciones'], ['recomend', 'Recomendaciones'], ['acción', 'Recomendaciones'], ['action', 'Recomendaciones'],
            ['brief', 'Ideas de brief'], ['test next', 'Próximos tests'], ['a/b', 'Tests A/B'], ['test', 'Tests A/B'], ['experimento', 'Tests A/B'],
            ['missing', 'Datos faltantes'], ['falta', 'Datos faltantes'], ['limitación', 'Datos faltantes'], ['limitation', 'Datos faltantes']
        ];
        for (const [needle, label] of map) if (t.includes(needle)) return label;
        return '';
    };

    // v0.9.25.0 — Phase 5: detect Markdown-table style metric rows like
    //   | Views | 43963 | Likes | 999 | Shares | 745 |
    // and turn them into readable "- Views: 43,963" lines so the analysis
    // panel doesn't render giant pipe walls.
    const nfmtForMetrics = (raw) => {
        const s = String(raw || '').trim();
        const n = Number(s.replace(/[,\s]/g, ''));
        if (Number.isFinite(n) && /^\d{4,}(\.\d+)?$/.test(s.replace(/[,\s]/g, ''))) {
            try { return n.toLocaleString('es-ES'); } catch (e) { return s; }
        }
        return s;
    };
    const convertPipeMetricLine = (line) => {
        const s = String(line || '').trim();
        if (!s.includes('|')) return s;
        // Skip Markdown table separators: | --- | --- |
        if (/^[|\s\-:]+$/.test(s)) return '';
        // v0.9.28.2 — Bug B + D fix. Previously a prose line with a stray pipe
        // like "This is | important text" was mis-detected as a label/value
        // pair and converted to "- This is: important text", losing readability.
        // Require the line to actually look like a Markdown table row: leading
        // pipe AND trailing pipe (after trim). And require values to be more
        // metric-shaped (numeric, percent, ratio, or short keyword like "high"/
        // "low"/"strong"/"weak").
        const looksLikeTableRow = /^\|.+\|$/.test(s);
        if (!looksLikeTableRow) return s;
        // Split: "| Views | 43963 | Likes | 999 |" → ["Views","43963","Likes","999"]
        const parts = s.split('|').map(p => p.trim()).filter(p => p.length > 0);
        if (parts.length < 2 || parts.length % 2 !== 0) {
            // Odd count means an orphan label/value — not a clean table row.
            return s;
        }
        const pairs = [];
        for (let i = 0; i + 1 < parts.length; i += 2) {
            const label = parts[i];
            const value = parts[i + 1];
            const labelLooksLabel = /[a-záéíóúñ]/i.test(label) && label.length < 50;
            const valueLooksMetric = /^[\d\s.,%kKmMBb+:/x-]+$/.test(value)
                || /^[\d.,]+\s*(?:%|likes?|views?|shares?|comments?)?$/i.test(value)
                || /^(high|medium|low|strong|weak|n\/a|n\.a\.|none|yes|no|unknown)$/i.test(value);
            if (labelLooksLabel && valueLooksMetric) {
                pairs.push(`- ${label}: ${nfmtForMetrics(value)}`);
            } else {
                // Not a clean pair — keep original line.
                return s;
            }
        }
        return pairs.join('\n');
    };

    const cleanAnalysisText = (value) => String(value || '')
        .replace(/\r/g, '')
        .split('\n')
        .map(line => convertPipeMetricLine(line))
        .filter(line => line !== null && line !== undefined)
        .join('\n')
        .replace(/^\s*[|\-=_]{4,}\s*$/gm, '')
        .replace(/[|\-]{8,}/g, ' ')
        .replace(/\n{3,}/g, '\n\n')
        .trim();

    const renderAnalysisStructuredCards = (analysis) => {
        const raw = cleanAnalysisText(analysis);
        if (!raw) return '<div class="ves-empty">No hay análisis disponible.</div>';
        const looksLikeError = /no se pudo|error al analizar|timed out|no respondió|went wrong|fallback/i.test(raw.slice(0, 300));
        if (looksLikeError) return `<div class="ves-analysis-rich">${renderAnalysisRichText(raw)}</div>`;
        const order = ['Lectura general','Mejor ejemplo','Comparación de patrón','Comparación de métricas','Comparación de hooks','Lectura ejecutiva','Hook y retención','Patrón creativo','Métricas','Encaje de marca','Audiencia / comentarios','Recomendaciones','Tests A/B','Ideas de brief','Próximos tests','Datos faltantes'];
        const buckets = Object.fromEntries(order.map(k => [k, []]));
        let current = 'Lectura ejecutiva';
        raw.split('\n').forEach((line) => {
            const clean = String(line || '').trim();
            if (!clean) return;
            if (/^[-*_=]{2,}(\s[-*_=]{1,})*$/.test(clean)) return;
            const heading = analysisSectionTitle(clean);
            if (heading) { current = heading; return; }
            const stripped = clean.replace(/^#{1,4}\s+/, '').replace(/^[-*+]\s+/, '').replace(/^\d+[.)]\s+/, '').trim();
            if (!stripped) return;
            buckets[current] = buckets[current] || [];
            buckets[current].push(stripped);
        });
        const filled = order.filter(k => (buckets[k] || []).length);
        if (!filled.length) return `<div class="ves-analysis-rich">${renderAnalysisRichText(raw)}</div>`;
        return `<div class="ves-analysis-accordion">${filled.map((title, i) => {
            const lines = buckets[title].slice(0, 12);
            const summary = lines[0] || '';
            const rest = lines.slice(1);
            const confidenceMatch = (lines.join(' ').match(/confidence[:\s]+([0-9]+%?)/i) || lines.join(' ').match(/confianza[:\s]+([0-9]+%?)/i));
            const confidence = confidenceMatch ? confidenceMatch[1] : '';
            return `<details class="ves-analysis-accordion-card" ${i === 0 ? 'open' : ''}><summary><span>${escapeHtml(title)}</span>${confidence ? `<b>${escapeHtml(confidence)}</b>` : ''}</summary><div class="ves-analysis-accordion-body">${summary ? `<p class="ves-analysis-summary">${formatInlineRich(summary)}</p>` : ''}${rest.length ? `<ul>${rest.map(x => `<li>${formatInlineRich(x)}</li>`).join('')}</ul>` : ''}</div></details>`;
        }).join('')}</div>`;
    };

    const parseMaybeJson = (value) => {
        if (!value) return null;
        if (typeof value === 'object') return value;
        const raw = String(value || '').trim();
        if (!raw) return null;
        try { return JSON.parse(raw); } catch (e) {}
        const m = raw.match(/\{[\s\S]*\}/);
        if (m) { try { return JSON.parse(m[0]); } catch (e) {} }
        return null;
    };

    const availabilityBadge = (label, ok) => `<span class="ves-data-badge ${ok ? 'is-on' : 'is-off'}">${escapeHtml(label)}: ${ok ? 'sí' : 'no'}</span>`;
    const renderList = (arr) => Array.isArray(arr) && arr.length ? `<ul>${arr.map(x => `<li>${formatInlineRich(String(x || ''))}</li>`).join('')}</ul>` : '';

    const renderStructuredItemAnalysis = (payload, rawText = '', meta = {}) => {
        const data = parseMaybeJson(payload) || parseMaybeJson(rawText);
        if (!data || typeof data !== 'object' || !data.executive_read) return '';
        // Phase 5C-UX corrective — thread ONLY real, existing meta fields into the
        // productization module (no fabricated usage/cost/services/object states).
        const m = (meta && typeof meta === 'object') ? meta : {};
        const productizationMeta = {
            usage: (m.usage && typeof m.usage === 'object') ? m.usage : null,
            usage_event: (m.usage_event && typeof m.usage_event === 'object') ? m.usage_event : null,
            services: (m.services && typeof m.services === 'object') ? m.services : null,
            usable_signal_count: m.usable_signal_count,
            normalized_signal_count: m.normalized_signal_count,
            confident_recommendations_allowed: m.confident_recommendations_allowed,
            approved_insight: m.approved_insight,
            approved_brief: m.approved_brief,
            lifecycle_status: m.lifecycle_status
        };
        const confidence = data.confidence || {};
        const ev = data.evidence_quality || data.evidence_available || {};
        const metrics = data.performance_metrics || {};
        const hook = data.hook_and_retention || {};
        const pattern = data.content_pattern || {};
        const fit = data.brand_or_market_fit || {};
        const recs = Array.isArray(data.recommendations) ? data.recommendations.filter(Boolean) : [];
        let tests = Array.isArray(data.ab_tests) ? data.ab_tests.filter(Boolean) : [];
        if (!tests.length) tests = [{ hypothesis:'Validar si el patrón observado merece producción adicional.', variant_a:'Recrear el hook/caption observado con una pieza control.', variant_b:'Probar un hook alternativo con el mismo tema y formato.', primary_kpi:'Tasa de retención o visualizaciones completas', secondary_kpi:'Engagement rate y comentarios cualitativos', minimum_evidence_needed:'Benchmark interno de piezas similares o al menos 3 publicaciones comparables.', test_duration_suggestion:'7-14 días o hasta alcanzar muestra suficiente.', why_this_test:'La evidencia actual es limitada y requiere benchmark antes de escalar.', confidence:'low' }];
        const sections = [];
        const exec = data.executive_read || {};
        sections.push(`<section class="ves-structured-analysis-section"><h3>Lectura ejecutiva</h3>${exec.summary ? `<p>${formatInlineRich(String(exec.summary))}</p>` : ''}<div class="ves-analysis-columns"><div><strong>Observado</strong>${renderList(exec.what_is_observed)}</div><div><strong>Inferido</strong>${renderList(exec.what_is_inferred)}</div><div><strong>No se puede concluir</strong>${renderList(exec.what_cannot_be_concluded)}</div></div></section>`);
        sections.push(`<section class="ves-structured-analysis-section"><h3>Calidad de evidencia</h3><div class="ves-badge-row">${availabilityBadge('Caption', ev.caption)}${availabilityBadge('Métricas', ev.metrics)}${availabilityBadge('Comentarios', ev.comments)}${availabilityBadge('Video frame-by-frame', ev.video_frames)}${availabilityBadge('Audio', ev.audio)}${availabilityBadge('Transcript', ev.transcript)}</div>${confidence.reason ? `<p>${formatInlineRich(String(confidence.reason))}</p>` : ''}</section>`);
        const ratios = Array.isArray(metrics.derived_ratios) ? metrics.derived_ratios : [];
        const hasMetric = (v) => v !== null && v !== undefined && v !== '' && !Number.isNaN(Number(v));
        sections.push(`<section class="ves-structured-analysis-section"><h3>Métricas</h3><div class="ves-seo-domain-grid"><div><small>Views</small><strong>${escapeHtml(metricCell(metrics.views, hasMetric(metrics.views)))}</strong></div><div><small>Likes</small><strong>${escapeHtml(metricCell(metrics.likes, hasMetric(metrics.likes)))}</strong></div><div><small>Comentarios</small><strong>${escapeHtml(metricCell(metrics.comments, hasMetric(metrics.comments)))}</strong></div><div><small>Shares</small><strong>${escapeHtml(metricCell(metrics.shares, hasMetric(metrics.shares)))}</strong></div><div><small>Saves</small><strong>${escapeHtml(metricCell(metrics.saves, hasMetric(metrics.saves)))}</strong></div></div>${ratios.length ? `<div class="ves-analysis-test-list">${ratios.map(r => `<article class="ves-ab-test-card"><strong>${escapeHtml(r.name || 'Ratio')}</strong><p>${escapeHtml(String(r.value || '—'))}</p><small>${escapeHtml(String(r.interpretation || 'Requiere benchmark.'))}</small></article>`).join('')}</div>` : '<p>Sin ratios suficientes o sin benchmark para calificarlos.</p>'}</section>`);
        sections.push(`<section class="ves-structured-analysis-section"><h3>Hook y patrón</h3>${hook.observed_hook ? `<p><strong>Hook:</strong> ${formatInlineRich(String(hook.observed_hook))}</p>` : ''}${hook.retention_hypothesis ? `<p><strong>Hipótesis de retención:</strong> ${formatInlineRich(String(hook.retention_hypothesis))}</p>` : ''}<div class="ves-analysis-columns"><div><strong>Ángulo</strong><p>${formatInlineRich(String(pattern.message_angle || '—'))}</p></div><div><strong>Formato</strong><p>${formatInlineRich(String(pattern.format_pattern || '—'))}</p></div><div><strong>Fórmula reutilizable</strong><p>${formatInlineRich(String(pattern.reusable_formula || '—'))}</p></div></div></section>`);
        sections.push(`<section class="ves-structured-analysis-section"><h3>Encaje de mercado</h3><p>${formatInlineRich(String(fit.fit_summary || fit.market_implication || 'Sin evidencia suficiente para un encaje fuerte.'))}</p>${fit.audience_fit ? `<p><strong>Audiencia:</strong> ${formatInlineRich(String(fit.audience_fit))}</p>` : ''}${fit.market_implication ? `<p><strong>Implicación:</strong> ${formatInlineRich(String(fit.market_implication))}</p>` : ''}</section>`);
        if (recs.length) sections.push((window.FIIS_Productization && window.FIIS_Productization.renderRecommendations) ? window.FIIS_Productization.renderRecommendations(recs, window.FIIS_Productization.build(data, productizationMeta)) : `<section class="ves-structured-analysis-section"><h3>Recomendaciones</h3><div class="ves-analysis-test-list">${recs.map(r => `<article class="ves-ab-test-card"><strong>${escapeHtml(r.action || 'Acción')}</strong><p>${formatInlineRich(String(r.why || ''))}</p><small>Confianza: ${escapeHtml(r.confidence || 'low')} · Riesgo: ${escapeHtml(r.risk || '—')}</small></article>`).join('')}</div></section>`);
        sections.push(`<section class="ves-structured-analysis-section"><h3>Plan de A/B testing</h3><div class="ves-analysis-test-list">${tests.map(t => `<article class="ves-ab-test-card"><strong>${escapeHtml(t.hypothesis || 'Hipótesis')}</strong><div class="ves-analysis-columns"><div><small>Variante A</small><p>${formatInlineRich(String(t.variant_a || '—'))}</p></div><div><small>Variante B</small><p>${formatInlineRich(String(t.variant_b || '—'))}</p></div></div><p><strong>KPI principal:</strong> ${escapeHtml(t.primary_kpi || '—')} · <strong>KPI secundario:</strong> ${escapeHtml(t.secondary_kpi || '—')}</p><small>${escapeHtml(t.minimum_evidence_needed || '')}${t.test_duration_suggestion ? ' · ' + escapeHtml(t.test_duration_suggestion) : ''} · Confianza: ${escapeHtml(t.confidence || 'low')}</small></article>`).join('')}</div></section>`);
        if (Array.isArray(data.limitations) && data.limitations.length) sections.push(`<section class="ves-structured-analysis-section"><h3>Limitaciones</h3>${renderList(data.limitations)}</section>`);
        const prodBlock = (window.FIIS_Productization && window.FIIS_Productization.renderProductization) ? window.FIIS_Productization.renderProductization(data, productizationMeta) : '';
        return `<div class="ves-structured-analysis">${prodBlock}<div class="ves-confidence-badge is-${escapeHtml(String(confidence.level || 'low'))}">Confianza: ${escapeHtml(String(confidence.level || 'low'))}</div>${sections.filter(Boolean).join('')}</div>`;
    };

    const renderAnalysisPanel = (analysis, model, meta = {}) => {
        const w = document.querySelector('.ves-wrap') || document.body;
        const raw = String(analysis || '');
        const structuredPayload = meta.analysis_structured || meta.analysis_json || meta.structured_analysis || null;
        const structuredHtml = renderStructuredItemAnalysis(structuredPayload, raw, meta);
        const isFallback = !!meta.fallback || !!meta.fallback_used || meta.format === 'local_fallback' || meta.confidence_mode === 'diagnostic_fallback' || meta.analysis_source === 'local_fallback';
        const isTextWithLocalMetrics = meta.analysis_source === 'ai_text_with_local_metrics';
        const displayModel = isFallback ? (meta.model_label || 'Análisis local de respaldo') : (isTextWithLocalMetrics ? (meta.model_label || 'Texto AI + métricas locales') : (meta.model_label || model || ''));
        const adminMeta = [];
        if (meta.project_context_used) adminMeta.push('Contexto reciente usado');
        if (meta.related_reports_used) adminMeta.push(`${escapeHtml(String(meta.related_reports_used))} memorias/reportes relacionados`);
        if (meta.item_enrichment_status || meta.enrichment_status) adminMeta.push(`Enriquecimiento: ${escapeHtml(String(meta.item_enrichment_status || meta.enrichment_status))}`);
        if (isAdminUI() && isFallback && meta.fallback_reason) adminMeta.push(`Fallback: ${escapeHtml(String(meta.fallback_reason))}`);
        const fallbackDisclaimer = isFallback ? `<div class="ves-fallback-notice" style="background:#fef9c3;border:1px solid #fde047;border-radius:6px;padding:8px 12px;margin-bottom:10px;font-size:12px;color:#713f12">Análisis local de respaldo. No se usó el modelo externo en este resultado.</div>` : (isTextWithLocalMetrics ? `<div class="ves-fallback-notice" style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;padding:8px 12px;margin-bottom:10px;font-size:12px;color:#3730a3">Texto AI conservado con métricas locales normalizadas.</div>` : '');
        const bodyHtml = fallbackDisclaimer + (structuredHtml || renderAnalysisStructuredCards(raw));
        const rawForDebug = structuredPayload ? JSON.stringify(structuredPayload, null, 2) : raw;
        return `<div class="ves-item-panel ves-analysis-panel"><div class="ves-item-panel-head"><div><div class="ves-item-panel-title">${escapeHtml(uiText(w, 'aiAnalysis'))}</div><div class="ves-meta">${escapeHtml(displayModel)}</div></div>${isAdminUI() && adminMeta.length ? `<div class="ves-meta ves-analysis-admin-meta">${adminMeta.join(' · ')}</div>` : ''}</div>${bodyHtml}${isDebugMode(w) ? `<details class="ves-source-input-details ves-raw-analysis-details"><summary style="font-size:11px;opacity:.75;cursor:pointer">Admin debug: raw AI output</summary><pre style="font-size:10px;max-height:240px;overflow:auto;opacity:.95">${escapeHtml(rawForDebug).slice(0, 8000)}</pre></details>` : ""}</div>`;
    };

    const currentViewState = (widget) => {
        const state = widgetState.get(widget);
        // v0.9.9.3: scope to active page so filter inputs in the right sub-page are read.
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const results = activePage.querySelector('.ves-results') || widget.querySelector('.ves-results');
        if (!results) return { items: [], q: '', minMetric: 0, sort: 'sectioned' };
        const q = String(results.querySelector('.ves-filter-query')?.value || '').trim().toLowerCase();
        const minMetric = responseMinimumMetric(state?.lastData);
        const hasRelevanceScores = Array.isArray(state?.lastData?.items) && state.lastData.items.some(it => parseNum(it?.ves_relevance_score || 0) > 0);
        const sort = String(results.querySelector('.ves-filter-sort')?.value || 'sectioned');
        let items = Array.isArray(state?.lastData?.items) ? state.lastData.items.slice().filter((it) => !isSourceErrorItem(it) && itemHasDisplayPayload(state.lastData.platform, it)) : [];

        if (q) {
            items = items.filter(it => {
                if (isSeoPlatform(state.lastData.platform)) {
                    const c = normalizeSeoItem(state.lastData.platform, it);
                    return [c.keyword, c.cluster, c.intent, c.serpType, c.why, c.url].join(' ').toLowerCase().includes(q);
                }
                const c = normalizeItem(state.lastData.platform, it);
                return [c.title, c.author, c.text, c.transcript, c.url].join(' ').toLowerCase().includes(q);
            });
        }

        items = items.filter(it => {
            if (isSeoPlatform(state.lastData.platform)) return true;
            const c = normalizeItem(state.lastData.platform, it);
            if (minMetric > 0 && (!hasFilterMetric(state.lastData.platform, c) || filterMetricValue(state.lastData.platform, c) < minMetric)) return false;
            return true;
        });

        const baseIndex = new Map((Array.isArray(state?.lastData?.items) ? state.lastData.items : []).map((it, i) => [it, i]));
        const bucketRank = (it) => String(it?.ves_result_bucket || 'best') === 'other' ? 1 : 0;
        const byOriginal = (a, b) => (baseIndex.get(a) || 0) - (baseIndex.get(b) || 0);
        const sorter = {
            sectioned: (a, b) => (bucketRank(a) - bucketRank(b)) || byOriginal(a, b),
            relevance: (a, b) => normalizeItem(state.lastData.platform, b).relevanceScore - normalizeItem(state.lastData.platform, a).relevanceScore,
            top_primary: (a, b) => filterMetricValue(state.lastData.platform, normalizeItem(state.lastData.platform, b)) - filterMetricValue(state.lastData.platform, normalizeItem(state.lastData.platform, a)),
            top_likes: (a, b) => normalizeItem(state.lastData.platform, b).likes - normalizeItem(state.lastData.platform, a).likes,
            top_comments: (a, b) => normalizeItem(state.lastData.platform, b).comments - normalizeItem(state.lastData.platform, a).comments,
            newest: (a, b) => String(normalizeItem(state.lastData.platform, b).date).localeCompare(String(normalizeItem(state.lastData.platform, a).date)),
            search_volume: (a, b) => normalizeSeoItem(state.lastData.platform, b).volume - normalizeSeoItem(state.lastData.platform, a).volume,
            commercial_intent: (a, b) => (/commercial|transactional/i.test(normalizeSeoItem(state.lastData.platform, b).intent) ? 1 : 0) - (/commercial|transactional/i.test(normalizeSeoItem(state.lastData.platform, a).intent) ? 1 : 0),
            low_competition: (a, b) => (normalizeSeoItem(state.lastData.platform, a).difficulty || 999) - (normalizeSeoItem(state.lastData.platform, b).difficulty || 999),
            opportunity_score: (a, b) => normalizeSeoItem(state.lastData.platform, b).opportunityScore - normalizeSeoItem(state.lastData.platform, a).opportunityScore,
            cpc: (a, b) => normalizeSeoItem(state.lastData.platform, b).cpc - normalizeSeoItem(state.lastData.platform, a).cpc,
            questions_first: (a, b) => (/^(how|what|why|qué|como|cómo|cu[aá]l|where|when)/i.test(normalizeSeoItem(state.lastData.platform, b).keyword) ? 1 : 0) - (/^(how|what|why|qué|como|cómo|cu[aá]l|where|when)/i.test(normalizeSeoItem(state.lastData.platform, a).keyword) ? 1 : 0),
            long_tail: (a, b) => normalizeSeoItem(state.lastData.platform, b).keyword.split(/\s+/).length - normalizeSeoItem(state.lastData.platform, a).keyword.split(/\s+/).length,
        }[sort] || ((a, b) => 0);

        items.sort(sorter);
        if (sort !== 'sectioned') {
            items.sort((a, b) => (bucketRank(a) - bucketRank(b)) || sorter(a, b));
        }
        state.filteredItems = items;
        return items;
    };

    const updateRenderedResults = (widget) => {
        const state = widgetState.get(widget);
        if (!state?.lastData) return;
        // v0.9.9.3: scope to active page.
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const results = activePage.querySelector('.ves-results') || widget.querySelector('.ves-results');
        if (!results) return;
        const filtered = currentViewState(widget);
        const countEl = results.querySelector('.ves-results-count');
        if (countEl) countEl.textContent = String(filtered.length);
        const cardsEl = results.querySelector('.ves-view-cards');
        if (cardsEl) {
            const visibleDiscarded = applyResponseMetricGate(state.lastData.platform, state.lastData.discarded_items || [], state.lastData);
            cardsEl.innerHTML = renderCards(state.lastData.platform, filtered, visibleDiscarded);
            const selected = new Set(state.selectedItemKeys || []);
            cardsEl.querySelectorAll('.ves-select-item').forEach((el) => { el.checked = selected.has(String(el.dataset.key || '')); });
        }
        const jsonEl = results.querySelector('.ves-view-json .ves-pre');
        if (jsonEl) jsonEl.textContent = JSON.stringify(filtered, null, 2);
        if (results.querySelector('.ves-panel-host') && state.panelHtml) results.querySelector('.ves-panel-host').innerHTML = state.panelHtml;
        updateSelectionControls(widget);
    };


    const formatInlineRich = (value) => {
        let html = escapeHtml(String(value || ''));
        // v0.9.24.8: handle **bold**, *italic*, and `code` spans.
        // escapeHtml already ran so we're safe to inject these tags.
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        return html;
    };

    const formatDisplayDate = (value) => {
        if (!value) return '—';
        const d = new Date(value);
        if (Number.isNaN(d.getTime())) return String(value);
        return d.toLocaleDateString('es-ES', { day: '2-digit', month: 'short' });
    };

    const renderAnalysisRichText = (text) => {
        const raw = String(text || '').replace(/\r/g, '');
        if (!raw.trim()) {
            return '<div class="ves-hint">No hay análisis disponible.</div>';
        }
        const lines = raw.split('\n');
        const html = [];
        let i = 0;
        while (i < lines.length) {
            const line = String(lines[i] || '').trim();
            const next = String(lines[i + 1] || '').trim();
            if (!line) {
                i += 1;
                continue;
            }
            const mdHeading = line.match(/^(#{2,4})\s+(.+)$/);
            if (mdHeading) {
                const level = mdHeading[1].length >= 4 ? 'h4' : 'h3';
                html.push(`<${level}>${formatInlineRich(mdHeading[2])}</${level}>`);
                i += 1;
                continue;
            }
            if (next && /^[-—]{4,}$/.test(next)) {
                html.push(`<h3>${formatInlineRich(line)}</h3>`);
                i += 2;
                continue;
            }
            if (/^[-*]\s+/.test(line) || /^\d+[.)\s]\s+/.test(line)) {
                const isNumbered = /^\d+[.)\s]\s+/.test(line);
                const items = [];
                while (i < lines.length && (/^[-*]\s+/.test(String(lines[i] || '').trim()) || /^\d+[.)\s]\s+/.test(String(lines[i] || '').trim()))) {
                    items.push(String(lines[i] || '').trim().replace(/^(?:[-*]|\d+[.)\s])\s+/, ''));
                    i += 1;
                }
                const tag = isNumbered ? 'ol' : 'ul';
                html.push(`<${tag}>` + items.map((item) => `<li>${formatInlineRich(item)}</li>`).join('') + `</${tag}>`);
                continue;
            }
            const paragraph = [];
            while (i < lines.length) {
                const current = String(lines[i] || '').trim();
                const upcoming = String(lines[i + 1] || '').trim();
                if (!current) {
                    i += 1;
                    break;
                }
                if (/^(#{2,4})\s+/.test(current) || /^[-*]\s+/.test(current) || (upcoming && /^[-—]{4,}$/.test(upcoming))) {
                    break;
                }
                paragraph.push(current);
                i += 1;
            }
            if (paragraph.length) {
                html.push(`<p>${formatInlineRich(paragraph.join(' '))}</p>`);
            }
        }
        return `<div class="ves-analysis-rich">${html.join('')}</div>`;
    };

    const renderTrendMiniTimeline = (items) => {
        const rows = Array.isArray(items) ? items.slice().filter((entry) => entry && (entry.created_at || entry.generated_at)) : [];
        if (!rows.length) {
            return '<div class="ves-hint">Todavía no hay snapshots suficientes para dibujar una timeline.</div>';
        }
        rows.sort((a, b) => new Date(a.created_at || a.generated_at || 0) - new Date(b.created_at || b.generated_at || 0));
        const sliced = rows.slice(-12);
        const scoreClass = (score) => {
            const n = Number(score) || 0;
            if (n >= 8) return 'high';
            if (n >= 5) return 'mid';
            return 'low';
        };
        return `
            <div class="ves-trend-timeline-wrap">
                <div class="ves-trend-mini-title">Mini trend timeline</div>
                <div class="ves-trend-timeline-track">
                    ${sliced.map((entry) => `
                        <div class="ves-trend-timeline-item">
                            <div class="ves-trend-timeline-line"></div>
                            <div class="ves-trend-timeline-dot ${scoreClass(entry.average_fit_score)}"></div>
                            <div class="ves-trend-timeline-date">${escapeHtml(formatDisplayDate(entry.created_at || entry.generated_at || ''))}</div>
                            <div class="ves-trend-timeline-score">${entry.average_fit_score !== null && entry.average_fit_score !== undefined ? `${escapeHtml(String(entry.average_fit_score))}/10` : '—'}</div>
                            <div class="ves-trend-timeline-label">${escapeHtml(safeTrendList(entry.top_trends, 1)[0] || entry.topic || 'Snapshot')}</div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    };

    const renderTrendHistoryBlock = (history) => {
        if (!Array.isArray(history) || !history.length) {
            return '<div class="ves-hint">Todavía no hay histórico comparable guardado para este tema.</div>';
        }
        return `
            <div class="ves-trend-history-grid">
                ${history.map((entry, idx) => `
                    <div class="ves-trend-history-card ${idx === 0 ? 'is-current' : ''}">
                        <div class="ves-trend-history-head">
                            <strong>${escapeHtml(entry.topic || 'Trend Finder')}</strong>
                            <span class="ves-trend-badge ${idx === 0 ? 'ok' : ''}">${idx === 0 ? 'Actual' : 'Histórico'}</span>
                        </div>
                        <div class="ves-meta">${escapeHtml(entry.created_at || '')}</div>
                        <div class="ves-trend-inline-note"><strong>País:</strong> ${escapeHtml(entry.country_label || entry.country || 'Worldwide')} · <strong>Rango:</strong> ${escapeHtml(entry.duration_label || entry.duration || '—')}</div>
                        <div class="ves-trend-inline-note"><strong>Fuentes útiles:</strong> ${escapeHtml(String((entry.usable_fuentes ?? entry.successful_fuentes ?? 0)))}/${escapeHtml(String(entry.total_fuentes || 4))}${entry.average_fit_score !== null && entry.average_fit_score !== undefined ? ` · <strong>Fit medio:</strong> ${escapeHtml(String(entry.average_fit_score))}/10` : ''}${entry.confidence_mode ? ` · <strong>Modo:</strong> ${escapeHtml(String(entry.confidence_mode))}` : ''}</div>
                        ${safeTrendList(entry.top_trends).length ? `<div class="ves-trend-mini-title">Top trends</div><ul class="ves-trend-list compact">${safeTrendList(entry.top_trends).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
                        ${entry.summary ? `<div class="ves-hint" style="margin-top:10px">${escapeHtml(entry.summary)}</div>` : ''}
                    </div>
                `).join('')}
            </div>
        `;
    };


    const renderTrendActionBoard = (actionBoard) => {
        const sections = {
            do_in_24h: 'Qué hacer en 24h',
            test_this_week: 'Qué testear esta semana',
            monitor: 'Qué vigilar',
            watch: 'Qué vigilar',
            ignore_for_now: 'Qué ignorar por ahora',
        };
        if (!actionBoard || typeof actionBoard !== 'object') return '';
        const cards = [];
        Object.entries(sections).forEach(([key, label]) => {
            const items = Array.isArray(actionBoard[key]) ? actionBoard[key].filter(x => String(x || '').trim() !== '') : [];
            if (!items.length || (key === 'watch' && Array.isArray(actionBoard.monitor) && actionBoard.monitor.length)) return;
            cards.push(`<div class="ves-trend-rec-card"><div class="ves-trend-mini-title">${escapeHtml(label)}</div><ul class="ves-trend-list compact">${items.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul></div>`);
        });
        if (!cards.length) return '';
        return `<div class="ves-trend-rec-grid">${cards.join('')}</div>`;
    };

    const renderTrendOpportunityBoard = (opportunities) => {
        if (!Array.isArray(opportunities) || !opportunities.length) {
            return '<div class="ves-hint">No se han detectado oportunidades promocionadas todavía.</div>';
        }
        const scoreClass = (score) => {
            const n = Number(score) || 0;
            if (n >= 8) return 'high';
            if (n >= 5) return 'mid';
            return 'low';
        };
        return `
            <div class="ves-trend-priority-grid ves-opportunity-grid">
                ${opportunities.map((opp) => `
                    <div class="ves-trend-priority-card ves-opportunity-card">
                        <div class="ves-trend-priority-head">
                            <div>
                                <strong>${escapeHtml(opp.opportunity_name || 'Oportunidad')}</strong>
                                <div class="ves-meta">Basada en ${escapeHtml(opp.based_on_trend || '—')} · ${escapeHtml(opp.signal_type || 'signal')}</div>
                            </div>
                            <span class="ves-trend-score ${scoreClass(opp.opportunity_score)}">${escapeHtml(String(opp.opportunity_score || 0))}/10</span>
                        </div>
                        <div class="ves-hint" style="margin-top:8px">${escapeHtml(opp.why_now || '')}</div>
                        ${opp.confidence ? `<div class="ves-trend-inline-note"><strong>Confianza:</strong> ${escapeHtml(opp.confidence)}</div>` : ''}
                        ${opp.activation_window ? `<div class="ves-trend-inline-note"><strong>Ventana:</strong> ${escapeHtml(opp.activation_window)}</div>` : ''}
                        ${opp.audience_fit ? `<div class="ves-trend-inline-note"><strong>Encaje audiencia:</strong> ${escapeHtml(opp.audience_fit)}</div>` : ''}
                        ${opp.brand_fit ? `<div class="ves-trend-inline-note"><strong>Encaje marca:</strong> ${escapeHtml(opp.brand_fit)}</div>` : ''}
                        ${opp.creative_angle ? `<div class="ves-trend-inline-note"><strong>Ángulo:</strong> ${escapeHtml(opp.creative_angle)}</div>` : ''}
                        ${opp.execution_note ? `<div class="ves-trend-inline-note"><strong>Nota de ejecución:</strong> ${escapeHtml(opp.execution_note)}</div>` : ''}
                        ${Array.isArray(opp.recommended_channels) && opp.recommended_channels.length ? `<div class="ves-trend-mini-title">Canales recomendados</div><ul class="ves-trend-list compact">${opp.recommended_channels.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
                        ${Array.isArray(opp.watchouts) && opp.watchouts.length ? `<div class="ves-trend-mini-title">Watchouts</div><ul class="ves-trend-list compact">${opp.watchouts.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
                    </div>
                `).join('')}
            </div>
        `;
    };

    const renderTrendBriefHistory = (briefs) => {
        if (!Array.isArray(briefs) || !briefs.length) {
            return '<div class="ves-hint">Todavía no hay briefs guardados para este reporte.</div>';
        }
        return `
            <div class="ves-trend-history-grid">
                ${briefs.map((brief, idx) => `
                    <div class="ves-trend-history-card ${idx === 0 ? 'is-current' : ''}">
                        <div class="ves-trend-history-head">
                            <strong>${escapeHtml(brief.brief_title || brief.trend_name || 'Brief')}</strong>
                            <span class="ves-trend-badge ${idx === 0 ? 'ok' : ''}">${escapeHtml(brief.brief_format || 'Brief')}</span>
                        </div>
                        <div class="ves-meta">${escapeHtml(formatDisplayDate(brief.created_at || ''))}</div>
                        ${brief.summary ? `<div class="ves-hint" style="margin-top:8px">${escapeHtml(brief.summary)}</div>` : ''}
                        ${brief.brief_text ? `<details class="ves-brief-details" style="margin-top:10px"><summary>Ver brief</summary><div class="ves-trend-ai-report compact">${renderAnalysisRichText(brief.brief_text)}</div></details>` : ''}
                    </div>
                `).join('')}
            </div>
        `;
    };

    const renderTrendMonitorPanel = (monitors, currentFilters = {}) => {
        const cadenceOptions = [
            { v: 'manual', l: 'Manual' },
            { v: 'daily', l: 'Daily template' },
            { v: 'weekly', l: 'Weekly template' },
        ];
        const rows = Array.isArray(monitors) ? monitors : [];
        return `
            <div class="ves-item-panel" style="margin-top:14px">
                <div class="ves-item-panel-head">
                    <div>
                        <div class="ves-item-panel-title">Saved monitors</div>
                        <div class="ves-meta">Guarda búsquedas reutilizables con threshold orientativo.</div>
                    </div>
                    <div class="ves-meta">${rows.length} guardados</div>
                </div>
                <div class="ves-trend-dashboard-filters" style="margin-top:12px">
                    <input class="ves-input" type="text" name="monitorName" placeholder="Nombre del monitor" value="${escapeHtml(currentFilters.topic || '')}">
                    <select class="ves-select" name="monitorCadence">${cadenceOptions.map((o) => `<option value="${escapeHtml(o.v)}">${escapeHtml(o.l)}</option>`).join('')}</select>
                    <input class="ves-input" type="number" min="1" max="10" step="1" name="monitorAlertThreshold" placeholder="Threshold 1-10" value="7">
                    <button type="button" class="ves-btn ves-btn-secondary ves-monitor-save">Guardar monitor</button>
                </div>
                ${rows.length ? `<div class="ves-trend-source-delta-grid" style="margin-top:14px">${rows.map((item) => `
                    <div class="ves-trend-source-delta-card ves-monitor-card">
                        <strong>${escapeHtml(item.name || item.topic || 'Monitor')}</strong>
                        <div class="ves-meta">${escapeHtml(item.topic || '')} · ${escapeHtml(item.country || 'None')} · ${escapeHtml(item.duration || '7d')}</div>
                        <div class="ves-trend-inline-note">Cadencia: ${escapeHtml(item.cadence || 'manual')} · Alert ≥ ${escapeHtml(String(item.alert_threshold || 7))}</div>
                        <div class="ves-trend-inline-note"><strong>Estado:</strong> ${escapeHtml(item.last_status || (item.cadence === 'manual' ? 'idle' : 'scheduled'))}${item.last_average_score !== null && item.last_average_score !== undefined ? ` · <strong>Score:</strong> ${escapeHtml(String(item.last_average_score))}/10` : ''}</div>
                        ${item.next_run_at ? `<div class="ves-trend-inline-note"><strong>Próxima ejecución:</strong> ${escapeHtml(formatDisplayDate(item.next_run_at))}</div>` : ''}
                        ${item.last_run_at ? `<div class="ves-trend-inline-note"><strong>Última ejecución:</strong> ${escapeHtml(formatDisplayDate(item.last_run_at))}</div>` : ''}
                        ${item.last_snapshot_id ? `<div class="ves-trend-inline-note"><strong>Último snapshot:</strong> #${escapeHtml(String(item.last_snapshot_id))}</div>` : ''}
                        ${item.last_run_note ? `<div class="ves-hint" style="margin-top:8px">${escapeHtml(item.last_run_note)}</div>` : ''}
                        ${item.last_alert_message ? `<div class="ves-hint" style="margin-top:8px"><strong>Última alerta:</strong> ${escapeHtml(item.last_alert_message)}${item.last_alerted_at ? ` · ${escapeHtml(formatDisplayDate(item.last_alerted_at))}` : ''}</div>` : ''}
                        ${item.reason ? `<div class="ves-hint" style="margin-top:8px">${escapeHtml(item.reason)}</div>` : ''}
                        <div class="ves-toolbar" style="margin-top:10px;margin-bottom:0">
                            <button type="button" class="ves-btn ves-btn-secondary ves-monitor-load" data-monitor-id="${escapeHtml(item.id)}">Cargar</button>
                            <button type="button" class="ves-btn ves-btn-secondary ves-monitor-delete" data-monitor-id="${escapeHtml(item.id)}">Borrar</button>
                        </div>
                    </div>
                `).join('')}</div>` : '<div class="ves-hint" style="margin-top:12px">Todavía no has guardado monitores.</div>'}
            </div>
        `;
    };

    const loadTrendMonitors = async (widget, silent = false) => {
        const state = widgetState.get(widget);
        if (!state || !state.form) return;
        try {
            const payload = await ajaxTrendMonitorList(state.form);
            state.monitors = Array.isArray(payload.monitors) ? payload.monitors : [];
            state.monitorsLoaded = true;
            const dashboardHost = widget.querySelector('.ves-trend-dashboard');
            if (dashboardHost && dashboardHost.style.display !== 'none') {
                renderTrendDashboard(widget, state.dashboardData || [], state.dashboardFilters || {}, state.dashboardComparison || null);
            }
        } catch (err) {
            if (!silent) {
                setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo cargar los monitores.', widget)), 'error');
            }
        }
    };

    const renderTrendComparisonBlock = (comparison) => {
        if (!comparison || typeof comparison !== 'object') {
            return '<div class="ves-hint">Aún no existe un reporte anterior comparable con el mismo tema, país y rango.</div>';
        }
        const scoreDelta = (comparison.average_fit_score_current !== null && comparison.average_fit_score_previous !== null)
            ? (Number(comparison.average_fit_score_current) - Number(comparison.average_fit_score_previous)).toFixed(1)
            : null;
        return `
            <div class="ves-trend-compare-grid">
                <div class="ves-trend-compare-card">
                    <div class="ves-trend-mini-title">Comparativa base</div>
                    <div class="ves-trend-inline-note"><strong>Reporte anterior:</strong> ${escapeHtml(comparison.previous_generated_at || '—')}</div>
                    ${comparison.days_apart !== null && comparison.days_apart !== undefined ? `<div class="ves-trend-inline-note"><strong>Días entre cortes:</strong> ${escapeHtml(String(comparison.days_apart))}</div>` : ''}
                    <div class="ves-trend-inline-note"><strong>Fuentes útiles:</strong> ${escapeHtml(String((comparison.usable_sources_current ?? comparison.successful_sources_current ?? 0)))} ahora vs ${escapeHtml(String((comparison.usable_sources_previous ?? comparison.successful_sources_previous ?? 0)))} antes</div>
                    ${(scoreDelta !== null) ? `<div class="ves-trend-inline-note"><strong>Fit medio:</strong> ${escapeHtml(String(comparison.average_fit_score_current))}/10 ahora vs ${escapeHtml(String(comparison.average_fit_score_previous))}/10 antes (${scoreDelta >= 0 ? '+' : ''}${escapeHtml(String(scoreDelta))})</div>` : ''}
                </div>
                <div class="ves-trend-compare-card">
                    <div class="ves-trend-mini-title">Persisten</div>
                    ${safeTrendList(comparison.overlap_trends).length ? `<ul class="ves-trend-list compact">${safeTrendList(comparison.overlap_trends).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : '<div class="ves-hint">No hay overlaps claros.</div>'}
                </div>
                <div class="ves-trend-compare-card">
                    <div class="ves-trend-mini-title">Nuevas en esta lectura</div>
                    ${safeTrendList(comparison.new_trends).length ? `<ul class="ves-trend-list compact">${safeTrendList(comparison.new_trends).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : '<div class="ves-hint">No aparecen tendencias nuevas relevantes.</div>'}
                </div>
                <div class="ves-trend-compare-card">
                    <div class="ves-trend-mini-title">Bajan o desaparecen</div>
                    ${safeTrendList(comparison.dropped_trends).length ? `<ul class="ves-trend-list compact">${safeTrendList(comparison.dropped_trends).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : '<div class="ves-hint">No hay caídas claras.</div>'}
                </div>
            </div>
            ${safeArray(comparison.corrupted_labels_removed).length ? `<div class="ves-low-confidence-banner is-repair"><strong>Needs evidence repair</strong><span>Se ocultaron labels legacy no válidos en la comparación: ${safeArray(comparison.corrupted_labels_removed).slice(0,8).map(escapeHtml).join(', ')}</span></div>` : ''}
            ${Array.isArray(comparison.source_item_delta) && comparison.source_item_delta.length ? `
                <div class="ves-item-panel" style="margin-top:14px">
                    <div class="ves-trend-mini-title">Delta por fuente</div>
                    <div class="ves-trend-source-delta-grid">
                        ${comparison.source_item_delta.map((row) => `
                            <div class="ves-trend-source-delta-card">
                                <strong>${escapeHtml(row.channel || 'Canal')}</strong>
                                <div class="ves-meta">${escapeHtml(String(row.previous_items || 0))} → ${escapeHtml(String(row.current_items || 0))}</div>
                                <div class="ves-trend-inline-note">Δ ${row.delta > 0 ? '+' : ''}${escapeHtml(String(row.delta || 0))}</div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            ` : ''}
        `;
    };


    const getTrendDashboardFilters = (widget) => {
        const host = widget.querySelector('.ves-trend-dashboard');
        const form = widget.querySelector('.ves-form');
        return {
            topic: String(host?.querySelector('[name="dashTopic"]')?.value || form?.querySelector('[name="trendCategory"]')?.value || '').trim(),
            country: String(host?.querySelector('[name="dashCountry"]')?.value || '').trim(),
            duration: String(host?.querySelector('[name="dashDuration"]')?.value || '').trim(),
            date_from: String(host?.querySelector('[name="dashDateFrom"]')?.value || '').trim(),
            date_to: String(host?.querySelector('[name="dashDateTo"]')?.value || '').trim(),
            limit: String(host?.querySelector('[name="dashLimit"]')?.value || '24').trim(),
        };
    };

    const renderTrendDashboard = (widget, dashboard = [], filters = {}, comparison = null, memoryActivity = null) => {
        const host = widget.querySelector('.ves-trend-dashboard');
        const state = widgetState.get(widget);
        if (!host || !state) return;
        host.style.display = '';
        state.dashboardData = Array.isArray(dashboard) ? dashboard : [];
        state.dashboardFilters = { topic:'', country:'', duration:'', date_from:'', date_to:'', limit:24, ...(filters || {}) };
        state.dashboardComparison = comparison || null;
        if (Array.isArray(memoryActivity)) state.memoryActivity = memoryActivity;
        const selected = new Set((state.dashboardSelected || []).map((id) => String(id)));
        const compareReady = selected.size === 2;
        const dashboardItems = Array.isArray(state.dashboardData) ? state.dashboardData : [];
        const memoryItems = Array.isArray(state.memoryActivity) ? state.memoryActivity : [];
        const summaryLine = dashboardItems.length ? `${dashboardItems.length} snapshots cargados` : 'Todavía no hay snapshots cargados en el dashboard.';
        const limitOptions = [12,24,50,100].map((v) => ({ v:String(v), l:`${v} resultados` }));
        const dashboardCountries = [{ v:'', l:'Cualquier país' }, ...COUNTRY_PRESETS.filter((o) => o.v !== 'None')];
        const dashboardDurations = [{ v:'', l:'Cualquier rango' }, ...TREND_TIME_PRESETS];
        host.innerHTML = `
            <div class="ves-card ves-trend-dashboard-card">
                <div class="ves-item-panel-head" style="margin-bottom:14px">
                    <div>
                        <div class="ves-item-panel-title">Trend History dashboard</div>
                        <div class="ves-meta">Filtra snapshots guardados, selecciona dos y compáralos entre sí.</div>
                    </div>
                    <div class="ves-meta">${escapeHtml(summaryLine)}</div>
                </div>
                <div class="ves-trend-dashboard-filters">
                    <input class="ves-input" type="text" name="dashTopic" placeholder="Tema o categoría" value="${escapeHtml(state.dashboardFilters.topic || '')}">
                    <select class="ves-select" name="dashCountry">${dashboardCountries.map((o) => `<option value="${escapeHtml(o.v)}" ${String(state.dashboardFilters.country || '') === String(o.v) ? 'selected' : ''}>${escapeHtml(o.l)}</option>`).join('')}</select>
                    <select class="ves-select" name="dashDuration">${dashboardDurations.map((o) => `<option value="${escapeHtml(o.v)}" ${String(state.dashboardFilters.duration || '') === String(o.v) ? 'selected' : ''}>${escapeHtml(o.l)}</option>`).join('')}</select>
                    <input class="ves-input" type="date" name="dashDateFrom" value="${escapeHtml(state.dashboardFilters.date_from || '')}">
                    <input class="ves-input" type="date" name="dashDateTo" value="${escapeHtml(state.dashboardFilters.date_to || '')}">
                    <select class="ves-select" name="dashLimit">${limitOptions.map((o) => `<option value="${escapeHtml(o.v)}" ${String(state.dashboardFilters.limit || 24) === String(o.v) ? 'selected' : ''}>${escapeHtml(o.l)}</option>`).join('')}</select>
                </div>
                <div class="ves-toolbar" style="margin-bottom:14px">
                    <button type="button" class="ves-btn ves-btn-secondary ves-dashboard-load">Cargar dashboard</button>
                    <button type="button" class="ves-btn ves-btn-secondary ves-dashboard-compare" ${compareReady ? '' : 'disabled'}>Comparar seleccionados</button>
                    <button type="button" class="ves-btn ves-btn-secondary ves-dashboard-export" ${(dashboardItems.length || memoryItems.length) ? '' : 'disabled'}>⬇ Export memory JSON</button>
                    <button type="button" class="ves-btn ves-btn-secondary ves-dashboard-clear">Limpiar</button>
                </div>
                <div class="ves-kpis" style="margin-bottom:14px">
                    <div class="ves-kpi"><small>Snapshots</small><strong>${escapeHtml(String(dashboardItems.length || 0))}</strong></div>
                    <div class="ves-kpi"><small>Comparación</small><strong>${compareReady ? '2 seleccionados' : 'Selecciona 2'}</strong></div>
                    <div class="ves-kpi"><small>País filtro</small><strong>${escapeHtml((dashboardCountries.find((o) => String(o.v) === String(state.dashboardFilters.country || '')) || {}).l || 'Cualquier país')}</strong></div>
                    <div class="ves-kpi"><small>Rango filtro</small><strong>${escapeHtml((dashboardDurations.find((o) => String(o.v) === String(state.dashboardFilters.duration || '')) || {}).l || 'Cualquier rango')}</strong></div>
                </div>
                <div class="ves-item-panel ves-memory-activity-panel" style="margin-bottom:14px">
                    <div class="ves-item-panel-head">
                        <div>
                            <div class="ves-item-panel-title">Recent activity</div>
                            <div class="ves-meta">Últimos resultados: análisis AI, recolecciones, briefs y trend reports.</div>
                        </div>
                        <div class="ves-meta">${escapeHtml(String(memoryItems.length || 0))} registros</div>
                    </div>
                    <div class="ves-memory-activity-grid">
                        ${memoryItems.length ? memoryItems.map((entry) => `
                            <div class="ves-memory-activity-card">
                                <div class="ves-trend-history-head">
                                    <strong>${escapeHtml(entry.title || entry.summary || entry.platform || 'Memory record')}</strong>
                                    <span class="ves-trend-badge">${escapeHtml(entry.entry_type || 'memory')}</span>
                                </div>
                                <div class="ves-meta">${escapeHtml(entry.platform || '—')} · ${escapeHtml(entry.created_at || '')}${entry.country_code ? ` · ${escapeHtml(entry.country_code)}` : ''}</div>
                                <div class="ves-trend-inline-note"><strong>Items:</strong> ${escapeHtml(String(entry.filtered_items ?? entry.raw_items ?? 0))} mostrados / ${escapeHtml(String(entry.raw_items ?? 0))} recibidos</div>
                                ${entry.summary ? `<div class="ves-hint" style="margin-top:8px">${escapeHtml(entry.summary).slice(0, 260)}</div>` : ''}
                                ${Array.isArray(entry.signals) && entry.signals.length ? `<ul class="ves-trend-list compact">${entry.signals.slice(0,3).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
                            </div>
                        `).join('') : '<div class="ves-hint">Aún no hay resultados o análisis guardados para esta sesión.</div>'}
                    </div>
                </div>
                ${renderTrendMonitorPanel(state.monitors || [], state.dashboardFilters || {})}
                <div class="ves-item-panel" style="margin-top:14px;margin-bottom:14px">
                    <div class="ves-item-panel-title">Mini trend timeline</div>
                    ${renderTrendMiniTimeline(dashboardItems)}
                </div>
                <div class="ves-trend-dashboard-grid">
                    ${dashboardItems.length ? dashboardItems.map((entry) => `
                        <label class="ves-trend-dashboard-entry ves-trend-dash-card ${selected.has(String(entry.id)) ? 'is-selected' : ''}">
                            <div class="ves-trend-dash-check"><input type="checkbox" class="ves-trend-dash-select" data-report-id="${escapeHtml(String(entry.id))}" ${selected.has(String(entry.id)) ? 'checked' : ''}></div>
                            <div class="ves-trend-history-head">
                                <strong>${escapeHtml(entry.topic || 'Trend Finder')}</strong>
                                <span class="ves-trend-badge">#${escapeHtml(String(entry.id))}</span>
                            </div>
                            <div class="ves-meta">${escapeHtml(entry.created_at || '')}</div>
                            <div class="ves-trend-inline-note"><strong>País:</strong> ${escapeHtml(entry.country_label || entry.country || 'Worldwide')} · <strong>Rango:</strong> ${escapeHtml(entry.duration_label || entry.duration || '—')}</div>
                            <div class="ves-trend-inline-note"><strong>Fuentes útiles:</strong> ${escapeHtml(String((entry.usable_fuentes ?? entry.successful_fuentes ?? 0)))}/${escapeHtml(String(entry.total_fuentes || 4))}${entry.average_fit_score !== null && entry.average_fit_score !== undefined ? ` · <strong>Fit medio:</strong> ${escapeHtml(String(entry.average_fit_score))}/10` : ''}${entry.confidence_mode ? ` · <strong>Modo:</strong> ${escapeHtml(String(entry.confidence_mode))}` : ''}</div>
                            ${safeTrendList(entry.top_trends).length ? `<div class="ves-trend-mini-title">Top trends</div><ul class="ves-trend-list compact">${safeTrendList(entry.top_trends).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
                            ${entry.summary ? `<div class="ves-hint" style="margin-top:10px">${escapeHtml(entry.summary)}</div>` : ''}
                        </label>
                    `).join('') : '<div class="ves-hint">Usa los filtros y pulsa "Cargar dashboard" para traer los snapshots guardados.</div>'}
                </div>
                <div class="ves-item-panel" style="margin-top:14px">
                    <div class="ves-item-panel-head">
                        <div class="ves-item-panel-title">Comparación arbitraria</div>
                        <div class="ves-meta">Selecciona exactamente 2 snapshots</div>
                    </div>
                    <div class="ves-trend-dashboard-compare-host">${comparison ? renderTrendComparisonBlock(comparison) : '<div class="ves-hint">Todavía no has comparado dos snapshots desde el dashboard.</div>'}</div>
                </div>
            </div>
        `;

        host.querySelectorAll('.ves-trend-dash-select').forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                let ids = Array.from(host.querySelectorAll('.ves-trend-dash-select:checked')).map((el) => String(el.dataset.reportId || '')).filter(Boolean);
                if (ids.length > 2) {
                    checkbox.checked = false;
                    ids = Array.from(host.querySelectorAll('.ves-trend-dash-select:checked')).map((el) => String(el.dataset.reportId || '')).filter(Boolean);
                }
                state.dashboardSelected = ids;
                renderTrendDashboard(widget, state.dashboardData, getTrendDashboardFilters(widget), state.dashboardComparison);
            });
        });

        host.querySelector('.ves-monitor-save')?.addEventListener('click', async () => {
            try {
                const form = state.form;
                const payload = await ajaxTrendMonitorSave(form, {
                    monitorName: host.querySelector('[name="monitorName"]')?.value || '',
                    cadence: host.querySelector('[name="monitorCadence"]')?.value || 'manual',
                    alertThreshold: host.querySelector('[name="monitorAlertThreshold"]')?.value || '7',
                    topic: form?.querySelector('[name="trendCategory"]')?.value || state.dashboardFilters?.topic || '',
                    country: form?.querySelector('[name="trendCountry"]')?.value || state.dashboardFilters?.country || 'None',
                    duration: form?.querySelector('[name="trendRange"], [name="trendTimeRange"], [name="duration"]')?.value || state.dashboardFilters?.duration || '7d',
                    reason: form?.querySelector('[name="trendReason"]')?.value || '',
                });
                state.monitors = Array.isArray(payload.monitors) ? payload.monitors : [];
                state.monitorsLoaded = true;
                renderTrendDashboard(widget, state.dashboardData, getTrendDashboardFilters(widget), state.dashboardComparison);
                setStatus(widget, '✅ <strong>Monitor guardado</strong>.', 'success');
            } catch (err) {
                setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo guardar el monitor.', widget)), 'error');
            }
        });

        host.querySelectorAll('.ves-monitor-load').forEach((btn) => btn.addEventListener('click', () => {
            const id = String(btn.dataset.monitorId || '');
            const monitor = (state.monitors || []).find((row) => String(row.id) === id);
            if (!monitor || !state.form) return;
            const form = state.form;
            if (form.querySelector('[name="trendCategory"]')) form.querySelector('[name="trendCategory"]').value = monitor.topic || '';
            if (form.querySelector('[name="trendCountry"]')) form.querySelector('[name="trendCountry"]').value = monitor.country || 'None';
            if (form.querySelector('[name="trendRange"], [name="trendTimeRange"], [name="duration"]')) form.querySelector('[name="trendRange"], [name="trendTimeRange"], [name="duration"]').value = monitor.duration || '7d';
            if (form.querySelector('[name="trendReason"]')) form.querySelector('[name="trendReason"]').value = monitor.reason || '';
            state.dashboardFilters = {
                ...(state.dashboardFilters || {}),
                topic: monitor.topic || '',
                country: monitor.country || 'None',
                duration: monitor.duration || '7d',
            };
            renderTrendDashboard(widget, state.dashboardData, state.dashboardFilters, state.dashboardComparison);
            setStatus(widget, `✅ <strong>Monitor cargado</strong> — ${escapeHtml(monitor.name || monitor.topic || 'monitor')}.`, 'success');
        }));

        host.querySelectorAll('.ves-monitor-delete').forEach((btn) => btn.addEventListener('click', async () => {
            try {
                const payload = await ajaxTrendMonitorDelete(state.form, btn.dataset.monitorId || '');
                state.monitors = Array.isArray(payload.monitors) ? payload.monitors : [];
                state.monitorsLoaded = true;
                renderTrendDashboard(widget, state.dashboardData, getTrendDashboardFilters(widget), state.dashboardComparison);
                setStatus(widget, '✅ <strong>Monitor borrado</strong>.', 'success');
            } catch (err) {
                setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo borrar el monitor.', widget)), 'error');
            }
        }));

        host.querySelector('.ves-dashboard-load')?.addEventListener('click', async () => {
            try {
                setStatus(widget, '<strong>Cargando Trend History dashboard…</strong>', 'info', true);
                const currentFilters = getTrendDashboardFilters(widget);
                const payload = await ajaxTrendDashboard(state.form, currentFilters);
                state.dashboardSelected = [];
                state.dashboardComparison = null;
                renderTrendDashboard(widget, Array.isArray(payload.dashboard) ? payload.dashboard : [], payload.filters || currentFilters, null, Array.isArray(payload.memory) ? payload.memory : []);
                setStatus(widget, `✅ <strong>Dashboard cargado</strong> — ${escapeHtml(String(payload.total || 0))} snapshots encontrados.`, 'success');
            } catch (err) {
                setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo cargar el dashboard.', widget)), 'error');
            }
        });

        host.querySelector('.ves-dashboard-export')?.addEventListener('click', () => {
            downloadFile('vietnam-estudio-memory-dashboard.json', JSON.stringify({ filters: state.dashboardFilters || {}, trend_snapshots: state.dashboardData || [], memory_activity: state.memoryActivity || [] }, null, 2), 'application/json');
        });

        host.querySelector('.ves-dashboard-clear')?.addEventListener('click', () => {
            state.dashboardSelected = [];
            state.dashboardComparison = null;
            state.memoryActivity = [];
            renderTrendDashboard(widget, [], { topic:'', country:'', duration:'', date_from:'', date_to:'', limit:24 }, null, []);
        });

        host.querySelector('.ves-dashboard-compare')?.addEventListener('click', async () => {
            const ids = Array.isArray(state.dashboardSelected) ? state.dashboardSelected.slice(0, 2) : [];
            if (ids.length !== 2) {
                setStatus(widget, 'Selecciona exactamente 2 snapshots para compararlos.', 'error');
                return;
            }
            try {
                setStatus(widget, '<strong>Comparando snapshots…</strong>', 'info', true);
                const payload = await ajaxTrendCompare(state.form, ids[0], ids[1]);
                state.dashboardComparison = payload.comparison || null;
                renderTrendDashboard(widget, state.dashboardData, getTrendDashboardFilters(widget), state.dashboardComparison);
                setStatus(widget, '✅ <strong>Comparación lista</strong>.', 'success');
            } catch (err) {
                setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo comparar los snapshots.', widget)), 'error');
            }
        });
    };

    const normalizeTrendStructuredV84 = (structuredRaw, data = {}) => {
        const structured = safeObject(structuredRaw);
        if (!structured || !structured.report_mode) return structured;
        const asTrend = (item) => {
            item = safeObject(item);
            return {
                trend_name: item.name || item.canonical_topic || item.trend_name || '',
                primary_channel: item.primary_channel || item.source || '',
                fit_score: Number(item.score || item.fit_score || 0) || 0,
                fit_reason: item.why_it_matters || item.fit_reason || '',
                signal_type: item.signal_type || '',
                evidence_strength: item.confidence || '',
                audience_or_context: item.audience_or_context || '',
                activation_window: item.activation_window || '',
                recommended_action: item.recommended_next_step || '',
                growth_drivers: [],
                evidence: safeArray(item.evidence),
                content_actions: item.recommended_next_step ? [item.recommended_next_step] : [],
                risk_note: item.watchout || '',
                counterargument: ''
            };
        };
        const asOpp = (item) => {
            item = safeObject(item);
            return {
                opportunity_name: item.name || item.canonical_topic || '',
                based_on_trend: item.name || item.canonical_topic || '',
                opportunity_score: Number(item.score || 0) || 0,
                confidence: item.confidence || '',
                signal_type: item.signal_type || 'adjacent',
                why_now: item.why_it_matters || '',
                activation_window: '',
                audience_fit: '',
                brand_fit: '',
                recommended_channels: [],
                creative_angle: item.recommended_next_step || '',
                execution_note: item.recommended_next_step || '',
                risk_level: item.watchout || '',
                watchouts: item.watchout ? [item.watchout] : []
            };
        };
        const quality = safeObject(structured.signal_quality);
        const coverage = safeObject(structured.evidence_coverage);
        const brief = safeObject(structured.brief_readiness);
        const action = safeObject(structured.action_board);
        return {
            ...structured,
            overall_take: quality.evidence_vs_inference || structured.overall_take || '',
            data_quality_summary: {
                overall_confidence: quality.confidence || '',
                direct_topic_signal: coverage.summary || '',
                what_is_inference_vs_evidence: quality.evidence_vs_inference || '',
                main_blindspots: safeArray(quality.blind_spots)
            },
            channel_readouts: safeArray(structured.source_reading).map((row) => ({
                channel: row.source || '',
                status: row.status || '',
                what_stands_out: row.usefulness || '',
                confidence: row.confidence || '',
                limitation: row.limitation || '',
                relevance_to_goal: row.next_action || '',
                evidence_examples: []
            })),
            prioritized_trends: safeArray(structured.prioritized_trends).map(asTrend),
            opportunity_board: safeArray(structured.adjacent_opportunities).map(asOpp),
            production_recommendations: {
                strategic_principles: safeArray(structured.validation_recommendations),
                angles: safeArray(structured.adjacent_opportunities).map(x => safeObject(x).why_it_matters || safeObject(x).name).filter(Boolean).slice(0, 4),
                formats: brief.allowed_output ? [brief.allowed_output] : [],
                hooks: [],
                first_pieces_to_produce: action.test_this_week || [],
                measurement_notes: action.monitor || action.watch || []
            },
            false_positives_and_risks: safeArray(structured.risks_false_positives),
            action_board: { ...action, watch: action.monitor || action.watch || [] },
            _v84_brief_readiness: brief,
            _v84_what_to_ignore: safeArray(structured.what_to_ignore),
        };
    };


    const providerCostLabel = (data, widget) => {
        const adminDebug = !!isDebugMode(widget);
        const provider = safeObject(data?.provider_cost);
        const costValue = data?.provider_cost_usd ?? data?.total_charge_usd ?? data?.actual_provider_cost_usd ?? provider.actual_usd ?? provider.estimated_usd;
        if (adminDebug) {
            return costValue !== null && costValue !== undefined && costValue !== '' && Number.isFinite(Number(costValue))
                ? '$' + Number(costValue).toFixed(4)
                : 'Cost unavailable';
        }
        const ledger = safeObject(data?.usage_ledger);
        const charged = ledger.credits_charged_final ?? data?.charge ?? data?.credits_charged;
        const refunded = ledger.credits_refunded_or_voided;
        if (refunded !== undefined && refunded !== null && Number(refunded) > 0) return 'Créditos devueltos';
        if (charged !== undefined && charged !== null && charged !== '') {
            const n = Number(charged);
            if (Number.isFinite(n) && n === 0) return '0 créditos cobrados';
            return String(charged) + ' créditos';
        }
        return 'Uso del run';
    };

    const providerCostKpiLabel = (widget) => isDebugMode(widget) ? 'Provider cost' : 'Uso';

    const externalResourceWarningMessage = (sourceCount) => {
        const count = Number(sourceCount || 0);
        return `⚠️ <strong>Resource notice</strong> — this run uses multiple external sources${count > 0 ? ` (${escapeHtml(String(count))})` : ''} and may consume more credits.`;
    };

    const renderResultDiagnostics = (data, cfg, widget) => {
        const raw = Number(data.raw_provider_returned_count ?? data.fetched_dataset_count ?? data.raw_items ?? 0);
        const filtered = Number(data.all_items_count ?? data.filtered_items ?? data.item_count_preview ?? 0);
        const requested = Number(data.requested_limit ?? 0);
        const chips = [];
        const chip = (label, value, tone = '') => {
            if (value === null || value === undefined || value === '' || Number.isNaN(value)) return;
            chips.push(`<span class="ves-run-chip${tone ? ' is-' + tone : ''}"><b>${escapeHtml(label)}</b>${escapeHtml(String(value))}</span>`);
        };
        const markerCount = Number(data.no_result_marker_count ?? data.discarded_no_result_marker_count ?? 0) || 0;
        const adjacentCount = Number(data.adjacent_count ?? data.adjacent_match_count ?? 0) || 0;
        const directCount = Number(data.direct_count ?? data.direct_match_count ?? 0) || 0;
        if (isDebugMode(widget)) {
            chip('Recibidos', raw || filtered || '0');
            chip('Mostrados', filtered);
            if (markerCount > 0) chip('No-result markers', markerCount, 'warn');
        } else {
            chip('Señales utilizables', Number(data.usable_signal_count ?? data.useful_delivered_rows ?? filtered ?? 0) || 0);
            if (directCount > 0) chip('Directas', directCount);
            if (adjacentCount > 0) chip('Adyacentes', adjacentCount, 'warn');
        }
        if (data.best_match_count !== undefined && directCount === 0) chip('Directas', Number(data.best_match_count || 0));
        if (data.other_items_count !== undefined && adjacentCount === 0) chip('Relevantes / adyacentes', Number(data.other_items_count || 0));
        if (data.discarded_items_count !== undefined) chip('Descartados / ruido', Number(data.discarded_items_count || 0), 'warn');
        if (requested > 0 && isDebugMode(widget)) chip('Solicitados', requested);
        if (isDebugMode(widget)) {
            if (data.actor_limit) chip('Provider route limit', data.actor_limit);
            if (data.adapter_used) chip('Adapter', data.adapter_used);
            if (data.provider_run_id) chip('Provider run', String(data.provider_run_id).slice(0, 10));
        }
        if (data.dropped_by_relevance) chip('Baja relevancia', data.dropped_by_relevance, 'warn');
        if (data.dropped_by_date) chip('Fecha', data.dropped_by_date, 'warn');
        if (data.dropped_by_country) chip('País', data.dropped_by_country, 'warn');
        if (data.filters_relaxed) chip('Filtros', 'relajados', 'warn');
        const provider = safeObject(data.provider_cost);
        if (isDebugMode(widget)) {
            if (provider.estimated_usd !== undefined && provider.estimated_usd !== null) {
                chip('Coste proveedor estimado', '$' + Number(provider.estimated_usd || 0).toFixed(2), provider.limit_exceeded ? 'danger' : (provider.warning ? 'warn' : ''));
            }
            if (data.provider_cost_usd !== undefined && data.provider_cost_usd !== null) {
                const actualCost = Number(data.provider_cost_usd);
                chip('Coste proveedor', Number.isFinite(actualCost) ? '$' + actualCost.toFixed(4) : 'No disponible');
            } else if (data.provider_run_id) {
                chip('Coste proveedor', 'No disponible');
            }
        }
        const ledger = safeObject(data.usage_ledger);
        if (ledger.credits_charged_final !== undefined) chip('Créditos cobrados', ledger.credits_charged_final);
        if (ledger.credits_refunded_or_voided !== undefined && Number(ledger.credits_refunded_or_voided) > 0) chip('Créditos devueltos', ledger.credits_refunded_or_voided, 'warn');
        const rawStatusMessage = String(data.outcome_message || data.status_message || '').trim();
        // v0.9.24.12: suppress provider-internal technical status lines (not user-relevant).
        const isProviderTechnical = /^(Scraped|Crawled|Processed|Run finished)\s+\d/i.test(rawStatusMessage);
        const message = (isProviderTechnical && !isAdminUI()) ? '' : rawStatusMessage;

        // v0.9.27.0 — Phase 6: build a human-readable credit explanation panel
        // from the data the backend already returns. Renders below the chips.
        // Always-present rows: raw / usable / discarded counts. Conditional:
        // estimated, provider cost, credits charged/refunded with reason.
        const buildCreditPanel = () => {
            const raw       = Number(data.raw_provider_returned_count ?? data.raw_items ?? 0) || 0;
            const usable    = Number(data.useful_delivered_rows ?? data.displayed_rows ?? data.filtered_items ?? 0) || 0;
            const discarded = Math.max(0, Number(data.discarded_rows ?? (raw - usable)) || 0);
            const chargedF  = ledger.credits_charged_final;
            const refundedF = ledger.credits_refunded_or_voided;
            const charged   = (chargedF === undefined || chargedF === null) ? null : Number(chargedF);
            const refunded  = (refundedF === undefined || refundedF === null) ? null : Number(refundedF);
            const providerCostUsd = (data.provider_cost_usd !== undefined && data.provider_cost_usd !== null)
                ? Number(data.provider_cost_usd) : null;
            const estimated = (provider && provider.estimated_usd !== undefined && provider.estimated_usd !== null)
                ? Number(provider.estimated_usd) : null;
            const outcome = String(data.provider_outcome || '');
            // Decide refund reason copy from outcome.
            let refundReason = '';
            if (outcome === 'success_empty') {
                refundReason = 'La fuente no devolvió filas para esta consulta.';
            } else if (outcome === 'success_all_filtered') {
                refundReason = 'Se encontraron resultados, pero los filtros locales los descartaron.';
            } else if (outcome === 'invalid_input' || outcome === 'preflight_blocked') {
                refundReason = 'Validación falló antes de llamar a la fuente — no se han cargado créditos.';
            } else if (outcome === 'access_failed' || outcome === 'provider_failed') {
                refundReason = 'La fuente no completó la ejecución — no se cobra el coste variable.';
            }
            const isZeroCharge = (charged !== null && charged === 0);
            const isRefund     = (refunded !== null && refunded > 0);
            if (raw === 0 && usable === 0 && charged === null && refunded === null && estimated === null && providerCostUsd === null) {
                return ''; // Nothing meaningful to show.
            }
            const rows = [];
            if (isDebugMode(widget)) {
                rows.push(`<div class="ves-credit-row"><span>Filas recibidas</span><b>${escapeHtml(String(raw))}</b></div>`);
            }
            rows.push(`<div class="ves-credit-row"><span>Señales utilizables</span><b>${escapeHtml(String(usable))}</b></div>`);
            if (Number(data.no_result_marker_count ?? data.discarded_no_result_marker_count ?? 0) > 0 && usable === 0) {
                rows.push(`<div class="ves-credit-row ves-credit-row-muted"><span>La fuente no encontró contenido utilizable</span><b>0</b></div>`);
            }
            if (discarded > 0) {
                rows.push(`<div class="ves-credit-row ves-credit-row-muted"><span>Filtrados / descartados</span><b>${escapeHtml(String(discarded))}</b></div>`);
            }
            if (isDebugMode(widget) && estimated !== null && Number.isFinite(estimated)) {
                rows.push(`<div class="ves-credit-row ves-credit-row-muted"><span>Coste proveedor estimado</span><b>$${estimated.toFixed(2)}</b></div>`);
            }
            if (isDebugMode(widget) && providerCostUsd !== null && Number.isFinite(providerCostUsd)) {
                rows.push(`<div class="ves-credit-row ves-credit-row-muted"><span>Coste proveedor real</span><b>$${providerCostUsd.toFixed(4)}</b></div>`);
            } else if (isDebugMode(widget) && data.provider_run_id) {
                rows.push(`<div class="ves-credit-row ves-credit-row-muted"><span>Coste proveedor real</span><b>No disponible</b></div>`);
            }
            if (charged !== null) {
                rows.push(`<div class="ves-credit-row ves-credit-row-${isZeroCharge ? 'zero' : 'charge'}"><span>Créditos cobrados</span><b>${escapeHtml(String(charged))}</b></div>`);
            }
            if (isRefund) {
                rows.push(`<div class="ves-credit-row ves-credit-row-refund"><span>Créditos devueltos</span><b>${escapeHtml(String(refunded))}</b></div>`);
            }
            const noteText = isZeroCharge && refundReason
                ? `0 créditos cobrados — ${refundReason}`
                : (isRefund && refundReason
                    ? `Devolución aplicada — ${refundReason}`
                    : '');
            const noteHtml = noteText ? `<div class="ves-credit-note">${escapeHtml(noteText)}</div>` : '';
            return `<details class="ves-credit-panel" ${(isZeroCharge || isRefund) ? 'open' : ''}><summary>Detalle de créditos</summary><div class="ves-credit-rows">${rows.join('')}</div>${noteHtml}</details>`;
        };
        const creditPanelHtml = buildCreditPanel();

        if (!chips.length && !message && !creditPanelHtml) return '';
        // v0.9.27.0 — Phase 3: attach outcome class so the panel can be styled
        // differently (success_empty/partial/failed). Falls back gracefully.
        const outcomeClass = data.provider_outcome ? ` ves-outcome-${String(data.provider_outcome).replace(/[^a-z_]/gi,'')}` : '';
        return `<div class="ves-run-diagnostics${outcomeClass}"><div class="ves-run-diagnostics-chips">${chips.join('')}</div>${message ? `<div class="ves-run-diagnostics-message">${escapeHtml(safeUserMessage(message, null).slice(0, 900))}</div>` : ''}${creditPanelHtml}</div>`;
    };

    const renderQueryDiagnostics = (data, widget) => {
        if (!(isAdminUI(widget) && isDebugMode(widget))) return '';
        const rows = safeArray(data?.query_diagnostics);
        if (!rows.length) return '';
        return `<details class="ves-query-diagnostics"><summary>Per-query diagnostics</summary><div class="ves-query-diagnostics-grid">${rows.slice(0, 12).map((row) => {
            const reasons = safeObject(row.rejection_reasons);
            const reasonText = Object.keys(reasons).slice(0, 4).map((key) => `${key}: ${reasons[key]}`).join(' · ');
            return `<div class="ves-query-diagnostic-row"><strong>${escapeHtml(row.query || '')}</strong><span>returned ${escapeHtml(String(row.items_returned || 0))} · accepted ${escapeHtml(String(row.items_accepted || 0))} · rejected ${escapeHtml(String(row.items_rejected || 0))}</span>${reasonText ? `<small>${escapeHtml(reasonText)}</small>` : ''}</div>`;
        }).join('')}</div></details>`;
    };

    const renderTrendResults = (widget, data) => {
        // v0.9.9.3: scope to active page so results render in the right sub-page.
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const results = activePage.querySelector('.ves-results') || widget.querySelector('.ves-results');
        const diagnosticStatus = String(data.status || data.run_status || '').toUpperCase();
        if (['RETRYABLE_DIAGNOSTIC', 'NO_EVIDENCE'].includes(diagnosticStatus)) {
            const diag = safeObject(data.safeDiagnostic || data.safe_diagnostic);
            const sourceDiags = safeArray(data.source_diagnostics);
            const msg = data.user_message || data.message || 'Las fuentes están temporalmente ocupadas. No se consumieron créditos finales. Reintenta en unos minutos o usa una búsqueda reducida.';
            results.innerHTML = `<div class="ves-result-card ves-trend-diagnostic-card"><div class="ves-item-panel-title">Trend Finder Lite — diagnóstico</div><div class="ves-low-confidence-banner"><strong>${escapeHtml(msg)}</strong></div><div class="ves-toolbar" style="margin-top:12px"><button type="button" class="ves-btn ves-btn-secondary ves-retry-run" data-ves-retry="run">Reintentar</button><button type="button" class="ves-btn ves-btn-primary ves-retry-reduced" data-ves-retry="trend_reduced_scope">Ejecutar búsqueda reducida</button><button type="button" class="ves-btn ves-btn-secondary ves-show-diagnostic" data-ves-diagnostic="show">Ver diagnóstico</button></div><details class="ves-source-input-details ves-diagnostic-details"><summary>Diagnóstico técnico</summary><pre>${escapeHtml(JSON.stringify({ safeDiagnostic: diag, source_diagnostics: sourceDiags, credits_reserved: data.credits_reserved, final_credit_settled: data.final_credit_settled, credit_voided: data.credit_voided }, null, 2))}</pre></details></div>`;
            results.classList.add('show');
            return;
        }
        const requested = safeObject(data.requested);
        const fuentes = safeObject(data.sources);
        const structured = normalizeTrendStructuredV84(safeObject(data.analysis_structured), data);
        const fallbackOnly = !!data.fallback_used || data.confidence_mode === 'diagnostic_fallback' || data.report_status === 'fallback_diagnostic';
        const sourceCoverageLow = Number(data.usable_fuentes ?? data.successful_fuentes ?? 0) < 2 || Number(data.failed_fuentes ?? 0) > 0;
        const lowConfidence = fallbackOnly || sourceCoverageLow || data.validation_required === true;
        const canGenerateBrief = !fallbackOnly && data.can_generate_brief !== false;
        const insufficientEvidence = String(data.confidence_mode || '') !== 'validated' || ['corrupted_legacy','needs_repair','fallback_diagnostic'].includes(String(data.memory_status || '')) || data.validation_required === true;
        const effectiveCanGenerateBrief = canGenerateBrief && !insufficientEvidence;
        const briefReadyInsights = effectiveCanGenerateBrief ? safeArray(data.brief_ready_insights).filter((item) => safeTrendLabel(item.trend_name || item.name || item.title || '')) : [];
        const coverage = safeObject(data.evidence_coverage_summary);
        const sourceCoverageHtml = `<div class="ves-item-panel ves-coverage-summary"><div class="ves-item-panel-title">Resumen de cobertura de evidencia</div><div class="ves-trend-coverage-grid"><span><strong>${escapeHtml(String(coverage.sources_queried ?? data.total_fuentes ?? 0))}</strong><small>fuentes consultadas</small></span><span><strong>${escapeHtml(String(coverage.usable_records ?? 0))}</strong><small>registros útiles</small></span><span><strong>${escapeHtml(String(coverage.direct_matches ?? 0))}</strong><small>coincidencias directas</small></span><span><strong>${escapeHtml(String(coverage.adjacent_matches ?? 0))}</strong><small>señales adyacentes</small></span><span><strong>${escapeHtml(String(coverage.discarded_or_noise_count ?? 0))}</strong><small>descartado / ruido</small></span></div>${coverage.confidence_reason ? `<div class="ves-hint" style="margin-top:10px">${escapeHtml(coverage.confidence_reason)}</div>` : ''}</div>`;
        const sourceCards = Object.values(fuentes).map((srcRaw) => { const src = safeObject(srcRaw); return `
            <div class="ves-trend-source-card">
                <div class="ves-trend-source-head">
                    <strong>${escapeHtml(src.label || '')}</strong>
                    <span class="ves-trend-badge ${String(src.analytical_usability_status || '').toLowerCase() === 'usable' ? 'ok' : (String(src.analytical_usability_status || '').toLowerCase() === 'partial' ? 'mid' : 'warn')}">${escapeHtml(src.analytical_usability_status || src.status || 'UNKNOWN')}</span>
                </div>
                <div class="ves-meta">${escapeHtml(String(src.usable_records_count ?? src.item_count ?? 0))} útiles / ${escapeHtml(String(src.item_count || 0))} capturados</div>
                <div class="ves-trend-inline-note"><strong>Completitud de métricas:</strong> ${escapeHtml(String(src.metric_completeness_score ?? '0'))} · <strong>Encaje geográfico:</strong> ${escapeHtml(String(src.geo_fit_score ?? '0'))} · <strong>Encaje de idioma:</strong> ${escapeHtml(String(src.language_fit_score ?? '0'))}</div>
                ${src.notes ? `<div class="ves-hint" style="margin-top:6px">${escapeHtml(src.notes)}</div>` : ''}${src.exclusion_reason ? `<div class="ves-hint" style="margin-top:6px"><strong>Uso analítico:</strong> ${escapeHtml(src.exclusion_reason)}</div>` : ''}
                ${src.input && isDebugMode(widget) ? `<details class="ves-source-input-details"><summary>Debug: source input</summary><pre>${escapeHtml(JSON.stringify(src.input, null, 2).slice(0, 1200))}</pre></details>` : ''}
                ${safeArray(src.highlights).length ? `<ul class="ves-trend-highlights">${safeArray(src.highlights).slice(0,6).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : '<div class="ves-hint" style="margin-top:8px">Sin highlights legibles.</div>'}
            </div>
        `; }).join('');

        const scoreClass = (score) => {
            const n = Number(score) || 0;
            if (n >= 8) return 'high';
            if (n >= 5) return 'mid';
            return 'low';
        };

        const structuredSummary = structured ? `
            <div class="ves-trend-summary-grid">
                <div class="ves-item-panel" style="margin-top:0">
                    <div class="ves-item-panel-title">Resumen ejecutivo</div>
                    <div class="ves-trend-ai-report compact">${renderAnalysisRichText(structured.executive_summary || '—')}</div>
                    ${(structured.overall_take && String(structured.overall_take).trim() !== '') ? `<div class="ves-hint" style="margin-top:10px"><strong>Take duro:</strong> ${escapeHtml(structured.overall_take)}</div>` : ''}${data.confidence_mode ? `<div class="ves-hint" style="margin-top:10px"><strong>Modo de confianza:</strong> ${escapeHtml(String(data.confidence_mode))}</div>` : ''}
                </div>
                <div class="ves-item-panel" style="margin-top:0">
                    <div class="ves-item-panel-title">Calidad de señal</div>
                    ${(structured.data_quality_summary && typeof structured.data_quality_summary === 'object') ? `
                        <div class="ves-trend-inline-note"><strong>Confianza general:</strong> ${escapeHtml(structured.data_quality_summary.overall_confidence || '—')}</div>
                        <div class="ves-trend-inline-note"><strong>Señal directa de categoría:</strong> ${escapeHtml(structured.data_quality_summary.direct_topic_signal || '—')}</div>
                        <div class="ves-trend-inline-note"><strong>Evidencia vs inferencia:</strong> ${escapeHtml(structured.data_quality_summary.what_is_inference_vs_evidence || '—')}</div>
                        ${Array.isArray(structured.data_quality_summary.main_blindspots) && structured.data_quality_summary.main_blindspots.length ? `<div class="ves-trend-mini-title">Puntos ciegos</div><ul class="ves-trend-list compact">${structured.data_quality_summary.main_blindspots.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
                    ` : '<div class="ves-hint">Sin lectura de calidad de señal.</div>'}
                </div>
            </div>
            <div class="ves-item-panel">
                <div class="ves-item-panel-head">
                    <div class="ves-item-panel-title">Tendencias priorizadas</div>
                    <div class="ves-meta">${safeArray(structured.prioritized_trends).filter((trend) => safeTrendLabel(trend.trend_name || '')).length} detectadas</div>
                </div>
                ${safeArray(structured.prioritized_trends).filter((trend) => safeTrendLabel(trend.trend_name || '')).length ? `
                    <div class="ves-trend-priority-grid">
                        ${safeArray(structured.prioritized_trends).filter((trend) => safeTrendLabel(trend.trend_name || '')).map((trend) => `
                            <div class="ves-trend-priority-card">
                                <div class="ves-trend-priority-head">
                                    <div>
                                        <strong>${escapeHtml(trend.trend_name || 'Trend')}</strong>
                                        <div class="ves-meta">${escapeHtml(trend.primary_channel || '—')} · ${escapeHtml(trend.signal_type || 'signal')}</div>
                                    </div>
                                    <span class="ves-trend-score ${scoreClass(trend.fit_score)}">${escapeHtml(String(trend.fit_score || 0))}/10</span>
                                </div>
                                <div class="ves-hint" style="margin-top:8px">${escapeHtml(trend.fit_reason || '')}</div>
                                ${trend.evidence_strength ? `<div class="ves-trend-inline-note"><strong>Fuerza de evidencia:</strong> ${escapeHtml(trend.evidence_strength)}</div>` : ''}
                                ${trend.audience_or_context ? `<div class="ves-trend-inline-note"><strong>Audiencia / contexto:</strong> ${escapeHtml(trend.audience_or_context)}</div>` : ''}
                                ${trend.activation_window ? `<div class="ves-trend-inline-note"><strong>Ventana:</strong> ${escapeHtml(trend.activation_window)}</div>` : ''}
                                ${trend.recommended_action ? `<div class="ves-trend-inline-note"><strong>Qué hacer:</strong> ${escapeHtml(trend.recommended_action)}</div>` : ''}
                                ${safeArray(trend.growth_drivers).length ? `<div class="ves-trend-mini-title">Por qué puede estar creciendo</div><ul class="ves-trend-list compact">${safeArray(trend.growth_drivers).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
                                ${safeArray(trend.evidence).length ? `<div class="ves-trend-mini-title">Evidencia cruzada</div><ul class="ves-trend-list compact">${safeArray(trend.evidence).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
                                ${safeArray(trend.content_actions).length ? `<div class="ves-trend-mini-title">Acciones de contenido</div><ul class="ves-trend-list compact">${safeArray(trend.content_actions).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
                                ${(trend.risk_note && String(trend.risk_note).trim() !== '') ? `<div class="ves-trend-risk-note">⚠ ${escapeHtml(trend.risk_note)}</div>` : ''}
                                ${(trend.counterargument && String(trend.counterargument).trim() !== '') ? `<div class="ves-hint" style="margin-top:10px"><strong>Contraargumento:</strong> ${escapeHtml(trend.counterargument)}</div>` : ''}
                                <div class="ves-toolbar" style="margin-top:12px;margin-bottom:0"><button type="button" class="ves-btn ves-btn-secondary ves-use-trend" data-validation-brief="${insufficientEvidence ? '1' : '0'}" data-trend-name="${escapeHtml(trend.trend_name || '')}">${insufficientEvidence ? 'Create validation brief' : 'Usar en brief'}</button></div>
                            </div>
                        `).join('')}
                    </div>
                ` : '<div class="ves-hint">No se pudieron estructurar tendencias priorizadas.</div>'}
            </div>
            <div class="ves-item-panel">
                <div class="ves-item-panel-head">
                    <div class="ves-item-panel-title">Oportunidades accionables</div>
                    <div class="ves-meta">Promocionadas desde los trends</div>
                </div>
                ${renderTrendOpportunityBoard(structured.opportunity_board)}
            </div>
            <div class="ves-item-panel">
                <div class="ves-item-panel-title">Lectura por canal</div>
                ${safeArray(structured.channel_readouts).length ? `
                    <div class="ves-trend-channel-grid">
                        ${safeArray(structured.channel_readouts).map((row) => `
                            <div class="ves-trend-channel-card">
                                <div class="ves-trend-channel-head">
                                    <strong>${escapeHtml(row.channel || 'Canal')}</strong>
                                    <span class="ves-trend-badge ${String(row.status || '').toLowerCase() === 'usable' || String(row.status || '').toLowerCase() === 'strong' || String(row.status || '').toLowerCase() === 'succeeded' ? 'ok' : 'warn'}">${escapeHtml(row.status || 'N/A')}</span>
                                </div>
                                <div class="ves-hint">${escapeHtml(row.what_stands_out || '')}</div>
                                ${row.confidence ? `<div class="ves-trend-inline-note"><strong>Confianza:</strong> ${escapeHtml(row.confidence)}</div>` : ''}
                                ${row.relevance_to_goal ? `<div class="ves-trend-inline-note"><strong>Relevancia para el objetivo:</strong> ${escapeHtml(row.relevance_to_goal)}</div>` : ''}
                                ${row.limitation ? `<div class="ves-trend-inline-note"><strong>Límite:</strong> ${escapeHtml(row.limitation)}</div>` : ''}
                                ${safeArray(row.evidence_examples).length ? `<div class="ves-trend-mini-title">Evidencia</div><ul class="ves-trend-list compact">${safeArray(row.evidence_examples).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
                            </div>
                        `).join('')}
                    </div>
                ` : '<div class="ves-hint">No hay lectura estructurada por canal.</div>'}
            </div>
            ${(() => {
                const recs = safeObject(structured.production_recommendations);
                const labelMap = { strategic_principles: 'Principios estratégicos', angles: 'Ángulos', formats: 'Formatos', hooks: 'Hooks', first_pieces_to_produce: 'Primeras piezas', measurement_notes: 'Qué medir primero' };
                const cards = ['strategic_principles','angles','formats','hooks','first_pieces_to_produce','measurement_notes'].map((key) => {
                    const items = Array.isArray(recs[key]) ? recs[key].filter(x => String(x || '').trim() !== '') : [];
                    if (!items.length) return '';
                    return `<div class="ves-trend-rec-card"><div class="ves-trend-mini-title">${escapeHtml(labelMap[key] || key)}</div><ul class="ves-trend-list compact">${items.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul></div>`;
                }).filter(Boolean);
                if (!cards.length) return '';
                return `<div class="ves-item-panel"><div class="ves-item-panel-head"><div class="ves-item-panel-title">${insufficientEvidence ? 'Validation recommendations' : 'Recomendaciones ejecutivas'}</div><div class="ves-meta">${insufficientEvidence ? 'Validar evidencia antes de convertir en campaña' : 'Más accionables y menos genéricas'}</div></div><div class="ves-trend-rec-grid">${cards.join('')}</div></div>`;
            })()}
            ${(() => {
                const board = renderTrendActionBoard(structured.action_board);
                return board ? `<div class="ves-item-panel"><div class="ves-item-panel-head"><div class="ves-item-panel-title">Action board</div><div class="ves-meta">Decisiones rápidas para no perder foco</div></div>${board}</div>` : '';
            })()}
            ${safeArray(structured.false_positives_and_risks).length ? `<div class="ves-item-panel"><div class="ves-item-panel-title">Riesgos y falsos positivos</div><ul class="ves-trend-list">${safeArray(structured.false_positives_and_risks).map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul></div>` : ''}
        ` : '';
        results.innerHTML = `
            <div class="ves-result-card">
                <div class="ves-toolbar" style="margin-bottom:16px">
                    <button type="button" class="ves-btn ves-btn-secondary ves-dl-trend-json">⬇ Informe JSON</button>
                    <button type="button" class="ves-btn ves-btn-secondary ves-dl-trend-history-json">⬇ History JSON</button>
                    <button type="button" class="ves-btn ves-btn-secondary ves-refresh-trend-history">↻ Actualizar history</button>
                </div>
                <div class="ves-kpis">
                    <div class="ves-kpi"><small>Tema</small><strong>${escapeHtml(requested.topic || '—')}</strong></div>
                    <div class="ves-kpi"><small>País</small><strong>${escapeHtml(requested.country_label || 'Worldwide')}</strong></div>
                    <div class="ves-kpi"><small>Rango</small><strong>${escapeHtml(requested.duration_label || '')}</strong></div>
                    <div class="ves-kpi"><small>Fuentes útiles</small><strong>${escapeHtml(String(data.usable_fuentes ?? data.successful_fuentes ?? 0))}/${escapeHtml(String(data.total_fuentes || Object.keys(fuentes || {}).length || 4))}</strong></div>
                    <div class="ves-kpi"><small>Confidence mode</small><strong>${escapeHtml(String(data.confidence_mode || '—'))}</strong></div>
                </div>
                ${requested.reason ? `<div class="ves-item-panel" style="margin-top:0;margin-bottom:16px"><div class="ves-item-panel-title">Objetivo declarado</div><div class="ves-trend-inline-note" style="margin-top:0;font-size:14px;color:var(--ves-text)">${escapeHtml(requested.reason)}</div></div>` : ''}
                ${lowConfidence || insufficientEvidence ? `<div class="ves-low-confidence-banner ${data.memory_status === 'corrupted_legacy' ? 'is-repair' : ''}"><strong>${fallbackOnly ? 'Diagnostic fallback only' : (data.memory_status === 'corrupted_legacy' ? 'Needs evidence repair' : 'Analysis completed with low confidence')}</strong><span>${fallbackOnly ? 'El análisis AI falló o devolvió una salida no usable. No uses este reporte como decisión de campaña; valida evidencia y relanza AI.' : (data.memory_status === 'corrupted_legacy' ? 'Este reporte contiene labels legacy contaminados; repara Memory antes de usar comparativas o briefs.' : 'Cobertura parcial o fuentes débiles. Este reporte debe tratarse como plan de validación, no como decisión de campaña.')}</span></div>` : ''}
                ${sourceCoverageHtml}
                <div class="ves-trend-source-grid">${sourceCards}</div>
                ${structuredSummary}
                <div class="ves-item-panel ves-brief-studio-panel">
                    <div class="ves-item-panel-head">
                        <div>
                            <div class="ves-item-panel-title">Brief Studio</div>
                            <div class="ves-meta">${effectiveCanGenerateBrief ? 'Usa insights materializados y no solo texto del reporte para crear briefs más seguros.' : 'No brief can be created until validated insights exist.'}</div>
                        </div>
                        <div class="ves-meta">${data.memory_entry_id ? `Reporte #${escapeHtml(String(data.memory_entry_id || 0))}` : 'Sin reporte guardado'}</div>
                    </div>
                    <div class="ves-trend-dashboard-filters" style="margin-top:12px">
                        <select class="ves-select" name="briefTrend">${briefReadyInsights.length ? briefReadyInsights.map((trend, idx) => `<option value="${escapeHtml(String(trend.insight_id || ''))}" data-trend-name="${escapeHtml(trend.trend_name || '')}" ${idx === 0 ? 'selected' : ''}>${escapeHtml(trend.trend_name || 'Trend')} · ${escapeHtml(String(trend.confidence_score ?? '—'))}/10</option>`).join('') : '<option value="">Sin insights listos</option>'}</select>
                        <select class="ves-select" name="briefFormat">
                            <option value="TikTok / Reels">TikTok / Reels</option>
                            <option value="YouTube Short">YouTube Short</option>
                            <option value="X / Thread">X / Thread</option>
                            <option value="Campaign angle">Campaign angle</option>
                            <option value="Blog angle">Blog angle</option>
                        </select>
                        <textarea class="ves-textarea" name="briefContext" placeholder="Contexto opcional para afinar el brief..." style="min-height:84px"></textarea>
                        <button type="button" class="ves-btn ves-btn-secondary ves-create-brief" ${(effectiveCanGenerateBrief && data.memory_entry_id && briefReadyInsights.length) ? '' : 'disabled'}>${effectiveCanGenerateBrief ? 'Crear brief' : 'No brief hasta validar insights'}</button>
                    </div>
                    <div class="ves-brief-result-host"></div>
                    <div class="ves-brief-history-host" style="margin-top:14px">${renderTrendBriefHistory(safeArray(data.trend_briefs))}</div>
                </div>
                <div class="ves-item-panel">
                    <div class="ves-item-panel-title">Comparativa vs reporte anterior</div>
                    ${renderTrendComparisonBlock(data.comparison)}
                </div>
                <div class="ves-item-panel">
                    <div class="ves-item-panel-head">
                        <div class="ves-item-panel-title">History / memory</div>
                        <div class="ves-meta">${safeArray(data.trend_history).length} cortes guardados</div>
                    </div>
                    <div style="margin-bottom:14px">${renderTrendMiniTimeline(safeArray(data.trend_history))}</div>
                    ${renderTrendHistoryBlock(data.trend_history)}
                </div>
                <div class="ves-item-panel">
                    <div class="ves-item-panel-head">
                        <div class="ves-item-panel-title">Salida AI</div>
                        <div class="ves-meta">${escapeHtml(data.model || '')} · ${escapeHtml(data.analysis_format || 'text')}${isAdminUI() && data.project_context_used ? ' · Contexto reciente usado' : ''}${isAdminUI() && data.related_reports_used ? ` · ${escapeHtml(String(data.related_reports_used))} memorias/reportes relacionados` : ''}</div>
                    </div>
                    <div class="ves-trend-ai-report">${renderAnalysisRichText(data.analysis || '')}</div>
                </div>
            </div>
        `;
        results.classList.add('show');
        const state = widgetState.get(widget);
        if (state) {
            state.lastData = data;
            state.dashboardData = safeArray(data.trend_history);
            state.dashboardFilters = {
                topic: requested.topic || '',
                country: requested.country || 'None',
                duration: requested.duration || '7d',
                date_from: '',
                date_to: '',
                limit: 24,
            };
            state.dashboardComparison = data.comparison || null;
            state.dashboardSelected = [];
            renderTrendDashboard(widget, state.dashboardData, state.dashboardFilters, state.dashboardComparison);
        }
        results.querySelector('.ves-dl-trend-json')?.addEventListener('click', () => downloadFile('vietnam-estudio-trend-report.json', JSON.stringify(data, null, 2), 'application/json'));
        results.querySelector('.ves-dl-trend-history-json')?.addEventListener('click', () => downloadFile('vietnam-estudio-trend-history.json', JSON.stringify(safeArray(data.trend_history), null, 2), 'application/json'));
        results.querySelectorAll('.ves-use-trend').forEach((btn) => btn.addEventListener('click', () => {
            if (btn.dataset.validationBrief === '1') {
                setStatus(widget, 'Evidence is weak. Create a validation brief after collecting or approving a validated insight.', 'info');
            }
            const select = results.querySelector('[name="briefTrend"]');
            if (select) {
                const wanted = btn.dataset.trendName || '';
                const option = Array.from(select.options).find((opt) => opt.text.includes(wanted) || (opt.dataset && opt.dataset.trendName === wanted));
                select.value = option ? option.value : (btn.dataset.trendName || '');
                results.querySelector('.ves-brief-studio-panel')?.scrollIntoView({ behavior:'smooth', block:'start' });
            }
        }));
        results.querySelector('.ves-create-brief')?.addEventListener('click', async () => {
            try {
                const insightId = results.querySelector('[name="briefTrend"]')?.value || '';
                const trendOption = results.querySelector('[name="briefTrend"]')?.selectedOptions?.[0];
                const trendName = trendOption ? (trendOption.dataset?.trendName || trendOption.text) : '';
                if (!insightId && !trendName) {
                    setStatus(widget, 'Selecciona un trend para crear el brief.', 'error');
                    return;
                }
                setStatus(widget, '<strong>Generando brief…</strong>', 'info', true);
                const payload = await ajaxTrendBriefCreate(state.form, {
                    reportId: data.memory_entry_id || 0,
                    insightId,
                    trendName,
                    briefFormat: results.querySelector('[name="briefFormat"]')?.value || 'TikTok / Reels',
                    extraContext: results.querySelector('[name="briefContext"]')?.value || '',
                });
                data.trend_briefs = Array.isArray(payload.briefs) ? payload.briefs : [];
                renderTrendResults(widget, data);
                refreshUsageSummary(widget);
                setStatus(widget, '✅ <strong>Brief creado</strong>.', 'success');
            } catch (err) {
                setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo crear el brief.', widget)), 'error');
            }
        });
        results.querySelector('.ves-refresh-trend-history')?.addEventListener('click', async () => {
            try {
                const payload = await ajaxTrendHistory(state.form, {
                    trendCategory: requested.topic || '',
                    trendCountry: requested.country || 'None',
                    trendTimeRange: requested.duration || '7d',
                    trendRange: requested.duration || '7d',
                    limit: 10,
                });
                data.trend_history = Array.isArray(payload.history) ? payload.history : [];
                renderTrendResults(widget, data);
            } catch (err) {
                setStatus(widget, escapeHtml(safeUserMessage(err.message || 'No se pudo actualizar el historial.', widget)), 'error');
            }
        });
    };

    const renderResults = (widget, data) => {
        data = data && typeof data === 'object' ? data : {};
        let state = widgetState.get(widget);
        if (!state) {
            state = {};
            widgetState.set(widget, state);
        }
        // v0.9.9.2: scope to active page so results render in the right sub-page.
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const box = activePage.querySelector('.ves-results') || widget.querySelector('.ves-results');
        if (!box) {
            throw new Error('No se encontró el contenedor de resultados en la interfaz.');
        }
        const effectivePlatform = data.platform || activePage?.dataset?.platform || state.currentPlatform || 'tiktok';
        const cfg = CONFIG[effectivePlatform] || { label: effectivePlatform };
        data = { ...data, platform: effectivePlatform };
        const lifecycle = String(data.run_lifecycle_status || data.lifecycle_status || '').toLowerCase();
        if (lifecycle) {
            if (['queued','provider_running','normalizing','ai_processing'].includes(lifecycle)) setRunState(widget, 'running', lifecycle.replace(/_/g, ' '));
            else if (['failed','canceled'].includes(lifecycle)) setRunState(widget, 'error', lifecycle === 'canceled' ? 'Cancelado' : 'Error');
            else if (['completed_no_usable_results','completed_low_confidence','needs_validation'].includes(lifecycle)) setRunState(widget, 'success', lifecycle === 'completed_no_usable_results' ? 'Sin resultados utiles' : 'Needs validation');
            else if (lifecycle === 'completed') setRunState(widget, 'success', 'Completado');
        }
        // v0.9.24.55: Old cached responses may not include locked_constraints.
        // When rendering a live (non-memory) run, inherit the visible form's minimum
        // metric as a frontend fallback before card/JSON filtering.
        const fallbackMinMetric = currentFormMinimumMetric(widget);
        if (!data.from_memory && fallbackMinMetric > 0 && responseMinimumMetric(data) <= 0 && !responseBypassesMetricGate(data)) {
            data = {
                ...data,
                minimum_metric: fallbackMinMetric,
                locked_constraints: { ...(data.locked_constraints || {}), minimum_metric: fallbackMinMetric }
            };
        }
        const providerReturnedCount = Number(data.raw_provider_returned_count ?? data.fetched_dataset_count ?? data.raw_dataset_count ?? data.raw_items ?? data.provider_raw_count ?? 0) || 0;
        const bestInput = Array.isArray(data.best_match_items) ? data.best_match_items : (Array.isArray(data.items) ? data.items : []);
        const otherInput = Array.isArray(data.other_items) ? data.other_items : [];
        const discardedInput = Array.isArray(data.discarded_items) ? data.discarded_items : [];
        const markBucket = (items, bucket) => items.map((it) => ({ ...(it || {}), ves_result_bucket: bucket }));
        let safeItems = markBucket(bestInput, 'best').concat(markBucket(otherInput, 'other'))
            .filter((it) => !isSourceErrorItem(it) && itemHasDisplayPayload(data.platform, it));
        let safeDiscarded = markBucket(discardedInput, 'discarded').filter((it) => !isSourceErrorItem(it) && itemHasDisplayPayload(data.platform, it));
        // Frontend safety gate: the backend applies this too, but cached responses
        // or stale JS should never display cards below a locked minimum metric.
        safeItems = applyResponseMetricGate(data.platform, safeItems, data);
        safeDiscarded = applyResponseMetricGate(data.platform, safeDiscarded, data);
        const safeBestCount = safeItems.filter((it) => String(it?.ves_result_bucket || 'best') === 'best').length;
        const safeOtherCount = safeItems.filter((it) => String(it?.ves_result_bucket || '') === 'other').length;
        const markerOnly = Number(data.no_result_marker_count ?? data.discarded_no_result_marker_count ?? 0) > 0 && safeItems.length === 0 && safeDiscarded.length === 0;
        const normalizationWarning = (!markerOnly && providerReturnedCount > 0 && safeItems.length === 0 && safeDiscarded.length === 0)
            ? `No usable social signals found. Try broader terms, fewer filters, another language, or a wider date range.`
            : '';
        const markerMessage = markerOnly
            ? 'No usable social signals were found for this query. The source returned empty result markers instead of content items. Try broader keywords, another language, or a wider date range.'
            : '';
        data = { ...data, raw_provider_returned_count: providerReturnedCount, fetched_dataset_count: Number(data.fetched_dataset_count ?? providerReturnedCount), items: safeItems, discarded_items: safeDiscarded, item_count_preview: safeItems.length, best_match_count: safeBestCount, other_items_count: safeOtherCount, discarded_items_count: safeDiscarded.length, all_items_count: safeItems.length, usable_signal_count: Number(data.usable_signal_count ?? safeItems.length) || safeItems.length, generation_blocked: data.generation_blocked || markerOnly, analysis_blocked_reason: markerOnly ? 'no_results_marker' : data.analysis_blocked_reason, adapter_status: data.adapter_status || (markerOnly ? 'no_result_markers_only' : (normalizationWarning ? 'no_displayable_rows' : 'ok')), status_message: markerMessage || normalizationWarning || data.status_message };
        if (!data.run_lifecycle_status) {
            data.run_lifecycle_status = safeItems.length > 0 ? 'completed' : (providerReturnedCount > 0 ? 'completed_no_usable_results' : 'completed_no_usable_results');
        }
        syncRunStateFromData(widget, data);
        state.lastData = data;
        state.filteredItems = safeItems.slice();
        state.panelHtml = '';
        const runtimeTxt = data.runtime_secs !== null && data.runtime_secs !== undefined ? Number(data.runtime_secs).toFixed(1) + 's' : '—';
        const chargeTxt = providerCostLabel(data, widget);
        const normalizedItems = state.lastData.items.map(it => normalizeItem(data.platform, it));
        const hasMetricForFilter = normalizedItems.some(it => hasFilterMetric(data.platform, it));
        const hasRelevanceScores = normalizedItems.some(it => it.relevanceScore > 0);
        const metricLabel = cfg.primaryMetricLabel || CONFIG[data.platform]?.primaryMetricLabel || 'views';
        const diagnosticsHtml = renderResultDiagnostics(data, cfg, widget);
        const queryDiagnosticsHtml = renderQueryDiagnostics(data, widget);

        box.classList.add('show');
        const seoMode = isSeoPlatform(data.platform);
        // v0.9.25.0 — Phase 10: when 0 usable results, mark the card so CSS
        // hides toolbar filters, sort, AI focus and selection bar (X/Twitter,
        // weak Reddit etc.). The empty-state message inside .ves-view-cards
        // becomes the primary content.
        const isEmptyResults = !seoMode && safeItems.length === 0;
        const emptyClass = isEmptyResults ? ' is-empty-results' : '';
        const searchPlaceholder = seoMode ? 'Buscar keyword, cluster, intencion o angulo SERP' : 'Buscar por texto, autor o URL';
        const cardViewLabel = seoMode ? 'Tabla SEO' : 'Tarjetas';
        const sortOptions = seoMode
            ? `<option value="opportunity_score">Puntuación de oportunidad</option><option value="relevance">Relevancia</option><option value="search_volume">Volumen de búsqueda</option><option value="commercial_intent">Intención comercial</option><option value="low_competition">Baja competencia</option><option value="cpc">CPC</option><option value="questions_first">Preguntas primero</option><option value="long_tail">Long-tail primero</option>`
            : `<option value="sectioned">Mejor ajuste primero</option>${hasRelevanceScores ? '<option value="relevance">Ordenar por relevancia</option>' : ''}<option value="newest">Más reciente</option>${hasMetricForFilter ? `<option value="top_primary">Más ${escapeHtml(metricLabel)}</option>` : ''}<option value="top_comments">Más comentarios</option>`;
        box.innerHTML = `<div class="ves-result-card ${seoMode ? 'ves-result-card-seo' : ''}${emptyClass}"><div class="ves-kpis"><div class="ves-kpi"><small>Plataforma</small><strong>${escapeHtml(cfg.label || '—')}</strong></div><div class="ves-kpi"><small>${seoMode ? 'Keywords entregadas' : 'Resultados'}</small><strong class="ves-results-count">${escapeHtml((data.all_items_count ?? data.item_count_preview) || 0)}</strong></div><div class="ves-kpi"><small>Duración</small><strong>${escapeHtml(runtimeTxt)}</strong></div><div class="ves-kpi"><small>${escapeHtml(providerCostKpiLabel(widget))}</small><strong>${escapeHtml(chargeTxt)}</strong></div></div>${diagnosticsHtml}${queryDiagnosticsHtml}<div class="ves-toolbar"><button type="button" class="ves-btn ves-btn-secondary ves-dl-json">⬇ JSON</button><button type="button" class="ves-btn ves-btn-secondary ves-dl-csv">⬇ CSV</button><div class="ves-segment"><button type="button" class="active" data-view="cards">${cardViewLabel}</button><button type="button" data-view="json">JSON</button></div></div><div class="ves-result-filters"><input class="ves-input ves-filter-query" type="text" placeholder="${escapeHtml(searchPlaceholder)}"><select class="ves-select ves-filter-sort">${sortOptions}</select></div><div class="ves-field" style="margin-top:0;margin-bottom:10px"><input class="ves-input ves-analysis-focus" type="text" placeholder="Foco opcional para el análisis AI (ej. hook, CTA, retención, tono)"></div><div class="ves-batch-analysis-bar"><div><strong><span class="ves-selected-count">0</span> seleccionados</strong><span>Elige varios resultados y pide una lectura comparativa.</span></div><div><button type="button" class="ves-mini-btn ves-analyze-selected" disabled hidden>Analizar seleccionados</button><button type="button" class="ves-mini-btn ves-compare-selected" disabled hidden>Comparar seleccionados</button><button type="button" class="ves-mini-btn ves-brief-selected" disabled hidden>Generar brief de la selección</button><button type="button" class="ves-mini-btn ves-clear-selection" disabled hidden>Limpiar selección</button></div></div><div class="ves-view ves-view-cards" data-view="cards"></div><div class="ves-view ves-view-json" data-view="json" style="display:none"><div class="ves-pre"></div></div><div class="ves-panel-host"></div></div>`;

        const toggles = box.querySelectorAll('.ves-segment button');
        const views = box.querySelectorAll('.ves-view');
        toggles.forEach(b => b.addEventListener('click', () => {
            toggles.forEach(x => x.classList.remove('active'));
            b.classList.add('active');
            views.forEach(v => v.style.display = v.dataset.view === b.dataset.view ? '' : 'none');
        }));

        ['.ves-filter-query', '.ves-filter-sort'].forEach(sel => {
            box.querySelector(sel)?.addEventListener('input', () => updateRenderedResults(widget));
            box.querySelector(sel)?.addEventListener('change', () => updateRenderedResults(widget));
        });
        box.addEventListener('click', (ev) => {
            const btn = ev.target && ev.target.closest ? ev.target.closest('.ves-reset-local-filters') : null;
            if (!btn) return;
            const qEl = box.querySelector('.ves-filter-query');
            const mEl = box.querySelector('.ves-filter-likes');
            const sEl = box.querySelector('.ves-filter-sort');
            if (qEl) qEl.value = '';
            if (mEl) mEl.value = '';
            if (sEl) sEl.selectedIndex = 0;
            updateRenderedResults(widget);
        });

        box.querySelector('.ves-dl-json')?.addEventListener('click', () => downloadFile('vietnam-estudio-' + effectivePlatform + '.json', JSON.stringify(currentViewState(widget), null, 2), 'application/json'));
        box.querySelector('.ves-dl-csv')?.addEventListener('click', () => downloadFile('vietnam-estudio-' + effectivePlatform + '.csv', '\ufeff' + itemsToCsv(currentViewState(widget)), 'text/csv;charset=utf-8'));

        updateRenderedResults(widget);
        applyUILanguage(widget);
        // v0.9.31.3 Phase 10B: inject server-rendered report card when present.
        // report_html is escaped server-side (esc_html in PHP templates); safe to set as innerHTML.
        if (data.report_html && typeof data.report_html === 'string') {
            const reportContainer = activePage.querySelector('.ves-report-container') || widget.querySelector('.ves-report-container');
            if (reportContainer) { reportContainer.innerHTML = data.report_html; }
        }
    };

    const currentGoogleModule = (form) => form?.querySelector?.('[name="googleModule"]')?.value || 'search';

    const updateGoogleModuleUI = (form) => {
        if (!form || !form.classList.contains('ves-form-google')) return;
        const module = currentGoogleModule(form);
        form.querySelectorAll('[data-google-module-panel]').forEach((panel) => {
            const active = panel.dataset.googleModulePanel === module;
            panel.hidden = !active;
            panel.querySelectorAll('input, select, textarea').forEach((field) => {
                field.disabled = !active;
            });
        });
        const scope = form.closest('.ves-page') || form.closest('.ves-wrap') || document;
        renderGoogleActiveSummary(scope, form);
    };

    const renderGoogleActiveSummary = (scope, form) => {
        const el = q(scope, '[data-google-active-settings]');
        if (!el || !form) return;
        const moduleSelect = q(form, '[name="googleModule"]');
        const modeSelect = q(form, '[name="analysisMode"]');
        const module = moduleSelect?.selectedOptions?.[0]?.textContent || currentGoogleModule(form);
        const mode = modeSelect?.selectedOptions?.[0]?.textContent || 'Resumen ejecutivo';
        const activePanel = form.querySelector(`[data-google-module-panel="${cssEscape(currentGoogleModule(form))}"]`);
        const primaryInput = activePanel?.querySelector('textarea:not([disabled]), input[type="text"]:not([disabled]), input[type="url"]:not([disabled])');
        const primaryValue = primaryInput ? String(primaryInput.value || '').split(/\r?\n/).filter(Boolean).slice(0, 1)[0] : '';
        const chips = [
            `<span class="ves-filter-chip"><b>Fuente</b>${escapeHtml(module)}</span>`,
            `<span class="ves-filter-chip"><b>AI</b>${escapeHtml(mode)}</span>`,
        ];
        if (primaryValue) chips.push(`<span class="ves-filter-chip"><b>Input</b>${escapeHtml(primaryValue.slice(0, 70))}</span>`);
        // v0.9.24.9: removed internal 'run_id server-side' chip — not user-relevant.
        el.innerHTML = chips.join('');
    };

    const bindGoogleFormControls = (widget, page, form) => {
        if (!form || form.dataset.googleBound === '1') return;
        form.dataset.googleBound = '1';
        const refresh = () => renderGoogleActiveSummary(page || widget, form);
        form.querySelector('[name="googleModule"]')?.addEventListener('change', () => updateGoogleModuleUI(form));
        form.querySelectorAll('input, select, textarea').forEach((field) => {
            field.addEventListener('input', refresh);
            field.addEventListener('change', refresh);
        });
        updateGoogleModuleUI(form);
    };

    const renderGoogleResults = (widget, data) => {
        const state = widgetState.get(widget) || {};
        if (!widgetState.has(widget)) widgetState.set(widget, state);
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const box = activePage.querySelector('.ves-results') || widget.querySelector('.ves-results');
        if (!box) return;
        state.lastData = { ...(data || {}), platform:'google_intel' };
        const displayItems = Array.isArray(data.display_items) ? data.display_items : [];
        const runtimeTxt = data.runtime_secs !== null && data.runtime_secs !== undefined ? Number(data.runtime_secs).toFixed(1) + 's' : '—';
        const chargeTxt = providerCostLabel(data, widget);
        box.classList.add('show');
        // v0.9.31.1: Google packs results into nested dataset rows, so all_items_count
        // (raw provider rows) can be 1 while display_items expands to many normalized
        // cards. Show the count of results actually rendered so the "Resultados" KPI
        // matches the cards on screen instead of reading "Resultados 1".
        const rawItemCount = Number(data.all_items_count ?? data.item_count_preview ?? 0) || 0;
        const shownResultCount = displayItems.length > 0 ? displayItems.length : rawItemCount;
        const cards = displayItems.length ? displayItems.map((item, idx) => {
            const url = safeUrl(item.url || '');
            const image = safeUrl(item.image || '');
            return `<article class="ves-card ves-google-result-card">
                ${image ? `<div class="ves-google-result-media" style="background-image:url('${escapeHtml(image)}')"></div>` : ''}
                <div class="ves-google-result-body">
                    <div class="ves-card-badge">${escapeHtml(item.badge || data.module_label || 'Google')}</div>
                    <h3>${escapeHtml(item.title || 'Google result')}</h3>
                    ${item.source ? `<div class="ves-meta">${escapeHtml(item.source)}</div>` : ''}
                    ${item.text ? `<p>${escapeHtml(item.text)}</p>` : ''}
                    ${url ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">Ver fuente</a>` : ''}
                </div>
            </article>`;
        }).join('') : '<div class="ves-empty">No results were returned for this search. Try adjusting your filters or search terms.</div>';

        box.innerHTML = `<div class="ves-result-card ves-google-results-card">
            <div class="ves-kpis">
                <div class="ves-kpi"><small>Módulo</small><strong>${escapeHtml(data.module_label || data.module || 'Google')}</strong></div>
                <div class="ves-kpi"><small>Resultados</small><strong>${escapeHtml(String(shownResultCount))}</strong></div>
                <div class="ves-kpi"><small>Duración</small><strong>${escapeHtml(runtimeTxt)}</strong></div>
                <div class="ves-kpi"><small>${escapeHtml(providerCostKpiLabel(widget))}</small><strong>${escapeHtml(chargeTxt)}</strong></div>
            </div>
            ${(data.outcome_message || data.status_message) ? `<div class="ves-run-diagnostics ves-outcome-${escapeHtml(data.provider_outcome || 'default')}"><div class="ves-run-diagnostics-message">${escapeHtml(safeUserMessage(String(data.outcome_message || data.status_message), widget).slice(0, 900))}</div></div>` : ''}
            <div class="ves-toolbar">
                <button type="button" class="ves-btn ves-btn-secondary ves-google-json">⬇ JSON</button>
                <button type="button" class="ves-btn ves-btn-primary ves-google-ai" ${displayItems.length ? '' : 'disabled'}>Analizar intención</button>
                <select class="ves-select ves-google-analysis-mode">
                    <option value="summary">Resumen ejecutivo</option>
                    <option value="opportunities">Oportunidades y gaps</option>
                    <option value="content">Brief / contenido</option>
                </select>
            </div>
            <div class="ves-field" style="margin-bottom:14px"><input class="ves-input ves-google-focus-inline" type="text" maxlength="500" placeholder="Foco opcional para el análisis AI"></div>
            ${data.actor_input && isDebugMode(widget) ? `<details class="ves-source-input-details"><summary>Admin debug: entrada de proveedor</summary><pre>${escapeHtml(JSON.stringify(data.actor_input, null, 2).slice(0, 1600))}</pre></details>` : ''}
            <div class="ves-google-results-grid">${cards}</div>
            <div class="ves-panel-host ves-google-analysis-host"></div>
        </div>`;
        const formMode = activePage.querySelector('[name="analysisMode"]')?.value || 'summary';
        const select = box.querySelector('.ves-google-analysis-mode');
        if (select) select.value = formMode;
        const focus = activePage.querySelector('[name="googleFocus"]')?.value || '';
        const focusInline = box.querySelector('.ves-google-focus-inline');
        if (focusInline) focusInline.value = focus;
        box.querySelector('.ves-google-json')?.addEventListener('click', () => downloadFile('vietnam-estudio-google-intelligence.json', JSON.stringify(data, null, 2), 'application/json'));
        const autoAnalyze = activePage.querySelector('[name="googleAutoAnalyze"]');
        if (data.status === 'SUCCEEDED' && displayItems.length && autoAnalyze?.checked && data.run_id && state && state.googleAutoAnalyzedRunId !== data.run_id) {
            state.googleAutoAnalyzedRunId = data.run_id;
            setTimeout(() => box.querySelector('.ves-google-ai')?.click(), 350);
        }
        // v0.9.31.3 Phase 10B: inject server-rendered report card when present.
        if (data.report_html && typeof data.report_html === 'string') {
            const reportContainer = activePage.querySelector('.ves-report-container') || widget.querySelector('.ves-report-container');
            if (reportContainer) { reportContainer.innerHTML = data.report_html; }
        }
    };

    const pollGoogleRun = async (widget, runId, moduleKey) => {
        const state = widgetState.get(widget);
        const form = state?.form;
        const startBtn = widget.querySelector('.ves-page.is-active .ves-start') || widget.querySelector('.ves-start');
        const abortBtn = widget.querySelector('.ves-page.is-active .ves-abort') || widget.querySelector('.ves-abort');
        if (abortBtn) abortBtn.hidden = false;
        if (startBtn) { startBtn.disabled = true; startBtn.dataset.vesBusy = '1'; }
        state.currentRunId = runId;
        state.currentRunType = 'google';
        for (let i = 0; i < 36; i++) {
            try {
                const data = await ajaxGooglePoll(form, runId, moduleKey);
                if (data.status === 'SUCCEEDED') {
                    state.currentRunId = null;
                    if (abortBtn) abortBtn.hidden = true;
                    if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
                    const count = Number(data.all_items_count ?? data.item_count_preview) || 0;
                    if (count === 0 && data.status_message) {
                        const googleStatusSafe = safeUserMessage(String(data.status_message), widget);
                        setStatus(widget, `ℹ️ <strong>No hay señales utilizables.</strong><div style="margin-top:6px;font-size:12px;opacity:.9">${escapeHtml(googleStatusSafe.slice(0, 300))}</div>`, 'info');
                    } else {
                        setStatus(widget, `✅ <strong>Google Intelligence completado</strong> — ${escapeHtml(String(count))} resultados.`, 'success');
                    }
                    renderGoogleResults(widget, data);
                    refreshUsageSummary(widget);
                    return;
                }
                if (['FAILED','TIMED-OUT','TIMEOUT','ABORTED'].includes(data.status)) {
                    state.currentRunId = null;
                    if (abortBtn) abortBtn.hidden = true;
                    if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
                    const googleDetail = isAdminUI(widget) && data.status_message ? `<div style="margin-top:6px;font-size:12px;opacity:.9">${escapeHtml(String(data.status_message).slice(0, 600))}</div>` : '';
                    setStatus(widget, `⚠️ <strong>Something went wrong.</strong> Please try again in a few minutes or contact support.${googleDetail}`, 'error');
                    renderGoogleResults(widget, data);
                    refreshUsageSummary(widget);
                    return;
                }
                setStatus(widget, `Google Intelligence en curso… <strong>${escapeHtml(data.status || 'RUNNING')}</strong>`, 'info', true);
            } catch (err) {
                VES_WARN('google poll failed', err);
                finishPollingAfterError(widget, state, abortBtn, startBtn, err, 'Error al consultar Google Intelligence');
                return;
            }
            await new Promise(r => setTimeout(r, 3000));
        }
        if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
        if (abortBtn) abortBtn.hidden = true;
        state.currentRunId = null;
        setStatus(widget, 'El proceso sigue en curso. Vuelve a consultar en unos minutos.', 'info');
    };

    const formDataToPayload = (formData) => {
        const payload = {};
        formData.forEach((value, key) => {
            const normalizedKey = String(key).endsWith('[]') ? String(key).slice(0, -2) : String(key);
            if (Object.prototype.hasOwnProperty.call(payload, normalizedKey)) {
                if (!Array.isArray(payload[normalizedKey])) payload[normalizedKey] = [payload[normalizedKey]];
                payload[normalizedKey].push(value);
            } else {
                payload[normalizedKey] = value;
            }
        });
        return payload;
    };

    const currentProjectPayload = (form) => {
        const widget = form?.closest?.('.ves-wrap');
        const state = widget ? widgetState.get(widget) : null;
        const ctx = state?.projectContext || {};
        const projectId = ctx.project_id || ctx.projectId || '';
        return projectId ? { project_id: String(projectId) } : {};
    };

    const appendCurrentProjectToFormData = (form, fd) => {
        const project = currentProjectPayload(form);
        if (project.project_id && fd && typeof fd.has === 'function' && !fd.has('project_id')) {
            fd.append('project_id', project.project_id);
        }
    };

    const withCurrentProjectPayload = (form, payload = {}) => ({ ...payload, ...currentProjectPayload(form) });

    const appendAjaxParam = (fd, key, value) => {
        if (value === undefined || value === null) return;
        if (Array.isArray(value)) {
            const arrayKey = String(key).endsWith('[]') ? String(key) : `${key}[]`;
            value.forEach((item) => appendAjaxParam(fd, arrayKey, item));
            return;
        }
        fd.append(key, value);
    };

    const postAjax = async (ajaxUrl, params) => {
        const fd = new FormData();
        Object.entries(params || {}).forEach(([k, v]) => appendAjaxParam(fd, k, v));
        let res;
        try {
            res = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
        } catch (fetchErr) {
            const msg = fetchErr instanceof TypeError
                ? 'No pudimos conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.'
                : 'No se pudo conectar con el servidor. Comprueba la conexión e inténtalo de nuevo.';
            throw new Error(msg);
        }
        const text = await res.text();
        const trimmed = text.trim();
        if (trimmed === '0' || trimmed === '-1') throw new Error('La petición fue rechazada. Refresca la página e inténtalo de nuevo.');
        let json;
        try {
            json = JSON.parse(trimmed);
        } catch (e) {
            if (res.status === 429) {
                throw new Error('Demasiadas peticiones. Espera unos segundos y vuelve a intentarlo.');
            }
            const looksHtml = /^<!doctype html|^<html|<body|<br\s*\/?>|<b>fatal error<\/b>/i.test(trimmed);
            const preview = trimmed.replace(/\s+/g, ' ').slice(0, 180);
            throw new Error(
                looksHtml
                    ? `The server returned an unexpected response. Please try again in a few minutes or contact support.`
                    : `The server returned an unexpected response. Please try again in a few minutes or contact support.`
            );
        }
        if (!json || typeof json !== 'object') throw new Error('Formato inesperado.');
        if (json.success === false && (json.gated || json.mvp_disabled || json.code === 'ves_mvp_module_disabled')) {
            const mvpErr = new Error(json.data?.message || json.message || MVP_DISABLED_MESSAGE);
            mvpErr.isMvpGated = true;
            mvpErr.gatedPayload = json.data || json;
            mvpErr.requestId = json.data?.request_id || '';
            throw mvpErr;
        }
        // v0.9.26.0 — Phase 1: locked responses arrive as { success:false, code:'feature_locked', ... }
        // at top level (not inside json.data) when sent via wp_send_json directly.
        // Make them throw a typed error so the standard catch handler renders a locked card.
        if (json.success === false && (json.code === 'feature_locked' || json.code === 'linkedin_missing_input' || json.code === 'linkedin_unsupported_objective' || json.code === 'preflight_blocked')) {
            const lockErr = new Error(json.message || 'Función no disponible en tu plan.');
            lockErr.isLocked = true;
            lockErr.lockedPayload = json;
            throw lockErr;
        }
        if (json.success === false) {
            // v0.9.30.6: never append technical status or raw reference IDs to the
            // public Error.message. Keep those details only on adminDetail for
            // explicit admin/debug diagnostics.
            const err = new Error(json.data?.message || 'Error inesperado.');
            // v0.9.30.21: store structured fields separately rather than a raw
            // JSON blob — renderAdminDetail() formats them without leaking full
            // raw provider objects to the DOM.
            err.adminFields = {
                request_id: json.data?.request_id || '',
                status: json.data?.status || '',
                source: json.data?.source || '',
                technical_message: json.data?.technical_message || '',
                stage: json.data?.stage || (json.data?.safe_diagnostic?.stage || ''),
                error_category: json.data?.error_category || (json.data?.safe_diagnostic?.error_category || ''),
            };
            // Keep adminDetail for backwards compatibility with any other reads,
            // but do NOT include the full raw payload — no slugs, costs, or tokens.
            err.adminDetail = null;
            err.requestId = json.data?.request_id || '';
            err.logicalStatus = json.data?.status || '';
            // v0.9.30.19: leak-free diagnostic for non-admin operators. Backend
            // sends `safe_diagnostic` (category/stage/last_successful_stage, no
            // slugs/costs/tokens/file/line) to EVERY user so the person testing
            // can see WHERE a run stopped without needing manage_options.
            err.safeDiagnostic = (json.data && json.data.safe_diagnostic && typeof json.data.safe_diagnostic === 'object') ? json.data.safe_diagnostic : null;
            err.actions = Array.isArray(json.data?.actions) ? json.data.actions : [];
            err.error_code = json.data?.error_code || json.data?.code || err.adminFields.error_category || '';
            err.retryable = json.data?.retryable;
            throw err;
        }
        return json.data;
    };

    // v0.9.30.21: structured, readable admin diagnostics — no raw JSON blob.
    // Renders only safe fields (code, stage, source, request_id, technical_message).
    // Shown inside a collapsible <details> element so it does not overflow the page.
    const renderAdminDetail = (err) => {
        if (!err) return '';
        const sd = err.safeDiagnostic;
        const af = err.adminFields;
        const rows = [];
        const add = (label, value) => {
            const v = String(value || '').trim();
            if (v) rows.push(`<tr><th>${escapeHtml(label)}</th><td>${escapeHtml(v)}</td></tr>`);
        };
        if (af) {
            add('Request ID', af.request_id);
            add('Category', af.error_category || (sd && sd.error_category) || '');
            add('Stage', af.stage || (sd && sd.stage) || '');
            add('Source', af.source);
            add('Status', af.status);
            add('Detail', af.technical_message);
        } else if (sd) {
            add('Category', sd.error_category);
            add('Stage', sd.stage);
            add('Last stage', sd.last_successful_stage);
            add('Provider called', sd.provider_call_attempted ? 'yes' : 'no');
            add('Run created', sd.provider_run_created ? 'yes' : 'no');
        }
        if (!rows.length) return '';
        // v0.9.31.1: classes added so CSS can wrap long values and scroll internally
        // instead of forcing horizontal page overflow on narrow screens.
        return `<details class="ves-admin-detail" style="margin-top:6px;font-size:11.5px"><summary style="cursor:pointer;opacity:.75">Admin diagnostics</summary><div class="ves-admin-detail-scroll"><table class="ves-admin-detail-table">${rows.join('')}</table></div></details>`;
    };

    // v0.9.30.19: render the leak-free member-safe diagnostic for ALL users.
    // Contains only category + stage names (no actor slugs, costs, tokens, URLs,
    // or file/line) so a non-admin operator can self-diagnose where a run stopped.
    const vesSafeDiagLine = (err) => {
        const sd = err && err.safeDiagnostic;
        if (!sd || typeof sd !== 'object') return '';
        const clean = (v) => String(v || '').replace(/[^a-z0-9_]/gi, '');
        const cat = clean(sd.error_category);
        const stage = clean(sd.stage);
        const last = clean(sd.last_successful_stage);
        const parts = [];
        if (cat) parts.push('reason: ' + cat);
        if (stage) parts.push('stopped at: ' + stage);
        else if (last) parts.push('after: ' + last);
        if (!parts.length) return '';
        return `<br><small style="opacity:.7;display:block;margin-top:6px;line-height:1.35">Diagnostic — ${escapeHtml(parts.join(' · '))}</small>`;
    };

    // v0.9.31.5 BUG-01: consume safe_diagnostic.retryable. When the backend
    // marks the failure as transient, surface a manual Reintentar button.
    // When it marks the failure as non-retryable, append a small note that
    // an admin must act. When unknown (null), render nothing extra.
    const vesRetryActionHtml = (err) => {
        const sd = err && err.safeDiagnostic;
        const code = String(err?.error_code || err?.code || (sd && sd.error_category) || '').toLowerCase();
        const actions = Array.isArray(err?.actions) ? err.actions : [];
        const retryable = !!(sd && sd.retryable === true) || err?.retryable === true || code === 'provider_busy' || actions.includes('retry') || actions.includes('reduced_scope');
        if (retryable) {
            const showReduced = actions.includes('reduced_scope') || ['provider_busy','provider_rate_limit','provider_timeout','provider_transport_error'].includes(code);
            const reducedBtn = showReduced ? `<button type="button" class="ves-mini-btn ves-retry-reduced" data-ves-retry="trend_reduced_scope" style="margin-left:6px">Ejecutar búsqueda reducida</button>` : '';
            return ` <button type="button" class="ves-mini-btn ves-retry-run" data-ves-retry="run" style="margin-left:8px">Reintentar</button>${reducedBtn}<button type="button" class="ves-mini-btn ves-show-diagnostic" data-ves-diagnostic="show" style="margin-left:6px">Ver diagnóstico</button>`;
        }
        if (sd && sd.retryable === false) {
            return ` <small style="opacity:.75;margin-left:8px">Se requiere acción de administrador.</small>`;
        }
        return '';
    };

    // v0.9.31.6 BUG-E: single helper that renders the full error status payload
    // — safe user message + safe diagnostic line + retry action + admin detail.
    // Used by every "run failed" surface (start/dispatch catch, polling catch,
    // and any future dispatcher) so retryable failures consistently get a
    // Reintentar action everywhere they can happen.
    const vesErrorStatusHtml = (label, err, widget) => {
        const sd = err && err.safeDiagnostic;
        const code = String(err?.error_code || err?.code || (sd && sd.error_category) || '').toLowerCase();
        let displayLabel = label || 'Ejecución no iniciada';
        if (!label || label === 'Error inesperado') {
            if (code === 'provider_busy') displayLabel = 'Fuente temporalmente ocupada';
            else if (['dispatch_slot_limit','run_dispatch_busy','duplicate_dispatch_blocked'].includes(code)) displayLabel = 'Sistema ocupado';
            else if (['usage_reservation_failed','credit_reservation_failed'].includes(code)) displayLabel = 'Ejecución no iniciada';
            else displayLabel = 'Ejecución no iniciada';
        }
        const rawMsg = code === 'provider_busy'
            ? 'Una fuente está temporalmente ocupada. Puedes reintentar o ejecutar una búsqueda reducida.'
            : (code === 'dispatch_slot_limit' || code === 'run_dispatch_busy'
                ? 'El sistema no pudo iniciar la ejecución en este momento. No se consumieron créditos finales. Reintenta en unos segundos.'
                : (code === 'duplicate_dispatch_blocked'
                    ? 'Ya hay una ejecución en curso. Espera unos segundos o vuelve a intentarlo.'
                    : (err && err.message ? err.message : (err && err.user_message ? err.user_message : 'No se pudo iniciar la ejecución.'))));
        const msg = safeUserMessage(rawMsg, widget);
        const adminInfo = (isDebugMode(widget) || window.VES_IS_ADMIN) ? renderAdminDetail(err) : '';
        const prefix = displayLabel ? `❌ ${escapeHtml(displayLabel)}: ` : '❌ ';
        return `${prefix}${escapeHtml(msg)}${vesSafeDiagLine(err)}${vesRetryActionHtml(err)}${adminInfo}`;
    };

    // v0.9.31.5 DEBT-04: in-memory auto-retry budget per widget. Capped at 3
    // attempts and never persisted (no localStorage per JS rules). Reset
    // happens at every fresh manual start (see widget start button binding).
    const VES_AUTO_RETRY_MAX = 3;
    const VES_AUTO_RETRY_DELAY_MS = 15000;
    const vesGetAutoRetryCount = (widget) => Number(widget?.dataset?.vesAutoRetryCount || 0) || 0;
    const vesIncrementAutoRetryCount = (widget) => {
        if (!widget) return 0;
        const n = vesGetAutoRetryCount(widget) + 1;
        widget.dataset.vesAutoRetryCount = String(n);
        return n;
    };
    const vesResetAutoRetryCount = (widget) => {
        if (widget && widget.dataset && widget.dataset.vesAutoRetryCount) {
            delete widget.dataset.vesAutoRetryCount;
        }
    };
    const vesClearAutoRetryTimer = (widget) => {
        // v0.9.31.6 BUG-D: previous version guarded on _vesAutoRetryTimer and
        // never cleared the countdown interval. Now clears all three pieces of
        // state symmetrically so cancellation cannot leak a tick interval.
        if (!widget) return;
        if (widget._vesAutoRetryTimer) {
            clearTimeout(widget._vesAutoRetryTimer);
            widget._vesAutoRetryTimer = null;
        }
        if (widget._vesAutoRetryTick) {
            clearInterval(widget._vesAutoRetryTick);
            widget._vesAutoRetryTick = null;
        }
        widget._vesAutoRetryDeadline = null;
    };
    const vesScheduleAutoRetry = (widget, label) => {
        if (!widget) return false;
        vesClearAutoRetryTimer(widget);
        const remaining = Math.max(0, VES_AUTO_RETRY_MAX - vesGetAutoRetryCount(widget));
        if (remaining <= 0) return false;
        const attempt = vesGetAutoRetryCount(widget) + 1;
        const deadline = Date.now() + VES_AUTO_RETRY_DELAY_MS;
        widget._vesAutoRetryDeadline = deadline;
        const renderCountdown = () => {
            const secs = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            setStatus(widget,
                `⏳ <strong>${escapeHtml(label || 'Reintento automático')}</strong> — reintentando en ${secs} s (intento ${attempt}/${VES_AUTO_RETRY_MAX}). <button type="button" class="ves-mini-btn ves-cancel-retry" style="margin-left:8px">Cancelar</button>`,
                'info'
            );
        };
        renderCountdown();
        widget._vesAutoRetryTick = setInterval(() => {
            if (Date.now() >= deadline) {
                clearInterval(widget._vesAutoRetryTick);
                widget._vesAutoRetryTick = null;
                return;
            }
            renderCountdown();
        }, 1000);
        widget._vesAutoRetryTimer = setTimeout(() => {
            // v0.9.31.6 BUG-D: delegate cleanup to vesClearAutoRetryTimer so the
            // tick interval cannot survive a fired timeout.
            vesClearAutoRetryTimer(widget);
            vesIncrementAutoRetryCount(widget);
            // Flag the synthetic click so the start handler does not reset the
            // counter on this attempt (manual clicks always reset).
            widget._vesAutoRetryFiring = true;
            const startBtn = widget.querySelector('.ves-page.is-active .ves-start') || widget.querySelector('.ves-start');
            try { if (startBtn) startBtn.click(); } finally { widget._vesAutoRetryFiring = false; }
        }, VES_AUTO_RETRY_DELAY_MS);
        return true;
    };

    // v0.9.31.3 Phase 12: show a non-error info banner when all items were removed by filters.
    const showFilterWarningIfNeeded = (widget, data) => {
        if (!data || typeof data !== 'object') return;
        const category = String(data.error_category || (data.safe_diagnostic && data.safe_diagnostic.error_category) || '');
        const droppedByMetric = Number(data.dropped_by_metric || 0);
        const itemCount = Number(data.all_items_count ?? data.item_count_preview ?? 0) || 0;
        if (category !== 'local_filters_removed_all' && !(droppedByMetric > 0 && itemCount === 0)) return;
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const resultsBox = activePage.querySelector('.ves-results') || widget.querySelector('.ves-results');
        if (!resultsBox) return;
        let existingWarning = resultsBox.querySelector('.ves-filter-warning');
        if (!existingWarning) {
            existingWarning = document.createElement('div');
            existingWarning.className = 'ves-filter-warning';
            resultsBox.prepend(existingWarning);
        }
        existingWarning.innerHTML = `<strong>⚠️ All results removed by filters.</strong> ${droppedByMetric > 0 ? escapeHtml(`${droppedByMetric} item(s) were fetched but removed by your minimum metric threshold. `) : ''}Try lowering the filter or clearing it to see all results. <button type="button" class="ves-btn ves-btn-secondary ves-reset-local-filters" style="margin-left:8px;padding:4px 10px;font-size:12px">Clear filters</button>`;
    };

    const finishPollingAfterError = (widget, state, abortBtn, startBtn, err, label) => {
        if (state) {
            state.currentRunId = null;
            state.currentRunType = 'standard';
        }
        if (abortBtn) {
            abortBtn.style.display = 'none';
            abortBtn.hidden = true;
        }
        if (startBtn) {
            startBtn.disabled = false;
            delete startBtn.dataset.vesBusy;
        }
        const rawMsg = err && err.message ? err.message : 'Error inesperado al consultar el proceso.';
        const category = String((err.adminFields && err.adminFields.error_category) || (err.safeDiagnostic && err.safeDiagnostic.error_category) || '');
        // v0.9.31.3 Phase 12: local_filters_removed_all is an info state, not a hard error.
        if (category === 'local_filters_removed_all') {
            const msg = safeUserMessage(rawMsg, widget);
            setStatus(widget, `ℹ️ <strong>Filters removed all results.</strong> ${escapeHtml(msg)}`, 'info');
            showFilterWarningIfNeeded(widget, { error_category: category, dropped_by_metric: 1 });
            return;
        }
        // v0.9.31.5 DEBT-04: try auto-retry first when the backend marked the
        // error as transient and we still have retry budget. Falls back to the
        // permanent error state (with the manual Reintentar from BUG-01) when
        // the budget is exhausted or the category is non-retryable.
        const sd = err && err.safeDiagnostic;
        if (sd && sd.retryable === true && vesGetAutoRetryCount(widget) < VES_AUTO_RETRY_MAX) {
            if (vesScheduleAutoRetry(widget, label)) return;
        }
        // v0.9.31.6 BUG-E: shared error-status helper. Identical contract across
        // polling and start/dispatch failure paths.
        setStatus(widget, vesErrorStatusHtml(label || 'Something went wrong', err, widget), 'error');
    };


    const providerInputLines = (value) => String(value || '')
        .split(/\r\n|\r|\n|,/)
        .map(v => v.trim())
        .filter(Boolean);

    const isLongProviderSentence = (value) => {
        const v = String(value || '').trim();
        if (!v) return false;
        const words = v.split(/\s+/).filter(Boolean);
        if (words.length > 6) return true;
        return /\b(identify|analyze|analyse|prioritize|prioritise|inspire|campaign|marketing|objective|strategy|brief|audience|recommend|opportunity|insight|establish|generar|analizar|identificar|priorizar|campaña|estrategia|objetivo|recomendaci[oó]n)\b/i.test(v) && words.length > 3;
    };

    const normalizeHashtagSlug = (value) => String(value || '')
        .replace(/^#+/, '')
        .replace(/^https?:\/\/(?:www\.)?instagram\.com\/explore\/tags\//i, '')
        .replace(/\/?(?:\?.*)?$/, '')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9_]/g, '')
        .trim()
        .toLowerCase();

    const dedupeLines = (lines) => {
        const seen = new Set();
        const out = [];
        lines.forEach(line => {
            const v = String(line || '').trim();
            const key = v.toLowerCase();
            if (!v || seen.has(key)) return;
            seen.add(key);
            out.push(v);
        });
        return out;
    };

    const setHiddenTargetsValue = (form, value) => {
        let targetEl = form.querySelector('[name="targets"]');
        if (!targetEl) {
            targetEl = document.createElement('input');
            targetEl.type = 'hidden';
            targetEl.name = 'targets';
            form.appendChild(targetEl);
        }
        targetEl.value = String(value || '').trim();
        return targetEl.value;
    };

    const composeProviderTargets = (form, platform) => {
        if (!form) return '';
        const mode = form.querySelector('[name="mode"]')?.value || '';
        const seed = String(form.querySelector('[name="seedKeyword"]')?.value || form.querySelector('[name="seoSeedKeyword"]')?.value || '').trim();
        const queryTerms = providerInputLines(form.querySelector('[name="queryTerms"]')?.value || '');
        const hashtags = providerInputLines(form.querySelector('[name="hashtags"]')?.value || '')
            .map(normalizeHashtagSlug)
            .filter(v => v && !isLongProviderSentence(v));
        const profileUrls = providerInputLines(form.querySelector('[name="profileUrls"]')?.value || '');
        const postUrls = providerInputLines(form.querySelector('[name="postUrls"]')?.value || '');
        const legacyTargets = String(form.querySelector('[name="targets"]')?.value || '').trim();
        const competitorDomains = providerInputLines(form.querySelector('[name="competitorDomains"]')?.value || '');
        const targetDomain = String(form.querySelector('[name="targetDomain"]')?.value || '').trim();
        let composed = legacyTargets;
        if (platform === 'semrush' || platform === 'moz' || platform === 'ahrefs') {
            if (platform === 'semrush' && ['keyword_magic', 'keyword_simple', 'keyword_ideas', 'serp_overview'].includes(mode)) {
                composed = seed || legacyTargets;
            } else {
                composed = dedupeLines([targetDomain, ...competitorDomains, legacyTargets].filter(Boolean)).join('\n');
            }
            setHiddenTargetsValue(form, composed);
            return composed;
        }
        if (['instagram', 'tiktok', 'youtube', 'twitter', 'facebook', 'reddit', 'pinterest', 'facebook_ads', 'google_ads'].includes(platform)) {
            const lines = [];
            const urls = dedupeLines([...profileUrls, ...postUrls]).filter(v => /^https?:\/\//i.test(v) || /^@?[A-Za-z0-9_.-]{2,}$/i.test(v));
            if (platform === 'instagram') {
                hashtags.forEach(tag => lines.push('#' + tag));
                urls.forEach(u => lines.push(u));
            } else if (platform === 'reddit') {
                // Reddit is community/search driven. Hashtags are deliberately ignored
                // because they create noisy, fake terms like #marketing Target.
                queryTerms.forEach(term => { if (!isLongProviderSentence(term)) lines.push(term); });
                urls.filter(u => /^https?:\/\/(?:www\.)?reddit\.com\//i.test(u)).forEach(u => lines.push(u));
            } else if (platform === 'facebook_ads' || platform === 'google_ads') {
                queryTerms.forEach(term => { if (!isLongProviderSentence(term)) lines.push(term); });
                competitorDomains.forEach(domain => lines.push(domain));
                if (targetDomain) lines.push(targetDomain);
                urls.forEach(u => lines.push(u));
            } else {
                queryTerms.forEach(term => { if (!isLongProviderSentence(term)) lines.push(term); });
                if (['tiktok', 'twitter', 'pinterest'].includes(platform)) hashtags.forEach(tag => lines.push('#' + tag));
                urls.forEach(u => lines.push(u));
            }
            composed = dedupeLines(lines).join('\n');
            setHiddenTargetsValue(form, composed);
            return composed;
        }
        setHiddenTargetsValue(form, composed);
        return composed;
    };

    const selectedPlatformObjectiveMismatch = (form, platform) => {
        const text = [
            form.querySelector('[name="businessObjective"]')?.value,
            form.querySelector('[name="searchContext"]')?.value,
            form.querySelector('[name="aiAnalysisGoal"]')?.value,
            form.querySelector('[name="queryTerms"]')?.value
        ].map(v => String(v || '')).join(' ').toLowerCase();
        if (platform === 'instagram' && /\btiktok\b/.test(text)) return 'La plataforma seleccionada es Instagram, pero el objetivo menciona TikTok. Cambia la plataforma a TikTok o adapta la búsqueda a Instagram antes de ejecutar.';
        if (platform === 'tiktok' && /\binstagram\b/.test(text)) return 'La plataforma seleccionada es TikTok, pero el objetivo menciona Instagram. Cambia la plataforma a Instagram o adapta la búsqueda a TikTok antes de ejecutar.';
        return '';
    };

    const validateStandardRunInput = (form, platform, cfg) => {
        const mismatch = selectedPlatformObjectiveMismatch(form, platform);
        if (mismatch) return mismatch;
        const targets = composeProviderTargets(form, platform);
        const mode = form.querySelector('[name="mode"]')?.value || cfg?.defaultMode || 'search';
        const targetlessModeAllowed = (platform === 'semrush' && mode === 'top_websites') || (platform === 'ahrefs' && mode === 'top_websites');
        const inputText = (name) => String(form.querySelector(`[name="${name}"]`)?.value || '').trim();
        const lineValues = (name) => dedupeLines(inputText(name).split(/\r\n|\r|\n|,/).map(v => String(v || '').trim()).filter(Boolean));
        const hasAnyLines = (...names) => names.some(name => lineValues(name).length > 0);
        const hasAnyUrl = (...names) => names.some(name => lineValues(name).some(v => /^https?:\/\//i.test(v)));
        const hasAnyHandle = (...names) => names.some(name => lineValues(name).some(v => /^@?[A-Za-z0-9_.-]{2,}$/i.test(v)));
        const actorSupportsInstagramKeyword = fieldEnabled(form.querySelector('[name="instagramSupportsKeywordSearch"]')) || form.dataset.instagramKeywordSearch === '1' || form.dataset.instagramKeywordSupported === '1';
        const platformValidation = (() => {
            if (platform === 'tiktok') {
                const ok = hasAnyLines('queryTerms','hashtags','profileUrls','postUrls') || !!targets;
                return ok ? '' : 'Introduce al menos un término de búsqueda, hashtag, perfil o URL de vídeo.';
            }
            if (platform === 'youtube') {
                const ok = hasAnyLines('queryTerms') || hasAnyUrl('postUrls','profileUrls') || hasAnyHandle('profileUrls') || !!targets;
                return ok ? '' : 'Introduce al menos una búsqueda, canal o URL de vídeo de YouTube.';
            }
            if (platform === 'instagram') {
                const explicit = hasAnyLines('hashtags','profileUrls','postUrls') || hasAnyUrl('profileUrls','postUrls');
                const keywordOnly = hasAnyLines('queryTerms') && !explicit;
                if (explicit || (keywordOnly && actorSupportsInstagramKeyword)) return '';
                return 'Introduce hashtags, perfiles o URLs de posts/reels compatibles.';
            }
            if (platform === 'reddit') {
                const subreddit = hasAnyLines('subreddit','subreddits','redditCommunity','redditSubreddit');
                const ok = hasAnyLines('queryTerms') || subreddit || hasAnyUrl('postUrls','profileUrls') || !!targets;
                return ok ? '' : 'Introduce una búsqueda, comunidad o URL pública de Reddit.';
            }
            if (platform === 'twitter') {
                const ok = hasAnyLines('queryTerms','hashtags','profileUrls','postUrls') || hasAnyHandle('profileUrls') || !!targets;
                return ok ? '' : 'Introduce al menos una búsqueda, hashtag, perfil o URL de post de X/Twitter.';
            }
            return '';
        })();
        if (platformValidation) return platformValidation;
        if (platform === 'facebook_ads' || platform === 'google_ads') {
            const adsTarget = String((form.querySelector('[name="queryTerms"]')?.value || '') + (form.querySelector('[name="postUrls"]')?.value || '') + (form.querySelector('[name="competitorDomains"]')?.value || '') + (form.querySelector('[name="targetDomain"]')?.value || '')).trim();
            if (!adsTarget) return platform === 'google_ads' ? 'Google Ads Intelligence necesita un dominio, anunciante o keyword.' : 'Meta Ads Intelligence necesita un anunciante, pagina, dominio o keyword.';
        }
        if (!targets && !targetlessModeAllowed) return 'Introduce una entrada válida para la plataforma seleccionada antes de ejecutar.';
        if (platform === 'semrush' && mode === 'keyword_magic') {
            const seed = String(form.querySelector('[name="seedKeyword"]')?.value || targets || '').trim();
            if (!seed) return 'Keyword Magic necesita una keyword semilla corta, por ejemplo herramientas seo o cerveza mahou.';
            if (isLongProviderSentence(seed) || seed.split(/\s+/).filter(Boolean).length > 6) return 'Semrush Keyword Magic necesita una keyword corta, no un objetivo de negocio. Usa 2-5 palabras, por ejemplo herramientas seo, cerveza mahou o ai seo tools.';
        }
        const lines = targets.split(/\r\n|\r|\n/).map(line => line.trim()).filter(Boolean);

        const hasUrlLike = lines.some(line => /^https?:\/\//i.test(line) || /^[a-z0-9.-]+\.[a-z]{2,}(?:\/.*)?$/i.test(line));
        const hasKeywordLike = lines.some(line => !(/^https?:\/\//i.test(line) || /^[a-z0-9.-]+\.[a-z]{2,}(?:\/.*)?$/i.test(line)));
        if (platform === 'reddit' && mode === 'urls' && lines.some(line => !/^https?:\/\/(?:www\.)?reddit\.com\//i.test(line))) {
            return 'En modo URL de Reddit, cada linea debe ser una URL publica de reddit.com.';
        }
        if (platform === 'pinterest' && mode === 'urls' && lines.some(line => !/^https?:\/\/(?:www\.)?pinterest\.[a-z.]+\//i.test(line))) {
            return 'En modo URL de Pinterest, cada linea debe ser una URL publica de Pinterest.';
        }
        if (platform === 'moz' && !hasUrlLike) {
            return 'MOZ necesita al menos un dominio o URL válido.';
        }
        if (platform === 'semrush') {
            if (mode === 'keyword_magic' && !targets) return 'Keyword Magic necesita una keyword principal.';
            if (mode === 'domain_seo') {
                const semrushField = (name) => form.querySelector(`[name="${name}"]`);
                const domainModuleNames = [
                    'semrushIncludeAuthority', 'semrushIncludeOverview', 'semrushIncludeTraffic',
                    'semrushIncludeBacklinks', 'semrushIncludeAiVisibility', 'semrushIncludeCompetitors',
                    'semrushIncludeKeywordsRanking', 'semrushIncludeSeo', 'semrushIncludeSerp'
                ];
                const needsUrl = domainModuleNames.some((name) => fieldEnabled(semrushField(name)));
                // Hidden defaults mean "collect safe context if available"; they should not force a keyword in pure domain mode.
                const keywordVolumeEl = semrushField('semrushIncludeKeywordVolume');
                const serpEl = semrushField('semrushIncludeSerp');
                const needsKeyword = !!((fieldIsUserToggle(keywordVolumeEl) && fieldEnabled(keywordVolumeEl)) || (fieldIsUserToggle(serpEl) && fieldEnabled(serpEl)));
                const seoKeyword = String(form.querySelector('[name="semrushSeoKeyword"]')?.value || '').trim();
                const seedKeyword = String(form.querySelector('[name="seedKeyword"]')?.value || '').trim();
                if (!needsKeyword && !needsUrl) return 'Activa al menos un módulo Semrush Domain/SEO antes de ejecutar.';
                if (needsUrl && !hasUrlLike) return 'El flujo Domain/SEO de Semrush necesita al menos un dominio válido, por ejemplo mahou.es o https://mahou.es.';
                if (needsKeyword && !seoKeyword && !seedKeyword && !hasKeywordLike) return 'Keyword Volume o SERP necesitan una keyword corta en Keyword semilla o en el campo keyword opcional.';
            }
        }
        if (platform === 'ahrefs') {
            const needsUrl = ['website_authority','backlinks_overview','backlinks_list','broken_links','traffic_overview','website_details','keyword_rank'].includes(mode);
            const needsKeyword = ['ai_visibility','keyword_ideas','keyword_difficulty','keyword_metrics','keyword_rank','serp_overview'].includes(mode);
            if (needsUrl && !hasUrlLike) return 'Este search type de Ahrefs necesita al menos un dominio o URL.';
            if (needsKeyword && !hasKeywordLike) return mode === 'keyword_rank' ? 'Keyword Rank necesita una keyword y un dominio/URL.' : 'Este search type de Ahrefs necesita una keyword, marca o frase.';
        }
        if (isPostUrlMode(mode)) {
            const invalid = lines.find(line => !/^https?:\/\//i.test(line));
            if (invalid) return 'En modo URL de post, cada línea debe ser una URL completa que empiece por http o https.';
        }
        if (mode === 'url_profile') {
            const invalid = lines.find(line => !(/^https?:\/\//i.test(line) || /^@?[A-Za-z0-9_.-]{2,}$/i.test(line)));
            if (invalid) return 'En modo perfil, usa URLs públicas o handles simples, uno por línea.';
        }
        const limitEl = form.querySelector('[name="limit"]');
        const limit = Number(limitEl?.value || 0);
        // v0.9.24.6: server-side max is ves_max_items() (default 200);
        // the form select only shows up to 50, but allow up to 200 in case the
        // value is set programmatically or the select is extended.
        const limitMax = Number(form.dataset.limitMax) || 200;
        if (!Number.isFinite(limit) || limit < 1 || limit > limitMax) return `La cantidad debe estar entre 1 y ${limitMax} resultados.`;
        const minViewsEl = form.querySelector('[name="minViews"]');
        if (minViewsEl && minViewsEl.offsetParent !== null && minViewsEl.value !== '') {
            const minViews = parseHumanNumberMaybe(minViewsEl.value);
            const label = CONFIG[platform]?.primaryMetricLabel || 'views';
            if (minViews === null || minViews < 0) return `Mínimo ${label} debe ser 0 o un número positivo.`;
        }
        return '';
    };


    const highlightValidationTarget = (form, platform, message = '') => {
        if (!form) return;
        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        const candidatesByPlatform = {
            tiktok: ['queryTerms','hashtags','profileUrls','postUrls'],
            youtube: ['queryTerms','profileUrls','postUrls'],
            instagram: ['hashtags','profileUrls','postUrls','queryTerms'],
            reddit: ['queryTerms','subreddit','profileUrls','postUrls'],
            twitter: ['queryTerms','hashtags','profileUrls','postUrls'],
            semrush: /dominio|Domain\/SEO/i.test(message) ? ['competitorDomains','targetDomain','seedKeyword'] : ['seedKeyword','queryTerms'],
            facebook_ads: ['queryTerms','competitorDomains','targetDomain','postUrls'],
            google_ads: ['queryTerms','competitorDomains','targetDomain','postUrls'],
        };
        const names = candidatesByPlatform[platform] || ['queryTerms','targets','postUrls','profileUrls'];
        let el = null;
        for (const name of names) { el = form.querySelector(`[name="${name}"]`); if (el) break; }
        if (!el) el = form.querySelector('textarea, input, select');
        const field = el?.closest?.('.ves-field') || el;
        field?.classList?.add('is-invalid');
        try { field?.scrollIntoView?.({ behavior: 'smooth', block: 'center' }); } catch (_) {}
        setTimeout(() => { try { el?.focus?.({ preventScroll: true }); } catch (_) {} }, 250);
    };

    const clientDispatchPlanHtml = (form, platform) => {
        const cfg = CONFIG[platform] || {};
        const mode = form.querySelector('[name="mode"]')?.value || cfg.defaultMode || 'search';
        const targets = composeProviderTargets(form, platform);
        const lines = targets.split(/\r\n|\r|\n/).map((line) => line.trim()).filter(Boolean);
        const limit = form.querySelector('[name="limit"]')?.value || '—';
        const shown = lines.slice(0, 4).map((line) => escapeHtml(line.length > 80 ? line.slice(0, 77) + '…' : line)).join(' · ') || 'esta fuente no requiere términos de búsqueda';
        return `<strong>Plan de ejecución</strong><div style="margin-top:6px;font-size:12px;opacity:.9">Fuente: ${escapeHtml(cfg.label || platform)} · Flujo: ${escapeHtml(mode)} · Resultado esperado: ${escapeHtml(String(limit))}</div><div style="margin-top:4px;font-size:12px;opacity:.86">Entrada de búsqueda: ${shown}</div>`;
    };

    const ajaxStart = async (form) => {
        if (!form?.dataset?.ajax) {
            throw new Error('Configuración de formulario inválida — recarga la página.');
        }
        syncOutputLanguageInputs(form.closest('.ves-wrap') || document.body);
        composeProviderTargets(form, form.querySelector('[name="platform"]')?.value || '');
        const fd = new FormData(form);
        appendCurrentProjectToFormData(form, fd);
        fd.append('action', 'ves_start');
        return postAjax(form.dataset.ajax, formDataToPayload(fd));
    };
    const ajaxPoll = (form, platform, runId) => { composeProviderTargets(form, platform); const fd = new FormData(form); const payload = formDataToPayload(fd); payload.action = 'ves_poll'; payload.platform = platform; payload.runId = runId; return postAjax(form.dataset.ajax, payload); };
    const ajaxAbort = (form, runId) => postAjax(form.dataset.ajax, { action:'ves_abort', nonce: form.querySelector('[name="nonce"]')?.value ?? '', runId });
    const ajaxAnalyze = (form, platform, item, focusPrompt) => postAjax(form.dataset.ajax, withCurrentProjectPayload(form, { action:'ves_analyze_item', nonce: form.querySelector('[name="nonce"]')?.value ?? '', platform, item: JSON.stringify(item), focusPrompt: focusPrompt || '', outputLanguage: form.querySelector('[name="outputLanguage"]')?.value || 'es' }));
    const ajaxAnalyzeBatch = (form, platform, items, focusPrompt) => postAjax(form.dataset.ajax, withCurrentProjectPayload(form, { action:'ves_analyze_items', nonce: form.querySelector('[name="nonce"]')?.value ?? '', platform, items: JSON.stringify(items), focusPrompt: focusPrompt || '', outputLanguage: form.querySelector('[name="outputLanguage"]')?.value || 'es' }));
    const ajaxMemoryList = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_memory_list', nonce: form.querySelector('[name="nonce"]')?.value ?? '', ...payload });
    const ajaxMemoryGet = (form, entryId) => postAjax(form.dataset.ajax, { action:'ves_memory_get', nonce: form.querySelector('[name="nonce"]')?.value ?? '', entryId });
    const normalizeTrendLitePayload = (payload, reduced = false) => {
        const next = { ...payload };
        next.sourceBudgetLevel = 'low_cost';
        next.budget_level = 'cheap_probe';
        const mode = reduced ? 'search_only' : (next.trendFuentes || next.trendSources || next.sourceMode || next.source_mode || 'lite');
        next.trendFuentes = mode;
        next.trendSources = mode;
        next.sourceMode = mode;
        next.source_mode = mode;
        const maxPool = reduced ? 15 : 20;
        const requestedPool = Number(next.candidatePoolSize || next.candidate_pool || maxPool);
        next.candidatePoolSize = Math.max(5, Math.min(maxPool, Number.isFinite(requestedPool) ? requestedPool : maxPool));
        next.candidate_pool = next.candidatePoolSize;
        const requestedFinal = Number(next.desiredFinalTrends || next.desired_final_trends || 4);
        next.desiredFinalTrends = Math.max(1, Math.min(reduced ? 3 : 5, Number.isFinite(requestedFinal) ? requestedFinal : 4));
        next.desired_final_trends = next.desiredFinalTrends;
        next.maxSources = reduced ? 2 : Math.min(3, Number(next.maxSources || next.max_sources || 3) || 3);
        next.max_sources = next.maxSources;
        if (reduced) { next.reducedScope = '1'; next.reduced_scope = '1'; }
        return next;
    };
    const ajaxTrendStart = async (form) => { syncOutputLanguageInputs(form.closest('.ves-wrap') || document.body); const fd = new FormData(form); appendCurrentProjectToFormData(form, fd); fd.append('action', 'ves_trends_start'); return postAjax(form.dataset.ajax, normalizeTrendLitePayload(formDataToPayload(fd), form.dataset.vesReducedScope === '1')); };
    const ajaxTrendPoll = (form, bundleId) => postAjax(form.dataset.ajax, { action:'ves_trends_poll', nonce: form.querySelector('[name="nonce"]')?.value ?? '', bundleId });
    const ajaxTrendAbort = (form, bundleId) => postAjax(form.dataset.ajax, { action:'ves_trends_abort', nonce: form.querySelector('[name="nonce"]')?.value ?? '', bundleId });
    const ajaxBrandAuditStart = async (form) => { syncOutputLanguageInputs(form.closest('.ves-wrap') || document.body); const fd = new FormData(form); appendCurrentProjectToFormData(form, fd); fd.append('action', 'ves_brand_audit_start'); return postAjax(form.dataset.ajax, formDataToPayload(fd)); };
    const ajaxBrandAuditPoll = (form, bundleId) => postAjax(form.dataset.ajax, { action:'ves_brand_audit_poll', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId });
    const ajaxBrandAuditActive = (form) => postAjax(form.dataset.ajax, { action:'ves_brand_audit_active', nonce: form.querySelector('[name="nonce"]')?.value || '' });
    const ajaxBrandAuditAbort = (form, bundleId) => postAjax(form.dataset.ajax, { action:'ves_brand_audit_abort', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId });
    const ajaxBrandAuditHistory = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_brand_audit_history', nonce: form.querySelector('[name="nonce"]')?.value || '', ...payload });
    const ajaxEvidenceSummary = (form, bundleId) => postAjax(form.dataset.ajax, { action:'ves_evidence_summary', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId });
    const ajaxEvidenceList = (form, bundleId, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_evidence_list', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId, ...payload });
    const ajaxEvidenceGet = (form, bundleId, evidenceId) => postAjax(form.dataset.ajax, { action:'ves_evidence_get', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId, evidenceId });
    const ajaxAssistantAsk = (form, payload = {}) => postAjax(form.dataset.ajax, withCurrentProjectPayload(form, { action:'ves_assistant_ask', nonce: form.querySelector('[name="nonce"]')?.value || '', ...payload }));
    const ajaxAssistantThreadMessages = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_assistant_thread_messages', nonce: form.querySelector('[name="nonce"]')?.value || '', ...payload });
    const ajaxIntelligenceSummary = (form, bundleId) => postAjax(form.dataset.ajax, { action:'ves_intelligence_summary', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId });
    const ajaxMetricSnapshotsList = (form, bundleId, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_metric_snapshots_list', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId, ...payload });
    const ajaxPatternCandidatesList = (form, bundleId, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_pattern_candidates_list', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId, ...payload });
    const ajaxInsightRecordsList = (form, bundleId, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_insight_records_list', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId, ...payload });
    const ajaxOpportunityRecordsList = (form, bundleId, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_opportunity_records_list', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId, ...payload });
    const ajaxInsightUpdateStatus = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_insight_update_status', nonce: form.querySelector('[name="nonce"]')?.value || '', ...payload });
    const ajaxOpportunityUpdateStatus = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_opportunity_update_status', nonce: form.querySelector('[name="nonce"]')?.value || '', ...payload });
    const ajaxGenerateBriefFromOpportunity = (form, payload = {}) => postAjax(form.dataset.ajax, withCurrentProjectPayload(form, { action:'ves_generate_brief_from_opportunity', nonce: form.querySelector('[name="nonce"]')?.value || '', ...payload }));
    const ajaxBriefRecordsList = (form, bundleId, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_brief_records_list', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId, ...payload });
    const ajaxBriefLibraryList = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_brief_library_list', nonce: form.querySelector('[name="nonce"]')?.value || '', ...payload });
    const ajaxBriefUpdateStatus = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_brief_update_status', nonce: form.querySelector('[name="nonce"]')?.value || '', ...payload });
    const ajaxWorkflowEventsList = (form, bundleId, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_workflow_events_list', nonce: form.querySelector('[name="nonce"]')?.value || '', bundleId, ...payload });
    const ajaxUsageEstimate = (form, payload = {}) => postAjax(form.dataset.ajax, withCurrentProjectPayload(form, { action:'ves_usage_estimate', nonce: form.querySelector('[name="nonce"]')?.value || '', ...payload }));
    const ajaxPayloadPreview = (form) => {
        const fd = new FormData(form);
        appendCurrentProjectToFormData(form, fd);
        const payload = formDataToPayload(fd);
        payload.action = 'ves_payload_preview';
        if (isDebugMode(form.closest('.ves-wrap'))) payload.debugMode = '1';
        return postAjax(form.dataset.ajax, payload);
    };
    const BRAND_AUDIT_PLATFORM_URL_FIELDS = [
        { name: 'instagramUrl', label: 'Instagram', hosts: ['instagram.com', 'www.instagram.com'] },
        { name: 'tiktokUrl', label: 'TikTok', hosts: ['tiktok.com', 'www.tiktok.com', 'm.tiktok.com'] },
        { name: 'youtubeUrl', label: 'YouTube', hosts: ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'] },
        { name: 'twitterUrl', label: 'X / Twitter', hosts: ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com', 'mobile.twitter.com'] },
        { name: 'facebookUrl', label: 'Facebook', hosts: ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.com', 'www.fb.com'] },
        { name: 'linkedinUrl', label: 'LinkedIn', hosts: ['linkedin.com', 'www.linkedin.com'] },
        { name: 'googleMapsUrl', label: 'Google Maps', hosts: ['google.com', 'www.google.com', 'maps.google.com', 'goo.gl'] }
    ];

    const brandAuditHostMatches = (host, allowedHosts) => {
        host = String(host || '').toLowerCase().replace(/^www\./, '');
        return allowedHosts.some((allowed) => {
            allowed = String(allowed || '').toLowerCase().replace(/^www\./, '');
            return host === allowed || host.endsWith('.' + allowed);
        });
    };

    const validateBrandAuditPlatformUrls = (form) => {
        const warnings = [];
        BRAND_AUDIT_PLATFORM_URL_FIELDS.forEach((field) => {
            const input = form?.querySelector?.(`[name="${field.name}"]`);
            if (!input) return;
            const value = String(input.value || '').trim();
            input.classList.remove('ves-input-warning');
            if (!value) return;
            let url;
            try {
                url = new URL(/^https?:\/\//i.test(value) ? value : `https://${value}`);
            } catch (err) {
                input.classList.add('ves-input-warning');
                warnings.push(`${field.label}: la URL no es válida.`);
                return;
            }
            if (!['http:', 'https:'].includes(url.protocol) || !brandAuditHostMatches(url.hostname, field.hosts)) {
                input.classList.add('ves-input-warning');
                warnings.push(`${field.label}: parece una URL de otra plataforma (${url.hostname}).`);
            }
        });
        return warnings;
    };

    const ajaxTrendHistory = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_trends_history', nonce: form.querySelector('[name="nonce"]')?.value ?? '', ...payload });
    const ajaxTrendDashboard = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_trends_dashboard', nonce: form.querySelector('[name="nonce"]')?.value ?? '', ...payload });
    const ajaxTrendCompare = (form, reportA, reportB) => postAjax(form.dataset.ajax, { action:'ves_trends_compare', nonce: form.querySelector('[name="nonce"]')?.value ?? '', reportA, reportB });
    const ajaxTrendBriefCreate = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_trends_brief_create', nonce: form.querySelector('[name="nonce"]')?.value ?? '', ...payload });
    const ajaxTrendMonitorList = (form) => postAjax(form.dataset.ajax, { action:'ves_trend_monitors_list', nonce: form.querySelector('[name="nonce"]')?.value ?? '' });
    const ajaxTrendMonitorSave = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_trend_monitors_save', nonce: form.querySelector('[name="nonce"]')?.value ?? '', ...payload });
    const ajaxTrendMonitorDelete = (form, monitorId) => postAjax(form.dataset.ajax, { action:'ves_trend_monitors_delete', nonce: form.querySelector('[name="nonce"]')?.value ?? '', monitorId });
    const ajaxProjectContextGet = (form) => postAjax(form.dataset.ajax, { action:'ves_project_context_get', nonce: form.querySelector('[name="nonce"]')?.value ?? '' });
    const ajaxProjectContextSave = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_project_context_save', nonce: form.querySelector('[name="nonce"]')?.value ?? '', ...payload });
    const ajaxWorkspaceKnowledgeList = (form) => postAjax(form.dataset.ajax, { action:'ves_workspace_knowledge_list', nonce: form.querySelector('[name="nonce"]')?.value ?? '' });
    const ajaxWorkspaceKnowledgeUpload = (form, file, title='') => postAjax(form.dataset.ajax, { action:'ves_workspace_knowledge_upload', nonce: form.querySelector('[name="nonce"]')?.value ?? '', knowledge_file: file, knowledge_title: title || '' });
    const ajaxWorkspaceKnowledgeIngest = (form, payload = {}) => postAjax(form.dataset.ajax, { action:'ves_workspace_knowledge_ingest', nonce: form.querySelector('[name="nonce"]')?.value ?? '', knowledge_url: payload.url || '', knowledge_url_title: payload.title || '' });
    const ajaxWorkspaceKnowledgeDelete = (form, knowledgeId) => postAjax(form.dataset.ajax, { action:'ves_workspace_knowledge_delete', nonce: form.querySelector('[name="nonce"]')?.value ?? '', knowledgeId });
    const ajaxUsageSummary = (form) => postAjax(form.dataset.ajax, { action:'ves_usage_summary', nonce: form.querySelector('[name="nonce"]')?.value ?? '' });
    const ajaxGoogleStart = async (form) => { syncOutputLanguageInputs(form.closest('.ves-wrap') || document.body); const fd = new FormData(form); appendCurrentProjectToFormData(form, fd); fd.append('action', 'ves_google_start'); return postAjax(form.dataset.ajax, formDataToPayload(fd)); };
    const ajaxGooglePoll = (form, runId, moduleKey) => postAjax(form.dataset.ajax, { action:'ves_google_poll', nonce: form.querySelector('[name="nonce"]')?.value ?? '', runId, googleModule: moduleKey || currentGoogleModule(form) });
    const ajaxGoogleAnalyze = (form, runId, moduleKey, analysisMode, googleFocus) => postAjax(form.dataset.ajax, { action:'ves_google_analyze', nonce: form.querySelector('[name="nonce"]')?.value ?? '', runId, googleModule: moduleKey || currentGoogleModule(form), analysisMode: analysisMode || 'summary', googleFocus: googleFocus || '', outputLanguage: form.querySelector('[name="outputLanguage"]')?.value || 'es' });
    const pruneAnalysisRaw = (raw) => {
        if (!raw || typeof raw !== 'object') return {};
        const clone = { ...raw };
        // Keep the evidence pack rich, but avoid blowing up AJAX with huge binary/media fields.
        ['mediaUrls','images','imageUrls','downloadedFiles','videoData','rawHtml','html','__debug'].forEach(k => {
            if (Array.isArray(clone[k]) && clone[k].length > 12) clone[k] = clone[k].slice(0, 12);
            if (typeof clone[k] === 'string' && clone[k].length > 5000) clone[k] = clone[k].slice(0, 5000) + '…';
        });
        return clone;
    };
    const vesNullableMetric = (value) => {
        if (value === 0 || value === '0') return 0;
        if (value === undefined || value === null) return null;
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (!trimmed || trimmed === '-' || trimmed.toLowerCase() === 'n/a') return null;
            const mult = /k$/i.test(trimmed) ? 1000 : (/m$/i.test(trimmed) ? 1000000 : 1);
            const numeric = Number(trimmed.replace(/[,+\s]/g, '').replace(/[kKmM]$/, ''));
            return Number.isFinite(numeric) ? Math.round(numeric * mult) : null;
        }
        const numeric = Number(value);
        return Number.isFinite(numeric) ? numeric : null;
    };
    const vesFirstMetric = (...values) => {
        for (const value of values) {
            const parsed = vesNullableMetric(value);
            if (parsed !== null) return parsed;
        }
        return null;
    };
    const buildAnalysisPayload = (platform, card, rawItem = null) => {
        const previewRaw = pruneAnalysisRaw(rawItem || card.raw || {});
        const raw = card.raw || {};
        const visibleMetrics = {
            views: vesFirstMetric(card.views, raw.views_or_plays, raw.views, raw.playCount, raw.viewCount, raw.videoViewCount),
            likes: vesFirstMetric(card.likes, raw.likes, raw.diggCount, raw.likeCount),
            comments: vesFirstMetric(card.comments, raw.comments, raw.commentCount, raw.comment_count),
            shares: vesFirstMetric(card.shares, raw.shares, raw.shareCount, raw.share_count),
            saves: vesFirstMetric(card.saves, raw.collectCount, raw.saveCount, raw.saves_or_collects, raw.saves, raw.bookmarkCount),
            duration: card.duration,
            hasPrimaryMetric:card.hasPrimaryMetric,
            hasLikeMetric:card.hasLikeMetric
        };
        return {
            platform,
            item_id:firstNonEmpty(card.raw?.id, card.raw?.videoId, card.raw?.postId, card.raw?.shortCode, card.url, ''),
            source_url:card.url,
            source_platform:platform,
            title:card.title,
            url:card.url,
            author:card.author,
            published_at:card.date,
            caption_or_text:card.text,
            transcript:card.transcript,
            visible_metrics: visibleMetrics,
            metrics: visibleMetrics,
            relevance:{ score:card.relevanceScore || 0, class:card.relevanceClass || '', label:card.relevanceLabel || '', explanation:card.relevanceExplanation || '' },
            raw_preview_payload: previewRaw,
            raw: previewRaw
        };
    };

    const refreshUsageSummary = async (widget) => {
        const box = widget?.querySelector?.('.ves-account-summary[data-dynamic="1"]') || document.querySelector('.ves-account-summary[data-dynamic="1"]');
        const form = widget?.querySelector?.('.ves-form');
        if (!box || !form) return;
        try {
            const payload = await ajaxUsageSummary(form);
            if (payload && payload.html) box.outerHTML = payload.html;
        } catch (err) {
            VES_WARN('usage summary refresh failed', err);
        }
    };

    const pollTrendRun = async (widget, bundleId) => {
        const state = widgetState.get(widget);
        const form = state.form;
        // v0.9.9.3: scope buttons + status to active page so multi-form widgets work.
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const abortBtn = activePage.querySelector('.ves-abort') || widget.querySelector('.ves-abort');
        const startBtn = activePage.querySelector('.ves-start') || widget.querySelector('.ves-start');
        state.currentRunId = bundleId;
        state.currentRunType = 'trend';
        if (abortBtn) abortBtn.style.display = 'inline-flex';
        const started = Date.now();
        for (let i = 1; i <= 60; i++) {
            const elapsed = Math.round((Date.now() - started) / 1000);
            setStatus(widget, `<strong>Recopilando tendencias…</strong> <span style="color:var(--ves-muted);margin-left:6px">${elapsed}s</span>`, 'info', true);
            try {
                const data = await ajaxTrendPoll(form, bundleId);
                if (data.status === 'RUNNING') {
                    const progress = data.progress || { done:0, total:4 };
                    const labels = Array.isArray(data.sources) ? data.sources.map(s=>`${s.label||''}: ${s.status||''}`).slice(0,4).join(' · ') : '';
                    setStatus(widget, `<strong>Recopilando tendencias…</strong> ${progress.done}/${progress.total} fuentes listas.${labels ? `<div style="margin-top:6px;font-size:11.5px;opacity:.85">${escapeHtml(labels)}</div>` : ''}`, 'info', true);
                } else if (data.status === 'SUCCEEDED') {
                    state.currentRunId = null;
                    state.currentRunType = 'standard';
                    if (abortBtn) abortBtn.style.display = 'none';
                    if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
                    const confidence = String(data?.confidence_mode || data?.report?.confidence_mode || '');
                    const memoryStatus = String(data?.memory_status || data?.report?.memory_status || '');
                    const statusLabel = memoryStatus === 'corrupted_legacy' ? 'Necesita reparación de evidencia' : (confidence === 'diagnostic_fallback' ? 'Solo diagnóstico de respaldo' : (confidence && confidence !== 'validated' ? 'Análisis completado con baja confianza' : 'Análisis completado'));
                    setStatus(widget, `✅ <strong>${escapeHtml(statusLabel)}</strong>.`, confidence === 'validated' ? 'success' : 'warning');
                    renderTrendResults(widget, data);
                    return;
                } else if (['RETRYABLE_DIAGNOSTIC','NO_EVIDENCE'].includes(String(data.status || ''))) {
                    state.currentRunId = null;
                    state.currentRunType = 'standard';
                    if (abortBtn) abortBtn.style.display = 'none';
                    if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
                    setStatus(widget, vesErrorStatusHtml('Trend Finder Lite', data, widget), 'warning');
                    renderTrendResults(widget, data);
                    return;
                } else if (['FAILED','TIMED-OUT','TIMEOUT','ABORTED'].includes(data.status)) {
                    const summary = safeObject(data.source_summary || data.evidence_coverage_summary);
                    const lifecycle = String(data.user_visible_lifecycle_status || data.run_lifecycle_status || '').toLowerCase();
                    const usableRows = Number(summary.usable_rows || summary.usable_records || data.usable_rows || 0) || 0;
                    const adjacentRows = Number(summary.adjacent_evidence || summary.adjacent_matches || data.adjacent_matches || 0) || 0;
                    const providersSucceeded = Number(summary.providers_succeeded || data.providers_succeeded || 0) || 0;
                    const recoverableLifecycle = ['partial','needs_validation','completed_low_confidence','completed_with_limitations','fallback_completed','completed'].includes(lifecycle);
                    if (data.status !== 'ABORTED' && (recoverableLifecycle || usableRows > 0 || adjacentRows > 0 || providersSucceeded > 0)) {
                        state.currentRunId = null;
                        state.currentRunType = 'standard';
                        if (abortBtn) abortBtn.style.display = 'none';
                        if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
                        data.status = 'SUCCEEDED';
                        data.run_status = data.run_status || 'partial';
                        data.user_visible_lifecycle_status = lifecycle || 'completed_with_limitations';
                        setStatus(widget, '✅ <strong>Ejecución completada con evidencia limitada.</strong> Algunas fuentes devolvieron datos, pero la señal no es suficiente para recomendar campaña sin validación.', 'warning');
                        renderTrendResults(widget, data);
                        return;
                    }
                    state.currentRunId = null;
                    state.currentRunType = 'standard';
                    if (abortBtn) abortBtn.style.display = 'none';
                    if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
                    setStatus(widget, `⚠️ <strong>No se pudo completar Trend Finder.</strong><div style="margin-top:6px;font-size:12px;opacity:.9">Fuentes correctas: ${escapeHtml(String(providersSucceeded))} · útiles: ${escapeHtml(String(usableRows))} · adyacentes: ${escapeHtml(String(adjacentRows))}. Revisa el acceso de la fuente, reduce filtros o ejecuta solo social/búsqueda.</div>`, 'error');
                    return;
                }
            } catch (err) {
                VES_WARN('trend poll failed', err);
                finishPollingAfterError(widget, state, abortBtn, startBtn, err, 'Error al consultar Trend Finder');
                return;
            }
            await new Promise(r => setTimeout(r, i > 120 ? 5000 : 2500));
        }
        state.currentRunId = null;
        state.currentRunType = 'standard';
        if (abortBtn) abortBtn.style.display = 'none';
        if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
        setStatus(widget, 'El análisis sigue en curso. Puedes volver a intentarlo más tarde.', 'info');
    };


    const renderBrandAuditTopCards = (title, items, type = 'insight', bundleId = '', brand = '') => {
        const list = Array.isArray(items) ? items.slice(0, 3) : [];
        if (!list.length) return '';
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">${escapeHtml(title)}</div><div class="ves-brand-opportunity-grid compact">${list.map((item, index) => {
            const cardTitle = item.title || item.name || `${type} ${index + 1}`;
            const summary = item.summary || item.description || item.business_implication || item.recommended_action || '';
            const confidence = item.confidence || item.opportunity_score || item.priority_score || item.priority || '';
            const numericId = item.id ? String(item.id) : '';
            const objectAttr = numericId ? (type === 'opportunity' ? ` data-opportunity-id="${escapeHtml(numericId)}"` : ` data-insight-id="${escapeHtml(numericId)}"`) : '';
            const validation = item.evidence_validation_status || item.meta?.evidence_validation_status || '';
            const unsupported = item.unsupported_by_evidence || validation === 'unsupported';
            const question = type === 'opportunity' ? `How should we act on this opportunity: ${cardTitle}?` : `What does this insight mean and what should we do next: ${cardTitle}?`;
            const statusBtns = numericId && type === 'insight'
                ? `<div class="ves-opportunity-actions"><button type="button" class="ves-mini-btn secondary ves-insight-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-insight-id="${escapeHtml(numericId)}" data-status="saved">Save</button><button type="button" class="ves-mini-btn secondary ves-insight-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-insight-id="${escapeHtml(numericId)}" data-status="accepted">Accept</button><button type="button" class="ves-mini-btn danger ves-insight-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-insight-id="${escapeHtml(numericId)}" data-status="rejected">Rechazar</button></div>`
                : '';
            return `<div class="ves-brand-opportunity-card compact ${unsupported ? 'is-unsupported' : ''}"${objectAttr}><div class="ves-opportunity-card-head"><strong>${escapeHtml(cardTitle)}</strong>${confidence !== '' ? `<span class="ves-pill">${formatConfidence(confidence)}</span>` : ''}</div>${validation ? `<div class="ves-evidence-validation ${escapeHtml(validation)}">Evidence: ${escapeHtml(validation)}</div>` : ''}${unsupported ? `<div class="ves-hint warning">Hypothesis: no valid current-run evidence_ref supports this item.</div>` : ''}${summary ? `<p>${escapeHtml(summary)}</p>` : ''}<button type="button" class="ves-mini-btn" data-ves-assistant-question="${escapeHtml(question)}" data-bundle-id="${escapeHtml(bundleId)}" data-ves-brand="${escapeHtml(brand)}"${objectAttr}>Preguntar al asistente</button>${statusBtns}<div class="ves-assistant-cta-panel"><div class="ves-assistant-response-host" aria-live="polite"></div></div></div>`;
        }).join('')}</div></div>`;
    };

    const renderBrandAuditOpportunities = (opportunities, bundleId = '', brand = '') => {
        const list = Array.isArray(opportunities) ? opportunities : [];
        if (!list.length) return '<div class="ves-item-panel"><div class="ves-hint">No structured opportunities were generated yet.</div></div>';
        const asList = (value) => Array.isArray(value) ? value.filter(Boolean) : (value ? [value] : []);
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">Opportunity Board seed</div><div class="ves-brand-opportunity-grid">${list.map((item, index) => {
            const title = item.title || item.name || `Opportunity ${index + 1}`;
            const description = item.description || item.summary || item.business_implication || item.why_it_matters || '';
            const action = item.recommended_action || item.suggested_next_step || asList(item.recommended_next_steps).join(' · ') || '';
            const evidence = asList(item.evidence_refs || item.evidence || item.evidence_used || item.evidence_ids || []).slice(0, 6);
            const oppId = item.id ? String(item.id) : '';
            const validation = item.evidence_validation_status || item.meta?.evidence_validation_status || (evidence.length ? 'supported' : 'unsupported');
            const unsupported = item.unsupported_by_evidence || validation === 'unsupported';
            const status = String(item.status || 'candidate');
            const canGenerateBrief = oppId && ['shortlisted','approved'].includes(status);
            const question = `How should we act on this opportunity: ${title}?`;
            return `<div class="ves-brand-opportunity-card ${unsupported ? 'is-unsupported' : ''}" data-opportunity-id="${escapeHtml(oppId)}">
                <div class="ves-opportunity-card-head"><strong>${escapeHtml(title)}</strong><span class="ves-pill">${escapeHtml(status)}</span></div>
                <div class="ves-opportunity-score-row"><span>Tipo: ${escapeHtml(item.opportunity_type || item.type || 'content')}</span><span>Impacto: ${escapeHtml(String(item.impact_score || item.impact || '—'))}</span><span>Esfuerzo: ${escapeHtml(String(item.effort_score || item.effort || '—'))}</span><span>Prioridad: ${escapeHtml(String(item.priority_score || item.priority || '—'))}</span><span>Confianza: ${formatConfidence(item.confidence || '')}</span></div>
                <div class="ves-evidence-validation ${escapeHtml(validation)}">Validación de evidencia: ${escapeHtml(validation)}</div>
                ${unsupported ? `<div class="ves-hint warning">Hipótesis: no hay evidence_ref válido de este run que respalde esta oportunidad.</div>` : ''}
                ${description ? `<p>${escapeHtml(description)}</p>` : ''}
                ${item.campaign_angle || item.content_or_campaign_angle ? `<p><strong>Ángulo:</strong> ${escapeHtml(item.campaign_angle || item.content_or_campaign_angle)}</p>` : ''}
                ${action ? `<p><strong>Acción recomendada:</strong> ${escapeHtml(action)}</p>` : ''}
                ${evidence.length ? `<div class="ves-evidence-ref-row">${evidence.map(e => `<code>${escapeHtml(String(e))}</code>`).join('')}</div>` : '<div class="ves-hint">Sin referencias de evidencia directa; trátalo como hipótesis hasta validarlo.</div>'}
                <div class="ves-opportunity-actions">
                    <button type="button" class="ves-mini-btn" data-ves-assistant-question="${escapeHtml(question)}" data-bundle-id="${escapeHtml(bundleId)}" data-ves-brand="${escapeHtml(brand)}" data-opportunity-id="${escapeHtml(oppId)}">Preguntar al asistente</button>
                    ${oppId ? `<button type="button" class="ves-mini-btn secondary ves-opportunity-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-opportunity-id="${escapeHtml(oppId)}" data-status="shortlisted">Preseleccionar</button><button type="button" class="ves-mini-btn secondary ves-opportunity-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-opportunity-id="${escapeHtml(oppId)}" data-status="approved">Aprobar</button><button type="button" class="ves-mini-btn danger ves-opportunity-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-opportunity-id="${escapeHtml(oppId)}" data-status="rejected">Rechazar</button><button type="button" class="ves-mini-btn secondary ves-opportunity-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-opportunity-id="${escapeHtml(oppId)}" data-status="archived">Archivar</button>` : ''}
                    ${oppId ? (canGenerateBrief ? `<button type="button" class="ves-mini-btn secondary ves-generate-brief-btn" data-bundle-id="${escapeHtml(bundleId)}" data-opportunity-id="${escapeHtml(oppId)}" data-brief-type="campaign">Generar brief</button>` : `<button type="button" class="ves-mini-btn secondary" disabled>Preseleccionar first to generate brief.</button>`) : `<button type="button" class="ves-mini-btn secondary" disabled>La generación de brief viene después.</button>`}
                </div>
                <div class="ves-assistant-cta-panel"><div class="ves-assistant-response-host" aria-live="polite"></div></div>
            </div>`;
        }).join('')}</div></div>`;
    };


    const loadOpportunityBoard = async (wrap) => {
        const widget = wrap.closest('.ves-wrap');
        const state = widgetState.get(widget);
        const form = state?.form || widget?.querySelector('form');
        const bundleId = wrap.dataset.bundleId || '';
        const panel = wrap.querySelector('[data-brand-result-panel="opportunities"]');
        if (!form || !bundleId || !panel) return;
        const brand = wrap.querySelector('.ves-brand-result-header h3')?.textContent || '';
        panel.innerHTML = '<div class="ves-item-panel"><div class="ves-spinner"></div><div class="ves-hint">Loading opportunities…</div></div>';
        try {
            const data = await ajaxOpportunityRecordsList(form, bundleId, { perPage: 50 });
            panel.innerHTML = renderBrandAuditOpportunities(data.items || [], bundleId, brand);
            panel.dataset.loadedFromEndpoint = '1';
        } catch (err) {
            panel.insertAdjacentHTML('afterbegin', `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Could not load opportunity records.'))}</div>`);
        }
    };

    const renderInsightRecordsPanel = (insights, bundleId = '', brand = '') => {
        const list = Array.isArray(insights) ? insights : [];
        if (!list.length) return '<div class="ves-item-panel"><div class="ves-hint">No stored insight records are available for this run.</div></div>';
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">Insight records</div><div class="ves-brand-opportunity-grid compact">${list.map((item, index) => {
            const id = item.id ? String(item.id) : '';
            const title = item.title || `Insight ${index + 1}`;
            const validation = item.evidence_validation_status || item.meta?.evidence_validation_status || 'unknown';
            const unsupported = item.unsupported_by_evidence || validation === 'unsupported';
            const refs = Array.isArray(item.evidence_refs) ? item.evidence_refs.slice(0, 6) : [];
            const q = `What does this insight mean and what should we do next: ${title}?`;
            return `<article class="ves-brand-opportunity-card compact ${unsupported ? 'is-unsupported' : ''}" data-insight-id="${escapeHtml(id)}"><div class="ves-opportunity-card-head"><strong>${escapeHtml(title)}</strong><span class="ves-pill">${escapeHtml(item.status || 'draft')}</span></div><div class="ves-opportunity-score-row"><span>Tipo: ${escapeHtml(item.insight_type || 'content')}</span><span>Impacto: ${escapeHtml(item.business_impact || 'medium')}</span><span>Urgency: ${escapeHtml(item.urgency || 'medium')}</span><span>Confianza: ${formatConfidence(item.confidence || '')}</span></div><div class="ves-evidence-validation ${escapeHtml(validation)}">Validación de evidencia: ${escapeHtml(validation)}</div>${unsupported ? '<div class="ves-hint warning">Hypothesis: not supported by valid current-run evidence refs.</div>' : ''}${item.summary ? `<p>${escapeHtml(item.summary)}</p>` : ''}${refs.length ? `<div class="ves-evidence-ref-row">${refs.map(r => `<code>${escapeHtml(String(r))}</code>`).join('')}</div>` : ''}<div class="ves-opportunity-actions"><button type="button" class="ves-mini-btn" data-ves-assistant-question="${escapeHtml(q)}" data-bundle-id="${escapeHtml(bundleId)}" data-ves-brand="${escapeHtml(brand)}" data-insight-id="${escapeHtml(id)}">Preguntar al asistente</button>${id ? `<button type="button" class="ves-mini-btn secondary ves-insight-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-insight-id="${escapeHtml(id)}" data-status="saved">Save</button><button type="button" class="ves-mini-btn secondary ves-insight-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-insight-id="${escapeHtml(id)}" data-status="accepted">Accept</button><button type="button" class="ves-mini-btn danger ves-insight-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-insight-id="${escapeHtml(id)}" data-status="rejected">Rechazar</button>` : ''}</div><div class="ves-assistant-cta-panel"><div class="ves-assistant-response-host" aria-live="polite"></div></div></article>`;
        }).join('')}</div></div>`;
    };

    const loadInsightBoard = async (wrap) => {
        const widget = wrap.closest('.ves-wrap');
        const state = widgetState.get(widget);
        const form = state?.form || widget?.querySelector('form');
        const bundleId = wrap.dataset.bundleId || '';
        const host = wrap.querySelector('[data-ves-insight-records-host]');
        if (!form || !bundleId || !host) return;
        host.innerHTML = '<div class="ves-item-panel"><div class="ves-spinner"></div><div class="ves-hint">Loading insight records…</div></div>';
        try {
            const data = await ajaxInsightRecordsList(form, bundleId, { perPage: 12 });
            host.innerHTML = renderInsightRecordsPanel(data.items || [], bundleId, wrap.querySelector('.ves-brand-result-header h3')?.textContent || '');
            host.dataset.loadedFromEndpoint = '1';
        } catch (err) {
            host.innerHTML = `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Could not load insight records.'))}</div>`;
        }
    };

    const renderBriefRecordsPanel = (briefs, bundleId = '') => {
        const list = Array.isArray(briefs) ? briefs : [];
        if (!list.length) return '<div class="ves-item-panel"><div class="ves-hint">No briefs generated for this audit yet. Preseleccionar or approve an opportunity, then generate a brief.</div></div>';
        return `<div class="ves-item-panel ves-brief-records-panel"><div class="ves-item-panel-title">Generated briefs</div><div class="ves-brand-opportunity-grid">${list.map((brief) => {
            const id = String(brief.id || '');
            const refs = Array.isArray(brief.evidence_refs) ? brief.evidence_refs.slice(0, 8) : [];
            const fallback = String(brief.created_by || '') === 'local_fallback';
            const askQ = `How should we use this brief: ${brief.title || 'Brief'}?`;
            return `<article class="ves-brand-opportunity-card ves-brief-card ${fallback ? 'is-fallback' : ''}" data-brief-id="${escapeHtml(id)}"><div class="ves-opportunity-card-head"><strong>${escapeHtml(brief.title || 'Brief')}</strong><span class="ves-pill">${escapeHtml(brief.status || 'draft')}</span></div><div class="ves-opportunity-score-row"><span>Tipo: ${escapeHtml(brief.brief_type || 'campaign')}</span><span>Source opportunity: ${escapeHtml(String(brief.opportunity_id || '—'))}</span><span>Evidence: ${escapeHtml(brief.evidence_validation_status || 'unknown')}</span><span>Created by: ${escapeHtml(brief.created_by || 'unknown')}</span></div>${fallback ? '<div class="ves-hint warning">Local fallback brief. Review evidence and assumptions before execution.</div>' : ''}${brief.objective ? `<p><strong>Objective:</strong> ${escapeHtml(brief.objective)}</p>` : ''}${brief.key_insight ? `<p><strong>Key insight:</strong> ${escapeHtml(brief.key_insight)}</p>` : ''}${Array.isArray(brief.message_angles) && brief.message_angles.length ? `<h4>Message angles</h4><ul>${brief.message_angles.slice(0,5).map(x => `<li>${escapeHtml(String(x))}</li>`).join('')}</ul>` : ''}${Array.isArray(brief.hooks) && brief.hooks.length ? `<h4>Hooks</h4><ul>${brief.hooks.slice(0,5).map(x => `<li>${escapeHtml(String(x))}</li>`).join('')}</ul>` : ''}${Array.isArray(brief.next_steps) && brief.next_steps.length ? `<h4>Next steps</h4><ul>${brief.next_steps.slice(0,5).map(x => `<li>${escapeHtml(String(x))}</li>`).join('')}</ul>` : ''}${refs.length ? `<div class="ves-evidence-ref-row">${refs.map(r => `<code>${escapeHtml(String(r))}</code>`).join('')}</div>` : '<div class="ves-hint warning">No valid evidence refs attached.</div>'}<div class="ves-opportunity-actions"><button type="button" class="ves-mini-btn" data-ves-assistant-question="${escapeHtml(askQ)}" data-bundle-id="${escapeHtml(bundleId)}" data-brief-id="${escapeHtml(id)}">Preguntar al asistente</button><button type="button" class="ves-mini-btn secondary ves-copy-brief-btn">Copy brief</button>${id ? `<button type="button" class="ves-mini-btn secondary ves-brief-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-brief-id="${escapeHtml(id)}" data-status="reviewed">Mark reviewed</button><button type="button" class="ves-mini-btn secondary ves-brief-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-brief-id="${escapeHtml(id)}" data-status="approved">Aprobar</button><button type="button" class="ves-mini-btn secondary ves-brief-status-btn" data-bundle-id="${escapeHtml(bundleId)}" data-brief-id="${escapeHtml(id)}" data-status="archived">Archivar</button>` : ''}<button type="button" class="ves-mini-btn secondary" disabled>Export comes next.</button></div><div class="ves-assistant-cta-panel"><div class="ves-assistant-response-host" aria-live="polite"></div></div></article>`;
        }).join('')}</div></div>`;
    };

    const loadBriefRecords = async (wrap) => {
        const widget = wrap.closest('.ves-wrap');
        const state = widgetState.get(widget);
        const form = state?.form || widget?.querySelector('form');
        const bundleId = wrap.dataset.bundleId || '';
        const host = wrap.querySelector('[data-ves-brief-records-host]');
        if (!form || !bundleId || !host) return;
        host.innerHTML = '<div class="ves-item-panel"><div class="ves-spinner"></div><div class="ves-hint">Loading briefs…</div></div>';
        try {
            const data = await ajaxBriefRecordsList(form, bundleId, { perPage: 50 });
            host.innerHTML = renderBriefRecordsPanel(data.items || [], bundleId);
        } catch (err) {
            host.innerHTML = `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Could not load briefs.'))}</div>`;
        }
    };

    const renderWorkflowEventsPanel = (events) => {
        const list = Array.isArray(events) ? events : [];
        if (!list.length) return '<div class="ves-item-panel"><div class="ves-hint">No workflow activity recorded yet.</div></div>';
        return `<div class="ves-item-panel ves-workflow-events-panel"><div class="ves-item-panel-title">Actividad</div>${list.slice(0,40).map(ev => `<div class="ves-workflow-event"><strong>${escapeHtml(String(ev.event_type || '').replace(/_/g, ' '))}</strong><span>${escapeHtml(ev.created_at || '')}</span><p>${escapeHtml(ev.note || `${ev.entity_type || ''} ${ev.entity_id || ''}`)}</p><small>${escapeHtml(ev.actor_role || '')}${ev.source ? ' · '+escapeHtml(ev.source) : ''}</small>${ev.previous_status || ev.new_status ? `<small>${escapeHtml(ev.previous_status || '—')} → ${escapeHtml(ev.new_status || '—')}</small>` : ''}</div>`).join('')}</div>`;
    };

    const loadWorkflowEvents = async (wrap) => {
        const widget = wrap.closest('.ves-wrap');
        const state = widgetState.get(widget);
        const form = state?.form || widget?.querySelector('form');
        const bundleId = wrap.dataset.bundleId || '';
        const host = wrap.querySelector('[data-ves-workflow-events-host]');
        if (!form || !bundleId || !host) return;
        try {
            const data = await ajaxWorkflowEventsList(form, bundleId, { limit: 30 });
            host.innerHTML = renderWorkflowEventsPanel(data.events || []);
        } catch (err) {
            host.innerHTML = `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Could not load workflow activity.'))}</div>`;
        }
    };


    const renderBrandAuditListPanel = (title, groups) => {
        const parts = [];
        Object.entries(groups || {}).forEach(([label, values]) => {
            const arr = Array.isArray(values) ? values.filter(Boolean).slice(0, 6) : [];
            if (!arr.length) return;
            parts.push(`<div class="ves-brand-readout-group"><strong>${escapeHtml(label)}</strong><ul>${arr.map(v => `<li>${escapeHtml(String(v))}</li>`).join('')}</ul></div>`);
        });
        if (!parts.length) return '';
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">${escapeHtml(title)}</div><div class="ves-brand-readout-grid">${parts.join('')}</div></div>`;
    };

    const renderBrandAuditStrategicReadouts = (structured) => {
        if (!structured || typeof structured !== 'object') return '';
        return [
            renderBrandAuditListPanel('Brand positioning readout', {
                'What the brand says': structured?.brand_positioning_readout?.what_brand_says || [],
                'What the market sees': structured?.brand_positioning_readout?.what_market_sees || [],
                'Positioning gaps': structured?.brand_positioning_readout?.positioning_gap || []
            }),
            renderBrandAuditListPanel('Search intent readout', {
                'Main intents': structured?.search_intent_readout?.main_intents || [],
                'Decision-stage needs': structured?.search_intent_readout?.decision_stage_needs || [],
                'Unanswered questions': structured?.search_intent_readout?.unanswered_questions || [],
                'SEO/content opportunities': structured?.search_intent_readout?.seo_content_opportunities || []
            }),
            renderBrandAuditListPanel('Social media readout', {
                'Owned content patterns': structured?.social_readout?.owned_content_patterns || [],
                'Earned/content signals': structured?.social_readout?.earned_content_patterns || [],
                'Top attention assets': structured?.social_readout?.top_attention_assets || [],
                'Missing territories': structured?.social_readout?.weak_or_missing_territories || []
            }),
            renderBrandAuditListPanel('Review & trust readout', {
                'Positive proof': structured?.review_and_trust_readout?.positive_proof || [],
                'Friction points': structured?.review_and_trust_readout?.friction_points || [],
                'Trust gaps': structured?.review_and_trust_readout?.trust_gap || [],
                'Reassurance opportunities': structured?.review_and_trust_readout?.reassurance_opportunities || []
            }),
            renderBrandAuditListPanel('Recommended next actions', {
                'Actions': structured?.recommended_next_actions || [],
                'Limitations': structured?.limitations || []
            })
        ].filter(Boolean).join('');
    };
    const renderBrandAuditDeck = (deck) => {
        const slides = Array.isArray(deck) ? deck : [];
        if (!slides.length) return '';
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">Deck-ready outline</div><div class="ves-brand-deck">${slides.map((slide, idx) => `
            <article class="ves-brand-slide">
                <div class="ves-brand-slide-number">Slide ${escapeHtml(String(slide.slide_number || idx + 1))}</div>
                <h4>${escapeHtml(slide.title || 'Untitled slide')}</h4>
                <p>${escapeHtml(slide.main_message || '')}</p>
                ${Array.isArray(slide.evidence_points) && slide.evidence_points.length ? `<ul>${slide.evidence_points.slice(0,4).map(p => `<li>${escapeHtml(p)}</li>`).join('')}</ul>` : ''}
                ${slide.visual_suggestion ? `<div class="ves-hint">Visual: ${escapeHtml(slide.visual_suggestion)}</div>` : ''}
            </article>`).join('')}</div></div>`;
    };

    const brandStatusClass = (status, usability) => {
        const u = String(usability || '').toLowerCase();
        const s = String(status || '').toUpperCase();
        if (u === 'usable' || s === 'SUCCEEDED') return 'is-ok';
        if (u === 'failed' || ['FAILED','DISPATCH_ERROR','CONFIG_ERROR','TIMEOUT','TIMED-OUT','ABORTED'].includes(s)) return 'is-bad';
        return 'is-mid';
    };

    const brandAuditPublicStatus = (status, dispatchStatus, notes = '') => {
        const s = String(status || '').toUpperCase();
        const d = String(dispatchStatus || '').toLowerCase();
        const n = String(notes || '').toLowerCase();
        if (s === 'WAITING_RETRY' || d === 'waiting_retry') return 'Source pending retry';
        if (s === 'SKIPPED_INVALID_INPUT' || n.includes('input.keyword') || n.includes('skipped_invalid_input')) return 'Source skipped due configuration';
        if (s === 'DISPATCH_ERROR' || d.includes('dispatch')) return 'Source unavailable';
        if (s === 'UNKNOWN') return 'Source pending review';
        if (s === 'SUCCEEDED') return 'Source completed';
        if (s === 'PARTIAL') return 'Partial evidence';
        if (['FAILED','TIMEOUT','TIMED-OUT','ABORTED','CONFIG_ERROR'].includes(s)) return 'Source unavailable';
        return s ? String(s).replace(/_/g, ' ') : 'Source status';
    };

    const renderBrandAuditSourceStatus = (sources) => {
        if (!isAdminUI()) return '';
        const list = Array.isArray(sources) ? fuentes : [];
        if (!list.length) return '';
        const familyOrder = ['workspace','owned_web','search','public_web','trends','social','reviews_maps','competitors','other'];
        const grouped = {};
        list.forEach((source) => {
            const family = source.family || source.source_family || 'other';
            if (!grouped[family]) grouped[family] = [];
            grouped[family].push(source);
        });
        const familyBlocks = familyOrder.filter(f => grouped[f]?.length).concat(Object.keys(grouped).filter(f => !familyOrder.includes(f))).map((family) => `
            <div class="ves-brand-source-family">
                <div class="ves-brand-source-family-title">${escapeHtml(String(family).replace(/_/g, ' '))}</div>
                <div class="ves-brand-source-grid compact">
                    ${grouped[family].map((source) => {
                        const status = String(source.status || '').toUpperCase();
                        const usability = String(source.usability || source.analytical_usability_status || '').toLowerCase();
                        const cls = brandStatusClass(status, usability);
                        const items = source.item_count !== undefined ? `${escapeHtml(String(source.item_count))} items` : '';
                        const publicLabel = brandAuditPublicStatus(status, source.dispatch_status, source.notes);
                        return `<div class="ves-brand-source-card ${cls}">
                            <div class="ves-brand-source-card-head"><strong>${escapeHtml(source.label || source.source_key || 'Source')}</strong><span>${escapeHtml(publicLabel)}</span></div>
                            <div class="ves-brand-source-meta">${items}${source.mode ? ` · ${escapeHtml(source.mode)}` : ''}</div>
                            ${source.message ? `<p>${escapeHtml(String(source.message)).slice(0, 220)}</p>` : ''}
                            ${source.notes ? `<details class="ves-diagnostics-details"><summary>Admin diagnostic</summary><p>${escapeHtml(String(source.notes)).slice(0, 360)}</p></details>` : ''}
                        </div>`;
                    }).join('')}
                </div>
            </div>`).join('');
        return `<details class="ves-item-panel ves-diagnostics-details"><summary><strong>Diagnostics</strong> <span class="ves-meta">Source status dashboard</span></summary>${familyBlocks}</details>`;
    };

    const renderBrandAuditFamilySummary = (families) => {
        const entries = Object.entries(families || {});
        if (!entries.length) return '';
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">Source family summary</div><div class="ves-brand-metric-grid">${entries.map(([name,row]) => `
            <div class="ves-brand-metric-card"><strong>${escapeHtml(String(name).replace(/_/g, ' '))}</strong><span>${escapeHtml(String(row.usable || 0))}/${escapeHtml(String(row.total || 0))} usable</span><small>${escapeHtml(String(row.items || 0))} items · ${escapeHtml(String(row.partial || 0))} partial · ${escapeHtml(String(row.failed || 0))} failed</small></div>
        `).join('')}</div></div>`;
    };

    const renderBrandAuditSearchClusters = (clusters) => {
        const list = Array.isArray(clusters) ? clusters.slice(0, 8) : [];
        if (!list.length) return '';
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">Search intent clusters</div><div class="ves-brand-cluster-grid">${list.map(c => `
            <div class="ves-brand-cluster-card"><strong>${escapeHtml(c.label || c.key || 'Intent')}</strong><span>${escapeHtml(String(c.signals || 0))} signals</span>${Array.isArray(c.items) && c.items.length ? `<ul>${c.items.slice(0,3).map(i => `<li>${escapeHtml(i.title || i.text || i.source || '')}</li>`).join('')}</ul>` : ''}</div>
        `).join('')}</div></div>`;
    };

    const renderBrandAuditSocialCalidad = (social) => {
        const scores = Array.isArray(social?.quality_scores) ? social.quality_scores : [];
        const summary = social?.owned_vs_earned_summary || {};
        if (!scores.length && !Object.keys(summary).length) return '';
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">Social evidence quality</div>
            ${Object.keys(summary).length ? `<div class="ves-brand-metric-grid compact">
                ${Object.entries(summary).map(([k,v]) => `<div class="ves-brand-metric-card"><strong>${escapeHtml(k.replace(/_/g, ' '))}</strong><span>${escapeHtml(String(v || 0))}</span></div>`).join('')}
            </div>` : ''}
            ${scores.length ? `<div class="ves-brand-quality-bars">${scores.map(row => {
                const score = Number(row.quality_score || 0);
                return `<div class="ves-brand-quality-row"><div><strong>${escapeHtml(row.platform || 'platform')}</strong><small>${escapeHtml(row.confidence || 'limited')} · ${escapeHtml(String(row.items || 0))} items · ${escapeHtml(String(row.metric_coverage || 0))}% metrics</small></div><div class="ves-brand-quality-track"><span style="width:${Math.max(0, Math.min(100, score))}%"></span></div><em>${escapeHtml(String(score))}/100</em></div>`;
            }).join('')}</div>` : ''}
        </div>`;
    };

    const renderBrandAuditReviewSentiment = (reviews) => {
        const summary = reviews?.sentiment_summary || {};
        const themes = Array.isArray(reviews?.complaint_themes) ? reviews.complaint_themes : [];
        const visibility = Array.isArray(reviews?.review_visibility_signals) ? reviews.review_visibility_signals : [];
        if (!Object.keys(summary).length && !themes.length && !visibility.length) return '';
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">Review sentiment & trust evidence</div>
            <div class="ves-brand-metric-grid compact">
                ${['direct_review_count','places_count','positive_count','negative_count','neutral_or_unclear_count','confidence'].map(k => summary[k] !== undefined ? `<div class="ves-brand-metric-card"><strong>${escapeHtml(k.replace(/_/g, ' '))}</strong><span>${escapeHtml(String(summary[k]))}</span></div>` : '').join('')}
            </div>
            ${themes.length ? `<div class="ves-brand-mini-title">Complaint / trust themes</div><ul class="ves-brand-inline-list">${themes.slice(0,6).map(t => `<li>${escapeHtml(t.theme || '')} <span>${escapeHtml(String(t.count || 0))}</span></li>`).join('')}</ul>` : ''}
            ${visibility.length ? `<div class="ves-brand-mini-title">Review visibility from search</div><ul>${visibility.slice(0,5).map(v => `<li>${escapeHtml(v.title || v.source || '')}</li>`).join('')}</ul>` : ''}
            ${reviews?.guardrail_note ? `<div class="ves-hint">${escapeHtml(reviews.guardrail_note)}</div>` : ''}
        </div>`;
    };

    const renderBrandAuditCompetitorMatrix = (context, structuredMatrix) => {
        const matrix = Array.isArray(context?.gap_matrix) && context.gap_matrix.length ? context.gap_matrix : (Array.isArray(structuredMatrix) ? structuredMatrix : []);
        if (!matrix.length && !Array.isArray(context?.competitor_search_items)) return '';
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">Competitor gap matrix</div>
            ${matrix.length ? `<div class="ves-brand-competitor-grid">${matrix.map(row => `
                <div class="ves-brand-competitor-card"><strong>${escapeHtml(row.competitor || 'Competitor')}</strong><p>${escapeHtml(row.what_they_appear_to_own || '')}</p><div class="ves-hint">${escapeHtml(row.brand_opportunity || '')}</div>${row.confidence ? `<small>Confianza: ${escapeHtml(row.confidence)}</small>` : ''}</div>
            `).join('')}</div>` : '<div class="ves-hint">No competitor gap matrix yet. Add competitor names or URLs to strengthen this layer.</div>'}
        </div>`;
    };

    const renderBrandAuditEvidenceViewer = (evidence, structured) => {
        if (!evidence || typeof evidence !== 'object') return '';
        return [
            renderBrandAuditFamilySummary(evidence?.source_quality?.family_summary || evidence?.source_quality?.detail?.families || {}),
            renderBrandAuditSearchClusters(evidence?.search_intent?.intent_clusters || []),
            renderBrandAuditSocialCalidad(evidence?.social_owned || {}),
            renderBrandAuditReviewSentiment(evidence?.reviews_maps || {}),
            renderBrandAuditCompetitorMatrix(evidence?.competitor_context || {}, structured?.competitor_gap_matrix || [])
        ].filter(Boolean).join('');
    };

    const renderBrandAuditEvidenceItems = (title, items) => {
        const list = Array.isArray(items) ? items.filter(Boolean).slice(0, 6) : [];
        if (!list.length) return '';
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">${escapeHtml(title)}</div><div class="ves-brand-evidence-list">${list.map((item) => {
            const t = item?.title || item?.text || 'Evidence item';
            const txt = item?.text || '';
            const badge = item?.badge || '';
            const url = item?.url || '';
            const metrics = item?.metrics && typeof item.metrics === 'object'
                ? Object.entries(item.metrics).filter(([k,v]) => Number(v) > 0).slice(0,4).map(([k,v]) => `${k.replace(/_/g, ' ')}: ${Number(v).toLocaleString()}`)
                : [];
            return `<article class="ves-brand-evidence-item">${badge ? `<span>${escapeHtml(badge)}</span>` : ''}<strong>${escapeHtml(String(t))}</strong>${metrics.length ? `<div class="ves-brand-evidence-metrics">${metrics.map(m => `<em>${escapeHtml(m)}</em>`).join('')}</div>` : ''}${txt ? `<p>${escapeHtml(String(txt)).slice(0, 420)}</p>` : ''}${url ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener">Open source</a>` : ''}</article>`;
        }).join('')}</div></div>`;
    };

    const renderBrandAuditEvidenceSections = (evidence) => {
        if (!evidence || typeof evidence !== 'object') return '';
        return [
            renderBrandAuditSourceStatus(Object.values(evidence?.source_quality?.status_cards || evidence?.source_quality?.coverage || {})),
            renderBrandAuditEvidenceItems('Website evidence', evidence?.owned_web?.pages || []),
            renderBrandAuditEvidenceItems('Search intent evidence', evidence?.search_intent?.google_search_items || []),
            renderBrandAuditEvidenceItems('News / public coverage evidence', evidence?.public_web_news?.news_items || []),
            renderBrandAuditEvidenceItems('Google Trends evidence', evidence?.trend_interest?.trend_items || []),
            renderBrandAuditEvidenceItems('Social media evidence', evidence?.social_owned?.posts || []),
            renderBrandAuditEvidenceItems('Top social posts / videos', evidence?.social_owned?.top_posts || []),
            renderBrandAuditEvidenceItems('Google Maps / review evidence', evidence?.reviews_maps?.places || []),
            renderBrandAuditEvidenceItems('Review snippets', evidence?.reviews_maps?.review_items || [])
        ].filter(Boolean).join('');
    };
    const renderBrandAuditIntelligenceRecords = (data) => {
        const layer = safeObject(data?.intelligence_records);
        const metrics = safeArray(safeObject(layer?.metrics).records).length ? safeArray(safeObject(layer?.metrics).records) : safeArray(data?.metric_snapshots);
        const patterns = safeArray(safeObject(layer?.patterns).records).length ? safeArray(safeObject(layer?.patterns).records) : safeArray(data?.pattern_candidates);
        const insights = safeArray(safeObject(layer?.insights).records);
        const opportunities = safeArray(safeObject(layer?.opportunities).records);
        if (!metrics.length && !patterns.length && !insights.length && !opportunities.length) return '';
        const metricCards = safeArray(metrics).slice(0, 8).map(m => `<div class="ves-brand-metric-card"><strong>${escapeHtml(String(m.metric_key || '').replace(/_/g, ' '))}</strong><span>${escapeHtml(String(m.metric_value ?? m.value ?? 0))}</span><small>${escapeHtml(m.platform || 'all')} · ${escapeHtml(m.metric_unit || '')}</small></div>`).join('');
        const patternCards = safeArray(patterns).slice(0, 4).map(pat => `<article class="ves-brand-pattern-card"><div><strong>${escapeHtml(pat.title || pat.pattern_type || 'Pattern')}</strong><span>${escapeHtml(String(pat.confidence || ''))}</span></div>${pat.description ? `<p>${escapeHtml(pat.description)}</p>` : ''}${safeArray(pat.evidence_refs).length ? `<div class="ves-evidence-ref-row">${safeArray(pat.evidence_refs).slice(0,5).map(ref => `<code>${escapeHtml(String(ref))}</code>`).join('')}</div>` : ''}</article>`).join('');
        if (!isAdminUI()) return '';
        return `<div class="ves-item-panel ves-intelligence-layer-panel"><div class="ves-item-panel-title">Intelligence layer diagnostics</div><p class="ves-hint">Admin view: normalized evidence, metric snapshots, pattern candidates, and persisted insight/opportunity records.</p>
            <div class="ves-brand-evidence-summary">
                <span><strong>Metrics</strong><em>${escapeHtml(String(metrics.length))}</em></span>
                <span><strong>Patterns</strong><em>${escapeHtml(String(patterns.length))}</em></span>
                <span><strong>Insights</strong><em>${escapeHtml(String(insights.length))}</em></span>
                <span><strong>Opportunities</strong><em>${escapeHtml(String(opportunities.length))}</em></span>
            </div>
            ${metricCards ? `<div class="ves-brand-metric-grid compact">${metricCards}</div>` : ''}
            ${patternCards ? `<div class="ves-brand-pattern-grid">${patternCards}</div>` : ''}
        </div>`;
    };

    const renderBrandAuditLimitations = (structured) => {
        const limitations = Array.isArray(structured?.limitations) ? structured.limitations.filter(Boolean) : [];
        if (!limitations.length) return '<div class="ves-hint">No explicit limitations returned.</div>';
        return `<ul class="ves-clean-list">${limitations.map(item => `<li>${escapeHtml(String(item))}</li>`).join('')}</ul>`;
    };

    const renderBrandAuditAssistantCtas = (data, structured) => {
        const brand = data?.requested?.brand_name || '';
        const suggestions = [
            'What is the strongest opportunity from this audit?',
            'Which competitor gap should we attack first?',
            'Turn the top insight into a campaign brief.',
            'What data should we collect next to improve confidence?'
        ];
        return `<div class="ves-item-panel ves-assistant-cta-panel">
            <div class="ves-item-panel-title">${escapeHtml('Ask ' + assistantName())}</div>
            <p class="ves-hint">Use this run, normalized evidence, limitations, and saved workspace memory as context. Choose a starter question or ask your own follow-up below.</p>
            <div class="ves-brand-question-grid">${suggestions.map(q => `<button type="button" class="ves-mini-btn" data-ves-assistant-question="${escapeHtml(q)}" data-ves-brand="${escapeHtml(brand)}" data-bundle-id="${escapeHtml(data?.bundle_id || '')}">${escapeHtml(q)}</button>`).join('')}</div>
            <div class="ves-assistant-followup-row"><textarea class="ves-input ves-assistant-followup" rows="3" placeholder="Ask a follow-up about this audit, opportunity, evidence or competitor gap…"></textarea><button type="button" class="ves-btn ves-btn-primary ves-assistant-submit" data-bundle-id="${escapeHtml(data?.bundle_id || '')}" data-ves-brand="${escapeHtml(brand)}">Preguntar al asistente</button></div>
            <div class="ves-assistant-response-host" aria-live="polite"></div>
        </div>`;
    };

    const renderBrandAuditEvidenceSummary = (data) => {
        const summary = data?.evidence_summary || {};
        const byPlatform = summary.by_platform || {};
        const byType = summary.by_source_type || {};
        const platformRows = Object.entries(byPlatform).map(([k,v]) => `<span><strong>${escapeHtml(k)}</strong><em>${escapeHtml(String(v))}</em></span>`).join('');
        const typeRows = Object.entries(byType).map(([k,v]) => `<span><strong>${escapeHtml(k.replace(/_/g, ' '))}</strong><em>${escapeHtml(String(v))}</em></span>`).join('');
        if (!summary.stored && !platformRows && !typeRows) return '';
        return `<div class="ves-item-panel"><div class="ves-item-panel-title">Normalized Evidence Store</div>
            <div class="ves-brand-evidence-summary">
                <span><strong>Total saved</strong><em>${escapeHtml(String(summary.stored || 0))}</em></span>
                <span><strong>Average quality</strong><em>${escapeHtml(String(summary.average_quality_score || 0))}</em></span>
                ${platformRows}${typeRows}
            </div>
        </div>`;
    };

    const renderEvidenceDbPanel = (data) => {
        const bundleId = data?.bundle_id || '';
        const summary = data?.evidence_summary || {};
        const warnings = Array.isArray(summary.coverage_warnings) ? summary.coverage_warnings : [];
        return `<div class="ves-evidence-db-panel" data-bundle-id="${escapeHtml(bundleId)}">
            <div class="ves-item-panel">
                <div class="ves-item-panel-title">Evidence database</div>
                <p class="ves-hint">Collected evidence for this audit. Use filters to explore by platform, entity type or source.</p>
                ${warnings.length ? `<ul class="ves-clean-list ves-warning-list">${warnings.map(w => `<li>${escapeHtml(String(w))}</li>`).join('')}</ul>` : ''}
                <form class="ves-evidence-filters">
                    <input class="ves-input" name="search" placeholder="Search evidence text, author, URL…">
                    <select class="ves-input" name="platform"><option value="">All platforms</option>${Object.keys(summary.by_platform || {}).map(k => `<option value="${escapeHtml(k)}">${escapeHtml(k)}</option>`).join('')}</select>
                    <select class="ves-input" name="entityType"><option value="">All entities</option>${Object.keys(summary.by_entity_type || {}).map(k => `<option value="${escapeHtml(k)}">${escapeHtml(k.replace(/_/g, ' '))}</option>`).join('')}</select>
                    <select class="ves-input" name="sourceType"><option value="">All sources</option>${Object.keys(summary.by_source_type || {}).map(k => `<option value="${escapeHtml(k)}">${escapeHtml(k.replace(/_/g, ' '))}</option>`).join('')}</select>
                    <select class="ves-input" name="sort"><option value="created_desc">Newest</option><option value="quality_desc">Calidad high</option><option value="metric_desc">Metric score</option><option value="published_desc">Published newest</option></select>
                    <button type="submit" class="ves-mini-btn">Apply</button>
                </form>
            </div>
            <div class="ves-evidence-list" data-ves-evidence-list><div class="ves-hint">Open this tab to load evidence from the database.</div></div>
        </div>`;
    };

    const renderEvidenceCards = (payload, bundleId) => {
        const items = Array.isArray(payload?.items) ? payload.items : [];
        if (!items.length) return '<div class="ves-item-panel"><div class="ves-hint">No evidence matched these filters.</div></div>';
        const cards = items.map(item => {
            const ref = item.evidence_ref || (item.id ? `ev_${item.id}` : '');
            const q = `What does evidence ${ref || item.id || ''} mean for this brand audit?`;
            return `<article class="ves-evidence-card" data-evidence-id="${escapeHtml(String(item.id || ''))}">
                <div class="ves-evidence-card-head"><span class="ves-pill">${escapeHtml(item.platform || 'unknown')}</span><span>${escapeHtml(item.entity_type || 'unknown')}</span><span>${escapeHtml(item.source_type || '')}</span>${ref ? `<code>${escapeHtml(ref)}</code><button type="button" class="ves-mini-btn ghost ves-copy-evidence-ref" data-evidence-ref="${escapeHtml(ref)}">Copy ref</button>` : ''}</div>
                <h4>${escapeHtml(item.title || item.author || item.url || 'Untitled evidence')}</h4>
                ${item.text ? `<p>${escapeHtml(String(item.text).slice(0, 360))}</p>` : ''}
                <div class="ves-evidence-meta"><span>Entity: ${escapeHtml(item.entity_name || 'unknown')}</span><span>Role: ${escapeHtml(item.entity_role || item.entity_type || 'unknown')}</span><span>Tipo: ${escapeHtml(item.content_type || 'unknown')}</span><span>Calidad: ${escapeHtml(String(item.quality_score || 0))}</span><span>Metric: ${escapeHtml(String(item.metric_score || 0))}</span><span>Status: ${escapeHtml(item.source_status || 'unknown')}</span></div>
                ${item.url ? `<a href="${escapeHtml(item.url)}" target="_blank" rel="noopener">Open source</a>` : ''}
${isDebugMode() ? `<details class="ves-evidence-debug"><summary>Debug: normalized fields</summary><pre>${escapeHtml(JSON.stringify({metrics:item.metrics || {}, normalized:item.normalized || {}, quality_flags:item.quality_flags || []}, null, 2))}</pre></details>` : ''}
                <div class="ves-evidence-actions"><button type="button" class="ves-mini-btn" data-ves-assistant-question="${escapeHtml(q)}" data-bundle-id="${escapeHtml(bundleId)}" data-evidence-id="${escapeHtml(String(item.id || ''))}">Preguntar al asistente</button>${item.raw_available ? `<button type="button" class="ves-mini-btn secondary ves-evidence-load-raw" data-bundle-id="${escapeHtml(bundleId)}" data-evidence-id="${escapeHtml(String(item.id || ''))}">View raw evidence</button>` : ''}</div>
                <div class="ves-assistant-cta-panel"><div class="ves-assistant-response-host" aria-live="polite"></div></div>
                <div class="ves-evidence-raw-host"></div>
            </article>`;
        }).join('');
        const page = Number(payload.page || 1);
        const totalPages = Number(payload.total_pages || 1);
        return `<div class="ves-evidence-card-grid">${cards}</div><div class="ves-evidence-pagination"><button type="button" class="ves-mini-btn ves-evidence-page" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Previous</button><span>Page ${escapeHtml(String(page))} / ${escapeHtml(String(totalPages || 1))}</span><button type="button" class="ves-mini-btn ves-evidence-page" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>Next</button></div>`;
    };

    const loadEvidenceList = async (wrap, page = 1) => {
        const widget = wrap.closest('.ves-wrap');
        const state = widgetState.get(widget);
        const form = state?.form || widget?.querySelector('form');
        const bundleId = wrap.dataset.bundleId || wrap.closest('[data-bundle-id]')?.dataset.bundleId || '';
        const listHost = wrap.querySelector('[data-ves-evidence-list]');
        if (!form || !bundleId || !listHost) return;
        const filters = wrap.querySelector('.ves-evidence-filters');
        const payload = { page, perPage: 12 };
        if (filters) {
            const fd = new FormData(filters);
            Object.assign(payload, formDataToPayload(fd));
        }
        listHost.innerHTML = '<div class="ves-item-panel"><div class="ves-spinner"></div><div class="ves-hint">Loading evidence…</div></div>';
        try {
            const data = await ajaxEvidenceList(form, bundleId, payload);
            listHost.innerHTML = renderEvidenceCards(data, bundleId);
            wrap.dataset.currentPage = String(data.page || page);
        } catch (err) {
            listHost.innerHTML = `<div class="ves-item-panel"><div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Could not load evidence.', widget))}</div></div>`;
        }
    };

    const renderAssistantAnswer = (answer, recentMessages = []) => {
        answer = answer || {};
        const recent = Array.isArray(recentMessages) && recentMessages.length
            ? `<div class="ves-assistant-recent"><h4>Recent thread context</h4>${recentMessages.slice(-4).map(msg => `<div class="ves-assistant-message-row ${escapeHtml(msg.role || '')}"><strong>${escapeHtml(msg.role || '')}</strong><span>${escapeHtml(String(msg.message || '').slice(0, 360))}</span></div>`).join('')}</div>`
            : '';
        return `<div class="ves-assistant-answer">${recent}<div class="ves-item-panel-title">Assistant answer</div><p>${escapeHtml(answer.direct_answer || 'No answer returned.')}</p>${answer.strategic_implication ? `<h4>Strategic implication</h4><p>${escapeHtml(answer.strategic_implication)}</p>` : ''}${answer.recommended_next_step ? `<h4>Recommended next step</h4><p>${escapeHtml(answer.recommended_next_step)}</p>` : ''}${Array.isArray(answer.evidence_used) && answer.evidence_used.length ? `<h4>Evidence used</h4><ul>${answer.evidence_used.map(x => `<li>${escapeHtml(String(x))}</li>`).join('')}</ul>` : ''}${Array.isArray(answer.suggested_workflows) && answer.suggested_workflows.length ? `<h4>Suggested workflows</h4><div class="ves-brand-question-grid">${answer.suggested_workflows.map(x => `<span class="ves-source-chip">${escapeHtml(String(x))}</span>`).join('')}</div>` : ''}${Array.isArray(answer.limitations) && answer.limitations.length ? `<h4>Limitations</h4><ul>${answer.limitations.map(x => `<li>${escapeHtml(String(x))}</li>`).join('')}</ul>` : ''}</div>`;
    };

    const askAssistantFromButton = async (btn, questionOverride = '') => {
        const widget = btn.closest('.ves-wrap');
        const state = widgetState.get(widget);
        const form = state?.form || widget?.querySelector('form');
        if (!form) return;
        const question = questionOverride || btn.dataset.vesAssistantQuestion || btn.closest('.ves-assistant-cta-panel')?.querySelector('.ves-assistant-followup')?.value || '';
        const bundleId = btn.dataset.bundleId || btn.closest('[data-bundle-id]')?.dataset.bundleId || '';
        const localCard = btn.closest('.ves-brand-opportunity-card, .ves-item-panel, .ves-evidence-card');
        const host = btn.closest('.ves-assistant-cta-panel')?.querySelector('.ves-assistant-response-host')
            || localCard?.querySelector('.ves-assistant-response-host')
            || btn.nextElementSibling?.querySelector?.('.ves-assistant-response-host')
            || btn.closest('.ves-brand-audit-results')?.querySelector('.ves-assistant-response-host');
        if (!question.trim()) return;
        if (host) host.innerHTML = '<div class="ves-item-panel"><div class="ves-spinner"></div><div class="ves-hint">' + escapeHtml(assistantName() + ' is reading the run context…') + '</div></div>';
        btn.disabled = true;
        try {
            const data = await ajaxAssistantAsk(form, {
                question,
                contextTipo: btn.dataset.contextType || 'brand_audit',
                bundleId,
                brandName: btn.dataset.vesBrand || '',
                evidenceId: btn.dataset.evidenceId || '',
                insightId: btn.dataset.insightId || '',
                opportunityId: btn.dataset.opportunityId || '',
                briefId: btn.dataset.briefId || ''
            });
            if (host) host.innerHTML = renderAssistantAnswer(data.answer || {}, data.recent_messages || []);
        } catch (err) {
            if (host) host.innerHTML = `<div class="ves-item-panel"><div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Assistant failed.', widget))}</div></div>`;
        } finally {
            btn.disabled = false;
        }
    };

    const brandAuditLabels = (data = {}) => {
        const lang = String(data?.requested?.language || data?.requested?.output_language || 'es').toLowerCase();
        if (lang.startsWith('es')) {
            return {
                executive:'Resumen ejecutivo', diagnosis:'Diagnóstico estratégico', evidenceCalidad:'Calidad de evidencia', usable:'util', partial:'parcial', failed:'fallidas', total:'total', confidence:'Confianza',
                topInsights:'Insights principales', marketOpportunities:'Oportunidades de mercado', dataHealth:'Hallazgos de salud de datos', noValidated:'Aún no hay insights estratégicos validados.',
                overview:'Resumen', evidence:'Evidencia', competitors:'Competidores', search:'Búsqueda / Website', social:'Social / Creatividad', opportunities:'Oportunidades', briefs:'Briefs', activity:'Actividad', deck:'Deck', assistant:'Asistente',
                aiLayer:'Capa estratégica de IA', aiOk:'Análisis estratégico generado con', aiUnavailable:'Capa estratégica de IA no disponible. El informe usa interpretación local.', completedPartial:'Completado con evidencia parcial'
            };
        }
        return { executive:'Resumen ejecutivo', diagnosis:'Diagnostico estrategico', evidenceCalidad:'Calidad de evidencia', usable:'util', partial:'parcial', failed:'fallido', total:'total', confidence:'Confianza', topInsights:'Insights principales', marketOpportunities:'Oportunidades de mercado', dataHealth:'Calidad de datos', noValidated:'Aun no hay insights estrategicos validados.', overview:'Resumen', evidence:'Evidencia', competitors:'Competidores', search:'Busqueda / Web', social:'Social / Creativo', opportunities:'Oportunidades', briefs:'Briefs', activity:'Actividad', deck:'Deck', assistant:'Asistente', aiLayer:'Capa estrategica AI', aiOk:'Analisis estrategico generado con', aiUnavailable:'Capa estrategica AI no disponible. El informe usa interpretacion local de respaldo.', completedPartial:'Completado con evidencia parcial' };
    };

    const normalizeBrandAuditReportSchema = (data = {}) => {
        const structured = data.analysis_structured || {};
        const schema = data.report_schema || structured.report_schema || {};
        if (schema.report_meta && schema.executive_verdict) return schema;
        const evidence = data.evidence_summary || {};
        const usable = Number(evidence.usable_count ?? evidence.stored ?? data.usable_fuentes ?? 0) || 0;
        const partial = Number(evidence.partial_count ?? data.partial_fuentes ?? 0) || 0;
        const failed = Number(evidence.failed_count ?? data.failed_fuentes ?? 0) || 0;
        const total = Number(evidence.total ?? evidence.total_saved ?? evidence.stored ?? data.total_fuentes ?? (usable + partial + failed)) || 0;
        const weak = total < 10 || partial > 0 || failed > 0 || String(data.run_status || '').toLowerCase() === 'partial';
        const opps = Array.isArray(data.market_opportunities) && data.market_opportunities.length ? data.market_opportunities : (Array.isArray(structured.opportunities) ? structured.opportunities : []);
        const insights = Array.isArray(structured.insights) ? structured.insights : [];
        const actions = Array.isArray(structured.recommended_next_actions) ? structured.recommended_next_actions : [];
        return {
            report_meta: {
                brand_name: data?.requested?.brand_name || '',
                market: data?.requested?.country || '',
                language: data?.requested?.language || '',
                status: total < 4 ? 'needs_more_data' : (weak ? 'completed_with_limitations' : 'completed'),
                evidence_count: total,
                confidence: weak ? 0.55 : 0.72,
                generated_at: data.generated_at || '',
                model: data.ai_model || '',
                prompt_version: data.prompt_version || 'legacy-normalized-client-view'
            },
            executive_verdict: {
                headline: structured?.strategic_diagnosis?.main_finding || data?.report?.title || 'Brand Deep Audit verdict',
                summary: structured.executive_summary || data.analysis || 'The audit generated a normalized report, but the strict report object was not present in the stored payload.',
                confidence: weak ? 0.55 : 0.72,
                client_ready: !weak,
                limitations: Array.isArray(structured.limitations) ? structured.limitations : []
            },
            evidence_quality: {
                usable_count: usable,
                partial_count: partial,
                failed_count: failed,
                source_breakdown: [],
                freshness: total ? 'Mixed public evidence. Verify dates before external use.' : 'No usable evidence available.',
                reliability_notes: total ? ['Legacy payload normalized into the client-ready report renderer.'] : ['Evidence is insufficient for a client-ready verdict.'],
                missing_sources: []
            },
            what_we_know: insights.map((x) => x?.claim || x?.title || x?.finding || '').filter(Boolean).slice(0, 8),
            what_we_do_not_know: Array.isArray(structured.limitations) ? structured.limitations : [],
            key_patterns: insights.map((x) => ({
                claim: x?.claim || x?.title || x?.finding || '',
                evidence: Array.isArray(x?.evidence_refs) ? x.evidence_refs : (Array.isArray(x?.evidence) ? x.evidence : []),
                confidence: Number(x?.confidence || 0.55) || 0.55,
                why_it_matters: x?.why_it_matters || x?.business_implication || x?.implication || '',
                missing_data: Array.isArray(x?.missing_data) ? x.missing_data : []
            })).filter((x) => x.claim),
            strategic_diagnosis: {
                diagnosis: structured?.strategic_diagnosis?.main_finding || '',
                business_implication: structured?.strategic_diagnosis?.why_it_matters || '',
                risk: weak ? 'Evidence is partial, so recommendations should be reviewed before client use.' : '',
                confidence: weak ? 0.55 : 0.72
            },
            competitor_implications: Array.isArray(structured.competitor_gap_matrix) ? structured.competitor_gap_matrix.map((x) => ({
                competitor: x?.competitor || x?.name || 'Competitor',
                observed_gap: x?.observed_gap || x?.gap || '',
                evidence: Array.isArray(x?.evidence_refs) ? x.evidence_refs : [],
                implication: x?.implication || '',
                recommended_response: x?.recommended_response || x?.recommendation || '',
                confidence: Number(x?.confidence || 0.55) || 0.55
            })) : [],
            opportunities: opps.map((x) => ({
                title: x?.title || x?.name || 'Opportunity',
                target_audience: x?.target_audience || x?.audience || '',
                channel: x?.channel || x?.platform || 'Multi-channel',
                gap: x?.gap || x?.why_now || '',
                evidence: Array.isArray(x?.evidence_refs) ? x.evidence_refs : (Array.isArray(x?.evidence) ? x.evidence : []),
                recommended_angle: x?.recommended_angle || x?.angle || x?.recommended_action || '',
                expected_business_value: x?.expected_business_value || x?.business_value || '',
                effort: ['low','medium','high'].includes(String(x?.effort || '').toLowerCase()) ? String(x.effort).toLowerCase() : 'medium',
                confidence: Number(x?.confidence || 0.55) || 0.55,
                next_action: x?.next_action || x?.recommended_action || 'Validate and generate a brief.'
            })).filter((x) => x.title),
            recommended_actions: actions.map((x) => typeof x === 'string' ? { action: x, priority: 'medium', reason: '', owner_role: 'Marketing lead', timeframe: 'Next planning cycle', confidence: 0.55 } : {
                action: x?.action || x?.title || '', priority: x?.priority || 'medium', reason: x?.reason || x?.why || '', owner_role: x?.owner_role || x?.owner || 'Marketing lead', timeframe: x?.timeframe || 'Next planning cycle', confidence: Number(x?.confidence || 0.55) || 0.55
            }).filter((x) => x.action),
            briefs: [],
            next_data_collection_plan: [],
            evidence_appendix: []
        };
    };

    const renderClientReportList = (items = [], empty = 'No items available yet.') => {
        if (!Array.isArray(items) || !items.length) return `<div class="ves-empty-state compact">${escapeHtml(empty)}</div>`;
        return `<ul class="ves-report-list">${items.map((item) => `<li>${escapeHtml(typeof item === 'string' ? item : (item?.summary || item?.title || item?.claim || String(item)))}</li>`).join('')}</ul>`;
    };

    const renderEvidenceCountBadge = (items = []) => {
        const count = Array.isArray(items) ? items.filter(Boolean).length : 0;
        return `<span class="ves-source-chip"><strong>Evidence</strong><em>${escapeHtml(String(count))} item${count === 1 ? '' : 's'}</em></span>`;
    };

    const renderBrandAuditResults = (widget, data) => {
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const results = activePage.querySelector('.ves-results') || widget.querySelector('.ves-results');
        if (!results) return;
        const note = activePage.querySelector('.ves-brand-audit-note');
        if (note) note.hidden = true;
        const report = normalizeBrandAuditReportSchema(data || {});
        const structured = data.analysis_structured || {};
        const isAdmin = isAdminUI(widget);
        const meta = report.report_meta || {};
        const verdict = report.executive_verdict || {};
        const quality = report.evidence_quality || {};
        const diagnosis = report.strategic_diagnosis || {};
        const statusLabel = String(meta.status || data.run_status || 'completed_with_limitations').replace(/_/g, ' ');
        const confidence = Number(verdict.confidence ?? meta.confidence ?? 0) || 0;
        const statusClass = String(meta.status || '').includes('needs_more') ? 'is-warning' : (String(meta.status || '').includes('failed') ? 'is-error' : 'is-ok');
        const evidenceCalidadPanel = `
            <div class="ves-item-panel ves-report-card">
                <div class="ves-item-panel-title">Evidence quality</div>
                <div class="ves-brand-kpi-row">
                    <span><strong>${escapeHtml(String(quality.usable_count || 0))}</strong><em>usable</em></span>
                    <span><strong>${escapeHtml(String(quality.partial_count || 0))}</strong><em>partial</em></span>
                    <span><strong>${escapeHtml(String(quality.failed_count || 0))}</strong><em>failed</em></span>
                    <span><strong>${escapeHtml(String(meta.evidence_count || 0))}</strong><em>evidence</em></span>
                </div>
                ${quality.freshness ? `<p class="ves-hint">${escapeHtml(quality.freshness)}</p>` : ''}
                ${renderClientReportList(quality.reliability_notes || [], 'No reliability notes recorded.')}
            </div>`;
        const overviewPanel = `
            <div class="ves-item-panel ves-executive-verdict-card">
                <div class="ves-item-panel-title">Executive verdict</div>
                <h3>${escapeHtml(verdict.headline || 'Brand Deep Audit verdict')}</h3>
                <div class="ves-trend-ai-report compact">${renderAnalysisRichText(verdict.summary || data.analysis || '')}</div>
                <div class="ves-report-meta-row">
                    <span class="ves-status-pill ${escapeHtml(statusClass)}">${escapeHtml(statusLabel)}</span>
                    <span class="ves-source-chip"><strong>Confidence</strong><em>${escapeHtml(String(Math.round(confidence * 100)))}%</em></span>
                    <span class="ves-source-chip"><strong>Client-ready</strong><em>${verdict.client_ready ? 'Yes' : 'Review first'}</em></span>
                </div>
                ${Array.isArray(verdict.limitations) && verdict.limitations.length ? `<div class="ves-report-subsection"><strong>Limitations</strong>${renderClientReportList(verdict.limitations)}</div>` : ''}
            </div>
            ${evidenceCalidadPanel}
            <div class="ves-brand-audit-grid-results">
                <div class="ves-item-panel ves-report-card"><div class="ves-item-panel-title">What we know</div>${renderClientReportList(report.what_we_know || [], 'No evidence-backed knowns recorded yet.')}</div>
                <div class="ves-item-panel ves-report-card"><div class="ves-item-panel-title">What we do not know</div>${renderClientReportList(report.what_we_do_not_know || [], 'No explicit unknowns recorded.')}</div>
            </div>`;
        const patternsPanel = Array.isArray(report.key_patterns) && report.key_patterns.length
            ? report.key_patterns.map((item) => `<div class="ves-item-panel ves-report-card"><div class="ves-item-panel-title">${escapeHtml(item.claim || 'Pattern')}</div><p>${escapeHtml(item.why_it_matters || '')}</p><div class="ves-report-meta-row">${renderEvidenceCountBadge(item.evidence || [])}<span class="ves-source-chip"><strong>Confidence</strong><em>${escapeHtml(String(Math.round((Number(item.confidence || 0) || 0) * 100)))}%</em></span></div>${renderClientReportList(item.missing_data || [], 'No missing data flagged for this pattern.')}</div>`).join('')
            : '<div class="ves-empty-state compact">No evidence-backed key patterns were recorded.</div>';
        const diagnosisPanel = `<div class="ves-item-panel ves-report-card"><div class="ves-item-panel-title">Strategic diagnosis</div><h3>${escapeHtml(diagnosis.diagnosis || 'Diagnosis not strong enough yet')}</h3><p>${escapeHtml(diagnosis.business_implication || '')}</p>${diagnosis.risk ? `<div class="ves-warning-box compact">${escapeHtml(diagnosis.risk)}</div>` : ''}<span class="ves-source-chip"><strong>Confidence</strong><em>${escapeHtml(String(Math.round((Number(diagnosis.confidence || 0) || 0) * 100)))}%</em></span></div>`;
        const competitorsPanel = Array.isArray(report.competitor_implications) && report.competitor_implications.length
            ? report.competitor_implications.map((item) => `<div class="ves-item-panel ves-report-card"><div class="ves-item-panel-title">${escapeHtml(item.competitor || 'Competitor')}</div><p><strong>Gap:</strong> ${escapeHtml(item.observed_gap || '')}</p><p><strong>Implication:</strong> ${escapeHtml(item.implication || '')}</p><p><strong>Recommended response:</strong> ${escapeHtml(item.recommended_response || '')}</p><div class="ves-report-meta-row">${renderEvidenceCountBadge(item.evidence || [])}<span class="ves-source-chip"><strong>Confidence</strong><em>${escapeHtml(String(Math.round((Number(item.confidence || 0) || 0) * 100)))}%</em></span></div></div>`).join('')
            : '<div class="ves-empty-state compact">No competitor implications were strong enough to show.</div>';
        const opportunitiesPanel = Array.isArray(report.opportunities) && report.opportunities.length
            ? report.opportunities.map((item, idx) => `<div class="ves-item-panel ves-report-card"><div class="ves-item-panel-title">${escapeHtml(item.title || 'Opportunity')}</div><p><strong>Audience:</strong> ${escapeHtml(item.target_audience || 'Not specified')}</p><p><strong>Channel:</strong> ${escapeHtml(item.channel || 'Multi-channel')}</p><p><strong>Gap:</strong> ${escapeHtml(item.gap || '')}</p><p><strong>Ángulo:</strong> ${escapeHtml(item.recommended_angle || '')}</p><p><strong>Business value:</strong> ${escapeHtml(item.expected_business_value || '')}</p><div class="ves-report-meta-row">${renderEvidenceCountBadge(item.evidence || [])}<span class="ves-source-chip"><strong>Effort</strong><em>${escapeHtml(item.effort || 'medium')}</em></span><span class="ves-source-chip"><strong>Confidence</strong><em>${escapeHtml(String(Math.round((Number(item.confidence || 0) || 0) * 100)))}%</em></span></div><div class="ves-report-actions">${(item.record_id || item.id) ? `<button type="button" class="ves-mini-btn" data-ves-generate-brief data-opportunity-id="${escapeHtml(item.record_id || item.id)}">Generar brief</button>` : `<button type="button" class="ves-mini-btn" disabled title="Save this opportunity before generating a linked brief.">Generar brief</button>`}<button type="button" class="ves-mini-btn secondary">Ver detalles</button></div></div>`).join('')
            : '<div class="ves-empty-state compact">No actionable opportunities were strong enough to show from this evidence.</div>';
        const actionsPanel = Array.isArray(report.recommended_actions) && report.recommended_actions.length
            ? report.recommended_actions.map((item) => `<div class="ves-item-panel ves-report-card"><div class="ves-item-panel-title">${escapeHtml(item.action || 'Recommended action')}</div><p>${escapeHtml(item.reason || '')}</p><div class="ves-report-meta-row"><span class="ves-source-chip"><strong>Priority</strong><em>${escapeHtml(item.priority || 'medium')}</em></span><span class="ves-source-chip"><strong>Owner</strong><em>${escapeHtml(item.owner_role || 'Marketing lead')}</em></span><span class="ves-source-chip"><strong>Timeframe</strong><em>${escapeHtml(item.timeframe || '')}</em></span></div></div>`).join('')
            : '<div class="ves-empty-state compact">No recommended actions recorded yet.</div>';
        const briefsPanel = `${Array.isArray(report.briefs) && report.briefs.length ? report.briefs.map((item) => `<div class="ves-item-panel ves-report-card"><div class="ves-item-panel-title">${escapeHtml(item.title || 'Brief')}</div><p><strong>Objective:</strong> ${escapeHtml(item.objective || '')}</p><p><strong>Audience:</strong> ${escapeHtml(item.audience || '')}</p><p><strong>Message:</strong> ${escapeHtml(item.message || '')}</p>${renderClientReportList(item.proof_points || [], 'No proof points recorded.')}</div>`).join('') : '<div class="ves-empty-state compact">No generated briefs are embedded in this report yet.</div>'}<div data-ves-brief-records-host></div>`;
        const planPanel = Array.isArray(report.next_data_collection_plan) && report.next_data_collection_plan.length
            ? report.next_data_collection_plan.map((item) => `<div class="ves-item-panel ves-report-card"><div class="ves-item-panel-title">${escapeHtml(item.source || 'Next source')}</div><p>${escapeHtml(item.reason || '')}</p><div class="ves-report-meta-row"><span class="ves-source-chip"><strong>Priority</strong><em>${escapeHtml(item.priority || 'medium')}</em></span></div><p><strong>Next action:</strong> ${escapeHtml(item.next_action || '')}</p></div>`).join('')
            : '<div class="ves-empty-state compact">No additional collection plan recorded.</div>';
        const appendixPanel = Array.isArray(report.evidence_appendix) && report.evidence_appendix.length
            ? `<div class="ves-report-appendix-grid">${report.evidence_appendix.slice(0, 80).map((item) => `<div class="ves-item-panel ves-report-card"><div class="ves-item-panel-title">${escapeHtml(item.source || item.entity || 'Evidence')}</div><p>${escapeHtml(item.summary || '')}</p>${item.url ? `<a class="ves-mini-btn secondary" href="${escapeHtml(item.url)}" target="_blank" rel="noopener">Ver fuente</a>` : ''}<span class="ves-source-chip"><strong>Calidad</strong><em>${escapeHtml(String(Math.round((Number(item.quality_score || 0) || 0) * 100)))}%</em></span></div>`).join('')}</div>`
            : '<div class="ves-empty-state compact">No client-safe evidence appendix is available.</div>';
        const adminDebugPanel = isAdmin ? `${renderEvidenceDbPanel(data)}${renderBrandAuditEvidenceViewer(data.evidence_pack || {}, structured)}${renderBrandAuditSourceStatus(Object.values(data?.evidence_pack?.source_quality?.status_cards || data?.evidence_pack?.source_quality?.coverage || {}))}<details class="ves-source-input-details"><summary>Admin: raw AI JSON</summary><pre>${escapeHtml(JSON.stringify(structured, null, 2).slice(0, 12000))}</pre></details><div data-ves-insight-records-host></div><div data-ves-workflow-events-host></div>` : '';
        const tabs = [
            ['overview', 'Overview', overviewPanel],
            ['patterns', 'Key Patterns', patternsPanel],
            ['diagnosis', 'Strategic Diagnosis', diagnosisPanel],
            ['competitors', 'Competitors', competitorsPanel],
            ['opportunities', 'Opportunities', opportunitiesPanel],
            ['actions', 'Recommended Actions', actionsPanel],
            ['briefs', 'Briefs', briefsPanel],
            ['plan', 'Next Data Plan', planPanel],
            ['appendix', 'Evidence Appendix', appendixPanel],
            ['assistant', 'Assistant', `${renderBrandAuditAssistantCtas(data, structured)}<div class="ves-item-panel"><div class="ves-item-panel-title">Report limitations</div>${renderClientReportList(verdict.limitations || report.what_we_do_not_know || [], 'No limitations recorded.')}</div>`]
        ];
        if (isAdmin) tabs.push(['debug', 'Admin Debug', adminDebugPanel]);
        results.innerHTML = `
            <div class="ves-brand-audit-results" data-bundle-id="${escapeHtml(data?.bundle_id || '')}">
                <div class="ves-brand-result-header">
                    <div>
                        <span class="ves-pill">Brand Deep Audit</span>
                        <h3>${escapeHtml(meta.brand_name || data?.requested?.brand_name || 'Brand Audit Result')}</h3>
                        <p>${escapeHtml(statusLabel)} · ${escapeHtml(meta.generated_at || data?.generated_at || '')}</p>
                    </div>
                    <button type="button" class="ves-mini-btn ves-brand-audit-refresh-active">Refresh Saved Runs</button>
                </div>
                <div class="ves-brand-result-tabs" role="tablist">
                    ${tabs.map(([key, label], index) => `<button type="button" class="ves-brand-result-tab ${index === 0 ? 'is-active' : ''}" data-brand-result-tab="${escapeHtml(key)}" role="tab" aria-selected="${index === 0 ? 'true' : 'false'}">${escapeHtml(label)}</button>`).join('')}
                </div>
                <div class="ves-brand-result-panels">
                    ${tabs.map(([key, label, html], index) => `<section class="ves-brand-result-panel ${index === 0 ? 'is-active' : ''}" data-brand-result-panel="${escapeHtml(key)}" role="tabpanel" aria-label="${escapeHtml(label)}">${html || '<div class="ves-empty-state compact">No data available for this section.</div>'}</section>`).join('')}
                </div>
            </div>`;
        results.classList.add('show');
        const resultWrap = results.querySelector('.ves-brand-audit-results');
        if (resultWrap) {
            if (isAdmin) setTimeout(() => loadInsightBoard(resultWrap), 120);
            setTimeout(() => loadBriefRecords(resultWrap), 180);
            if (isAdmin) setTimeout(() => loadWorkflowEvents(resultWrap), 220);
        }
    };

    const BRAND_AUDIT_ACTIVE_STORAGE_KEY = 'ves_brand_audit_active_v2';
    const saveActiveBrandAuditRun = (bundleId, meta = {}) => {
        if (!bundleId) return;
        try {
            safeStorage.set(BRAND_AUDIT_ACTIVE_STORAGE_KEY, JSON.stringify({ bundleId, meta, savedAt: Date.now(), path: window.location.pathname || '' }));
        } catch (e) {}
    };
    const clearActiveBrandAuditRun = (bundleId = '') => {
        try {
            const raw = safeStorage.get(BRAND_AUDIT_ACTIVE_STORAGE_KEY);
            if (!raw) return;
            const parsed = JSON.parse(raw);
            if (!bundleId || parsed.bundleId === bundleId) safeStorage.remove(BRAND_AUDIT_ACTIVE_STORAGE_KEY);
        } catch (e) { safeStorage.remove(BRAND_AUDIT_ACTIVE_STORAGE_KEY); }
    };
    const renderBrandAuditActiveRuns = (widget, runs = []) => {
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const results = activePage.querySelector('.ves-results') || widget.querySelector('.ves-results');
        if (!results) return;
        if (!Array.isArray(runs) || !runs.length) return;
        const phaseClass = (run) => String(run.run_phase || run.status || 'running').toLowerCase().replace(/[^a-z0-9_-]/g, '-');
        const sourceChip = (s) => {
            const stage = String(s.stage || s.status || 'unknown').toLowerCase().replace(/[^a-z0-9_-]/g, '-');
            const message = s.message || s.notes || '';
            const count = Number(s.item_count || 0);
            return `<span class="ves-source-chip ves-source-chip-${escapeHtml(stage)}" title="${escapeHtml(message)}"><strong>${escapeHtml(s.label || 'Source')}</strong><em>${escapeHtml(s.stage_label || s.status || '—')}${count ? ` · ${count}` : ''}</em></span>`;
        };
        const rows = runs.map((run) => {
            const progress = run.progress || { done: 0, total: 0 };
            const total = Number(progress.total || 0);
            const done = Number(progress.done || 0);
            const pct = total > 0 ? Math.max(0, Math.min(100, Math.round((done / total) * 100))) : 0;
            const fuentes = Array.isArray(run.sources) ? run.sources.slice(0, 8).map(sourceChip).join('') : '';
            const finalAvailable = !!run.final_available;
            const titlePrefix = finalAvailable ? 'Brand Audit guardado' : 'Brand Audit activo';
            const primaryLabel = finalAvailable ? 'Ver resultado' : 'Continuar seguimiento';
            const evidenceCount = Number(run.evidence_count || 0);
            const intelCounts = run.intelligence_counts || {};
            const intelTotal = Number(intelCounts.metrics || 0) + Number(intelCounts.patterns || 0) + Number(intelCounts.insights || 0) + Number(intelCounts.opportunities || 0) + Number(intelCounts.briefs || 0);
            const generatedAt = run.completed_at || run.updated_at || run.created_at || '';
            return `<div class="ves-item-panel ves-brand-audit-active-card ves-run-phase-${escapeHtml(phaseClass(run))}" data-bundle-id="${escapeHtml(run.bundle_id || '')}">
                <div class="ves-item-panel-title">${titlePrefix}: ${escapeHtml(run.brand_name || 'Brand Audit')}</div>
                <div class="ves-run-phase-line"><span>${escapeHtml(run.phase_label || run.status || 'Running')}</span><strong>${escapeHtml(String(done))}/${escapeHtml(String(total))} fuentes</strong>${generatedAt ? `<em>${escapeHtml(generatedAt)}</em>` : `<em>${escapeHtml(String(run.elapsed_seconds || 0))}s</em>`}</div>
                <div class="ves-run-progress" aria-hidden="true"><span style="width:${pct}%"></span></div>
                <div class="ves-brand-kpi-row compact"><span><strong>${escapeHtml(String(evidenceCount))}</strong><em>evidence items</em></span><span><strong>${escapeHtml(String(intelTotal))}</strong><em>intelligence records</em></span><span><strong>${escapeHtml(run.status || 'unknown')}</strong><em>status</em></span></div>
                ${intelTotal ? `<div class="ves-hint">Metrics ${escapeHtml(String(intelCounts.metrics || 0))} · Patterns ${escapeHtml(String(intelCounts.patterns || 0))} · Insights ${escapeHtml(String(intelCounts.insights || 0))} · Opportunities ${escapeHtml(String(intelCounts.opportunities || 0))} · Briefs ${escapeHtml(String(intelCounts.briefs || 0))}</div>` : ''}
                ${fuentes ? `<div class="ves-source-chip-row">${sources}</div>` : ''}
                <div class="ves-run-actions">
                    <button type="button" class="ves-btn ves-brand-audit-resume" data-bundle-id="${escapeHtml(run.bundle_id || '')}">${primaryLabel}</button>
                    ${finalAvailable ? `<button type="button" class="ves-btn secondary" data-ves-assistant-question="What should I do next from this saved Brand Audit?" data-bundle-id="${escapeHtml(run.bundle_id || '')}" data-ves-brand="${escapeHtml(run.brand_name || '')}">Preguntar al asistente</button>` : ''}
                    <button type="button" class="ves-btn secondary ves-brand-audit-refresh-active">Actualizar procesos</button>
                </div>
                <div class="ves-assistant-cta-panel"><div class="ves-assistant-response-host" aria-live="polite"></div></div>
            </div>`;
        }).join('');
        results.innerHTML = `<div class="ves-brand-audit-results"><div class="ves-item-panel"><div class="ves-item-panel-title">Procesos guardados</div><div class="ves-hint">Los Brand Audits ya no dependen de mantener esta página abierta. Puedes recargar, volver más tarde y abrir el resultado desde aquí.</div></div>${rows}</div>`;
        results.classList.add('show');
    };
    const checkBrandAuditActiveRuns = async (widget, autoResume = false) => {
        const state = widgetState.get(widget);
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const form = activePage.querySelector('.ves-form') || state?.form;
        if (!state || !form || state.currentRunId) return;
        try {
            const data = await ajaxBrandAuditActive(form);
            const activeRuns = Array.isArray(data.active_runs) ? data.active_runs : [];
            let historyRuns = [];
            try {
                const historyData = await ajaxBrandAuditHistory(form, { limit: 12 });
                historyRuns = Array.isArray(historyData.history) ? historyData.history : [];
            } catch (historyErr) {
                VES_WARN('brand audit history lookup failed', historyErr);
            }
            const seenBundles = new Set();
            const runs = [...activeRuns, ...historyRuns].filter(run => {
                const key = run?.bundle_id || run?.id || '';
                if (!key || seenBundles.has(key)) return false;
                seenBundles.add(key);
                return true;
            });
            if (!runs.length) return;
            renderBrandAuditActiveRuns(widget, runs);
            const latest = activeRuns[0] || historyRuns[0];
            if (latest?.bundle_id) saveActiveBrandAuditRun(latest.bundle_id, latest);
            if (autoResume && latest?.bundle_id && widget.dataset.activePage === 'brand-audit') {
                await pollBrandAuditRun(widget, latest.bundle_id, { resumed: true });
            }
        } catch (err) {
            VES_WARN('brand audit active lookup failed', err);
        }
    };
    const pollBrandAuditRun = async (widget, bundleId, options = {}) => {
        const state = widgetState.get(widget);
        const form = state.form;
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const abortBtn = activePage.querySelector('.ves-abort') || widget.querySelector('.ves-abort');
        const startBtn = activePage.querySelector('.ves-start') || widget.querySelector('.ves-start');
        state.currentRunId = bundleId;
        state.currentRunType = 'brand_audit';
        if (abortBtn) abortBtn.style.display = 'inline-flex';
        const started = Date.now();
        saveActiveBrandAuditRun(bundleId, { resumed: !!options.resumed });
        // Max ~60 min: 120 polls × 2.5 s + 480 polls × 5 s + 840 polls × 8 s = ~113 min → capped at 1440 to cover deep runs.
        for (let i = 1; i <= 1440; i++) {
            const elapsed = Math.round((Date.now() - started) / 1000);
            setStatus(widget, `<strong>Preparando Brand Audit…</strong> <span style="color:var(--ves-muted);margin-left:6px">${elapsed}s</span><div style="margin-top:6px;font-size:11.5px;opacity:.85">Puedes cerrar o recargar la página; el proceso queda guardado y se puede retomar.</div>`, 'info', true);
            try {
                const data = await ajaxBrandAuditPoll(form, bundleId);
                if (data.status === 'RUNNING') {
                    const progress = data.progress || { done:0, total:1 };
                    const phase = data.phase_label || data.run_phase || 'Collecting evidence';
                    const progressDone = Math.max(0, parseInt(progress.done, 10) || 0);
                    const progressTotal = Math.max(0, parseInt(progress.total, 10) || 1);
                    const labels = Array.isArray(data.sources) ? data.sources.map(s=>`${escapeHtml(s.label||'')}: ${escapeHtml(s.stage_label||s.status||'')}${s.item_count ? ' (' + Math.max(0,parseInt(s.item_count,10)||0) + ')' : ''}`).slice(0,6).join(' · ') : '';
                    const errors = Array.isArray(data.sources) ? data.sources.filter(s => ['config_error','dispatch_error','failed','timed_out','partial'].includes(String(s.stage || '').toLowerCase())).slice(0,3).map(s => `${escapeHtml(s.label||'Source')}: ${escapeHtml(s.message||s.notes||s.status||'')}`).join(' · ') : '';
                    setStatus(widget, `<strong>${escapeHtml(phase)}…</strong> ${progressDone}/${progressTotal} fuentes listas.${labels ? `<div style="margin-top:6px;font-size:11.5px;opacity:.85">${escapeHtml(labels)}</div>` : ''}${errors ? `<div style="margin-top:6px;font-size:11.5px;color:#a15c00">${escapeHtml(errors)}</div>` : ''}<div style="margin-top:6px;font-size:11.5px;opacity:.75">Proceso persistente: puedes volver más tarde y continuar seguimiento.</div>`, 'info', true);
                } else if (data.status === 'SUCCEEDED') {
                    state.currentRunId = null;
                    state.currentRunType = 'standard';
                    if (abortBtn) abortBtn.style.display = 'none';
                    if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
                    clearActiveBrandAuditRun(bundleId);
                    setStatus(widget, '✅ <strong>Brand Audit completado</strong>.', 'success');
                    renderBrandAuditResults(widget, data);
                    refreshUsageSummary(widget);
                    return;
                } else if (['FAILED','TIMED-OUT','TIMEOUT','ABORTED'].includes(data.status)) {
                    state.currentRunId = null;
                    state.currentRunType = 'standard';
                    if (abortBtn) abortBtn.style.display = 'none';
                    if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
                    clearActiveBrandAuditRun(bundleId);
                    setStatus(widget, `⚠️ Brand Audit terminó con estado <strong>${escapeHtml(data.status)}</strong>.`, 'error');
                    return;
                }
            } catch (err) {
                VES_WARN('brand audit poll failed', err);
                // v0.9.16.23: Brand Audit runs are persistent server-side. A temporary
                // AJAX/polling failure must not make the user think the task was lost.
                saveActiveBrandAuditRun(bundleId, { pausedAt: Date.now(), pollError: err?.message || 'poll_failed' });
                if (state) {
                    state.currentRunId = null;
                    state.currentRunType = 'standard';
                }
                if (abortBtn) {
                    abortBtn.style.display = 'none';
                    abortBtn.hidden = true;
                }
                if (startBtn) {
                    startBtn.disabled = false;
                    delete startBtn.dataset.vesBusy;
                }
                setStatus(widget, `⚠️ No se pudo consultar el Brand Audit ahora, pero el proceso sigue guardado. Vuelve a abrir esta sección o pulsa “Continuar seguimiento”.<div style="margin-top:6px;font-size:11.5px;opacity:.85">Detalle: ${escapeHtml(err?.message || 'Error de polling')}</div>`, 'info');
                renderBrandAuditActiveRuns(widget, [{ bundle_id: bundleId, brand_name: 'Brand Audit', status: 'running', phase_label: 'Saved / waiting for next check', progress: {done: 0, total: 0}, elapsed_seconds: Math.round((Date.now() - started) / 1000), sources: [] }]);
                return;
            }
            await new Promise(r => setTimeout(r, i > 480 ? 8000 : i > 120 ? 5000 : 2500));
        }
        saveActiveBrandAuditRun(bundleId, { pausedAt: Date.now() });
        state.currentRunId = null;
        state.currentRunType = 'standard';
        if (abortBtn) abortBtn.style.display = 'none';
        if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
        setStatus(widget, 'El Brand Audit sigue en curso, pero no se ha perdido. Puedes cerrar la página y volver: aparecerá como proceso activo para continuar seguimiento.', 'info');
        renderBrandAuditActiveRuns(widget, [{ bundle_id: bundleId, brand_name: 'Brand Audit', status: 'running', progress: {done: 0, total: 0}, elapsed_seconds: Math.round((Date.now() - started) / 1000), sources: [] }]);
    };
    const pollRun = async (widget, platform, runId) => {
        const state = widgetState.get(widget);
        const form = state.form;
        // v0.9.9.3: scope buttons to active page.
        const activePage = widget.querySelector('.ves-page.is-active') || widget;
        const abortBtn = activePage.querySelector('.ves-abort') || widget.querySelector('.ves-abort');
        const startBtn = activePage.querySelector('.ves-start') || widget.querySelector('.ves-start');
        state.currentRunId = runId;
        if (abortBtn) abortBtn.style.display = 'inline-flex';
        const started = Date.now();
        for (let i = 1; i <= 60; i++) {
            const elapsed = Math.round((Date.now() - started) / 1000);
            setStatus(widget, `<strong>Procesando resultados…</strong> <span style="color:var(--ves-muted);margin-left:6px">${elapsed}s</span>`, 'info', true);
            try {
                const data = await ajaxPoll(form, platform, runId);
                if (['SUCCEEDED','FAILED','TIMED-OUT','TIMEOUT','ABORTED'].includes(data.status)) {
                    syncRunStateFromData(widget, data);
                    state.currentRunId = null;
                    if (abortBtn) abortBtn.style.display = 'none';
                    if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
                    if (['CONFIG_REQUIRED','NO_EVIDENCE','HONEST_DIAGNOSTIC'].includes(data.status)) {
                        setStatus(widget, vesErrorStatusHtml('Google Intelligence Basic', data, widget), data.retryable ? 'warning' : 'info');
                        renderGoogleResults(widget, data);
                        startBtn.disabled = false; delete startBtn.dataset.vesBusy;
                    } else if (data.status === 'SUCCEEDED') {
                        const count = Number(data.all_items_count ?? data.item_count_preview) || 0;
                        if (count === 0 && data.status_message) {
                            const baMsgSafe = safeUserMessage(String(data.status_message), widget);
                            const baAdminDetail = isAdminUI(widget) ? `<div style="margin-top:6px;font-size:12px;opacity:.9">${escapeHtml(String(data.status_message).slice(0, 600))}</div>` : '';
                            setStatus(widget, `ℹ️ <strong>No hay señales utilizables.</strong>${baAdminDetail || `<div style="margin-top:6px;font-size:12px;opacity:.9">${escapeHtml(baMsgSafe)}</div>`}`, 'info');
                        } else {
                            setStatus(widget, `✅ <strong>Extracción completada</strong> — ${count} resultados.`, 'success');
                        }
                        try {
                            renderResults(widget, data);
                        } catch (renderErr) {
                            VES_WARN('render results failed', renderErr);
                            setStatus(widget, '❌ Error al pintar resultados: ' + escapeHtml(safeUserMessage(renderErr.message || 'Error de renderizado.', widget)), 'error');
                        }
                    } else {
                        const baErrDetail = isAdminUI(widget) && data.status_message ? ` <div style="margin-top:6px;font-size:11.5px;opacity:.85">Detail: ${escapeHtml(String(data.status_message).slice(0, 500))}</div>` : '';
                        setStatus(widget, `⚠️ <strong>Something went wrong.</strong> Please try again in a few minutes or contact support.${baErrDetail}`, 'error');
                    }
                    return;
                }
            } catch (err) {
                VES_WARN('poll failed', err);
                finishPollingAfterError(widget, state, abortBtn, startBtn, err, 'Error al consultar la extracción');
                return;
            }
            await new Promise(r => setTimeout(r, i > 120 ? 5000 : 2500));
        }
        state.currentRunId = null;
        if (abortBtn) abortBtn.style.display = 'none';
        if (startBtn) { startBtn.disabled = false; delete startBtn.dataset.vesBusy; }
        setStatus(widget, 'El proceso sigue en curso. Puedes volver a intentarlo más tarde.', 'info');
    };

    document.addEventListener('change', (e) => {
        const field = e.target && e.target.closest ? e.target.closest('[name="platform"], [name="mode"], [name="sortPriority"], [name="limit"], [name="datePreset"], [name="dateFromIso"], [name="country"], [name="language"], [name="minViews"], [name^="reddit"], [name^="pinterest"], [name^="semrush"], [name^="moz"], [name^="ahrefs"]') : null;
        if (!field) return;
        const form = field.closest('.ves-form');
        const widget = field.closest('.ves-wrap');
        if (!form || !widget) return;
        const platform = form.querySelector('[name="platform"]')?.value || '';
        const cfg = CONFIG[platform] || {};
        const mode = form.querySelector('[name="mode"]')?.value || cfg.defaultMode || 'search';
        const scope = form.closest('.ves-page') || widget;
        renderActiveSettingsSummary(scope, form, cfg, mode);
        renderPlatformChecklist(scope, cfg, mode);
        renderRunPreflight(scope, form, cfg, mode);
        const previewHost = form.querySelector('[data-payload-preview-host]');
        if (previewHost) { previewHost.hidden = true; previewHost.innerHTML = ''; }
    });

    document.addEventListener('input', (e) => {
        const memorySearch = e.target && e.target.closest ? e.target.closest('.ves-memory-search') : null;
        if (!memorySearch) return;
        const widget = memorySearch.closest('.ves-wrap');
        const state = widgetState.get(widget);
        if (!state) return;
        state.memoryFilters = { ...(state.memoryFilters || {}), q: memorySearch.value || '' };
        renderMemoryListOnly(widget);
    });

    document.addEventListener('submit', (e) => {
        const evidenceFilters = e.target.closest ? e.target.closest('.ves-evidence-filters') : null;
        if (evidenceFilters) {
            e.preventDefault();
            const panel = evidenceFilters.closest('.ves-evidence-db-panel');
            if (panel) loadEvidenceList(panel, 1);
            return;
        }
        const authForm = e.target.closest ? e.target.closest('.ves-auth-form') : null;
        if (authForm) {
            const btn = authForm.querySelector('[data-ves-auth-submit]');
            if (btn) {
                btn.disabled = true;
                btn.dataset.originalText = btn.textContent || '';
                btn.textContent = 'Procesando…';
            }
        }
    });

    const normalizeSuggestionField = (fieldName) => String(fieldName || '')
        .replace(/[^a-zA-Z0-9]/g, '')
        .toLowerCase();

    const capSuggestion = (value) => {
        const v = String(value || '').replace(/\s+/g, ' ').trim();
        return v.length > 90 ? v.slice(0, 87) + '…' : v;
    };

    const dedupeSuggestions = (items) => {
        const seen = new Set();
        return (items || []).map(capSuggestion).filter((item) => {
            const key = item.toLowerCase();
            if (!item || seen.has(key)) return false;
            seen.add(key);
            return true;
        }).slice(0, 4);
    };

    // v0.9.25.0 — Phase 4: smart, brand/topic-aware suggestion chips.
    // Replaces static generic suggestions ("#marketing #socialmedia") that
    // were noisy and unrelated when the user typed a real brand like "mahou".
    //
    // The function now derives chips from the current query, platform and module.
    // If the query is empty we surface a few high-quality examples per module.
    // If the query has a topic/brand, we derive related terms from it instead
    // of falling back to generic hashtag spam.

    const BRAND_AWARE_PACKS = [
        // Pack: trigger keywords (lowercase substrings) -> { topic, hashtags, queries, audience, role }
        // Order matters: most specific first.
        { trigger: ['mahou', 'san miguel', 'cinco estrellas'],
          topic: ['mahou', 'mahou san miguel', 'cerveza mahou', 'mahou madrid', 'cinco estrellas'],
          hashtags: ['#mahou', '#cervezamahou', '#sanmiguel', '#mahousanmiguel', '#cervezaespañola'],
          queries: ['cerveza mahou', 'mahou madrid', 'cultura cervecera madrid', 'san isidro mahou', 'hostelería madrid cerveza'],
          audience: ['Bares y restaurantes en Madrid', 'Consumidores de cerveza en España', 'Eventos y fiestas locales'],
          role: ['Brand Manager cerveza', 'Trade Marketing on-trade', 'CMO bebidas'] },
        { trigger: ['estrella galicia', 'estrella damm', 'amstel', 'heineken', 'cruzcampo', 'alhambra', 'voll-damm', 'cerveza artesana', 'cerveza craft'],
          topic: ['cerveza artesana', 'craft beer', 'cerveza premium', 'cerveza de temporada'],
          hashtags: ['#cervezaartesana', '#craftbeerspain', '#cervezaespañola', '#beerlovers', '#cervezapremium'],
          queries: ['cerveza artesana España', 'craft beer Madrid', 'cerveza premium', 'fiestas cerveza'],
          audience: ['Aficionados craft beer', 'Foodies y restauración', 'Compradores premium en supermercado'],
          role: ['CMO bebidas', 'Brand Manager craft', 'Trade Marketing'] },
        { trigger: ['ai marketing', 'inteligencia artificial', 'marketing ia', 'marketing con ia', 'ai agency', 'agencia ia'],
          topic: ['marketing con inteligencia artificial', 'IA aplicada al marketing', 'automatización marketing', 'herramientas IA marketing'],
          hashtags: ['#aimarketing', '#marketingia', '#martech', '#aitools', '#growthmarketing'],
          queries: ['marketing con inteligencia artificial', 'IA aplicada al marketing', 'automatización marketing', 'agencias marketing IA', 'AI marketing Spain'],
          audience: ['CMO y heads of marketing', 'Agencias de marketing en España', 'Founders SaaS B2B'],
          role: ['CMO', 'Head of Marketing', 'Growth Lead', 'Marketing Director', 'Founder', 'AI Marketing Manager'] },
        { trigger: ['seo', 'semrush', 'ahrefs', 'keyword'],
          topic: ['SEO tools', 'herramientas SEO', 'estrategia SEO', 'SEO técnico'],
          hashtags: ['#seo', '#seospain', '#seotips', '#contentmarketing'],
          queries: ['herramientas seo', 'ai seo tools', 'estrategia SEO 2025', 'semrush alternatives'],
          audience: ['SEO Managers', 'Content strategists', 'Agencias digitales'],
          role: ['SEO Manager', 'Head of SEO', 'Content Strategist'] },
        { trigger: ['coche electrico', 'coche eléctrico', 'electric car', 'tesla', 'byd', 'vehiculo electrico'],
          topic: ['coches eléctricos', 'movilidad eléctrica', 'cargadores ev'],
          hashtags: ['#cocheselectricos', '#movilidadelectrica', '#ev', '#tesla'],
          queries: ['coches eléctricos España', 'cargadores coche eléctrico', 'subvención coche eléctrico'],
          audience: ['Compradores potenciales EV', 'Flotas empresariales', 'Concesionarios'],
          role: ['Marketing Director automoción', 'Brand Manager EV'] },
        { trigger: ['farmacia', 'parafarmacia', 'wellness', 'cosmetica', 'cosmética', 'skincare'],
          topic: ['farmacia digital', 'wellness España', 'skincare clean'],
          hashtags: ['#skincare', '#farmacia', '#wellness', '#bienestar'],
          queries: ['skincare España', 'parafarmacia online', 'rutina facial 2025'],
          audience: ['Consumidoras skincare', 'Profesionales sanitarios', 'Compradoras parafarmacia'],
          role: ['Brand Manager pharma', 'Marketing Director wellness'] }
    ];

    const detectBrandPack = (query, context) => {
        const haystack = ((query || '') + ' ' + (context || '')).toLowerCase();
        if (!haystack.trim()) return null;
        for (const pack of BRAND_AWARE_PACKS) {
            if (pack.trigger.some(t => haystack.includes(t))) return pack;
        }
        return null;
    };

    // Build smart suggestions per module/field/platform with the actual query in mind.
    const generateSmartSuggestions = ({ moduleName, platform, field, query, hashtags, country, language, objective }) => {
        const fieldKey = String(field || '').replace(/[^a-zA-Z0-9]/g, '').toLowerCase();
        const moduleKey = String(moduleName || '').toLowerCase();
        const platformKey = String(platform || '').toLowerCase();
        const q = String(query || '').trim();
        const ctx = String(objective || '');
        const pack = detectBrandPack(q || hashtags, ctx);

        // 1. Hashtag fields: never spam generic #marketing #socialmedia chips.
        if (/^(hashtag|hashtags|socialhashtag|instagramhashtag)$/.test(fieldKey)) {
            if (pack) return pack.hashtags.slice(0, 5);
            // No query, no pack — return nothing rather than misleading spam.
            // Empty list = chips stay collapsed, which is the right zero-state.
            return [];
        }

        // 2. Search query / scraper query / trend topic: derive from pack if any.
        if (/^(scraperquery|scraperqueryterms|queryterms|searchterms|tiktokquery|trendcategory|category|topic|seokeywordseed|seedkeyword|keywordseed|keyword)$/.test(fieldKey)) {
            if (pack) return (fieldKey.includes('keyword') ? pack.queries : pack.topic).slice(0, 5);
            if (q) {
                // No pack but user typed something — offer light variants.
                const lower = q.toLowerCase();
                const out = [];
                if (lower.length > 2) {
                    out.push(`${q} España`);
                    out.push(`${q} 2025`);
                    if (!lower.includes('marketing')) out.push(`${q} marketing`);
                }
                return out.slice(0, 3);
            }
            // Truly empty — small set of high-quality examples per module.
            if (moduleKey.includes('trend')) return ['ai marketing', 'cerveza artesana', 'coches eléctricos'];
            if (moduleKey.includes('seo') || moduleKey.includes('semrush')) return ['herramientas seo', 'ai seo tools', 'semrush alternatives'];
            return ['marketing con IA', 'creative testing', 'tendencias 2025'];
        }

        // 3. Profile URL / Post URL — keep the format examples (still useful).
        if (/^(profileurl|profileurls|targetprofiles|linkedinprofile)$/.test(fieldKey)) {
            if (platformKey.includes('linkedin')) return ['https://www.linkedin.com/company/company-name/'];
            if (platformKey.includes('tiktok'))   return ['https://www.tiktok.com/@brandname'];
            if (platformKey.includes('instagram'))return ['https://www.instagram.com/brandname/'];
            return ['https://www.instagram.com/brandname/', 'https://www.linkedin.com/company/company-name/'];
        }
        if (/^(posturl|posturls|targeturls|directurl)$/.test(fieldKey)) {
            if (platformKey.includes('linkedin')) return ['https://www.linkedin.com/posts/...'];
            if (platformKey.includes('tiktok'))   return ['https://www.tiktok.com/@user/video/VIDEO_ID'];
            if (platformKey.includes('instagram'))return ['https://www.instagram.com/p/POST_ID/'];
            return ['https://www.instagram.com/p/POST_ID/', 'https://www.tiktok.com/@user/video/VIDEO_ID'];
        }

        // 4. LinkedIn roles / personas.
        if (/^(linkedinrole|linkedinroles|seniority)$/.test(fieldKey)) {
            if (pack && pack.role) return pack.role.slice(0, 4);
            return ['CMO', 'Head of Marketing', 'Growth Lead', 'Marketing Director'];
        }
        if (/^(linkedincompanyfilter|companyfilter|companysize)$/.test(fieldKey)) {
            if (pack && pack.audience) return pack.audience.slice(0, 3);
            return ['SaaS companies, 11–200 employees', 'Marketing agencies in Spain'];
        }
        if (/^(targetaudience|audience)$/.test(fieldKey)) {
            if (pack && pack.audience) return pack.audience.slice(0, 4);
            return [];
        }

        // 5. Business objective / context / AI goal — keep short, generic examples
        // (these are open-ended prose, so noise here is low risk).
        if (/^(businessobjective|objective|campaigngoal)$/.test(fieldKey)) {
            if (pack) return [`Encontrar oportunidades para ${pack.topic[0]}.`, 'Detectar hooks y ángulos creativos.'];
            return ['Identificar oportunidades respaldadas por evidencia.', 'Encontrar ejemplos relevantes para campañas.'];
        }
        if (/^(searchcontext|context|background)$/.test(fieldKey)) {
            if (pack) return [`Mercado España, sector ${pack.topic[0]}.`, 'Foco en evidencia local y reciente.'];
            return [];
        }
        if (/^(aianalysisgoal|analysisgoal|analysisfocus)$/.test(fieldKey)) {
            return ['Separar evidencia de hipótesis', 'Detectar hooks ganadores', 'Recomendar próximos tests'];
        }
        if (/^(trendreason|reason|businessgoal|businessobjective)$/.test(fieldKey)) {
            return ['Validar si hay evidencia para un test brief.', 'Encontrar oportunidades respaldadas por fuentes.'];
        }
        if (/^(competitorlist|competitordomains|competitors)$/.test(fieldKey)) {
            if (pack && pack.trigger.some(t => /mahou|cerveza|estrella/i.test(t))) return ['estrelladamm.com', 'cruzcampo.com', 'alhambra.es'];
            return [];
        }

        return [];
    };

    const localInputSuggestions = (moduleName, fieldName, text) => {
        // v0.9.25.0 — Phase 4: route everything through generateSmartSuggestions
        // so chips always reflect query + platform context, not generic defaults.
        const value = String(text || '').trim();
        const fieldNorm = normalizeSuggestionField(fieldName);
        // Try to harvest hashtags and platform from the active form for context.
        let platform = '', hashtags = '', country = '', language = '', objective = '';
        try {
            const form = document.activeElement?.closest?.('form') || document.querySelector('form.ves-form');
            if (form) {
                platform = form.querySelector('[name="platform"]')?.value || '';
                hashtags = form.querySelector('[name="hashtags"]')?.value || '';
                country  = form.querySelector('[name="country"]')?.value || '';
                language = form.querySelector('[name="language"]')?.value || '';
                objective = form.querySelector('[name="businessObjective"]')?.value
                         || form.querySelector('[name="trendReason"]')?.value || '';
            }
        } catch (e) { /* harmless */ }
        const list = generateSmartSuggestions({
            moduleName, platform, field: fieldNorm, query: value, hashtags,
            country, language, objective
        });
        return dedupeSuggestions(list);
    };

    const renderInputSuggestions = (input) => {
        const wrap = input.closest('.ves-input-assist-wrap') || input.parentElement;
        if (!wrap) return;
        const box = wrap.querySelector('.ves-input-suggestions') || wrap.nextElementSibling;
        if (!box || !box.classList.contains('ves-input-suggestions')) return;
        const form = input.closest('form');
        const moduleName = form?.dataset?.module || input.closest('.ves-page')?.dataset?.page || '';
        const suggestions = localInputSuggestions(moduleName, input.dataset.fieldType || input.dataset.field || input.name || '', input.value);
        box.innerHTML = suggestions.map(x => `<button type="button" class="ves-suggestion-chip" data-suggestion="${escapeHtml(x)}">${escapeHtml(x)}</button>`).join('');
    };

    const syncModuleFormState = (widget) => {
        const activePage = widget?.querySelector?.('.ves-page.is-active') || widget;
        const seoForm = activePage?.querySelector?.('form[data-module="seo"]');
        if (seoForm) {
            const provider = String(seoForm.querySelector('[name="seoProvider"]')?.value || 'semrush').toLowerCase();
            const platform = provider.includes('ahrefs') ? 'ahrefs' : (provider.includes('moz') ? 'moz' : (provider.includes('google') ? 'semrush' : 'semrush'));
            const pInput = seoForm.querySelector('[name="platform"]'); if (pInput) pInput.value = platform;
            const country = seoForm.querySelector('[name="country"]')?.value || '';
            const lang = seoForm.querySelector('[name="language"]')?.value || '';
            const providerCountry = (country && country !== 'None') ? String(country).toLowerCase() : '';
            const providerLang = lang ? String(lang).toLowerCase() : '';
            ['semrushCountry','semrushSeoCountry'].forEach(n => { const el = seoForm.querySelector(`[name="${n}"]`); if (el && providerCountry) el.value = providerCountry; });
            const le = seoForm.querySelector('[name="semrushLanguage"]'); if (le && providerLang) le.value = providerLang;
        }
        const liForm = activePage?.querySelector?.('form[data-module="linkedin"]');
        if (liForm) {
            const objective = liForm.querySelector('[name="linkedinObjective"]')?.value || 'analyze_posts';
            liForm.querySelectorAll('[data-linkedin-panel], [data-linkedin-objective-panel]').forEach(panel => {
                const values = (panel.dataset.linkedinPanel || panel.dataset.linkedinObjectivePanel || '').split(/\s+/);
                panel.hidden = !values.includes(objective);
            });
            liForm.querySelectorAll('[data-linkedin-unsupported]').forEach(panel => { panel.hidden = panel.dataset.linkedinUnsupported !== objective; });
        }
    };

    document.addEventListener('click', async (e) => {
        const suggestionChip = e.target.closest('.ves-suggestion-chip');
        if (suggestionChip) {
            e.preventDefault();
            const wrap = suggestionChip.closest('.ves-input-assist-wrap') || suggestionChip.closest('.ves-field') || suggestionChip.parentElement;
            const input = wrap?.querySelector?.('.ves-ai-assisted') || wrap?.previousElementSibling?.querySelector?.('.ves-ai-assisted');
            if (input) { input.value = suggestionChip.dataset.suggestion || suggestionChip.textContent || ''; input.dispatchEvent(new Event('input', { bubbles: true })); }
            return;
        }
        const rerunSuggestion = e.target.closest('.ves-rerun-suggestion');
        if (rerunSuggestion) {
            e.preventDefault();
            const widget = rerunSuggestion.closest('.ves-wrap');
            const page = rerunSuggestion.closest('.ves-page') || widget?.querySelector('.ves-page.is-active') || widget;
            const form = page?.querySelector('form.ves-form') || widget?.querySelector('form.ves-form');
            if (form) {
                ['minViews','minLikes','minSearchVolume','maxDifficulty'].forEach((name) => { const el = form.querySelector(`[name="${name}"]`); if (el) el.value = name === 'maxDifficulty' ? '' : '0'; });
                const pool = form.querySelector('[name="candidatePoolSize"]'); if (pool && Number(pool.value || 0) < 50) pool.value = '50';
                const sort = form.querySelector('[name="sortPriority"], [name="rankingMode"]'); if (sort) sort.value = sort.querySelector('option[value="best_match"]') ? 'best_match' : (sort.querySelector('option[value="opportunity_score"]') ? 'opportunity_score' : sort.value);
                setStatus(widget, 'Recommended filters applied. Run again when ready.', 'info');
            }
            return;
        }

        // v0.9.31.5 BUG-01: manual Reintentar after a retryable failure.
        // Resets the auto-retry budget (user took action) and re-fires the
        // active page's start button to repeat the same dispatch.
        const retryRunBtn = e.target.closest('.ves-retry-run');
        if (retryRunBtn) {
            e.preventDefault();
            const widget = retryRunBtn.closest('.ves-wrap');
            if (!widget) return;
            vesClearAutoRetryTimer(widget);
            vesResetAutoRetryCount(widget);
            const startBtn = widget.querySelector('.ves-page.is-active .ves-start') || widget.querySelector('.ves-start');
            if (startBtn) startBtn.click();
            return;
        }

        // v0.9.31.5 DEBT-04: cancel a scheduled auto-retry countdown.
        // v0.9.31.6 BUG-D: clearing is delegated to vesClearAutoRetryTimer which
        // now owns both the timeout and the interval.
        const cancelRetryBtn = e.target.closest('.ves-cancel-retry');
        if (cancelRetryBtn) {
            e.preventDefault();
            const widget = cancelRetryBtn.closest('.ves-wrap');
            if (!widget) return;
            vesClearAutoRetryTimer(widget);
            setStatus(widget, 'Reintento automático cancelado. Pulsa Reintentar cuando estés listo.', 'info');
            return;
        }

        const aiRefineBtn = e.target.closest('.ves-ai-refine-button');
        if (aiRefineBtn) {
            e.preventDefault();
            const form = aiRefineBtn.closest('form');
            const widget = aiRefineBtn.closest('.ves-wrap');
            const fieldName = aiRefineBtn.dataset.target || aiRefineBtn.dataset.targetField || '';
            const input = form?.querySelector?.(`[name="${cssEscape(fieldName)}"]`) || aiRefineBtn.closest('.ves-input-assist-wrap')?.querySelector?.('.ves-ai-assisted');
            if (!input) return;
            const original = input.value || '';
            const moduleName = form?.dataset?.module || aiRefineBtn.closest('.ves-page')?.dataset?.page || '';
            aiRefineBtn.disabled = true; aiRefineBtn.dataset.originalText = aiRefineBtn.textContent || 'Mejorar'; aiRefineBtn.textContent = 'Mejorando...';
            try {
                const res = await postAjax(form?.dataset?.ajax || widget?.dataset?.ajax || '', withCurrentProjectPayload(form || widget, { action: 'ves_ai_refine_input', nonce: form?.querySelector?.('[name="nonce"]')?.value || widget?.querySelector?.('[name="nonce"]')?.value || '', module: moduleName, field: fieldName, fieldTipo: input.dataset.fieldType || fieldName, mode: form?.querySelector?.('[name="mode"]')?.value || '', text: original, platform: form?.querySelector?.('[name="platform"]')?.value || '' }));
                const improved = res.improved_text || res.text || '';
                if (improved) { input.dataset.previousValue = original; input.value = improved; input.dispatchEvent(new Event('input', { bubbles: true })); const wrap = input.closest('.ves-input-assist-wrap'); const box = wrap?.querySelector?.('.ves-input-suggestions'); if (box) box.innerHTML = `<button type="button" class="ves-suggestion-chip ves-undo-refine" data-suggestion="${escapeHtml(original)}">Undo refine</button>`; }
            } catch (err) { setStatus(widget, escapeHtml(safeUserMessage(err.message || 'Could not improve this input.', widget)), 'error'); }
            finally { aiRefineBtn.disabled = false; aiRefineBtn.textContent = aiRefineBtn.dataset.originalText || 'Mejorar'; }
            return;
        }
        const passwordToggle = e.target.closest('[data-ves-password-toggle]');
        if (passwordToggle) {
            e.preventDefault();
            const input = passwordToggle.closest('.ves-password-wrap')?.querySelector('input');
            if (input) {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                passwordToggle.textContent = show ? 'Ocultar' : 'Mostrar';
            }
            return;
        }

        const payloadPreviewBtn = e.target.closest('.ves-payload-preview-btn');
        if (payloadPreviewBtn) {
            e.preventDefault();
            const form = payloadPreviewBtn.closest('.ves-form');
            const host = form?.querySelector('[data-payload-preview-host]');
            if (!form || !host) return;
            const original = payloadPreviewBtn.textContent;
            payloadPreviewBtn.disabled = true;
            payloadPreviewBtn.textContent = 'Previewing…';
            host.hidden = false;
            host.innerHTML = '<div class="ves-hint">Building server-side payload preview…</div>';
            try {
                const data = await ajaxPayloadPreview(form);
                const notes = Array.isArray(data.readiness_notes) && data.readiness_notes.length
                    ? `<ul>${data.readiness_notes.map(n => `<li>${escapeHtml(n)}</li>`).join('')}</ul>`
                    : '<div class="ves-hint">No special readiness notes.</div>';
                const statusClass = data.valid ? 'ves-preview-ok' : 'ves-preview-error';
                const previewWrap = form.closest('.ves-wrap');
                const previewAdmin = isDebugMode(previewWrap) || isAdminUI(previewWrap);
                const adminPreviewDetails = previewAdmin
                    ? `<details open class="ves-source-input-details"><summary>Admin: provider input</summary><pre>${escapeHtml(JSON.stringify(data.actor_input || {}, null, 2).slice(0, 12000))}</pre></details><details class="ves-source-input-details"><summary>Admin: dispatch summary</summary><pre>${escapeHtml(JSON.stringify(data.summary || {}, null, 2).slice(0, 4000))}</pre></details>`
                    : '<div class="ves-empty-state compact">Payload preview internals are available to administrators only.</div>';
                const previewSourceLabel = previewAdmin ? (data.actor_slug || data.source_label || 'Admin provider route') : (data.source_label || 'Product-safe source route');
                host.innerHTML = `
                    <div class="ves-payload-preview-card ${statusClass}">
                        <div class="ves-payload-preview-head">
                            <strong>${data.valid ? 'Payload valid' : 'Payload blocked'}</strong>
                            <span>${escapeHtml(previewSourceLabel)}</span>
                        </div>
                        <div class="ves-hint">${escapeHtml(data.message || '')}</div>
                        ${notes}
                        ${adminPreviewDetails}
                    </div>`;
            } catch (err) {
                host.innerHTML = `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Could not preview payload.'))}</div>`;
            } finally {
                payloadPreviewBtn.disabled = false;
                payloadPreviewBtn.textContent = original;
            }
            return;
        }
        const brandResultTab = e.target.closest('.ves-brand-result-tab');
        if (brandResultTab) {
            e.preventDefault();
            const wrap = brandResultTab.closest('.ves-brand-audit-results');
            const key = brandResultTab.dataset.brandResultTab || '';
            if (!wrap || !key) return;
            wrap.querySelectorAll('.ves-brand-result-tab').forEach((btn) => {
                const active = btn === brandResultTab;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            wrap.querySelectorAll('.ves-brand-result-panel').forEach((panel) => {
                panel.classList.toggle('is-active', panel.dataset.brandResultPanel === key);
            });
            if (key === 'evidence') {
                const panel = wrap.querySelector('[data-brand-result-panel="evidence"] .ves-evidence-db-panel');
                if (panel && panel.dataset.loaded !== '1') {
                    panel.dataset.loaded = '1';
                    loadEvidenceList(panel, 1);
                }
            }
            if (key === 'opportunities') {
                const panel = wrap.querySelector('[data-brand-result-panel="opportunities"]');
                if (panel && panel.dataset.loadedFromEndpoint !== '1') {
                    loadOpportunityBoard(wrap);
                }
            }
            if (key === 'overview') {
                const host = wrap.querySelector('[data-ves-insight-records-host]');
                if (host && host.dataset.loadedFromEndpoint !== '1') {
                    loadInsightBoard(wrap);
                }
            }
            return;
        }

        const evidenceFilterBtn = e.target.closest('.ves-evidence-filters button');
        if (evidenceFilterBtn) {
            e.preventDefault();
            const panel = evidenceFilterBtn.closest('.ves-evidence-db-panel');
            if (panel) loadEvidenceList(panel, 1);
            return;
        }

        const evidencePageBtn = e.target.closest('.ves-evidence-page');
        if (evidencePageBtn) {
            e.preventDefault();
            const panel = evidencePageBtn.closest('.ves-evidence-db-panel');
            const page = Number(evidencePageBtn.dataset.page || 1);
            if (panel && page > 0) loadEvidenceList(panel, page);
            return;
        }

        const copyEvidenceRefBtn = e.target.closest('.ves-copy-evidence-ref');
        if (copyEvidenceRefBtn) {
            e.preventDefault();
            const ref = copyEvidenceRefBtn.dataset.evidenceRef || '';
            try {
                if (navigator.clipboard && ref) { await navigator.clipboard.writeText(ref); }
                copyEvidenceRefBtn.textContent = 'Copied';
                setTimeout(() => { copyEvidenceRefBtn.textContent = 'Copy ref'; }, 1400);
            } catch (err) {
                copyEvidenceRefBtn.textContent = ref || 'Copy failed';
            }
            return;
        }

        const rawEvidenceBtn = e.target.closest('.ves-evidence-load-raw');
        if (rawEvidenceBtn) {
            e.preventDefault();
            const widget = rawEvidenceBtn.closest('.ves-wrap');
            const state = widgetState.get(widget);
            const form = state?.form || widget?.querySelector('form');
            const host = rawEvidenceBtn.closest('.ves-evidence-card')?.querySelector('.ves-evidence-raw-host');
            if (!form || !host) return;
            host.innerHTML = '<div class="ves-hint">Loading raw evidence…</div>';
            try {
                const data = await ajaxEvidenceGet(form, rawEvidenceBtn.dataset.bundleId || '', rawEvidenceBtn.dataset.evidenceId || '');
                host.innerHTML = isAdminUI() ? `<details open class="ves-source-input-details"><summary>Debug: raw evidence</summary><pre>${escapeHtml(JSON.stringify(data.item || {}, null, 2).slice(0, 12000))}</pre></details>` : `<div class="ves-hint">Raw evidence view is available to administrators only.</div>`;
            } catch (err) {
                host.innerHTML = `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Could not load raw evidence.'))}</div>`;
            }
            return;
        }

        const oppStatusBtn = e.target.closest('.ves-opportunity-status-btn');
        if (oppStatusBtn) {
            e.preventDefault();
            const widget = oppStatusBtn.closest('.ves-wrap');
            const state = widgetState.get(widget);
            const form = state?.form || widget?.querySelector('form');
            const card = oppStatusBtn.closest('.ves-brand-opportunity-card');
            if (!form) return;
            oppStatusBtn.disabled = true;
            try {
                const data = await ajaxOpportunityUpdateStatus(form, { bundleId: oppStatusBtn.dataset.bundleId || '', opportunityId: oppStatusBtn.dataset.opportunityId || '', status: oppStatusBtn.dataset.status || '' });
                const status = data?.record?.status || oppStatusBtn.dataset.status || '';
                card?.querySelector('.ves-opportunity-card-head .ves-pill')?.replaceChildren(document.createTextNode(status));
                card?.classList.toggle('is-unsupported', data?.record?.evidence_validation_status === 'unsupported');
                const wrap = oppStatusBtn.closest('.ves-brand-audit-results');
                if (wrap) loadOpportunityBoard(wrap);
            } catch (err) {
                card?.insertAdjacentHTML('beforeend', `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Could not update opportunity status.'))}</div>`);
            } finally {
                oppStatusBtn.disabled = false;
            }
            return;
        }

        const insightStatusBtn = e.target.closest('.ves-insight-status-btn');
        if (insightStatusBtn) {
            e.preventDefault();
            const widget = insightStatusBtn.closest('.ves-wrap');
            const state = widgetState.get(widget);
            const form = state?.form || widget?.querySelector('form');
            const card = insightStatusBtn.closest('.ves-brand-opportunity-card');
            if (!form) return;
            insightStatusBtn.disabled = true;
            try {
                const data = await ajaxInsightUpdateStatus(form, { bundleId: insightStatusBtn.dataset.bundleId || '', insightId: insightStatusBtn.dataset.insightId || '', status: insightStatusBtn.dataset.status || '' });
                card?.querySelector('.ves-opportunity-card-head .ves-pill')?.replaceChildren(document.createTextNode(data?.record?.status || insightStatusBtn.dataset.status || ''));
                card?.insertAdjacentHTML('beforeend', `<div class="ves-hint">Insight status updated to ${escapeHtml(data?.record?.status || insightStatusBtn.dataset.status || '')}.</div>`);
            } catch (err) {
                card?.insertAdjacentHTML('beforeend', `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Could not update insight status.'))}</div>`);
            } finally {
                insightStatusBtn.disabled = false;
            }
            return;
        }

        const briefLibraryRefresh = e.target.closest('.ves-brief-library-refresh');
        if (briefLibraryRefresh) {
            e.preventDefault();
            const widget = briefLibraryRefresh.closest('.ves-wrap');
            if (widget) { widget.dataset.briefLibraryLoaded = '0'; maybeLoadBriefLibraryPage(widget, true); }
            return;
        }


        const trendEstimateBtn = e.target.closest('.ves-trend-estimate-btn');
        if (trendEstimateBtn) {
            e.preventDefault();
            const widget = trendEstimateBtn.closest('.ves-wrap');
            const form = trendEstimateBtn.closest('form') || widget?.querySelector('.ves-form[data-module="trend"]');
            const host = form?.querySelector('[data-ves-trend-estimate-host]');
            if (!form) return;
            const original = trendEstimateBtn.textContent;
            trendEstimateBtn.disabled = true;
            trendEstimateBtn.textContent = 'Estimating…';
            if (host) host.innerHTML = '<div class="ves-spinner"></div><div class="ves-hint">Estimando créditos de Trend Finder…</div>';
            try {
                const fd = new FormData(form);
                const payload = formDataToPayload(fd);
                payload.action_type = 'trend_run';
                const data = await ajaxUsageEstimate(form, payload);
                const estimate = data.estimate || {};
                const provider = estimate.provider_cost || {};
                const breakdown = Array.isArray(estimate.breakdown) ? estimate.breakdown.map(x => `<div><strong>${escapeHtml(x.label || x.event_type || '')}</strong>: ${escapeHtml(String(x.credits ?? 0))} credits${x.optional ? ' (optional)' : ''}</div>`).join('') : '';
                const adminDebug = isDebugMode(widget);
                const warning = provider.limit_exceeded
                    ? '<div class="ves-error-box">This run exceeds the configured resource allowance. Reduce sources/results or ask an administrator for approval.</div>'
                    : (provider.warning ? '<div class="ves-info-box">This run uses multiple external sources and may consume more credits.</div>' : '');
                const resourceLine = adminDebug
                    ? `<div class="ves-hint"><strong>Admin provider cost risk:</strong> $${escapeHtml(String(Number(provider.estimated_usd || 0).toFixed(2)))} · sources ${escapeHtml(String(provider.source_count || 0))} · heavy ${escapeHtml(String(provider.heavy_source_count || 0))}</div>`
                    : `<div class="ves-hint"><strong>Resource scope:</strong> ${escapeHtml(String(provider.source_count || 0))} planned source(s). May consume more credits.</div>`;
                if (host) host.innerHTML = `<div class="ves-credit-estimate-box"><strong>Créditos estimados de Trend Finder: ${escapeHtml(String(estimate.estimated_credits ?? '—'))}</strong>${breakdown}${resourceLine}${warning}<div class="ves-hint">${escapeHtml((estimate.notes || []).join(' '))}</div></div>`;
            } catch (err) {
                if (host) host.innerHTML = `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'No se pudo estimar el coste de Trend Finder.', widget))}</div>`;
            } finally {
                trendEstimateBtn.disabled = false;
                trendEstimateBtn.textContent = original;
            }
            return;
        }

        const estimateBtn = e.target.closest('.ves-brand-audit-estimate-btn');
        if (estimateBtn) {
            e.preventDefault();
            const widget = estimateBtn.closest('.ves-wrap');
            const state = widgetState.get(widget);
            const form = state?.form || estimateBtn.closest('form') || widget?.querySelector('form');
            if (!form) return;
            const host = form.querySelector('[data-ves-brand-audit-estimate-host]');
            const original = estimateBtn.textContent;
            estimateBtn.disabled = true;
            estimateBtn.classList.add('is-loading');
            estimateBtn.textContent = estimateBtn.dataset.loadingText || 'Estimando…';
            if (host) host.innerHTML = '<div class="ves-spinner"></div><div class="ves-hint">Estimando créditos…</div>';
            try {
                const fd = new FormData(form);
                const payload = formDataToPayload(fd);
                payload.action_type = 'brand_audit';
                payload.platforms = Array.from(form.querySelectorAll('[name$="Url"]')).filter(x => String(x.value || '').trim()).map(x => x.name.replace('Url','')).join(',');
                payload.competitorCount = String((form.querySelector('[name="brandCompetitors"], [name="competitors"]')?.value || '').split(/[\n,]+/).filter(Boolean).length);
                const data = await ajaxUsageEstimate(form, payload);
                const estimate = data.estimate || {};
                const preview = estimate.source_preview || {};
                const provider = estimate.provider_cost || {};
                const rows = Array.isArray(estimate.breakdown) ? estimate.breakdown.map(x => `<div><strong>${escapeHtml(x.label || x.event_type || '')}</strong>: ${escapeHtml(String(x.credits ?? 0))} credits${x.optional ? ' (optional)' : ''}</div>`).join('') : '';
                const adminDebug = isDebugMode(widget);
                const previewHtml = preview.source_count !== undefined
                    ? `<div class="ves-hint"><strong>Planned sources:</strong> ${escapeHtml(String(preview.source_count || 0))} total · ${escapeHtml(String(preview.heavy_source_count || 0))} heavy · ${escapeHtml(String(preview.competitor_expansion_count || 0))} competitor expansion · ${escapeHtml(String(preview.optional_source_count || 0))} optional</div>${adminDebug ? `<div class="ves-hint"><strong>Admin provider cost risk:</strong> $${escapeHtml(String(Number(preview.estimated_external_provider_cost || provider.estimated_usd || 0).toFixed(2)))}</div>` : `<div class="ves-hint"><strong>Resource scope:</strong> this run may consume more credits.</div>`}${preview.warning ? `<div class="ves-error-box">${escapeHtml(preview.warning)}</div>` : ''}`
                    : '';
                if (host) host.innerHTML = `<div class="ves-credit-estimate-box"><strong>Estimated credits: ${escapeHtml(String(estimate.estimated_credits ?? '—'))}</strong>${rows}${previewHtml}<div class="ves-hint">${escapeHtml((estimate.notes || []).join(' '))}</div></div>`;
            } catch (err) {
                if (host) host.innerHTML = `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Could not estimate usage.'))}</div>`;
            } finally {
                estimateBtn.disabled = false;
                estimateBtn.classList.remove('is-loading');
                estimateBtn.textContent = original;
            }
            return;
        }

        const generateBriefBtn = e.target.closest('.ves-generate-brief-btn');
        if (generateBriefBtn) {
            e.preventDefault();
            const widget = generateBriefBtn.closest('.ves-wrap');
            const wrap = generateBriefBtn.closest('.ves-brand-audit-results');
            const state = widgetState.get(widget);
            const form = state?.form || widget?.querySelector('form');
            const card = generateBriefBtn.closest('.ves-brand-opportunity-card');
            if (!form) return;
            generateBriefBtn.disabled = true;
            let generatedOk = false;
            const original = generateBriefBtn.textContent;
            generateBriefBtn.textContent = 'Generating brief…';
            try {
                const bundleId = generateBriefBtn.dataset.bundleId || wrap?.dataset.bundleId || '';
                const opportunityId = generateBriefBtn.dataset.opportunityId || card?.dataset.opportunityId || '';
                const estimate = await ajaxUsageEstimate(form, { action_type: 'brief_generation', bundleId, briefTipo: generateBriefBtn.dataset.briefType || 'campaign' }).catch(() => null);
                const data = await ajaxGenerateBriefFromOpportunity(form, { bundleId, opportunityId, briefTipo: generateBriefBtn.dataset.briefType || 'campaign', force_new: generateBriefBtn.dataset.forceNew || '' });
                const note = estimate?.estimate?.estimated_credits !== undefined ? ` Estimado: ${estimate.estimate.estimated_credits} créditos.` : '';
                card?.insertAdjacentHTML('beforeend', `<div class="ves-hint">${data?.existing ? 'Brief existente cargado.' : 'Brief creado.'}${escapeHtml(note)}</div>`);
                generateBriefBtn.textContent = data?.existing ? 'Brief existente cargado' : 'Brief creado';
                if (wrap) loadBriefRecords(wrap);
                if (wrap) loadWorkflowEvents(wrap);
                if (data?.brief?.id) generateBriefBtn.dataset.briefId = String(data.brief.id);
                generatedOk = true;
            } catch (err) {
                card?.insertAdjacentHTML('beforeend', `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'No se pudo generar el brief.'))}</div>`);
                generateBriefBtn.textContent = original || 'Generar brief';
            } finally {
                generateBriefBtn.disabled = generatedOk;
            }
            return;
        }

        const briefStatusBtn = e.target.closest('.ves-brief-status-btn');
        if (briefStatusBtn) {
            e.preventDefault();
            const widget = briefStatusBtn.closest('.ves-wrap');
            const wrap = briefStatusBtn.closest('.ves-brand-audit-results');
            const state = widgetState.get(widget);
            const form = state?.form || widget?.querySelector('form');
            const card = briefStatusBtn.closest('.ves-brief-card');
            if (!form) return;
            briefStatusBtn.disabled = true;
            try {
                const data = await ajaxBriefUpdateStatus(form, { bundleId: briefStatusBtn.dataset.bundleId || wrap?.dataset.bundleId || '', briefId: briefStatusBtn.dataset.briefId || card?.dataset.briefId || '', status: briefStatusBtn.dataset.status || '' });
                card?.querySelector('.ves-opportunity-card-head .ves-pill')?.replaceChildren(document.createTextNode(data?.record?.status || briefStatusBtn.dataset.status || ''));
                if (wrap) loadWorkflowEvents(wrap);
            } catch (err) {
                card?.insertAdjacentHTML('beforeend', `<div class="ves-error-box">${escapeHtml(safeUserMessage(err.message || 'Could not update brief status.'))}</div>`);
            } finally {
                briefStatusBtn.disabled = false;
            }
            return;
        }

        const copyBriefBtn = e.target.closest('.ves-copy-brief-btn');
        if (copyBriefBtn) {
            e.preventDefault();
            const card = copyBriefBtn.closest('.ves-brief-card');
            const text = card ? card.innerText : '';
            try {
                if (navigator.clipboard && text) await navigator.clipboard.writeText(text);
                copyBriefBtn.textContent = 'Copied';
                setTimeout(() => { copyBriefBtn.textContent = 'Copy brief'; }, 1400);
            } catch (err) {
                copyBriefBtn.textContent = 'Copy failed';
            }
            return;
        }

        const assistantBtn = e.target.closest('[data-ves-assistant-question], .ves-assistant-submit');
        if (assistantBtn) {
            e.preventDefault();
            await askAssistantFromButton(assistantBtn);
            return;
        }

        const refreshUsageBtn = e.target.closest('.ves-refresh-usage');
        if (refreshUsageBtn) {
            e.preventDefault();
            const widget = document.querySelector('.ves-wrap');
            if (widget) refreshUsageSummary(widget);
            return;
        }

        const memoryRefresh = e.target.closest('.ves-memory-refresh');
        if (memoryRefresh) {
            e.preventDefault();
            const widget = memoryRefresh.closest('.ves-wrap');
            if (!widget) return;
            widget.dataset.memoryLoaded = '0';
            await maybeLoadMemoryPage(widget, true);
            return;
        }

        const memoryTab = e.target.closest('.ves-memory-tab');
        if (memoryTab) {
            e.preventDefault();
            const widget = memoryTab.closest('.ves-wrap');
            const state = widgetState.get(widget);
            if (!state) return;
            state.memoryFilters = { ...(state.memoryFilters || {}), entry_type: memoryTab.dataset.memoryType || 'all' };
            renderMemoryExplorer(widget);
            return;
        }

        const memoryEntry = e.target.closest('.ves-memory-entry');
        if (memoryEntry) {
            e.preventDefault();
            const widget = memoryEntry.closest('.ves-wrap');
            if (!widget) return;
            if (widget.dataset.activePage !== 'memory') switchPage(widget, 'memory');
            await renderMemoryDetail(widget, memoryEntry.dataset.memoryId || 0);
            return;
        }

        const memoryReloadEntry = e.target.closest('.ves-memory-refresh-current');
        if (memoryReloadEntry) {
            e.preventDefault();
            const widget = memoryReloadEntry.closest('.ves-wrap');
            await renderMemoryDetail(widget, memoryReloadEntry.dataset.memoryId || 0);
            return;
        }

        const memoryGoogleAi = e.target.closest('.ves-memory-analyze-google');
        if (memoryGoogleAi) {
            e.preventDefault();
            const widget = memoryGoogleAi.closest('.ves-wrap');
            const state = widgetState.get(widget);
            const activePage = memoryGoogleAi.closest('.ves-page') || widget.querySelector('.ves-page.is-active') || widget;
            const host = activePage.querySelector('.ves-google-analysis-host') || activePage.querySelector('.ves-panel-host') || activePage.querySelector('.ves-results');
            const items = mapGoogleMemoryItemsForAi(state?.memoryGoogleItems || []);
            if (!items.length) {
                if (host) host.innerHTML = renderAnalysisPanel('No hay resultados guardados para analizar.', '');
                return;
            }
            memoryGoogleAi.disabled = true;
            if (host) host.innerHTML = renderAnalysisPanel('Generando análisis de resultados guardados…', '');
            try {
                const res = await ajaxAnalyzeBatch(state.form, 'google_intel', items, 'Analiza estos resultados guardados desde Memory.');
                if (host) host.innerHTML = renderAnalysisPanel(res.analysis, res.model, res);
                refreshUsageSummary(widget);
            } catch (err) {
                if (host) host.innerHTML = renderAnalysisPanel(safeUserMessage(err.message || 'Error al analizar resultados guardados.', widget), '');
            } finally {
                memoryGoogleAi.disabled = false;
            }
            return;
        }

        const brandAuditRefresh = e.target.closest('.ves-brand-audit-refresh-active');
        if (brandAuditRefresh) {
            e.preventDefault();
            const widget = brandAuditRefresh.closest('.ves-wrap');
            if (widget) await checkBrandAuditActiveRuns(widget, false);
            return;
        }

        const brandAuditResume = e.target.closest('.ves-brand-audit-resume');
        if (brandAuditResume) {
            e.preventDefault();
            const widget = brandAuditResume.closest('.ves-wrap');
            const bundleId = brandAuditResume.dataset.bundleId || '';
            if (widget && bundleId) await pollBrandAuditRun(widget, bundleId, { resumed: true });
            return;
        }
        const startBtn = e.target.closest('.ves-start');
        if (startBtn) {
            e.preventDefault();
            if (startBtn.dataset.vesBusy === '1') { return; }
            const widget = startBtn.closest('.ves-wrap');
            // v0.9.31.5 DEBT-04: manual click resets the auto-retry budget.
            // Synthetic clicks triggered by the auto-retry timer set the
            // `_vesAutoRetryFiring` flag to skip this reset.
            if (widget && !widget._vesAutoRetryFiring) {
                vesResetAutoRetryCount(widget);
                vesClearAutoRetryTimer(widget);
            }
            syncModuleFormState(widget);
            // v0.9.9.2: scope to active page so multi-form widgets work.
            const activePage = widget.querySelector('.ves-page.is-active') || widget;
            if (isProductionMvpWidget(widget) && activePage?.dataset?.mvpDisabled === '1') {
                setStatus(widget, MVP_DISABLED_MESSAGE, 'info');
                return;
            }
            const form = activePage.querySelector('.ves-form') || widget.querySelector('.ves-form');
            if (form && form.dataset.module === 'linkedin') {
                const objective = form.querySelector('[name="linkedinObjective"]')?.value || '';
                const supported = ['analyze_posts', 'thought_leadership_topic_scan'];
                if (!supported.includes(objective)) {
                    setStatus(widget, 'Este objetivo de LinkedIn requiere una fuente compatible de personas/empresas. Elige Analizar posts o pide a un administrador que configure la fuente adecuada.', 'info');
                    return;
                }
            }
            const platform = form?.querySelector('[name="platform"]')?.value || '';
            if (isProductionMvpWidget(widget) && MVP_DISABLED_PLATFORMS.has(platform)) {
                setStatus(widget, MVP_DISABLED_MESSAGE, 'info');
                return;
            }
            const resultsBox = activePage.querySelector('.ves-results') || widget.querySelector('.ves-results');
            if (platform === 'trend_finder') {
                const topic = String(form.querySelector('[name="trendCategory"]')?.value || '').trim();
                if (!topic) {
                    setStatus(widget, 'Introduce una categoría o tema antes de ejecutar.', 'error');
                    return;
                }
                try {
                    const estimatePayload = formDataToPayload(new FormData(form));
                    estimatePayload.action_type = 'trend_run';
                    const estimateData = await ajaxUsageEstimate(form, estimatePayload);
                    const provider = estimateData?.estimate?.provider_cost || {};
                    const hiddenOverride = form.querySelector('[data-allow-provider-over-budget]');
                    if ((provider.limit_exceeded || provider.warning) && hiddenOverride && hiddenOverride.value !== '1') {
                        const adminDebug = isDebugMode(widget);
                        const cost = Number(provider.estimated_usd || 0).toFixed(2);
                        const message = adminDebug
                            ? (provider.limit_exceeded
                                ? `Admin notice: this run exceeds configured provider budget (~$${cost}). Continue?`
                                : `Admin notice: this run may use higher provider cost (~$${cost}). Continue?`)
                            : 'This run uses multiple external sources and may consume more credits. Continue?';
                        if (!window.confirm(message)) {
                            setStatus(widget, 'Trend Finder no se inició. Reduce el alcance de fuentes o vuelve a estimar.', 'info');
                            return;
                        }
                        hiddenOverride.value = '1';
                    }
                } catch (preErr) {
                    VES_WARN('trend preflight failed', preErr);
                }
                startBtn.disabled = true; startBtn.dataset.vesBusy = '1';
                if (resultsBox) {
                    resultsBox.classList.remove('show');
                    resultsBox.innerHTML = '';
                }
                try {
                    setStatus(widget, '<strong>Iniciando Trend Finder…</strong>', 'info', true);
                    const data = await ajaxTrendStart(form);
                    refreshUsageSummary(widget);
                    if (data.resource_notice && data.resource_notice.warning) {
                        setStatus(widget, externalResourceWarningMessage(data.resource_notice.source_count || data.resource_notice.sources || 0), 'info', true);
                    } else if (isDebugMode(widget) && data.provider_cost && data.provider_cost.warning) {
                        const cost = Number(data.provider_cost.estimated_usd || 0).toFixed(2);
                        const fuentes = Number(data.provider_cost.source_count || 0);
                        setStatus(widget, `⚠️ <strong>Admin provider cost notice</strong> — estimated provider cost ~$${escapeHtml(cost)} across ${escapeHtml(String(fuentes))} source(s).`, 'info', true);
                    }
                    if (data.bundle_id) {
                        await pollTrendRun(widget, data.bundle_id);
                    } else {
                        throw new Error('No se recibió identificador de proceso.');
                    }
                } catch (err) {
                    VES_WARN('trend start failed', err);
                    setStatus(widget, vesErrorStatusHtml('Ejecución no iniciada', err, widget), 'error', true);
                    startBtn.disabled = false; delete startBtn.dataset.vesBusy;
                }
                return;
            }
            if (platform === 'brand_audit') {
                const brandName = String(form.querySelector('[name="brandName"]')?.value || '').trim();
                if (!brandName) {
                    setStatus(widget, 'Introduce el nombre de la marca antes de ejecutar.', 'error');
                    return;
                }
                const urlWarnings = validateBrandAuditPlatformUrls(form);
                if (urlWarnings.length) {
                    setStatus(widget, `Corrige las URLs antes de ejecutar:<br>${urlWarnings.map(w => `• ${escapeHtml(w)}`).join('<br>')}`, 'error');
                    return;
                }
                startBtn.disabled = true; startBtn.dataset.vesBusy = '1';
                if (resultsBox) {
                    resultsBox.classList.remove('show');
                    resultsBox.innerHTML = '';
                }
                try {
                    setStatus(widget, '<strong>Iniciando Brand Deep Audit…</strong>', 'info', true);
                    const data = await ajaxBrandAuditStart(form);
                    refreshUsageSummary(widget);
                    if (data.resource_notice && data.resource_notice.warning) {
                        setStatus(widget, externalResourceWarningMessage(data.resource_notice.source_count || data.resource_notice.sources || 0), 'info', true);
                    } else if (isDebugMode(widget) && data.provider_cost && data.provider_cost.warning) {
                        const cost = Number(data.provider_cost.estimated_usd || 0).toFixed(2);
                        const fuentes = Number(data.provider_cost.source_count || 0);
                        setStatus(widget, `⚠️ <strong>Admin provider cost notice</strong> — estimated provider cost ~$${escapeHtml(cost)} across ${escapeHtml(String(fuentes))} source(s).`, 'info', true);
                    }
                    if (data.bundle_id) {
                        saveActiveBrandAuditRun(data.bundle_id, data);
                        await pollBrandAuditRun(widget, data.bundle_id);
                    } else {
                        throw new Error('No se recibió identificador de Brand Audit.');
                    }
                } catch (err) {
                    VES_WARN('brand audit start failed', err);
                    setStatus(widget, vesErrorStatusHtml('Brand Deep Audit', err, widget), 'error');
                    startBtn.disabled = false; delete startBtn.dataset.vesBusy;
                }
                return;
            }
            if (platform === 'google_intel') {
                const moduleKey = currentGoogleModule(form);
                if (isProductionMvpWidget(widget) && MVP_DISABLED_GOOGLE_MODULES.has(moduleKey)) {
                    setStatus(widget, MVP_DISABLED_MESSAGE, 'info');
                    return;
                }
                const activePanel = form.querySelector(`[data-google-module-panel="${cssEscape(moduleKey)}"]`);
                const requiredEmpty = activePanel ? Array.from(activePanel.querySelectorAll('[required]:not([disabled])')).find((el) => !String(el.value || '').trim()) : null;
                if (requiredEmpty) {
                    setStatus(widget, 'Completa los campos obligatorios del módulo Google seleccionado.', 'error');
                    requiredEmpty.focus();
                    return;
                }
                startBtn.disabled = true; startBtn.dataset.vesBusy = '1';
                if (resultsBox) { resultsBox.classList.remove('show'); resultsBox.innerHTML = ''; }
                try {
                    setStatus(widget, '<strong>Iniciando Google Intelligence…</strong>', 'info', true);
                    const data = await ajaxGoogleStart(form);
                    refreshUsageSummary(widget);
                    if (['CONFIG_REQUIRED','NO_EVIDENCE','HONEST_DIAGNOSTIC'].includes(data.status)) {
                        setStatus(widget, vesErrorStatusHtml('Google Intelligence Basic', data, widget), data.retryable ? 'warning' : 'info');
                        renderGoogleResults(widget, data);
                        startBtn.disabled = false; delete startBtn.dataset.vesBusy;
                    } else if (data.status === 'SUCCEEDED') {
                        const count = Number(data.all_items_count ?? data.item_count_preview) || 0;
                        if (count === 0 && data.status_message) {
                            setStatus(widget, `ℹ️ <strong>No hay señales utilizables.</strong><div style="margin-top:6px;font-size:12px;opacity:.9">${escapeHtml(safeUserMessage(String(data.status_message), widget).slice(0, 300))}</div>`, 'info');
                        } else {
                            setStatus(widget, `✅ <strong>Google Intelligence completado</strong> — ${count} resultados.`, 'success');
                        }
                        renderGoogleResults(widget, data);
                        startBtn.disabled = false; delete startBtn.dataset.vesBusy;
                    } else if (['FAILED','TIMED-OUT','TIMEOUT','ABORTED'].includes(data.status)) {
                        const detail = data.status_message ? `<div style="margin-top:6px;font-size:12px;opacity:.9">${escapeHtml(String(data.status_message).slice(0, 600))}</div>` : '';
                        setStatus(widget, `⚠️ Google Intelligence terminó con estado <strong>${escapeHtml(data.status)}</strong>.${detail}`, 'error');
                        renderGoogleResults(widget, data);
                        startBtn.disabled = false; delete startBtn.dataset.vesBusy;
                    } else if (data.run_id) {
                        await pollGoogleRun(widget, data.run_id, moduleKey);
                    } else {
                        throw new Error('No se recibió identificador de proceso.');
                    }
                } catch (err) {
                    VES_WARN('google start failed', err);
                    setStatus(widget, vesErrorStatusHtml('Google Intelligence Basic', err, widget), 'error');
                    startBtn.disabled = false; delete startBtn.dataset.vesBusy;
                }
                return;
            }

            const validationError = validateStandardRunInput(form, platform, CONFIG[platform] || {});
            if (validationError) {
                setStatus(widget, validationError, 'error');
                highlightValidationTarget(form, platform, validationError);
                return;
            }
            setStatus(widget, clientDispatchPlanHtml(form, platform), 'info', true);
            startBtn.disabled = true; startBtn.dataset.vesBusy = '1';
            if (resultsBox) {
                resultsBox.classList.remove('show');
                resultsBox.innerHTML = '';
            }
            try {
                setStatus(widget, '<strong>Iniciando extracción…</strong>', 'info', true);
                const data = await ajaxStart(form);
                refreshUsageSummary(widget);

                // v0.9.24.4 continuation: helper that handles one start() response
                // and re-fires another run if the server signals needs_more_results.
                const handleStartData = async (d, runNum = 1) => {
                    if (d.status === 'SUCCEEDED') {
                        const count = Number(d.all_items_count ?? d.item_count_preview) || 0;
                        // If server says it needs more results (minViews shortfall) and
                        // we haven't exceeded 4 retry attempts, fire another run.
                        if (d.needs_more_results && d.continuation_token && runNum < 4) {
                            const accum = Math.max(0, parseInt(d.accumulated_count, 10) || count);
                            const requested = Number(form.querySelector('[name="limit"]')?.value) || 20;
                            setStatus(widget, `↻ <strong>Buscando más resultados…</strong> ${accum}/${requested} encontrados hasta ahora (intento ${runNum + 1}/4). El filtro de mínimo views requiere más extracción.`, 'info', true);
                            // Show what we have so far while we wait for the next batch.
                            if (count > 0) { try { renderResults(widget, d); } catch (_) {} }
                            // Inject continuation token and re-submit.
                            const fd2 = new FormData(form);
                            appendCurrentProjectToFormData(form, fd2);
                            fd2.append('action', 'ves_start');
                            fd2.set('continuationToken', d.continuation_token);
                            let d2;
                            try { d2 = await postAjax(form.dataset.ajax, formDataToPayload(fd2)); }
                            catch (retryErr) {
                                // Retry network error — show what we accumulated so far.
                                if (count > 0) {
                                    setStatus(widget, `✅ <strong>Extracción parcial</strong> — ${count} resultados (no se pudo buscar más: ${escapeHtml(safeUserMessage(retryErr.message || 'error de red', widget))}).`, 'info');
                                } else {
                                    throw retryErr;
                                }
                                startBtn.disabled = false; delete startBtn.dataset.vesBusy;
                                return;
                            }
                            return handleStartData(d2, runNum + 1);
                        }
                        // Normal completion (enough results or max retries reached).
                        if (count === 0 && d.status_message) {
                            const rawSeen = Number(d.raw_provider_returned_count || d.fetched_dataset_count || d.raw_items || d.raw_item_count || 0) || 0;
                            const socMsg = rawSeen > 0
                                ? 'La fuente devolvió resultados, pero ninguno pasó los filtros de relevancia, idioma, mercado o calidad.'
                                : 'La fuente no devolvió resultados para esta consulta.';
                            const socAdmin = `<div style="margin-top:6px;font-size:12px;opacity:.9">${escapeHtml(socMsg)}</div>`;
                            setStatus(widget, `ℹ️ <strong>No hay señales utilizables.</strong>${socAdmin}`, 'info');
                        } else {
                            const retryNote = runNum > 1 ? ` <span style="font-size:11px;opacity:.7">(${runNum} búsquedas)</span>` : '';
                            setStatus(widget, `✅ <strong>Extracción completada</strong> — ${count} resultados.${retryNote}`, 'success');
                        }
                        try { renderResults(widget, d); }
                        catch (renderErr) {
                            VES_WARN('render results failed after start', renderErr);
                            setStatus(widget, '❌ Error al pintar resultados: ' + escapeHtml(safeUserMessage(renderErr.message || 'Error de renderizado.', widget)), 'error');
                        }
                        startBtn.disabled = false; delete startBtn.dataset.vesBusy;
                    } else if (['FAILED','TIMED-OUT','TIMEOUT','ABORTED'].includes(d.status)) {
                        const detail = d.status_message ? ` <div style="margin-top:6px;font-size:11.5px;opacity:.85">Detalle: ${escapeHtml(safeUserMessage(String(d.status_message), widget).slice(0, 500))}</div>` : '';
                        setStatus(widget, `⚠️ El proceso terminó con estado <strong>${escapeHtml(d.status)}</strong>.${detail}`, 'error');
                        startBtn.disabled = false; delete startBtn.dataset.vesBusy;
                    } else if (d.run_id) {
                        await pollRun(widget, d.platform, d.run_id);
                    } else {
                        throw new Error('No se recibió identificador de proceso.');
                    }
                };
                await handleStartData(data);
            } catch (err) {
                VES_WARN('start failed', err);
                // v0.9.31.6 BUG-E: route start/dispatch failures through the same
                // helper as polling so a retryable transient error here also
                // shows the Reintentar button. The auto-retry budget is not
                // applied at the dispatch layer because the run has not started
                // yet — the user is in control.
                // Hotfix: a run can complete and render results, then a secondary
                // step throws (e.g. a render TypeError). In that case results are
                // already on screen, so do NOT overwrite the completed status with
                // a misleading "Ejecución no iniciada" banner — keep the result and
                // re-enable the controls. Only show the error when nothing rendered.
                let __vesResultsShown = false;
                try {
                    const __ap = widget.querySelector('.ves-page.is-active') || widget;
                    const __box = __ap.querySelector('.ves-results') || widget.querySelector('.ves-results');
                    __vesResultsShown = !!(__box && (__box.children.length > 0 || (__box.textContent || '').trim() !== ''));
                } catch (_) {}
                if (!__vesResultsShown) {
                    setStatus(widget, vesErrorStatusHtml('Ejecución no iniciada', err, widget), 'error', true);
                }
                startBtn.disabled = false; delete startBtn.dataset.vesBusy;
            }
            return;
        }

        const retryBtn = e.target.closest('[data-ves-retry]');
        if (retryBtn) {
            e.preventDefault();
            const wrap = retryBtn.closest('.ves-wrap') || document;
            const retryState = widgetState.get(wrap);
            const form = retryState?.form || wrap.querySelector('form[data-platform="trend_finder"], form.ves-form');
            if (form && retryBtn.dataset.vesRetry === 'trend_reduced_scope') {
                form.dataset.vesReducedScope = '1';
                const budget = form.querySelector('[name="sourceBudgetLevel"]'); if (budget) budget.value = 'low_cost';
                const fuentes = form.querySelector('[name="trendFuentes"]'); if (fuentes) fuentes.value = 'search_only';
                const pool = form.querySelector('[name="candidatePoolSize"]'); if (pool) pool.value = '15';
                const desired = form.querySelector('[name="desiredFinalTrends"]'); if (desired) desired.value = '3';
                let maxSources = form.querySelector('[name="maxSources"]');
                if (!maxSources) { maxSources = document.createElement('input'); maxSources.type = 'hidden'; maxSources.name = 'maxSources'; form.appendChild(maxSources); }
                maxSources.value = '2';
            } else if (form) {
                delete form.dataset.vesReducedScope;
            }
            const btn = form ? form.querySelector('button[type="submit"], .ves-start') : null;
            if (btn) { btn.click(); }
            return;
        }
        const diagBtn = e.target.closest('[data-ves-diagnostic="show"]');
        if (diagBtn) {
            e.preventDefault();
            const details = diagBtn.closest('.ves-result-card, .ves-status, .ves-wrap')?.querySelector('.ves-diagnostic-details, details');
            if (details) { details.open = true; details.scrollIntoView({ behavior:'smooth', block:'nearest' }); }
            return;
        }

        const abortBtn = e.target.closest('.ves-abort');
        if (abortBtn) {
            e.preventDefault();
            const widget = abortBtn.closest('.ves-wrap');
            const state = widgetState.get(widget);
            if (!state?.currentRunId) return;
            abortBtn.disabled = true;
            try { if (state.currentRunType === 'trend') { await ajaxTrendAbort(state.form, state.currentRunId); } else if (state.currentRunType === 'brand_audit') { await ajaxBrandAuditAbort(state.form, state.currentRunId); } else { await ajaxAbort(state.form, state.currentRunId); } setStatus(widget, 'Cancelación enviada.', 'info'); }
            catch (err) { setStatus(widget, '❌ ' + escapeHtml(safeUserMessage(err.message || 'Error al cancelar.', widget)), 'error'); }
            finally { abortBtn.disabled = false; }
            return;
        }

        const googleAiBtn = e.target.closest('.ves-google-ai');
        if (googleAiBtn) {
            e.preventDefault();
            const widget = googleAiBtn.closest('.ves-wrap');
            const state = widgetState.get(widget);
            const data = state?.lastData || {};
            const activePage = googleAiBtn.closest('.ves-page') || widget.querySelector('.ves-page.is-active') || widget;
            const host = activePage.querySelector('.ves-google-analysis-host') || activePage.querySelector('.ves-panel-host');
            const form = state?.form || activePage.querySelector('.ves-form');
            const runId = data.run_id || '';
            const moduleKey = data.module || currentGoogleModule(form);
            if (!runId) {
                setStatus(widget, 'No hay run_id para analizar. Ejecuta primero Google Intelligence.', 'error');
                return;
            }
            const mode = activePage.querySelector('.ves-google-analysis-mode')?.value || form?.querySelector('[name="analysisMode"]')?.value || 'summary';
            const focus = activePage.querySelector('.ves-google-focus-inline')?.value || form?.querySelector('[name="googleFocus"]')?.value || '';
            googleAiBtn.disabled = true;
            if (host) host.innerHTML = renderAnalysisPanel('Generando análisis de Google Intelligence…', '');
            try {
                const res = await ajaxGoogleAnalyze(form, runId, moduleKey, mode, focus);
                if (host) host.innerHTML = renderAnalysisPanel(res.analysis, res.model, res);
                refreshUsageSummary(widget);
                setStatus(widget, '✅ <strong>Análisis de Google Intelligence listo</strong>.', 'success');
            } catch (err) {
                if (host) host.innerHTML = renderAnalysisPanel(safeUserMessage(err.message || 'Error al analizar Google Intelligence.', widget), '');
                setStatus(widget, '❌ ' + escapeHtml(safeUserMessage(err.message || 'Error al analizar.', widget)), 'error');
            } finally {
                googleAiBtn.disabled = false;
            }
            return;
        }

        const selectItem = e.target.closest('.ves-select-item');
        if (selectItem) {
            const widget = selectItem.closest('.ves-wrap');
            const state = widgetState.get(widget);
            if (!state) return;
            const key = String(selectItem.dataset.key || '');
            const selected = new Set(state.selectedItemKeys || []);
            if (selectItem.checked) selected.add(key); else selected.delete(key);
            state.selectedItemKeys = Array.from(selected).filter(Boolean).slice(0, 12);
            updateSelectionControls(widget);
            return;
        }

        const clearSelectionBtn = e.target.closest('.ves-clear-selection');
        if (clearSelectionBtn) {
            e.preventDefault();
            const widget = clearSelectionBtn.closest('.ves-wrap');
            const state = widgetState.get(widget);
            if (!state) return;
            state.selectedItemKeys = [];
            const activePage = clearSelectionBtn.closest('.ves-page') || widget.querySelector('.ves-page.is-active') || widget;
            activePage.querySelectorAll('.ves-select-item').forEach((el) => { el.checked = false; });
            updateSelectionControls(widget);
            return;
        }

        const analyzeSelectedBtn = e.target.closest('.ves-analyze-selected, .ves-compare-selected, .ves-brief-selected');
        if (analyzeSelectedBtn) {
            e.preventDefault();
            const widget = analyzeSelectedBtn.closest('.ves-wrap');
            const state = widgetState.get(widget);
            if (!state?.lastData) return;
            const activePage = analyzeSelectedBtn.closest('.ves-page') || widget.querySelector('.ves-page.is-active') || widget;
            const panelHost = activePage.querySelector('.ves-panel-host') || widget.querySelector('.ves-panel-host');
            const baseFocusPrompt = activePage.querySelector('.ves-analysis-focus')?.value || widget.querySelector('.ves-analysis-focus')?.value || '';
            const focusPrompt = analyzeSelectedBtn.classList.contains('ves-brief-selected') ? ('Generate a practical brief from this selection. ' + baseFocusPrompt).trim() : (analyzeSelectedBtn.classList.contains('ves-compare-selected') ? ('Compare the selected evidence side by side. ' + baseFocusPrompt).trim() : baseFocusPrompt);
            const selected = new Set(state.selectedItemKeys || []);
            const selectedItems = (state.filteredItems || []).filter((item) => selected.has(itemSelectionKey(state.lastData.platform, item))).slice(0, 12);
            if (!selectedItems.length) {
                if (panelHost) panelHost.innerHTML = renderAnalysisPanel('Selecciona al menos un resultado para analizar.', '');
                return;
            }
            analyzeSelectedBtn.disabled = true;
            if (panelHost) panelHost.innerHTML = renderAnalysisPanel(uiText(widget, 'generatingBatch', { n: selectedItems.length }), '');
            try {
                const payloads = selectedItems.map((item) => buildAnalysisPayload(state.lastData.platform, normalizeItem(state.lastData.platform, item), item));
                const res = await ajaxAnalyzeBatch(state.form, state.lastData.platform, payloads, focusPrompt);
                state.panelHtml = renderAnalysisPanel(res.analysis, res.model, res);
                if (panelHost) panelHost.innerHTML = state.panelHtml;
                refreshUsageSummary(widget);
            } catch (err) {
                if (panelHost) panelHost.innerHTML = renderAnalysisPanel(safeUserMessage(err.message || 'Error al analizar seleccionados.', widget), '');
            } finally {
                analyzeSelectedBtn.disabled = selectedCountForWidget(widget) === 0;
            }
            return;
        }
        const showBtn = e.target.closest('.ves-show-item');
        if (showBtn) {
            e.preventDefault();
            const widget = showBtn.closest('.ves-wrap');
            const state = widgetState.get(widget);
            const idx = Number(showBtn.dataset.index || -1);
            const item = state?.filteredItems?.[idx];
            if (!item) return;
            // v0.9.9.5: panel-host lives inside the page's .ves-results — scope.
            const activePage = showBtn.closest('.ves-page') || widget.querySelector('.ves-page.is-active') || widget;
            const panelHost = activePage.querySelector('.ves-panel-host') || widget.querySelector('.ves-panel-host');
            state.panelHtml = renderDetailPanel(state.lastData.platform, item);
            if (panelHost) panelHost.innerHTML = state.panelHtml;
            return;
        }

        const analyzeBtn = e.target.closest('.ves-analyze-item');
        if (analyzeBtn) {
            e.preventDefault();
            const widget = analyzeBtn.closest('.ves-wrap');
            const state = widgetState.get(widget);
            const idx = Number(analyzeBtn.dataset.index || -1);
            const item = state?.filteredItems?.[idx];
            if (!item) return;
            const activePage = analyzeBtn.closest('.ves-page') || widget.querySelector('.ves-page.is-active') || widget;
            const panelHost = activePage.querySelector('.ves-panel-host') || widget.querySelector('.ves-panel-host');
            const focusPrompt = activePage.querySelector('.ves-analysis-focus')?.value || widget.querySelector('.ves-analysis-focus')?.value || '';
            analyzeBtn.disabled = true;
            if (panelHost) panelHost.innerHTML = renderAnalysisPanel(uiText(widget, 'generating'), '');
            try {
                const payload = buildAnalysisPayload(state.lastData.platform, normalizeItem(state.lastData.platform, item), item);
                const res = await ajaxAnalyze(state.form, state.lastData.platform, payload, focusPrompt);
                state.panelHtml = renderAnalysisPanel(res.analysis, res.model, res);
                if (panelHost) panelHost.innerHTML = state.panelHtml;
                refreshUsageSummary(widget);
            } catch (err) {
                if (panelHost) panelHost.innerHTML = renderAnalysisPanel(safeUserMessage(err.message || 'Error al analizar.', widget), '');
            } finally {
                analyzeBtn.disabled = false;
            }
        }
    });

    // ============================================================
    // v0.9.26.0 — Phase 1/3/13 frontend extensions
    // Access map gating, media normalization, Creative preflight,
    // and locked-AJAX response handling.
    // ============================================================

    const VES_ACCESS = (typeof window !== 'undefined' && window.VES_ACCESS_MAP && typeof window.VES_ACCESS_MAP === 'object')
        ? window.VES_ACCESS_MAP
        : { modules: {}, features: {}, providers: {}, lockedMessages: {}, planSlug: 'free', isAdmin: false };

    const accessState = (section, key) => {
        const sec = VES_ACCESS[section] || {};
        const v = String(sec[key] || 'enabled');
        // admin_only collapses to hidden for non-admins client-side too.
        if (v === 'admin_only' && !VES_ACCESS.isAdmin) return 'disabled_hidden';
        return v;
    };
    const isEnabled  = (section, key) => accessState(section, key) === 'enabled';
    const isLocked   = (section, key) => accessState(section, key) === 'disabled_locked_visible';
    const isHidden   = (section, key) => accessState(section, key) === 'disabled_hidden';
    const isComing   = (section, key) => accessState(section, key) === 'coming_soon';
    const lockedMsg  = (key) => {
        const m = VES_ACCESS.lockedMessages || {};
        return m[key] || m.default || 'Función no disponible en tu plan.';
    };

    // Apply access map to the sidebar and module pages.
    const applyAccessMap = (root) => {
        const ctx = root || document;
        // Sidebar items: data-page attribute matches the module key.
        ctx.querySelectorAll('.ves-nav-item[data-page]').forEach((btn) => {
            const moduleKey = String(btn.dataset.page || '');
            if (!moduleKey) return;
            const state = accessState('modules', moduleKey);
            btn.classList.remove('is-access-locked', 'is-access-coming');
            btn.removeAttribute('data-access-locked');
            if (state === 'disabled_hidden') {
                btn.style.display = 'none';
            } else if (state === 'disabled_locked_visible') {
                btn.classList.add('is-access-locked');
                btn.setAttribute('data-access-locked', '1');
                // Append lock badge if not already there.
                if (!btn.querySelector('.ves-access-lock-badge')) {
                    const badge = document.createElement('span');
                    badge.className = 'ves-access-lock-badge';
                    badge.textContent = 'Pro';
                    badge.setAttribute('aria-label', 'Requiere plan superior');
                    btn.appendChild(badge);
                }
            } else if (state === 'coming_soon') {
                btn.classList.add('is-access-coming');
                if (!btn.querySelector('.ves-access-soon-badge')) {
                    const badge = document.createElement('span');
                    badge.className = 'ves-access-soon-badge';
                    badge.textContent = 'Próximamente';
                    btn.appendChild(badge);
                }
            }
        });
        // Module pages: when a locked module page becomes active, replace inner with a locked card.
        ctx.querySelectorAll('.ves-page[data-page]').forEach((page) => {
            const moduleKey = String(page.dataset.page || '');
            if (!moduleKey) return;
            const state = accessState('modules', moduleKey);
            if (state === 'disabled_hidden') {
                page.style.display = 'none';
            } else if (state === 'disabled_locked_visible' && !page.dataset.lockedRendered) {
                renderLockedPage(page, moduleKey);
            }
        });
    };

    const renderLockedPage = (page, moduleKey) => {
        page.dataset.lockedRendered = '1';
        const msg = lockedMsg(moduleKey);
        const cta = (VES_ACCESS.lockedMessages || {})._cta_text || 'Ver planes';
        const url = (VES_ACCESS.lockedMessages || {})._cta_url  || '#pricing';
        const inner = page.querySelector('.ves-page-inner') || page;
        const head  = inner.querySelector('.ves-page-head');
        const body  = document.createElement('div');
        body.className = 'ves-locked-card';
        body.innerHTML = `
            <div class="ves-locked-icon" aria-hidden="true">🔒</div>
            <h3>Esta función está bloqueada en tu plan actual</h3>
            <p>${escapeHtml(msg)}</p>
            <a class="ves-btn ves-btn-primary" href="${escapeHtml(url)}">${escapeHtml(cta)}</a>
        `;
        // Hide everything else inside the page, keep header for visual continuity.
        Array.from(inner.children).forEach((child) => {
            if (child === head) return;
            child.style.display = 'none';
        });
        inner.appendChild(body);
    };

    // Block clicks on locked sidebar items: prevent activation, show toast/message.
    document.addEventListener('click', (e) => {
        const navBtn = e.target.closest && e.target.closest('.ves-nav-item[data-access-locked="1"]');
        if (!navBtn) return;
        e.preventDefault();
        e.stopPropagation();
        const moduleKey = String(navBtn.dataset.page || '');
        const msg = lockedMsg(moduleKey);
        // Use the existing toast/status mechanism if available, otherwise alert.
        const widget = navBtn.closest('.ves-wrap');
        if (widget && typeof setStatus === 'function') {
            setStatus(widget, msg, 'info');
        } else {
            try { window.alert(msg); } catch (_) { /* nope */ }
        }
    }, true);

    // ----- Media normalization (Phase 3) -----
    // Build a canonical media object from any provider record. Returns
    // { type, thumbnail_url, preview_url, source_url, embed_url, duration,
    //   width, height, alt, provider_media_fields_found, preview_status }
    const sanitizeUrl = (u) => {
        let s = String(u || '').trim();
        if (!s) return '';
        // v0.9.28.2 — Bug C fix. Provider JSON sometimes carries HTML-encoded
        // URLs like "https://cdn/x?a=1&amp;b=2". Render-time escapeHtml would
        // double-encode to "&amp;amp;", which the browser then parses back to
        // "&amp;" → server sees "amp;b" as a param. Decode common entities
        // before sanitization so we end up with a clean URL.
        if (s.indexOf('&') !== -1) {
            s = s.replace(/&amp;/gi, '&')
                 .replace(/&#x2[fF];/g, '/')
                 .replace(/&#47;/g, '/')
                 .replace(/&quot;/gi, '"')
                 .replace(/&#x?27;/g, "'");
        }
        if (s.startsWith('data:')) return '';
        if (s.startsWith('javascript:')) return '';   // belt-and-braces
        if (s.startsWith('//')) return 'https:' + s;
        if (!/^https?:\/\//i.test(s)) return '';
        return s.replace(/['"<>\s]/g, '');
    };
    const pickPath = (obj, path) => {
        if (!obj || typeof obj !== 'object') return null;
        const parts = String(path).split('.');
        let cur = obj;
        for (const p of parts) {
            if (cur && typeof cur === 'object' && p in cur) { cur = cur[p]; }
            else if (cur && typeof cur === 'object' && /^\d+$/.test(p) && Array.isArray(cur)) { cur = cur[Number(p)]; }
            else { return null; }
        }
        return cur;
    };
    const MEDIA_PATHS = [
        'thumbnail', 'thumbnailUrl', 'thumbnail_url', 'image', 'imageUrl', 'image_url',
        'displayUrl', 'display_url', 'cover', 'coverUrl', 'cover_url',
        'videoCover', 'video_cover', 'videoThumbnail', 'video_thumbnail',
        'preview', 'previewUrl', 'preview_url', 'previewImageUrl',
        'media.url', 'media.thumbnail', 'media.thumbnailUrl', 'media.0.url', 'media.0.thumbnail', 'media.0.thumbnailUrl',
        'video.thumbnail', 'video.thumbnailUrl', 'video.cover', 'video.coverUrl', 'video.dynamicCover', 'video.originCover', 'videoMeta.coverUrl', 'videoMeta.originalCoverUrl',
        'covers.0', 'coverUrl', 'cover_url', 'dynamicCover', 'originCover',
        'author.avatar', 'author.profilePictureUrl', 'channel.avatar', 'profilePicUrl',
        'images.0.url', 'images.0', 'thumbnails.0.url', 'thumbnails.0',
        // v0.9.28.0 — Phase 4: explicit YouTube thumbnails.* + extended X/Reddit paths.
        'thumbnails.default.url', 'thumbnails.medium.url', 'thumbnails.high.url',
        'thumbnails.standard.url', 'thumbnails.maxres.url',
        'preview.images.0.source.url', 'preview.images.0.resolutions.0.url',
        'preview.images.0.resolutions.1.url', 'preview.images.0.resolutions.2.url',
        'secure_media.oembed.thumbnail_url',
        'media.0.media_url_https', 'media.0.media_url',
        'entities.media.0.media_url_https', 'entities.media.0.media_url',
        'extended_entities.media.0.media_url_https', 'extended_entities.media.0.media_url',
        'attachments.0.url', 'attachments.0.media_url', 'picture', 'poster', 'ogImage', 'openGraph.image',
        'thumbnailDynamic', 'dynamicCover', 'originCover', 'channelAvatarUrl',
        'thumbnailSrc', 'thumbnail_src', 'mediaUrl', 'media_url', 'favicon',
        'snapshot.images.0.original_image_url', 'snapshot.images.0.resized_image_url',
        'snapshot.cards.0.original_image_url'
    ];
    const VIDEO_PATHS = ['videoUrl', 'video_url', 'video.url', 'media.video_url', 'mp4Url', 'playUrl'];
    const normalizeMedia = (item, hints = {}) => {
        if (!item || typeof item !== 'object') {
            return { type: 'unknown', thumbnail_url: null, preview_url: null, source_url: null, embed_url: null,
                     duration: null, width: null, height: null, alt: null, provider_media_fields_found: [], preview_status: 'missing' };
        }
        const found = [];
        let thumb = '';
        for (const p of MEDIA_PATHS) {
            const v = pickPath(item, p);
            if (v && typeof v === 'string') {
                const cleaned = sanitizeUrl(v);
                if (cleaned) { thumb = cleaned; found.push(p); break; }
            }
        }
        let video = '';
        for (const p of VIDEO_PATHS) {
            const v = pickPath(item, p);
            if (v && typeof v === 'string') {
                const cleaned = sanitizeUrl(v);
                if (cleaned) { video = cleaned; found.push(p); break; }
            }
        }
        // YouTube fallback: build from videoId or public watch/shorts URL.
        if (!thumb && hints.platform === 'youtube') {
            let vid = String(item.videoId || item.video_id || item.id || '').replace(/[^a-zA-Z0-9_-]/g, '');
            if (!vid) {
                const rawUrl = String(item.url || item.videoUrl || item.webUrl || '').trim();
                const m = rawUrl.match(/[?&]v=([A-Za-z0-9_-]{6,20})/) || rawUrl.match(/youtu\.be\/([A-Za-z0-9_-]{6,20})/) || rawUrl.match(/shorts\/([A-Za-z0-9_-]{6,20})/);
                vid = m ? m[1] : '';
            }
            if (vid && vid.length >= 6 && vid.length <= 20) {
                thumb = 'https://img.youtube.com/vi/' + vid + '/hqdefault.jpg';
                found.push('youtube_video_id_fallback');
            }
        }
        // Reddit: ignore "self" / "default" / "nsfw" pseudo-thumbnails.
        if (hints.platform === 'reddit' && thumb && /\b(self|default|nsfw|spoiler|image)\b/i.test(thumb) && !/^https?:\/\//i.test(thumb)) {
            thumb = '';
        }
        const sourceUrl = sanitizeUrl(item.url || item.source_url || item.permalink || item.link || '');
        let previewStatus = 'available';
        let previewError = null;
        if (!thumb && !video) {
            previewStatus = (found.length === 0) ? 'missing' : 'invalid_url';
            previewError = (found.length === 0)
                ? 'No media field present in provider record'
                : 'Media field present but URL failed sanitization (likely data:, javascript:, or malformed)';
        }
        const isVideo = !!video || /youtube|tiktok|reels|video/i.test(String(hints.platform || ''));
        // v0.9.28.0 — Phase 4: include debug metadata so admin diagnostics can
        // explain *why* preview is missing. selected_thumbnail_path tells us
        // which provider field actually won, even when there are many candidates.
        const selectedThumbnailPath = thumb && found.length > 0 ? found[0] : null;
        const selectedPreviewPath   = thumb && found.length > 0 ? found[0] : null;
        return {
            type: isVideo ? 'video' : (thumb ? 'image' : 'unknown'),
            thumbnail_url: thumb || null,
            preview_url: thumb || null,
            source_url: sourceUrl || null,
            embed_url: video || null,
            duration: item.duration || item.videoDuration || null,
            width:  item.width  || null,
            height: item.height || null,
            alt:    item.title  || item.caption || null,
            provider_media_fields_found: found,
            media_fields_found: found,  // alias matching spec wording
            selected_thumbnail_path: selectedThumbnailPath,
            selected_preview_path: selectedPreviewPath,
            preview_status: previewStatus,
            preview_error: previewError
        };
    };
    // Expose for debugging / future extension.
    if (typeof window !== 'undefined') { window.VES_normalizeMedia = normalizeMedia; }

    // ----- Creative Intelligence upload preflight (Phase 13) -----
    // Block file selection before upload when oversized. Looks for the input
    // by data attribute or by common selectors.
    const CREATIVE_MAX_IMAGE_MB = (window.VES_CREATIVE_MAX_IMAGE_MB) || 12;
    const CREATIVE_MAX_VIDEO_MB = (window.VES_CREATIVE_MAX_VIDEO_MB) || 80;
    document.addEventListener('change', (e) => {
        const input = e.target;
        if (!input || input.type !== 'file' ||
            !input.matches('[data-creative-upload], input[name="creativeFile"], input[name="creative_file"], input[name="creativeAsset"]')) return;
        const file = input.files && input.files[0];
        if (!file) return;
        const sizeMB = file.size / (1024 * 1024);
        const isVideo = /^video\//.test(file.type);
        const isImage = /^image\//.test(file.type);
        const cap = isVideo ? CREATIVE_MAX_VIDEO_MB : CREATIVE_MAX_IMAGE_MB;
        const widget = input.closest('.ves-wrap');
        // Type check.
        if (!isVideo && !isImage) {
            input.value = '';
            const msg = 'Tipo de archivo no soportado. Sube una imagen (JPG/PNG/WebP) o un vídeo (MP4/MOV).';
            if (widget && typeof setStatus === 'function') setStatus(widget, msg, 'error');
            else try { window.alert(msg); } catch (_) {}
            return;
        }
        // Size check.
        if (sizeMB > cap) {
            input.value = '';
            const msg = `El archivo (${sizeMB.toFixed(1)} MB) supera el límite actual de ${cap} MB. Comprime el archivo, sube un clip más corto, o pide al administrador subir el límite. No se han cargado créditos.`;
            if (widget && typeof setStatus === 'function') setStatus(widget, msg, 'error');
            else try { window.alert(msg); } catch (_) {}
            return;
        }
        // Show image preview if available.
        if (isImage) {
            const reader = new FileReader();
            reader.onload = (ev) => {
                let preview = input.parentElement && input.parentElement.querySelector('.ves-creative-preview');
                if (!preview && input.parentElement) {
                    preview = document.createElement('img');
                    preview.className = 'ves-creative-preview';
                    preview.alt = '';
                    preview.style.cssText = 'max-width:240px;max-height:240px;margin-top:10px;border-radius:8px;display:block';
                    input.parentElement.appendChild(preview);
                }
                if (preview) preview.src = ev.target.result;
            };
            try { reader.readAsDataURL(file); } catch (_) {}
        }
    });

    // ----- Locked AJAX response handling -----
    // When an AJAX endpoint returns a feature_locked / linkedin_missing_input
    // style structured error, surface it as a friendly status message rather
    // than a raw network error.
    const handleLockedAjaxResponse = (data, widget) => {
        if (!data || typeof data !== 'object') return false;
        const lockedCodes = ['feature_locked','linkedin_missing_input','linkedin_unsupported_objective','preflight_blocked'];
        const code = String(data.code || '');
        if (data.success === false && lockedCodes.indexOf(code) !== -1) {
            const msg = data.message || lockedMsg(data.key || '');
            const ctaUrl  = data.upgrade_url || '';
            const ctaText = data.cta_text || 'Ver planes';
            const html = `<div class="ves-locked-card ves-locked-inline">
                <div class="ves-locked-icon" aria-hidden="true">🔒</div>
                <h4>${escapeHtml(data.title || 'Función no disponible')}</h4>
                <p>${escapeHtml(msg)}</p>
                ${ctaUrl ? `<a class="ves-btn ves-btn-primary" href="${escapeHtml(ctaUrl)}">${escapeHtml(ctaText)}</a>` : ''}
                <small class="ves-locked-credits-note">No se han cargado créditos.</small>
            </div>`;
            if (widget) {
                const panelHost = widget.querySelector('.ves-page.is-active .ves-panel-host') || widget.querySelector('.ves-panel-host');
                if (panelHost) panelHost.innerHTML = html;
                if (typeof setStatus === 'function') setStatus(widget, msg, 'info');
            }
            return true;
        }
        return false;
    };
    if (typeof window !== 'undefined') { window.VES_handleLockedAjax = handleLockedAjaxResponse; }

    // Apply access map on initial bootstrap and after route changes.
    document.addEventListener('DOMContentLoaded', () => { try { applyAccessMap(document); } catch (e) {} });
    if (document.readyState !== 'loading') { try { applyAccessMap(document); } catch (e) {} }

    // Phase 15: inject a mobile menu toggle into the top header so users can
    // open the sidebar drawer on tablet/mobile.
    // v0.9.28.0 — Phase 8: the toggle is now rendered in the PHP template
    // (templates/shortcode.php), so this injection is a fallback for sites
    // running an older template or a custom shell.
    const injectMobileMenuToggle = () => {
        document.querySelectorAll('.ves-light-suite, .ves-wrap').forEach((root) => {
            if (root.dataset.mobileMenuInjected === '1') return;
            // Skip injection if the template already rendered the toggle.
            if (root.querySelector(':scope > .ves-mobile-menu-toggle, :scope > .ves-mobile-drawer-backdrop')) {
                root.dataset.mobileMenuInjected = '1';
                return;
            }
            const header = root.querySelector('.ves-header, .ves-page-head, .ves-topbar, .ves-page-header');
            if (!header) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ves-mobile-menu-toggle';
            btn.setAttribute('aria-label', 'Abrir menú');
            btn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>';
            header.insertBefore(btn, header.firstChild);
            root.dataset.mobileMenuInjected = '1';
        });
        // Wire toggle click for both template-rendered AND legacy-injected buttons.
        document.querySelectorAll('.ves-mobile-menu-toggle').forEach((btn) => {
            if (btn.dataset.vesWired === '1') return;
            btn.dataset.vesWired = '1';
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const root = btn.closest('.ves-light-suite, .ves-wrap');
                if (!root) return;
                const opening = !root.classList.contains('ves-sidebar-open');
                root.classList.toggle('ves-sidebar-open', opening);
                btn.setAttribute('aria-expanded', opening ? 'true' : 'false');
            });
        });
        // Backdrop click closes the drawer.
        document.querySelectorAll('.ves-mobile-drawer-backdrop').forEach((backdrop) => {
            if (backdrop.dataset.vesWired === '1') return;
            backdrop.dataset.vesWired = '1';
            backdrop.addEventListener('click', () => {
                const root = backdrop.closest('.ves-light-suite, .ves-wrap');
                if (root) {
                    root.classList.remove('ves-sidebar-open');
                    const t = root.querySelector('.ves-mobile-menu-toggle');
                    if (t) t.setAttribute('aria-expanded', 'false');
                }
            });
        });
        // Close drawer when a nav item is tapped on mobile.
        document.addEventListener('click', (e) => {
            const navItem = e.target.closest && e.target.closest('.ves-nav-item[data-page]');
            if (!navItem) return;
            const root = navItem.closest('.ves-light-suite, .ves-wrap');
            if (root && window.matchMedia && window.matchMedia('(max-width: 1279px)').matches) {
                root.classList.remove('ves-sidebar-open');
                const t = root.querySelector('.ves-mobile-menu-toggle');
                if (t) t.setAttribute('aria-expanded', 'false');
            }
        });
        // v0.9.28.0 — Phase 8: Escape closes the drawer.
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            const root = document.querySelector('.ves-light-suite.ves-sidebar-open, .ves-wrap.ves-sidebar-open');
            if (root) {
                root.classList.remove('ves-sidebar-open');
                const t = root.querySelector('.ves-mobile-menu-toggle');
                if (t) t.setAttribute('aria-expanded', 'false');
            }
        });
        // Close drawer when content area is clicked (outside the sidebar) on mobile.
        document.addEventListener('click', (e) => {
            if (!window.matchMedia || !window.matchMedia('(max-width: 1279px)').matches) return;
            const root = document.querySelector('.ves-light-suite.ves-sidebar-open, .ves-wrap.ves-sidebar-open');
            if (!root) return;
            const insideSidebar = e.target.closest('.ves-sidebar, .ves-app-sidebar, .ves-left-nav, .ves-mobile-menu-toggle');
            if (!insideSidebar) {
                root.classList.remove('ves-sidebar-open');
                const t = root.querySelector('.ves-mobile-menu-toggle');
                if (t) t.setAttribute('aria-expanded', 'false');
            }
        });
    };
    document.addEventListener('DOMContentLoaded', injectMobileMenuToggle);
    if (document.readyState !== 'loading') injectMobileMenuToggle();

    /* v0.9.9.1: single bootstrap. */
    const bootstrap = () => {
        removeLegacyExtraFields(document);
        document.querySelectorAll(".ves-wrap").forEach((widget) => {
            if (widget.dataset.ready === '1') return;
            try { setupWidget(widget); } catch (err) { console.error("[VES] bootstrap widget failed", err); }
        });
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", bootstrap, { once: true });
    } else {
        bootstrap();
    }


    document.addEventListener('input', (e) => {
        const assisted = e.target.closest && e.target.closest('.ves-ai-assisted');
        if (assisted) renderInputSuggestions(assisted);
    });
    document.addEventListener('focusin', (e) => {
        const assisted = e.target.closest && e.target.closest('.ves-ai-assisted');
        if (assisted) renderInputSuggestions(assisted);
    });
    document.addEventListener('change', (e) => {
        const field = e.target;
        if (!field) return;
        if (field.matches('[data-seo-provider], [name="seoProvider"], [name="country"], [name="language"], [data-linkedin-objective], [name="linkedinObjective"]')) {
            const widget = field.closest('.ves-wrap');
            if (widget) syncModuleFormState(widget);
        }
    });
})();
