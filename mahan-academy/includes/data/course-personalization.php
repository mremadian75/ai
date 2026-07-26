<?php
/**
 * Seed course: "Personalizing AI: Make It Work Like You Do" (AI at Work).
 *
 * Follows the canonical schema contract defined in course-pe-foundations.php.
 *
 * The subject is the one the rest of the catalog assumes and never teaches:
 * *why* an AI assistant can feel like it knows you, what is actually doing the
 * work (context, instructions, memory, examples, feedback), and how to make it
 * happen deliberately instead of hoping for it. Grounded in the published
 * behaviour of the major assistants, the in-context-learning result that makes
 * examples work at all, retrieval-augmented generation, and the privacy rules
 * that bound how much of yourself it is wise to hand over.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'ai-personalization',
	'title'       => 'Personalizing AI: Make It Work Like You Do',
	'slug'        => 'personalizing-ai',
	'subtitle'    => 'Stop getting generic answers. Learn the four levers that make an AI assistant work in your role, your voice, and your context.',
	'excerpt'     => 'Why AI answers feel generic, and the four levers — instructions, context, memory, and examples — that make an assistant work the way you do. With a method for checking that it actually helped.',
	'description' => '<p>Two people ask the same assistant the same question and get the same flat answer. One of them then spends ten minutes setting it up properly and never gets a generic answer again. The difference is not a better prompt — it is <strong>personalization</strong>, and it is a skill with four specific levers.</p>'
		. '<p>This course takes personalization apart. You will learn what an assistant actually knows about you (far less than it seems), how to tell it who you are in a way that survives every new chat, how memory and projects and uploaded documents change what it can do, and how to teach it your voice by showing rather than telling.</p>'
		. '<p>Then the part almost everyone skips: <strong>checking whether any of it worked</strong>. You will build a small personal test set, compare answers with and without your setup, and learn to spot the two failure modes personalization creates — stale context that quietly misleads, and an assistant so tuned to you that it stops disagreeing with you.</p>',
	'category'    => 'AI at Work',
	'level'       => 'beginner',
	'est_hours'   => 3,
	'featured'    => true,
	'certificate' => true,
	'order'       => 2,
	'topics'      => array( 'Personalization', 'Context & memory', 'Grounding', 'Evaluation' ),
	'outcomes'    => array(
		'Explain why an AI assistant produces generic answers by default',
		'Use the four levers of personalization — instructions, context, memory, and examples',
		'Write a durable profile of yourself that improves every future conversation',
		'Ground an assistant in your own documents instead of its general knowledge',
		'Teach an assistant your voice by showing examples rather than describing them',
		'Test whether your personalization actually improved the answers',
		'Recognise the limits: what not to share, and when personalization starts to mislead you',
	),
	'references'  => array(
		array(
			'title'  => 'Language Models are Few-Shot Learners',
			'source' => 'Brown et al., 2020 — arXiv:2005.14165',
			'url'    => 'https://arxiv.org/abs/2005.14165',
		),
		array(
			'title'  => 'Retrieval-Augmented Generation for Knowledge-Intensive NLP Tasks',
			'source' => 'Lewis et al., 2020 — arXiv:2005.11401',
			'url'    => 'https://arxiv.org/abs/2005.11401',
		),
		array(
			'title'  => 'Memory and new controls for ChatGPT',
			'source' => 'OpenAI, 2024',
			'url'    => 'https://openai.com/index/memory-and-new-controls-for-chatgpt/',
		),
		array(
			'title'  => 'Projects: organise your work with Claude',
			'source' => 'Anthropic, 2024',
			'url'    => 'https://www.anthropic.com/news/projects',
		),
		array(
			'title'  => 'GDPR Article 5 — Principles relating to processing of personal data',
			'source' => 'Regulation (EU) 2016/679',
			'url'    => 'https://gdpr-info.eu/art-5-gdpr/',
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
			'title'   => 'Why the answers feel generic',
			'lessons' => array(

				array(
					'title'   => 'The assistant does not know you',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'Personalization', 'Context & memory' ),
					'content' => '<h2>It is not being lazy. It genuinely has nothing to go on.</h2>'
						. '<p>Ask an assistant "how should I open this email?" and you get advice that would suit anyone on earth. It feels like the model is holding back. It is not. When you opened that chat, the model received your sentence and almost nothing else — no job title, no company, no history, no idea whether you are writing to a regulator or a friend.</p>'
						. '<p>A language model has <strong>no persistent knowledge of you</strong>. What it knows in any given moment is exactly what is inside its <strong>context window</strong>: the block of text handed to it for this request. Your message goes in there. So does the system prompt, any file you attached, and any earlier turns of the conversation. Nothing else exists.</p>'
						. '<blockquote>Personalization is not the model learning about you. It is you putting the right things into the context window, reliably, without retyping them.</blockquote>'
						. '<h3>Why this reframing matters</h3>'
						. '<p>Once you see personalization as "what goes in the context", the whole problem becomes concrete and solvable. You stop asking "how do I make it understand me?" — a question with no handle on it — and start asking "what does it need to know, and where do I put that so it arrives every time?"</p>'
						. '<p>That question has four answers, and they are the four levers of this course:</p>'
						. '<ul>'
						. '<li><strong>Instructions</strong> — a standing description of who you are and how you want to be answered.</li>'
						. '<li><strong>Context</strong> — your own material: documents, data, past work.</li>'
						. '<li><strong>Memory</strong> — facts the tool carries between conversations on your behalf.</li>'
						. '<li><strong>Examples</strong> — samples of the output you actually want.</li>'
						. '</ul>'
						. '<h3>The everyday cost of skipping it</h3>'
						. '<p>Working in {{role}}, you probably re-explain your situation at the start of most chats — your team, your constraints, your audience. That re-explaining is a tax you pay per conversation. Personalization is how you pay it once.</p>'
						. '<h3>Recap</h3>'
						. '<p>A generic answer is a symptom of an empty context, not a weak model. Everything the assistant knows about you in a given moment was put there — by you, or by a feature acting for you.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why does an assistant give a generic answer to "how should I open this email?"',
							'options'  => array(
								'The model is rationing effort to save compute',
								'Nothing in the context tells it who you are or who the email is for',
								'Email is a subject models are not trained on',
								'The question is too short to process',
							),
							'answer'   => 1,
							'hint'     => 'Think about what the model actually received with that sentence.',
							'feedback_correct'   => 'Right — an empty context can only produce a general answer.',
							'feedback_incorrect' => 'Not quite. The model answers from what is in its context, and in that request there was almost nothing about you.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'By default, a language model retains what it learned about you in previous separate conversations.',
							'answer'   => 1,
							'hint'     => 'Unless a specific feature carries it over, each conversation starts empty.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Everything the model can use for a single request has to fit inside its ___ window.',
							'answer_text' => 'context',
							'accept'      => array( 'context window' ),
							'hint'        => 'The block of text handed to the model for this request.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Think of a question you ask an AI assistant regularly. What do you find yourself re-explaining every time before it gives a useful answer?',
							'rubric'   => 'A good answer names a specific recurring piece of context — a role, an audience, a product, a constraint, a format — rather than a general wish for "better answers".',
						),
					),
				),

				array(
					'title'   => 'The four levers',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 20,
					'topics'  => array( 'Personalization' ),
					'content' => '<h2>Four levers, four different jobs</h2>'
						. '<p>People often reach for one lever and conclude that personalization "does not really work". Usually they pulled the wrong one. Each lever answers a different question, and they are not interchangeable.</p>'
						. '<h3>1. Instructions — <em>who am I and how should you answer me?</em></h3>'
						. '<p>A short standing description that is attached to every conversation: your role, your audience, your constraints, your preferred format. Most assistants call this <em>custom instructions</em>, a <em>system prompt</em>, or a <em>profile</em>. It is stable, it is small, and it is the highest-leverage ten minutes you will spend.</p>'
						. '<h3>2. Context — <em>what should you be working from?</em></h3>'
						. '<p>Your actual material: a strategy document, last quarter\'s numbers, the style guide, the contract. General knowledge cannot tell the assistant what your company decided in March. Attaching or retrieving your own material can.</p>'
						. '<h3>3. Memory — <em>what should you carry between conversations?</em></h3>'
						. '<p>A feature that lets the tool save durable facts about you and re-inject them later. Powerful, and the one lever that can quietly go wrong, because you are not the only author of it.</p>'
						. '<h3>4. Examples — <em>what does good look like?</em></h3>'
						. '<p>Two or three samples of the output you want. This works because of <strong>in-context learning</strong>: models shown a few examples of a task inside the prompt perform markedly better on it, without any retraining. Brown and colleagues demonstrated this at scale in 2020, and it remains the cheapest quality upgrade available.</p>'
						. '<h3>Choosing the right lever</h3>'
						. '<table>'
						. '<thead><tr><th>The problem</th><th>The lever</th></tr></thead>'
						. '<tbody>'
						. '<tr><td>"It keeps explaining things I already know"</td><td>Instructions</td></tr>'
						. '<tr><td>"It invents details about our product"</td><td>Context</td></tr>'
						. '<tr><td>"I retype the same background every time"</td><td>Memory (or instructions)</td></tr>'
						. '<tr><td>"The tone is never right"</td><td>Examples</td></tr>'
						. '</tbody></table>'
						. '<h3>Recap</h3>'
						. '<p>Instructions set who you are. Context supplies your material. Memory carries facts forward. Examples show the target. Diagnose which one is missing before you rewrite the prompt again.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'An assistant keeps inventing plausible-sounding details about your company\'s product. Which lever addresses this most directly?',
							'options'  => array(
								'Instructions — describe your role more precisely',
								'Context — give it the actual product documentation',
								'Examples — show it two well-written product descriptions',
								'Memory — ask it to remember the product name',
							),
							'answer'   => 1,
							'hint'     => 'It is not a tone problem or an identity problem. It lacks the facts.',
							'feedback_correct'   => 'Exactly — invented specifics mean the real material was never in the context.',
							'feedback_incorrect' => 'Close, but invented facts are a context problem: the assistant never had the real material to work from.',
						),
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which lever is the "in-context learning" result from Brown et al. (2020) most directly about?',
							'options'  => array(
								'Instructions',
								'Memory',
								'Examples',
								'Context length',
							),
							'answer'   => 2,
							'hint'     => 'The paper is titled "Language Models are Few-Shot Learners".',
							'feedback_correct'   => 'Right — showing a few examples inside the prompt lifts performance without retraining.',
							'feedback_incorrect' => 'It is about examples: a few samples of the task inside the prompt improve results without any retraining.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A short standing description of who you are, attached to every conversation, is usually called custom ___.',
							'answer_text' => 'instructions',
							'accept'      => array( 'instruction' ),
							'hint'        => 'Some tools call it a system prompt instead.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Pick one recurring frustration you have with AI answers. Name which of the four levers is missing, and write one sentence explaining why that lever — and not the other three — is the fix.',
							'rubric' => 'A strong answer states a specific frustration, picks exactly one lever, and justifies the choice by what the assistant was missing (identity, facts, continuity, or a target format) rather than restating the frustration.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Why answers feel generic — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'The best one-line definition of personalization in this course is:',
						'options'  => array(
							'Retraining the model on your data',
							'Getting the right things into the context window reliably, without retyping them',
							'Choosing a larger model',
							'Writing longer prompts',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'A generic answer usually means the model is weak rather than under-informed.',
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Showing two or three samples of the output you want uses the lever called ___.',
						'answer_text' => 'examples',
						'accept'      => array( 'example', 'few-shot' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which lever is the right first move for "it keeps explaining basics I already know"?',
						'options'  => array(
							'Context',
							'Instructions',
							'Memory',
							'Examples',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Telling it who you are',
			'lessons' => array(

				array(
					'title'   => 'Writing a profile that actually changes the output',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 20,
					'topics'  => array( 'Personalization' ),
					'content' => '<h2>Most custom instructions are wasted words</h2>'
						. '<p>The usual first attempt looks like this: <em>"I am a professional. Please be helpful, accurate, and concise."</em> That changes nothing. It contains no fact the assistant can act on, and "be helpful" was already the plan.</p>'
						. '<p>A profile earns its place when every line would visibly change an answer. Test each line by asking: <strong>what would the assistant do differently if it knew this?</strong> If you cannot say, delete the line.</p>'
						. '<h3>The five things worth writing down</h3>'
						. '<ul>'
						. '<li><strong>Role and domain.</strong> Not "I work in business" — "I lead {{role}} work in a mid-sized software company."</li>'
						. '<li><strong>Audience.</strong> Who reads what you produce? Executives, customers, engineers, regulators? This single line changes vocabulary, length, and how much is assumed.</li>'
						. '<li><strong>What you already know.</strong> The fastest way to stop being taught the basics is to say which basics you have.</li>'
						. '<li><strong>Format defaults.</strong> "Answer first, then reasoning." "No bullet lists unless I ask." "Always give me one option, not five."</li>'
						. '<li><strong>Hard constraints.</strong> Tools you cannot use, words you cannot say, claims you cannot make, a language you must write in.</li>'
						. '</ul>'
						. '<h3>Be specific about what you do <em>not</em> want</h3>'
						. '<p>Negative instructions are underused and unusually effective, because they name behaviours the model would otherwise default to. "Do not open with a summary of my question." "Do not hedge — if you are unsure, say so once and then commit." "Do not suggest hiring a consultant."</p>'
						. '<h3>Keep it short enough to stay true</h3>'
						. '<p>A profile is prepended to every conversation, so it competes with your actual question for attention — and it goes stale. Two hundred words that are accurate beat a thousand that describe a job you left. Reread it once a quarter; treat it as a living document, not a form you filled in.</p>'
						. '<blockquote>If a line in your profile could sit unchanged in a stranger\'s profile, it is not doing any work.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Write role, audience, existing knowledge, format defaults, and hard constraints. Include what you do not want. Keep it short, and keep it true.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which line belongs in a custom-instructions profile?',
							'options'  => array(
								'Please be helpful and accurate',
								'I write for hospital procurement committees, who are non-technical and risk-averse',
								'I like good answers',
								'You are an expert AI assistant',
							),
							'answer'   => 1,
							'hint'     => 'Which line would visibly change the vocabulary and framing of an answer?',
							'feedback_correct'   => 'Yes — naming the audience changes vocabulary, length, and what gets assumed.',
							'feedback_incorrect' => 'The useful line is the one that names your audience — it changes how every answer is written.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Telling an assistant what NOT to do is generally a waste of profile space.',
							'answer'   => 1,
							'hint'     => 'Negative instructions name defaults the model would otherwise fall into.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'The test for any profile line: what would the assistant do ___ if it knew this?',
							'answer_text' => 'differently',
							'accept'      => array( 'different' ),
							'hint'        => 'If the answer is "nothing", cut the line.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write a five-line custom-instructions profile for yourself: role and domain, audience, what you already know, one format default, and one hard constraint. Keep every line specific enough that it would be wrong in someone else\'s profile.',
							'rubric' => 'A strong answer has five distinct, concrete lines — a named role and domain, a named audience, specific prior knowledge, an actionable format rule, and a real constraint. Generic phrases such as "be concise" or "I am a professional" are weak.',
						),
					),
				),

				array(
					'title'   => 'Memory: what it keeps, and what to check',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'Context & memory' ),
					'content' => '<h2>The lever you did not fully write</h2>'
						. '<p>Several assistants now offer <strong>memory</strong>: the tool notices durable-looking facts during a conversation, saves them, and re-injects them into later ones. OpenAI shipped this for ChatGPT in 2024 with user-facing controls to view, edit, and delete what was stored. It is the only lever where the assistant is a co-author of your context.</p>'
						. '<h3>What memory is good at</h3>'
						. '<p>Stable facts that would be tedious to repeat: your role, the products you work on, that you write in British English, that you prefer metric units, that your team runs two-week sprints. These pay off every single conversation.</p>'
						. '<h3>Where it goes wrong</h3>'
						. '<p>Three failure modes, all quiet:</p>'
						. '<ul>'
						. '<li><strong>It saves the temporary as permanent.</strong> You spend an afternoon on a one-off project; six weeks later every answer is still angled at it.</li>'
						. '<li><strong>It saves an assumption as a fact.</strong> You explored an idea out loud; it recorded the idea as your position.</li>'
						. '<li><strong>It goes stale.</strong> You changed roles, tools, or opinions. Memory did not.</li>'
						. '</ul>'
						. '<p>None of these announce themselves. The answers stay fluent — they are just subtly aimed at a version of you that no longer exists.</p>'
						. '<h3>The hygiene routine</h3>'
						. '<p>Once a month, open the memory list and read it as if it described someone else. Delete anything temporary. Correct anything that was an assumption. Ask yourself whether a stranger reading this list would form an accurate picture of your work today. This takes about three minutes and it is the difference between memory helping you and memory quietly steering you.</p>'
						. '<p>Also decide what should never be in there at all. Client names, salary figures, unreleased plans, anything about a colleague — if you would not want it re-injected into an unrelated conversation months later, keep it out of memory and put it in a project instead, where you control the boundary.</p>'
						. '<h3>Recap</h3>'
						. '<p>Memory is durable facts, auto-written. Review it monthly, delete the temporary, correct the assumed, and keep sensitive material out of it entirely.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which is the most characteristic failure mode of assistant memory?',
							'options'  => array(
								'It forgets everything between sessions',
								'It saves a temporary situation as if it were a permanent fact about you',
								'It makes answers slower to generate',
								'It refuses to answer personal questions',
							),
							'answer'   => 1,
							'hint'     => 'The dangerous failures are the quiet ones.',
							'feedback_correct'   => 'Right — and it never announces that it has done so.',
							'feedback_incorrect' => 'The quiet one is the problem: a temporary project recorded as a permanent fact keeps steering answers long after it stopped being true.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Because memory is written automatically, it is worth reviewing periodically rather than trusting it indefinitely.',
							'answer'   => 0,
							'hint'     => 'You are not the only author of it.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Memory should hold ___ facts about you, not the details of a one-off project.',
							'answer_text' => 'durable',
							'accept'      => array( 'stable', 'permanent', 'lasting' ),
							'hint'        => 'The opposite of temporary.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Name one fact about your work that belongs in memory, and one that should never go there. Explain the difference in one sentence.',
							'rubric'   => 'A good answer pairs a stable, non-sensitive fact (role, language, preferred units, recurring audience) with something temporary or confidential (client names, unreleased plans, personal data about others), and explains the difference in terms of durability or sensitivity.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Instructions & memory — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which profile line is doing real work?',
						'options'  => array(
							'Be professional and thorough',
							'My readers are non-technical procurement committees',
							'You are a helpful assistant',
							'I value quality',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'A profile is prepended to every conversation, so length has a real cost.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Reviewing and pruning what an assistant has stored about you is memory ___.',
						'answer_text' => 'hygiene',
						'accept'      => array( 'maintenance', 'review' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Confidential client details are best kept:',
						'options'  => array(
							'In memory, so they are always available',
							'Out of memory — scoped to a project where you control the boundary',
							'In your custom instructions',
							'In the first message of every chat',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'Feeding it your world',
			'lessons' => array(

				array(
					'title'   => 'Projects, spaces, and your own documents',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 20,
					'topics'  => array( 'Grounding', 'Context & memory' ),
					'content' => '<h2>General knowledge cannot know what your team decided in March</h2>'
						. '<p>A model trained on the public internet knows a great deal about your industry and nothing at all about your organisation. The fix is not a better prompt — it is <strong>grounding</strong>: putting your own material into the context so the answer is built from your facts rather than the average of everyone\'s.</p>'
						. '<h3>Three ways to ground, in increasing durability</h3>'
						. '<ul>'
						. '<li><strong>Attach a file to one conversation.</strong> Fastest. Gone when the chat ends.</li>'
						. '<li><strong>Create a project or space.</strong> Anthropic\'s Claude Projects, and equivalents in other tools, let you attach a set of documents <em>and</em> a set of instructions that apply to every conversation inside that project. This is the sweet spot for ongoing work.</li>'
						. '<li><strong>Retrieval over a corpus.</strong> For material too large to attach, a retrieval system finds the relevant passages per question and inserts only those. This is <strong>retrieval-augmented generation</strong> (RAG), introduced by Lewis and colleagues in 2020, and it is what sits behind most "chat with your documents" products.</li>'
						. '</ul>'
						. '<h3>Why projects beat re-uploading</h3>'
						. '<p>A project is personalization with a boundary. Everything inside it shares the same documents and the same standing instructions, and nothing leaks out to unrelated chats. If you work in {{role}}, a project per recurring workstream — one for the quarterly report, one for customer research — will do more for answer quality than any amount of prompt polish.</p>'
						. '<h3>What to put in, and what to leave out</h3>'
						. '<p>Grounding material should be <em>authoritative</em> and <em>current</em>. A superseded strategy document is worse than no document, because the assistant will treat it as true and argue from it confidently. Date your sources, and prune them when they expire. Signal-to-noise matters more than volume: five accurate pages beat fifty stale ones.</p>'
						. '<h3>Recap</h3>'
						. '<p>Attach for one-offs, use projects for ongoing work, use retrieval when the corpus is too big to attach. Keep grounding material authoritative and current — a stale document is confidently wrong.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'You will spend the next three months on one report and keep asking related questions. What is the right structure?',
							'options'  => array(
								'Re-upload the documents at the start of each chat',
								'A project or space holding the documents and standing instructions',
								'Paste the documents into your custom instructions',
								'Rely on memory to retain the content',
							),
							'answer'   => 1,
							'hint'     => 'Ongoing work with a fixed set of material and a boundary.',
							'feedback_correct'   => 'Exactly — a project carries the documents and the instructions across every conversation inside it.',
							'feedback_incorrect' => 'A project is the fit here: same documents, same standing instructions, every conversation, with a boundary around it.',
						),
						array(
							'type'     => 'multiple_choice',
							'question' => 'What does retrieval-augmented generation (RAG) do?',
							'options'  => array(
								'Retrains the model on your documents',
								'Finds the passages relevant to a question and inserts only those into the context',
								'Compresses your documents so more fit in the context window',
								'Lets the model browse the live internet',
							),
							'answer'   => 1,
							'hint'     => 'It is a way to handle a corpus too large to attach whole.',
							'feedback_correct'   => 'Right — retrieve the relevant passages, then generate from them.',
							'feedback_incorrect' => 'RAG retrieves the passages relevant to each question and puts only those in the context — no retraining involved.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A superseded document is worse than none, because the assistant will treat it as ___.',
							'answer_text' => 'true',
							'accept'      => array( 'current', 'accurate', 'fact' ),
							'hint'        => 'It has no way to know the document expired.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Design a project for one recurring piece of your work. List the three to five documents you would attach, and write the standing instruction that should apply to every conversation inside it.',
							'rubric' => 'A strong answer names specific, authoritative documents (not "our files") and a standing instruction that constrains role, audience, or format for that workstream specifically.',
						),
					),
				),

				array(
					'title'   => 'Teaching it your voice by showing, not telling',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'Personalization', 'Grounding' ),
					'content' => '<h2>Describing your style barely works. Showing it works immediately.</h2>'
						. '<p>Ask for "a warm but professional tone" and you get someone\'s average of those words. Paste two emails you actually wrote and ask for a third in the same voice, and the difference is obvious in one attempt.</p>'
						. '<p>This is the <strong>examples</strong> lever, and it works for the same reason few-shot prompting works: models pick up the pattern of a task from demonstrations inside the context far more reliably than from a description of it. Style is exactly the kind of thing that is easy to demonstrate and nearly impossible to specify.</p>'
						. '<h3>How to build a voice sample set</h3>'
						. '<ul>'
						. '<li><strong>Two or three examples, not ten.</strong> More examples crowd the context and rarely add signal past the third.</li>'
						. '<li><strong>Pick your best, not your most recent.</strong> The assistant will imitate whatever you show it, including the habits you were trying to drop.</li>'
						. '<li><strong>Match the genre.</strong> Samples of your internal Slack posts will not produce a good board memo. Keep a small set per genre.</li>'
						. '<li><strong>Say what to copy.</strong> "Match the sentence length and directness, not the subject matter" prevents it borrowing content along with the style.</li>'
						. '</ul>'
						. '<h3>Examples for structure, not only tone</h3>'
						. '<p>The same trick works for any output shape you produce repeatedly: a bug report, a meeting summary, a customer reply, a product brief. Show one filled-in example and the format arrives correct on the first attempt instead of the fourth.</p>'
						. '<blockquote>Anything you can show, show. Save description for the things you cannot demonstrate.</blockquote>'
						. '<h3>Where showing fails</h3>'
						. '<p>Examples teach form, not judgement. They will not tell the assistant which facts are true, which claims you are allowed to make, or when to refuse. That is what instructions and grounding are for — which is why the four levers work together rather than competing.</p>'
						. '<h3>Recap</h3>'
						. '<p>Two or three of your best samples, matched to the genre, with an explicit note on what to copy. Examples carry form; instructions and grounding carry substance.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why do examples beat a description of your tone?',
							'options'  => array(
								'They use fewer tokens',
								'Models pick up a pattern from demonstrations more reliably than from a description',
								'Descriptions are ignored by the model',
								'Examples are stored permanently',
							),
							'answer'   => 1,
							'hint'     => 'This is the same effect behind few-shot prompting.',
							'feedback_correct'   => 'Yes — demonstrations carry the pattern; adjectives carry an average.',
							'feedback_incorrect' => 'It is the in-context learning effect: a demonstration conveys the pattern far more precisely than words describing it.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Providing ten style samples instead of three reliably produces a better match.',
							'answer'   => 1,
							'hint'     => 'Past the third, you are mostly spending context.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Examples teach form; instructions and grounding carry the ___.',
							'answer_text' => 'substance',
							'accept'      => array( 'facts', 'content', 'judgement', 'judgment' ),
							'hint'        => 'What examples cannot convey.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Pick something you write repeatedly. Which two samples would you use as your voice set, and what would you tell the assistant to copy from them?',
							'rubric'   => 'A good answer names a specific genre, chooses two samples for a stated reason (quality, representativeness), and names a concrete attribute to copy — sentence length, directness, structure — rather than "the style".',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Grounding & voice — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Putting your own authoritative material into the context so answers are built from your facts is called:',
						'options'  => array(
							'Fine-tuning',
							'Grounding',
							'Prompt chaining',
							'Distillation',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Retrieval-augmented generation retrains the model on your documents.',
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'For ongoing work with a fixed set of documents and standing instructions, use a ___.',
						'answer_text' => 'project',
						'accept'      => array( 'space', 'projects' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Examples are the wrong tool for:',
						'options'  => array(
							'Matching your sentence rhythm',
							'Reproducing a report format',
							'Deciding which claims are factually true',
							'Copying your level of directness',
						),
						'answer'   => 2,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Making it trustworthy',
			'lessons' => array(

				array(
					'title'   => 'Testing whether personalization actually helped',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 20,
					'topics'  => array( 'Evaluation', 'Personalization' ),
					'content' => '<h2>"It feels better" is not evidence</h2>'
						. '<p>You spent an hour on a profile, a project, and a voice sample set. Did the answers improve? Almost nobody checks, and the reason is that checking sounds like it needs a methodology. It needs about fifteen minutes.</p>'
						. '<h3>Build a tiny personal eval set</h3>'
						. '<p>Write down <strong>five tasks you actually do</strong>. Not clever test prompts — the real ones: "draft a reply to a customer asking for a discount", "summarise this meeting for my manager", "review this paragraph for the board deck". Five is enough to see a difference and few enough that you will actually run them.</p>'
						. '<p>For each, write one sentence describing what a good answer would contain. That sentence is your rubric, and writing it before you look at any output is what keeps you honest.</p>'
						. '<h3>Run the comparison</h3>'
						. '<p>Run all five with your personalization turned off, and all five with it on. Score each answer against its rubric — a simple <em>good / partly / no</em> is plenty. Then look at the pattern, not the totals: <strong>which tasks improved, and which did not?</strong></p>'
						. '<p>The tasks that did not improve are the interesting ones. They usually reveal a missing lever: the answer was well-pitched but factually thin (missing grounding), or accurate but in the wrong shape (missing examples).</p>'
						. '<h3>Re-run it when things change</h3>'
						. '<p>Keep the five tasks and the rubrics in a file. Re-run them when you change your profile, when you switch tools, or when a model is updated — a new model version can quietly change how your instructions are interpreted. This is the same discipline the NIST AI Risk Management Framework calls <em>measure</em>: you cannot manage what you never checked.</p>'
						. '<blockquote>Fifteen minutes of comparison will tell you more than another hour of prompt tinkering.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Five real tasks, one rubric sentence each, run with and without personalization, scored coarsely. The tasks that did not improve tell you which lever is still missing.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What makes a personal eval set useful?',
							'options'  => array(
								'It uses clever prompts designed to stress the model',
								'It uses tasks you genuinely do, with a rubric written before you see any output',
								'It contains at least fifty examples',
								'It scores answers on a hundred-point scale',
							),
							'answer'   => 1,
							'hint'     => 'What keeps the comparison honest, and what keeps you actually running it?',
							'feedback_correct'   => 'Right — real tasks, and a rubric written in advance.',
							'feedback_incorrect' => 'Real tasks with rubrics written before you look at the output — that is what makes the comparison mean anything.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'The tasks that did NOT improve after personalization are the most informative ones.',
							'answer'   => 0,
							'hint'     => 'They point at the lever you have not pulled.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Re-run your eval set when your profile changes, when you switch tools, or when the ___ is updated.',
							'answer_text' => 'model',
							'accept'      => array( 'model version' ),
							'hint'        => 'A new version can reinterpret your instructions.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write your five-task eval set: five real tasks from your week, each with a one-sentence rubric describing what a good answer must contain.',
							'rubric' => 'A strong answer lists five concrete, recurring tasks — not generic prompts — and pairs each with a rubric that names checkable content rather than "a good answer".',
						),
					),
				),

				array(
					'title'   => 'The limits: privacy, drift, and being agreed with',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'Evaluation', 'Personalization' ),
					'content' => '<h2>Personalization has two failure modes and one hard boundary</h2>'
						. '<h3>The boundary: what you hand over</h3>'
						. '<p>Every lever works by putting information somewhere a system will read it later. That makes "what should I share?" a real question rather than a paranoid one. The principle to borrow is <strong>data minimisation</strong>, set out in Article 5 of the GDPR: collect and keep only what is necessary for the purpose. Applied here — put in what changes the answer, and nothing else.</p>'
						. '<p>Some concrete lines: personal data about other people is theirs, not yours to paste. Unreleased plans and client identities belong in a scoped project, if anywhere, not in memory. Check your organisation\'s policy on which tools may see which categories of data — and check whether your account\'s content is used for training, because that setting decides how far the information travels.</p>'
						. '<h3>Failure mode one: drift</h3>'
						. '<p>Your profile, your memory, and your project documents were all true when you wrote them. Roles change, products get renamed, strategies get replaced. Because none of this fails loudly, a drifted setup produces confident answers aimed at a job you no longer have. The eval set from the previous lesson is the cheapest detector: when familiar tasks start scoring worse for no obvious reason, something in your context has gone stale.</p>'
						. '<h3>Failure mode two: an assistant that only agrees with you</h3>'
						. '<p>This one is subtler and more costly. Tell a system your role, your goals, your positions, and your preferred framing, and it will increasingly answer inside that frame. You will feel well-served. You will also stop encountering the strongest version of the opposing case — the thing you most needed.</p>'
						. '<p>The countermeasure is deliberate and takes one sentence: <em>"Argue the strongest case against what I just proposed."</em> Build it into your habits for consequential decisions, and consider adding a line to your profile that licenses disagreement: <em>"Tell me when you think I am wrong, before you help me do it."</em></p>'
						. '<blockquote>A personalized assistant is a better mirror. Decide when you need a mirror and when you need a second opinion.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Share what changes the answer and no more. Watch for drift with your eval set. And build in disagreement on purpose, because a well-personalized assistant will not volunteer it.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'The data-minimisation principle, applied to personalization, says:',
							'options'  => array(
								'Share as much as possible so the assistant knows you fully',
								'Put in what changes the answer, and nothing else',
								'Never share anything about your work',
								'Only use tools hosted in your own country',
							),
							'answer'   => 1,
							'hint'     => 'It is about necessity for the purpose.',
							'feedback_correct'   => 'Exactly — necessity is the test, not comfort or completeness.',
							'feedback_incorrect' => 'Minimisation means only what is necessary for the purpose: what changes the answer, and nothing else.',
						),
						array(
							'type'     => 'multiple_choice',
							'question' => 'What is the main risk of an assistant that knows your positions well?',
							'options'  => array(
								'It answers more slowly',
								'It stops presenting the strongest opposing case',
								'It forgets your formatting preferences',
								'It uses more of the context window',
							),
							'answer'   => 1,
							'hint'     => 'The failure feels like good service.',
							'feedback_correct'   => 'Right — and it feels like being well served, which is what makes it dangerous.',
							'feedback_incorrect' => 'The risk is agreement: it increasingly answers inside your frame and stops showing you the strongest counter-case.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A profile, memory, and project documents that were true when written but no longer are have ___.',
							'answer_text' => 'drifted',
							'accept'      => array( 'drift', 'gone stale', 'stale' ),
							'hint'        => 'It never fails loudly.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Write the one sentence you will add to your workflow to make sure a well-personalized assistant still disagrees with you, and say when you will use it.',
							'rubric'   => 'A good answer gives a concrete instruction that invites the opposing case or explicit disagreement, and names a specific situation — a consequential decision, a plan review, a draft you are attached to — rather than "sometimes".',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Trustworthy personalization — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'The cheapest way to detect that your personalization has gone stale is:',
						'options'  => array(
							'Reading your profile again',
							'Re-running a small set of real tasks you scored before',
							'Switching to a newer model',
							'Clearing memory monthly',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'A well-personalized assistant will normally volunteer the strongest case against your position.',
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Put in what changes the answer and nothing else — the principle of data ___.',
						'answer_text' => 'minimisation',
						'accept'      => array( 'minimization', 'minimisation', 'minimising' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Personal data about a colleague is best handled by:',
						'options'  => array(
							'Adding it to memory so context is complete',
							'Not pasting it at all — it is theirs, not yours to share',
							'Putting it in your custom instructions',
							'Uploading it to every project',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
