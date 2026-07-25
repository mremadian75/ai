<?php
/**
 * Seed course: "How Large Language Models Work" (Generative AI bundle).
 *
 * Conforms to the canonical schema contract defined in
 * course-pe-foundations.php. The seeder ({@see Mahan_Seed}) loads each
 * `course-*.php` and expects exactly that shape:
 * units -> lessons -> exercises, plus an optional unit quiz.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'genai-llms',
	'title'       => 'How Large Language Models Work',
	'slug'        => 'genai-llms',
	'subtitle'    => 'A no-maths look inside LLMs — next-token prediction, why they hallucinate, and the settings that shape their behaviour.',
	'excerpt'     => 'Open the black box of large language models: how they predict text, why they sometimes make things up, and the handful of settings that change how they answer.',
	'description' => '<p>Large language models can write, summarise, and reason — but most people use them with no idea of what is happening under the hood. That gap is exactly why prompts misfire and why a confident answer can be quietly wrong.</p>'
		. '<p>This course opens the black box without a single equation. You will see how an LLM builds an answer one token at a time, why training on huge amounts of text makes it fluent but not infallible, and how simple controls like temperature, system prompts, retrieval, and fine-tuning let you shape what it does. Practical intuition you can use in any AI tool today.</p>',
	'category'    => 'Generative AI',
	'track'       => 'generative-ai',
	'level_rank'  => 2,
	'level'       => 'intermediate',
	'est_hours'   => 4,
	'featured'    => false,
	'certificate' => true,
	'order'       => 2,
	'topics'      => array( 'Next-token prediction', 'Training', 'Temperature & sampling', 'System prompts & fine-tuning' ),
	'outcomes'    => array(
		'Explain how an LLM generates text through next-token prediction',
		'Describe how training turns raw text into a helpful assistant',
		'Recognise why LLMs hallucinate and how a knowledge cutoff limits them',
		'Adjust temperature to make output focused or creative for the task',
		'Choose between a system prompt, retrieval, and fine-tuning to shape behaviour',
	),
	'references'  => array(
		array(
			'title'  => 'Attention Is All You Need (the Transformer)',
			'source' => 'Vaswani et al., 2017 — arXiv:1706.03762',
			'url'    => 'https://arxiv.org/abs/1706.03762',
		),
		array(
			'title'  => 'Language Models are Few-Shot Learners (GPT-3)',
			'source' => 'Brown et al., 2020 — arXiv:2005.14165',
			'url'    => 'https://arxiv.org/abs/2005.14165',
		),
		array(
			'title'  => 'Training language models to follow instructions with human feedback (InstructGPT/RLHF)',
			'source' => 'Ouyang et al., 2022 — arXiv:2203.02155',
			'url'    => 'https://arxiv.org/abs/2203.02155',
		),
		array(
			'title'  => 'Retrieval-Augmented Generation for Knowledge-Intensive NLP Tasks',
			'source' => 'Lewis et al., 2020 — arXiv:2005.11401',
			'url'    => 'https://arxiv.org/abs/2005.11401',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'Inside an LLM',
			'lessons' => array(

				array(
					'title'   => 'Prediction, tokens, and training',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 25,
					'topics'  => array( 'Next-token prediction', 'Training' ),
					'content' => '<h2>An LLM is a next-token prediction machine</h2>'
						. '<p>Strip away the marketing and a large language model does one deceptively simple thing: it looks at the text so far and predicts the most likely next <strong>token</strong> — a chunk that is often a word or part of a word. Then it adds that token to the text and predicts again. It builds an answer one token at a time, until it decides to stop.</p>'
						. '<p>That is the whole trick. When you type "The capital of France is", the model has seen that pattern countless times and predicts "Paris" with high confidence. When you ask it to write a poem, it predicts each next word so the growing text keeps looking like a plausible poem.</p>'
						. '<h3>Where the patterns come from: training</h3>'
						. '<p>The model is not born knowing anything. During <strong>training</strong> it is shown enormous amounts of text — books, articles, code, conversations — and repeatedly asked to guess the next token. Each time it guesses, it is nudged to do a little better. After trillions of these tiny corrections it has absorbed the statistical patterns of language: grammar, facts that appear often, styles, and reasoning steps that tend to follow one another.</p>'
						. '<h3>Two stages, roughly</h3>'
						. '<p>Most modern assistants are built in two phases. First comes <strong>pretraining</strong>: the raw next-token learning on a huge pile of text, which produces a model that can continue any text but is not especially helpful or safe. Then comes <strong>alignment</strong> — instruction-tuning and feedback from humans — which teaches the model to follow requests, stay on task, and behave like an assistant rather than a raw autocomplete.</p>'
						. '<blockquote>The base model learns language. The alignment step teaches it to be helpful.</blockquote>'
						. '<h3>It models likely text, not a database</h3>'
						. '<p>Here is the idea to hold on to: an LLM is <em>not</em> a lookup table of stored facts. It is a model of what text is <em>likely</em> to come next. Often "likely" and "true" line up, which is why it can answer so much correctly. But the model is always predicting plausibility, not retrieving a verified record — a distinction that explains a great deal of its behaviour, as the next lesson shows.</p>'
						. '<h3>Recap</h3>'
						. '<p>An LLM predicts the next token over and over. It learned those predictions by training on huge amounts of text — first pretraining for raw language ability, then alignment to become a helpful assistant. Underneath, it models likely text, not a store of facts.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'At the most basic level, how does an LLM produce a response?',
							'options'  => array(
								'It searches a database of stored answers for the closest match',
								'It copies the single most relevant sentence from its training data',
								'It predicts the next token repeatedly, one at a time',
								'It asks a human to write the response behind the scenes',
							),
							'answer'   => 2,
							'hint'     => 'Think about how the answer is built up word by word.',
							'feedback_correct'   => 'Exactly — it predicts one likely token, adds it, and predicts again until it stops.',
							'feedback_incorrect' => 'Not quite. An LLM generates text by repeatedly predicting the next token, not by looking up stored answers.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'During training, the model learns by repeatedly trying to predict the next token and being corrected when it is wrong.',
							'answer'   => 0,
							'hint'     => 'That guess-and-correct loop, run trillions of times, is the whole idea of training.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'The first phase, raw next-token learning on a huge pile of text, is called ___.',
							'answer_text' => 'pretraining',
							'accept'      => array( 'pre-training', 'pre training' ),
							'hint'        => 'It comes before the alignment step.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'An LLM works by storing facts in a lookup table and retrieving the matching entry for each question.',
							'answer'   => 1,
							'hint'     => 'It models likely text; it is not a database of records.',
						),
					),
				),

				array(
					'title'   => 'Why LLMs sometimes make things up',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Next-token prediction' ),
					'content' => '<h2>Fluent does not mean correct</h2>'
						. '<p>Because a language model works by predicting <strong>plausible continuations</strong>, it is very good at producing text that <em>reads</em> as right. Unfortunately, plausible and true are not the same thing. When the model does not actually "know" something, it does not stop — it predicts the most likely-sounding answer anyway and delivers it with the same confident tone it uses for real facts. This is called <strong>hallucination</strong>.</p>'
						. '<h3>Why it happens</h3>'
						. '<p>Remember the core mechanism: the model is always answering the question "what text is likely to come next?" It has no separate step that checks "is this actually true?" There is no built-in fact-checker and no little database it consults to verify itself. If a made-up citation, a plausible-sounding statistic, or a fake function name fits the pattern of the text, the model will happily produce it.</p>'
						. '<blockquote>An LLM has no internal truth meter. It optimises for plausible, not for correct.</blockquote>'
						. '<h3>The knowledge cutoff</h3>'
						. '<p>There is a second, related limit. A model only learned from text that existed up to a certain date — its <strong>knowledge cutoff</strong>. It cannot know about events, releases, or prices that came after that date, and it usually cannot tell that it does not know. Ask about yesterday\'s news and, unless it has a live tool to look things up, it may confidently invent an answer that simply fits the pattern of "recent news".</p>'
						. '<h3>How to reduce it</h3>'
						. '<p>You cannot make hallucination disappear, but you can sharply cut it:</p>'
						. '<ul>'
						. '<li><strong>Ground the model:</strong> paste the source text, or connect a retrieval or search tool, so the answer is drawn from real material rather than memory.</li>'
						. '<li><strong>Allow "I don\'t know":</strong> tell the model to say when it is unsure instead of guessing.</li>'
						. '<li><strong>Verify what matters:</strong> treat names, numbers, quotes, and legal or medical claims as a draft until you have checked them yourself.</li>'
						. '</ul>'
						. '<h3>A concrete example</h3>'
						. '<p>Ask an ungrounded model for "three studies proving X" and it may return three real-looking references — authors, journal, year — that do not exist. Paste the actual studies and it can summarise them accurately, because now the facts are in front of it.</p>'
						. '<h3>Recap</h3>'
						. '<p>LLMs hallucinate because they predict plausible text and have no built-in check for truth, and their knowledge stops at a cutoff date. Grounding them in real sources, letting them admit uncertainty, and verifying important facts are your best defences.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why can an LLM produce a confident answer that is completely false?',
							'options'  => array(
								'It is deliberately programmed to lie from time to time',
								'It predicts plausible-sounding text and has no built-in check for truth',
								'It runs out of memory in the middle of a sentence',
								'It always copies errors directly from a single website',
							),
							'answer'   => 1,
							'hint'     => 'Think about what the model is actually optimising for.',
							'feedback_correct'   => 'Right — plausible is not the same as true, and there is no internal fact-checker.',
							'feedback_incorrect' => 'Not quite. Hallucination comes from predicting plausible text with no verification step, not from a bug or deliberate lying.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A model only learned from text up to a certain date, known as its knowledge ___.',
							'answer_text' => 'cutoff',
							'accept'      => array( 'cut-off', 'cut off' ),
							'hint'        => 'After this date, it simply has not seen anything.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Every LLM contains a built-in fact-checker that verifies each answer against a trusted database before showing it.',
							'answer'   => 1,
							'hint'     => 'There is no separate "is this true?" step in the prediction process.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Name two things you can do to reduce the chance of a hallucination when the answer really matters, and briefly say why each helps.',
							'rubric'   => 'A good answer names at least two of: grounding the model in pasted or retrieved source material, telling it to admit uncertainty rather than guess, and verifying important facts manually — and explains that each connects the answer to real information or catches errors.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Inside an LLM — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'What does "next-token prediction" mean?',
						'options'  => array(
							'The model ranks web pages by how relevant they are',
							'The model deletes tokens it has already used up',
							'The model repeatedly predicts the most likely next chunk of text',
							'The model translates each token into another language',
						),
						'answer'   => 2,
					),
					array(
						'type'     => 'true_false',
						'question' => 'LLMs can hallucinate because they optimise for plausible-sounding text rather than for verified truth.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'The date after which a model has not learned anything new is called its knowledge ___.',
						'answer_text' => 'cutoff',
						'accept'      => array( 'cut-off', 'cut off' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which move most reliably reduces hallucinations about recent events?',
						'options'  => array(
							'Typing the question in capital letters',
							'Giving the model a live search tool or the source text to work from',
							'Asking for a much longer answer',
							'Telling the model that it is very intelligent',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Shaping behaviour',
			'lessons' => array(

				array(
					'title'   => 'Temperature and sampling',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 25,
					'topics'  => array( 'Temperature & sampling' ),
					'content' => '<h2>The dial between focused and creative</h2>'
						. '<p>When a model predicts the next token, it does not settle on a single option — it produces a whole ranked list of candidates with probabilities. "The sky is" might be 80% "blue", 5% "grey", 2% "falling", and so on down the list. <strong>Temperature</strong> is the setting that decides how adventurously the model picks from that list.</p>'
						. '<h3>Low temperature: focused and predictable</h3>'
						. '<p>At a low temperature (near zero), the model almost always chooses the top-ranked token. Answers become <strong>focused, consistent, and nearly deterministic</strong> — ask the same question twice and you tend to get the same reply. This is what you want for factual extraction, classification, data formatting, code, or anything where there is a right answer and you want it reliably.</p>'
						. '<h3>High temperature: varied and creative</h3>'
						. '<p>At a high temperature, the model is more willing to pick lower-ranked, less obvious tokens. Answers become more <strong>varied, surprising, and creative</strong> — great for brainstorming names, generating many different marketing angles, or writing playful copy. The trade-off is that too high a temperature drifts into rambling or nonsense, because the model starts choosing genuinely unlikely words.</p>'
						. '<blockquote>Low temperature for "get it right"; high temperature for "give me options".</blockquote>'
						. '<h3>Choosing a setting</h3>'
						. '<ul>'
						. '<li><strong>Extracting a date from an invoice?</strong> Low. You want the same correct answer every time.</li>'
						. '<li><strong>Naming a new product?</strong> Higher. You want ten different directions to react to.</li>'
						. '<li><strong>Writing a careful summary?</strong> Low to middle — accurate, but not robotic.</li>'
						. '</ul>'
						. '<h3>Other sampling knobs, briefly</h3>'
						. '<p>Temperature is the main dial, but you may meet two relatives. <strong>Top-p</strong> (nucleus sampling) tells the model to consider only the smallest set of top candidates that together cover, say, 90% of the probability. <strong>Top-k</strong> limits it to the k most likely tokens. Both, like temperature, trade focus against variety. You rarely need to touch all three — adjusting temperature alone covers most everyday needs.</p>'
						. '<h3>Recap</h3>'
						. '<p>Temperature controls randomness. Low means focused, consistent, near-deterministic output for tasks with a right answer; high means varied, creative output for brainstorming and writing. Top-p and top-k are related knobs that also balance focus against variety.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'You need to pull the total amount from 500 invoices and get it right the same way every time. What temperature fits best?',
							'options'  => array(
								'A low temperature, for focused and consistent output',
								'A high temperature, for creative variety',
								'It makes no difference at all for extraction',
								'The maximum possible temperature',
							),
							'answer'   => 0,
							'hint'     => 'There is one right answer per invoice and you want it reliably.',
							'feedback_correct'   => 'Exactly — low temperature keeps the model focused and repeatable, which is what extraction needs.',
							'feedback_incorrect' => 'For a task with one right answer that must be consistent, a low temperature is the safer choice.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A higher temperature tends to make a model\'s output more varied and creative.',
							'answer'   => 0,
							'hint'     => 'Higher temperature = more willing to pick less obvious words.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'The main setting that controls how random or creative a model\'s word choices are is called ___.',
							'answer_text' => 'temperature',
							'accept'      => array( 'temp' ),
							'hint'        => 'Low = focused, high = creative.',
						),
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which task is the best fit for a higher temperature?',
							'options'  => array(
								'Extracting a phone number from a document',
								'Classifying an email as spam or not spam',
								'Converting a messy list into strict JSON',
								'Brainstorming ten different slogans for a campaign',
							),
							'answer'   => 3,
							'hint'     => 'Which one benefits from variety rather than one exact answer?',
							'feedback_correct'   => 'Right — brainstorming wants many different options, so more randomness helps.',
							'feedback_incorrect' => 'The first three want one exact, repeatable answer; brainstorming is the one that benefits from a higher temperature.',
						),
					),
				),

				array(
					'title'   => 'System prompts and fine-tuning at a glance',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 25,
					'topics'  => array( 'System prompts & fine-tuning' ),
					'content' => '<h2>Three ways to shape what a model does</h2>'
						. '<p>Once you understand prediction and temperature, the last piece is <em>how</em> you steer a model toward your particular needs. There are three common levers, from lightest to heaviest.</p>'
						. '<h3>1. The system prompt</h3>'
						. '<p>A <strong>system prompt</strong> is a set of instructions placed above the conversation that sets the model\'s persistent role and rules for the whole session. Where a normal message is one-off, the system prompt keeps applying to every reply. "You are a support agent for Acme. Be concise, never promise refunds, and always end by asking if there is anything else." It shapes tone, boundaries, and behaviour without changing the model itself — and it is instant and free to edit.</p>'
						. '<h3>2. Retrieval (RAG)</h3>'
						. '<p>A system prompt cannot add facts the model never learned. <strong>Retrieval-augmented generation</strong> (RAG) solves that by fetching relevant documents — your policies, product docs, a knowledge base — at question time and pasting them into the context so the model answers from real, current material. This is the go-to fix when the model needs to know <em>your</em> up-to-date information, and it also cuts hallucination.</p>'
						. '<h3>3. Fine-tuning</h3>'
						. '<p><strong>Fine-tuning</strong> goes deeper: you take a base model and train it further on your own examples so its <em>default behaviour</em> shifts toward your style, format, or domain. It is the right tool when you need a consistent voice or format across thousands of calls, or specialised patterns a prompt struggles to capture. It costs more, needs good example data, and must be redone as your needs change — so it is the heaviest lever.</p>'
						. '<blockquote>Prompt to steer behaviour, retrieve to supply facts, fine-tune to change default style at scale.</blockquote>'
						. '<h3>Choosing between them</h3>'
						. '<ul>'
						. '<li><strong>Need a role or rules for a session?</strong> System prompt.</li>'
						. '<li><strong>Need current or private facts?</strong> Retrieval (RAG).</li>'
						. '<li><strong>Need a consistent style or format at scale?</strong> Fine-tuning.</li>'
						. '</ul>'
						. '<p>Most real systems combine them: a base or fine-tuned model, a clear system prompt, and retrieval feeding in fresh facts. Reach for the lightest tool that solves your problem first — usually the prompt.</p>'
						. '<h3>Recap</h3>'
						. '<p>A system prompt sets a persistent role and rules cheaply; retrieval supplies fresh or private facts; fine-tuning reshapes a model\'s default behaviour at scale but costs the most. Start light and only escalate when you must.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What is the main job of a system prompt?',
							'options'  => array(
								'To permanently retrain the model on your own data',
								'To fetch documents from a database at question time',
								'To set a persistent role and rules for the whole session',
								'To make the model respond faster',
							),
							'answer'   => 2,
							'hint'     => 'It applies to every reply in the session, not just one message.',
							'feedback_correct'   => 'Exactly — a system prompt sets the model\'s role and rules for the whole conversation, cheaply and instantly.',
							'feedback_incorrect' => 'A system prompt does not retrain or retrieve; it sets persistent role and rules for the session.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Fine-tuning is generally cheaper and quicker to change than editing a system prompt.',
							'answer'   => 1,
							'hint'     => 'A prompt is instant and free to edit; fine-tuning needs data and retraining.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Feeding fresh or private documents into the context at question time is known as retrieval, or ___ for short.',
							'answer_text' => 'RAG',
							'accept'      => array( 'R.A.G.', 'retrieval augmented generation', 'retrieval-augmented generation' ),
							'hint'        => 'A three-letter acronym for retrieval-augmented generation.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Pick a real use case from your own work and decide whether a system prompt, retrieval (RAG), or fine-tuning best fits it. In one or two sentences, justify your choice.',
							'rubric' => 'A strong answer names a concrete use case, picks one of the three levers, and justifies it correctly — e.g. a system prompt for roles/rules, retrieval for current or private facts, or fine-tuning for a consistent style or format at scale.',
							'hint'   => 'Ask first whether you need behaviour, facts, or a new default style — then match the lightest tool that fits.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Shaping behaviour — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'What happens to a model\'s output at a LOW temperature?',
						'options'  => array(
							'It becomes more random and creative',
							'It becomes more focused, consistent, and predictable',
							'The model refuses to answer',
							'The model starts ignoring your prompt',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'The persistent instructions that set a model\'s role and rules for a whole session are called the ___ prompt.',
						'answer_text' => 'system',
						'accept'      => array(),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Fine-tuning changes a model\'s default behaviour by training it further on examples, whereas a prompt only steers a single session\'s output.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'You want ten wildly different creative taglines. Which setting helps most?',
						'options'  => array(
							'A higher temperature',
							'A temperature of exactly zero',
							'A shorter context window',
							'Turning off the system prompt',
						),
						'answer'   => 0,
					),
				),
			),
		),
	),
);
