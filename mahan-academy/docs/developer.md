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
│   ├── class-mahan-db.php                # 10 custom tables + AI cache
│   ├── class-mahan-settings.php          # options + defaults + profile schema
│   ├── class-mahan-i18n.php              # language resolution, learner preference, catalogs
│   ├── class-mahan-learner.php           # saved courses, skills, lifetime totals, minutes left
│   ├── class-mahan-cpt.php               # mahan_course / mahan_lesson + taxonomy
│   ├── class-mahan-profile.php           # schema-driven learner profile (user meta)
│   ├── class-mahan-courses.php           # course/lesson structure + meta keys + topics
│   ├── class-mahan-seed.php              # starter-content installer (idempotent)
│   ├── class-mahan-variants.php          # level ladders (tracks) + department field variants
│   ├── class-mahan-placement.php         # placement test: bank, sittings, scoring
│   ├── class-mahan-certificates.php      # issued credentials: serials, verification
│   ├── class-mahan-recommend.php         # personalized course/bundle fit scoring
│   ├── class-mahan-gamification.php      # XP, levels, streaks, level titles
│   ├── class-mahan-badges.php            # achievements
│   ├── class-mahan-emails.php            # notification emails + daily cron
│   ├── class-mahan-enrollment.php        # enrollments
│   ├── class-mahan-progress.php          # lesson/course progress
│   ├── class-mahan-ai.php                # provider-agnostic completions
│   ├── class-mahan-exercises.php         # grading (instant + AI)
│   ├── class-mahan-quizzes.php           # end-of-unit quizzes
│   ├── class-mahan-reviews.php           # adaptive review (spaced repetition + AI variants)
│   ├── class-mahan-practice.php          # on-demand AI practice generator + grading
│   ├── class-mahan-viva.php              # live AI oral exam: staged, graded, resumable
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
└── includes/data/                # curated starter-content library (loaded by Mahan_Seed)
    ├── course-*.php              # one file per seed course (returns a course array)
    ├── bundles.php               # specialization bundles (return an array of paths)
    └── placement.php             # placement question bank (tier-tagged)
└── languages/                    # gettext catalogs
    ├── mahan-academy.pot         # template, regenerated from source
    ├── mahan-academy-es_ES.po    # Spanish, generated from the dictionary
    └── mahan-academy-es_ES.mo    # compiled — the one WordPress loads
└── tools/                        # translation toolchain (see "Translations")
    ├── make-pot.php  i18n-es.php  make-po.php  po2mo.php
