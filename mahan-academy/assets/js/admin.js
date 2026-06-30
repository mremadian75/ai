/**
 * Mahan Academy — admin scripts.
 * - Settings tab switching
 * - AI connection tester
 * - Lesson exercise builder
 */
(function ($) {
	'use strict';

	$(function () {
		initTabs();
		initAiTester();
		initExerciseBuilder();
	});

	/* ------------------------------------------------------------------ */
	/* Settings tabs                                                       */
	/* ------------------------------------------------------------------ */

	function initTabs() {
		var $tabs   = $('.mahan-tabs a.nav-tab');
		var $panels = $('.mahan-tab-panel');
		if (!$tabs.length) { return; }

		function show(hash) {
			$tabs.removeClass('nav-tab-active');
			$tabs.filter('[href="' + hash + '"]').addClass('nav-tab-active');
			$panels.hide();
			$(hash).show();
		}

		$tabs.on('click', function (e) {
			e.preventDefault();
			var hash = $(this).attr('href');
			show(hash);
			if (history && history.replaceState) {
				history.replaceState(null, '', hash);
			}
		});

		var initial = window.location.hash;
		if (initial && $(initial).length) {
			show(initial);
		} else {
			show($tabs.first().attr('href'));
		}
	}

	/* ------------------------------------------------------------------ */
	/* AI connection tester                                                */
	/* ------------------------------------------------------------------ */

	function initAiTester() {
		var $btn  = $('#mahan-test-ai');
		var $out  = $('#mahan-test-result');
		var $prov = $('#mahan_ai_provider');
		if (!$btn.length) { return; }

		$btn.on('click', function () {
			$out.removeClass('is-ok is-err').text((window.MahanAdmin && MahanAdmin.i18n.testing) || 'Testing…');
			$.post(MahanAdmin.ajaxUrl, {
				action: 'mahan_test_ai',
				nonce: MahanAdmin.nonce,
				provider: $prov.val()
			}).done(function (r) {
				if (r && r.success) {
					$out.addClass('is-ok').text(r.data.message);
				} else {
					$out.addClass('is-err').text((r && r.data && r.data.message) || 'Failed.');
				}
			}).fail(function () {
				$out.addClass('is-err').text('Request failed.');
			});
		});
	}

	/* ------------------------------------------------------------------ */
	/* Exercise builder                                                    */
	/* ------------------------------------------------------------------ */

	function initExerciseBuilder() {
		var $root = $('#mahan-exercise-builder');
		if (!$root.length) { return; }
		var I = (window.MahanAdmin && MahanAdmin.i18n) || {};
		var exercises;
		try { exercises = JSON.parse($root.attr('data-exercises') || '[]'); } catch (e) { exercises = []; }
		if (!Array.isArray(exercises)) { exercises = []; }

		var $list   = $root.find('.mahan-ex-list');
		var $hidden = $('#mahan_exercises_json');

		function uid() { return 'ex_' + Math.random().toString(36).slice(2, 8); }

		function ensureKey(ex) {
			if (!ex.key) { ex.key = uid(); }
			return ex;
		}

		function persist() {
			$hidden.val(JSON.stringify(exercises));
		}

		function render() {
			$list.empty();
			if (!exercises.length) {
				$list.append($('<p class="mahan-ex-empty"></p>').text(I.noExercises || 'No exercises yet.'));
			}
			exercises.forEach(function (ex, idx) {
				$list.append(renderCard(ex, idx));
			});
			persist();
		}

		function renderCard(ex, idx) {
			ensureKey(ex);
			var $card = $('<div class="mahan-ex-edit"></div>');
			$card.append('<div class="mahan-ex-edit-head"><strong>#' + (idx + 1) + '</strong> <button type="button" class="button-link mahan-ex-remove">' + esc(I.remove || 'Remove') + '</button></div>');

			// Type.
			var $type = $('<select class="mahan-ex-type"></select>');
			[['multiple_choice', I.multiple_choice], ['short_answer', I.short_answer], ['reflection', I.reflection], ['prompt_task', I.prompt_task]].forEach(function (t) {
				var $o = $('<option></option>').val(t[0]).text(t[1] || t[0]);
				if (t[0] === ex.type) { $o.prop('selected', true); }
				$type.append($o);
			});
			$card.append($('<div class="mahan-ex-row"></div>').append($('<label></label>').text(I.type || 'Type')).append($type));

			// Question/task body wrapper (re-rendered when type changes).
			var $body = $('<div class="mahan-ex-body"></div>');
			$card.append($body);
			renderBody($body, ex, idx);

			// Hint + XP.
			var $hint = $('<input type="text" />').val(ex.hint || '');
			$hint.on('input', function () { ex.hint = $(this).val(); persist(); });
			$card.append($('<div class="mahan-ex-row"></div>').append($('<label></label>').text(I.hint || 'Hint (optional)')).append($hint));

			var $xp = $('<input type="number" min="0" />').val(ex.xp || 0).css('width', '90px');
			$xp.on('input', function () { ex.xp = parseInt($(this).val(), 10) || 0; persist(); });
			$card.append($('<div class="mahan-ex-row"></div>').append($('<label></label>').text(I.xp || 'XP')).append($xp));

			$type.on('change', function () {
				ex.type = $(this).val();
				renderBody($body, ex, idx);
				persist();
			});
			$card.on('click', '.mahan-ex-remove', function () {
				exercises.splice(idx, 1);
				render();
			});
			return $card;
		}

		function renderBody($body, ex, idx) {
			$body.empty();

			// Question (for MC + short answer + reflection); for prompt_task we
			// store the brief in `question` and show `task` separately.
			var qLabel = (ex.type === 'prompt_task') ? (I.question || 'Question') : (I.question || 'Question');
			var $q = $('<textarea rows="3"></textarea>').val(ex.question || '');
			$q.on('input', function () { ex.question = $(this).val(); persist(); });
			$body.append($('<div class="mahan-ex-row"></div>').append($('<label></label>').text(qLabel)).append($q));

			if (ex.type === 'multiple_choice') {
				if (!Array.isArray(ex.options)) { ex.options = []; }
				if (typeof ex.answer !== 'number') { ex.answer = 0; }

				var $opts = $('<div class="mahan-ex-opts"></div>');
				function renderOpts() {
					$opts.empty();
					ex.options.forEach(function (text, i) {
						var $row = $('<div class="mahan-ex-opt"></div>');
						var $radio = $('<input type="radio" />').prop('checked', ex.answer === i)
							.on('change', function () { ex.answer = i; persist(); });
						var $input = $('<input type="text" />').val(text).attr('placeholder', (I.option || 'Option') + ' ' + (i + 1));
						$input.on('input', function () { ex.options[i] = $(this).val(); persist(); });
						var $rm = $('<button type="button" class="button-link"></button>').text('×')
							.on('click', function () {
								ex.options.splice(i, 1);
								if (ex.answer >= ex.options.length) { ex.answer = Math.max(0, ex.options.length - 1); }
								renderOpts();
								persist();
							});
						$row.append($radio).append($input).append($rm);
						$opts.append($row);
					});
					var $add = $('<button type="button" class="button button-secondary mahan-ex-add-opt"></button>').text('+ ' + (I.addOption || 'Add option'));
					$add.on('click', function () {
						ex.options.push('');
						renderOpts();
						persist();
					});
					$opts.append($add);
				}
				renderOpts();
				$body.append($('<div class="mahan-ex-row"></div>').append($('<label></label>').text((I.correct || 'Correct') + ' / ' + (I.option || 'Option'))).append($opts));

				var $fc = $('<textarea rows="2"></textarea>').val(ex.feedback_correct || '');
				$fc.on('input', function () { ex.feedback_correct = $(this).val(); persist(); });
				$body.append($('<div class="mahan-ex-row"></div>').append($('<label></label>').text('Feedback (correct)')).append($fc));

				var $fi = $('<textarea rows="2"></textarea>').val(ex.feedback_incorrect || '');
				$fi.on('input', function () { ex.feedback_incorrect = $(this).val(); persist(); });
				$body.append($('<div class="mahan-ex-row"></div>').append($('<label></label>').text('Feedback (incorrect)')).append($fi));
			} else {
				if (ex.type === 'prompt_task') {
					var $task = $('<textarea rows="3"></textarea>').val(ex.task || '');
					$task.on('input', function () { ex.task = $(this).val(); persist(); });
					$body.append($('<div class="mahan-ex-row"></div>').append($('<label></label>').text(I.task || 'Task for the student')).append($task));
				}
				var $rubric = $('<textarea rows="4"></textarea>').val(ex.rubric || '');
				$rubric.on('input', function () { ex.rubric = $(this).val(); persist(); });
				$body.append($('<div class="mahan-ex-row"></div>').append($('<label></label>').text(I.rubric || 'Rubric')).append($rubric));

				var $ph = $('<input type="text" />').val(ex.placeholder || '');
				$ph.on('input', function () { ex.placeholder = $(this).val(); persist(); });
				$body.append($('<div class="mahan-ex-row"></div>').append($('<label></label>').text('Placeholder')).append($ph));
			}
		}

		$('#mahan-add-exercise').on('click', function () {
			exercises.push({ key: uid(), type: 'multiple_choice', question: '', options: ['', ''], answer: 0, xp: 0 });
			render();
		});

		function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

		render();
	}
})(jQuery);
