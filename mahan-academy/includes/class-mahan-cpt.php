<?php
/**
 * Custom Post Types and taxonomies for courses and lessons.
 *
 * @package Mahan_Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mahan_CPT {

	const COURSE = 'mahan_course';
	const LESSON = 'mahan_lesson';
	const PATH   = 'mahan_path';
	const CAT    = 'mahan_category';
	const TOPIC  = 'mahan_topic';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
	}

	public static function register() {
		register_post_type(
			self::COURSE,
			array(
				'label'         => __( 'Courses', 'mahan-academy' ),
				'labels'        => array(
					'name'               => __( 'Courses', 'mahan-academy' ),
					'singular_name'      => __( 'Course', 'mahan-academy' ),
					'add_new'            => __( 'Add Course', 'mahan-academy' ),
					'add_new_item'       => __( 'Add New Course', 'mahan-academy' ),
					'edit_item'          => __( 'Edit Course', 'mahan-academy' ),
					'new_item'           => __( 'New Course', 'mahan-academy' ),
					'view_item'          => __( 'View Course', 'mahan-academy' ),
					'search_items'       => __( 'Search Courses', 'mahan-academy' ),
					'not_found'          => __( 'No courses found', 'mahan-academy' ),
					'not_found_in_trash' => __( 'No courses in trash', 'mahan-academy' ),
					'menu_name'          => __( 'Courses', 'mahan-academy' ),
				),
				'public'        => true,
				'show_in_menu'  => 'mahan-academy',
				'show_in_rest'  => true,
				'has_archive'   => false,
				'menu_icon'     => 'dashicons-welcome-learn-more',
				'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
				'rewrite'       => array( 'slug' => 'course', 'with_front' => false ),
				'capability_type' => 'post',
			)
		);

		register_post_type(
			self::LESSON,
			array(
				'label'         => __( 'Lessons', 'mahan-academy' ),
				'labels'        => array(
					'name'               => __( 'Lessons', 'mahan-academy' ),
					'singular_name'      => __( 'Lesson', 'mahan-academy' ),
					'add_new'            => __( 'Add Lesson', 'mahan-academy' ),
					'add_new_item'       => __( 'Add New Lesson', 'mahan-academy' ),
					'edit_item'          => __( 'Edit Lesson', 'mahan-academy' ),
					'new_item'           => __( 'New Lesson', 'mahan-academy' ),
					'view_item'          => __( 'View Lesson', 'mahan-academy' ),
					'search_items'       => __( 'Search Lessons', 'mahan-academy' ),
					'not_found'          => __( 'No lessons found', 'mahan-academy' ),
					'not_found_in_trash' => __( 'No lessons in trash', 'mahan-academy' ),
					'menu_name'          => __( 'Lessons', 'mahan-academy' ),
				),
				'public'        => true,
				'show_in_menu'  => 'mahan-academy',
				'show_in_rest'  => true,
				'has_archive'   => false,
				'supports'      => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
				'rewrite'       => array( 'slug' => 'lesson', 'with_front' => false ),
				'capability_type' => 'post',
			)
		);

		register_post_type(
			self::PATH,
			array(
				'label'         => __( 'Learning Paths', 'mahan-academy' ),
				'labels'        => array(
					'name'               => __( 'Learning Paths', 'mahan-academy' ),
					'singular_name'      => __( 'Learning Path', 'mahan-academy' ),
					'add_new'            => __( 'Add Path', 'mahan-academy' ),
					'add_new_item'       => __( 'Add New Path', 'mahan-academy' ),
					'edit_item'          => __( 'Edit Path', 'mahan-academy' ),
					'new_item'           => __( 'New Path', 'mahan-academy' ),
					'view_item'          => __( 'View Path', 'mahan-academy' ),
					'search_items'       => __( 'Search Paths', 'mahan-academy' ),
					'not_found'          => __( 'No paths found', 'mahan-academy' ),
					'not_found_in_trash' => __( 'No paths in trash', 'mahan-academy' ),
					'menu_name'          => __( 'Learning Paths', 'mahan-academy' ),
				),
				'public'          => true,
				'show_in_menu'    => 'mahan-academy',
				'show_in_rest'    => true,
				'has_archive'     => false,
				'menu_icon'       => 'dashicons-networking',
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
				'rewrite'         => array( 'slug' => 'path', 'with_front' => false ),
				'capability_type' => 'post',
			)
		);

		register_taxonomy(
			self::CAT,
			array( self::COURSE ),
			array(
				'label'        => __( 'Categories', 'mahan-academy' ),
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => true,
				'rewrite'      => array( 'slug' => 'course-category' ),
			)
		);

		// Topics ("مباحث") — a flat, concept-level vocabulary shared across
		// courses AND lessons. Categories are the Coursera-style domain
		// (Prompt Engineering, Machine Learning); topics are the specific
		// skills/concepts inside them (Few-shot examples, Overfitting, …), and
		// they feed the AI tutor / question generator so it knows what a lesson
		// is actually about.
		register_taxonomy(
			self::TOPIC,
			array( self::COURSE, self::LESSON ),
			array(
				'labels'       => array(
					'name'          => __( 'Topics', 'mahan-academy' ),
					'singular_name' => __( 'Topic', 'mahan-academy' ),
					'menu_name'     => __( 'Topics', 'mahan-academy' ),
					'search_items'  => __( 'Search Topics', 'mahan-academy' ),
					'all_items'     => __( 'All Topics', 'mahan-academy' ),
					'edit_item'     => __( 'Edit Topic', 'mahan-academy' ),
					'add_new_item'  => __( 'Add New Topic', 'mahan-academy' ),
					'new_item_name' => __( 'New Topic Name', 'mahan-academy' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'show_in_menu' => true,
				'hierarchical' => false,
				'rewrite'      => array( 'slug' => 'topic' ),
			)
		);
	}
}
