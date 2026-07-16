=== Mahan Academy ===
Contributors: mahan
Tags: lms, ai, learning, chatgpt, claude, gemini, course, tutor, gamification
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A standalone AI-learning platform for WordPress. Coursera-style course structure, Duolingo-style interactive practice, and a real-time AI tutor — powered directly by Anthropic, OpenAI, or Google.

== Description ==

Mahan Academy is a self-contained learning plugin built to teach people how to use AI in their daily work. No LMS dependency. No external automation services. Just WordPress, custom post types, custom tables, and direct calls to the AI provider of your choice.

**Key features:**

* Standalone courses & lessons (no Tutor LMS, LearnDash, etc. required)
* Visual drag-and-drop Course Builder — build the whole curriculum on one screen
* AI authoring assistant — generate outcomes, draft lessons, and create exercises
* Beautiful single-page application front-end (Coursera structure + Duolingo energy)
* Real-time streaming AI tutor via Server-Sent Events (Anthropic / OpenAI / Google)
* Five exercise types: multiple choice, true/false, fill-in-the-blank, short answer, and prompt-writing (open answers graded by AI)
* Schema-driven learner profile that personalizes the tutor and grading prompts
* Gamification: XP with a full audit log, streak XP multipliers, linear or RPG-style progressive levels & titles, daily XP goals, streaks with earned streak freezes, weekly activity dots, 21 tiered achievements with live unlock notifications and progress bars, and weekly + all-time leaderboards
* Polished learner UX: instant catalog search, "Jump back in" resume, lesson wayfinding (unit · lesson X of Y), confetti course-completion celebrations, offline-aware errors, and instant back/forward navigation
* Adaptive review: wrong answers are re-asked at the end of the lesson and again on later days (spaced repetition), with an optional AI "ask it a different way" from another model
* End-of-unit quizzes with instant grading and a passing score
* Learning paths — group courses into a guided, ordered program
* Email notifications (welcome, completion, achievement, streak reminder)
* Admin reports & analytics with CSV export
* Featured courses, promo videos, prerequisites, and printable completion certificates
* English UI, fully translatable
* Lightweight: vanilla JS, no build step, no external runtime dependencies

== Installation ==

1. Upload the `mahan-academy` folder to `/wp-content/plugins/`.
2. Activate "Mahan Academy" from the Plugins screen.
3. Open *Mahan Academy → Settings* and add an API key for at least one AI provider.
4. Create a course (and lessons) from the Mahan Academy menu.
5. Visit the auto-created "Academy" page to see the SPA in action.

== Frequently Asked Questions ==

= Which AI providers are supported? =
Anthropic (Claude), OpenAI (GPT), and Google (Gemini). You pick the active one in settings; the same model handles both the streaming tutor and the AI-graded exercises.

= Do I need any external service like n8n or Zapier? =
No. Everything happens inside WordPress.

= Can I customize the profile questions? =
Yes — *Settings → Profile Form* takes a JSON schema. The placeholders you define there flow into the tutor's system prompt automatically.

== Changelog ==

= 1.9.0 =
UI best-practices pass — a visual-design refinement of the whole front-end, verified by rendering the components in a headless browser in both light and dark themes.
* Proper semantic colour system: reds, ambers, and the info blue are now theme-aware design tokens, so status text (errors, "not quite", failed quiz, streak-at-risk) stays legible in dark mode instead of being too dark to read.
* Live-updating numbers (XP, streak, level, percentages, ranks) now use tabular figures so they no longer shift width and "jitter" as they change.
* The primary "Continue / Complete" button now presses down with a tactile 3D motion; every text field shows a clear focus ring; pill controls and cards have smoother, more defined hover and keyboard-focus states.
* Native form controls (selects, scrollbars, checkboxes) now follow the light/dark theme via `color-scheme`; the app's scroll areas get slim themed scrollbars.
* Headings wrap more gracefully (no orphan last word), text renders crisper, empty-state icons get a focal circular badge, and text selection uses the brand colour.
* No functional or content changes — styling only. Database version unchanged (still v4).

= 1.8.0 =
UX best-practices round 3 — a multi-lens audit of the whole learner experience (adaptive review, dashboard, lesson player, accessibility, and motivation copy), with the findings folded back in.
* Dashboard now leads with what to do: the due-review call-to-action and "Jump back in" card sit above the stats hero, and only one of them is the primary button so the page has a single obvious next step.
* The reviews-due count now shows as a live badge on the navigation (desktop and mobile) and stays correct after every answer — a new mistake surfaces immediately instead of waiting for a page refresh.
* The streak flame in the top bar goes "cold" on days you haven't studied yet, matching the dashboard — a gentle, honest nudge that the streak is at risk.
* Adaptive review is fairer and more helpful: missing an AI-rephrased question now brings the original concept back later in the session (instead of dropping it), a missed item is never re-asked immediately after its answer was shown, each wrong answer offers a one-tap "Revisit lesson", and a session that cleared nothing is reported honestly (with a "Review skipped" shortcut) rather than celebrated.
* Answer options in review can be picked with the number keys (1–9), and correct/incorrect results now carry a ✓/✕ text cue — not colour alone.
* Reinforcement copy ("Correct!", "Daily goal reached!", "Level up!") now rotates through several phrasings so the reward loop stays fresh.
* Accessibility: week-activity dots, achievement badges, graded answers, and the AI tutor's replies are now announced properly to screen readers; unit titles and the catalog use a correct heading order; locked lessons expose their disabled state.
* The AI tutor now shows a clear "Online / Unavailable" status, and the mobile chat button no longer appears when the tutor isn't configured for a lesson.
* Course-card images now load lazily (faster catalog on slow connections), the daily-goal picker saves optimistically (no frozen dropdown), and the lesson player reuses its cache for instant back-navigation.

