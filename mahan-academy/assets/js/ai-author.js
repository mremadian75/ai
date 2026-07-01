/**
 * Mahan Academy — AI authoring assistant (admin).
 * Adds "generate with AI" affordances to the course and lesson editors:
 *   • Course: fill "What you'll learn" outcomes.
 *   • Lesson: draft content from a topic; generate exercises from the material.
 */
(function ($) {
	'use strict';

	var CFG = window.MahanAIAuthor || {};
	var I = CFG.i18n || {};
	function t(key, fallback) { return I[key] || fallback || key; }

	function ajax(action, data) {
		return $.post(CFG.ajaxUrl, $.extend({ action: action, nonce: CFG.nonce }, data)).then(function (r) {
			if (r && r.success) { return r.data || {}; }
			return $.Deferred().reject((r && r.data && r.data.message) || 'Error');
		});
	}

	// Best-effort read of the post title/content across classic & block editors.
	function postTitle() {
		try {
			if (window.wp && wp.data && wp.data.select('core/editor')) {
				return wp.data.select('core/editor').getEditedPostAttribute('title') || '';
			}
		} catch (e) {}
		return $('#title').val() || '';
	}
	function postContent() {
		try {
			if (window.wp && wp.data && wp.data.select('core/editor')) {
				return wp.data.select('core/editor').getEditedPostContent() || '';
			}
		} catch (e) {}
		return $('#content').val() || '';
	}

	$(function () {
		wireOutcomes();
		wireDraft();
		wireExercises();
	});

	/* ---- Course: outcomes ------------------------------------------- */
	function wireOutcomes() {
		var $ta = $('#mahan_outcomes');
		if (!$ta.length || !CFG.nonce) { return; }
		var $btn = $('<button type="button" class="button button-secondary" style="margin-bottom:8px">✨ ' + esc(t('genOutcomes', 'Generate with AI')) + '</button>');
		var $spin = $('<span class="spinner mahan-ai-spin" style="float:none;margin:0 6px"></span>');
		$ta.before($('<div class="mahan-ai-inline" />').append($btn).append($spin));
		$btn.on('click', function () {
			$spin.addClass('is-active');
			$btn.prop('disabled', true);
			ajax('mahan_ai_outcomes', { title: postTitle(), description: postContent().replace(/<[^>]+>/g, ' ').slice(0, 2000) })
				.then(function (d) {
					var cur = $ta.val().trim();
					$ta.val(cur ? cur + '\n' + d.text : d.text);
				})
				.fail(function (m) { window.alert(m); })
				.always(function () { $spin.removeClass('is-active'); $btn.prop('disabled', false); });
		});
	}

	/* ---- Lesson: draft ---------------------------------------------- */
	function wireDraft() {
		var $box = $('#mahan-ai-draft');
		if (!$box.length) { return; }
		var $spin = $box.find('.mahan-ai-spin');
		$box.find('#mahan-ai-draft-btn').on('click', function () {
			var topic = $box.find('#mahan-ai-topic').val();
			if (!topic || !topic.trim()) { $box.find('#mahan-ai-topic').focus(); return; }
			$spin.addClass('is-active');
			$(this).prop('disabled', true);
			var $btn = $(this);
			ajax('mahan_ai_lesson_draft', { topic: topic })
				.then(function (d) {
					$box.find('#mahan-ai-draft-out').show();
					$box.find('#mahan-ai-draft-text').val(d.html);
				})
				.fail(function (m) { window.alert(m); })
				.always(function () { $spin.removeClass('is-active'); $btn.prop('disabled', false); });
		});
		$box.find('#mahan-ai-draft-copy').on('click', function () {
			var el = $box.find('#mahan-ai-draft-text')[0];
			el.select();
			try { document.execCommand('copy'); $(this).text(t('copied', 'Copied!')); } catch (e) {}
			var self = this;
			setTimeout(function () { $(self).text(t('copy', 'Copy')); }, 1500);
		});
	}

	/* ---- Lesson: exercises ------------------------------------------ */
	function wireExercises() {
		var $builder = $('#mahan-exercise-builder');
		if (!$builder.length || !CFG.nonce) { return; }
		var $bar = $('<div class="mahan-ai-inline" style="margin:6px 0 12px" />');
		var $btn = $('<button type="button" class="button button-secondary">✨ ' + esc(t('genExercises', 'Generate exercises with AI')) + '</button>');
		var $count = $('<select style="margin:0 6px"></select>');
		[3, 4, 5, 6].forEach(function (n) { $count.append('<option value="' + n + '">' + n + '</option>'); });
		var $spin = $('<span class="spinner mahan-ai-spin" style="float:none"></span>');
		$bar.append($btn).append($count).append('<span class="description">' + esc(t('exercisesHint', 'from this lesson\'s content')) + '</span>').append($spin);
		$builder.prepend($bar);

		$btn.on('click', function () {
			$spin.addClass('is-active');
			$btn.prop('disabled', true);
			ajax('mahan_ai_exercises', {
				lesson_id: $builder.data('lessonId') || getLessonId(),
				count: $count.val(),
				content: postContent()
			})
				.then(function (d) {
					// Hand the generated list to the exercise builder (admin.js).
					$builder.trigger('mahan:load', [d.exercises, true]);
				})
				.fail(function (m) { window.alert(m); })
				.always(function () { $spin.removeClass('is-active'); $btn.prop('disabled', false); });
		});
	}

	function getLessonId() {
		try {
			if (window.wp && wp.data && wp.data.select('core/editor')) {
				return wp.data.select('core/editor').getCurrentPostId() || 0;
			}
		} catch (e) {}
		return parseInt($('#post_ID').val(), 10) || 0;
	}

	function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
})(jQuery);
