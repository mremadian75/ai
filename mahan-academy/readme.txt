=== Mahan Academy ===
Contributors: mahan
Tags: lms, ai, learning, chatgpt, claude, gemini, course, tutor, gamification
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A standalone AI-learning platform for WordPress. Coursera-style course structure, Duolingo-style interactive practice, and a real-time AI tutor — powered directly by Anthropic, OpenAI, or Google.

== Description ==

Mahan Academy is a self-contained learning plugin built to teach people how to use AI in their daily work. No LMS dependency. No external automation services. Just WordPress, custom post types, custom tables, and direct calls to the AI provider of your choice.

**Key features:**

* Standalone courses & lessons (no Tutor LMS, LearnDash, etc. required)
* Beautiful single-page application front-end (Coursera structure + Duolingo energy)
* Real-time streaming AI tutor via Server-Sent Events (Anthropic / OpenAI / Google)
* Interactive exercises with instant multiple-choice grading and AI-graded open responses
* Schema-driven learner profile that personalizes the tutor and grading prompts
* Gamification: XP, levels, daily streaks
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

= 1.0.0 =
* Initial release.
