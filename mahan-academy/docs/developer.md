# Mahan Academy — Developer Reference

Architecture, data model, hooks, and the REST/AJAX surface. PHP targets 7.4+,
WordPress 6.0+. No build step — vanilla JS and plain CSS.

---

## Directory layout

```
mahan-academy/
├── mahan-academy.php            # bootstrap: constants, requires, lifecycle hooks
├── uninstall.php                # optional data removal (opt-in)
├── includes/
│   ├── class-mahan-logger.php           # debug logging
│   ├── class-mahan-utils.php            # time, meta, JSON, placeholder helpers
│   ├── class-mahan-db.php                # 6 custom tables + AI cache
│   ├── class-mahan-settings.php          # options + defaults + profile schema
│   ├── class-mahan-cpt.php               # mahan_course / mahan_lesson + taxonomy
│   ├── class-mahan-profile.php           # schema-driven learner profile (user meta)
│   ├── class-mahan-courses.php           # course/lesson structure + meta keys
│   ├── class-mahan-gamification.php      # XP, levels, streaks, level titles
│   ├── class-mahan-badges.php            # achievements
│   ├── class-mahan-emails.php            # notification emails + daily cron
│   ├── class-mahan-enrollment.php        # enrollments
│   ├── class-mahan-progress.php          # lesson/course progress
│   ├── class-mahan-ai.php                # provider-agnostic completions
│   ├── class-mahan-exercises.php         # grading (instant + AI)
│   ├── class-mahan-quizzes.php           # end-of-unit quizzes
│   ├── class-mahan-reviews.php           # adaptive review (spaced repetition + AI variants)
│   ├── class-mahan-paths.php             # learning paths
│   ├── class-mahan-ai-stream.php         # SSE tutor + non-streaming fallback
│   ├── class-mahan-rest.php              # mahan/v1 REST API
│   ├── class-mahan-front.php             # [mahan_academy] SPA mount + assets
│   ├── class-mahan-course-builder.php    # admin: curriculum builder + quiz editor + AJAX
│   ├── class-mahan-ai-author.php         # admin: AI authoring + AJAX
│   ├── class-mahan-reports.php           # admin: analytics + CSV export
│   ├── class-mahan-meta-boxes-course.php # admin: course fields
│   ├── class-mahan-meta-boxes-lesson.php # admin: lesson fields + exercise builder
│   ├── class-mahan-meta-boxes-path.php   # admin: path course picker
│   ├── class-mahan-admin.php             # admin: menu, settings, reports, assets
│   └── class-mahan-plugin.php            # orchestrator (activate/deactivate/init)
└── assets/
    ├── js/{app.js, admin.js, course-builder.js, ai-author.js, path-admin.js}
    └── css/{app.css, admin.css, course-builder.css}
```

---

## Data model

**Content** lives in three CPTs:

- `mahan_course` — the course. Meta keys (constants on `Mahan_Courses`):
  `M_SUBTITLE`, `M_LEVEL`, `M_EST_HOURS`, `M_OUTCOMES`, `M_FEATURED`,
  `M_PROMO_VIDEO`, `M_PREREQ`, `M_CERTIFICATE`, `M_UNIT_QUIZZES` (per-unit
  quizzes keyed by unit title).
- `mahan_lesson` — a lesson, linked to its course by meta.
  Keys: `M_COURSE_ID`, `M_UNIT`, `M_UNIT_ORDER`, `M_ORDER`, `M_XP`, `M_EST_MIN`,
  `M_TYPE`, `M_EXERCISES` (JSON array of exercise definitions).
- `mahan_path` — a learning path. Meta (on `Mahan_Paths`): `M_COURSES` (ordered
  course IDs), `M_SUBTITLE`.

**Dynamic per-user data** lives in eight custom tables (see `Mahan_DB`):
`enrollments`, `progress`, `attempts`, `stats`, `chat`, `ai_cache`,
`xp_log` (append-only: every XP award with `amount`, `reason`, `ref_id`,
`created_at` — powers weekly leaderboards, daily goals, and reports), and
`reviews` (the adaptive-review queue: one row per missed question with a
Leitner `box`, `due_at`, `reps`/`lapses`, a JSON `snapshot` of the question,
and `last_xp_date` — added in DB v3, `last_xp_date` in DB v4). The `stats`
table also carries `freezes` and `daily_goal` (added in DB v2 via `dbDelta`).

