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

**Dynamic per-user data** lives in seven custom tables (see `Mahan_DB`):
`enrollments`, `progress`, `attempts`, `stats`, `chat`, `ai_cache`, and
`xp_log` (append-only: every XP award with `amount`, `reason`, `ref_id`,
`created_at` — powers weekly leaderboards, daily goals, and reports). The
`stats` table also carries `freezes` and `daily_goal` (added in DB v2 via
`dbDelta`).

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
| `GET /lesson/{id}` | logged-in | Lesson content, exercises (answers stripped), siblings, stats. |
| `POST /enroll` | logged-in | Enroll in a course. |
| `POST /progress` | logged-in | Mark a lesson complete (awards XP, recomputes course %). |
| `POST /exercise` | logged-in | Grade a submitted answer. |
| `GET /quiz` | logged-in | Fetch a unit quiz (answers stripped) + best attempt. |
| `POST /quiz` | logged-in | Submit a unit quiz; graded, awards XP on first pass. |
| `POST /tutor` | logged-in | Non-streaming tutor reply (SSE fallback). |
| `GET /chat` | logged-in | Tutor chat history for a lesson. |
| `GET /me` | logged-in | User, stats, enrolled courses, badges, leaderboard flag. |
| `GET /leaderboard` | public* | Top-20 by XP; `?period=week\|all`; includes the caller's "me" rank (*only when enabled). |
| `POST /goal` | logged-in | Save the learner's daily XP goal. |
| `GET /paths` | public | Learning paths with aggregate progress. |
| `GET /path/{id}` | public | One path with its ordered courses + progress. |
| `GET/POST /profile` | logged-in | Read/save the learner profile. |

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

Quiz attempts reuse the `attempts` table (`type = 'quiz'`, `lesson_id = 0`,
`exercise_key = 'quiz:<md5(unit)>'`), so v1.2.0 still adds no tables.

---

## Coding conventions

- WordPress escaping/sanitization throughout; direct DB queries are prepared and
  annotated with `phpcs:ignore` where unavoidable.
- No `JSON.parse` in PHP paths — use `Mahan_Utils::extract_json()` / core
  `json_decode`. In JS the SPA parses its own API responses.
- Every PHP file passes `php -l`; every JS file passes `node --check`.
