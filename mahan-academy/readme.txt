=== Mahan Academy ===
Contributors: mahan
Tags: lms, ai, learning, chatgpt, claude, gemini, course, tutor, gamification
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.22.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A standalone AI-learning platform for WordPress. Coursera-style course structure, Duolingo-style interactive practice, and a real-time AI tutor — powered directly by Anthropic, OpenAI, or Google.

== Description ==

Mahan Academy is a self-contained learning plugin built to teach people how to use AI in their daily work. No LMS dependency. No external automation services. Just WordPress, custom post types, custom tables, and direct calls to the AI provider of your choice.

**Key features:**

* Standalone courses & lessons (no Tutor LMS, LearnDash, etc. required)
* Built-in Starter Content Library — 15 professionally written courses across Prompt Engineering, Machine Learning, Generative AI, AI for Productivity, hands-on AI Tools (ChatGPT, Claude, Gemini, image generation), and Responsible AI, organized into categories and Coursera-style bundles with interactive exercises and unit quizzes. Installs automatically so the academy is never empty
* Level ladders: subjects climb four rungs — Beginner → Intermediate → Advanced → Expert — as real, separate courses on a shared track, with the whole ladder shown on the course page so learners can move up (or drop back) at any time
* Department-specific lessons: the same lesson specializes to the learner's field (marketing, sales, finance, HR, management, …) with its own worked examples, metrics, and cautions, marked by a "Tailored for X" badge — mapped automatically from the profile role, and dropped silently rather than faked when there is no match for that field
* Reference-grounded: every course cites the authoritative sources it is built on — peer-reviewed papers, standards bodies (NIST AI RMF, EU AI Act, UNESCO, OWASP, C2PA), textbooks, and official documentation — shown as "Further reading" on the course page
* Every lesson is personalized — lesson text adapts to your role, goal, and tools via {{placeholders}}, plus an AI "For you" note on how each lesson applies to your own work
* "Recommended for you" — the catalog leads with the courses that fit your role, goal, and level, with a plain-language reason why
* Concept "topics" (مباحث): a shared vocabulary tagged on courses and lessons — shown as chips in the learner UI — that also tells the AI tutor and question generator exactly which concepts each lesson covers
* Smart Practice: a one-tap "Generate practice" on any lesson that asks AI for fresh questions tuned to the lesson's concepts and your level (with misconception-targeting distractors), grades them instantly, and feeds anything you miss into spaced repetition
* Category filter + bundle discovery right in the catalog (Coursera/Duolingo-style domains and specializations)
* Browse by subject & topic: the catalog groups courses into category sections and offers a "Browse by topic" panel to filter by any concept (مباحث); course topic chips are clickable to pivot to everything on the same topic
* Visual drag-and-drop Course Builder — build the whole curriculum on one screen
* AI authoring assistant — generate outcomes, draft lessons, and create exercises
* Beautiful single-page application front-end (Coursera structure + Duolingo energy)
* Automatic course cover art: every course gets a generated CSS gradient cover — hue by category so a subject area reads as a colour family, and one of six pattern families (weave, dot grid, ruled grid, rays, arcs, cross-hatch) plus angle and tone by title, so sibling courses never look like the same card twice. No uploads, no image requests, and it looks right in both light and dark themes
* Per-course identity: each course carries its own accent through its kicker, unit headings, topic chips, meters and ladder — and into its lessons. Primary buttons stay on the brand colour so the button you press never moves around the palette. Every accent is contrast-checked in both themes
* Designed for dark mode, not just recoloured for it: cards, heroes, panels and dialogs carry real elevation on dark surfaces
* Real-time streaming AI tutor via Server-Sent Events (Anthropic / OpenAI / Google) — with a structured teaching playbook (diagnose → explain at your level with a worked example from your world → check understanding → point to the next step) and misconception-aware, difficulty-tuned practice questions
* Five exercise types: multiple choice, true/false, fill-in-the-blank, short answer, and prompt-writing (open answers graded by AI)
* Adaptive personalization engine: a professional onboarding intake builds a rich learner profile that (with a live progress + difficulty signal) tailors the tutor, AI grading, and generated practice questions to each learner's role, level, and goals
* Gamification: XP with a full audit log, streak XP multipliers, linear or RPG-style progressive levels & titles, daily XP goals, streaks with earned streak freezes, weekly activity dots, 21 tiered achievements with live unlock notifications and progress bars, and weekly + all-time leaderboards
* Polished learner UX: instant catalog search, "Jump back in" resume, lesson wayfinding (unit · lesson X of Y), confetti course-completion celebrations, offline-aware errors, and instant back/forward navigation
* Filters that explain themselves: a live result count ("Showing 3 of 18 courses") with a removable pill for every active filter — search, level, category, topic — and one Clear all
* In-lesson Contents drawer: jump to any lesson in the course without backing out, with your current lesson marked; plus a hairline showing how far through the lesson you've read
* Keyboard shortcuts: / to search, ← → to move between lessons, C to continue where you left off, ? for the list, Esc to clear
* Adaptive review: wrong answers are re-asked at the end of the lesson and again on later days (spaced repetition), with an optional AI "ask it a different way" from another model
* End-of-unit quizzes with instant grading and a passing score
* Learning paths — group courses into a guided, ordered program
* Email notifications (welcome, completion, achievement, streak reminder)
* Admin reports & analytics with CSV export
* Placement test: a short assessment that measures where a learner actually is and starts them on the matching rung of every course ladder. Authored questions and arithmetic scoring — no API key needed, same answer twice. Options are shuffled per sitting, so guessing the same slot every time gets you nowhere
* Verifiable certificates: issued automatically the moment a course is completed, with the date actually earned and a unique serial. Anyone can check a serial at the public verification page without logging in, and it reveals only who completed what, and when
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

= How do I get started fast with real courses? =
You don't have to do anything — the starter catalog installs automatically on activation, so the academy is never empty. It covers Prompt Engineering, Machine Learning, Generative AI, AI for Productivity, AI Tools, and Responsible AI, grouped into categories and Coursera-style bundles, with interactive exercises, unit quizzes, and cited sources. You can also re-run it any time from *Mahan Academy → Dashboard*; the install is idempotent, never duplicates, and never touches courses you made yourself. Edit or delete any of it like normal WordPress content.

= Where does the course content come from? =
Each course lists the authoritative references it is grounded in under "Further reading & sources" on the course page — peer-reviewed papers, standards and institutional guidance (NIST AI Risk Management Framework, EU AI Act, UNESCO, OECD, OWASP, C2PA), textbooks, and official provider documentation.

== Changelog ==

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
