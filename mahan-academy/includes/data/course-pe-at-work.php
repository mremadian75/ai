<?php
/**
 * Seed course: "Prompt Engineering at Work" (Prompt Engineering bundle).
 *
 * Follows the canonical schema contract defined in course-pe-foundations.php.
 * The seeder ({@see Mahan_Seed}) loads this file and expects exactly that shape:
 * units -> lessons -> exercises, plus an optional unit quiz.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'pe-at-work',
	'title'       => 'Prompt Engineering at Work',
	'slug'        => 'pe-at-work',
	'subtitle'    => 'Turn prompting technique into everyday workflows: reusable templates, document summarising, and debugging prompts that misbehave.',
	'excerpt'     => 'Move from one-off prompts to repeatable systems — templates you reuse, reliable ways to summarise long documents, a method for fixing prompts that go wrong, and a shared library your whole team can lean on.',
	'description' => '<p>You already know how to write a decent prompt. This course is about what comes next at work: turning that skill into <strong>workflows</strong> you and your team can rely on every day, instead of reinventing a good prompt every time you need one.</p>'
		. '<p>You will build fill-in-the-blanks templates, learn dependable patterns for summarising and rewriting long documents, follow a calm diagnostic loop for fixing prompts that misbehave, and set up a shared prompt library with light rules everyone can actually follow. Practical, tool-agnostic, and ready to use on Monday morning.</p>',
	'category'    => 'Prompt Engineering',
	'track'       => 'prompt-engineering',
	'level_rank'  => 3,
	'level'       => 'advanced',
	'est_hours'   => 3,
	'featured'    => false,
	'certificate' => true,
	'order'       => 3,
	'topics'      => array( 'Prompt templates', 'Summarising', 'Debugging prompts', 'Team prompt libraries' ),
	'outcomes'    => array(
		'Build reusable prompt templates with placeholders for the parts that change',
		'Summarise, extract, and rewrite long documents reliably',
		'Chunk a document that is too long to fit in one prompt',
		'Debug a misbehaving prompt by changing one thing at a time',
		'Set up a shared prompt library your team can trust and maintain',
	),
	'references'  => array(
		array(
			'title'  => 'Prompt engineering guide',
			'source' => 'OpenAI — API documentation',
			'url'    => 'https://platform.openai.com/docs/guides/prompt-engineering',
		),
		array(
			'title'  => 'Use prompt templates and variables',
			'source' => 'Anthropic — Claude documentation',
			'url'    => 'https://docs.anthropic.com/en/docs/build-with-claude/prompt-engineering/overview',
		),
		array(
			'title'  => 'Generative AI and the future of work',
			'source' => 'McKinsey Global Institute',
			'url'    => 'https://www.mckinsey.com/mgi/our-research',
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
			'title'   => 'Everyday workflows',
			'lessons' => array(

				array(
					'title'   => 'Reusable prompt templates',
					'type'    => 'practice',
					'est_min' => 12,
					'xp'      => 25,
					'topics'  => array( 'Prompt templates' ),
					'content' => '<h2>A template is a good prompt with the changeable parts pulled out</h2>'
						. '<p>You have probably written a prompt that worked beautifully, then had to rebuild it from memory a week later. A <strong>template</strong> fixes that. It is a prompt you wrote once, with the parts that change from job to job replaced by clearly marked <em>placeholders</em>.</p>'
						. '<h3>Spot what changes and what stays</h3>'
						. '<p>Take any prompt you use more than once and ask: which words would I swap next time? Usually it is the <strong>audience</strong>, the <strong>tone</strong>, and the <strong>source text</strong>. Everything else — the role, the task, the format rules — stays put. Those stable parts are what make the output consistently good; the placeholders are what make it reusable.</p>'
						. '<h3>A weekly-update template</h3>'
						. '<blockquote>You are a team lead writing a weekly update for [AUDIENCE]. Tone: [TONE]. Using the notes below, write a 120-word update with three sections: Wins, In progress, Blockers. Notes: [PASTE RAW NOTES]</blockquote>'
						. '<p>To reuse it, you fill three blanks. For your manager you might set AUDIENCE to "my manager" and TONE to "concise and factual". For the wider company you swap in "the whole company" and "warm, plain English". Same skeleton, two very different emails, zero rewriting of the hard parts.</p>'
						. '<h3>Why this saves more than time</h3>'
						. '<p>The obvious win is speed — you stop reinventing the prompt. The bigger win is <strong>consistency</strong>. Because the quality-carrying instructions (length, structure, honesty rules) never change, every update comes out in the same reliable shape. A teammate can use your template and get your quality without your experience.</p>'
						. '<h3>Build one that is easy to fill</h3>'
						. '<p>Keep placeholders obvious. Square brackets and CAPS — [AUDIENCE], [SOURCE TEXT] — are hard to miss and easy to find-and-replace. Add a one-line note above the template reminding future-you what each blank expects.</p>'
						. '<h3>Recap</h3>'
						. '<p>Freeze the parts that make a prompt good; mark the parts that change as placeholders. That is the whole idea, and it turns a one-off win into a tool you and your team can reuse.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'In a reusable prompt template, which part should become a placeholder rather than staying fixed?',
							'options'  => array(
								'The output format rules',
								'The honesty and length instructions',
								'The source text that changes each time',
								'The role you assign the model',
							),
							'answer'   => 2,
							'hint'     => 'Placeholders are for what changes from job to job.',
							'feedback_correct'   => 'Right — the source text is exactly the kind of thing you swap out each time, so it becomes a placeholder.',
							'feedback_incorrect' => 'Not quite. The stable, quality-carrying parts stay fixed; the source text is what changes, so it is the placeholder.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'In a template, the parts that change from job to job — like the audience or source text — are marked as ___.',
							'answer_text' => 'placeholders',
							'accept'      => array( 'placeholder', 'blanks', 'blank' ),
							'hint'        => 'Think square brackets in CAPS.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'The main benefit of a template is that it lets you rewrite the quality-carrying instructions each time you use it.',
							'answer'   => 1,
							'hint'     => 'A template exists so you do not have to rewrite those parts.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Pick a task you do at least weekly and write it as a template: keep the role, task, and format fixed, and mark the audience, tone, and source text as bracketed placeholders.',
							'rubric' => 'A strong answer keeps stable instructions (role, task, format) fixed and clearly marks the changing parts (audience, tone, source) as obvious placeholders such as [AUDIENCE].',
							'hint'   => 'Ask yourself which words you would swap the next time you run it.',
						),
					),
				),

				array(
					'title'   => 'Summarising and rewriting long documents',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 20,
					'topics'  => array( 'Summarising' ),
					'content' => '<h2>Three jobs: summarise, extract, rewrite</h2>'
						. '<p>Most "do something with this document" requests are really one of three tasks, and naming the right one gets you a far better result.</p>'
						. '<ul>'
						. '<li><strong>Summarise</strong> — shrink the whole thing while keeping the gist. "Give me the key points of this report."</li>'
						. '<li><strong>Extract</strong> — pull out only specific items and ignore the rest. "List every deadline and who owns it."</li>'
						. '<li><strong>Rewrite</strong> — keep the content but change its form. "Rewrite this policy in plain English for new hires."</li>'
						. '</ul>'
						. '<p>Summarise compresses; extract filters; rewrite transforms. Asking for a summary when you actually needed the action items buried on page nine wastes a round-trip.</p>'
						. '<h3>The executive-summary-plus-actions pattern</h3>'
						. '<p>A reliable workhorse for long documents:</p>'
						. '<blockquote>Give me a three-sentence executive summary of the text below, then a bulleted list of concrete action items with an owner for each. Text: [PASTE]</blockquote>'
						. '<p>You get the big picture and the "so what do we do now" in a single pass.</p>'
						. '<h3>When the document is too long for one prompt</h3>'
						. '<p>If a report will not fit in the context window, <strong>chunk</strong> it. Split it into sections, summarise each section on its own, then paste those summaries together and ask for a summary of the summaries. This "summarise, then summarise again" approach keeps every part in view at some stage instead of overflowing the window and quietly losing the beginning.</p>'
						. '<h3>Keep it honest: grounding</h3>'
						. '<p>Summarising is where invented facts sneak in — the model fills a gap with something plausible. <strong>Ground</strong> the request: "Use only the text below. If it does not state something, do not add it. If a figure is not given, write \'not specified\'." Grounding tells the model to stay inside the source rather than draw on its general training.</p>'
						. '<h3>Recap</h3>'
						. '<p>Pick the right verb — summarise, extract, or rewrite — add an executive-summary-plus-actions structure when it helps, chunk anything too long for the window, and ground the request so the summary reflects the document and not the model\'s imagination.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'You need only the specific deadlines out of a 40-page contract and nothing else. Which task should you ask for?',
							'options'  => array(
								'Summarise',
								'Extract',
								'Rewrite',
								'Translate',
							),
							'answer'   => 1,
							'hint'     => 'You want to pull out specific items and ignore the rest.',
							'feedback_correct'   => 'Exactly — pulling out only specific items and ignoring the rest is extraction.',
							'feedback_incorrect' => 'Not quite. Summarising compresses the whole thing; you want to filter for specific items, which is extraction.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'To summarise a document that is too long for the context window, you can summarise each section and then summarise those summaries together.',
							'answer'   => 0,
							'hint'     => 'This is the "summarise, then summarise again" chunking approach.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Telling the model to use only the pasted text and not add outside facts is called ___ the request.',
							'answer_text' => 'grounding',
							'accept'      => array( 'ground', 'grounded' ),
							'hint'        => 'It keeps the model inside the source material.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'In your own words, explain the difference between summarising and extracting, and give one example of when you would choose extract.',
							'rubric'   => 'A good answer explains that summarising compresses the whole text while extracting pulls out only specific items, and gives a concrete example such as listing dates, owners, or figures from a long document.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Everyday workflows — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'What is the purpose of a placeholder like [AUDIENCE] in a prompt template?',
						'options'  => array(
							'It marks the part you swap out to reuse the template',
							'It tells the model to ignore that line',
							'It sets how creative the model should be',
							'It hides the prompt from other users',
						),
						'answer'   => 0,
					),
					array(
						'type'     => 'true_false',
						'question' => 'When a document is too long for one prompt, a good approach is to summarise it in chunks and then combine those summaries.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Pulling out only specific items such as dates or owners while ignoring the rest is called ___.',
						'answer_text' => 'extracting',
						'accept'      => array( 'extract', 'extraction' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'You want the three main themes of a long article, not any specific detail. Which task fits best?',
						'options'  => array(
							'Extract',
							'Summarise',
							'Rewrite',
							'Translate',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Iterate and scale',
			'lessons' => array(

				array(
					'title'   => 'Debugging a prompt that misbehaves',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Debugging prompts' ),
					'content' => '<h2>Debug like an engineer: change one thing at a time</h2>'
						. '<p>When a prompt gives you the wrong thing, the temptation is to rewrite it wholesale. Resist that. The fast route to a working prompt is a tight <strong>diagnostic loop</strong>, and its golden rule is: change only <strong>one thing</strong> between tries.</p>'
						. '<h3>Read what the model actually did</h3>'
						. '<p>Start by treating the output as evidence, not a failure. Did it answer a different question? Ignore your format? Invent a fact? The specific way it went wrong points straight at what is missing. A wall of prose when you wanted bullets is a <em>format</em> problem — not a reason to rewrite the whole task.</p>'
						. '<h3>The single-change rule</h3>'
						. '<p>Adjust one variable, run it again, and compare. If you change the role, the format, and the wording all at once and it improves, you have no idea which edit helped — and if it gets worse, you cannot back it out. One change per iteration turns guessing into a controlled experiment.</p>'
						. '<h3>Common causes and their fixes</h3>'
						. '<ul>'
						. '<li><strong>Ambiguous task</strong> — the verb is fuzzy. Replace "look at this" with "list the three biggest risks".</li>'
						. '<li><strong>Missing format</strong> — you never said what shape you wanted. Add "as a five-row table".</li>'
						. '<li><strong>No context</strong> — the model lacks a fact it needed. Paste the source or state the constraint.</li>'
						. '</ul>'
						. '<p>The two strongest single moves are usually <strong>adding the one missing constraint</strong> and <strong>showing an example</strong> of a good answer. A single example often does in one shot what three sentences of description cannot.</p>'
						. '<h3>When to start fresh</h3>'
						. '<p>If a chat has wandered through several failed attempts, the model may be anchored on its earlier mistakes cluttering the context. At that point, stop patching. Open a clean chat, fold what you learned into one well-formed prompt, and start again.</p>'
						. '<h3>Recap</h3>'
						. '<p>Read the actual output, change one thing at a time, add the missing constraint or an example, and know when a fresh start beats yet another patch.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Your prompt returns a paragraph but you wanted a table, so you change the format instruction, the role, and three word choices at once — and it works. What is the problem with that approach?',
							'options'  => array(
								'Nothing — changing more at once is always faster',
								'You cannot tell which change actually fixed it',
								'The model will crash from too many edits',
								'Tables are never a good output format',
							),
							'answer'   => 1,
							'hint'     => 'Think about what you learn from the experiment.',
							'feedback_correct'   => 'Exactly — with several edits at once you lose the ability to know what helped or to undo what hurt.',
							'feedback_incorrect' => 'Not quite. The issue is that changing many things at once hides which edit caused the result.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'When debugging a prompt, you should change several things at once to save time.',
							'answer'   => 1,
							'hint'     => 'The golden rule is one change per try.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'The debugging rule is to change only ___ thing between tries.',
							'answer_text' => 'one',
							'accept'      => array( 'a single', 'single' ),
							'hint'        => 'It is the whole point of the diagnostic loop.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'A prompt keeps ignoring your requested format. Describe the single change you would make first and why.',
							'rubric'   => 'A good answer identifies the missing or unclear format instruction as the one thing to change first, makes exactly one change, and explains that this keeps the fix diagnosable.',
						),
					),
				),

				array(
					'title'   => 'Building a prompt library for your team',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 20,
					'topics'  => array( 'Team prompt libraries' ),
					'content' => '<h2>From personal tricks to a shared asset</h2>'
						. '<p>Once you and your teammates each have a handful of prompts that reliably work, the next step is obvious: stop keeping them in scattered notes and build a shared <strong>prompt library</strong>. It turns individual know-how into something the whole team can reuse.</p>'
						. '<h3>Save, name, and organise</h3>'
						. '<p>A prompt is only reusable if you can find it again. Give each one a clear, descriptive name — "Weekly update — team", "Contract deadline extractor" — not "prompt3". Group them by task or department so a colleague can browse to the right one instead of asking around.</p>'
						. '<h3>Add notes on when to use each</h3>'
						. '<p>The prompt itself is half the value; the other half is knowing <em>when</em> to reach for it. Add a short note to each entry: what it is for, what to paste in, and any gotchas. "Use for external emails only — tone is deliberately formal" saves the next person a failed attempt.</p>'
						. '<h3>Version as models change</h3>'
						. '<p>Prompts are not write-once. Models get updated, and a prompt that needed heavy hand-holding last year may work better trimmed down today. Treat entries like living documents: keep a <strong>version</strong> or a "last checked" date, and revisit the important ones when the tool underneath you changes.</p>'
						. '<h3>Light governance</h3>'
						. '<p>A shared library also needs a few guardrails — kept light so people actually follow them. The main one is <strong>what data is allowed</strong>: note which prompts are safe for customer data, which must use anonymised examples, and which are fine for public information only. A one-line rule at the top of the library beats a policy nobody reads.</p>'
						. '<h3>Recap</h3>'
						. '<p>Name and organise prompts so they are findable, note when to use each, version them as models evolve, and add light rules about what data is allowed. A shared library means the whole team gets everyone\'s best prompt, not just their own.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What is a key benefit of a shared team prompt library over everyone keeping private notes?',
							'options'  => array(
								'It makes prompts run faster',
								'It removes the need to write prompts at all',
								'It lets the model train on your company data',
								'The whole team can reuse everyone\'s best prompts',
							),
							'answer'   => 3,
							'hint'     => 'Think about who gets to benefit from one person\'s good prompt.',
							'feedback_correct'   => 'Right — a shared library spreads each person\'s best prompts across the whole team.',
							'feedback_incorrect' => 'Not quite. The point is reuse: a library lets everyone benefit from the prompts that already work.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Prompts should be treated as write-once and never updated as models change.',
							'answer'   => 1,
							'hint'     => 'Models get updated, so entries are living documents.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Keeping a "last checked" date or version number on each saved prompt is a form of ___.',
							'answer_text' => 'versioning',
							'accept'      => array( 'version', 'version control' ),
							'hint'        => 'It tracks how an entry changes over time.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Draft a one-line data rule you would put at the top of your team\'s prompt library.',
							'rubric'   => 'A good answer states a clear, short guardrail about what data is allowed — for example which prompts may use customer data versus anonymised or public information only.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Iterate and scale — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'What is the core rule of the prompt debugging loop?',
						'options'  => array(
							'Rewrite the whole prompt from scratch each time',
							'Change one thing at a time so you know what helped',
							'Always start a brand new chat immediately',
							'Add as many constraints as possible at once',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'A shared prompt library lets teammates reuse prompts that already work instead of starting from scratch.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'When debugging, change only ___ variable between tries.',
						'answer_text' => 'one',
						'accept'      => array( 'a single', 'single' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Why change just one variable at a time when fixing a prompt?',
						'options'  => array(
							'It is required by the software',
							'To use fewer tokens overall',
							'So you can tell which change caused the result',
							'Because models only read one line at a time',
						),
						'answer'   => 2,
					),
				),
			),
		),

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'Prompts that run without you',
			'lessons' => array(

				array(
					'title'   => 'Writing for a program, not a person',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 30,
					'topics'  => array( 'Prompt templates', 'Debugging prompts' ),
					'content' => '<h2>Nobody is watching when this one runs</h2>'
						. '<p>A prompt you run in a chat window has a safety net: you. You see a strange answer and retry. The moment a prompt runs inside a script, a scheduled job or a product feature, that net is gone — the output goes straight to a database, a customer, or another system.</p>'
						. '<p>Prompts for automation are written to a different standard, and it is not about wording.</p>'
						. '<h3>1. The output must be machine-checkable</h3>'
						. '<p>Prose cannot be validated. Structure can. Specify an exact schema, and have the calling code check it before doing anything with it — required fields present, values from the allowed set, types correct. An answer that fails validation is a failure you can handle; an answer that is merely wrong is one you will discover from a customer.</p>'
						. '<h3>2. There must be a defined "I can\'t"</h3>'
						. '<p>Give every automated prompt a legitimate escape hatch: <code>{"status": "needs_review", "reason": "..."}</code>. Without one, an input the prompt was never designed for still produces a confident, well-formed, wrong record — and well-formed wrong is the hardest kind to catch downstream.</p>'
						. '<h3>3. Untrusted input is data, always</h3>'
						. '<p>In automation, the variable part is usually something a stranger wrote: an email, a review, a form field. Fence it with delimiters, state explicitly that its contents are data and never instructions, and put your real instruction after it. Assume someone will eventually paste "ignore all previous instructions" into your form, because they will.</p>'
						. '<h3>4. Pin what you can</h3>'
						. '<p>Pin the model version — a silent upgrade can change output shape overnight. Set temperature to zero for extraction and classification, where you want the same input to give the same answer. Save nothing to your prompt that you would not want reproduced identically ten thousand times.</p>'
						. '<blockquote>In a chat, a bad answer costs you thirty seconds. In a pipeline, a bad answer becomes a row that everything downstream believes.</blockquote>'
						. '<h3>5. Log the input, the prompt version, and the output</h3>'
						. '<p>When something goes wrong three weeks later, the only way to understand it is to replay it. Log enough to reconstruct the call exactly — subject to whatever your privacy rules allow you to keep.</p>'
						. '<h3>Recap</h3>'
						. '<p>Validate the shape, provide an honest way out, treat all supplied content as data, pin what you can, and log enough to replay.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why does an automated prompt need an explicit "needs_review" style output?',
							'options'  => array(
								'It reduces token cost',
								'Without one, an unexpected input still yields a confident, well-formed, wrong record',
								'Models refuse to answer otherwise',
								'It is required by most APIs',
							),
							'answer'   => 1,
							'hint'     => 'What happens to an input the prompt was never designed for?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'For extraction and classification in a pipeline, set temperature to ___ so the same input gives the same answer.',
							'answer_text' => '0',
							'accept'      => array( 'zero', '0.0' ),
							'hint'        => 'You want determinism, not creativity.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Pinning a specific model version matters little, because newer versions are strictly better.',
							'answer'   => 1,
							'hint'     => 'Better on average is not the same as identical in shape.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write the full prompt for an automated step that reads an inbound customer email and outputs a JSON record with intent, urgency (low/medium/high), and a one-line summary. Include the schema, the escape hatch, and the delimiting of untrusted content.',
							'rubric' => 'A strong answer gives an exact schema with an enumerated urgency set, forbids prose around the JSON, fences the email in clear delimiters while stating its contents are data and never instructions, provides an explicit needs_review path with a reason, and places the real instruction after the untrusted block.',
							'hint'   => 'Assume the email contains an attempt to hijack your instructions.',
						),
					),
				),

				array(
					'title'   => 'Handling failure on purpose',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Debugging prompts', 'Prompt templates' ),
					'content' => '<h2>The call will fail. Decide now what happens then</h2>'
						. '<p>Model calls fail in ways ordinary functions do not: they time out, they get rate-limited, they return the right shape with the wrong content, and occasionally they return something no schema anticipated. A pipeline that assumes success is a pipeline that will corrupt data quietly.</p>'
						. '<h3>Four failure classes, four responses</h3>'
						. '<table><thead><tr><th>Failure</th><th>How you detect it</th><th>What to do</th></tr></thead><tbody>'
						. '<tr><td>Transport — timeout, 5xx, rate limit</td><td>The HTTP layer</td><td>Retry with exponential backoff, then give up loudly</td></tr>'
						. '<tr><td>Malformed — not parseable</td><td>Your parser</td><td>Retry once with the error appended; then route to review</td></tr>'
						. '<tr><td>Invalid — parses, breaks the schema</td><td>Your validator</td><td>Route to review. Do not retry blindly; the prompt may be wrong</td></tr>'
						. '<tr><td>Plausible but wrong</td><td>Nothing automatic</td><td>Sampling, spot checks, downstream signals</td></tr>'
						. '</tbody></table>'
						. '<p>The fourth row is the honest one. No amount of prompt engineering detects a confident wrong answer from inside the same call. You catch it by checking a sample of real output regularly, and by watching what happens downstream — refund rates, reopened tickets, corrections.</p>'
						. '<h3>Retry carefully</h3>'
						. '<p>Retrying the identical prompt after a malformed response often works, because generation is not deterministic. Retrying more than once or twice usually does not — at that point the prompt, not the call, is the problem. And never retry a request that already had a side effect.</p>'
						. '<h3>Fail into a queue, not into silence</h3>'
						. '<p>Every path that cannot be handled automatically should end somewhere a human will actually look: a review queue, a flagged row, an alert. "Log and continue" means nobody ever finds out.</p>'
						. '<blockquote>Design the unhappy path first. The happy path is the easy half, and it is not the half that damages trust.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Separate transport, malformed, invalid and plausible-but-wrong. Retry only where retrying makes sense. Route everything else to a queue a person reads.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which failure class cannot be detected from inside the same model call?',
							'options'  => array(
								'A request timeout',
								'Unparseable output',
								'Output that breaks the schema',
								'Output that is well-formed and confidently wrong',
							),
							'answer'   => 3,
							'hint'     => 'Which one looks exactly like success?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'If a response fails schema validation, the right move is to keep retrying the same prompt until it passes.',
							'answer'   => 1,
							'hint'     => 'What does repeated schema failure suggest about the prompt itself?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Anything that cannot be handled automatically should end in a review ___ that a person actually reads.',
							'answer_text' => 'queue',
							'accept'      => array( 'list' ),
							'hint'        => 'Not a log file nobody opens.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Your extraction step succeeds on 98% of emails. Describe how you would find out whether the 98% are actually correct.',
							'rubric'   => 'A strong answer recognises that success rate measures parseability, not correctness, and proposes measuring correctness directly: regularly sampling real outputs against human judgement, and watching downstream signals such as corrections, reopened tickets or complaints that would reveal errors the pipeline accepted.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Prompts that run without you — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'The single most important difference between a chat prompt and an automated one is that:',
						'options'  => array(
							'Automated prompts must be shorter',
							'No human sees the output before something acts on it',
							'Automated prompts cannot use examples',
							'Chat prompts cost more',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Content pasted in from a customer should be fenced and declared to be data, never instructions.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Log the input, the output, and the prompt ___ so a failure weeks later can be replayed exactly.',
						'answer_text' => 'version',
						'accept'      => array( 'id' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'A response arrives unparseable. The most sensible first step is to:',
						'options'  => array(
							'Route straight to human review',
							'Retry once, appending the parse error to the prompt',
							'Retry indefinitely',
							'Accept it and store the raw text',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Measuring, versioning, and governing prompts',
			'lessons' => array(

				array(
					'title'   => 'A test set beats an opinion',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 30,
					'topics'  => array( 'Debugging prompts', 'Team prompt libraries' ),
					'content' => '<h2>"That feels better" is not a result</h2>'
						. '<p>Prompt work goes wrong in a specific, recognisable way: someone tweaks the wording, tries two inputs, decides it improved, and ships. Two weeks later the outputs are worse on a case nobody thought to try, and there is no way to tell which of the eleven edits since caused it.</p>'
						. '<p>The fix is unglamorous and takes an afternoon: <strong>a small evaluation set</strong>.</p>'
						. '<h3>Building one</h3>'
						. '<ol>'
						. '<li><strong>Collect 20–50 real inputs.</strong> Real, not invented — invented inputs are always tidier than reality.</li>'
						. '<li><strong>Include the hard ones deliberately.</strong> The empty input, the one in another language, the 40-page one, the one that broke it last month. A set of easy cases proves nothing.</li>'
						. '<li><strong>Write down the acceptable output for each.</strong> Not the perfect one — the bar you would sign off.</li>'
						. '<li><strong>Decide what "pass" means per case.</strong> Exact match for structured fields; a short rubric for prose.</li>'
						. '</ol>'
						. '<h3>Using it</h3>'
						. '<p>Run the whole set before and after every prompt change. You are looking for two numbers: how many passed, and — more importantly — <em>which ones changed</em>. A change that fixes three cases and breaks two is not an improvement, and without the set it would have looked like one.</p>'
						. '<blockquote>Twenty real cases with agreed answers will tell you more than any amount of discussion about wording.</blockquote>'
						. '<h3>Grading prose without drowning in it</h3>'
						. '<p>For open-ended output, write a three-line rubric per case and grade against it. A model can apply that rubric for a first pass, which is fine for catching regressions — but a human sets the rubric and reviews disagreements. A model marking its own family of outputs against a standard it also wrote is not evidence.</p>'
						. '<h3>Keep the set alive</h3>'
						. '<p>Every real failure that reaches production earns a permanent place in the set. That single habit is what stops a system regressing into the same mistake twice, and it is the difference between a prompt that gets better and one that just gets edited.</p>'
						. '<h3>Recap</h3>'
						. '<p>Twenty to fifty real inputs, hard cases included, with agreed acceptable outputs. Run it on every change, watch which cases moved, and add every production failure to it forever.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A prompt change makes three previously failing cases pass and two previously passing cases fail. This is:',
							'options'  => array(
								'A clear improvement — net positive',
								'Not obviously an improvement; the two regressions need examining',
								'Irrelevant, since the total is higher',
								'A sign the test set is too large',
							),
							'answer'   => 1,
							'hint'     => 'Which cases broke, and how much do they matter?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'An evaluation set built from invented example inputs is just as useful as one built from real ones.',
							'answer'   => 1,
							'hint'     => 'How messy is real input compared with what you would make up?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Every failure that reaches production should be added permanently to the ___ set, so the same mistake cannot return unnoticed.',
							'answer_text' => 'test',
							'accept'      => array( 'eval', 'evaluation' ),
							'hint'        => 'The habit that stops regressions repeating.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Name three inputs you would deliberately put in the evaluation set for a prompt that summarises customer support threads, and say what each one is testing.',
							'rubric'   => 'A strong answer chooses genuinely adversarial cases — for example an empty or one-line thread, a thread in another language, a very long thread that exceeds the window, one containing an instruction-like sentence, or one where the customer is angry — and states the specific failure mode each is designed to expose.',
						),
					),
				),

				array(
					'title'   => 'Prompts as team assets',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 30,
					'topics'  => array( 'Team prompt libraries', 'Prompt templates' ),
					'content' => '<h2>The prompt in someone\'s notes app is a liability</h2>'
						. '<p>Once prompts affect real work, they stop being personal notes and start being infrastructure. The failure is familiar to anyone who has watched a spreadsheet become load-bearing: it works, one person understands it, and then that person is on holiday.</p>'
						. '<h3>Treat a production prompt like code</h3>'
						. '<ul>'
						. '<li><strong>Version it.</strong> In the repository, in review, with a history. "Who changed this and why" must be answerable.</li>'
						. '<li><strong>Give it an owner.</strong> A named person or team accountable for its behaviour — not "the AI channel".</li>'
						. '<li><strong>Document its contract.</strong> What goes in, what comes out, what it must never do, what it costs per call.</li>'
						. '<li><strong>Attach its evaluation set.</strong> A prompt without one cannot be safely changed by anyone but its author.</li>'
						. '</ul>'
						. '<h3>What a library entry should contain</h3>'
						. '<p>Purpose in one sentence · the prompt itself with placeholders marked · a worked example of input and expected output · known limitations and failure modes · owner and last-reviewed date · the model and settings it was validated against.</p>'
						. '<p>That last item saves the most grief. A prompt tuned on one model at temperature 0 may behave quite differently elsewhere, and without the note nobody knows what it was ever tested on.</p>'
						. '<h3>Review on a schedule, not on incident</h3>'
						. '<p>Models change under you. A quarterly pass — re-run every library prompt against its evaluation set, update the last-reviewed date — turns a slow, invisible drift into a scheduled half-day. The alternative is finding out from a customer.</p>'
						. '<blockquote>If a prompt matters enough to run in production, it matters enough to have an owner, a version, and a test set. If it has none of those, it is not a tool — it is a rumour that happens to work.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Version, own, document, and evaluate. Record the model and settings each prompt was validated against, and re-run everything on a schedule instead of waiting for an incident.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which library entry field most often prevents confusion when a prompt starts behaving differently?',
							'options'  => array(
								'The author\'s job title',
								'The model and settings it was validated against',
								'The date it was first written',
								'The number of times it has run',
							),
							'answer'   => 1,
							'hint'     => 'What changes underneath a prompt without anyone editing it?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A production prompt should have a named ___ who is accountable for its behaviour.',
							'answer_text' => 'owner',
							'accept'      => array( 'maintainer' ),
							'hint'        => 'Not a channel, a person or team.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Waiting for something to break is an acceptable review schedule for prompts in production.',
							'answer'   => 1,
							'hint'     => 'Who discovers the drift first in that model?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write a complete library entry for one prompt you or your team actually rely on: purpose, the prompt with placeholders, a worked input/output example, known failure modes, owner, and the model and settings it was validated against.',
							'rubric' => 'A strong answer is a genuine handover document — a colleague could adopt the prompt without asking questions. It must include marked placeholders, at least one honest limitation rather than only strengths, and the specific model and settings, not just "an AI".',
							'hint'   => 'Write it for a colleague who joins next month.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'Which prompts in your organisation are currently load-bearing but undocumented — and what would break first if their author left tomorrow?',
							'rubric'   => 'A thoughtful answer identifies a specific dependency and traces the concrete consequence, rather than stating generally that documentation is good.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Measuring and governing — quiz',
				'passing'   => 70,
				'xp'        => 40,
				'questions' => array(
					array(
						'type'        => 'fill_blank',
						'question'    => 'Before and after every prompt change, run the whole evaluation set and check which cases ___.',
						'answer_text' => 'changed',
						'accept'      => array( 'moved', 'regressed' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'A model grading its own outputs against a rubric it also wrote counts as independent evidence of quality.',
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which is the strongest sign a prompt has become team infrastructure rather than a personal note?',
						'options'  => array(
							'It is longer than ten lines',
							'Real work depends on it and only one person understands it',
							'It uses few-shot examples',
							'It returns JSON',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Why review library prompts on a schedule rather than when something breaks?',
						'options'  => array(
							'To keep the document count up',
							'Because models change underneath prompts, and drift is otherwise found by customers',
							'Because prompts expire',
							'To reduce token cost',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
