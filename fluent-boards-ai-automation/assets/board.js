/**
 * Fluent Boards AI — in-board "AI Analyze" launcher.
 * Vanilla JS, no framework/jQuery dependency. Admin-only (gated server-side at enqueue).
 * Calls the plugin's capability-protected REST endpoints; never handles secrets.
 */
(function () {
    'use strict';

    if (!window.FBAIABoard || !window.FBAIABoard.restRoot) {
        return;
    }
    var cfg = window.FBAIABoard;
    var btnEl = null;
    var panelEl = null;
    var detected = '';
    var currentSuggestionId = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function isVisible(el) {
        return !!(el && el.offsetParent !== null);
    }

    // Best-effort detection of the currently open task, tolerant across Fluent Boards
    // versions: first the URL (hash/query), then visible DOM data attributes.
    function detectTaskId() {
        var hay = (window.location.hash || '') + ' ' + (window.location.search || '');
        var patterns = [
            /(?:taskid|task[_-]?id)[=:\/-]?(\d+)/i,
            /tasks?[\/=_-](\d+)/i,
            /[?&]task=(\d+)/i
        ];
        for (var i = 0; i < patterns.length; i++) {
            var m = hay.match(patterns[i]);
            if (m) { return m[1]; }
        }
        var nodes = document.querySelectorAll('[data-task-id],[data-taskid],[data-task_id]');
        for (var j = 0; j < nodes.length; j++) {
            var n = nodes[j];
            if (!isVisible(n)) { continue; }
            var v = n.getAttribute('data-task-id') || n.getAttribute('data-taskid') || n.getAttribute('data-task_id');
            if (v && /^\d+$/.test(v)) { return v; }
        }
        return '';
    }

    // Re-detect and reflect the open task in the launcher label + input, without clobbering
    // a value the user typed manually.
    function refreshDetected() {
        var id = detectTaskId();
        if (!id || id === detected) { return; }
        var prev = detected;
        detected = id;
        if (btnEl) { btnEl.textContent = cfg.i18n.launcher + ' · #' + id; }
        if (panelEl) {
            var input = panelEl.querySelector('#fbaia-bp-task');
            if (input && (!input.value || input.value === prev)) {
                input.value = id;
            }
            var hint = panelEl.querySelector('.fbaia-bp-detected');
            if (hint) { hint.textContent = cfg.i18n.detected + ': #' + id; hint.hidden = false; }
        }
    }

    function getJSON(url) {
        return fetch(url, { method: 'GET', headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return {}; }); });
    }

    function postJSON(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) { return r.json().catch(function () { return {}; }); });
    }

    function latestFor(taskId) {
        return getJSON(cfg.restRoot + 'suggestions?task_id=' + taskId).then(function (d) {
            var list = (d && d.suggestions) ? d.suggestions : [];
            return list.length ? list[0] : null; // newest first
        }).catch(function () { return null; });
    }

    function pct(n) { return Math.round((parseFloat(n) || 0) * 100); }

    function build() {
        if (document.querySelector('.fbaia-board-launcher')) { return; }

        btnEl = document.createElement('button');
        btnEl.type = 'button';
        btnEl.className = 'fbaia-board-launcher';
        btnEl.setAttribute('aria-haspopup', 'dialog');
        btnEl.setAttribute('aria-expanded', 'false');
        btnEl.title = cfg.i18n.title;
        btnEl.textContent = cfg.i18n.launcher;

        panelEl = document.createElement('div');
        panelEl.className = 'fbaia-board-panel';
        panelEl.setAttribute('role', 'dialog');
        panelEl.setAttribute('aria-label', cfg.i18n.title);
        panelEl.hidden = true;
        panelEl.innerHTML =
            '<div class="fbaia-bp-head"><strong>' + esc(cfg.i18n.title) + '</strong>' +
            '<button type="button" class="fbaia-bp-close" aria-label="' + esc(cfg.i18n.close) + '">×</button></div>' +
            '<p class="fbaia-bp-detected" hidden></p>' +
            '<label class="fbaia-bp-label" for="fbaia-bp-task">' + esc(cfg.i18n.taskId) + '</label>' +
            '<div class="fbaia-bp-row">' +
            '<input id="fbaia-bp-task" type="number" min="1" class="fbaia-bp-input" value="">' +
            '<button type="button" class="fbaia-bp-go">' + esc(cfg.i18n.analyze) + '</button></div>' +
            '<p class="fbaia-bp-status" role="status" aria-live="polite"></p>' +
            '<div class="fbaia-bp-result" hidden></div>' +
            '<div class="fbaia-bp-links"><a href="' + esc(cfg.reviewUrl) + '">' + esc(cfg.i18n.review) + '</a> · ' +
            '<a href="' + esc(cfg.settingsUrl) + '">' + esc(cfg.i18n.settings) + '</a></div>';

        document.body.appendChild(btnEl);
        document.body.appendChild(panelEl);
        wire();
        refreshDetected();
    }

    function wire() {
        function open() {
            panelEl.hidden = false;
            btnEl.setAttribute('aria-expanded', 'true');
            refreshDetected();
            var i = panelEl.querySelector('#fbaia-bp-task');
            if (i) { if (!i.value) { i.value = detected || ''; } i.focus(); }
        }
        function close() {
            panelEl.hidden = true;
            btnEl.setAttribute('aria-expanded', 'false');
            btnEl.focus();
        }
        btnEl.addEventListener('click', function () { panelEl.hidden ? open() : close(); });
        panelEl.querySelector('.fbaia-bp-close').addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panelEl.hidden) { close(); }
        });
        panelEl.querySelector('.fbaia-bp-go').addEventListener('click', analyze);
        panelEl.querySelector('#fbaia-bp-task').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); analyze(); }
        });
        window.addEventListener('hashchange', refreshDetected);
    }

    function renderResult(s) {
        var box = panelEl.querySelector('.fbaia-bp-result');
        currentSuggestionId = s.id || null;
        var prio = s.recommended_priority
            ? '<span class="fbaia-bp-badge fbaia-bp-prio-' + esc(s.recommended_priority) + '">' +
              esc(cfg.i18n.priority) + ': ' + esc(s.recommended_priority) + '</span>'
            : '';
        var actions = (s.status === 'pending')
            ? '<div class="fbaia-bp-actions">' +
              '<button type="button" class="fbaia-bp-approve">' + esc(cfg.i18n.approve) + '</button>' +
              '<button type="button" class="fbaia-bp-reject">' + esc(cfg.i18n.reject) + '</button></div>'
            : '<p class="fbaia-bp-pending">' + esc(s.status || '') + '</p>';
        box.innerHTML =
            '<p class="fbaia-bp-summary">' + esc(s.summary || s.decision_brief || '') + '</p>' +
            '<p class="fbaia-bp-meta">' + prio +
            '<span class="fbaia-bp-badge">' + esc(cfg.i18n.confidence) + ': ' + pct(s.confidence) + '%</span>' +
            '<span class="fbaia-bp-badge">' + esc(cfg.i18n.risk) + ': ' + pct(s.risk_score) + '%</span></p>' +
            actions;
        box.hidden = false;

        var ap = box.querySelector('.fbaia-bp-approve');
        if (ap) { ap.addEventListener('click', applyCurrent); }
        var rj = box.querySelector('.fbaia-bp-reject');
        if (rj) { rj.addEventListener('click', rejectCurrent); }
    }

    function setActions(html) {
        var el = panelEl.querySelector('.fbaia-bp-actions');
        if (el) { el.innerHTML = html; }
    }

    function applyCurrent() {
        if (!currentSuggestionId) { return; }
        setActions(esc(cfg.i18n.applying));
        postJSON(cfg.restRoot + 'suggestions/' + encodeURIComponent(currentSuggestionId) + '/apply', {})
            .then(function (d) {
                if (d && d.ok) {
                    setActions('<span class="fbaia-bp-applied">' + esc(cfg.i18n.applied) + '</span>');
                } else {
                    setActions(esc(cfg.i18n.error) + (d && d.error ? ' (' + esc(d.error) + ')' : ''));
                }
            }).catch(function () { setActions(esc(cfg.i18n.error)); });
    }

    function rejectCurrent() {
        if (!currentSuggestionId) { return; }
        var feedback = '';
        try { feedback = window.prompt(cfg.i18n.rejectPrompt, '') || ''; } catch (e) { feedback = ''; }
        setActions(esc(cfg.i18n.rejecting));
        postJSON(cfg.restRoot + 'suggestions/' + encodeURIComponent(currentSuggestionId) + '/reject', { feedback: feedback })
            .then(function (d) {
                if (d && d.ok) {
                    setActions('<span class="fbaia-bp-rejected">' + esc(cfg.i18n.rejected) + '</span>');
                } else {
                    setActions(esc(cfg.i18n.error) + (d && d.error ? ' (' + esc(d.error) + ')' : ''));
                }
            }).catch(function () { setActions(esc(cfg.i18n.error)); });
    }

    function poll(taskId, prevId, tries) {
        var status = panelEl.querySelector('.fbaia-bp-status');
        var go = panelEl.querySelector('.fbaia-bp-go');
        latestFor(taskId).then(function (s) {
            if (s && s.id !== prevId) {
                status.textContent = '';
                status.className = 'fbaia-bp-status is-ok';
                renderResult(s);
                go.disabled = false;
                return;
            }
            if (tries <= 0) {
                status.innerHTML = esc(cfg.i18n.stillRunning) + ' <a href="' + esc(cfg.reviewUrl) + '">' + esc(cfg.i18n.review) + '</a>';
                status.className = 'fbaia-bp-status is-warn';
                go.disabled = false;
                return;
            }
            setTimeout(function () { poll(taskId, prevId, tries - 1); }, 3000);
        });
    }

    function analyze() {
        var input = panelEl.querySelector('#fbaia-bp-task');
        var status = panelEl.querySelector('.fbaia-bp-status');
        var result = panelEl.querySelector('.fbaia-bp-result');
        var go = panelEl.querySelector('.fbaia-bp-go');
        var id = parseInt(input.value, 10);

        if (!id || id < 1) {
            status.textContent = cfg.i18n.needId;
            status.className = 'fbaia-bp-status is-warn';
            return;
        }
        if (!cfg.engineEnabled) {
            status.textContent = cfg.i18n.engineOff;
            status.className = 'fbaia-bp-status is-warn';
            return;
        }

        result.hidden = true;
        result.innerHTML = '';
        currentSuggestionId = null;
        status.textContent = cfg.i18n.working;
        status.className = 'fbaia-bp-status';
        go.disabled = true;

        latestFor(id).then(function (prev) {
            var prevId = prev ? prev.id : null;
            postJSON(cfg.restRoot + 'task/' + id + '/analyze', {}).then(function (d) {
                if (d && d.queued) {
                    status.textContent = cfg.i18n.analyzing;
                    status.className = 'fbaia-bp-status';
                    poll(id, prevId, 8);
                } else {
                    go.disabled = false;
                    var reason = (d && (d.reason || d.error)) ? (d.reason || d.error) : 'error';
                    status.textContent = cfg.i18n.notQueued + ' (' + reason + ')';
                    status.className = 'fbaia-bp-status is-warn';
                }
            }).catch(function () {
                go.disabled = false;
                status.textContent = cfg.i18n.error;
                status.className = 'fbaia-bp-status is-warn';
            });
        });
    }

    if (document.readyState !== 'loading') {
        build();
    } else {
        document.addEventListener('DOMContentLoaded', build);
    }
})();