> Review XP is capped at once per item per day (`last_xp_date`): a wrong
> answer makes an item immediately due again (so the end-of-lesson re-drill
> still rewards a correct answer), but a wrong→correct loop can't farm XP.

**Learner profile** is stored in user meta `mahan_profile` (schema-driven; see
`Mahan_Profile` and Settings → Profile Form). **Badges** are stored in user meta
`mahan_badges`.

> The 1.1.0 features (badges, level titles, course options) added **no** new
> tables — `MAHAN_DB_VERSION` is unchanged.

---

## AI providers

`Mahan_AI::complete( $messages, $opts )` normalizes messages to
`[{role, content}]` and dispatches to Anthropic, OpenAI, or Google based on
settings (overridable per call). `Mahan_AI_Stream` handles the SSE tutor via a
cURL relay, with a non-streaming `reply()` used by the REST fallback.

---

## REST API (`mahan/v1`)

| Method & route | Auth | Purpose |
| --- | --- | --- |
| `GET /catalog` | public | Course cards (featured first). |
| `GET /course/{id}` | public | Course detail: outcomes, description, units, promo video, prerequisite, certificate, progress. |
| `GET /lesson/{id}` | logged-in | Lesson content, exercises (answers stripped), siblings, stats, `position` (index/total/unit), `course_pct`, and `unit_quiz` (when the lesson closes a unit that has a quiz). |
| `POST /enroll` | logged-in | Enroll in a course. |
| `POST /progress` | logged-in | Mark a lesson complete (awards XP, recomputes course %). |
| `POST /exercise` | logged-in | Grade a submitted answer. |
| `GET /quiz` | logged-in | Fetch a unit quiz (answers stripped) + best attempt. |
| `POST /quiz` | logged-in | Submit a unit quiz; graded, awards XP on first pass. |
| `POST /tutor` | logged-in | Non-streaming tutor reply (SSE fallback). |
| `GET /chat` | logged-in | Tutor chat history for a lesson. |
| `GET /me` | logged-in | User, stats (incl. `week` — last-7-days activity from the XP log), enrolled courses (incl. `next_lesson_id`/`next_lesson_title` for in-progress ones), badges (incl. `need`/`progress`), leaderboard flag. |
| `GET /leaderboard` | public* | Top-20 by XP; `?period=week\|all`; includes the caller's "me" rank (*only when enabled). |
| `POST /goal` | logged-in | Save the learner's daily XP goal. |
| `GET /paths` | public | Learning paths with aggregate progress. |
| `GET /path/{id}` | public | One path with its ordered courses + progress. |
| `GET/POST /profile` | logged-in | Read/save the learner profile. |
| `GET /reviews` | logged-in | Due review items (answers stripped) + queue counts. `scope=lesson&lesson_id=N` returns that lesson's misses. |
| `POST /review` | logged-in | Grade a review answer (against the snapshot or an AI variant `variant_token`); reschedules + awards XP. |
| `POST /review/variant` | logged-in | AI re-poses a missed question a different way (from another provider/model when available); returns the variant + a `variant_token`. |

The streaming tutor runs over admin-ajax (`action=mahan_tutor_stream`) as SSE,
not REST.

Admin AJAX actions (nonce-protected, capability-checked):
`mahan_cb_*` (course builder), `mahan_ai_*` (authoring), `mahan_test_ai`.

---

## Hooks

Actions fired by the plugin (use for integrations):

| Action | Args | When |
| --- | --- | --- |
| `mahan_enrolled` | `$user_id, $course_id` | On enrollment. |
| `mahan_lesson_completed` | `$user_id, $lesson_id, $course_id` | Lesson marked complete. |
| `mahan_course_completed` | `$user_id, $course_id` | Course reaches 100%. |
| `mahan_level_up` | `$user_id, $new_level` | Level increases. |
| `mahan_streak_updated` | `$user_id, $streak` | Daily streak changes. |
| `mahan_streak_frozen` | `$user_id, $freezes_used` | Freezes covered missed day(s). |
| `mahan_badge_awarded` | `$user_id, $badge_key` | A badge is earned. |
| `mahan_quiz_passed` | `$user_id, $course_id, $unit, $score` | A unit quiz is passed. |
| `mahan_exercise_correct` | `$user_id, $lesson_id, $key` | First correct answer to an exercise. |
| `mahan_review_cleared` | `$user_id, $review_id, $box` | A due review item answered correctly (box advanced). |

Filters:

