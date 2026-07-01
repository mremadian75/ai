# Changelog

All notable changes to **Mahan Academy** are documented here. The format is
loosely based on [Keep a Changelog](https://keepachangelog.com/), and the
project follows semantic-ish versioning.

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
