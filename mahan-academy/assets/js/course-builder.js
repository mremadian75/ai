/**
 * Mahan Academy — Course Studio (curriculum builder).
 *
 * One screen builds the whole course, Tutor-LMS style: collapsible unit cards,
 * inline add/rename, drag-and-drop reordering, a per-unit quiz editor, and a
 * full lesson editor in a modal — title, type, video, minutes, XP, and the
 * lesson body in a real TinyMCE (with the media library behind Add Media) so
 * nobody has to leave the course to write a lesson.
 *
 * Requires jQuery (+ jQuery UI sortable when available — everything else
 * degrades: no sortable means no dragging, no wp.editor means a plain
 * textarea, and both are still fully usable).
 */
(function ($) {
	'use strict';

	var CFG = window.MahanCB || {};
	var I = CFG.i18n || {};

	function t(key, fallback) { return I[key] || fallback || key; }
	function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

	/**
	 * Mirror of the server's video whitelist (Mahan_Courses::video_embed), so
	 * the studio can show "✓ YouTube" / a live preview before saving. The
	 * server remains the authority — this only predicts what it will say.
	 */
	function parseVideo(url) {
		url = String(url == null ? '' : url).trim();
		if (!url) { return { type: '', src: '' }; }
		var m;
		if ((m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([A-Za-z0-9_-]{6,})/))) {
			return { type: 'youtube', src: 'https://www.youtube.com/embed/' + m[1] };
		}
		if ((m = url.match(/vimeo\.com\/(?:video\/)?(\d+)/))) {
			return { type: 'vimeo', src: 'https://player.vimeo.com/video/' + m[1] };
		}
		if (/\.(mp4|webm|ogg)(\?.*)?$/i.test(url)) {
			return { type: 'file', src: url };
		}
		return { type: '', src: '' };
	}

	var TYPES = [
		['reading', 'dashicons-media-document'],
		['video', 'dashicons-video-alt3'],
		['practice', 'dashicons-edit']
	];
	function typeIcon(type) {
		for (var i = 0; i < TYPES.length; i++) { if (TYPES[i][0] === type) { return TYPES[i][1]; } }
		return TYPES[0][1];
	}
	function typeLabel(type) { return t(type, type.charAt(0).toUpperCase() + type.slice(1)); }

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
		// Units start open — an editor that greets you with closed boxes makes
		// you pay a click to see your own course.
		this.model.forEach(function (u) { if (u._open === undefined) { u._open = true; } });
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
		this.$root.on('click', '#mahan-cb-toggle-all', function () {
			// If anything is open, close everything; otherwise open everything.
			var anyOpen = self.model.some(function (u) { return u._open; });
			self.model.forEach(function (u) { u._open = !anyOpen; });
			self.render();
		});
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
		this.$empty.toggle(this.model.length === 0);
		this.makeSortable();
		this.updateStats();
	};

	Builder.prototype.unitSummary = function (unit) {
		var lessons = unit.lessons || [];
		var min = 0;
		lessons.forEach(function (n) { min += (n.est_min || 0); });
		var parts = [lessons.length + ' ' + t('lessons', 'lessons')];
		if (min) { parts.push(min + ' ' + t('minutes', 'min')); }
		var qn = (unit.quiz && unit.quiz.questions) ? unit.quiz.questions.length : 0;
		if (qn) { parts.push('✓ ' + t('quiz', 'Quiz')); }
		return parts.join(' · ');
	};

	Builder.prototype.renderUnit = function (unit, ui) {
		var self = this;
		var $u = $('<section class="mahan-cb-unit" />').attr('data-unit', ui).toggleClass('is-closed', !unit._open);
		$u.data('quiz', unit.quiz || null);

		var $head = $('<div class="mahan-cb-unit-head" />');
		$head.append('<span class="mahan-cb-drag dashicons dashicons-move" title="' + esc(t('dragUnit', 'Drag to reorder unit')) + '"></span>');

		var $toggle = $('<button type="button" class="mahan-cb-toggle" aria-expanded="' + (unit._open ? 'true' : 'false') + '"><span class="dashicons dashicons-arrow-down-alt2"></span></button>');
		$toggle.attr('title', unit._open ? t('collapse', 'Collapse') : t('expand', 'Expand'));
		$toggle.on('click', function () { unit._open = !unit._open; self.render(); });
		$head.append($toggle);

		$head.append('<span class="mahan-cb-unit-n">' + (ui + 1) + '</span>');

		var $titleWrap = $('<div class="mahan-cb-unit-titles" />');
		var $title = $('<input type="text" class="mahan-cb-unit-title" />').val(unit.title || '');
		$title.attr('placeholder', t('unitName', 'Unit name'));
		$title.on('change blur', function () { unit.title = $(this).val(); self.saveStructure(); });
		// The head doubles as the collapse toggle, but typing a name is not
		// asking for a collapse.
		$title.on('click', function (e) { e.stopPropagation(); });
		$titleWrap.append($title);
		$titleWrap.append('<span class="mahan-cb-unit-sum">' + esc(this.unitSummary(unit)) + '</span>');
		$head.append($titleWrap);

		var qn = (unit.quiz && unit.quiz.questions) ? unit.quiz.questions.length : 0;
		var $quiz = $('<button type="button" class="button mahan-cb-quiz-btn"></button>')
			.html('<span class="dashicons dashicons-forms"></span> ' + (qn ? esc(t('quiz', 'Quiz')) + ' (' + qn + ')' : esc(t('addQuiz', 'Add quiz'))));
		if (qn) { $quiz.addClass('has-quiz'); }
		$quiz.on('click', function (e) { e.stopPropagation(); self.editQuiz(unit, $u, $quiz); });
		$head.append($quiz);

		var $del = $('<button type="button" class="button-link mahan-cb-unit-del" title="' + esc(t('deleteUnit', 'Delete empty unit')) + '"><span class="dashicons dashicons-trash"></span></button>');
		$del.on('click', function (e) { e.stopPropagation(); self.deleteUnit(ui); });
		$head.append($del);

		// The whole header row toggles — the affordance every accordion
		// teaches — except its interactive children.
		$head.on('click', function (e) {
			if (e.target === $head[0] || $(e.target).hasClass('mahan-cb-unit-sum') || $(e.target).hasClass('mahan-cb-unit-n')) {
				unit._open = !unit._open;
				self.render();
			}
		});

		$u.append($head);

		var $body = $('<div class="mahan-cb-unit-body" />');
		var $lessons = $('<div class="mahan-cb-lessons" />').attr('data-unit', ui);
		(unit.lessons || []).forEach(function (node) {
			$lessons.append(self.renderLesson(node));
		});
		if (!(unit.lessons || []).length) {
			$lessons.append('<p class="mahan-cb-unit-empty">' + esc(t('emptyUnit', 'No lessons in this unit yet — add the first one below.')) + '</p>');
		}
		$body.append($lessons);
		$body.append(this.renderAddRow(unit, ui));
		$u.append($body);
		return $u;
	};

	/**
	 * Tutor-LMS-style inline add: type the title, pick a type, press Enter.
	 * Focus stays in the field so a whole unit can be sketched in one breath.
	 */
	Builder.prototype.renderAddRow = function (unit, ui) {
		var self = this;
		var $row = $('<div class="mahan-cb-addrow" />');
		var $type = $('<select class="mahan-cb-addrow-type" aria-label="' + esc(t('type', 'Type')) + '" />');
		TYPES.forEach(function (tp) {
			$type.append($('<option/>').val(tp[0]).text(typeLabel(tp[0])));
		});
		var $inp = $('<input type="text" class="mahan-cb-addrow-title" />')
			.attr('placeholder', '+ ' + t('addLessonPh', 'Add a lesson — type its title and press Enter'));
		var $btn = $('<button type="button" class="button button-primary mahan-cb-addrow-btn">' + esc(t('add', 'Add')) + '</button>');

		function submit() {
			var title = $inp.val().trim();
			if (!title) { $inp.focus(); return; }
			$btn.prop('disabled', true);
			self.ajax('mahan_cb_add_lesson', { course_id: self.courseId, unit: unit.title || '', title: title, type: $type.val() })
				.then(function (d) {
					unit.lessons = unit.lessons || [];
					unit.lessons.push(d.lesson);
					self.render();
					self.saveStructure();
					self.flash(t('lessonAdded', 'Lesson added'));
					// Re-focus the fresh add-row of the same unit for the next one.
					self.$units.find('.mahan-cb-unit[data-unit="' + ui + '"] .mahan-cb-addrow-title').focus();
				})
				.fail(function (m) { $btn.prop('disabled', false); self.flash(m, true); });
		}
		$inp.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
		$btn.on('click', submit);

		return $row.append($type).append($inp).append($btn);
	};

	Builder.prototype.renderLesson = function (node) {
		var self = this;
		var $l = $('<div class="mahan-cb-lesson" />').attr('data-id', node.id).data('node', node);
		$l.append('<span class="mahan-cb-drag dashicons dashicons-menu" title="' + esc(t('dragLesson', 'Drag to reorder')) + '"></span>');
		$l.append('<span class="mahan-cb-lesson-icon is-' + esc(node.type || 'reading') + ' dashicons ' + typeIcon(node.type) + '" title="' + esc(typeLabel(node.type || 'reading')) + '"></span>');

		var $title = $('<input type="text" class="mahan-cb-lesson-title" />').val(node.title || '');
		$title.on('change blur', function () {
			var v = $(this).val();
			if (v === node.title) { return; }
			self.ajax('mahan_cb_update_lesson', { lesson_id: node.id, title: v })
				.then(function (d) { node.title = d.lesson.title; self.flash(t('saved', 'Saved')); })
				.fail(function (m) { self.flash(m, true); });
		});
		$l.append($title);

		var $badges = $('<div class="mahan-cb-lesson-badges" />');
		if (node.video_ok) {
			$badges.append('<span class="mahan-cb-badge is-video" title="' + esc(t('hasVideo', 'Has a video')) + '"><span class="dashicons dashicons-video-alt3"></span></span>');
		}
		if (node.est_min) { $badges.append('<span class="mahan-cb-badge">' + node.est_min + ' ' + esc(t('minutes', 'min')) + '</span>'); }
		if (node.xp) { $badges.append('<span class="mahan-cb-badge">' + node.xp + ' XP</span>'); }
		if (node.exercises) { $badges.append('<span class="mahan-cb-badge">' + node.exercises + ' ' + esc(t('exercises', 'exercises')) + '</span>'); }
		if (node.status && node.status !== 'publish') {
			$badges.append('<span class="mahan-cb-badge is-draft">' + esc(t('draft', 'Draft')) + '</span>');
		}
		if (!node.has_content) {
			// The one flag an author actually needs at a glance: which lessons
			// are still hollow.
			$badges.append('<span class="mahan-cb-badge is-warn" title="' + esc(t('noContent', 'No content yet')) + '">' + esc(t('noContentShort', 'empty')) + '</span>');
		}
		$l.append($badges);

		var $actions = $('<div class="mahan-cb-lesson-actions" />');
		var $edit = $('<button type="button" class="button mahan-cb-edit-btn"><span class="dashicons dashicons-edit-large"></span> ' + esc(t('edit', 'Edit')) + '</button>');
		$edit.on('click', function () { self.editLesson(node, $l); });
		$actions.append($edit);
		if (node.edit_url) {
			$actions.append('<a class="button button-small mahan-cb-iconbtn" href="' + esc(node.edit_url) + '" target="_blank" rel="noopener" title="' + esc(t('openWp', 'Open in the WordPress editor')) + '"><span class="dashicons dashicons-external"></span></a>');
		}
		var $dup = $('<button type="button" class="button button-small mahan-cb-iconbtn" title="' + esc(t('duplicate', 'Duplicate')) + '"><span class="dashicons dashicons-admin-page"></span></button>');
		$dup.on('click', function () { self.duplicateLesson(node); });
		$actions.append($dup);
		var $del = $('<button type="button" class="button button-small mahan-cb-iconbtn mahan-cb-lesson-del" title="' + esc(t('delete', 'Delete')) + '"><span class="dashicons dashicons-trash"></span></button>');
		$del.on('click', function () { self.deleteLesson(node, $l); });
		$actions.append($del);
		$l.append($actions);

		return $l;
	};

	/* ---------------------------------------------------------------- */
	/* Modal chrome (shared by the lesson editor and the quiz editor)    */
	/* ---------------------------------------------------------------- */

	/**
	 * Open a studio modal. `opts.beforeClose` may veto an accidental
	 * dismissal (overlay click / Escape); explicit buttons call close(true).
	 */
	Builder.prototype.openModal = function ($modal, opts) {
		opts = opts || {};
		var $overlay = $('<div class="mahan-cb-overlay" role="dialog" aria-modal="true" />').append($modal);
		function close(force) {
			if (!force && opts.beforeClose && !opts.beforeClose()) { return; }
			$(document).off('keydown.mahancb');
			if (opts.onClose) { opts.onClose(); }
			$overlay.remove();
		}
		$overlay.on('mousedown', function (e) { if (e.target === this) { close(); } });
		$(document).on('keydown.mahancb', function (e) { if (e.key === 'Escape') { close(); } });
		$('body').append($overlay);
		return { $overlay: $overlay, close: close };
	};

	/* ---------------------------------------------------------------- */
	/* Lesson editor (the studio's centrepiece)                          */
	/* ---------------------------------------------------------------- */

	Builder.prototype.editLesson = function (node, $l) {
		var self = this;
		var editorId = 'mahan-cb-editor-' + node.id;
		var dirty = false;
		var handle = null;

		var $modal = $('<div class="mahan-cb-modal mahan-cb-modal-lesson" />');
		$modal.append(
			'<div class="mahan-cb-modal-head"><h2><span class="dashicons dashicons-welcome-write-blog"></span> '
			+ esc(t('editLesson', 'Edit lesson')) + '</h2>'
			+ '<button type="button" class="mahan-cb-modal-x" aria-label="' + esc(t('cancel', 'Cancel')) + '">×</button></div>'
		);
		var $body = $('<div class="mahan-cb-modal-body" />');
		$modal.append($body);
		$body.append('<p class="mahan-cb-loading"><span class="spinner is-active"></span> ' + esc(t('loading', 'Loading…')) + '</p>');

		handle = this.openModal($modal, {
			beforeClose: function () {
				if (!dirty) { return true; }
				return window.confirm(t('discard', 'Discard unsaved changes to this lesson?'));
			},
			onClose: function () {
				if (window.wp && wp.editor && wp.editor.remove) { try { wp.editor.remove(editorId); } catch (e) { /* not initialised */ } }
			}
		});
		$modal.on('click', '.mahan-cb-modal-x', function () { handle.close(); });

		this.ajax('mahan_cb_get_lesson', { lesson_id: node.id })
			.then(function (d) { build(d.lesson, d.content || ''); })
			.fail(function (m) { handle.close(true); self.flash(m, true); });

		function markDirty() { dirty = true; }

		function build(fresh, content) {
			$body.empty();
			var type = fresh.type || 'reading';
			var video = fresh.video || '';

			// Title.
			var $title = $('<input type="text" class="mahan-cb-modal-title" />').val(fresh.title || '')
				.attr('placeholder', t('lessonTitle', 'Lesson title')).on('input', markDirty);
			$body.append(field(t('lessonTitle', 'Lesson title'), $title));

			// Type — a segmented control, not a dropdown: three choices you
			// can see beat three choices you have to open.
			var $seg = $('<div class="mahan-cb-seg" role="radiogroup" />');
			TYPES.forEach(function (tp) {
				var $b = $('<button type="button" class="mahan-cb-seg-btn" data-type="' + tp[0] + '"><span class="dashicons ' + tp[1] + '"></span> ' + esc(typeLabel(tp[0])) + '</button>');
				if (tp[0] === type) { $b.addClass('is-on'); }
				$b.on('click', function () {
					type = tp[0];
					$seg.find('.mahan-cb-seg-btn').removeClass('is-on');
					$b.addClass('is-on');
					$videoField.toggleClass('is-suggested', type === 'video');
					markDirty();
				});
				$seg.append($b);
			});
			$body.append(field(t('type', 'Type'), $seg));

			// Video URL + live verdict + preview.
			var $video = $('<input type="url" class="mahan-cb-video-url" />').val(video)
				.attr('placeholder', 'https://www.youtube.com/watch?v=…');
			var $verdict = $('<span class="mahan-cb-video-verdict" />');
			var $preview = $('<div class="mahan-cb-video-preview" />');
			function paintVideo() {
				var v = parseVideo($video.val());
				$preview.empty();
				if (!$video.val().trim()) {
					$verdict.text(t('videoHint', 'YouTube, Vimeo, or a direct .mp4 / .webm link')).attr('class', 'mahan-cb-video-verdict');
					return;
				}
				if (!v.type) {
					$verdict.text('✗ ' + t('videoBad', 'Not a supported video link')).attr('class', 'mahan-cb-video-verdict is-bad');
					return;
				}
				var providerName = { youtube: 'YouTube', vimeo: 'Vimeo', file: t('videoFile', 'Video file') };
				$verdict.text('✓ ' + (providerName[v.type] || v.type)).attr('class', 'mahan-cb-video-verdict is-ok');
				$preview.append(
					v.type === 'file'
						? $('<video controls preload="metadata" />').attr('src', v.src)
						: $('<iframe frameborder="0" allowfullscreen />').attr('src', v.src)
				);
			}
			$video.on('input', function () { paintVideo(); markDirty(); });
			var $videoField = field(t('videoUrl', 'Video'), $('<div/>').append($video).append($verdict).append($preview))
				.addClass('mahan-cb-video-field').toggleClass('is-suggested', type === 'video');
			$body.append($videoField);
			paintVideo();

			// Minutes + XP, side by side.
			var $min = $('<input type="number" min="0" class="small-text" />').val(fresh.est_min || 0).on('input', markDirty);
			var $xp = $('<input type="number" min="0" class="small-text" />').val(fresh.xp || 0).on('input', markDirty);
			var $pair = $('<div class="mahan-cb-pair" />')
				.append($('<label/>').append('<span>' + esc(t('minutesFull', 'Minutes')) + '</span>').append($min))
				.append($('<label/>').append('<span>XP</span>').append($xp));
			$body.append(field(t('settings', 'Settings'), $pair));

			// Content — a real TinyMCE when WordPress provides one.
			var $ta = $('<textarea rows="12" />').attr('id', editorId).val(content);
			$body.append(field(t('content', 'Lesson content'), $('<div class="mahan-cb-editor-wrap" />').append($ta)));
			var richEditor = false;
			if (window.wp && wp.editor && wp.editor.initialize) {
				richEditor = true;
				// Deferred so the textarea is in the DOM before TinyMCE mounts.
				window.setTimeout(function () {
					wp.editor.initialize(editorId, {
						tinymce: {
							wpautop: true,
							height: 320,
							toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,wp_add_media,undo,redo',
							setup: function (ed) { ed.on('change keyup', markDirty); }
						},
						quicktags: true,
						mediaButtons: true
					});
				}, 0);
			} else {
				$ta.on('input', markDirty);
			}

			function readContent() {
				if (richEditor && window.wp && wp.editor && wp.editor.getContent) {
					try { return wp.editor.getContent(editorId) || ''; } catch (e) { /* fall through */ }
				}
				return $ta.val() || '';
			}

			// Footer.
			var $save = $('<button type="button" class="button button-primary button-large" />').text(t('saveLesson', 'Save lesson'));
			var $cancel = $('<button type="button" class="button button-large" />').text(t('cancel', 'Cancel'));
			$cancel.on('click', function () { handle.close(); });
			$save.on('click', function () {
				$save.prop('disabled', true).text(t('saving', 'Saving…'));
				self.ajax('mahan_cb_update_lesson', {
					lesson_id: node.id,
					title: $title.val(),
					type: type,
					video: $video.val(),
					est_min: parseInt($min.val(), 10) || 0,
					xp: parseInt($xp.val(), 10) || 0,
					content: readContent()
				}).then(function (d) {
					// Adopt the server's node wholesale and repaint just this row.
					$.extend(node, d.lesson);
					dirty = false;
					handle.close(true);
					$l.replaceWith(self.renderLesson(node));
					self.updateStats();
					self.flash(t('lessonSaved', 'Lesson saved'));
				}).fail(function (m) {
					$save.prop('disabled', false).text(t('saveLesson', 'Save lesson'));
					self.flash(m, true);
				});
			});
			$modal.append($('<div class="mahan-cb-modal-actions" />').append($cancel).append($save));
		}

		function field(label, $input) {
			return $('<div class="mahan-cb-fieldrow" />')
				.append('<label class="mahan-cb-fieldrow-label">' + esc(label) + '</label>')
				.append($('<div class="mahan-cb-fieldrow-input" />').append($input));
		}
	};

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
			model.push({ title: title, quiz: $u.data('quiz') || null, lessons: lessons, _open: !$u.hasClass('is-closed') });
		});
		this.model = model;
		// Re-attach unit indices + summaries without a full re-render (keeps focus).
		this.$units.find('.mahan-cb-unit').each(function (i) {
			$(this).attr('data-unit', i);
			$(this).find('.mahan-cb-lessons').attr('data-unit', i);
			$(this).find('.mahan-cb-unit-n').text(i + 1);
			$(this).find('.mahan-cb-unit-sum').text(self.unitSummary(self.model[i]));
		});
		this.updateStats();
		this.saveStructure();
	};

	/* ---------------------------------------------------------------- */
	/* Actions                                                           */
	/* ---------------------------------------------------------------- */

	Builder.prototype.addUnit = function () {
		this.model.push({ title: t('newUnit', 'New unit'), lessons: [], _open: true });
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
			[['multiple_choice', t('multiple_choice', 'Multiple choice')], ['multi_select', t('multi_select', 'Select all that apply')], ['true_false', t('true_false', 'True / False')], ['fill_blank', t('fill_blank', 'Fill in the blank')]].forEach(function (o) {
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
				var renderOpts = function () {
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
				};
				renderOpts();
				$q.append(row(t('correct', 'Correct') + ' / ' + t('option', 'Option'), $opts));
			} else if (q.type === 'multi_select') {
				if (!Array.isArray(q.options)) { q.options = ['', '']; }
				if (!Array.isArray(q.answers)) { q.answers = []; }
				var $mopts = $('<div class="mahan-cb-q-opts" />');
				var renderM = function () {
					$mopts.empty();
					q.options.forEach(function (text, i) {
						var $r = $('<div class="mahan-cb-q-opt" />');
						var $cb = $('<input type="checkbox" />').prop('checked', q.answers.indexOf(i) >= 0).on('change', function () {
							var at = q.answers.indexOf(i);
							if (this.checked && at < 0) { q.answers.push(i); }
							if (!this.checked && at >= 0) { q.answers.splice(at, 1); }
						});
						var $inp = $('<input type="text" />').val(text).attr('placeholder', t('option', 'Option') + ' ' + (i + 1)).on('input', function () { q.options[i] = $(this).val(); });
						var $rm = $('<button type="button" class="button-link">×</button>').on('click', function () {
							q.options.splice(i, 1);
							// Answer indices shift when an option is removed.
							q.answers = q.answers.filter(function (a) { return a !== i; })
								.map(function (a) { return a > i ? a - 1 : a; });
							renderM();
						});
						$r.append($cb).append($inp).append($rm);
						$mopts.append($r);
					});
					$mopts.append($('<button type="button" class="button button-secondary" />').text('+ ' + t('addOption', 'Add option')).on('click', function () { q.options.push(''); renderM(); }));
				};
				renderM();
				$q.append(row(t('correctAll', 'Correct (tick every one)'), $mopts));
			} else if (q.type === 'true_false') {
				if (typeof q.answer !== 'number') { q.answer = 0; }
				var $tf = $('<div class="mahan-cb-q-opts" />');
				[[0, t('true_', 'True')], [1, t('false_', 'False')]].forEach(function (o) {
					var $lb = $('<label style="margin-right:14px" />');
					$lb.append($('<input type="radio" name="tf_' + idx + '" />').prop('checked', q.answer === o[0]).on('change', function () { q.answer = o[0]; })).append(' ' + o[1]);
					$tf.append($lb);
				});
				$q.append(row(t('correct', 'Correct'), $tf));
			} else {
				var $ans = $('<input type="text" />').val(q.answer_text || '').attr('placeholder', t('answerText', 'Expected answer')).on('input', function () { q.answer_text = $(this).val(); });
				$q.append(row(t('answer', 'Answer'), $ans));
				var $acc = $('<input type="text" />').val((q.accept || []).join(', ')).attr('placeholder', 'synonym1, synonym2').on('input', function () { q.accept = $(this).val().split(',').map(function (s) { return s.trim(); }).filter(Boolean); });
				$q.append(row(t('alsoAccept', 'Also accept'), $acc));
			}

			// Shown to the learner only after the attempt is graded.
			var $exp = $('<textarea rows="2" />').val(q.explain || '')
				.attr('placeholder', t('explainPh', 'Why this is the answer — shown after grading'))
				.on('input', function () { q.explain = $(this).val(); });
			$q.append(row(t('explain', 'Explanation'), $exp));

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
			// The new question is at the bottom — bring it into view.
			var $sc = $modal.find('.mahan-cb-modal-body');
			$sc.scrollTop($sc[0].scrollHeight);
		});

		var $meta = $('<div class="mahan-cb-quiz-meta" />')
			.append(row(t('passingScore', 'Passing score (%)'), $pass))
			.append(row(t('quizXp', 'XP on pass (0 = auto)'), $xp));

		var handle;
		var $save = $('<button type="button" class="button button-primary button-large" />').text(t('save', 'Save quiz')).on('click', function () {
			quiz.questions = quiz.questions.filter(function (q) { return (q.question || '').trim() !== ''; });
			unit.quiz = quiz.questions.length ? quiz : null;
			$u.data('quiz', unit.quiz);
			var n = unit.quiz ? unit.quiz.questions.length : 0;
			$quizBtn.html('<span class="dashicons dashicons-forms"></span> ' + (n ? esc(t('quiz', 'Quiz')) + ' (' + n + ')' : esc(t('addQuiz', 'Add quiz')))).toggleClass('has-quiz', !!n);
			self.saveStructure();
			handle.close(true);
		});
		var $cancel = $('<button type="button" class="button button-large" />').text(t('cancel', 'Cancel')).on('click', function () { handle.close(true); });

		var $modal = $('<div class="mahan-cb-modal mahan-cb-modal-quiz" />')
			.append('<div class="mahan-cb-modal-head"><h2><span class="dashicons dashicons-forms"></span> ' + esc(t('unitQuiz', 'Unit quiz')) + ' — ' + esc(unit.title || '') + '</h2>'
				+ '<button type="button" class="mahan-cb-modal-x" aria-label="' + esc(t('cancel', 'Cancel')) + '">×</button></div>')
			.append($('<div class="mahan-cb-modal-body" />').append($meta).append($body).append($('<p />').append($addQ)))
			.append($('<div class="mahan-cb-modal-actions" />').append($cancel).append($save));

		handle = this.openModal($modal, {});
		$modal.on('click', '.mahan-cb-modal-x', function () { handle.close(true); });
		renderQuestions();
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
		var lessons = 0, xp = 0, min = 0;
		this.model.forEach(function (u) {
			(u.lessons || []).forEach(function (n) { lessons++; xp += (n.xp || 0); min += (n.est_min || 0); });
		});
		this.$root.find('[data-cb-count="units"]').text(units);
		this.$root.find('[data-cb-count="lessons"]').text(lessons);
		this.$root.find('[data-cb-count="xp"]').text(xp);
		this.$root.find('[data-cb-count="min"]').text(min);
		this.$empty.toggle(units === 0);
	};
})(jQuery);