| Filter | Value | Purpose |
| --- | --- | --- |
| `mahan_badge_defs` | `array` | Add/modify badge definitions. |

### Example — add a custom badge

```php
add_filter( 'mahan_badge_defs', function ( $defs ) {
    $defs[] = array(
        'key'    => 'xp_10000',
        'icon'   => '🚀',
        // one of: lessons, courses, streak, level, xp,
        // quizzes_passed, perfect_quizzes, exercises_correct, paths_completed
        'metric' => 'xp',
        'need'   => 10000,
        'title'  => 'Legend',
        'desc'   => 'Earn 10,000 XP',
    );
    return $defs;
} );
```

### Example — react to course completion

```php
add_action( 'mahan_course_completed', function ( $user_id, $course_id ) {
    // e.g. email the learner, issue a coupon, sync to a CRM…
}, 10, 2 );
```

---

## Front-end (SPA)

`assets/js/app.js` is a dependency-free single-page app mounted on
`#mahan-app`. Views: catalog, course (with unit quiz cards), lesson (tutor panel
+ exercises), dashboard (with badges), leaderboard, paths, and path detail.
Config + i18n are injected via `wp_localize_script` as `window.MahanData`. Theme
colors are CSS variables (`--mahan-primary`, `--mahan-accent`) set inline from
settings.

The stylesheet (`assets/css/app.css`) is token-driven. `.mahan-app` defines the
design tokens — surfaces, `--m-muted`/`--m-border`, a **semantic colour system**
(`--m-danger`/`--m-danger-solid`/`--m-warning`/`--m-warning-text`/`--m-info`),
radius scale (`--m-radius-sm/-/-lg`), elevation (`--m-shadow-sm/-/-lg`), and a
`--m-ring` focus token, a **motion scale** (`--m-ease`/`--m-ease-out`,
`--m-dur-1/2/3`) and a **spacing scale** (`--m-space-1..6`, `--m-section-gap`) —
and `.mahan-theme-dark` overrides them (including `color-scheme: dark` so native
controls follow the theme, and lighter red/amber so status text stays legible).

Colour has two families: the raw **fill** tokens (`--m-primary`, `--m-accent`,
`--m-warning`, `--m-info`) for solid backgrounds/borders with white text, and
the **on-surface text** variants (`--m-primary-text`, `--m-accent-text`,
`--m-warning-text`, `--m-info-text`, plus `--m-accent-fill` for white-glyph
circles) which are theme-tuned for AA contrast — a fill shade is often too light
as small text on the low-contrast side of a theme. Use the `-text` token when a
brand/accent colour is text or an icon; use the fill token for backgrounds.
Status colours should reference tokens, not literals, so both themes stay
correct. Live-updating numbers use `font-variant-numeric: tabular-nums` to avoid
width jitter.

Responsive & accessibility behaviors (1.4.0):

- **Navigation** — the desktop pill nav collapses below 640px into a fixed
  bottom tab bar (`bottomNav()`); both are built from the same `navItems()`
  list and carry `aria-current` on the active item.
- **Tutor** — a sidebar ≥980px; below that it becomes a bottom sheet toggled
  by a floating button (`toggleTutor()`), with scrim, close button, and
  Escape support.
- **Loading** — `loadingShell(kind)` renders per-view skeletons
  (`cards | detail | article | rows | dash`) with `role="status"`.
- **Modals** — all dialogs go through `openModal(dialog, opts)`, which
  provides `role="dialog"`, `aria-modal`, a focus trap, Escape/overlay
  close, focus restoration, an `onClose` hook, and a `beforeClose` veto
  (used by the quiz to guard in-progress answers). Overlays mount inside
  `#mahan-app` so the theme's CSS variables (including dark mode) resolve.
- **CSS** — `:focus-visible` outlines, ≥44px touch targets under
  `(pointer: coarse)`, bottom-sheet modals under 640px, and a
  `prefers-reduced-motion` block live in the "v1.4.0" layer at the end of
  `app.css`.

UX behaviors (1.5.0):

- **Session cache** — `cachedApi(path, skeleton, paint, retry)` paints
  cached GET responses instantly and repaints only when the fresh response
  differs (`JSON.stringify` compare); any non-GET `api()` call clears the
  whole cache.
- **Browser integration** — every view sets `document.title`; `go()` stamps
  the outgoing history entry with its scroll offset and `popstate` restores
  it once non-skeleton content mounts; `history.scrollRestoration` is
  `manual`.
