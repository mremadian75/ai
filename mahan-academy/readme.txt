=== Mahan Academy ===
Contributors: mahan
Tags: lms, ai, learning, chatgpt, claude, gemini, course, tutor, gamification
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.34.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A standalone AI-learning platform for WordPress. Coursera-style course structure, Duolingo-style interactive practice, and a real-time AI tutor — powered directly by Anthropic, OpenAI, or Google.

== Description ==

Mahan Academy is a self-contained learning plugin built to teach people how to use AI in their daily work. No LMS dependency. No external automation services. Just WordPress, custom post types, custom tables, and direct calls to the AI provider of your choice.

**Key features:**

* Standalone courses & lessons (no Tutor LMS, LearnDash, etc. required)
* Built-in Starter Content Library — 19 professionally written courses across Prompt Engineering, Machine Learning, Generative AI, AI for Productivity, hands-on AI Tools (ChatGPT, Claude, Gemini, image generation), AI personalization, and Responsible AI, organized into categories and Coursera-style bundles. Every course is four units deep: 152 lessons, 629 interactive exercises and 76 unit quizzes — around 53 hours of material. Installs automatically so the academy is never empty
* Honest course lengths — the hours on a card are computed from the lessons, exercises and quizzes actually in the course, not typed in by hand, and the build fails if the two ever disagree
* Level ladders: subjects climb four rungs — Beginner → Intermediate → Advanced → Expert — as real, separate courses on a shared track, with the whole ladder shown on the course page so learners can move up (or drop back) at any time
* Department-specific lessons: the same lesson specializes to the learner's field (marketing, sales, finance, HR, management, …) with its own worked examples, metrics, and cautions, marked by a "Tailored for X" badge — mapped automatically from the profile role, and dropped silently rather than faked when there is no match for that field
* Reference-grounded: every course cites the authoritative sources it is built on — peer-reviewed papers, standards bodies (NIST AI RMF, EU AI Act, UNESCO, OWASP, C2PA), textbooks, and official documentation — shown as "Further reading" on the course page
* Every lesson is personalized — lesson text adapts to your role, goal, and tools via {{placeholders}}, plus an AI "For you" note on how each lesson applies to your own work
* "Recommended for you" — the catalog leads with the courses that fit your role, goal, and level, with a plain-language reason why
* Concept "topics" (مباحث): a shared vocabulary tagged on courses and lessons — shown as chips in the learner UI — that also tells the AI tutor and question generator exactly which concepts each lesson covers
* Live assessment (AI oral exam): at the end of each unit, an AI examiner runs a three-stage viva — explain the idea in your own words, apply it to a scenario built from your own role and tools, then judge a trade-off or a limit. Answers are prose, graded 0–100 against a rubric the examiner wrote when it set the question; pass a stage and the next one opens, answer partly right and it probes once on the exact gap. The score is decided on the server, the rubric never reaches the browser, and the sitting survives closing the tab
* Smart Practice: a one-tap "Generate practice" on any lesson that asks AI for fresh questions tuned to the lesson's concepts and your level (with misconception-targeting distractors), grades them instantly, and feeds anything you miss into spaced repetition
* Category filter + bundle discovery right in the catalog (Coursera/Duolingo-style domains and specializations)
* Browse by subject & topic: the catalog groups courses into category sections and offers a "Browse by topic" panel to filter by any concept (مباحث); course topic chips are clickable to pivot to everything on the same topic
* Visual drag-and-drop Course Studio — build the whole curriculum on one screen, Tutor-LMS style: collapsible unit cards with live summaries, inline lesson add (type a title, press Enter), status badges (video, draft, still-empty), and a full lesson editor in a modal — rich text with the media library, a video field with live preview (YouTube / Vimeo / mp4), minutes and XP — without ever leaving the course
* Lesson videos: paste a video link on any lesson and learners get a responsive player above the lesson text; URLs pass a strict whitelist so only YouTube, Vimeo and direct media files ever embed
* AI authoring assistant — generate outcomes, draft lessons, and create exercises
* Beautiful single-page application front-end (Coursera structure + Duolingo energy)
* Automatic course cover art: every course gets a generated CSS gradient cover — hue by category so a subject area reads as a colour family, and one of six pattern families (weave, dot grid, ruled grid, rays, arcs, cross-hatch) plus angle and tone by title, so sibling courses never look like the same card twice. No uploads, no image requests, and it looks right in both light and dark themes
* Per-course identity: each course carries its own accent through its kicker, unit headings, topic chips, meters and ladder — and into its lessons. Primary buttons stay on the brand colour so the button you press never moves around the palette. Every accent is contrast-checked in both themes
* Designed for dark mode, not just recoloured for it: cards, heroes, panels and dialogs carry real elevation on dark surfaces
* Real-time streaming AI tutor via Server-Sent Events (Anthropic / OpenAI / Google) — with a structured teaching playbook (diagnose → explain at your level with a worked example from your world → check understanding → point to the next step) and misconception-aware, difficulty-tuned practice questions
* Five exercise types: multiple choice, true/false, fill-in-the-blank, short answer, and prompt-writing (open answers graded by AI)
* Adaptive personalization engine: a professional onboarding intake builds a rich learner profile that (with a live progress + difficulty signal) tailors the tutor, AI grading, and generated practice questions to each learner's role, level, and goals
* Gamification: XP with a full audit log, streak XP multipliers, linear or RPG-style progressive levels & titles, streaks with earned streak freezes, weekly activity dots, 24 tiered achievements with live unlock notifications and progress bars, and weekly + all-time leaderboards. Daily goals are a real mechanic: reaching yours pays a bonus, is recorded permanently the day it happens (so changing your goal never rewrites the past), and builds a goal streak with its own achievements
* A dashboard that answers "what do I do now?": a single Today block names the one thing worth doing — fading review items first, then a finished unit's live assessment, then the course you were last working on — with everything else as quiet one-tap rows underneath, plus a momentum line showing this week against last and how close the next streak freeze is
* Polished learner UX: instant catalog search, "Jump back in" resume, lesson wayfinding (unit · lesson X of Y), confetti course-completion celebrations, offline-aware errors, and instant back/forward navigation
* Filters that explain themselves: a live result count ("Showing 3 of 18 courses") with a removable pill for every active filter — search, level, category, topic — and one Clear all
* In-lesson Contents drawer: jump to any lesson in the course without backing out, with your current lesson marked; plus a hairline showing how far through the lesson you've read
* Keyboard shortcuts: / to search, ← → to move between lessons, C to continue where you left off, ? for the list, Esc to clear
* Adaptive review: wrong answers are re-asked at the end of the lesson and again on later days (spaced repetition), with an optional AI "ask it a different way" from another model
* End-of-unit quizzes with instant grading and a passing score, four question types including "select all that apply", and a per-question explanation shown the moment the attempt is graded. Options are permuted per sitting — and re-permuted on every retake — so a quiz cannot be passed by remembering which slot the right answer sat in. Fill-in-the-blank accepts a single typo on longer answers and tells you the exact spelling
* Learning paths — group courses into a guided, ordered program
* Email notifications (welcome, completion, achievement, streak reminder)
* Admin analytics dashboard: a date-range view (7/30/90/365 days) with trend charts and vs-previous-period deltas, a learning funnel (enrolled → started → halfway → completed), per-course health including each course's drop-off lesson, the hardest exercises ranked as a revision list, live-assessment pass rates, a study-pattern weekday chart, and day-by-day CSV export — all charts server-rendered SVG, no charting library, plus the register of every issued certificate and the placement-level breakdown
* Student management: a Students screen listing everyone actually learning — searchable, filterable by course and recency, sortable, paginated, and exportable to CSV with the filters applied. Each student opens into a full file: stats, enrollments with progress meters, enroll / unenroll / reset-progress actions with honest semantics (unenroll keeps progress; reset destroys it but never certificates or the XP log), reversible certificate revoke/restore, and a recent-activity feed
* Placement test: a short assessment that measures where a learner actually is and starts them on the matching rung of every course ladder. Authored questions and arithmetic scoring — no API key needed, same answer twice. Options are shuffled per sitting, so guessing the same slot every time gets you nowhere
* Verifiable certificates: issued automatically the moment a course is completed, with the date actually earned and a unique serial. Anyone can check a serial at the public verification page without logging in, and it reveals only who completed what, and when
* Featured courses, promo videos, prerequisites, and printable completion certificates
* Bilingual interface — English and Spanish, shipped compiled and ready. Each learner picks their own language from the top bar and reads the academy in it while the rest of the site stays in its own; the AI tutor answers in that language too. Course text and email templates are yours and stay as you wrote them
* Fully translatable, with the gettext toolchain (POT extractor, PO merger, MO compiler) included so you can add a language without installing gettext binaries
* Learner profile: lifetime totals that never go backwards, a 91-day activity calendar, and "Skills you have built" earned from the topics whose lessons you actually completed — each one pivoting the catalog to more of the same
* My Learning shelves: In progress, Completed and Saved, each with its count; plus save-for-later stars on every course card and "43 min left" on the ones you are part-way through
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

