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
    // v0.4.0 — Asset Studio copy buttons: the value travels in the attribute
    // (delegated so module pages rendered later still work).
    document.addEventListener('click', function (event) {
      var btn = event.target.closest ? event.target.closest('[data-fi-copy]') : null;
      if (!btn) { return; }
      var text = btn.getAttribute('data-fi-copy') || '';
      if (text === '') { return; }
      var done = function () {
        btn.classList.add('is-copied');
        var t = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(function () { btn.classList.remove('is-copied'); btn.textContent = t; }, 1400);
      };
      var fallback = function () {
        try {
          var ta = document.createElement('textarea');
          ta.value = text;
          ta.setAttribute('readonly', 'readonly');
          ta.style.position = 'fixed';
          ta.style.opacity = '0';
          document.body.appendChild(ta);
          ta.select();
          document.execCommand('copy');
          document.body.removeChild(ta);
          done();
        } catch (e) { /* clipboard unavailable */ }
      };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, fallback); }
      else { fallback(); }
    });
  });
})();
