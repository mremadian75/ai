/*
 * Future Island — Signal Room / Workbench minimal admin JS (Phase 7A/8A).
 * Read-only: copy a JSON block to clipboard; toggle a details block. No AI call,
 * no mutation, no fake state, no action enabling.
 */
(function () {
  'use strict';
  function ready(fn) { if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  ready(function () {
    document.querySelectorAll('[data-fiis-copy-target]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var el = document.querySelector(btn.getAttribute('data-fiis-copy-target'));
        var text = el ? (el.textContent || '') : '';
        if (!text) { return; }
        var done = function () { var t = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = t; }, 1200); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () {}); }
        else { try { var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); done(); } catch (e) {} }
      });
    });
    document.querySelectorAll('[data-fiis-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () { var el = document.querySelector(btn.getAttribute('data-fiis-toggle')); if (el) { el.hidden = !el.hidden; } });
    });
  });
})();