= How do I get started fast with real courses? =
You don't have to do anything — the starter catalog installs automatically on activation, so the academy is never empty. It covers Prompt Engineering, Machine Learning, Generative AI, AI for Productivity, AI Tools, and Responsible AI, grouped into categories and Coursera-style bundles, with interactive exercises, unit quizzes, and cited sources. You can also re-run it any time from *Mahan Academy → Dashboard*; the install is idempotent, never duplicates, and never touches courses you made yourself. Edit or delete any of it like normal WordPress content.

= Where does the course content come from? =
Each course lists the authoritative references it is grounded in under "Further reading & sources" on the course page — peer-reviewed papers, standards and institutional guidance (NIST AI Risk Management Framework, EU AI Act, UNESCO, OECD, OWASP, C2PA), textbooks, and official provider documentation.

== Changelog ==

= 1.34.1 =
Two fixes from a live install. Topic names containing an ampersand showed their HTML entity on screen — a topic called "Email & writing" rendered as "Email &amp; writing" on the lesson chip and in the AI tutor's suggested questions. WordPress stores taxonomy names HTML-escaped because core prints them straight into markup, while the app renders them as text; term names are now decoded at the JSON boundary, so any name with an ampersand, quote or angle bracket reads correctly everywhere. And learning paths, which had been left on a flat placeholder with the same compass icon on every card, now use the same generated cover art courses have had since v1.19 — seeded from the path title, so each one has its own colour, pattern and initial instead of five identical cards. No schema change.

