<?php
/**
 * Seed course: "Supervised Learning Essentials" (Machine Learning bundle).
 *
 * Conforms to the canonical schema contract defined in
 * {@see course-pe-foundations.php}. The seeder ({@see Mahan_Seed}) loads each
 * `course-*.php` and expects exactly that shape: units -> lessons -> exercises,
 * plus an optional per-unit quiz.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'seed_key'    => 'ml-supervised',
	'title'       => 'Supervised Learning Essentials',
	'slug'        => 'ml-supervised',
	'subtitle'    => 'The workhorse of applied ML: regression, classification, and how to tell whether a model is actually any good.',
	'excerpt'     => 'A practical tour of regression, classification, and the evaluation metrics — accuracy, precision, recall, and overfitting checks — that reveal whether a model truly works.',
	'description' => '<p><strong>Supervised learning</strong> is where most real-world machine learning lives — from forecasting sales to flagging fraud to sorting support tickets. If you can frame a problem as "here are examples with the right answers, now predict the answer for new cases", this is the toolkit you reach for.</p>'
		. '<p>This course gives you the working mental models behind the two core tasks — regression and classification — and, just as important, how to tell whether a trained model is genuinely good. You will learn why accuracy alone can lie, what precision and recall really measure, and how to catch a model that has memorised instead of learned. No heavy maths, just the intuition practitioners actually use.</p>',
	'category'    => 'Machine Learning',
	'track'       => 'machine-learning',
	'level_rank'  => 2,
	'level'       => 'intermediate',
	'est_hours'   => 3,
	'featured'    => false,
	'certificate' => true,
	'order'       => 2,
	'topics'      => array( 'Regression', 'Classification', 'Model evaluation', 'Overfitting' ),
	'outcomes'    => array(
		'Tell regression and classification problems apart and pick the right one for a task',
		'Explain the line-of-best-fit and decision-boundary intuitions in plain language',
		'Judge a model with the right metric instead of relying on accuracy alone',
		'Weigh precision against recall based on which mistake costs more',
		'Detect overfitting with a held-out test set and balance it against underfitting',
	),
	'references'  => array(
		array(
			'title'  => 'The Elements of Statistical Learning',
			'source' => 'Hastie, Tibshirani & Friedman — Springer',
			'url'    => 'https://hastie.su.domains/ElemStatLearn/',
		),
		array(
			'title'  => 'An Introduction to Statistical Learning',
			'source' => 'James, Witten, Hastie & Tibshirani — Springer',
			'url'    => 'https://www.statlearning.com/',
		),
		array(
			'title'  => 'Classification: Accuracy, Precision and Recall',
			'source' => 'Google — Machine Learning Crash Course',
			'url'    => 'https://developers.google.com/machine-learning/crash-course/classification/accuracy-precision-recall',
		),
		array(
			'title'  => 'scikit-learn: model evaluation',
			'source' => 'scikit-learn user guide',
			'url'    => 'https://scikit-learn.org/stable/modules/model_evaluation.html',
		),
	),
	'units'       => array(

		/* ---- Unit 1 ------------------------------------------------------ */
		array(
			'title'   => 'Regression and classification',
			'lessons' => array(

				array(
					'title'   => 'Predicting numbers: regression',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 20,
					'topics'  => array( 'Regression' ),
					'content' => '<h2>Regression predicts a number</h2>'
						. '<p>Supervised learning comes in two flavours, and the first is <strong>regression</strong>: predicting a <em>continuous number</em>. Think house price, next month\'s demand, tomorrow\'s temperature, or the delivery time for an order. The answer is not a yes/no or a category — it is a value on a scale.</p>'
						. '<h3>The line-of-best-fit intuition</h3>'
						. '<p>Imagine plotting the size of houses (in square metres) against their sale price. The dots scatter, but they trend upward: bigger homes cost more. Regression draws the single <strong>line of best fit</strong> that passes as close to all the dots as possible. Once you have that line, you can read a prediction straight off it — give it a size, and it returns a price.</p>'
						. '<p>Real models use many inputs at once — size, location, number of bedrooms, age — but the idea is the same: find the relationship that best maps inputs to a number.</p>'
						. '<h3>A business example</h3>'
						. '<p>A retail planner wants to forecast <strong>next month\'s sales</strong>. She feeds the model past months of data — marketing spend, season, price, web traffic — each paired with the actual sales that followed. The model learns the pattern and outputs a single figure: an estimated sales number for next month. That figure feeds staffing, stock orders, and cash-flow plans.</p>'
						. '<h3>Error is distance from the line</h3>'
						. '<p>No line passes through every dot perfectly. The <strong>error</strong> for each point is how far it sits from the line — the gap between what the model predicted and what actually happened. Training a regression model means adjusting the line to make those gaps, taken together, as small as possible. A model whose predictions sit close to reality has small error; one that is wildly off has large error.</p>'
						. '<blockquote>Regression asks "how much?" or "how many?" — and answers with a number on a scale, judged by how close it lands to the truth.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Regression predicts continuous numbers, from prices to demand. Picture a line of best fit through your data, use it to read off predictions, and measure quality by the distance between predictions and reality.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which of these is a regression task?',
							'options'  => array(
								'Deciding whether an email is spam or not',
								'Sorting product reviews into positive or negative',
								'Predicting next month\'s sales revenue in dollars',
								'Choosing which of five categories a photo belongs to',
							),
							'answer'   => 2,
							'hint'     => 'Regression outputs a number on a scale, not a label.',
							'feedback_correct'   => 'Right — a dollar figure is a continuous number, so that is regression.',
							'feedback_incorrect' => 'Those options predict a category or label. Regression predicts a continuous number, like a revenue figure.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Regression predicts a continuous number rather than a category.',
							'answer'   => 0,
							'hint'     => 'Price, demand, temperature — values on a scale.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A regression model\'s error can be measured as the ___ between each data point and the line of best fit.',
							'answer_text' => 'distance',
							'accept'      => array( 'gap', 'difference', 'residual' ),
							'hint'        => 'How far the actual point sits from the prediction.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Give an example of a prediction problem from your own field that is a regression task, and say what number is being predicted.',
							'rubric'   => 'A good answer names a real task whose output is a continuous quantity (e.g. price, duration, demand, temperature) and identifies that number explicitly, rather than a yes/no or category.',
						),
					),
				),

				array(
					'title'   => 'Predicting categories: classification',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 20,
					'topics'  => array( 'Classification' ),
					'content' => '<h2>Classification predicts a category</h2>'
						. '<p>The second flavour of supervised learning is <strong>classification</strong>: instead of a number, the model predicts a <em>class</em> or <em>label</em>. Is this email spam or not spam? Will this customer churn or stay? Which of five product categories does this item belong to? The answer is a bucket, not a value on a scale.</p>'
						. '<h3>Binary versus multi-class</h3>'
						. '<p><strong>Binary</strong> classification chooses between exactly two options — spam or not, fraud or legitimate, churn or retain. <strong>Multi-class</strong> classification chooses among three or more — routing a support ticket to one of six departments, or tagging a photo as cat, dog, or bird. Same idea, more buckets.</p>'
						. '<h3>The decision-boundary intuition</h3>'
						. '<p>Picture plotting customers by two features, say monthly spend and months since last purchase. Those who churned cluster in one region; those who stayed cluster in another. Classification learns a <strong>decision boundary</strong> — a dividing line between the regions. New customers falling on one side are predicted to churn; those on the other side are predicted to stay.</p>'
						. '<h3>Probabilities and thresholds</h3>'
						. '<p>Most classifiers do not just say "churn". They output a <strong>probability</strong> — say a 0.82 chance of churn. You then apply a <strong>threshold</strong> to turn that number into a decision: if the probability is above 0.5, call it churn. Moving the threshold changes behaviour. Lower it to 0.3 and you flag more customers as at-risk (catching more real churners but also more false alarms); raise it to 0.7 and you flag fewer, only the most confident cases.</p>'
						. '<blockquote>Classification asks "which one?" — it sorts each example into a labelled bucket, often via a probability and a threshold you control.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Classification predicts labels, not numbers. It can be binary or multi-class, it separates classes with a decision boundary, and it usually works through a probability that a threshold converts into a final call.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which of these is a multi-class classification problem?',
							'options'  => array(
								'Predicting tomorrow\'s temperature in degrees',
								'Deciding whether a transaction is fraud or not',
								'Routing a support ticket to one of six departments',
								'Estimating a house\'s sale price',
							),
							'answer'   => 2,
							'hint'     => 'Multi-class means choosing among three or more labels.',
							'feedback_correct'   => 'Exactly — six possible departments is a multi-class label problem.',
							'feedback_incorrect' => 'Look for the option that chooses among three or more labels. Fraud-or-not is only two, and the others predict numbers.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Binary classification chooses between exactly two possible classes.',
							'answer'   => 0,
							'hint'     => 'Spam or not spam; fraud or legitimate.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A classifier often outputs a probability, and a ___ decides the cutoff that turns it into a final class.',
							'answer_text' => 'threshold',
							'accept'      => array( 'cutoff', 'cut-off' ),
							'hint'        => 'Above this value, call it positive; below it, negative.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Describe a classification task you could build at work. State whether it is binary or multi-class, name the labels, and give two input features the model could use.',
							'rubric' => 'A strong answer frames a genuine classification problem, correctly labels it binary or multi-class, lists the possible classes, and names at least two plausible input features.',
							'hint'   => 'Start from a yes/no or which-bucket decision someone currently makes by hand.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Regression and classification — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'What is the key difference between regression and classification?',
						'options'  => array(
							'Regression predicts a category; classification predicts a number',
							'Regression predicts a continuous number; classification predicts a category or label',
							'They are two names for the same thing',
							'Regression only works on images and classification only on text',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Which task is a classification problem?',
						'options'  => array(
							'Forecasting next quarter\'s revenue',
							'Predicting a house\'s exact sale price',
							'Deciding whether a loan applicant will default: yes or no',
							'Estimating tomorrow\'s rainfall in millimetres',
						),
						'answer'   => 2,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Choosing among more than two labels, such as routing to one of six teams, is called ___ classification.',
						'answer_text' => 'multi-class',
						'accept'      => array( 'multiclass', 'multi class' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'Predicting whether an email is spam or not spam is a binary classification task.',
						'answer'   => 0,
					),
				),
			),
		),

		/* ---- Unit 2 ------------------------------------------------------ */
		array(
			'title'   => 'Evaluating models',
			'lessons' => array(

				array(
					'title'   => 'Accuracy, precision, and recall',
					'type'    => 'reading',
					'est_min' => 14,
					'xp'      => 25,
					'topics'  => array( 'Model evaluation' ),
					'content' => '<h2>Why accuracy alone can fool you</h2>'
						. '<p>Once a model makes predictions, you need to know whether it is any good. The obvious metric is <strong>accuracy</strong>: the share of predictions it got right. It is useful — but on the wrong data it hides disaster.</p>'
						. '<h3>The 99% trap</h3>'
						. '<p>Suppose one transaction in a hundred is fraudulent. A lazy model that labels <em>every</em> transaction "not fraud" is <strong>99% accurate</strong> — and completely worthless, because it catches zero actual fraud. When one class is rare (imbalanced data), high accuracy can simply mean the model always guesses the majority. You need metrics that look at the rare class directly.</p>'
						. '<h3>Precision and recall</h3>'
						. '<p>Two metrics do exactly that. <strong>Precision</strong> asks: of all the cases the model flagged as positive, how many really were? High precision means few false alarms. <strong>Recall</strong> asks: of all the cases that truly were positive, how many did the model catch? High recall means few misses.</p>'
						. '<p>For fraud: precision is how many of the flagged transactions were genuinely fraud; recall is how much of the total fraud you actually caught.</p>'
						. '<h3>The trade-off</h3>'
						. '<p>You usually cannot max out both. Flag more transactions and you catch more fraud (recall up) but raise more false alarms (precision down). Flag fewer and precision rises while recall falls. Which matters more depends on the cost of a mistake:</p>'
						. '<ul>'
						. '<li><strong>Cancer screening:</strong> a missed case is deadly, so favour <strong>recall</strong> — catch every possible case, tolerate some false alarms.</li>'
						. '<li><strong>Spam filtering:</strong> deleting a real email is annoying, so favour <strong>precision</strong> — only bin what you are sure is junk.</li>'
						. '</ul>'
						. '<blockquote>Accuracy tells you how often you were right overall; precision and recall tell you how you were right and wrong on the class that matters.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>On imbalanced data, accuracy misleads. Precision (of the predicted positives, how many were correct) and recall (of the actual positives, how many were caught) reveal the truth — and you trade one against the other based on which error costs more.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A fraud detector labels every transaction "not fraud" and scores 99% accuracy on data where 1% of transactions are fraud. Why is it useless?',
							'options'  => array(
								'Because 99% is actually a low score',
								'Because it never catches any real fraud — its recall is zero',
								'Because its precision is far too high',
								'Because accuracy cannot be computed on imbalanced data',
							),
							'answer'   => 1,
							'hint'     => 'Think about how many of the actual fraud cases it caught.',
							'feedback_correct'   => 'Correct — it misses 100% of the fraud, so recall is zero despite the high accuracy.',
							'feedback_incorrect' => 'The score is high precisely because fraud is rare; the real problem is that it catches none of it (zero recall).',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Recall measures, of all the cases that were actually positive, how many the model correctly caught.',
							'answer'   => 0,
							'hint'     => 'Recall is about misses; precision is about false alarms.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Of all the cases the model predicted positive, the fraction that were actually positive is called ___.',
							'answer_text' => 'precision',
							'accept'      => array( 'the precision' ),
							'hint'        => 'The metric about false alarms, not misses.',
						),
						array(
							'type'     => 'multiple_choice',
							'question' => 'A hospital wants to catch as many real cancer cases as possible, even if that means some false alarms. Which should the model prioritise?',
							'options'  => array(
								'High recall — catch as many true cases as possible',
								'High precision above all else',
								'Raw accuracy only',
								'None of these matter for screening',
							),
							'answer'   => 0,
							'hint'     => 'A missed case is far costlier than a false alarm here.',
							'feedback_correct'   => 'Right — when a miss is dangerous, you favour recall and accept more false alarms.',
							'feedback_incorrect' => 'When missing a real case is the costly error, you prioritise recall, not precision or bare accuracy.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'A fraud model reports 99.4% accuracy on a dataset where 0.6% of transactions are fraudulent. Why is that number close to meaningless, and which measures would you ask for instead?',
							'rubric'   => 'A strong answer spots that a model predicting "never fraud" for every transaction would score 99.4% while catching nothing, so accuracy is uninformative on heavily imbalanced data. It should request precision and recall (or a confusion matrix) and connect the choice between them to the relative cost of a missed fraud versus a blocked legitimate customer.',
						),
					),
				),

				array(
					'title'   => 'Overfitting and generalisation',
					'type'    => 'reading',
					'est_min' => 14,
					'xp'      => 25,
					'topics'  => array( 'Overfitting' ),
					'content' => '<h2>Overfitting: when memorising beats learning</h2>'
						. '<p>A model can score brilliantly on the data it trained on and still be useless in the real world. That gap is the central problem of machine learning, and it has a name: <strong>overfitting</strong>.</p>'
						. '<h3>Memorising versus generalising</h3>'
						. '<p>The goal of a supervised model is to <strong>generalise</strong> — to perform well on new, unseen data, not just the examples it studied. An overfit model does the opposite: it effectively <em>memorises</em> the training set, quirks and noise included, instead of learning the underlying pattern. Ask it about a case it has never seen and it stumbles.</p>'
						. '<h3>The tell-tale sign</h3>'
						. '<p>Overfitting has a clear signature: a <strong>great training score but a poor test score</strong>. A model that is 99% right on data it has seen and 62% right on data it has not is not learning the pattern — it is reciting answers. The wider that gap, the worse the overfitting.</p>'
						. '<h3>Why complex models overfit</h3>'
						. '<p>An overly <strong>complex</strong> model — one flexible enough to bend around every single training point — has the capacity to memorise. Like a student who learns exact exam answers by heart rather than the concepts, it aces the practice paper and fails the real test the moment the questions change.</p>'
						. '<h3>The held-out test set</h3>'
						. '<p>Detecting it is simple and non-negotiable: hold back a portion of your data as a <strong>test set</strong> the model never trains on. Judge the model only on that unseen data. If training accuracy is high but test accuracy is low, you have caught overfitting before it reaches production.</p>'
						. '<h3>The opposite problem</h3>'
						. '<p>Watch the other extreme too. <strong>Underfitting</strong> is when a model is too simple to capture the pattern at all, so it does poorly even on the training data. Good modelling lives between the two: complex enough to learn, simple enough to generalise.</p>'
						. '<blockquote>If it shines on data it has seen but fails on data it has not, it memorised instead of learned.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Overfitting is memorising the training data at the expense of new data; you spot it as a high train score paired with a low test score, guard against it with a held-out test set, and balance it against underfitting.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'A model scores 99% on its training data but only 62% on unseen test data. What is most likely happening?',
							'options'  => array(
								'Underfitting',
								'Overfitting',
								'The data is perfectly balanced',
								'Ideal generalisation',
							),
							'answer'   => 1,
							'hint'     => 'A big gap between train and test performance is the classic tell.',
							'feedback_correct'   => 'Correct — a high train score with a low test score is the signature of overfitting.',
							'feedback_incorrect' => 'The model does great on seen data but poorly on unseen data — that gap is overfitting, not underfitting.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A held-out test set that the model never trained on helps reveal overfitting.',
							'answer'   => 0,
							'hint'     => 'You need unseen data to see whether the model generalises.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A model that memorises the training data but fails on new data is said to be ___.',
							'answer_text' => 'overfitting',
							'accept'      => array( 'overfit', 'over-fitting' ),
							'hint'        => 'The opposite of generalising well.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'You can reliably detect overfitting by looking only at the training score.',
							'answer'   => 1,
							'hint'     => 'The training score looks great precisely when a model overfits.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Your model scores 97% on training data and 71% on held-out data. Describe two different changes you would try, and what result would tell you each one had worked.',
							'rubric'   => 'A strong answer proposes two genuinely different remedies — for example more or more varied training data, a simpler model or stronger regularisation, removing features that encode noise, or better cross-validation — and for each states the observable evidence of success: the gap between training and held-out scores narrowing without the held-out score falling.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Evaluating models — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'Why can 99% accuracy be misleading?',
						'options'  => array(
							'Accuracy can never be misleading',
							'On imbalanced data a model can score high by always predicting the majority class while missing every rare case',
							'Accuracy is not a real metric',
							'Accuracy only applies to regression problems',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'Recall answers which question?',
						'options'  => array(
							'Of the cases we predicted positive, how many were actually right?',
							'Of the cases that were actually positive, how many did we catch?',
							'How fast does the model make a prediction?',
							'How large is the training set?',
						),
						'answer'   => 1,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'You detect overfitting by comparing the training score against the score on a held-out ___ set.',
						'answer_text' => 'test',
						'accept'      => array( 'testing', 'validation', 'hold-out' ),
					),
					array(
						'type'     => 'true_false',
						'question' => 'A model that memorises its training data but performs poorly on new data is overfitting.',
						'answer'   => 0,
					),
				),
			),
		),

		/* ---- Unit 3 ------------------------------------------------------ */
		array(
			'title'   => 'Features: where models are actually made',
			'lessons' => array(

				array(
					'title'   => 'Feature engineering, plainly',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 25,
					'topics'  => array( 'Regression', 'Classification' ),
					'content' => '<h2>The model sees only what you put in front of it</h2>'
						. '<p>A supervised model does not understand your business. It sees columns of numbers. <strong>Feature engineering</strong> is the work of turning what you know into columns the model can use — and on most real problems it moves the result more than swapping algorithms ever will.</p>'
						. '<h3>A raw field is rarely a good feature</h3>'
						. '<p>A timestamp is close to useless as a number. The same timestamp turned into <em>hour of day</em>, <em>day of week</em>, <em>is it a public holiday</em>, and <em>days since the customer last ordered</em> gives the model four things it can actually learn from. Nothing new was measured; the information was made visible.</p>'
						. '<h3>Ratios beat raw counts more often than people expect</h3>'
						. '<p>"Number of support tickets" mostly tells the model how big a customer is. "Tickets per user per month" tells it whether they are struggling. The second one is the question you meant.</p>'
						. '<h3>Categories need a representation</h3>'
						. '<p>A model cannot read "London". Encoding turns categories into numbers — but be careful: mapping cities to 1, 2, 3 implies that London is less than Manchester and that the midpoint of the two means something. It does not. One column per category avoids inventing an order that was never there.</p>'
						. '<h3>Scale matters to many models</h3>'
						. '<p>If salary runs to five digits and years-of-service to two, some algorithms will let salary drown the other out purely because the numbers are bigger. Putting features on a comparable scale is routine, cheap, and easy to forget.</p>'
						. '<blockquote>Ask what a knowledgeable human would look at to make this judgement — then build the column that holds it. That question is most of feature engineering.</blockquote>'
						. '<h3>The discipline that keeps it honest</h3>'
						. '<p>Every feature must be computable at the moment the prediction is made, from information that exists then. It is remarkably easy to build a brilliant feature out of something that is only known afterwards.</p>'
						. '<h3>Recap</h3>'
						. '<p>Derive rather than dump. Prefer ratios where size would otherwise dominate, encode categories without implying an order, keep scales comparable, and check the timing of every input.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which is the strongest feature for predicting whether a customer is struggling with a product?',
							'options'  => array(
								'Total support tickets ever raised',
								'Support tickets per active user per month',
								'The customer\'s account ID',
								'The date the account was created, as a raw number',
							),
							'answer'   => 1,
							'hint'     => 'Which one is not just a proxy for company size?',
						),
						array(
							'type'     => 'true_false',
							'question' => 'Mapping cities to the numbers 1, 2 and 3 is a safe way to give a model categorical information.',
							'answer'   => 1,
							'hint'     => 'What does the model infer from 1 < 2 < 3?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'If salary runs to five digits and years of service to two, features should be put on a comparable ___.',
							'answer_text' => 'scale',
							'accept'      => array( 'range' ),
							'hint'        => 'Otherwise the bigger numbers dominate.',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'You are predicting whether a delivery will be late, and you have: order timestamp, warehouse, postcode, item weight, courier, and the customer\'s order history. Propose five derived features and say what each is meant to capture.',
							'rubric' => 'A strong answer derives rather than lists — hour and day-of-week from the timestamp, distance or region from the postcode, the courier\'s recent late rate, warehouse load at order time, and something from history such as the customer\'s previous late rate. Each should be justified by what a knowledgeable human would look at, and all must be computable at order time.',
							'hint'   => 'What would an experienced dispatcher glance at?',
						),
					),
				),

				array(
					'title'   => 'Choosing the threshold is a business decision',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 25,
					'topics'  => array( 'Classification', 'Model evaluation' ),
					'content' => '<h2>Most classifiers do not output a class. They output a probability</h2>'
						. '<p>A fraud model does not say "fraud". It says 0.73. Something then has to decide that 0.73 counts as fraud — and that something is a <strong>threshold</strong> you choose, not a fact the model discovered.</p>'
						. '<p>Almost everyone leaves it at 0.5 because that is the default. That default encodes a specific claim: that a missed case and a false alarm cost exactly the same. They almost never do.</p>'
						. '<h3>Moving the dial</h3>'
						. '<p>Lower the threshold and you catch more real cases and raise more false alarms — recall up, precision down. Raise it and the reverse. You cannot have both, and the model does not improve either way; you are choosing which error to make.</p>'
						. '<h3>Price the two errors</h3>'
						. '<p>Cancer screening: a missed case may be fatal, a false alarm means a follow-up test. Threshold goes low. Automatically blocking a customer\'s card: a false alarm strands someone at a checkout, a missed case is a chargeback the bank absorbs. Threshold goes higher. Same mathematics, opposite decision, and only the domain can settle it.</p>'
						. '<h3>Do the arithmetic out loud</h3>'
						. '<p>At a threshold catching 90% of fraud, you review 500 alerts a week for 60 real cases. At 70%, 120 alerts for 47 cases. If your team can review 150 a week, the second is not a compromise — it is the only one that runs. Capacity is part of the model.</p>'
						. '<blockquote>The threshold is where the model stops and the organisation starts. Leaving it at the default is a decision, just an unexamined one.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Classifiers output probabilities; the cut-off is yours. Set it from the relative cost of the two errors and the capacity of whoever handles the output — never from the default.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Lowering a classifier\'s threshold from 0.5 to 0.3 will:',
							'options'  => array(
								'Improve both precision and recall',
								'Catch more real cases and raise more false alarms',
								'Make the model more accurate on unseen data',
								'Have no effect without retraining',
							),
							'answer'   => 1,
							'hint'     => 'You are choosing which error to make, not improving the model.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A threshold of 0.5 is neutral — it makes no assumption about the relative cost of the two errors.',
							'answer'   => 1,
							'hint'     => 'What claim does treating them equally make?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Most classifiers output a ___ rather than a class, and the cut-off is chosen by you.',
							'answer_text' => 'probability',
							'accept'      => array( 'score', 'probability score' ),
							'hint'        => 'Something like 0.73.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Your model flags suspicious expense claims. Finance can investigate about 30 a month. Explain how you would set the threshold, and what you would tell finance about what they are giving up.',
							'rubric'   => 'A strong answer sets the threshold from review capacity — high enough that alerts fit within 30 a month — and is explicit about the trade: some genuine cases will go uninvestigated. It should propose measuring what is missed (sampling below the threshold, or tracking cases found by other means) rather than assuming the unflagged are clean.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Features and thresholds — quiz',
				'passing'   => 70,
				'xp'        => 30,
				'questions' => array(
					array(
						'type'     => 'multiple_choice',
						'question' => 'On most real problems, the biggest gains come from:',
						'options'  => array(
							'Switching to a more advanced algorithm',
							'Better features built from what you know about the domain',
							'Training for more epochs',
							'Adding more columns of any kind',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'true_false',
						'question' => 'Choosing a classification threshold is a business decision, not a purely technical one.',
						'answer'   => 0,
					),
					array(
						'type'        => 'fill_blank',
						'question'    => 'Every feature must be computable at the moment the ___ is made, from information available then.',
						'answer_text' => 'prediction',
						'accept'      => array( 'decision' ),
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'A team can review 150 alerts a week. A threshold producing 500 alerts is:',
						'options'  => array(
							'Better, because it catches more',
							'Unworkable — capacity is part of the design',
							'Irrelevant to the model',
							'Fine if the model is accurate',
						),
						'answer'   => 1,
					),
				),
			),
		),

		/* ---- Unit 4 ------------------------------------------------------ */
		array(
			'title'   => 'Validating like you mean it',
			'lessons' => array(

				array(
					'title'   => 'Cross-validation and the split you got wrong',
					'type'    => 'reading',
					'est_min' => 12,
					'xp'      => 25,
					'topics'  => array( 'Model evaluation', 'Overfitting' ),
					'content' => '<h2>One split can be lucky. Several cannot all be</h2>'
						. '<p>Hold out 20% of the data, score on it, and you get a number. Do it again with a different 20% and you get a different number — sometimes markedly different, especially on smaller datasets. Neither is wrong; both are one sample of the model\'s behaviour.</p>'
						. '<p><strong>Cross-validation</strong> removes the luck. Split the data into (say) five parts, train on four and test on the fifth, and rotate until each part has been the test set once. You end up with five scores. Their average is a better estimate, and their <em>spread</em> is the part people ignore: a model scoring 71, 89, 74, 91 and 70 has an average of 79 and is nothing you would deploy.</p>'
						. '<h3>Three splits, not two</h3>'
						. '<p>If you use the test set to choose between models, you have tuned to it, and its score is no longer an honest estimate — you have simply overfitted more slowly. The clean structure is three: <strong>train</strong> to fit, <strong>validation</strong> to make choices, and a <strong>test</strong> set opened once, at the end.</p>'
						. '<h3>Random splitting is wrong more often than you think</h3>'
						. '<p><strong>Time.</strong> If you are predicting the future, train on the past and test on what came after. A random split lets the model see next month while predicting last month, which flatters it enormously.</p>'
						. '<p><strong>Groups.</strong> With several rows per customer, a random split puts the same customer in both sets. The model recognises the customer rather than learning the pattern. Split by customer instead.</p>'
						. '<p><strong>Rarity.</strong> With a rare outcome, a careless split can leave almost none of it in the test set. Stratify so the proportions match.</p>'
						. '<blockquote>An impossibly good score is not good news. It almost always means information reached the model that will not be there in production.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Average several folds and look at the spread. Keep a test set you open once. Split along time, groups or class proportions whenever a random split would leak.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Five-fold cross-validation gives scores of 71, 89, 74, 91 and 70. What is the right reading?',
							'options'  => array(
								'The model scores 79 and is ready',
								'The wide spread means the result is unstable and not deployable as it stands',
								'Discard the low folds and report 90',
								'The folds were the wrong size',
							),
							'answer'   => 1,
							'hint'     => 'The average is not the whole story.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'When predicting future events, splitting the data randomly is an acceptable way to build a test set.',
							'answer'   => 1,
							'hint'     => 'What does the model get to see?',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'Choices between models should be made on the ___ set, keeping the test set for a single final measurement.',
							'answer_text' => 'validation',
							'accept'      => array( 'dev' ),
							'hint'        => 'The middle one of the three.',
						),
						array(
							'type'     => 'short_answer',
							'question' => 'Your model predicting repeat purchases scores 0.97 — far above anything the team expected. What do you investigate first, and why?',
							'rubric'   => 'A strong answer treats the surprise as suspicious rather than successful, and looks for leakage: a feature only known after the purchase, the same customer appearing in both train and test, or a split that ignores time. It should say why — an impossibly good score usually means information reached the model that will not exist in production.',
						),
					),
				),

				array(
					'title'   => 'A model card for the model you built',
					'type'    => 'practice',
					'est_min' => 11,
					'xp'      => 30,
					'topics'  => array( 'Model evaluation', 'Classification', 'Regression' ),
					'content' => '<h2>The document that makes a model safe to hand over</h2>'
						. '<p>A trained model on its own is an artefact nobody else can judge. A <strong>model card</strong> is the short, honest document that travels with it — what it does, on whom, how well, and where it should not be used. The practice comes out of published research on responsible ML reporting, and it takes half an hour.</p>'
						. '<h3>What goes in it</h3>'
						. '<ul>'
						. '<li><strong>Purpose</strong> — the exact prediction, and the decision it feeds.</li>'
						. '<li><strong>Training data</strong> — what it was, from when, and who is represented in it.</li>'
						. '<li><strong>Performance</strong> — the metrics that matter here, on held-out data, against the trivial baseline.</li>'
						. '<li><strong>Performance by group</strong> — the same numbers broken down by the segments that matter. An overall figure can hide a model that works for the majority and fails for a minority, and that is exactly the failure you must not ship blind.</li>'
						. '<li><strong>Threshold and why</strong> — the cut-off in use and the cost reasoning behind it.</li>'
						. '<li><strong>Out of scope</strong> — the populations, regions or periods it was never validated on.</li>'
						. '<li><strong>Owner and review date.</strong></li>'
						. '</ul>'
						. '<h3>The section that earns its place</h3>'
						. '<p>Performance by group is the one people skip and the one that matters. A model at 88% overall can be 93% for one group and 61% for another. Both numbers are true; only one of them is in the headline; and the person in the second group experiences the model as broken.</p>'
						. '<blockquote>If you cannot state where a model should <em>not</em> be used, you do not yet know enough about it to hand it to anyone.</blockquote>'
						. '<h3>Recap</h3>'
						. '<p>Purpose, data, performance, performance by group, threshold, limits, owner. Write it while you still remember, not when someone finally asks.</p>',
					'exercises' => array(
						array(
							'type'     => 'multiple_choice',
							'question' => 'Which model card section most often reveals a problem the headline metric hides?',
							'options'  => array(
								'The owner\'s name',
								'Performance broken down by group',
								'The training date',
								'The file format',
							),
							'answer'   => 1,
							'hint'     => 'An average can conceal two very different experiences.',
						),
						array(
							'type'        => 'fill_blank',
							'question'    => 'A model card should state the populations and periods the model was never ___ on.',
							'answer_text' => 'validated',
							'accept'      => array( 'tested', 'evaluated' ),
							'hint'        => 'The out-of-scope section.',
						),
						array(
							'type'     => 'true_false',
							'question' => 'A model at 88% overall is necessarily performing acceptably for every group in the data.',
							'answer'   => 1,
							'hint'     => 'What can an average conceal?',
						),
						array(
							'type'   => 'prompt_task',
							'task'   => 'Write a model card for a classifier that predicts which job applicants to shortlist. Cover purpose, training data, performance against a baseline, performance by group, the threshold and its justification, out-of-scope uses, and the owner.',
							'rubric' => 'A strong answer names a specific target and the decision it feeds, is explicit about who is represented in the training data and who is not, reports against a trivial baseline, breaks performance down by at least one protected or otherwise material group, justifies the threshold in terms of the cost of each error, and states concrete out-of-scope conditions — different regions, roles or periods — rather than a generic caution.',
							'hint'   => 'Hiring is exactly where the by-group section stops being optional.',
						),
						array(
							'type'     => 'reflection',
							'question' => 'Think of a model or automated score that has affected you personally. Which section of a model card would you most have wanted to read?',
							'rubric'   => 'A thoughtful answer connects a specific personal experience to a specific section — typically performance for people like them, the threshold reasoning, or the out-of-scope limits — rather than answering in the abstract.',
						),
					),
				),
			),
			'quiz'    => array(
				'title'     => 'Validating like you mean it — quiz',
				'passing'   => 70,
				'xp'        => 35,
				'questions' => array(
					array(
						'type'        => 'fill_blank',
						'question'    => 'Rotating which part of the data is the test set, and averaging the results, is called cross-___.',
						'answer_text' => 'validation',
						'accept'      => array(),
					),
					array(
						'type'     => 'true_false',
						'question' => 'With several rows per customer, a random train/test split can let the model recognise the customer instead of learning the pattern.',
						'answer'   => 0,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'An unexpectedly excellent score should first be treated as:',
						'options'  => array(
							'A success worth announcing',
							'A signal to hunt for leakage',
							'A reason to reduce the training data',
							'Proof the algorithm was well chosen',
						),
						'answer'   => 1,
					),
					array(
						'type'     => 'multiple_choice',
						'question' => 'The purpose of keeping a test set untouched until the end is to:',
						'options'  => array(
							'Save computation',
							'Keep one honest estimate that your model choices have not tuned to',
							'Make training faster',
							'Satisfy a file-format requirement',
						),
						'answer'   => 1,
					),
				),
			),
		),
	),
);
