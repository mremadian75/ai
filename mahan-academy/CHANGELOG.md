# Changelog

All notable changes to **Mahan Academy** are documented here. The format is
loosely based on [Keep a Changelog](https://keepachangelog.com/), and the
project follows semantic-ish versioning.

## [1.28.1]

A deep bug hunt over 1.28.0's new code. Five real defects, every one of them
now covered by a test that failed before the fix.

### The score could exceed the maximum

If the provider failed while setting the *next* stage's question, the stage that
had just been passed was banked anyway — but the sitting stayed on that stage.
Answering it again added its score a second time. Three stages could total 320
out of 300, and the certificate-adjacent number a learner sees read **107%**.

All three failure paths in `answer()` now persist nothing at all: the sitting is
left exactly as it was found, and the browser (which still holds the typed text)
re-sends. The same fix stopped a provider failure from charging the learner one
of their two attempts for something that was never their mistake.

### Two final answers could be paid twice

A double tap, or a retried request, meant two calls read an active sitting, both
decided "passed", and both reached the XP award before either had written the
status the anti-farm check reads. Sixty XP twice.

The terminal transition is now a guarded `UPDATE ... WHERE status = 'active'`,
so MySQL picks one winner; the loser is told the sitting is already closed. The
"have they passed this before" question is asked *before* that write — asking it
after meant the sitting became its own prior pass and nobody was ever paid.

### A grader failure showed the learner their own answer twice

The learner's turn was persisted on a grading failure, in the name of not losing
their words. But the browser had not lost them — it restores the text and
re-sends — so the only thing the write achieved was a transcript containing the
same answer twice.

### Unit titles

`start()` ran the unit title through `sanitize_text_field()` and then matched the
result against the course's stored titles. A unit whose title had a trailing
space could therefore never be examined: the row rendered as available and the
request 404'd. The title is now matched verbatim, and clamped to the column width
only at the database boundary — where a longer title would otherwise be silently
truncated on write, leaving the sitting invisible to both the resume lookup and
the once-per-unit XP guard.

### A course page cost sixteen queries it did not need

Every unit's viva state was computed independently, each one re-fetching the
course's lesson list and the learner's progress map that the page had already
loaded. `Mahan_Viva::course_states()` now answers the whole course in **two
queries and one lesson fetch**, down from eight queries and four fetches on a
four-unit course.

### Also

- A daily cap (12) on *new* sittings. Resuming one is always free, and XP was
  already once-per-unit — this is about a provider bill nobody agreed to, since
  nothing else stopped a learner looping start → fail → start.
- Two taps on "Live assessment" opened two modals over one sitting.

### Delivery

- 108 assertions in the viva suite (up from 87) — 21 of them regressions for the
  defects above, including a modelled two-request race.
- All 11 logic suites and 17 render harnesses pass. Spanish at 713/713.

## [1.28.0]

A live AI examiner, and the course that explains why any of this feels personal.
**Schema change: DB goes to v6.**

### The thing a quiz cannot do

Every assessment in the plugin until now asked the learner to *recognise* the
right answer: pick an option, judge true/false, fill a blank. That is cheap to
grade and easy to pass without understanding anything.

**Live assessment** (`Mahan_Viva`) asks them to say it. At the end of each unit —
once every lesson in it is done — a sitting opens and an AI examiner runs three
stages:

| Stage | What it asks for |
| --- | --- |
| **Explain** | The core idea in your own words, including *why* it works. |
| **Apply** | What you would actually do, in a scenario built from **your** role, tools and goal. |
| **Judge** | A trade-off, a limit, a failure mode, or when not to use it at all. |

Each answer is prose, graded 0–100 against a rubric the examiner wrote when it
set the question. Pass the stage and the next one opens. Answer *partly* right
and it probes once — one focused follow-up on the exact gap — before the attempt
counts against you. Two failed attempts on a stage ends the sitting; the
questions are new next time.

### Three properties that are not negotiable

- **The score is the server's.** The model returns a number and an advisory
  verdict; PHP decides pass/fail. The rubric lives in the database row and is
  stripped before the session reaches the browser, so there is nothing to forge
  and nothing to read the answer off. A model that says "pass" on a 30 is
  overruled; so is one that says "fail" on a 95. There are tests for both.
- **It is bounded.** Stages, attempts, turns per stage, answer length and the
  unit material handed to the examiner are all capped. A live model conversation
  cannot become a runaway bill.
- **It is resumable.** A sitting is a row, not a transient. Close the tab
  mid-exam and you come back to the same question — not a fresh one, and not a
  lost one. A sitting untouched for a day is retired rather than resumed.

Passing pays 60 XP, once per unit ever. Retaking for a better score is welcome;
farming it is not. With no API key configured the feature does not appear at all
— the same rule the tutor already followed.

### The personalization thread, made explicit

The **Apply** stage is where the request behind this release lands: its question
is generated from the learner context block, so two people finishing the same
unit are examined on the same concept *inside their own jobs*.

Alongside it, a nineteenth course — **"Personalizing AI: Make It Work Like You
Do"** — teaches the subject the rest of the catalog had been assuming: why an
assistant answers generically by default (an empty context, not a weak model),
the four levers that change that (instructions, context, memory, examples), how
to write a profile where every line would be wrong in a stranger's, when to use
a project instead of re-uploading, why showing beats describing, and then the
part almost everyone skips — building a five-task personal eval set and actually
checking whether the setup helped. It closes on the two failure modes
personalization creates: context that has quietly gone stale, and an assistant so
well tuned to you that it stops disagreeing with you.

Four units, eight lessons, 32 exercises, four quizzes, ~3 hours, grounded in the
few-shot-learning and RAG papers, the published behaviour of the major
assistants, GDPR Article 5, and the NIST AI RMF. The catalog is now **19 courses,
76 units, 152 lessons, 629 exercises, 297 quiz questions**.

The per-lesson "For you" note also got sharper: it must now end with one specific
thing the learner could try today, in a task they already have, rather than
observing that the lesson is relevant to them. The AI cache namespace was
versioned along with it — changing a prompt without that would have shipped the
improvement to nobody, since every existing site would keep serving notes the old
prompt wrote.

### Fixes

- `Mahan_DB::drop_tables()` and `uninstall.php` both missed the `certificates`
  table, shipped in 1.22.0. "Remove all data" left every issued credential
  behind. Both now drop it (and `viva`).
- Five CSS rules referenced `--m-primary-on-surface` / `--m-accent-on-surface`,
  tokens that do not exist, so they silently resolved to their fallbacks instead
  of the AA-legible `--m-primary-text` / `--m-accent-text` they were meant to
  use. Introduced in 1.26.0, fixed here.

### Delivery

- 87 new assertions covering the state machine — server-authoritative scoring,
  bounded probing, ownership, resumption, staleness, XP-once, and that the rubric
  never reaches `public_session()`.
- A 25-check headless render harness driving a whole sitting: probe → stage pass
  → stage pass → final pass, asserting the locked row is inert, the pips track
  the stage, the compose box disappears on completion, and the rubric never
  reaches the DOM.
- Spanish is complete again: 712/712 strings.

## [1.27.0]

Every course is four units deep. **No schema change — DB stays v5.**

v1.25.0 rebuilt the Prompt Engineering ladder to real depth and left the other
fifteen courses honest but thin — around an hour each, which is what they
actually contained. This finishes the job.

| | Before 1.25.0 | After 1.27.0 |
| --- | --- | --- |
| Units | 36 | **72** |
| Lessons | 72 | **144** |
| Exercises | 277 | **597** |
| Quiz questions | 137 | **281** |
| Authored material | 12.7 h | **50.5 h** |
| Recall-only exercises | 81% | **74%** |

Roughly 43,000 words of lesson prose.

### Each course got an arc, not more of the same

The two new units per course are where the subject actually gets difficult,
not an extension of the introduction:

- **ML Foundations** — where the difficulty really lives (data, not
  algorithms), when *not* to use ML, and how to read an accuracy claim.
- **Supervised Learning** — feature engineering, the classification threshold
  as a business decision, cross-validation, and model cards.
- **Practical ML** — drift and silent decay, retraining without breaking
  things, bias that survives dropping the sensitive column, and explaining a
  model to the three audiences that need different things.
- **Generative AI, Explained** — which tasks are worth handing over, reading
  output like an editor, what you may paste, and building one workflow.
- **How LLMs Work** — the context window as the whole world, cost and model
  choice, tool use and agents, and reading a launch announcement without
  being sold to.
- **RAG** — diagnosing whether retrieval or generation failed, keeping a corpus
  honest, measuring faithfully, and when *not* to build RAG at all.
- **ChatGPT ladder** — steering instead of restarting and files/images/voice
  (rung 1); variation, shareable prompts and proportionate checking (rung 2);
  tools, scale economics and handover (rung 3); the politics of adoption,
  policy people will follow, and honest reporting when a pilot fails (rung 4).
- **Claude / Gemini / Image generation / Responsible AI / Productivity** — long
  context and tool choice; integration and currency; composition control and
  where generated images belong; accountability, procurement and incidents;
  where the minutes actually go and what stays yours.

### The validator did its job again

Six courses had `est_hours` that the new content had pushed past an hour
boundary. The check added in 1.25.0 failed the build on all six before anything
shipped — which is the entire reason the number is computed rather than typed.

### Delivery

Everything reaches sites that installed an earlier version through the
strictly additive backfill from 1.25.0: new lessons and unit quizzes appear,
and nothing you edited is touched.

### Tooling

`tools/` gained nothing, but the authoring pipeline did: units are now written
as standalone blocks and spliced in by a small script that refuses rather than
guesses if a file's tail does not match exactly. Hand-editing eighteen deeply
nested data files is how a silent truncation gets shipped.

## [1.26.0]

Profile and dashboard. **No schema change — DB stays v5.**

Ideas taken deliberately from the three systems that solved this already:
Duolingo's activity calendar and lifetime streak record, Coursera's skills and
"My Learning" shelves, Udemy's save-for-later.

### There was no profile

The avatar in the top bar was decoration. The dashboard could answer *what do I
do next*; nothing could answer *what have I become* — and that second question
is the one that makes the hours spent feel like they were worth something.

`?view=profile`, reached from the avatar (and its own stop in the phone bottom
bar, which has no avatar to tap):

- **Identity** — name, the role from their own profile, member-since, level
  title, and the tier they placed at.
- **Six lifetime totals** — XP, lessons, courses, exercises correct, days
  active, longest streak. Lifetime on purpose: a streak resets to zero on one
  missed day, and these never go backwards. "Days active" is a truer measure of
  a habit than a streak for exactly that reason.
- **A 91-day activity calendar** — columns are weeks, so the eye reads a habit
  rather than a list of days. Intensity is scaled against the learner's own
  daily goal, not their best day ever, so one enormous session doesn't make
  every ordinary day afterwards look like failure.
- **Skills you have built** — the topic taxonomy crossed with completed
  lessons. A topic counts only at two lessons or more: one lesson is an
  encounter, not a skill, and a profile listing every topic ever touched says
  nothing. Each chip pivots the catalog to that topic.
- **Certificates and achievements**, with earned ones leading — the profile is
  a record of what was done, so the locked ones are context, not the headline.

### My Learning is three shelves, not one heap

A finished course sitting in "Continue" is noise, and a course someone was
merely curious about had nowhere to live at all. Tabs now separate **In
progress / Completed / Saved**, each with its count, so a tab tells you whether
it is worth opening before you open it. Switching is local — the data is
already on the page, so a tab costs no round trip — and arrow keys move between
them.

### Save for later

A star on every course card, deliberately distinct from enrolling: "I might do
this" and "I am doing this" are different intentions, and a catalog offering
only the second loses everything anyone was curious about. Saving does not
navigate into the course, reports its state to assistive tech, and on failure
puts the star back rather than claiming success.

### Minutes left, not percent done

In-progress cards now say how much work is actually left, summed from the
lessons not yet finished. "60% done" says nothing about whether the rest is ten
minutes or two hours, and that difference decides whether someone starts.

### Fixed along the way

- **`urlFor()` silently dropped unknown params.** A skill chip linking to a
  topic-filtered catalog produced a URL with no topic — the filter worked for
  one paint and vanished on reload or share. `topic` is now a first-class URL
  param, so a filtered catalog is linkable.
- The activity grid stretched its columns to fill the container, which read as
  scattered dots rather than a calendar; columns are now cell-width.
- Profile stat tiles lost their emoji, matching the call 1.20.0 made on the
  dashboard: six icons at that size compete with the numbers, and the numbers
  are the point. The flame stays on the one stat where it means something.

### Under the hood

New `Mahan_Learner` (saved courses, skills, lifetime totals, minutes
remaining) and `Mahan_Gamification::activity_map()`. Everything is derived from
data already stored — no new tables. Two new routes: `GET /profile/summary`
(kept separate from `/me` because its history scans are heavier and the
dashboard reloads far more often) and `POST /save`.

## [1.25.0]

Course quality. **No schema change — DB stays v5.**

### The catalog was overstating itself

Measured before touching anything: the catalog advertised **57 hours** of
material and contained **12.7** — 22% of the claim. Every one of the 18 courses
had the identical shape (2 units × 2 lessons), which is a template filled in
eighteen times, not eighteen authored curricula. And 81% of exercises were
recall — multiple choice, true/false, fill-in-the-blank — which tests whether
someone read the page, not whether they can do anything.

### Duration is now measured, not typed

`est_hours` was a number written by hand next to the content, and the two
drifted for eleven releases because nothing ever compared them. `Mahan_Seed`
now computes the length from what is actually authored — lesson minutes, plus a
flat budget per exercise and quiz question, since those are answered rather than
read. The seed validator fails the build if an authored `est_hours` disagrees
with the computed one, so it cannot drift again.

Every card now shows a smaller, true number. Fifteen courses correctly read
"1 h" — because that is what they contain. That is the honest starting point
for fixing them, and a course card that lies is worse than one that
disappoints.

### The Prompt Engineering track is a real course now

The flagship ladder went from 2 units to 4 on every rung — **12 units, 24
lessons, 98 exercises, 47 quiz questions**, roughly 3 hours each, and each rung
now has an arc rather than a sample:

| Rung | New unit 3 | New unit 4 |
| --- | --- | --- |
| Prompting Foundations | Iterating instead of restarting — diagnosing a bad answer, few-shot | Judgement — where models are weak, and building your own checklist |
| Prompt Patterns & Techniques | Decomposition — chaining, and making the model check its own work | When patterns fail — the four failure modes, and choosing the cheapest pattern |
| Prompt Engineering at Work | Prompts that run without you — writing for a program, handling failure | Measuring and governing — evaluation sets, versioning, ownership |

That last progression is the point: technique → automation → governance is what
separates a course from a tutorial, and it is the template the other tracks
will follow.

### Exercises that ask you to do something

Fourteen new applied exercises — prompt-writing tasks and short answers with
real rubrics — across the eight courses the new validator check flagged as
recall-heavy. They are built around the mistakes that actually happen: target
leakage in an ML feature set, 99.4% accuracy on a 0.6% fraud base rate, a
retrieval miss caused by vocabulary rather than meaning, a policy everyone
ignores. No course is above 80% recall any more, and the validator warns if one
drifts back.

### Delivering deeper courses to sites that already installed

v1.23.0 fixed metadata reaching existing sites but deliberately stopped short of
content — which meant the new units above would only ever have appeared on
brand-new academies. `Mahan_Seed::add_missing_lessons()` closes that gap under a
strictly additive rule:

- a lesson is created **only** when nothing carries its seed key yet
- an existing lesson is **never** rewritten, reordered, or removed, even when
  the library's copy has since changed
- a missing unit quiz is added; an existing one is carried through untouched
  (`save_all()` replaces the whole map, so adding one naively would wipe the rest)
- a course that grew has its advertised length recomputed

The honest cost: a lesson an owner deliberately deleted comes back. Trashing is
not a strong enough signal to tell "I don't want this" apart from "I haven't
seen it yet", and never delivering new material is worse for every other site.

### Validator

Three new checks, one of which found a real bug the moment it ran — an authored
`est_hours` that the new exercises had pushed over an hour boundary:

- `est_hours` must equal the computed duration
- a course needs at least 2 units, and warns below 45 minutes of material
- warns above 80% recall exercises

Also fixed a check that disagreed with the code it validates: the validator used
`empty()` on `answer_text` where `Mahan_Seed` uses `'' === trim()`, so a
perfectly good fill-in-the-blank answer of `"0"` was rejected by the validator
and accepted by the seeder. A validator that disagrees with production is worse
than no validator.

### Still to do

The other three ladders and the standalone courses keep their original depth for
now and read "1 h" honestly. They follow the same four-unit pattern next.

## [1.24.0]

The academy speaks Spanish. **No schema change — DB stays v5.**

### What is bilingual, and what isn't

Every string the plugin itself renders — all 650 of them, across the learner
app, the admin screens, the course and lesson editors, badges, emails chrome,
reports and settings — now has a Spanish translation. That is the interface.

What is **not** translated, and won't pretend to be: course prose, lesson
bodies, quiz questions, and the email templates in Settings. Those are rows in
your database, written by whoever runs the site. The plugin has no business
rewriting them, and saying so plainly is better than a feature that quietly
does half a job. The admin Language panel says the same thing on screen.

### The switch is the learner's, and it is narrow

WordPress already has a site locale, and since 4.7 a per-user locale — but that
one only applies inside wp-admin. Neither covers the case an academy actually
has: a learner reading in Spanish on a site whose theme, admin, and every other
plugin stay in English.

So the switch is scoped to this plugin's textdomain via the `plugin_locale`
filter. Nothing else on the page changes language, and that narrowness is
exactly what makes it safe to hand the control to the learner instead of
reserving it for an administrator:

- A **language picker in the top bar**, on every view, for signed-in learners
  and signed-out visitors alike — a visitor browsing the catalog can read it in
  their own language before deciding to sign up.
- Each language is listed **in its own name** ("Español", not "Spanish"):
  someone hunting for their language can't read the English word for it.
- A guest's choice lives in a cookie and is **adopted onto their account** the
  first time they sign in, so registering doesn't reset them to English.
- The picker **doesn't appear at all** on a site with one language. An inert
  control offering a single option is worse than no control.
- Operators can see and fix a learner's language on the WordPress user profile,
  and can turn learner choice off entirely in *Settings → Appearance → Language*.

`es_MX`, `es_AR` and every other Spanish variant now resolve to the Spanish
catalog rather than falling all the way back to English, which is what those
sites were plainly asking for.

### The tutor answers in your language

Translating the buttons around a tutor that only replies in English is a
half-finished job — the part of the product a learner actually talks to has to
answer them back. The language instruction rides in the shared learner-context
block, so the tutor, the AI grader and the question writer all pick it up at
once. Dates follow the academy's language too: a Spanish certificate no longer
reads "June 2, 2026" because the reader's laptop happens to be set to English.

### Translation tooling, committed

There is no `xgettext`, no `msgfmt` and no WP-CLI in every environment, and a
translation that exists only as a `.po` is a translation WordPress ignores. So
the toolchain ships with the plugin rather than being assumed:

| Tool | What it does |
| --- | --- |
| `tools/make-pot.php` | Extracts translatable strings via `token_get_all()` — literals only, our domain only |
| `tools/i18n-es.php` | The Spanish dictionary, keyed by source string and reviewable as plain PHP |
| `tools/make-po.php` | Merges the two, reporting coverage **and orphans** — entries matching no source string, i.e. translations quietly doing nothing |
| `tools/po2mo.php` | Compiles the binary `.mo` WordPress loads |

`po2mo` deliberately omits untranslated entries: gettext would otherwise return
the empty string as the "translation" and blank the UI, which is worse than
falling back to English.

### Fixed along the way

- **Translations loaded too early.** `load_plugin_textdomain()` ran on
  `plugins_loaded`; WordPress 6.7 flags that, and anything translated before
  `init` is frozen in whatever locale was current at that moment. It now runs on
  `init` priority 0, ahead of the CPT labels and the settings defaults that
  depend on it.
- **A plural translated as a singular.** "Install %d starter courses" was
  getting the singular form in both slots — correct at one course, broken
  Spanish at two. The dictionary now supplies both forms, and the test suite
  fails any plural entry whose two forms are identical.

## [1.23.0]

Finishes what 1.22.0 started. The placement test could measure a learner's
level, then point them at exactly **one** subject — because only the ChatGPT
family was wired as a ladder. Now four subjects are. **No schema change —
DB stays v5.**

### Three more ladders

Prompt Engineering, Machine Learning and Generative AI were already
progressions in everything but metadata. They're now real tracks:

| Track | 1 · Beginner | 2 · Intermediate | 3 · Advanced |
| --- | --- | --- | --- |
| `prompt-engineering` | Prompting Foundations | Prompt Patterns & Techniques | Prompt Engineering at Work |
| `machine-learning` | ML Foundations | Supervised Learning Essentials | Practical ML: Idea to Model |
| `generative-ai` | Generative AI, Explained | How LLMs Work | Grounding AI (RAG) |

Each third rung moved from `intermediate` to `advanced` — the level now matches
the rung, which the ladder UI and the placement result both read. The AI Tools
courses (Claude, Gemini, image generation) are deliberately **not** a track:
they're different tools, not levels of one subject.

Everything that keys off tracks now works across the catalog rather than for
one subject: level ladders on four course pages, "Step 2 of 3" on cards, and a
placement result that names a starting point in every subject.

### The upgrade path that would have been missed

`Mahan_Seed::install()` skips courses that already exist — correctly, because
the site owner may have edited them. But "don't touch the content" was
implemented as "skip entirely", which means **metadata added in a later version
could never reach a site that had already seeded**. Every existing install would
have kept a flat catalog forever, and the ladders above would only have appeared
on brand-new sites.

- New `refresh_course_meta()` updates only what the plugin owns — ladder track,
  rung, and the level the rung implies — and never title, body, lessons or
  exercises.
- References are filled in only when absent, so a curated set is never
  overwritten.
- `maybe_refresh_structure()` runs the sweep once per version, claimed with an
  atomic per-version `add_option` (no separate lock that a fatal mid-sweep could
  strand, blocking every future refresh).

### Operator visibility

An operator handed a serial by a candidate had no way to look it up — the admin
never showed certificates at all.

- **Certificates issued** table in Reports (serial, recipient, course, date)
  with a total count and a **CSV export**, run through the same
  formula-injection guard as the existing export.
- **Placement levels** table showing how the audience actually splits across
  the four tiers — the answer to "is my catalog pitched at the people I have?".

### Validator

The seed validator now checks ladder coherence, which is exactly the class of
mistake made by hand above: duplicate rungs on a track, a level that disagrees
with its rank, a rank outside 1–4, `level_rank` with no track, and gaps in a
ladder (a gap silently drops a learner placed at the missing level to a lower
rung). A single-rung track warns rather than errors. All five failure modes were
confirmed to actually fire against injected bad data — a validator that never
fails is worthless.

### Verification

- Seed validator PASS, now printing all four ladders; its five new checks
  negative-tested against deliberately broken fixtures.
- **15-assertion refresh suite**: an existing course picking up track/rung/level,
  owner-edited fields and post content left alone, missing references filled but
  curated ones preserved, no track meaning no level rewrite, idempotence,
  invalid ids writing nothing, and the atomic per-version gate.
- All seven logic suites and all twelve render harnesses still pass; `php -l`
  and `node --check` clean.

## [1.22.0]

Two things a learning platform needs and this one didn't have: a way to find
out **where a learner should start**, and a credential at the end that is
actually **worth something**. **First schema change in eleven releases —
DB goes to v5** for the certificates table.

### Placement test — `Mahan_Placement`

A 12-question assessment that decides which rung of each ladder a learner
starts on, before they pick anything.

- **Authored, not AI-generated.** A placement test is the first thing a new
  learner meets: it has to work on a site with no API key, give the same answer
  twice, and be reviewable by whoever runs the academy. The bank is data
  (`includes/data/placement.php` — 32 questions, 8 per tier) and the scoring is
  arithmetic.
- **Balanced sittings.** An even spread across the four tiers, served
  easiest-first — opening an assessment with an expert question makes beginners
  quit before it can place them.
- **The level rule is "highest tier you actually demonstrated"**, not a total:
  you place at the top tier where you got two-thirds right *and* cleared every
  tier below it. Someone who guesses one expert question right but misses the
  intermediate ones is not an expert, and a score-based rule would call them one.
- **No feedback during the test.** Telling people how they're doing changes how
  they answer the rest.
- Skipping is allowed — an unanswered question is no evidence, which the tier
  rule already handles, rather than a wrong answer.
- The result syncs into the profile's `ai_level`, so the tutor, question
  difficulty and recommendations all pick it up with no extra wiring, and the
  result screen links straight to the matching rung of every ladder.

**Caught while writing it:** the bank had the right answer at index 1 on nearly
every question, so "always pick B" would have scored full marks. Rather than
hand-shuffling the data (where the same bias would creep back in with the next
contributor), options are now permuted per sitting from `(question key, seed)`
and the choice is mapped back when grading. There's a test that asserts
same-slot guessing fails.

### Certificates that mean something — `Mahan_Certificates`

The old "certificate" was a card the browser drew on demand, stamped with
*today's* date — reopen it next month and the date changed, nothing was
recorded, and nobody could check it.

- **Issued automatically** on course completion (hooked to
  `mahan_course_completed`), not when someone finds a button.
- **A real record**, with the date it was actually earned and a serial like
  `MA-2026-7F3KQX92`. Sequential ids would let anyone enumerate every credential
  the site has issued, so the random part carries the entropy; ambiguous glyphs
  (0/O, 1/I) are excluded because these get typed off a printout.
- **Publicly verifiable.** `GET /certificate/{serial}` needs no login — a
  credential only its holder can check verifies nothing — and returns just who,
  what and when. No user id, no email, no progress. There's a verification page
  at `?view=verify`, and a pasted link checks itself.
- **Idempotent.** Issuing twice returns the same credential; the unique key on
  `(user_id, course_id)` is the real guard, so two concurrent completions can't
  mint two serials.
- Serials normalise on lookup — lower case, missing dashes, stray spaces — so a
  real holder isn't told their real serial is invalid.
- **Back-filled once** for everyone who completed a course before this shipped;
  otherwise their only route to a credential would be re-taking a course they
  had already finished.
- The dashboard lists what you hold; the course page shows the issued record
  rather than drawing a card whether or not anything was awarded.

### Verification

- **27-assertion placement suite**: bank integrity, balanced and
  easiest-first sittings, the answer key never leaving the server, same-slot
  guessing failing, a perfect run through the shuffle scoring 12/12,
  mismatched-seed grading not silently passing, all six level-rule cases,
  skipping, and profile sync that doesn't wipe the rest of the profile.
- **25-assertion certificate suite** against a fake `$wpdb` honouring both
  unique keys: issuing, double-issue returning one row, the per-course and
  site-wide switches, 200 distinct serials with no ambiguous glyphs,
  verification exposing no internal ids, four typed-off-paper serial forms,
  revocation, and listing.
- **16-assertion headless run** driving both flows end to end.
- All eleven earlier render harnesses still pass; `php -l`, `node --check` and
  the seed validator clean.

## [1.21.0]

**Every course looks like itself.** Until now the whole app was one indigo and
covers varied only by hue, so a category section could read as the same card
printed four times. Now a course carries its own identity — cover pattern and
accent colour — from the catalog card all the way into its lessons.
**No new tables, DB stays v4.**

### Cover pattern families

Six pattern families (diagonal weave, dot grid, ruled grid, rays, concentric
arcs, cross-hatch), picked from the course title, layered over the existing
category hue. Two courses in one category now share a colour family without
sharing a look. All pure CSS gradients — still no image requests.

### Course accent

One class on the course/lesson wrapper redefines `--m-course` for everything
inside it, derived from the same identity the cover uses.

- Applied to **identifying chrome only**: category kicker, unit headings, topic
  chips, progress meters, the reading-progress hairline, the current ladder
  rung, and a tint on the course hero card.
- **Primary buttons deliberately keep the brand colour.** The button you press
  must not move around the palette from one course to the next — that rule is
  asserted in the test suite, not just written down.
- Lessons inherit their course's accent (`/lesson` now returns
  `course_categories`), so a course reads as itself all the way down instead of
  every page being brand indigo.

