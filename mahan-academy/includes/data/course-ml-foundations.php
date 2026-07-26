<?php
/**
 * Seed course: "Machine Learning Foundations" (Machine Learning bundle).
 *
 * Follows the canonical schema contract defined in
 * {@see course-pe-foundations.php}. The seeder ({@see Mahan_Seed}) loads each
 * `course-*.php` and expects exactly that shape:
 * units -> lessons -> exercises, plus an optional unit quiz.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'ml-foundations',
	'title'       => 'Machine Learning Foundations',
	'slug'        => 'ml-foundations',
	'subtitle'    => 'What machine learning actually is, the main types, and the workflow every ML project follows — no maths required.',
	'excerpt'     => 'A jargon-free introduction to what machine learning is, its three main types, and the data-to-model workflow behind every ML project.',
	'description' => '<p>Everyone has heard the phrase <strong>machine learning</strong>, but few can say what it actually means. This course clears that up in plain language — no maths, no code, no jargon — using everyday examples like spam filters, product recommendations, and house prices.</p>'
		. '<p>You will learn how machine learning differs from ordinary programming, the three main types and when each is used, and the workflow every project follows: data, features and labels, and the all-important train/test split. By the end you will understand what people mean when they talk about ML — and be ready to go deeper with confidence.</p>',
	'category'    => 'Machine Learning',
	'track'       => 'machine-learning',
	'level_rank'  => 1,
	'level'       => 'beginner',
	'est_hours'   => 3,
	'featured'    => true,
	'certificate' => true,
	'order'       => 1,
	'topics'      => array( 'Learning from data', 'Types of ML', 'Features & labels', 'Train/test split' ),
	'outcomes'    => array(
		'Describe how machine learning differs from writing explicit rules',
		'Tell supervised, unsupervised, and reinforcement learning apart',
		'Identify the features and the label in a dataset',
		'Explain why data quality sets the ceiling on model performance',
		'Explain why a model must be tested on unseen data',
	),
	'references'  => array(
		array(
			'title'  => 'An Introduction to Statistical Learning',
			'source' => 'James, Witten, Hastie & Tibshirani — Springer',
			'url'    => 'https://www.statlearning.com/',
		),
		array(
			'title'  => 'Machine Learning Crash Course',
			'source' => 'Google — Machine Learning Education',
			'url'    => 'https://developers.google.com/machine-learning/crash-course',
		),
		array(
			'title'  => 'Artificial Intelligence: A Modern Approach',
			'source' => 'Russell & Norvig — Pearson',
			'url'    => 'https://aima.cs.berkeley.edu/',
		),
		array(
			'title'  => 'Rules of Machine Learning: Best Practices for ML Engineering',
			'source' => 'Google',
			'url'    => 'https://developers.google.com/machine-learning/guides/rules-of-ml',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'What machine learning is',
			'lessons' => array(

				array(
					'title'   => 'Learning from data vs. writing rules',
					'type'    => 'reading',
					'est_min' => 8,
					'xp'      => 20,
					'topics'  => array( 'Learning from data' ),
					'content' => '<h2>Two ways to get a computer to do a job</h2>'
						. '<p>For most of computing history, making a program do something meant one thing: a human sat down and wrote the <strong>rules</strong>. If the total is over $100, apply a discount. If the date is a weekend, charge the higher rate. This is <em>traditional programming</em> — you spell out every step, and the computer follows it exactly.</p>'
						. '<p><strong>Machine learning turns that around.</strong> Instead of writing the rules yourself, you show the system many <em>examples</em> and let it work out the pattern on its own. You do not tell it what to look for; you give it labelled cases and it learns the rule that connects them.</p>'
						. '<h3>The classic example: spam filtering</h3>'
						. '<p>Imagine trying to catch spam email by hand-writing rules. "Block anything that says free money." Spammers write "fr33 m0ney". You add another rule. They change again. You would be patching rules forever, and still miss new tricks.</p>'
						. '<p>A machine learning spam filter takes a different route. You feed it thousands of emails already labelled <em>spam</em> or <em>not spam</em>, and it learns the subtle combinations of words, senders, and patterns that tend to mark spam — including ones no human thought to write down. When a new trick appears, you do not rewrite rules; you give it fresh examples and it adapts.</p>'
						. '<h3>When ML is the right tool</h3>'
						. '<p>Rules are perfect when the logic is clear and stable: tax brackets, business hours, password length. Reach for machine learning when the pattern is <strong>too messy, too subtle, or too changeable</strong> to write out by hand — recognising faces, predicting demand, spotting fraud, understanding language. If you could easily write the rule, you usually should. ML earns its keep exactly where you cannot.</p>'
						. '<blockquote>Traditional programming: humans write the rules. Machine learning: the system learns the rules from examples.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Two approaches, one big difference. In traditional programming you supply the logic; in machine learning you supply the examples and the system infers the logic. Choose rules for clear, fixed problems, and machine learning for messy patterns that would be impossible to spell out by hand.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'What is the key difference between traditional programming and machine learning?',
							'options'  => array(
								'Machine learning is always faster than writing rules',
								'In traditional programming humans write the rules; in machine learning the system learns them from examples',
								'Traditional programming does not need a computer',
								'Machine learning can only be used for email',
							),
							'answer'   => 1,
							'hint'     => 'Think about who or what decides the rules in each approach.',
							'feedback_correct'   => 'Correct — in ML the system infers the rules from examples rather than being told them.',
							'feedback_incorrect' => 'Not quite. The defining trait of ML is that it learns the rules from data instead of a human writing them.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Spam filtering is a task where hand-written rules keep working perfectly forever without any updates.',
							'answer'   => 1,
							'hint'     => 'Spammers keep changing their tricks.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'In machine learning, instead of writing rules by hand, the system learns patterns from ___ .',
							'answer_text' => 'examples',
							'accept'      => array( 'data', 'example' ),
							'hint'        => 'You show it many labelled cases.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Think of a task at your work or in daily life. Decide whether plain rules or machine learning fits it better, and explain your choice in a sentence or two.',
							'rubric' => 'A strong answer names a concrete task, picks rules or ML, and justifies the choice by whether the underlying logic is clear and fixed (rules) or messy and pattern-based (ML).',
							'hint'   => 'Ask yourself: could a human easily write the rule out by hand?',
						),
					),
				),

				array(
					'title'   => 'Supervised, unsupervised, reinforcement',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Types of ML' ),
					'content' => '<h2>Three families of machine learning</h2>'
						. '<p>Almost every machine learning system falls into one of three broad types. They differ in one thing: <strong>what kind of examples you give the system, and whether you tell it the right answer.</strong></p>'
						. '<h3>1. Supervised learning — learning from labelled examples</h3>'
						. '<p>Here every example comes with the correct answer attached, called a <strong>label</strong>. You show the system thousands of house listings <em>and</em> their sale prices, and it learns to predict a price for a new house. Because you supervised it with known answers, it can predict a <em>known kind</em> of answer. Real-world example: a bank trains a model on past loan applications labelled "repaid" or "defaulted" to score new applicants.</p>'
						. '<h3>2. Unsupervised learning — finding structure with no labels</h3>'
						. '<p>Now imagine you have the examples but <strong>no answers</strong> — just raw data. Unsupervised learning hunts for hidden structure on its own, most often by <em>clustering</em> similar items together. Real-world example: an online shop feeds in customer purchase histories with no labels, and the system groups shoppers into natural segments — bargain hunters, gift buyers, loyal regulars — that nobody defined in advance.</p>'
						. '<h3>3. Reinforcement learning — learning by trial and reward</h3>'
						. '<p>The third type learns by <strong>doing</strong>. An <em>agent</em> tries actions, gets a <strong>reward</strong> when things go well and a penalty when they do not, and gradually learns a strategy that earns the most reward. Real-world example: a program learns to play a game — or steer a warehouse robot — by trying moves millions of times and keeping what scores best.</p>'
						. '<blockquote>Supervised = labelled answers. Unsupervised = no labels, find the pattern. Reinforcement = trial, reward, repeat.</blockquote>'
						. '<h3>How to tell them apart</h3>'
						. '<p>Ask one question: <em>what am I giving the system?</em> Labelled examples with the right answer point to supervised learning. Unlabelled data where you want to discover groupings point to unsupervised learning. A situation where the system acts and learns from feedback over time points to reinforcement learning.</p>'
						. '<h3>Recap</h3>'
						. '<p>Three types, one distinguishing question. Supervised learning predicts known answers from labelled data, unsupervised learning uncovers hidden structure in unlabelled data, and reinforcement learning improves through reward and trial. Most everyday ML you meet — spam filters, recommendations, price predictions — is supervised.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which type of machine learning uses examples that already have the correct answer (a label) attached?',
							'options'  => array(
								'Unsupervised learning',
								'Reinforcement learning',
								'Supervised learning',
								'None of them use answers',
							),
							'answer'   => 2,
							'hint'     => 'The name hints that someone supervised it with known answers.',
							'feedback_correct'   => 'Right — supervised learning trains on labelled examples where the answer is known.',
							'feedback_incorrect' => 'Not quite. Labelled examples with known answers are the hallmark of supervised learning.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Unsupervised learning works with data that has no labels.',
							'answer'   => 0,
							'hint'     => 'Think about what "unsupervised" implies.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Learning by taking actions and adjusting based on rewards and penalties is called ___ learning.',
							'answer_text' => 'reinforcement',
							'accept'      => array(),
							'hint'        => 'It learns by trial and reward, like training a pet.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Give one real-world example of unsupervised learning and explain why it counts as unsupervised.',
							'rubric'   => 'A good answer describes a task (such as grouping customers or documents) and notes that it uses unlabelled data where the system finds structure or clusters on its own, with no predefined answers.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'What machine learning is — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'In machine learning, where does the system get its rules from?',
						'options'  => array(
							'A human writes them out step by step',
							'It learns them from examples in the data',
							'They are downloaded ready-made from the internet',
							'It has no rules at all',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which type of machine learning relies on labelled examples?',
						'options'  => array(
							'Unsupervised',
							'Reinforcement',
							'Supervised',
							'Clustering',
						),
						'answer'   => 2,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Grouping customers into segments without any predefined labels is an example of ___ learning.',
						'answer_text' => 'unsupervised',
						'accept'      => array(),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Machine learning is usually the best choice for a simple, fixed rule like "charge extra on weekends".',
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'The ML workflow',
			'lessons' => array(

				array(
					'title'   => 'Data, features, and labels',
					'type'    => 'reading',
					'est_min' => 9,
					'xp'      => 20,
					'topics'  => array( 'Features & labels' ),
					'content' => '<h2>What a dataset is actually made of</h2>'
						. '<p>Machine learning runs on data, and most data starts life as a simple <strong>table</strong> — rows and columns, like a spreadsheet. Three words describe every such table, and getting them straight makes the rest of ML click into place.</p>'
						. '<h3>A row is one example</h3>'
						. '<p>Each <strong>row</strong> is a single <em>example</em> the model can learn from — one house, one customer, one email. If you are predicting house prices, one row is one house you have data about.</p>'
						. '<h3>Features are the inputs</h3>'
						. '<p>The <strong>features</strong> are the input columns — the facts you know about each example and feed into the model. For a house, the features might be its size in square metres, its number of bedrooms, and its location. Features are what the model looks <em>at</em>.</p>'
						. '<h3>The label is the answer</h3>'
						. '<p>The <strong>label</strong> is the one column you want to predict — the answer. For our houses, the label is the <em>sale price</em>. In training data you already know the label; the whole point is to learn how the features connect to it so you can predict the label for a new house where you do not know it yet.</p>'
						. '<p>Here is the shape of it:</p>'
						. '<ul>'
						. '<li><strong>Features (inputs):</strong> size = 85 square metres, bedrooms = 2, location = "city centre"</li>'
						. '<li><strong>Label (answer to predict):</strong> price = $320,000</li>'
						. '</ul>'
						. '<h3>Garbage in, garbage out</h3>'
						. '<p>A model can only be as good as the data you feed it. If your features are wrong, missing, or biased — prices typed in the wrong currency, whole neighbourhoods absent — the model learns those flaws faithfully and predicts badly. Practitioners call this <strong>"garbage in, garbage out."</strong> Clean, representative data usually matters more than any clever algorithm.</p>'
						. '<blockquote>Row = one example. Features = the inputs you know. Label = the answer you want to predict.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Think of a spreadsheet: each row is an example, the feature columns are what you feed in, and the label column is what you are trying to predict. Master this vocabulary and most machine learning explanations suddenly make sense — and remember that the quality of that data sets the ceiling on how good your model can be.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'In a table of housing data used to predict price, which column is the label?',
							'options'  => array(
								'The size of the house',
								'The number of bedrooms',
								'The location',
								'The sale price you want to predict',
							),
							'answer'   => 3,
							'hint'     => 'The label is the answer you are trying to predict.',
							'feedback_correct'   => 'Correct — the sale price is the value you want the model to predict, so it is the label.',
							'feedback_incorrect' => 'Not quite. Size, bedrooms, and location are inputs (features); the price you predict is the label.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'The features are the input columns the model uses to make a prediction.',
							'answer'   => 0,
							'hint'     => 'Features are what the model looks at.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'The single column you are trying to predict is called the ___ .',
							'answer_text' => 'label',
							'accept'      => array( 'target' ),
							'hint'        => 'It is the answer attached to each training example.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Imagine you want to predict whether a customer will cancel a subscription. List three features you might use and name the label.',
							'rubric' => 'A strong answer lists three plausible input features (for example: months subscribed, number of support tickets, last login date) and correctly names the label as whether the customer cancels.',
							'hint'   => 'Features are the facts you know; the label is the yes/no you want to predict.',
						),
					),
				),

				array(
					'title'   => 'Training, testing, and the split',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Train/test split' ),
					'content' => '<h2>How do you know the model actually learned?</h2>'
						. '<p>Suppose you train a model and it answers <em>every</em> example you gave it correctly. Is it good? You cannot tell yet — because it might have simply <strong>memorised</strong> those examples rather than learning a pattern that works on new ones. To find out, you need to test it on data it has never seen.</p>'
						. '<h3>Splitting the data</h3>'
						. '<p>Before training, you divide your data into two parts. A common split is about 80% and 20%:</p>'
						. '<ul>'
						. '<li>The <strong>training set</strong> — the examples the model learns from.</li>'
						. '<li>The <strong>test set</strong> — examples you hide away and never show during training, kept to check the model afterwards.</li>'
						. '</ul>'
						. '<p>The training set <em>teaches</em>; the test set <em>checks</em>. Because the model never saw the test examples while learning, doing well on them is real evidence that it learned a general pattern — not just that it memorised the answers.</p>'
						. '<h3>The golden rule: never test on training data</h3>'
						. '<p>If you grade the model on the same examples it studied, you learn nothing useful — of course it does well, it has seen them. That is like giving students an exam containing the exact questions they were handed with the answers already filled in. A high score there proves memorisation, not understanding. The whole value of the test set is that it is <strong>unseen</strong>.</p>'
						. '<h3>Generalisation</h3>'
						. '<p>The thing we actually care about is <strong>generalisation</strong>: does the model perform well on new, real-world examples it will meet after it is deployed? A model that scores well on training data but poorly on the test set has <em>overfit</em> — it clung to the specific examples instead of the underlying pattern. The held-out test set is how you catch that before it reaches real users.</p>'
						. '<blockquote>Training data teaches the model. A separate, unseen test set proves whether it truly learned.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Split your data first. Let the model learn from the training set, then judge it on a test set it has never seen. Never test on the data you trained on — only performance on unseen examples tells you whether the model will hold up in the real world.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why do we keep a separate test set that the model never sees during training?',
							'options'  => array(
								'To give the model more data to memorise',
								'To make training run faster',
								'To check whether the model generalises to new, unseen examples',
								'Because it is required by law',
							),
							'answer'   => 2,
							'hint'     => 'What are we actually trying to measure about the model?',
							'feedback_correct'   => 'Exactly — testing on unseen data shows whether the model learned a general pattern rather than memorising.',
							'feedback_incorrect' => 'Not quite. The test set exists to measure how well the model performs on data it has not seen before.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'It is good practice to evaluate a model on the exact same examples it was trained on.',
							'answer'   => 1,
							'hint'     => 'A high score on examples it has already seen proves nothing.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'The portion of data the model learns its patterns from is called the ___ set.',
							'answer_text' => 'training',
							'accept'      => array( 'train' ),
							'hint'        => 'The other portion is the test set.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Explain in your own words why a model that scores 100% on its training data might still be a bad model.',
							'rubric'   => 'A good answer explains that a perfect training score can mean the model memorised (overfit) the examples rather than learning a general pattern, so it may perform poorly on new, unseen data — which is why a held-out test set is needed.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'The ML workflow — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'In a dataset, what is the difference between a feature and a label?',
						'options'  => array(
							'Features are the answer to predict; the label is an input',
							'Features are the inputs; the label is the answer you want to predict',
							'They are two words for exactly the same thing',
							'Labels are only used in unsupervised learning',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'What is the main reason to hold out a test set?',
						'options'  => array(
							'To speed up training',
							'To give the model bonus examples to learn from',
							'To measure how well the model performs on data it has not seen',
							'To make the dataset smaller',
						),
						'answer'   => 2,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'The data the model learns its patterns from is called the ___ set.',
						'answer_text' => 'training',
						'accept'      => array( 'train' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Testing a model on the same data it was trained on gives a reliable measure of how it will perform on new data.',
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'Where the difficulty actually lives',
			'lessons' => array(

				array(
					'title'   => 'Garbage in, confident garbage out',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Features & labels', 'Learning from data' ),
					'content' => '<h2>Most failed ML projects fail at the data, not the model</h2>'
						. '<p>The public story of machine learning is about algorithms. The working reality is that teams spend most of their time on data, and almost every project that quietly dies does so because the data could not support the question — not because the model was the wrong shape.</p>'
						. '<h3>Four ways data betrays you</h3>'
						. '<p><strong>Not enough of the thing you care about.</strong> Ten thousand transactions sounds like plenty until you notice only forty were fraudulent. The model learns almost nothing about fraud because it has barely seen any.</p>'
						. '<p><strong>Labels that are actually opinions.</strong> If two people disagree about whether a ticket is "urgent", the model is being asked to learn a rule that does not exist. It will learn something — usually which labeller was busier that week.</p>'
						. '<p><strong>The past does not look like the future.</strong> A model trained on pre-pandemic shopping predicts a world that stopped existing. Data has a shelf life, and nothing in the model tells you when it expired.</p>'
						. '<p><strong>Missing in a way that means something.</strong> A blank income field is not random: it is often people who declined to say. Filling it with the average quietly invents a group that does not exist.</p>'
						. '<blockquote>A model cannot learn what the data never recorded. It will still produce an answer — that is the dangerous part.</blockquote>'
						. '<h3>The questions to ask before any modelling</h3>'
						. '<ul>'
						. '<li>How many examples of the <em>rare</em> outcome do we have? Not how many rows in total.</li>'
						. '<li>Who created these labels, and would a second person agree with them?</li>'
						. '<li>What period is this from, and what has changed since?</li>'
						. '<li>Which fields are missing, and is the missingness itself informative?</li>'
						. '</ul>'
						. '<h3>Recap</h3>'
						. '<p>Interrogate the data before reaching for a model. Volume is not coverage, labels are not facts, and the past is only a guide while the world holds still.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A dataset has 10,000 loan applications, 40 of which defaulted. What is the real problem here?',
							'options'  => array(
								'The dataset is too small overall',
								'There are very few examples of the outcome the model is meant to predict',
								'Loan data cannot be modelled',
								'The rows need reordering',
							),
							'answer'   => 1,
							'hint'     => 'Count the examples of the thing you care about, not the rows.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'If two experts disagree on how to label an example, a model can still be expected to learn the "right" answer.',
							'answer'   => 1,
							'hint'     => 'What rule would it be learning?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Filling blank income fields with the average quietly invents a group of people who do not ___.',
							'answer_text' => 'exist',
							'accept'      => array( 'exists' ),
							'hint'        => 'Missing is often not random.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'You are asked to predict which employees will resign next quarter, using five years of HR records. Name two things about that data you would check before agreeing the project is feasible.',
							'rubric'   => 'A strong answer raises concrete data concerns rather than modelling ones — how many resignations are actually in the data, whether the organisation or job market has changed over the five years, whether the reason-for-leaving field is reliably filled, and whether any field would only be known after someone had already resigned.',
						),
					),
				),

				array(
					'title'   => 'When not to use machine learning',
					'type'    => 'reading',
					'est_min' => 10,
					'xp'      => 25,
					'topics'  => array( 'Learning from data', 'Types of ML' ),
					'content' => '<h2>The most valuable ML skill is recognising the problems that are not ML problems</h2>'
						. '<p>Machine learning earns its cost when a rule is genuinely hard to write down and the pattern is genuinely in the data. Plenty of problems meet neither condition, and reaching for a model there buys you complexity, opacity and a maintenance burden in exchange for nothing.</p>'
						. '<h3>Use a rule instead when the rule exists</h3>'
						. '<p>"Flag any invoice over £10,000 for approval" is a policy. It is exact, auditable, changeable in one line, and explainable to a regulator. A model that learns it from data will be approximately right and impossible to argue with. If you can write the rule down, write the rule down.</p>'
						. '<h3>Be honest when the pattern is not there</h3>'
						. '<p>Some things genuinely are close to unpredictable from the data you hold. A model asked to predict them will not refuse — it will find noise and present it as signal, and it will look convincing right up until it is deployed.</p>'
						. '<h3>Think again when a wrong answer is expensive and unexplainable</h3>'
						. '<p>In lending, hiring and medicine, "the model said so" is not an answer anyone accepts, and in several jurisdictions it is not one the law accepts either. If you cannot explain a decision to the person it affects, the technical accuracy is not the binding constraint.</p>'
						. '<h3>Wait when the ground moves faster than you can retrain</h3>'
						. '<p>A model learns yesterday. If your domain changes weekly and you can retrain monthly, you are always shipping a picture of a world that has moved.</p>'
						. '<blockquote>Ask what a competent person with a spreadsheet would do. If they would do nearly as well, that is your baseline — and quite possibly your solution.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>ML is one tool. Rules beat it on clarity, honesty beats it on unpredictable problems, explainability beats it where decisions affect people, and nothing beats it when the world will not sit still.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which of these is best solved with a written rule rather than a model?',
							'options'  => array(
								'Predicting which customers will churn',
								'Flagging every invoice above a fixed approval threshold',
								'Recognising objects in photographs',
								'Recommending related articles',
							),
							'answer'   => 1,
							'hint'     => 'Which one can you state exactly in a sentence?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Asked to predict something that is essentially unpredictable, a model will report that it cannot.',
							'answer'   => 1,
							'hint'     => 'What does it do with noise?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'In lending or hiring, a decision you cannot ___ to the person it affects is a problem regardless of accuracy.',
							'answer_text' => 'explain',
							'accept'      => array( 'justify' ),
							'hint'        => 'Several jurisdictions require this.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'A colleague proposes an ML model to decide which support tickets are urgent. Write the three questions you would ask before agreeing, and say what answer to each would make you recommend a rule instead.',
							'rubric' => 'A strong answer asks whether urgency can be stated as a rule, whether existing urgency labels are consistent between people, and what the cost of a wrong call is and whether it must be explainable. For each it should name the answer that points to a rule — a statable policy, unreliable labels, or a decision that has to be defensible.',
							'hint'   => 'You are deciding whether this is an ML problem at all.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Where the difficulty lives — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Most machine learning projects that quietly fail do so because of:',
						'options'  => array(
							'The wrong choice of algorithm',
							'Data that cannot support the question being asked',
							'Insufficient computing power',
							'Poorly named variables',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'If you can state the rule exactly in a sentence, writing the rule is usually better than learning it.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'A model trained on data from a world that has since changed is shipping a picture of the ___.',
						'answer_text' => 'past',
						'accept'      => array( 'old world' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Ten thousand rows with forty positive cases is a problem mainly because:',
						'options'  => array(
							'Ten thousand rows is too few for any model',
							'The model has barely seen the outcome it is meant to predict',
							'Forty is not a round number',
							'The data must be sorted first',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Reading ML claims like a professional',
			'lessons' => array(

				array(
					'title'   => 'What an accuracy figure is hiding',
					'type'    => 'reading',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Train/test split', 'Types of ML' ),
					'content' => '<h2>You will be shown numbers. Here is how to read them</h2>'
						. '<p>You do not need to build models to be useful here. You need to be the person in the room who asks the three questions that separate a real result from a demo.</p>'
						. '<h3>1. Measured on what?</h3>'
						. '<p>A score on the data the model trained on is not a result — it is a memory test. The only number that means anything is one measured on data the model has never seen. If nobody can tell you how the split was made, treat the figure as unverified.</p>'
						. '<h3>2. Compared with what?</h3>'
						. '<p>"92% accurate" means nothing on its own. If 92% of your cases are the same class, a model that always guesses that class scores 92% and does no work at all. Always ask what the trivial answer scores, because that is the bar.</p>'
						. '<h3>3. Wrong in which direction?</h3>'
						. '<p>Two models with identical accuracy can be opposites in practice: one misses real cases, the other raises false alarms. Which is worse depends entirely on the situation, and the single accuracy figure hides the choice completely.</p>'
						. '<h3>The demo effect</h3>'
						. '<p>Every model looks impressive on hand-picked examples. Ask to see it on the hard cases, the ambiguous ones, and a random sample nobody chose. Ask what happens on an input it was never designed for.</p>'
						. '<blockquote>Three questions: on unseen data, versus what baseline, and wrong in which direction. Most inflated claims do not survive all three.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Insist on held-out data, a trivial baseline for comparison, and a breakdown of which kind of error the model makes.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A vendor reports 99% accuracy. What is the single most useful follow-up question?',
							'options'  => array(
								'How many rows did you train on?',
								'What does always predicting the majority class score on the same data?',
								'Which programming language is it written in?',
								'How long did training take?',
							),
							'answer'   => 1,
							'hint'     => 'What is the bar the number has to clear?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A score measured on the training data is a valid measure of how a model will perform in production.',
							'answer'   => 1,
							'hint'     => 'What is being tested — learning or memory?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Two models can have identical accuracy but be opposites in practice, because accuracy hides which ___ of error they make.',
							'answer_text' => 'kind',
							'accept'      => array( 'type', 'direction' ),
							'hint'        => 'Missed cases versus false alarms.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'A supplier demos a model that spotted defects perfectly in five photographs they selected. What would you ask to see next, and why?',
							'rubric'   => 'A strong answer asks for performance on a random or independently chosen sample, on deliberately hard or ambiguous cases, and on inputs outside the intended range — explaining that hand-picked examples demonstrate the best case rather than the expected one.',
						),
					),
				),

				array(
					'title'   => 'Your first honest ML conversation',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 30,
					'topics'  => array( 'Learning from data', 'Features & labels', 'Train/test split' ),
					'content' => '<h2>Turning the whole course into one useful habit</h2>'
						. '<p>Whether you commission ML, work beside it, or are simply in the room, the same short frame turns a vague idea into a conversation that goes somewhere.</p>'
						. '<h3>The five questions, in order</h3>'
						. '<ol>'
						. '<li><strong>What decision changes?</strong> If no decision or action changes because of the prediction, there is no project — only a dashboard.</li>'
						. '<li><strong>What exactly is being predicted, and when?</strong> "Churn" is not a target. "Will this customer cancel within 30 days, judged at the moment of their renewal date" is.</li>'
						. '<li><strong>What would we know at that moment?</strong> Every input has to exist <em>before</em> the prediction is made. Anything recorded afterwards is a leak that makes the model look brilliant in testing and useless in production.</li>'
						. '<li><strong>What does the trivial answer achieve?</strong> Always-no, or one obvious rule. That is the number to beat, and it is often uncomfortably high.</li>'
						. '<li><strong>What happens when it is wrong?</strong> Who is affected, who notices, and what is the recourse?</li>'
						. '</ol>'
						. '<h3>Why question three catches the most projects</h3>'
						. '<p>Target leakage is the classic, and it is embarrassingly easy to fall into: predicting cancellations using a "cancellation reason" field, or predicting delivery delays using the actual delivery date. Both score beautifully. Neither can run, because at prediction time the field is empty.</p>'
						. '<blockquote>Decision, target, timing, baseline, consequences. Five questions, and most doomed projects fail one of them out loud in the first meeting.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>You now know what ML is, what the types are, how the workflow runs, where it goes wrong, when not to use it at all, and how to read a claim. The five questions are how you use all of it.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why must every input be available before the moment of prediction?',
							'options'  => array(
								'To save storage',
								'Otherwise the model scores well in testing and cannot run in production',
								'Because models read inputs alphabetically',
								'To keep the dataset small',
							),
							'answer'   => 1,
							'hint'     => 'What is in that field at prediction time?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Using a field that only exists after the outcome has happened is called target ___.',
							'answer_text' => 'leakage',
							'accept'      => array( 'leak' ),
							'hint'        => 'Information from the future getting in.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'If no decision or action changes as a result of the prediction, there is still a worthwhile ML project.',
							'answer'   => 1,
							'hint'     => 'What is the prediction for?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Take a prediction someone in your organisation has wished for and run the five questions over it in writing: the decision that changes, the exact target and its timing, what is known at that moment, the trivial baseline, and what happens when it is wrong.',
							'rubric' => 'A strong answer states a target precise enough to be measured (an event and a time window), identifies at least one field that would be leakage and excludes it, proposes a concrete trivial baseline, and names who is affected by a wrong prediction and what their recourse is.',
							'hint'   => 'Be strict about question three — it is the one that catches real projects.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'Before this course, what did you assume machine learning was mostly about? What would you say now?',
							'rubric'   => 'A thoughtful answer names a specific belief that changed — typically from algorithms and cleverness toward data quality, problem framing, and honest evaluation — rather than a general statement of having learned things.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Reading ML claims — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'        => 'fill_blank',
						'question'    => 'A model score only means something when it is measured on data the model has never ___.',
						'answer_text' => 'seen',
						'accept'      => array( 'been shown' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Predicting cancellations using a "cancellation reason" field is an example of:',
						'options'  => array( 'Good feature engineering', 'Target leakage', 'Overfitting', 'Class imbalance' ),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'A single accuracy figure tells you whether a model tends to miss real cases or raise false alarms.',
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which is the strongest first question about any proposed ML project?',
						'options'  => array(
							'Which library will we use?',
							'What decision changes because of this prediction?',
							'How many GPUs do we need?',
							'Can it run in the cloud?',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