= 1.34.0 =
Gamification: the daily goal becomes real. Until now it was a progress bar with nothing behind it — you set a target, the ring filled, and nothing happened: no reward, no acknowledgement, nothing recorded. Worse, whether a past day counted was recomputed against your current goal every time the week strip rendered, so lowering your goal made days you had missed sprout ticks, and raising it erased days you had genuinely earned. Meeting your goal is now an event: it pays a bonus (configurable, 15 XP by default) and writes a permanent record the day it happens, so history stops moving when the setting does. The claim is guarded so two requests finishing together can never both pay it. Runs of met goals are tracked as a goal streak — a stronger signal than the activity streak, because it means you hit a target you set yourself — shown on the goal card with your personal best, and backed by three new achievements: On Target, Week of Wins (7 days) and Ironclad (30 days). Also fixes a case where the goal bonus could carry a learner across a level threshold without levelling them up, and replaces a fragile before/after inference in the celebration with the server's own goal event, which used to re-fire on every award once you were past the goal and stay silent when two requests landed together. Schema change: DB goes to v7.

= 1.33.0 =
The quiz engine, and a real integrity bug fixed. Measured across this plugin's own catalog, 77% of multiple-choice answers sat in option B — against a 70% pass mark, which means tapping the second option on every question, reading nothing, passed almost every unit quiz and also cleared the spaced-repetition queue that re-asks the same items. The placement test was hardened against exactly this in v1.22 by permuting options per sitting; unit quizzes and the review queue never were. Now they are, sharing one implementation: a quiz is served in a per-sitting permutation whose seed travels with it and comes back on submit, so grading reconstructs the order without storing anything, and a retake reshuffles — a failed attempt cannot be answered from memory of where the right option was. Review items permute per encounter too, so spaced repetition rehearses the concept rather than a position. After the fix, "always tap B" scores 16.5% instead of 77%. Authors can opt a question out for the rare option set whose order matters. Alongside that: questions gain an explanation, editable in the Course Studio, stripped from everything served before grading and released with the result where it teaches instead of giving the answer away; a new "select all that apply" type graded all-or-nothing that tells the learner how many to pick; and fill-in-the-blank now forgives one slipped key on answers of six characters or more — showing the exact spelling — while short and case-sensitive answers stay exact. The quiz header shows your attempt number and best score. Also fixes a REST sanitiser that cast every answer to a string, which would have flattened every multi-select answer to the literal "Array". No schema change.