### Contrast, measured rather than assumed

Each of the six families has a separate AA-legible on-surface text shade per
theme (`--m-course-text`), the same fill/text split as `--m-primary`. Measuring
caught three real problems that eyeballing would have shipped:

- The amber accent came out at exactly **4.50:1** on its own chip tint — no
  margin at all. Darkened.
- The green accent measured **4.29:1** on the current-rung tint. Darkened.
- Painting the *solid* current ladder rung with the course fill put white text
  on cyan/green/amber at **1.8–3.7:1**. That rung is now tinted with accent-
  coloured text instead of filled.

Worst case across all 12 accents (6 families × 2 themes), on the surface, on
the chip tint and on the rung tint, is now **4.54:1**.

### Verification

- 10-assertion headless run: pattern families in use, one category = one hue
  while its courses still differ by pattern, the theme class on both the course
  and its lessons, accent-coloured chrome, **the CTA still on brand**, the rung
  tinted rather than white-on-accent, and **every accent passing AA in both
  themes as the browser actually computes it**.
- Two of those assertions failed first as *harness* bugs, both fixed: Chromium
  returns `color-mix()` as `color(srgb 0–1)`, which the probe was reading as
  0–255 (making every tint look near-black), and `rgb(154, 69, 8)` was being
  string-compared against `#9a4508`. The browser-measured numbers now match the
  offline math exactly.
