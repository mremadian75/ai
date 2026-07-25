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
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write a prompt that turns a pasted list of expenses into a table with exactly the columns Date, Vendor, Amount, Category — output only the table, ready to paste into a spreadsheet, with nothing before or after it.',
							'rubric' => 'A strong answer names the exact columns in order, forbids any preamble or closing remark, states what to do when a field is missing rather than leaving it to guesswork, and marks where the expense list gets pasted.',
							'hint'   => 'Say what the output must NOT contain as well as what it must.',
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
						array(
							'type'     => 'short_answer',
							'question' => 'A colleague copies your best prompt but gets noticeably worse results from it. What is the most likely reason, and what would you add to the shared version to fix it?',
							'rubric'   => 'A strong answer recognises that the prompt carried unstated context the original author supplied without noticing — assumed audience, tone, or background facts — and proposes making those explicit: marked placeholders, a note on what each expects, and a worked example of good input.',
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

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'Reliability under pressure',
			'lessons' => array(

				array(
					'title'   => 'When the same prompt gives different answers',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Verification', 'Structured output' ),
					'content' => '<h2>Variation is the design, not a fault</h2>'
						. '<p>Run the same prompt twice and you will often get two different answers. People assume something is broken. Nothing is: generation involves deliberate randomness, which is what makes the output feel natural rather than robotic.</p>'
						. '<p>The practical question is whether that variation matters for <em>your</em> task — and the answer differs sharply.</p>'
						. '<h3>Where variation is fine, and where it is not</h3>'
						. '<p>Drafting, brainstorming, rewriting: variation is harmless or actively useful. Extraction, classification, anything feeding a spreadsheet or another step: variation is a defect, because the same input producing different structured output is a data quality problem you will discover downstream.</p>'
						. '<h3>Three ways to pin it down</h3>'
						. '<p><strong>Constrain the output space.</strong> "Answer with exactly one of: Billing, Bug, Feature request. No other text." A prompt that permits only three answers cannot drift into a fourth.</p>'
						. '<p><strong>Give examples.</strong> Two or three worked cases anchor the format far more firmly than any description of it — which you learned in Unit 1, and this is the reason it matters beyond style.</p>'
						. '<p><strong>Ask more than once.</strong> For a judgement that must be right, run it three times and look at whether the answers agree. Where they do, you can be reasonably confident. Where they disagree, you have identified exactly the cases that need a human — which is often more useful than the answer.</p>'
						. '<h3>Use disagreement as a signal</h3>'
						. '<p>This is the move most people miss. Inconsistency across runs is not noise to be suppressed; it is the model telling you the case is genuinely ambiguous. Route those to a person and let the consistent ones through.</p>'
						. '<blockquote>Ask whether you need the same answer every time. If yes, constrain the output. If no, stop worrying about it.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Variation is deliberate. It is fine for drafting and a defect for structured work. Constrain the answer set, anchor with examples, and treat disagreement across runs as a flag rather than a failure.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'You run a classification prompt three times and get two "Billing" and one "Bug". The best response is:',
							'options'  => array(
								'Take the majority and move on silently',
								'Take the majority but flag this case for a human, because disagreement marks ambiguity',
								'Discard the case entirely',
								'Rewrite the prompt from scratch',
							),
							'answer'   => 1,
							'hint'     => 'What is the disagreement telling you?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Getting a different answer to the same prompt means something has gone wrong.',
							'answer'   => 1,
							'hint'     => 'Why does output feel natural rather than robotic?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A prompt that permits only three named answers cannot ___ into a fourth.',
							'answer_text' => 'drift',
							'accept'      => array( 'wander', 'stray' ),
							'hint'        => 'Constrain the output space.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'You are tagging 200 product reviews as Positive, Negative or Mixed, and the results are inconsistent between runs. Write the revised prompt, and describe the process you would run around it to catch the ambiguous cases.',
							'rubric' => 'A strong answer constrains the output to exactly the three labels with no additional text, includes two or three worked examples covering a genuinely ambiguous case, and proposes running each review more than once and routing disagreements to a human rather than silently taking a majority.',
							'hint'   => 'Fix the prompt and the process — one alone is not enough.',
						),
					),
				),

				array(
					'title'   => 'Prompts that survive being handed over',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Reusable workflows', 'Few-shot examples' ),
					'content' => '<h2>The prompt works. For you</h2>'
						. '<p>Here is a pattern that repeats everywhere: someone builds a prompt that works beautifully, shares it, and their colleague gets noticeably worse results from what looks like the same thing.</p>'
						. '<p>The reason is almost never the prompt text. It is everything the author supplied without noticing — knowing what good input looks like, recognising a bad answer, quietly correcting the first attempt, having context in the conversation from earlier messages. None of that travels.</p>'
						. '<h3>What to add before you share</h3>'
						. '<ul>'
						. '<li><strong>Obvious placeholders</strong> — <code>[PASTE TRANSCRIPT]</code>, not a leftover from your own last use, which colleagues will edit around rather than replace.</li>'
						. '<li><strong>A worked example of good input</strong> — so they can see what "meeting notes" means here: three bullet points, or two pages?</li>'
						. '<li><strong>An example of good output</strong> — the standard, so they can tell whether what they got is acceptable.</li>'
						. '<li><strong>The known failure</strong> — "if the transcript has no decisions in it, this produces vague filler; that is the signal to check the source, not to rerun."</li>'
						. '<li><strong>A first-line note</strong> saying what it is for, in one sentence.</li>'
						. '</ul>'
						. '<h3>Test it the honest way</h3>'
						. '<p>Hand it to a colleague and watch without helping. Every question they ask is a gap in the prompt. It is uncomfortable and it takes ten minutes, and it finds things you cannot see because you already know the answers.</p>'
						. '<h3>Where to keep them</h3>'
						. '<p>Somewhere the team already looks — the wiki, the shared drive, the pinned channel message. A brilliant prompt library nobody can find is a private notes file with extra steps.</p>'
						. '<blockquote>A prompt is not shareable until someone else has used it successfully without asking you anything.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Package the tacit knowledge: placeholders, an input example, an output standard, the known failure mode, and one line on purpose. Then watch someone use it, silently.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A colleague gets worse results from your shared prompt. The most likely cause is:',
							'options'  => array(
								'They typed it incorrectly',
								'Unstated knowledge you supplied without noticing — what good input looks like, when to correct it',
								'Their account is on a weaker model',
								'The prompt is too long',
							),
							'answer'   => 1,
							'hint'     => 'What travelled with the text, and what did not?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Including an example of good ___ tells a colleague whether the answer they got is acceptable.',
							'answer_text' => 'output',
							'accept'      => array( 'result' ),
							'hint'        => 'The standard to compare against.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Watching a colleague use your prompt without helping is a good way to find gaps in it.',
							'answer'   => 0,
							'hint'     => 'Every question they ask is a gap.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Take a prompt you use regularly. What do you know about using it well that is not written anywhere in it?',
							'rubric'   => 'A strong answer surfaces genuinely tacit knowledge — what constitutes suitable input, how to recognise a bad answer, a correction habitually made on the second turn, or context the author supplies from memory — rather than restating what the prompt already says.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Reliability under pressure — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Variation between runs is a genuine defect when:',
						'options'  => array(
							'You are brainstorming ideas',
							'The output feeds a spreadsheet or another automated step',
							'You are drafting an email',
							'You are rewriting a paragraph',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Disagreement across repeated runs identifies the cases that need a ___.',
						'answer_text' => 'human',
						'accept'      => array( 'person', 'review' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'A prompt is ready to share once it works reliably for its author.',
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which addition most helps a colleague judge whether their result is good enough?',
						'options'  => array(
							'A longer instruction',
							'An example of acceptable output',
							'A note about which model to use',
							'More placeholders',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Judgement at scale',
			'lessons' => array(

				array(
					'title'   => 'Deciding how much checking is enough',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Verification', 'Reusable workflows' ),
					'content' => '<h2>Checking everything is as wrong as checking nothing</h2>'
						. '<p>Unit 2 gave you a sixty-second verification habit. It works well for one output at a time. It falls apart at fifty, and what people do next is the interesting part: they either check nothing, or they check everything and lose all the time they saved.</p>'
						. '<p>Neither is right. The amount of checking should be set by the consequence of an error, and consequences vary enormously across a single day.</p>'
						. '<h3>Three tiers</h3>'
						. '<p><strong>Check everything.</strong> Anything going to a customer, a regulator or a public channel; anything with numbers a decision rests on; anything you would be embarrassed to have attributed to you. Small volume, high stakes.</p>'
						. '<p><strong>Sample.</strong> Bulk work where an occasional error is survivable — internal tagging, first-pass triage, draft summaries someone will read anyway. Check ten in every hundred, properly. If the sample is clean, proceed; if two of ten are wrong, stop and fix the prompt rather than continuing to check.</p>'
						. '<p><strong>Check the process, not the output.</strong> High volume, low individual stakes. Verify that the pipeline is behaving — is the distribution of labels roughly what you expect, has the rate of one category suddenly doubled — rather than reading each item.</p>'
						. '<h3>Sampling is a real answer, not laziness</h3>'
						. '<p>Every quality process outside software works this way. Reading a sample properly beats skimming everything, because skimming everything catches almost nothing while feeling thorough.</p>'
						. '<h3>Escalate on evidence</h3>'
						. '<p>When a sample shows a problem, the response is to fix the prompt and re-run — not to check harder. Checking harder treats the symptom forever.</p>'
						. '<blockquote>Decide the tier before you start the batch. Deciding afterwards means deciding while tired, with a deadline, which reliably produces "it looked fine".</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Three tiers set by consequence: check everything, sample properly, or monitor the process. Choose the tier up front, and treat a bad sample as a prompt problem rather than a reason to read more.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'You have 200 internally tagged support tickets. The right verification approach is usually:',
							'options'  => array(
								'Read all 200 carefully',
								'Check a proper sample, and fix the prompt if the sample shows problems',
								'Check none — they are internal',
								'Skim all 200 quickly',
							),
							'answer'   => 1,
							'hint'     => 'Which one catches problems without consuming the time saved?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Skimming everything quickly is a better use of time than reading a smaller sample properly.',
							'answer'   => 1,
							'hint'     => 'What does skimming actually catch?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'When a sample shows repeated errors, the right response is to fix the ___ rather than to check more items.',
							'answer_text' => 'prompt',
							'accept'      => array( 'process' ),
							'hint'        => 'Treat the cause, not the symptom.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Sort three tasks from your own work into the three tiers, and justify each placement by what happens when it is wrong.',
							'rubric'   => 'A strong answer assigns three concrete tasks to the check-everything, sample and monitor-the-process tiers, and justifies each by consequence and visibility of an error — who sees it, how quickly it would be caught, and what it would cost — rather than by how much time each takes.',
						),
					),
				),

				array(
					'title'   => 'Knowing when you have outgrown the chat window',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 30,
					'topics'  => array( 'Reusable workflows', 'Structured output', 'Verification' ),
					'content' => '<h2>Four signs it is time for something more than a chat box</h2>'
						. '<p>Working in the chat interface is right for most people most of the time. But there is a point where it stops being the efficient choice, and recognising it early saves a great deal of tedium.</p>'
						. '<ul>'
						. '<li><strong>You are pasting the same prompt more than a few times a day.</strong> That is a job for a saved prompt or a small custom assistant.</li>'
						. '<li><strong>You are doing the same thing to many items.</strong> Fifty documents through one prompt by hand is an afternoon; the same thing scripted is minutes, and it never gets bored on item forty.</li>'
						. '<li><strong>The output has to land somewhere else.</strong> Copying results into a spreadsheet one at a time is the clearest signal of all — that is a data pipeline being performed manually.</li>'
						. '<li><strong>Someone else needs it to run without you.</strong> A prompt only you execute is a bottleneck with your name on it.</li>'
						. '</ul>'
						. '<h3>What comes next, in order of effort</h3>'
						. '<p><strong>A saved prompt</strong> costs nothing. <strong>A custom assistant</strong> with fixed instructions and reference files costs an hour and removes all the setup typing. <strong>A no-code automation</strong> connects it to the tools you already use. <strong>Actual code</strong> against the API is the most flexible and the most work — and it is where the whole of this ladder\'s rung three lives.</p>'
						. '<h3>Do not skip ahead</h3>'
						. '<p>Building automation before the prompt is proven is the classic mistake. Get it right by hand, use it for a fortnight, and let real use tell you what actually needs to scale. Half the prompts people plan to automate turn out not to be worth it.</p>'
						. '<blockquote>Automate the prompt you have used forty times, not the one you are excited about today.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Repetition, volume, output that must land elsewhere, and dependence on you are the four signals. Climb the ladder of effort only as far as real use justifies.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which is the clearest sign you have outgrown the chat window?',
							'options'  => array(
								'The answers are getting long',
								'You are copying results into a spreadsheet one at a time',
								'You use it every day',
								'You have several prompts saved',
							),
							'answer'   => 1,
							'hint'     => 'That is a pipeline being performed by hand.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Automate the prompt you have already used many times, not the one you are ___ about today.',
							'answer_text' => 'excited',
							'accept'      => array( 'enthusiastic' ),
							'hint'        => 'Let real use decide.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Building the automation first and proving the prompt afterwards is the efficient order.',
							'answer'   => 1,
							'hint'     => 'What happens if the prompt turns out not to be worth it?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Identify one thing you currently do by hand in a chat window that meets at least two of the four signals. Describe the smallest next step up — not the most sophisticated one — and say what you would need to see before going further.',
							'rubric' => 'A strong answer names a real task, states which of the four signals it meets, proposes the genuinely smallest escalation (a saved prompt or a custom assistant rather than jumping to code), and gives a concrete condition — a volume, a frequency, or a second person needing it — that would justify the next step.',
							'hint'   => 'Smallest step, not the most impressive one.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'Which of this course\'s ideas — examples, structured output, verification, reusable prompts — has changed your results the most, and how do you know?',
							'rubric'   => 'A thoughtful answer names one idea and offers concrete evidence of the change — a task that now takes less time, an error class that stopped appearing, a prompt colleagues now use — rather than a general impression.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Judgement at scale — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'How much checking an AI output needs should be decided by:',
						'options'  => array(
							'How long the output is',
							'The consequence of an error and whether it would be caught',
							'How confident the model sounds',
							'How much time is left in the day',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Reading a proper sample beats skimming everything.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'A prompt only you can run is a ___ with your name on it.',
						'answer_text' => 'bottleneck',
						'accept'      => array( 'dependency' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'The right first escalation beyond the chat window is usually:',
						'options'  => array(
							'Writing code against the API',
							'A saved prompt or a custom assistant',
							'Hiring a developer',
							'Switching AI providers',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
