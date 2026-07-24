# Changelog

All notable changes to **Mahan Academy** are documented here. The format is
loosely based on [Keep a Changelog](https://keepachangelog.com/), and the
project follows semantic-ish versioning.

## [1.15.0]

Personalized discovery. The catalog now leads with **"Recommended for you"** —
the courses that fit this learner's role, goal, and level — so the
personalization work pays off at the moment of choosing what to learn.
**No new tables, no AI call — DB stays v4.** Scoring is pure and deterministic
(9-assertion test) and the strip was driven in headless Chromium.

### New — `includes/class-mahan-recommend.php`

- `prefs($profile)` maps the profile to a preference vector (goal→categories,
  role→categories, level), and `has_signal()` reports whether there's anything
  to personalize on.
- `score_course($course, $prefs)` — a pure fit score: rank-weighted category
  matches for the learner's **goal** and **role**, a **level-fit** bonus, and a
  gentle featured tiebreak (only once there's signal, so a blank profile scores
  0 everywhere and cleanly falls back to catalog order).
- `for_user($user_id, $limit)` ranks every published course (skipping ones the
  learner is already enrolled in), picks the **best-fit bundle**, and returns a
  human `reason` ("Based on your role in Marketing and your goal to…").
- New REST route `GET /recommendations` (logged-in + nonce), which also attaches
  enrollment/progress so cards render exactly like the catalog's.

### Learner UI

- A **"✨ Recommended for you"** strip at the top of the catalog with the reason
  line and up to three fitted course cards, loaded asynchronously and hidden
  entirely when the learner has no profile signal (or the request fails) — the
  catalog never regresses for anonymous or profile-less visitors.
- New `recommendedForYou` i18n string and `.mahan-rec*` styles.

## [1.14.0]

Default content, per-lesson personalization, more AI-tool courses, and a
bug-fix pass. **No new tables — DB stays v4.** Seed data validated (13 courses),
personalization logic covered by a 15-assertion test, grader fixes by a
19-assertion test, and the lesson/catalog UI driven end-to-end in headless
Chromium.

### Courses ship by default

- `Mahan_Seed::maybe_autoseed()` installs the starter catalog automatically — on
  activation **and** as a one-time catch-up on upgrade (atomic `add_option`
  gate; only seeds when the site has no seeded courses, and never re-imposes if
  the owner later removes it). The academy is no longer empty on first visit.
- **New "AI Tools" category + bundle** — *ChatGPT for Everyday Work*, *Working
  with Claude*, *Google Gemini at Work*, and *AI Image Generation*. The library
  now ships **13 courses, 52 lessons, 206 exercises, 26 quizzes, 50 topics**
  across **5 categories** and **4 bundles**.

### Every lesson is personalized

- Lesson bodies now resolve `{{role}}` / `{{primary_goal}}` / `{{daily_tools}}`
  (and the rest of the profile) per reader, with natural fallbacks for blanks —
  `Mahan_Profile::personalize_content()` / `content_map()`, applied in `/lesson`.
- A per-lesson **"✨ For you"** card (`Mahan_Personalization::for_you()`, new
  `POST /lesson/personalize`) generates a short, learner-specific note on how the
  lesson applies to their job — server-cached per (lesson, profile) via a profile
  `signature()`, lazily loaded, and hidden when there's no profile / no AI key.
- Seed lessons weave placeholders in so the personalization is visible by default.

### Bug fixes (from an adversarial review pass)

- **Quiz XP re-awarded on every pass** — `Mahan_Quizzes::best()` selected a bare
  non-grouped column alongside `MAX()`, which errors under MySQL
  `ONLY_FULL_GROUP_BY` (default on 5.7.5+/8), so `best()` returned null and the
  "already passed" guard never fired. Now a pure aggregate query.
- **Wrong option graded correct** — `Mahan_Practice` / `Mahan_Reviews` filtered
  out blank AI options *before* clamping the answer index, silently shifting the
  correct answer onto a different option. Blank options are now dropped while the
  answer index is remapped (and a question whose correct option is blank/out of
  range is rejected).
- **Lesson-completion XP/streak farming & double-award** — `complete_lesson()`
  now awards XP and bumps the streak only on the actual transition to completed
  (conditional UPDATE / INSERT-race check), so re-completing a finished lesson is
  a no-op and a concurrent duplicate request can't double-award.
- **Practice XP farmable** — added a per-day practice-XP cap (`review_xp × 10`).
- Draft courses/paths no longer served by `/course` and `/path`; `enroll()`
  rejects non-published courses; `maybe_autoseed()` is race-safe.

## [1.13.0]

Browse by subject & topic. The catalog becomes a real discovery surface —
Coursera-style **category sections** by default and **topic-based browsing**.
**Front-end only (`app.js` + `app.css`) + i18n — no PHP behaviour, DB stays v4.**
Runs entirely on the `categories` + per-course `topics` the `/catalog` endpoint
already returns (v1.11.0). Verified end-to-end in headless Chromium.

- **Category sections.** With no active filter or search, the catalog renders
  grouped into per-category sections (a titled section + course grid each), with
  a "View all" that switches to that category. Any uncategorised courses fall
  under a "More courses" section. Applying any filter/search returns the flat
  filtered grid.
- **Browse by topic (مباحث).** A collapsible "Browse by topic" panel lists every
  concept present in the catalog (deduped from courses' `topics`, ranked by how
  many courses cover it) as clickable chips. Selecting one filters the catalog to
  courses covering that concept, with an active-filter banner (`Topic: … ✕`) to
  clear it. The filter is URL-synced (`?topic=`) so a filtered view is
  shareable/bookmarkable.
- **Clickable course topics.** The topic chips on a course page are now buttons
  that jump to the catalog filtered by that concept — so you can pivot from one
  course to everything else on the same topic.
- New i18n: `browseByTopic`, `topicFilterLabel`, `viewAll`, `otherCourses`;
  `.mahan-topic-panel` / `.mahan-cat-section` / `.mahan-active-filter` styles.

## [1.12.0]

Smart Practice. An on-demand AI practice generator, plus concept-topic chips in
the learner UI. **No new tables — DB stays v4** (generated sets live in a
short-lived per-user transient; wrong answers reuse the existing review queue).
Backend logic covered by a 17-assertion standalone test; the whole panel was
driven end-to-end in headless Chromium (generate → answer → grade) with zero JS
errors.

### On-demand AI practice — `includes/class-mahan-practice.php`

- `Mahan_Practice::generate($user_id, $lesson_id, $count)` asks the active
  provider for fresh questions that test the lesson's concepts (its
  `mahan_topic`s), tuned to the learner's **adaptive difficulty** and written
  with **misconception-targeting distractors**. Only deterministic types
  (multiple choice, true/false, fill-blank) are produced, so every question is
  graded instantly and can re-enter spaced repetition.
