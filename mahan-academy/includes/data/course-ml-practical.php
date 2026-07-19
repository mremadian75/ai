<?php
/**
 * Seed course: "Practical ML: From Idea to Model" (Machine Learning bundle).
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
	'seed_key'    => 'ml-practical',
	'title'       => 'Practical ML: From Idea to Model',
	'slug'        => 'ml-practical',
	'subtitle'    => 'Take a real business question all the way to a working, monitored model — framing, data, baselines, and responsible deployment.',
	'excerpt'     => 'Turn a fuzzy business goal into a concrete ML task, prepare honest data, beat a baseline, and ship a model you can monitor and trust.',
	'description' => '<p>Great machine learning is far more than picking a clever algorithm. The teams that succeed are the ones who <strong>frame the problem well, prepare honest data, and ship responsibly</strong> — and this course walks you through that whole journey with practical, example-driven steps.</p>'
		. '<p>Starting from a vague ask like "reduce churn", you will define a real prediction target, hunt down the data problems that quietly wreck models, beat a simple baseline, and deploy something you can actually monitor over time. No heavy math — just the working judgment that separates a demo from a model people rely on.</p>',
	'category'    => 'Machine Learning',
	'level'       => 'intermediate',
	'est_hours'   => 4,
	'featured'    => false,
	'certificate' => true,
	'order'       => 3,
	'topics'      => array( 'Problem framing', 'Data preparation', 'Baselines & tuning', 'Deployment & monitoring' ),
	'outcomes'    => array(
		'Translate a vague business goal into a concrete prediction target, label, and success metric',
		'Judge when machine learning is the right tool and when a simple rule wins',
		'Spot and fix common data problems — missing values, duplicates, bias, and leakage',
		'Build a simple baseline and tune a model without cheating on your test set',
		'Deploy a model responsibly and monitor it for drift, fairness, and degradation',
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'Framing the problem',
			'lessons' => array(

				array(
					'title'   => 'From business question to ML task',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 20,
					'topics'  => array( 'Problem framing' ),
					'content' => '<h2>Turn a business wish into a prediction the model can make</h2>'
						. '<p>"Reduce churn." "Grow revenue." "Cut support costs." These are business goals, not machine-learning tasks. A model cannot predict a wish — it can only predict a specific, measurable thing about a specific unit. Your first and most important job is translation.</p>'
						. '<h3>Name the unit of prediction</h3>'
						. '<p>Decide what single thing you are scoring. For churn it is usually one customer; for fraud it might be one transaction; for demand it might be one product on one day. Everything downstream — your rows of data, your label, your metric — hangs off this choice. Get it wrong and nothing else will line up.</p>'
						. '<h3>Define the label</h3>'
						. '<p>The label is the exact outcome you predict for each unit. "Reduce churn" becomes: for each active customer, will they cancel within the next 30 days — yes or no? Now it is concrete. You can look back at history, find who actually cancelled, and label those rows. If you cannot write down how you would label past examples, you do not yet have an ML task.</p>'
						. '<h3>Choose a success metric</h3>'
						. '<p>How will you know the model is good enough to use? Accuracy alone can lie — if only 3% of customers churn, a model that predicts "nobody churns" is 97% accurate and completely useless. Pick a metric that reflects the decision: precision, recall, ROC-AUC, or a dollar value tied to the action you will take.</p>'
						. '<blockquote>Business goal, then unit of prediction, then label, then success metric. If any link is missing, you are not ready to model.</blockquote>'
						. '<h3>Ask whether you even need ML</h3>'
						. '<p>Machine learning earns its keep when patterns are complex and data is plentiful. Often a simple rule ("flag any invoice more than 60 days late") or an existing report solves the problem faster, cheaper, and more transparently. Reach for ML when the simpler options genuinely fall short — not because it sounds impressive.</p>'
						. '<h3>A worked example</h3>'
						. '<p>A subscription app wants to "keep more users". We choose the unit (one active subscriber), the label (renews at the next billing date: yes or no), and the metric (ROC-AUC, plus estimated revenue saved by an early-warning email). Now the vague wish is a task an engineer can build and a manager can judge.</p>'
						. '<h3>Recap</h3>'
						. '<p>Framing is the highest-leverage step in any ML project. Nail the unit of prediction, the label, and the success metric before touching a single algorithm — and check that ML is really the right tool at all.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A product team says "reduce churn." Which is the best way to turn that into a concrete ML prediction target?',
							'options'  => array(
								'Make customers happier overall',
								'Reduce churn as much as humanly possible',
								'For each active customer, predict the probability they cancel within the next 30 days',
								'Predict whether the whole company will be profitable this year',
							),
							'answer'   => 2,
							'hint'     => 'A prediction target is specific and measurable for one unit of prediction.',
							'feedback_correct'   => 'Exactly — a per-customer, time-bounded, yes/no outcome is something a model can actually predict and you can label from history.',
							'feedback_incorrect' => 'Not quite. A usable target names the unit (a customer), a concrete outcome (cancel), and a time window.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Before reaching for machine learning, you should check whether a simple rule or an existing report could already solve the problem.',
							'answer'   => 0,
							'hint'     => 'ML earns its keep only when simpler options fall short.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'The single thing the model scores once — one customer, one order, one session — is called the unit of ___.',
							'answer_text' => 'prediction',
							'accept'      => array( 'analysis', 'observation' ),
							'hint'        => 'It is what each row of your data represents.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Take a vague business goal from your own work (for example "grow revenue" or "cut support costs") and rewrite it as a concrete ML task: name the unit of prediction, the exact label you would predict, and the success metric you would judge it by.',
							'rubric' => 'A strong answer names a clear unit of prediction, a specific label that could be derived from historical data, and a success metric tied to the business goal.',
							'hint'   => 'If you cannot describe how you would label past examples, the framing is not concrete enough yet.',
						),
					),
				),

				array(
					'title'   => 'Getting and cleaning data',
					'type'    => 'reading',
					'est_min' => 13,
					'xp'      => 20,
					'topics'  => array( 'Data preparation' ),
					'content' => '<h2>Most of the work is getting the data right</h2>'
						. '<p>Newcomers imagine ML is mostly choosing clever algorithms. In reality, teams spend the majority of their time finding, joining, and cleaning data. A modest model on clean, honest data beats a sophisticated model on a mess almost every time.</p>'
						. '<h3>Where data comes from</h3>'
						. '<p>Your raw material lives in application databases, event logs, spreadsheets, third-party APIs, and CRM exports. Pulling it together means joining tables that were never designed to meet, reconciling mismatched IDs, and lining up time zones and date formats. Expect this plumbing to take longer than you think.</p>'
						. '<h3>Common problems to hunt for</h3>'
						. '<ul>'
						. '<li><strong>Missing values:</strong> blanks, nulls, or sentinel values like -1 or 0 standing in for "unknown". Decide deliberately whether to fill, flag, or drop them.</li>'
						. '<li><strong>Duplicates:</strong> the same event recorded twice inflates patterns and quietly biases the model.</li>'
						. '<li><strong>Bias:</strong> if your history over-represents one group or one period, the model learns that skew and repeats it.</li>'
						. '<li><strong>Leakage:</strong> the sneakiest of all — a feature that secretly contains the answer.</li>'
						. '</ul>'
						. '<h3>Why leakage is so dangerous</h3>'
						. '<p>Data leakage happens when a feature carries information you would not actually have at prediction time. Imagine predicting cancellation and accidentally including the cancellation date as an input. The model looks brilliant in testing and collapses in production, because that column is empty for the customers you actually need to score. Leakage produces great scores and worthless models — always ask, "Would I really know this at the moment I make the prediction?"</p>'
						. '<blockquote>If a feature would not exist at the moment of prediction, it does not belong in your training data.</blockquote>'
						. '<h3>Feature engineering, briefly</h3>'
						. '<p>Once the data is clean, you shape it into useful inputs. Feature engineering means deriving better columns from raw ones: turning a birth date into an age, a timestamp into "hour of day", or a stream of purchases into "orders in the last 90 days". Thoughtful features often help more than a fancier algorithm.</p>'
						. '<h3>Recap</h3>'
						. '<p>Budget most of your time for data. Chase down missing values, duplicates, and bias; guard fiercely against leakage; and invest in a few well-chosen features. Clean, honest, leak-free data is the real foundation of a working model.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which situation is a clear example of data leakage?',
							'options'  => array(
								'A column that records missing sensor readings as 0',
								'Using a customer\'s cancellation date as a feature to predict whether they will cancel',
								'A dataset that contains a handful of duplicate rows',
								'Categorical values stored as text instead of numbers',
							),
							'answer'   => 1,
							'hint'     => 'Which option sneaks in information you would not have at prediction time?',
							'feedback_correct'   => 'Right — the cancellation date reveals the answer and would be empty when you actually need to predict, so it leaks.',
							'feedback_incorrect' => 'Those are real data problems, but leakage specifically means a feature that carries information from after the prediction moment.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'In most real ML projects, cleaning and preparing the data takes only a small fraction of the total effort.',
							'answer'   => 1,
							'hint'     => 'Think about how the lesson described where teams actually spend their time.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'When a feature secretly contains information that would not be available at prediction time, it causes data ___.',
							'answer_text' => 'leakage',
							'accept'      => array( 'leak', 'leaking' ),
							'hint'        => 'It makes the model look great in testing and fail in production.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Feature engineering means creating more useful input columns from raw data, such as turning a birth date into an age.',
							'answer'   => 0,
							'hint'     => 'It is about shaping raw fields into inputs a model can learn from.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Framing the problem — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'You are asked to "improve retention." Which is the most useful ML framing?',
						'options'  => array(
							'Retain every customer forever',
							'For each subscriber, predict whether they renew at their next billing date, and track ROC-AUC',
							'Predict tomorrow\'s weather',
							'Choose the company\'s five-year strategy',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Duplicate rows and missing values are common data-quality problems that can distort a model if left unaddressed.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Letting information that would not exist at prediction time into your features is called data ___.',
						'answer_text' => 'leakage',
						'accept'      => array( 'leak' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Why is data leakage so dangerous?',
						'options'  => array(
							'It makes training run more slowly',
							'It makes the model look great in testing but fail in the real world, because it relied on information it will not have at prediction time',
							'It always uses too much memory',
							'It causes the training script to crash immediately',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Building and shipping',
			'lessons' => array(

				array(
					'title'   => 'Baselines, training, and tuning',
					'type'    => 'reading',
					'est_min' => 14,
					'xp'      => 25,
					'topics'  => array( 'Baselines & tuning' ),
					'content' => '<h2>Start simple, then earn every bit of complexity</h2>'
						. '<p>It is tempting to open with a deep neural network. Resist. The professional move is to build the simplest thing that could possibly work first, then only add complexity that measurably pays off.</p>'
						. '<h3>Always build a baseline to beat</h3>'
						. '<p>A baseline is a deliberately simple reference: predict the most common class, reuse last week\'s value, or fit a plain logistic regression. It costs almost nothing and gives you an honest yardstick. If your elaborate model barely beats "always predict no", the complexity is not worth it — and you learned that cheaply.</p>'
						. '<h3>Train a first real model</h3>'
						. '<p>Split your data first. A common pattern is three parts: a <strong>training</strong> set to learn from, a <strong>validation</strong> set to compare choices, and a <strong>test</strong> set you keep sealed until the very end. Train a straightforward model — say a gradient-boosted tree or logistic regression — and compare it to the baseline on the validation set.</p>'
						. '<h3>Tune, but on the right data</h3>'
						. '<p>Models have <strong>hyperparameters</strong> — settings like tree depth or learning rate that you choose rather than learn. Tuning means trying combinations and keeping what scores best on the <em>validation</em> set. Never tune against the test set: the moment you optimise toward it, it stops being an honest estimate of real-world performance.</p>'
						. '<blockquote>Train on the training set, choose on the validation set, and judge exactly once on the test set.</blockquote>'
						. '<h3>Watch for overfitting</h3>'
						. '<p>If a model scores 99% on training data but 70% on new data, it has memorised noise instead of learning patterns — that gap is overfitting. More data, simpler models, and regularisation all help close it. The validation set is your early-warning system.</p>'
						. '<h3>Iterate deliberately</h3>'
						. '<p>Improvement is a loop: add a feature, retrain, check the validation score, keep what helps, discard what does not. Change one thing at a time so you know what caused a move. Stop when you are comfortably beating the baseline and extra effort stops paying off.</p>'
						. '<h3>Recap</h3>'
						. '<p>Beat a simple baseline, split your data honestly, tune on validation and not test, and stay alert to overfitting. Disciplined iteration beats chasing the fanciest algorithm.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Why start a project with a simple baseline model?',
							'options'  => array(
								'Because simple models are always more accurate than complex ones',
								'To have a cheap, honest yardstick that any fancier model must beat to justify its complexity',
								'Because baselines need no data to build',
								'To remove the need for a validation set',
							),
							'answer'   => 1,
							'hint'     => 'A baseline is a reference score, not the final model.',
							'feedback_correct'   => 'Exactly — if the complex model barely beats the baseline, the extra complexity is not worth it.',
							'feedback_incorrect' => 'A baseline is not about being best; it is a cheap reference every serious model must clearly beat.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'You should tune your hyperparameters against the final test set to squeeze out the best possible score.',
							'answer'   => 1,
							'hint'     => 'What happens to the test set the moment you optimise toward it?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'You tune hyperparameters and compare models on a separate ___ set, keeping the test set sealed until the end.',
							'answer_text' => 'validation',
							'accept'      => array( 'dev', 'development' ),
							'hint'        => 'It sits between the training set and the test set.',
						),
						array(
							'type'     => 'multiple_choice',
							'question' => 'A model scores 99% on the data it trained on but only 70% on new data. This gap is a classic sign of:',
							'options'  => array(
								'underfitting',
								'a perfectly generalising model',
								'overfitting',
								'data leakage being fixed',
							),
							'answer'   => 2,
							'hint'     => 'The model has memorised the training data instead of learning general patterns.',
							'feedback_correct'   => 'Right — a big train/test gap means the model memorised noise rather than learning to generalise.',
							'feedback_incorrect' => 'A large gap between training and new-data scores is the textbook signature of overfitting.',
						),
					),
				),

				array(
					'title'   => 'Deploying and monitoring responsibly',
					'type'    => 'reading',
					'est_min' => 13,
					'xp'      => 25,
					'topics'  => array( 'Deployment & monitoring' ),
					'content' => '<h2>Shipping the model is the middle of the story, not the end</h2>'
						. '<p>A model sitting in a notebook helps no one. Deployment means putting it where real decisions happen — behind an API, inside a nightly batch job that scores customers, or embedded in a product feature. But going live is where the real responsibility begins.</p>'
						. '<h3>Getting a model into use</h3>'
						. '<p>Wrap the model so other systems can call it, feed it the same features it was trained on, and return predictions where they are needed. Start small: roll out to a fraction of traffic, compare against the old process, and expand only when it proves itself.</p>'
						. '<h3>Monitor for drift and degradation</h3>'
						. '<p>The world does not hold still. Customer behaviour shifts, prices change, a competitor launches — and gradually the live data stops looking like your training data. This is <strong>drift</strong>, and it quietly erodes accuracy over time. Monitoring means tracking input distributions and prediction quality on an ongoing basis, and alerting when they slip so you can retrain before users feel it.</p>'
						. '<h3>Close the feedback loop</h3>'
						. '<p>Wherever possible, capture what actually happened — did the flagged customer really churn? Those outcomes become fresh labels for the next round of training, turning your deployed model into a system that keeps learning rather than slowly rotting.</p>'
						. '<h3>Deploy responsibly</h3>'
						. '<p>Models influence real lives, so fairness, transparency, and accountability are not optional extras.</p>'
						. '<ul>'
						. '<li><strong>Fairness:</strong> check that outcomes are not systematically worse for one group, and keep checking over time rather than only at launch.</li>'
						. '<li><strong>Transparency:</strong> be able to explain, at least roughly, why the model made a decision.</li>'
						. '<li><strong>Human in the loop:</strong> for high-stakes calls — loans, hiring, medical triage — a person should review or be able to override the model. The cost of an automated mistake there is simply too high to trust the model alone.</li>'
						. '</ul>'
						. '<blockquote>The higher the stakes, the more a human belongs in the decision.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Deploy gradually, monitor relentlessly for drift, feed real outcomes back into training, and keep people in charge of high-stakes decisions. A responsible, monitored model is the one that keeps earning trust long after launch.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'After deployment, the world changes and incoming data no longer resembles the training data, so accuracy slips. What is this called?',
							'options'  => array(
								'A passing grade',
								'Underfitting',
								'Model drift',
								'A baseline',
							),
							'answer'   => 2,
							'hint'     => 'It is the gradual gap that opens between live data and training data.',
							'feedback_correct'   => 'Exactly — drift is why monitoring and periodic retraining matter after launch.',
							'feedback_incorrect' => 'When live data shifts away from the training data over time, that is drift.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'For high-stakes decisions such as loan approvals or medical triage, keeping a human in the loop is a sensible safeguard.',
							'answer'   => 0,
							'hint'     => 'Think about the cost of an automated mistake in those settings.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Continuously watching a live model for degrading accuracy and shifting inputs is called ___.',
							'answer_text' => 'monitoring',
							'accept'      => array( 'monitor', 'observability' ),
							'hint'        => 'It is the ongoing watch that alerts you when to retrain.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'A deployed loan-approval model starts rejecting far more applicants from one neighborhood than before. Describe two things a responsible team should do in response.',
							'rubric'   => 'A good answer includes investigating for bias or fairness issues and for drift, and involving human review or pausing automated decisions rather than trusting the model blindly.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Building and shipping — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'What is the main reason to build a simple baseline first?',
						'options'  => array(
							'It is legally required for every project',
							'It gives you a reference score that more complex models must beat to justify their cost',
							'It removes the need for data cleaning',
							'It guarantees the model will be free of bias',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Model drift means a deployed model\'s performance can degrade over time as real-world data shifts away from the training data.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Why keep a human in the loop for high-stakes automated decisions?',
						'options'  => array(
							'Because humans are always faster than models',
							'To catch errors, unfair outcomes, and edge cases the model may get wrong, where the cost of a mistake is high',
							'Because it makes the model train faster',
							'Because it is only ever needed for low-stakes decisions',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Watching a live model for a gradual drop in accuracy caused by changing data is watching for model ___.',
						'answer_text' => 'drift',
						'accept'      => array( 'drifting' ),
					),
				),
			),
		),
	),
);