- All ten earlier render harnesses still pass; `php -l`, `node --check` and the
  seed validator clean.

## [1.20.0]

A second UI pass, this one about **restraint**. 1.19.0 added things; this one
takes things away so what's left can lead. Front-end only — **no new tables, DB
stays v4**.

### One dominant block per screen

The dashboard stacked three full-width banners in three different tints — amber
review, lavender resume, white stats — so nothing led and the page had no answer
to "what do I do now?".

- The review CTA is now a **single-line strip**. It still comes first (it's the
  time-sensitive task) but it no longer competes for the eye.
- "Continue learning" is therefore the page's **one primary button** again,
  instead of being demoted to a ghost whenever a review was due.

### Fewer colours doing less work

- **Stats lost their emoji hats.** 🔥 stays on the streak, because the flame
  carries state; ⚡ ◆ ❄️ only restated the label directly beneath them, in three
  more colours, rendered differently on every OS.
- **The at-risk streak is a dimmed flame, not a candle.** Swapping in a
  different object made the learner decode a new symbol instead of reading a
  state change.
- **The level meter is one flat accent.** It was an indigo→green gradient: the
  loudest element on the page, and the hue shift meant nothing.
- **Week dots are filled or not.** The old ring-with-a-`·`-inside was a shape,
  not information. A goal-met day still gets a tick.

