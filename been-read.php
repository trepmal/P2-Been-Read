<?php
/*
 * Plugin Name: Been Read
 * Description: Mark posts as read/unread
 * Plugin URI:
 * Version: 2012-11-05
 * Author: Kailey Lampert
 * Author URI: http://kaileylampert.com
 */

// give our set of plugins a special filter for sorting actions
if ( ! has_action( 'p2_action_links', 'p2_append_actions') ) {
	add_action( 'p2_action_links', 'p2_append_actions');
	function p2_append_actions( ) {

		if ( ! is_user_logged_in() ) return;
		$items = apply_filters( 'p2_action_items', array() );
		ksort( $items );
		$items = implode( ' | ', $items );

		echo " | $items";
	}
}

$p2_been_read = new P2_Been_Read();

class P2_Been_Read {

	function __construct() {

		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		add_action( 'user_register', array( &$this, 'user_register' ) );
		add_action( 'init', array( &$this, 'init' ) );

		// create a magic "unread" page
		// site.url/unread/ redirects to site.url/unread/{curent-username}/
		add_action( 'wp_loaded', array( &$this, 'wp_loaded' ) );
		add_filter( 'rewrite_rules_array', array( &$this, 'rewrite_rules_array' ) );
		add_filter( 'query_vars', array( &$this, 'query_vars' ) );
		add_action( 'pre_get_posts', array( &$this, 'pre_get_posts' ) );

	}

	function admin_init() {
		// setup initial terms for taxonomy
		// and only do it once
		if ( ! get_option( 'setup_been_read_terms' ) ) {
			$users = get_users();
			foreach( $users as $u ) {
				if ( ! term_exists( $u->user_login, 'been_read' ) ) {
					wp_insert_term( $u->user_login, 'been_read' );
				}
			}
			update_option( 'setup_been_read_terms', true );
		}
	}

	function user_register( $userid ) {
		// for every new user, create term
		$user = get_user_by( 'id', $userid );
		wp_insert_term( $user->user_login, 'been_read' );
	}

	function init() {

		$been_read_labels = array(
			'name' => 'Been Read',
			'singular_name' => 'Been Read',
			'search_items' => 'Search Been Read',
			'all_items' => 'All Been Read',
			'edit_item' => 'Edit Been Read',
			'update_item' => 'Update Been Read',
			'add_new_item' => 'Add New Been Read',
			'new_item_name' => 'New Been Read Name',
			'menu_name' => 'Been Read',
		);
		register_taxonomy( 'been_read', 'post', array(
				'hierarchical' => false,
				'labels' => $been_read_labels,
				'sort' => true,
				'public' => true,
				'rewrite' => array('slug' => 'been_read'),
			)
		);

		if ( ! is_user_logged_in() ) return;

		add_action( 'wp_enqueue_scripts', array( &$this, 'wp_enqueue_scripts') );
		add_action( 'wp_ajax_toggle_read_status', array( &$this, 'toggle_read_status_cb' ) );
		add_action( 'wp_ajax_mark_all_read', array( &$this, 'mark_all_read_cb' ) );

		add_filter( 'p2_action_items', array( &$this, 'p2_action_items' ) );
		add_filter( 'post_class', array( &$this, 'post_class' ), 10, 3 );

		add_action( 'wp_head', array( &$this, 'mark_single_views_as_read' ) );
		add_action( 'wp_insert_post', array( &$this, 'wp_insert_post' ) );

		add_action( 'admin_bar_menu', array( &$this, 'admin_bar_menu' ) );

	}

