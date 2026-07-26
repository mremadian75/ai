<?php
/**
 * Seed course: "Generative AI, Explained" (Generative AI bundle).
 *
 * Follows the canonical schema contract defined in course-pe-foundations.php.
 * The seeder ({@see Mahan_Seed}) loads each `course-*.php` and expects exactly
 * that shape: units -> lessons -> exercises, plus an optional unit quiz.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'genai-foundations',
	'title'       => 'Generative AI, Explained',
	'slug'        => 'genai-foundations',
	'subtitle'    => 'What generative AI is, the families of tools, and how to use them well without getting burned.',
	'excerpt'     => 'A plain-English tour of generative AI — how it differs from older AI, the main families of tools, where it goes wrong, and how to use it responsibly.',
	'description' => '<p>Generative AI went from a curiosity to an everyday tool almost overnight, and the hype makes it hard to tell what it actually does. This course cuts through the noise with a clear, jargon-free explanation of what these tools really are.</p>'
		. '<p>You will learn how generative AI differs from the AI that came before it, meet the main families of tools for text, images, audio, and code, and — most importantly — understand where they fail so you can use them confidently and safely at work and at home.</p>',
	'category'    => 'Generative AI',
	'track'       => 'generative-ai',
	'level_rank'  => 1,
	'level'       => 'beginner',
	'est_hours'   => 3,
	'featured'    => true,
	'certificate' => true,
	'order'       => 1,
	'topics'      => array( 'Generating vs classifying', 'GenAI families', 'Hallucinations & limits', 'Responsible use' ),
	'outcomes'    => array(
		'Explain how generative AI differs from older classifying AI',
		'Identify the main families of generative tools and match one to a job',
		'Recognise a hallucination and verify AI output before trusting it',
		'Protect private data when using public AI tools',
		'Apply responsible-use habits like human oversight and giving credit',
	),
	'references'  => array(
		array(
			'title'  => 'Introduction to Large Language Models',
			'source' => 'Google — Machine Learning Education',
			'url'    => 'https://developers.google.com/machine-learning/resources/intro-llms',
		),
		array(
			'title'  => 'On the Dangers of Stochastic Parrots: Can Language Models Be Too Big?',
			'source' => 'Bender, Gebru et al., FAccT 2021',
			'url'    => 'https://dl.acm.org/doi/10.1145/3442188.3445922',
		),
		array(
			'title'  => 'Recommendation on the Ethics of Artificial Intelligence',
			'source' => 'UNESCO, 2021',
			'url'    => 'https://www.unesco.org/en/artificial-intelligence/recommendation-ethics',
		),
		array(
			'title'  => 'AI Index Report',
			'source' => 'Stanford HAI',
			'url'    => 'https://aiindex.stanford.edu/report/',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'What generative AI is',
			'lessons' => array(

				array(
					'title'   => 'Generating vs. classifying',
					'type'    => 'reading',
					'est_min' => 8,
					'xp'      => 20,
					'topics'  => array( 'Generating vs classifying' ),
					'content' => '<h2>Two very different jobs</h2>'
						. '<p>For most of its history, everyday AI was a <strong>sorter</strong>. You handed it something and it put a label on it: this email is spam or not spam, this photo contains a cat or a dog, this transaction looks fraudulent. That kind of AI is called <em>classifying</em> (or predicting). It never makes anything new — it chooses from a fixed set of answers you defined in advance.</p>'
						. '<h3>Generative AI creates</h3>'
						. '<p><strong>Generative AI</strong> does the opposite. Instead of labelling an input, it produces brand-new content: a paragraph of text, an image, a snippet of code, a piece of audio. Ask it to write a birthday poem or draft a product description and it composes something that did not exist a moment ago.</p>'
						. '<blockquote>Classifying AI answers "which bucket does this go in?" Generative AI answers "make me something new."</blockquote>'
						. '<h3>How it builds an answer</h3>'
						. '<p>A useful intuition: a text model works a bit like a very well-read autocomplete. Having seen enormous amounts of writing, it predicts the <strong>most likely next piece</strong> of text, one small chunk at a time, given everything so far. String enough of those predictions together and you get a fluent sentence, then a paragraph, then an essay.</p>'
						. '<h3>Fluent is not the same as true</h3>'
						. '<p>This prediction trick is why the output <em>reads</em> so smoothly — it is literally optimised to sound like natural, plausible language. But "sounds plausible" and "is correct" are not the same thing. The model is chasing likely words, not verified facts, so a confident, well-written answer can still be wrong. Keep that gap in mind; it is the theme of Unit 2.</p>'
						. '<h3>Recap</h3>'
						. '<p>Older AI mostly sorted things into labels. Generative AI makes new content by predicting likely next pieces. That makes it wonderfully fluent — and means fluency is never a guarantee of truth.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What is the key difference between classifying AI and generative AI?',
							'options'  => array(
								'Classifying AI is newer than generative AI',
								'Classifying AI only works with images',
								'Classifying AI labels an input, while generative AI creates new content',
								'They are two names for exactly the same thing',
							),
							'answer'   => 2,
							'hint'     => 'Think "which bucket?" versus "make me something new."',
							'feedback_correct'   => 'Right — classifying picks a label, generative produces something new.',
							'feedback_incorrect' => 'Not quite. The core split is labelling an input versus generating fresh content.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A text model builds its answer by predicting the most likely next piece of text, one chunk at a time.',
							'answer'   => 0,
							'hint'     => 'Think of a very well-read autocomplete.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Older AI that sorts an input into a fixed set of labels is doing ___, not generating.',
							'answer_text' => 'classifying',
							'accept'      => array( 'classification', 'classify', 'labelling', 'labeling' ),
							'hint'        => 'The opposite of creating new content.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'In your own words, why can a generative model produce an answer that sounds convincing but is still factually wrong?',
							'rubric'   => 'A good answer notes that the model predicts likely-sounding text rather than checking facts, so fluent output is optimised for plausibility, not truth.',
						),
					),
				),

				array(
					'title'   => 'The families: text, image, audio, code',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'GenAI families' ),
					'content' => '<h2>Different tools for different kinds of output</h2>'
						. '<p>Generative AI is not one product — it is a set of families grouped by what they produce. Knowing the families helps you reach for the right tool instead of forcing everything through a chatbot.</p>'
						. '<h3>Text</h3>'
						. '<p>The most familiar family. A <strong>writing assistant</strong> drafts emails, summarises long reports, rewrites a clumsy paragraph, or answers questions in plain language. If the job is words, this is your starting point.</p>'
						. '<h3>Image</h3>'
						. '<p>An <strong>image generator</strong> turns a written description into a picture — a logo concept, an illustration for a slide deck, a mock-up of a room design. You describe what you want and it renders a visual version.</p>'
						. '<h3>Audio</h3>'
						. '<p>The audio family covers <strong>speech and sound</strong>: reading text aloud in a natural voice, transcribing a recorded meeting into text, or generating background music. Great for accessibility, podcasts, and voice notes.</p>'
						. '<h3>Code</h3>'
						. '<p>A <strong>coding copilot</strong> writes and explains software. It can draft a function, spot a bug, or translate an idea like "sort this spreadsheet by date" into working code — a huge help for programmers and curious beginners alike.</p>'
						. '<h3>Multimodal tools combine them</h3>'
						. '<p>Many modern tools are <strong>multimodal</strong>, meaning they handle more than one type of content at once. You might show one a photo and ask a question about it in text, or hand it a chart and ask for a written summary. Multimodal simply means the tool understands and mixes several formats — text, images, audio — together.</p>'
						. '<h3>Matching a tool to a job</h3>'
						. '<p>Start from the output you need. Need words? Text. Need a picture? Image. Need a voiceover or a transcript? Audio. Need software? Code. Working across formats — a photo plus a question — points to a multimodal tool.</p>'
						. '<h3>Recap</h3>'
						. '<p>The families are text, image, audio, and code, and multimodal tools blend them. Pick by the kind of output you are after.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'You want to turn a written description into a picture for a slide. Which family fits best?',
							'options'  => array(
								'An image generator',
								'A meeting transcriber',
								'A spam classifier',
								'A text summariser',
							),
							'answer'   => 0,
							'hint'     => 'Match the tool to the kind of output you need.',
							'feedback_correct'   => 'Correct — describing something you want drawn is the image family.',
							'feedback_incorrect' => 'Not quite. Producing a picture from a description is the job of an image generator.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A tool that can handle more than one type of content at once — such as a photo plus a text question — is called ___.',
							'answer_text' => 'multimodal',
							'accept'      => array( 'multi-modal', 'multi modal' ),
							'hint'        => 'The prefix means "many"; the root means "type of media".',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A coding copilot can draft a function and help explain or fix a bug.',
							'answer'   => 0,
							'hint'     => 'The code family writes and explains software.',
						),
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which task is the best fit for the audio family of generative tools?',
							'options'  => array(
								'Rewriting a clumsy paragraph',
								'Generating a logo concept',
								'Transcribing a recorded meeting into text',
								'Sorting emails into spam or not spam',
							),
							'answer'   => 2,
							'hint'     => 'Audio covers speech and sound, in both directions.',
							'feedback_correct'   => 'Yes — turning speech into text is an audio-family job.',
							'feedback_incorrect' => 'Not quite. Transcription (speech to text) belongs to the audio family.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Pick one task from your own work and say which family of generative model fits it, what you would have to supply, and what could go wrong.',
							'rubric'   => 'A strong answer names a specific task, matches it to the right family (text, image, audio or code), states concretely what input the model would need from the person, and identifies a realistic failure — invented detail, an unusable output shape, or a rights or privacy issue — rather than a generic caution.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'What generative AI is — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which statement best describes classic (classifying) AI compared with generative AI?',
						'options'  => array(
							'Classic AI creates new images from scratch',
							'Classic AI sorts inputs into labels, while generative AI produces new content',
							'Classic AI is always multimodal',
							'Classic AI predicts the next word one chunk at a time',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which of these is an example of a generative task?',
						'options'  => array(
							'Flagging a transaction as fraud or not fraud',
							'Deciding whether a photo shows a cat or a dog',
							'Drafting an original product description from a short brief',
							'Marking an email as spam',
						),
						'answer'   => 2,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'A tool that can work with several formats at once — like an image and a text question together — is described as ___.',
						'answer_text' => 'multimodal',
						'accept'      => array( 'multi-modal', 'multi modal' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Generative AI produces new content rather than only choosing from a fixed set of labels.',
						'answer'   => 0,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Using it well and safely',
			'lessons' => array(

				array(
					'title'   => 'Strengths, limits, and hallucinations',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Hallucinations & limits' ),
					'content' => '<h2>Play to its strengths, respect its limits</h2>'
						. '<p>Generative AI is genuinely powerful, but it is powerful at some things and weak at others. Knowing which is which is the difference between a useful assistant and an expensive mistake.</p>'
						. '<h3>What it is great at</h3>'
						. '<p>It shines at <strong>first drafts, brainstorming, and summaries</strong>. Ask it to outline a blog post, list ten campaign ideas, rephrase a stiff email, or condense a long document into three bullets, and it will save you real time. When there is no single "right" answer and you just need a strong starting point, it is excellent.</p>'
						. '<h3>What it is weak at</h3>'
						. '<p>It is unreliable on things that demand precision: <strong>specific facts, exact figures, real citations, breaking news, and careful maths</strong>. It has no live connection to today unless a tool provides it, and it can get arithmetic wrong while sounding certain. Treat every hard fact as unverified.</p>'
						. '<h3>What a hallucination is</h3>'
						. '<p>A <strong>hallucination</strong> is when the model states something false as if it were true — an invented statistic, a fake quote, a book or legal case that does not exist. It is not lying; it is predicting plausible-sounding text, and sometimes the most plausible-sounding thing simply is not real.</p>'
						. '<blockquote>The danger is not that AI is sometimes wrong. It is that it is wrong in a confident, fluent, professional-looking way.</blockquote>'
						. '<h3>Verify, then trust</h3>'
						. '<p>The habit that keeps you safe is simple: <strong>verify before you trust</strong>. Check names, numbers, dates, and quotes against a reliable source before you send or publish. Ask the model for its sources, then confirm they are real.</p>'
						. '<h3>Keep a human in the loop</h3>'
						. '<p>Use AI to draft and to speed you up, but keep a person — you — responsible for the final result. The model is a fast assistant, not the accountable author.</p>'
						. '<h3>Recap</h3>'
						. '<p>Lean on generative AI for drafts, ideas, and summaries; distrust it on facts, figures, and fresh events; watch for confident hallucinations; and always verify before you trust.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which task is generative AI LEAST reliable for on its own?',
							'options'  => array(
								'Brainstorming ten campaign ideas',
								'Rewriting a paragraph in a friendlier tone',
								'Summarising a document you pasted in',
								'Giving the exact, current population of a city',
							),
							'answer'   => 3,
							'hint'     => 'Which one demands a precise, up-to-date fact?',
							'feedback_correct'   => 'Right — precise, current facts are exactly where it is weakest.',
							'feedback_incorrect' => 'Not quite. It is strong at drafts and summaries but weak on exact, current facts.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'When a model confidently states something false, such as an invented statistic or a fake citation, that is called a ___.',
							'answer_text' => 'hallucination',
							'accept'      => array( 'hallucinations', 'hallucinating' ),
							'hint'        => 'A confident but made-up "fact".',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Because AI output reads fluently and confidently, you can safely publish its facts without checking them.',
							'answer'   => 1,
							'hint'     => 'Fluent is not the same as correct.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Describe a real task from your work where you would use generative AI for a first draft, and write one concrete step you would take to verify its output before trusting it.',
							'rubric' => 'A strong answer names a suitable drafting/brainstorming/summarising task and gives a specific verification step, such as checking figures or sources against a reliable reference.',
							'hint'   => 'Pick something with no single right answer for the draft, then say what you would fact-check.',
						),
					),
				),

				array(
					'title'   => 'Privacy, bias, and responsible use',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Responsible use' ),
					'content' => '<h2>Powerful tools come with responsibilities</h2>'
						. '<p>Using generative AI well is not only about getting good output — it is about not causing harm along the way. Three habits cover most of it: protect data, watch for bias, and stay accountable.</p>'
						. '<h3>Do not paste secrets into public tools</h3>'
						. '<p>Anything you type into a public AI tool may be stored or reviewed, so treat it like a postcard, not a sealed letter. <strong>Never paste passwords, customer records, unreleased financials, health details, or other personal or confidential information</strong> into a public chatbot. If you need AI on sensitive data, use an approved, private tool that your organisation has cleared for that purpose.</p>'
						. '<blockquote>If you would not read it aloud to a stranger, do not paste it into a public AI tool.</blockquote>'
						. '<h3>Bias can show up in the output</h3>'
						. '<p>Models learn from huge collections of human-made text and images, and human material carries human <strong>bias</strong>. That bias can surface in the output — skewed assumptions about who does which job, uneven quality across languages or cultures, stereotyped images. Read AI output with a critical eye, especially when it describes groups of people or informs a decision about them.</p>'
						. '<h3>Give credit and keep oversight</h3>'
						. '<p>Be honest about AI\'s role. If AI helped write or make something, do not pass it off as entirely your own where that matters, and never present AI output as expert advice it is not qualified to give. You remain <strong>responsible</strong> for anything you send out under your name — the model is a tool, not the author.</p>'
						. '<h3>Know your organisation\'s policy</h3>'
						. '<p>Many workplaces and schools now have rules about which AI tools are allowed and what data may go into them. Before you use AI for work, <strong>check the policy</strong> — it protects you, your colleagues, and the people whose data you handle.</p>'
						. '<h3>Recap</h3>'
						. '<p>Keep secrets and personal data out of public tools, stay alert to bias in what the model produces, give credit and keep human oversight, and follow your organisation\'s AI policy.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which is the safest habit when using a public AI chatbot at work?',
							'options'  => array(
								'Paste in full customer records so the answer is accurate',
								'Keep passwords and personal or confidential data out of it',
								'Share unreleased financials to get better forecasts',
								'Upload the employee database for a quick summary',
							),
							'answer'   => 1,
							'hint'     => 'Treat a public tool like a postcard, not a sealed letter.',
							'feedback_correct'   => 'Exactly — keep secrets and personal data out of public tools.',
							'feedback_incorrect' => 'Not quite. The safe move is to never feed secrets or personal data into a public tool.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Because models learn from human-made data, human biases can show up in what they produce.',
							'answer'   => 0,
							'hint'     => 'The training material carries human assumptions.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Before using an AI tool for work, you should check your organisation\'s ___ to see which tools and data are allowed.',
							'answer_text' => 'policy',
							'accept'      => array( 'policies', 'guidelines', 'rules' ),
							'hint'        => 'The written rules your workplace or school sets.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Give one specific example of information you should never paste into a public AI tool, and explain why.',
							'rubric'   => 'A good answer names a concrete piece of sensitive or personal data (password, customer record, health detail, unreleased financials) and explains that public tools may store or expose it.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Using it well and safely — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which task is generative AI weakest at and should be double-checked?',
						'options'  => array(
							'Producing exact, up-to-date statistics and real citations',
							'Drafting a rough first version of an email',
							'Brainstorming a list of ideas',
							'Summarising a document you provided',
						),
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'When an AI confidently states a false "fact" as if it were true, that mistake is known as a ___.',
						'answer_text' => 'hallucination',
						'accept'      => array( 'hallucinations' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'It is fine to paste customer passwords and personal records into a public AI chatbot as long as the answer is helpful.',
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'A colleague wants to use AI to summarise a spreadsheet full of customer contact details. What is the responsible first step?',
						'options'  => array(
							'Paste it into any free chatbot immediately',
							'Check the organisation\'s policy and use an approved, private tool for sensitive data',
							'Post it in a public forum to ask others for help',
							'Email the file to themselves first, then paste it in',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'Working with it, not just trying it',
			'lessons' => array(

				array(
					'title'   => 'Which tasks it is worth handing over',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'GenAI families', 'Hallucinations & limits' ),
					'content' => '<h2>The question is not "can it?" but "should it?"</h2>'
						. '<p>Generative AI can attempt almost any task involving words or images. Attempting is cheap; being reliably useful is not. Two questions sort the good candidates from the expensive disappointments.</p>'
						. '<h3>Question 1: is the material in front of it?</h3>'
						. '<p>Tasks where you supply everything needed — summarise this document, rewrite this in plainer English, pull the dates out of these emails, turn these notes into a table — are the reliable ones. The model is transforming what it was given, so there is little room to invent.</p>'
						. '<p>Tasks that depend on facts you did <em>not</em> supply — what our refund policy says, what last quarter\'s numbers were, what the law requires — are where confident fiction appears. The fix is not a better prompt. It is putting the material in front of it.</p>'
						. '<h3>Question 2: would you catch a mistake?</h3>'
						. '<p>If you would spot an error in ten seconds, use the model freely. If a wrong answer would sail past you and reach a customer, either verify every output or do not use it there.</p>'
						. '<p>These two questions produce a simple grid:</p>'
						. '<table><thead><tr><th></th><th>Easy to check</th><th>Hard to check</th></tr></thead><tbody>'
						. '<tr><td><strong>Material supplied</strong></td><td>Use freely — summaries, rewrites, extraction</td><td>Use with a review step — long-document analysis</td></tr>'
						. '<tr><td><strong>Material not supplied</strong></td><td>Use as a starting point — brainstorms, first drafts</td><td>Do not use unaided — facts, figures, citations, legal or medical claims</td></tr>'
						. '</tbody></table>'
						. '<h3>The most valuable use is not "write it for me"</h3>'
						. '<p>People reach first for full drafting, which is the case that needs the most editing. The higher-yield uses are quieter: getting unstuck on a blank page, having something explained at the level you actually need, turning a mess of notes into structure, and being asked what you have forgotten.</p>'
						. '<blockquote>Give it the material, or accept that you are getting a plausible guess. There is no third option.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Supply the material, and know whether you would catch an error. Those two answers place any task on the grid and tell you how much to trust the output.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which task sits in the "use freely" corner of the grid?',
							'options'  => array(
								'Asking what your company\'s refund policy says',
								'Summarising a report you pasted in full',
								'Asking for the exact figure of last quarter\'s revenue',
								'Requesting a citation for a legal claim',
							),
							'answer'   => 1,
							'hint'     => 'Which one supplies the material and is easy to check?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A better-worded prompt can make a model reliable about facts it was never given.',
							'answer'   => 1,
							'hint'     => 'Where would the facts come from?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'If a wrong answer would reach a customer without anyone ___ it, that task needs verification or should not use AI at all.',
							'answer_text' => 'catching',
							'accept'      => array( 'noticing', 'checking', 'spotting' ),
							'hint'        => 'Question two of the grid.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Place two tasks from your own week on the grid — one you would hand over freely and one you would not — and say which question decided each.',
							'rubric'   => 'A strong answer names two concrete tasks and justifies each by the two criteria: whether the necessary material is supplied in the prompt, and whether an error would be caught quickly. It should not simply label one "easy" and one "hard".',
						),
					),
				),

				array(
					'title'   => 'Reading AI output like an editor',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Hallucinations & limits', 'Responsible use' ),
					'content' => '<h2>The output is a draft by someone confident and unaccountable</h2>'
						. '<p>Generative AI writes with unearned assurance. There is no hesitation in the prose when it is guessing, which means fluency tells you nothing — and the habit worth building is reading output the way an editor reads a submission from a bright, fast, occasionally careless writer.</p>'
						. '<h3>Four things to look at first</h3>'
						. '<p><strong>Specifics.</strong> Names, numbers, dates, quotations, references. These are where fabrication concentrates, because they are exactly the details a plausible sentence needs. Check every one you did not supply.</p>'
						. '<p><strong>Confident hedging.</strong> "Studies suggest", "it is widely accepted", "experts agree" — with no study, no one, and no source. This construction is a strong tell.</p>'
						. '<p><strong>Silent omission.</strong> Ask what is missing. A summary that reads beautifully may have dropped the one caveat that mattered, and nothing in the text marks the gap.</p>'
						. '<p><strong>Averaged blandness.</strong> Text that could describe any company in your industry means the model had no specifics and smoothed toward the middle. That is a signal you did not give it enough, not that your situation is unremarkable.</p>'
						. '<h3>The sixty-second check</h3>'
						. '<p>Before anything leaves your hands: does every fact come from something I supplied or can verify? Is anything stated more certainly than I know it to be? What has been left out? Would I put my name on this?</p>'
						. '<p>That last question is the real one. You are accountable for what you send, and "the AI wrote it" has never once repaired a relationship with a customer.</p>'
						. '<blockquote>Fluent and correct are unrelated properties. The model optimises for one of them.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Check specifics, distrust sourceless authority, hunt for what is missing, read blandness as a prompt problem — and take the last sixty seconds before anything goes out with your name on it.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Where does fabrication most often concentrate in AI output?',
							'options'  => array(
								'In the opening sentence',
								'In specific names, numbers, dates and citations',
								'In short paragraphs',
								'In bulleted lists',
							),
							'answer'   => 1,
							'hint'     => 'Which details does a plausible-sounding sentence need?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => '"Studies suggest" with no study named is an example of confident ___.',
							'answer_text' => 'hedging',
							'accept'      => array( 'vagueness', 'sourceless authority' ),
							'hint'        => 'Authority with nothing behind it.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Well-written, fluent output is reasonable evidence that the content is accurate.',
							'answer'   => 1,
							'hint'     => 'What is the model optimising for?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'You asked AI to summarise a supplier contract and it produced a clean one-page summary. Write the checklist you would work through before forwarding it to your finance team.',
							'rubric' => 'A strong answer checks every figure, date and party name against the actual contract, looks specifically for omitted clauses (termination, liability, auto-renewal) rather than only verifying what is present, flags anything stated more confidently than the source supports, and ends with taking personal responsibility for what is sent on.',
							'hint'   => 'What is present is easier to check than what is missing.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Working with it — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'The most reliable generative AI tasks are those where:',
						'options'  => array(
							'The prompt is very long',
							'You supply the material and can check the result quickly',
							'The model is the newest available',
							'The output is short',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Output that could describe any company in your industry usually means the prompt lacked specifics.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Before sending AI-assisted work, the decisive question is whether you would put your ___ on it.',
						'answer_text' => 'name',
						'accept'      => array( 'signature' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which is hardest to spot when reviewing an AI summary?',
						'options'  => array(
							'A misspelled word',
							'A clause that was silently left out',
							'An overly long paragraph',
							'A missing heading',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Bringing it into real work',
			'lessons' => array(

				array(
					'title'   => 'What you may and may not put in',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Responsible use' ),
					'content' => '<h2>The input side is where most organisations get hurt</h2>'
						. '<p>Attention goes to what AI produces. The costlier mistakes usually happen at the other end — in what people paste into it — and they happen quietly, with good intentions, by someone trying to get their work done.</p>'
						. '<h3>Assume it leaves the building</h3>'
						. '<p>Unless you are on a service that contractually says otherwise, treat anything you paste as having left your organisation. That is the working assumption; the terms of your specific plan then either relax it or confirm it. Consumer tiers and enterprise agreements differ sharply here, and the difference is worth ten minutes of reading.</p>'
						. '<h3>The four categories that need care</h3>'
						. '<ul>'
						. '<li><strong>Personal data</strong> — customer names, addresses, health or financial details. Under GDPR and similar regimes, pasting these into an external service is a transfer that needs a basis.</li>'
						. '<li><strong>Confidential business information</strong> — unreleased plans, pricing, contracts, anything under NDA. An NDA does not carve out "unless it was convenient".</li>'
						. '<li><strong>Credentials and keys</strong> — never, under any circumstances, including "just to debug this error".</li>'
						. '<li><strong>Someone else\'s copyrighted work</strong> — pasting a whole book or paid report to summarise is a use question, not just a privacy one.</li>'
						. '</ul>'
						. '<h3>Redaction usually costs nothing</h3>'
						. '<p>Most tasks do not need the real names. "Customer A" and "Customer B" summarise exactly as well as the real people, and the output maps back trivially. Where the work genuinely needs real data, that is the signal to use a sanctioned tool with the right agreement — not to paste anyway and hope.</p>'
						. '<blockquote>The question is not "is this sensitive?" — people always answer no when they are busy. It is "would I be comfortable if this appeared in a news story about our company?"</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Assume it leaves. Know the four categories. Redact by default, because it usually costs nothing. Escalate to a sanctioned tool when the work truly needs the real thing.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which is the safest default assumption about text you paste into an AI service?',
							'options'  => array(
								'It is deleted immediately',
								'Treat it as having left your organisation unless the terms say otherwise',
								'It is safe because the chat is private to you',
								'It is safe if you delete the conversation afterwards',
							),
							'answer'   => 1,
							'hint'     => 'Start from the cautious assumption, then check the terms.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Pasting an API key into a chat is acceptable when you are only trying to debug an error message.',
							'answer'   => 1,
							'hint'     => 'There is no exception to this one.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'For most summarising tasks, replacing real names with "Customer A" and "Customer B" costs ___.',
							'answer_text' => 'nothing',
							'accept'      => array( 'little', 'almost nothing' ),
							'hint'        => 'The output maps back trivially.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'A colleague wants to paste a spreadsheet of customer complaints — with names and email addresses — to find common themes. What do you suggest, and why is it not simply "no"?',
							'rubric'   => 'A strong answer keeps the useful work while removing the risk: strip names and contact details, since themes do not depend on identity, and paste the complaint text alone. It should explain why a flat refusal fails — the colleague will do it anyway or lose real value — and note when the task genuinely needs identity, escalate to a sanctioned tool with the right agreement.',
						),
					),
				),

				array(
					'title'   => 'Your own first workflow',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 30,
					'topics'  => array( 'Generating vs classifying', 'GenAI families', 'Responsible use' ),
					'content' => '<h2>One task, done properly, beats ten experiments</h2>'
						. '<p>Most people try generative AI on a dozen things, find it uneven, and drift away. The ones for whom it sticks pick a single repeating task and get it genuinely right, once. The saved prompt then pays back every week without further thought.</p>'
						. '<h3>Choose well</h3>'
						. '<p>The task should repeat weekly or more, take real minutes each time, work from material you can supply, and have an output you would recognise as wrong. Weekly report from notes, triaging an inbox, turning a call into action items, first-pass replies to common questions — these qualify. "Write our strategy" does not.</p>'
						. '<h3>Build it in four passes</h3>'
						. '<ol>'
						. '<li><strong>Do it once by hand</strong> and keep the result. That is your target — you cannot judge the model against a standard you never wrote down.</li>'
						. '<li><strong>Write the prompt</strong> with the role, the material pasted, one clear task, and the exact output shape.</li>'
						. '<li><strong>Compare with your hand-made version</strong> and change one thing at a time until the gap closes.</li>'
						. '<li><strong>Save it with placeholders</strong> for whatever changes each week, and a note on what each expects.</li>'
						. '</ol>'
						. '<h3>Measure honestly, twice</h3>'
						. '<p>After a month, ask two questions. Is it faster including the checking? Editing a bad draft can take longer than writing a good one, and the honest answer is sometimes no. And is the output as good as what you did by hand? Faster and worse is not a win — it is a slow erosion nobody logs.</p>'
						. '<blockquote>One saved prompt you actually reuse is worth more than a hundred you read about.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>You know what generative AI is, which families exist, where it fails, how to judge output, and what not to paste. Now pick one repeating task, build it properly, and measure whether it really helped.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which task is the best first candidate for a saved AI workflow?',
							'options'  => array(
								'Writing the company strategy',
								'Turning your weekly meeting notes into a standard update',
								'Deciding next year\'s budget',
								'Choosing who to promote',
							),
							'answer'   => 1,
							'hint'     => 'Repeating, material supplied, output you would recognise as wrong.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Do the task by hand once first, because you cannot judge the model against a standard you never wrote ___.',
							'answer_text' => 'down',
							'accept'      => array( 'out' ),
							'hint'        => 'Your target output.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'If a workflow is faster but the output is noticeably worse than your own, it is still a clear win.',
							'answer'   => 1,
							'hint'     => 'Faster and worse is erosion nobody logs.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Pick one task you repeat weekly and write the full prompt for it: role, the material with a marked placeholder, one clear task, the exact output format, and a line telling the model what to do when the material does not contain something it needs.',
							'rubric' => 'A strong answer is genuinely reusable — marked placeholders rather than one week\'s details, one task rather than several, a specific output shape with a limit, and explicit instruction to flag gaps rather than fill them. The chosen task should be one where the material is supplied and an error would be noticed.',
							'hint'   => 'Write it so next month\'s you can use it without thinking.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'What did you believe generative AI was for before this course, and what would you now tell a colleague it is genuinely good at?',
							'rubric'   => 'A thoughtful answer contrasts a specific prior expectation with a more precise view — typically moving from "it writes things" toward transforming supplied material, structuring, explaining and first drafts — rather than a general statement of having learned.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Bringing it into real work — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which should never be pasted into an AI tool?',
						'options'  => array(
							'A meeting agenda',
							'An API key or password',
							'A public press release',
							'Your own draft email',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'For most tasks, replacing real customer names with labels is a form of ___ that costs almost nothing.',
						'answer_text' => 'redaction',
						'accept'      => array( 'anonymisation', 'anonymization' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'A workflow should be judged on time saved including the time spent checking the output.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'The strongest reason to do a task by hand once before automating it is:',
						'options'  => array(
							'To practise typing',
							'To have a target you can judge the model against',
							'To use up the free tier',
							'To make the prompt longer',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
