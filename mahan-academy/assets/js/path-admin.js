/**
 * Mahan Academy — Learning Path course picker (admin).
 * Ordered, drag-sortable list of courses + an "add course" dropdown.
 * Requires jQuery + jQuery UI sortable (bundled).
 */
(function ($) {
	'use strict';

	$(function () {
		var $root = $('#mahan-path-builder');
		if (!$root.length) { return; }

		var selected, all;
		try { selected = JSON.parse($root.attr('data-selected') || '[]'); } catch (e) { selected = []; }
		try { all = JSON.parse($root.attr('data-all') || '[]'); } catch (e) { all = []; }
		if (!Array.isArray(selected)) { selected = []; }
		if (!Array.isArray(all)) { all = []; }

		var $list = $root.find('.mahan-path-list');
		var $select = $root.find('.mahan-path-select');
		var $hidden = $root.find('#mahan_path_courses');

		function persist() {
			$hidden.val(JSON.stringify(selected.map(function (c) { return c.id; })));
		}

		function refreshSelect() {
			var chosen = selected.map(function (c) { return c.id; });
			$select.empty();
			var avail = all.filter(function (c) { return chosen.indexOf(c.id) < 0; });
			if (!avail.length) {
				$select.append('<option value="">' + escHtml(wpText('allAdded', '— all courses added —')) + '</option>');
				return;
			}
			$select.append('<option value="">' + escHtml(wpText('choose', '— choose a course —')) + '</option>');
			avail.forEach(function (c) {
				$select.append($('<option/>').val(c.id).text(c.title));
			});
		}

		function render() {
			$list.empty();
			if (!selected.length) {
				$list.append('<li class="mahan-path-empty">' + escHtml(wpText('empty', 'No courses yet — add some below.')) + '</li>');
			}
			selected.forEach(function (c, i) {
				var $li = $('<li class="mahan-path-item" />').attr('data-id', c.id);
				$li.append('<span class="dashicons dashicons-menu mahan-path-drag"></span>');
				$li.append('<span class="mahan-path-num">' + (i + 1) + '</span>');
				$li.append($('<span class="mahan-path-title" />').text(c.title));
				var $rm = $('<button type="button" class="button-link mahan-path-rm">×</button>');
				$rm.on('click', function () {
					selected = selected.filter(function (x) { return x.id !== c.id; });
					render(); refreshSelect(); persist();
				});
				$li.append($rm);
				$list.append($li);
			});
			if ($.fn.sortable) {
				$list.sortable({
					handle: '.mahan-path-drag',
					items: '> .mahan-path-item',
					axis: 'y',
					update: function () {
						var order = $list.find('.mahan-path-item').map(function () { return parseInt($(this).attr('data-id'), 10); }).get();
						selected.sort(function (a, b) { return order.indexOf(a.id) - order.indexOf(b.id); });
						render(); persist();
					}
				});
			}
			persist();
		}

		$root.find('.mahan-path-add-btn').on('click', function () {
			var id = parseInt($select.val(), 10);
			if (!id) { return; }
			var course = all.filter(function (c) { return c.id === id; })[0];
			if (course && selected.map(function (c) { return c.id; }).indexOf(id) < 0) {
				selected.push({ id: course.id, title: course.title });
				render(); refreshSelect();
			}
		});

		function wpText(key, fallback) {
			return (window.MahanPath && window.MahanPath.i18n && window.MahanPath.i18n[key]) || fallback;
		}
		function escHtml(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

		render();
		refreshSelect();
	});
})(jQuery);
