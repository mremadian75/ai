# Mahan Academy — Admin Guide

A practical walkthrough for course creators. No coding required.

---

## 1. First-time setup

1. **Install & activate** the plugin (Plugins → Add New → Upload Plugin →
   `mahan-academy.zip`).
2. On activation the plugin auto-creates an **Academy** page containing the
   `[mahan_academy]` shortcode — that page is your public learning app.
3. Go to **Mahan Academy → Settings → AI Provider** and paste an API key for at
   least one provider (Anthropic, OpenAI, or Google). Click **Test connection**
   — you should see *"Connection successful! (OK)"*.

> The same provider powers the real-time tutor, AI grading of open answers, and
> the AI authoring assistant.

---

## 2. Create a course

**Mahan Academy → Courses → Add Course.**

Fill in:

| Field | What it does |
| --- | --- |
| **Title / content** | Course name and the long description shown on the course page. |
| **Subtitle** | One-line tagline on the card and hero. |
| **Level** | Beginner / Intermediate / Advanced (also a catalog filter). |
| **Estimated hours** | Shown as a badge. |
| **What you'll learn** | One outcome per line → a checklist on the course page. Click **✨ Generate with AI** to draft these from the title + description. |
| **Promo video URL** | YouTube, Vimeo, or a direct `.mp4` — embedded on the course hero. |
| **Prerequisite course** | A soft "recommended first" note (not enforced). |
| **Featured** | Sorts the course first in the catalog and adds a ribbon. |
| **Completion certificate** | Offers a printable certificate when a learner finishes (requires the global certificates setting to be on). |
| **Featured image** | The card/hero image. |

Publish the course.

---

## 3. Build the curriculum (the Course Studio)

Scroll to the **Curriculum builder** box on the course editor. The whole course
is authored here — you should almost never need to leave this screen.

- **+ Add unit** — creates a numbered section card. Click its title to rename;
  the chevron (or the header itself) collapses it, and the toolbar has an
  **Expand / collapse all**. Each card shows a live summary: lessons, minutes,
  and whether it has a quiz.
- **Add a lesson inline** — every unit ends with a dashed row: pick a type,
  type the title, press **Enter**. Focus stays put, so you can sketch a whole
  unit in one sitting.
- **Edit** (on every lesson row) opens the full lesson editor right there:
  - the **lesson body** in the rich-text editor, with **Add Media** for images;
  - a **Video** field — paste a YouTube, Vimeo, or direct `.mp4`/`.webm` link
    and you get an instant "✓ YouTube"-style verdict plus a live preview.
    Learners see the video as a responsive player above the lesson text;
  - **Type** (Reading / Video / Practice), **Minutes**, and **XP**;
  - closing with unsaved changes asks first.
- **Status badges** on each row tell you what still needs work: a video mark,
  minutes, XP, exercise count, **Draft**, and an **"empty"** warning on any
  lesson that has no body yet.
- **Drag** the handle to reorder lessons within a unit, move them between
  units, or reorder whole units.
- **Duplicate** / **Delete** from the row; the ↗ icon opens the lesson in the
  normal WordPress editor when you prefer it.
- **Quiz / Add quiz** (per unit) opens the quiz editor — a passing score, XP,
  and four question types: multiple choice, **select all that apply**,
  true/false, and fill-in-the-blank. Learners see the quiz card at the end of
  that unit. Each question also takes an **Explanation** — the learner sees it
  only after submitting, so it teaches without giving the answer away.

  Two things the quiz engine does for you, worth knowing when you write
  questions:

  - **Answer order is shuffled for every learner, on every attempt.** You do
    not need to vary where you put the right answer, and a learner who fails
    cannot pass a retake by remembering the position. If an option set only
    makes sense in order (e.g. it ends with "all of the above"), that
    question can be marked to keep its order.
  - **Fill-in-the-blank forgives one typo** on answers of six characters or
    more, and tells the learner the exact spelling. Short answers and
    case-sensitive ones are matched exactly.

  "Select all that apply" is graded all-or-nothing, and learners are told how
  many options are correct.

Everything saves automatically as you edit — watch the "Saved" indicator. Only
the lesson editor has an explicit **Save lesson** button, because a half-typed
lesson body should never autosave over the real one.

> Ordering and unit grouping are stored on each lesson, so the builder and the
> individual lesson editor always stay in sync.

---

## 4. Add lesson content & exercises

Open a lesson (**Edit content** from the builder, or Mahan Academy → Lessons).

- Write the lesson body in the block editor.
- **AI assistant** (side box): enter a topic and click **Generate draft** to get
  a ready-to-paste HTML lesson.
- **Interactive exercises** (main box): click **+ Add exercise**, or
  **✨ Generate exercises with AI** to create a set from the lesson content.

### Exercise types