- **Deep-link-safe login** — `loginHref()` builds a `redirect_to` back to
  the current SPA URL from `MahanData.loginBase`.
- **Flow** — enrolling jumps straight into lesson 1; completing the last
  lesson of a unit opens its quiz; finishing a course opens a confetti
  celebration modal; a completed course's CTA becomes "Review course".
- **Errors** — `errorBox(retry, err)` distinguishes 401 (→ login gate),
  403 `not_enrolled` / `locked` (→ course page), 404 (→ "Back to catalog"),
  and offline (with an auto-retry on the `online` event).

Sequential gating is enforced server-side by `Mahan_Courses::lesson_locked()`
(the single source of truth, mirroring the course view's lock rule): `GET
/lesson`, `POST /progress`, and `POST /exercise` return `403 { error:
'locked' }` for a lesson whose predecessor isn't complete.

UX behaviors (1.6.0):

- **`setBusy(btn, label)`** — puts a spinner + `aria-busy` on any async
  button and returns a `restore(html?)` fn; used by enroll, quiz submit,
  exercise check, and complete-lesson.
- **Origin-aware navigation** — `go()`/`urlFor()` carry a `from` param
  (`dashboard`, `paths`, or `path:<id>`); `courseBackLink()` resolves it to
  the right back destination and label.
- **`syncUrl()`** — mirrors transient view state (catalog `q`/`level`,
  leaderboard `period`) into the URL via `replaceState` (no history entry);
  `parseUrl()` restores them, so filtered views are shareable.
- **Tutor** — `sendToTutor()` disables the Send button while streaming and
  arms a 25s watchdog that `AbortController`-aborts a stalled stream;
  `streamTutor()` takes an optional `signal` and ignores `AbortError`.
- **Leaderboard** — rows show a "+X XP → #N" gap to the next rank; the
  caller's row is scrolled into view only when off-screen.

UX behaviors (1.8.0):

- **`fmt(tpl, …args)`** — sprintf-lite (`%s` and positional `%1$s`) so
  translatable strings stay whole sentences; **`plural(n, oneKey, manyKey, …)`**
  for count labels ("1 lesson" vs "3 lessons"); **`tv(key, fallbackKey, fb, seed)`**
  rotates through an i18n *array* (e.g. `correctVariants`, `levelUpVariants`) so
  reinforcement copy stays fresh — the arrays survive `wp_localize_script`
  because it JSON-encodes non-scalar values.
- **Live HUD** — `Mahan_Gamification::hud()` now includes `reviews`
  (`Mahan_Reviews::counts`) on every stats payload, so `refreshHud()` can keep
  the nav reviews-due badge and the cold-streak flame correct after any grading
  action without a `/me` refetch. `navBadge()` renders even at 0 (hidden via
  `.mahan-nav-count:empty`) so it can be patched in place.
- **Hero copy** — `MahanData.heroTitle` / `heroSub` come from the
  `hero_title` / `hero_subtitle` settings (Appearance tab), falling back to a
  translatable default.
- **Lesson payload** — `/lesson` returns `est_min` (estimated minutes) and
  `position`; `Mahan_Reviews::public_item()` returns a `context`
  ("Course · Lesson") string for the mixed cross-course review queue.
- **Optimistic UI** — `dailyGoalCard`'s select swaps the card immediately on
  change and reconciles/rolls-back against the server response.
- **Review engine (client)** — `runReviewSession()` re-queues the *original*
  when an AI variant is missed, splices near-end repeats so nothing is re-asked
  back-to-back, offers "Revisit lesson", supports number-key answers, and
  reports an honest (non-celebratory) summary with a "Review skipped" re-run.
- **Accessibility** — `.mahan-sr-only` utility; week dots (`role="img"`),
  badges, and graded options carry composed labels/cues; the tutor has a
  once-per-reply `role="status"` announcer; unit titles are `<h3>` and the
  catalog carries a visually-hidden `<h2>`.

Quiz attempts reuse the `attempts` table (`type = 'quiz'`, `lesson_id = 0`,
`exercise_key = 'quiz:<md5(unit)>'`), so v1.2.0 still adds no tables.

---

## Coding conventions

- WordPress escaping/sanitization throughout; direct DB queries are prepared and
  annotated with `phpcs:ignore` where unavoidable.
- No `JSON.parse` in PHP paths — use `Mahan_Utils::extract_json()` / core
  `json_decode`. In JS the SPA parses its own API responses.
- Every PHP file passes `php -l`; every JS file passes `node --check`.
