<?php
/**
 * Seed bundles (Coursera-style specializations) — each groups an ordered set of
 * seed courses (referenced by their `seed_key`) into a `mahan_path`.
 *
 * Shape per bundle:
 *   seed_key    string  Stable idempotency marker.
 *   title       string
 *   slug        string  post_name.
 *   subtitle    string  M_SUBTITLE.
 *   description string  Landing HTML (post_content).
 *   order       int     menu_order.
 *   courses     string[] Ordered course seed_keys (resolved to post IDs by the seeder).
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	array(
		'seed_key'    => 'bundle-prompt-engineering',
		'title'       => 'Prompt Engineering Specialization',
		'slug'        => 'prompt-engineering-specialization',
		'subtitle'    => 'Go from writing your first clear prompt to building reliable, reusable prompt workflows for your whole team.',
		'description' => '<p>A three-course path that takes you from the fundamentals of prompting to production-grade technique. Start with how models read your words, learn the repeatable patterns that make output reliable, and finish by turning prompting into everyday workflows you can share.</p>'
			. '<p>Best taken in order. Each course builds on the last.</p>',
		'order'       => 1,
		'courses'     => array( 'pe-foundations', 'pe-patterns', 'pe-at-work' ),
	),
	array(
		'seed_key'    => 'bundle-machine-learning',
		'title'       => 'Machine Learning Foundations',
		'slug'        => 'machine-learning-foundations',
		'subtitle'    => 'Understand how machine learning really works — no maths degree required — from core ideas to shipping a real model.',
		'description' => '<p>Three courses that demystify machine learning for professionals. Begin with what ML is and the workflow every project follows, master supervised learning and how to judge a model, then walk a real business problem all the way to a deployed, monitored model.</p>'
			. '<p>Conceptual and practical — built for decision-makers and builders alike.</p>',
		'order'       => 2,
		'courses'     => array( 'ml-foundations', 'ml-supervised', 'ml-practical' ),
	),
	array(
		'seed_key'    => 'bundle-generative-ai',
		'title'       => 'Generative AI Essentials',
		'slug'        => 'generative-ai-essentials',
		'subtitle'    => 'What generative AI is, how large language models work under the hood, and how to use both wisely.',
		'description' => '<p>Two courses that give you a clear mental model of modern generative AI. Understand the families of tools and their limits, then look inside large language models to see why they behave — and misbehave — the way they do.</p>',
		'order'       => 3,
		'courses'     => array( 'genai-foundations', 'genai-llms' ),
	),
	array(
		'seed_key'    => 'bundle-ai-tools',
		'title'       => 'AI Tools Starter Kit',
		'slug'        => 'ai-tools-starter-kit',
		'subtitle'    => 'Hands-on guides to the AI tools people actually use at work — ChatGPT, Claude, Gemini, and AI image generators.',
		'description' => '<p>A practical tour of the most popular AI assistants and creative tools. Learn how each one works, what it is best at, and how to fold it into your day — from writing and analysis to generating images — while staying safe and accurate.</p>'
			. '<p>Start anywhere; each course stands on its own.</p>',
		'order'       => 4,
		'courses'     => array( 'tool-chatgpt', 'tool-claude', 'tool-gemini', 'tool-image-gen' ),
	),
);