### Entrance motion

Card grids now lift in as they scroll into view — 460ms, transform and opacity
only, a 55ms stagger capped at 8 so a long grid's last card isn't left waiting.

It is strictly **opt-in**: the animating class is added by JS, never by CSS, so
with no JS, no `IntersectionObserver`, or `prefers-reduced-motion: reduce`, the
content is simply there. Nothing is ever hidden and then revealed.

### Fixed

- **Grids injected after mount never animated.** The recommendations strip loads
  async and is the most prominent grid on the catalog — it was the one thing
  that never moved. `revealGroups()` now arms late-arriving content too.
- **Uncategorised courses all shared one cover colour**, so a grid of them came
  out monochrome. They now seed off their own title instead.

### Verification

- 14-assertion headless run: the async above-the-fold grid revealing with no
  scrolling (the case that used to be missed), scrolling revealing the rest,
  stagger indices and the cap, the animation always settling fully opaque,
  **reduced motion skipping it entirely**, **no-JS hiding nothing**, the review
  strip being materially shorter than the resume block, exactly one dominant
  primary button, one stat icon and three plain stats, a flat level meter,
  labelled week days with ticks only where earned, the dimmed-flame cold state,
  and uncategorised covers still differing.
- All nine earlier render harnesses still pass; `php -l` and `node --check`
  clean.

