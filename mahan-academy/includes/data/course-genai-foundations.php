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
	),
);
