(function ($) {
    'use strict';

    function autoGrow(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(Math.max(textarea.scrollHeight, 120), 640) + 'px';
    }

    function getTemplate(key) {
        if (!window.FBAIAAdmin || !FBAIAAdmin.templates) {
            return '';
        }
        return FBAIAAdmin.templates[key] || '';
    }

    function createCopyButton() {
        $('.fbaia-card pre').each(function () {
            var $pre = $(this);
            if ($pre.parent().find('.fbaia-copy-pre').length) {
                return;
            }
            var $btn = $('<button type="button" class="button button-small fbaia-copy-pre">Copy</button>');
            $btn.on('click', function () {
                var text = $pre.text();
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text);
                }
                $btn.text((window.FBAIAAdmin && FBAIAAdmin.copied) || 'Copied');
                setTimeout(function () { $btn.text('Copy'); }, 1200);
            });
            $pre.before($btn);
        });
    }

    function addTextareaTools() {
        $('.fbaia-field-block textarea').each(function () {
            var textarea = this;
            var $textarea = $(textarea);
            if (!$textarea.prev('.fbaia-textarea-tools').length) {
                var $tools = $('<div class="fbaia-textarea-tools"><button type="button" class="button button-small fbaia-clear-field">Clear</button><button type="button" class="button button-small fbaia-copy-field">Copy</button></div>');
                $textarea.before($tools);
                $tools.find('.fbaia-clear-field').on('click', function () {
                    if (window.confirm('Clear this field?')) {
                        $textarea.val('').trigger('input');
                    }
                });
                $tools.find('.fbaia-copy-field').on('click', function () {
                    var text = $textarea.val();
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text);
                    }
                    $(this).text('Copied');
                    var btn = this;
                    setTimeout(function () { $(btn).text('Copy'); }, 1200);
                });
            }
            autoGrow(textarea);
        }).on('input', function () {
            autoGrow(this);
        });
    }

    function bindTemplateButtons() {
        $('.fbaia-template-button').on('click', function () {
            var key = $(this).data('template-target');
            var template = getTemplate(key);
            var $field = $('#fbaia-' + key);
            if (!$field.length || !template) {
                return;
            }
            var current = $field.val();
            if (current && !window.confirm('This field already has content. Append the template instead of replacing it?')) {
                return;
            }
            $field.val(current ? current.replace(/\s*$/, '\n\n---\n') + template : template).trigger('input').trigger('change');
            $('html, body').animate({ scrollTop: $field.offset().top - 120 }, 250);
            $field.focus();
        });
    }

    function enhanceTables() {
        $('.fbaia-suggestions-table, .fbaia-card table.widefat').each(function () {
            var $table = $(this);
            if (!$table.closest('.fbaia-table-scroll').length) {
                $table.wrap('<div class="fbaia-table-scroll"></div>');
            }
        });
    }

    // Warn before navigating away from a settings tab with unsaved edits. This is purely
    // an enhancement: forms still work and save correctly without JavaScript.
    function guardUnsavedChanges() {
        var dirty = false;
        var $forms = $('form[data-fbaia-dirty-watch]');
        if (!$forms.length) {
            return;
        }
        $forms.on('input change', 'input, textarea, select', function () {
            dirty = true;
        });
        $forms.on('submit', function () {
            dirty = false;
        });
        window.addEventListener('beforeunload', function (e) {
            if (!dirty) {
                return undefined;
            }
            var msg = (window.FBAIAAdmin && FBAIAAdmin.unsavedWarning) || 'You have unsaved changes.';
            e.preventDefault();
            e.returnValue = msg;
            return msg;
        });
    }

    // Visual recipe builder: add/remove recipes, conditions, and actions. Indices only need to
    // be unique within their parent array; a single incrementing counter guarantees that.
    function recipeBuilder() {
        var list = document.getElementById('fbaia-recipe-list');
        if (!list) {
            return;
        }
        var counter = 100000;
        function nextIdx() { return ++counter; }

        function tpl(id) {
            var el = document.getElementById(id);
            return el ? el.innerHTML : '';
        }

        function addRow(recipe, type) {
            var ri = recipe.getAttribute('data-ri');
            var token = type === 'condition' ? '__CINDEX__' : '__AINDEX__';
            var html = tpl(type === 'condition' ? 'fbaia-cond-tpl' : 'fbaia-action-tpl')
                .split('__RINDEX__').join(ri)
                .split(token).join(String(nextIdx()));
            var wrap = document.createElement('div');
            wrap.innerHTML = html.trim();
            var node = wrap.firstElementChild;
            recipe.querySelector(type === 'condition' ? '.fbaia-conditions' : '.fbaia-actions-list').appendChild(node);
        }

        $(list).closest('.fbaia-card').on('click', '.fbaia-add-recipe', function () {
            var html = tpl('fbaia-recipe-tpl').split('__RINDEX__').join(String(nextIdx()));
            var wrap = document.createElement('div');
            wrap.innerHTML = html.trim();
            var node = wrap.firstElementChild;
            list.appendChild(node);
            addRow(node, 'action'); // start with one action so the recipe is valid
        });
        $(list).on('click', '.fbaia-add-condition', function () {
            addRow($(this).closest('.fbaia-recipe')[0], 'condition');
        });
        $(list).on('click', '.fbaia-add-action', function () {
            addRow($(this).closest('.fbaia-recipe')[0], 'action');
        });
        $(list).on('click', '.fbaia-remove-recipe', function () {
            $(this).closest('.fbaia-recipe').remove();
        });
        $(list).on('click', '.fbaia-remove-row', function () {
            $(this).closest('.fbaia-recipe-row').remove();
        });
    }

    // AI Command Console: free text (or voice) -> REST interpret -> run, or confirm-then-run
    // for task-changing commands.
    function commandConsole() {
        var card = document.getElementById('fbaia-command');
        if (!card || !window.FBAIAAdmin || !FBAIAAdmin.restRoot) {
            return;
        }
        var input = card.querySelector('.fbaia-cmd-input');
        var sendBtn = card.querySelector('.fbaia-cmd-send');
        var micBtn = card.querySelector('.fbaia-cmd-mic');
        var out = card.querySelector('.fbaia-cmd-output');
        var i18n = FBAIAAdmin.cmd || {};
        var viaVoice = false;

        function speak(text) {
            if (!viaVoice || !window.speechSynthesis || !text) { return; }
            try {
                var u = new SpeechSynthesisUtterance(String(text));
                u.lang = (document.documentElement.lang || 'en-US');
                window.speechSynthesis.cancel();
                window.speechSynthesis.speak(u);
            } catch (e) { /* ignore */ }
        }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function post(path, payload) {
            return fetch(FBAIAAdmin.restRoot + path, {
                method: 'POST',
                headers: { 'X-WP-Nonce': FBAIAAdmin.nonce, 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload || {})
            }).then(function (r) { return r.json().catch(function () { return {}; }); });
        }

        function renderTasks(tasks) {
            if (!tasks || !tasks.length) { return ''; }
            var rows = tasks.slice(0, 25).map(function (t) {
                return '<li>#' + esc(t.id) + ' — ' + esc(t.title || '') +
                    (t.priority ? ' <em>(' + esc(t.priority) + ')</em>' : '') +
                    (t.due_at ? ' · ' + esc(t.due_at) : '') + '</li>';
            }).join('');
            return '<ul class="fbaia-cmd-tasks">' + rows + '</ul>';
        }

        function showResult(d) {
            var html = '<p class="' + (d.ok ? 'is-ok' : 'is-warn') + '">' + esc(d.message || (d.ok ? i18n.done : i18n.error)) + '</p>';
            if (d.data && d.data.tasks) { html += renderTasks(d.data.tasks); }
            if (d.data && d.data.next_actions && d.data.next_actions.length) {
                html += '<ul class="fbaia-cmd-tasks">' + d.data.next_actions.map(function (a) { return '<li>' + esc(a) + '</li>'; }).join('') + '</ul>';
            }
            if (d.needs_confirm) {
                html += '<div class="fbaia-cmd-confirm">' +
                    '<button type="button" class="button button-primary fbaia-cmd-yes">' + esc(i18n.confirm || 'Confirm') + '</button> ' +
                    '<button type="button" class="button fbaia-cmd-no">' + esc(i18n.cancel || 'Cancel') + '</button></div>';
            }
            out.innerHTML = html;
            speak(d.message);
            if (d.needs_confirm) {
                out.querySelector('.fbaia-cmd-yes').addEventListener('click', function () {
                    out.innerHTML = '<p>' + esc(i18n.thinking || '…') + '</p>';
                    post('command/execute', { intent: d.intent, params: d.params }).then(showResult);
                });
                out.querySelector('.fbaia-cmd-no').addEventListener('click', function () { out.innerHTML = ''; viaVoice = false; });
            } else {
                viaVoice = false;
            }
        }

        function run() {
            var text = (input.value || '').trim();
            if (!text) { return; }
            out.innerHTML = '<p>' + esc(i18n.thinking || '…') + '</p>';
            sendBtn.disabled = true;
            post('command', { text: text }).then(function (d) {
                sendBtn.disabled = false;
                if (d && d.error) { out.innerHTML = '<p class="is-warn">' + esc(d.error) + '</p>'; return; }
                showResult(d);
            }).catch(function () { sendBtn.disabled = false; out.innerHTML = '<p class="is-warn">' + esc(i18n.error) + '</p>'; });
        }

        sendBtn.addEventListener('click', run);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });

        // Voice input via the Web Speech API (graceful fallback when unsupported).
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) {
            micBtn.disabled = true;
            micBtn.title = i18n.micUnsupported || '';
        } else {
            micBtn.addEventListener('click', function () {
                var rec = new SR();
                rec.lang = (document.documentElement.lang || 'en-US');
                rec.interimResults = false;
                rec.maxAlternatives = 1;
                micBtn.classList.add('is-listening');
                micBtn.setAttribute('aria-pressed', 'true');
                out.innerHTML = '<p>' + esc(i18n.listening || '…') + '</p>';
                rec.onresult = function (ev) {
                    var said = ev.results[0][0].transcript;
                    input.value = said;
                    viaVoice = true;
                    run();
                };
                rec.onerror = function () { out.innerHTML = ''; };
                rec.onend = function () { micBtn.classList.remove('is-listening'); micBtn.setAttribute('aria-pressed', 'false'); };
                rec.start();
            });
        }
    }

    $(function () {
        addTextareaTools();
        bindTemplateButtons();
        createCopyButton();
        enhanceTables();
        guardUnsavedChanges();
        recipeBuilder();
        commandConsole();
    });
})(jQuery);