| Type | Grading |
| --- | --- |
| **Multiple choice** | Instant. Pick the correct option; add optional correct/incorrect feedback. |
| **True / False** | Instant. Choose which is correct. |
| **Fill in the blank** | Instant. Set the expected answer, optional accepted synonyms, and case sensitivity. Put `___` in the question to mark the blank. |
| **Short answer** | Graded by AI against your rubric. |
| **Reflection** | Graded by AI (encouraging, rubric-based). |
| **Prompt-writing task** | Graded by AI — evaluates the prompt the learner wrote for a task. |

XP is awarded the first time a learner gets an exercise right.

### Live assessment (the AI oral exam)

Each unit also carries a **live assessment** — nothing to author, and nothing to
switch on beyond having an API key configured. Once a learner finishes every
lesson in a unit, a row appears under it and opens a three-stage conversation
with an AI examiner: explain the idea in their own words, apply it to a scenario
built from their own role and tools, then judge a trade-off or a limit.

Things worth knowing as an owner:

- **You do not write the questions.** They are generated from the unit's own
  lessons and concepts, so a unit you edit is examined on what it now says.
- **The score is decided on your server**, not by the model's own verdict, and
  the grading rubric never reaches the learner's browser.
- **It costs API calls** — roughly two per answer (one to grade, one to set the
  next question). Stages, attempts and answer length are all capped, so a single
  sitting has a hard ceiling.
- **It is resumable.** A learner who closes the tab returns to the same question.
  A sitting nobody touches for a day is retired automatically.
- **Passing pays 60 XP once per unit, ever.** Retaking for a better score costs
  nothing and pays nothing.
- **A learner may open at most 12 new sittings a day.** Resuming one they are
  already in the middle of is always free and never counts.
- With **no API key configured, the row does not appear at all.**

---

## 5. Gamification

**Settings → Gamification:**

- **XP per lesson / exercise** and **XP per level** (the level curve).
- **Level curve mode** — *Linear* (every level costs the same) or *Progressive*
  (RPG-style: level N costs N × "XP per level" — early levels come fast, later
  ones are earned).
- **Streak XP bonus** — extra % XP per full week of streak (capped at +50%).
  Rewards consistency; set 0 to disable.
- **Default daily XP goal** — learners see a 🎯 goal in the HUD and a progress
  card on the dashboard, and can pick their own target (10–100 XP).
- **Daily streak** tracking, plus **streak freezes**: learners earn a freeze
  every N consecutive days (default 7, holding up to 2). If they miss a day,
  a freeze is consumed automatically and the streak survives — shown as ❄️
  next to the flame.
- **Achievements** — 21 tiered badges across lessons, courses, streaks,
  levels, XP, quizzes (including a perfect-score badge), exercises, and
  learning paths. Unlocks pop up instantly as toasts and are showcased on the
  dashboard.
- **Leaderboard** — opt-in, with **This week** and **All time** tabs. Learners
  outside the top 20 still see their exact rank.
- **Level titles** — optional names per level (one per line: Novice, Explorer,
  Practitioner …). The last name is reused for higher levels.

Every XP award is recorded in an audit log with its reason, which is what
powers the weekly leaderboard, the dashboard's last-7-days activity dots, and
the "XP this week" report — the numbers are exact, not estimates.

Learners also see live celebrations for streak extensions and daily-goal
completion, progress bars toward locked achievements, and a confetti moment
when they finish a course.

**Adaptive review** (Settings → Gamification → *Adaptive review*): when on, the
plugin remembers every question a learner answers wrong (multiple choice,
true/false, and fill-in-the-blank, from both exercises and unit quizzes) and
re-asks it:

- **At the end of the lesson** — finishing a lesson that had mistakes drops the
  learner straight into a quick review of just those questions.
- **On later days** — missed questions come back on a spaced-repetition
  schedule (a few minutes, then 1, 3, 7, 16, 35, 75 days) that stretches out
  each time they answer correctly, until the item is "mastered". A dashboard
  *Practice your mistakes* card shows how many are due.
- **"Ask a different way"** — during a review the learner can tap this to have
  the AI re-pose the question from a fresh angle. If you've configured more
  than one AI provider, the reworded question is generated by a *different*
  model, so the same concept is tested in varied ways.

Set the XP awarded per cleared review right below the toggle. Clearing reviews
also counts toward the daily goal and keeps the streak alive.

---

## 6. Learning paths

**Mahan Academy → Learning Paths → Add Path.** A path is a guided program that
groups several courses in a recommended order.

- Give the path a title, description, image, and subtitle.
- In **Path courses**, pick courses from the dropdown and drag to order them.
- Learners get a **Paths** tab in the app: each path shows its courses in order
  with their progress, plus the path's overall completion. Paths are a guide —
  they don't lock courses.

## 7. Email notifications

**Settings → Emails.**

- Master toggle + **From name / email**.
- Per-email toggles and editable **subject + body**:
  - *Enrollment / welcome* — when a learner enrolls.
  - *Course completed* — when they finish a course.
  - *New achievement* — when they earn a badge.
  - *Daily streak reminder* — a wp-cron nudge for learners with an active streak
    who haven't studied today.
