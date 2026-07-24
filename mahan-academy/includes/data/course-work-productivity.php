<?php
/**
 * Seed course: "AI for Everyday Productivity" (AI at Work bundle).
 *
 * Follows the canonical schema contract defined in course-pe-foundations.php:
 * units -> lessons -> exercises, plus an optional per-unit quiz. The seeder
 * ({@see Mahan_Seed}) loads each `course-*.php` and expects exactly this shape.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'work-productivity',
	'title'       => 'AI for Everyday Productivity',
	'slug'        => 'work-productivity',
	'subtitle'    => 'Practical, safe wins you can use today — email, notes, planning, meetings — and the one habit that keeps your data safe.',
	'excerpt'     => 'Practical, safe ways to use AI for email, notes, planning, and meetings — plus the data-safety habit every professional needs.',
	'description' => '<p>You do not need to be technical to get real value from AI. This short course gives you a handful of practical, repeatable wins you can put to work in your very next email, meeting, or to-do list.</p>'
		. '<p>In about two hours you will learn how to draft and summarise faster, brainstorm and plan with AI as a thinking partner, turn rough meeting notes into clear action items, and — most importantly — build the one habit that keeps your company\'s data safe.</p>',
	'category'    => 'AI at Work',
	'level'       => 'beginner',
	'est_hours'   => 2,
	'featured'    => true,
	'certificate' => true,
	'order'       => 1,
	'topics'      => array( 'Email & writing', 'Planning & ideation', 'Meeting notes', 'Data safety at work' ),
	'outcomes'    => array(
		'Draft, reply to, and summarise email in a fraction of the time',
		'Use AI as a brainstorming partner for outlines, checklists, and options',
		'Turn messy meeting notes into clear action items with owners and dates',
		'Spot what should never be pasted into a public AI tool',
		'Anonymise sensitive information so you can get AI help safely',
	),
	'references'  => array(
		array(
			'title'  => 'AI Risk Management Framework (AI RMF 1.0)',
			'source' => 'NIST, 2023',
			'url'    => 'https://www.nist.gov/itl/ai-risk-management-framework',
		),
		array(
			'title'  => 'Generative AI and the future of work',
			'source' => 'McKinsey Global Institute',
			'url'    => 'https://www.mckinsey.com/mgi/our-research',
		),
		array(
			'title'  => 'Prompt engineering guide',
			'source' => 'OpenAI — API documentation',
			'url'    => 'https://platform.openai.com/docs/guides/prompt-engineering',
		),
		array(
			'title'  => 'Recommendation on the Ethics of Artificial Intelligence',
			'source' => 'UNESCO, 2021',
			'url'    => 'https://www.unesco.org/en/artificial-intelligence/recommendation-ethics',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'Save time every day',
			'lessons' => array(

				array(
					'title'   => 'Email, notes, and summaries',
					'type'    => 'practice',
					'est_min' => 18,
					'xp'      => 25,
					'topics'  => array( 'Email & writing' ),
					'content' => '<h2>Three everyday jobs AI is genuinely good at</h2>'
						. '<p>If you send email, jot messy notes, or wade through long threads, AI can save you real time today. The pattern is always the same: hand it the raw material, give one clear instruction, and review what comes back. Here are three jobs and a reusable prompt for each.</p>'
						. '<h3>1. Draft or reply to an email</h3>'
						. '<p>Instead of staring at a blank message, give the model the situation and the point you want to make.</p>'
						. '<blockquote>Draft a friendly reply to the email below. Keep it under 100 words, thank them for the update, and ask to move our call to Thursday. Email: [paste].</blockquote>'
						. '<p>You get a solid first draft in seconds. Tighten the wording, add anything personal, and send.</p>'
						. '<h3>2. Clean up rough notes</h3>'
						. '<p>Typed something fast during a call? Paste your shorthand and ask for order.</p>'
						. '<blockquote>Turn these rough notes into clear bullet points grouped by topic. Do not add anything I did not write. Notes: [paste].</blockquote>'
						. '<p>The "do not add anything" line matters — it keeps the model from inventing details you never mentioned.</p>'
						. '<h3>3. Summarise a long thread</h3>'
						. '<p>Fifteen replies deep and lost the plot? Let the model catch you up.</p>'
						. '<blockquote>Summarise this email thread in five bullets: what was decided, what is still open, and who I owe a reply. Thread: [paste].</blockquote>'
						. '<h3>Always review before you send</h3>'
						. '<p>An AI draft is a starting point, not a finished product. It can get a name wrong, soften a "no" you meant firmly, or state a date that was never agreed. Read every draft as if a brand-new assistant wrote it — because, in effect, one did. A ten-second check protects your credibility.</p>'
						. '<h3>Recap</h3>'
						. '<p>Paste the raw material, give one clear instruction, and tell the model what <em>not</em> to invent. Draft, clean up, summarise — then always review before it leaves your outbox.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why add "Do not add anything I did not write" when asking AI to clean up your notes?',
							'options'  => array(
								'It stops the model from adding details you never mentioned',
								'It makes the response arrive faster',
								'It automatically sends the email for you',
								'It translates the notes into another language',
							),
							'answer'   => 0,
							'hint'     => 'Think about what could go wrong if the model fills in gaps on its own.',
							'feedback_correct'   => 'Right — that instruction keeps the summary faithful to what you actually recorded.',
							'feedback_incorrect' => 'Not quite. The line is a guardrail: it stops the model from inventing details that were not in your notes.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'You should review an AI-written email before sending it, because the model can get a name or date wrong.',
							'answer'   => 0,
							'hint'     => 'Treat the draft like work from a new assistant.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'To handle a fifteen-reply email chain, ask the model to ___ the thread into a few bullets.',
							'answer_text' => 'summarise',
							'accept'      => array( 'summarize', 'sum up', 'condense', 'summarise' ),
							'hint'        => 'You want the gist, not every message.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Pick a real email in your inbox and write a prompt that asks AI to draft a reply. Include the situation, the main point you want to make, a tone, and a length limit.',
							'rubric' => 'A strong answer supplies the situation or pasted email, a clear main point or goal, a named tone, and an explicit length or format constraint.',
							'hint'   => 'Give the model the same brief you would give a colleague writing it for you.',
						),
					),
				),

				array(
					'title'   => 'Planning and brainstorming with AI',
					'type'    => 'reading',
					'est_min' => 14,
					'xp'      => 20,
					'topics'  => array( 'Planning & ideation' ),
					'content' => '<h2>AI as a thinking partner</h2>'
						. '<p>AI is not only for finished writing. Some of its best value comes earlier, while you are still figuring out what to do. Treat it like a fast, tireless colleague you can brainstorm with — one who never runs out of ideas and never minds being told "try again".</p>'
						. '<h3>Ask for outlines and structure</h3>'
						. '<p>Facing a blank page for a report, a plan, or a presentation? Ask for the skeleton first.</p>'
						. '<blockquote>I need to plan a team offsite for 12 people. Give me an outline of the sections a good plan would cover, before we fill in any details.</blockquote>'
						. '<p>Now you have a scaffold to react to, which is far easier than inventing one from nothing.</p>'
						. '<h3>Generate checklists and pros and cons</h3>'
						. '<p>Repeatable work becomes a checklist in seconds — "List everything I should check before publishing a blog post." Weighing a decision? Ask for both sides: "Give me the pros and cons of switching our weekly meeting to every two weeks." You still decide; the model just makes sure you did not miss an angle.</p>'
						. '<h3>Ask for options, then choose</h3>'
						. '<p>The biggest mistake is asking for one answer. Ask for several.</p>'
						. '<blockquote>Give me three different approaches to cutting our onboarding time, with a one-line trade-off for each.</blockquote>'
						. '<p>You stay the decision-maker; the model simply widens your view. Options are where AI shines, because comparing beats accepting.</p>'
						. '<h3>The first draft is a draft</h3>'
						. '<p>A plan that appears in seconds can feel authoritative, but it is only a starting point. The model does not know your budget, your politics, or last year\'s failure. Use its draft to think faster — then apply the judgement only you have. Never ship a first draft as a final decision.</p>'
						. '<h3>Recap</h3>'
						. '<p>Use AI to get unstuck: ask for outlines, checklists, and pros and cons; request <em>options</em> rather than a single answer; and remember that the first draft is raw material for your judgement, not a substitute for it.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What is the biggest advantage of asking AI for three options instead of one answer?',
							'options'  => array(
								'It uses less electricity',
								'The first option is always the correct one',
								'You can compare trade-offs and stay the decision-maker',
								'It stops the model from ever making mistakes',
							),
							'answer'   => 2,
							'hint'     => 'Who should be making the final call?',
							'feedback_correct'   => 'Exactly — options widen your view while you keep the judgement.',
							'feedback_incorrect' => 'Not quite. Asking for options lets you compare trade-offs and choose, rather than accepting a single answer.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A first-draft plan from AI should be treated as a final decision because it looks polished.',
							'answer'   => 1,
							'hint'     => 'The model does not know your budget or context.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Instead of asking AI for a single answer, ask it for several ___ so you can compare and choose.',
							'answer_text' => 'options',
							'accept'      => array( 'option', 'choices', 'alternatives' ),
							'hint'        => 'More than one, so you can weigh them.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Describe one planning or brainstorming task from your own work where asking AI for an outline first would help you get started.',
							'rubric'   => 'A good answer names a concrete task and explains how starting from an AI-generated outline or set of options would reduce blank-page friction while keeping the person in charge of decisions.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Save time every day — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which is a good everyday use of AI at work?',
						'options'  => array(
							'Sending emails automatically without reading them',
							'Drafting a first version of an email for you to review and send',
							'Making final hiring decisions on its own',
							'Storing your account passwords',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'You should review an AI-written draft before sending, because it can get facts like names and dates wrong.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'To brainstorm well, ask AI for several ___ instead of a single answer.',
						'answer_text' => 'options',
						'accept'      => array( 'choices', 'alternatives' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Asking AI for three approaches with a trade-off for each is useful mainly because:',
						'options'  => array(
							'The third option is always the best one',
							'It removes the need for you to think',
							'You can compare the trade-offs and choose yourself',
							'It guarantees the plan will have no mistakes',
						),
						'answer'   => 2,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Work smarter and safer',
			'lessons' => array(

				array(
					'title'   => 'Turning meetings into action items',
					'type'    => 'practice',
					'est_min' => 18,
					'xp'      => 25,
					'topics'  => array( 'Meeting notes' ),
					'content' => '<h2>From messy notes to a clear action list</h2>'
						. '<p>The most useful thing AI can do after a meeting is turn a wall of rough notes or a raw transcript into something you can act on. Paste what you have and ask for structure — the model is very good at pulling order out of chaos. A messy paragraph of half-finished sentences becomes a tidy list you can share in seconds.</p>'
						. '<h3>What to ask for</h3>'
						. '<p>A good post-meeting summary pulls out four things:</p>'
						. '<ul>'
						. '<li><strong>Decisions</strong> — what was actually agreed.</li>'
						. '<li><strong>Action items</strong> — the concrete next steps.</li>'
						. '<li><strong>Owners</strong> — who is responsible for each one.</li>'
						. '<li><strong>Due dates</strong> — when each is expected.</li>'
						. '</ul>'
						. '<h3>A prompt you can reuse</h3>'
						. '<blockquote>From the meeting notes below, produce: (1) a short list of decisions, (2) action items with an owner and a due date for each, and (3) any open questions. If an owner or date is not stated, mark it "TBD" rather than guessing. Notes: [paste].</blockquote>'
						. '<p>That "mark it TBD rather than guessing" line is the safety valve. Without it, a model will happily assign Priya a deadline nobody actually agreed to, simply because it seems plausible.</p>'
						. '<h3>Always verify names and dates</h3>'
						. '<p>Before you send the action list to your team, read it against reality. Did the model attach the right owner to each task? Are the dates the ones you actually discussed, or invented? A transcript full of "um" and crosstalk can lead the model to mishear a name. The summary saves you ten minutes; the check takes thirty seconds and keeps you from committing a colleague to work they never accepted.</p>'
						. '<h3>Recap</h3>'
						. '<p>Paste your notes, ask for decisions, action items, owners, and due dates, and tell the model to mark anything unclear as "TBD". Then verify the names and dates before you press send.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which four things should you ask AI to pull out of your meeting notes?',
							'options'  => array(
								'Every sentence anyone said, in full',
								'Decisions, action items, owners, and due dates',
								'The meeting\'s start and end time only',
								'A ranking of who talked the most',
							),
							'answer'   => 1,
							'hint'     => 'Think about what makes a summary actionable afterwards.',
							'feedback_correct'   => 'Correct — those four turn a wall of notes into something the team can act on.',
							'feedback_incorrect' => 'Not quite. An actionable summary captures decisions, action items, owners, and due dates.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Telling the model to mark an owner or date as "TBD" when it is not stated helps prevent it from inventing assignments.',
							'answer'   => 0,
							'hint'     => 'A guardrail against plausible-sounding guesses.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'After AI drafts your action list, you should still ___ the names and dates against what was actually agreed.',
							'answer_text' => 'verify',
							'accept'      => array( 'check', 'confirm', 'double-check', 'double check' ),
							'hint'        => 'Do not trust it blindly.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write a prompt that turns a set of meeting notes into decisions, action items with owners and due dates, and open questions — and that tells the model what to do when an owner or date is unclear.',
							'rubric' => 'A strong answer requests decisions, action items with owners and due dates, and open questions, and includes an explicit instruction (such as marking items "TBD") to prevent the model from guessing.',
							'hint'   => 'Reuse the structure from the lesson and add the "do not guess" safety valve.',
						),
					),
				),

				array(
					'title'   => 'What not to paste: data safety at work',
					'type'    => 'reading',
					'est_min' => 14,
					'xp'      => 20,
					'topics'  => array( 'Data safety at work' ),
					'content' => '<h2>The one habit that keeps you safe</h2>'
						. '<p>AI tools are helpful precisely because you feed them real material. That is also the risk. Anything you paste into a consumer AI tool may be stored, processed on servers you do not control, and in some cases used to improve the product. So before you paste, pause and ask: <em>would I be comfortable if this text left the building?</em></p>'
						. '<h3>What never to paste into a public tool</h3>'
						. '<ul>'
						. '<li><strong>Secrets and credentials</strong> — passwords, API keys, access tokens.</li>'
						. '<li><strong>Customer PII</strong> — names, emails, addresses, phone numbers, ID numbers, health or payment data.</li>'
						. '<li><strong>Confidential IP</strong> — unreleased plans, source code you do not have the right to share, contracts, and financial results.</li>'
						. '</ul>'
						. '<p>If any of these leaks, the damage is real: a breached customer record, a broken contract, a competitor tipped off. "I only pasted it to summarise" is not a defence anyone wants to give.</p>'
						. '<h3>Know your organisation\'s policy</h3>'
						. '<p>Most workplaces now have a written rule about which AI tools are allowed and what you may put into them. Find it and follow it. If you cannot find one, treat that as a "no" until you can ask.</p>'
						. '<h3>Use approved tools, and anonymise when unsure</h3>'
						. '<p>Many employers offer an enterprise version of an AI tool that keeps your data private and out of training. Prefer those for work. When you are not sure whether something is sensitive, anonymise it: replace real names with "Customer A", swap real figures for round dummy numbers, and strip identifiers before you paste. You keep the useful shape of the problem without exposing the people inside it.</p>'
						. '<blockquote>If you would not post it publicly, do not paste it into a public AI tool.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Never paste secrets, customer PII, or confidential IP into consumer tools. Know your organisation\'s policy, prefer approved or enterprise tools, and anonymise anything you are unsure about before it goes near an AI.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which of these is safe to paste into a public consumer AI tool?',
							'options'  => array(
								'A customer\'s full name, email, and account number',
								'A database password',
								'Your company\'s unreleased financial results',
								'A paragraph of already-public marketing copy',
							),
							'answer'   => 3,
							'hint'     => 'Which one is already visible to the world?',
							'feedback_correct'   => 'Right — content that is already public carries no new exposure.',
							'feedback_incorrect' => 'Not quite. Credentials, customer PII, and unreleased results must stay out; already-public copy is fine.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'It is fine to paste customer credit-card numbers into a free public chatbot as long as you delete the chat afterwards.',
							'answer'   => 1,
							'hint'     => 'Deleting your view does not undo what was sent.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'When you are unsure whether text is sensitive, ___ it — replacing real names and figures with dummy ones — before pasting.',
							'answer_text' => 'anonymise',
							'accept'      => array( 'anonymize', 'redact', 'mask' ),
							'hint'        => 'Remove anything that identifies real people.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Give an example of information from your own work that you should never paste into a consumer AI tool, and explain why.',
							'rubric'   => 'A good answer names a specific type of sensitive data (credentials, customer PII, or confidential IP) and explains the harm — leakage, policy breach, or exposure of real people — that pasting it could cause.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Work smarter and safer — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'What should you ask AI to extract from a set of meeting notes?',
						'options'  => array(
							'Decisions, action items, owners, and due dates',
							'The background music that was playing',
							'Everyone\'s exact words, transcribed in full',
							'Nothing — always read every note yourself',
						),
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which of these should you NOT paste into a public consumer AI tool?',
						'options'  => array(
							'A public press release',
							'Customer personal data and passwords',
							'A generic personal to-do list',
							'A quote from a published book',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Replacing real names and figures with dummy ones before pasting is known as ___ the data.',
						'answer_text' => 'anonymising',
						'accept'      => array( 'anonymizing', 'anonymise', 'anonymize', 'redacting' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Anonymising sensitive data before pasting protects the real people involved while still letting AI help with the problem.',
						'answer'   => 0,
					),
				),
			),
		),
	),
);
