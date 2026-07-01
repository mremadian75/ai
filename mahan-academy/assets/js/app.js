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

	function api(path, method, data) {
		var opt = {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.nonce || '' }
		};
		if (data) { opt.body = JSON.stringify(data); }
		return fetch(REST + path, opt).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) { throw Object.assign(new Error('API'), { status: r.status, payload: j }); }
				return j;
			});
		});
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

	function parseUrl() {
		var p = new URLSearchParams(window.location.search);
		state.view = p.get('view') || 'catalog';
		state.courseId = parseInt(p.get('course') || '0', 10);
		state.lessonId = parseInt(p.get('lesson') || '0', 10);
	}

	function urlFor(view, params) {
		var base = D.appUrl || window.location.pathname;
		var u = new URL(base, window.location.origin);
		u.searchParams.set('view', view);
		if (params && params.course) { u.searchParams.set('course', params.course); }
		if (params && params.lesson) { u.searchParams.set('lesson', params.lesson); }
		return u.pathname + u.search;
	}

	function go(view, params) {
		state.view = view;
		state.courseId = (params && params.course) || 0;
		state.lessonId = (params && params.lesson) || 0;
		window.history.pushState({ view: view, params: params }, '', urlFor(view, params));
		window.scrollTo(0, 0);
		render();
	}

	window.addEventListener('popstate', function () { parseUrl(); render(); });

	/* ------------------------------------------------------------------ */
	/* HUD (top bar)                                                       */
	/* ------------------------------------------------------------------ */

	function hud() {
		var bar = h('header', { class: 'mahan-topbar' });
		var brand = h('a', { class: 'mahan-brand', href: urlFor('catalog'),
			onClick: function (e) { e.preventDefault(); go('catalog'); } }, ['Mahan ', h('span', { text: 'Academy' })]);

		var nav = h('nav', { class: 'mahan-nav' }, [
			h('a', { class: 'mahan-nav-link' + (state.view === 'catalog' ? ' is-active' : ''), href: urlFor('catalog'),
				onClick: function (e) { e.preventDefault(); go('catalog'); }, text: t('catalog', 'Explore') }),
			D.loggedIn ? h('a', { class: 'mahan-nav-link' + (state.view === 'dashboard' ? ' is-active' : ''), href: urlFor('dashboard'),
				onClick: function (e) { e.preventDefault(); go('dashboard'); }, text: t('dashboard', 'My Learning') }) : null
		]);

		var right;
		if (D.loggedIn && state.me && state.me.stats) {
			var s = state.me.stats;
			right = h('div', { class: 'mahan-hud' }, [
				h('div', { class: 'mahan-hud-item mahan-hud-streak', title: t('streak', 'day streak') }, [
					h('span', { class: 'mahan-hud-icon', text: '🔥' }), h('span', { id: 'hud-streak', text: String(s.streak || 0) })]),
				h('div', { class: 'mahan-hud-item mahan-hud-xp', title: 'XP' }, [
					h('span', { class: 'mahan-hud-icon', text: '⚡' }), h('span', { id: 'hud-xp', text: String(s.xp || 0) })]),
				h('div', { class: 'mahan-hud-item mahan-hud-level', title: t('level', 'Level') }, [
					h('span', { class: 'mahan-hud-icon', text: '◆' }), h('span', { id: 'hud-level', text: String(s.level || 1) })]),
				state.me.user ? h('img', { class: 'mahan-hud-avatar', src: state.me.user.avatar, alt: state.me.user.name }) : null
			]);
		} else {
			right = h('div', { class: 'mahan-hud' }, [
				h('a', { class: 'mahan-btn mahan-btn-sm mahan-btn-primary', href: D.loginUrl, text: t('login', 'Log in') })
			]);
		}

		bar.appendChild(brand);
		bar.appendChild(nav);
		bar.appendChild(right);
		return bar;
	}

	function refreshHud(stats) {
		if (!stats) { return; }
		if (state.me) { state.me.stats = stats; }
		var x = el('hud-xp'), l = el('hud-level'), st = el('hud-streak');
		if (x) { x.textContent = stats.xp; }
		if (l) { l.textContent = stats.level; }
		if (st) { st.textContent = stats.streak; }
	}

	/* ------------------------------------------------------------------ */
	/* Toasts                                                              */
	/* ------------------------------------------------------------------ */

	function toast(msg, kind) {
		var box = el('mahan-toasts');
		if (!box) { box = h('div', { id: 'mahan-toasts', class: 'mahan-toasts' }); document.body.appendChild(box); }
		var node = h('div', { class: 'mahan-toast mahan-toast-' + (kind || 'info'), html: msg });
		box.appendChild(node);
		setTimeout(function () { node.classList.add('is-out'); setTimeout(function () { node.remove(); }, 400); }, 2600);
	}

	function celebrateXp(res) {
		if (res && res.xp_awarded > 0) { toast('⚡ +' + res.xp_awarded + ' XP', 'xp'); }
		if (res && res.stats) { refreshHud(res.stats); }
		if (res && res.leveled_up) { toast('◆ ' + t('levelUp', 'Level up!'), 'level'); }
	}

	/* ------------------------------------------------------------------ */
	/* Loading / shells                                                    */
	/* ------------------------------------------------------------------ */

	function mount(content) {
		root.innerHTML = '';
		root.appendChild(hud());
		var main = h('main', { class: 'mahan-main' }, [content]);
		root.appendChild(main);
	}

	function loadingShell() {
		return h('div', { class: 'mahan-loading' }, [h('div', { class: 'mahan-boot-spinner' }), h('p', { text: '…' })]);
	}

	/* ------------------------------------------------------------------ */
	/* View: Catalog                                                       */
	/* ------------------------------------------------------------------ */

	function renderCatalog() {
		mount(loadingShell());
		api('/catalog').then(function (j) {
			var courses = j.courses || [];
			var wrap = h('div', { class: 'mahan-catalog' });
			wrap.appendChild(h('div', { class: 'mahan-hero' }, [
				h('h1', { text: 'Learn to use AI at work' }),
				h('p', { class: 'mahan-hero-sub', text: 'Structured courses. Hands-on practice. A tutor that answers in real time.' })
			]));

			// Level filter chips.
			var levels = [['', t('allLevels', 'All levels')], ['beginner', t('beginner', 'Beginner')], ['intermediate', t('intermediate', 'Intermediate')], ['advanced', t('advanced', 'Advanced')]];
			var chips = h('div', { class: 'mahan-chips' }, levels.map(function (lv) {
				return h('button', {
					class: 'mahan-chip' + (state.levelFilter === lv[0] ? ' is-active' : ''),
					text: lv[1],
					onClick: function () { state.levelFilter = lv[0]; renderCatalog(); }
				});
			}));
			wrap.appendChild(chips);

			var filtered = courses.filter(function (c) { return !state.levelFilter || c.level === state.levelFilter; });
			if (!filtered.length) {
				wrap.appendChild(h('div', { class: 'mahan-empty', text: t('emptyCatalog', 'No courses available yet.') }));
			} else {
				var grid = h('div', { class: 'mahan-grid' });
				filtered.forEach(function (c) { grid.appendChild(courseCard(c)); });
				wrap.appendChild(grid);
			}
			mount(wrap);
		}).catch(function () { mount(errorBox(renderCatalog)); });
	}

	function courseCard(c) {
		var media = c.image
			? h('div', { class: 'mahan-card-media', style: 'background-image:url(' + esc(c.image) + ')' })
			: h('div', { class: 'mahan-card-media mahan-card-media-ph' }, [h('span', { text: (c.title || '?').charAt(0) })]);

		var foot = c.enrolled
			? h('div', { class: 'mahan-progress' }, [
				h('div', { class: 'mahan-progress-bar' }, [h('span', { style: 'width:' + (c.progress_pct || 0) + '%' })]),
				h('span', { class: 'mahan-progress-label', text: (c.progress_pct || 0) + '%' })])
			: h('div', { class: 'mahan-card-tags' }, [
				h('span', { class: 'mahan-tag', text: levelLabel(c.level) }),
				h('span', { class: 'mahan-tag mahan-tag-soft', text: c.lesson_count + ' ' + t('lessons', 'lessons') })]);

		return h('a', {
			class: 'mahan-card', href: urlFor('course', { course: c.id }),
			onClick: function (e) { e.preventDefault(); go('course', { course: c.id }); }
		}, [
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

	/* ------------------------------------------------------------------ */
	/* View: Course                                                        */
	/* ------------------------------------------------------------------ */

	function renderCourse() {
		mount(loadingShell());
		api('/course/' + state.courseId).then(function (j) {
			if (!j.ok) { mount(errorBox(renderCourse)); return; }
			var c = j.course;
			var wrap = h('div', { class: 'mahan-course' });

			// Hero.
			var hero = h('div', { class: 'mahan-course-hero' }, [
				h('div', { class: 'mahan-course-hero-text' }, [
					h('a', { class: 'mahan-back', href: urlFor('catalog'), onClick: function (e) { e.preventDefault(); go('catalog'); }, text: '← ' + t('catalog', 'Explore') }),
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
				content.appendChild(h('div', { class: 'mahan-unit-title', text: unit.title }));
				var list = h('ul', { class: 'mahan-lesson-list' });
				unit.lessons.forEach(function (ls) {
					list.appendChild(lessonRow(ls, j.enrolled));
				});
				content.appendChild(list);
			});
			wrap.appendChild(content);

			mount(wrap);
		}).catch(function () { mount(errorBox(renderCourse)); });
	}

	function courseCta(j) {
		if (!D.loggedIn) {
			return h('a', { class: 'mahan-btn mahan-btn-primary mahan-btn-lg', href: D.loginUrl, text: t('loginToLearn', 'Log in to start learning') });
		}
		if (!j.enrolled) {
			return h('button', { class: 'mahan-btn mahan-btn-primary mahan-btn-lg', text: t('enroll', 'Enroll — free'),
				onClick: function (e) {
					var btn = e.currentTarget; btn.disabled = true; btn.textContent = '…';
					api('/enroll', 'POST', { course_id: j.course.id }).then(function (r) {
						if (r.stats) { refreshHud(r.stats); }
						D._enrolled = true;
						renderCourse();
					}).catch(function () { btn.disabled = false; btn.textContent = t('enroll', 'Enroll — free'); });
				} });
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
			title: ls.locked ? t('locked', 'Complete the previous lesson to unlock') : '',
			onClick: clickable ? function (e) { e.preventDefault(); go('lesson', { course: state.courseId, lesson: ls.id }); } : null
		}, [
			h('span', { class: 'mahan-lesson-icon', text: ls.locked ? '🔒' : icon }),
			h('span', { class: 'mahan-lesson-name', text: ls.title }),
			h('span', { class: 'mahan-lesson-meta' }, [
				ls.est_min ? h('span', { class: 'mahan-lesson-min', text: ls.est_min + ' min' }) : null,
				h('span', { class: 'mahan-lesson-xp', text: '⚡' + ls.xp })
			])
		]);
	}

	/* ------------------------------------------------------------------ */
	/* View: Lesson player                                                 */
	/* ------------------------------------------------------------------ */

	function renderLesson() {
		mount(loadingShell());
		ensureProfile().then(function () {
			return api('/lesson/' + state.lessonId);
		}).then(function (L) {
			if (!L.ok) { mount(errorBox(renderLesson)); return; }
			if (L.stats) { refreshHud(L.stats); }
			var wrap = h('div', { class: 'mahan-lesson' });

			// Top: back to course + progress.
			wrap.appendChild(h('div', { class: 'mahan-lesson-top' }, [
				h('a', { class: 'mahan-back', href: urlFor('course', { course: L.course_id }),
					onClick: function (e) { e.preventDefault(); go('course', { course: L.course_id }); },
					text: '← ' + (L.course_title || t('backToCourse', 'Back to course')) })
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

			// Tutor side panel.
			layout.appendChild(tutorPanel(L));

			wrap.appendChild(layout);
			mount(wrap);
			loadChat(L.id);
		}).catch(function (e) {
			if (e && e.status === 401) { mount(loginGate()); return; }
			mount(errorBox(renderLesson));
		});
	}

	function lessonFooter(L) {
		var foot = h('div', { class: 'mahan-lesson-foot' });
		foot.appendChild(L.siblings && L.siblings.prev
			? h('button', { class: 'mahan-btn mahan-btn-ghost', text: '← ' + t('prevLesson', 'Previous'),
				onClick: function () { go('lesson', { course: L.course_id, lesson: L.siblings.prev.id }); } })
			: h('span', {}));

		var done = L.status === 'completed';
		var main = h('button', {
			class: 'mahan-btn mahan-btn-primary' + (done ? ' is-done' : ''),
			text: done ? '✓ ' + t('lessonComplete', 'Completed') : t('completeLesson', 'Complete lesson'),
			onClick: function (e) {
				var btn = e.currentTarget;
				if (!L.enrolled) { go('course', { course: L.course_id }); return; }
				btn.disabled = true;
				api('/progress', 'POST', { lesson_id: L.id }).then(function (r) {
					celebrateXp({ xp_awarded: r.xp_awarded, stats: r.stats });
					if (r.course_completed) {
						toast('🎉 ' + t('courseComplete', 'Course complete!'), 'level');
						setTimeout(function () { go('course', { course: L.course_id }); }, 900);
					} else if (L.siblings && L.siblings.next) {
						setTimeout(function () { go('lesson', { course: L.course_id, lesson: L.siblings.next.id }); }, 700);
					} else {
						setTimeout(function () { go('course', { course: L.course_id }); }, 700);
					}
				}).catch(function () { btn.disabled = false; toast(t('error', 'Something went wrong.'), 'error'); });
			}
		});
		foot.appendChild(main);

		foot.appendChild(L.siblings && L.siblings.next
			? h('button', { class: 'mahan-btn mahan-btn-ghost', text: t('nextLesson', 'Next lesson') + ' →',
				onClick: function () { go('lesson', { course: L.course_id, lesson: L.siblings.next.id }); } })
			: h('span', {}));
		return foot;
	}

	/* ------------------------------------------------------------------ */
	/* Exercises                                                           */
	/* ------------------------------------------------------------------ */

	function exerciseCard(ex, L) {
		var card = h('div', { class: 'mahan-ex' + (ex.solved ? ' is-solved' : '') });
		card.appendChild(h('div', { class: 'mahan-ex-q', html: mdToHtml(ex.question || ex.task || '') }));

		var feedback = h('div', { class: 'mahan-ex-feedback', style: 'display:none' });
		var checkBtn;

		if ((ex.type === 'multiple_choice' || ex.type === 'true_false') && ex.options) {
			var chosen = { i: -1 };
			var opts = h('div', { class: 'mahan-ex-options' + (ex.type === 'true_false' ? ' mahan-ex-tf' : '') }, ex.options.map(function (o, i) {
				return h('button', { class: 'mahan-ex-option', type: 'button', text: o,
					onClick: function (e) {
						if (card.classList.contains('is-solved')) { return; }
						opts.querySelectorAll('.mahan-ex-option').forEach(function (b) { b.classList.remove('is-chosen'); });
						e.currentTarget.classList.add('is-chosen');
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
			var inp = h('input', { class: 'mahan-ex-input mahan-ex-blank', type: 'text', placeholder: ex.placeholder || t('typeAnswer', 'Type your answer…') });
			if (ex.solved) { inp.disabled = true; }
			card.appendChild(inp);
			checkBtn = h('button', { class: 'mahan-btn mahan-btn-primary mahan-ex-check', text: t('check', 'Check'),
				onClick: function () { submitExercise(L.id, ex, inp.value, card, feedback, checkBtn, null); } });
			inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); checkBtn.click(); } });
		} else {
			var ta = h('textarea', { class: 'mahan-ex-input', rows: 4, placeholder: ex.placeholder || t('writeAnswer', 'Write your answer…') });
			if (ex.solved) { ta.disabled = true; }
			card.appendChild(ta);
			checkBtn = h('button', { class: 'mahan-btn mahan-btn-primary mahan-ex-check', text: t('submit', 'Submit'),
				onClick: function () { submitExercise(L.id, ex, ta.value, card, feedback, checkBtn, null); } });
		}

		var bar = h('div', { class: 'mahan-ex-bar' }, [
			ex.hint ? h('button', { class: 'mahan-ex-hint-btn', type: 'button', text: '💡 ' + t('hint', 'Hint'),
				onClick: function () { toast(esc(ex.hint), 'info'); } }) : h('span', {}),
			checkBtn
		]);
		card.appendChild(bar);
		card.appendChild(feedback);

		if (ex.solved) { card.classList.add('is-solved'); }
		return card;
	}

	function submitExercise(lessonId, ex, answer, card, feedback, btn, opts) {
		var isChoice = (ex.type === 'multiple_choice' || ex.type === 'true_false');
		if (isChoice && (answer === -1 || answer === '')) { return; }
		if (!isChoice && !String(answer).trim()) { return; }
		btn.disabled = true;
		var oldLabel = btn.textContent;
		btn.textContent = '…';
		api('/exercise', 'POST', { lesson_id: lessonId, key: ex.key, answer: answer }).then(function (r) {
			btn.textContent = oldLabel;
			feedback.style.display = 'block';
			feedback.className = 'mahan-ex-feedback ' + (r.is_correct ? 'is-correct' : 'is-incorrect');
			var head = r.is_correct ? '✓ ' + t('correct', 'Correct!') : '✕ ' + t('incorrect', 'Not quite');
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
			btn.textContent = oldLabel; btn.disabled = false;
			toast(t('error', 'Something went wrong.'), 'error');
		});
	}

	/* ------------------------------------------------------------------ */
	/* AI Tutor (streaming)                                                */
	/* ------------------------------------------------------------------ */

	var tutorBusy = false;

	function tutorPanel(L) {
		var panel = h('aside', { class: 'mahan-tutor' });
		panel.appendChild(h('div', { class: 'mahan-tutor-head' }, [
			h('span', { class: 'mahan-tutor-avatar', text: '🤖' }),
			h('div', {}, [h('strong', { text: t('tutorTitle', 'AI Tutor') }), h('span', { class: 'mahan-tutor-status', text: 'online' })])
		]));
		var log = h('div', { class: 'mahan-tutor-log', id: 'mahan-tutor-log' });
		if (!D.aiReady || !L.tutor_ready) {
			log.appendChild(tutorBubble('assistant', t('tutorOffline', 'The AI tutor is not configured yet.')));
		} else {
			log.appendChild(tutorBubble('assistant', t('tutorIntro', 'Hi! Ask me anything about this lesson.')));
		}
		panel.appendChild(log);
		var input = h('textarea', { class: 'mahan-tutor-input', rows: 1, placeholder: t('tutorPlaceholder', 'Ask the tutor…') });
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendToTutor(L.id, input); }
		});
		var send = h('button', { class: 'mahan-tutor-send', text: '➤', onClick: function () { sendToTutor(L.id, input); } });
		panel.appendChild(h('div', { class: 'mahan-tutor-bar' }, [input, send]));
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
		var log = el('mahan-tutor-log');
		log.appendChild(tutorBubble('user', msg));
		var bubble = tutorBubble('assistant', '');
		bubble.classList.add('is-streaming');
		bubble.innerHTML = '<span class="mahan-typing"><i></i><i></i><i></i></span>';
		log.appendChild(bubble);
		log.scrollTop = log.scrollHeight;
		tutorBusy = true;

		streamTutor(lessonId, msg, function (token) {
			if (bubble.classList.contains('is-streaming') && bubble.querySelector('.mahan-typing')) { bubble.innerHTML = ''; }
			bubble._raw = (bubble._raw || '') + token;
			bubble.innerHTML = mdToHtml(bubble._raw);
			log.scrollTop = log.scrollHeight;
		}, function (err) {
			tutorBusy = false;
			bubble.classList.remove('is-streaming');
			if (err) {
				bubble.innerHTML = '<em>' + esc(err) + '</em>';
			} else if (!bubble._raw) {
				bubble.innerHTML = '<em>' + esc(t('error', 'Something went wrong.')) + '</em>';
			}
			log.scrollTop = log.scrollHeight;
		});
	}

	function streamTutor(lessonId, message, onToken, onDone) {
		var url = D.ajaxUrl + '?action=' + encodeURIComponent(D.streamAction) + '&_wpnonce=' + encodeURIComponent(D.streamNonce);
		fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ message: message, lesson_id: lessonId })
		}).then(function (resp) {
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
		}).catch(function () { tutorFallback(lessonId, message, onToken, onDone); });
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

	function renderDashboard() {
		if (!D.loggedIn) { mount(loginGate()); return; }
		mount(loadingShell());
		api('/me').then(function (j) {
			state.me = j;
			var s = j.stats || {};
			var wrap = h('div', { class: 'mahan-dash' });
			wrap.appendChild(h('div', { class: 'mahan-dash-hero' }, [
				h('h1', { text: (t('dashboard', 'My Learning')) }),
				h('div', { class: 'mahan-dash-stats' }, [
					statBig('🔥', s.streak || 0, t('streak', 'day streak')),
					statBig('⚡', s.xp || 0, 'XP'),
					statBig('◆', s.level || 1, t('level', 'Level'))
				]),
				h('div', { class: 'mahan-level-bar' }, [
					h('div', { class: 'mahan-level-bar-track' }, [h('span', { style: 'width:' + levelPct(s) + '%' })]),
					h('span', { class: 'mahan-level-bar-label', text: (s.xp_into_level || 0) + ' / ' + (s.xp_per_level || 100) + ' XP → ' + t('level', 'Level') + ' ' + ((s.level || 1) + 1) })
				])
			]));

			var courses = j.courses || [];
			if (!courses.length) {
				wrap.appendChild(h('div', { class: 'mahan-empty' }, [
					h('p', { text: t('emptyDashboard', "You haven't enrolled in any courses yet.") }),
					h('button', { class: 'mahan-btn mahan-btn-primary', text: t('browseCourses', 'Browse courses'), onClick: function () { go('catalog'); } })
				]));
			} else {
				wrap.appendChild(h('h2', { class: 'mahan-dash-h2', text: t('continue', 'Continue') }));
				var grid = h('div', { class: 'mahan-grid' });
				courses.forEach(function (c) { c.enrolled = true; grid.appendChild(courseCard(c)); });
				wrap.appendChild(grid);
			}
			mount(wrap);
		}).catch(function () { mount(errorBox(renderDashboard)); });
	}

	function statBig(icon, value, label) {
		return h('div', { class: 'mahan-stat-big' }, [
			h('span', { class: 'mahan-stat-icon', text: icon }),
			h('span', { class: 'mahan-stat-value', text: String(value) }),
			h('span', { class: 'mahan-stat-label', text: label })
		]);
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
			fields.forEach(function (f) { form.appendChild(profileField(f, profile[f.key])); });
			var msg = h('div', { class: 'mahan-profile-msg' });

			var overlay = h('div', { class: 'mahan-modal-overlay' }, [
				h('div', { class: 'mahan-modal' }, [
					h('h2', { text: t('profileTitle', 'Tell us about you') }),
					h('p', { class: 'mahan-modal-sub', text: t('profileIntro', 'This personalizes your lessons and AI tutor.') }),
					form,
					msg,
					h('div', { class: 'mahan-modal-actions' }, [
						h('button', { class: 'mahan-btn mahan-btn-ghost', type: 'button', text: t('notNow', 'Not now'),
							onClick: function () { overlay.remove(); resolve(); } }),
						h('button', { class: 'mahan-btn mahan-btn-primary', type: 'button', text: t('save', 'Save & continue'),
							onClick: function (e) {
								var out = collectProfile(form, fields);
								for (var i = 0; i < fields.length; i++) {
									var f = fields[i];
									if (f.required && (!out[f.key] || (Array.isArray(out[f.key]) && !out[f.key].length))) {
										msg.textContent = t('required', 'Please fill in the required fields.');
										return;
									}
								}
								e.currentTarget.disabled = true;
								api('/profile', 'POST', { profile: out }).then(function (r) {
									state.profile = { complete: r.complete, profile: r.profile, schema: schema };
									overlay.remove(); resolve();
								}).catch(function () { e.currentTarget.disabled = false; msg.textContent = t('error', 'Something went wrong.'); });
							} })
					])
				])
			]);
			document.body.appendChild(overlay);
		});
	}

	function profileField(f, value) {
		var wrap = h('div', { class: 'mahan-field' });
		var req = f.required ? ' *' : '';
		wrap.appendChild(h('label', { text: (f.label || f.key) + req }));
		var input;
		if (f.type === 'textarea') {
			input = h('textarea', { rows: 3, 'data-key': f.key });
			input.value = value || '';
		} else if (f.type === 'select') {
			input = h('select', { 'data-key': f.key });
			input.appendChild(h('option', { value: '', text: '—' }));
			(f.options || []).forEach(function (o) {
				var ov = typeof o === 'string' ? o : o.value;
				var ol = typeof o === 'string' ? o : (o.label || o.value);
				var opt = h('option', { value: ov, text: ol });
				if (String(ov) === String(value)) { opt.selected = true; }
				input.appendChild(opt);
			});
		} else if (f.type === 'multiselect') {
			input = h('div', { class: 'mahan-checks', 'data-key': f.key });
			var vals = Array.isArray(value) ? value.map(String) : [];
			(f.options || []).forEach(function (o) {
				var ov = typeof o === 'string' ? o : o.value;
				var ol = typeof o === 'string' ? o : (o.label || o.value);
				var cb = h('input', { type: 'checkbox', value: ov });
				if (vals.indexOf(String(ov)) >= 0) { cb.checked = true; }
				input.appendChild(h('label', { class: 'mahan-check-item' }, [cb, document.createTextNode(' ' + ol)]));
			});
		} else {
			input = h('input', { type: 'text', 'data-key': f.key });
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
				h('a', { class: 'mahan-btn mahan-btn-primary', href: D.loginUrl, text: t('login', 'Log in') }),
				D.canRegister ? h('a', { class: 'mahan-btn mahan-btn-ghost', href: D.registerUrl, text: t('register', 'Create account') }) : null
			])
		]);
	}

	function errorBox(retry) {
		return h('div', { class: 'mahan-empty' }, [
			h('p', { text: t('error', 'Something went wrong.') }),
			h('button', { class: 'mahan-btn mahan-btn-ghost', text: '↻', onClick: retry })
		]);
	}

	/* ------------------------------------------------------------------ */
	/* Render dispatcher                                                   */
	/* ------------------------------------------------------------------ */

	function render() {
		switch (state.view) {
			case 'course': return renderCourse();
			case 'lesson': return D.loggedIn ? renderLesson() : mount(loginGate());
			case 'dashboard': return renderDashboard();
			case 'catalog':
			default: return renderCatalog();
		}
	}

	function boot() {
		parseUrl();
		if (D.loggedIn) {
			api('/me').then(function (j) { state.me = j; render(); }).catch(function () { render(); });
		} else {
			render();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