- Placeholders: `{{name}}`, `{{course}}`, `{{badge}}`, `{{streak}}`, `{{site}}`,
  `{{academy_url}}`, `{{login_url}}`. Basic HTML is allowed in the body.

## 8. Students

**Mahan Academy → Students.** Everyone who is actually learning — enrolled in
at least one course or carrying stats — with XP, level, streak, last-active
date, enrollments, completions, and valid certificates per row. Not a list of
every WordPress user.

- **Find people**: search by name or email, filter by course, filter by
  recency (active in the last 7 / 30 days, or inactive), sort by any column.
- **Export CSV** exports what you're looking at — the current search, course
  and activity filters stay applied.
- Click a name to open the **student file**: profile + placement tags, six
  lifetime stat tiles, and four sections you can act from.

Actions on the student file — and what they really do:

| Action | What happens | What is kept |
| ------ | ------------ | ------------ |
| **Enroll in course** | Adds an enrollment, same as if they enrolled themselves. | Everything. |
| **Unenroll** | Removes the enrollment only. | **All progress** — it comes back if they re-enroll. |
| **Reset progress** | Deletes lesson progress, exercise attempts and the review queue *for that course*, and rewinds the enrollment to 0%. | **Certificates and the XP log** — earned credentials and the audit trail survive a reset. |
| **Revoke certificate** | The certificate stops verifying on the public page. The row is kept and struck through. | Everything — **Restore** undoes it completely. |

Destructive actions ask for confirmation and say exactly what they will and
won't delete. Nothing on this screen can silently destroy a credential.

## 9. Reports & analytics

**Mahan Academy → Reports.** A read-only analytics dashboard. Pick a window
at the top — **7 / 30 / 90 days or a year** — and the top of the page follows.

**The trend view:**

- Four hero cards — enrollments, active learners, lessons completed, XP
  awarded — each with a sparkline and a change vs the previous window
  ("+18% vs previous period"). "New" means there was nothing to compare
  against, and *active learners* counts distinct people, not visit-days.
- An **Activity** chart overlaying lessons completed, enrollments and course
  completions day by day.
- **Export activity (CSV)** — the same day-by-day numbers, with your current
  range applied.

**Where learners get stuck:**

- **Learning funnel** — of everyone who ever enrolled, how many started, got
  halfway, and finished.
- **Course health** — per course: enrolled, completed, completion %, average
  progress, exercise accuracy, quiz pass, live-assessment pass, and the
  **drop-off point**: the lesson where most non-finishers stopped, linked so
  you can go fix it. A dash means "no data yet", not zero.
- **Hardest exercises** — the exercises learners fail the most, ranked. This
  is your revision list. Exercises with fewer than 5 attempts aren't ranked,
  so one unlucky guess can't top the chart.
- **Study pattern** — which weekdays your learners actually study.
- **Live assessments** — sittings, passes, pass rate and the average passing
  score of the AI oral exams.

Below the windowed view: the all-time totals, top learners, a
recent-completions feed, the placement-level breakdown, and the register of
every issued certificate (with its own CSV export).

## 10. Appearance & profile

- **Settings → Appearance**: primary/accent colors, light/dark theme, custom CSS.
  - **Catalog headline** and **Catalog tagline** set the hero copy at the top of
    the Explore page. Leave them blank to use the built-in defaults ("Learn to
    use AI at work" and its tagline).
- **Settings → Profile Form**: a JSON schema of the questions asked on a
  learner's first lesson. The answers fill `{{placeholders}}` in the tutor's
  system prompt (Settings → AI Provider), personalizing every conversation.

### Language

At the bottom of **Settings → Appearance**:

- **Academy language** — what a learner sees before they choose anything.
  "Follow the site language" is the default; pick English or Spanish to pin it
  regardless of what WordPress is set to.
- **Learner choice** — leave on to show a language picker in the academy's top
  bar. Each learner's choice applies to the academy only; the rest of your site
  stays in its own language. Signed-out visitors can switch too, and their
  choice follows them onto their account when they register.

You can also see and change any individual learner's language on their
WordPress user profile, under **Mahan Academy → Academy language**.

**What gets translated:** the interface — every button, label, heading,
message and admin screen the plugin renders. The AI tutor also answers in the
learner's language.

**What doesn't:** your course text, lesson bodies, quiz questions and the email
templates on the Emails tab. Those are your words, stored in your database, and
the plugin leaves them exactly as you wrote them. If you want a Spanish course,
write a Spanish course — the starter catalog is English, and you can edit or
duplicate it like any other WordPress content.

---

## 11. Go live

Share the **Academy** page (Settings → Advanced shows which page it is). Learners
sign in with normal WordPress accounts, enroll for free, and progress through
courses with the AI tutor alongside every lesson.

The app is fully responsive: on phones learners get an app-style bottom
navigation bar, and the AI tutor opens as a slide-up panel from a floating
button — no setup required.