= 1.32.0 =
The learner dashboard, rebuilt around what to do next. It used to stack a review banner, a resume banner and a placement nudge and leave the learner to rank them; now a single "Today" block names the one thing worth doing and lists the rest as quiet one-tap rows underneath. The ranking is by what is actually lost by not doing it today: due review items decay, so they lead; a unit whose lessons are all finished has no next lesson to resume, so its live assessment is the natural next step and outranks starting new material; then continuing the course you were last working on; then placement, once, if it was never taken. This also brings the AI oral exam to the dashboard for the first time — until now you had to walk into a course to discover a unit was waiting. Fixes a real misdirection: the resume card pointed at the course you most recently enrolled in rather than the one you were actually studying, so signing up for something on a whim would hide the course you had been working through all week; both the plan and the In-progress shelf now order by real activity. Adds a momentum line under the week dots — lessons this week against last week, XP this week, and how many more days earn the next streak freeze, promised only when it is genuinely reachable and omitted entirely on a brand-new account. No schema change.

= 1.31.0 =
The Reports screen becomes a real analytics dashboard. A date-range switcher (7/30/90/365 days) drives four hero cards — enrollments, active learners, lessons completed, XP awarded — each with a sparkline and an honest delta against the previous window: something-from-nothing reads "New" rather than an infinite percentage, and active learners are distinct people over the window, never a sum of daily actives. An activity chart overlays lessons, enrollments and completions day by day; every chart is server-rendered inline SVG, so there is no charting library and the dashboard works with scripts disabled. Below it, the parts that say where learners get stuck: a learning funnel (enrolled → started → halfway → completed) that can never go back up; a course-health table extended with exercise accuracy, quiz pass rate, live-assessment pass rate and each course's drop-off point — the lesson most often the last thing a non-finisher completed, linked and annotated; a hardest-exercises ranking noise-gated to five attempts so one unlucky guess can't top the chart; a weekday study-pattern chart; and live-assessment tiles whose pass rate is computed over decided sittings only. Rates with no data render as a dash, not a misleading 0%. The day-by-day series exports to CSV with the current range applied. All-time totals, top learners, recent completions, the placement spread and the certificate register remain. No schema change.

= 1.30.0 =
Student management. A new Students screen lists everyone who is actually learning — enrolled in something or carrying stats, not every WordPress user — with XP, level, streak, last-active date, enrollments, completions and valid certificates per row; searchable by name or email, filterable by course and by recency (active in the last 7 or 30 days, or inactive), sortable, paginated, and exportable to CSV with the current filters applied. Clicking a student opens their file: profile and placement tags, six lifetime stat tiles, enrollments with live progress meters and enroll / unenroll / reset-progress actions, certificates with reversible revoke and restore, and a recent-activity feed with human labels. The destructive semantics are deliberate: unenrolling keeps progress (it comes back on re-enroll); resetting progress deletes lesson progress, exercise attempts and the review queue for that course but never certificates or the XP audit log; certificates are only ever revoked or restored, never deleted. Every value that reaches SQL is whitelisted or clamped first, every mutation sits behind a capability check, a nonce and a confirm, and CSV cells that could be spreadsheet formulas are neutralised on export. No schema change.

= 1.29.0 =
The Course Studio: the admin course builder rebuilt in Tutor-LMS style so a whole course is authored on one screen. Every lesson row gains an Edit button that opens a full editor in place — the lesson body in the classic rich-text editor with the media library, a video field that validates and previews YouTube / Vimeo / direct-file links as you paste them, a segmented type control, minutes and XP — with a dirty-guard so closing an edited lesson asks first. Lessons can now carry a video that learners see as a responsive player above the lesson text; pasted URLs pass a strict server-side whitelist so only YouTube, Vimeo and direct media files ever embed. The curriculum tree became collapsible unit cards with live summaries, per-lesson status badges (video, draft, and an "empty" warning on lessons with no body yet), and inline lesson add — type a title, pick a type, press Enter. Content saved from the studio passes wp_kses_post, exactly like the classic editor. Also fixes a CSS-token scoping bug that rendered the modal's selected type segment white-on-white. No schema change.

