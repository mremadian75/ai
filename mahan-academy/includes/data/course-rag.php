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
	'track'       => 'generative-ai',
	'level_rank'  => 3,
	'level'       => 'advanced',
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
						array(
							'type'     => 'short_answer',
							'question' => 'A search for "SSO login failure" returns passages about single sign-on setup but misses the one troubleshooting page that only ever says "SAML authentication error". Explain what went wrong and what would fix it.',
							'rubric'   => 'A strong answer explains that semantic search matched on general topic similarity while the exact vocabulary differed, and proposes concrete fixes: hybrid search combining keyword matching, indexing synonyms or acronym expansions, or retrieving more candidates and re-ranking them.',
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
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write the answering prompt for a RAG system over your company handbook. It must answer only from the retrieved passages, cite the source of every claim, and refuse cleanly when the passages do not cover the question.',
							'rubric' => 'A strong answer fences the retrieved passages as data, restricts the answer strictly to their contents, requires an inline citation per claim, and gives explicit wording for the refusal case so it cannot be confused with a real answer. It should also handle passages that contradict each other rather than assuming they agree.',
							'hint'   => 'What should it do when two retrieved passages disagree?',
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

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'When retrieval is the thing that is broken',
			'lessons' => array(

				array(
					'title'   => 'Diagnosing a bad answer: retrieval or generation?',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 30,
					'topics'  => array( 'Embeddings & search', 'Grounding' ),
					'content' => '<h2>Two failures that look identical to the user</h2>'
						. '<p>A RAG system gives a wrong answer. Almost everyone\'s first move is to edit the prompt. That fixes about half the cases and wastes time on the other half, because there are two entirely separate failures wearing the same face.</p>'
						. '<p><strong>Retrieval failure</strong> — the right passage was never fetched, so the model was asked to answer from material that did not contain the answer.</p>'
						. '<p><strong>Generation failure</strong> — the right passage was fetched and the model still got it wrong: ignored it, misread it, or blended it with its own training.</p>'
						. '<h3>The one-minute test</h3>'
						. '<p>Look at what was retrieved. Does the answer appear in those passages, to a human reading them?</p>'
						. '<ul>'
						. '<li><strong>No</strong> → retrieval problem. The prompt is irrelevant. Fix chunking, the query, the index or the number of passages returned.</li>'
						. '<li><strong>Yes</strong> → generation problem. Now the prompt matters: tighten the instruction to answer only from the passages, require a quotation, lower the temperature.</li>'
						. '</ul>'
						. '<p>This is why logging the retrieved passages alongside every answer is not optional. Without it you are guessing, and you will guess wrong half the time.</p>'
						. '<h3>Why retrieval misses</h3>'
						. '<p><strong>Vocabulary.</strong> The document says "SAML authentication error"; the user typed "SSO login broken". Semantically close, lexically distant — the classic case for hybrid search.</p>'
						. '<p><strong>The answer is split.</strong> The condition is in one chunk and the exception in the next, and only one was retrieved. Overlapping chunks help.</p>'
						. '<p><strong>Too few passages.</strong> Top-3 is a common default and often one short.</p>'
						. '<p><strong>It is not there.</strong> The honest one. No amount of tuning retrieves a document nobody wrote, and the correct outcome is a refusal.</p>'
						. '<blockquote>Never tune a prompt before you have read what was retrieved. Half the time you are fixing the wrong component.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Separate the two failures by reading the retrieved passages. Retrieval problems are fixed in the index; generation problems are fixed in the prompt. Log the passages so the question is answerable at all.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A RAG answer is wrong. What should you look at first?',
							'options'  => array(
								'The system prompt',
								'The passages that were actually retrieved',
								'The model version',
								'The temperature setting',
							),
							'answer'   => 1,
							'hint'     => 'It decides which of the two components is at fault.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'If the correct passage was retrieved and the answer is still wrong, the fix belongs in retrieval.',
							'answer'   => 1,
							'hint'     => 'The material was there — which component failed?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A condition in one chunk and its exception in the next is fixed by giving chunks some ___.',
							'answer_text' => 'overlap',
							'accept'      => array( 'overlapping' ),
							'hint'        => 'So a boundary does not split a rule.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Users report your handbook assistant "makes things up about parental leave". Write the investigation you would run, in order, and say what each step would tell you.',
							'rubric' => 'A strong answer starts by collecting real failing questions and inspecting the retrieved passages for each, then branches: if the policy text was not retrieved it investigates chunking, vocabulary mismatch and the number of passages; if it was retrieved it tightens the answering prompt and requires citation. It should also allow for the honest case that the policy is genuinely not in the corpus, where the correct behaviour is refusal.',
							'hint'   => 'The first step decides which half of the system you are debugging.',
						),
					),
				),

				array(
					'title'   => 'Keeping the corpus honest',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Chunking', 'Grounding' ),
					'content' => '<h2>A grounded system is only as good as what it is grounded in</h2>'
						. '<p>RAG answers faithfully from your documents. If your documents contain three versions of the expenses policy, two of them obsolete, the system will answer faithfully from the wrong one — and it will cite its source while doing it, which makes the wrong answer more convincing than an ungrounded guess.</p>'
						. '<h3>The failure nobody plans for: stale duplicates</h3>'
						. '<p>Real document stores accumulate. The 2022 policy, the 2024 revision, a draft someone shared, and a slide summarising an older version all sit in the same folder. Semantic search cannot tell which is current — they all match the query beautifully.</p>'
						. '<p>Fixes, in order of how much they help:</p>'
						. '<ul>'
						. '<li><strong>Curate the index.</strong> Not every document your company owns belongs in it. A deliberately small, current corpus outperforms a large one full of history.</li>'
						. '<li><strong>Keep the date and status as metadata</strong> and prefer or filter on them at retrieval time.</li>'
						. '<li><strong>Show the source and its date</strong> in every answer, so a human can spot a 2022 citation immediately.</li>'
						. '<li><strong>Remove superseded documents</strong> from the index — archived, not indexed, is a perfectly good state.</li>'
						. '</ul>'
						. '<h3>Permissions are a retrieval concern</h3>'
						. '<p>If your index contains documents not everyone may read, filtering has to happen at retrieval, per user — not by asking the model nicely to withhold things. A model instructed to keep a secret it can see is one clever question away from not keeping it. Never index content the asker is not entitled to, or scope the search to what they may access.</p>'
						. '<h3>Re-indexing is an ongoing job</h3>'
						. '<p>Documents change. An index built once drifts from the source silently — no error, just increasingly wrong answers. Decide the refresh cadence when you build it, not when someone complains.</p>'
						. '<blockquote>Grounding transfers trust from the model to your documents. That is only an improvement if your documents deserve it.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Curate rather than dump, carry dates and status as metadata, show sources with dates, filter by permission at retrieval, and schedule re-indexing before anyone has to ask for it.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Your index holds the 2022 and 2024 expenses policies. Why is this particularly dangerous?',
							'options'  => array(
								'It doubles the storage cost',
								'The system may answer confidently from the obsolete one and cite it',
								'Semantic search cannot handle two documents',
								'The model will refuse to answer',
							),
							'answer'   => 1,
							'hint'     => 'What does a citation do to the reader\'s trust?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Instructing the model not to reveal restricted documents is an adequate substitute for filtering at retrieval.',
							'answer'   => 1,
							'hint'     => 'What can it disclose if it can see it?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A superseded document should be archived but not ___, so retrieval can never reach it.',
							'answer_text' => 'indexed',
							'accept'      => array( 'in the index' ),
							'hint'        => 'Two different states.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'You are indexing a shared drive of 40,000 files for a company assistant. Name three things you would exclude and say why each would cause a wrong answer.',
							'rubric'   => 'A strong answer excludes categories with concrete failure modes — superseded policy versions that would be cited as current, personal or HR files the asker may not be entitled to see, and drafts or personal notes that read as authoritative but were never approved. It should tie each exclusion to the specific wrong answer it prevents rather than listing file types.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'When retrieval is broken — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Logging the retrieved passages with every answer matters mainly because:',
						'options'  => array(
							'It reduces cost',
							'Without it you cannot tell a retrieval failure from a generation failure',
							'It speeds up search',
							'It is required by the model',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'A deliberately small, current corpus usually beats a large one full of superseded history.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Access control in a RAG system belongs at ___ time, per user, not in the prompt.',
						'answer_text' => 'retrieval',
						'accept'      => array( 'search', 'query' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'The document says "SAML authentication error", the user asks about "SSO login broken". This is a case for:',
						'options'  => array(
							'A larger model',
							'Hybrid search combining keyword and semantic matching',
							'Lowering the temperature',
							'A longer system prompt',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Proving it works, and knowing when not to build it',
			'lessons' => array(

				array(
					'title'   => 'Measuring a RAG system honestly',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 30,
					'topics'  => array( 'Citations & evaluation', 'Grounding' ),
					'content' => '<h2>"It seems good in the demo" is where most RAG projects stop</h2>'
						. '<p>Grounded systems demo beautifully. Someone asks three questions they already know the answers to, the citations look convincing, and the project is declared a success. Then it meets real questions from people who cannot check the answers.</p>'
						. '<p>Because RAG has two components, it needs two measurements, and an overall score hides which half is failing.</p>'
						. '<h3>Measure retrieval separately</h3>'
						. '<p>Take fifty real questions and, by hand, record which document actually answers each. Then ask: how often is the right document in what was retrieved? That number is retrieval quality, and it is the ceiling on everything else — the generator cannot use what it never received.</p>'
						. '<h3>Then measure the answer</h3>'
						. '<p>For each answer, judge three things separately:</p>'
						. '<ul>'
						. '<li><strong>Faithful</strong> — is every claim supported by the retrieved passages? This is the one that matters most, because an unfaithful answer is exactly the failure RAG exists to prevent.</li>'
						. '<li><strong>Correct</strong> — is it actually right? A faithful answer from an outdated document is faithful and wrong.</li>'
						. '<li><strong>Refused when it should</strong> — feed it questions your corpus genuinely does not cover and check that it declines rather than improvises. Most teams never test this, and it is where the reputational damage lives.</li>'
						. '</ul>'
						. '<h3>Build the set from real questions</h3>'
						. '<p>Invented test questions are always tidier than real ones. Take actual queries from your support inbox, including the vague, the misspelt, and the ones asking two things at once. Every failure that reaches production earns a permanent place in the set.</p>'
						. '<blockquote>Faithful, correct, and correctly refused. A system scoring well on the first two and never refusing anything is not safe — it is untested.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Measure retrieval and generation separately, judge faithfulness apart from correctness, and deliberately test the questions your documents cannot answer.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why measure retrieval separately from the final answer?',
							'options'  => array(
								'It is cheaper',
								'Retrieval quality is the ceiling on answer quality, and an overall score hides which half failed',
								'Generation cannot be measured',
								'To reduce the number of test questions',
							),
							'answer'   => 1,
							'hint'     => 'The generator cannot use what it never received.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'An answer that is faithful to the retrieved passages is necessarily correct.',
							'answer'   => 1,
							'hint'     => 'What if the passage is out of date?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Test questions your corpus genuinely cannot answer, to check the system ___ rather than improvises.',
							'answer_text' => 'refuses',
							'accept'      => array( 'declines' ),
							'hint'        => 'The test most teams skip.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Your RAG assistant answers 92% of test questions correctly. What single additional number would you most want, and why?',
							'rubric'   => 'A strong answer asks for behaviour on out-of-scope questions — the refusal rate on questions the corpus cannot answer — because a high correctness score on answerable questions says nothing about whether the system invents answers when it should decline, which is the failure that damages trust. Asking for the retrieval hit rate separately is an equally strong answer if justified as the ceiling.',
						),
					),
				),

				array(
					'title'   => 'When not to build RAG',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 30,
					'topics'  => array( 'Grounding', 'Chunking', 'Citations & evaluation' ),
					'content' => '<h2>The most expensive RAG system is the one that should have been a search box</h2>'
						. '<p>RAG is genuinely the right answer for a large body of documents and open-ended questions. It is also proposed constantly for problems it does not fit, at considerable cost.</p>'
						. '<h3>Four cases where something simpler wins</h3>'
						. '<p><strong>The corpus is small and stable.</strong> Twenty pages of policy fit in a modern context window. Paste them. No index, no chunking, no retrieval failures — and the model sees everything rather than a guessed subset.</p>'
						. '<p><strong>The answer is structured data.</strong> "How many orders shipped late last month?" is a database query. A retrieval system searching prose for a number will do it slowly, expensively and wrongly. Give the model a tool that queries the database instead.</p>'
						. '<p><strong>People know what they are looking for.</strong> If users want <em>the document</em> rather than an answer, they want search. A good search box returning three ranked links is faster, cheaper, and lets them see the source in full.</p>'
						. '<p><strong>Precision is legally required.</strong> Where an approximate paraphrase is unacceptable — regulatory text, safety procedures, contract clauses — the right output is the passage itself, quoted and linked, not a summary of it.</p>'
						. '<h3>What RAG is genuinely for</h3>'
						. '<p>A large, changing corpus; questions that span several documents; users who want an answer rather than a reading list; and a domain where a synthesised response saves real time. When all four hold, RAG earns its complexity comfortably.</p>'
						. '<h3>The honest sequence</h3>'
						. '<p>Try the search box. Then try pasting the documents. Then try a tool call. If those genuinely fail — and you can say how — build the retrieval system, with the evaluation set from the previous lesson ready before you start.</p>'
						. '<blockquote>Every component you add is a component that can fail silently. Add them when something cheaper has demonstrably failed, not in anticipation.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>You now know why grounding matters, how retrieval works, how to chunk, how to handle citations and refusal, how to diagnose failures, how to keep a corpus honest, how to measure the whole thing — and when to build none of it.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => '"How many orders shipped late last month?" is best answered by:',
							'options'  => array(
								'Retrieval over your documents',
								'A tool that queries the database',
								'A larger context window',
								'Better chunking',
							),
							'answer'   => 1,
							'hint'     => 'What kind of data holds that answer?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'If the corpus is small and stable, you can often skip retrieval entirely and just ___ the documents into the context.',
							'answer_text' => 'paste',
							'accept'      => array( 'put', 'include' ),
							'hint'        => 'Twenty pages fits comfortably.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'When users want to find a specific document rather than get an answer, RAG is the right tool.',
							'answer'   => 1,
							'hint'     => 'What do they actually want back?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'A stakeholder asks for "an AI that knows our company". Write the reply that turns it into a real decision: the questions you need answered, and the three cheaper options you would rule out first.',
							'rubric' => 'A strong answer asks what questions people actually ask, how large and how volatile the corpus is, whether users want an answer or a document, and what the cost of a wrong answer is — then explicitly proposes ruling out search, pasting a small corpus, and a database tool call before committing to retrieval. It should mention having an evaluation set ready before building.',
							'hint'   => 'Turn a wish into a specification, and start from the cheapest option.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'Thinking about a document-heavy problem in your own organisation — would RAG genuinely help, or would something simpler? Say what decides it.',
							'rubric'   => 'A thoughtful answer applies the four criteria (corpus size and volatility, cross-document questions, answer versus document, value of synthesis) to a real situation and reaches a definite position, rather than concluding that it depends.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Proving it works — quiz',
				'passing'   => 70,
				'xp'        => 40,
				'questions' => array(
					array(
						'type'        => 'fill_blank',
						'question'    => 'Whether every claim is supported by the retrieved passages is called ___.',
						'answer_text' => 'faithfulness',
						'accept'      => array( 'faithful', 'groundedness' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'A twenty-page stable policy set is usually better pasted into the context than indexed for retrieval.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which is the strongest sign RAG is the right tool?',
						'options'  => array(
							'The corpus is large and changing, and questions span several documents',
							'Everyone already knows which document they need',
							'The answers live in a database',
							'The documents are legally binding and must be quoted exactly',
						),
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'A system that scores well on correctness but has never been tested on out-of-scope questions is:',
						'options'  => array(
							'Ready to deploy',
							'Untested where it matters most — it may invent rather than refuse',
							'Guaranteed to refuse correctly',
							'Only a retrieval problem',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
