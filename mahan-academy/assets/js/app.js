/**
 * Mahan Academy — front-end SPA.
 * Coursera-style structure + Duolingo-style practice + a real-time AI tutor.
 * Vanilla JS, no build step.
 */
(function () {
	'use strict';

	var D = window.MahanData || {};
	var I = D.i18n || {};
	var REST = (D.restUrl || '').replace(/\/$/, '');

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function el(id) { return document.getElementById(id); }

	function h(tag, attrs, children) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			if (k === 'class') { node.className = attrs[k]; }
			else if (k === 'html') { node.innerHTML = attrs[k]; }
			else if (k === 'text') { node.textContent = attrs[k]; }
			else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') {
				node.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
			} else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) {
				node.setAttribute(k, attrs[k]);
			}
		});
		(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) { return; }
			node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}

	function t(key, fallback) { return I[key] || fallback || key; }

	// sprintf-lite: fills %s (and positional %1$s / %2$s) placeholders so
	// translatable strings stay whole sentences instead of word-by-word
	// concatenation. Only the forms the UI actually uses are supported.
	function fmt(tpl) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(tpl)
			.replace(/%(\d+)\$s/g, function (_, n) { return String(args[n - 1] == null ? '' : args[n - 1]); })
			.replace(/%s/g, function () { return String(args[i] == null ? '' : args[i++]); });
	}

	// Pluralize a count with singular/plural i18n keys (falls back to fallback).
	function plural(n, oneKey, manyKey, oneFb, manyFb) {
		return n + ' ' + (n === 1 ? t(oneKey, oneFb) : t(manyKey, manyFb));
	}

	// Variant picker: rotate reinforcement copy so the reward loop doesn't go
	// stale. `key` names an i18n array; falls back to t(fallbackKey).
	function tv(key, fallbackKey, fallback, seed) {
		var arr = I[key];
		if (arr && arr.length) {
			var idx = (typeof seed === 'number' ? seed : Math.floor(Math.random() * arr.length)) % arr.length;
			return arr[idx] || arr[0];
		}
		return t(fallbackKey, fallback);
	}

	// In-memory session cache for GET responses: repeat visits paint
	// instantly (stale-while-revalidate). Any mutation clears it.
	var apiCache = {};

	// Bumped on every render(); a deferred async paint checks it so a slow
	// response can't clobber a view the learner has already navigated away from.
	var navSeq = 0;

	function api(path, method, data) {
		var opt = {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.nonce || '' }
		};
		if (data) { opt.body = JSON.stringify(data); }
		if (method && method !== 'GET') { apiCache = {}; }
		return fetch(REST + path, opt).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) { throw Object.assign(new Error('API'), { status: r.status, payload: j }); }
				return j;
			});
		});
	}

	// Fetch-and-paint with the session cache: paint cached data immediately
	// (no skeleton), then repaint only if the fresh response differs.
	function cachedApi(path, skeletonKind, paint, retry) {
		var hit = apiCache[path];
		var seq = navSeq;
		if (hit) { paint(hit); } else { mount(loadingShell(skeletonKind)); }
		api(path).then(function (j) {
			// Bail if the learner navigated away while this was in flight.
			if (seq !== navSeq) { apiCache[path] = j; return; }
			var same = hit && JSON.stringify(hit) === JSON.stringify(j);
			apiCache[path] = j;
			if (same) { return; }
			// Don't rip focus out from under the user mid-keystroke — keep
			// the (slightly) stale view; the cache is fresh for next paint.
			var ae = document.activeElement;
			if (hit && ae && root.contains(ae) && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName)) { return; }
			paint(j);
		}).catch(function (e) {
			if (seq === navSeq && !hit) { mount(errorBox(retry, e)); }
		});
	}

	// Login link that returns the learner to the exact view they were on.
	function loginHref() {
		if (!D.loginBase) { return D.loginUrl; }
		var sep = D.loginBase.indexOf('?') >= 0 ? '&' : '?';
		return D.loginBase + sep + 'redirect_to=' + encodeURIComponent(window.location.href);
	}

	function setTitle(view) {
		var site = D.siteName || 'Academy';
		document.title = view ? view + ' – ' + site : site;
		// Announce the new view to screen readers on SPA navigation (not on the
		// very first paint — the page title already conveys that).
		if (liveRegion && view && announceRoutes) { liveRegion.textContent = view; }
	}

	// Put an async button into a real busy state: a spinner + aria-busy,
	// keeping its width so the layout doesn't jump. Returns a restore fn.
	function setBusy(btn, busyLabel) {
		if (!btn) { return function () {}; }
		var prevHtml = btn.innerHTML;
		var prevDisabled = btn.disabled;
		var w = btn.offsetWidth;
		if (w) { btn.style.minWidth = w + 'px'; }
		btn.disabled = true;
		btn.setAttribute('aria-busy', 'true');
		btn.innerHTML = '<span class="mahan-btn-spinner" aria-hidden="true"></span>' +
			(busyLabel ? '<span>' + esc(busyLabel) + '</span>' : '');
		return function restore(newHtml) {
			btn.disabled = prevDisabled;
			btn.removeAttribute('aria-busy');
			btn.style.minWidth = '';
			btn.innerHTML = (newHtml !== undefined && newHtml !== null) ? newHtml : prevHtml;
		};
	}

	// Minimal, safe markdown for tutor/AI text.
	function mdToHtml(text) {
		var lines = String(text || '').replace(/\r\n/g, '\n').split('\n');
		var out = '', ul = false, ol = false;
		function closeLists() { if (ul) { out += '</ul>'; ul = false; } if (ol) { out += '</ol>'; ol = false; } }
		function inline(s) {
			s = esc(s);
			s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
			s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
			s = s.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
			return s;
		}
		lines.forEach(function (line) {
			var s = line.trim();
			if (!s) { closeLists(); return; }
			var m;
			if ((m = s.match(/^[-*]\s+(.*)$/))) { if (!ul) { closeLists(); out += '<ul>'; ul = true; } out += '<li>' + inline(m[1]) + '</li>'; return; }
			if ((m = s.match(/^\d+\.\s+(.*)$/))) { if (!ol) { closeLists(); out += '<ol>'; ol = true; } out += '<li>' + inline(m[1]) + '</li>'; return; }
			if ((m = s.match(/^#{1,4}\s+(.*)$/))) { closeLists(); out += '<h4>' + inline(m[1]) + '</h4>'; return; }
			closeLists(); out += '<p>' + inline(s) + '</p>';
		});
		closeLists();
		return out;
	}

	/* ------------------------------------------------------------------ */
	/* State & routing                                                     */
	/* ------------------------------------------------------------------ */

	var state = { view: 'catalog', courseId: 0, lessonId: 0, me: null, profile: null, levelFilter: '' };
	var root = el('mahan-app');

	// --- Keyboard / screen-reader infrastructure (persists across mount()) ---
	// A skip link (first tabbable element) + a polite live region that announces
	// each SPA view change. Both are re-attached on every mount() because
	// mount() clears root.innerHTML.
	var skipLink = null, liveRegion = null, announceRoutes = false;
	function a11yNodes() {
		if (!skipLink) {
			skipLink = h('a', { class: 'mahan-skip', href: '#mahan-main', text: t('skipToContent', 'Skip to content'),
				onClick: function (e) {
					e.preventDefault();
					var m = el('mahan-main');
					if (m) { m.setAttribute('tabindex', '-1'); try { m.focus(); } catch (er) { /* ok */ } m.scrollIntoView(); }
				} });
		}
		if (!liveRegion) {
			liveRegion = h('div', { class: 'mahan-sr-only', id: 'mahan-live', role: 'status', 'aria-live': 'polite' });
		}
	}

	function parseUrl() {
		var p = new URLSearchParams(window.location.search);
		state.view = p.get('view') || 'catalog';
		state.courseId = parseInt(p.get('course') || '0', 10);
		state.lessonId = parseInt(p.get('lesson') || '0', 10);
		state.pathId = parseInt(p.get('path') || '0', 10);
		state.from = p.get('from') || '';
		// Restore filter/search/period from the URL so a shared or bookmarked
		// filtered view reopens exactly as it was.
		state.q = p.get('q') || '';
		state.levelFilter = p.get('level') || '';
		state.lbPeriod = p.get('period') || state.lbPeriod || 'week';
	}

	function urlFor(view, params) {
		var base = D.appUrl || window.location.pathname;
		var u = new URL(base, window.location.origin);
		u.searchParams.set('view', view);
		if (params && params.course) { u.searchParams.set('course', params.course); }
		if (params && params.lesson) { u.searchParams.set('lesson', params.lesson); }
		if (params && params.path) { u.searchParams.set('path', params.path); }
		if (params && params.from) { u.searchParams.set('from', params.from); }
		return u.pathname + u.search;
	}

	// Reflect transient view state (search, filter, period) into the URL
	// without adding a history entry, so Back still works normally.
	function syncUrl() {
		var u = new URL(window.location.href);
		function set(k, v) { if (v) { u.searchParams.set(k, v); } else { u.searchParams.delete(k); } }
		if (state.view === 'catalog') { set('q', state.q); set('level', state.levelFilter); }
		else { u.searchParams.delete('q'); u.searchParams.delete('level'); }
		if (state.view === 'leaderboard') { set('period', state.lbPeriod === 'all' ? 'all' : ''); }
		try { window.history.replaceState(window.history.state, '', u.pathname + u.search); } catch (e) { /* ignore */ }
	}

	// Scroll restoration: remember where the learner was before navigating
	// away, and put them back there on browser back/forward.
	var pendingScroll = null;

	// Set on go(): once the destination's real content mounts, focus moves to
	// the main region so assistive tech announces the route change.
	var pendingFocus = false;

	function go(view, params) {
		var url = urlFor(view, params);
		// Re-tapping the current destination must not stack history entries
		// (which would make Back appear broken) — just refresh in place.
		var samePlace = (url === window.location.pathname + window.location.search);
		// Stamp the current entry with its scroll position before leaving it.
		try {
			window.history.replaceState(
				Object.assign({}, window.history.state || {}, { scroll: window.scrollY || window.pageYOffset || 0 }),
				''
			);
		} catch (e) { /* ignore */ }
		state.view = view;
		state.courseId = (params && params.course) || 0;
		state.lessonId = (params && params.lesson) || 0;
		state.pathId = (params && params.path) || 0;
		state.from = (params && params.from) || '';
		pendingScroll = null;
		pendingFocus = true;
		// From the first user navigation on, announce view changes to AT.
		announceRoutes = true;
		if (!samePlace) { window.history.pushState({ view: view, params: params }, '', url); }
		window.scrollTo(0, 0);
		render();
	}

	window.addEventListener('popstate', function (e) {
		// Back/forward closes any open dialog cleanly (teardown + listener
		// removal) — silently, so a modal's onClose can't navigate on top of
		// the render() below (which already paints the destination view).
		modalStack.slice().forEach(function (m) { m.close(true, true); });
		parseUrl();
		pendingScroll = (e.state && typeof e.state.scroll === 'number') ? e.state.scroll : 0;
		render();
	});

	if ('scrollRestoration' in window.history) { window.history.scrollRestoration = 'manual'; }

	/* ------------------------------------------------------------------ */
	/* HUD (top bar)                                                       */
	/* ------------------------------------------------------------------ */

	// Primary destinations, shared by the top nav (desktop) and bottom nav (mobile).
	function navItems() {
		// Duolingo-style attention badge: surface due reviews on My Learning
		// so learners discover them from anywhere, not just the dashboard.
		var due = (state.me && state.me.stats && state.me.stats.reviews && state.me.stats.reviews.due) || 0;
		return [
			{ view: 'catalog', icon: '🧭', label: t('catalog', 'Explore'),
				active: state.view === 'catalog' || state.view === 'course' },
			D.loggedIn ? { view: 'dashboard', icon: '📚', label: t('dashboard', 'My Learning'), badge: due,
				active: state.view === 'dashboard' || state.view === 'lesson' || state.view === 'review' } : null,
			D.hasPaths ? { view: 'paths', icon: '🗺️', label: t('paths', 'Paths'),
				active: state.view === 'paths' || state.view === 'path' } : null,
			D.leaderboard ? { view: 'leaderboard', icon: '🏆', label: t('leaderboard', 'Leaderboard'),
				active: state.view === 'leaderboard' } : null
		].filter(Boolean);
	}

	function navBadge(it) {
		if (it.badge === undefined) { return null; }
		// Rendered even at 0 (hidden via :empty) so refreshHud() can patch the
		// count in place when stats change mid-session.
		return h('span', {
			class: 'mahan-nav-count',
			text: it.badge > 0 ? (it.badge > 9 ? '9+' : String(it.badge)) : '',
			'aria-label': it.badge > 0 ? it.badge + ' ' + t('reviewsDue', 'reviews due') : null
		});
	}

	function bottomNav() {
		var items = navItems();
		if (items.length < 2) { return null; }
		return h('nav', { class: 'mahan-bottomnav', 'aria-label': t('menu', 'Menu') }, items.map(function (it) {
			return h('a', {
				class: 'mahan-bottomnav-item' + (it.active ? ' is-active' : ''), href: urlFor(it.view),
				'aria-current': it.active ? 'page' : null,
				onClick: function (e) { e.preventDefault(); go(it.view); }
			}, [
				h('span', { class: 'mahan-bottomnav-icon' }, [
					h('span', { text: it.icon, 'aria-hidden': 'true' }),
					navBadge(it)
				]),
				h('span', { class: 'mahan-bottomnav-label', text: it.label })
			]);
		}));
	}

	function hud() {
		var bar = h('header', { class: 'mahan-topbar' });
		// Brand = the site's actual name (two-tone on the last word), not a
		// hardcoded plugin name.
		var siteName = (D.siteName || 'Mahan Academy').trim();
		var spaceAt = siteName.lastIndexOf(' ');
		var brandKids = spaceAt > 0
			? [siteName.slice(0, spaceAt + 1), h('span', { text: siteName.slice(spaceAt + 1) })]
			: [h('span', { text: siteName })];
		var brand = h('a', { class: 'mahan-brand', href: urlFor('catalog'),
			onClick: function (e) { e.preventDefault(); go('catalog'); } }, brandKids);

		var nav = h('nav', { class: 'mahan-nav', 'aria-label': t('menu', 'Menu') }, navItems().map(function (it) {
			return h('a', {
				class: 'mahan-nav-link' + (it.active ? ' is-active' : ''), href: urlFor(it.view),
				'aria-current': it.active ? 'page' : null,
				onClick: function (e) { e.preventDefault(); go(it.view); }
			}, [
				document.createTextNode(it.label),
				navBadge(it)
			]);
		}));

		var right;
		if (D.loggedIn && state.me && state.me.stats) {
			var s = state.me.stats;
			var goalDone = (s.daily_goal > 0 && (s.daily_xp || 0) >= s.daily_goal);
			var cold = hudStreakCold(s);
			right = h('div', { class: 'mahan-hud' }, [
				h('div', { class: 'mahan-hud-item mahan-hud-streak' + (cold ? ' is-cold' : ''), title: t('streak', 'day streak') + ((s.freezes || 0) > 0 ? ' · ' + s.freezes + ' ' + t('freezes', 'streak freezes') : '') }, [
					h('span', { class: 'mahan-hud-icon', id: 'hud-streak-icon', text: cold ? '🕯️' : '🔥' }), h('span', { id: 'hud-streak', text: String(s.streak || 0) }),
					h('span', { class: 'mahan-hud-freeze', id: 'hud-freeze', text: (s.freezes || 0) > 0 ? '❄️' + s.freezes : '' })]),
				(s.daily_goal > 0) ? h('div', { class: 'mahan-hud-item mahan-hud-goal' + (goalDone ? ' is-done' : ''), id: 'hud-goal', title: t('dailyGoal', 'Daily goal') }, [
					h('span', { class: 'mahan-hud-icon', text: goalDone ? '✅' : '🎯' }),
					h('span', { id: 'hud-goal-text', text: (s.daily_xp || 0) + '/' + s.daily_goal })]) : null,
				h('div', { class: 'mahan-hud-item mahan-hud-xp', title: 'XP' }, [
					h('span', { class: 'mahan-hud-icon', text: '⚡' }), h('span', { id: 'hud-xp', text: String(s.xp || 0) })]),
				h('div', { class: 'mahan-hud-item mahan-hud-level', title: s.level_title || t('level', 'Level') }, [
					h('span', { class: 'mahan-hud-icon', text: '◆' }), h('span', { id: 'hud-level', text: String(s.level || 1) })]),
				state.me.user ? h('img', { class: 'mahan-hud-avatar', src: state.me.user.avatar, alt: state.me.user.name }) : null
			]);
		} else if (D.loggedIn) {
			// Logged in but /me hasn't resolved yet — placeholder, not a
			// misleading "Log in" button.
			right = h('div', { class: 'mahan-hud' }, [h('div', { class: 'mahan-sk mahan-sk-circle' })]);
		} else {
			right = h('div', { class: 'mahan-hud' }, [
				h('a', { class: 'mahan-btn mahan-btn-sm mahan-btn-primary', href: loginHref(), text: t('login', 'Log in') })
			]);
		}

		bar.appendChild(brand);
		bar.appendChild(nav);
		bar.appendChild(right);
		return bar;
	}

	// The at-risk signal: a running streak that today's activity hasn't
	// extended yet (last week-strip day inactive).
	function hudStreakCold(s) {
		return (s.streak || 0) > 0 && !!(s.week && s.week.length) && !s.week[s.week.length - 1].active;
	}

	function refreshHud(stats) {
		if (!stats) { return; }
		// Bare hud() payloads may lack week/reviews — keep the last known
		// values so the flame and the reviews badge don't silently reset.
		if (state.me && state.me.stats) {
			if (!stats.week) { stats.week = state.me.stats.week; }
			if (!stats.reviews) { stats.reviews = state.me.stats.reviews; }
		}
		if (state.me) { state.me.stats = stats; }
		var x = el('hud-xp'), l = el('hud-level'), st = el('hud-streak'), fz = el('hud-freeze'), g = el('hud-goal'), gt = el('hud-goal-text');
		if (x) { x.textContent = stats.xp; }
		if (l) { l.textContent = stats.level; }
		if (st) { st.textContent = stats.streak; }
		if (fz) { fz.textContent = (stats.freezes || 0) > 0 ? '❄️' + stats.freezes : ''; }
		if (g && gt && stats.daily_goal > 0) {
			var done = (stats.daily_xp || 0) >= stats.daily_goal;
			gt.textContent = (stats.daily_xp || 0) + '/' + stats.daily_goal;
			g.classList.toggle('is-done', done);
			var icon = g.querySelector('.mahan-hud-icon');
			if (icon) { icon.textContent = done ? '✅' : '🎯'; }
		}
		var streakItem = document.querySelector('.mahan-hud-streak');
		if (streakItem) {
			var cold = hudStreakCold(stats);
			streakItem.classList.toggle('is-cold', cold);
			var sIcon = el('hud-streak-icon');
			if (sIcon) { sIcon.textContent = cold ? '🕯️' : '🔥'; }
		}
		// Reviews-due badge on the nav (desktop + mobile), patched in place.
		var due = (stats.reviews && stats.reviews.due) || 0;
		var badges = document.querySelectorAll('.mahan-nav-count');
		for (var bi = 0; bi < badges.length; bi++) {
			badges[bi].textContent = due > 0 ? (due > 9 ? '9+' : String(due)) : '';
			if (due > 0) { badges[bi].setAttribute('aria-label', due + ' ' + t('reviewsDue', 'reviews due')); }
			else { badges[bi].removeAttribute('aria-label'); }
		}
	}

	/* ------------------------------------------------------------------ */
	/* Toasts                                                              */
	/* ------------------------------------------------------------------ */

	function toast(msg, kind) {
		var box = el('mahan-toasts');
		if (!box) { box = h('div', { id: 'mahan-toasts', class: 'mahan-toasts', role: 'status', 'aria-live': 'polite' }); document.body.appendChild(box); }
		// Never stack more than 3 — a lesson completion can fire XP + streak
		// + goal + level + badges; drop the oldest instead of a toast tower.
		while (box.children.length >= 3) { box.removeChild(box.firstChild); }
		var node = h('div', { class: 'mahan-toast mahan-toast-' + (kind || 'info'), html: msg });
		// Dismiss on tap; celebrations linger a bit longer than info blips.
		var life = (kind === 'level') ? 4000 : 2600;
		function out() { node.classList.add('is-out'); setTimeout(function () { node.remove(); }, 400); }
		node.addEventListener('click', out);
		box.appendChild(node);
		setTimeout(out, life);
	}

	function celebrateXp(res) {
		if (!res) { return; }
		// Celebrate crossing the daily goal and extending the streak — the
		// two core-loop moments — by comparing stats before/after.
		var old = state.me && state.me.stats;
		if (res.xp_awarded > 0) { toast('⚡ +' + res.xp_awarded + ' XP', 'xp'); }
		// Merge the streak/goal celebrations into ONE toast so a big moment
		// (xp + streak + goal + level + badge) doesn't evict its own toasts.
		var parts = [];
		if (old && res.stats) {
			if (res.stats.streak > (old.streak || 0)) {
				parts.push('🔥 ' + res.stats.streak + ' ' + esc(t('streak', 'day streak')) + '!');
			}
			if ((res.stats.daily_goal || 0) > 0 && (old.daily_xp || 0) < (old.daily_goal || 0) && (res.stats.daily_xp || 0) >= res.stats.daily_goal) {
				parts.push('🎯 ' + esc(tv('goalVariants', 'goalHit', 'Daily goal reached!')));
			}
		}
		if (parts.length) {
			setTimeout(function () { toast(parts.join(' · '), 'level'); }, 350);
		}
		if (res.stats) {
			if (!state.me) {
				// /me hasn't resolved yet (e.g. a deep-linked review). Seed it
				// so the HUD can render, and rebuild the top bar (which is a
				// placeholder skeleton with no live nodes to update).
				state.me = { stats: res.stats, user: D.user ? { name: D.user.name, display_name: D.user.name, avatar: D.user.avatar } : null };
				refreshTopbar();
			} else {
				refreshHud(res.stats);
			}
		}
		if (res.leveled_up) {
			var title = (res.stats && res.stats.level_title) ? ' — ' + res.stats.level_title : '';
			// Rotate the celebration copy so the reward loop doesn't go stale.
			// Seed by level so the same level always reads the same, but climbing
			// levels rotates through fresh phrasings.
			var lvMsg = tv('levelUpVariants', 'levelUp', 'Level up!', (res.stats && res.stats.level) || 1);
			setTimeout(function () { toast('◆ ' + lvMsg + title, 'level'); }, 650);
		}
		// New achievements arrive with grading/progress responses — celebrate each.
		(res.new_badges || []).forEach(function (b, i) {
			setTimeout(function () {
				toast(esc(b.icon) + ' ' + esc(t('badgeEarned', 'Achievement unlocked:')) + ' <strong>' + esc(b.title) + '</strong>', 'level');
			}, 1000 + i * 900);
		});
	}

	/* ------------------------------------------------------------------ */
	/* Loading / shells                                                    */
	/* ------------------------------------------------------------------ */

	function mount(content) {
		// Keep any open dialog alive across repaints (e.g. a quiz opened
		// while a stale-while-revalidate refresh was still in flight).
		var overlays = Array.prototype.slice.call(root.querySelectorAll('.mahan-modal-overlay'));
		overlays.forEach(function (o) { o.remove(); });
		root.innerHTML = '';
		a11yNodes();
		// Skip link is the first tabbable element; the live region trails.
		root.appendChild(skipLink);
		root.appendChild(hud());
		var main = h('main', { class: 'mahan-main', id: 'mahan-main' }, [content]);
		root.appendChild(main);
		var bnav = bottomNav();
		if (bnav) { root.appendChild(bnav); }
		root.appendChild(liveRegion);
		overlays.forEach(function (o) { root.appendChild(o); });
		var isSkeleton = !!main.querySelector('.mahan-skeleton');
		// Restore the saved scroll position once real content (not a
		// skeleton) is on screen — back/forward lands where the user was.
		if (pendingScroll !== null && !isSkeleton) {
			window.scrollTo(0, pendingScroll);
			pendingScroll = null;
		}
		// SPA route-change announcement: move focus to the new main region
		// (once real content is in) so screen readers read the new view
		// instead of being stranded on a removed node.
		if (pendingFocus && !isSkeleton) {
			pendingFocus = false;
			main.setAttribute('tabindex', '-1');
			try { main.focus({ preventScroll: true }); } catch (e) { main.focus(); }
		}
	}

	// Swap just the top bar in place (used when /me resolves after boot).
	function refreshTopbar() {
		var old = root.querySelector('.mahan-topbar');
		if (old) { old.parentNode.replaceChild(hud(), old); }
	}

	/* Skeleton loading — mirrors each view's real layout instead of a spinner. */

	function skLine(w) { return h('div', { class: 'mahan-sk mahan-sk-line' + (w ? ' mahan-sk-' + w : '') }); }

	function skCard() {
		return h('div', { class: 'mahan-card mahan-sk-card' }, [
			h('div', { class: 'mahan-sk mahan-sk-media' }),
			h('div', { class: 'mahan-card-body' }, [skLine('w40'), skLine('w90'), skLine('w70')])
		]);
	}

	function skRow() {
		return h('div', { class: 'mahan-lesson-row mahan-sk-row' }, [
			h('div', { class: 'mahan-sk mahan-sk-circle' }),
			skLine('grow')
		]);
	}

	function loadingShell(kind) {
		var wrap = h('div', { class: 'mahan-skeleton', role: 'status', 'aria-label': t('loading', 'Loading…') });
		var i, list, grid;
		if (kind === 'article') {
			var layout = h('div', { class: 'mahan-lesson-layout' });
			layout.appendChild(h('div', { class: 'mahan-lesson-col' }, [
				h('div', { class: 'mahan-sk mahan-sk-h1' }),
				skLine('w90'), skLine('w80'), skLine('w90'), skLine('w60'), skLine('w90'), skLine('w70')
			]));
			layout.appendChild(h('div', { class: 'mahan-tutor mahan-sk-panel' }, [skLine('w60'), skLine('w90'), skLine('w80')]));
			wrap.appendChild(layout);
		} else if (kind === 'detail') {
			wrap.appendChild(h('div', { class: 'mahan-course-hero' }, [
				h('div', {}, [skLine('w30'), h('div', { class: 'mahan-sk mahan-sk-h1' }), skLine('w60'), skLine('w40')])
			]));
			list = h('div', { class: 'mahan-lesson-list' });
			for (i = 0; i < 5; i++) { list.appendChild(skRow()); }
			wrap.appendChild(h('div', { class: 'mahan-section' }, [list]));
		} else if (kind === 'rows') {
			list = h('div', { class: 'mahan-lb-list' });
			for (i = 0; i < 8; i++) { list.appendChild(skRow()); }
			wrap.appendChild(list);
		} else if (kind === 'dash') {
			wrap.appendChild(h('div', { class: 'mahan-dash-hero' }, [
				h('div', { class: 'mahan-sk mahan-sk-h1' }),
				h('div', { class: 'mahan-dash-stats' }, [0, 1, 2].map(function () {
					return h('div', { class: 'mahan-stat-big' }, [h('div', { class: 'mahan-sk mahan-sk-circle' }), skLine('w60')]);
				}))
			]));
			grid = h('div', { class: 'mahan-grid' });
			for (i = 0; i < 3; i++) { grid.appendChild(skCard()); }
			wrap.appendChild(grid);
		} else {
			wrap.appendChild(h('div', { class: 'mahan-hero mahan-sk-hero' }, [h('div', { class: 'mahan-sk mahan-sk-h1' }), skLine('w40')]));
			grid = h('div', { class: 'mahan-grid' });
			for (i = 0; i < 6; i++) { grid.appendChild(skCard()); }
			wrap.appendChild(grid);
		}
		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Modal helper (a11y: focus trap, Escape / overlay close)            */
	/* ------------------------------------------------------------------ */

	// Open dialogs, so navigation (popstate) can tear them down cleanly.
	var modalStack = [];

	function openModal(dialog, opts) {
		opts = opts || {};
		var overlay = h('div', { class: 'mahan-modal-overlay' }, [dialog]);
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		if (opts.label) { dialog.setAttribute('aria-label', opts.label); }
		dialog.setAttribute('tabindex', '-1');
		var prevFocus = document.activeElement;
		var closed = false;

		function close(force, silent) {
			if (closed) { return; }
			// Let the dialog veto an accidental dismissal (e.g. a quiz with
			// answers in progress). Explicit buttons pass force=true.
			if (!force && opts.beforeClose && !opts.beforeClose()) { return; }
			closed = true;
			var idx = modalStack.indexOf(handle);
			if (idx >= 0) { modalStack.splice(idx, 1); }
			document.removeEventListener('keydown', onKey, true);
			overlay.remove();
			if (prevFocus && typeof prevFocus.focus === 'function') { try { prevFocus.focus(); } catch (e) { /* gone */ } }
			// `silent` suppresses onClose side effects — used when the browser
			// Back button closes the dialog and render() will already repaint
			// the destination view (so onClose must not also navigate).
			if (opts.onClose && !silent) { opts.onClose(); }
		}

		function onKey(e) {
			if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
			if (e.key !== 'Tab') { return; }
			var f = dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])');
			if (!f.length) { e.preventDefault(); dialog.focus(); return; }
			var first = f[0], last = f[f.length - 1];
			if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		}

		overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
		document.addEventListener('keydown', onKey, true);
		// Inside the app root so theme CSS variables (incl. dark mode) apply.
		(root || document.body).appendChild(overlay);
		dialog.focus();

		var handle = { overlay: overlay, dialog: dialog, close: close };
		modalStack.push(handle);
		return handle;
	}

	/* ------------------------------------------------------------------ */
	/* View: Catalog                                                       */
	/* ------------------------------------------------------------------ */

	function renderCatalog() {
		setTitle(t('catalog', 'Explore'));
		cachedApi('/catalog', 'cards', function (j) {
			var courses = j.courses || [];
			var wrap = h('div', { class: 'mahan-catalog' });
			wrap.appendChild(h('div', { class: 'mahan-hero' }, [
				h('h1', { text: D.heroTitle || 'Learn to use AI at work' }),
				h('p', { class: 'mahan-hero-sub', text: D.heroSub || 'Structured courses. Hands-on practice. A tutor that answers in real time.' })
			]));

			// Search + level filter — both client-side and instant.
			var search = h('input', {
				class: 'mahan-search-input', type: 'search',
				placeholder: t('searchCourses', 'Search courses…'),
				'aria-label': t('searchCourses', 'Search courses…')
			});
			search.value = state.q || '';
			wrap.appendChild(h('div', { class: 'mahan-search' }, [
				h('span', { class: 'mahan-search-icon', text: '🔍', 'aria-hidden': 'true' }),
				search
			]));

			var levels = [['', t('allLevels', 'All levels')], ['beginner', t('beginner', 'Beginner')], ['intermediate', t('intermediate', 'Intermediate')], ['advanced', t('advanced', 'Advanced')]];
			var chipsArea = h('div', { class: 'mahan-chips' });
			var listArea = h('div', { class: 'mahan-catalog-list' });
			wrap.appendChild(chipsArea);
			wrap.appendChild(listArea);

			function matches(c) {
				if (state.levelFilter && c.level !== state.levelFilter) { return false; }
				var q = (state.q || '').toLowerCase().trim();
				if (!q) { return true; }
				var hay = [c.title, c.subtitle, c.excerpt, (c.categories || []).join(' ')].join(' ').toLowerCase();
				return hay.indexOf(q) >= 0;
			}

			function paintChips() {
				chipsArea.innerHTML = '';
				levels.forEach(function (lv) {
					chipsArea.appendChild(h('button', {
						class: 'mahan-chip' + (state.levelFilter === lv[0] ? ' is-active' : ''),
						text: lv[1], 'aria-pressed': state.levelFilter === lv[0] ? 'true' : 'false',
						onClick: function () { state.levelFilter = lv[0]; paintChips(); paintList(); syncUrl(); }
					}));
				});
			}

			function paintList() {
				listArea.innerHTML = '';
				if (!courses.length) {
					listArea.appendChild(h('div', { class: 'mahan-empty' }, [
						h('div', { class: 'mahan-empty-icon', 'aria-hidden': 'true', text: '📚' }),
						h('p', { text: t('emptyCatalog', 'No courses available yet.') })
					]));
					return;
				}
				var filtered = courses.filter(matches);
				if (!filtered.length) {
					listArea.appendChild(h('div', { class: 'mahan-empty' }, [
						h('div', { class: 'mahan-empty-icon', 'aria-hidden': 'true', text: '🔍' }),
						h('p', { text: t('noResults', 'No courses match your search.') }),
						h('button', { class: 'mahan-btn mahan-btn-ghost', text: t('clearFilters', 'Clear filters'),
							onClick: function () { state.q = ''; state.levelFilter = ''; search.value = ''; paintChips(); paintList(); syncUrl(); } })
					]));
					return;
				}
				listArea.appendChild(h('h2', { class: 'mahan-sr-only', text: t('coursesHeading', 'Courses') }));
				var grid = h('div', { class: 'mahan-grid' });
				filtered.forEach(function (c) { grid.appendChild(courseCard(c)); });
				listArea.appendChild(grid);
			}

			var syncT;
			search.addEventListener('input', function () {
				state.q = search.value; paintList();
				clearTimeout(syncT); syncT = setTimeout(syncUrl, 350);
			});

			paintChips();
			paintList();
			mount(wrap);
		}, renderCatalog);
	}

	// Warm the session cache for a course while the pointer hovers its card,
	// so the click lands on an instant paint instead of a skeleton.
	var prefetching = {};
	function prefetchCourse(id) {
		var path = '/course/' + id;
		if (apiCache[path] || prefetching[path]) { return; }
		prefetching[path] = true;
		api(path).then(function (j) { apiCache[path] = j; }).catch(function () {})
			.then(function () { delete prefetching[path]; });
	}

	function courseCard(c, from) {
		var nav = { course: c.id };
		if (from) { nav.from = from; }
		var media = c.image
			? h('div', { class: 'mahan-card-media' }, [
				h('img', { class: 'mahan-card-img', src: c.image, alt: '', loading: 'lazy', decoding: 'async',
					onLoad: function (e) { e.currentTarget.classList.add('is-loaded'); } })
			])
			: h('div', { class: 'mahan-card-media mahan-card-media-ph' }, [h('span', { text: (c.title || '?').charAt(0) })]);

		var foot = c.enrolled
			? h('div', { class: 'mahan-progress' }, [
				h('div', { class: 'mahan-progress-bar' }, [h('span', { style: 'width:' + (c.progress_pct || 0) + '%' })]),
				h('span', { class: 'mahan-progress-label', text: (c.progress_pct || 0) + '%' })])
			: h('div', { class: 'mahan-card-tags' }, [
				h('span', { class: 'mahan-tag', text: levelLabel(c.level) }),
				h('span', { class: 'mahan-tag mahan-tag-soft', text: plural(c.lesson_count, 'lessonOne', 'lessons', 'lesson', 'lessons') })]);

		return h('a', {
			class: 'mahan-card' + (c.featured ? ' is-featured' : ''), href: urlFor('course', nav),
			onClick: function (e) { e.preventDefault(); go('course', nav); },
			onMouseenter: function () { prefetchCourse(c.id); },
			onFocus: function () { prefetchCourse(c.id); }
		}, [
			c.featured ? h('span', { class: 'mahan-ribbon', text: '★ ' + t('featured', 'Featured') }) : null,
			media,
			h('div', { class: 'mahan-card-body' }, [
				h('span', { class: 'mahan-card-cat', text: (c.categories && c.categories[0]) || 'AI Skills' }),
				h('h3', { class: 'mahan-card-title', text: c.title }),
				h('p', { class: 'mahan-card-sub', text: c.subtitle || c.excerpt || '' }),
				foot
			])
		]);
	}

	function levelLabel(lv) {
		return lv === 'advanced' ? t('advanced', 'Advanced') : lv === 'intermediate' ? t('intermediate', 'Intermediate') : t('beginner', 'Beginner');
	}

	// Resolve the course view's back link from state.from (set by whichever
	// card the learner tapped).
	function courseBackLink() {
		var from = state.from || '';
		if (from === 'dashboard' && D.loggedIn) {
			return { label: t('dashboard', 'My Learning'), href: urlFor('dashboard'), go: function () { go('dashboard'); } };
		}
		if (from.indexOf('path:') === 0) {
			var pid = parseInt(from.slice(5), 10);
			if (pid) { return { label: t('paths', 'Paths'), href: urlFor('path', { path: pid }), go: function () { go('path', { path: pid }); } }; }
		}
		if (from === 'paths') {
			return { label: t('learningPaths', 'Learning paths'), href: urlFor('paths'), go: function () { go('paths'); } };
		}
		return { label: t('catalog', 'Explore'), href: urlFor('catalog'), go: function () { go('catalog'); } };
	}

	/* ------------------------------------------------------------------ */
	/* View: Course                                                        */
	/* ------------------------------------------------------------------ */

	function renderCourse() {
		cachedApi('/course/' + state.courseId, 'detail', function (j) {
			if (!j.ok) { mount(errorBox(renderCourse)); return; }
			var c = j.course;
			setTitle(c.title);
			var wrap = h('div', { class: 'mahan-course' });

			// Hero. Back link points at wherever the learner arrived from
			// (dashboard, a path, or the catalog).
			var back = courseBackLink();
			var hero = h('div', { class: 'mahan-course-hero' }, [
				h('div', { class: 'mahan-course-hero-text' }, [
					h('a', { class: 'mahan-back', href: back.href, onClick: function (e) { e.preventDefault(); back.go(); }, text: '← ' + back.label }),
					h('h1', { text: c.title }),
					c.subtitle ? h('p', { class: 'mahan-course-sub', text: c.subtitle }) : null,
					h('div', { class: 'mahan-course-meta' }, [
						h('span', { class: 'mahan-tag', text: levelLabel(c.level) }),
						h('span', { class: 'mahan-tag mahan-tag-soft', text: c.lesson_count + ' ' + t('lessons', 'lessons') }),
						c.est_hours ? h('span', { class: 'mahan-tag mahan-tag-soft', text: '~' + c.est_hours + 'h' }) : null
					]),
					courseCta(j)
				]),
				c.image ? h('div', { class: 'mahan-course-hero-media', style: 'background-image:url(' + esc(c.image) + ')' }) : null
			]);
			wrap.appendChild(hero);

			// Prerequisite note.
			if (j.prerequisite) {
				wrap.appendChild(h('div', { class: 'mahan-note' }, [
					h('span', { text: '💡 ' + t('prereqNote', 'Recommended first:') + ' ' }),
					h('a', { href: urlFor('course', { course: j.prerequisite.id }), text: j.prerequisite.title,
						onClick: function (e) { e.preventDefault(); go('course', { course: j.prerequisite.id }); } })
				]));
			}

			// Promo video.
			if (j.promo_video && j.promo_video.src) {
				var pv = j.promo_video;
				var media = pv.type === 'file'
					? h('video', { class: 'mahan-promo', src: pv.src, controls: 'controls' })
					: h('div', { class: 'mahan-promo mahan-promo-embed' }, [
						h('iframe', { src: pv.src, frameborder: '0', allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture', allowfullscreen: 'allowfullscreen' })
					]);
				wrap.appendChild(h('section', { class: 'mahan-section mahan-promo-wrap' }, [media]));
			}

			// Certificate note.
			if (j.certificate) {
				wrap.appendChild(h('div', { class: 'mahan-note mahan-note-cert' }, [
					h('span', { text: '🎓 ' + t('certificate', 'Certificate of completion') }),
					j.completed ? h('button', { class: 'mahan-btn mahan-btn-sm mahan-btn-ghost', text: t('viewCertificate', 'View certificate'),
						onClick: function () { showCertificate(j.course); } }) : null
				]));
			}

			// Outcomes.
			if (j.outcomes && j.outcomes.length) {
				wrap.appendChild(h('section', { class: 'mahan-section' }, [
					h('h2', { text: t('whatYouLearn', "What you'll learn") }),
					h('ul', { class: 'mahan-outcomes' }, j.outcomes.map(function (o) {
						return h('li', {}, [h('span', { class: 'mahan-check', text: '✓' }), document.createTextNode(o)]);
					}))
				]));
			}

			// Description.
			if (j.description && j.description.trim()) {
				wrap.appendChild(h('section', { class: 'mahan-section mahan-prose', html: j.description }));
			}

			// Content / units.
			var content = h('section', { class: 'mahan-section' }, [h('h2', { text: t('courseContent', 'Course content') })]);
			(j.units || []).forEach(function (unit) {
				content.appendChild(h('h3', { class: 'mahan-unit-title', text: unit.title }));
				var list = h('ul', { class: 'mahan-lesson-list' });
				unit.lessons.forEach(function (ls) {
					list.appendChild(lessonRow(ls, j.enrolled));
				});
				if (unit.quiz) {
					list.appendChild(quizRow(unit, j));
				}
				content.appendChild(list);
			});
			wrap.appendChild(content);

			mount(wrap);
		}, renderCourse);
	}

	function courseCta(j) {
		if (!D.loggedIn) {
			// Give first-time visitors a signup path, not just "Log in".
			return h('div', { class: 'mahan-cta-row' }, [
				h('a', { class: 'mahan-btn mahan-btn-primary mahan-btn-lg', href: loginHref(), text: t('loginToLearn', 'Log in to start learning') }),
				D.canRegister ? h('a', { class: 'mahan-btn mahan-btn-ghost mahan-btn-lg', href: D.registerUrl, text: t('register', 'Create account') }) : null
			]);
		}
		if (!j.enrolled) {
			return h('button', { class: 'mahan-btn mahan-btn-primary mahan-btn-lg', text: t('enroll', 'Enroll — free'),
				onClick: function (e) {
					var restore = setBusy(e.currentTarget, t('loading', 'Loading…'));
					api('/enroll', 'POST', { course_id: j.course.id }).then(function (r) {
						if (r.stats) { refreshHud(r.stats); }
						// Enrolling means "start learning" — go straight to lesson 1.
						var first = firstActionableLesson(j);
						if (first) { go('lesson', { course: j.course.id, lesson: first }); }
						else { renderCourse(); }
					}).catch(function (err) {
						restore();
						var reason = err && err.payload && err.payload.error;
						toast(esc(reason || t('error', 'Something went wrong.')), 'error');
					});
				} });
		}
		if ((j.progress_pct || 0) >= 100) {
			// Completed: don't relabel it "Resume" — offer a review pass.
			var firstLesson = firstActionableLesson(j);
			return h('button', { class: 'mahan-btn mahan-btn-primary mahan-btn-lg is-done',
				text: '✓ ' + t('reviewCourse', 'Review course'),
				onClick: function () { if (firstLesson) { go('lesson', { course: j.course.id, lesson: firstLesson }); } } });
		}
		// Enrolled: continue to first incomplete lesson.
		var next = firstActionableLesson(j);
		return h('button', { class: 'mahan-btn mahan-btn-primary mahan-btn-lg',
			text: (j.progress_pct > 0 ? t('resume', 'Resume') : t('start', 'Start course')),
			onClick: function () { if (next) { go('lesson', { course: j.course.id, lesson: next }); } } });
	}

	function firstActionableLesson(j) {
		var pick = 0, firstId = 0;
		(j.units || []).forEach(function (u) {
			u.lessons.forEach(function (ls) {
				if (!firstId) { firstId = ls.id; }
				if (!pick && ls.status !== 'completed') { pick = ls.id; }
			});
		});
		return pick || firstId;
	}

	function lessonRow(ls, enrolled) {
		var icon = ls.status === 'completed' ? '✓' : (ls.type === 'practice' ? '✎' : '▶');
		var cls = 'mahan-lesson-row';
		if (ls.status === 'completed') { cls += ' is-done'; }
		if (ls.locked) { cls += ' is-locked'; }
		var clickable = enrolled && !ls.locked;
		return h(clickable ? 'a' : 'div', {
			class: cls,
			href: clickable ? urlFor('lesson', { course: state.courseId, lesson: ls.id }) : null,
			role: clickable ? null : 'button',
			tabindex: clickable ? null : '0',
			'aria-disabled': clickable ? null : 'true',
			title: ls.locked ? t('locked', 'Complete the previous lesson to unlock') : '',
			onClick: clickable
				? function (e) { e.preventDefault(); go('lesson', { course: state.courseId, lesson: ls.id }); }
				// Inert rows still explain themselves on tap instead of dead silence.
				: function () {
					toast(ls.locked
						? esc(t('lockedLesson', 'Complete the previous lesson to unlock this one.'))
						: esc(t('enrollFirst', 'Enroll first — it takes one tap and it\'s free.')), 'info');
				}
		}, [
			h('span', { class: 'mahan-lesson-icon', text: ls.locked ? '🔒' : icon }),
			h('span', { class: 'mahan-lesson-name', text: ls.title }),
			h('span', { class: 'mahan-lesson-meta' }, [
				ls.est_min ? h('span', { class: 'mahan-lesson-min', text: ls.est_min + ' min' }) : null,
				h('span', { class: 'mahan-lesson-xp', text: '⚡' + ls.xp })
			])
		]);
	}

	function quizRow(unit, j) {
		var q = unit.quiz;
		var clickable = j.enrolled && D.loggedIn;
		var cls = 'mahan-lesson-row mahan-quiz-row' + (q.passed ? ' is-done' : '');
		return h(clickable ? 'button' : 'div', {
			class: cls,
			type: clickable ? 'button' : null,
			onClick: clickable ? function () { openQuiz(j.course.id, unit.title); } : null
		}, [
			h('span', { class: 'mahan-lesson-icon', text: q.passed ? '✓' : '❓' }),
			h('span', { class: 'mahan-lesson-name' }, [
				document.createTextNode(q.title + '  '),
				h('span', { class: 'mahan-quiz-tag', text: t('quiz', 'Quiz') })
			]),
			h('span', { class: 'mahan-lesson-meta' }, [
				q.passed ? h('span', { class: 'mahan-quiz-score', text: '✓ ' + q.score + '%' })
					: (q.score !== null ? h('span', { class: 'mahan-quiz-score-fail', text: q.score + '%' }) : null),
				h('span', { class: 'mahan-lesson-min', text: plural(q.count, 'questionOne', 'questions', 'question', 'questions') })
			])
		]);
	}

	/* ------------------------------------------------------------------ */
	/* Unit quiz (modal)                                                   */
	/* ------------------------------------------------------------------ */

	function openQuiz(courseId, unit) {
		api('/quiz?course_id=' + courseId + '&unit=' + encodeURIComponent(unit)).then(function (j) {
			if (!j.ok) { toast(t('error', 'Something went wrong.'), 'error'); return; }
			renderQuizModal(courseId, unit, j.quiz);
		}).catch(function () { toast(t('error', 'Something went wrong.'), 'error'); });
	}

	function renderQuizModal(courseId, unit, quiz) {
		var answers = {};
		var total = quiz.questions.length;
		var counter = h('span', { class: 'mahan-quiz-count', 'aria-live': 'polite' });

		function answeredCount() {
			var n = 0;
			Object.keys(answers).forEach(function (k) {
				var v = answers[k];
				if (v !== '' && v !== null && v !== undefined) { n++; }
			});
			return n;
		}
		function updateCounter() {
			counter.textContent = answeredCount() + '/' + total + ' ' + t('answered', 'answered');
		}

		var form = h('div', { class: 'mahan-quiz-form' });
		quiz.questions.forEach(function (q, i) {
			var block = h('div', { class: 'mahan-quiz-q' });
			block.appendChild(h('div', { class: 'mahan-quiz-q-title', html: (i + 1) + '. ' + mdToHtml(q.question) }));
			if ((q.type === 'multiple_choice' || q.type === 'true_false') && q.options) {
				var opts = h('div', { class: 'mahan-quiz-opts' }, q.options.map(function (o, oi) {
					return h('button', { class: 'mahan-ex-option', type: 'button', text: o, 'aria-pressed': 'false',
						onClick: function (e) {
							opts.querySelectorAll('.mahan-ex-option').forEach(function (b) { b.classList.remove('is-chosen'); b.setAttribute('aria-pressed', 'false'); });
							e.currentTarget.classList.add('is-chosen');
						e.currentTarget.setAttribute('aria-pressed', 'true');
							answers[q.key] = oi;
							updateCounter();
						} });
				}));
				block.appendChild(opts);
			} else {
				var inp = h('input', { class: 'mahan-ex-input', type: 'text', placeholder: t('typeAnswer', 'Type your answer…'), 'aria-label': t('typeAnswer', 'Type your answer…') });
				inp.addEventListener('input', function () {
					answers[q.key] = inp.value.trim() === '' ? '' : inp.value;
					updateCounter();
				});
				block.appendChild(inp);
			}
			block.setAttribute('data-key', q.key);
			form.appendChild(block);
		});
		updateCounter();

		var msg = h('div', { class: 'mahan-quiz-msg', role: 'status' });
		var modal;
		var armed = false;
		var submitBtn = h('button', { class: 'mahan-btn mahan-btn-primary', text: t('submitQuiz', 'Submit quiz'),
			onClick: function (e) {
				var btn = e.currentTarget;
				// Unanswered questions are graded as wrong — make that a
				// deliberate choice, not a silent surprise.
				if (answeredCount() < total && !armed) {
					armed = true;
					msg.textContent = t('unansweredWarn', 'Some questions are unanswered — tap Submit again to send anyway.');
					return;
				}
				var restore = setBusy(btn, t('loading', 'Loading…'));
				msg.textContent = '';
				api('/quiz', 'POST', { course_id: courseId, unit: unit, answers: answers }).then(function (r) {
					modal.submitted = true;
					showQuizResult(modal, form, unit, r);
				}).catch(function () { restore(); msg.textContent = t('error', 'Something went wrong.'); });
			} });

		var dialog = h('div', { class: 'mahan-modal mahan-quiz-modal' }, [
			h('h2', { text: quiz.title }),
			h('p', { class: 'mahan-modal-sub', text: quiz.count + ' ' + t('questions', 'questions') + ' · ' + t('passMark', 'pass') + ' ' + quiz.passing + '%' }),
			form,
			msg,
			h('div', { class: 'mahan-modal-actions mahan-quiz-actions' }, [
				counter,
				h('button', { class: 'mahan-btn mahan-btn-ghost', text: t('notNow', 'Close'), onClick: function () { modal.close(); } }),
				submitBtn
			])
		]);
		var closeArmed = false;
		modal = openModal(dialog, {
			label: quiz.title,
			// A stray Escape / overlay tap must not silently destroy answers.
			beforeClose: function () {
				if (modal.submitted || answeredCount() === 0 || closeArmed) { return true; }
				closeArmed = true;
				msg.textContent = t('closeQuizWarn', 'Your answers will be lost — close again to confirm.');
				return false;
			},
			onClose: function () {
				// Reflect a graded attempt (score / passed) back on the course view.
				if (modal.submitted && state.view === 'course') { renderCourse(); }
			}
		});
	}

	function showQuizResult(modal, form, unit, r) {
		// Mark each question right/wrong.
		var byKey = {};
		(r.results || []).forEach(function (res) { byKey[res.key] = res; });
		form.querySelectorAll('.mahan-quiz-q').forEach(function (block) {
			var key = block.getAttribute('data-key');
			var res = byKey[key];
			if (!res) { return; }
			block.classList.add(res.correct ? 'is-correct' : 'is-incorrect');
			var qtitle = block.querySelector('.mahan-quiz-q-title');
			if (qtitle) { qtitle.insertBefore(h('span', { class: 'mahan-grade-cue', 'aria-label': res.correct ? t('correct', 'Correct!') : t('incorrect', 'Not quite'), text: (res.correct ? '✓' : '✕') + ' ' }), qtitle.firstChild); }
			var opts = block.querySelectorAll('.mahan-ex-option');
			if (opts.length && typeof res.correct_index === 'number' && opts[res.correct_index]) {
				opts[res.correct_index].classList.add('is-correct');
				opts[res.correct_index].appendChild(h('span', { class: 'mahan-sr-only', text: ' (' + t('correctAnswer', 'correct answer') + ')' }));
			}
			block.querySelectorAll('.mahan-ex-option, .mahan-ex-input').forEach(function (el) { el.disabled = true; });
		});
		celebrateXp(r);

		var head = modal.dialog.querySelector('h2');
		var banner = h('div', { class: 'mahan-quiz-result ' + (r.passed ? 'is-pass' : 'is-fail') }, [
			h('span', { class: 'mahan-quiz-result-icon', text: r.passed ? '🎉' : '💪' }),
			h('div', {}, [
				h('strong', { text: (r.passed ? t('quizPassed', 'Passed!') : t('quizFailed', 'Keep going')) + ' — ' + r.score + '%' }),
				h('div', { class: 'mahan-quiz-result-sub', text: r.correct + ' / ' + r.total + ' ' + t('correctCount', 'correct') })
			])
		]);
		head.parentNode.insertBefore(banner, head.nextSibling);

		var actions = modal.dialog.querySelector('.mahan-modal-actions');
		actions.innerHTML = '';
		actions.appendChild(h('button', { class: 'mahan-btn mahan-btn-primary', text: t('done', 'Done'),
			onClick: function () { modal.close(); } }));
		if (!r.passed) {
			actions.appendChild(h('button', { class: 'mahan-btn mahan-btn-ghost', text: t('retry', 'Try again'),
				// force-close (true) so the beforeClose "answers will be lost"
				// guard doesn't veto it and leave two modals stacked.
				onClick: function () { modal.submitted = false; modal.close(true); openQuiz(state.courseId, unit); } }));
		}
	}

	/* ------------------------------------------------------------------ */
	/* View: Lesson player                                                 */
	/* ------------------------------------------------------------------ */

	function renderLesson() {
		var seq = navSeq;
		var key = '/lesson/' + state.lessonId;
		var hit = apiCache[key];
		// Stale-while-revalidate for browser Back: paint the cached lesson
		// instantly, then refresh (the GET also re-marks "started" — that's
		// idempotent server-side).
		if (hit) { paintLesson(hit); } else { mount(loadingShell('article')); }
		ensureProfile().then(function () {
			return api(key);
		}).then(function (L) {
			// Ignore a slow lesson response once the learner has moved on.
			if (seq !== navSeq) { if (L && L.ok) { apiCache[key] = L; } return; }
			if (!L.ok) { mount(errorBox(renderLesson)); return; }
			var same = hit && JSON.stringify(hit) === JSON.stringify(L);
			apiCache[key] = L;
			if (!same) { paintLesson(L); }
		}).catch(function (e) {
			if (seq !== navSeq) { return; }
			if (e && e.status === 401) { mount(loginGate()); return; }
			if (!hit) { mount(errorBox(renderLesson, e)); }
		});

		function paintLesson(L) {
			setTitle(L.title);
			if (L.stats) {
				if (!state.me && D.loggedIn) { state.me = { stats: L.stats, user: D.user || null }; }
				else { refreshHud(L.stats); }
			}
			var wrap = h('div', { class: 'mahan-lesson' });

			// Top: back link + "Unit · Lesson X of Y" + course progress bar,
			// so the learner always knows where they are in the course.
			var pos = L.position || {};
			wrap.appendChild(h('div', { class: 'mahan-lesson-top' }, [
				h('div', { class: 'mahan-lesson-topline' }, [
					h('a', { class: 'mahan-back', href: urlFor('course', { course: L.course_id }),
						onClick: function (e) { e.preventDefault(); go('course', { course: L.course_id }); },
						text: '← ' + (L.course_title || t('backToCourse', 'Back to course')) }),
					(pos.total > 0 && pos.index > 0) ? h('span', { class: 'mahan-lesson-pos' }, [
						pos.unit ? h('span', { class: 'mahan-lesson-pos-unit', text: pos.unit + ' · ' }) : null,
						h('span', { text: t('lesson', 'Lesson') + ' ' + pos.index + ' ' + t('of', 'of') + ' ' + pos.total }),
						(L.est_min > 0) ? h('span', { text: ' · ~' + L.est_min + ' ' + t('minShort', 'min') }) : null
					]) : null
				]),
				(L.enrolled && pos.total > 0) ? h('div', { class: 'mahan-lesson-course-bar', title: (L.course_pct || 0) + '%' }, [
					h('span', { style: 'width:' + (L.course_pct || 0) + '%' })
				]) : null
			]));

			var layout = h('div', { class: 'mahan-lesson-layout' });

			// Main column.
			var col = h('div', { class: 'mahan-lesson-col' });
			col.appendChild(h('h1', { class: 'mahan-lesson-title', text: L.title }));
			col.appendChild(h('article', { class: 'mahan-prose', html: L.content || '' }));

			// Exercises.
			if (L.exercises && L.exercises.length) {
				var exWrap = h('section', { class: 'mahan-exercises' }, [h('h2', { text: t('practice', 'Practice') })]);
				L.exercises.forEach(function (ex) { exWrap.appendChild(exerciseCard(ex, L)); });
				col.appendChild(exWrap);
			}

			// Footer nav.
			col.appendChild(lessonFooter(L));
			layout.appendChild(col);

			// Tutor side panel (desktop) / bottom sheet (mobile).
			layout.appendChild(tutorPanel(L));

			wrap.appendChild(layout);
			// Mobile-only affordances: scrim behind the sheet + a FAB to open it.
			// Only when the tutor is actually usable — no dead sheet otherwise.
			if (D.aiReady && L.tutor_ready) {
				wrap.appendChild(h('div', { class: 'mahan-tutor-scrim', onClick: function () { toggleTutor(false); } }));
				wrap.appendChild(h('button', {
					class: 'mahan-tutor-fab', type: 'button',
					'aria-label': t('askTutor', 'Ask the AI tutor'), 'aria-expanded': 'false',
					onClick: function () { toggleTutor(true); }
				}, [h('span', { 'aria-hidden': 'true', text: '🤖' })]));
			}
			// Fresh lesson → fresh tutor panel; clear any lingering busy flag
			// from a previous lesson's stream so this lesson's history loads.
			tutorBusy = false;
			mount(wrap);
			loadChat(L.id);
		}
	}

	// Course-completion celebration: a real moment, not a 2.6s toast.
	function showCourseComplete(courseId, courseTitle, hasCert) {
		var modal;
		var confetti = h('div', { class: 'mahan-confetti', 'aria-hidden': 'true' });
		var colors = ['#f59e0b', '#22c55e', '#4f46e5', '#ec4899', '#06b6d4'];
		for (var i = 0; i < 28; i++) {
			var piece = h('i');
			piece.style.left = (Math.random() * 100) + '%';
			piece.style.animationDelay = (Math.random() * 0.9) + 's';
			piece.style.animationDuration = (2.2 + Math.random() * 1.6) + 's';
			piece.style.background = colors[i % colors.length];
			confetti.appendChild(piece);
		}
		var dialog = h('div', { class: 'mahan-modal mahan-complete-modal' }, [
			confetti,
			h('div', { class: 'mahan-complete-icon', text: '🎓' }),
			h('h2', { text: '🎉 ' + t('courseCompleteTitle', 'Course complete!') }),
			h('p', { class: 'mahan-modal-sub', text: t('courseCompleteMsg', 'You finished') + ' “' + (courseTitle || '') + '”' }),
			h('div', { class: 'mahan-modal-actions mahan-center' }, [
				hasCert ? h('button', { class: 'mahan-btn mahan-btn-ghost mahan-btn-lg', text: '🎓 ' + t('viewCertificate', 'View certificate'),
					onClick: function () {
						modal.close(true);
						showCertificate({ title: courseTitle || '' });
					} }) : null,
				h('button', { class: 'mahan-btn mahan-btn-primary mahan-btn-lg', text: t('keepLearning', 'Keep learning'),
					onClick: function () { modal.close(); } })
			])
		]);
		modal = openModal(dialog, {
			label: t('courseCompleteTitle', 'Course complete!'),
			onClose: function () { go('course', { course: courseId }); }
		});
	}

	// A Prev/Next button that also shows the sibling lesson's title.
	function siblingBtn(L, sib, dir) {
		if (!sib) { return h('span', { class: 'mahan-lesson-nav-spacer' }); }
		var kicker = dir === 'prev' ? '← ' + t('prevLesson', 'Previous') : t('nextLesson', 'Next lesson') + ' →';
		return h('button', { class: 'mahan-btn mahan-btn-ghost mahan-lesson-nav mahan-lesson-nav-' + dir,
			title: sib.title || null,
			onClick: function () { go('lesson', { course: L.course_id, lesson: sib.id }); } }, [
			h('span', { class: 'mahan-lesson-nav-kicker', text: kicker }),
			sib.title ? h('span', { class: 'mahan-lesson-nav-title', text: sib.title }) : null
		]);
	}

	function lessonFooter(L) {
		var foot = h('div', { class: 'mahan-lesson-foot' });
		foot.appendChild(siblingBtn(L, L.siblings && L.siblings.prev, 'prev'));

		var done = L.status === 'completed';
		var main;
		if (!L.enrolled) {
			// Not enrolled: this button navigates back to the course, so name
			// it honestly rather than "Complete lesson".
			main = h('button', { class: 'mahan-btn mahan-btn-primary', text: '← ' + t('backToCourse', 'Back to course'),
				onClick: function () { go('course', { course: L.course_id }); } });
		} else {
			main = h('button', {
				class: 'mahan-btn mahan-btn-primary' + (done ? ' is-done' : ''),
				text: done ? '✓ ' + t('completed', 'Completed') : t('completeLesson', 'Complete lesson'),
				onClick: function (e) {
					var btn = e.currentTarget;
					var restore = setBusy(btn, t('loading', 'Loading…'));
					api('/progress', 'POST', { lesson_id: L.id }).then(function (r) {
						celebrateXp(r);
						restore('✓ ' + esc(t('completed', 'Completed')));
						btn.classList.add('is-done');
						if (r.course_completed) {
							setTimeout(function () { showCourseComplete(L.course_id, L.course_title, r.certificate); }, 600);
						} else if ((r.review_pending || 0) > 0) {
							// "Ask the missed questions at the end": re-drill
							// this lesson's mistakes before moving on.
							toast('🎯 ' + esc(fmt(t('reviewMissedToast', "%s missed — let's fix them before moving on"), r.review_pending || 0)), 'level');
							setTimeout(function () { go('review', { lesson: L.id, course: L.course_id }); }, 1400);
						} else if (L.unit_quiz && !L.unit_quiz.passed) {
							// Last lesson of a unit with a quiz: bring the quiz
							// into the flow instead of silently skipping past it.
							toast('❓ ' + esc(t('unitQuizNext', 'Unit quiz unlocked!')), 'level');
							setTimeout(function () { openQuiz(L.course_id, L.unit_quiz.unit); }, 900);
						} else if (L.siblings && L.siblings.next) {
							setTimeout(function () { go('lesson', { course: L.course_id, lesson: L.siblings.next.id }); }, 700);
						} else {
							setTimeout(function () { go('course', { course: L.course_id }); }, 700);
						}
					}).catch(function () { restore(); toast(t('error', 'Something went wrong.'), 'error'); });
				}
			});
		}
		foot.appendChild(main);

		foot.appendChild(siblingBtn(L, L.siblings && L.siblings.next, 'next'));
		return foot;
	}

	/* ------------------------------------------------------------------ */
	/* Exercises                                                           */
	/* ------------------------------------------------------------------ */

	function exerciseCard(ex, L) {
		var card = h('div', { class: 'mahan-ex' + (ex.solved ? ' is-solved' : '') });
		card.appendChild(h('div', { class: 'mahan-ex-q', html: mdToHtml(ex.question || ex.task || '') }));

		var feedback = h('div', { class: 'mahan-ex-feedback', style: 'display:none', role: 'status' });
		var checkBtn;

		if ((ex.type === 'multiple_choice' || ex.type === 'true_false') && ex.options) {
			var chosen = { i: -1 };
			var opts = h('div', { class: 'mahan-ex-options' + (ex.type === 'true_false' ? ' mahan-ex-tf' : '') }, ex.options.map(function (o, i) {
				return h('button', { class: 'mahan-ex-option', type: 'button', text: o, 'aria-pressed': 'false',
					onClick: function (e) {
						if (card.classList.contains('is-solved')) { return; }
						opts.querySelectorAll('.mahan-ex-option').forEach(function (b) { b.classList.remove('is-chosen'); b.setAttribute('aria-pressed', 'false'); });
						e.currentTarget.classList.add('is-chosen');
						e.currentTarget.setAttribute('aria-pressed', 'true');
						chosen.i = i;
						checkBtn.disabled = false;
					} });
			}));
			card.appendChild(opts);
			checkBtn = h('button', { class: 'mahan-btn mahan-btn-primary mahan-ex-check', text: t('check', 'Check'), disabled: true,
				onClick: function () {
					submitExercise(L.id, ex, chosen.i, card, feedback, checkBtn, opts);
				} });
		} else if (ex.type === 'fill_blank') {
			var inp = h('input', { class: 'mahan-ex-input mahan-ex-blank', type: 'text', placeholder: ex.placeholder || t('typeAnswer', 'Type your answer…'), 'aria-label': ex.placeholder || t('typeAnswer', 'Type your answer…') });
			if (ex.solved) { inp.disabled = true; }
			card.appendChild(inp);
			checkBtn = h('button', { class: 'mahan-btn mahan-btn-primary mahan-ex-check', text: t('check', 'Check'),
				onClick: function () { submitExercise(L.id, ex, inp.value, card, feedback, checkBtn, null); } });
			inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); checkBtn.click(); } });
		} else {
			var ta = h('textarea', { class: 'mahan-ex-input', rows: 4, placeholder: ex.placeholder || t('writeAnswer', 'Write your answer…'), 'aria-label': ex.placeholder || t('writeAnswer', 'Write your answer…') });
			if (ex.solved) { ta.disabled = true; }
			card.appendChild(ta);
			checkBtn = h('button', { class: 'mahan-btn mahan-btn-primary mahan-ex-check', text: t('submitFeedback', 'Submit for feedback'),
				onClick: function () { submitExercise(L.id, ex, ta.value, card, feedback, checkBtn, null); } });
		}

		// Hint expands inline (persistent + re-readable), not a vanishing toast.
		var hintBox = ex.hint ? h('div', { class: 'mahan-ex-hint', style: 'display:none' }, [
			h('span', { 'aria-hidden': 'true', text: '💡 ' }), document.createTextNode(ex.hint)
		]) : null;
		var bar = h('div', { class: 'mahan-ex-bar' }, [
			ex.hint ? h('button', { class: 'mahan-ex-hint-btn', type: 'button', text: '💡 ' + t('hint', 'Hint'), 'aria-expanded': 'false',
				onClick: function (e) {
					var open = hintBox.style.display !== 'none';
					hintBox.style.display = open ? 'none' : 'block';
					e.currentTarget.setAttribute('aria-expanded', open ? 'false' : 'true');
				} }) : h('span', {}),
			checkBtn
		]);
		if (hintBox) { card.appendChild(hintBox); }
		card.appendChild(bar);
		card.appendChild(feedback);

		if (ex.solved) { card.classList.add('is-solved'); }
		return card;
	}

	function submitExercise(lessonId, ex, answer, card, feedback, btn, opts) {
		var isChoice = (ex.type === 'multiple_choice' || ex.type === 'true_false');
		// Explain instead of silently ignoring an empty submit.
		if ((isChoice && (answer === -1 || answer === '')) || (!isChoice && !String(answer).trim())) {
			toast(esc(t('pickAnswer', 'Pick an answer first.')), 'info');
			return;
		}
		var oldLabel = btn.innerHTML;
		var restore = setBusy(btn);
		api('/exercise', 'POST', { lesson_id: lessonId, key: ex.key, answer: answer }).then(function (r) {
			restore(oldLabel);
			feedback.style.display = 'block';
			feedback.className = 'mahan-ex-feedback ' + (r.is_correct ? 'is-correct' : 'is-incorrect');
			var head = r.is_correct ? '✓ ' + tv('correctVariants', 'correct', 'Correct!') : '✕ ' + tv('incorrectVariants', 'incorrect', 'Not quite');
			feedback.innerHTML = '<strong>' + head + '</strong>' + (r.feedback ? '<div>' + mdToHtml(r.feedback) + '</div>' : '');
			if (opts && (ex.type === 'multiple_choice' || ex.type === 'true_false') && typeof r.correct_index === 'number') {
				var btns = opts.querySelectorAll('.mahan-ex-option');
				if (btns[r.correct_index]) { btns[r.correct_index].classList.add('is-correct'); }
			}
			if (r.is_correct) {
				card.classList.add('is-solved');
				if (opts) { opts.querySelectorAll('.mahan-ex-option').forEach(function (b) { b.disabled = true; }); }
				else { var ta = card.querySelector('.mahan-ex-input'); if (ta) { ta.disabled = true; } }
				btn.disabled = true;
				celebrateXp(r);
			} else {
				btn.disabled = false;
			}
		}).catch(function () {
			restore(oldLabel);
			toast(t('error', 'Something went wrong.'), 'error');
		});
	}

	/* ------------------------------------------------------------------ */
	/* AI Tutor (streaming)                                                */
	/* ------------------------------------------------------------------ */

	var tutorBusy = false;

	// On narrow screens the tutor lives in a bottom sheet opened by a FAB.
	function toggleTutor(open) {
		var panel = document.querySelector('.mahan-tutor');
		var fab = document.querySelector('.mahan-tutor-fab');
		var scrim = document.querySelector('.mahan-tutor-scrim');
		if (!panel) { return; }
		var isOpen = typeof open === 'boolean' ? open : !panel.classList.contains('is-open');
		panel.classList.toggle('is-open', isOpen);
		if (scrim) { scrim.classList.toggle('is-open', isOpen); }
		if (fab) { fab.setAttribute('aria-expanded', isOpen ? 'true' : 'false'); fab.classList.toggle('is-hidden', isOpen); }
		if (isOpen) {
			var log = panel.querySelector('.mahan-tutor-log');
			if (log) { log.scrollTop = log.scrollHeight; }
			var inp = panel.querySelector('.mahan-tutor-input');
			if (inp) { inp.focus(); }
		} else if (fab) {
			fab.focus();
		}
	}

	function tutorPanel(L) {
		var panel = h('aside', { class: 'mahan-tutor', 'aria-label': t('tutorTitle', 'AI Tutor') });
		var ready = D.aiReady && L.tutor_ready;
		panel.appendChild(h('div', { class: 'mahan-tutor-head' }, [
			h('span', { class: 'mahan-tutor-avatar', text: '🤖' }),
			h('div', {}, [h('strong', { text: t('tutorTitle', 'AI Tutor') }), h('span', { class: 'mahan-tutor-status' + (ready ? '' : ' is-off'), text: ready ? t('tutorReady', 'Online') : t('tutorUnavailable', 'Unavailable') })]),
			h('button', { class: 'mahan-tutor-close', type: 'button', 'aria-label': t('close', 'Close'), text: '✕',
				onClick: function () { toggleTutor(false); } })
		]));
		panel.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && panel.classList.contains('is-open')) { toggleTutor(false); }
		});
		var log = h('div', { class: 'mahan-tutor-log', id: 'mahan-tutor-log', 'aria-label': t('tutorConversation', 'Tutor conversation') });
		if (!D.aiReady || !L.tutor_ready) {
			log.appendChild(tutorBubble('assistant', t('tutorOffline', 'The AI tutor is not configured yet.')));
		} else {
			log.appendChild(tutorBubble('assistant', t('tutorIntro', 'Hi! Ask me anything about this lesson.')));
		}
		panel.appendChild(log);
		// Streamed replies rewrite the bubble per token; announce the finished
		// reply exactly once here instead of re-reading partial content.
		panel.appendChild(h('div', { class: 'mahan-sr-only', id: 'mahan-tutor-live', role: 'status', 'aria-live': 'polite' }));
		var input = h('textarea', { class: 'mahan-tutor-input', rows: 1,
			placeholder: ready ? t('tutorPlaceholder', 'Ask the tutor…') : t('tutorOffline', 'The AI tutor is not configured yet.') });
		if (!ready) { input.disabled = true; }
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendToTutor(L.id, input); }
		});
		// Auto-grow with the message (up to the CSS max-height).
		input.addEventListener('input', function () {
			input.style.height = 'auto';
			input.style.height = Math.min(input.scrollHeight, 120) + 'px';
		});
		var send = h('button', { class: 'mahan-tutor-send', text: '➤', 'aria-label': t('send', 'Send'), title: t('send', 'Send'), onClick: function () { sendToTutor(L.id, input); } });
		if (!ready) { send.disabled = true; }
		panel.appendChild(h('div', { class: 'mahan-tutor-bar' }, [input, send]));
		panel.appendChild(h('div', { class: 'mahan-tutor-hint', text: t('enterToSend', 'Enter to send · Shift+Enter for a new line') }));
		return panel;
	}

	function tutorBubble(role, text) {
		return h('div', { class: 'mahan-bubble mahan-bubble-' + role, html: role === 'assistant' ? mdToHtml(text) : esc(text) });
	}

	function loadChat(lessonId) {
		if (!D.loggedIn) { return; }
		api('/chat?lesson_id=' + lessonId).then(function (j) {
			if (!j.messages || !j.messages.length) { return; }
			var log = el('mahan-tutor-log');
			if (!log) { return; }
			// Don't clobber a conversation the learner already started while
			// this history request was in flight (they'd lose their question
			// and the streaming reply).
			if (tutorBusy || log.querySelector('.mahan-bubble-user')) { return; }
			log.innerHTML = '';
			j.messages.forEach(function (m) { log.appendChild(tutorBubble(m.role === 'assistant' ? 'assistant' : 'user', m.content)); });
			log.scrollTop = log.scrollHeight;
		}).catch(function () {});
	}

	function sendToTutor(lessonId, input) {
		var msg = (input.value || '').trim();
		if (!msg || tutorBusy) { return; }
		if (!D.aiReady) { toast(t('tutorOffline', 'The AI tutor is not configured yet.'), 'error'); return; }
		input.value = '';
		input.style.height = '';
		var log = el('mahan-tutor-log');
		log.appendChild(tutorBubble('user', msg));
		var bubble = tutorBubble('assistant', '');
		bubble.classList.add('is-streaming');
		bubble.innerHTML = '<span class="mahan-typing"><i></i><i></i><i></i></span>';
		log.appendChild(bubble);
		log.scrollTop = log.scrollHeight;
		tutorBusy = true;

		// Reflect the busy state on the send button so it's clearly disabled
		// (not silently swallowing clicks) while a reply streams.
		var sendBtn = input.parentNode ? input.parentNode.querySelector('.mahan-tutor-send') : null;
		if (sendBtn) { sendBtn.classList.add('is-busy'); sendBtn.disabled = true; sendBtn.setAttribute('aria-busy', 'true'); }

		// Watchdog: if the stream stalls (no token for 25s), stop waiting so
		// the tutor can't deadlock. Reset on every token, cleared on done.
		var ctrl = window.AbortController ? new AbortController() : null;
		var done = false, watchdog = null;
		function arm() {
			clearTimeout(watchdog);
			watchdog = setTimeout(function () { if (ctrl) { ctrl.abort(); } finish(t('tutorTimeout', 'The tutor took too long to respond. Please try again.')); }, 25000);
		}
		function finish(err) {
			if (done) { return; }
			done = true;
			clearTimeout(watchdog);
			tutorBusy = false;
			if (sendBtn) { sendBtn.classList.remove('is-busy'); sendBtn.disabled = false; sendBtn.removeAttribute('aria-busy'); }
			bubble.classList.remove('is-streaming');
			if (err || !bubble._raw) {
				bubble.innerHTML = '<em>' + esc(err || t('error', 'Something went wrong.')) + '</em>';
				// Don't swallow the learner's message — put it back so they
				// can retry without retyping.
				if (!input.value) { input.value = msg; }
			}
			var live = el('mahan-tutor-live');
			if (live) { live.textContent = err ? err : (bubble._raw || t('error', 'Something went wrong.')); }
			log.scrollTop = log.scrollHeight;
		}

		arm();
		streamTutor(lessonId, msg, function (token) {
			if (done) { return; }
			arm();
			if (bubble.classList.contains('is-streaming') && bubble.querySelector('.mahan-typing')) { bubble.innerHTML = ''; }
			bubble._raw = (bubble._raw || '') + token;
			bubble.innerHTML = mdToHtml(bubble._raw);
			log.scrollTop = log.scrollHeight;
		}, finish, ctrl && ctrl.signal);
	}

	function streamTutor(lessonId, message, onToken, onDone, signal) {
		var url = D.ajaxUrl + '?action=' + encodeURIComponent(D.streamAction) + '&_wpnonce=' + encodeURIComponent(D.streamNonce);
		var opts = {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ message: message, lesson_id: lessonId })
		};
		if (signal) { opts.signal = signal; }
		fetch(url, opts).then(function (resp) {
			if (!resp.ok || !resp.body) { return tutorFallback(lessonId, message, onToken, onDone); }
			var reader = resp.body.getReader();
			var decoder = new TextDecoder();
			var buf = '';
			var gotError = null;
			function pump() {
				return reader.read().then(function (res) {
					if (res.done) { onDone(gotError); return; }
					buf += decoder.decode(res.value, { stream: true });
					var idx;
					while ((idx = buf.indexOf('\n\n')) >= 0) {
						var chunk = buf.slice(0, idx); buf = buf.slice(idx + 2);
						var dataLine = chunk.split('\n').filter(function (l) { return l.indexOf('data:') === 0; })[0];
						if (!dataLine) { continue; }
						var payload = dataLine.slice(5).trim();
						if (!payload) { continue; }
						try {
							var obj = JSON.parse(payload);
							if (obj.t) { onToken(obj.t); }
							if (obj.error) { gotError = obj.error; }
							if (obj.done) { onDone(gotError); return; }
						} catch (e) { /* ignore */ }
					}
					return pump();
				});
			}
			return pump();
		}).catch(function (e) {
			// A deliberate watchdog abort already resolved the UI — don't
			// fall back or double-report.
			if (e && e.name === 'AbortError') { return; }
			tutorFallback(lessonId, message, onToken, onDone);
		});
	}

	// REST fallback when SSE streaming isn't available.
	function tutorFallback(lessonId, message, onToken, onDone) {
		api('/tutor', 'POST', { lesson_id: lessonId, message: message }).then(function (j) {
			if (j.reply) { onToken(j.reply); }
			onDone(null);
		}).catch(function (e) {
			onDone((e && e.payload && e.payload.error) || t('error', 'Something went wrong.'));
		});
	}

	/* ------------------------------------------------------------------ */
	/* View: Dashboard                                                     */
	/* ------------------------------------------------------------------ */

	// Duolingo-style last-7-days activity strip (from the server's XP log).
	function weekDots(week) {
		if (!week || !week.length) { return null; }
		return h('div', { class: 'mahan-week' }, [
			h('span', { class: 'mahan-week-label', text: t('thisWeekActivity', 'This week') }),
			h('div', { class: 'mahan-week-days' }, week.map(function (d) {
				var cls = 'mahan-week-day' + (d.goal_met ? ' is-goal' : (d.active ? ' is-active' : '')) + (d.today ? ' is-today' : '');
				var st = d.goal_met ? t('weekGoalMet', 'goal met') : (d.active ? t('weekStudied', 'studied') : t('weekNoActivity', 'no activity'));
				var aria = d.label + ': ' + st + (d.today ? ', ' + t('today', 'today') : '');
				return h('div', { class: cls, role: 'img', 'aria-label': aria }, [
					h('span', { class: 'mahan-week-dot', 'aria-hidden': 'true', text: d.goal_met ? '✓' : (d.active ? '·' : '') }),
					h('span', { class: 'mahan-week-name', 'aria-hidden': 'true', text: d.label })
				]);
			}))
		]);
	}

	function renderDashboard() {
		if (!D.loggedIn) { mount(loginGate()); return; }
		setTitle(t('dashboard', 'My Learning'));
		cachedApi('/me', 'dash', function (j) {
			state.me = j;
			var s = j.stats || {};
			// The flame goes "cold" when today hasn't counted yet — a gentle
			// nudge that the streak is at risk until the learner earns XP.
			var todayCounted = !!(s.week && s.week.length && s.week[s.week.length - 1].active);
			var streakCold = (s.streak || 0) > 0 && !todayCounted;
			var streakSub = streakCold
				? t('streakAtRisk', 'Study today to keep it!')
				: (((s.longest_streak || 0) > (s.streak || 0))
					? t('longestStreak', 'Longest') + ': ' + s.longest_streak
					: t('streak', 'day streak'));
			var wrap = h('div', { class: 'mahan-dash' });
			wrap.appendChild(h('h1', { class: 'mahan-dash-title', text: (t('dashboard', 'My Learning')) }));
			var hero = h('div', { class: 'mahan-dash-hero' }, [
				h('div', { class: 'mahan-dash-stats' }, [
					statBig(streakCold ? '🕯️' : '🔥', s.streak || 0, streakSub, streakCold ? 'is-cold' : ''),
					statBig('⚡', s.xp || 0, 'XP'),
					statBig('◆', s.level || 1, s.level_title || t('level', 'Level')),
					(s.freezes || 0) > 0 ? statBig('❄️', s.freezes, t('freezes', 'streak freezes')) : null
				]),
				weekDots(s.week),
				h('div', { class: 'mahan-level-bar' }, [
					h('div', { class: 'mahan-level-bar-track' }, [h('span', { style: 'width:' + levelPct(s) + '%' })]),
					h('span', { class: 'mahan-level-bar-label', text: (s.xp_into_level || 0) + ' / ' + (s.xp_per_level || 100) + ' XP → ' + t('level', 'Level') + ' ' + ((s.level || 1) + 1) })
				]),
				dailyGoalCard(s)
			]);

			var courses = j.courses || [];
			var rv = s.reviews || {};
			var hasReview = (rv.due || 0) > 0;

			// Adaptive review first — it's the time-sensitive task (due items
			// fade from memory unless refreshed on schedule).
			if (hasReview) {
				wrap.appendChild(h('div', { class: 'mahan-review-cta' }, [
					h('div', { class: 'mahan-review-cta-body' }, [
						h('span', { class: 'mahan-review-cta-icon', text: '🎯' }),
						h('div', {}, [
							h('h2', { class: 'mahan-review-cta-title', text: t('reviewTitle', 'Practice your mistakes') }),
							h('p', { class: 'mahan-review-cta-sub', text: fmt(t('reviewFading', '%s fading — a 2-minute review locks them in'), rv.due) + (rv.mastered ? ' · ' + rv.mastered + ' ' + t('reviewMastered', 'mastered') : '') })
						])
					]),
					h('button', { class: 'mahan-btn mahan-btn-primary mahan-btn-lg', text: t('reviewNow', 'Review now'),
						onClick: function () { go('review'); } })
				]));
			}

			// "Jump back in": one tap straight into the next lesson of the most
			// recent in-progress course (no course-page detour). Demoted to a
			// ghost button when the review CTA is present so the page keeps a
			// single primary action.
			var inProgress = courses.filter(function (c) { return c.next_lesson_id && (c.progress_pct || 0) < 100; });
			if (inProgress.length) {
				var jb = inProgress[0];
				wrap.appendChild(h('div', { class: 'mahan-jump' }, [
					h('div', { class: 'mahan-jump-body' }, [
						h('span', { class: 'mahan-jump-kicker', text: '▶ ' + t('jumpBackIn', 'Jump back in') }),
						h('h2', { class: 'mahan-jump-title', text: jb.title }),
						jb.next_lesson_title ? h('p', { class: 'mahan-jump-next', text: t('nextUp', 'Next up:') + ' ' + jb.next_lesson_title }) : null,
						h('div', { class: 'mahan-progress' }, [
							h('div', { class: 'mahan-progress-bar' }, [h('span', { style: 'width:' + (jb.progress_pct || 0) + '%' })]),
							h('span', { class: 'mahan-progress-label', text: (jb.progress_pct || 0) + '%' })
						])
					]),
					h('button', { class: 'mahan-btn mahan-btn-lg ' + (hasReview ? 'mahan-btn-ghost' : 'mahan-btn-primary'), text: t('continueLearning', 'Continue learning'),
						onClick: function () { go('lesson', { course: jb.id, lesson: jb.next_lesson_id }); } })
				]));
			}

			// Stats after the actions: what to DO comes before how it's going.
			wrap.appendChild(hero);

			if (!courses.length) {
				wrap.appendChild(h('div', { class: 'mahan-empty' }, [
					h('div', { class: 'mahan-empty-icon', 'aria-hidden': 'true', text: '📚' }),
					h('p', { text: t('emptyDashboard', 'Pick your first course and start earning XP today.') }),
					h('button', { class: 'mahan-btn mahan-btn-primary', text: t('browseCourses', 'Browse courses'), onClick: function () { go('catalog'); } })
				]));
			} else {
				wrap.appendChild(h('h2', { class: 'mahan-dash-h2', text: t('continue', 'Continue') }));
				var grid = h('div', { class: 'mahan-grid' });
				courses.forEach(function (c) { c.enrolled = true; grid.appendChild(courseCard(c, 'dashboard')); });
				wrap.appendChild(grid);
			}

			// Achievements.
			if (j.badges && j.badges.length) {
				wrap.appendChild(h('h2', { class: 'mahan-dash-h2', text: t('achievements', 'Achievements') }));
				var earned = j.badges.filter(function (b) { return b.earned; }).length;
				wrap.appendChild(h('p', { class: 'mahan-badges-sub', text: earned + ' / ' + j.badges.length }));
				var bgrid = h('div', { class: 'mahan-badges' });
				j.badges.forEach(function (b) {
					var showProgress = !b.earned && (b.need || 0) > 1 && (b.progress || 0) > 0;
					bgrid.appendChild(h('div', { class: 'mahan-badge' + (b.earned ? ' is-earned' : ' is-locked'), title: b.desc }, [
						h('span', { class: 'mahan-sr-only', text: b.earned ? t('badgeEarnedState', 'Earned:') : fmt(t('badgeLockedState', 'Locked, %s of %s:'), b.progress || 0, b.need || 1) }),
						h('span', { class: 'mahan-badge-icon', 'aria-hidden': 'true', text: b.icon }),
						h('span', { class: 'mahan-badge-title', text: b.title }),
						h('span', { class: 'mahan-badge-desc', text: b.desc }),
						showProgress ? h('div', { class: 'mahan-badge-progress' }, [
							h('div', { class: 'mahan-badge-progress-bar' }, [
								h('span', { style: 'width:' + Math.round((b.progress / b.need) * 100) + '%' })
							]),
							h('span', { class: 'mahan-badge-progress-label', text: b.progress + '/' + b.need })
						]) : null
					]));
				});
				wrap.appendChild(bgrid);
			}

			mount(wrap);
		}, renderDashboard);
	}

	/* ------------------------------------------------------------------ */
	/* View: Leaderboard                                                   */
	/* ------------------------------------------------------------------ */

	function renderLeaderboard() {
		var period = state.lbPeriod || 'week';
		setTitle(t('leaderboard', 'Leaderboard'));
		cachedApi('/leaderboard?period=' + period, 'rows', function (j) {
			var wrap = h('div', { class: 'mahan-leaderboard' });
			wrap.appendChild(h('div', { class: 'mahan-dash-hero' }, [h('h1', { text: '🏆 ' + t('leaderboard', 'Leaderboard') })]));

			// Period tabs.
			wrap.appendChild(h('div', { class: 'mahan-chips' }, [
				['week', t('thisWeek', 'This week')],
				['all', t('allTime', 'All time')]
			].map(function (p) {
				return h('button', {
					class: 'mahan-chip' + (period === p[0] ? ' is-active' : ''), text: p[1],
					'aria-pressed': period === p[0] ? 'true' : 'false',
					onClick: function () { state.lbPeriod = p[0]; syncUrl(); renderLeaderboard(); }
				});
			})));

			var entries = j.entries || [];
			if (!entries.length) {
				wrap.appendChild(h('div', { class: 'mahan-empty' }, [
					h('div', { class: 'mahan-empty-icon', 'aria-hidden': 'true', text: '🏆' }),
					h('p', { text: t('emptyLeaderboard', 'No ranked learners yet — earn some XP!') }),
					h('button', { class: 'mahan-btn mahan-btn-primary', text: t('browseCourses', 'Browse courses'), onClick: function () { go('catalog'); } })
				]));
			} else {
				var list = h('div', { class: 'mahan-lb-list' });
				function lbRow(e, gapToNext) {
					// "X XP to rank #N" — a concrete target to chase.
					var note = (gapToNext && gapToNext.xp > 0)
						? h('span', { class: 'mahan-lb-gap-note', text: '+' + gapToNext.xp + ' XP → #' + gapToNext.rank })
						: null;
					return h('div', { class: 'mahan-lb-row' + (e.is_me ? ' is-me' : '') + (e.rank <= 3 ? ' is-top' : ''), id: e.is_me ? 'mahan-lb-me' : null }, [
						h('span', { class: 'mahan-lb-rank', text: e.rank <= 3 ? ['🥇', '🥈', '🥉'][e.rank - 1] : String(e.rank) }),
						h('img', { class: 'mahan-lb-avatar', src: e.avatar, alt: e.name }),
						h('span', { class: 'mahan-lb-name' }, [
							document.createTextNode(e.name + (e.is_me ? ' (' + t('you', 'You') + ')' : '')),
							note
						]),
						h('span', { class: 'mahan-lb-streak', text: '🔥 ' + e.streak }),
						h('span', { class: 'mahan-lb-level', text: '◆ ' + e.level }),
						h('span', { class: 'mahan-lb-xp', text: '⚡ ' + e.xp })
					]);
				}
				entries.forEach(function (e, i) {
					// For the caller inside the top list, show the gap to the
					// person directly above them.
					var gap = (e.is_me && i > 0) ? { xp: (entries[i - 1].xp - e.xp), rank: entries[i - 1].rank } : null;
					list.appendChild(lbRow(e, gap));
				});
				// The caller's own rank when they're outside the top list —
				// gap to break into the visible board.
				if (j.me) {
					list.appendChild(h('div', { class: 'mahan-lb-gap', text: '···' }));
					var last = entries[entries.length - 1];
					var meGap = (last && last.xp > j.me.xp) ? { xp: (last.xp - j.me.xp), rank: last.rank } : null;
					list.appendChild(lbRow(j.me, meGap));
				}
				wrap.appendChild(list);
			}
			mount(wrap);
			// Bring the learner's own row into view only when it's actually
			// off-screen (don't yank the page when it's already visible).
			var meRow = el('mahan-lb-me');
			if (meRow && meRow.getBoundingClientRect && meRow.scrollIntoView) {
				setTimeout(function () {
					var r = meRow.getBoundingClientRect();
					if (r.top < 80 || r.bottom > (window.innerHeight || 0)) {
						meRow.scrollIntoView({ block: 'center' });
					}
				}, 0);
			}
		}, renderLeaderboard);
	}

	/* ------------------------------------------------------------------ */
	/* View: Learning paths                                                */
	/* ------------------------------------------------------------------ */

	function renderPaths() {
		setTitle(t('learningPaths', 'Learning paths'));
		cachedApi('/paths', 'cards', function (j) {
			var wrap = h('div', { class: 'mahan-paths' });
			wrap.appendChild(h('div', { class: 'mahan-hero' }, [
				h('h1', { text: t('learningPaths', 'Learning paths') }),
				h('p', { class: 'mahan-hero-sub', text: t('pathsSub', 'Guided programs — courses in a recommended order.') })
			]));
			var paths = j.paths || [];
			if (!paths.length) {
				wrap.appendChild(h('div', { class: 'mahan-empty' }, [
					h('div', { class: 'mahan-empty-icon', 'aria-hidden': 'true', text: '🗺️' }),
					h('p', { text: t('emptyPaths', 'No learning paths yet.') }),
					h('button', { class: 'mahan-btn mahan-btn-primary', text: t('browseCourses', 'Browse courses'), onClick: function () { go('catalog'); } })
				]));
			} else {
				var grid = h('div', { class: 'mahan-grid' });
				paths.forEach(function (p) { grid.appendChild(pathCard(p)); });
				wrap.appendChild(grid);
			}
			mount(wrap);
		}, renderPaths);
	}

	function pathCard(p) {
		var media = p.image
			? h('div', { class: 'mahan-card-media', style: 'background-image:url(' + esc(p.image) + ')' })
			: h('div', { class: 'mahan-card-media mahan-card-media-ph' }, [h('span', { text: '🧭' })]);
		var foot = (D.loggedIn && p.completed > 0)
			? h('div', { class: 'mahan-progress' }, [
				h('div', { class: 'mahan-progress-bar' }, [h('span', { style: 'width:' + (p.progress_pct || 0) + '%' })]),
				h('span', { class: 'mahan-progress-label', text: p.completed + '/' + p.course_count })])
			: h('div', { class: 'mahan-card-tags' }, [
				h('span', { class: 'mahan-tag', text: p.course_count + ' ' + t('courses', 'courses') })]);
		return h('a', {
			class: 'mahan-card', href: urlFor('path', { path: p.id }),
			onClick: function (e) { e.preventDefault(); go('path', { path: p.id }); }
		}, [
			media,
			h('div', { class: 'mahan-card-body' }, [
				h('span', { class: 'mahan-card-cat', text: t('paths', 'Paths') }),
				h('h3', { class: 'mahan-card-title', text: p.title }),
				h('p', { class: 'mahan-card-sub', text: p.subtitle || p.excerpt || '' }),
				foot
			])
		]);
	}

	function renderPath() {
		cachedApi('/path/' + state.pathId, 'detail', function (j) {
			if (!j.ok) { mount(errorBox(renderPath)); return; }
			var p = j.path;
			setTitle(p.title);
			var wrap = h('div', { class: 'mahan-path' });
			wrap.appendChild(h('div', { class: 'mahan-course-hero' }, [
				h('div', { class: 'mahan-course-hero-text' }, [
					h('a', { class: 'mahan-back', href: urlFor('paths'), onClick: function (e) { e.preventDefault(); go('paths'); }, text: '← ' + t('paths', 'Paths') }),
					h('h1', { text: p.title }),
					p.subtitle ? h('p', { class: 'mahan-course-sub', text: p.subtitle }) : null,
					h('div', { class: 'mahan-course-meta' }, [
						h('span', { class: 'mahan-tag', text: p.course_count + ' ' + t('courses', 'courses') }),
						(D.loggedIn) ? h('span', { class: 'mahan-tag mahan-tag-soft', text: p.completed + '/' + p.course_count + ' ' + t('pathComplete', 'completed') }) : null
					]),
					(D.loggedIn && p.course_count) ? h('div', { class: 'mahan-progress', style: 'max-width:320px' }, [
						h('div', { class: 'mahan-progress-bar' }, [h('span', { style: 'width:' + p.progress_pct + '%' })]),
						h('span', { class: 'mahan-progress-label', text: p.progress_pct + '%' })
					]) : null
				]),
				p.image ? h('div', { class: 'mahan-course-hero-media', style: 'background-image:url(' + esc(p.image) + ')' }) : null
			]));

			if (p.description && p.description.trim()) {
				wrap.appendChild(h('section', { class: 'mahan-section mahan-prose', html: p.description }));
			}

			var sec = h('section', { class: 'mahan-section' }, [h('h2', { text: t('coursesInPath', 'Courses in this path') })]);
			var list = h('ol', { class: 'mahan-path-courses' });
			var fromPath = 'path:' + p.id;
			(p.courses || []).forEach(function (c, i) {
				var nav = { course: c.id, from: fromPath };
				list.appendChild(h('li', { class: 'mahan-path-course' + (c.completed ? ' is-done' : '') }, [
					h('span', { class: 'mahan-path-step', text: c.completed ? '✓' : String(i + 1) }),
					h('div', { class: 'mahan-path-course-body' }, [
						h('a', { class: 'mahan-path-course-title', href: urlFor('course', nav),
							onClick: function (e) { e.preventDefault(); go('course', nav); }, text: c.title }),
						c.subtitle ? h('span', { class: 'mahan-path-course-sub', text: c.subtitle }) : null,
						c.enrolled ? h('div', { class: 'mahan-progress' }, [
							h('div', { class: 'mahan-progress-bar' }, [h('span', { style: 'width:' + c.progress_pct + '%' })]),
							h('span', { class: 'mahan-progress-label', text: c.progress_pct + '%' })
						]) : null
					]),
					h('a', { class: 'mahan-btn mahan-btn-sm mahan-btn-ghost', href: urlFor('course', nav),
						onClick: function (e) { e.preventDefault(); go('course', nav); }, text: t('openCourse', 'Open') })
				]));
			});
			sec.appendChild(list);
			wrap.appendChild(sec);
			mount(wrap);
		}, renderPath);
	}

	/* ------------------------------------------------------------------ */
	/* Certificate (printable)                                             */
	/* ------------------------------------------------------------------ */

	function showCertificate(course) {
		var name = (state.me && state.me.user && state.me.user.display_name) || (D.user && D.user.name) || '';
		var date = new Date().toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
		var cert = h('div', { class: 'mahan-cert' }, [
			h('div', { class: 'mahan-cert-inner' }, [
				h('div', { class: 'mahan-cert-badge', text: '🎓' }),
				h('div', { class: 'mahan-cert-brand', text: D.siteName || 'Mahan Academy' }),
				h('div', { class: 'mahan-cert-line', text: t('certAwarded', 'This certifies that') }),
				h('div', { class: 'mahan-cert-name', text: name }),
				h('div', { class: 'mahan-cert-line', text: t('certCompleted', 'has successfully completed') }),
				h('div', { class: 'mahan-cert-course', text: course.title }),
				h('div', { class: 'mahan-cert-date', text: date })
			])
		]);
		var modal;
		var dialog = h('div', { class: 'mahan-modal mahan-cert-modal' }, [
			cert,
			h('div', { class: 'mahan-modal-actions' }, [
				h('button', { class: 'mahan-btn mahan-btn-ghost', text: t('close', 'Close'), onClick: function () { modal.close(); } }),
				h('button', { class: 'mahan-btn mahan-btn-primary', text: t('print', 'Print / Save as PDF'), onClick: function () { window.print(); } })
			])
		]);
		modal = openModal(dialog, { label: t('certificate', 'Certificate of completion') });
	}

	function statBig(icon, value, label, extraClass) {
		return h('div', { class: 'mahan-stat-big' + (extraClass ? ' ' + extraClass : '') }, [
			h('span', { class: 'mahan-stat-icon', text: icon }),
			h('span', { class: 'mahan-stat-value', text: String(value) }),
			h('span', { class: 'mahan-stat-label', text: label })
		]);
	}

	// Duolingo-style daily goal: today's XP vs. a learner-chosen target.
	function dailyGoalCard(s) {
		var goal = s.daily_goal || 0;
		var xpToday = s.daily_xp || 0;
		var pct = goal > 0 ? Math.max(0, Math.min(100, Math.round((xpToday / goal) * 100))) : 0;
		var done = goal > 0 && xpToday >= goal;

		var select = h('select', { class: 'mahan-goal-select', 'aria-label': t('dailyGoal', 'Daily goal') });
		if (!goal) {
			// No goal set yet — say so instead of pretending it's 10 XP.
			var ph = h('option', { value: '', text: '—' });
			ph.selected = true;
			select.appendChild(ph);
		}
		[10, 20, 30, 50, 100].forEach(function (v) {
			var opt = h('option', { value: String(v), text: v + ' XP' });
			if (v === goal) { opt.selected = true; }
			select.appendChild(opt);
		});
		if ([10, 20, 30, 50, 100].indexOf(goal) < 0 && goal > 0) {
			var custom = h('option', { value: String(goal), text: goal + ' XP' });
			custom.selected = true;
			select.appendChild(custom);
		}
		select.addEventListener('change', function () {
			var v = parseInt(select.value, 10);
			if (!v) { return; }
			// Optimistic: the client already knows the new goal, so swap the
			// card and celebrate immediately, then reconcile with the server.
			// On failure, roll back to the previous card.
			var prevStats = s;
			var optimistic = Object.assign({}, s, { daily_goal: v });
			var fresh = dailyGoalCard(optimistic);
			if (card.parentNode) { card.parentNode.replaceChild(fresh, card); }
			toast('🎯 ' + esc(t('goalSaved', 'Daily goal updated')), 'info');
			api('/goal', 'POST', { daily_goal: v }).then(function (r) {
				if (state.me) { state.me.stats = r.stats; }
				// Rebuild the top bar (not just refreshHud) so a first-ever
				// goal creates its 🎯 HUD chip, which refreshHud can't add.
				refreshTopbar();
				// Reconcile the optimistic card with the server's authoritative
				// stats (in case daily_xp / level shifted).
				var reconciled = dailyGoalCard(r.stats);
				if (fresh.parentNode) { fresh.parentNode.replaceChild(reconciled, fresh); }
			}).catch(function () {
				var rollback = dailyGoalCard(prevStats);
				if (fresh.parentNode) { fresh.parentNode.replaceChild(rollback, fresh); }
				toast(t('error', 'Something went wrong.'), 'error');
			});
		});

		var card = h('div', { class: 'mahan-goal-card' + (done ? ' is-done' : '') }, [
			h('div', { class: 'mahan-goal-head' }, [
				h('span', { class: 'mahan-goal-title', text: (done ? '✅ ' : '🎯 ') + t('dailyGoal', 'Daily goal') }),
				h('label', { class: 'mahan-goal-pick' }, [
					h('span', { text: t('goalLabel', 'Goal:') + ' ' }),
					select
				])
			]),
			goal > 0 ? h('div', { class: 'mahan-progress' }, [
				h('div', { class: 'mahan-progress-bar' }, [h('span', { style: 'width:' + pct + '%' })]),
				h('span', { class: 'mahan-progress-label', text: xpToday + ' / ' + goal + ' XP' })
			]) : h('p', { class: 'mahan-goal-pick-msg', text: t('pickGoal', 'Set a daily XP goal to build a habit.') }),
			done ? h('p', { class: 'mahan-goal-done-msg', text: t('goalDone', 'Goal reached — nice work! Everything extra is a bonus.') }) : null
		]);
		return card;
	}

	function levelPct(s) {
		var per = s.xp_per_level || 100;
		return Math.max(0, Math.min(100, Math.round(((s.xp_into_level || 0) / per) * 100)));
	}

	/* ------------------------------------------------------------------ */
	/* Profile gate                                                        */
	/* ------------------------------------------------------------------ */

	function ensureProfile() {
		if (!D.loggedIn || !D.gateEnabled) { return Promise.resolve(); }
		if (state.profile && state.profile.complete) { return Promise.resolve(); }
		// "Not now" means not this session — don't re-nag on every lesson.
		try { if (window.sessionStorage && sessionStorage.getItem('mahanProfileSkip')) { return Promise.resolve(); } } catch (e) { /* private mode */ }
		return api('/profile').then(function (j) {
			state.profile = j;
			if (j.complete) { return; }
			return showProfileGate(j);
		}).catch(function () { /* don't block on error */ });
	}

	function showProfileGate(j) {
		return new Promise(function (resolve) {
			var schema = j.schema || { fields: [] };
			var fields = schema.fields || [];
			var profile = j.profile || {};

			var form = h('form', { class: 'mahan-profile-form' });
			// Enter in a text field must never trigger a native page reload.
			form.addEventListener('submit', function (e) { e.preventDefault(); });
			fields.forEach(function (f) { form.appendChild(profileField(f, profile[f.key])); });
			var msg = h('div', { class: 'mahan-profile-msg' });

			var modal;
			var dialog = h('div', { class: 'mahan-modal' }, [
				h('h2', { text: t('profileTitle', 'Tell us about you') }),
				h('p', { class: 'mahan-modal-sub', text: t('profileIntro', 'This personalizes your lessons and AI tutor.') }),
				form,
				msg,
				h('div', { class: 'mahan-modal-actions' }, [
					h('button', { class: 'mahan-btn mahan-btn-ghost', type: 'button', text: t('notNow', 'Not now'),
						onClick: function () { modal.close(); } }),
					h('button', { class: 'mahan-btn mahan-btn-primary', type: 'button', text: t('save', 'Save & continue'),
						onClick: function (e) {
							var btn = e.currentTarget;
							var out = collectProfile(form, fields);
							// Point at the exact missing fields, not just a generic line.
							form.querySelectorAll('.mahan-field.is-invalid').forEach(function (n) { n.classList.remove('is-invalid'); });
							var missing = fields.filter(function (f) {
								return f.required && (!out[f.key] || (Array.isArray(out[f.key]) && !out[f.key].length));
							});
							if (missing.length) {
								missing.forEach(function (f) {
									var n = form.querySelector('[data-key="' + f.key + '"]');
									var wrapEl = n && n.closest ? n.closest('.mahan-field') : null;
									if (wrapEl) { wrapEl.classList.add('is-invalid'); }
								});
								msg.textContent = t('required', 'Please fill in the required fields.');
								return;
							}
							btn.disabled = true;
							api('/profile', 'POST', { profile: out }).then(function (r) {
								state.profile = { complete: r.complete, profile: r.profile, schema: schema };
								modal.close();
								// Confirm the payoff: their lessons/tutor/exercises now adapt.
								toast('✨ ' + esc(t('profileSaved', 'Your lessons, exercises, and tutor are now personalized to you.')), 'level');
							}).catch(function () { btn.disabled = false; msg.textContent = t('error', 'Something went wrong.'); });
						} })
				])
			]);
			// Dismissing the gate (Escape / outside click) is the same as
			// "Not now": remember for this session so it doesn't re-nag on
			// every lesson (saving marks the profile complete anyway).
			modal = openModal(dialog, { label: t('profileTitle', 'Tell us about you'), onClose: function () {
				if (!(state.profile && state.profile.complete)) {
					try { if (window.sessionStorage) { sessionStorage.setItem('mahanProfileSkip', '1'); } } catch (e) { /* private mode */ }
				}
				resolve();
			} });
		});
	}

	function profileField(f, value) {
		var wrap = h('div', { class: 'mahan-field' });
		var req = f.required ? ' *' : '';
		var fid = 'mahan-pf-' + String(f.key).replace(/[^a-z0-9_-]/gi, '');
		wrap.appendChild(h('label', { text: (f.label || f.key) + req, for: fid }));
		var input;
		if (f.type === 'textarea') {
			input = h('textarea', { rows: 3, 'data-key': f.key, id: fid });
			input.value = value || '';
		} else if (f.type === 'select') {
			input = h('select', { 'data-key': f.key, id: fid });
			input.appendChild(h('option', { value: '', text: '—' }));
			(f.options || []).forEach(function (o) {
				var ov = typeof o === 'string' ? o : o.value;
				var ol = typeof o === 'string' ? o : (o.label || o.value);
				var opt = h('option', { value: ov, text: ol });
				if (String(ov) === String(value)) { opt.selected = true; }
				input.appendChild(opt);
			});
		} else if (f.type === 'multiselect') {
			input = h('div', { class: 'mahan-checks', 'data-key': f.key, role: 'group', 'aria-label': f.label || f.key });
			var vals = Array.isArray(value) ? value.map(String) : [];
			(f.options || []).forEach(function (o) {
				var ov = typeof o === 'string' ? o : o.value;
				var ol = typeof o === 'string' ? o : (o.label || o.value);
				var cb = h('input', { type: 'checkbox', value: ov });
				if (vals.indexOf(String(ov)) >= 0) { cb.checked = true; }
				input.appendChild(h('label', { class: 'mahan-check-item' }, [cb, document.createTextNode(' ' + ol)]));
			});
		} else {
			input = h('input', { type: 'text', 'data-key': f.key, id: fid });
			input.value = value || '';
		}
		wrap.appendChild(input);
		return wrap;
	}

	function collectProfile(form, fields) {
		var out = {};
		fields.forEach(function (f) {
			if (f.type === 'multiselect') {
				var box = form.querySelector('[data-key="' + f.key + '"]');
				out[f.key] = box ? Array.prototype.slice.call(box.querySelectorAll('input:checked')).map(function (c) { return c.value; }) : [];
			} else {
				var node = form.querySelector('[data-key="' + f.key + '"]');
				out[f.key] = node ? node.value : '';
			}
		});
		return out;
	}

	/* ------------------------------------------------------------------ */
	/* Misc views                                                          */
	/* ------------------------------------------------------------------ */

	function loginGate() {
		return h('div', { class: 'mahan-empty mahan-login-gate' }, [
			h('h2', { text: t('loginToLearn', 'Log in to start learning') }),
			h('div', { class: 'mahan-modal-actions mahan-center' }, [
				h('a', { class: 'mahan-btn mahan-btn-primary', href: loginHref(), text: t('login', 'Log in') }),
				D.canRegister ? h('a', { class: 'mahan-btn mahan-btn-ghost', href: D.registerUrl, text: t('register', 'Create account') }) : null
			])
		]);
	}

	function errorBox(retry, err) {
		var status = err && err.status;
		var code = err && err.payload && err.payload.error;
		// A session that expired mid-use → straight to the login gate.
		if (status === 401) {
			return loginGate();
		}
		// Not enrolled → the course page is where you fix that.
		if (status === 403 && code === 'not_enrolled' && state.courseId) {
			return h('div', { class: 'mahan-empty mahan-error-box' }, [
				h('p', { text: t('notEnrolled', 'Enroll in this course to access its lessons.') }),
				h('button', { class: 'mahan-btn mahan-btn-primary', text: t('backToCourse', 'Back to course'), onClick: function () { go('course', { course: state.courseId }); } })
			]);
		}
		// Locked by sequential gating → send them back to the course outline.
		if (status === 403 && code === 'locked' && state.courseId) {
			return h('div', { class: 'mahan-empty mahan-error-box' }, [
				h('p', { text: t('lockedLesson', 'Complete the previous lesson to unlock this one.') }),
				h('button', { class: 'mahan-btn mahan-btn-primary', text: t('backToCourse', 'Back to course'), onClick: function () { go('course', { course: state.courseId }); } })
			]);
		}
		// A missing resource can't be fixed by retrying — offer a way out.
		if (status === 404) {
			return h('div', { class: 'mahan-empty mahan-error-box' }, [
				h('p', { text: t('notFound', 'This content is no longer available.') }),
				h('button', { class: 'mahan-btn mahan-btn-primary', text: t('backToCatalog', 'Back to catalog'), onClick: function () { go('catalog'); } })
			]);
		}
		var offline = (typeof navigator !== 'undefined') && navigator.onLine === false;
		return h('div', { class: 'mahan-empty mahan-error-box' }, [
			h('div', { class: 'mahan-empty-icon', 'aria-hidden': 'true', text: offline ? '📡' : '⚠️' }),
			h('p', { text: offline ? t('offline', 'You appear to be offline. Check your connection and try again.') : t('error', 'Something went wrong.') }),
			offline ? h('p', { class: 'mahan-error-hint', text: '📡' }) : null,
			h('button', { class: 'mahan-btn mahan-btn-ghost', text: '↻ ' + t('retry', 'Try again'), onClick: retry })
		]);
	}

	// When the connection returns, recover automatically instead of leaving
	// the learner staring at an error.
	window.addEventListener('online', function () {
		if (root && root.querySelector('.mahan-error-box')) {
			toast('📡 ' + t('backOnline', 'Back online!'), 'info');
			render();
		}
	});

	/* ------------------------------------------------------------------ */
	/* View: Adaptive review (spaced repetition of missed questions)       */
	/* ------------------------------------------------------------------ */

	// A single review question. `onGrade(answer, variantToken)` is called when
	// the learner checks; the returned object exposes lock()/showResult().
	function reviewCard(item, onSubmit) {
		var chosen = { i: -1, text: '' };
		var card = h('div', { class: 'mahan-ex mahan-review-card' + (item.is_variant ? ' is-variant' : '') });
		if (item.is_variant) {
			card.appendChild(h('span', { class: 'mahan-review-variant-tag', text: '✨ ' + t('askDifferently', 'Ask a different way') }));
		}
		// Where this question came from (course · lesson) — a mixed
		// cross-course queue needs orientation.
		if (item.context) {
			card.appendChild(h('div', { class: 'mahan-review-context', text: item.context }));
		}
		card.appendChild(h('div', { class: 'mahan-ex-q', html: mdToHtml(item.question || '') }));

		var input;
		if ((item.type === 'multiple_choice' || item.type === 'true_false') && item.options) {
			input = h('div', { class: 'mahan-ex-options' + (item.type === 'true_false' ? ' mahan-ex-tf' : '') }, item.options.map(function (o, i) {
				return h('button', { class: 'mahan-ex-option', type: 'button', 'aria-pressed': 'false',
					onClick: function (e) {
						if (card.classList.contains('is-locked')) { return; }
						input.querySelectorAll('.mahan-ex-option').forEach(function (b) { b.classList.remove('is-chosen'); b.setAttribute('aria-pressed', 'false'); });
						e.currentTarget.classList.add('is-chosen');
						e.currentTarget.setAttribute('aria-pressed', 'true');
						chosen.i = i;
					} }, [
					h('span', { class: 'mahan-ex-option-key', 'aria-hidden': 'true', text: String(i + 1) }),
					document.createTextNode(o)
				]);
			}));
			// Number-key shortcuts (1–9) for keyboard-driven practice.
			card.addEventListener('keydown', function (e) {
				if (card.classList.contains('is-locked') || e.metaKey || e.ctrlKey || e.altKey) { return; }
				var n = parseInt(e.key, 10);
				if (n >= 1 && n <= 9) {
					var opts = input.querySelectorAll('.mahan-ex-option');
					if (opts[n - 1]) { e.preventDefault(); opts[n - 1].click(); }
				}
			});
			card.appendChild(input);
		} else {
			input = h('input', { class: 'mahan-ex-input mahan-ex-blank', type: 'text', placeholder: t('typeAnswer', 'Type your answer…'), 'aria-label': t('typeAnswer', 'Type your answer…') });
			// Enter submits, same as lesson exercises.
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && onSubmit && !card.classList.contains('is-locked')) { e.preventDefault(); onSubmit(); }
			});
			card.appendChild(input);
		}

		return {
			node: card,
			focus: function () {
				// Focus the first option for keyboard flow; deliberately skip
				// text inputs so the mobile keyboard doesn't pop uninvited.
				var f = card.querySelector('.mahan-ex-option');
				if (f) { try { f.focus({ preventScroll: true }); } catch (e) { /* ok */ } }
			},
			answer: function () {
				return (item.type === 'multiple_choice' || item.type === 'true_false') ? chosen.i : input.value;
			},
			lock: function () {
				card.classList.add('is-locked');
				card.querySelectorAll('.mahan-ex-option, .mahan-ex-input').forEach(function (n) { n.disabled = true; });
			},
			markResult: function (res) {
				card.classList.add(res.is_correct ? 'is-correct' : 'is-incorrect');
				var opts = card.querySelectorAll('.mahan-ex-option');
				if (opts.length && typeof res.correct_index === 'number' && opts[res.correct_index]) {
					opts[res.correct_index].classList.add('is-correct');
					opts[res.correct_index].appendChild(h('span', { class: 'mahan-sr-only', text: ' (' + t('correctAnswer', 'correct answer') + ')' }));
				}
			}
		};
	}

	function reviewEmpty(counts) {
		var wrap = h('div', { class: 'mahan-review' });
		wrap.appendChild(reviewHeader());
		wrap.appendChild(h('div', { class: 'mahan-empty' }, [
			h('div', { class: 'mahan-review-empty-icon', text: '✅' }),
			h('p', { text: t('reviewNone', "You're all caught up — no reviews due. Nice!") }),
			(counts && counts.mastered) ? h('p', { class: 'mahan-badges-sub', text: counts.mastered + ' ' + t('reviewMastered', 'mastered') + (counts.learning ? ' · ' + counts.learning + ' ' + t('reviewLearning', 'learning') : '') }) : null,
			h('button', { class: 'mahan-btn mahan-btn-ghost', text: t('backToDashboard', 'Back to My Learning'), onClick: function () { go('dashboard'); } })
		]));
		return wrap;
	}

	function reviewHeader(lessonScope) {
		return h('div', { class: 'mahan-dash-hero mahan-review-hero' }, [
			h('h1', { text: '🎯 ' + t('reviewTitle', 'Practice your mistakes') }),
			h('p', { class: 'mahan-hero-sub', text: lessonScope
				? t('reviewSubLesson', 'A quick check on the questions you missed in this lesson.')
				: t('reviewSub', 'Questions you missed come back here until you master them.') })
		]);
	}

	function renderReview() {
		if (!D.loggedIn) { mount(loginGate()); return; }
		setTitle(t('review', 'Review'));
		var lessonScope = state.lessonId > 0;
		var courseId = state.courseId;
		var path = lessonScope ? '/reviews?scope=lesson&lesson_id=' + state.lessonId : '/reviews';
		var seq = navSeq;
		mount(loadingShell('rows'));
		api(path).then(function (j) {
			if (seq !== navSeq) { return; }
			var items = (j.items || []).slice();
			if (!items.length) { mount(reviewEmpty(j.counts)); return; }
			runReviewSession(items, j.counts || {}, !!j.ai_ready, lessonScope, courseId);
		}).catch(function (e) { if (seq === navSeq) { mount(errorBox(renderReview, e)); } });
	}

	function runReviewSession(queue, counts, aiReady, lessonScope, courseId) {
		var total = queue.length;
		var pos = 0, cleared = 0, skipped = 0;
		var skippedItems = [];
		var wrap = h('div', { class: 'mahan-review' });
		var head = reviewHeader(lessonScope);
		var bar = h('div', { class: 'mahan-review-progress' }, [
			h('div', { class: 'mahan-review-progress-track' }, [h('span', { class: 'mahan-review-progress-fill' })]),
			h('span', { class: 'mahan-review-progress-label' }),
			h('button', { class: 'mahan-review-exit', type: 'button', text: '✕',
				'aria-label': t('exitReview', 'Exit review'), title: t('exitReview', 'Exit review'),
				onClick: function () { if (lessonScope && courseId) { go('course', { course: courseId }); } else { go('dashboard'); } } })
		]);
		var stage = h('div', { class: 'mahan-review-stage' });
		wrap.appendChild(head);
		wrap.appendChild(bar);
		wrap.appendChild(stage);
		mount(wrap);

		var maxPct = 0;
		var xpEarned = 0;
		function updateBar() {
			// Monotonic fill: a wrong answer re-queues an item (total grows),
			// but the bar must never visibly move backwards.
			var pct = total ? Math.round((pos / total) * 100) : 0;
			maxPct = Math.max(maxPct, pct);
			bar.querySelector('.mahan-review-progress-fill').style.width = maxPct + '%';
			bar.querySelector('.mahan-review-progress-label').textContent = Math.min(pos + 1, total) + ' ' + t('reviewProgress', 'of') + ' ' + total;
		}

		function finish() {
			stage.innerHTML = '';
			bar.querySelector('.mahan-review-progress-fill').style.width = '100%';
			bar.querySelector('.mahan-review-progress-label').textContent = total + ' ' + t('reviewProgress', 'of') + ' ' + total;
			// Honest feedback: only the celebratory icon/heading when something
			// was actually cleared and nothing was left behind.
			var win = cleared > 0 && skipped === 0;
			var hasSkipped = skippedItems.length > 0;
			var kids = [
				h('div', { class: 'mahan-review-empty-icon', text: win ? '🎉' : '✅' }),
				h('h2', { text: win ? t('reviewDone', 'Review complete!') : t('reviewEnded', 'Session ended') }),
				h('p', { class: 'mahan-badges-sub', text: cleared + ' ' + t('reviewCleared', 'cleared')
					+ (skipped > 0 ? ' · ' + skipped + ' ' + t('skippedCount', 'skipped') : '')
					+ (xpEarned > 0 ? ' · ⚡ +' + xpEarned + ' XP' : '') })
			];
			// Re-run just the skipped items instead of forcing a full re-entry.
			if (hasSkipped) {
				kids.push(h('button', { class: 'mahan-btn mahan-btn-primary mahan-btn-lg', text: '🔁 ' + fmt(t('reviewSkipped', 'Review %s skipped'), skippedItems.length),
					onClick: function () { runReviewSession(skippedItems.slice(), counts, aiReady, lessonScope, courseId); } }));
			}
			kids.push(lessonScope
				? h('button', { class: 'mahan-btn ' + (hasSkipped ? 'mahan-btn-ghost' : 'mahan-btn-primary') + ' mahan-btn-lg', text: t('continueLearning', 'Continue learning'), onClick: function () { go('course', { course: courseId }); } })
				: h('button', { class: 'mahan-btn ' + (hasSkipped ? 'mahan-btn-ghost' : 'mahan-btn-primary') + ' mahan-btn-lg', text: t('backToDashboard', 'Back to My Learning'), onClick: function () { go('dashboard'); } }));
			stage.appendChild(h('div', { class: 'mahan-empty mahan-review-complete' }, kids));
		}

		function showItem(item) {
			updateBar();
			stage.innerHTML = '';
			var rc = reviewCard(item, function () { checkBtn.click(); });
			var variantToken = item._variant_token || '';
			var feedback = h('div', { class: 'mahan-ex-feedback', style: 'display:none', role: 'status' });
			var msg = h('div', { class: 'mahan-review-msg', role: 'status' });

			var checkBtn = h('button', { class: 'mahan-btn mahan-btn-primary mahan-ex-check', text: t('check', 'Check'),
				onClick: function (e) {
					var ans = rc.answer();
					var isChoice = (item.type === 'multiple_choice' || item.type === 'true_false');
					if ((isChoice && ans === -1) || (!isChoice && !String(ans).trim())) {
						msg.textContent = t('pickAnswer', 'Pick an answer first.');
						return;
					}
					msg.textContent = '';
					var restore = setBusy(e.currentTarget);
					api('/review', 'POST', { review_id: item.review_id, answer: ans, variant_token: variantToken }).then(function (r) {
						if (!r.ok) { restore(); toast(t('error', 'Something went wrong.'), 'error'); return; }
						rc.lock();
						rc.markResult(r);
						if (r.is_correct) { cleared++; xpEarned += (r.xp_awarded || 0); celebrateXp(r); }
						feedback.style.display = 'block';
						feedback.className = 'mahan-ex-feedback ' + (r.is_correct ? 'is-correct' : 'is-incorrect');
						var headTxt = r.is_correct ? '✓ ' + tv('correctVariants', 'correct', 'Correct!') : '✕ ' + tv('incorrectVariants', 'incorrect', 'Not quite');
						var detail = r.is_correct
							? (r.mastered ? t('reviewMasteredMsg', 'Mastered! You won\'t see this one for a while.') : t('reviewAgainSoon', "We'll ask you again soon."))
							: (r.correct_text ? t('answerWas', 'Answer:') + ' ' + esc(r.correct_text) : '');
						// Wrong answers come back once more this same session. Missing
						// an AI variant re-queues the ORIGINAL (its one-time token is
						// spent), so the concept still returns.
						if (!r.is_correct && !item._retried) {
							var reItem = item._orig || item;
							reItem._retried = true;
							// Never re-ask back-to-back right after showing the answer:
							// slot it a couple of cards ahead when near the end.
							if (total - pos <= 2) { queue.splice(Math.min(pos + 2, total), 0, reItem); }
							else { queue.push(reItem); }
							total++;
							detail += '<div class="mahan-review-again">' + esc(t('seeAgainThisSession', "You'll see this one again before the end of this session.")) + '</div>';
						}
						feedback.innerHTML = '<strong>' + headTxt + '</strong>' + (detail ? '<div>' + detail + '</div>' : '');
						btnRow.innerHTML = '';
						var contBtn = h('button', { class: 'mahan-btn mahan-btn-primary', text: t('continueBtn', 'Continue'),
							onClick: function () { pos++; next(); } });
						btnRow.appendChild(contBtn);
						// A miss dead-ends without the source material — offer a jump
						// back to the lesson that teaches the concept.
						if (!r.is_correct && (item.lesson_id || (item._orig && item._orig.lesson_id))) {
							var lid = item.lesson_id || item._orig.lesson_id;
							var cid = item.course_id || (item._orig && item._orig.course_id) || courseId;
							btnRow.appendChild(h('button', { class: 'mahan-btn mahan-btn-ghost', text: t('revisitLesson', 'Revisit lesson') + ' →',
								onClick: function () { go('lesson', { course: cid, lesson: lid }); } }));
						}
						// Enter advances to the next question.
						try { contBtn.focus({ preventScroll: true }); } catch (err) { /* ok */ }
					}).catch(function () { restore(); toast(t('error', 'Something went wrong.'), 'error'); });
				} });

			var actions = [];
			// A variant can always go back to the original question.
			if (item.is_variant && item._orig) {
				actions.push(h('button', { class: 'mahan-btn mahan-btn-ghost mahan-review-variant-btn', text: '↩ ' + t('showOriginal', 'Show the original'),
					onClick: function () { showItem(item._orig); } }));
			}
			// "Ask a different way" — an AI variant, only before answering.
			if (aiReady && !item.is_variant) {
				var variantBtn = h('button', { class: 'mahan-btn mahan-btn-ghost mahan-review-variant-btn', text: '✨ ' + t('askDifferently', 'Ask a different way'),
					onClick: function (e) {
						var restore = setBusy(e.currentTarget, t('loading', 'Loading…'));
						api('/review/variant', 'POST', { review_id: item.review_id }).then(function (r) {
							if (r.ok && r.item) {
								var v = r.item;
								v._variant_token = r.variant_token;
								v._retried = item._retried;
								v._orig = item;
								showItem(v);
							} else {
								restore();
								toast(t('variantFailed', "Couldn't rephrase that one — here's the original."), 'info');
							}
						}).catch(function () { restore(); toast(t('variantFailed', "Couldn't rephrase that one — here's the original."), 'info'); });
					} });
				actions.push(variantBtn);
			}
			actions.push(h('button', { class: 'mahan-btn mahan-btn-ghost', text: t('skip', 'Skip'), onClick: function () { skipped++; skippedItems.push(item._orig || item); pos++; next(); } }));
			actions.push(checkBtn);
			var btnRow = h('div', { class: 'mahan-ex-bar mahan-review-bar' }, actions);

			stage.appendChild(rc.node);
			stage.appendChild(feedback);
			stage.appendChild(msg);
			stage.appendChild(btnRow);
			rc.focus();
		}

		function next() {
			if (pos >= total) { finish(); return; }
			showItem(queue[pos]);
		}
		next();
	}

	/* ------------------------------------------------------------------ */
	/* Render dispatcher                                                   */
	/* ------------------------------------------------------------------ */

	function render() {
		// New navigation generation — invalidates any in-flight async paint
		// from the previous view.
		navSeq++;
		switch (state.view) {
			case 'course': return renderCourse();
			case 'lesson': return D.loggedIn ? renderLesson() : mount(loginGate());
			case 'dashboard': return renderDashboard();
			case 'review': return D.loggedIn ? renderReview() : mount(loginGate());
			case 'leaderboard': return D.leaderboard ? renderLeaderboard() : renderCatalog();
			case 'paths': return renderPaths();
			case 'path': return renderPath();
			case 'catalog':
			default: return renderCatalog();
		}
	}

	// Arrow-key navigation across a single-select answer group (roving focus).
	// Delegated once so every option list — lesson MC/TF, quiz, review — gets
	// it without per-render wiring; buttons keep their click/aria-pressed logic.
	root.addEventListener('keydown', function (e) {
		var opt = e.target;
		if (!opt || !opt.classList || !opt.classList.contains('mahan-ex-option')) { return; }
		var dir = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1, Home: 'home', End: 'end' };
		if (!(e.key in dir)) { return; }
		var group = opt.closest ? opt.closest('.mahan-ex-options') : null;
		if (!group) { return; }
		var opts = Array.prototype.slice.call(group.querySelectorAll('.mahan-ex-option')).filter(function (b) { return !b.disabled; });
		if (opts.length < 2) { return; }
		e.preventDefault();
		var i = opts.indexOf(opt);
		var next = dir[e.key] === 'home' ? opts[0]
			: dir[e.key] === 'end' ? opts[opts.length - 1]
			: opts[(i + dir[e.key] + opts.length) % opts.length];
		if (next) { try { next.focus(); } catch (er) { /* ok */ } }
	});

	function boot() {
		parseUrl();
		// Paint the view immediately; hydrate the HUD in parallel instead of
		// serializing two round-trips before first paint.
		render();
		if (D.loggedIn && state.view !== 'dashboard') {
			api('/me').then(function (j) {
				apiCache['/me'] = j;
				state.me = j;
				refreshTopbar();
			}).catch(function () { /* HUD stays in placeholder state */ });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