= 1.28.1 =
A deep bug hunt over the new live-assessment code, with a regression test behind every fix. A provider failure at a stage boundary used to bank that stage's score without moving off the stage, so answering it again counted it twice — a sitting could score 320 out of 300 and report 107%; all three failure paths now leave the sitting exactly as they found it, which also stops a provider hiccup from spending one of the learner's two attempts. Two final answers landing together (a double tap, a retried request) could both be paid the 60 XP; the closing transition is now guarded so MySQL picks one winner. A grading failure persisted the learner's turn even though the browser still held the text, so re-sending showed the same answer twice. A unit whose title had a trailing space could never be examined, because the title was cleaned before being matched against the course's own titles. And a four-unit course page issued sixteen queries it did not need, re-fetching the lesson list and progress map once per unit; the whole course now costs two queries and one fetch. Also adds a daily cap on new sittings — resuming is always free — because nothing else bounded what a learner could spend of the site's API budget. No schema change.

= 1.28.0 =
A live AI examiner. Every assessment in the plugin until now asked you to recognise the right answer; this one asks you to say it. At the end of each unit — once its lessons are done — an AI examiner runs a three-stage viva: explain the core idea in your own words, apply it to a scenario built from your own role, tools and goal, then judge a trade-off or a limit. Answers are prose, graded 0–100 against a rubric the examiner wrote when it set the question. Pass a stage and the next opens; answer partly right and it probes once on the exact gap before the attempt counts. The score is the server's — a model that says "pass" on a 30 is overruled, and the rubric is stripped before the session reaches the browser, so there is nothing to forge and nothing to read the answer off. Stages, attempts, turns and answer length are all capped, and the sitting is a database row rather than a transient, so closing the tab mid-exam loses nothing. Passing pays XP once per unit ever. With no API key configured the feature does not appear at all. Alongside it, a nineteenth course — "Personalizing AI: Make It Work Like You Do" — teaches what the rest of the catalog had been assuming: why answers are generic by default, the four levers that change it, and how to check whether your setup actually helped. The per-lesson "For you" note now has to end with one thing you could try today. Fixes: "remove all data" left the certificates table behind, and five CSS rules pointed at colour tokens that do not exist. Schema change: DB goes to v6.

= 1.27.0 =
Every course is now four units deep. v1.25.0 rebuilt the Prompt Engineering ladder and left the other fifteen courses honest but thin at roughly an hour each; this release finishes the job. The Machine Learning, Generative AI and ChatGPT ladders and all five standalone courses gained two units apiece, each with a genuine arc rather than more of the same — where difficulty really lives and when not to use ML at all; the context window, cost and tool use; the politics of adoption and honest reporting when a pilot fails; diagnosing whether retrieval or generation broke; accountability, procurement questions and incident response. The catalog goes from 12.7 to 50.5 hours of authored material: 72 units, 144 lessons, 597 exercises, 281 quiz questions, roughly 43,000 words. Course lengths stayed honest throughout — the validator caught six courses whose advertised hours had fallen behind the new content, which is exactly what it was added for. Recall-only exercises fell from 81% to 74%. Everything reaches existing sites through the additive backfill shipped in 1.25.0, which never touches a lesson you edited. No schema change.

= 1.26.0 =
Profile and dashboard, with ideas taken deliberately from Duolingo, Coursera and Udemy. There was no profile at all before — the avatar was decoration. Now it opens one: identity and role, six lifetime totals (XP, lessons, courses, exercises correct, days active, longest streak), a 91-day activity calendar whose columns are weeks so you read a habit rather than a list of days, "Skills you have built" derived from the topics whose lessons you actually finished (each chip pivots the catalog to that topic), plus your certificates and achievements with earned ones leading. My Learning is now three shelves instead of one heap — In progress / Completed / Saved — each with its count, switching locally with no round trip and navigable by arrow key. Every course card gains a save-for-later star, deliberately separate from enrolling. In-progress cards say how many minutes of work are actually left, summed from the lessons you have not done, because "60% done" does not tell you whether the rest is ten minutes or two hours. Also fixes a URL builder that silently dropped unknown params, so a topic-filtered catalog is now properly linkable and survives a reload. No schema change.

= 1.25.0 =
Course quality. Measured first: the catalog advertised 57 hours of material and contained 12.7 — and all 18 courses had the identical 2-unit shape. Course length is now computed from what is actually authored (lesson minutes plus a budget per exercise and quiz question) instead of typed by hand, and the seed validator fails if the two disagree, so it cannot drift again. Cards now show smaller, true numbers. The Prompt Engineering ladder was rebuilt to real depth — 4 units on every rung, 24 lessons, 98 exercises, ~3 hours each — with a genuine progression from technique to automation to governance, and that is the template the other tracks follow next. Fourteen new applied exercises (prompt-writing and short answer with real rubrics) across the courses that were almost entirely multiple-choice; no course is above 80% recall now. Deeper courses also reach sites that already installed: a strictly additive backfill installs lessons and unit quizzes that are missing, and never rewrites, reorders or removes anything already there — including owner-edited unit quizzes. Also fixes a validator check that disagreed with the seeder and rejected a valid fill-in-the-blank answer of "0". The other tracks keep their original depth for now and read "1 h" honestly. No schema change.

