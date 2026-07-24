<?php
/**
 * Seed course: "Grounding AI in Your Own Knowledge (RAG)" — Generative AI.
 *
 * Follows the canonical schema contract defined in course-pe-foundations.php.
 * Grounded in Lewis et al. (2020) on retrieval-augmented generation and the
 * major providers' retrieval/embedding documentation.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'rag-grounding',
	'title'       => 'Grounding AI in Your Own Knowledge (RAG)',
	'slug'        => 'rag-grounding',
	'subtitle'    => 'Make AI answer from your documents instead of guessing — the idea behind retrieval-augmented generation.',
	'excerpt'     => 'Why models invent answers about your business, and how retrieval-augmented generation fixes it by giving them your actual sources.',
	'description' => '<p>Ask a general AI assistant about your company\'s refund policy and it will answer confidently — and probably wrongly. It has never seen your policy. The fix has a name: <strong>retrieval-augmented generation</strong>, or RAG.</p>'
		. '<p>This course explains RAG without the jargon: why grounding beats memorising, how a system finds the right passage to hand the model, and how to tell whether an answer is genuinely sourced. You will finish able to judge and specify AI-over-your-documents tools rather than just trusting them.</p>',
	'category'    => 'Generative AI',
	'level'       => 'intermediate',
	'est_hours'   => 3,
	'featured'    => false,
	'certificate' => true,
	'order'       => 3,
	'topics'      => array( 'Grounding', 'Embeddings & search', 'Chunking', 'Citations & evaluation' ),
	'outcomes'    => array(
		'Explain why an ungrounded model invents answers about your organisation',
		'Describe the retrieve-then-generate loop in plain language',
		'Explain how embeddings let a system find passages by meaning, not keywords',
		'Choose sensible chunking and source hygiene for a knowledge base',
		'Judge whether an AI answer is genuinely grounded in its cited sources',
	),
	'references'  => array(
		array(
			'title'  => 'Retrieval-Augmented Generation for Knowledge-Intensive NLP Tasks',
			'source' => 'Lewis et al., 2020 — arXiv:2005.11401',
			'url'    => 'https://arxiv.org/abs/2005.11401',
		),
		array(
			'title'  => 'Dense Passage Retrieval for Open-Domain Question Answering',
			'source' => 'Karpukhin et al., 2020 — arXiv:2004.04906',
			'url'    => 'https://arxiv.org/abs/2004.04906',
		),
		array(
			'title'  => 'Embeddings guide',
			'source' => 'OpenAI — API documentation',
			'url'    => 'https://platform.openai.com/docs/guides/embeddings',
		),
		array(
			'title'  => 'Contextual retrieval',
			'source' => 'Anthropic — engineering documentation',
			'url'    => 'https://www.anthropic.com/news/contextual-retrieval',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'Why grounding matters',
			'lessons' => array(

				array(
					'title'   => 'The problem: confident answers about things it never saw',
					'type'    => 'reading',
					'est_min' => 8,
					'xp'      => 20,
					'topics'  => array( 'Grounding' ),
					'content' => '<h2>A language model is not a database</h2>'
						. '<p>A model predicts likely text. That is a wonderful property for drafting and terrible for facts it was never trained on. Ask about your internal refund policy and it will produce something that <em>sounds</em> exactly like a refund policy — fluent, plausible, and invented.</p>'
						. '<p>Three limits cause this: the model has a <strong>knowledge cutoff</strong>, it never saw your private documents, and it has no built-in way to say "I do not know".</p>'
						. '<h3>The fix: hand it the source</h3>'
						. '<p>You already know the manual version of this. Paste the policy into the chat, then ask your question, and the answer gets dramatically better — because the facts are now <em>in the prompt</em>, not recalled from training.</p>'
						. '<p><strong>Retrieval-augmented generation</strong> automates exactly that. Instead of you pasting the right document, the system searches your knowledge base for the passages most relevant to the question and puts them into the prompt automatically. The model then answers <em>from</em> those passages.</p>'
						. '<blockquote>Do not ask the model to remember. Give it the source and ask it to read.</blockquote>'
						. '<h3>What this changes in practice</h3>'
						. '<p>Grounded answers can cite where they came from, they update the moment you update the document, and they can be told to refuse when the sources do not cover the question. For anyone in {{role}} handling policy, product, or support questions, that difference is the whole ballgame.</p>'
						. '<h3>Recap</h3>'
						. '<p>Models invent because they are predicting, not looking things up. RAG turns "remember this" into "read this" by retrieving the right passages and putting them in the prompt.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why does a general AI assistant give a confident but wrong answer about your internal policy?',
							'options'  => array(
								'The question was too short',
								'It never saw that document, so it predicts plausible-sounding text instead',
								'It refuses private questions by design',
								'Internal policies are always ambiguous',
							),
							'answer'   => 1,
							'hint'     => 'What is the model actually doing when it answers?',
							'feedback_correct'   => 'Exactly — with no access to the document it falls back on producing plausible text.',
							'feedback_incorrect' => 'Not quite — the model never saw your document, so it generates something plausible rather than looking it up.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Putting the relevant source text into the prompt generally produces more reliable answers than relying on the model\'s memory.',
							'answer'   => 0,
							'hint'     => 'Read, don\'t remember.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Automatically finding the right passages and adding them to the prompt is called retrieval-augmented ___.',
							'answer_text' => 'generation',
							'accept'      => array( 'generation (RAG)' ),
							'hint'        => 'The G in RAG.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Name a question people ask in your organisation that a general AI assistant would answer badly, and explain what source it would need to answer it well.',
							'rubric'   => 'A good answer names a genuinely organisation-specific question and identifies the concrete document or data source that would have to be retrieved to answer it.',
						),
					),
				),

				array(
					'title'   => 'How retrieval finds the right passage',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 20,
					'topics'  => array( 'Embeddings & search' ),
					'content' => '<h2>Searching by meaning, not by keyword</h2>'
						. '<p>Classic search matches words. If your policy says "reimbursement window" and the customer asks about "getting money back", a keyword search finds nothing. Retrieval systems solve this with <strong>embeddings</strong>.</p>'
						. '<h3>What an embedding is</h3>'
						. '<p>An embedding turns a piece of text into a list of numbers — a point in space — positioned so that <em>texts with similar meaning land near each other</em>. "Getting money back" ends up close to "reimbursement window" even though they share no words.</p>'
						. '<p>So the retrieval step is: embed every chunk of your documents once, store the results, embed the incoming question, and return the chunks whose points sit nearest to it. That is <strong>semantic search</strong>.</p>'
						. '<h3>The full loop</h3>'
						. '<ol>'
						. '<li>The user asks a question.</li>'
						. '<li>The system retrieves the top few most relevant chunks.</li>'
						. '<li>Those chunks are inserted into the prompt with the question.</li>'
						. '<li>The model answers using them, and cites which chunk it used.</li>'
						. '</ol>'
						. '<h3>Hybrid search</h3>'
						. '<p>Meaning-based search can miss exact strings — product codes, names, error numbers. Strong systems therefore combine semantic search with old-fashioned keyword search, a pattern usually called <strong>hybrid search</strong>. Use both when your content is full of identifiers.</p>'
						. '<h3>Recap</h3>'
						. '<p>Embeddings place text by meaning so retrieval can find the right passage without shared keywords; combining that with keyword search catches exact codes and names.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What does an embedding let a retrieval system do?',
							'options'  => array(
								'Compress documents to save disk space',
								'Find passages by meaning even when they share no keywords',
								'Translate documents into other languages',
								'Guarantee the answer is factually correct',
							),
							'answer'   => 1,
							'hint'     => '"Getting money back" vs "reimbursement window".',
							'feedback_correct'   => 'Right — similar meanings land near each other, so semantic matches are findable.',
							'feedback_incorrect' => 'Not quite — embeddings position text by meaning so related passages can be found without shared words.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Semantic search alone is always better than keyword search, so exact product codes are never a problem.',
							'answer'   => 1,
							'hint'     => 'Why do teams use hybrid search?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Combining semantic search with keyword search is usually called ___ search.',
							'answer_text' => 'hybrid',
							'accept'      => array(),
							'hint'        => 'Both approaches at once.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Grounding & retrieval — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which best describes the retrieve-then-generate loop?',
						'options'  => array(
							'Retrain the model on your documents before every question',
							'Find the most relevant passages, put them in the prompt, then answer from them',
							'Ask the model to guess and then check it manually',
							'Translate the question into keywords and return links only',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Turning text into numbers positioned by meaning produces an ___.',
						'answer_text' => 'embedding',
						'accept'      => array( 'embeddings' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'A grounded answer can be updated simply by updating the underlying document.',
						'answer'   => 0,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Making it work in practice',
			'lessons' => array(

				array(
					'title'   => 'Chunking and source hygiene',
					'type'    => 'practice',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Chunking' ),
					'content' => '<h2>Most RAG failures are content failures</h2>'
						. '<p>When a grounded assistant gives bad answers, the retrieval step is usually fine — the <em>content</em> it was pointed at was the problem. Two things dominate: how documents are split, and what is in the knowledge base at all.</p>'
						. '<h3>Chunking</h3>'
						. '<p>Documents are split into <strong>chunks</strong> before embedding, because you want to hand the model the relevant paragraph, not a 90-page PDF. The trade-off is simple:</p>'
						. '<ul>'
						. '<li><strong>Too small</strong> — a chunk ends mid-explanation and loses the context that made it meaningful.</li>'
						. '<li><strong>Too large</strong> — one chunk covers five topics, so its "meaning" is muddy and retrieval gets vague.</li>'
						. '</ul>'
						. '<p>Practical defaults: split on natural boundaries (headings, sections), keep chunks to roughly a few hundred words, and let consecutive chunks <strong>overlap</strong> slightly so a sentence split across a boundary is not orphaned. Keep the source title and section with each chunk so answers can cite them.</p>'
						. '<h3>Source hygiene</h3>'
						. '<p>A knowledge base containing three versions of the same policy will confidently cite the wrong one. Remove superseded documents, mark effective dates, and prefer one authoritative source per topic. If you would not want a new colleague to read a document as current, do not index it.</p>'
						. '<blockquote>Retrieval is only as good as the library it searches.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Split on natural boundaries with a little overlap, carry titles for citation, and keep exactly one authoritative, current version of each document in the index.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A knowledge base contains an old and a new version of the same policy. What is the likely result?',
							'options'  => array(
								'The system always picks the newer file',
								'It may retrieve and confidently cite the outdated version',
								'Retrieval stops working entirely',
								'The two versions are merged automatically',
							),
							'answer'   => 1,
							'hint'     => 'Retrieval matches meaning, not recency, unless you tell it to.',
							'feedback_correct'   => 'Correct — both look equally relevant, so the stale one can win.',
							'feedback_incorrect' => 'Not quite — nothing prefers the newer file by default, so the outdated one may be cited.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Chunks that are far too large make retrieval vaguer, because one chunk covers many different topics.',
							'answer'   => 0,
							'hint'     => 'What happens to a chunk\'s "meaning" when it spans five subjects?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Letting consecutive chunks share a little text at their boundaries is called ___.',
							'answer_text' => 'overlap',
							'accept'      => array( 'overlapping' ),
							'hint'        => 'It stops a split sentence from being orphaned.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Pick a real document set from your work that you would want an AI assistant to answer from. Write a short plan: how you would split it into chunks, what metadata you would keep for citation, and which documents you would exclude.',
							'rubric' => 'A strong answer proposes splitting on natural boundaries with a sensible size, keeps title/section metadata for citations, and names concrete exclusions such as superseded or draft documents.',
						),
					),
				),

				array(
					'title'   => 'Citations, refusal, and evaluating answers',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 25,
					'topics'  => array( 'Citations & evaluation' ),
					'content' => '<h2>Grounded is not the same as correct</h2>'
						. '<p>RAG dramatically reduces invention, but it does not eliminate it. A model can still misread a passage, blend two sources, or answer confidently from a chunk that was retrieved but irrelevant. So you check.</p>'
						. '<h3>Three instructions that do most of the work</h3>'
						. '<ul>'
						. '<li><strong>Answer only from the provided sources.</strong> Explicitly forbid outside knowledge.</li>'
						. '<li><strong>Cite the source for each claim.</strong> Citations make verification cheap — and make it obvious when a claim has none.</li>'
						. '<li><strong>Refuse when the sources do not cover it.</strong> "Not addressed in the provided documents" is a correct, useful answer.</li>'
						. '</ul>'
						. '<h3>How to evaluate a grounded assistant</h3>'
						. '<p>Do not judge it on vibes. Build a small set of real questions with known answers — twenty is enough to start — and check three things per answer: was the <em>right passage retrieved</em>; is the answer <em>supported by that passage</em>; and does it <em>refuse correctly</em> when the answer genuinely is not in the knowledge base. Re-run the set whenever you change chunking, sources, or the model.</p>'
						. '<blockquote>A citation you never check is decoration. Spot-check them and the system stays honest.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Instruct the system to answer only from sources, cite every claim, and refuse when coverage is missing — then verify with a small fixed question set covering retrieval, support, and refusal.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which instruction most directly reduces invented claims in a grounded assistant?',
							'options'  => array(
								'"Be as detailed as possible."',
								'"Answer only from the provided sources, and say so if they do not cover it."',
								'"Answer quickly."',
								'"Sound confident."',
							),
							'answer'   => 1,
							'hint'     => 'Restrict the source of truth and permit refusal.',
							'feedback_correct'   => 'Exactly — restricting to the sources plus permitting refusal is the core guardrail.',
							'feedback_incorrect' => 'Not quite — the key is restricting the model to the provided sources and letting it decline.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Because RAG grounds answers in real documents, its answers never need to be verified.',
							'answer'   => 1,
							'hint'     => 'A model can still misread or blend passages.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'When the knowledge base does not cover a question, the correct behaviour is to ___ rather than guess.',
							'answer_text' => 'refuse',
							'accept'      => array( 'say so', 'decline' ),
							'hint'        => '"Not addressed in the provided documents."',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Practical RAG — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which is the best chunking practice for a policy handbook?',
						'options'  => array(
							'One chunk for the whole handbook',
							'Split on headings/sections into moderate chunks with slight overlap, keeping titles for citation',
							'One chunk per sentence, with no metadata',
							'Split randomly every 5,000 characters',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Keeping several versions of the same document in the index risks the assistant citing an outdated one.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'To evaluate a grounded assistant, build a fixed set of real questions with known answers and check retrieval, support, and ___.',
						'answer_text' => 'refusal',
						'accept'      => array( 'refusals' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'An answer cites a source, but the cited passage does not actually contain the claim. What does this tell you?',
						'options'  => array(
							'Citations are unnecessary',
							'Grounding does not guarantee correctness — citations must be spot-checked',
							'The knowledge base is too small',
							'The model needs a longer answer limit',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
