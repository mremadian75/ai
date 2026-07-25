<?php
/**
 * Seed course: "ChatGPT: Leading AI Adoption" — rung 4 of the `chatgpt` ladder.
 *
 * Follows the canonical schema contract in course-pe-foundations.php, plus
 * track/level_rank (level ladder) and per-lesson `variants` (department
 * overlays, see Mahan_Variants).
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'chatgpt-l4',
	'title'       => 'ChatGPT: Leading AI Adoption',
	'slug'        => 'chatgpt-leading-adoption',
	'subtitle'    => 'Take it beyond your own desk — design team workflows, set guardrails, and measure whether it actually helped.',
	'excerpt'     => 'The expert rung: turn personal skill into team capability with standards, guardrails, enablement, and honest measurement.',
	'description' => '<p>You are now the person others ask. The bottleneck stops being your own prompting and becomes everyone else\'s: inconsistent quality, unclear rules about data, and no idea whether any of it is paying off.</p>'
		. '<p>This level is about designing AI into how a team works — choosing the right jobs, writing guardrails people will actually follow, teaching without becoming the help desk, and measuring the outcome honestly enough to keep or kill each use case.</p>',
	'category'    => 'AI Tools',
	'track'       => 'chatgpt',
	'level_rank'  => 4,
	'level'       => 'expert',
	'est_hours'   => 3,
	'featured'    => false,
	'certificate' => true,
	'order'       => 7,
	'topics'      => array( 'Workflow design', 'Guardrails & data policy', 'Team enablement', 'Measuring impact' ),
	'outcomes'    => array(
		'Choose which parts of a workflow should and should not use AI',
		'Write data and usage guardrails your team will actually follow',
		'Roll out a shared prompt standard without becoming the help desk',
		'Measure whether an AI workflow genuinely improved the outcome',
		'Retire a use case that is not earning its keep',
	),
	'references'  => array(
		array(
			'title'  => 'AI Risk Management Framework (AI RMF 1.0)',
			'source' => 'NIST, 2023',
			'url'    => 'https://www.nist.gov/itl/ai-risk-management-framework',
		),
		array(
			'title'  => 'OWASP Top 10 for Large Language Model Applications',
			'source' => 'OWASP Foundation',
			'url'    => 'https://owasp.org/www-project-top-10-for-large-language-model-applications/',
		),
		array(
			'title'  => 'EU Artificial Intelligence Act (Regulation (EU) 2024/1689)',
			'source' => 'European Union',
			'url'    => 'https://artificialintelligenceact.eu/',
		),
		array(
			'title'  => 'Generative AI and the future of work',
			'source' => 'McKinsey Global Institute',
			'url'    => 'https://www.mckinsey.com/mgi/our-research',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'Designing AI into the work',
			'lessons' => array(

				array(
					'title'   => 'Choosing the right jobs for AI',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Workflow design' ),
					'content' => '<h2>Most failed AI rollouts picked the wrong job</h2>'
						. '<p>Teams often aim AI at whatever is most visible rather than what actually suits it. The result is a tool that impresses in a demo and quietly gets abandoned.</p>'
						. '<h3>What AI is genuinely good at</h3>'
						. '<ul>'
						. '<li><strong>First drafts</strong> of things a human will review anyway.</li>'
						. '<li><strong>Reshaping</strong> — summarising, reformatting, translating between styles.</li>'
						. '<li><strong>Breadth</strong> — generating many options to choose from.</li>'
						. '<li><strong>Unblocking</strong> — getting past a blank page.</li>'
						. '</ul>'
						. '<h3>Where it disappoints</h3>'
						. '<p>Anything requiring guaranteed accuracy without review, anything needing genuine accountability, anything depending on context nobody wrote down, and anything where the review takes longer than doing it yourself. That last one is the quiet killer: if checking the output costs more than the draft saved, the workflow is a net loss no matter how good the demo was.</p>'
						. '<h3>The design question</h3>'
						. '<p>Do not ask "can AI do this job?" — ask <em>"which step of this job is the bottleneck, and is that step a drafting problem or a judgement problem?"</em> Point AI at drafting steps and keep judgement with people. Working toward {{primary_goal}}, that single reframe usually picks the right pilot for you.</p>'
						. '<blockquote>Automate the draft. Keep the decision.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Pick jobs where a human reviews anyway, where breadth or reshaping helps, and where review is cheaper than the work saved — and keep accountable judgement with people.</p>',
					'variants' => array(
						'marketing' => array(
							'heading' => 'In marketing',
							'body'    => 'Good pilot: first-draft variants and channel adaptations that an editor reviews anyway. Bad pilot: publishing unreviewed claims or auto-generating whole campaigns without a strategist.',
							'example' => 'Pilot: "AI drafts 10 ad variants; a marketer selects and edits 2." Measure editing time saved per campaign.',
						),
						'sales'     => array(
							'heading' => 'In sales',
							'body'    => 'Good pilot: pre-call research summaries and follow-up drafts. Bad pilot: unreviewed outbound at volume, where one confident wrong claim damages the relationship and the brand.',
							'example' => 'Pilot: "AI summarises the account and drafts the follow-up; the rep verifies claims and sends." Measure prep time per call.',
						),
						'finance'   => array(
							'heading' => 'In finance',
							'body'    => 'Good pilot: narrative commentary on figures a human has already verified. Bad pilot: anything touching the calculation, reconciliation, or the numbers in a filing.',
							'example' => 'Pilot: "AI drafts variance commentary from the verified pack." Measure hours saved at close; accuracy stays a human gate.',
						),
						'hr'        => array(
							'heading' => 'In HR & People',
							'body'    => 'Good pilot: drafting job descriptions and summarising anonymised survey themes. Bad pilot: screening or ranking candidates, which is high-risk under emerging regulation and carries real fairness exposure.',
							'example' => 'Pilot: "AI drafts the JD; the hiring manager edits." Never: "AI ranks applicants."',
						),
						'management' => array(
							'heading' => 'In management',
							'body'    => 'Good pilot: turning your notes into structured updates and memos. Bad pilot: performance judgements or anything where accountability must sit visibly with a named person.',
							'example' => 'Pilot: "AI drafts the weekly update from my notes; I edit and own it." Measure minutes per update.',
						),
					),
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which is the strongest sign an AI workflow is a net loss despite good-looking output?',
							'options'  => array(
								'The team enjoys using it',
								'Reviewing and correcting the output takes longer than doing the task manually',
								'It produces long answers',
								'It needs a prompt template',
							),
							'answer'   => 1,
							'hint'     => 'Count the review cost, not just the drafting saved.',
							'feedback_correct'   => 'Exactly — if review costs more than the draft saved, the workflow loses.',
							'feedback_incorrect' => 'Not quite — the decisive signal is review cost exceeding the time saved.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A good design question is "which step is the bottleneck, and is it a drafting problem or a judgement problem?"',
							'answer'   => 0,
							'hint'     => 'Automate the draft, keep the decision.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Point AI at drafting steps and keep accountable ___ with people.',
							'answer_text' => 'judgement',
							'accept'      => array( 'judgment', 'decisions' ),
							'hint'        => 'The part someone must own.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Pick a workflow your team runs in {{role}}. Identify the bottleneck step, classify it as drafting or judgement, and propose a pilot that keeps judgement with a person.',
							'rubric' => 'A strong answer names a real workflow and bottleneck, correctly classifies it, proposes a scoped pilot pointed at a drafting step, and keeps a human accountable for the decision.',
						),
					),
				),

				array(
					'title'   => 'Guardrails people will actually follow',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Guardrails & data policy' ),
					'content' => '<h2>A policy nobody reads is not a control</h2>'
						. '<p>Most AI policies fail the same way: eight pages of legal language that everyone signs and nobody applies at the moment they are pasting something into a chat window. The goal is a rule people can recall <em>while working</em>.</p>'
						. '<h3>Make the data rule concrete</h3>'
						. '<p>"Do not share confidential information" is unusable — everything feels borderline. Give categories and examples instead:</p>'
						. '<ul>'
						. '<li><strong>Never</strong>: customer personal data, credentials, unreleased financials, anything under NDA.</li>'
						. '<li><strong>Only in approved tools</strong>: internal documents, draft strategy, employee information.</li>'
						. '<li><strong>Fine anywhere</strong>: public information, your own writing, generic questions.</li>'
						. '</ul>'
						. '<h3>Tie the rule to the tier</h3>'
						. '<p>Governance should be proportionate to consequence — the principle behind the NIST AI RMF and the EU AI Act\'s risk tiers. Anything affecting a person\'s rights, money, safety, or opportunities needs documentation and human sign-off. Drafting your own emails does not.</p>'
						. '<h3>Say who decides</h3>'
						. '<p>Every guardrail needs a named owner and an escape hatch: who approves a new use case, and who to ask when the rule does not obviously apply. Without that, people either freeze or quietly ignore the policy — and you lose visibility either way.</p>'
						. '<blockquote>One page, concrete categories, a named owner, and a way to ask. That beats eight pages of prose.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Write rules in recallable categories with examples, scale scrutiny to consequence, and name both the owner and the route for questions.</p>',
					'variants' => array(
						'marketing' => array(
							'heading' => 'In marketing',
							'body'    => 'Your sharpest guardrails are about unpublished campaign plans and unverified claims — plus disclosure expectations for AI-generated imagery, where provenance standards like C2PA are becoming the norm.',
							'example' => 'Rule: "Unreleased campaign plans only in approved tools. No published statistic without a cited source."',
						),
						'sales'     => array(
							'heading' => 'In sales',
							'body'    => 'Customer data is the line that matters: names, contact details, contract terms, and pipeline notes about real people do not belong in a consumer account.',
							'example' => 'Rule: "Never paste customer names, contacts, or contract terms. Anonymise before drafting."',
						),
						'finance'   => array(
							'heading' => 'In finance',
							'body'    => 'Unreleased financials are the hard line — material non-public information in a consumer tool is a disclosure problem, not just a policy breach.',
							'example' => 'Rule: "No pre-release figures in any external tool. Post-release and public data only."',
						),
						'hr'        => array(
							'heading' => 'In HR & People',
							'body'    => 'Employee and candidate data is the strictest category you own, and hiring-related uses sit in the high-risk tier — documentation and human sign-off are not optional there.',
							'example' => 'Rule: "No named employee or candidate data, ever. Aggregate and anonymise first; hiring uses need sign-off."',
						),
						'management' => array(
							'heading' => 'In management',
							'body'    => 'You own the guardrail itself: name the approver, publish the one-page rule, and make the escape hatch obvious so people ask instead of guessing.',
							'example' => 'Rule: "New AI use cases affecting people or money go through me before rollout. Ask in #ai-help if unsure."',
						),
					),
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why does "do not share confidential information" fail as a working guardrail?',
							'options'  => array(
								'It is too short to be legally valid',
								'It gives no concrete categories, so everything feels borderline and people guess',
								'Confidentiality is not a real concern with AI',
								'It only applies to executives',
							),
							'answer'   => 1,
							'hint'     => 'Can someone apply it in the moment?',
							'feedback_correct'   => 'Right — without concrete categories and examples it cannot be applied in the moment.',
							'feedback_incorrect' => 'Not quite — the problem is that it is unusably vague at the moment of decision.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Scrutiny should scale with consequence: uses affecting people\'s rights or money need more oversight than drafting your own email.',
							'answer'   => 0,
							'hint'     => 'Proportionate governance.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Every guardrail needs a named ___ who approves new use cases and answers edge cases.',
							'answer_text' => 'owner',
							'accept'      => array( 'approver' ),
							'hint'        => 'Someone accountable for the rule.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Your AI policy says "do not paste confidential data into AI tools", and people are pasting it anyway. Rather than restating the rule, what would you change?',
							'rubric'   => 'A strong answer treats widespread breach as a design problem rather than a discipline problem: it asks what job people were trying to do, and proposes a sanctioned route that is at least as easy as the unsanctioned one — an approved tool, a redaction step, clear examples of what counts as confidential — instead of stronger wording or more training.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Workflow design & guardrails — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which pilot is best suited to AI?',
						'options'  => array(
							'Ranking job applicants automatically',
							'Drafting content a human reviews and owns anyway',
							'Producing final financial figures',
							'Making performance decisions',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'A usable data rule replaces vague wording with concrete ___ and examples.',
						'answer_text' => 'categories',
						'accept'      => array( 'category' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'If reviewing AI output takes longer than doing the task manually, the workflow is a net loss.',
						'answer'   => 0,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Rollout and results',
			'lessons' => array(

				array(
					'title'   => 'Enablement without becoming the help desk',
					'type'    => 'practice',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Team enablement' ),
					'content' => '<h2>Skill does not spread by announcement</h2>'
						. '<p>Sending a link to a prompt guide changes almost nothing. Adoption happens when someone sees the tool solve a problem they personally have, then has something concrete to copy.</p>'
						. '<h3>Start with the volunteers</h3>'
						. '<p>Do not roll out to everyone at once. Find three or four people who are already curious, work with them on their real tasks, and let their results be the evidence. Sceptics move for a colleague\'s result far more than for a mandate.</p>'
						. '<h3>Ship templates, not principles</h3>'
						. '<p>People do not adopt "be specific about your desired output". They adopt <em>"paste your notes here and run this"</em>. Give a small library of parameterised prompts for the five jobs your team actually repeats, each with one line on when to use it and what good output looks like.</p>'
						. '<h3>Make the failures visible too</h3>'
						. '<p>Share what did not work as openly as what did. A team that only hears success stories develops exactly the wrong instinct — over-trust — and then someone ships an invented figure. Normalising "here is where it was confidently wrong" builds the checking habit into the culture.</p>'
						. '<h3>Pick one owner, not a committee</h3>'
						. '<p>One person should own the prompt library, keep it current, and prune what nobody uses. Shared ownership means the library rots quietly.</p>'
						. '<blockquote>Three convinced colleagues with copyable templates beat one all-hands announcement.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Start with volunteers on real tasks, ship copyable templates rather than principles, share failures openly, and give the library a single owner.</p>',
					'variants' => array(
						'marketing' => array(
							'heading' => 'In marketing',
							'body'    => 'Run a working session on one live campaign rather than a training deck. The team leaves with three prompts tied to the brief-to-copy pipeline they already use.',
							'example' => 'Session: "Bring a live brief. We build the outline prompt, the variants prompt, and the QA prompt together."',
						),
						'sales'     => array(
							'heading' => 'In sales',
							'body'    => 'Adoption follows the number. Have two reps use the research-and-follow-up templates for a fortnight, then show prep-time and reply-rate changes at the team meeting.',
							'example' => 'Pilot with 2 reps for 2 weeks; report minutes saved per call and any claim errors caught in review.',
						),
						'finance'   => array(
							'heading' => 'In finance',
							'body'    => 'Enable on the narrative work only, and be explicit that nothing enters the calculation path. Your enablement message is as much about the boundary as the benefit.',
							'example' => 'Template pack: commentary drafts, policy summaries, meeting notes. Explicit: "never for computation or reconciliation."',
						),
						'hr'        => array(
							'heading' => 'In HR & People',
							'body'    => 'You are enabling other departments as much as your own — so pair every template with the data rule it must respect, since HR owns the consequences when it is broken.',
							'example' => 'Every template ships with its data line, e.g. "Anonymise before pasting; no named employees."',
						),
						'management' => array(
							'heading' => 'In management',
							'body'    => 'Your job is to make the space and name the owner: sponsor the pilot, protect the time, and be publicly honest about what did not work so the team can be honest too.',
							'example' => 'Sponsor: "One owner for the prompt library, 2 hours a week protected, monthly review of what to keep or kill."',
						),
					),
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What spreads AI skill through a team most effectively?',
							'options'  => array(
								'A company-wide announcement with a link to a guide',
								'A few volunteers getting real results, plus copyable templates for jobs the team repeats',
								'A mandatory certification',
								'Restricting access until everyone is trained',
							),
							'answer'   => 1,
							'hint'     => 'Evidence from a colleague beats a mandate.',
							'feedback_correct'   => 'Exactly — visible results from peers plus something concrete to copy.',
							'feedback_incorrect' => 'Not quite — adoption follows peer results and copyable templates, not announcements.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Sharing only success stories is the safest way to build team confidence in AI.',
							'answer'   => 1,
							'hint'     => 'What instinct does that build?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A prompt library needs a single named ___ to keep it current and prune what nobody uses.',
							'answer_text' => 'owner',
							'accept'      => array( 'maintainer' ),
							'hint'        => 'Not a committee.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write the one-page starter guide you would hand a new team adopting AI: three tasks worth trying first, two tasks not to use it for, one worked prompt they can copy, and the single rule they must not break.',
							'rubric' => 'A strong answer is genuinely usable on day one — the three tasks are concrete and low-risk, the two exclusions are justified rather than asserted, the worked prompt is complete enough to paste, and the one rule is specific and checkable rather than a general appeal to care.',
							'hint'   => 'One rule, not ten — which single one prevents the most damage?',
						),
					),
				),

				array(
					'title'   => 'Measuring whether it actually helped',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Measuring impact' ),
					'content' => '<h2>Enthusiasm is not evidence</h2>'
						. '<p>"Everyone loves it" is the most common AI success metric and the least useful. To keep a use case — or kill it — you need a number you agreed on <em>before</em> you started.</p>'
						. '<h3>Measure the outcome, not the activity</h3>'
						. '<p>Prompts run, messages sent, licences issued: all activity, none of it value. Measure the thing the work exists to produce — time to a publishable draft, tickets resolved per day, days to close, error rate at review.</p>'
						. '<h3>Take a baseline first</h3>'
						. '<p>Spend one week recording the current number before you change anything. Without a baseline every later comparison is an argument about memory, and memory reliably flatters the new tool.</p>'
						. '<h3>Count the hidden costs</h3>'
						. '<p>Honest measurement includes review time, correction time, and the occasional expensive error. A workflow that halves drafting time but adds a heavy checking burden may be roughly break-even — worth knowing before you scale it.</p>'
						. '<h3>Be willing to retire it</h3>'
						. '<p>Some use cases will not earn their keep. Retiring one is a healthy signal, not a failure, and it protects the credibility of the ones that do work. Review the portfolio on a schedule — monthly is plenty — and keep, adjust, or kill each item.</p>'
						. '<blockquote>Baseline, one outcome metric, honest costs, and a real willingness to stop.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Agree one outcome metric up front, baseline it before changing anything, count review and error costs, and review the portfolio on a schedule with permission to retire.</p>',
					'variants' => array(
						'marketing' => array(
							'heading' => 'In marketing',
							'body'    => 'Measure time from brief to publishable draft and the edit ratio (how much of the AI draft survives). Engagement is too noisy to attribute to the drafting tool.',
							'example' => 'Metric: median hours brief→approved draft, plus % of AI text surviving edit. Baseline for 2 weeks first.',
						),
						'sales'     => array(
							'heading' => 'In sales',
							'body'    => 'Measure prep minutes per call and reply rate on the drafted sequence — and separately track claim errors caught in review, which is your risk metric.',
							'example' => 'Metric: prep minutes/call and reply rate; risk metric: claim corrections per 50 drafts.',
						),
						'finance'   => array(
							'heading' => 'In finance',
							'body'    => 'Measure hours saved at close and, critically, the error rate found in review. If review time rises faster than drafting time falls, you have not gained anything.',
							'example' => 'Metric: hours to complete commentary; guard metric: errors found per pack (target: zero reaching sign-off).',
						),
						'hr'        => array(
							'heading' => 'In HR & People',
							'body'    => 'Measure time-to-post for job descriptions and consistency of scorecard language — and track any fairness complaint or bias flag as a hard stop metric.',
							'example' => 'Metric: days req→posted; guard metric: any bias or inclusivity issue raised in review.',
						),
						'management' => array(
							'heading' => 'In management',
							'body'    => 'Insist on the baseline before rollout and hold the monthly keep/adjust/kill review yourself — otherwise every use case survives on enthusiasm indefinitely.',
							'example' => 'Cadence: baseline week, 6-week pilot, monthly portfolio review with an explicit keep/adjust/kill decision.',
						),
					),
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which is a genuine outcome metric rather than an activity metric?',
							'options'  => array(
								'Number of prompts run this month',
								'Median time from brief to publishable draft',
								'Number of licences purchased',
								'How positive the team feedback was',
							),
							'answer'   => 1,
							'hint'     => 'What does the work exist to produce?',
							'feedback_correct'   => 'Right — it measures the result, not the activity.',
							'feedback_incorrect' => 'Not quite — prompts run, licences, and sentiment are activity or opinion, not outcomes.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Taking a baseline measurement before rollout is optional if the improvement feels obvious.',
							'answer'   => 1,
							'hint'     => 'Memory flatters the new tool.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Honest measurement counts the hidden costs of ___ and correction time, not just the drafting time saved.',
							'answer_text' => 'review',
							'accept'      => array( 'checking', 'verification' ),
							'hint'        => 'The work that happens after the draft.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Pick one AI use case in your team. Name the single outcome metric you would agree up front, the baseline you would take, and the condition under which you would retire it.',
							'rubric'   => 'A good answer names a real use case, gives one measurable outcome (not activity) metric, describes a concrete baseline period, and states a specific retirement condition.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Rollout & measurement — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'What should you do before rolling out an AI workflow?',
						'options'  => array(
							'Buy licences for everyone',
							'Record a baseline of the current outcome metric',
							'Write an eight-page policy',
							'Announce it company-wide',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Retiring a use case that is not earning its keep is a sign of a healthy ___, not a failure.',
						'answer_text' => 'portfolio',
						'accept'      => array( 'process', 'programme', 'program' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Sharing where AI was confidently wrong helps a team keep its verification habit.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'A workflow halves drafting time but doubles review time. What is the right conclusion?',
						'options'  => array(
							'Scale it immediately — drafting is faster',
							'It may be roughly break-even; measure both sides before scaling',
							'Review time never matters',
							'Abandon AI entirely',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'The politics of adoption',
			'lessons' => array(

				array(
					'title'   => 'Resistance is information, not obstruction',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 30,
					'topics'  => array( 'Team enablement', 'Workflow design' ),
					'content' => '<h2>The people pushing back usually know something you do not</h2>'
						. '<p>Every rollout meets resistance, and the standard response is to treat it as a communication problem — more training, better messaging, an enthusiastic email from a senior leader. That works when the objection is unfamiliarity. It fails completely when the objection is correct.</p>'
						. '<h3>Four objections, and what each actually means</h3>'
						. '<p><strong>"It gets things wrong."</strong> Usually true, and usually from someone who tried it on the hardest part of their job. They are not wrong about the tool; they are wrong about where it fits. Show them a task where it genuinely works, not a demo of the task they raised.</p>'
						. '<p><strong>"It will replace us."</strong> Not a technical objection and cannot be answered with technical reassurance. If nobody will lose their job, say so plainly and specifically. If roles genuinely will change, say that instead — people forgive an uncomfortable truth far more readily than a comfortable evasion they later discover was one.</p>'
						. '<p><strong>"I do not have time to learn it."</strong> The most reasonable objection on the list. Someone whose week is already full is being asked to spend hours now for savings later, on a promise. Take work away first, or accept that adoption will be slow among exactly the busiest people — who are often the ones you most wanted to reach.</p>'
						. '<p><strong>"It is not allowed."</strong> Sometimes true and worth knowing. If your policy is genuinely unclear, that is your problem to fix before it is theirs.</p>'
						. '<h3>The quiet resistance that matters more</h3>'
						. '<p>Nobody argues. Everyone agrees in the meeting. Usage stays at nine people. Vocal objection is a gift because it is visible — silent non-adoption tells you the same thing, later, with no diagnosis attached. Watch usage, not sentiment.</p>'
						. '<blockquote>If experienced people are refusing, assume first that they are right about something and find out what.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Sort objections by what they actually are. Answer the "wrong" objection with a better-fitting task, the job-security one honestly, the time one by removing work — and treat silence as the loudest signal on the list.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'An experienced colleague says the tool "gets things wrong". The best first response is:',
							'options'  => array(
								'Explain that all tools have limitations',
								'Ask what they tried, and show a task where it genuinely fits',
								'Send them more training material',
								'Escalate to their manager',
							),
							'answer'   => 1,
							'hint'     => 'They may be right about the task they chose.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Vocal objection is a worse signal than a room that agrees and then does not use the tool.',
							'answer'   => 1,
							'hint'     => 'Which one comes with a diagnosis attached?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Someone saying they have no time to learn it is often best answered by taking ___ away first.',
							'answer_text' => 'work',
							'accept'      => array( 'something', 'tasks' ),
							'hint'        => 'They are being asked to invest hours on a promise.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Your rollout has enthusiastic sign-up and almost no sustained use. What would you investigate, and in what order?',
							'rubric'   => 'A strong answer treats the gap as a diagnosis problem rather than a motivation one: look at who used it once and stopped, ask those people specifically what happened, check whether the suggested tasks actually fit their work, and whether the time cost was real. It should avoid defaulting to more training or more communication.',
						),
					),
				),

				array(
					'title'   => 'Finding and keeping the people who make it spread',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 30,
					'topics'  => array( 'Team enablement' ),
					'content' => '<h2>Adoption spreads sideways, not downward</h2>'
						. '<p>People adopt tools because a colleague they respect showed them something useful — not because leadership announced a programme. Which means your job is less about broadcasting and more about finding the handful of people whose example actually moves others, and making their life easy.</p>'
						. '<h3>Who they are</h3>'
						. '<p>Not necessarily the most technical, and rarely the most enthusiastic. The people who spread things are the ones others already ask for help. They are usually busy, which is exactly why they cannot also be your unpaid support desk.</p>'
						. '<h3>What to give them</h3>'
						. '<ul>'
						. '<li><strong>Early access and real influence</strong> — being consulted before decisions, not informed after.</li>'
						. '<li><strong>Something to show</strong> — one worked example from their own function, so they demonstrate rather than advocate.</li>'
						. '<li><strong>Somewhere to send questions</strong> — so helping does not consume their week.</li>'
						. '<li><strong>Visible credit</strong> — named, publicly. This costs nothing and is the most reliable thing on the list.</li>'
						. '</ul>'
						. '<h3>Share the failures too</h3>'
						. '<p>A channel of nothing but wins is read as marketing and quietly ignored. "I tried this and it wasted an hour, here is why" builds far more credibility than another success story, and it saves everyone else the same hour. Post one yourself, early, so it is clearly allowed.</p>'
						. '<h3>Watch for the burnout you caused</h3>'
						. '<p>If one person is answering everything, your programme has a single point of failure and that person is being punished for helping. Rotate it, document the repeated questions, and notice when someone starts responding more slowly.</p>'
						. '<blockquote>Find the four people others already ask for help. Everything else is amplification of what they do or do not do.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Adoption travels between peers. Equip the people others already trust, give them something to show rather than something to say, make failures publishable, and protect them from becoming the help desk.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'The most useful champions are usually:',
							'options'  => array(
								'The most technically skilled people',
								'The people colleagues already go to for help',
								'The most senior people',
								'The most enthusiastic volunteers',
							),
							'answer'   => 1,
							'hint'     => 'Whose example do people actually follow?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A channel containing only successes reads as ___ and gets ignored.',
							'answer_text' => 'marketing',
							'accept'      => array( 'propaganda', 'advertising' ),
							'hint'        => 'Post a failure yourself, early.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'One person answering all the questions is a healthy sign of engagement.',
							'answer'   => 1,
							'hint'     => 'What happens when they go on holiday, or burn out?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Name three people in your organisation you would equip first — by role, not necessarily by title — and for each say what specific worked example you would build with them and what you would give them in return.',
							'rubric' => 'A strong answer picks people others already turn to rather than the most enthusiastic or senior, names a concrete task from each person\'s own function, and offers something real in return — early influence, visible credit, or protection from becoming the support desk — rather than just asking them to advocate.',
							'hint'   => 'Something to show, not something to say.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'The politics of adoption — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'A team agrees enthusiastically in the meeting and usage stays flat. This tells you:',
						'options'  => array(
							'The message needs repeating',
							'There is a real obstacle nobody said out loud',
							'They need more senior encouragement',
							'The tool is not capable enough',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'If roles genuinely will change, saying so plainly is better than reassurance people later discover was hollow.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Adoption spreads ___ between peers rather than downward from announcements.',
						'answer_text' => 'sideways',
						'accept'      => array( 'laterally', 'horizontally' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Sharing a failure in a team channel is valuable mainly because:',
						'options'  => array(
							'It lowers expectations',
							'It builds credibility and saves others the same wasted hour',
							'It reduces usage',
							'It satisfies compliance',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Governance that survives contact with reality',
			'lessons' => array(

				array(
					'title'   => 'Writing a policy people will actually follow',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 30,
					'topics'  => array( 'Guardrails & data policy', 'Workflow design' ),
					'content' => '<h2>Most AI policies fail the same way</h2>'
						. '<p>They are long, they are written to protect the organisation rather than to help the reader, and they say "do not" without saying "instead". People read them once, conclude that everything interesting is forbidden, and carry on using their personal accounts where nobody can see.</p>'
						. '<p>That last outcome is worse than a permissive policy. A prohibition that drives usage into the shadows removes your visibility along with your control.</p>'
						. '<h3>What a usable policy contains</h3>'
						. '<ul>'
						. '<li><strong>Which tools are approved</strong>, by name. "Use approved tools" with no list is not a policy.</li>'
						. '<li><strong>What must never go in</strong>, with concrete examples from your business rather than abstract categories.</li>'
						. '<li><strong>What to do instead</strong> for each prohibition — the sanctioned route, and it must be genuinely usable.</li>'
						. '<li><strong>Which decisions a human must make</strong>, named explicitly.</li>'
						. '<li><strong>When to disclose AI involvement</strong> — internally and to customers.</li>'
						. '<li><strong>Who to ask</strong> when the policy does not cover something, because it will not cover everything.</li>'
						. '</ul>'
						. '<h3>One page</h3>'
						. '<p>If it does not fit on one page, it will not be read, and an unread policy provides documentation rather than protection. Everything else belongs in an appendix nobody needs on a normal day.</p>'
						. '<h3>Widespread breach is a design signal</h3>'
						. '<p>When most people are ignoring a rule, the useful question is not how to enforce it harder. It is what job they were trying to do, and why the sanctioned route was worse. Fix that and compliance follows; enforce harder and you get better concealment.</p>'
						. '<blockquote>Every prohibition needs a workable alternative attached. Without one you have not banned a behaviour — you have hidden it.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Name the tools, give concrete examples, attach an alternative to every prohibition, keep it to a page, and treat mass non-compliance as feedback about the sanctioned route.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why is a strict prohibition without an alternative often worse than a permissive policy?',
							'options'  => array(
								'It is harder to write',
								'It drives usage into personal accounts where you have no visibility',
								'It costs more to enforce',
								'It slows down approvals',
							),
							'answer'   => 1,
							'hint'     => 'Where does the behaviour go?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Widespread breach of a rule is best treated as an enforcement problem.',
							'answer'   => 1,
							'hint'     => 'What were people trying to do?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A policy longer than one ___ will not be read, and an unread policy is documentation rather than protection.',
							'answer_text' => 'page',
							'accept'      => array( 'side' ),
							'hint'        => 'Keep the rest in an appendix.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write the one-page AI policy for your organisation: approved tools by name, what never goes in with examples from your actual business, the sanctioned alternative for each, decisions reserved for humans, the disclosure rule, and who to ask.',
							'rubric' => 'A strong answer names specific tools, uses concrete examples from a real business rather than abstract categories, attaches a genuinely usable alternative to every prohibition, reserves named decisions for humans, and gives a real contact for uncovered cases. It should fit plausibly on one page and read as help rather than as legal protection.',
							'hint'   => 'Every "do not" needs an "instead".',
						),
					),
				),

				array(
					'title'   => 'When to stop, and how to say so',
					'type'    => 'practice',
					'est_min' => 12,
					'xp'      => 35,
					'topics'  => array( 'Measuring impact', 'Workflow design', 'Guardrails & data policy' ),
					'content' => '<h2>The hardest thing an AI lead does is recommend not doing it</h2>'
						. '<p>You have measured the impact, run the pilots, and built the guardrails. Some of what you tried has not worked, and there is enormous organisational pressure to describe it as a success anyway — budget was spent, expectations were set, and "AI initiative delivers modest results" is a headline nobody wants to write.</p>'
						. '<p>Being the person who says so accurately is the whole basis of being trusted on the things that did work.</p>'
						. '<h3>What "stop" looks like in evidence</h3>'
						. '<ul>'
						. '<li><strong>Time saved is inside the noise.</strong> If you cannot distinguish the improvement from an ordinary good week, it is not there.</li>'
						. '<li><strong>Quality dropped and nobody logged it.</strong> Faster and worse is the most expensive outcome, because it accumulates silently.</li>'
						. '<li><strong>The checking exceeds the doing.</strong> A workflow that needs verifying more carefully than doing it manually is a net loss.</li>'
						. '<li><strong>Only its author uses it.</strong> After a fair run, that is a hobby, not a workflow.</li>'
						. '</ul>'
						. '<h3>How to report it</h3>'
						. '<p>Lead with what you tried and what you learned, not with an apology. Separate the specific failure from the general question — "this workflow did not pay off, for these reasons" is a finding; "AI does not work for us" is a conclusion your evidence does not support. Name what you would need to see for it to be worth revisiting, and say what the money is better spent on now.</p>'
						. '<h3>Retire it properly</h3>'
						. '<p>Turn it off, tell the people who used it, and record why. An abandoned workflow left running is the worst outcome available: nobody maintains it and people still act on its output.</p>'
						. '<h3>Where this leaves you</h3>'
						. '<p>Across four rungs you have gone from asking better questions, to reliable structured output, to chained workflows and tools, to designing how an organisation adopts all of it. The last skill is the one that makes the rest trustworthy: honest reporting, including when the honest report is that this one did not work.</p>'
						. '<blockquote>Your credibility is not built by the projects that worked. It is built the first time you say clearly that one did not.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Know the four stop signals, separate a specific failure from a general verdict, say what would change your mind, and turn the thing off properly.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which is the most expensive failure mode of an AI workflow?',
							'options'  => array(
								'It is slower than expected',
								'It is faster but the quality dropped without anyone recording it',
								'It costs more than budgeted',
								'Only some of the team use it',
							),
							'answer'   => 1,
							'hint'     => 'Which one accumulates silently?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A workflow needing more careful checking than doing the task manually is a net ___.',
							'answer_text' => 'loss',
							'accept'      => array( 'negative' ),
							'hint'        => 'Count the verification time.',
						),
						array(
							'type'     => 'true_false',
							'question' => '"This workflow did not pay off" and "AI does not work for us" are conclusions supported by the same evidence.',
							'answer'   => 1,
							'hint'     => 'One is a finding; the other is a generalisation.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write the short report recommending that a six-month AI pilot be stopped: what was tried, what the evidence showed, what you would need to see to revisit it, and what you propose doing instead.',
							'rubric' => 'A strong answer leads with the finding rather than an apology, cites specific evidence including time and quality, carefully separates this workflow\'s failure from any verdict on AI generally, states a concrete condition that would justify revisiting, and proposes a specific alternative use of the resource. It should also address turning the pilot off and informing its users.',
							'hint'   => 'A finding, not an apology — and not a verdict on AI.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'Having reached the top of this ladder, what is the single change you would make to how your organisation currently approaches AI — and what would make it hard?',
							'rubric'   => 'A thoughtful answer names one specific change and is honest about the obstacle — political, resourcing, or cultural — rather than proposing an ideal state with no acknowledgement of what stands in its way.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Governance and honest reporting — quiz',
				'passing'   => 70,
				'xp'        => 40,
				'questions' => array(
					array(
						'type'        => 'fill_blank',
						'question'    => 'Every prohibition in an AI policy needs a workable ___ attached to it.',
						'answer_text' => 'alternative',
						'accept'      => array( 'instead', 'route' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'An abandoned workflow left running is worse than one that has been switched off and announced.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'If time saved cannot be distinguished from an ordinary good week, the honest reading is:',
						'options'  => array(
							'The measurement needs refining until it shows a gain',
							'The benefit is not there',
							'More training is needed',
							'The pilot should run longer by default',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'An AI lead\'s credibility is established most durably by:',
						'options'  => array(
							'The number of workflows launched',
							'Saying clearly, the first time it happens, that something did not work',
							'Enthusiasm in leadership updates',
							'The size of the budget secured',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