= 1.24.0 =
The academy is now bilingual: English and Spanish. All 650 interface strings are translated — learner app, admin screens, editors, badges, reports and settings. Course prose, quiz questions and email templates are deliberately NOT translated: those are your rows in your database, and the plugin says so on screen rather than half-doing the job. A language picker in the top bar lets each learner (and each signed-out visitor) read the academy in their own language, while the rest of the site stays in its own — the switch is scoped to this plugin's textdomain, so nothing else on the page moves. A guest's choice follows them onto their account when they register. Spanish variants like es_MX and es_AR now resolve to the Spanish catalog instead of falling back to English. The AI tutor, grader and question writer answer in the learner's language, and dates format in it too. Translation tooling (POT extractor, dictionary, PO merger, MO compiler) ships with the plugin, so the academy stays translatable without depending on gettext binaries being installed. Also fixes translations loading before `init` (flagged by WordPress 6.7) and a plural that used the singular form in both slots. No schema change.

= 1.23.0 =
Finishes what 1.22.0 started. The placement test could measure your level but then point you at only one subject, because only the ChatGPT family was wired as a ladder. Prompt Engineering, Machine Learning and Generative AI are now real three-rung tracks too (Foundations -> Patterns/Supervised/LLMs -> At Work/Practical/RAG), so ladders, "Step 2 of 3" card tags and the placement result work across the catalog. Also fixes an upgrade path that would otherwise have been silently missed: the seeder skips courses that already exist, which meant metadata added in a later version could never reach a site that had already installed — every existing site would have kept a flat catalog forever. A structural refresh now updates only what the plugin owns (ladder wiring), never your edited content, once per version. For operators: the admin Reports screen now lists issued certificates with a CSV export, plus a placement-level breakdown of your audience. No schema change.

= 1.22.0 =
Placement tests and real certificates. A new 12-question placement test works out which rung of each ladder a learner should start on before they pick anything — authored questions and arithmetic scoring, so it works with no API key and gives the same answer twice. You place at the highest tier you actually demonstrated (two-thirds right, and every tier below it cleared), not on a total, so one lucky expert answer can't call a beginner an expert. Certificates are now genuine credentials: issued automatically when a course is completed, recorded with the date actually earned and a serial like MA-2026-7F3KQX92, and publicly verifiable at ?view=verify with no login. The old certificate was a card the browser drew on demand stamped with today's date, recorded nowhere and checkable by nobody. Anyone who already finished a course is back-filled once. First schema change in eleven releases: DB goes to v5.

= 1.21.0 =
Every course now looks like itself. Covers gained six pattern families (weave, dot grid, ruled grid, rays, arcs, cross-hatch) picked from the course title and layered over the category hue, so two courses in one category share a colour family without sharing a look. Each course also carries its own accent colour through its category kicker, unit headings, topic chips, progress meters and the current ladder rung — and into its lessons, so a course reads as itself all the way down instead of every page being brand indigo. Primary buttons deliberately stay on the brand colour: the button you press must not move around the palette from course to course. Every one of the twelve accents (six families x two themes) was measured rather than eyeballed; three that failed AA were fixed, and the worst case is now 4.54:1. No new tables.

= 1.20.0 =
A second UI pass, this one about restraint — 1.19.0 added things, this one takes things away so what's left can lead. The dashboard stacked three full-width banners in three tints and nothing led; the review CTA is now a single-line strip, which makes "Continue learning" the one primary action again. Stats lost their emoji hats (the flame stays, because it carries state — an at-risk streak is now a dimmed flame instead of a cryptic candle), the level meter went from a two-hue gradient to one flat accent, and week dots are simply filled or not. Card grids now lift in as they scroll into view — strictly opt-in, so no JS, no IntersectionObserver, or reduced motion means the content is just there. Fixes: grids loaded after the page (the recommendations strip) never animated, and uncategorised courses all shared one cover colour. Front-end only, no new tables.

