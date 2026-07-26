<?php
/**
 * Seed course: "Google Gemini at Work" (AI Tools bundle).
 *
 * Follows the canonical schema contract defined in course-pe-foundations.php.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'tool-gemini',
	'title'       => 'Google Gemini at Work',
	'slug'        => 'tool-gemini',
	'subtitle'    => 'Use Google\'s Gemini for text and images, and fold it into your everyday tasks.',
	'excerpt'     => 'A practical, beginner-friendly tour of Google Gemini for handling text and images and speeding up your everyday work.',
	'description' => '<p>Google <strong>Gemini</strong> is an AI assistant you talk to in plain language — ask a question, share an image, and get back a fresh, human-sounding reply. If you can hold a conversation, you can use it.</p>'
		. '<p>This short course shows you what Gemini is, how to combine words and pictures in a single prompt, and how to fold the tool into everyday work like drafting, summarising, and brainstorming. You will also learn its limits — when to double-check an answer and how to keep private information safe — so you can rely on it without being caught out.</p>',
	'category'    => 'AI Tools',
	'level'       => 'beginner',
	'est_hours'   => 3,
	'featured'    => false,
	'certificate' => true,
	'order'       => 3,
	'topics'      => array( 'Gemini basics', 'Multimodal', 'Everyday tasks', 'Limits' ),
	'outcomes'    => array(
		'Describe what Gemini is and the kinds of tasks it handles',
		'Combine an image and a question in a single multimodal prompt',
		'Choose when to show a picture instead of describing it in words',
		'Use Gemini to draft, summarise, brainstorm, and speed up daily tasks',
		'Verify its answers and keep private data safe before you rely on them',
	),
	'references'  => array(
		array(
			'title'  => 'Gemini API documentation',
			'source' => 'Google AI for Developers',
			'url'    => 'https://ai.google.dev/gemini-api/docs',
		),
		array(
			'title'  => 'Google AI Principles',
			'source' => 'Google',
			'url'    => 'https://ai.google/responsibility/principles/',
		),
		array(
			'title'  => 'Introduction to Large Language Models',
			'source' => 'Google — Machine Learning Education',
			'url'    => 'https://developers.google.com/machine-learning/resources/intro-llms',
		),
		array(
			'title'  => 'Secure AI Framework (SAIF)',
			'source' => 'Google',
			'url'    => 'https://safety.google/cybersecurity-advancements/saif/',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'Getting started with Gemini',
			'lessons' => array(

				array(
					'title'   => 'What Gemini is',
					'type'    => 'reading',
					'est_min' => 8,
					'xp'      => 20,
					'topics'  => array( 'Gemini basics' ),
					'content' => '<h2>Meet Gemini, Google\'s AI assistant</h2>'
						. '<p>Gemini is Google\'s <strong>AI assistant</strong>: you type a request in plain language and it writes back a fresh, human-sounding answer. If you have ever used a chat-style assistant, the feel is familiar — you have a back-and-forth conversation, and each reply builds on what came before.</p>'
						. '<p>Two things make Gemini worth knowing. First, it is genuinely <strong>conversational</strong>: you do not need special commands or keywords. You can ask a question, react to the answer, and say "make it shorter" or "explain that differently" — and it keeps its place in the thread. Second, it is <strong>multimodal</strong>, which is a fancy way of saying it works with more than just text. It can read and understand an <em>image</em> you share, not only the words you type.</p>'
						. '<h3>Text and images, one assistant</h3>'
						. '<p>On the text side, Gemini can draft an email, summarise a long report, brainstorm names, or explain a tricky idea in simpler terms. On the image side, you can show it a photo of a whiteboard and ask it to type up the notes, or share a screenshot of a chart and ask what the numbers suggest. Same assistant, two kinds of input.</p>'
						. '<blockquote>Think of Gemini as a knowledgeable colleague who can both talk things through and look at what you show them.</blockquote>'
						. '<h3>What to keep in mind</h3>'
						. '<p>Because Google keeps improving these tools, the exact version names, limits, and paid tiers change often — so it is not worth memorising them. What lasts is the <em>shape</em> of what the assistant does: understand your request, work with text and images, and reply in a conversation. Learn that shape and you can sit down with whatever version is in front of you and be productive right away.</p>'
						. '<h3>Where to start</h3>'
						. '<p>As someone working in {{role}}, the fastest way in is to pick one small, real task and just try it — a message to rewrite, a document to summarise, a photo to describe. You learn far more from one honest attempt than from reading about features.</p>'
						. '<h3>Recap</h3>'
						. '<p>Gemini is Google\'s conversational AI assistant that handles both text and images. The tool changes fast, but the core idea — a colleague you brief in plain language who can also look at what you share — stays the same.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which of these best describes Gemini?',
							'options'  => array(
								'A spreadsheet program for building budgets',
								'A conversational AI assistant that works with text and images',
								'A search engine that only returns a list of links',
								'A photo-editing app with no text features',
							),
							'answer'   => 1,
							'hint'     => 'Think about what you actually do with it — chat, and sometimes share a picture.',
							'feedback_correct'   => 'Right — it is a conversational assistant that handles both text and images.',
							'feedback_incorrect' => 'Not quite — Gemini is a conversational AI assistant that works with both text and images.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Because Gemini\'s versions, limits, and paid tiers change often, it is not worth memorising those specifics.',
							'answer'   => 0,
							'hint'     => 'The lesson says to learn the shape of what it does, not the changing details.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Gemini is described as ___, meaning you have a natural back-and-forth instead of using special commands.',
							'answer_text' => 'conversational',
							'accept'      => array( 'conversation', 'chatty' ),
							'hint'        => 'You chat with it like a colleague.',
						),
					),
				),

				array(
					'title'   => 'Multimodal prompts',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'Multimodal' ),
					'content' => '<h2>When a picture belongs in your prompt</h2>'
						. '<p>A <strong>multimodal prompt</strong> is simply a prompt that includes an image alongside your words. Instead of describing something in painstaking detail, you show it and ask your question. For many tasks this is faster, clearer, and more accurate than text alone.</p>'
						. '<h3>Two everyday examples</h3>'
						. '<p>Say a chart in a report looks off. You can share the chart image and ask, <em>"What\'s wrong with this chart?"</em> Gemini can point out a mislabelled axis, a misleading scale, or a total that does not add up — things that are hard to explain in words but obvious in a picture.</p>'
						. '<p>Or you have a photo for a website and need accessibility text. Share the image and ask, <em>"Draft alt text for this photo."</em> You get a concise description you can refine, without staring at the image trying to word it from scratch.</p>'
						. '<blockquote>If it would be quicker to point at something than to describe it, that is a job for a multimodal prompt.</blockquote>'
						. '<h3>When the picture beats the words</h3>'
						. '<p>Reach for an image in your prompt when:</p>'
						. '<ul>'
						. '<li>The detail is <strong>visual</strong> — layout, colour, a diagram, handwriting, a screenshot of an error.</li>'
						. '<li>Describing it accurately would take many sentences, and you might still miss something.</li>'
						. '<li>You want the assistant to read text <em>inside</em> an image, like a photographed receipt or a slide.</li>'
						. '</ul>'
						. '<p>Stick with text alone when the task is about ideas, wording, or facts you can state plainly — "rewrite this paragraph" or "list five names" — where a picture would add nothing.</p>'
						. '<h3>Pair the image with a clear ask</h3>'
						. '<p>An image on its own is not a prompt. Always add the specific question or task: not just the chart, but "what\'s misleading here and how would you fix it?" The picture gives context; your words give direction. Whatever {{daily_tools}} you already use for screenshots or photos will feed Gemini perfectly well.</p>'
						. '<h3>Recap</h3>'
						. '<p>A multimodal prompt combines an image with a text question. Use one whenever showing is easier than telling — a confusing chart, a photo needing alt text, text trapped in a screenshot — and always pair the image with a clear ask.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'You need to explain what is misleading about a complicated chart in a colleague\'s report. What is usually the fastest approach?',
							'options'  => array(
								'Ask Gemini to guess what a typical chart looks like',
								'Type several paragraphs describing every bar, axis, and label',
								'Share the chart image and ask Gemini what is misleading about it',
								'Avoid AI entirely and hope no one notices the problem',
							),
							'answer'   => 2,
							'hint'     => 'Multimodal prompts let you show rather than tell.',
							'feedback_correct'   => 'Exactly — showing the chart and asking a specific question beats describing it in words.',
							'feedback_incorrect' => 'Think about the point of a multimodal prompt: share the image and ask directly.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A multimodal prompt means giving the assistant an image together with a text question.',
							'answer'   => 0,
							'hint'     => '"Multi" points to more than one kind of input.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'An image on its own is not a prompt — you should always pair it with a clear question or ___.',
							'answer_text' => 'task',
							'accept'      => array( 'question', 'ask', 'instruction' ),
							'hint'        => 'The image gives context; your words give direction.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Think of one thing on your screen right now that is easier to show than to describe. Write a multimodal prompt: name the image you would share and the exact question you would ask about it.',
							'rubric' => 'A strong answer identifies a genuinely visual item and pairs it with a specific, answerable question rather than a vague request.',
							'hint'   => 'Good visual candidates are charts, screenshots of errors, handwritten notes, or photos.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Getting started with Gemini — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'What does "multimodal" mean when describing Gemini?',
						'options'  => array(
							'It can be used in many different countries',
							'It has many different subscription plans',
							'It can be opened in many browser tabs at once',
							'It can work with more than one kind of input, such as text and images',
						),
						'answer'   => 3,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which task is the best fit for a text-only prompt, with no image needed?',
						'options'  => array(
							'Explaining what is wrong with a photographed chart',
							'Reading handwriting from a scanned note',
							'Rewriting a paragraph to sound more friendly',
							'Describing the layout shown in a screenshot',
						),
						'answer'   => 2,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Sharing a photo and asking Gemini to write a short description for accessibility is asking it to draft ___ text.',
						'answer_text' => 'alt',
						'accept'      => array( 'alternative' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'An image pasted into a prompt with no question attached is already a complete, well-formed prompt.',
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Gemini for work',
			'lessons' => array(

				array(
					'title'   => 'Everyday tasks with Gemini',
					'type'    => 'practice',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Everyday tasks' ),
					'content' => '<h2>Putting Gemini to work on the everyday stuff</h2>'
						. '<p>Most of the value you will get from Gemini is not dramatic — it is the steady grind of small tasks done faster. Four jobs it handles well every day:</p>'
						. '<ul>'
						. '<li><strong>Drafting:</strong> a first version of an email, a message, a job post, or meeting notes. A rough draft in seconds beats a blank page every time.</li>'
						. '<li><strong>Summarising:</strong> paste a long thread or document and ask for the key points, decisions, or action items.</li>'
						. '<li><strong>Brainstorming:</strong> ask for ten angles, names, or approaches when you are stuck for ideas.</li>'
						. '<li><strong>Quick research help:</strong> get a plain-English explanation of an unfamiliar term or a starting overview of a topic — then verify anything important.</li>'
						. '</ul>'
						. '<h3>Ask for options, then choose</h3>'
						. '<p>A simple habit that lifts quality fast: instead of asking for one answer, ask for several. "Give me three subject lines, each a different tone" gives you something to react to. You are a better editor than a blank page, and choosing between options is easier than conjuring one from nothing.</p>'
						. '<blockquote>Ask for a few options, pick the closest, then tell Gemini how to improve it. Editing beats originating.</blockquote>'
						. '<h3>Build a reusable prompt</h3>'
						. '<p>When a task comes up repeatedly, save the prompt that worked and reuse it. A support lead might keep: <em>"Rewrite the pasted message to be warm, clear, and under 100 words. Keep any dates and order numbers exact. Give me two versions."</em> Paste new text each time and you have a reliable mini-tool. Point this habit at {{primary_goal}} and you will feel the time saving within a week.</p>'
						. '<h3>Try it now</h3>'
						. '<p>Take one real task from today, write a prompt that asks for two or three options, and keep the wording that worked as your template. That is the whole loop: draft, choose, refine, save.</p>'
						. '<h3>Recap</h3>'
						. '<p>Use Gemini for drafting, summarising, brainstorming, and quick research. Ask for options and edit down, and turn any prompt you reuse into a saved mini-tool.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why is it often better to ask Gemini for several options instead of a single answer?',
							'options'  => array(
								'Choosing between options is easier than starting from a blank page, and gives you something to refine',
								'It uses far less of the context window',
								'Several options are always more factually accurate than one',
								'It stops the model from ever making a mistake',
							),
							'answer'   => 0,
							'hint'     => 'Think about the difference between editing and originating.',
							'feedback_correct'   => 'Right — you are a better editor than a blank page, so options give you a head start.',
							'feedback_incorrect' => 'The main reason is practical: it is easier to pick and refine an option than to create one from scratch.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'When a task comes up again and again, save the prompt that worked so it becomes a ___ mini-tool.',
							'answer_text' => 'reusable',
							'accept'      => array( 'reuseable', 'repeatable', 'saved' ),
							'hint'        => 'You paste new text into the same wording each time.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Gemini is a reliable substitute for verifying important research facts yourself.',
							'answer'   => 1,
							'hint'     => 'It is a useful starting point, not the final word.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write a reusable prompt for a task you do often (drafting a reply, summarising notes, brainstorming). Make it ask for two or three options and state one thing that must stay exactly right.',
							'rubric' => 'A strong answer is reusable (works with different pasted content), requests multiple options, and pins down a constraint such as tone, length, or facts to preserve.',
							'hint'   => 'Leave a clear spot where you would paste new text each time.',
						),
					),
				),

				array(
					'title'   => 'Checking Gemini\'s work',
					'type'    => 'reading',
					'est_min' => 8,
					'xp'      => 20,
					'topics'  => array( 'Limits' ),
					'content' => '<h2>Trust, but verify</h2>'
						. '<p>Gemini is fast and often impressive — but it is not always right. Like any AI assistant, it can state something false with complete confidence, and its knowledge of very recent events may be out of date. Treat every answer as a well-informed draft, not a verified fact.</p>'
						. '<h3>Check facts and links</h3>'
						. '<p>Two things deserve a second look every time they matter:</p>'
						. '<ul>'
						. '<li><strong>Facts and figures:</strong> names, dates, statistics, quotes, legal or medical claims. If a decision rides on it, confirm it against a trusted source.</li>'
						. '<li><strong>Links and references:</strong> an AI can produce a URL or citation that looks real but leads nowhere. Click through before you rely on it or pass it along.</li>'
						. '</ul>'
						. '<blockquote>The confident tone is not evidence. A wrong answer sounds exactly as sure as a right one.</blockquote>'
						. '<h3>Protect private and company data</h3>'
						. '<p>Be thoughtful about what you paste in. Avoid putting customer records, passwords, unreleased plans, or anything covered by a confidentiality agreement into a general AI chat unless your organisation has approved it. A safe habit: strip out names and specifics, or use a made-up example, when the real data is not essential to the question. If you are unsure whether something is safe to share, check your workplace policy first.</p>'
						. '<h3>Review before you send</h3>'
						. '<p>The final step is always yours. Before an AI-assisted email, report, or post goes out under your name, read it as if you wrote every word — because to the recipient, you did. Fix anything that is wrong, off-tone, or that you cannot personally stand behind. Whatever {{daily_tools}} you send work through, that last human read is what keeps your name safe.</p>'
						. '<h3>Recap</h3>'
						. '<p>Gemini can be wrong or out of date, so verify facts and links that matter. Keep private and company data out of general chats, and always review AI-assisted work before it goes out under your name.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A colleague asks you to include a study that Gemini cited in your report. Before you do, you should:',
							'options'  => array(
								'Include it as-is — AI citations are always accurate',
								'Add it but quietly note that it might be fake',
								'Click the link and confirm the source is real and says what Gemini claimed',
								'Ask Gemini whether it is sure and trust the reply',
							),
							'answer'   => 2,
							'hint'     => 'An AI can produce a citation that looks real but leads nowhere.',
							'feedback_correct'   => 'Right — verify the link and the claim against the actual source before relying on it.',
							'feedback_incorrect' => 'The safe move is to check the link and source yourself; a confident tone is not proof.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Because Gemini answers confidently, a confident tone is a good sign that the answer is correct.',
							'answer'   => 1,
							'hint'     => 'A wrong answer sounds just as sure as a right one.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'You should avoid pasting private or company ___ into a general AI chat unless it has been approved.',
							'answer_text' => 'data',
							'accept'      => array( 'information' ),
							'hint'        => 'Think customer records, passwords, or unreleased plans.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Why is it important to read an AI-assisted email yourself before sending it under your name?',
							'rubric'   => 'A good answer notes that the sender is responsible for the content, that AI can be wrong or off-tone, and that a human review catches errors before they reach the recipient.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Gemini for work — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which of these is a good everyday use of Gemini?',
						'options'  => array(
							'Drafting a first version of an email that you will then edit',
							'Signing legal contracts on your behalf',
							'Storing your only copy of important passwords',
							'Making final medical decisions with no human check',
						),
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Why should you verify important facts and links that come from Gemini?',
						'options'  => array(
							'Because it always refuses to answer factual questions',
							'Because it can state wrong or out-of-date things confidently, and may cite links that lead nowhere',
							'Because verifying makes every answer longer',
							'Because links from an AI never work at all',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'A safe habit is to keep private or company ___ out of general AI chats unless it has been approved.',
						'answer_text' => 'data',
						'accept'      => array( 'information' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'It is fine to paste confidential customer records into any AI chat as long as the answer is useful.',
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'Working where your files already live',
			'lessons' => array(

				array(
					'title'   => 'The advantage of being inside the suite',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Everyday tasks', 'Gemini basics' ),
					'content' => '<h2>The paste-and-download loop is where the time actually goes</h2>'
						. '<p>Compare two ways of doing the same job. Open a document, select all, copy, switch tabs, paste into a chat, ask, copy the answer, switch back, paste, fix the formatting. Or: ask in the sidebar of the document you already have open.</p>'
						. '<p>The second is not a better answer. It is the same answer without eight steps of clerical work — and across a week that difference dwarfs any prompting technique.</p>'
						. '<h3>Where it pays most</h3>'
						. '<ul>'
						. '<li><strong>Long threads.</strong> Summarising a forty-message conversation you are joining late, without forwarding anything anywhere.</li>'
						. '<li><strong>Documents you are already editing.</strong> Rewrite this section, tighten this paragraph, suggest a heading — in place.</li>'
						. '<li><strong>Spreadsheets.</strong> Describing what you want and getting a formula beats searching for the syntax you half remember.</li>'
						. '<li><strong>Recorded meetings.</strong> Notes and action items from something that already happened, with no transcript to shuttle around.</li>'
						. '</ul>'
						. '<h3>The trade you are making</h3>'
						. '<p>Integration means the assistant can reach your material — which is convenient and is exactly why it is worth knowing what it may reach, and under whose account. Convenience and access are the same property viewed from two directions.</p>'
						. '<p>Two habits follow. Know whether the assistant sees only the current file or your whole drive. And be careful in shared documents: something drafted in a file others have open is visible before you have reviewed it.</p>'
						. '<blockquote>Judge a tool by the work it removes, not only by the quality of its answer. Eight steps of copy-paste is real work.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Integration wins by deleting the shuttling, most visibly on threads, live documents, spreadsheets and recordings — and the same access that makes it convenient is the thing to understand before using it.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'The main advantage of an assistant built into your document suite is:',
							'options'  => array(
								'It gives better answers than other assistants',
								'It removes the copy-paste-switch loop around the answer',
								'It is always free',
								'It never makes mistakes',
							),
							'answer'   => 1,
							'hint'     => 'What work disappears?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Convenience and access are separate properties — an integrated assistant is convenient without reaching more of your material.',
							'answer'   => 1,
							'hint'     => 'How does it reach the file you are editing?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'In a ___ document, AI-drafted text is visible to others before you have reviewed it.',
							'answer_text' => 'shared',
							'accept'      => array( 'collaborative' ),
							'hint'        => 'Others may have it open.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Name a task you currently do by copying between a document and a chat window. How many steps would integration remove, and what would you check before switching?',
							'rubric'   => 'A strong answer counts the actual clerical steps removed and identifies a specific thing to check first — what the assistant can access, whether the document is shared, or whether the content is confidential — rather than only welcoming the convenience.',
						),
					),
				),

				array(
					'title'   => 'When the answer needs to be current',
					'type'    => 'practice',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Limits', 'Everyday tasks' ),
					'content' => '<h2>"Does this thing look things up?" is the question people forget to ask</h2>'
						. '<p>Some assistants search the web as they answer; some answer purely from training; some do either depending on how you ask. Not knowing which mode you are in produces a specific and avoidable failure: a confident answer about something that changed last month.</p>'
						. '<h3>How to tell</h3>'
						. '<p>Ask something that only a current source could answer — a price, a recent announcement, today\'s date in a specific place. If the answer arrives with links or a note about sources, you are searching. If it arrives smoothly with no sources at all, treat it as memory.</p>'
						. '<h3>Searching does not mean correct</h3>'
						. '<p>A retrieved answer inherits whatever it retrieved. A confidently wrong web page becomes a confidently wrong answer, now wearing a citation — which makes it more persuasive, not less. Two habits follow: read what the citation actually says rather than trusting that it supports the claim, and notice when the source is a forum post rather than the official documentation.</p>'
						. '<h3>Ask for the source, always</h3>'
						. '<p>"Give me the link for each claim" costs nothing and changes the answer\'s status entirely. An unlinked claim is something to verify; a linked one is something to check in ten seconds.</p>'
						. '<h3>Where currency matters most</h3>'
						. '<p>Prices, product features, regulations, anything involving a version number, and anything with a date in it. If your question has an implicit "right now" in it, the mode you are in is the whole question.</p>'
						. '<blockquote>An answer with a citation is not verified. It is checkable — which is only an improvement if you check it.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Establish whether you are searching or remembering, ask for links by default, read what the source actually says, and treat anything time-sensitive as needing a current source.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'How can you tell whether an assistant is searching or answering from memory?',
							'options'  => array(
								'By how long the answer is',
								'By asking something only a current source could answer and seeing whether sources appear',
								'By the tone of the reply',
								'By the time of day',
							),
							'answer'   => 1,
							'hint'     => 'Test it with something time-sensitive.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'An answer that includes a citation has been verified.',
							'answer'   => 1,
							'hint'     => 'Checkable is not the same as checked.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A retrieved answer inherits the quality of whatever it ___.',
							'answer_text' => 'retrieved',
							'accept'      => array( 'found', 'read' ),
							'hint'        => 'A wrong page becomes a wrong answer with a link.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write a prompt for researching whether a software product meets four specific requirements, in a way that makes the answer checkable rather than merely confident.',
							'rubric' => 'A strong answer names the four requirements explicitly, requires a source link per claim, instructs the assistant to distinguish official documentation from secondary sources, and directs it to say "not found" for any requirement it cannot evidence rather than inferring an answer.',
							'hint'   => 'What should it do about a requirement it cannot evidence?',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Integration and currency — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'        => 'fill_blank',
						'question'    => 'Ask for a ___ with each claim so an answer becomes checkable in seconds.',
						'answer_text' => 'link',
						'accept'      => array( 'source', 'citation' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'The same access that makes an integrated assistant convenient is what you should understand before using it.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which question most needs a current source rather than trained knowledge?',
						'options'  => array(
							'Explain what a spreadsheet pivot table is',
							'What is this product\'s current pricing tier?',
							'Rewrite this paragraph more simply',
							'Summarise this document I pasted',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'The clearest benefit of an in-suite assistant is that it:',
						'options'  => array(
							'Produces better prose',
							'Removes the clerical work around the answer',
							'Costs nothing',
							'Never needs verification',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Making it stick without making a mess',
			'lessons' => array(

				array(
					'title'   => 'Three tasks worth setting up properly',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Everyday tasks', 'Multimodal' ),
					'content' => '<h2>Everyone experiments. Very few people set anything up</h2>'
						. '<p>The gap between people who get real value from these tools and people who find them "quite good" is not skill. It is that one group turned three tasks into a routine and the other kept improvising.</p>'
						. '<h3>The three that repay setup for almost everyone</h3>'
						. '<p><strong>1. The recurring summary.</strong> A long thread, a weekly report, a set of updates — anything you read regularly and extract the same kind of thing from. Write the prompt once, specifying exactly what you want pulled out, and use it every time.</p>'
						. '<p><strong>2. The thing you always reformat.</strong> Notes into a standard structure, data into a table, technical detail into plain language. The shape never changes, only the content, which is precisely what a saved prompt is for.</p>'
						. '<p><strong>3. The first draft you always rewrite anyway.</strong> Where you are going to edit heavily regardless, starting from a draft is strictly better than starting from nothing — and the editing was always the real work.</p>'
						. '<h3>What "setting up properly" means</h3>'
						. '<p>Write the prompt. Test it three times on real material. Fix the one thing that keeps going wrong. Save it where you will find it in a month — not in a note you will lose. That is twenty minutes, once.</p>'
						. '<h3>What not to bother with</h3>'
						. '<p>Anything you do once. Anything where you already know exactly what to write. Anything where checking would cost more than doing. Being deliberate about the exclusions is what stops this becoming a hobby.</p>'
						. '<blockquote>Three tasks set up properly beat thirty tried once. The second group has more stories; the first group has the time back.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Set up the recurring summary, the standard reformat, and the draft you always rewrite. Test each three times, save it findably, and deliberately skip the rest.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which task is worth turning into a saved routine?',
							'options'  => array(
								'A one-off letter you will never write again',
								'The weekly summary you produce from the same kind of source every time',
								'A sentence you already know how to write',
								'A decision only you can make',
							),
							'answer'   => 1,
							'hint'     => 'Repeating, same shape, different content.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Setting up properly means testing the prompt on ___ material a few times before relying on it.',
							'answer_text' => 'real',
							'accept'      => array( 'actual' ),
							'hint'        => 'Not invented examples.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'It is worth setting up a routine for tasks where verifying the output costs more than doing the task.',
							'answer'   => 1,
							'hint'     => 'Count the checking.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Name your three, and say what specifically you would ask for in the first one.',
							'rubric'   => 'A strong answer names three genuinely recurring tasks fitting the summary, reformat and first-draft patterns, and gives a concrete specification for the first — what to extract, in what shape, and what to do with anything missing — rather than a general description.',
						),
					),
				),

				array(
					'title'   => 'What stays yours',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 30,
					'topics'  => array( 'Limits', 'Gemini basics', 'Everyday tasks' ),
					'content' => '<h2>The tool got faster. The responsibility did not move</h2>'
						. '<p>The more useful these assistants become, the easier it is to let them creep from drafting into deciding. The line is worth drawing deliberately, once, rather than discovering where it was after crossing it.</p>'
						. '<h3>What stays with you, always</h3>'
						. '<ul>'
						. '<li><strong>The facts.</strong> Anything the model was not given by you, going anywhere it matters, is yours to check.</li>'
						. '<li><strong>The judgement.</strong> Whether to hire, what to prioritise, how to answer an unhappy customer, what you believe about a strategy. A draft can help you think; it cannot hold the opinion.</li>'
						. '<li><strong>The relationship.</strong> An apology, a difficult conversation, a message to someone who is upset — people can usually tell, and being able to tell is the damage.</li>'
						. '<li><strong>The accountability.</strong> Everything you send is yours, however it was drafted. There has never been a working version of "the AI wrote it".</li>'
						. '</ul>'
						. '<h3>The drift to watch for</h3>'
						. '<p>It is gradual and reasonable at every step. Draft the email, then send it with a glance, then stop reading carefully, then use it for the difficult one too. No single step is wrong. The cumulative position is somewhere you would not have chosen.</p>'
						. '<p>A simple test: could you defend this output in your own words if challenged? If not, you have not reviewed it — you have forwarded it.</p>'
						. '<h3>Where this leaves you</h3>'
						. '<p>You know what Gemini is, how multimodal prompts work, how to use it for everyday tasks, how to check its work, where integration helps, when currency matters, and what to set up. The last piece is the one that keeps all of it trustworthy.</p>'
						. '<blockquote>Use it for everything it is good at. Keep the judgement, the relationships, and the accountability firmly in your own hands.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Facts, judgement, relationships and accountability stay yours. Watch for gradual drift, and use the defend-it-in-your-own-words test before anything leaves.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which task should stay entirely with a person?',
							'options'  => array(
								'Drafting a routine status update',
								'Apologising to an upset customer in a way that has to be sincere',
								'Summarising a long thread',
								'Reformatting notes into a table',
							),
							'answer'   => 1,
							'hint'     => 'Which one is about the relationship?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'If you could not ___ the output in your own words when challenged, you forwarded it rather than reviewed it.',
							'answer_text' => 'defend',
							'accept'      => array( 'explain', 'justify' ),
							'hint'        => 'A simple test before sending.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Drift from drafting into deciding usually happens through one obviously wrong decision.',
							'answer'   => 1,
							'hint'     => 'Each step seems reasonable.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write your own four lines: what you will always verify, what you will never delegate, what you will disclose, and what you remain accountable for — each specific to your actual job.',
							'rubric' => 'A strong answer is concrete to the writer\'s work rather than generic: named categories of fact they must check, at least one specific task reserved for their own judgement, a stated disclosure threshold, and an unambiguous claim of personal accountability. "Be careful" does not meet the bar.',
							'hint'   => 'Specific enough that you could check yourself against it.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'Where in your own use is the line between drafting and deciding least clear — and how will you keep it visible?',
							'rubric'   => 'A thoughtful answer identifies a genuine grey area in their own practice and proposes a concrete check, rather than asserting the line is always obvious.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'What stays yours — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Three tasks set up properly beat thirty tried once because:',
						'options'  => array(
							'Setting up is more enjoyable',
							'Routines return time every week; experiments return stories',
							'Saved prompts are cheaper',
							'The model prefers repetition',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Everything you send remains yours, however it was drafted.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'The drift from drafting into deciding is dangerous because each individual step seems ___.',
						'answer_text' => 'reasonable',
						'accept'      => array( 'sensible', 'fine' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which is NOT worth setting up as a routine?',
						'options'  => array(
							'A weekly summary from the same kind of source',
							'A one-off letter you will never write again',
							'Notes reformatted into a standard structure',
							'A first draft you always rewrite anyway',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
