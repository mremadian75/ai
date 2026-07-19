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
	'level'       => 'intermediate',
	'est_hours'   => 4,
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
	),
);