= 1.19.0 =
UI pass. Every course now has real cover art — CSS gradients with the hue set by category (so a category reads as a colour family) and the angle and tone varied by title (so four courses on one ladder don't look identical); the old placeholder was a flat block with one letter that was effectively invisible in dark mode. Search moved into the hero, where you can actually see it — it used to sit a screen below the fold. The course hero now shows where you stand (percent, "2 of 4 lessons", progress bar) right above Resume, and gets a cover of its own. Cards, heroes and panels finally have real depth in dark mode. The tutor opens with three questions built from the lesson instead of a blank box, avatars fall back to initials instead of a broken image, and the HUD reads as separate stats. Front-end only, no new tables.

= 1.18.0 =
UX pass on finding and not losing your place. The catalog now states how many courses you're looking at and why ("Showing 3 of 18"), with a removable pill for every active filter and one Clear-all; level chips are built from the catalog itself, so Expert courses became filterable (they weren't). Cards on a level ladder show which rung they are ("Step 2 of 4"). Inside a lesson, a Contents button opens the course outline so you can jump lessons without backing out, and a hairline tracks how far through the lesson you've read. Keyboard shortcuts throughout: / search, ← → lesson, C continue, ? help, Esc clear. Front-end only, no new tables.

= 1.17.0 =
Levels & department variants — subjects now climb a four-rung ladder (Beginner → Intermediate → Advanced → Expert) shown on the course page, and lessons specialize to the learner's department (marketing, sales, finance, HR, management) with a "Tailored for X" badge. The ChatGPT track ships all four rungs with 60 hand-written department blocks, plus a new ChatGPT Mastery Track bundle; onboarding gains a four-tier experience scale and a management role. Library is now 18 courses / 72 lessons / 277 exercises / 36 quizzes / 73 references. No new tables.

= 1.16.0 =
Reference-grounded curriculum — every course now cites the authoritative sources it is built on (papers, NIST/EU AI Act/UNESCO/OWASP/C2PA, textbooks, official docs), shown as "Further reading" on the course page, and all 13 existing courses were upgraded with 4 references each. Two new courses: Responsible AI & Governance (new Responsible AI category, built on the NIST AI RMF and EU AI Act risk tiers) and Grounding AI in Your Own Knowledge (RAG). The library is now 15 courses / 60 lessons / 235 exercises / 30 quizzes / 61 references. No new tables.

= 1.15.0 =
Personalized discovery — the catalog now leads with a "Recommended for you" strip matched to your role, goal, and level (with the reason shown), plus a best-fit learning path. Pure deterministic scoring, no AI call, no new tables. Covered by a scoring test and a headless render.

= 1.14.0 =
Courses now ship by default (auto-installed, incl. a new AI Tools bundle — ChatGPT, Claude, Gemini, image generation), every lesson is personalized (profile placeholders + an AI "For you" note), and a bug-fix pass hardens grading and XP (quiz-XP re-award under ONLY_FULL_GROUP_BY, a blank-option answer-index shift, lesson-completion double-award/farming, and a practice-XP cap). No new tables. Verified with logic tests + headless render.

= 1.13.0 =
Browse by subject & topic — the catalog now groups courses into Coursera-style category sections and adds a "Browse by topic" panel to filter by any concept (مباحث); course topic chips are clickable. Front-end only, no new tables. Verified in a headless browser.

= 1.12.0 =
Smart Practice — a one-tap AI practice generator on every lesson that writes fresh, concept- and difficulty-matched questions (misconception-aware, instantly graded, fed into spaced repetition), plus concept-topic chips in the learner UI. No new tables — DB stays v4. Backend covered by a standalone logic test; the panel was driven end-to-end (generate → grade) in a headless browser.

= 1.11.0 =
Curriculum Library — a one-click Starter Content installer that ships professionally written courses across four categories, grouped into Coursera-style bundles; a new concept-"topics" taxonomy on courses and lessons; category + bundle discovery in the catalog; and a smarter tutor (structured teaching playbook) with misconception-aware, difficulty-tuned practice questions. No new tables — DB stays v4. Seed data validated by a standalone schema+answer-key checker; catalog verified in a headless browser.