```

---

## Data model

**Content** lives in three CPTs:

- `mahan_course` — the course. Meta keys (constants on `Mahan_Courses`):
  `M_SUBTITLE`, `M_LEVEL`, `M_EST_HOURS`, `M_OUTCOMES`, `M_FEATURED`,
  `M_PROMO_VIDEO`, `M_PREREQ`, `M_CERTIFICATE`, `M_UNIT_QUIZZES` (per-unit
  quizzes keyed by unit title), `M_REFERENCES` (further-reading sources:
  `{ title, source, url }`, read via `Mahan_Courses::course_references()`), plus
  `Mahan_Variants::M_TRACK` / `M_LEVEL_RANK` (the level-ladder position, also
  surfaced on `course_summary()` so a catalog card can say "Step 2 of 4").
- `mahan_lesson` — a lesson, linked to its course by meta.
  Keys: `M_COURSE_ID`, `M_UNIT`, `M_UNIT_ORDER`, `M_ORDER`, `M_XP`, `M_EST_MIN`,
  `M_TYPE`, `M_EXERCISES` (JSON array of exercise definitions), `M_VARIANTS`
  (per-department blocks, read via `Mahan_Courses::lesson_variants()`).
- `mahan_path` — a learning path / **bundle** (Coursera-style specialization).
  Meta (on `Mahan_Paths`): `M_COURSES` (ordered course IDs), `M_SUBTITLE`.

**Taxonomies** (`Mahan_CPT`): `mahan_category` (`CAT`, hierarchical, on courses —
the Coursera-style domain) and `mahan_topic` (`TOPIC`, flat, on **courses and
lessons** — concept-level "مباحث"/skills that the AI tutor and question generator
key off). `Mahan_Courses::course_topics()` / `lesson_topics()` read them; the
lesson's topics are named to the tutor in its lesson-context block.

**Ladders.** Four subject tracks ship wired: `chatgpt` (4 rungs),
`prompt-engineering`, `machine-learning` and `generative-ai` (3 each). A rung's
`level` must match its `level_rank` (1 beginner, 2 intermediate, 3 advanced,
4 expert) — the ladder UI and the placement result both read it, and the seed
validator errors on a mismatch, a duplicate rung, a rank outside 1–4, a
`level_rank` with no track, or a gap in a ladder. The AI Tools courses (Claude,
Gemini, image generation) are deliberately not a track: different tools, not
levels of one subject.

**Starter content** (`Mahan_Seed`) turns the curated `includes/data/` library into
real posts on demand: it creates the category + topic terms, inserts each course
with its units, lessons (HTML + exercises + topics) and unit quizzes, and wires
the courses into bundles. Every seeded post carries a `_mahan_seed_key` marker,
so `Mahan_Seed::install()` is idempotent — re-running skips existing content and
only relinks bundle membership — but it does refresh *structural* metadata on
those skipped courses via `refresh_course_meta()` (ladder track/rung/level, and
references only when absent). That distinction matters: "skip" must mean "don't
touch the owner's content", not "never learn anything new", or metadata added in
a later version could never reach a site that already seeded.
`maybe_refresh_structure()` runs that sweep once per version, claimed with an
atomic per-version `add_option` — deliberately not a separate lock option, which
a fatal mid-sweep could strand and thereby block every future refresh.

The same sweep calls `add_missing_lessons()`, which delivers *new content* to
already-installed courses under a strictly additive rule: a lesson is created
only when nothing carries its seed key (`<course>:u<N>:l<M>`) yet, and an
existing lesson is never rewritten, reordered or removed even when the library's
copy has since changed. Unit quizzes follow the same rule, with one trap worth
knowing — `Mahan_Quizzes::save_all()` replaces the whole map, so the existing
quizzes must be read and merged or adding one would silently wipe the rest. The
honest cost of "additive" is that a lesson the owner deliberately deleted comes
back; trashing is not a strong enough signal to distinguish that from "not seen
yet", and never shipping new material is worse for every other site.

**Course shape.** Every seed course is four units of two lessons, each unit
closed by a quiz — 76 units, 152 lessons, 629 exercises and 297 quiz questions
across the nineteen courses. The two later units of each course are
deliberately not more of the introduction: they carry the material that makes
the subject difficult (limits, failure diagnosis, cost, governance), which is
what gives a course an arc rather than a length.

**Course length is computed, never typed.** `Mahan_Seed::duration()` sums lesson
minutes plus a flat budget per exercise (`MIN_PER_EXERCISE`) and per quiz
question (`MIN_PER_QUIZ_Q`), because those are answered rather than read. It is
the source of truth for `M_EST_HOURS` at install and after a backfill. The
authored `est_hours` in each data file is kept only as a claim the seed
validator checks against the computed value — the two drifted for eleven
releases until the catalog advertised 57 hours against 13 of material, precisely
because nothing compared them.

Triggered from the admin dashboard
(`admin_post_mahan_seed_install`) and **auto-installed by default** via
`Mahan_Seed::maybe_autoseed()` — on activation and as a one-time `init` catch-up
(atomic `add_option` gate; only seeds an empty site, never re-imposes). Ships
**no** new tables and no schema bump.

**Levels & department variants** (`Mahan_Variants`) run on two deliberately
different axes, so 15 subjects × 4 levels × 6 departments doesn't become 360
courses to maintain:

- **Level = a real course.** Each rung is its own published course sharing
  `M_TRACK` with its siblings and ordered by `M_LEVEL_RANK` (1–4, from `LEVELS`:
  `beginner`, `intermediate`, `advanced`, `expert`). `track_ladder($track)`
  returns the published rungs in order — the data behind the ladder UI on the
  course page. `normalize_level()` / `level_rank()` are case-insensitive and
  resolve anything unknown to `beginner`.
- **Field = a render-time overlay.** `M_VARIANTS` holds
  `field => { title, body }` blocks on the *lesson*; nothing is duplicated.
  `field_for_user()` maps the profile role to a `FIELDS` entry (via `ROLE_FIELD`
  — e.g. `founder` → `management`), `pick()` resolves it (exact match → `general`
  → nothing), and `apply()` merges the block into the body, returning
  `{ content, applied }`. **`applied` is the field that actually matched**, so a
  `general` fallback never renders as "Tailored for Marketing". `/lesson` returns
  it as `field` + `field_label`, and the badge shows only on a real match. A
  subject with no hand-written blocks still specializes through
  `Mahan_Personalization::for_you()`.

**Per-lesson personalization.** Lesson bodies resolve `{{profile placeholders}}`
per reader via `Mahan_Profile::personalize_content()` (natural fallbacks for
blanks), applied in `/lesson`. `Mahan_Personalization::for_you()`
(`POST /lesson/personalize`) generates a short "how this applies to your work"
note, cached in the AI cache per `(lesson, Mahan_Profile::signature())` so it
regenerates only when the learner updates their profile. The cache namespace
carries a version (`mahan_foryou.v2`) — change the prompt without bumping it and
the improvement ships to nobody, because every existing site keeps serving notes
the old prompt wrote.

**Live oral exam** (`Mahan_Viva`) is the one assessment that cannot be answered
by recognition. A sitting runs three stages — explain → apply → judge — each one
question answered in prose, graded 0–100 by the AI against a rubric the AI wrote
when it set the question. Three properties are load-bearing:

- **The score is the server's.** The model returns a number and an advisory
  verdict; PHP decides pass/fail against `PASS_SCORE`. The rubric lives in the
  row's `pending` blob and is stripped by `public_session()`, so the browser has
  nothing to forge and nothing to read the answer off. A `probe` (one focused
  follow-up on a partly-right answer) is the only verdict the model controls, and
  `MAX_TURNS` bounds even that.
- **It is bounded.** Stages, attempts per stage (`MAX_ATTEMPTS`), turns per stage,
  answer length (`ANSWER_MAX`) and unit material (`MATERIAL_MAX`) are all capped,
  so a live model conversation cannot become a runaway bill.
- **It is resumable.** The sitting is a row, not a transient. `start()` returns
  the active sitting instead of opening a second one; a sitting untouched for
  `STALE_AFTER` is retired rather than resumed, because a half-remembered
  question a week later helps nobody.

A viva opens only when every lesson in its unit is complete, and it is hidden
entirely when no provider is configured — the same rule as the tutor.

Four rules earned by bugs, worth keeping:

- **Every failure path persists nothing.** A grading or question-generation
  failure leaves the sitting exactly as it was found. Banking a passed stage
  without moving off it meant re-answering counted its score twice (320 out of
  300); banking the learner's turn on a grading failure meant re-sending showed
  the same answer twice — the browser had never lost it.
- **The terminal transition is guarded.** `close_once()` writes the closing
  status with `WHERE status = 'active'`, so two finals landing together produce
  one winner and one `finished`. `passed_before()` is read *before* that write,
  or the sitting becomes its own prior pass and XP is never paid at all. XP is
  paid once per unit ever, so retaking for a better score is welcome and farming
  it is not.
- **The unit title is matched, not cleaned.** `unit_key()` clamps it to the
  column width at the database boundary and nowhere else. Running it through
  `sanitize_text_field()` before matching made a unit whose title had a trailing
  space impossible to examine, and letting MySQL truncate a long one made the
  sitting invisible to both the resume lookup and the once-per-unit XP guard.
- **Course pages use `course_states()`.** Two queries and one lesson fetch for
  the whole course; the per-unit path re-fetched both once per unit.

`MAX_SITTINGS_PER_DAY` caps *new* sittings — resuming is always free. XP is
already once-per-unit, so this is not about farming; it is about a provider bill
nobody agreed to, since nothing else stopped a learner looping start → fail →
start. The middle stage is where the personalization thread
lands: its question is generated from `Mahan_Personalization::learner_context()`,
so two learners finishing the same unit are examined on the same concept inside
their own jobs.

**Placement** (`Mahan_Placement`) is authored, not AI-generated: the bank lives
in `includes/data/placement.php` (32 questions tagged tier 1–4) and scoring is
arithmetic, so it works with no API key and gives the same answer twice.
`sitting()` draws an even spread across tiers, easiest-first. `level_from()`
places you at the **highest tier you demonstrated** — two-thirds correct at that
tier *and* every tier below it cleared — rather than on a total, so one lucky
expert answer can't outrank a failed intermediate tier. Options are permuted per
sitting by `option_order(key, seed)` and mapped back when grading; do **not**
try to vary answer position in the data, and don't drop the shuffle — the bank
was authored with the answer at index 1 almost every time. The result lives in
user meta and syncs into the profile's `ai_level`, which is what the tutor,
question difficulty and recommendations already read.

**Certificates** (`Mahan_Certificates`) are issued from the
`mahan_course_completed` action, never from a UI button. `issue()` is idempotent
and the unique key on `(user_id, course_id)` is the real guard under concurrency.
Serials are random (not sequential — that would let anyone enumerate every
credential) and skip 0/O/1/I because they get typed off printouts;
`normalize_serial()` accepts lower case, missing dashes and stray spaces.
`verify()` is public and returns **only** serial/recipient/course/date/issuer —
never a user id. `Mahan_Plugin::maybe_backfill_certificates()` issues once for
anyone who completed a course before v1.22.

**Dynamic per-user data** lives in ten custom tables (see `Mahan_DB`):
`enrollments`, `progress`, `attempts`, `stats`, `chat`, `ai_cache`,
`xp_log` (append-only: every XP award with `amount`, `reason`, `ref_id`,
`created_at` — powers weekly leaderboards, daily goals, and reports), and
`reviews` (the adaptive-review queue: one row per missed question with a
Leitner `box`, `due_at`, `reps`/`lapses`, a JSON `snapshot` of the question,
and `last_xp_date` — added in DB v3, `last_xp_date` in DB v4), and
`certificates` (issued credentials: `serial`, `issued_at`, `revoked`, unique on
both `serial` and `(user_id, course_id)` — added in DB v5), and `viva` (live
oral-exam sittings: `stage`/`turn`/`attempt`, the running `score`, a JSON
`transcript`, and a JSON `pending` holding the live question **and its grading
rubric** — added in DB v6). The `stats` table also carries `freezes` and
`daily_goal` (added in DB v2 via `dbDelta`).

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

### Personalization (`Mahan_Personalization`)

The brain that adapts AI output to the learner. Two pure, unit-tested pieces:

- `difficulty_from($ai_level, $level, $mastered, $learning)` → a 1–5 target
  difficulty (base from stated AI level, lifted by gamification level, nudged
  ±1 by the review mastery ratio, clamped). `difficulty($user_id)` / `signals()`
  resolve it against a real user (one `Mahan_Gamification::hud()` call).
- `learner_context($user_id, $opts)` → a compact structured block (profile +
  live progress + target difficulty), emitting only non-empty lines.
  `difficulty_directive($user_id)` is a one-line teaching instruction.

It's injected wherever the AI writes for a learner: the tutor system prompt
(`Mahan_AI_Stream::build_messages`), exercise grading (`Mahan_Exercises`), and
the personalized review-variant generator (`Mahan_Reviews::generate_variant`,
which now takes `$user_id`). The profile itself lives in `mahan_profile` user
meta via `Mahan_Profile`; the schema (with the `seniority` / `learning_style`
fields) is `Mahan_Settings::default_schema()`. No new tables.

---

## REST API (`mahan/v1`)

| Method & route | Auth | Purpose |
| --- | --- | --- |
| `GET /catalog` | public | Course cards (featured first). |
| `GET /course/{id}` | public | Course detail: outcomes, description, units, promo video, prerequisite, certificate, progress, `references`, and `ladder` (the track's level rungs). |
| `GET /lesson/{id}` | logged-in | Lesson content (placeholders resolved + department variant merged), exercises (answers stripped), siblings, stats, `position` (index/total/unit), `course_pct`, `topics`, `field`/`field_label`, and `unit_quiz` (when the lesson closes a unit that has a quiz). |
| `POST /lesson/personalize` | logged-in | AI "how this lesson applies to your work" note (cached per profile signature). |
| `POST /practice` | logged-in | Generate fresh AI practice questions for a lesson, tuned to its concepts and the learner's difficulty. |
| `POST /practice/grade` | logged-in | Grade a generated practice answer; misses go into the review queue (XP daily-capped). |
| `POST /viva/start` | logged-in | Open (or resume) a unit's live oral exam; returns the sitting and its first question. |
| `POST /viva/answer` | logged-in | Submit one prose answer; returns the grade, the outcome (`probe` / `stage_passed` / `retry` / `passed` / `failed`) and the updated sitting. |
| `POST /viva/abandon` | logged-in | Retire a sitting the learner walked out of. |
| `GET /recommendations` | logged-in | "Recommended for you" courses + best-fit bundle, with the reason. |
| `GET /placement` | logged-in | A sitting (answer keys stripped) + the seed + any previous result. |
| `POST /placement` | logged-in | Grade a sitting, store the level, return where to start on each ladder. |
| `GET /certificates` | logged-in | The caller's issued certificates. |
| `GET /certificate/{serial}` | **public** | Verify a serial. Public by design; returns only who/what/when. |
| `GET /profile/summary` | logged-in | Profile page: identity, lifetime totals, skills, 91-day activity map, certificates, badges. Separate from `/me` because its history scans are heavier and the dashboard reloads far more often. |
| `POST /save` | logged-in | Toggle save-for-later on a course. |
| `POST /language` | public† | Record the reader's language. Public so guests can switch before signing up (†still requires the REST nonce, so no other site can flip a learner's language). |
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
`#mahan-app`. Views: catalog, course (with unit quiz and live-assessment rows), lesson (tutor
panel + exercises), dashboard (with badges), profile, leaderboard, paths, and
path detail.
Config + i18n are injected via `wp_localize_script` as `window.MahanData`. Theme
colors are CSS variables (`--mahan-primary`, `--mahan-accent`) set inline from
settings.

