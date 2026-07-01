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

## 3. Build the curriculum (the Course Builder)

Scroll to the **Curriculum builder** box on the course editor. This is where you
assemble units and lessons visually.

- **+ Add unit** — creates a section (e.g. "Getting started"). Click the unit
  title to rename it.
- **+ Add lesson** — type a title; the lesson is created and added to that unit.
- **Drag** the handle (⣿ / ↕) to reorder lessons within a unit, move them
  between units, or reorder whole units.
- Per lesson you can set **Type** (Reading / Practice / Video), **XP**, and
  **estimated minutes** right there.
- **Edit content** opens the lesson in the full block editor (for rich text,
  images, embeds, and exercises).
- **Duplicate** or **Delete** a lesson from its row.
- **Quiz** (per unit): the "Quiz" button on a unit header opens a quiz editor —
  add multiple-choice / true-false / fill-blank questions, set a passing score
  and XP. Learners see a quiz card at the end of that unit.

Everything saves automatically as you edit — watch the "Saved" indicator.

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

---

## 5. Gamification

**Settings → Gamification:**

- **XP per lesson / exercise** and **XP per level** (the level curve).
- **Daily streak** tracking.
- **Achievements** — badges for milestones (first lesson, 10 lessons, first
  course, streaks, levels, XP). Shown on the learner dashboard.
- **Leaderboard** — an opt-in public top-20 by XP.
- **Level titles** — optional names per level (one per line: Novice, Explorer,
  Practitioner …). The last name is reused for higher levels.

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

## 8. Reports & analytics

**Mahan Academy → Reports.** A read-only dashboard:

- KPI cards: learners, enrollments, completions, active today / this week, total
  XP, lessons completed, exercise accuracy, and quiz pass rate.
- A per-course table (enrolled / completed / completion % / average progress)
  with an **Export CSV** button.
- Top learners and a recent-completions feed.

## 9. Appearance & profile

- **Settings → Appearance**: primary/accent colors, light/dark theme, custom CSS.
- **Settings → Profile Form**: a JSON schema of the questions asked on a
  learner's first lesson. The answers fill `{{placeholders}}` in the tutor's
  system prompt (Settings → AI Provider), personalizing every conversation.

---

## 10. Go live

Share the **Academy** page (Settings → Advanced shows which page it is). Learners
sign in with normal WordPress accounts, enroll for free, and progress through
courses with the AI tutor alongside every lesson.
