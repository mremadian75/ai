/**
 * Fluent Boards AI — in-board "AI Analyze" launcher.
 * Vanilla JS, no framework/jQuery dependency. Admin-only (gated server-side at enqueue).
 * Calls the plugin's capability-protected REST endpoint; never handles secrets.
 */
(function () {
    'use strict';

    if (!window.FBAIABoard || !window.FBAIABoard.restRoot) {
        return;
    }
    var cfg = window.FBAIABoard;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Best-effort task id from the Fluent Boards hash route (e.g. #/boards/3/tasks/42).
    function detectTaskId() {
        var h = window.location.hash || '';
        var m = h.match(/task[s]?[\/=_-]?(\d+)/i);
        return m ? m[1] : '';
    }

    function build() {
        if (document.querySelector('.fbaia-board-launcher')) {
            return;
        }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fbaia-board-launcher';
        btn.setAttribute('aria-haspopup', 'dialog');
        btn.setAttribute('aria-expanded', 'false');
        btn.title = cfg.i18n.title;
        btn.textContent = cfg.i18n.launcher;

        var panel = document.createElement('div');
        panel.className = 'fbaia-board-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', cfg.i18n.title);
        panel.hidden = true;
        panel.innerHTML =
            '<div class="fbaia-bp-head"><strong>' + esc(cfg.i18n.title) + '</strong>' +
            '<button type="button" class="fbaia-bp-close" aria-label="' + esc(cfg.i18n.close) + '">×</button></div>' +
            '<label class="fbaia-bp-label" for="fbaia-bp-task">' + esc(cfg.i18n.taskId) + '</label>' +
            '<div class="fbaia-bp-row">' +
            '<input id="fbaia-bp-task" type="number" min="1" class="fbaia-bp-input" value="' + esc(detectTaskId()) + '">' +
            '<button type="button" class="fbaia-bp-go">' + esc(cfg.i18n.analyze) + '</button></div>' +
            '<p class="fbaia-bp-status" role="status" aria-live="polite"></p>' +
            '<div class="fbaia-bp-result" hidden></div>' +
            '<div class="fbaia-bp-links"><a href="' + esc(cfg.reviewUrl) + '">' + esc(cfg.i18n.review) + '</a> · ' +
            '<a href="' + esc(cfg.settingsUrl) + '">' + esc(cfg.i18n.settings) + '</a></div>';

        document.body.appendChild(btn);
        document.body.appendChild(panel);
        wire(btn, panel);
    }

    function wire(btn, panel) {
        function open() {
            panel.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            var i = panel.querySelector('#fbaia-bp-task');
            if (i) {
                if (!i.value) { i.value = detectTaskId(); }
                i.focus();
            }
        }
        function close() {
            panel.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
            btn.focus();
        }
        btn.addEventListener('click', function () { panel.hidden ? open() : close(); });
        panel.querySelector('.fbaia-bp-close').addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) { close(); }
        });
        panel.querySelector('.fbaia-bp-go').addEventListener('click', function () { analyze(panel); });
        panel.querySelector('#fbaia-bp-task').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); analyze(panel); }
        });
    }

    function getJSON(url) {
        return fetch(url, {
            method: 'GET',
            headers: { 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin'
        }).then(function (r) { return r.json().catch(function () { return {}; }); });
    }

    function latestFor(taskId) {
        return getJSON(cfg.restRoot + 'suggestions?task_id=' + taskId).then(function (d) {
            var list = (d && d.suggestions) ? d.suggestions : [];
            return list.length ? list[0] : null; // newest first
        }).catch(function () { return null; });
    }

    function pct(n) { return Math.round((parseFloat(n) || 0) * 100); }

    function renderResult(panel, s) {
        var box = panel.querySelector('.fbaia-bp-result');
        var prio = s.recommended_priority ? '<span class="fbaia-bp-badge fbaia-bp-prio-' + esc(s.recommended_priority) + '">' +
            esc(cfg.i18n.priority) + ': ' + esc(s.recommended_priority) + '</span>' : '';
        box.innerHTML =
            '<p class="fbaia-bp-summary">' + esc(s.summary || s.decision_brief || '') + '</p>' +
            '<p class="fbaia-bp-meta">' + prio +
            '<span class="fbaia-bp-badge">' + esc(cfg.i18n.confidence) + ': ' + pct(s.confidence) + '%</span>' +
            '<span class="fbaia-bp-badge">' + esc(cfg.i18n.risk) + ': ' + pct(s.risk_score) + '%</span></p>' +
            '<p class="fbaia-bp-pending">' + esc(cfg.i18n.pendingReview) + ' · ' +
            '<a href="' + esc(cfg.reviewUrl) + '">' + esc(cfg.i18n.review) + '</a></p>';
        box.hidden = false;
    }

    function poll(panel, taskId, prevId, tries) {
        var status = panel.querySelector('.fbaia-bp-status');
        var go = panel.querySelector('.fbaia-bp-go');
        latestFor(taskId).then(function (s) {
            if (s && s.id !== prevId) {
                status.textContent = '';
                status.className = 'fbaia-bp-status is-ok';
                renderResult(panel, s);
                go.disabled = false;
                return;
            }
            if (tries <= 0) {
                status.innerHTML = esc(cfg.i18n.stillRunning) + ' <a href="' + esc(cfg.reviewUrl) + '">' + esc(cfg.i18n.review) + '</a>';
                status.className = 'fbaia-bp-status is-warn';
                go.disabled = false;
                return;
            }
            setTimeout(function () { poll(panel, taskId, prevId, tries - 1); }, 3000);
        });
    }

    function analyze(panel) {
        var input = panel.querySelector('#fbaia-bp-task');
        var status = panel.querySelector('.fbaia-bp-status');
        var result = panel.querySelector('.fbaia-bp-result');
        var go = panel.querySelector('.fbaia-bp-go');
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
        status.textContent = cfg.i18n.working;
        status.className = 'fbaia-bp-status';
        go.disabled = true;

        // Record the newest existing suggestion id for this task so we can detect the new one.
        latestFor(id).then(function (prev) {
            var prevId = prev ? prev.id : null;
            fetch(cfg.restRoot + 'task/' + id + '/analyze', {
                method: 'POST',
                headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
                credentials: 'same-origin'
            }).then(function (r) {
                return r.json().catch(function () { return {}; });
            }).then(function (d) {
                if (d && d.queued) {
                    status.textContent = cfg.i18n.analyzing;
                    status.className = 'fbaia-bp-status';
                    poll(panel, id, prevId, 8);
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