**View teardown.** `mount()` clears the app root, so anything a view wires up
*outside* its own DOM (window `scroll`/`resize` listeners, timers) must be
registered with `onUnmount(fn)`; `mount()` runs and drains those hooks before
painting the next view. The corollary matters: register hooks **after** calling
`mount()`, or the teardown you are about to trigger will eat them. That is why
`readingProgress()` returns `{ node, start }` instead of wiring itself up.

**Live assessment modal** (`openViva` / `renderVivaModal`). A turn-by-turn
transcript with stage pips, a compose box (Ctrl/Cmd+Enter sends), and grade
cards carrying the score, the feedback, and strength/gap chips. Three details
that are not decoration: the learner's turn is appended optimistically and
**rolled back** if the request fails, so a provider hiccup never leaves a
phantom answer on screen; the typed text is restored into the box on failure
rather than lost; and closing is safe by design — the sitting lives on the
server, so `beforeClose` only guards *unsent* text. After each answer the client
adopts the server's session wholesale instead of maintaining a parallel copy of
the state machine.

**Course covers.** `courseCover(course, { wide })` returns the card/hero cover.
With a featured image it's the image; otherwise it's a generated CSS gradient —
no request, no upload. The variant (`.mahan-cover-0..5`) comes from the
**category** via `COVER_BY_CATEGORY` (hand-assigned for the shipped categories,
since hashing six names into six buckets collides almost every time; unknown
categories fall back to `hashBucket`), and the **title** sets `--cover-angle`
and `--cover-tone` so siblings in one category still differ. Keep it a pure
function of the category name — a course opened directly, with no catalog
loaded, must render the same cover it had on the card. Covers are `aria-hidden`.
The title also picks one of six **pattern families** (`.mahan-cover-p0..5`), so
two courses in one category share a hue without sharing a look.

