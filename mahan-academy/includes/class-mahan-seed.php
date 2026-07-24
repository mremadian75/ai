<?php
/**
 * Starter-content installer.
 *
 * Turns the curated data library in `includes/data/` into real content: it
 * creates the Coursera-style category terms and the concept-level topic terms,
 * inserts each course with its units, lessons, exercises, and unit quizzes, and
 * wires the courses into learning-path bundles (specializations).
 *
 * The install is idempotent: every seeded post carries a `_mahan_seed_key`
 * marker, so re-running skips content that already exists (and only relinks
 * bundle membership). Nothing the site owner authored by hand is ever touched.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_Seed {

	/** Marker meta on seeded posts (course/lesson/path). */
	const SEED_META = '_mahan_seed_key';

	/** Option recording the plugin version that last seeded. */
	const OPT_DONE = 'mahan_seed_version';

	/** One-time flag: the auto-seed catch-up has run (so it never re-imposes). */
	const OPT_AUTOSEED = 'mahan_autoseed_done';

	/* ------------------------------------------------------------------ */
	/* Data library                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * All seed courses, ordered for a stable catalog.
	 *
	 * @return array[]
	 */
	public static function courses_data() {
		$out = array();
		foreach ( glob( MAHAN_DIR . 'includes/data/course-*.php' ) as $file ) {
			$course = include $file;
			if ( is_array( $course ) && ! empty( $course['seed_key'] ) && ! empty( $course['title'] ) ) {
				$out[] = $course;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				$ca = isset( $a['category'] ) ? (string) $a['category'] : '';
				$cb = isset( $b['category'] ) ? (string) $b['category'] : '';
				if ( $ca !== $cb ) {
					return strcmp( $ca, $cb );
				}
				$oa = isset( $a['order'] ) ? (int) $a['order'] : 0;
				$ob = isset( $b['order'] ) ? (int) $b['order'] : 0;
				return $oa <=> $ob;
			}
		);
		return $out;
	}

	/**
	 * Bundle (specialization) definitions.
	 *
	 * @return array[]
	 */
	public static function bundles_data() {
		$file = MAHAN_DIR . 'includes/data/bundles.php';
		if ( ! file_exists( $file ) ) {
			return array();
		}
		$bundles = include $file;
		return is_array( $bundles ) ? $bundles : array();
	}

	/**
	 * Short marketing descriptions for the auto-created category terms.
	 *
	 * @return array name => description.
	 */
	public static function category_descriptions() {
		return array(
			'Prompt Engineering' => __( 'Write clear, reliable prompts and build repeatable prompting workflows.', 'mahan-academy' ),
			'Machine Learning'   => __( 'How machines learn from data — core concepts through practical, shippable models.', 'mahan-academy' ),
			'Generative AI'      => __( 'Understand and use generative AI and large language models with confidence.', 'mahan-academy' ),
			'AI at Work'         => __( 'Practical, safe ways to use AI to get everyday work done faster.', 'mahan-academy' ),
			'AI Tools'           => __( 'Hands-on guides to popular AI tools — ChatGPT, Claude, Gemini, and image generators.', 'mahan-academy' ),
			'Responsible AI'     => __( 'Use AI in ways you can defend — risk, fairness, transparency, and the rules that apply.', 'mahan-academy' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Status                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Has any starter content been installed?
	 */
	public static function is_installed() {
		return self::count_installed() > 0;
	}

	/**
	 * Ship the starter content by default, exactly once, without re-imposing it.
	 *
	 * Runs on activation and (for sites that were already active before this
	 * version) once via an `init` catch-up. It only seeds when the site has no
	 * seeded courses yet, then records a permanent flag — so an owner who later
	 * deletes the demo content is never re-flooded with it.
	 */
	public static function maybe_autoseed() {
		// Atomic one-time gate: add_option only succeeds for the FIRST caller
		// (wp_options.option_name is unique), so two concurrent init requests on
		// a fresh site can't both pass the guard and double-seed. It also serves
		// as the "already ran once" flag on every later request.
		if ( false === add_option( self::OPT_AUTOSEED, '1', '', 'no' ) ) {
			return;
		}
		if ( 0 === self::count_installed() ) {
			self::install();
		}
	}

	/**
	 * How many seeded courses currently exist.
	 */
	public static function count_installed() {
		$ids = get_posts(
			array(
				'post_type'      => Mahan_CPT::COURSE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_key'       => self::SEED_META,
			)
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/* ------------------------------------------------------------------ */
	/* Install                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Install (or top up) the starter content. Idempotent.
	 *
	 * @return array { courses, lessons, quizzes, bundles, skipped, terms }
	 */
	public static function install() {
		// Make sure the CPTs + taxonomies are registered so term/post inserts work
		// even if this runs very early.
		if ( ! taxonomy_exists( Mahan_CPT::TOPIC ) ) {
			Mahan_CPT::register();
		}

		$result = array(
			'courses' => 0,
			'lessons' => 0,
			'quizzes' => 0,
			'bundles' => 0,
			'skipped' => 0,
			'terms'   => 0,
		);

		// seed_key => course post id (includes pre-existing ones, so bundles can
		// still link courses that were skipped this run).
		$map = array();

		foreach ( self::courses_data() as $course ) {
			$seed_key = (string) $course['seed_key'];
			$existing = self::find_by_seed_key( Mahan_CPT::COURSE, $seed_key );
			if ( $existing ) {
				$map[ $seed_key ] = $existing;
				$result['skipped']++;
				continue;
			}
			$made = self::install_course( $course, $result );
			if ( $made ) {
				$map[ $seed_key ] = $made['id'];
				$result['courses']++;
				$result['lessons'] += $made['lessons'];
				$result['quizzes'] += $made['quizzes'];
			}
		}

		foreach ( self::bundles_data() as $bundle ) {
			if ( self::install_bundle( $bundle, $map ) ) {
				$result['bundles']++;
			}
		}

		update_option( self::OPT_DONE, MAHAN_VERSION );
		return $result;
	}

	/* ------------------------------------------------------------------ */
	/* Course                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $c      Course data.
	 * @param array $result Running totals (terms counter is bumped by reference).
	 * @return array|null { id, lessons, quizzes }
	 */
	private static function install_course( $c, &$result ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => Mahan_CPT::COURSE,
				'post_status'  => 'publish',
				'post_title'   => (string) $c['title'],
				'post_name'    => isset( $c['slug'] ) ? sanitize_title( (string) $c['slug'] ) : '',
				'post_content' => isset( $c['description'] ) ? (string) $c['description'] : '',
				'post_excerpt' => isset( $c['excerpt'] ) ? (string) $c['excerpt'] : '',
				'menu_order'   => isset( $c['order'] ) ? (int) $c['order'] : 0,
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return null;
		}

		update_post_meta( $post_id, self::SEED_META, (string) $c['seed_key'] );
		update_post_meta( $post_id, Mahan_Courses::M_SUBTITLE, isset( $c['subtitle'] ) ? (string) $c['subtitle'] : '' );
		update_post_meta( $post_id, Mahan_Courses::M_LEVEL, isset( $c['level'] ) ? (string) $c['level'] : 'beginner' );
		update_post_meta( $post_id, Mahan_Courses::M_EST_HOURS, isset( $c['est_hours'] ) ? (int) $c['est_hours'] : 0 );
		update_post_meta( $post_id, Mahan_Courses::M_OUTCOMES, isset( $c['outcomes'] ) && is_array( $c['outcomes'] ) ? implode( "\n", array_map( 'strval', $c['outcomes'] ) ) : '' );
		update_post_meta( $post_id, Mahan_Courses::M_FEATURED, ! empty( $c['featured'] ) ? 1 : 0 );
		update_post_meta( $post_id, Mahan_Courses::M_CERTIFICATE, ! empty( $c['certificate'] ) ? 1 : 0 );

		// Further reading: the authoritative sources the course is grounded in.
		if ( ! empty( $c['references'] ) && is_array( $c['references'] ) ) {
			$refs = array();
			foreach ( $c['references'] as $ref ) {
				if ( ! is_array( $ref ) || empty( $ref['title'] ) ) {
					continue;
				}
				$refs[] = array(
					'title'  => sanitize_text_field( (string) $ref['title'] ),
					'source' => isset( $ref['source'] ) ? sanitize_text_field( (string) $ref['source'] ) : '',
					'url'    => isset( $ref['url'] ) ? esc_url_raw( (string) $ref['url'] ) : '',
				);
			}
			if ( ! empty( $refs ) ) {
				update_post_meta( $post_id, Mahan_Courses::M_REFERENCES, $refs );
			}
		}

		// Category (Coursera-style domain).
		if ( ! empty( $c['category'] ) ) {
			$descs   = self::category_descriptions();
			$desc    = isset( $descs[ $c['category'] ] ) ? $descs[ $c['category'] ] : '';
			$cat_id  = self::ensure_term( (string) $c['category'], Mahan_CPT::CAT, $desc, $result );
			if ( $cat_id ) {
				wp_set_object_terms( $post_id, array( $cat_id ), Mahan_CPT::CAT );
			}
		}

		// Course-level topics.
		if ( ! empty( $c['topics'] ) && is_array( $c['topics'] ) ) {
			$topic_ids = self::ensure_terms( $c['topics'], Mahan_CPT::TOPIC, $result );
			if ( $topic_ids ) {
				wp_set_object_terms( $post_id, $topic_ids, Mahan_CPT::TOPIC );
			}
		}

		$lessons  = 0;
		$quiz_map = array();
		$units    = isset( $c['units'] ) && is_array( $c['units'] ) ? $c['units'] : array();
		$unit_i   = 0;
		foreach ( $units as $unit ) {
			$unit_i++;
			// Sanitize the unit title ONCE and use the same value for the lesson
			// meta (M_UNIT) and the quiz map key. Mahan_Quizzes::save_all()
			// re-sanitizes the key, so an unsanitized title here would store a
			// mismatched lesson unit and silently orphan the unit quiz.
			$unit_title = sanitize_text_field( isset( $unit['title'] ) ? (string) $unit['title'] : sprintf( __( 'Unit %d', 'mahan-academy' ), $unit_i ) );
			$order_i    = 0;
			$unit_lessons = isset( $unit['lessons'] ) && is_array( $unit['lessons'] ) ? $unit['lessons'] : array();
			foreach ( $unit_lessons as $lesson ) {
				$order_i++;
				if ( self::install_lesson( $post_id, (string) $c['seed_key'], $unit_title, $unit_i, $order_i, $lesson, $result ) ) {
					$lessons++;
				}
			}
			if ( ! empty( $unit['quiz'] ) && ! empty( $unit['quiz']['questions'] ) ) {
				$quiz_map[ $unit_title ] = $unit['quiz'];
			}
		}

		$quizzes = 0;
		if ( ! empty( $quiz_map ) && class_exists( 'Mahan_Quizzes' ) ) {
			Mahan_Quizzes::save_all( $post_id, $quiz_map );
			$quizzes = count( $quiz_map );
		}

		return array( 'id' => (int) $post_id, 'lessons' => $lessons, 'quizzes' => $quizzes );
	}

	/* ------------------------------------------------------------------ */
	/* Lesson                                                              */
	/* ------------------------------------------------------------------ */

	private static function install_lesson( $course_id, $course_seed, $unit_title, $unit_order, $order, $lesson, &$result ) {
		if ( ! is_array( $lesson ) || empty( $lesson['title'] ) ) {
			return 0;
		}
		$lesson_id = wp_insert_post(
			array(
				'post_type'    => Mahan_CPT::LESSON,
				'post_status'  => 'publish',
				'post_title'   => (string) $lesson['title'],
				'post_content' => isset( $lesson['content'] ) ? (string) $lesson['content'] : '',
				'menu_order'   => (int) $order,
			),
			true
		);
		if ( is_wp_error( $lesson_id ) || ! $lesson_id ) {
			return 0;
		}

		$type = isset( $lesson['type'] ) ? sanitize_key( (string) $lesson['type'] ) : 'reading';
		if ( ! in_array( $type, array( 'reading', 'practice', 'video' ), true ) ) {
			$type = 'reading';
		}

		update_post_meta( $lesson_id, self::SEED_META, $course_seed . ':u' . (int) $unit_order . ':l' . (int) $order );
		update_post_meta( $lesson_id, Mahan_Courses::M_COURSE_ID, (int) $course_id );
		update_post_meta( $lesson_id, Mahan_Courses::M_UNIT, (string) $unit_title );
		update_post_meta( $lesson_id, Mahan_Courses::M_UNIT_ORDER, (int) $unit_order );
		update_post_meta( $lesson_id, Mahan_Courses::M_ORDER, (int) $order );
		update_post_meta( $lesson_id, Mahan_Courses::M_TYPE, $type );
		update_post_meta( $lesson_id, Mahan_Courses::M_EST_MIN, isset( $lesson['est_min'] ) ? (int) $lesson['est_min'] : 0 );
		update_post_meta( $lesson_id, Mahan_Courses::M_XP, isset( $lesson['xp'] ) ? (int) $lesson['xp'] : 0 );

		if ( ! empty( $lesson['topics'] ) && is_array( $lesson['topics'] ) ) {
			$topic_ids = self::ensure_terms( $lesson['topics'], Mahan_CPT::TOPIC, $result );
			if ( $topic_ids ) {
				wp_set_object_terms( $lesson_id, $topic_ids, Mahan_CPT::TOPIC );
			}
		}

		$exercises = self::normalize_exercises( isset( $lesson['exercises'] ) ? $lesson['exercises'] : array() );
		if ( ! empty( $exercises ) ) {
			update_post_meta( $lesson_id, Mahan_Courses::M_EXERCISES, $exercises );
		}

		return (int) $lesson_id;
	}

	/**
	 * Coerce authored exercise data into the stored shape, assigning stable keys.
	 *
	 * @param array $list Authored exercises.
	 * @return array[]
	 */
	private static function normalize_exercises( $list ) {
		$valid = array( 'multiple_choice', 'true_false', 'fill_blank', 'short_answer', 'reflection', 'prompt_task' );
		$out   = array();
		$i     = 0;
		foreach ( (array) $list as $ex ) {
			if ( ! is_array( $ex ) ) {
				continue;
			}
			$type = isset( $ex['type'] ) ? sanitize_key( (string) $ex['type'] ) : 'multiple_choice';
			if ( ! in_array( $type, $valid, true ) ) {
				$type = 'multiple_choice';
			}
			$i++;
			$row = array(
				'key'      => 'ex_' . $i,
				'type'     => $type,
				'question' => isset( $ex['question'] ) ? sanitize_textarea_field( (string) $ex['question'] ) : '',
				'hint'     => isset( $ex['hint'] ) ? sanitize_textarea_field( (string) $ex['hint'] ) : '',
			);

			if ( 'multiple_choice' === $type ) {
				$opts = ( isset( $ex['options'] ) && is_array( $ex['options'] ) ) ? array_values( array_map( 'sanitize_text_field', $ex['options'] ) ) : array();
				if ( count( $opts ) < 2 ) {
					continue;
				}
				$row['options']            = $opts;
				$row['answer']             = isset( $ex['answer'] ) ? max( 0, min( count( $opts ) - 1, (int) $ex['answer'] ) ) : 0;
				$row['feedback_correct']   = isset( $ex['feedback_correct'] ) ? sanitize_textarea_field( (string) $ex['feedback_correct'] ) : '';
				$row['feedback_incorrect'] = isset( $ex['feedback_incorrect'] ) ? sanitize_textarea_field( (string) $ex['feedback_incorrect'] ) : '';
			} elseif ( 'true_false' === $type ) {
				$row['options'] = array( __( 'True', 'mahan-academy' ), __( 'False', 'mahan-academy' ) );
				$row['answer']  = ( isset( $ex['answer'] ) && 1 === (int) $ex['answer'] ) ? 1 : 0;
			} elseif ( 'fill_blank' === $type ) {
				$row['answer_text'] = isset( $ex['answer_text'] ) ? sanitize_text_field( (string) $ex['answer_text'] ) : '';
				if ( '' === trim( $row['answer_text'] ) ) {
					continue;
				}
				$row['accept']         = ( isset( $ex['accept'] ) && is_array( $ex['accept'] ) ) ? array_values( array_map( 'sanitize_text_field', $ex['accept'] ) ) : array();
				$row['case_sensitive'] = 0;
			} else { // short_answer, reflection, prompt_task
				$row['rubric']      = isset( $ex['rubric'] ) ? sanitize_textarea_field( (string) $ex['rubric'] ) : '';
				$row['placeholder'] = isset( $ex['placeholder'] ) ? sanitize_text_field( (string) $ex['placeholder'] ) : '';
				if ( 'prompt_task' === $type ) {
					$row['task']     = isset( $ex['task'] ) ? sanitize_textarea_field( (string) $ex['task'] ) : $row['question'];
					$row['question'] = '' !== trim( $row['question'] ) ? $row['question'] : $row['task'];
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Bundle (path)                                                       */
	/* ------------------------------------------------------------------ */

	private static function install_bundle( $b, $map ) {
		if ( ! is_array( $b ) || empty( $b['seed_key'] ) || empty( $b['courses'] ) ) {
			return false;
		}
		$course_ids = array();
		foreach ( (array) $b['courses'] as $seed_key ) {
			if ( isset( $map[ $seed_key ] ) ) {
				$course_ids[] = (int) $map[ $seed_key ];
			}
		}
		if ( empty( $course_ids ) ) {
			return false;
		}

		$existing = self::find_by_seed_key( Mahan_CPT::PATH, (string) $b['seed_key'] );
		if ( $existing ) {
			// Keep membership fresh, but don't overwrite an owner's edits to title/body.
			update_post_meta( $existing, Mahan_Paths::M_COURSES, $course_ids );
			return false;
		}

		$path_id = wp_insert_post(
			array(
				'post_type'    => Mahan_CPT::PATH,
				'post_status'  => 'publish',
				'post_title'   => (string) $b['title'],
				'post_name'    => isset( $b['slug'] ) ? sanitize_title( (string) $b['slug'] ) : '',
				'post_content' => isset( $b['description'] ) ? (string) $b['description'] : '',
				'menu_order'   => isset( $b['order'] ) ? (int) $b['order'] : 0,
			),
			true
		);
		if ( is_wp_error( $path_id ) || ! $path_id ) {
			return false;
		}
		update_post_meta( $path_id, self::SEED_META, (string) $b['seed_key'] );
		update_post_meta( $path_id, Mahan_Paths::M_COURSES, $course_ids );
		update_post_meta( $path_id, Mahan_Paths::M_SUBTITLE, isset( $b['subtitle'] ) ? (string) $b['subtitle'] : '' );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Find a seeded post by its marker, if any.
	 *
	 * @param string $post_type Post type.
	 * @param string $seed_key  Marker value.
	 * @return int Post id or 0.
	 */
	private static function find_by_seed_key( $post_type, $seed_key ) {
		$ids = get_posts(
			array(
				'post_type'      => $post_type,
				// Include 'trash' so re-running the installer never duplicates (or
				// resurrects) a seed post the owner deliberately trashed — 'any'
				// would miss it and create a second copy.
				'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'future', 'trash' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_key'       => self::SEED_META,
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (string) $seed_key,
			)
		);
		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Ensure a term exists in a taxonomy, returning its id.
	 *
	 * @param string     $name     Term name.
	 * @param string     $taxonomy Taxonomy.
	 * @param string     $desc     Optional description (set only on create).
	 * @param array|null $result   Optional running totals (terms counter bumped by ref).
	 * @return int Term id, or 0.
	 */
	private static function ensure_term( $name, $taxonomy, $desc = '', &$result = null ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$args = array();
		if ( '' !== $desc ) {
			$args['description'] = $desc;
		}
		$res = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $res ) ) {
			// Race / already-exists — try to resolve again.
			$term = get_term_by( 'name', $name, $taxonomy );
			return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
		}
		if ( is_array( $result ) ) {
			$result['terms']++;
		}
		return isset( $res['term_id'] ) ? (int) $res['term_id'] : 0;
	}

	/**
	 * Ensure a list of terms exists, returning their ids.
	 *
	 * @param array      $names    Term names.
	 * @param string     $taxonomy Taxonomy.
	 * @param array|null $result   Optional running totals.
	 * @return int[]
	 */
	private static function ensure_terms( $names, $taxonomy, &$result = null ) {
		$ids = array();
		foreach ( (array) $names as $name ) {
			$id = self::ensure_term( (string) $name, $taxonomy, '', $result );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
