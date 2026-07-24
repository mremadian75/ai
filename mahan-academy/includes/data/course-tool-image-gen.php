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
	),
);
