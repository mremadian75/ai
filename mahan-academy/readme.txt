=== Mahan Academy ===
Contributors: mahan
Tags: lms, ai, learning, chatgpt, claude, gemini, course, tutor, gamification
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A standalone AI-learning platform for WordPress. Coursera-style course structure, Duolingo-style interactive practice, and a real-time AI tutor — powered directly by Anthropic, OpenAI, or Google.

== Description ==

Mahan Academy is a self-contained learning plugin built to teach people how to use AI in their daily work. No LMS dependency. No external automation services. Just WordPress, custom post types, custom tables, and direct calls to the AI provider of your choice.

**Key features:**

* Standalone courses & lessons (no Tutor LMS, LearnDash, etc. required)
* Visual drag-and-drop Course Builder — build the whole curriculum on one screen
* AI authoring assistant — generate outcomes, draft lessons, and create exercises
* Beautiful single-page application front-end (Coursera structure + Duolingo energy)
* Real-time streaming AI tutor via Server-Sent Events (Anthropic / OpenAI / Google)
* Five exercise types: multiple choice, true/false, fill-in-the-blank, short answer, and prompt-writing (open answers graded by AI)
* Schema-driven learner profile that personalizes the tutor and grading prompts
* Gamification: XP with a full audit log, streak XP multipliers, linear or RPG-style progressive levels & titles, daily XP goals, streaks with earned streak freezes, 21 tiered achievements with live unlock notifications, and weekly + all-time leaderboards
* End-of-unit quizzes with instant grading and a passing score
* Learning paths — group courses into a guided, ordered program
* Email notifications (welcome, completion, achievement, streak reminder)
* Admin reports & analytics with CSV export
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

== Changelog ==

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
