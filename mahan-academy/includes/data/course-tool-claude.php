<?php
/**
 * Seed course: "Working with Claude" (AI Tools bundle).
 *
 * Follows the canonical schema contract defined in course-pe-foundations.php.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'tool-claude',
	'title'       => 'Working with Claude',
	'slug'        => 'tool-claude',
	'subtitle'    => 'Use Claude for careful writing, analysis, and working through long documents.',
	'excerpt'     => 'Get practical results from Claude — what it is good at, how to feed it long documents, how to ask for structured output, and how to use it responsibly.',
	'description' => '<p><strong>Claude</strong> is an AI assistant that is especially strong at careful writing, measured reasoning, and working with long pieces of text. This short course shows you how to point that strength at real work.</p>'
		. '<p>You will learn which jobs Claude handles well, how to paste in a report and ask grounded questions about it, how to get answers in the exact shape you need, and the handful of habits that keep AI-assisted work trustworthy. No coding required — just clear thinking and a few reliable moves.</p>',
	'category'    => 'AI Tools',
	'level'       => 'beginner',
	'est_hours'   => 1,
	'featured'    => false,
	'certificate' => true,
	'order'       => 2,
	'topics'      => array( 'Claude basics', 'Long documents', 'Structured output', 'Responsible use' ),
	'outcomes'    => array(
		'Recognise the tasks Claude is genuinely good at',
		'Paste long documents and ask grounded questions about them',
		'Ask Claude to admit when an answer is not in the source',
		'Request output in a specific shape — bullets, a table, or JSON',
		'Apply responsible-use habits that keep AI work trustworthy',
	),
	'references'  => array(
		array(
			'title'  => 'Claude documentation',
			'source' => 'Anthropic',
			'url'    => 'https://docs.anthropic.com/',
		),
		array(
			'title'  => 'Prompt engineering overview',
			'source' => 'Anthropic — Claude documentation',
			'url'    => 'https://docs.anthropic.com/en/docs/build-with-claude/prompt-engineering/overview',
		),
		array(
			'title'  => 'Constitutional AI: Harmlessness from AI Feedback',
			'source' => 'Bai et al., 2022 — arXiv:2212.08073',
			'url'    => 'https://arxiv.org/abs/2212.08073',
		),
		array(
			'title'  => 'Anthropic\'s Responsible Scaling Policy',
			'source' => 'Anthropic',
			'url'    => 'https://www.anthropic.com/news/anthropics-responsible-scaling-policy',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'Meet Claude',
			'lessons' => array(

				array(
					'title'   => 'What Claude is good at',
					'type'    => 'reading',
					'est_min' => 8,
					'xp'      => 20,
					'topics'  => array( 'Claude basics' ),
					'content' => '<h2>A capable, measured writing and thinking partner</h2>'
						. '<p><strong>Claude</strong> is an AI assistant you talk to in plain language. You describe what you need, it writes back. Its particular strengths are careful <strong>writing</strong>, <strong>reasoning</strong> through a problem step by step, and handling <strong>long inputs</strong> without losing the thread. Its default style is helpful and measured — it tends to explain its thinking rather than fire off a one-liner.</p>'
						. '<h3>The jobs it handles well</h3>'
						. '<p>Think of Claude as a sharp, patient colleague who is a strong writer. It is a good fit when the task is language-shaped:</p>'
						. '<ul>'
						. '<li><strong>Drafting</strong> — a first version of an email, a proposal, a job description, release notes.</li>'
						. '<li><strong>Editing</strong> — tightening, restructuring, or changing the tone of text you already have.</li>'
						. '<li><strong>Summarising</strong> — turning a long document or thread into the few points that matter.</li>'
						. '<li><strong>Thinking through a problem</strong> — weighing options, listing trade-offs, poking holes in a plan.</li>'
						. '</ul>'
						. '<blockquote>Claude does not replace your judgement. It gives you a strong first draft and a second pair of eyes, fast.</blockquote>'
						. '<h3>Where it is weaker</h3>'
						. '<p>Claude is a language model, not a database or a calculator. It can state something wrong while sounding confident, and it only knows what it was trained on plus what you give it in the conversation. For anything factual or high-stakes, treat its output as a draft to check, not a final answer.</p>'
						. '<h3>Start with a real task</h3>'
						. '<p>The fastest way to learn what Claude is good at is to hand it something from your own week. As someone in {{role}}, pick one writing or thinking task that moves you toward {{primary_goal}} and give it a genuine try — a draft you dread, a document you need summarised, a decision you are chewing on.</p>'
						. '<h3>Recap</h3>'
						. '<p>Claude shines at drafting, editing, summarising, and reasoning. Lean on it for language work, keep it away from unchecked facts and math, and always stay the one making the call.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which task is the best fit for Claude?',
							'options'  => array(
								'Turning a long meeting transcript into a short summary',
								'Storing your customer records as a permanent database',
								'Guaranteeing exact tax figures with no checking',
								'Measuring the temperature in your office',
							),
							'answer'   => 0,
							'hint'     => 'Think language-shaped work, not a database or a sensor.',
							'feedback_correct'   => 'Right — summarising long text is exactly the kind of language work Claude excels at.',
							'feedback_incorrect' => 'Not quite. Claude is a writing and reasoning partner, not a database, calculator, or measuring device.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Claude can state something incorrect while still sounding confident, so factual output is worth checking.',
							'answer'   => 0,
							'hint'     => 'It is a language model, not a fact database.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Claude is especially strong at writing, reasoning, and working with ___ inputs like reports and threads.',
							'answer_text' => 'long',
							'accept'      => array( 'lengthy', 'large' ),
							'hint'        => 'The opposite of short.',
						),
						array(
							'type'   => 'short_answer',
							'question' => 'Name one task from your own work that is a good fit for Claude, and say why it suits the tool.',
							'rubric'   => 'A good answer names a concrete language-shaped task (drafting, editing, summarising, or reasoning) and explains that it plays to Claude\'s strengths rather than requiring a database, precise calculation, or a live fact lookup.',
						),
					),
				),

				array(
					'title'   => 'Feeding Claude documents & context',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'Long documents' ),
					'content' => '<h2>Give it the source, then ask about the source</h2>'
						. '<p>Claude is at its most useful when it works from material you provide. Instead of asking it to recall facts, you <strong>paste or attach</strong> the text — a report, a contract, a long email thread — and then ask questions about <em>that</em>. Because Claude comfortably reads long inputs, this is one of its standout uses.</p>'
						. '<p>Pasting works for shorter passages; for whole files, attaching them keeps the conversation tidy and lets Claude read the entire document at once. Either way, the goal is the same: get the real source in front of it before you ask a single question.</p>'
						. '<h3>Ground your question in the text</h3>'
						. '<p>The key move is to tell Claude to answer from the source and nothing else. A simple phrase does it:</p>'
						. '<blockquote>"Using only the text above, list the three main risks the report raises."</blockquote>'
						. '<p>That single instruction keeps the answer tied to your document instead of to Claude\'s general impressions.</p>'
						. '<h3>What this unlocks</h3>'
						. '<ul>'
						. '<li><strong>Summarising reports</strong> — "Summarise this 20-page report in eight bullets for a busy manager."</li>'
						. '<li><strong>Comparing documents</strong> — paste two versions and ask "What changed between version A and version B?"</li>'
						. '<li><strong>Extracting action items</strong> — "Pull out every task, who owns it, and its due date, from this thread."</li>'
						. '</ul>'
						. '<h3>Tell it to admit the gaps</h3>'
						. '<p>Documents rarely contain every answer. Add a safety line so Claude flags what is missing instead of inventing it:</p>'
						. '<blockquote>"If the answer is not in the text, say \'Not stated\' rather than guessing."</blockquote>'
						. '<p>This is the difference between a summary you can trust and one you have to double-check line by line. In {{role}}, get in the habit of adding that sentence to every document question — it takes two seconds and saves real rework.</p>'
						. '<h3>Recap</h3>'
						. '<p>Paste the source, ask Claude to work only from it, and tell it to say when something is not covered. That combination makes Claude a reliable partner for long-document work.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why paste the actual document into the conversation instead of just naming it?',
							'options'  => array(
								'It makes Claude respond in a friendlier tone',
								'Claude can only work from what is actually in the conversation',
								'It permanently trains Claude on your files',
								'It is the only way to make answers shorter',
							),
							'answer'   => 1,
							'hint'     => 'Claude answers from what is in front of it.',
							'feedback_correct'   => 'Exactly — Claude works from the text you provide, so the source has to be in the conversation.',
							'feedback_incorrect' => 'Not quite. The reason is that Claude can only use material actually present in the conversation.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A good grounding instruction is: "Using only the ___ above, answer the question."',
							'answer_text' => 'text',
							'accept'      => array( 'document', 'source', 'passage' ),
							'hint'        => 'Refer to the material you pasted.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Asking Claude to say "Not stated" when the source lacks an answer helps reduce made-up facts.',
							'answer'   => 0,
							'hint'     => 'Giving it permission to admit a gap beats forcing a guess.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write a prompt you would put above a pasted report that (a) asks for a grounded summary and (b) tells Claude to flag anything the report does not cover.',
							'rubric' => 'A strong answer instructs Claude to answer using only the pasted text, requests a specific summary, and includes an instruction to state when something is not covered rather than guessing.',
							'hint'   => 'Combine a "use only the text" clause with a "say if it is not covered" clause.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Meet Claude — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which of these is the strongest use of Claude?',
						'options'  => array(
							'Keeping a permanent, always-accurate record of live prices',
							'Drafting and editing a tricky email you have been putting off',
							'Physically filing your paper documents',
							'Guaranteeing legal advice with no human review',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Giving Claude the source text lets it answer from your material instead of from general impressions.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Telling Claude to work "using only the text above" is called ___ the answer in your source.',
						'answer_text' => 'grounding',
						'accept'      => array( 'grounded', 'anchoring' ),
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Getting more from Claude',
			'lessons' => array(

				array(
					'title'   => 'Structured outputs & step-by-step',
					'type'    => 'practice',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Structured output' ),
					'content' => '<h2>Ask for the exact shape you need</h2>'
						. '<p>Left to its own devices, Claude answers in prose. But you can ask for almost any <strong>shape</strong> — and getting the shape right the first time saves you cleaning it up afterwards.</p>'
						. '<h3>Name the format</h3>'
						. '<ul>'
						. '<li><strong>Bullets</strong> — "Give me five bullet points, one line each."</li>'
						. '<li><strong>A table</strong> — "Return a table with columns: Task, Owner, Due date."</li>'
						. '<li><strong>JSON</strong> — "Reply as JSON with keys name, email, and status, and nothing else."</li>'
						. '</ul>'
						. '<p>When you need to paste the result straight into a spreadsheet or another tool, an explicit format like a table or JSON is the move. The more precise you are — column names, key names, how many items — the less tidying you do afterwards.</p>'
						. '<p>A useful trick: show Claude a tiny example of the shape you want. One sample row or a two-line template tells it more than a paragraph of description, and it will match that pattern for the rest of the output.</p>'
						. '<h3>A worked example: messy notes to a clean table</h3>'
						. '<p>Suppose you have scribbled notes: <em>"Priya to send the budget by Friday. Sam still owes the venue quote — no date. Kick-off deck due next Wednesday, I\'ll do it."</em> A single prompt turns that into something usable:</p>'
						. '<blockquote>"Turn these notes into a table with columns Task, Owner, and Due date. If a due date is missing, write \'TBD\'."</blockquote>'
						. '<p>Claude returns three tidy rows — no more squinting at your own shorthand.</p>'
						. '<h3>Ask it to think step by step</h3>'
						. '<p>For harder problems — a calculation, a comparison, a decision with trade-offs — add <strong>"think step by step"</strong> or "show your reasoning before the answer." Working through the steps out loud makes Claude more accurate and lets you check <em>how</em> it reached the conclusion, not just what it concluded. In {{role}}, this is worth it whenever the answer would be expensive to get wrong.</p>'
						. '<h3>Recap</h3>'
						. '<p>State the output shape you want, and ask for step-by-step reasoning on anything tricky. Two small habits, far more usable answers.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'You want a result you can paste straight into a spreadsheet. What should you ask Claude for?',
							'options'  => array(
								'A long flowing paragraph',
								'A table with named columns',
								'A poem about the data',
								'Whatever format it prefers',
							),
							'answer'   => 1,
							'hint'     => 'Think about what drops cleanly into rows and columns.',
							'feedback_correct'   => 'Right — asking for a table with named columns gives you spreadsheet-ready structure.',
							'feedback_incorrect' => 'Not quite. A table with named columns is what maps cleanly into a spreadsheet.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'For a machine-readable result with named keys, ask Claude to reply as ___.',
							'answer_text' => 'JSON',
							'accept'      => array( 'json' ),
							'hint'        => 'A common data format with keys and values.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Asking Claude to "think step by step" tends to help most on harder reasoning or calculation tasks.',
							'answer'   => 0,
							'hint'     => 'Working through the steps helps where the answer is easy to get wrong.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Take a few lines of messy notes from your own work and write a prompt that turns them into a table with clear columns, including how missing values should be handled.',
							'rubric' => 'A strong answer specifies a table with named columns and states a rule for missing or unknown values (for example, writing "TBD" or "Not stated").',
							'hint'   => 'Name the columns and say what to do when a field is blank.',
						),
					),
				),

				array(
					'title'   => 'Using Claude responsibly',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 25,
					'topics'  => array( 'Responsible use' ),
					'content' => '<h2>Powerful assistant, human in charge</h2>'
						. '<p>Claude makes you faster, but the responsibility for the result stays with you. A few habits keep AI-assisted work trustworthy.</p>'
						. '<h3>Verify what matters</h3>'
						. '<p>Claude can sound certain and still be wrong. For anything that carries weight — a figure in a report, a legal or medical claim, a name or a date — <strong>check it against a reliable source</strong> before you rely on it. Treat its output as a well-informed draft, not the last word.</p>'
						. '<h3>Guard sensitive information</h3>'
						. '<p>Do not paste <strong>secrets or personal data</strong> — passwords, API keys, customer records, health details — into a consumer AI tool. If you would not post it on a shared drive without thinking, do not drop it into a chat box. When you need to work with real data, remove or mask the sensitive parts first: swap real names for "Customer A", strip out account numbers, and keep only the detail the task actually needs.</p>'
						. '<p>If your workplace has an approved AI tool or a data-handling policy, follow it. In {{role}}, knowing what you are and are not allowed to share is part of doing the job well.</p>'
						. '<blockquote>Speed is worth nothing if it leaks a customer\'s data or ships a confident mistake.</blockquote>'
						. '<h3>Keep a human in the loop</h3>'
						. '<p>Use Claude to inform decisions, not to make them. A person should review and own anything that affects other people — hiring notes, customer replies, policy wording. The tool drafts; you decide, and you carry the result.</p>'
						. '<h3>Be transparent</h3>'
						. '<p>When AI meaningfully shaped a piece of work, say so where it matters. Being open about AI-assisted writing builds trust and sets honest expectations with colleagues and clients.</p>'
						. '<h3>Recap</h3>'
						. '<p>Verify the important facts, keep secrets and personal data out, leave decisions with a human, and be open about AI\'s role. These habits let you move fast without cutting corners you will regret.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which is the best responsible-use habit when working with Claude?',
							'options'  => array(
								'Publish its answers without reading them, to save time',
								'Paste customer passwords in so it has full context',
								'Verify important facts against a reliable source before relying on them',
								'Let Claude make final hiring decisions on its own',
							),
							'answer'   => 2,
							'hint'     => 'Which option reduces risk rather than adding it?',
							'feedback_correct'   => 'Exactly — checking important facts before you rely on them is the core habit.',
							'feedback_incorrect' => 'Not quite. The responsible choice is to verify important facts before trusting them.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'It is fine to paste passwords, API keys, and customer records into a consumer AI tool as long as the task is urgent.',
							'answer'   => 1,
							'hint'     => 'Urgency does not make sharing secrets safe.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'For decisions that affect other people, always keep a ___ in the loop to review and own the outcome.',
							'answer_text' => 'human',
							'accept'      => array( 'person', 'people' ),
							'hint'        => 'The tool drafts; who decides?',
						),
						array(
							'type'     => 'reflection',
							'question' => 'Think about your own work: what is one piece of information you should never paste into a consumer AI tool, and how would you handle that task instead?',
							'rubric'   => 'A thoughtful answer identifies specific sensitive data (such as personal, financial, or confidential information) and describes a safer approach, such as masking or removing it, or using an approved tool.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Getting more from Claude — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'What is the most reliable way to force Claude into a machine-readable shape with named fields?',
						'options'  => array(
							'Ask it to "be creative with the layout"',
							'Ask it to reply as JSON with specific keys and nothing else',
							'Hope it guesses the format you had in mind',
							'Tell it to make the answer longer',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Asking Claude to "think step by step" is most useful on harder reasoning or calculation tasks.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'A core responsible-use rule is to ___ important facts against a reliable source before you rely on them.',
						'answer_text' => 'verify',
						'accept'      => array( 'check', 'confirm' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which piece of information should you avoid pasting into a consumer AI tool?',
						'options'  => array(
							'A public press release',
							'A paragraph you wrote yourself',
							'A generic list of writing tips',
							'A customer\'s password and personal records',
						),
						'answer'   => 3,
					),
				),
			),
		),
	),
);
