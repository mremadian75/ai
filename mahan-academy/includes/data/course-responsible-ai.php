<?php
/**
 * Seed course: "Responsible AI & Governance" (Responsible AI bundle).
 *
 * Follows the canonical schema contract defined in course-pe-foundations.php.
 * Grounded in the NIST AI Risk Management Framework, the EU AI Act, the OECD AI
 * Principles, and the UNESCO Recommendation on the Ethics of AI.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'responsible-ai',
	'title'       => 'Responsible AI & Governance',
	'slug'        => 'responsible-ai-governance',
	'subtitle'    => 'Use AI in ways you can defend — risk, fairness, transparency, and the rules that now apply.',
	'excerpt'     => 'A practical grounding in responsible AI, built on the NIST AI Risk Management Framework, the EU AI Act, and the OECD and UNESCO principles.',
	'description' => '<p>AI is no longer a free-for-all. Regulators, customers, and your own colleagues increasingly expect you to explain <strong>why</strong> an AI-assisted decision was made, what could go wrong, and who is accountable when it does.</p>'
		. '<p>This course turns the major frameworks — the <strong>NIST AI Risk Management Framework</strong>, the <strong>EU AI Act</strong>, and the <strong>OECD</strong> and <strong>UNESCO</strong> principles — into things you can actually do in your work: spot the risks in a use case, judge how sensitive it is, and put simple guardrails in place. No legal background required.</p>',
	'category'    => 'Responsible AI',
	'level'       => 'beginner',
	'est_hours'   => 3,
	'featured'    => true,
	'certificate' => true,
	'order'       => 1,
	'topics'      => array( 'AI risk', 'Fairness & bias', 'Transparency', 'Governance & compliance' ),
	'outcomes'    => array(
		'Explain what "responsible AI" means in practical, everyday terms',
		'Identify the main risks in an AI use case before you ship it',
		'Recognise where bias enters a system and how to reduce it',
		'Apply transparency practices such as disclosure and human oversight',
		'Judge how heavily a use case should be governed, using risk tiers',
	),
	'references'  => array(
		array(
			'title'  => 'AI Risk Management Framework (AI RMF 1.0)',
			'source' => 'NIST, 2023',
			'url'    => 'https://www.nist.gov/itl/ai-risk-management-framework',
		),
		array(
			'title'  => 'EU Artificial Intelligence Act (Regulation (EU) 2024/1689)',
			'source' => 'European Union',
			'url'    => 'https://artificialintelligenceact.eu/',
		),
		array(
			'title'  => 'OECD AI Principles',
			'source' => 'OECD',
			'url'    => 'https://oecd.ai/en/ai-principles',
		),
		array(
			'title'  => 'Recommendation on the Ethics of Artificial Intelligence',
			'source' => 'UNESCO, 2021',
			'url'    => 'https://www.unesco.org/en/artificial-intelligence/recommendation-ethics',
		),
		array(
			'title'  => 'Model Cards for Model Reporting',
			'source' => 'Mitchell et al., 2019 — arXiv:1810.03993',
			'url'    => 'https://arxiv.org/abs/1810.03993',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'What responsible AI actually means',
			'lessons' => array(

				array(
					'title'   => 'From principles to practice',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'AI risk' ),
					'content' => '<h2>Everyone agrees on the principles. The work is in applying them.</h2>'
						. '<p>Read enough AI policy documents and the same handful of ideas keep appearing: AI should be <strong>safe</strong>, <strong>fair</strong>, <strong>transparent</strong>, <strong>accountable</strong>, and respectful of <strong>privacy</strong>. The OECD AI Principles say it. The UNESCO Recommendation on the Ethics of AI says it. Company AI policies say it.</p>'
						. '<p>The gap is that principles do not tell you what to do on a Tuesday afternoon when you are about to paste a customer list into a chatbot. That is what frameworks are for.</p>'
						. '<h3>The NIST framing: govern, map, measure, manage</h3>'
						. '<p>The <strong>NIST AI Risk Management Framework</strong> is the most usable starting point. It organises the work into four functions:</p>'
						. '<ul>'
						. '<li><strong>Govern</strong> — who is accountable, and what are the rules here?</li>'
						. '<li><strong>Map</strong> — what is this system actually for, who does it affect, and what could go wrong?</li>'
						. '<li><strong>Measure</strong> — how will we test whether it works and whether it is harming anyone?</li>'
						. '<li><strong>Manage</strong> — what do we do about the risks we found, and who watches it over time?</li>'
						. '</ul>'
						. '<p>You do not need to be a compliance officer to use this. Working in {{role}}, you can run all four in ten minutes on a whiteboard before adopting a new AI workflow.</p>'
						. '<h3>A concrete example</h3>'
						. '<p>Suppose you want AI to draft replies to customer complaints. <em>Map:</em> it affects real customers, some of them upset. <em>Measure:</em> sample fifty drafts and check tone and factual accuracy. <em>Manage:</em> a human approves every reply before it sends. <em>Govern:</em> the support lead owns the process. That is responsible AI — not a philosophy seminar.</p>'
						. '<h3>Recap</h3>'
						. '<p>Principles set the direction; frameworks make them operational. Govern, map, measure, manage is a checklist you can run on any use case, including small ones.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which set names the four functions of the NIST AI Risk Management Framework?',
							'options'  => array(
								'Plan, build, test, launch',
								'Govern, map, measure, manage',
								'Collect, clean, train, deploy',
								'Detect, prevent, respond, recover',
							),
							'answer'   => 1,
							'hint'     => 'One of them is about knowing what the system is for and who it affects.',
							'feedback_correct'   => 'Correct — govern, map, measure, manage.',
							'feedback_incorrect' => 'Not quite. NIST organises AI risk work as govern, map, measure, and manage.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'High-level principles alone are usually enough to tell a team exactly what to do in a specific AI use case.',
							'answer'   => 1,
							'hint'     => 'Think about the gap between "AI should be fair" and a Tuesday-afternoon decision.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'In the NIST framework, working out what a system is for, who it affects, and what could go wrong is the ___ function.',
							'answer_text' => 'map',
							'accept'      => array( 'mapping' ),
							'hint'        => 'It comes right after "govern".',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Pick one AI use case from your own work. Write a short govern/map/measure/manage plan for it: who owns it, who it affects, how you would test it, and what guardrail you would put in place.',
							'rubric' => 'A strong answer names an owner, identifies the people affected, proposes a concrete test or check, and states at least one guardrail (e.g. human review, limited data, disclosure).',
						),
					),
				),

				array(
					'title'   => 'Bias: where it comes from and what to do',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'Fairness & bias' ),
					'content' => '<h2>Bias is not usually malice — it is inherited</h2>'
						. '<p>An AI system learns from data produced by people and institutions. If the past was skewed, the model learns the skew. This is why fairness is treated as a first-class risk in every major framework, not an afterthought.</p>'
						. '<h3>Three places bias enters</h3>'
						. '<ul>'
						. '<li><strong>The data.</strong> If a hiring dataset mostly contains people who were hired under a biased process, a model trained on it will reproduce that pattern.</li>'
						. '<li><strong>The framing.</strong> Choosing to predict "who was promoted" instead of "who performed well" bakes an assumption into the whole system.</li>'
						. '<li><strong>The use.</strong> A tool that works fine for one group can be deployed in a context it was never validated for.</li>'
						. '</ul>'
						. '<blockquote>A model does not know what is fair. It only knows what was common in its training data.</blockquote>'
						. '<h3>What actually helps</h3>'
						. '<p>You will not eliminate bias, but you can reduce and detect it. Practical moves: check whether your data represents the people the system will affect; <strong>measure results separately for different groups</strong> instead of only looking at one overall accuracy number; document known limitations (the idea behind <em>model cards</em>); and keep a human in the loop for consequential decisions about people — hiring, credit, discipline, access to services.</p>'
						. '<h3>Recap</h3>'
						. '<p>Bias enters through data, framing, and use. Representative data, group-by-group measurement, honest documentation, and human oversight on high-stakes calls are the practical defences.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A recruiting model is trained on ten years of past hiring decisions. What is the main fairness risk?',
							'options'  => array(
								'The model will run too slowly on old data',
								'It learns and reproduces the biases in past hiring decisions',
								'It cannot process text résumés',
								'Older data is always more accurate',
							),
							'answer'   => 1,
							'hint'     => 'What does the model treat as "correct" here?',
							'feedback_correct'   => 'Exactly — the historical decisions become the target it learns to imitate.',
							'feedback_incorrect' => 'Not quite — the risk is that past biased decisions become the pattern the model learns.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A single overall accuracy score is enough to show that a model treats different groups fairly.',
							'answer'   => 1,
							'hint'     => 'One number can hide very different results per group.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'For consequential decisions about people, keep a ___ in the loop rather than letting the system decide alone.',
							'answer_text' => 'human',
							'accept'      => array( 'person', 'human reviewer' ),
							'hint'        => 'Oversight by someone accountable.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Name one place bias could enter an AI system you might use in your work, and one concrete step you would take to check for it.',
							'rubric'   => 'A good answer identifies a specific entry point (data, framing, or deployment context) and a concrete check, such as comparing outcomes across groups or reviewing whether the data represents the affected population.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Responsible AI foundations — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which is the best description of the purpose of an AI risk framework?',
						'options'  => array(
							'To ban risky uses of AI outright',
							'To turn broad principles into repeatable steps a team can actually follow',
							'To make models run faster',
							'To replace human decision-makers',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Bias most often enters an AI system through the ___ it learns from.',
						'answer_text' => 'data',
						'accept'      => array( 'training data' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Documenting a model\'s known limitations (as in a "model card") is a recognised transparency practice.',
						'answer'   => 0,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Transparency, risk tiers, and governance',
			'lessons' => array(

				array(
					'title'   => 'Transparency and human oversight',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 25,
					'topics'  => array( 'Transparency' ),
					'content' => '<h2>People should know when AI is involved</h2>'
						. '<p>Transparency runs through every major framework, and it is one of the easiest things to get right. It has three practical layers.</p>'
						. '<h3>1. Disclosure</h3>'
						. '<p>Tell people when they are interacting with an AI system rather than a person, and when content was AI-generated. The EU AI Act makes this explicit for chatbots and synthetic media; increasingly it is also just what customers expect. For images and video, provenance standards such as <strong>Content Credentials (C2PA)</strong> attach a verifiable record to the file itself.</p>'
						. '<h3>2. Explanation</h3>'
						. '<p>Be able to say, in plain language, what the system does, what data it uses, and what its known weaknesses are. That is the idea behind <em>model cards</em> — a short, honest summary of intended use and limitations.</p>'
						. '<h3>3. Oversight</h3>'
						. '<p>A named human should be able to review, override, and switch the system off. "The AI decided" is never an acceptable explanation to a customer or a regulator — accountability stays with people.</p>'
						. '<blockquote>If you would be uncomfortable telling the affected person how the system works, that discomfort is the finding.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Disclose that AI is involved, be able to explain what it does and where it fails, and keep a named human able to override it. These three moves cover most of what transparency requires in practice.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A customer asks why an AI-assisted system rejected their application. Which response best reflects responsible practice?',
							'options'  => array(
								'"The AI decided, so we cannot explain it."',
								'Explain in plain language what the system considers, and have a named person review the decision',
								'Refuse to discuss any automated processing',
								'Send them the model\'s raw parameters',
							),
							'answer'   => 1,
							'hint'     => 'Accountability stays with people.',
							'feedback_correct'   => 'Right — plain-language explanation plus a human who can review and override.',
							'feedback_incorrect' => 'Not quite — "the AI decided" is never an acceptable explanation; a person must be accountable.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Telling users they are interacting with an AI system rather than a human is a recognised transparency obligation.',
							'answer'   => 0,
							'hint'     => 'The EU AI Act is explicit about chatbots and synthetic media.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A short, honest summary of a model\'s intended use and known limitations is commonly called a model ___.',
							'answer_text' => 'card',
							'accept'      => array( 'cards' ),
							'hint'        => 'Mitchell et al., 2019.',
						),
					),
				),

				array(
					'title'   => 'Risk tiers: how much governance is enough?',
					'type'    => 'practice',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Governance & compliance', 'AI risk' ),
					'content' => '<h2>Not every use case deserves the same scrutiny</h2>'
						. '<p>Treating an AI meeting-notes summariser with the same ceremony as an AI credit-scoring system wastes everyone\'s time — and treating them the same in the other direction is dangerous. The insight behind the <strong>EU AI Act</strong> is that governance should scale with <strong>risk</strong>.</p>'
						. '<h3>The tiering idea</h3>'
						. '<ul>'
						. '<li><strong>Unacceptable</strong> — a small set of uses that are simply prohibited (for example, social scoring by public authorities).</li>'
						. '<li><strong>High risk</strong> — uses affecting people\'s rights and life chances: employment, credit, education, essential services, safety components. These carry the heaviest obligations: risk management, data governance, documentation, human oversight.</li>'
						. '<li><strong>Limited risk</strong> — mainly transparency duties, such as telling people they are talking to a chatbot or labelling synthetic media.</li>'
						. '<li><strong>Minimal risk</strong> — most everyday productivity uses; ordinary good practice is enough.</li>'
						. '</ul>'
						. '<h3>Applying it to your own work</h3>'
						. '<p>Ask one question first: <em>does this affect a person\'s rights, money, safety, or opportunities?</em> If yes, treat it as high-stakes — documentation, testing, and human sign-off. If it is drafting your own emails or summarising your own notes toward {{primary_goal}}, keep it light and just review the output.</p>'
						. '<blockquote>Match the weight of your process to the weight of the consequences.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Governance should be proportionate. Sort a use case by its impact on people, then apply the level of testing, documentation, and oversight that impact deserves.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Under a risk-tiered approach, which use case warrants the heaviest governance?',
							'options'  => array(
								'Summarising your own meeting notes',
								'Drafting an internal newsletter',
								'Screening job applicants for hiring decisions',
								'Generating ideas for a team offsite',
							),
							'answer'   => 2,
							'hint'     => 'Which one changes a person\'s life chances?',
							'feedback_correct'   => 'Correct — employment decisions affect people\'s rights and opportunities, so they sit in the high-risk tier.',
							'feedback_incorrect' => 'Not quite — the heaviest obligations attach to uses that affect people\'s rights and opportunities, such as hiring.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A risk-tiered approach applies exactly the same obligations to every AI use case.',
							'answer'   => 1,
							'hint'     => 'The whole point is proportionality.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Governance effort should be ___ to the risk a use case creates for people.',
							'answer_text' => 'proportionate',
							'accept'      => array( 'proportional', 'matched' ),
							'hint'        => 'Match the weight of the process to the weight of the consequences.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Take an AI use case you are considering with {{daily_tools}}. Decide which risk tier it belongs in, justify the choice in one sentence, and list the two controls you would put in place.',
							'rubric' => 'A strong answer assigns a tier, justifies it by the impact on people (rights, money, safety, opportunities), and names two proportionate controls such as human sign-off, disclosure, testing, or limiting the data used.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Transparency & governance — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which pair best captures the practical core of transparency?',
						'options'  => array(
							'Publishing your source code and your prices',
							'Disclosing that AI is involved, and being able to explain what it does and where it fails',
							'Keeping the system confidential and logging everything',
							'Using the largest available model and citing it',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Under a risk-tiered approach, a small set of AI uses is prohibited outright.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Uses affecting employment, credit, or essential services fall into the ___ risk tier.',
						'answer_text' => 'high',
						'accept'      => array( 'high-risk' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'You deploy an AI chatbot for customer questions. What is the minimum transparency step?',
						'options'  => array(
							'Publish the model weights',
							'Tell users they are interacting with an AI system',
							'Disable the chatbot at night',
							'Ask users to sign a contract',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'From policy to practice',
			'lessons' => array(

				array(
					'title'   => 'Who is accountable when the system is wrong',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Governance & compliance', 'Transparency' ),
					'content' => '<h2>"The system decided" is not an answer anyone accepts</h2>'
						. '<p>An AI system produces a wrong outcome that affects someone. The question that follows is never technical. It is: who is answerable, and what can the affected person do about it?</p>'
						. '<p>Organisations that cannot answer that in advance discover, at the worst possible moment, that everyone assumed someone else was.</p>'
						. '<h3>The four roles worth naming</h3>'
						. '<p><strong>The decision owner</strong> — the person accountable for the outcome, who would have been accountable if a human had made the same call. Deploying a model does not transfer this to anyone; it just makes it easier to forget.</p>'
						. '<p><strong>The system owner</strong> — accountable for the thing working as described: monitored, reviewed, retired when it should be.</p>'
						. '<p><strong>The reviewer</strong> — where a human is in the loop, whoever that is, with enough information and enough time to genuinely exercise judgement.</p>'
						. '<p><strong>The route for the affected person</strong> — how they find out a system was involved, how they contest the outcome, and who reconsiders it.</p>'
						. '<h3>The failure mode: oversight in name only</h3>'
						. '<p>A reviewer given four hundred cases a day, no context, and a target that punishes disagreement is not oversight. They are a rubber stamp providing legal cover, and everyone in the chain knows it. Genuine human review needs time, information, and a culture where overriding the system is normal rather than career-limiting.</p>'
						. '<blockquote>If nobody can be named, nobody is accountable. Write the names down before deployment, not after the incident.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Name the decision owner, the system owner, the reviewer and the appeal route before launch — and check that the reviewer can actually review rather than rubber-stamp.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Deploying a model to make a decision transfers accountability to:',
							'options'  => array(
								'The model vendor',
								'Nobody — it stays with whoever was accountable for the decision before',
								'The data science team',
								'The end user',
							),
							'answer'   => 1,
							'hint'     => 'Who was answerable when a human made this call?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A reviewer handling 400 cases a day with no context provides meaningful human oversight.',
							'answer'   => 1,
							'hint'     => 'What do they actually have time to do?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'An affected person needs a route to ___ the outcome, and someone who will reconsider it.',
							'answer_text' => 'contest',
							'accept'      => array( 'appeal', 'challenge', 'dispute' ),
							'hint'        => 'The fourth role.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'For an AI system your organisation uses or might use, name the four roles. Where you cannot name a person, say what that tells you.',
							'rubric'   => 'A strong answer attempts real names or roles rather than departments, and treats a gap as a finding — an unnamed decision owner or a missing appeal route is a deployment risk to fix before launch, not a formality.',
						),
					),
				),

				array(
					'title'   => 'Assessing a system before you buy it',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'AI risk', 'Governance & compliance' ),
					'content' => '<h2>Most AI in an organisation arrives through procurement</h2>'
						. '<p>Far more AI enters a business inside purchased software than gets built in-house. It arrives as a feature in an HR platform, a scoring tool in a CRM, an assistant bolted onto a helpdesk — and it usually arrives without anyone asking the questions they would ask of a system built internally.</p>'
						. '<h3>Seven questions for any vendor</h3>'
						. '<ol>'
						. '<li><strong>What exactly does the model decide or recommend, and what happens to that output?</strong> A recommendation nobody ever overrides is a decision.</li>'
						. '<li><strong>What data was it trained on, and does it resemble our population?</strong> A model validated elsewhere may behave very differently here.</li>'
						. '<li><strong>How does it perform by group?</strong> Ask for the breakdown, not the headline. Reluctance here is itself informative.</li>'
						. '<li><strong>What explanation does an affected person get?</strong> "Proprietary" is a business answer, not a legal one in several jurisdictions.</li>'
						. '<li><strong>What happens to our data?</strong> Is it used to train their models, where is it stored, who can access it.</li>'
						. '<li><strong>How do we know when it degrades?</strong> Monitoring, alerting, and what we would see.</li>'
						. '<li><strong>How do we turn it off?</strong> What the fallback is, and whether we could still operate.</li>'
						. '</ol>'
						. '<h3>The answers you should worry about</h3>'
						. '<p>"Our accuracy is 95%" with no baseline and no breakdown. "The algorithm is proprietary" in response to a question about explanation rather than internals. "It learns from your data" with no detail about what that means for confidentiality. None of these are refusals exactly, which is what makes them easy to accept.</p>'
						. '<blockquote>Buying a system does not outsource the accountability. You will answer for its decisions, so ask the questions before signing rather than after.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Procurement is where most AI risk enters. Ask about scope, training data, group performance, explanation, data handling, degradation and exit — and treat evasive answers as answers.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A vendor answers "the algorithm is proprietary" when asked what explanation an affected person receives. This is:',
							'options'  => array(
								'A complete answer',
								'A business answer to a question that in several jurisdictions has a legal dimension',
								'Irrelevant to procurement',
								'A reason to pay more',
							),
							'answer'   => 1,
							'hint'     => 'The question was about the affected person, not the internals.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A recommendation that nobody ever overrides is, in practice, a ___.',
							'answer_text' => 'decision',
							'accept'      => array( 'determination' ),
							'hint'        => 'Regardless of what it is called.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Buying an AI system from a vendor transfers accountability for its decisions to that vendor.',
							'answer'   => 1,
							'hint'     => 'Who answers to the affected person?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write the AI section of a vendor questionnaire for a tool that will screen or score people in some way. Include the questions and, for three of them, the answer that would stop the purchase.',
							'rubric' => 'A strong answer covers scope, training data and its representativeness, performance by group, explanation to affected people, data handling, monitoring and exit. The three disqualifying answers should be specific and defensible — refusal to provide a group breakdown, using customer data to train shared models without consent, or no way to contest an outcome.',
							'hint'   => 'Decide the deal-breakers before you are attached to the tool.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'From policy to practice — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'        => 'fill_blank',
						'question'    => 'Genuine human oversight needs time, information, and a culture where ___ the system is normal.',
						'answer_text' => 'overriding',
						'accept'      => array( 'disagreeing with', 'challenging' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Most AI risk in a typical organisation arrives through purchased software rather than in-house builds.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which vendor answer should most increase your concern?',
						'options'  => array(
							'Detailed performance broken down by group',
							'"Our accuracy is 95%" with no baseline and no breakdown',
							'A documented monitoring plan',
							'A clear data-retention policy',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'The four roles to name before deployment are:',
						'options'  => array(
							'Developer, tester, manager, user',
							'Decision owner, system owner, reviewer, and the affected person\'s appeal route',
							'Vendor, buyer, auditor, regulator',
							'Analyst, engineer, lawyer, executive',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Responsible AI as everyday practice',
			'lessons' => array(

				array(
					'title'   => 'Incidents: what to do when it goes wrong',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'AI risk', 'Governance & compliance' ),
					'content' => '<h2>Assume it will, and decide now what happens</h2>'
						. '<p>AI incidents are not exotic. A chatbot tells a customer something untrue about their rights. A scoring tool is found to work poorly for one group. A summarisation tool omits a safety warning. Generated content is published containing invented facts.</p>'
						. '<p>Each of these has happened somewhere, and the organisations that handled them well were the ones that had decided the response before they needed it.</p>'
						. '<h3>The five steps</h3>'
						. '<ol>'
						. '<li><strong>Stop the harm.</strong> Turn it off or fall back to the manual process. This should be possible in minutes, and someone should have tested that it is.</li>'
						. '<li><strong>Find the scope.</strong> How many people, over what period. This is where good logging earns everything it cost — and where its absence turns a contained incident into an unbounded one.</li>'
						. '<li><strong>Tell the affected people.</strong> Earlier and more plainly than is comfortable. The reputational damage from a disclosed error is almost always smaller than from a concealed one that surfaces later.</li>'
						. '<li><strong>Fix the cause, not the instance.</strong> One wrong answer patched is one wrong answer. Ask what class of input produces it.</li>'
						. '<li><strong>Record it.</strong> What happened, why, what changed. This becomes the test case that stops it recurring.</li>'
						. '</ol>'
						. '<h3>The thing that makes all of this possible</h3>'
						. '<p>Logs. Without a record of what the system was given and what it produced, you cannot establish scope, cannot tell the affected people anything useful, and cannot prove the fix worked. Decide what you log — within your privacy obligations — while building, because you cannot log the past.</p>'
						. '<blockquote>The measure of a responsible deployment is not that nothing goes wrong. It is how quickly you can tell who was affected and stop it.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Stop, scope, tell, fix the class of problem, record. All of it depends on logging you set up beforehand.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which capability most determines how well an AI incident is handled?',
							'options'  => array(
								'The size of the engineering team',
								'Logs that establish who was affected and how',
								'The model\'s accuracy',
								'The vendor relationship',
							),
							'answer'   => 1,
							'hint'     => 'What lets you answer "how many people?"',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Concealing a contained AI error usually costs less reputationally than disclosing it.',
							'answer'   => 1,
							'hint'     => 'What happens when it surfaces later?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Fix the ___ of input that causes the error, not just the single instance reported.',
							'answer_text' => 'class',
							'accept'      => array( 'category', 'type' ),
							'hint'        => 'One patched answer is one answer.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Your customer-facing assistant has been giving incorrect refund information for an unknown period. Walk through your first hour.',
							'rubric'   => 'A strong answer disables the assistant or falls back immediately rather than investigating first, then uses logs to establish scope and identify affected customers, initiates proactive contact, and only then investigates the cause. It should acknowledge explicitly what happens if the logs do not exist.',
						),
					),
				),

				array(
					'title'   => 'The questions worth asking every time',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 30,
					'topics'  => array( 'Fairness & bias', 'Transparency', 'AI risk', 'Governance & compliance' ),
					'content' => '<h2>Responsible AI is a habit, not a document</h2>'
						. '<p>Most organisations write principles and then struggle to connect them to Tuesday. The connection is a short set of questions asked routinely — in the meeting where someone proposes a tool, not in an annual review.</p>'
						. '<h3>The six</h3>'
						. '<ol>'
						. '<li><strong>Who is affected by this, and were they asked?</strong> The people a system acts on are rarely in the room where it is chosen.</li>'
						. '<li><strong>What does it get wrong, and who bears that?</strong> Every system has an error profile. Someone experiences it, and it is usually not the person deploying it.</li>'
						. '<li><strong>Does it work equally well for everyone it touches?</strong> If you cannot answer, that is the finding.</li>'
						. '<li><strong>Can an affected person find out and object?</strong> If not, you have automated something unaccountable.</li>'
						. '<li><strong>Who is answerable?</strong> By name.</li>'
						. '<li><strong>How would we know if it stopped working?</strong> Silent degradation is the normal failure mode.</li>'
						. '</ol>'
						. '<h3>Ask them early, when they are cheap</h3>'
						. '<p>Raised at the proposal stage, these are a fifteen-minute conversation. Raised after launch, each one is a project. The cost of asking rises steeply with time, which is exactly why they get skipped in the rush and become expensive later.</p>'
						. '<h3>Being the person who asks</h3>'
						. '<p>You will occasionally be the only one raising them, and it can feel like obstruction. It is not — these are the questions the organisation will be asked eventually, by a regulator, a journalist, or a customer with a lawyer. Asking early is cheaper for everyone, and being consistently right about that is how the role becomes valued rather than tolerated.</p>'
						. '<blockquote>Responsible AI is not a brake on doing things. It is the difference between doing them once and doing them again after an incident.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>You know what the principles mean, where bias comes from, what transparency and oversight require, how risk tiers work, who must be accountable, how to assess a vendor, and what to do in an incident. The six questions are how you use all of it, routinely.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why ask the responsible-AI questions at the proposal stage?',
							'options'  => array(
								'It is required by law everywhere',
								'They cost fifteen minutes then and become projects after launch',
								'It delays projects usefully',
								'Vendors expect it',
							),
							'answer'   => 1,
							'hint'     => 'How does the cost change with time?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'If you cannot answer whether a system works equally well for everyone it touches, that inability is itself the ___.',
							'answer_text' => 'finding',
							'accept'      => array( 'answer', 'problem' ),
							'hint'        => 'Not knowing is information.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'The people a system acts on are usually present when it is chosen.',
							'answer'   => 1,
							'hint'     => 'Who is in that meeting?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Apply the six questions to one AI system your organisation actually uses. Answer each honestly, and mark the ones you cannot answer.',
							'rubric' => 'A strong answer works through all six on a real system, gives specific answers rather than reassurances, and is willing to mark unanswerable ones — which is the point of the exercise. It should identify at least one concrete action arising from a gap.',
							'hint'   => 'The ones you cannot answer are the valuable output.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'Which of the six questions would be hardest to raise in your organisation, and what would make it easier?',
							'rubric'   => 'A thoughtful answer names a specific question and an honest obstacle — commercial pressure, hierarchy, or lack of a forum — and proposes something concrete rather than concluding that culture should change.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Everyday practice — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'The first step in an AI incident is to:',
						'options'  => array(
							'Investigate the root cause',
							'Stop the harm — disable it or fall back',
							'Draft a public statement',
							'Contact the vendor',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'You cannot log the past, so what to log must be decided while building.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Every system has an error profile, and the person who ___ it is rarely the person deploying it.',
						'answer_text' => 'bears',
						'accept'      => array( 'experiences', 'suffers' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'A system nobody affected can find out about or object to is:',
						'options'  => array(
							'Efficient',
							'Automated and unaccountable',
							'Compliant by default',
							'Low risk',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