	function wp_enqueue_scripts() {
		wp_enqueue_script( 'been-read', plugins_url( 'js/been-read.js', __FILE__ ), array( 'jquery' ), '3', true );
		wp_localize_script( 'been-read', 'been_read', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
		?><style>.been_read-new-post{background:#fffaf0;}</style><?php
	}

	// ajax callback
	function toggle_read_status_cb() {
		$id = $_POST['post_id'];

		$username = get_userdata( get_current_user_id() )->user_login;

		$return = '';
		if ( has_term( $username, 'been_read', $id )) {
			$terms = wp_get_post_terms( $id, 'been_read' );

			$terms = wp_list_pluck( $terms, 'name' );
			$terms = array_flip( $terms );
			unset( $terms[ $username ] );
			$keep = array_flip( $terms );

			wp_set_post_terms( $id, $keep, 'been_read' );
			$return['message'] = 'Mark Read';
			$return['increment'] = 1;
		} elseif ( wp_set_post_terms( $id, $username, 'been_read', true ) ) {
			$return['message'] = 'Mark Unread';
			$return['increment'] = -1;
		} else {
			$return['message'] = 'could not change status';
			$return['increment'] = 0;
		}

		get_unread_count( true );

		die( json_encode( $return ) );
	}

	// ajax callback
	function mark_all_read_cb() {
		$skip = $_POST['skip'];

		foreach( $skip as $k => $v ) {
			$skip[ $k ] = str_replace( 'prologue-', '', $v );
		}

		$counts = get_unread_count();
		$login = wp_get_current_user()->user_login;
		$unread = $counts[ $login ];

		foreach( $unread as $pid ) {
			if ( in_array( $pid, $skip ) ) continue;
			wp_set_post_terms( $pid, $login, 'been_read', true );
		}

		get_unread_count( true );

		die( 'done' );
	}

	function p2_action_items( $items ) {
		if ( is_page() ) return $items;

		$items[-15] = '<a href="#" class="toggle-read-status">'. $this->get_post_readstatus( get_the_ID() ).'</a>';
		return $items;
	}

		function get_post_readstatus( $post_id ) {
			$username = get_userdata( get_current_user_id() )->user_login;
			if ( has_term( $username, 'been_read', $post_id ))
				return 'Mark Unread';
			return 'Mark Read';
		}

	function post_class( $classes, $class, $post_id ) {
		if ( is_page() ) return $classes;

		$username = get_userdata( get_current_user_id() )->user_login;
		if ( !has_term( $username, 'been_read', $post_id ))
			$classes[] = 'been_read-new-post';
		return $classes;
	}

	function wp_insert_post( $post_id ) {
		$username = get_userdata( get_current_user_id() )->user_login;
		wp_set_post_terms( $post_id, $username, 'been_read', true );
	}

	function mark_single_views_as_read() {
		if ( is_singular() ) {
			global $post;
			$username = get_userdata( get_current_user_id() )->user_login;
			wp_set_post_terms( $post->ID, $username, 'been_read', true );
		}
	}

	function admin_bar_menu( $wp_admin_bar ) {

		if ( is_admin() ) return;

		$counts = get_unread_count();
		$login = wp_get_current_user()->user_login;
		$unread = count( $counts[ $login ] );

		if ( $unread < 1 ) return;

		$node = array (
			'parent' => 'my-account',
			'id' => 'unread-posts',
			'title' => "<label class='ab-item' id='unread-count-bubble' style='color: #21759B;border-radius:4px;background:lightgrey;padding:3px;'>{$unread}</label> ". _n( 'Unread Post', 'Unread Posts', $unread ),
			'href' => site_url('unread')
		);

		$wp_admin_bar->add_menu( $node );

		$node = array (
			// 'parent' => 'my-account',
			'parent' => 'unread-posts',
			'id' => 'mark-all-unread-posts',
			'title' => 'Mark all as read',
		);

		$wp_admin_bar->add_menu( $node );

	}

	// flush_rules() if our rules are not yet included
	function wp_loaded(){
		$rules = get_option( 'rewrite_rules' );

		if ( ! isset( $rules['unread$'] ) || 
			! isset( $rules['unread/([^/]*)$'] ) ||
			! isset( $rules['unread/([^/]*)/page/(\d*)$'] ) ) {
			global $wp_rewrite;
			$wp_rewrite->flush_rules();
		}
	}

	// Adding a new rule
	function rewrite_rules_array( $rules ) {
		$newrules = array();
		$newrules['unread$'] = 'index.php?unread';
		$newrules['unread/([^/]*)$'] = 'index.php?unread=$matches[1]';
		$newrules['unread/([^/]*)/page/(\d*)$'] = 'index.php?unread=$matches[1]&paged=$matches[2]';
		return $newrules + $rules;
	}

	// Adding the id var so that WP recognizes it
	function query_vars( $vars ) {
		array_push( $vars, 'unread' );
		return $vars;
	}

	function pre_get_posts( $query ) {

		if ( is_admin() ) return;
		if ( ! isset( $query->query_vars['unread'] ) ) return;

		if ( ! is_user_logged_in() && isset( $query->query_vars['unread'] ) ) {
			$query->is_404 = true;
			return;
		}

		if ( empty( $query->query_vars['unread'] ) ) {
			$login = wp_get_current_user()->user_login;
			wp_redirect( site_url( "unread/$login/" ) );
			exit;
		}

		$args = array( array(
			'terms' => $query->query_vars['unread'],
			'taxonomy' => 'been_read',
			'field' => 'slug',
			'operator' => 'NOT IN'
		) );
		$query->set( 'tax_query', $args );

	}

} // end class

// returns an array of unread counts indexed by user logins
function get_unread_count( $force = false ) {

	$login = wp_get_current_user()->user_login;
	if ( $force )
		delete_transient( 'unread-'.$login );
	if ( false === ( $num_unread = get_transient( 'unread-'.$login ) ) ) {

		$num_unread = array();

			$args = array(
				'tax_query' => array(
					array(
						'taxonomy' => 'been_read',
						'field' => 'slug',
						'terms' => $login,
						'operator' => 'NOT IN'
					)
				),
				'posts_per_page' => -1,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'no_found_rows' => true,
				'fields' => 'ids'
			);
			$unread = new WP_Query( $args );
			$unread = $unread->posts;

			$num_unread[ $login ] = $unread;

		set_transient( 'unread-'.$login, $num_unread, 60*15 );
	}
	return $num_unread;
}