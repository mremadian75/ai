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
	'est_hours'   => 3,
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

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'The moves that separate casual users from confident ones',
			'lessons' => array(

				array(
					'title'   => 'Steering a conversation instead of restarting it',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Chat basics', 'Everyday workflows' ),
					'content' => '<h2>Almost nobody gets it right first time. That is fine</h2>'
						. '<p>Watch a confident user and you will notice they rarely write one perfect prompt. They write a decent one, look at what came back, and then <em>steer</em> — which is faster and produces better work than agonising over the opening message.</p>'
						. '<h3>Four corrections that cover most cases</h3>'
						. '<ul>'
						. '<li><strong>"Half that length, keep the second point."</strong> Length and focus in one move, without re-explaining anything.</li>'
						. '<li><strong>"Less formal — like explaining to a colleague over coffee."</strong> A comparison beats an adjective; "friendly" is vaguer than a situation.</li>'
						. '<li><strong>"You have assumed we are B2C. We sell to hospitals. Redo it."</strong> Correct the wrong assumption directly rather than starting over.</li>'
						. '<li><strong>"Give me three versions: one cautious, one direct, one warm."</strong> When you cannot describe what you want, choosing is easier than specifying.</li>'
						. '</ul>'
						. '<h3>Why staying in the conversation wins</h3>'
						. '<p>Everything you have already established — the audience, the facts, the format, the three things you rejected — is still there. Starting a fresh chat throws all of it away and you rebuild it from memory, usually less completely.</p>'
						. '<h3>When to start fresh anyway</h3>'
						. '<p>Two cases. If the conversation has wandered a long way and the model keeps dragging in irrelevant earlier material, the context has become clutter. And if you are switching to a genuinely different task, a clean start is clearer than steering sideways.</p>'
						. '<h3>Ask it to ask you</h3>'
						. '<p>The most underused move of all: "Before you write anything, ask me the three questions that would most improve your answer." You will usually be asked something you had not thought to mention — and that missing detail was the reason your first attempt disappointed.</p>'
						. '<blockquote>Do not write the perfect prompt. Write a good one, read what comes back, and correct one thing.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Steer rather than restart. Correct assumptions directly, compare rather than describe, ask for options when specifying is hard, and let it interview you when you are stuck.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'The reply is good but assumes the wrong audience. What is the fastest fix?',
							'options'  => array(
								'Start a brand-new chat and rewrite the prompt',
								'Say what the audience actually is and ask for a redo',
								'Ask for it to be shorter',
								'Try a different AI tool',
							),
							'answer'   => 1,
							'hint'     => 'Correct the assumption without discarding everything else.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Starting a fresh chat is usually the best response to an answer that is close but not right.',
							'answer'   => 1,
							'hint'     => 'What happens to the context you already built?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'When you cannot describe the tone you want, asking for three ___ lets you choose instead of specify.',
							'answer_text' => 'versions',
							'accept'      => array( 'options', 'variants' ),
							'hint'        => 'Choosing is easier than describing.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'You asked for a customer apology email and got something stiff, twice as long as you wanted, and assuming the customer is a consumer when they are a business buyer. Write the single follow-up message that fixes all three — without starting over.',
							'rubric' => 'A strong answer is one continuation message that corrects the audience explicitly, gives a concrete length (a number, not "shorter"), and describes the tone by comparison to a situation rather than with a bare adjective. It should not re-supply context already given.',
							'hint'   => 'One message, three corrections, no re-explaining.',
						),
					),
				),

				array(
					'title'   => 'Working with files, images and voice',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Everyday workflows', 'Chat basics' ),
					'content' => '<h2>Most people only use the text box</h2>'
						. '<p>ChatGPT accepts more than typing, and the other inputs are where a lot of the everyday time saving actually lives.</p>'
						. '<h3>Files</h3>'
						. '<p>Upload a document or spreadsheet and ask about its contents. This is the single best habit to build, because it moves you from the unreliable half of AI use to the reliable half: the material is now in front of the model instead of being remembered.</p>'
						. '<p>Two cautions. A very long file may not be read in full detail — ask about specific sections rather than assuming page 40 got the same attention as page 1. And uploading is still sending, so the rules about confidential material apply exactly as before.</p>'
						. '<h3>Images</h3>'
						. '<p>Photograph a whiteboard after a workshop and ask for it as structured notes. Screenshot an error and ask what it means. Send a chart and ask what it shows. This works well for reading and describing — and less well for precise counting or exact measurement, so check anything numeric.</p>'
						. '<h3>Voice</h3>'
						. '<p>Speaking is roughly three times faster than typing, and rambling is fine — the model handles disorganised speech well. Talking through a problem on a walk and asking for structured notes at the end is a genuinely different way of working, not a gimmick.</p>'
						. '<h3>Combining them is where it gets good</h3>'
						. '<p>Photograph handwritten meeting notes, upload last quarter\'s report, and ask for a summary that connects the two. Neither input alone would have been enough.</p>'
						. '<blockquote>If material exists as a file, a photo or a thought you can speak, you do not have to retype it. Retyping is the tax people pay for not knowing this.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Upload rather than paraphrase, photograph rather than transcribe, speak rather than type when it is faster — and keep checking anything numeric, whatever the input was.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why is uploading a document better than describing what it says?',
							'options'  => array(
								'It uses fewer words',
								'It moves the task from remembering to reading, which is the reliable mode',
								'Uploads are always free',
								'The model prefers files to text',
							),
							'answer'   => 1,
							'hint'     => 'Which mode invents less?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Image understanding is reliable for exact counting and precise measurement.',
							'answer'   => 1,
							'hint'     => 'What should you always check?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Confidentiality rules apply to uploads exactly as they do to pasted text, because uploading is still ___.',
							'answer_text' => 'sending',
							'accept'      => array( 'sharing', 'transferring' ),
							'hint'        => 'The file leaves your machine.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Describe one task from your week where uploading a file or photo instead of typing would have saved real time — and say what you would ask for.',
							'rubric'   => 'A strong answer names a concrete task where the material already exists in another form (a document, spreadsheet, whiteboard photo or screenshot), states the specific request that would be made of it, and shows awareness of whether the content is confidential.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Confident everyday use — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which follow-up is most likely to improve a disappointing answer?',
						'options'  => array(
							'"Make it better."',
							'"Half that length, and the reader is a hospital procurement manager."',
							'"Try again."',
							'"That was wrong."',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Asking it to ___ you three clarifying questions before writing usually surfaces the detail you forgot to give.',
						'answer_text' => 'ask',
						'accept'      => array( 'put to' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Uploading a document puts the material in front of the model instead of relying on its memory.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'A good reason to start a genuinely new chat is:',
						'options'  => array(
							'The answer needed one small correction',
							'You are switching to a completely different task',
							'You want a shorter reply',
							'The tone was slightly off',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Making it a habit that lasts',
			'lessons' => array(

				array(
					'title'   => 'Where it fits in a normal week',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Everyday workflows', 'Custom instructions' ),
					'content' => '<h2>The problem is not capability. It is remembering to use it</h2>'
						. '<p>Most people finish a course like this convinced, use the tool enthusiastically for a week, and then drift back to old habits — not because it stopped working, but because reaching for it never became automatic.</p>'
						. '<h3>Attach it to something you already do</h3>'
						. '<p>New habits stick when they hang off existing ones. You already have a weekly report, a Monday inbox, a meeting that produces notes, a recurring type of customer question. Pick one and attach AI to it specifically — "after every client call, paste my notes and ask for the action items" is a habit. "Use AI more" is a resolution.</p>'
						. '<h3>The four moments it usually pays</h3>'
						. '<ul>'
						. '<li><strong>Facing a blank page.</strong> Anything to react to beats nothing, even when you rewrite most of it.</li>'
						. '<li><strong>Facing too much text.</strong> Long thread, long report, long document — summarise and extract.</li>'
						. '<li><strong>Changing the shape of something.</strong> Notes to a table, prose to bullets, technical to plain.</li>'
						. '<li><strong>Not understanding something.</strong> Ask for it at the level you actually need, then ask again if it is still not clear. It does not get impatient.</li>'
						. '</ul>'
						. '<h3>And the moments it does not</h3>'
						. '<p>When you already know exactly what to write, typing it is faster than prompting for it and editing. When the answer must be exactly right and you cannot check it. When the thinking <em>is</em> the work — deciding what you believe is not a task to outsource.</p>'
						. '<blockquote>Pick one recurring moment this week. One habit that sticks beats twenty experiments you abandon.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Attach it to an existing routine, learn the four moments where it reliably pays, and be honest about the ones where it does not.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which is most likely to become a lasting habit?',
							'options'  => array(
								'"Use AI more this quarter."',
								'"After every client call, paste my notes and ask for action items."',
								'"Explore what AI can do."',
								'"Try a new AI tool each week."',
							),
							'answer'   => 1,
							'hint'     => 'Which one is attached to something you already do?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'When you already know exactly what you want to write, prompting for it and editing is usually faster than just writing it.',
							'answer'   => 1,
							'hint'     => 'Count the editing time.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A new habit sticks best when it is attached to a routine you ___ have.',
							'answer_text' => 'already',
							'accept'      => array( 'currently' ),
							'hint'        => 'Hang it off an existing hook.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Name the one recurring moment in your week where you will use AI, and say exactly what will trigger it.',
							'rubric'   => 'A strong answer names a specific recurring event and a concrete trigger-and-action pair, rather than a general intention. The trigger should be something that already reliably happens.',
						),
					),
				),

				array(
					'title'   => 'Your personal AI ground rules',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 30,
					'topics'  => array( 'Limits & safety', 'Custom instructions', 'Everyday workflows' ),
					'content' => '<h2>Five lines you write once and follow forever</h2>'
						. '<p>Most people either use AI with no rules at all or follow a corporate policy they have never read. A short personal set — genuinely yours, genuinely followed — is worth more than either.</p>'
						. '<h3>The five to decide now</h3>'
						. '<ol>'
						. '<li><strong>What I will never paste.</strong> Be specific: customer names and contact details, unreleased financials, anything under NDA, credentials. Vague rules get bent when you are busy.</li>'
						. '<li><strong>What I always verify.</strong> Numbers, names, dates, quotations, anything with a source attached, and anything a client will see. Everything the model was not given by me.</li>'
						. '<li><strong>Where I will not use it at all.</strong> The judgement calls that are your job — a performance review, a decision you own, an apology that should come from a person.</li>'
						. '<li><strong>What I disclose.</strong> Decide your line — nobody expects a note on a spellcheck; most people would want to know if a substantive analysis was AI-drafted — and apply it consistently rather than case by case.</li>'
						. '<li><strong>What I stay responsible for.</strong> Everything you send. There is no version of this where the tool is accountable.</li>'
						. '</ol>'
						. '<h3>Write them down</h3>'
						. '<p>Rules you have only thought about are rules you will rationalise past on a bad afternoon. Written ones you can check in ten seconds.</p>'
						. '<h3>The rest of the ladder</h3>'
						. '<p>You now have the everyday foundation: how to ask, how to steer, what to upload, where it fails, and how to build the habit. The next rungs go further — getting reliably structured output you can paste into real work, then chaining prompts across long material, then leading how a whole team adopts it.</p>'
						. '<blockquote>The people who get the most from AI are not the ones with the cleverest prompts. They are the ones who know exactly where their own judgement still has to do the work.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Decide the five, write them down, and keep the last one in view: you are accountable for everything you send, however it was drafted.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which of these should always be on the "never paste" list?',
							'options'  => array(
								'A public press release',
								'Your own rough draft',
								'Customer names and contact details',
								'A meeting agenda',
							),
							'answer'   => 2,
							'hint'     => 'Which one is someone else\'s personal data?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Anything the model was not given by you should be ___ before it goes anywhere.',
							'answer_text' => 'verified',
							'accept'      => array( 'checked' ),
							'hint'        => 'Numbers, names, dates, quotations.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'If AI drafted something and it turns out to be wrong, responsibility is shared with the tool.',
							'answer'   => 1,
							'hint'     => 'Who pressed send?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write your own five ground rules, filled in with specifics from your actual job — not generic advice. Make each one concrete enough that you could tell in ten seconds whether you had followed it.',
							'rubric' => 'A strong answer is specific to the writer\'s work: named categories of data they handle, the particular things they must verify, at least one task they are explicitly reserving for their own judgement, a stated disclosure line, and an unambiguous statement of personal accountability. Generic phrasing such as "be careful with sensitive data" does not meet the bar.',
							'hint'   => 'Specific enough to check in ten seconds.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'What was the biggest thing you were doing wrong with ChatGPT before this course?',
							'rubric'   => 'A thoughtful answer identifies one concrete prior habit — asking about things it was never given, accepting the first answer, restarting instead of steering, retyping material that could have been uploaded — rather than a general statement about improvement.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Making it a habit — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which moment does AI reliably pay off in?',
						'options'  => array(
							'Deciding what you personally believe about a strategy',
							'Turning a long thread into a summary with action items',
							'Writing a sentence you already have fully in mind',
							'Making a judgement call you own',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Written ground rules are harder to rationalise past than ones you have only thought about.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'You remain ___ for everything you send, however it was drafted.',
						'answer_text' => 'responsible',
						'accept'      => array( 'accountable' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'The strongest reason to attach AI use to an existing routine is that:',
						'options'  => array(
							'Routines are more important than new work',
							'A habit hung off something you already do actually survives past week one',
							'It uses fewer tokens',
							'The model works better on schedule',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
