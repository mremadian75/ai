<?php
/**
 * Seed course: "AI Image Generation" (AI Tools bundle).
 *
 * Conforms to the canonical seed schema defined in course-pe-foundations.php.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'tool-image-gen',
	'title'       => 'AI Image Generation',
	'slug'        => 'tool-image-gen',
	'subtitle'    => 'Create images with AI — how the tools work, how to write great image prompts, and how to use them responsibly.',
	'excerpt'     => 'A beginner-friendly tour of AI image tools: how they turn words into pictures, how to prompt them well, how to iterate toward a finished result, and how to stay on the right side of rights and ethics.',
	'description' => '<p>Type a sentence, get a picture. <strong>AI image generation</strong> puts a small design studio in your browser — but the difference between a throwaway result and one you can actually use comes down to a handful of learnable skills.</p>'
		. '<p>This course starts from zero. You will build a plain-language mental model of how image models work, learn the parts of a prompt that reliably steer the result, practise turning a first draft into a finished image, and cover the rights and ethics that keep your work safe to publish. No design degree and no coding required.</p>',
	'category'    => 'AI Tools',
	'level'       => 'beginner',
	'est_hours'   => 3,
	'featured'    => true,
	'certificate' => true,
	'order'       => 4,
	'topics'      => array( 'How image models work', 'Writing image prompts', 'Iterating & editing', 'Rights & ethics' ),
	'outcomes'    => array(
		'Explain in plain language how a text-to-image model turns your words into a picture',
		'Write image prompts that specify subject, style, composition, lighting, and mood',
		'Improve a weak prompt by adding the one part it was missing',
		'Iterate from a rough first draft to a finished image using variations and editing',
		'Use AI images responsibly by checking rights, avoiding likenesses, and disclosing when expected',
	),
	'references'  => array(
		array(
			'title'  => 'High-Resolution Image Synthesis with Latent Diffusion Models (Stable Diffusion)',
			'source' => 'Rombach et al., 2022 — arXiv:2112.10752',
			'url'    => 'https://arxiv.org/abs/2112.10752',
		),
		array(
			'title'  => 'Denoising Diffusion Probabilistic Models',
			'source' => 'Ho, Jain & Abbeel, 2020 — arXiv:2006.11239',
			'url'    => 'https://arxiv.org/abs/2006.11239',
		),
		array(
			'title'  => 'Content Credentials (C2PA) — provenance for AI-generated media',
			'source' => 'Coalition for Content Provenance and Authenticity',
			'url'    => 'https://c2pa.org/',
		),
		array(
			'title'  => 'Copyright and Artificial Intelligence',
			'source' => 'U.S. Copyright Office',
			'url'    => 'https://www.copyright.gov/ai/',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'How AI images work',
			'lessons' => array(

				array(
					'title'   => 'Text-to-image basics',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'How image models work' ),
					'content' => '<h2>Turning words into pictures</h2>'
						. '<p>A <strong>text-to-image</strong> tool takes a sentence you type — the <em>prompt</em> — and produces a brand-new picture that tries to match it. You describe what you want in plain language, and the tool paints something that has never existed before. Popular tools such as DALL' . "\xC2\xB7" . 'E, Midjourney, Adobe Firefly, and Stable Diffusion all work this way, even though their menus and house styles differ.</p>'
						. '<h3>A plain-language look under the hood</h3>'
						. '<p>Most of these tools use a method called <strong>diffusion</strong>. The idea is simpler than it sounds. The tool starts with a canvas of pure visual <em>noise</em> — random speckles, like old TV static. Then, step by step, it nudges that static a little closer to your description, removing randomness and adding structure until a clear image emerges. Picture a sculptor starting with a rough block and gradually revealing a shape, except here the block is noise and the chisel is your prompt.</p>'
						. '<p>Because the tool is <em>refining toward</em> your words rather than pulling out a stored photo, the words you choose steer every step. A vague prompt gives it little to aim at, so it fills the gaps with whatever is most typical.</p>'
						. '<h3>Why you get a different picture every time</h3>'
						. '<p>That starting static is random, and a fresh batch of randomness is used on each run. So the <strong>same prompt can produce a different image every time</strong> you generate. This is a feature, not a bug: it lets you spin up several options and pick the strongest. If you love one result and want more like it, most tools let you reuse its <em>seed</em> — the number that fixed that particular starting noise — to stay close to it.</p>'
						. '<blockquote>The prompt sets the destination; the random starting point decides which scenic route the tool takes to get there.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Text-to-image tools generate a new picture from your words by starting with noise and refining it toward your description. Expect variation between runs, generate a few options, and remember that clearer words give the tool a clearer target.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What does a text-to-image tool actually produce from your prompt?',
							'options'  => array(
								'A ranked list of stock photos that match your keywords',
								'An exact copy of the closest image it was trained on',
								'A brand-new image generated to match your description',
								'A link to a page where the image already exists',
							),
							'answer'   => 2,
							'hint'     => 'The tool paints something new rather than searching for something old.',
							'feedback_correct'   => 'Right — it creates a fresh image built to match the words you gave it.',
							'feedback_incorrect' => 'Not quite. A text-to-image tool generates a new picture; it is not a search engine or a copy machine.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Running the same image prompt twice can give you two different pictures.',
							'answer'   => 0,
							'hint'     => 'Each run starts from a fresh batch of random noise.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Diffusion tools begin with random visual ___ and refine it step by step toward your prompt.',
							'answer_text' => 'noise',
							'accept'      => array( 'static' ),
							'hint'        => 'Think of old TV snow before the picture appears.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'In your own words, why does the same prompt often produce a different image each time you run it?',
							'rubric'   => 'A good answer explains that generation begins from random noise and a new random starting point is used on each run, so results vary even though the prompt is unchanged.',
						),
					),
				),

				array(
					'title'   => 'Writing a great image prompt',
					'type'    => 'practice',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Writing image prompts' ),
					'content' => '<h2>The parts of a strong image prompt</h2>'
						. '<p>A good image prompt is a small brief. Instead of piling on adjectives at random, cover a few reliable slots. You will not always need every one, but knowing them keeps your descriptions sharp.</p>'
						. '<ul>'
						. '<li><strong>Subject</strong> — the main thing in the frame: <em>a ceramic coffee cup on a wooden table</em>.</li>'
						. '<li><strong>Style</strong> — the look: photo, watercolour, 3D render, flat illustration, pencil sketch.</li>'
						. '<li><strong>Composition / framing</strong> — the camera\'s point of view: close-up, wide shot, top-down, centred, rule of thirds.</li>'
						. '<li><strong>Lighting</strong> — soft morning light, warm golden hour, dramatic studio lighting, cool neon glow.</li>'
						. '<li><strong>Mood</strong> — calm, energetic, cosy, futuristic, playful.</li>'
						. '<li><strong>What to avoid</strong> — say what you do <em>not</em> want: no text, no clutter, no extra people.</li>'
						. '</ul>'
						. '<h3>Before and after: a marketing thumbnail</h3>'
						. '<p>Suppose you need a thumbnail for a blog post about healthy breakfasts.</p>'
						. '<blockquote><strong>Before:</strong> "a healthy breakfast"</blockquote>'
						. '<p>That is only a subject. The tool has to guess the style, angle, light, and feel, so results wander all over the place.</p>'
						. '<blockquote><strong>After:</strong> "Top-down photo of a healthy breakfast bowl with berries and oats on a light marble surface, soft natural morning light, fresh and vibrant mood, plenty of empty space at the top for a title, no text in the image."</blockquote>'
						. '<p>Now every slot is filled: subject, style (photo), composition (top-down, with room for a headline), lighting (soft morning), mood (fresh), and an avoid ("no text"). The tool finally has a real target to hit.</p>'
						. '<h3>Make it yours</h3>'
						. '<p>As someone in {{role}}, pick one real image you actually need for {{primary_goal}} and write a prompt that fills every slot. Naming the composition and the empty space you need for a title is often what separates a usable thumbnail from a pretty but unusable one.</p>'
						. '<h3>Recap</h3>'
						. '<p>Strong prompts describe subject, style, composition, lighting, and mood — and say what to avoid. Start from those slots, then refine.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Your image prompt says only "a healthy breakfast". Which change would most improve it?',
							'options'  => array(
								'Repeat the word "healthy" several more times',
								'Add composition and lighting, e.g. "top-down photo, soft morning light"',
								'Make the prompt as long as you possibly can',
								'Type the whole prompt in capital letters',
							),
							'answer'   => 1,
							'hint'     => 'Which missing slots would give the tool a clearer target?',
							'feedback_correct'   => 'Exactly — naming framing and light turns a bare subject into a real brief.',
							'feedback_incorrect' => 'Not quite. Repeating words or shouting does not help; filling missing slots like composition and lighting does.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Telling the tool "no text, no clutter" is the ___ part of a prompt — describing what you do not want.',
							'answer_text' => 'avoid',
							'accept'      => array( 'negative', 'exclude' ),
							'hint'        => 'It is the opposite of the subject you are asking for.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Naming the framing, such as "close-up" or "top-down", helps you control the composition of the image.',
							'answer'   => 0,
							'hint'     => 'Framing is exactly the composition slot of a prompt.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write a single image prompt that fills every slot — subject, style, composition, lighting, mood, and one thing to avoid — for a header image you might use in your own work.',
							'rubric' => 'A strong answer names a clear subject, a style, a composition/framing, a lighting description, a mood, and at least one explicit "avoid".',
							'hint'   => 'Walk down the slot list and make sure none is left to the tool to guess.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'How AI images work — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'What does a text-to-image tool do?',
						'options'  => array(
							'Finds existing photos that contain your keywords',
							'Generates a new image that matches your text description',
							'Only edits photos that you upload yourself',
							'Translates your text into other languages',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'A strong image prompt usually covers subject, style, composition, lighting, and ___.',
						'answer_text' => 'mood',
						'accept'      => array( 'feel', 'tone' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Running the same prompt again can produce a different image because it starts from a fresh batch of random noise.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which addition would most improve the prompt "a dog"?',
						'options'  => array(
							'Writing "a dog a dog a dog"',
							'Writing it as "DOG"',
							'"Close-up photo of a golden retriever puppy in soft park light, playful mood"',
							'Adding "please" to the front',
						),
						'answer'   => 2,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'From draft to final',
			'lessons' => array(

				array(
					'title'   => 'Iterating, variations & editing',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Iterating & editing' ),
					'content' => '<h2>Your first image is a draft, not the answer</h2>'
						. '<p>Beginners often generate one picture, feel disappointed, and give up. Experienced users expect the first result to be a <strong>rough draft</strong> and plan to improve it. Getting a great image is a loop, not a single shot.</p>'
						. '<h3>Generate variations</h3>'
						. '<p>When a result is close but not right, ask the tool for <strong>variations</strong> — new versions that keep the overall idea while changing the details. Batch a few at once and compare. Choosing from four options is faster, and usually better, than agonising over one.</p>'
						. '<h3>Adjust the prompt</h3>'
						. '<p>If the whole direction is off, change the words, not just the dice. Add the missing slot: maybe you never named the lighting, or forgot to say "no text". Change one thing at a time so you can see what each edit does. If the image is too busy, add "simple, minimal background"; if it is too dark, say "bright and airy".</p>'
						. '<h3>Edit specific parts</h3>'
						. '<p>Most tools now let you fix a region instead of regenerating everything:</p>'
						. '<ul>'
						. '<li><strong>Inpainting</strong> — paint over one area (an extra finger, an odd logo) and have the tool redraw just that part.</li>'
						. '<li><strong>Outpainting / expand</strong> — extend the canvas to add more scene around the edges, handy when you need a wider crop.</li>'
						. '<li><strong>Masking</strong> — protect the parts you already like so edits leave them untouched.</li>'
						. '</ul>'
						. '<h3>A realistic loop</h3>'
						. '<p>Generate four options, pick the best, expand the canvas to fit your layout, then inpaint away a distracting object in the corner. Whatever {{daily_tools}} you already use for design, drop the result in and check whether it truly fits before you generate again — seeing it in the real layout often reveals what still needs fixing.</p>'
						. '<blockquote>Do not chase the perfect prompt in one go. Aim, look, adjust, repeat.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Treat the first image as a draft: generate variations, change one part of the prompt at a time, and use inpaint or expand to fix specific areas instead of starting over.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What is the healthiest way to treat the first image a tool gives you?',
							'options'  => array(
								'As a rough draft to refine through variations and edits',
								'As the final answer, because regenerating is cheating',
								'As proof that your prompt is already perfect',
								'As a reason to give up if it is not right',
							),
							'answer'   => 0,
							'hint'     => 'Great images come from a loop, not a single shot.',
							'feedback_correct'   => 'Yes — the first result is a starting point you improve, not the finish line.',
							'feedback_incorrect' => 'Not quite. The first image is a draft; you refine it with variations and targeted edits.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Inpainting lets you redraw just one region of an image instead of regenerating the whole thing.',
							'answer'   => 0,
							'hint'     => 'You paint over the area you want changed and leave the rest alone.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Extending the canvas to add more scene around the edges of an image is often called outpainting or ___.',
							'answer_text' => 'expand',
							'accept'      => array( 'expanding', 'outpaint' ),
							'hint'        => 'The button usually uses this everyday word for "make bigger".',
						),
					),
				),

				array(
					'title'   => 'Rights, ethics & safe use',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'Rights & ethics' ),
					'content' => '<h2>Using AI images responsibly</h2>'
						. '<p>AI images are fun to make, but publishing them carries real-world responsibilities. A picture that is fine for a private brainstorm may not be fine on a product page or in an ad. A few durable principles keep you safe.</p>'
						. '<h3>Rights and commercial use differ by tool</h3>'
						. '<p>Every tool has its own <strong>terms of use and licence</strong>, and they are not the same. Some allow commercial use of what you generate; some restrict it; some depend on your plan. Before you use an image for work, read that specific tool\'s licence and confirm you are allowed to use it the way you intend. Do not assume that "the AI made it, so it is free to use".</p>'
						. '<h3>Do not borrow real people or owned characters</h3>'
						. '<p>Generating the likeness of a <strong>real person</strong> — a celebrity, a colleague, a customer — without consent can cause genuine harm and legal trouble, especially in advertising. The same goes for <strong>copyrighted characters</strong> and brand logos: a famous cartoon mouse or a rival\'s logo does not become yours just because a model drew it. For real, published work, stick to original subjects.</p>'
						. '<h3>Disclose when people expect it</h3>'
						. '<p>In many settings — journalism, reviews, anything that could mislead — audiences and platforms expect you to <strong>say that an image is AI-generated</strong>. When in doubt, label it. Honesty protects your credibility, and some platforms and regions now require disclosure outright.</p>'
						. '<blockquote>Ask three questions before you publish: Am I licensed to use this? Am I copying a real person or an owned character? Should I disclose that it is AI?</blockquote>'
						. '<h3>A quick pre-publish check</h3>'
						. '<ul>'
						. '<li>Check the tool\'s licence for your intended use (personal versus commercial).</li>'
						. '<li>Avoid real likenesses and copyrighted or trademarked material.</li>'
						. '<li>Disclose AI generation where readers would expect it.</li>'
						. '<li>Verify anything factual the image implies — AI pictures are not evidence.</li>'
						. '</ul>'
						. '<h3>Recap</h3>'
						. '<p>Rights vary by tool, so check the licence; keep real people and owned characters out of published work; and disclose AI images where it is expected. Responsible use keeps the creativity and drops the risk.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'You want to use an AI image in a paid ad. What should you check first?',
							'options'  => array(
								'Whether the image looks realistic enough',
								'How many times you had to regenerate it',
								'Which colour palette the image uses',
								'The specific tool\'s licence and commercial-use terms',
							),
							'answer'   => 3,
							'hint'     => 'Commercial use is exactly what a licence governs.',
							'feedback_correct'   => 'Correct — the tool\'s licence decides whether you may use the image commercially.',
							'feedback_incorrect' => 'Not quite. Looks and effort do not grant rights; the tool\'s licence does.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Because an AI model drew it, generating a famous cartoon character for a commercial product is automatically fine to use.',
							'answer'   => 1,
							'hint'     => 'Copyright still applies to owned characters even when a model draws them.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'In journalism and other settings that could mislead people, you are often expected to ___ that an image is AI-generated.',
							'answer_text' => 'disclose',
							'accept'      => array( 'label', 'reveal' ),
							'hint'        => 'It means to openly say so rather than hide it.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Name one ethical rule you would follow before publishing an AI-generated image, and explain briefly why it matters.',
							'rubric'   => 'A good answer states a concrete rule (e.g. check the licence, avoid real likenesses or owned characters, disclose AI use) and gives a sensible reason tied to rights, harm, or honesty.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'From draft to final — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Why is it useful to treat your first generated image as a draft?',
						'options'  => array(
							'Because the tool must be broken if it is not perfect',
							'Because you can refine it with variations and edits to reach a better result',
							'Because draft images cost less to make',
							'Because you must never reuse a prompt',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'You should get consent before generating and publishing the likeness of a real, identifiable person.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Before using an AI image commercially, check the specific tool\'s ___ to confirm you are allowed to.',
						'answer_text' => 'licence',
						'accept'      => array( 'license', 'terms' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which of these is a responsible practice with AI images?',
						'options'  => array(
							'Assume every AI image is free for any use',
							'Recreate a competitor\'s logo for your ads',
							'Disclose AI generation where audiences expect it',
							'Never bother reading the tool\'s terms',
						),
						'answer'   => 2,
					),
				),
			),
		),

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'Control: getting the picture you meant',
			'lessons' => array(

				array(
					'title'   => 'Composition, camera and light',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Writing image prompts', 'Iterating & editing' ),
					'content' => '<h2>Most disappointing images are under-specified, not badly generated</h2>'
						. '<p>"A photo of an office" gives you the average of every office photograph ever taken: a grey desk, a laptop, a plant, from eye level, in flat light. That is not a failure — it is what happens when nothing in the request narrows the possibilities.</p>'
						. '<p>The vocabulary that narrows it is borrowed from photography and illustration, and three axes do most of the work.</p>'
						. '<h3>1. Framing and viewpoint</h3>'
						. '<p>Close-up, wide shot, overhead, low angle, eye level, over-the-shoulder. Distance and height together decide the whole feel of an image, and neither is guessable from a subject description alone.</p>'
						. '<h3>2. Light</h3>'
						. '<p>The single highest-leverage word in most image prompts. Soft window light, harsh midday sun, golden hour, overcast, backlit, single lamp in a dark room. Light carries mood far more than colour does — and "professional photo" mostly means "someone thought about the light".</p>'
						. '<h3>3. Lens and depth</h3>'
						. '<p>Wide angle for space and slight distortion; telephoto for compression and a blurred background; macro for texture. "Shallow depth of field" is the shorthand for the background falling softly out of focus that reads as expensive.</p>'
						. '<h3>Put them in order of importance</h3>'
						. '<p>Subject first, then setting, then light, then framing, then style. Earlier words carry more weight, so a prompt that opens with the style and mentions the subject last will give you a very stylish picture of something not quite right.</p>'
						. '<h3>Say what you do not want</h3>'
						. '<p>Some tools accept explicit exclusions; all of them respond to positive alternatives. "Empty desk, no people" works better than hoping people do not appear.</p>'
						. '<blockquote>If the image is boring, you probably described the subject and left the camera and the light to chance.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Framing, light and lens are the three axes. Order matters — subject first, style last — and stating what should be absent beats hoping.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which addition usually changes the mood of an image most?',
							'options'  => array(
								'A different aspect ratio',
								'Specifying the light — soft window light, harsh sun, golden hour',
								'Adding more adjectives about quality',
								'Requesting higher resolution',
							),
							'answer'   => 1,
							'hint'     => 'What does "professional photo" usually actually mean?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Put the subject first and the style ___, because earlier words carry more weight.',
							'answer_text' => 'last',
							'accept'      => array( 'later', 'at the end' ),
							'hint'        => 'Order changes the emphasis.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A generic result usually means the model is weak rather than that the prompt left the camera and light unspecified.',
							'answer'   => 1,
							'hint'     => 'What was left to chance?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Rewrite "a photo of a team meeting" into a fully specified prompt: subject, setting, light, framing, lens and style, in that order — and state one thing that should not be in the frame.',
							'rubric' => 'A strong answer specifies all six elements in the stated order, uses concrete photographic vocabulary rather than adjectives like "nice" or "professional", and includes a positively phrased exclusion. The result should be an image only one way could plausibly be produced.',
							'hint'   => 'Leave nothing to the average.',
						),
					),
				),

				array(
					'title'   => 'Iterating without starting over',
					'type'    => 'practice',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Iterating & editing', 'How image models work' ),
					'content' => '<h2>The first image is a question, not an attempt</h2>'
						. '<p>Experienced users treat generation as a conversation with the composition. The first result tells you what the model understood; every one after that is a correction.</p>'
						. '<h3>Change one thing at a time</h3>'
						. '<p>Rewrite the whole prompt and you cannot tell which change fixed it or which broke the part you liked. Adjust one element — the light, the angle, the background — and read the difference. The discipline is identical to debugging a text prompt, and it is skipped for the same reason: it feels slower.</p>'
						. '<h3>Keep what worked</h3>'
						. '<p>Where a tool exposes a seed or a "make variations of this" option, it lets you hold the composition steady while changing details. That is the difference between exploring around a good result and rolling the dice again.</p>'
						. '<h3>Fix regions, not whole images</h3>'
						. '<p>When one part is wrong and the rest is right, regenerating everything is the expensive mistake. Editing just the offending region — inpainting — keeps what you already liked. Extending the canvas outward gets you a wider crop without re-rolling the subject.</p>'
						. '<h3>Know what to stop fighting</h3>'
						. '<p>Some things remain unreliable: text inside images, precise counts of objects, hands and fine anatomy, exact brand logos, and consistency of the same character across separate images. If you have tried three times and it is still wrong, that is usually a limitation rather than a prompting failure — fix it in an editor, or design around it.</p>'
						. '<blockquote>Two hours of re-rolling for text a designer would set correctly in thirty seconds is the most common way to lose the time this was supposed to save.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Read the first image as information, change one element at a time, hold good compositions with seeds and variations, edit regions rather than regenerating, and recognise the limits worth designing around.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'One element of an otherwise good image is wrong. The best move is:',
							'options'  => array(
								'Regenerate the whole image with a new prompt',
								'Edit just that region, keeping the rest',
								'Accept it as it is',
								'Increase the resolution',
							),
							'answer'   => 1,
							'hint'     => 'Why discard what already worked?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Change ___ element at a time so you can tell which adjustment caused the difference.',
							'answer_text' => 'one',
							'accept'      => array( 'a single', 'single' ),
							'hint'        => 'Same discipline as debugging a text prompt.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Readable text inside a generated image is reliable enough to use without checking.',
							'answer'   => 1,
							'hint'     => 'One of the known weak spots.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'You need a hero image with your product name displayed on a sign. Describe the approach you would actually take, and why.',
							'rubric'   => 'A strong answer avoids fighting the known text weakness: generate the scene with a blank or plain sign, then add the text in a design tool afterwards. It should explain the reasoning — text rendering is unreliable and a designer sets type correctly in seconds — rather than proposing repeated re-rolls.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Control and iteration — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'        => 'fill_blank',
						'question'    => 'Editing only the wrong region of an image, keeping the rest, is called ___.',
						'answer_text' => 'inpainting',
						'accept'      => array( 'in-painting', 'region editing' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'A generic image usually means the prompt left the camera and the light unspecified.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which is still generally unreliable in generated images?',
						'options'  => array(
							'Overall composition',
							'Exact counts of objects and readable text',
							'Colour and mood',
							'General style',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'A seed or "make variations" control is useful because it lets you:',
						'options'  => array(
							'Generate faster',
							'Hold a composition you liked steady while changing details',
							'Increase resolution',
							'Avoid writing a prompt',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Using generated images in real work',
			'lessons' => array(

				array(
					'title'   => 'Where AI images belong, and where they do not',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Rights & ethics', 'How image models work' ),
					'content' => '<h2>Capability is not permission</h2>'
						. '<p>You can generate almost any image. Whether you should use it depends on what it depicts, who sees it, and what they will take it to mean — questions that have nothing to do with image quality.</p>'
						. '<h3>Generally fine</h3>'
						. '<p>Concept art and mood boards, internal presentations, placeholders in a mock-up, abstract or decorative backgrounds, illustrations of things that are obviously illustrations. Low stakes, no one misled.</p>'
						. '<h3>Needs care</h3>'
						. '<p>Anything published under your brand — check your platform\'s terms, your client\'s contract, and your own disclosure policy. Anything resembling a real person. Anything in a style closely identified with a living artist, which may be legally uncertain and is reputationally risky regardless.</p>'
						. '<h3>Do not</h3>'
						. '<p>Photorealistic images of real people doing things they did not do. Fake evidence, fake products, fake testimonials. Images implying a photograph was taken when none was — a generated "customer" in a case study is a fabrication however good the image is.</p>'
						. '<h3>The test that settles most cases</h3>'
						. '<p>Would the viewer change their reading of this if they knew it was generated? For a decorative header, no. For a photograph of a happy customer, absolutely — and that difference is the whole answer.</p>'
						. '<h3>Provenance is becoming standard</h3>'
						. '<p>Content credentials (the C2PA standard) attach tamper-evident provenance metadata to images, and platform support is growing. Preserving rather than stripping it is quickly becoming the professional default.</p>'
						. '<blockquote>The question is never "is this image good enough to pass as real?" It is "would anyone feel deceived to learn it was not?"</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Decorative and conceptual use is low risk; brand-published and person-resembling use needs checking; anything implying a photograph of real events does not belong. Apply the would-they-feel-misled test, and preserve provenance data.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which use is clearly out of bounds?',
							'options'  => array(
								'An abstract background for a slide deck',
								'A photorealistic "customer" in a testimonial',
								'Concept art for an internal brainstorm',
								'A placeholder in a design mock-up',
							),
							'answer'   => 1,
							'hint'     => 'Which one asks the viewer to believe something untrue?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'The deciding question is whether a viewer would feel ___ to learn the image was generated.',
							'answer_text' => 'misled',
							'accept'      => array( 'deceived', 'cheated' ),
							'hint'        => 'Not whether it looks real enough.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Provenance metadata such as content credentials should be stripped before publishing.',
							'answer'   => 1,
							'hint'     => 'Which direction is the professional default moving?',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Your team wants AI-generated images across a marketing site. Which placements would you approve, which would you refuse, and what would you require in between?',
							'rubric'   => 'A strong answer approves decorative, abstract and conceptual placements, refuses anything depicting apparently real customers, staff or events, and for the middle ground requires specific safeguards — checking platform and client terms, avoiding living artists\' styles, disclosure where a reasonable viewer would assume a photograph, and preserving provenance data.',
						),
					),
				),

				array(
					'title'   => 'A workflow that fits real projects',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 30,
					'topics'  => array( 'Iterating & editing', 'Writing image prompts', 'Rights & ethics' ),
					'content' => '<h2>Generation is one step in a process, not the process</h2>'
						. '<p>Treating image generation as "type words, receive asset" is where most of the wasted time happens. Fitted into a real workflow, it is one step among several — and usually not the longest.</p>'
						. '<h3>The five steps</h3>'
						. '<ol>'
						. '<li><strong>Decide what the image must do.</strong> Set a mood, illustrate a concept, fill a space? The job determines everything after it, and skipping this is why people generate forty images and use none.</li>'
						. '<li><strong>Check the constraints first.</strong> Aspect ratio, where it will be cropped, whether text sits over it, brand colours, and whether generated imagery is permitted here at all. Finding out afterwards wastes the whole effort.</li>'
						. '<li><strong>Generate broadly, then narrow.</strong> A few different directions first, then iterate on the one that is closest. Refining your first idea immediately usually means refining the wrong idea well.</li>'
						. '<li><strong>Finish it properly.</strong> Crop, colour, remove artefacts, add text in a design tool. Almost nothing arrives publication-ready, and the finishing is where the professional look comes from.</li>'
						. '<li><strong>Record what you did.</strong> The prompt, the tool, the date. In six months someone will ask for "another one like that", and reconstructing a prompt from an image is impossible.</li>'
						. '</ol>'
						. '<h3>Know when to stop</h3>'
						. '<p>If you are twenty generations in and still not close, the concept is probably wrong rather than the prompt. Step back and ask what the image actually needs to do — or accept that a photograph, a stock image or an illustrator is the right answer here.</p>'
						. '<blockquote>The people who get consistently good results are not the ones with secret prompts. They are the ones who decided what the image was for before they started generating.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>You know how these models work, how to prompt them, how to control composition, how to iterate, and where the ethical and legal lines sit. The workflow is what turns all of it into finished work.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why check constraints like aspect ratio and text overlay before generating?',
							'options'  => array(
								'It makes generation faster',
								'Discovering them afterwards can waste the entire effort',
								'The model requires them',
								'It improves image quality',
							),
							'answer'   => 1,
							'hint'     => 'What happens to a beautiful image in the wrong shape?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Record the ___ alongside the image, because reconstructing it from the picture later is impossible.',
							'answer_text' => 'prompt',
							'accept'      => array( 'prompt and tool' ),
							'hint'        => 'Someone will ask for another one like that.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Twenty generations without getting close usually means the prompt needs more adjectives.',
							'answer'   => 1,
							'hint'     => 'What is more likely wrong?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Plan the images for a three-page landing site: state what each image must do, the constraints you would check first, and which of the three you would not generate with AI at all — with the reason.',
							'rubric' => 'A strong answer gives each image a specific job, lists real constraints (ratio, crop points, text overlay, brand palette, permission), and refuses at least one on principled grounds — typically anything depicting real customers, staff or premises — explaining that a viewer would reasonably read it as a photograph.',
							'hint'   => 'At least one of the three should not be generated.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'What did you expect AI image generation to be useful for before this course, and what would you now use it for — and not use it for?',
							'rubric'   => 'A thoughtful answer contrasts an initial expectation with a more specific view, and names at least one use it would now avoid on quality, legal or ethical grounds.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Using generated images — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'The first step in an image workflow should be:',
						'options'  => array(
							'Writing the prompt',
							'Deciding what the image must actually do',
							'Choosing a tool',
							'Picking a style',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Almost nothing arrives publication-ready; the finishing step is where the professional look comes from.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Generate ___ first across a few directions, then iterate on the closest one.',
						'answer_text' => 'broadly',
						'accept'      => array( 'widely', 'several' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Content credentials (C2PA) exist to:',
						'options'  => array(
							'Improve image resolution',
							'Attach tamper-evident provenance information to an image',
							'Compress files',
							'Watermark images visibly',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
