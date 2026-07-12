/**
 * Mahan Academy — Course Builder.
 * A drag-and-drop curriculum editor for the Course edit screen: units and
 * lessons in one tree, inline add/rename, quick per-lesson settings, and
 * persistence over admin-ajax. Requires jQuery + jQuery UI sortable (bundled).
 */
(function ($) {
	'use strict';

	var CFG = window.MahanCB || {};
	var I = CFG.i18n || {};

	function t(key, fallback) { return I[key] || fallback || key; }

	$(function () {
		var $root = $('#mahan-cb');
		if (!$root.length) { return; }
		new Builder($root);
	});

	function Builder($root) {
		this.$root = $root;
		this.courseId = parseInt($root.attr('data-course'), 10) || 0;
		try { this.model = JSON.parse($root.attr('data-tree') || '[]'); } catch (e) { this.model = []; }
		if (!Array.isArray(this.model)) { this.model = []; }
		this.$units = $root.find('#mahan-cb-units');
		this.$empty = $root.find('#mahan-cb-empty');
		this.$saving = $root.find('#mahan-cb-saving');
		this.saveTimer = null;
		this.bind();
		this.render();
	}

	Builder.prototype.ajax = function (action, data) {
		var payload = $.extend({ action: action, nonce: CFG.nonce }, data);
		return $.post(CFG.ajaxUrl, payload).then(
			function (r) {
				if (r && r.success) { return r.data || {}; }
				return $.Deferred().reject((r && r.data && r.data.message) || 'Error');
			},
			function (jqXHR) {
				// Coded guard/validation errors arrive as non-2xx responses,
				// so jQuery rejects with the jqXHR — pull the message out of it
				// instead of letting .fail() render "[object Object]".
				var msg = (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message)
					|| (jqXHR && jqXHR.statusText) || 'Error';
				return $.Deferred().reject(msg);
			}
		);
	};

	Builder.prototype.flash = function (msg, isError) {
		this.$saving.text(msg).toggleClass('is-error', !!isError).addClass('is-show');
		var $s = this.$saving;
		clearTimeout(this._flashT);
		this._flashT = setTimeout(function () { $s.removeClass('is-show'); }, 1600);
	};

	Builder.prototype.bind = function () {
		var self = this;
		this.$root.on('click', '#mahan-cb-add-unit', function () { self.addUnit(); });
	};

	/* ---------------------------------------------------------------- */
	/* Rendering                                                         */
	/* ---------------------------------------------------------------- */

	Builder.prototype.render = function () {
		var self = this;
		this.$units.empty();
		this.model.forEach(function (unit, ui) {
			self.$units.append(self.renderUnit(unit, ui));
		});
		this.$empty.toggle(this.totalLessons() === 0);
		this.makeSortable();
		this.updateStats();
	};

	Builder.prototype.renderUnit = function (unit, ui) {
		var self = this;
		var $u = $('<div class="mahan-cb-unit" />').attr('data-unit', ui);
		$u.data('quiz', unit.quiz || null);
		var $head = $('<div class="mahan-cb-unit-head" />');
		$head.append('<span class="mahan-cb-drag dashicons dashicons-move" title="' + esc(t('dragUnit', 'Drag to reorder unit')) + '"></span>');
		var $title = $('<input type="text" class="mahan-cb-unit-title" />').val(unit.title || '');
		$title.attr('placeholder', t('unitName', 'Unit name'));
		$title.on('change blur', function () { unit.title = $(this).val(); self.saveStructure(); });
		$head.append($title);
		$head.append('<span class="mahan-cb-unit-count">' + (unit.lessons ? unit.lessons.length : 0) + ' ' + esc(t('lessons', 'lessons')) + '</span>');
		var qn = (unit.quiz && unit.quiz.questions) ? unit.quiz.questions.length : 0;
		var $quiz = $('<button type="button" class="button button-small mahan-cb-quiz-btn"></button>')
			.html('<span class="dashicons dashicons-forms"></span> ' + esc(t('quiz', 'Quiz')) + (qn ? ' (' + qn + ')' : ''));
		if (qn) { $quiz.addClass('has-quiz'); }
		$quiz.on('click', function () { self.editQuiz(unit, $u, $quiz); });
		$head.append($quiz);
		var $del = $('<button type="button" class="button-link mahan-cb-unit-del" title="' + esc(t('deleteUnit', 'Delete empty unit')) + '"><span class="dashicons dashicons-trash"></span></button>');
		$del.on('click', function () { self.deleteUnit(ui); });
		$head.append($del);
		$u.append($head);

		var $lessons = $('<div class="mahan-cb-lessons" />').attr('data-unit', ui);
		(unit.lessons || []).forEach(function (node) {
			$lessons.append(self.renderLesson(node));
		});
		$u.append($lessons);

		var $add = $('<button type="button" class="button button-secondary mahan-cb-add-lesson">+ ' + esc(t('addLesson', 'Add lesson')) + '</button>');
		$add.on('click', function () { self.addLesson(ui, $lessons); });
		$u.append($('<div class="mahan-cb-unit-foot" />').append($add));
		return $u;
	};

	Builder.prototype.renderLesson = function (node) {
		var self = this;
		var $l = $('<div class="mahan-cb-lesson" />').attr('data-id', node.id).data('node', node);
		$l.append('<span class="mahan-cb-drag dashicons dashicons-menu" title="' + esc(t('dragLesson', 'Drag to reorder')) + '"></span>');

		var typeIcon = node.type === 'video' ? 'dashicons-video-alt3' : (node.type === 'practice' ? 'dashicons-edit' : 'dashicons-media-document');
		$l.append('<span class="mahan-cb-lesson-icon dashicons ' + typeIcon + '"></span>');

		var $title = $('<input type="text" class="mahan-cb-lesson-title" />').val(node.title || '');
		$title.on('change blur', function () {
			var v = $(this).val();
			if (v === node.title) { return; }
			self.ajax('mahan_cb_update_lesson', { lesson_id: node.id, title: v })
				.then(function (d) { node.title = d.lesson.title; self.flash(t('saved', 'Saved')); })
				.fail(function (m) { self.flash(m, true); });
		});
		$l.append($title);

		var $meta = $('<div class="mahan-cb-lesson-meta" />');

		var $type = $('<select class="mahan-cb-lesson-type" />');
		[['reading', t('reading', 'Reading')], ['practice', t('practice', 'Practice')], ['video', t('video', 'Video')]].forEach(function (o) {
			var $o = $('<option/>').val(o[0]).text(o[1]);
			if (o[0] === node.type) { $o.prop('selected', true); }
			$type.append($o);
		});
		$type.on('change', function () {
			node.type = $(this).val();
			self.ajax('mahan_cb_update_lesson', { lesson_id: node.id, type: node.type })
				.then(function () { self.render(); self.flash(t('saved', 'Saved')); })
				.fail(function (m) { self.flash(m, true); });
		});
		$meta.append($wrapField(t('type', 'Type'), $type));

		var $xp = $('<input type="number" min="0" class="mahan-cb-lesson-xp small-text" />').val(node.xp || 0);
		$xp.on('change', function () {
			node.xp = parseInt($(this).val(), 10) || 0;
			self.ajax('mahan_cb_update_lesson', { lesson_id: node.id, xp: node.xp })
				.then(function () { self.updateStats(); self.flash(t('saved', 'Saved')); })
				.fail(function (m) { self.flash(m, true); });
		});
		$meta.append($wrapField('XP', $xp));

		var $min = $('<input type="number" min="0" class="mahan-cb-lesson-min small-text" />').val(node.est_min || 0);
		$min.on('change', function () {
			node.est_min = parseInt($(this).val(), 10) || 0;
			self.ajax('mahan_cb_update_lesson', { lesson_id: node.id, est_min: node.est_min })
				.then(function () { self.flash(t('saved', 'Saved')); })
				.fail(function (m) { self.flash(m, true); });
		});
		$meta.append($wrapField(t('minutes', 'Min'), $min));

		if (node.exercises) {
			$meta.append('<span class="mahan-cb-badge">' + node.exercises + ' ' + esc(t('exercises', 'exercises')) + '</span>');
		}
		$l.append($meta);

		var $actions = $('<div class="mahan-cb-lesson-actions" />');
		if (node.edit_url) {
			$actions.append('<a class="button button-small" href="' + esc(node.edit_url) + '" target="_blank" rel="noopener">' + esc(t('editContent', 'Edit content')) + '</a>');
		}
		var $dup = $('<button type="button" class="button button-small" title="' + esc(t('duplicate', 'Duplicate')) + '"><span class="dashicons dashicons-admin-page"></span></button>');
		$dup.on('click', function () { self.duplicateLesson(node); });
		$actions.append($dup);
		var $del = $('<button type="button" class="button button-small mahan-cb-lesson-del" title="' + esc(t('delete', 'Delete')) + '"><span class="dashicons dashicons-trash"></span></button>');
		$del.on('click', function () { self.deleteLesson(node, $l); });
		$actions.append($del);
		$l.append($actions);

		return $l;
	};

	function $wrapField(label, $input) {
		return $('<label class="mahan-cb-field" />').append('<span>' + esc(label) + '</span>').append($input);
	}

	/* ---------------------------------------------------------------- */
	/* Sortable                                                          */
	/* ---------------------------------------------------------------- */

	Builder.prototype.makeSortable = function () {
		var self = this;
		if (!$.fn.sortable) { return; }

		this.$units.sortable({
			handle: '> .mahan-cb-unit-head > .mahan-cb-drag',
			items: '> .mahan-cb-unit',
			axis: 'y',
			tolerance: 'pointer',
			update: function () { self.syncFromDom(); }
		});

		this.$units.find('.mahan-cb-lessons').sortable({
			handle: '> .mahan-cb-lesson > .mahan-cb-drag',
			items: '> .mahan-cb-lesson',
			connectWith: '.mahan-cb-lessons',
			placeholder: 'mahan-cb-placeholder',
			tolerance: 'pointer',
			update: function (ev, ui) {
				// Only sync once (the receiving list fires update).
				if (this === ui.item.parent()[0]) { self.syncFromDom(); }
			}
		});
	};

	/* Rebuild the model from the current DOM order, then persist. */
	Builder.prototype.syncFromDom = function () {
		var self = this;
		var model = [];
		this.$units.find('.mahan-cb-unit').each(function () {
			var $u = $(this);
			var title = $u.find('.mahan-cb-unit-title').val() || '';
			var lessons = [];
			$u.find('.mahan-cb-lesson').each(function () {
				lessons.push($(this).data('node'));
			});
			model.push({ title: title, quiz: $u.data('quiz') || null, lessons: lessons });
		});
		this.model = model;
		// Re-attach unit indices + counts without a full re-render (keeps focus).
		this.$units.find('.mahan-cb-unit').each(function (i) {
			$(this).attr('data-unit', i);
			$(this).find('.mahan-cb-lessons').attr('data-unit', i);
			$(this).find('.mahan-cb-unit-count').text($(this).find('.mahan-cb-lesson').length + ' ' + t('lessons', 'lessons'));
		});
		this.updateStats();
		this.saveStructure();
	};

	/* ---------------------------------------------------------------- */
	/* Actions                                                           */
	/* ---------------------------------------------------------------- */

	Builder.prototype.addUnit = function () {
		this.model.push({ title: t('newUnit', 'New unit'), lessons: [] });
		this.render();
		this.saveStructure();
		this.$units.find('.mahan-cb-unit').last().find('.mahan-cb-unit-title').focus().select();
	};

	Builder.prototype.deleteUnit = function (ui) {
		var unit = this.model[ui];
		if (!unit) { return; }
		if (unit.lessons && unit.lessons.length) {
			window.alert(t('unitNotEmpty', 'Move or delete its lessons first.'));
			return;
		}
		this.model.splice(ui, 1);
		this.render();
		this.saveStructure();
	};

	Builder.prototype.addLesson = function (ui, $lessons) {
		var self = this;
		var unit = this.model[ui];
		if (!unit) { return; }
		var title = window.prompt(t('lessonTitle', 'Lesson title'), '');
		if (title === null) { return; }
		this.ajax('mahan_cb_add_lesson', { course_id: this.courseId, unit: unit.title || '', title: title })
			.then(function (d) {
				unit.lessons = unit.lessons || [];
				unit.lessons.push(d.lesson);
				self.render();
				self.saveStructure();
				self.flash(t('lessonAdded', 'Lesson added'));
			})
			.fail(function (m) { self.flash(m, true); });
	};

	Builder.prototype.duplicateLesson = function (node) {
		var self = this;
		this.ajax('mahan_cb_duplicate_lesson', { lesson_id: node.id })
			.then(function (d) {
				var unit = self.findUnitOf(node.id);
				if (unit) {
					var idx = unit.lessons.indexOf(node);
					unit.lessons.splice(idx + 1, 0, d.lesson);
				}
				self.render();
				self.saveStructure();
				self.flash(t('duplicated', 'Duplicated'));
			})
			.fail(function (m) { self.flash(m, true); });
	};

	Builder.prototype.deleteLesson = function (node, $l) {
		var self = this;
		if (!window.confirm(t('confirmDelete', 'Delete this lesson? It will be moved to Trash.'))) { return; }
		this.ajax('mahan_cb_delete_lesson', { lesson_id: node.id })
			.then(function () {
				var unit = self.findUnitOf(node.id);
				if (unit) { unit.lessons.splice(unit.lessons.indexOf(node), 1); }
				self.render();
				self.saveStructure();
				self.flash(t('deleted', 'Deleted'));
			})
			.fail(function (m) { self.flash(m, true); });
	};

	Builder.prototype.findUnitOf = function (lessonId) {
		for (var i = 0; i < this.model.length; i++) {
			var ls = this.model[i].lessons || [];
			for (var j = 0; j < ls.length; j++) { if (ls[j].id === lessonId) { return this.model[i]; } }
		}
		return null;
	};

	/* ---------------------------------------------------------------- */
	/* Unit quiz editor (modal)                                          */
	/* ---------------------------------------------------------------- */

	Builder.prototype.editQuiz = function (unit, $u, $quizBtn) {
		var self = this;
		// Deep clone the working quiz so Cancel discards changes.
		var quiz = unit.quiz ? JSON.parse(JSON.stringify(unit.quiz)) : { title: '', passing: 70, xp: 0, questions: [] };
		if (!Array.isArray(quiz.questions)) { quiz.questions = []; }

		var $body = $('<div class="mahan-cb-quiz-body" />');

		function renderQuestions() {
			$body.empty();
			if (!quiz.questions.length) {
				$body.append('<p class="description">' + esc(t('noQuestions', 'No questions yet. Add one below.')) + '</p>');
			}
			quiz.questions.forEach(function (q, idx) { $body.append(renderQ(q, idx)); });
		}

		function renderQ(q, idx) {
			var $q = $('<div class="mahan-cb-q" />');
			$q.append('<div class="mahan-cb-q-head"><strong>#' + (idx + 1) + '</strong> <button type="button" class="button-link mahan-cb-q-del">' + esc(t('remove', 'Remove')) + '</button></div>');

			var $type = $('<select />');
			[['multiple_choice', t('multiple_choice', 'Multiple choice')], ['true_false', t('true_false', 'True / False')], ['fill_blank', t('fill_blank', 'Fill in the blank')]].forEach(function (o) {
				var $o = $('<option/>').val(o[0]).text(o[1]);
				if (o[0] === q.type) { $o.prop('selected', true); }
				$type.append($o);
			});
			$type.on('change', function () { q.type = $(this).val(); renderQuestions(); });
			$q.append(row(t('type', 'Type'), $type));

			var $ques = $('<textarea rows="2" />').val(q.question || '');
			$ques.on('input', function () { q.question = $(this).val(); });
			$q.append(row(t('question', 'Question'), $ques));

			if (q.type === 'multiple_choice') {
				if (!Array.isArray(q.options)) { q.options = ['', '']; }
				if (typeof q.answer !== 'number') { q.answer = 0; }
				var $opts = $('<div class="mahan-cb-q-opts" />');
				function renderOpts() {
					$opts.empty();
					q.options.forEach(function (text, i) {
						var $r = $('<div class="mahan-cb-q-opt" />');
						var $radio = $('<input type="radio" name="qa_' + idx + '" />').prop('checked', q.answer === i).on('change', function () { q.answer = i; });
						var $inp = $('<input type="text" />').val(text).attr('placeholder', t('option', 'Option') + ' ' + (i + 1)).on('input', function () { q.options[i] = $(this).val(); });
						var $rm = $('<button type="button" class="button-link">×</button>').on('click', function () {
							q.options.splice(i, 1);
							if (q.answer >= q.options.length) { q.answer = Math.max(0, q.options.length - 1); }
							renderOpts();
						});
						$r.append($radio).append($inp).append($rm);
						$opts.append($r);
					});
					$opts.append($('<button type="button" class="button button-secondary" />').text('+ ' + t('addOption', 'Add option')).on('click', function () { q.options.push(''); renderOpts(); }));
				}
				renderOpts();
				$q.append(row(t('correct', 'Correct') + ' / ' + t('option', 'Option'), $opts));
			} else if (q.type === 'true_false') {
				if (typeof q.answer !== 'number') { q.answer = 0; }
				var $tf = $('<div class="mahan-cb-q-opts" />');
				[[0, t('true_', 'True')], [1, t('false_', 'False')]].forEach(function (o) {
					var $l = $('<label style="margin-right:14px" />');
					$l.append($('<input type="radio" name="tf_' + idx + '" />').prop('checked', q.answer === o[0]).on('change', function () { q.answer = o[0]; })).append(' ' + o[1]);
					$tf.append($l);
				});
				$q.append(row(t('correct', 'Correct'), $tf));
			} else {
				var $ans = $('<input type="text" />').val(q.answer_text || '').attr('placeholder', t('answerText', 'Expected answer')).on('input', function () { q.answer_text = $(this).val(); });
				$q.append(row(t('answer', 'Answer'), $ans));
				var $acc = $('<input type="text" />').val((q.accept || []).join(', ')).attr('placeholder', 'synonym1, synonym2').on('input', function () { q.accept = $(this).val().split(',').map(function (s) { return s.trim(); }).filter(Boolean); });
				$q.append(row(t('alsoAccept', 'Also accept'), $acc));
			}

			$q.on('click', '.mahan-cb-q-del', function () { quiz.questions.splice(idx, 1); renderQuestions(); });
			return $q;
		}

		function row(label, $input) {
			return $('<div class="mahan-cb-q-row" />').append('<label>' + esc(label) + '</label>').append($input);
		}

		var $pass = $('<input type="number" min="0" max="100" class="small-text" />').val(quiz.passing != null ? quiz.passing : 70).on('input', function () { quiz.passing = parseInt($(this).val(), 10) || 0; });
		var $xp = $('<input type="number" min="0" class="small-text" />').val(quiz.xp || 0).on('input', function () { quiz.xp = parseInt($(this).val(), 10) || 0; });
		var $addQ = $('<button type="button" class="button button-secondary" />').text('+ ' + t('addQuestion', 'Add question')).on('click', function () {
			quiz.questions.push({ type: 'multiple_choice', question: '', options: ['', ''], answer: 0 });
			renderQuestions();
		});

		var $meta = $('<div class="mahan-cb-quiz-meta" />')
			.append(row(t('passingScore', 'Passing score (%)'), $pass))
			.append(row(t('quizXp', 'XP on pass (0 = auto)'), $xp));

		var $save = $('<button type="button" class="button button-primary" />').text(t('save', 'Save quiz')).on('click', function () {
			quiz.questions = quiz.questions.filter(function (q) { return (q.question || '').trim() !== ''; });
			unit.quiz = quiz.questions.length ? quiz : null;
			$u.data('quiz', unit.quiz);
			var n = unit.quiz ? unit.quiz.questions.length : 0;
			$quizBtn.html('<span class="dashicons dashicons-forms"></span> ' + esc(t('quiz', 'Quiz')) + (n ? ' (' + n + ')' : '')).toggleClass('has-quiz', !!n);
			self.saveStructure();
			$overlay.remove();
		});
		var $cancel = $('<button type="button" class="button" />').text(t('cancel', 'Cancel')).on('click', function () { $overlay.remove(); });

		var $modal = $('<div class="mahan-cb-modal" />')
			.append('<h2>' + esc(t('unitQuiz', 'Unit quiz')) + ' — ' + esc(unit.title || '') + '</h2>')
			.append($meta)
			.append($body)
			.append($('<p />').append($addQ))
			.append($('<div class="mahan-cb-modal-actions" />').append($cancel).append(' ').append($save));

		var $overlay = $('<div class="mahan-cb-overlay" />').append($modal).on('click', function (e) { if (e.target === this) { $overlay.remove(); } });
		renderQuestions();
		$('body').append($overlay);
	};

	/* ---------------------------------------------------------------- */
	/* Persistence + stats                                               */
	/* ---------------------------------------------------------------- */

	Builder.prototype.serialize = function () {
		return this.model.map(function (u) {
			return { title: u.title || '', quiz: u.quiz || null, lessons: (u.lessons || []).map(function (n) { return n.id; }) };
		});
	};

	Builder.prototype.saveStructure = function () {
		var self = this;
		clearTimeout(this.saveTimer);
		this.saveTimer = setTimeout(function () {
			self.ajax('mahan_cb_save_structure', { course_id: self.courseId, structure: JSON.stringify(self.serialize()) })
				.then(function () { self.flash(t('saved', 'Saved')); })
				.fail(function (m) { self.flash(m, true); });
		}, 500);
	};

	Builder.prototype.totalLessons = function () {
		return this.model.reduce(function (n, u) { return n + ((u.lessons && u.lessons.length) || 0); }, 0);
	};

	Builder.prototype.updateStats = function () {
		var units = this.model.length;
		var lessons = 0, xp = 0;
		this.model.forEach(function (u) {
			(u.lessons || []).forEach(function (n) { lessons++; xp += (n.xp || 0); });
		});
		this.$root.find('[data-cb-count="units"]').text(units);
		this.$root.find('[data-cb-count="lessons"]').text(lessons);
		this.$root.find('[data-cb-count="xp"]').text(xp);
		this.$empty.toggle(lessons === 0);
	};

	function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
})(jQuery);