## [1.19.0]

A UI pass, driven by actually looking at the thing in both themes. Front-end
only — **no new tables, DB stays v4**.

### Course covers

Courses without a featured image got a flat block with one oversized letter. In
light mode it read as a *missing* image; in dark mode the letter was indigo on
navy and effectively invisible. Every course now has real cover art:

- Six saturated gradients with a soft light source and a faint diagonal weave,
  drawn entirely in CSS — no image is fetched, nothing to upload.
- **Hue comes from the category**, so a category reads as a colour family. It is
  a hand-assigned map for the six categories the plugin ships, because hashing
  six names into six buckets collides essentially every time.
- **The title varies the gradient angle and nudges the hue**, so four sibling
  courses on one ladder don't look like the same card four times.
- It's a pure function of the category name, not of position in the catalog: a
  course opened directly shows the same cover it had on the card you tapped.
- Covers are `aria-hidden` — decoration, not information. Cards don't repeat the
  category on the cover (it's already in the card body); the wide hero cover does.

### Hierarchy

- **Search moved into the hero.** It used to sit below the recommended and
  bundle strips — roughly a screen down at 1280×800. The control most people
  reach for first was the last one they could see. The hero title and subtitle
  were scaled back to make room.
- **The course hero now shows where you stand**: percent, `2 of 4 lessons`, and
  a progress bar directly above the CTA. "Resume" on its own never said resume
  *what*, or how far in. Certificate courses show a tag for it.
- Every course hero gets a cover, not only ones with an uploaded image.

### Depth, in dark mode too

Dark surfaces sit close to the background, so the 1px border that carried the
whole card in light mode did almost nothing. Cards, heroes, sections, the tutor
panel, modals and the top bar now get a hairline top highlight plus a real
two-layer shadow in dark.

### Smaller things

- **Avatar falls back to initials.** A user with no avatar rendered a broken
  `<img>` leaking its alt text into the top bar. Also recovers on image error.
- **The HUD reads as a cluster**, not one run-on string: each stat gets its own
  padded, hoverable pill.
- **The tutor is no longer a tall blank box.** It opens with three questions
  built from the lesson's own topics; tapping one sends it, and they clear as
  soon as there's a real conversation.

### Verification

- 15-assertion headless run: search above the fold, initials fallback with no
  `<img>` avatar left, covers on every card with no legacy placeholder, covers
  hidden from assistive tech, **each category exactly one colour and no two
  categories sharing one**, siblings still varying, course-page cover matching
  the catalog card, standing meter values, tutor openers sending and clearing,
  and real dark-mode elevation. Zero JS errors.
- All eight earlier render harnesses still pass; seed validator and the four
  logic suites (variants 18, recommend 9, personalize 15, practice 19) unchanged.

## [1.18.0]

A UX pass on the two things six feature releases made harder: **finding the
right course**, and **not losing your place inside one**. Front-end only —
**no new tables, DB stays v4**.

### Filters that explain themselves

Six releases each added a way to narrow the catalog (search, level, category,
topic) and none of them said what was active or how much was left.

- A live **result count** — `18 courses` unfiltered, `Showing 3 of 18 courses`
  once anything is on. It's a persistent `role="status"` region whose text is
  swapped in place (a live region rebuilt on each repaint doesn't announce).
- **One removable pill per active filter**, including the ones whose own control
  is off screen — a collapsed topic panel, a scrolled-past chip row — plus a
  single **Clear all**. This replaces the topic-only banner, which explained one
  filter out of four.
- **Level chips are derived from the catalog**, not hardcoded. This fixes a real
  gap from 1.17.0: the list stopped at Advanced, so the new **Expert** courses
  could not be filtered for at all. Levels nobody teaches no longer show a dead
  chip, and a level added later needs no code change.

### Wayfinding

- Catalog cards on a level ladder now show **which rung they are** — `Step 2 of
  4` — so four sibling courses read as a progression instead of near-duplicates.
  `course_summary()` carries `track` + `level_rank`; the client counts the rungs.
- A **Contents** button in the lesson header opens the course outline over the
  reader: units, lesson states (done / locked), the current lesson marked **You
  are here** and deliberately not a link. Jump anywhere without backing out.
  It reuses the cached `/course` payload when there is one.
- A **reading-progress hairline** for the lesson body — the bar in the header
  tracks the whole course, this one answers "how much of *this* is left?". It
  hides itself when the lesson fits on one screen.

### Keyboard

- `/` search · `←` `→` previous / next lesson · `C` continue where you left off
  (from any view) · `?` the shortcut sheet · `Esc` clear filters / close.
- Deliberately conservative: never with a modifier, never while a dialog owns
  the keyboard, and never while the learner is typing — the tutor input and
  every answer box keep every character. `Esc` only claims the key when there is
  actually a filter to drop.
- A `⌨` hint in the top bar makes them discoverable, hidden on touch.

### Fixed

- `go()` no longer dies on a rejected `pushState` (sandboxed or exotically
  hosted documents) — the URL stays put but the view still changes.
- New: view teardown (`onUnmount`) for listeners a view attaches outside its own
  DOM, so a departed view stops reacting to scroll and resize.

### Verification

- 20-assertion headless run over every new surface — data-driven level chips,
  count text, pill add/remove/clear, rung tags, `/` focus, `?` sheet, `Esc`
  clear, live reading bar, contents drawer (both units, current marked and
  unlinked, jump navigates), `←`/`→`, and `C` resume — plus a second pass at
  390px and in dark mode checking no horizontal overflow and that the shortcut
  hint is hidden on touch. Zero JS errors. All six earlier render harnesses
  still pass.

## [1.17.0]

Levels & department variants. A course is no longer one-size-fits-all: subjects
now climb a **four-rung ladder** (Beginner → Intermediate → Advanced → Expert),
and inside a lesson the material **specializes to the learner's department**
(marketing, sales, finance, HR, management, …). **No new tables — DB stays v4.**

### The two-axis design

Levels and fields are handled on *different* axes on purpose, because authoring
15 subjects × 4 levels × 6 departments as separate courses would mean 360
courses to maintain:

- **Level = a real course.** Each rung is its own published course sharing a
  `track` with its siblings, ordered by `level_rank` (1–4). Learners see the
  whole ladder and move between rungs.
- **Field = a render-time overlay.** One course body, plus per-department blocks
  attached to the lesson, merged when the lesson is served. Nothing is
  duplicated, and a subject without hand-written blocks still specializes
  through the existing AI personalization layer.

### New — `includes/class-mahan-variants.php`

- `LEVELS` / `FIELDS` vocabularies, `normalize_level()` and `level_rank()`
  (case-insensitive; anything unknown resolves to `beginner`).
- `track_ladder($track)` returns the published courses on a track sorted by
  rank — the data behind the ladder UI.
- `field_for_user()` maps the profile's role to a department (`founder` →
  management, and so on), so nobody has to pick their field twice.
- `pick()` resolves a field to a block: exact match, else the `general` block,
  else nothing. `apply()` merges the block into the lesson body and reports
  **which** field actually applied — so a `general` fallback never renders as
  "Tailored for Marketing". That honesty is covered by the test suite.

### The ChatGPT ladder — the worked example

- `course-tool-chatgpt.php` becomes rung 1 of the `chatgpt` track, joined by
  three new courses: **ChatGPT at Work** (intermediate), **ChatGPT for
  Professionals** (advanced), and **ChatGPT Mastery** (expert).
- Every lesson in the three new rungs ships **five department variants**
  (marketing, sales, finance, HR, management) — **60 hand-written blocks** in
  all — so a finance learner and an HR learner reading the same lesson get
  different worked examples, metrics, and cautions.
- New **ChatGPT Mastery Track** bundle collects all four rungs into one program.

### Learner-facing

- The course page shows the **level ladder** for its track: the current rung is
  marked and the others are one click away.
- A lesson carrying a matched block shows a **"🎯 Tailored for <field>"** badge
  and renders the department section inline; the badge appears only on a real
  match.
- Onboarding's AI-experience question is now a **four-tier** scale with
  descriptive labels that line up with the ladder, and **management** joins the
  role list.
- `/lesson` returns `field` + `field_label`; `/course` returns `ladder`.

### Library

- **18 courses, 72 lessons, 277 exercises, 36 quizzes (137 questions), 68
  topics, 73 references, 60 department-variant blocks**, across 6 categories and
  5 bundles.

### Verification

- 18-assertion variants logic test (level normalization, ladder ordering, role→
  field mapping, exact/general/no-match resolution, fallback reporting); seed
  validator extended to check the 4th level tier and every variant field/body;
  the ladder, the tailored badge, and the variant block were driven in headless
  Chromium with zero console errors.

## [1.16.0]

Reference-grounded curriculum. Every course now carries the **authoritative
sources** it is built on, shown as "Further reading" — and the library grows with
two new reference-led courses. **No new tables — DB stays v4.**

### References layer

- New `references` field in the course data contract (`{ title, source, url? }`),
  stored by `Mahan_Seed` as `_mahan_references` and read via
  `Mahan_Courses::course_references()`; exposed on `/course`.
- The course page renders a **"Further reading & sources"** section. External
  links open in a new tab with `rel="noopener noreferrer"`; a reference without a
  URL renders as plain text, so citation-only sources (books) work too.

### Every existing course upgraded

- All 13 previously shipped courses now cite **4 authoritative references each**
  — peer-reviewed papers (*Attention Is All You Need*, GPT-3, InstructGPT/RLHF,
  RAG, Stochastic Parrots, Latent Diffusion, DDPM, Model Cards, Hidden Technical
  Debt), standards and institutions (**NIST AI RMF**, **UNESCO**, **OWASP LLM
  Top 10**, **C2PA**, **U.S. Copyright Office**, **Stanford HAI AI Index**),
  textbooks (*ISLR*, *ESL*, *AIMA*), and official vendor documentation.

### Two new courses

- **Responsible AI & Governance** (new **Responsible AI** category) — govern /
  map / measure / manage from the **NIST AI Risk Management Framework**, where
  bias enters and what reduces it, transparency and human oversight, and
  risk-tiered governance in the spirit of the **EU AI Act**, with the **OECD**
  and **UNESCO** principles as the backdrop.
- **Grounding AI in Your Own Knowledge (RAG)** (Generative AI) — why ungrounded
  models invent answers, the retrieve-then-generate loop, embeddings and hybrid
  search, chunking and source hygiene, and how to evaluate citations and
  refusals. Grounded in **Lewis et al. (2020)** and **Karpukhin et al. (2020)**.
- The library now ships **15 courses, 60 lessons, 235 exercises, 30 quizzes
  (116 questions), 58 topics, 61 references** across **6 categories** and
  **4 bundles**; RAG joins the Generative AI Essentials bundle, and the
  recommendation engine routes HR/finance/founder and support-goal learners
  toward Responsible AI.

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