= 1.10.0 =
Adaptive personalization — the tutor, AI grading, and generated practice questions now tailor themselves to each learner, and onboarding is a richer professional intake. New personalization engine backed by logic tests; onboarding verified by rendering in a headless browser.
* Professional onboarding: the intake now also asks where you are in your career and how you like to learn, on top of role, company, AI experience, goal, tools, and biggest challenge — and explains up front that the more you share, the more everything adapts. A confirmation shows when your learning is personalized.
* A "learner context" (your profile plus your live progress) is now given to the AI tutor on every message, so it meets you at your level, uses examples from your role and tools, and connects lessons to your goal.
* Adaptive difficulty: the system reads your experience level and how you're doing (mastered vs. still-learning reviews, your level) to pick a target difficulty, and pitches generated questions and tutor explanations there.
* Smarter question design: the "ask it a different way" review questions are now personalized — set in a scenario from your world and tuned to your current level — instead of a generic reword.
* AI grading feedback now uses the full learner context, so it's pitched at your level and speaks to your goals.
* No new data tables — personalization is stored in your existing profile. Database version unchanged (still v4).

= 1.9.4 =
Keyboard & screen-reader navigation pass, verified by driving the real front-end in a headless browser (skip link, arrow-key focus, live region).
* Added a "Skip to content" link — the first thing keyboard users reach — that jumps straight past the navigation to the lesson/page content.
* Answer options can now be moved through with the arrow keys (with Home/End), skipping disabled options and wrapping around — a faster, more standard keyboard flow on top of the existing 1–9 number-key shortcuts.
* Screen readers now announce the new view each time you navigate within the app, so blind and low-vision learners know where they've landed instead of being left on a stale page.
* Styling + front-end script only — no functional or content changes. Database version unchanged (still v4).

= 1.9.3 =
Accessibility & UI-states pass driven by a six-lens visual-design audit, verified by rendering the dashboard, a graded quiz, disabled fields and empty states in a headless browser in both themes.
* Fixed several colour-contrast (WCAG AA) failures: the HUD XP/level/streak/freeze counters, progress percentages, quiz scores, tags and active-nav pill now use legible on-surface shades in whichever theme they were failing — core gamification numbers are readable at a glance in both light and dark.
* Disabled and locked controls now clearly look disabled: buttons and answer options no longer light up on hover when disabled, and a solved answer field or the tutor box (when the AI isn't configured) now shows a proper greyed-out, not-editable state.
* In a graded quiz/review, the option you picked wrong is now outlined in red — not just the correct one shown in green — so your own mistake is unmistakable.
* Empty and error screens (no search results, empty dashboard/leaderboard/paths, generic errors) now show a focal circular icon, matching the polished review empty state.
* Mobile hardening: the top bar can't force a sideways scroll on narrow phones, long lesson tokens/tables wrap or scroll inside their own box, toasts no longer cover the bottom navigation, and a few small tap targets were brought up to 44px; landscape-notch safe-area insets added.
* Motion & rhythm polish: modals fade/scale in on desktop, exercise feedback and hints ease in instead of snapping, dashboard section headers sit on the type scale with correct spacing, and shared motion/spacing tokens were introduced.
* Styling only — no functional or content changes. Database version unchanged (still v4).

= 1.9.2 =
Typography & reading-experience refinement (continues the 1.9.x visual series), verified by rendering long-form lesson content in a headless browser in both themes.
* Lesson content is now capped to a comfortable reading measure (~66 characters per line) instead of running the full column width, and body text uses a more generous line-height — long lessons are noticeably easier to read.
* Refined prose rhythm: tighter, more consistent heading spacing; links get a cleaner offset underline that thickens on hover; the first block no longer adds stray top space.
* In-page headings now scroll clear of the sticky top bar when linked to.
* Modernised the font stack (system-ui first, dedicated emoji fallbacks, optical sizing) for crisper, more consistent text across platforms.
* Styling only — no functional or content changes. Database version unchanged (still v4).

= 1.9.1 =
UI depth & elevation refinement (follows the 1.9.0 visual pass), verified by rendering the dashboard and lesson components in a headless browser in both themes.
* Inset elements — dashboard stat tiles, XP/level/progress tracks, lesson & path step circles, the daily-goal card, soft tags, tutor reply bubbles, and code blocks — now use a dedicated layered surface. In dark mode they read as distinct raised cards instead of near-black "holes"; in light mode they gain crisper definition.
* Dashboard stat tiles gain a hairline border, and the streak-at-risk tile a matching warm outline, so the stats grid reads as a clean set of cards.
* Card and panel shadows are now softer, two-layer depth (a subtle contact shadow plus a wider ambient one) for a more premium feel.
* Styling only — no functional or content changes. Database version unchanged (still v4).

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
