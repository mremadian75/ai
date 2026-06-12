(function () {
  const panels = document.querySelectorAll('[data-fici-panel]');
  if (!panels.length || typeof FICI_FRONTEND === 'undefined') return;

  panels.forEach(initPanel);

  function initPanel(panel) {
    const form = panel.querySelector('[data-fici-form]');
    const resultBox = panel.querySelector('[data-fici-result]');
    const historyBox = panel.querySelector('[data-fici-history]');
    const statusBox = panel.querySelector('[data-fici-status]');
    const estimateBox = panel.querySelector('[data-fici-estimate]');
    const submitButton = form ? form.querySelector('button[type="submit"]') : null;

    if (!form || !resultBox || !historyBox) return;

    bindEstimate(form, estimateBox);
    bindHistoryActions(historyBox, resultBox, statusBox);
    bindResultActions(resultBox, historyBox, statusBox);
    loadHistory(historyBox, resultBox);
    bindCreativePreflight(form, resultBox, statusBox);

    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      clearError(resultBox);
      const fileInput = form.querySelector('input[name="asset_file"]');
      const file = fileInput && fileInput.files && fileInput.files.length ? fileInput.files[0] : null;
      const preflightError = preflightCreativeFile(form, file);
      if (preflightError) {
        renderError(resultBox, preflightError);
        setStatus(statusBox, 'Archivo bloqueado antes de llamar a AI. No se cobra análisis completo.');
        return;
      }
      setBusy(submitButton, true);
      setStatus(statusBox, 'Preparando creatividad para análisis...');

      const body = new FormData(form);

      try {
        if (file && file.type && file.type.indexOf('video/') === 0) {
          setStatus(statusBox, 'Extrayendo key frames en tu navegador...');
          const frames = await extractVideoFrames(file);
          renderFramePreview(form, frames);
          body.append('video_frames_json', JSON.stringify(frames));
          body.append('client_frame_count', String(frames.length));
        }

        setStatus(statusBox, 'Analizando creatividad con AI...');

        const data = await apiFetch('/analysis/start', {
          method: 'POST',
          body
        });

        renderResult(resultBox, data);
        setStatus(statusBox, 'Análisis completo. Guardado en historial.');
        await loadHistory(historyBox, resultBox);
      } catch (error) {
        renderError(resultBox, humanizeError(error), error);
        setStatus(statusBox, 'El análisis falló.');
      } finally {
        setBusy(submitButton, false);
      }
    });
  }

  function bindCreativePreflight(form, resultBox, statusBox) {
    const fileInput = form.querySelector('input[name="asset_file"]');
    const preflightBox = form.querySelector('[data-fici-preflight]');
    const maxSize = form.querySelector('[data-fici-max-size]');
    const dropzone = form.querySelector('[data-fici-dropzone]');
    if (maxSize) maxSize.textContent = `${Number(FICI_FRONTEND.maxFileSizeMb || 20)} MB`;
    const update = () => {
      const file = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
      if (!preflightBox) return;
      if (!file) { preflightBox.hidden = true; preflightBox.innerHTML = ''; return; }
      const err = preflightCreativeFile(form, file);
      const sizeMb = file.size ? (file.size / (1024 * 1024)).toFixed(2) : '0.00';
      preflightBox.hidden = false;
      preflightBox.innerHTML = `<strong>${err ? 'Preflight bloqueado' : 'Preflight listo'}</strong><span>${escapeHtml(file.name)} · ${escapeHtml(file.type || 'unknown')} · ${escapeHtml(sizeMb)} MB</span>${err ? `<p>${escapeHtml(err)}</p>` : '<p>El archivo puede enviarse. En vídeo se extraerán frames antes del análisis AI.</p>'}`;
      if (err) setStatus(statusBox, 'Archivo bloqueado antes de AI. No se consumen créditos completos.');
    };
    if (fileInput) fileInput.addEventListener('change', update);
    if (dropzone && fileInput) {
      ['dragenter','dragover'].forEach(type => dropzone.addEventListener(type, (event) => { event.preventDefault(); dropzone.classList.add('is-dragover'); }));
      ['dragleave','drop'].forEach(type => dropzone.addEventListener(type, (event) => { event.preventDefault(); dropzone.classList.remove('is-dragover'); }));
      dropzone.addEventListener('drop', (event) => {
        const files = event.dataTransfer && event.dataTransfer.files;
        if (!files || !files.length) return;
        fileInput.files = files;
        update();
      });
    }
    update();
  }

  function preflightCreativeFile(form, file) {
    if (!file) return '';
    const maxMb = Number(FICI_FRONTEND.maxFileSizeMb || 20);
    const maxBytes = Math.max(1, maxMb) * 1024 * 1024;
    const allowed = /^(image\/(jpeg|png|webp|gif)|video\/(mp4|webm|quicktime))$/i;
    if (file.size && file.size > maxBytes) return `El archivo pesa ${(file.size / (1024 * 1024)).toFixed(2)} MB y supera el límite actual de ${maxMb} MB.`;
    if (file.type && !allowed.test(file.type)) return `Formato no permitido. Usa ${FICI_FRONTEND.acceptedFormats || 'JPG, PNG, WebP, GIF, MP4, MOV o WebM'}.`;
    return '';
  }

  function renderFramePreview(form, frames) {
    const box = form.querySelector('[data-fici-frame-preview]');
    if (!box) return;
    const list = Array.isArray(frames) ? frames.slice(0, 6) : [];
    if (!list.length) { box.hidden = true; box.innerHTML = ''; return; }
    box.hidden = false;
    box.innerHTML = '<strong>Frames extraídos antes del AI</strong><div>' + list.map(src => `<img src="${escapeHtml(src)}" alt="Video key frame" loading="lazy">`).join('') + '</div>';
  }

  function bindEstimate(form, estimateBox) {
    if (!estimateBox) return;
    const update = () => {
      const estimate = estimateCredits(form);
      estimateBox.innerHTML = `Estimated cost: <strong>${escapeHtml(String(estimate))} credits</strong>`;
    };
    form.addEventListener('change', update);
    form.addEventListener('input', update);
    update();
  }

  function estimateCredits(form) {
    const mode = form.querySelector('[name="analysis_mode"]')?.value || 'standard';
    const fileInput = form.querySelector('[name="asset_file"]');
    const assetUrl = form.querySelector('[name="asset_url"]')?.value || '';
    const context = form.querySelector('[name="context"]')?.value || '';
    const file = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

    let assetType = 'text';
    if (file && file.type && file.type.indexOf('video/') === 0) assetType = 'video';
    else if (file && file.type && file.type.indexOf('image/') === 0) assetType = 'image';
    else if (assetUrl.trim()) assetType = 'url';
    else if (context.trim()) assetType = 'text';

    const costs = FICI_FRONTEND.costs || {};
    const multipliers = FICI_FRONTEND.multipliers || {};
    const base = Number(costs[assetType] || costs.image || 6);
    const multiplier = Number(multipliers[mode] || 1);
    return Math.max(1, Math.ceil(base * multiplier));
  }

  async function loadHistory(historyBox, resultBox) {
    try {
      historyBox.innerHTML = '<div class="fici-muted">Loading previous analyses...</div>';
      const data = await apiFetch('/analysis/recent?limit=12', { method: 'GET' });
      renderHistory(historyBox, Array.isArray(data.items) ? data.items : []);
    } catch (error) {
      historyBox.innerHTML = '<div class="fici-error">Could not load history: ' + escapeHtml(humanizeError(error)) + '</div>';
    }
  }

  function bindHistoryActions(historyBox, resultBox, statusBox) {
    historyBox.addEventListener('click', async function (event) {
      const viewButton = event.target.closest('[data-fici-view]');
      const deleteButton = event.target.closest('[data-fici-delete]');
      if (!viewButton && !deleteButton) return;

      const id = Number((viewButton || deleteButton).getAttribute(viewButton ? 'data-fici-view' : 'data-fici-delete'));
      if (!id) return;

      if (viewButton) {
        try {
          resultBox.hidden = false;
          resultBox.innerHTML = '<div class="fici-muted">Loading saved result...</div>';
          const data = await apiFetch('/analysis/' + encodeURIComponent(String(id)) + '/result', { method: 'GET' });
          if (data.result) renderResult(resultBox, data);
          else renderError(resultBox, data.error_message || 'This analysis has no completed result yet.');
        } catch (error) {
          renderError(resultBox, humanizeError(error));
        }
      }

      if (deleteButton) {
        const ok = window.confirm('Delete this analysis and its stored asset?');
        if (!ok) return;
        try {
          await apiFetch('/analysis/' + encodeURIComponent(String(id)), { method: 'DELETE' });
          setStatus(statusBox, 'Analysis deleted.');
          await loadHistory(historyBox, resultBox);
        } catch (error) {
          renderError(resultBox, humanizeError(error));
        }
      }
    });
  }

  function bindResultActions(resultBox, historyBox, statusBox) {
    resultBox.addEventListener('click', async function (event) {
      const button = event.target.closest('[data-fici-action]');
      if (!button) return;

      const runId = Number(button.getAttribute('data-fici-run'));
      const action = button.getAttribute('data-fici-action');
      if (!runId || !action) return;

      const endpoints = {
        memory: '/analysis/' + encodeURIComponent(String(runId)) + '/write-memory',
        insight: '/analysis/' + encodeURIComponent(String(runId)) + '/create-insight',
        brief: '/analysis/' + encodeURIComponent(String(runId)) + '/create-brief'
      };

      if (!endpoints[action]) return;

      try {
        button.disabled = true;
        setStatus(statusBox, 'Sending result to ' + action + ' layer...');
        await apiFetch(endpoints[action], { method: 'POST' });
        const updated = await apiFetch('/analysis/' + encodeURIComponent(String(runId)) + '/result', { method: 'GET' });
        renderResult(resultBox, updated);
        await loadHistory(historyBox, resultBox);
        setStatus(statusBox, 'Saved to ' + action + ' layer.');
      } catch (error) {
        button.disabled = false;
        renderError(resultBox, humanizeError(error));
        setStatus(statusBox, 'Action failed.');
      }
    });
  }

  function renderHistory(historyBox, items) {
    if (!items.length) {
      historyBox.innerHTML = '<div class="fici-empty">No previous analyses yet.</div>';
      return;
    }

    historyBox.innerHTML = items.map((item) => {
      const statusClass = item.status === 'completed' ? 'is-complete' : item.status === 'failed' ? 'is-failed' : 'is-pending';
      const summary = item.summary || item.error_message || 'No summary saved.';
      const meta = [item.asset_type, item.target_platform, item.analysis_mode].filter(Boolean).join(' · ');
      const saved = savedBadges(item.core_links || {});
      return `
        <article class="fici-history-item ${statusClass}">
          <div>
            <div class="fici-history-top">
              <span class="fici-pill">${escapeHtml(item.status || 'unknown')}</span>
              <span class="fici-muted">${escapeHtml(formatDate(item.created_at))}</span>
            </div>
            <strong>${escapeHtml(meta || 'Creative analysis')}</strong>
            <p>${escapeHtml(summary)}</p>
            <div class="fici-mini-metrics">
              <span>Hook ${escapeHtml(String(item.hook_score || 0))}</span>
              <span>Brand ${escapeHtml(String(item.brand_score || 0))}</span>
              <span>${escapeHtml(String(item.credits_used || 0))} credits</span>
            </div>
            ${saved ? '<div class="fici-saved-badges">' + saved + '</div>' : ''}
          </div>
          <div class="fici-history-actions">
            <button type="button" data-fici-view="${escapeHtml(String(item.id))}">View</button>
            <button type="button" class="fici-danger" data-fici-delete="${escapeHtml(String(item.id))}">Delete</button>
          </div>
        </article>
      `;
    }).join('');
  }

  function renderResult(resultBox, data) {
    const result = data.result || {};
    const hook = result.hook_analysis || {};
    const brand = result.brand_presence || {};
    const platform = result.platform_fit || {};
    const brief = result.suggested_brief || {};
    const runId = Number(data.run_id || data.id || 0);
    const links = data.core_links || {};

    resultBox.hidden = false;
    resultBox.innerHTML = `
      <div class="fici-result-header">
        <div>
          <span class="fici-eyebrow">Creative analysis</span>
          <h3>Analysis complete</h3>
          <p>${escapeHtml(result.creative_summary || 'No summary returned.')}</p>
        </div>
        <div class="fici-credit-badge">${escapeHtml(String(data.credits_used || data.estimated_credits || 0))}<span>credits</span></div>
      </div>

      ${runId ? actionPanel(runId, links) : ''}

      <div class="fici-score-grid">
        ${scoreCard('Hook', hook.score)}
        ${scoreCard('Brand', brand.score)}
        ${scoreCard('Confidence', result.confidence || '-')}
      </div>

      <div class="fici-result-grid">
        ${card('Hook diagnosis', '<p>' + escapeHtml(hook.diagnosis || '-') + '</p><p class="fici-muted">Type: ' + escapeHtml(hook.hook_type || '-') + '</p>')}
        ${card('Brand presence', '<p>' + escapeHtml(brand.notes || '-') + '</p>')}
        ${card('Platform fit', platformBars(platform))}
        ${card('Detected elements', list(result.detected_elements))}
        ${card('Detected text', list(result.detected_text))}
        ${card('Strengths', list(result.strengths))}
        ${card('Weaknesses', list(result.weaknesses))}
        ${card('Opportunities', list(result.opportunities))}
        ${card('Recommended next actions', list(result.recommended_next_actions))}
        ${card('Suggested hooks', list(result.suggested_hooks))}
        ${card('Suggested captions', list(result.suggested_captions))}
        ${card('Suggested brief', briefHtml(brief))}
      </div>
    `;
  }

  function actionPanel(runId, links) {
    const memory = !!(links.memory && links.memory.created);
    const insight = !!(links.insight && links.insight.created);
    const brief = !!(links.brief && links.brief.created);
    const mode = FICI_FRONTEND.coreAvailable ? 'Core connected' : 'Standalone bridge mode';

    return `
      <div class="fici-action-panel">
        <div>
          <strong>Send this result into the SaaS flow</strong>
          <p>${escapeHtml(mode)}. In standalone mode, actions are recorded locally and will use core hooks when connected.</p>
        </div>
        <div class="fici-action-buttons">
          ${actionButton('memory', 'Save memory', memory, runId)}
          ${actionButton('insight', 'Create insight', insight, runId)}
          ${actionButton('brief', 'Create brief', brief, runId)}
        </div>
      </div>
    `;
  }

  function actionButton(action, label, done, runId) {
    return `<button type="button" ${done ? 'disabled' : ''} data-fici-action="${escapeHtml(action)}" data-fici-run="${escapeHtml(String(runId))}">${escapeHtml(done ? label + ' saved' : label)}</button>`;
  }

  function savedBadges(links) {
    const badges = [];
    if (links.memory && links.memory.created) badges.push('<span>Memory</span>');
    if (links.insight && links.insight.created) badges.push('<span>Insight</span>');
    if (links.brief && links.brief.created) badges.push('<span>Brief</span>');
    return badges.join('');
  }

  function scoreCard(label, value) {
    return `<div><strong>${escapeHtml(label)}</strong><span>${escapeHtml(value === undefined || value === null || value === '' ? '-' : String(value))}</span></div>`;
  }

  function card(title, body) {
    return `<section class="fici-result-card"><h4>${escapeHtml(title)}</h4>${body}</section>`;
  }

  function platformBars(platform) {
    const entries = Object.entries(platform || {});
    if (!entries.length) return '<p>-</p>';
    return entries.map(([key, value]) => {
      const score = Math.max(0, Math.min(100, Number(value || 0)));
      return `
        <div class="fici-platform-row">
          <span>${escapeHtml(labelize(key))}</span>
          <div class="fici-bar"><i style="width:${score}%"></i></div>
          <strong>${score}</strong>
        </div>
      `;
    }).join('');
  }

  function briefHtml(brief) {
    return `
      <p><strong>Objective:</strong> ${escapeHtml(brief.objective || '-')}</p>
      <p><strong>Audience:</strong> ${escapeHtml(brief.target_audience || '-')}</p>
      <p><strong>Angle:</strong> ${escapeHtml(brief.angle || '-')}</p>
      ${list(brief.key_points)}
    `;
  }

  function list(items) {
    if (!Array.isArray(items) || !items.length) return '<p>-</p>';
    return '<ul>' + items.map((item) => '<li>' + escapeHtml(item) + '</li>').join('') + '</ul>';
  }

  function renderError(resultBox, message, error) {
    resultBox.hidden = false;
    // v0.9.30.21: show code + HTTP status in a readable line, not just a raw message.
    const isAdmin = typeof FICI_FRONTEND !== 'undefined' && FICI_FRONTEND.isAdmin;
    let detail = '';
    if (isAdmin && error && error.serverData) {
      const sd = error.serverData;
      const parts = [];
      if (sd.code) parts.push('code: ' + escapeHtml(sd.code));
      if (sd.status) parts.push('HTTP ' + escapeHtml(String(sd.status)));
      if (sd.stage) parts.push('stage: ' + escapeHtml(sd.stage));
      if (parts.length) detail = '<details style="margin-top:6px;font-size:11.5px"><summary style="cursor:pointer;opacity:.75">Admin diagnostics</summary><p style="margin:4px 0">' + parts.join(' · ') + '</p></details>';
    }
    resultBox.innerHTML = '<div class="fici-error"><h3>Analysis failed</h3><p>' + escapeHtml(message) + '</p>' + detail + '</div>';
  }

  function clearError(resultBox) {
    resultBox.hidden = true;
    resultBox.innerHTML = '';
  }

  async function apiFetch(path, options) {
    const response = await fetch(FICI_FRONTEND.restUrl + path, {
      credentials: 'same-origin',
      headers: Object.assign({ 'X-WP-Nonce': FICI_FRONTEND.nonce }, options && options.headers ? options.headers : {}),
      ...options
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.code) {
      const error = new Error(data.message || data.error_message || 'Request failed.');
      error.code = data.code || 'request_failed';
      // v0.9.30.21: store HTTP status and nested WP REST data fields separately
      // so renderError can show structured diagnostics to admins/debug users.
      error.httpStatus = response.status;
      error.data = data;
      error.serverData = {
        status: response.status,
        code: data.code || '',
        stage: (data.data && data.data.stage) || '',
      };
      throw error;
    }
    return data;
  }

  function seekVideo(video, time) {
    return new Promise((resolve, reject) => {
      const onSeeked = () => {
        cleanup();
        resolve();
      };
      const onError = () => {
        cleanup();
        reject(new Error('Could not read this video frame in the browser. Try a shorter MP4/WebM or upload a screenshot.'));
      };
      const cleanup = () => {
        video.removeEventListener('seeked', onSeeked);
        video.removeEventListener('error', onError);
      };
      video.addEventListener('seeked', onSeeked, { once: true });
      video.addEventListener('error', onError, { once: true });
      video.currentTime = Math.min(Math.max(time, 0), Math.max(video.duration - 0.1, 0));
    });
  }

  function waitForMetadata(video) {
    return new Promise((resolve, reject) => {
      if (video.readyState >= 1 && Number.isFinite(video.duration)) {
        resolve();
        return;
      }
      const onLoaded = () => {
        cleanup();
        resolve();
      };
      const onError = () => {
        cleanup();
        reject(new Error('Could not load video metadata.'));
      };
      const cleanup = () => {
        video.removeEventListener('loadedmetadata', onLoaded);
        video.removeEventListener('error', onError);
      };
      video.addEventListener('loadedmetadata', onLoaded, { once: true });
      video.addEventListener('error', onError, { once: true });
    });
  }

  async function extractVideoFrames(file) {
    const objectUrl = URL.createObjectURL(file);
    const video = document.createElement('video');
    video.muted = true;
    video.playsInline = true;
    video.preload = 'metadata';
    video.src = objectUrl;

    try {
      await waitForMetadata(video);

      if (!Number.isFinite(video.duration) || video.duration <= 0) {
        throw new Error('This video duration could not be detected.');
      }
      const maxVideoSeconds = Number(FICI_FRONTEND.maxVideoSeconds || 60);
      if (video.duration > maxVideoSeconds) {
        throw new Error(`El vídeo dura ${Math.round(video.duration)} s y supera el límite de ${maxVideoSeconds} s para preflight. Sube un clip más corto o un screenshot.`);
      }

      const sampleTimes = [0.5, video.duration * 0.25, video.duration * 0.5, Math.max(video.duration - 0.5, 0.5)];
      const uniqueTimes = Array.from(new Set(sampleTimes.map((t) => Math.round(t * 10) / 10))).slice(0, Number(FICI_FRONTEND.maxVideoFrames || 4));
      const frames = [];

      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d');
      if (!ctx) throw new Error('Canvas is not available in this browser.');

      for (const time of uniqueTimes) {
        await seekVideo(video, time);
        const maxWidth = 960;
        const scale = Math.min(1, maxWidth / video.videoWidth);
        canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
        canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        frames.push(canvas.toDataURL('image/jpeg', 0.78));
      }

      return frames;
    } finally {
      URL.revokeObjectURL(objectUrl);
    }
  }

  function setStatus(statusBox, message) {
    if (!statusBox) return;
    statusBox.hidden = !message;
    statusBox.textContent = message || '';
  }

  function setBusy(button, isBusy) {
    if (!button) return;
    button.disabled = isBusy;
    button.textContent = isBusy ? 'Analyzing...' : 'Analyze creative';
  }

  function humanizeError(error) {
    if (!error) return 'Unexpected error.';
    const message = error.message || 'Unexpected error.';
    const code = error.code || '';

    // v0.9.30.21: added all error codes that FICI_Error_Mapper and
    // FICI_OpenAI_Provider can return. Previously fici_invalid_api_key,
    // fici_empty_output, fici_request_timeout and upstream errors fell
    // through to the raw server message — now mapped to user-safe text.
    const known = {
      fici_missing_api_key:       'OpenAI API key is missing. Add it in Settings > Creative Intelligence.',
      fici_invalid_api_key:       'OpenAI authentication failed. Check the API key in Settings > Creative Intelligence.',
      fici_auth_error:            'OpenAI authentication failed. Check the API key.',
      fici_rate_limited:          'OpenAI rate limit reached. Try again later or use a lighter analysis mode.',
      fici_rate_limited_upstream: 'OpenAI rate limit reached. Try again in a moment.',
      fici_file_too_large:        'The uploaded file is too large for the current add-on settings.',
      fici_invalid_file_type:     'Unsupported file type. Use an image, MP4, WebM, or MOV.',
      fici_video_frames_missing:  'No key frames were received from the video. Try a shorter MP4/WebM or upload a screenshot instead.',
      fici_schema_mismatch:       'The AI response did not match the expected schema. Try again or use Standard mode.',
      fici_empty_output:          'The AI returned an empty response. Try again or simplify the input.',
      fici_request_timeout:       'The AI request timed out. Try again with a smaller file or fewer frames.',
      fici_invalid_response:      'The AI provider returned an unexpected response format. Try again.',
      fici_upstream_unavailable:  'The AI provider is temporarily unavailable. Try again in a few minutes.',
      fici_upstream_error:        'The AI provider returned an error. Try again or check Settings.',
      fici_missing_input:         'No asset was provided. Upload a file, enter an Asset URL, or paste context text.',
      fici_run_not_completed:     'This analysis is not completed yet.',
      fici_no_suggested_brief:    'This analysis does not contain a suggested brief.',
      fici_permission_denied:     'You do not have permission to use Creative Intelligence.',
      fici_creative_module_locked:'Creative Intelligence is not available on your current plan.',
      fici_feature_locked:        'This Creative Intelligence feature is not available on your current plan.',
      rest_cookie_invalid_nonce:  'Your session expired. Refresh the page and try again.',
    };

    return known[code] || message;
  }

  function escapeHtml(value) {
    return String(value || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function labelize(value) {
    return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function formatDate(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T') + 'Z');
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
  }
})();
