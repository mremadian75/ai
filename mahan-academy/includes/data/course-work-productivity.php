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
	'est_hours'   => 3,
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

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'Getting time back, honestly',
			'lessons' => array(

				array(
					'title'   => 'Where the minutes actually go',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Email & writing', 'Planning & ideation' ),
					'content' => '<h2>People point AI at the wrong part of the job</h2>'
						. '<p>Ask someone what takes their time and they will name the visible thing — writing the report, answering the email. Watch a week and the time is somewhere else: switching between tools, rereading a thread to remember where it got to, reformatting the same information for a different audience, and deciding what to do next.</p>'
						. '<p>AI helps enormously with three of those four, and most people never point it at any of them.</p>'
						. '<h3>Rereading to catch up</h3>'
						. '<p>You join a thread late, return from leave, or pick up a project after two weeks. Reconstructing the state costs twenty minutes and produces nothing. "Summarise where this got to, what was decided, and what is still open" costs one and produces the same understanding.</p>'
						. '<h3>Reformatting for a different audience</h3>'
						. '<p>The same content, three times: detailed for your team, condensed for your manager, plain for a customer. This is exactly what these tools do best — the information already exists and you are changing its shape, which is the reliable half of AI use.</p>'
						. '<h3>Deciding what to do next</h3>'
						. '<p>Not the deciding itself — that stays yours — but the sorting that precedes it. Paste a chaotic list and ask for it grouped by what is blocking others, what is time-bound, and what could wait a week. The clarity is usually worth more than the sorting.</p>'
						. '<h3>The one it does not fix</h3>'
						. '<p>Context switching. If anything, a tool that lives in another tab makes it slightly worse — which is why using an assistant inside the application you are already in matters more than it sounds.</p>'
						. '<blockquote>Point it at the invisible work — the catching up, the reshaping, the sorting. That is where the hours actually are.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>The visible tasks are not where the time goes. Rereading, reformatting and sorting are, and all three are things AI does reliably because the material is already in front of it.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which is the most overlooked place AI saves time?',
							'options'  => array(
								'Writing long documents from nothing',
								'Reconstructing where a thread or project got to',
								'Choosing between strategic options',
								'Attending meetings',
							),
							'answer'   => 1,
							'hint'     => 'Which one produces nothing but costs twenty minutes?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'AI meaningfully reduces the cost of context switching between tools.',
							'answer'   => 1,
							'hint'     => 'What does one more tab do?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Reformatting the same information for a different audience is reliable because the material is already ___.',
							'answer_text' => 'supplied',
							'accept'      => array( 'there', 'given', 'in front of it' ),
							'hint'        => 'The reliable half of AI use.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Track back over yesterday. Where did time go that produced nothing — and which of those could have been a one-minute prompt?',
							'rubric'   => 'A strong answer identifies genuinely non-productive time — catching up, reformatting, searching for context — rather than naming the visible tasks, and matches at least one of them to a specific prompt that would have shortened it.',
						),
					),
				),

				array(
					'title'   => 'Measuring whether it actually helped',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Planning & ideation', 'Email & writing' ),
					'content' => '<h2>Feeling faster and being faster are different things</h2>'
						. '<p>AI feels productive. Something appears immediately, the blank page is gone, and the sensation of progress is strong. That sensation is not evidence, and a few weeks in it is worth checking whether the time actually came back.</p>'
						. '<h3>Count the whole loop</h3>'
						. '<p>The honest measure is not how long the model took. It is prompt-writing plus reading plus editing plus verifying, against how long the task took before. People consistently count the first part and forget the last three — which is how a task that now takes longer feels faster.</p>'
						. '<h3>Three outcomes, and two of them are wins</h3>'
						. '<p><strong>Faster and as good</strong> — keep it, and consider setting it up properly. <strong>Same speed but better output</strong> — also a win, just a different one; be clear which you got. <strong>Faster but worse</strong> — the dangerous one, because it feels like success and the quality loss is invisible until someone else notices.</p>'
						. '<h3>The honest test</h3>'
						. '<p>Do the next one both ways. Write it yourself, then have AI draft it, and compare — not just the time, but which you would rather send. Half an hour, once, and it settles the question for that task permanently.</p>'
						. '<h3>Beware the moving standard</h3>'
						. '<p>The subtle risk is that your bar drops to meet the output. The AI draft is fine, so fine becomes the standard, and the good version you used to write quietly stops existing. Compare against what you used to produce, not against what the tool gives you.</p>'
						. '<blockquote>Faster and worse is the most expensive outcome available, because nobody logs it and everybody feels productive.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Count prompting, reading, editing and verifying together. Distinguish faster from better. Test one task both ways. And compare against your old standard, not against the draft in front of you.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'When measuring time saved, what do people most often forget to count?',
							'options'  => array(
								'How long the model took to respond',
								'Writing the prompt, reading, editing and verifying',
								'The cost of the subscription',
								'The number of attempts',
							),
							'answer'   => 1,
							'hint'     => 'Everything either side of the generation.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Compare the output against what you ___ to produce, not against the draft in front of you.',
							'answer_text' => 'used',
							'accept'      => array( 'used to be able' ),
							'hint'        => 'The standard can drift down.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Faster but slightly worse output is a straightforward win.',
							'answer'   => 1,
							'hint'     => 'Who notices, and when?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Design the honest test for one task you now use AI for: what you would measure, how you would compare quality rather than only speed, and what result would make you stop using it.',
							'rubric' => 'A strong answer measures the whole loop including editing and verification, compares quality using a concrete criterion — which version they would rather send, or a colleague\'s blind preference — and states a specific stopping condition rather than assuming the tool will win.',
							'hint'   => 'Decide the stopping condition before you run it.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Getting time back — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which task type is AI most reliably good at?',
						'options'  => array(
							'Recalling facts about your company',
							'Reshaping information you supplied for a different audience',
							'Making the decision itself',
							'Predicting next quarter',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'The honest measure counts prompting, reading, editing and ___ together.',
						'answer_text' => 'verifying',
						'accept'      => array( 'checking', 'verification' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Catching up on a thread you joined late is a good use of a summarising prompt.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'The risk of a "moving standard" is that:',
						'options'  => array(
							'Prompts get longer over time',
							'Your bar quietly drops to match the AI draft',
							'The model changes version',
							'Costs increase',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Working well with other people in the loop',
			'lessons' => array(

				array(
					'title'   => 'When to say it was AI-assisted',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Data safety at work', 'Email & writing' ),
					'content' => '<h2>Nobody has settled this, so decide your own line</h2>'
						. '<p>There is no established etiquette yet, and the vacuum makes people either over-disclose until it becomes noise, or never mention it and quietly worry. A consistent personal rule is better than either.</p>'
						. '<h3>A workable principle</h3>'
						. '<p>Disclose when the reader would reasonably assume a human did something a human did not — and when knowing would change how they read it.</p>'
						. '<p>Nobody needs telling you used spellcheck. But a recommendation, an assessment, a piece of analysis, or a personal message carries an implicit claim about where it came from, and that claim is what disclosure protects.</p>'
						. '<h3>Rough lines</h3>'
						. '<p><strong>Usually no need</strong> — AI helped you edit, summarise for your own use, or draft something routine you then rewrote substantially. The judgement and the words are yours.</p>'
						. '<p><strong>Usually say something</strong> — substantive analysis or research that AI produced, anything a reader might act on without checking, and creative work where authorship matters to the audience.</p>'
						. '<p><strong>Always</strong> — where a policy, a client contract, an academic rule or a regulator requires it. Check rather than assume.</p>'
						. '<h3>How to say it without making it strange</h3>'
						. '<p>One clause, no ceremony. "I used AI to pull the first draft together from the reports; I have checked the figures." That tells the reader what was done and, more importantly, what you took responsibility for — which is the part they actually care about.</p>'
						. '<blockquote>The question is not whether AI touched it. It is whether the reader would feel misled to find out.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Disclose when it would change how someone reads it or when a rule requires it, keep it to one plain clause, and make clear what you verified.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which most clearly warrants disclosure?',
							'options'  => array(
								'AI helped tidy the grammar of your email',
								'AI produced the analysis your colleague will act on without checking',
								'AI summarised a document for your own reading',
								'AI suggested a subject line you rewrote',
							),
							'answer'   => 1,
							'hint'     => 'Would knowing change how they treat it?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A disclosure should also make clear what you ___ before sending it on.',
							'answer_text' => 'verified',
							'accept'      => array( 'checked' ),
							'hint'        => 'The part readers actually care about.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Because there is no settled etiquette, it is best to disclose on absolutely everything.',
							'answer'   => 1,
							'hint'     => 'What happens to a signal used everywhere?',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Write your own disclosure rule in two sentences, and give one example each of something above and below your line.',
							'rubric'   => 'A strong answer states a rule based on reader expectation or consequence rather than on how much AI was involved, and the two examples should genuinely sit on either side of it — showing the line has been thought about rather than asserted.',
						),
					),
				),

				array(
					'title'   => 'Keeping the parts that are yours',
					'type'    => 'practice',
					'est_min' => 12,
					'xp'      => 30,
					'topics'  => array( 'Meeting notes', 'Email & writing', 'Data safety at work' ),
					'content' => '<h2>Some things get worse when you delegate them</h2>'
						. '<p>The productivity framing pushes toward handing over everything that can be handed over. Some tasks are genuinely worth doing slowly, and it is worth naming them before efficiency quietly erodes them.</p>'
						. '<h3>Writing that is thinking</h3>'
						. '<p>Some writing is transmission — a status update, a confirmation, a summary. Delegate it freely. Other writing is how you work out what you think: the argument you are constructing, the decision you are reasoning through. Hand that over and you get a document without having done the thinking, and you will notice the first time someone asks a follow-up question.</p>'
						. '<h3>The message that is really a relationship</h3>'
						. '<p>Condolences. Apologies. Difficult feedback. Thanks that means something. People can usually tell, and being able to tell is itself the injury — the message said you did not consider it worth your own words.</p>'
						. '<h3>The skill you are still building</h3>'
						. '<p>If you are learning to write well, or to structure an argument, or to work in a new domain, having a model do it produces good output and no learning. That is fine for something you will never need to do again, and a real cost for something central to your career. Notice which you are in.</p>'
						. '<h3>The judgement itself</h3>'
						. '<p>You can ask for options, arguments and things you might have missed — all of that helps you decide better. But the deciding is what you are there for, and the moment "the AI recommended it" enters your reasoning, something has gone wrong that the tool cannot fix.</p>'
						. '<blockquote>Ask what you lose by not doing it yourself. For most tasks the answer is nothing, which is exactly why the exceptions are worth naming.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>You know where the hidden time is, how to measure honestly, when to disclose, and what to protect. Delegate transmission freely; keep the thinking, the relationships, the learning and the judgement.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which writing is worth doing yourself even when AI could do it faster?',
							'options'  => array(
								'A routine status update',
								'The argument you are using to work out what you actually think',
								'A meeting summary for your own reference',
								'A confirmation email',
							),
							'answer'   => 1,
							'hint'     => 'Which one is the thinking, not the transmission?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'When you are still building a skill, delegating it produces good output and no ___.',
							'answer_text' => 'learning',
							'accept'      => array( 'practice', 'growth' ),
							'hint'        => 'Fine for one-offs, costly for your core work.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A message of condolence or apology is a good candidate for AI drafting because the wording is difficult.',
							'answer'   => 1,
							'hint'     => 'What does the recipient conclude if they can tell?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write your own two lists: five things you will delegate to AI without hesitation, and four you will keep — with the reason for each of the four.',
							'rubric' => 'A strong answer draws on the writer\'s real work, and the four reserved items should have distinct reasons — one because the writing is the thinking, one because it is a relationship, one because they are still learning that skill, and one because the judgement is theirs to make.',
							'hint'   => 'Four different reasons, not four examples of the same one.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'Is there anything you have already started delegating that you should take back? What made it easy to hand over?',
							'rubric'   => 'A thoughtful answer names something specific and is honest about the pull that made it easy — time pressure, the output looking good enough, or the task feeling routine — rather than answering that everything is well balanced.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'People in the loop — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'The best test for whether to disclose AI assistance is:',
						'options'  => array(
							'How many words the model produced',
							'Whether the reader would feel misled to find out',
							'Whether it was a long task',
							'Whether anyone asked',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Writing that is transmission can be delegated; writing that is ___ should not be.',
						'answer_text' => 'thinking',
						'accept'      => array( 'reasoning' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Delegating a skill you are actively trying to build gives you output without the learning.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Disclosing on absolutely everything is unhelpful because:',
						'options'  => array(
							'It is against most policies',
							'A signal used everywhere stops carrying information',
							'It takes too long to type',
							'Readers dislike transparency',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
