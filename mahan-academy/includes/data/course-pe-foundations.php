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
	),
);