**Course accent** (`courseThemeClass()`). The course and lesson wrappers carry
`.mahan-themed .mahan-c0..5`, derived from the same identity as the cover, which
redefines `--m-course` / `--m-course-text` for everything inside. `/lesson`
returns `course_categories` so a lesson can paint itself in its course's colour
without a second request.

Two rules hold this together:

- **Identifying chrome only** — kicker, unit headings, topic chips, meters, the
  reading hairline, the current ladder rung, the hero tint. **Primary buttons
  keep the brand colour**: the button you press must not move around the palette
  from course to course. There's an assertion for this.
- **`--m-course` is the fill; `--m-course-text` is the AA-legible on-surface
  shade**, per theme — the same split as `--m-primary` / `--m-primary-text`.
  Never use the fill as small text, and never put white text on the fill: the
  cyan/green/amber fills measure 1.8–3.7:1 against white. New accents must be
  measured (surface, chip tint, rung tint, both themes) before shipping.

**Entrance motion.** `armReveals(main)` runs from `mount()` and observes card
grids (`.mahan-grid`, `.mahan-bundle-row`, `.mahan-badges`) with an
`IntersectionObserver`, adding `.mahan-reveal` plus a capped `--reveal-i`
stagger as each group scrolls in. Anything that injects a grid *after* mount
must call `revealGroups(container)` — the recommendations strip loads async and
would otherwise be the one grid that never animates. The animating class is only
ever added by JS, never by CSS, so with no JS, no `IntersectionObserver`, or
`prefers-reduced-motion: reduce` the content is simply visible; never hide
content in CSS and reveal it in JS.

