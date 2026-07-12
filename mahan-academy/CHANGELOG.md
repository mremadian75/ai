# Changelog

All notable changes to **Mahan Academy** are documented here. The format is
loosely based on [Keep a Changelog](https://keepachangelog.com/), and the
project follows semantic-ish versioning.

## [1.7.1]

Hardening & bug-fix release following a full-codebase audit (four parallel
review passes: PHP correctness/security, SPA JS, REST↔SPA contract, admin/
assets). No schema change (DB version stays 3).

### Security

- **Server-side sequential gating** — `Mahan_Courses::lesson_locked()` is now
  enforced in `GET /lesson`, `POST /progress`, and `POST /exercise` (403
  `locked`). Previously locking was advisory (client-only), so an enrolled
  learner could script requests to skip content, instantly complete a course,
  and trigger the certificate / `first_course` badge.
- **Course Builder AJAX authorization** — the shared guard now requires
  `edit_post` on the *specific* course (not just the generic `edit_posts`),
  `save_structure` checks `edit_post` per lesson before rewriting its meta,
  and new/duplicated lessons are created as drafts for roles that can't
  `publish_posts`. Stops a Contributor from publishing lessons or wiping
  another author's unit quizzes.
- **REST nonce always required** — `perm_logged_in` no longer treats the
  `X-WP-Nonce` header as optional.
- **CSV formula injection** — report export now prefixes leading `= + - @`
  (and tab/CR) in author-controlled fields.

### Fixed

- **Review XP farming** — `Mahan_Reviews::grade()` awards XP only when the
  item was actually due (a correct answer pushes `due_at` into the future, so
  repeated submissions can't farm); box-0 items are due immediately so the
  end-of-lesson re-drill still earns XP. AI variants are single-use (their
  transient is deleted on grade). `due()` now shares the `box < MASTERED`
  predicate with `counts()`, so the dashboard "due" figure and the served
  session agree (and mastered items aren't re-served).
- **Duplicate course-completion** — `mahan_course_completed` (and its email)
  only fires on the transition to complete, not on every redundant
  `complete_lesson` call.
- **Premature 100%** — `course_progress_pct()` (and path progress) floor the
  percentage and only return 100 when every lesson is done, so a 200-lesson
  course no longer rounds up to "complete" (and offers the certificate) one
  lesson early.
- **Streak bonus ordering** — `record_activity()` now runs before `add_xp()`
  on every earn path, so the streak bonus reflects today's streak and a
  lapsed streak is reset before the bonus applies (no last inflated bonus on
  a comeback).
- **AI grading** — a model returning the JSON string `"false"` is parsed with
  `FILTER_VALIDATE_BOOLEAN` instead of a bare `(bool)` cast (which was `true`).
- **SPA:** quiz "Try again" force-closes the results dialog (no more stacked
  modals); `loadChat` won't wipe an in-flight tutor exchange; a stale async
  response can't paint over a view you've navigated away from (nav-sequence
  guard); Back on the completion modal closes it silently instead of running
  its navigating `onClose` inside the popstate handler; `celebrateXp` seeds
  the HUD when `/me` hasn't resolved yet; setting a first daily goal rebuilds
  the top bar so its chip appears; the `/me` user object carries `name` so the
  HUD avatar keeps its alt text.
- **Admin:** the multiple-choice correct-answer radios share a `name` (proper
  radio group); field-less registered options (`hearts_max`, `ai_cache_ttl`,
  `hearts_enabled`) are preserved on Settings save instead of resetting to 0;
  the settings sanitizer no longer double-unslashes (backslashes survive); the
  AI test message isn't double-encoded; Course Builder surfaces the real error
  string instead of `[object Object]`; the AI-author lesson-id data attribute
  is read correctly.

### Improved

- Dark-theme contrast for success / goal / correct-answer text.

## [1.7.0]

Adaptive review — an intelligent spaced-repetition system that tracks which
questions each learner gets wrong and brings them back until they're mastered.
**DB version 3** — adds the `mahan_reviews` table (migrated automatically via
`dbDelta`).

### Added

- **Review engine** (`class-mahan-reviews.php`). Every graded *deterministic*
  question (multiple choice / true-false / fill-in-the-blank), from both
  exercises and unit quizzes, is fed to `Mahan_Reviews::record()`. A wrong
  answer enters a Leitner-box queue with a self-contained snapshot of the
  question (so it can be re-asked and graded independently of the lesson); a
  correct answer advances the box and pushes the next review further out
  (10 min → 1 → 3 → 7 → 16 → 35 → 75 days). AI-graded open answers are never
  auto-queued (they can't be re-graded deterministically).
- **End-of-lesson review.** Completing a lesson that had mistakes drops the
  learner into a quick review of exactly those questions before moving on
  (`/progress` now returns `review_pending`).
- **Spaced review hub.** A dashboard "Practice your mistakes" card shows how
  many items are due (`/me` stats now include a `reviews` summary) and opens
  a review session that pulls due items across all courses.
- **Review session UI** — one question at a time with a progress bar, instant
  grading, the correct answer shown on a miss, XP per cleared item, and wrong
  answers re-queued to the end of the session.
- **"Ask a different way"** (`POST /review/variant`). The AI re-poses a missed
  question testing the same concept from a new angle; when more than one
  provider is configured it is generated by a *different* provider/model than
  the active one. The variant's answer is stashed server-side (a 30-min
  transient) so the submission is graded against it.
- **REST**: `GET /reviews`, `POST /review`, `POST /review/variant`.
- **Settings → Gamification → Adaptive review**: enable/disable + XP per
  cleared review (`review_enabled`, `review_xp`).
- Fires `mahan_review_cleared` ( `$user_id, $review_id, $box` ).

## [1.6.0]

Second UX pass, clearing the remaining items from the v1.5.0 best-practice
audit backlog. Front-end only — no schema or REST changes.

### Added

- **Real in-flight button states** — a shared `setBusy()` helper puts a
  spinner + `aria-busy` on any async action (enroll, quiz submit, exercise
  check, complete lesson) and keeps the button width so nothing jumps,
  instead of a bare "…" or no feedback at all.
- **Sibling titles in the lesson footer** — Previous / Next buttons show the
  actual adjacent lesson titles, not just generic labels.
- **Leaderboard rank targets** — each visible "you" row shows "+X XP → #N"
  (the gap to the next rank up), and the caller's row scrolls into view when
  it would otherwise be off-screen.
- **Origin-aware course back link** — the course hero back link points at
  wherever the learner arrived from (Explore, a learning path, or My
  Learning) via a `from` navigation param.
- **Streak polish** — the dashboard streak stat shows the longest streak and
  switches to a "cold" candle with a "study today to keep it" nudge when
  today hasn't counted yet.
- **Shareable filtered views** — catalog search + level filter and the
  leaderboard period are persisted to the URL via `replaceState`, and
  restored on load, so a filtered view can be bookmarked or shared.

### Improved

- The AI tutor's Send button shows a busy spinner and is disabled while a
  reply streams (no more silently-swallowed clicks), and a 25-second
  watchdog aborts a stalled stream so the tutor can't deadlock.
- Changing the daily goal updates the card in place with a confirmation
  toast instead of re-rendering (and refetching) the whole dashboard.
- `errorBox` routes a 401 straight to the login gate and a 403
  `not_enrolled` to the course page, instead of a generic retry.
- The un-enrolled lesson footer's primary button reads "Back to course"
  (which is what it does) instead of "Complete lesson".
- Stale-while-revalidate repaints no longer steal focus from an active
  input (the fresh paint is deferred while the learner is typing).

## [1.5.0]

UX overhaul driven by a five-lens best-practice audit of the learner app
(journey friction, system-status feedback, forms & input, motivation loops,
navigation & wayfinding). Front-end + additive REST fields; no schema changes.

### Added

- **Catalog search** — instant client-side search over title/subtitle/
  category, combined with the level chips (which no longer refetch and flash
  a skeleton). Empty results offer a one-tap "Clear filters".
- **"Jump back in"** — the dashboard's hero card deep-links straight into
  the next lesson of the most recent in-progress course (`/me` now returns
  `next_lesson_id`/`next_lesson_title` per course). Enrolling also drops the
  learner directly into lesson 1 instead of re-rendering the course page.
- **Lesson wayfinding** — the lesson player shows the unit name,
  "Lesson X of Y", and a slim course-progress bar (`/lesson` now returns
  `position` + `course_pct`).
- **Weekly activity dots** — Duolingo-style last-7-days strip on the
  dashboard (label, active, goal-met, today) computed from the XP log
  (`stats.week` on `/me`).
- **Course-completion celebration** — a confetti modal replaces the
  2.6-second toast + forced redirect; closing it returns to the course.
- **Unit quiz in the flow** — completing the last lesson of a unit with a
  quiz opens that quiz (`/lesson` returns `unit_quiz`), instead of the
  happy path silently skipping every quiz.
- **Badge progress** — locked achievements show "3/10"-style progress bars
  (`for_user` now returns `need` + `progress`).
- **Micro-celebrations** — extending a streak and reaching the daily goal
  now toast at the moment they happen (before/after stats comparison).
- **Session cache + stale-while-revalidate** — GET responses are cached in
  memory and repainted only when fresh data differs; any POST clears the
  cache. Back navigation is instant, chips/filters no longer flash.
- **Browser integration** — per-view `document.title`, scroll-position
  restoration on back/forward, and login links that redirect back to the
  exact deep link (`loginBase` in `MahanData`).

### Improved

- Quiz taking: an "N/M answered" counter, a two-tap guard before submitting
  with unanswered questions (they're graded wrong), and a confirm-on-close
  guard so a stray Escape can't destroy answers in progress.
- Errors: offline is detected and labeled (with auto-retry when the
  connection returns), 404s offer "Back to catalog" instead of an infinite
  retry loop, and failed enrollments show a toast instead of failing silently.
- Tutor: the input auto-grows up to 120px, a failed send restores the typed
  message, the status label reflects real availability instead of a
  hardcoded "online", and an "Enter to send" hint shows on keyboard devices.
- Exercise hints expand inline (persistent, re-readable) instead of a
  vanishing toast; toasts are tap-to-dismiss and celebrations linger longer.
- Boot no longer serializes two round-trips: the view renders immediately
  while `/me` hydrates the HUD in parallel (with a skeleton placeholder
  instead of a misleading "Log in" button).
- A completed course's CTA is "✓ Review course" instead of "Resume" (which
  reopened lesson 1); lesson completion also fires previously-dropped
  `leveled_up`/`new_badges` payloads.

### Fixed

- The profile gate form could trigger a native page reload when pressing
  Enter in a text field; required-field errors now highlight the exact
  missing fields and labels are associated with their inputs (`for`/`id`).
- An unset daily goal rendered as "10 XP" with a "0 / 0 XP" bar; it now
  shows a placeholder and invites the learner to pick a goal.

## [1.4.0]

UI/UX and responsive overhaul of the learner app, following mobile-first best
practices (app-style bottom navigation, bottom sheets, skeleton screens,
WCAG-oriented accessibility). Front-end only — no data-model or API changes.

### Added

- **Mobile bottom navigation.** Under 640px the top menu used to be hidden
  entirely; it's now replaced by a fixed, thumb-reachable tab bar (Explore /
  My Learning / Paths / Leaderboard) with icons, active state,
  `aria-current`, and safe-area padding for notched devices.
- **Tutor FAB + bottom sheet.** On screens below 980px the AI tutor no longer
  pushes the lesson content down — a floating action button opens it as a
  slide-up bottom sheet with a scrim, a close button, and Escape support.
- **Skeleton loading.** Every view now shows a shimmer skeleton that mirrors
  its real layout (card grid, course detail, lesson + tutor panel, dashboard
  stats, leaderboard rows) instead of a generic spinner, announced to screen
  readers via `role="status"`.
- **Shared accessible modal helper.** All dialogs (unit quiz, certificate,
  profile gate) now get `role="dialog"` / `aria-modal`, a focus trap,
  Escape-to-close, click-outside-to-close, and focus restoration to the
  triggering element. On phones modals present as bottom sheets with
  full-width actions.

### Improved

- Touch targets meet the 44px guideline on coarse pointers (buttons, chips,
  answer options, tutor input/send); the lesson footer reflows with a
  full-width primary action on small screens.
- Keyboard focus is visible everywhere via `:focus-visible` outlines.
- Icon-only controls (tutor send, tutor close, FAB) carry `aria-label`s;
  toasts are a polite live region; nav landmarks are labeled.
- `prefers-reduced-motion` disables shimmer, pop, slide, and hover-lift
  animations.
- Compact HUD, hero, section, and stat spacing on small screens.

### Fixed

- Modals and toasts rendered with transparent backgrounds: the theme's CSS
  variables were defined on the app container, but overlays were appended to
  `<body>` where the variables don't resolve. Overlays now mount inside the
  app root (dark mode included) and toast colors read the `:root`-level vars.
- The profile form's Save button could stay disabled after a failed save
  (the handler read `e.currentTarget` inside an async callback, where it is
  already `null`).
- Closing a graded quiz with Escape / outside click now refreshes the course
  view so the new best score shows, same as the Done button.

## [1.3.0]

Gamification overhaul, modeled on the patterns proven by mature open-source
systems (GamiPress/myCred point logs, BadgeOS tiered achievements,
Duolingo-style goals, freezes, and weekly boards). **DB version 2** — adds the
`mahan_xp_log` table plus `freezes` / `daily_goal` columns on stats (migrated
automatically via `dbDelta` on upgrade).

### Added

- **XP log** — every award is appended to `mahan_xp_log` with a reason
  (`lesson` / `exercise` / `quiz`) and reference id. Weekly leaderboards, daily
  goals, and the new "XP this week" report KPI are computed exactly from it.
- **Daily XP goal** — site default (Settings) + per-learner override from a
  dashboard card (`POST mahan/v1/goal`). The HUD shows 🎯 today's progress and
  flips to ✅ on completion.
- **Streak freezes** — earn 1 freeze per N consecutive days (default 7, max
  held 2); missed days consume freezes automatically so the streak survives.
  Fires `mahan_streak_frozen`. Configurable / can be disabled.
- **Streak XP multiplier** — optional bonus per full week of streak (default
  10%, capped +50%), applied inside `add_xp` and reflected in awarded amounts.
- **Progressive level curve** — optional RPG-style mode where level N costs
  N × "XP per level" (linear stays the default; boundaries unit-verified).
- **12 new achievements** (21 total) with new metrics: quizzes passed, perfect
  quizzes, exercises solved, learning paths completed, plus higher tiers for
  lessons/courses/streaks/levels/XP. Existing badge keys unchanged, so earned
  badges are preserved.
- **Live achievement notifications** — grading/progress responses now carry
  `new_badges`; the SPA toasts each unlock instead of waiting for the next
  dashboard visit.
- **Weekly leaderboard** — `GET mahan/v1/leaderboard?period=week|all` with
  This week / All time tabs, and a "me" row with the caller's exact rank when
  they're outside the top 20.

### Fixed

- Level-up celebrations now fire: the front-end always checked `leveled_up`
  on grading responses but the server never sent it. `add_xp` now reports it
  (with the level title in the toast).

## [1.2.0]

### Added

- **End-of-unit quizzes** (`class-mahan-quizzes.php`). Each course unit can carry
  a quiz, edited in the Course Builder (a "Quiz" button per unit opens a modal
  editor). Questions reuse the deterministic types (multiple choice, true/false,
  fill-in-the-blank) and are graded instantly against a configurable passing
  score; passing awards XP once. In the app a quiz card appears at the end of a
  unit and opens a taking flow with per-question results and retry. Quizzes are
  stored on the course keyed by unit title and travel with the unit in the
  builder, so renaming a unit keeps its quiz. Attempts reuse the existing table
  (no schema change).
- **Learning paths** (`mahan_path` CPT, `class-mahan-paths.php`). Group courses
  into an ordered program. An admin course picker (drag-sortable) sets the order;
  the app shows a Paths catalog and a path detail with each course's progress
  and the path's overall completion. Non-gating.
- **Email notifications** (`class-mahan-emails.php`). Templated HTML emails on
  enrollment, course completion, and new achievement, plus an optional daily
  streak reminder via wp-cron. Editable subject/body per type with
  `{{placeholders}}`, configurable From name/email, and a Settings → Emails tab.
- **Admin Reports** (`class-mahan-reports.php`). A Reports page with overview
  KPIs (learners, enrollments, completions, active today/week, XP, lessons,
  exercise accuracy, quiz pass rate), a per-course table, top learners, recent
  activity, and CSV export.

### Changed

- Course REST units carry a learner-facing quiz summary; new `GET/POST
  mahan/v1/quiz`, `GET mahan/v1/paths`, `GET mahan/v1/path/{id}`.
- `uninstall.php` cleans up the new options, `mahan_badges` user meta, the daily
  cron, and the `mahan_path` CPT.
- Fires `mahan_quiz_passed` (user, course, unit, score).

## [1.1.0]

### Added — Authoring

- **Visual Course Builder** (`class-mahan-course-builder.php`,
  `assets/js/course-builder.js`, `assets/css/course-builder.css`).
  A full-width "Curriculum builder" meta box on the Course edit screen. Build
  the whole curriculum in one place instead of creating lessons one by one and
  hand-numbering their order:
  - Add units and lessons inline.
  - Drag-and-drop to reorder lessons within a unit and across units, and to
    reorder units themselves.
  - Per-lesson quick settings (type, XP, estimated minutes) without leaving the
    course.
  - Rename units inline; duplicate, delete, or open a lesson's full block editor
    ("Edit content").
  - Backed by nonce-protected admin-ajax endpoints. Lessons remain ordinary
    `mahan_lesson` posts — no data-model change.
- **AI authoring assistant** (`class-mahan-ai-author.php`,
  `assets/js/ai-author.js`). Uses the configured AI provider to:
  - Generate "What you'll learn" outcomes for a course from its title +
    description.
  - Draft a lesson (clean HTML) from a topic, with copy-to-clipboard.
  - Generate a mix of exercises from a lesson's content, loaded straight into
    the exercise builder.

### Added — Learning

- **Two new exercise types**: `true_false` (instant) and `fill_blank`
  (text answer graded against an expected value + optional accepted synonyms,
  with a case-sensitivity toggle). Existing types unchanged.

### Added — Gamification & courses

- **Achievements / badges** (`class-mahan-badges.php`): nine milestone badges
  (lessons, courses, streaks, levels, XP) awarded automatically and shown on the
  dashboard.
- **Opt-in leaderboard**: `GET mahan/v1/leaderboard` + a new SPA view and nav
  item (top 20 by XP, current user highlighted).
- **Configurable level titles** (e.g. Novice → Explorer → Expert).
- **Per-course options**: featured flag (sorts first + ribbon), promo video
  (YouTube / Vimeo / direct file), prerequisite (soft note), and a printable
  completion certificate (per-course toggle, gated by a global setting).

### Changed

- Catalog now sorts featured courses first.
- `GET mahan/v1/course/{id}` returns `promo_video`, `prerequisite`,
  `certificate`, and `completed`.
- `GET mahan/v1/me` returns `badges` and the `leaderboard` flag.
- Settings → Gamification gained: achievements, leaderboard, certificates, and
  level titles.

## [1.0.0]

- Initial release: standalone courses & lessons (2 CPTs + 6 tables),
  provider-agnostic AI client (Anthropic / OpenAI / Google), real-time
  SSE tutor with REST fallback, interactive exercises (multiple choice +
  AI-graded open answers), schema-driven learner profile, XP/level/streak
  gamification, and a Coursera-meets-Duolingo single-page front-end.
