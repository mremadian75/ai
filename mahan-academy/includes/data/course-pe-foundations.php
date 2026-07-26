<?php
/**
 * Seed course: "Prompting Foundations" (Prompt Engineering bundle).
 *
 * This is the CANONICAL schema contract for every seed course file in this
 * directory. The seeder ({@see Mahan_Seed}) loads each `course-*.php` and
 * expects exactly this shape. Keys and value types:
 *
 *   seed_key    string  Stable unique id — idempotency marker (never reuse).
 *   title       string
 *   slug        string  post_name.
 *   subtitle    string  Course subtitle (M_SUBTITLE).
 *   excerpt     string  Short catalog blurb (post_excerpt).
 *   description string  Course landing HTML (post_content).
 *   category    string  mahan_category term name (Coursera-style domain).
 *   level       string  'beginner' | 'intermediate' | 'advanced'.
 *   est_hours   int
 *   featured    bool
 *   certificate bool
 *   order       int     menu_order in the catalog.
 *   topics      string[] Course-level concept tags (mahan_topic).
 *   outcomes    string[] "What you'll learn" bullets.
 *   references  array of { title, source, url? } — the authoritative sources the
 *               course is grounded in, shown as "Further reading" (optional).
 *   units       array of {
 *       title    string  Unit title (also the quiz key).
 *       lessons  array of {
 *           title   string
 *           type    string 'reading' | 'practice'
 *           est_min int
 *           xp      int
 *           topics  string[]
 *           content string  Lesson body HTML (no <html>/<body>, no inline styles).
 *           exercises array of exercise defs (see below).
 *       }
 *       quiz (optional) { title, passing:int, xp:int, questions: quiz-question[] }
 *   }
 *
 * Exercise defs — allowed `type` and required keys:
 *   multiple_choice : question, options[>=2], answer(0-based int), hint?, feedback_correct?, feedback_incorrect?
 *   true_false      : question, answer(0=True|1=False), hint?
 *   fill_blank      : question (use ___ for the blank), answer_text, accept[]?, hint?
 *   short_answer    : question, rubric
 *   reflection      : question, rubric?
 *   prompt_task     : task, rubric, hint?
 *
 * Quiz questions may only be multiple_choice | true_false | fill_blank.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'pe-foundations',
	'title'       => 'Prompting Foundations',
	'slug'        => 'prompting-foundations',
	'subtitle'    => 'Write clear, reliable prompts that get useful answers the first time.',
	'excerpt'     => 'The bedrock skills of prompt engineering — how models read your words, the anatomy of a strong prompt, and how to steer tone, format, and accuracy.',
	'description' => '<p>Everyone can type a question into an AI chat box. <strong>Prompting</strong> is the skill of getting a genuinely useful answer — reliably, and without three rounds of back-and-forth.</p>'
		. '<p>This course starts from zero. You will learn how a language model actually reads your request, the four parts every strong prompt shares, and simple moves that turn a vague reply into exactly what you needed. No coding, no jargon — just practical technique you can use in any AI tool today.</p>',
	'category'    => 'Prompt Engineering',
	'track'       => 'prompt-engineering',
	'level_rank'  => 1,
	'level'       => 'beginner',
	'est_hours'   => 3,
	'featured'    => true,
	'certificate' => true,
	'order'       => 1,
	'topics'      => array( 'Prompt design', 'Context & role', 'Output format', 'Iteration' ),
	'outcomes'    => array(
		'Explain how a language model turns your prompt into a response',
		'Write prompts with clear role, context, task, and format',
		'Control the tone, length, and structure of an answer',
		'Fix a weak prompt by adding the one thing it was missing',
		'Iterate quickly instead of restarting from scratch',
	),
	'references'  => array(
		array(
			'title'  => 'Prompt engineering overview',
			'source' => 'Anthropic — Claude documentation',
			'url'    => 'https://docs.anthropic.com/en/docs/build-with-claude/prompt-engineering/overview',
		),
		array(
			'title'  => 'Prompt engineering guide',
			'source' => 'OpenAI — API documentation',
			'url'    => 'https://platform.openai.com/docs/guides/prompt-engineering',
		),
		array(
			'title'  => 'Language Models are Few-Shot Learners (GPT-3)',
			'source' => 'Brown et al., 2020 — arXiv:2005.14165',
			'url'    => 'https://arxiv.org/abs/2005.14165',
		),
		array(
			'title'  => 'Introduction to Large Language Models',
			'source' => 'Google — Machine Learning Education',
			'url'    => 'https://developers.google.com/machine-learning/resources/intro-llms',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'How models read your prompt',
			'lessons' => array(

				array(
					'title'   => 'What a prompt really is',
					'type'    => 'reading',
					'est_min' => 8,
					'xp'      => 20,
					'topics'  => array( 'Prompt design' ),
					'content' => '<h2>A prompt is an instruction, not a search query</h2>'
						. '<p>When you use a search engine, you type <em>keywords</em> and it finds pages that contain them. A language model works differently: it reads your <strong>whole prompt as instructions</strong> and writes a fresh answer, word by word, that it predicts should follow.</p>'
						. '<p>That single difference changes everything. Because the model is following instructions, the quality of what you get back is mostly decided by the quality of what you put in. Vague in, vague out.</p>'
						. '<h3>Why "write me something about marketing" fails</h3>'
						. '<p>The model has no idea who you are, what you sell, who the reader is, or what "something" means. So it guesses — and averages. You get bland, generic text because you asked a bland, generic question.</p>'
						. '<blockquote>The model is not reading your mind. It is reading your words. Everything it does not know, it invents or averages.</blockquote>'
						. '<h3>A concrete example</h3>'
						. '<p>Compare these two prompts a sales rep might use:</p>'
						. '<ul>'
						. '<li><strong>Weak:</strong> "Write a follow-up email."</li>'
						. '<li><strong>Strong:</strong> "Write a 90-word follow-up email to a prospect who went quiet after a demo. Friendly, not pushy. Remind them we cut onboarding time by 40%. End with one clear next step."</li>'
						. '</ul>'
						. '<p>Same tool, wildly different results — because the second prompt gave the model the facts and constraints it needed to do the job.</p>'
						. '<h3>Recap</h3>'
						. '<p>Treat the model like a sharp new colleague on their first day: capable, but only as good as the brief you give them. Working in {{role}}, your fastest win is to take one real task toward {{primary_goal}} and turn it into a proper brief today. The rest of this course is about writing a great brief.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'How does a language model mainly treat the text of your prompt?',
							'options'  => array(
								'As keywords to look up in a database',
								'As instructions it follows to generate a new answer',
								'As a password that unlocks a stored response',
								'As a spelling test',
							),
							'answer'   => 1,
							'hint'     => 'Think about the difference between a search engine and a colleague you brief.',
							'feedback_correct'   => 'Exactly — it reads the whole prompt as instructions and writes a fresh reply.',
							'feedback_incorrect' => 'Not quite. A model follows your prompt as instructions rather than matching keywords.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A vague prompt tends to produce a generic answer because the model fills the gaps by averaging.',
							'answer'   => 0,
							'hint'     => 'Vague in, vague out.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Everything the model does not know from your prompt, it will invent or ___.',
							'answer_text' => 'average',
							'accept'      => array( 'averages', 'guess', 'guesses' ),
							'hint'        => 'It smooths toward the most typical answer.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Rewrite this weak prompt so a model could do it well: "Write a follow-up email." Add who the reader is, the goal, the tone, one concrete fact, and the length.',
							'rubric' => 'A strong answer specifies the audience, the desired outcome, a tone, at least one concrete detail, and a length or format constraint.',
							'hint'   => 'Give it the facts a human colleague would need to write the same email.',
						),
					),
				),

				array(
					'title'   => 'Tokens, context, and why length matters',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'Context & role' ),
					'content' => '<h2>The model reads in tokens, and it only sees your window</h2>'
						. '<p>A model does not read letters or even whole words — it reads <strong>tokens</strong>, chunks that are often a word or part of a word. "Prompting" might be one token; "unbelievable" might be three. You do not need to count them, but two practical facts follow.</p>'
						. '<h3>1. There is a limit — the context window</h3>'
						. '<p>Everything the model can consider at once — your instructions, any text you paste, the conversation so far, and its own answer — has to fit inside a fixed <strong>context window</strong>. Go past it and the oldest content falls out of view. That is why a very long chat can seem to "forget" what you said at the start.</p>'
						. '<h3>2. Only what is in the window exists</h3>'
						. '<p>The model cannot see a file you did not paste, a link you did not include, or a fact it was never told. If you want it to use something, it has to be <em>in the prompt</em>.</p>'
						. '<blockquote>If it is not in the context window, for this answer it does not exist.</blockquote>'
						. '<h3>A real-work example</h3>'
						. '<p>An operations manager pastes last month\'s KPI table directly into the prompt and asks for a summary. That works, because the numbers are now in the window. Asking "summarise our KPIs" with no table pasted does not — the model will invent plausible-looking figures.</p>'
						. '<h3>Recap</h3>'
						. '<p>Give the model the material it needs, keep the important instructions close to your actual question, and start a fresh chat when a conversation gets long and muddled.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why can a long conversation make a model seem to "forget" earlier details?',
							'options'  => array(
								'The model gets tired',
								'Old content scrolls out of the fixed context window',
								'It deletes your messages to save money',
								'Longer chats switch to a weaker model',
							),
							'answer'   => 1,
							'hint'     => 'Everything has to fit in a fixed window.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A model can read a document you mention by name even if you did not paste its contents.',
							'answer'   => 1,
							'hint'     => 'If it is not in the window, it does not exist for that answer.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Models read text in chunks called ___, not individual letters.',
							'answer_text' => 'tokens',
							'accept'      => array( 'token' ),
							'hint'        => 'Often a word or part of a word.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'In your own words, why should you paste the actual data into a prompt instead of just referring to it?',
							'rubric'   => 'A good answer explains that the model can only use information present in the context window, so referenced-but-not-included material is unavailable and may be fabricated.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'How models read your prompt — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which prompt is most likely to get a useful answer on the first try?',
						'options'  => array(
							'"Tell me about our product."',
							'"Write a 100-word product blurb for busy IT managers, emphasising security and easy setup."',
							'"Product info please."',
							'"Marketing."',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'The main lever on answer quality is the quality of the instructions you give.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'The fixed amount of text a model can consider at once is called the context ___.',
						'answer_text' => 'window',
						'accept'      => array(),
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'The anatomy of a strong prompt',
			'lessons' => array(

				array(
					'title'   => 'Role, context, task, format',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Prompt design', 'Output format' ),
					'content' => '<h2>Four parts that turn a request into a brief</h2>'
						. '<p>Almost every strong prompt contains four ingredients. You do not need fancy wording — just make sure each is present.</p>'
						. '<h3>1. Role</h3>'
						. '<p>Tell the model who to be. "You are a senior financial analyst" primes it toward the right vocabulary, assumptions, and level of rigour.</p>'
						. '<h3>2. Context</h3>'
						. '<p>Give the facts of the situation: who the audience is, what you are working on, any data or constraints. This is where you paste the material the model needs.</p>'
						. '<h3>3. Task</h3>'
						. '<p>State the one thing you want done, using a clear verb: summarise, draft, compare, classify, rewrite. One prompt, one main job.</p>'
						. '<h3>4. Format</h3>'
						. '<p>Describe the shape of the output: a five-bullet list, a two-sentence summary, a table with three columns, JSON, a friendly email. If you do not specify, you get the model\'s default — usually a wall of prose.</p>'
						. '<blockquote>Role + Context + Task + Format. Miss one and you can usually predict exactly how the answer will disappoint you.</blockquote>'
						. '<h3>Putting it together</h3>'
						. '<p>"You are an HR partner (role). We are onboarding five remote engineers next week (context). Draft a welcome checklist (task) as a numbered list of no more than eight items (format)." Every part is doing a job.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A prompt gives a role, context, and task but the answer comes back as a long paragraph when you wanted bullets. Which part was missing?',
							'options'  => array( 'Role', 'Context', 'Task', 'Format' ),
							'answer'   => 3,
							'hint'     => 'What controls the shape of the output?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Starting a prompt with "You are a senior analyst…" is setting the model\'s ___.',
							'answer_text' => 'role',
							'accept'      => array( 'persona' ),
							'hint'        => 'Who you tell it to be.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'It is best to pack several unrelated tasks into a single prompt to save time.',
							'answer'   => 1,
							'hint'     => 'One prompt, one main job.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write a prompt that uses all four parts (role, context, task, format) to produce a short meeting agenda for a project kickoff in your field.',
							'rubric' => 'A strong answer clearly includes a role, situational context, a single clear task verb, and an explicit output format.',
							'hint'   => 'Label the parts in your head as you write to make sure none is missing.',
						),
					),
				),

				array(
					'title'   => 'Steering tone, length, and accuracy',
					'type'    => 'practice',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Output format', 'Iteration' ),
					'content' => '<h2>Small instructions, big control</h2>'
						. '<p>Once your prompt has the four parts, a few extra instructions give you fine control.</p>'
						. '<h3>Tone</h3>'
						. '<p>Name the voice you want: "warm and encouraging", "concise and formal", "plain English, no jargon". You can even anchor it: "in the style of a helpful colleague, not a press release".</p>'
						. '<h3>Length</h3>'
						. '<p>Be specific: "under 80 words", "exactly three bullets", "one paragraph". "Short" is subjective; "80 words" is not.</p>'
						. '<h3>Accuracy and honesty</h3>'
						. '<p>Models can state wrong things confidently — this is often called <strong>hallucination</strong>. Two moves reduce it: give the model the source material to work from, and add "If you are not sure, say so rather than guessing." For anything that matters, verify facts yourself.</p>'
						. '<blockquote>You cannot make a model perfect, but you can make it honest about its limits — just ask it to be.</blockquote>'
						. '<h3>Example</h3>'
						. '<p>"Summarise the pasted policy in three bullets, plain English, under 60 words. If the policy does not cover remote work, say \'Not addressed\' rather than guessing."</p>'
						. '<h3>Recap</h3>'
						. '<p>Tone, length, and an honesty instruction are cheap to add and dramatically improve how usable the answer is.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which instruction best reduces the risk of a confidently wrong answer?',
							'options'  => array(
								'"Be creative and fill in any gaps."',
								'"Work only from the text I pasted, and say if something is not covered."',
								'"Answer as fast as possible."',
								'"Make it sound very confident."',
							),
							'answer'   => 1,
							'hint'     => 'Ground it in a source and let it admit uncertainty.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'When a model states something false but sounds sure, that is commonly called a ___.',
							'answer_text' => 'hallucination',
							'accept'      => array( 'hallucinations' ),
							'hint'        => 'A confident but invented "fact".',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Asking for "under 80 words" gives more reliable length control than asking for "short".',
							'answer'   => 0,
							'hint'     => 'Specific beats subjective.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Take a task from your own work and write a prompt that controls tone, sets an explicit length, and tells the model to admit uncertainty instead of guessing.',
							'rubric' => 'A strong answer names a tone, gives a concrete length/format, and includes an instruction to flag uncertainty or avoid fabrication.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Anatomy of a strong prompt — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which set names the four core parts of a strong prompt?',
						'options'  => array(
							'Role, context, task, format',
							'Who, what, when, where',
							'Intro, body, conclusion, summary',
							'Keyword, synonym, filter, sort',
						),
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'To control output length reliably, give a specific number of words instead of the vague word "___".',
						'answer_text' => 'short',
						'accept'      => array(),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Grounding the model in pasted source material and letting it say "not sure" helps reduce hallucinations.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Your prompt returns a stiff, corporate tone but you wanted something warmer. The quickest fix is to:',
						'options'  => array(
							'Switch to a different computer',
							'Add a tone instruction such as "warm, like a helpful colleague"',
							'Make the prompt much longer',
							'Remove the context',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'Iterating instead of restarting',
			'lessons' => array(

				array(
					'title'   => 'Read the answer like a diagnosis',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Iteration' ),
					'content' => '<h2>A disappointing answer is evidence, not a dead end</h2>'
						. '<p>The most common mistake after a bad reply is to delete the prompt and type a different one from scratch. That throws away the only useful information you just bought: <strong>the specific way it went wrong</strong>.</p>'
						. '<p>Almost every disappointing answer fails in one of a handful of recognisable ways, and each one points at a missing ingredient.</p>'
						. '<h3>The diagnostic table</h3>'
						. '<table><thead><tr><th>What came back</th><th>What was missing</th><th>The fix</th></tr></thead><tbody>'
						. '<tr><td>Generic, could be about any company</td><td>Context</td><td>Paste the actual facts, names, numbers</td></tr>'
						. '<tr><td>Right content, wrong shape</td><td>Format</td><td>State the structure: bullets, table, word count</td></tr>'
						. '<tr><td>Right shape, wrong register</td><td>Role or tone</td><td>Name the speaker and the reader</td></tr>'
						. '<tr><td>Confident but wrong facts</td><td>Grounding</td><td>Supply the source; allow "I don\'t know"</td></tr>'
						. '<tr><td>Answered a different question</td><td>Task clarity</td><td>One verb, one job, moved to the end</td></tr>'
						. '<tr><td>Too long, buries the point</td><td>A limit</td><td>Give a number, not "briefly"</td></tr>'
						. '</tbody></table>'
						. '<h3>Change one thing at a time</h3>'
						. '<p>If you rewrite the role, the context and the format all at once and the answer improves, you have learned nothing about which change did it. Adjust one ingredient, look at what moved, keep it or discard it. This is slower for one prompt and far faster across the hundred you will write this year.</p>'
						. '<blockquote>Do not ask "what should I write instead?" Ask "which of the four parts was thin?" The second question has an answer.</blockquote>'
						. '<h3>Steer inside the same conversation</h3>'
						. '<p>You rarely need a new chat. "That is close — make it half the length and drop the third point" keeps everything the model already knows and corrects only what was wrong. Starting over means re-supplying all the context you had just finished giving it.</p>'
						. '<h3>Recap</h3>'
						. '<p>Diagnose the failure, name the missing ingredient, change that one thing, and stay in the conversation while you do it.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'An answer is accurate and well-structured but reads like a legal notice when you wanted it friendly. Which ingredient is thin?',
							'options'  => array( 'Context', 'Task', 'Role and tone', 'Grounding' ),
							'answer'   => 2,
							'hint'     => 'Which part decides who is speaking, and to whom?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'When a reply is close but not right, starting a brand-new chat is usually the fastest fix.',
							'answer'   => 1,
							'hint'     => 'What happens to all the context you already supplied?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Change ___ thing at a time, so you can tell which change actually helped.',
							'answer_text' => 'one',
							'accept'      => array( 'a single', 'single' ),
							'hint'        => 'The opposite of rewriting everything at once.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Here is a real failure: you asked for "a summary of our Q3 results" and got a confident summary containing numbers you have never seen. Write the follow-up message that fixes the actual problem — do not start a new chat.',
							'rubric' => 'A strong answer identifies missing grounding as the cause, supplies (or says it will paste) the real figures, and explicitly permits the model to say it does not know rather than fill gaps. It should be a continuation of the conversation, not a fresh prompt.',
							'hint'   => 'Invented numbers is the classic symptom of one specific missing ingredient.',
						),
					),
				),

				array(
					'title'   => 'Showing beats explaining: examples',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Iteration', 'Output format' ),
					'content' => '<h2>When describing what you want stops working</h2>'
						. '<p>Some requirements are easy to describe: "under 100 words", "in a table", "no jargon". Others are almost impossible — house style, the particular way your team writes a bug title, the voice of your newsletter. You know it when you see it, and so does the model, but only if you <strong>show</strong> it.</p>'
						. '<p>Including one or two examples of the output you want is the single highest-leverage move in this course. Researchers call it few-shot prompting; in practice it is just "here is one I made earlier".</p>'
						. '<h3>The shape of an example-led prompt</h3>'
						. '<p>Give the instruction, then a worked example, then the real input:</p>'
						. '<pre><code>Rewrite each support ticket title so it names the symptom, not the guess.\n\nExample\nBefore: "Database is broken again"\nAfter: "Checkout fails with timeout after 30s"\n\nNow rewrite:\nBefore: "Emails not working"</code></pre>'
						. '<p>Nothing in that prompt explains your style rules. The example carries them: past tense out, specific symptom in, no blame, roughly this length.</p>'
						. '<h3>One good example beats five mediocre ones</h3>'
						. '<p>The model imitates what it sees — including mistakes. If your example is inconsistent, the output will be too. Pick a case you would be happy to receive back.</p>'
						. '<h3>A counter-example is a sharp tool</h3>'
						. '<p>Showing one thing you do <em>not</em> want, labelled clearly, fixes stubborn habits fast: "Avoid this: \'We are pleased to announce…\'". Use it sparingly — a prompt that is mostly prohibitions produces cautious, lifeless writing.</p>'
						. '<blockquote>If you have tried to describe it twice and it still is not right, stop describing and paste an example.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Describe what is easy to describe. Show everything else. One clean example, and a counter-example only when a habit will not shift.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which requirement is best communicated with an example rather than an instruction?',
							'options'  => array(
								'A 200-word limit',
								'Output as valid JSON',
								'Our team\'s particular tone of voice',
								'Use British spelling',
							),
							'answer'   => 2,
							'hint'     => 'Which one would you struggle to write down as a rule?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Giving a model one or two worked examples of the output you want is known as ___-shot prompting.',
							'answer_text' => 'few',
							'accept'      => array( 'one', 'n' ),
							'hint'        => 'As opposed to zero-shot, where you only describe.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Adding more examples always improves the result, so include as many as you can find.',
							'answer'   => 1,
							'hint'     => 'What happens if the examples disagree with each other?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write an example-led prompt that turns rough meeting notes into a standard action item in your own team\'s style. Include the instruction, exactly one before/after example, and then the real input.',
							'rubric' => 'A strong answer has three clearly separated parts: a one-line instruction, a single consistent before/after pair that demonstrates the style rather than describing it, and the real input left for the model to process. The example should itself be well-formed enough to imitate.',
							'hint'   => 'The example is doing the teaching — make it one you would be happy to receive.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Iterating instead of restarting — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'The answer is generic and could describe any company in your industry. What is missing?',
						'options'  => array( 'Format', 'Context', 'A word limit', 'A different model' ),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Changing several parts of a prompt at once makes it harder to learn which change helped.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'When describing a style twice has not worked, stop describing and paste an ___.',
						'answer_text' => 'example',
						'accept'      => array( 'examples' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'A model returns confident figures you have never seen. The right correction is to:',
						'options'  => array(
							'Ask it to be more careful next time',
							'Supply the real source and allow it to say it does not know',
							'Increase the word limit',
							'Rephrase the question as a command',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Judgement: trust, limits, and your own checklist',
			'lessons' => array(

				array(
					'title'   => 'Where models are strong and where they are not',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Prompt design', 'Iteration' ),
					'content' => '<h2>Knowing when not to ask is part of the skill</h2>'
						. '<p>A language model predicts fluent text. Everything it is good at, and everything it is bad at, follows from that one fact — and fluency is not the same thing as being right.</p>'
						. '<h3>Reliably strong</h3>'
						. '<ul>'
						. '<li><strong>Transforming text you supply</strong> — summarising, rewriting, translating, changing register, extracting fields. The material is in front of it, so there is little to invent.</li>'
						. '<li><strong>Producing structure</strong> — turning prose into a table, notes into an agenda, requirements into a checklist.</li>'
						. '<li><strong>Getting you off a blank page</strong> — a first draft to react to is easier than nothing, even when you rewrite most of it.</li>'
						. '<li><strong>Explaining at a chosen level</strong> — the same concept for a board, a new starter, or a specialist.</li>'
						. '</ul>'
						. '<h3>Reliably weak</h3>'
						. '<ul>'
						. '<li><strong>Facts it was never given</strong> — private figures, internal policy, anything after its training cutoff. It will answer anyway, and it will sound certain.</li>'
						. '<li><strong>Exact arithmetic over long chains</strong> — it is predicting the next token, not calculating. Check the numbers or give it a calculator tool.</li>'
						. '<li><strong>Citations</strong> — plausible-looking references to papers, cases and page numbers that do not exist. Never pass one on unchecked.</li>'
						. '<li><strong>Saying "I don\'t know"</strong> — unless you explicitly make that an acceptable answer, it will fill the gap.</li>'
						. '</ul>'
						. '<blockquote>Fluency is not accuracy. The answer that sounds most confident is not the one that has been checked — it is just the one that is well written.</blockquote>'
						. '<h3>The practical rule</h3>'
						. '<p>Ask yourself: <em>could I verify this in under a minute?</em> If yes, use the model freely and verify. If no — and the answer matters — either supply the source material yourself, or do not use the model for that part.</p>'
						. '<h3>Recap</h3>'
						. '<p>Lean on it for transformation, structure and first drafts. Distrust it for unsupplied facts, exact arithmetic and citations. Make "I don\'t know" a permitted answer.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which task plays most directly to a language model\'s strengths?',
							'options'  => array(
								'Telling you last quarter\'s exact revenue',
								'Turning a page of meeting notes you paste in into a structured action list',
								'Citing the page number of a specific court judgment',
								'Adding a long column of figures',
							),
							'answer'   => 1,
							'hint'     => 'Which one works entirely from material you supplied?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A confident, well-written answer is a reasonable signal that the facts in it are correct.',
							'answer'   => 1,
							'hint'     => 'What is the model actually optimising for?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Model-generated references to papers or cases are notorious for being plausible but ___.',
							'answer_text' => 'fabricated',
							'accept'      => array( 'invented', 'made up', 'fake', 'nonexistent', 'non-existent' ),
							'hint'        => 'They look real and are not.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Describe one task in your own work you would happily hand to a model, and one you would not — and say what distinguishes them.',
							'rubric'   => 'A strong answer names two concrete tasks and identifies the real distinguishing factor: whether the material needed is supplied in the prompt versus recalled by the model, and whether a wrong answer would be caught quickly or pass unnoticed.',
						),
					),
				),

				array(
					'title'   => 'Build your own prompt checklist',
					'type'    => 'practice',
					'est_min' => 12,
					'xp'      => 30,
					'topics'  => array( 'Prompt design', 'Output format', 'Iteration' ),
					'content' => '<h2>Turning this course into something you actually use</h2>'
						. '<p>Technique you have to remember is technique you will skip when you are busy. The fix is a short checklist you keep beside you until it becomes automatic — the same reason surgeons and pilots use them, and for the same reason: not because the steps are hard, but because under pressure people skip the obvious one.</p>'
						. '<h3>The five questions</h3>'
						. '<ol>'
						. '<li><strong>Who is speaking, and to whom?</strong> Role and audience.</li>'
						. '<li><strong>What does it need to know that only I know?</strong> Context — and paste it, do not refer to it.</li>'
						. '<li><strong>What is the one job?</strong> A single clear verb.</li>'
						. '<li><strong>What shape should the answer be?</strong> Format, with a number wherever "short" or "a few" would otherwise appear.</li>'
						. '<li><strong>What would make this answer wrong, and would I notice?</strong> If the answer is "no", supply the source or check it yourself.</li>'
						. '</ol>'
						. '<h3>Keep the ones that work</h3>'
						. '<p>When a prompt finally produces exactly what you wanted, do not close the tab. Save it. Most professional work is repetitive — the weekly report, the ticket triage, the customer reply — and a saved prompt turns a ten-minute exercise into a thirty-second one. A folder of six prompts you actually reuse is worth more than a hundred you read about.</p>'
						. '<h3>Write for the next person</h3>'
						. '<p>Replace the parts that change with obvious placeholders — <code>[PASTE TRANSCRIPT]</code>, <code>[CUSTOMER NAME]</code> — so a colleague can use it without reverse-engineering what it was built for. This is the point where personal technique becomes a team asset.</p>'
						. '<blockquote>Five questions before you send. One folder of prompts that work. That is the whole discipline.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>You now have the model of how prompts are read, the four ingredients, a way to diagnose failures, examples for what cannot be described, and a sense of what to distrust. The checklist is what makes you use all of it.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why does the checklist end with "what would make this answer wrong, and would I notice?"',
							'options'  => array(
								'To make the prompt longer',
								'Because an error you cannot catch is the one that reaches your reader',
								'Because models refuse uncertain questions',
								'To reduce the cost of the request',
							),
							'answer'   => 1,
							'hint'     => 'Which errors actually cause damage — the ones you spot, or the ones you do not?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'In a reusable prompt, the parts that change each time should be marked with obvious ___.',
							'answer_text' => 'placeholders',
							'accept'      => array( 'placeholder' ),
							'hint'        => 'Like [PASTE TRANSCRIPT].',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A prompt that worked well is worth saving even though you could write it again.',
							'answer'   => 0,
							'hint'     => 'How much of your work repeats?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Take the single task you repeat most often at work and write it as a reusable prompt: role, context with placeholders, one clear task, an explicit output format, and a line permitting the model to flag anything it is unsure of.',
							'rubric' => 'A strong answer is genuinely reusable — it contains marked placeholders rather than one situation\'s details, names a role and audience, states exactly one task, specifies the output shape with a concrete limit, and includes an instruction that allows the model to signal uncertainty instead of guessing.',
							'hint'   => 'Run the five questions over it before you submit.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'Looking back at how you were prompting before this course, which of the four ingredients were you most often leaving out — and what did the answers look like as a result?',
							'rubric'   => 'A thoughtful answer connects a specific missing ingredient to the specific disappointment it caused, rather than giving a general statement about improvement.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Judgement and your checklist — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which of these should you least trust a model to produce unaided?',
						'options'  => array(
							'A summary of a document you pasted',
							'A specific citation with a page number',
							'A rewrite of your draft in a warmer tone',
							'A table built from notes you supplied',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Unless you say otherwise, a model will usually fill a gap rather than admit it does not know.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Replace vague words like "short" with a specific ___ so the length is under your control.',
						'answer_text' => 'number',
						'accept'      => array( 'word count', 'limit' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'The strongest reason to save a prompt that worked is that:',
						'options'  => array(
							'Prompts expire if unused',
							'Most professional work repeats, so a saved prompt pays back many times',
							'Models perform better on older prompts',
							'It reduces the context window',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
