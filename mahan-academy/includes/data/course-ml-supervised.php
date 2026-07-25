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
	'est_hours'   => 2,
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
	),
);
