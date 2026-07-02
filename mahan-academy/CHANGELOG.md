# Changelog

All notable changes to **Mahan Academy** are documented here. The format is
loosely based on [Keep a Changelog](https://keepachangelog.com/), and the
project follows semantic-ish versioning.

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
