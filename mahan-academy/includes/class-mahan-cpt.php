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
	const CAT    = 'mahan_category';

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
	}
}
