<?php
/**
 * Seed course: "ChatGPT: Reliable Results" — rung 2 of the `chatgpt` ladder.
 *
 * Follows the canonical schema contract in course-pe-foundations.php, plus the
 * two v1.17 additions:
 *   track / level_rank  — position on a subject's level ladder.
 *   lesson['variants']  — per-department overlay blocks (see Mahan_Variants),
 *                         keyed by field: marketing, sales, finance, hr,
 *                         management, operations, engineering, product, general.
 *                         Each block: { heading?, body, example? }.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'chatgpt-l2',
	'title'       => 'ChatGPT: Reliable Results',
	'slug'        => 'chatgpt-reliable-results',
	'subtitle'    => 'Move past hit-and-miss answers — structure, examples, and checks that make ChatGPT dependable.',
	'excerpt'     => 'The intermediate rung: give ChatGPT structure and examples, get output in the shape you need, and verify it before you use it.',
	'description' => '<p>You can already hold a useful conversation with ChatGPT. The next problem is <strong>consistency</strong>: the same kind of request gives a great answer one day and a vague one the next.</p>'
		. '<p>This level fixes that. You will learn to give the model structure and worked examples, to demand output in a fixed shape you can paste straight into your workflow, and to build a quick verification habit so you catch the errors that matter.</p>',
	'category'    => 'AI Tools',
	'track'       => 'chatgpt',
	'level_rank'  => 2,
	'level'       => 'intermediate',
	'est_hours'   => 3,
	'featured'    => false,
	'certificate' => true,
	'order'       => 5,
	'topics'      => array( 'Structured output', 'Few-shot examples', 'Verification', 'Reusable workflows' ),
	'outcomes'    => array(
		'Structure a request so ChatGPT answers consistently',
		'Use worked examples to lock in a format and a voice',
		'Demand output in a shape you can paste straight into your tools',
		'Verify AI output quickly without re-doing the work yourself',
		'Turn a prompt that worked once into a reusable team asset',
	),
	'references'  => array(
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
			'title'  => 'Structured outputs',
			'source' => 'OpenAI — API documentation',
			'url'    => 'https://platform.openai.com/docs/guides/structured-outputs',
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
			'title'   => 'Structure and examples',
			'lessons' => array(

				array(
					'title'   => 'Show, don\'t just tell: worked examples',
					'type'    => 'practice',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Few-shot examples' ),
					'content' => '<h2>The fastest way to fix an inconsistent answer</h2>'
						. '<p>Describing what you want takes paragraphs. <em>Showing</em> what you want takes one example — and works better. Paste two or three worked examples of input → desired output, then give the real input. The model copies the pattern: length, tone, structure, level of detail.</p>'
						. '<h3>Why it works</h3>'
						. '<p>You are no longer asking the model to guess your standards from adjectives like "professional" or "concise". It can see them. This is the technique usually called <strong>few-shot prompting</strong>, and it is the single highest-leverage move at this level.</p>'
						. '<h3>How many examples?</h3>'
						. '<p>Two or three is usually the sweet spot. One is often ambiguous; ten mostly wastes space and can make the model over-copy surface details of your examples instead of the underlying pattern. Pick examples that differ from each other, so the model learns the <em>rule</em> rather than a template.</p>'
						. '<blockquote>If you have ever fixed an answer by saying "no, more like this…", that correction was an example. Put it in the prompt next time.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Two or three deliberately varied worked examples teach format, tone, and depth faster and more reliably than any amount of description.</p>',
					'variants' => array(
						'marketing' => array(
							'heading' => 'In marketing',
							'body'    => 'Paste three of your best-performing subject lines or ad headlines as examples, then ask for ten more for a new campaign. The model picks up your brand voice from the examples instead of producing generic copy.',
							'example' => 'Here are 3 subject lines that performed well for us: […]. Write 10 more in the same voice for a webinar invite to operations managers.',
						),
						'sales'     => array(
							'heading' => 'In sales',
							'body'    => 'Give two real (anonymised) outreach emails that got replies, then ask for a new one for a different prospect. The examples carry your tone and your value framing far better than describing them.',
							'example' => 'Two emails that got replies: […]. Write one for a logistics COO who downloaded our pricing page, same length and tone.',
						),
						'finance'   => array(
							'heading' => 'In finance',
							'body'    => 'Show one worked variance commentary you were happy with, then ask for the same treatment on this month\'s numbers. Examples are how you get a consistent house style across a reporting pack.',
							'example' => 'Here is last quarter\'s variance commentary: […]. Write this quarter\'s in the same structure using the figures below.',
						),
						'hr'        => array(
							'heading' => 'In HR & People',
							'body'    => 'Provide two interview scorecards or feedback notes written the way your team expects, then ask for a draft for a new candidate. This keeps evaluation language consistent and fair across interviewers.',
							'example' => 'Two example scorecards: […]. Draft one from these interview notes, same structure and neutral tone.',
						),
						'management' => array(
							'heading' => 'In management',
							'body'    => 'Paste two past project updates leadership responded well to, then ask for this week\'s. Examples encode what your audience cares about — decisions and risks, not activity logs.',
							'example' => 'Two updates that landed well: […]. Write this week\'s from these notes, same length and emphasis.',
						),
					),
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Your prompt keeps producing answers in the wrong tone despite detailed instructions. What is the most effective next move?',
							'options'  => array(
								'Add more adjectives describing the tone',
								'Paste two or three examples of output in the tone you want',
								'Ask the model to try harder',
								'Shorten the prompt',
							),
							'answer'   => 1,
							'hint'     => 'Show rather than describe.',
							'feedback_correct'   => 'Exactly — worked examples communicate tone far more precisely than adjectives.',
							'feedback_incorrect' => 'Not quite — the reliable fix is to show examples of the output you want.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Adding ten nearly identical examples is generally better than two varied ones.',
							'answer'   => 1,
							'hint'     => 'You want the model to learn the rule, not copy surface details.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Including a few worked input→output examples in a prompt is called ___ prompting.',
							'answer_text' => 'few-shot',
							'accept'      => array( 'few shot', 'fewshot' ),
							'hint'        => 'As opposed to zero-shot.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Take a task you repeat at work in {{role}}. Write a prompt that includes two worked examples and then the real input, so the output matches your standards without further editing.',
							'rubric' => 'A strong answer includes at least two genuinely varied input→output examples, a clear instruction, and the real input — with the examples carrying tone/format rather than being described in words.',
						),
					),
				),

				array(
					'title'   => 'Output you can paste straight into your work',
					'type'    => 'practice',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Structured output' ),
					'content' => '<h2>Stop reformatting AI output by hand</h2>'
						. '<p>Most of the time people lose with AI is spent tidying the answer: splitting prose into rows, renaming headings, cutting the preamble. All of that is avoidable — ask for the shape you actually need.</p>'
						. '<h3>Name the shape exactly</h3>'
						. '<ul>'
						. '<li><strong>A table</strong> — name the columns, in order: "a markdown table with columns: item, owner, due date, risk".</li>'
						. '<li><strong>A fixed list</strong> — "exactly five bullets, each under 15 words".</li>'
						. '<li><strong>Machine-readable</strong> — "JSON with keys title, summary, priority" when it feeds another tool.</li>'
						. '</ul>'
						. '<h3>Two rules that save you every time</h3>'
						. '<p>First, <strong>ban the preamble</strong>: "Return only the table, no introduction." Second, <strong>say what to do with unknowns</strong>: "If a field is not in the source, write \'Not stated\' rather than guessing." Without that second rule the model will quietly invent plausible values to fill your columns.</p>'
						. '<blockquote>Specify the shape and the unknown-handling, and the output goes straight into your document.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Name the exact output shape, forbid the preamble, and define what "unknown" looks like — then you can paste rather than reformat.</p>',
					'variants' => array(
						'marketing' => array(
							'heading' => 'In marketing',
							'body'    => 'Ask for a content calendar as a table with columns: date, channel, hook, CTA, asset needed. It drops straight into your planning sheet instead of becoming a paragraph you have to break apart.',
							'example' => 'Return only a markdown table, columns: date | channel | hook | CTA | asset. Mark anything not in the brief as "Not stated".',
						),
						'sales'     => array(
							'heading' => 'In sales',
							'body'    => 'Turn messy call notes into a fixed CRM shape: next step, decision-maker, objection, timeline. Same columns every time means your pipeline stays comparable.',
							'example' => 'From these notes return only JSON with keys: next_step, decision_maker, main_objection, timeline. Use "Not stated" when the notes do not say.',
						),
						'finance'   => array(
							'heading' => 'In finance',
							'body'    => 'Demand a fixed schedule shape — line item, current, prior, variance, driver — and explicitly forbid invented figures. Never let the model fill an empty cell with a plausible number.',
							'example' => 'Return only a table: line item | current | prior | variance | driver. If a figure is not in the data provided, write "Not provided" — do not estimate.',
						),
						'hr'        => array(
							'heading' => 'In HR & People',
							'body'    => 'Standardise role requirements as a table — requirement, must/nice-to-have, how assessed — so every hiring manager evaluates against the same structure.',
							'example' => 'Return only a table: requirement | must-have or nice-to-have | how we assess it. Mark unclear items as "To confirm with hiring manager".',
						),
						'management' => array(
							'heading' => 'In management',
							'body'    => 'Ask for decisions and risks in a fixed shape — decision, owner, due date, risk — so your updates are scannable and comparable week over week.',
							'example' => 'Return only a table: decision | owner | due date | risk. Use "Unassigned" where the notes do not name an owner.',
						),
					),
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which instruction most reliably stops the model inventing values for empty fields?',
							'options'  => array(
								'"Be accurate."',
								'"If a field is not in the source, write \'Not stated\' rather than guessing."',
								'"Use your best judgement."',
								'"Keep it short."',
							),
							'answer'   => 1,
							'hint'     => 'Give the model an explicit way to say "I don\'t know".',
							'feedback_correct'   => 'Right — defining the unknown case removes the pressure to fabricate.',
							'feedback_incorrect' => 'Not quite — you have to give an explicit placeholder for unknowns.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Naming the exact columns you want is more reliable than asking for "a nice summary table".',
							'answer'   => 0,
							'hint'     => 'Specific beats vague.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'To stop the model adding an introduction before your table, tell it to return only the table with no ___.',
							'answer_text' => 'preamble',
							'accept'      => array( 'introduction', 'intro' ),
							'hint'        => 'The chatty sentence before the useful part.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Structure & examples — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'How many worked examples are usually the sweet spot for few-shot prompting?',
						'options'  => array( 'None', 'Two or three varied examples', 'Exactly one', 'Ten or more' ),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'When output feeds another tool, ask for a machine-readable format such as ___.',
						'answer_text' => 'JSON',
						'accept'      => array( 'json' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Without an explicit instruction, a model asked to fill a table may invent plausible values for missing fields.',
						'answer'   => 0,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Trust, verify, and reuse',
			'lessons' => array(

				array(
					'title'   => 'A verification habit that takes 60 seconds',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 25,
					'topics'  => array( 'Verification' ),
					'content' => '<h2>Checking everything is as bad as checking nothing</h2>'
						. '<p>Re-doing the work to verify the AI defeats the point; trusting it blindly eventually burns you. The middle path is <strong>risk-weighted checking</strong>: spend your attention where a mistake would actually cost something.</p>'
						. '<h3>The three things worth checking</h3>'
						. '<ol>'
						. '<li><strong>Numbers and names.</strong> Anything specific — figures, dates, people, product names — is where confident invention hurts most. Check these against the source, always.</li>'
						. '<li><strong>Claims you will repeat.</strong> If you are going to say it to a customer, a regulator, or your leadership, verify it.</li>'
						. '<li><strong>Anything suspiciously convenient.</strong> If the answer is exactly what you hoped, look twice.</li>'
						. '</ol>'
						. '<h3>Make the model help you check</h3>'
						. '<p>Ask it to mark its own uncertainty: "Flag any claim you are not confident about." Ask it to work only from what you pasted: "Use only the text above." Ask it to cite the line it used. None of these are guarantees, but they turn a wall of confident prose into something you can scan.</p>'
						. '<blockquote>Verify the parts that would embarrass you if they were wrong. Skim the rest.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Check numbers, names, and anything you will repeat publicly; ask the model to ground itself and flag uncertainty; let the low-stakes prose go unchecked.</p>',
					'variants' => array(
						'finance'   => array(
							'heading' => 'In finance',
							'body'    => 'Every figure gets checked against the source — no exceptions. AI is for the commentary and the structure, not for the arithmetic. Never let a number reach a report without a human tracing it back.',
							'example' => 'Use only the figures in the table above. Do not compute new totals. Flag any statement you cannot support from the table.',
						),
						'hr'        => array(
							'heading' => 'In HR & People',
							'body'    => 'Anything about a specific person — dates, titles, performance statements — is verified before it is used, and sensitive personal data does not go into a consumer tool at all.',
							'example' => 'Summarise only from the notes provided. Do not infer anything about the candidate that is not explicitly stated.',
						),
						'sales'     => array(
							'heading' => 'In sales',
							'body'    => 'Product capabilities, pricing, and timelines are the danger zone: a confident wrong claim in an email becomes a promise. Check those against your own material every time.',
							'example' => 'Draft the reply using only the product facts below. If the prospect asked something not covered here, flag it instead of answering.',
						),
						'marketing' => array(
							'heading' => 'In marketing',
							'body'    => 'Statistics, competitor claims, and anything quotable get verified — an invented figure in a public post is a correction you will have to publish.',
							'example' => 'Do not include statistics unless they appear in the source I pasted. Mark any claim needing a citation as [VERIFY].',
						),
						'management' => array(
							'heading' => 'In management',
							'body'    => 'Check attributions and commitments: who agreed to what, by when. AI summaries of meetings routinely assign an action to the wrong person, and that error propagates.',
							'example' => 'List decisions and owners only where the notes explicitly name someone; otherwise write "Owner unclear".',
						),
					),
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which part of an AI-drafted document most deserves your verification time?',
							'options'  => array(
								'The greeting',
								'Specific figures, dates, and names',
								'The paragraph spacing',
								'The choice of synonyms',
							),
							'answer'   => 1,
							'hint'     => 'Where does confident invention cost the most?',
							'feedback_correct'   => 'Correct — specifics are where hallucination does real damage.',
							'feedback_incorrect' => 'Not quite — concrete specifics (figures, dates, names) carry the real risk.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Asking the model to flag claims it is unsure about is a useful (though not foolproof) checking aid.',
							'answer'   => 0,
							'hint'     => 'It helps you scan; it is not a guarantee.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Spending your checking effort where an error would be costly is called ___-weighted verification.',
							'answer_text' => 'risk',
							'accept'      => array(),
							'hint'        => 'Match effort to consequences.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Describe one AI output in your own work where a wrong detail would be costly, and the specific check you would run before using it.',
							'rubric'   => 'A good answer names a concrete output, identifies the specific element that carries risk (a figure, name, date, commitment, or claim), and states a practical check against a source.',
						),
					),
				),

				array(
					'title'   => 'From one good prompt to a team asset',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 25,
					'topics'  => array( 'Reusable workflows' ),
					'content' => '<h2>The prompt that worked is worth keeping</h2>'
						. '<p>Most people rewrite the same prompt from scratch every week. The intermediate move is to treat a prompt that worked as a small <strong>asset</strong>: save it, parameterise it, and share it.</p>'
						. '<h3>Parameterise it</h3>'
						. '<p>Find the parts that change each time — the audience, the source text, the deadline — and turn them into slots you fill in: <em>"Write a [LENGTH] update for [AUDIENCE] from the notes below."</em> Everything else stays fixed, which is exactly why the output stays consistent.</p>'
						. '<h3>Write down when to use it</h3>'
						. '<p>A prompt library without usage notes gets ignored. One line is enough: what it is for, what to paste in, and what good output looks like.</p>'
						. '<h3>Review it as models change</h3>'
						. '<p>Prompts are not permanent. When a tool updates, spot-check your most-used prompts — occasionally a workaround you added is no longer needed, or a constraint has stopped being honoured. Working toward {{primary_goal}}, the two or three prompts you run weekly are the ones worth this attention.</p>'
						. '<blockquote>A saved, parameterised prompt with one line of usage notes will outlive a dozen clever one-offs.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Save what worked, turn the changing parts into slots, add a line on when to use it, and re-check your most-used prompts when tools change.</p>',
					'variants' => array(
						'marketing' => array(
							'heading' => 'In marketing',
							'body'    => 'Build a shared library of campaign prompts — brief-to-outline, outline-to-copy, copy-to-variants — so every campaign starts from the same proven structure instead of a blank page.',
							'example' => 'Template: "Write [N] [CHANNEL] variants for [AUDIENCE] promoting [OFFER], in our brand voice (examples below), each under [LIMIT] words."',
						),
						'sales'     => array(
							'heading' => 'In sales',
							'body'    => 'Templatise outreach and follow-up by segment. New reps get your best-performing structure on day one rather than reinventing it over their first quarter.',
							'example' => 'Template: "Write a follow-up to [ROLE] at a [INDUSTRY] company after [EVENT], referencing [VALUE POINT], under 120 words, one clear next step."',
						),
						'finance'   => array(
							'heading' => 'In finance',
							'body'    => 'Standardise the recurring reporting narratives — monthly close commentary, budget-variance explanation — so the pack reads consistently no matter who drafts it.',
							'example' => 'Template: "Write [PERIOD] variance commentary from the table below, 3 bullets per line item over [THRESHOLD]% variance, using only the figures given."',
						),
						'hr'        => array(
							'heading' => 'In HR & People',
							'body'    => 'Templatise job descriptions, interview scorecards, and policy summaries so language stays consistent and inclusive across every team that hires.',
							'example' => 'Template: "Draft a job description for [ROLE] at [LEVEL] from these responsibilities, using our structure (example below), inclusive language, no unnecessary requirements."',
						),
						'management' => array(
							'heading' => 'In management',
							'body'    => 'Keep a small set of leadership prompts — weekly update, decision memo, risk review — so your communications are predictable and your team knows what to expect.',
							'example' => 'Template: "Write a decision memo on [TOPIC]: context, options considered, recommendation, risks, and what I need from [AUDIENCE]. Under 300 words."',
						),
					),
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What makes a saved prompt genuinely reusable?',
							'options'  => array(
								'Making it as long as possible',
								'Turning the parts that change into clearly marked slots, with a note on when to use it',
								'Keeping it secret so it is not diluted',
								'Rewriting it fresh each time',
							),
							'answer'   => 1,
							'hint'     => 'Fixed structure, variable inputs.',
							'feedback_correct'   => 'Exactly — parameterised slots plus usage notes make it usable by anyone.',
							'feedback_incorrect' => 'Not quite — reusability comes from marking the variable parts and noting when to use it.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Prompts should be re-checked occasionally, because tool updates can change how they behave.',
							'answer'   => 0,
							'hint'     => 'Prompts are not permanent artefacts.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Turning the parts of a prompt that change each time into fill-in slots is called ___ it.',
							'answer_text' => 'parameterising',
							'accept'      => array( 'parameterizing', 'templating' ),
							'hint'        => 'Making a template out of it.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Verify & reuse — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which is the best description of risk-weighted verification?',
						'options'  => array(
							'Verify every word of every output',
							'Check the parts where an error would be costly; skim the rest',
							'Never check AI output',
							'Only check outputs longer than a page',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'A prompt worth keeping should be saved with a short note on ___ to use it.',
						'answer_text' => 'when',
						'accept'      => array(),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Specific figures and names deserve more verification attention than phrasing choices.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Your weekly report prompt suddenly produces a different structure after a tool update. What is the right response?',
						'options'  => array(
							'Abandon AI for reports',
							'Spot-check and adjust your most-used prompts after tool changes',
							'Assume the new structure is better',
							'Add ten more examples',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
