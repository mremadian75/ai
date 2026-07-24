<?php
/**
 * Seed course: "ChatGPT for Everyday Work" (AI Tools bundle).
 *
 * Follows the canonical schema contract defined in course-pe-foundations.php
 * (units -> lessons -> exercises, plus an optional unit quiz). See that file
 * for the full key/type documentation.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'tool-chatgpt',
	'title'       => 'ChatGPT for Everyday Work',
	'slug'        => 'tool-chatgpt',
	'subtitle'    => 'Get real work done with ChatGPT — conversations, custom instructions, and the everyday tasks it does best.',
	'excerpt'     => 'A practical, no-jargon guide to using ChatGPT as a daily work tool — how to converse with it, set it up with your context, and use it safely for drafting, summarising, and rewriting.',
	'description' => '<p>Almost everyone has typed a question into ChatGPT. The gap between a novelty and a genuine daily tool is knowing <strong>how to work with it</strong> — as a conversation you steer, not a vending machine you poke once and walk away from.</p>'
		. '<p>This short course gets you productive fast. You will learn to talk to ChatGPT in back-and-forth, set custom instructions so it already knows your context, and put it to work on the everyday jobs it does best — drafting, summarising, and rewriting — while staying clear-eyed about its limits around accuracy and privacy. No coding required.</p>',
	'category'    => 'AI Tools',
	// Rung 1 of the ChatGPT level ladder (beginner → intermediate → advanced → expert).
	'track'       => 'chatgpt',
	'level_rank'  => 1,
	'level'       => 'beginner',
	'est_hours'   => 2,
	'featured'    => true,
	'certificate' => true,
	'order'       => 1,
	'topics'      => array( 'Chat basics', 'Custom instructions', 'Everyday workflows', 'Limits & safety' ),
	'outcomes'    => array(
		'Hold a back-and-forth conversation with ChatGPT and refine answers instead of settling for the first reply',
		'Set custom instructions so ChatGPT knows your role and preferences without being told each time',
		'Use reusable prompts to draft, summarise, and rewrite everyday work text',
		'Recognise hallucinations and a knowledge cutoff, and verify facts before you rely on them',
		'Keep sensitive data safe by knowing what should never be pasted into a consumer AI account',
	),
	'references'  => array(
		array(
			'title'  => 'Prompt engineering guide',
			'source' => 'OpenAI — API documentation',
			'url'    => 'https://platform.openai.com/docs/guides/prompt-engineering',
		),
		array(
			'title'  => 'OpenAI usage policies',
			'source' => 'OpenAI',
			'url'    => 'https://openai.com/policies/usage-policies/',
		),
		array(
			'title'  => 'Language Models are Few-Shot Learners (GPT-3)',
			'source' => 'Brown et al., 2020 — arXiv:2005.14165',
			'url'    => 'https://arxiv.org/abs/2005.14165',
		),
		array(
			'title'  => 'AI Risk Management Framework (AI RMF 1.0)',
			'source' => 'NIST, 2023',
			'url'    => 'https://www.nist.gov/itl/ai-risk-management-framework',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'Getting productive with ChatGPT',
			'lessons' => array(

				array(
					'title'   => 'How to talk to ChatGPT',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 20,
					'topics'  => array( 'Chat basics' ),
					'content' => '<h2>It is a conversation, not a one-shot search</h2>'
						. '<p>Many people treat ChatGPT like a search engine: type one query, take whatever comes back, and move on. That leaves most of its value on the table. ChatGPT is built for <strong>back-and-forth</strong>. The first reply is a starting point, not a final answer — and refining it is where the good results come from.</p>'
						. '<h3>You can always follow up</h3>'
						. '<p>Within a single chat, ChatGPT remembers what you have said so far in that thread, so you rarely need to re-explain yourself. If the tone is off, ask for something warmer or more formal. If it is too long, say "cut it to three sentences." If it missed the point, correct it in one line and it will adjust.</p>'
						. '<h3>Redo, regenerate, edit</h3>'
						. '<p>Two simple moves do a lot of work. You can ask ChatGPT to <strong>redo</strong> an answer differently ("try again, more concrete, with an example"), or <strong>edit one of your earlier messages</strong> to fix the instruction and branch the conversation from there. Both beat starting over from scratch.</p>'
						. '<h3>A quick back-and-forth</h3>'
						. '<blockquote>A three-turn exchange, paraphrased below, shows how fast you can close in on what you actually want.</blockquote>'
						. '<ul>'
						. '<li><strong>You:</strong> "Draft a message to my team about Friday\'s outage."</li>'
						. '<li><strong>ChatGPT:</strong> returns a formal, five-paragraph notice.</li>'
						. '<li><strong>You:</strong> "Too formal and too long — three short sentences, calm and reassuring."</li>'
						. '<li><strong>ChatGPT:</strong> returns a tight, warm version.</li>'
						. '<li><strong>You:</strong> "Perfect. Add one line thanking the on-call engineer."</li>'
						. '</ul>'
						. '<p>Three turns, and you have exactly what you wanted — none of which the very first prompt spelled out.</p>'
						. '<h3>When to start a fresh chat</h3>'
						. '<p>Threads are sticky by design, which is usually helpful but occasionally a trap. If you switch to a completely different topic, start a <strong>new chat</strong> so old context does not bleed in. A long, meandering thread can also get muddled; a clean start often works better than fighting it. As someone in {{role}}, a good habit is one chat per task on the way to {{primary_goal}}, rather than one endless conversation for everything.</p>'
						. '<h3>Recap</h3>'
						. '<p>Treat each reply as a draft you can steer. Follow up, ask for redos, edit your instructions — and open a fresh chat when the topic truly changes.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What is the best way to think about ChatGPT\'s first reply?',
							'options'  => array(
								'As the final answer you should copy and use immediately',
								'As a fixed result that cannot be changed',
								'As a first draft you can refine by following up',
								'As a search result ranked purely by relevance',
							),
							'answer'   => 2,
							'hint'     => 'The value comes from the back-and-forth, not the first message.',
							'feedback_correct'   => 'Right — the first reply is a starting point you steer with follow-ups.',
							'feedback_incorrect' => 'Not quite — treat the first reply as a draft you refine, not a finished answer.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Within a single chat, ChatGPT remembers what you said earlier in that thread, so you do not have to repeat yourself.',
							'answer'   => 0,
							'hint'     => 'Threads are sticky by design.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'When you switch to a completely different topic, it is best to start a fresh ___ so old context does not bleed in.',
							'answer_text' => 'chat',
							'accept'      => array( 'conversation', 'thread' ),
							'hint'        => 'A clean start beats fighting a muddled one.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Think of a message you recently had to write. Draft the opening request you would send ChatGPT, then write two follow-up instructions you would use to refine the reply (for example, changing the tone and the length).',
							'rubric' => 'A strong answer includes a clear initial request plus at least two specific refinements, such as adjusting tone, length, or adding a missing detail.',
							'hint'   => 'Steer with small, specific corrections rather than starting over.',
						),
					),
				),

				array(
					'title'   => 'Custom instructions & memory',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 20,
					'topics'  => array( 'Custom instructions' ),
					'content' => '<h2>Tell it once, not every single time</h2>'
						. '<p>If you find yourself opening every chat with "I work in marketing, keep it brief, no jargon," there is a better way. ChatGPT lets you set <strong>persistent context</strong> — often called custom instructions — that applies to your conversations automatically. You describe yourself and how you want replies once, and it carries across chats.</p>'
						. '<h3>Two questions worth answering</h3>'
						. '<p>Most custom-instruction setups come down to two things:</p>'
						. '<ul>'
						. '<li><strong>Who you are:</strong> your role, your field, who your audience usually is, and what you are trying to accomplish. For example, "I am a {{role}} and most of my writing is aimed at non-technical clients."</li>'
						. '<li><strong>How you want replies:</strong> your tone, length, and format defaults. For example, "Be concise. Use plain English. Give me options as bullet lists."</li>'
						. '</ul>'
						. '<p>With that in place, you stop re-explaining yourself and answers arrive closer to what you need on the first try.</p>'
						. '<h3>Memory, briefly</h3>'
						. '<p>Beyond instructions you set on purpose, ChatGPT may also <strong>remember</strong> useful details you mention over time — preferences, recurring projects, the way you like things done — and reuse them later. The practical upside is continuity: you do not have to reintroduce the basics each session. You stay in control of what is remembered, and you can review or clear it.</p>'
						. '<h3>Specific, but not a straitjacket</h3>'
						. '<p>The art is giving enough direction to be useful without boxing the model in. "Always reply in exactly four bullets" sounds tidy but will mangle answers that do not fit four bullets. Better: "Prefer short, skimmable replies; use bullets when they help." Aim your instructions at your <em>defaults</em>, then override them in the moment when a particular task needs something different.</p>'
						. '<blockquote>Good instructions describe your normal preferences; they should not force every answer into the same mould.</blockquote>'
						. '<h3>A concrete before and after</h3>'
						. '<p>Before: every chat opens with three lines of throat-clearing about who you are. After: you set that once, then simply ask, "Draft a client update about the timeline slip." The reply already lands in your voice, at your length, for your audience — a real time-saver when you are pushing toward {{primary_goal}}.</p>'
						. '<h3>Recap</h3>'
						. '<p>Set persistent context for who you are and how you like replies, let memory handle the recurring details, and keep instructions specific enough to help without over-constraining.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What are custom instructions mainly for?',
							'options'  => array(
								'Giving ChatGPT persistent context so you do not repeat yourself every chat',
								'Permanently locking every answer into one rigid format',
								'Making ChatGPT respond noticeably faster',
								'Turning off ChatGPT\'s ability to ever be wrong',
							),
							'answer'   => 0,
							'hint'     => 'Think about the throat-clearing you would otherwise type each time.',
							'feedback_correct'   => 'Exactly — they carry your context and preferences across conversations.',
							'feedback_incorrect' => 'Not quite — custom instructions save you from repeating your context, they do not lock every reply into one shape.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Custom instructions usually cover two things: who you are, and how you want your ___.',
							'answer_text' => 'replies',
							'accept'      => array( 'answers', 'responses' ),
							'hint'        => 'Your tone, length, and format defaults.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Setting a rigid rule like "always answer in exactly four bullets" is the safest way to write custom instructions.',
							'answer'   => 1,
							'hint'     => 'Over-constraining mangles answers that do not fit the mould.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Describe two things you would put in your own custom instructions and why they would save you time.',
							'rubric'   => 'A good answer names at least one "who you are" detail (role, audience, or goals) and one "how you want replies" preference (tone, length, or format), with a brief reason each reduces repetition.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Getting productive with ChatGPT — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which best describes how to get good results from ChatGPT?',
						'options'  => array(
							'Write one perfect prompt and never reply again',
							'Treat it as a conversation and refine the answer with follow-ups',
							'Copy the first answer without reading it',
							'Ask the same question in ten separate chats',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Custom instructions let you set persistent context so you stop re-explaining who you are in every chat.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'When is it most worth starting a brand-new chat?',
						'options'  => array(
							'Every time you send any message',
							'Never — one chat should hold your entire working life',
							'When you move to a completely different topic or the thread has become muddled',
							'Only when the app crashes',
						),
						'answer'   => 2,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'ChatGPT remembers what you have said earlier in the current ___, so you can follow up without repeating yourself.',
						'answer_text' => 'thread',
						'accept'      => array( 'chat', 'conversation' ),
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Real work with ChatGPT',
			'lessons' => array(

				array(
					'title'   => 'Draft, summarise, rewrite',
					'type'    => 'practice',
					'est_min' => 12,
					'xp'      => 25,
					'topics'  => array( 'Everyday workflows' ),
					'content' => '<h2>The three everyday jobs ChatGPT does best</h2>'
						. '<p>You do not need clever tricks to get value from ChatGPT at work. Three ordinary jobs cover most of it: <strong>drafting</strong>, <strong>summarising</strong>, and <strong>rewriting</strong>. Get comfortable with these and you will reach for it every day. Whatever already sits in your {{daily_tools}}, these patterns slot right in.</p>'
						. '<h3>1. Drafting</h3>'
						. '<p>Blank pages are slow; editing is fast. Let ChatGPT produce a first version you shape, whether it is an email, a post, an outline, or a set of talking points.</p>'
						. '<blockquote>Reusable prompt: "Draft a [type of message] to [audience] about [topic]. Goal: [what you want to happen]. Tone: [tone]. Keep it under [length]."</blockquote>'
						. '<p>For example: "Draft an email to a client about a one-week timeline slip. Goal: reassure them and propose a new date. Tone: honest and calm. Under 120 words."</p>'
						. '<h3>2. Summarising</h3>'
						. '<p>A long thread, a dense document, messy meeting notes — ChatGPT can compress them so you grasp the gist fast. The trick is to <strong>paste the actual text</strong> and say what kind of summary you need.</p>'
						. '<blockquote>Reusable prompt: "Summarise the text below in [format]. Focus on [decisions / action items / key numbers]." Then paste the text.</blockquote>'
						. '<p>For example: "Summarise the thread below in five bullets. Focus on decisions and who owns each next step."</p>'
						. '<h3>3. Rewriting</h3>'
						. '<p>When you already have words but they are not landing, rewriting adjusts tone, length, or clarity without losing your meaning.</p>'
						. '<blockquote>Reusable prompt: "Rewrite this to be [tone] and [length]. Keep the meaning." Then paste the text.</blockquote>'
						. '<p>For example: "Rewrite this to be warmer and half the length. Keep the meaning and the deadline."</p>'
						. '<h3>Always review before it goes out</h3>'
						. '<p>ChatGPT gives you a strong draft, not a finished, signed-off deliverable. Read every output before you send it: check the facts, the names, the numbers, and that the tone fits your relationship with the reader. You are still the author — it is the fast typist.</p>'
						. '<h3>Recap</h3>'
						. '<p>Draft to beat the blank page, summarise by pasting the real text, and rewrite to fix tone or length — then review before anything leaves your hands.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which of these is the sweet spot for ChatGPT in everyday work?',
							'options'  => array(
								'Making legally binding decisions on your behalf',
								'Guaranteeing that every fact it states is correct',
								'Replacing your judgement entirely',
								'Drafting, summarising, and rewriting text that you then review',
							),
							'answer'   => 3,
							'hint'     => 'Think first-draft help, not final authority.',
							'feedback_correct'   => 'Yes — draft, summarise, and rewrite are its everyday strengths, with you reviewing the result.',
							'feedback_incorrect' => 'Not quite — its everyday strengths are drafting, summarising, and rewriting, always with your review.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'To get a useful summary of a long document, you should paste the actual text into the prompt rather than just naming the document.',
							'answer'   => 0,
							'hint'     => 'It can only work with what is in front of it.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'ChatGPT gives you a strong draft, not a finished deliverable, so you should always ___ the output before you send it.',
							'answer_text' => 'review',
							'accept'      => array( 'check', 'proofread', 'read' ),
							'hint'        => 'Facts, names, numbers, and tone.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Pick a real email or message you need to write this week. Using the drafting pattern (audience, goal, tone, length), write the prompt you would give ChatGPT to draft it.',
							'rubric' => 'A strong answer specifies the audience, the goal of the message, a tone, and a length or format constraint.',
							'hint'   => 'Fill in audience, goal, tone, and length explicitly.',
						),
					),
				),

				array(
					'title'   => 'Limits: verify, privacy, hallucinations',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 25,
					'topics'  => array( 'Limits & safety' ),
					'content' => '<h2>Know where the guardrails are</h2>'
						. '<p>ChatGPT is genuinely useful, but using it well means knowing what it cannot be trusted to do. Three limits matter for everyday work.</p>'
						. '<h3>1. It can be confidently wrong</h3>'
						. '<p>Sometimes ChatGPT produces a claim, a quote, a statistic, or a citation that simply is not true — stated with total confidence. This is called a <strong>hallucination</strong>. It is not lying; it is predicting plausible-sounding text, and plausible is not the same as correct. Treat anything checkable — names, dates, figures, legal or medical specifics, references — as unverified until you confirm it from a reliable source.</p>'
						. '<h3>2. It has a knowledge cutoff</h3>'
						. '<p>A model\'s built-in knowledge stops at the point its training data ends, so it may not know about recent events, new releases, or the latest version of anything — and it can be fuzzy on fast-moving details. Unless it is clearly pulling in live information, do not rely on it for "what is the newest…" or "what happened this week" questions without checking elsewhere.</p>'
						. '<h3>3. Privacy: mind what you paste</h3>'
						. '<p>Anything you type into a general consumer AI account may not be as private as an internal system. As a rule, <strong>do not paste secrets or sensitive data</strong>: passwords and API keys, customer records, personal data such as names, addresses, or health and financial details, or confidential company documents. For sensitive or regulated work, use the tools and accounts your organisation has approved for it, and follow your workplace AI policy.</p>'
						. '<blockquote>If you would be uncomfortable seeing it on a billboard, do not paste it into a consumer chatbot.</blockquote>'
						. '<h3>Working the limits into your habits</h3>'
						. '<p>None of this makes ChatGPT less useful — it just tells you where to keep your hands on the wheel. Lean on it for drafting and thinking, verify the facts, and route anything sensitive to approved channels. In {{role}}, that mix keeps you fast without creating a mess to clean up later.</p>'
						. '<h3>Recap</h3>'
						. '<p>Assume it can be confidently wrong, remember its knowledge has a cutoff, and never paste secrets or customer data into a consumer account — verify facts and use approved tools for sensitive work.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What is a "hallucination" when talking about ChatGPT?',
							'options'  => array(
								'A visual glitch in the app\'s interface',
								'A confident-sounding claim that is actually false or made up',
								'A feature that shows images instead of text',
								'A slow response caused by heavy traffic',
							),
							'answer'   => 1,
							'hint'     => 'Plausible-sounding is not the same as correct.',
							'feedback_correct'   => 'Right — it is a false claim delivered with full confidence, so checkable facts need verifying.',
							'feedback_incorrect' => 'Not quite — a hallucination is a confident but false or invented claim, not a display issue.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'It is fine to paste customer records or passwords into a general consumer ChatGPT account as long as you delete the chat afterwards.',
							'answer'   => 1,
							'hint'     => 'Sensitive data belongs in approved tools only.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Because a model\'s built-in knowledge stops at a training ___, it may not know about very recent events.',
							'answer_text' => 'cutoff',
							'accept'      => array( 'cut-off', 'cut off', 'date' ),
							'hint'        => 'The point where its training data ends.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Name one thing you should always verify from ChatGPT and one type of information you should never paste into a consumer account, and explain why for each.',
							'rubric'   => 'A good answer identifies a checkable output to verify (a fact, figure, name, quote, or citation) and a category of sensitive data to withhold (passwords, customer or personal data, confidential documents), with a brief reason for each.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Real work with ChatGPT — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which is a solid everyday use of ChatGPT?',
						'options'  => array(
							'Drafting a first version of an email that you then review and edit',
							'Storing your company\'s customer database in a public chat',
							'Trusting every statistic it gives without checking',
							'Getting up-to-the-minute breaking news with no verification',
						),
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'ChatGPT confidently gives you a statistic and a source. What should you do before using it in a report?',
						'options'  => array(
							'Use it immediately — confidence means it is correct',
							'Assume the source is real without looking it up',
							'Verify the figure and the source from a reliable reference',
							'Delete your report to be safe',
						),
						'answer'   => 2,
					),
					array(
						'type'     => 'true_false',
						'question' => 'A hallucination is when ChatGPT states something false but sounds completely sure about it.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'You should never paste ___ data such as passwords or customer records into a general consumer ChatGPT account.',
						'answer_text' => 'sensitive',
						'accept'      => array( 'private', 'confidential', 'personal' ),
					),
				),
			),
		),
	),
);