**Keyboard layer.** A single delegated `keydown` handler implements `/` (search),
`←`/`→` (previous/next lesson), `C` (continue), `?` (shortcut sheet) and `Esc`
(clear filters). It reads view-scoped handles — `catalogSearchInput`,
`clearCatalogFilters`, `currentLesson` — that each view sets after `mount()` and
`mount()` nulls; a null handle means "this shortcut doesn't apply here". The
handler bails on any modifier, while `modalStack` is non-empty, and whenever
focus is in an input, textarea, select, or contenteditable.

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
- **Keyboard / SR navigation (1.9.4)** — `mount()` re-attaches two persistent
  nodes: a `.mahan-skip` skip-to-content link (first tabbable, targets
  `#mahan-main`) and a polite `#mahan-live` region that `setTitle()` writes the
  view name to on each navigation (gated by `announceRoutes`, set from the first
  `go()`). A single delegated `keydown` on root gives `.mahan-ex-options`
  arrow-key roving focus (↑↓←→ + Home/End, skips disabled, wraps).

Quiz attempts reuse the `attempts` table (`type = 'quiz'`, `lesson_id = 0`,
`exercise_key = 'quiz:<md5(unit)>'`), so v1.2.0 still adds no tables.

---

## Translations

The plugin ships English (source) and Spanish, and the whole toolchain is in
`tools/` because `xgettext` / `msgfmt` / WP-CLI are not present everywhere, and
a translation that only exists as a `.po` is a translation WordPress ignores.