- Access is gated exactly like the tutor/lesson: enrolled + unlocked only, so
  practice can never leak locked material. The set (with answers) is stashed in
  a 30-minute per-user transient keyed by an opaque token; the browser only sees
  answer-stripped questions.
- `grade($user_id, $token, $index, $answer)` grades one item via the existing
  `Mahan_Reviews::grade_question`, awards review XP **once per question** (stash
  tracks graded indices, so it can't be farmed), and feeds wrong answers into
  the `Mahan_Reviews` queue (`source = practice`) so they come back later.
- New REST routes `POST /practice` and `POST /practice/grade` (logged-in +
  nonce; 403 for not-enrolled/locked, 404 for unknown token).

### Learner UI (`app.js` + `app.css`)

- A **Smart practice** panel on the lesson (shown when enrolled and the AI is
  configured): "Generate practice" → answer-and-check cards reusing the existing
  option/blank rendering and XP celebration → "Generate more". Grades against
  `/practice/grade`, revealing the correct answer on a miss.
- **Topic chips** ("مباحث") now render under the lesson title and on the course
  landing header, from `lesson_topics()` / `course_topics()`. New i18n:
  `topics`, `smartPractice`, `smartPracticeSub`, `generatePractice`,
  `generateMore`, `practiceFailed`; `.mahan-practice-*` / `.mahan-topic-chip*`
  styles.

## [1.11.0]

Curriculum Library. This release fills the academy with real content and makes
the teaching smarter. It adds a **one-click Starter Content installer**, a
concept-level **topics** taxonomy, **category + bundle discovery** in the
catalog, and a **structured teaching playbook** for the tutor plus
misconception-aware practice questions. **No new tables — DB stays v4.** The
curated curriculum is validated by a standalone schema + answer-key checker
(every marked answer proven in range) and the catalog was rendered in a headless
browser.

### Content model — topics ("مباحث")

- New `mahan_topic` taxonomy (`Mahan_CPT::TOPIC`), flat, registered on **both
  courses and lessons**. Categories (`mahan_category`) stay the Coursera-style
  domain; topics are the specific concepts inside them (Few-shot examples,
  Overfitting, Next-token prediction, …).
- `Mahan_Courses::course_topics()` / `lesson_topics()` read them; topics are
  surfaced in `course_summary` and the `/lesson` payload.

### Starter Content installer — `includes/class-mahan-seed.php`

- A curated data library in `includes/data/` (`course-*.php` + `bundles.php`)
  is turned into real posts on demand: category + topic terms, courses with
  units, lessons (HTML + exercises + topics), unit quizzes, and the bundles that
  group them.
- **Idempotent.** Every seeded post carries a `_mahan_seed_key` marker;
  re-running skips existing content and only relinks bundle membership. Nothing
  the owner authored by hand is ever touched.
- Triggered from the admin Dashboard (a "Starter content library" card →
  `admin_post_mahan_seed_install`, nonce + capability checked).
- Ships **three bundles** — *Prompt Engineering Specialization*, *Machine
  Learning Foundations*, *Generative AI Essentials* — plus a standalone
  *AI for Everyday Productivity* course, across four categories (Prompt
  Engineering, Machine Learning, Generative AI, AI at Work). In total:
  **9 courses, 36 lessons, 144 exercises, 18 unit quizzes (71 questions), and
  36 topics.** Every answer key was adversarially verified and a WP-mocked
  end-to-end install simulation confirmed the seeder is idempotent.

### Catalog v2 — categories + bundles (Coursera / Duolingo)

- `/catalog` now returns `categories` (name + course count) and `bundles` (the
  learning-path specializations).
- `app.js` renders a **bundles strip** at the top of the catalog and a
  **category filter** chip row (shown when the catalog spans more than one
  category), both URL-synced; search now also matches topics. New `allTopics` /
  `pathBadge` i18n strings, and `.mahan-bundle-*` / `.mahan-chips-cat` styles.

### Smarter teaching & question design

- **Tutor** (`Mahan_Settings::default_tutor_prompt`) rewritten as a structured
  teaching loop: diagnose → explain at their level → show one worked example
  from their world → scaffold (hint before answer) → check understanding →
  point to the next step. The current lesson's **topics** are now named to the
  tutor in its lesson-context block (`Mahan_AI_Stream`).
- **Question generation** (`Mahan_Reviews::generate_variant`) is now
  misconception-aware: distractors target the specific mistakes a learner who
  doesn't grasp the concept would make, with exactly one clearly-correct answer.
- **AI grading** (`Mahan_Exercises::grade_ai`) teaches as it grades — feedback
  names one strength, the single most important gap, and one concrete fix.

## [1.10.0]

Adaptive personalization. A new `Mahan_Personalization` engine turns the learner
profile + live progress into (a) a **learner-context block** injected into every
AI prompt and (b) an **adaptive target difficulty**. **No new tables — stored in
the existing `mahan_profile` user meta; DB stays v4.** The pure difficulty +
context logic is covered by a 27-assertion standalone test; the enriched
onboarding was rendered in headless Chromium (both themes).

### New — `includes/class-mahan-personalization.php`

- `difficulty_from($ai_level, $level, $mastered, $learning)` — pure 1–5 target
  difficulty: base from stated AI level, lifted by gamification level, nudged
  ±1 by the review mastery ratio once there's a signal; clamped 1–5.
  `difficulty()` / `signals()` wire it to a real user via one `hud()` call.
- `learner_context($user_id, $opts)` — a compact, structured block (role, org,
  seniority, AI level, goal, tools, learning style, challenge + live progress +
  target difficulty), emitting only lines with a real value. `difficulty_directive()`
  adds a one-line teaching instruction tuned to difficulty + learning style.

### Threaded through the AI paths

- **Tutor** (`Mahan_AI_Stream::build_messages`) appends `learner_context()` after
  the rendered system prompt, so the tutor adapts to live progress + difficulty,
  not just the static `{{placeholders}}`. Default tutor prompt rewritten with
  adaptive teaching directives + the new `{{seniority}}` / `{{learning_style}}`.
- **Grading** (`Mahan_Exercises`) replaces the ad-hoc profile sprintf with the
  full `learner_context()`.
- **Question generation** (`Mahan_Reviews::generate_variant`, now passed the
  user) personalizes the "ask a different way" rewrite — scenario from the
  learner's world, difficulty tuned to performance — instead of a generic reword.

### Onboarding

- Richer default profile schema (`Mahan_Settings::default_schema`) adds
  `seniority` and `learning_style` selects; the schema-driven SPA form renders
  them automatically. Intro copy reframed around the personalization payoff, and
  a `profileSaved` confirmation toast fires on save. Admin placeholder hint
  updated.

## [1.9.4]

Keyboard & screen-reader navigation pass. **Front-end (`app.js` + `app.css`) and
one i18n string only — DB stays v4.** Verified by loading the real `app.js` in
headless Chromium and driving it: skip link becomes the first tabbable element
and slides in on focus; arrow-roving moves focus across a real option group
(skipping a disabled option, wrapping, Home/End); the live region and
`#mahan-main` exist; zero page errors.

- **Skip-to-content link.** `mount()` re-attaches a persistent `.mahan-skip`
  anchor as the first child of `#mahan-app`; it is off-screen until focused,
  then slides to `top:0` and focuses `#mahan-main` (added an `id` to the
  `<main>`). New `skipToContent` i18n string.
- **Arrow-key roving focus** across `.mahan-ex-options` (lesson MC/TF, quiz,
  review) via one delegated `keydown` on root: ↑/↓/←/→ move between enabled
  options with wraparound, Home/End jump to ends. Buttons keep their existing
  click / `aria-pressed` behaviour and the review card's 1–9 shortcuts.
- **SPA route announcement.** A persistent polite live region (`#mahan-live`,
  `role="status"`) announces the view name on each navigation. `setTitle()`
  writes to it, gated on an `announceRoutes` flag set from the first `go()` so
  the initial load (already conveyed by the page title) stays silent.

## [1.9.3]

Accessibility & UI-states pass from a six-lens visual-design audit (responsive,
motion, hierarchy, component-consistency, contrast, states). **Styling +
6-line JS (empty-state icons) only — DB stays v4.** Verified by rendering the
dashboard, a graded quiz, disabled fields, and empty states in headless
Chromium in both themes.

### Contrast (WCAG AA)

- Added on-surface **text** tokens `--m-primary-text` / `--m-accent-text` /
  `--m-info-text` and a `--m-accent-fill`, theme-tuned so each brand/accent/info
  shade is legible on the low-contrast side of each theme. Repointed the failing
  text/icon usages (HUD XP/level/streak/freeze, progress %, quiz score, lesson/lb
  XP, tags, active nav, checks, `--m-primary`-as-text) — fills like buttons and
  `.chip.is-active` keep the raw token. Fixed the invisible active-day week-dot
  glyph and darkened white-on-green completion circles to meet 3:1. Nudged light
  `--m-muted` (#6b7280→#5b6270) to clear AA on `--m-surface-2`.

### States & affordances

- Gated the four ungated `:hover` rules on `:not([disabled])`/`:not(:disabled)`
  so disabled buttons/options/tutor-send no longer light up.
- Added a disabled resting state for text inputs/textareas/selects
  (`--m-surface-2` bg, muted text, `-webkit-text-fill-color`, `not-allowed`).
- Graded wrong pick: `.is-incorrect .mahan-ex-option.is-chosen:not(.is-correct)`
  now outlines red (scoped so re-submittable lesson exercises are untouched).
- Press states on chips / options / clickable div rows; hover on clickable
  (non-`<a>`) lesson rows; themed `::placeholder`; prereq-note link underline.
- Generalized the review empty-icon badge to a shared `.mahan-empty-icon`,
  prepended (decorative, `aria-hidden`) to the catalog/dashboard/leaderboard/
  paths/no-results/error states.

### Responsive & overflow

- Brand ellipsizes + HUD `flex:none` + `min-width:0` so a wide HUD can't force a
  whole-page horizontal scrollbar; prose `overflow-wrap`, scrollable wide tables,
  bubble `overflow-wrap:anywhere`; toasts lifted above the mobile bottom nav +
  `max-width`; four missed 44px touch targets; L/R `env(safe-area-inset-*)` on
  topbar/main for landscape notches.

### Motion & rhythm

- Desktop modal fade/scale-in; exercise feedback + hint reveal animations
  (reduced-motion-guarded); button `background-color` transitions;
  `.mahan-dash-h2` sized to the 22px scale with `--m-section-gap` rhythm;
  heading-band letter-spacing; leaderboard-hero trailing-space fix; goal-select
  radius aligned to the scale. Introduced `--m-ease`/`--m-ease-out` +
  `--m-dur-1/2/3` motion tokens and a `--m-space-*`/`--m-section-gap` spacing
  scale, threaded through high-traffic transitions.

## [1.9.2]

Typography & reading-experience refinement, continuing the 1.9.x visual
series. **Styling only — no PHP/JS changes, DB stays v4.** Verified by
rendering long-form lesson prose in headless Chromium in both themes.

- **Reading measure.** `.mahan-lesson-col > .mahan-prose` is capped at `66ch`
  so long lesson lines don't run the full column width (the classic 45–75
  character readability range); scoped to the lesson reading column, so
  full-width headings, exercises, and the course description are unaffected.
- **Prose rhythm.** `line-height: 1.7` on prose body; prose headings get
  `line-height: 1.2` + `-.015em` tracking; `:first-child` top-margin reset.
- **Links.** Prose links use `text-underline-offset: 2px` +
  `text-decoration-thickness: 1px`, thickening to `2px` on hover.
- **Sticky-bar anchors.** `scroll-margin-top: 84px` on prose headings, the
  lesson title, section `h2`s, and `:target`, so in-page jumps clear the
  sticky top bar.
- **Font stack.** `system-ui` first, dedicated emoji fallbacks
  (`"Apple Color Emoji"`, `"Segoe UI Emoji"`, `"Segoe UI Symbol"`), and
  `font-optical-sizing: auto`.

## [1.9.1]

UI depth & elevation refinement, building on the 1.9.0 token system.
**Styling only — no PHP/JS changes, DB stays v4.** Verified by rendering the
dashboard hero and lesson list in headless Chromium in both themes.

- **Elevation via `--m-surface-2`.** Threaded the layered-surface token
  (defined but barely used in 1.9.0) through the inset/nested elements that
  previously used `--m-bg`: dashboard stat tiles, progress/level tracks,
  lesson- and path-step circles, the daily-goal card, `.mahan-tag-soft`, the
  assistant tutor bubble, and prose `code`/`pre`. In dark mode these lift out
  of the near-black page background instead of reading as holes; in light mode
  they gain subtle definition. The cold-streak tile mixes its amber tint over
  `--m-surface-2` and gains a warm border.
- **Hairline borders** on stat tiles so the stats grid reads as a set of cards.
- **Two-layer shadows.** `--m-shadow` / `--m-shadow-sm` are now a soft contact
  shadow plus a wider ambient one (both themes) for more natural depth.

## [1.9.0]

UI best-practices pass — a visual-design refinement of `assets/css/app.css`,
verified by rendering representative components in headless Chromium in both
light and dark themes. **Styling only — no PHP/JS changes, DB stays v4.**

### Design tokens

- Expanded the `.mahan-app` token layer with a **semantic colour system**:
  `--m-danger` / `--m-danger-solid` / `--m-warning` / `--m-warning-text` /
  `--m-info`, plus `--m-surface-2`, radius scale (`--m-radius-sm/lg`),
  `--m-shadow-lg`, and a `--m-ring` focus token. The dark theme overrides the
  reds/ambers to lighter shades so status text stays legible.
- Threaded ~15 previously hard-coded `#ef4444` / `#b91c1c` / `#f59e0b` /
  `#b45309` / `#38bdf8` rules through the new tokens (feedback, quiz results,
  profile/quiz/review messages, nav badge, hint, streak, freeze, grade cues,
  cold-streak stat). Only the body-level error toast (outside `.mahan-app`,
  where the vars don't resolve) stays literal.

### Polish layer

- **Tabular numerals** (`font-variant-numeric: tnum`) on all live-updating
  counters — HUD, stats, progress %, level bar, leaderboard, nav badge, review
  progress — so digits don't jitter as values change.
- **Tactile primary button**: the 3D press now sinks into its shadow instead of
  the flat 1px nudge; the tutor send button gains matching hover/active/disabled
  states.
- **Consistent focus** — a soft `--m-ring` on every text control (was
  border-only), and cards mirror their hover lift on keyboard focus.
- `color-scheme: light` / `dark` per theme so native selects, checkboxes, and
  scrollbars adopt the theme; slim themed scrollbars for the tutor log, modals,
  and code blocks; `accent-color` for native controls.
- `appearance: none` normalization on the custom buttons; `text-wrap: balance`
  on headings and `pretty` on body sub-copy; `-webkit-font-smoothing`;
  `::selection` in the brand colour; empty-state icons get a focal circular
  badge. All new motion respects `prefers-reduced-motion`.

## [1.8.0]

UX best-practices round 3. A six-agent audit fanned out across the adaptive
review flow, a fresh-eyes pass over the whole SPA, accessibility, perceived
performance, and motivation copy; the 38 raw findings were de-duplicated into a
24-item backlog and the high- and medium-impact items were implemented. No
schema change (**DB version stays 4**).

### Dashboard & navigation

- **Actionable-first order.** The due-review CTA and "Jump back in" card now
  render above the stats hero. When both are present only one is a primary
  button (the other is demoted to a ghost) so the page keeps a single obvious
  next step. Review sub-copy reframed around memory decay
  (`reviewFading`) rather than inventory count.
- **Live reviews-due badge.** `navBadge()` now always renders (hidden at 0 via
  `:empty`) so `refreshHud()` can patch the count in place. `Mahan_Gamification::hud()`
  now carries `reviews` (`Mahan_Reviews::counts`) on every stats payload, so a
  newly created miss bumps the badge immediately instead of waiting for a `/me`
  refetch. `refreshHud()` also preserves `week`/`reviews` across bare payloads
  and toggles the cold-streak flame everywhere.
- **Cold-streak flame in the top bar.** Mirrors the dashboard: when today's
  activity hasn't counted yet the 🔥 becomes 🕯️ with an `is-cold` class.

### Adaptive review

- **Variant re-queue.** Missing an AI variant now re-queues the *original*
  (its one-time token is spent, so grading falls back to the snapshot) instead
  of dropping the concept for the session.
- **No back-to-back repeats.** A re-queued miss is spliced a couple of cards
  ahead when near the end of the queue, so it never reappears right after its
  answer was shown. An explicit "you'll see this again" line sets the
  expectation when the finish line grows.
- **Remediation path.** A wrong answer now offers a one-tap "Revisit lesson →"
  (guarded on `lesson_id`).
- **Honest completion.** A session that cleared nothing (or left items skipped)
  gets neutral copy instead of 🎉, plus a "Review skipped" shortcut that re-runs
  just the skipped items.
- **Keyboard.** Number keys (1–9) pick answer options; results carry ✓/✕ text
  cues, not colour alone.
- **Scope-aware header** for the lesson re-drill, and a count-aware, readable
  post-lesson redirect toast (1400 ms).

### Accessibility

- `.mahan-sr-only` utility added. Week-day nodes get `role="img"` + composed
  labels and a non-colour cue; badges announce earned/locked state; graded quiz
  and review options carry screen-reader cues; the AI tutor gets a dedicated
  once-per-reply `role="status"` announcer (log demoted from a noisy live
  region). Unit titles are real `<h3>` headings and the catalog gains a
  visually-hidden `<h2>`; locked lesson rows expose `aria-disabled`.

### Tutor, motivation & performance

- Tutor status reads "Online / Unavailable" (i18n) and the mobile FAB/scrim is
  skipped entirely when the tutor isn't configured for a lesson.
- Reinforcement copy (correct/incorrect, daily-goal, level-up) rotates through
  translatable variant arrays via a new `tv()` picker.
- Course-card images lazy-load as real `<img loading="lazy" decoding="async">`
  with a fade-in; the daily-goal picker saves optimistically with rollback; the
  lesson player already reuses its session cache for instant back-navigation.
- New `fmt()` sprintf-lite and `plural()` helpers replace word-by-word string
  concatenation ("1 lessons" → "1 lesson", cryptic "N Q" → "N questions"); the
  free-text exercise button now reads "Submit for feedback".

### Consciously deferred

- Full app-shell diffing (mount() still rebuilds topbar/nav) and the leaderboard
  period-toggle skeleton — both higher-risk refactors for marginal gain in a
  dependency-free SPA; the session cache already softens the leaderboard case.
- The deep i18n template refactor (sprintf-everything) is started (`fmt`/`plural`
  helpers, count strings) but not exhaustive.

## [1.7.2]

Second audit pass — a regression review of the 1.7.1 fixes (all confirmed
sound) plus a deep dive into the less-covered subsystems (AI streaming relay,
profile, emails, utils, enrollment). **DB version 4** — adds a `last_xp_date`
column to `mahan_reviews` (migrated automatically via `dbDelta`).

### Fixed — regressions from 1.7.1

- **`lesson_locked()` traversal** (committed in 9e84599): walked the grouped
  `get_course_units()` order while `next_lesson()` uses the flat
  `get_course_lessons()` order — on inconsistent unit metadata the gate could
  403 the exact lesson the app auto-navigates to. Now walks the same flat
  order (fail-open for the auto-nav target; proven over 2000 random states).
- **Review XP farm reopened by box-0 `+0 seconds`.** Making a freshly-missed
  item due immediately (needed for the end-of-lesson re-drill to award XP)
  let a wrong→correct→wrong→correct loop farm XP, since the `was_due` gate
  only blocks repeated *correct* answers. Review XP is now capped at **once
  per item per day** (`last_xp_date`), which closes the loop while keeping the
  end-of-lesson reward. Verified by a farm-simulation test.

### Fixed — subsystems

- **Streaming tutor swallowed provider errors.** With `CURLOPT_RETURNTRANSFER`
  off, `curl_exec` returns `true` for 4xx/5xx, whose body isn't SSE, so no
  token streamed and no error surfaced (learner saw an empty reply). Now
  checks `CURLINFO_HTTP_CODE` and emits an error when nothing streamed and the
  status is non-2xx. Also swapped the hard 120s total timeout for a connect
  timeout + 60s stall timeout so a long healthy answer isn't truncated.
- **Tutor IDOR.** `build_messages` fed lesson content with only a
  `post_type` check — draft/private lesson bodies could be extracted via the
  tutor prompt. Now requires a `publish`ed lesson the user is enrolled in.
- **`extract_json` brace matcher** ignored string literals, so valid JSON with
  a `}`/`{` inside a string value failed to parse — and the exercise grader's
  "API failure" fallback then auto-passed the answer. The scanner is now
  string- and escape-aware and skips a failed candidate to the next `{`.
  (8-case unit test.)
- **Duplicate `mahan_enrolled`.** A concurrent double-enroll lost the
  UNIQUE race but still fired the hook twice (two welcome emails). The hook
  now fires only when the row was actually inserted.
- **`update_progress` wiped `completed_at`.** Recomputing a completed course's
  progress below 100 reset its status to active and nulled the completion
  timestamp. A finished course now keeps its completion state and original
  date.
- **Quiz badges under-counted** across courses sharing a unit title
  (`quiz:md5(unit)` key had no course scope) — now `COUNT(DISTINCT
  course_id:key)`. **Review clears** now also run badge evaluation
  (`mahan_review_cleared` → `evaluate`) so XP-threshold badges unlock promptly.
- **Re-queued AI variant** was displayed as the variant but graded against the
  original (its one-time token was already spent) — variants are no longer
  re-queued within a session.
- **Course-outline lock flag** now computed in the same flat order as the
  gate, so the outline and the server agree even on inconsistent unit data.
- **`loadChat`** clears a stale `tutorBusy` flag on a fresh lesson so its
  history always loads.

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