= 1.7.2 =
Second audit pass — regression checks on the 1.7.1 fixes plus deeper subsystem review. **DB v4** (adds one column to the reviews table, migrated automatically).
* Fixed: a wrong→right loop could still farm review XP — review XP is now capped at once per item per day (the end-of-lesson re-drill still rewards you normally).
* Fixed: the AI tutor now surfaces provider errors (expired key, rate limit) instead of silently showing an empty reply; long answers are no longer cut off by a hard timeout.
* Fixed: the tutor can no longer be used to read unpublished/draft lesson content (it now requires a published lesson you're enrolled in).
* Fixed: a double-click on Enroll no longer sends two welcome emails.
* Fixed: a finished course keeps its completion date/status even if its progress is later recomputed.
* Fixed: quiz achievements now count correctly across courses that share a unit name; clearing reviews can unlock XP achievements immediately.
* Fixed: an AI reply whose JSON contains a brace inside a text value is now parsed correctly (previously it could make a wrong answer count as correct).
* Fixed: the lesson lock shown in the course outline now always matches what the server enforces; a slow/late fix ensures the next lesson is never wrongly locked.

= 1.7.1 =
Hardening & bug-fix release after a full-codebase audit.
* Security: sequential lesson gating is now enforced server-side (you can no longer skip ahead or instantly "complete" a course by scripting requests); Course Builder AJAX now checks per-object permissions and no longer lets low-privilege roles publish or tamper with other authors' curricula; the REST API always requires a valid nonce; the CSV export is protected against spreadsheet formula injection.
* Fixed: review XP could be farmed by re-submitting the same answer — XP is now only granted for genuinely-due reviews, and AI "ask a different way" variants are single-use.
* Fixed: finishing an already-complete course could send a duplicate "course completed" email.
* Fixed: a very large course (200+ lessons) could round up to "100% complete" one lesson early, wrongly offering the certificate.
* Fixed: the streak XP bonus is now calculated after today's streak is counted (a lapsed streak no longer grants one last inflated bonus).
* Fixed: AI-graded answers returning a JSON string "false" are no longer marked correct.
* Fixed (app): quiz "Try again" no longer stacks a second dialog; loading tutor history no longer wipes a reply you're in the middle of; a slow response can no longer paint over a screen you've navigated away from; pressing Back on the course-complete celebration no longer corrupts history; the daily-goal chip now appears in the top bar the first time you set it; XP/streak celebrations work even before your profile finishes loading.
* Fixed (admin): the multiple-choice "correct answer" selector is now a proper radio group; saving Settings no longer resets hidden numeric options to 0; backslashes typed in email/prompt fields are preserved; Course Builder shows the real error message instead of "[object Object]".
* Improved: better dark-theme contrast for success/goal text.

= 1.7.0 =
* New: Adaptive review — the plugin now remembers every question a learner answers wrong and re-asks it: right at the end of the lesson, and again on later days using spaced repetition (a Leitner schedule) until they master it.
* New: "Ask a different way" — a learner can have the AI re-pose a missed question from a fresh angle (a new scenario/wording of the same concept), and, when more than one AI provider is configured, it comes from a different model.
* New: "Practice your mistakes" review session in the app, with a progress bar, instant grading, the correct answer on a miss, and XP for each cleared item — wrong answers loop back to the end of the session.
* New: A dashboard "Review now" card shows how many items are due; finishing a lesson with mistakes drops you straight into a quick review of just those questions.
* New: Settings → Gamification → Adaptive review (toggle + XP per cleared review). New `mahan_reviews` table (DB v3, migrated automatically).

= 1.6.0 =
* New: Real busy states on every action button — a spinner + disabled state while enrolling, submitting a quiz, checking an answer, or completing a lesson (no more bare "…").
* New: Previous / Next lesson buttons show the actual sibling lesson titles.
* New: Leaderboard shows "+X XP → #N" (how much XP to pass the next person) and scrolls your own row into view.
* New: The course back link points where you came from — Explore, a learning path, or My Learning.
* New: Streak stat shows your longest streak, and the flame goes "cold" when today hasn't counted yet.
* New: Search, level filter, and leaderboard period are saved in the URL, so filtered views can be shared or bookmarked.
* Improved: The AI tutor's Send button is clearly disabled while a reply streams, and a 25-second watchdog stops it from hanging forever.
* Improved: Changing your daily goal updates the card in place with a confirmation, instead of reloading the whole dashboard.
* Improved: Session-expired (401) errors go straight to the login screen; "not enrolled" (403) sends you to the course page.
* Improved: The un-enrolled lesson footer button now reads "Back to course" instead of a misleading "Complete lesson".

= 1.5.0 =
* New: Catalog search — find courses instantly by title, subtitle, or category (combined with the level filter, no page reloads).
* New: "Jump back in" card on the dashboard — one tap straight into the next lesson of your in-progress course.
* New: Lesson wayfinding — every lesson shows its unit, "Lesson X of Y", and a course progress bar.
* New: Weekly activity dots (Duolingo-style) on the dashboard, with goal-met checkmarks, powered by the XP log.
* New: Course completion now opens a real celebration (confetti!) instead of a 2.6-second toast.
* New: Completing the last lesson of a unit routes you into the unit quiz instead of silently skipping it.
* New: Locked achievements show progress toward earning them (e.g. "3/10").
* New: Streak extensions and daily-goal completion are celebrated the moment they happen.
* Improved: Quizzes show an answered counter, warn before submitting with unanswered questions, and confirm before closing with answers in progress.
* Improved: Instant back/forward — API responses are cached per session, scroll position is restored, and each view sets the browser tab title.
* Improved: Enrolling drops you straight into lesson 1; a completed course offers "Review course" instead of a misleading "Resume".
* Improved: Log-in links return you to the exact course/lesson you were viewing.
* Improved: Offline-aware errors with auto-recovery, 404s offer a way back instead of a retry loop, and enrollment failures show a message.
* Improved: Tutor input auto-grows, failed messages are restored for retry, the status label is honest, hints expand inline, and toasts are tap-to-dismiss.
* Fixed: Profile form could trigger a native page reload on Enter; missing required fields are now highlighted individually.
* Fixed: An unset daily goal no longer displays as "10 XP" with a "0 / 0" bar.

= 1.4.0 =
* New: Mobile bottom navigation — app-style tabs (Explore / My Learning / Paths / Leaderboard) replace the hidden menu on phones.
* New: On phones and tablets the AI tutor opens as a bottom sheet from a floating button, instead of pushing the lesson down.
* New: Skeleton loading screens that mirror each view's layout (no more bare spinner).
* Improved: Modals — Escape or tapping outside closes them, focus is trapped and restored, and on phones they slide up as bottom sheets with full-width actions.
* Improved: Touch targets meet the 44px guideline on touch devices; lesson footer buttons reflow on small screens.
* Improved: Keyboard focus outlines (:focus-visible), screen-reader labels on icon-only buttons, live-region toasts, and reduced-motion support.
* Fixed: Modals and toasts now pick up the theme colors (including dark mode) — they previously rendered with transparent backgrounds.
* Fixed: The profile form's Save button re-enables correctly after a failed save.

= 1.3.0 =
* New: XP log — every award recorded with a reason; powers exact weekly leaderboards and reports.
* New: Daily XP goals (learner-adjustable) with HUD progress.
* New: Streak freezes — earned automatically, consumed to protect streaks on missed days.
* New: Streak XP multiplier (configurable % per week of streak, capped +50%).
* New: Progressive (RPG-style) level curve option.
* New: 12 more achievements (21 total) covering quizzes, perfect scores, exercises, and learning paths — with instant unlock notifications.
* New: Weekly leaderboard tab + your exact rank even outside the top 20.
* Fixed: level-up celebrations now actually fire (server never sent `leveled_up`).

= 1.2.0 =
* New: End-of-unit quizzes — edit per-unit quizzes in the Course Builder; instant grading with a passing score and XP reward; a quiz card and taking flow in the app.
* New: Learning paths — a `mahan_path` type that groups courses into an ordered program with aggregate progress; a Paths catalog and detail view.
* New: Email notifications — welcome/enrollment, course completion, new achievement, and an optional daily streak reminder; editable templates with placeholders.
* New: Admin Reports page — KPIs, per-course completion table, top learners, recent activity, and CSV export.

= 1.1.0 =
* New: Visual drag-and-drop Course Builder on the course editor (units + lessons in one place; inline add/rename/duplicate/delete; per-lesson type/XP/duration).
* New: AI authoring assistant — generate course outcomes, draft lesson content, and generate exercises from lesson material.
* New: True/False and Fill-in-the-blank exercise types.
* New: Achievements/badges with a dashboard showcase.
* New: Opt-in public XP leaderboard.
* New: Configurable level titles.
* New: Per-course featured flag, promo video, prerequisite, and printable completion certificate.
* Docs: added an admin guide and developer reference under /docs.

= 1.0.0 =
* Initial release.