Adding or updating a language:

```bash
php tools/make-pot.php              # rescan source → languages/mahan-academy.pot
# edit tools/i18n-<lang>.php  (msgid => translation; an array supplies plurals)
php tools/make-po.php es_ES         # merge dictionary + template → .po
php tools/po2mo.php languages/mahan-academy-es_ES.po   # → .mo
```

`make-po` reports two things worth acting on: strings with no translation yet
(they fall back to English), and **orphans** — dictionary entries matching no
source string, which is almost always a source string that changed underneath a
translation, leaving it silently doing nothing.

`po2mo` deliberately omits untranslated entries. Writing them would make gettext
return the empty string as the "translation" and blank the UI, which is worse
than falling back to English.

### How a locale is chosen

`Mahan_I18n` filters `plugin_locale` for this textdomain only — nothing else on
the page changes language, which is what makes it safe to let a learner choose.
Resolution order on the front end:

1. The learner's stored preference (user meta `mahan_lang`), or for signed-out
   visitors the `mahan_lang` cookie. A signed-in account always outranks a
   leftover guest cookie.
2. `default_language` from settings, when the site pins one.
3. WordPress's own locale, mapped to the nearest catalog in the same language
   family — so `es_MX` gets Spanish rather than falling back to English.

wp-admin is excluded and keeps WordPress's rules: an editor's admin screens
should not switch because they once read a course in Spanish. `admin-ajax` is
ruled back in by hand — it reports `is_admin()` while serving the front end.

To render in another learner's language (background jobs, notifications), use
`Mahan_I18n::with_user( $user_id, $callback )`. It swaps only this plugin's
catalog and restores it even if the callback throws — `switch_to_locale()` would
move the entire site, which is far more than a background job needs.

### What is not translated

Course prose, lesson bodies, quiz questions and email templates are rows in the
database written by the site owner. Gettext does not reach them, and the plugin
does not pretend otherwise. `Mahan_Front::strings()` is the boundary: everything
the SPA renders comes through it, wrapped in `__()`, resolved server-side at
boot. The app does no client-side translation — switching language reloads the
document rather than re-rendering, because the strings live on the server.

---

## Profile & saved courses

`Mahan_Learner` derives everything the profile shows from data already stored —
there are no new tables. Skills come from the topic taxonomy joined to completed
lessons in one query (`SKILL_THRESHOLD` of 2, because one lesson is an encounter
rather than a skill); lifetime totals from the progress, enrollment, attempts
and XP-log tables; saved courses from `mahan_saved` user meta.

`Mahan_Gamification::activity_map()` generalises the seven-day strip into a
run of weeks, keeping XP per day so the UI can show intensity. Intensity is
bucketed against the learner's own daily goal rather than their best day ever —
otherwise one enormous session makes every ordinary day afterwards look like a
failure.

**Saved is not enrolled.** "I might do this" and "I am doing this" are different
intentions; collapsing them turns a dashboard into a graveyard of things nobody
started. The dashboard's three shelves (in progress / completed / saved) are
filtered client-side from data `/me` already returns, so switching tabs costs no
round trip.

**`urlFor()` is the only place URLs are built**, and it ignores params it does
not know about — silently. A `topic` param was added there when the profile's
skill chips needed to link to a filtered catalog; anything else that needs to
survive a reload or a share has to be added there too, not passed hopefully
through `go()`.

---

## Coding conventions

- WordPress escaping/sanitization throughout; direct DB queries are prepared and
  annotated with `phpcs:ignore` where unavoidable.
- No `JSON.parse` in PHP paths — use `Mahan_Utils::extract_json()` / core
  `json_decode`. In JS the SPA parses its own API responses.
- Every PHP file passes `php -l`; every JS file passes `node --check`.
