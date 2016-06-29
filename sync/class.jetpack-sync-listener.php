<?php

require_once dirname( __FILE__ ) . '/class.jetpack-sync-queue.php';
require_once dirname( __FILE__ ) . '/class.jetpack-sync-functions.php';
require_once dirname( __FILE__ ) . '/class.jetpack-sync-full.php';
require_once dirname( __FILE__ ) . '/class.jetpack-sync-defaults.php';

require_once dirname( __FILE__ ) . '/class.jetpack-sync-modules.php';

/** 
 * This class monitors actions and logs them to the queue to be sent
 */
class Jetpack_Sync_Listener {
	
	private static $valid_settings = array( 'dequeue_max_bytes' => true, 'upload_max_bytes' => true, 'upload_max_rows' => true, 'sync_wait_time' => true );

	private $sync_queue;
	private $full_sync_client;
	private $meta_types = array( 'post', 'comment' );
	private $taxonomy_whitelist;
	private $is_multisite;

	// singleton functions
	private static $instance;

	public static function getInstance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	// this is necessary because you can't use "new" when you declare instance properties >:(
	protected function __construct() {
		$this->set_defaults();
		$this->init();
	}

	private function init() {

		$handler = array( $this, 'action_handler' );

		foreach( Jetpack_Sync_Modules::get_modules() as $module ) {
			$module->init_listeners( $handler );
		}

		/**
		 * Most of the following hooks are sent to the same $handler
		 * for immediate serialization and queuing be sent to the server.
		 * The only exceptions are actions which need additional processing.
		 */


		// attachments
		add_action( 'edit_attachment', array( $this, 'send_attachment_info' ) );
		// Once we don't have to support 4.3 we can start using add_action( 'attachment_updated', $handler, 10, 3 ); instead
		add_action( 'add_attachment', array( $this, 'send_attachment_info' ) );
		add_action( 'jetpack_sync_save_add_attachment', $handler, 10, 2 );

		// comments
		add_action( 'wp_insert_comment', $handler, 10, 2 );
		add_action( 'deleted_comment', $handler, 10 );
		add_action( 'trashed_comment', $handler, 10 );
		add_action( 'spammed_comment', $handler, 10 );

		add_filter( 'jetpack_sync_before_send_wp_insert_comment', array( $this, 'expand_wp_insert_comment' ) );

		// even though it's messy, we implement these hooks because
		// the edit_comment hook doesn't include the data
		// so this saves us a DB read for every comment event
		foreach ( array( '', 'trackback', 'pingback' ) as $comment_type ) {
			foreach ( array( 'unapproved', 'approved' ) as $comment_status ) {
				$comment_action_name = "comment_{$comment_status}_{$comment_type}";
				add_action( $comment_action_name, $handler, 10, 2 );
				add_filter( 'jetpack_sync_before_send_' . $comment_action_name, array( $this, 'expand_wp_insert_comment' ) );
			}
		}

		// themes
		add_action( 'switch_theme', array( $this, 'send_theme_info' ) );
		add_action( 'jetpack_sync_current_theme_support', $handler, 10 ); // custom hook, see meta-hooks below

		// post-meta, and in the future - other meta?
		foreach ( $this->meta_types as $meta_type ) {
			add_action( "added_{$meta_type}_meta", $handler, 10, 4 );
			add_action( "updated_{$meta_type}_meta", $handler, 10, 4 );
			add_action( "deleted_{$meta_type}_meta", $handler, 10, 4 );
		}

		// terms
		add_action( 'created_term', array( $this, 'save_term_handler' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'save_term_handler' ), 10, 3 );
		add_action( 'jetpack_sync_save_term', $handler, 10, 4 );
		add_action( 'delete_term', $handler, 10, 4 );
		add_action( 'set_object_terms', $handler, 10, 6 );
		add_action( 'deleted_term_relationships', $handler, 10, 2 );

		// users
		add_action( 'user_register', array( $this, 'save_user_handler' ) );
		add_action( 'profile_update', array( $this, 'save_user_handler' ), 10, 2 );
		add_action( 'add_user_to_blog', array( $this, 'save_user_handler' ) );
		add_action( 'jetpack_sync_save_user', $handler, 10, 2 );

		add_action( 'deleted_user', $handler, 10, 2 );
		add_filter( 'jetpack_sync_before_send_jetpack_sync_save_user', array( $this, 'expand_user' ), 10, 2 );
		add_action( 'remove_user_from_blog', $handler, 10, 2 );

		// user roles
		add_action( 'add_user_role', array( $this, 'save_user_role_handler' ), 10, 2 );
		add_action( 'set_user_role', array( $this, 'save_user_role_handler' ), 10, 3 );
		add_action( 'remove_user_role', array( $this, 'save_user_role_handler' ), 10, 2 );

		// user capabilities
		add_action( 'added_user_meta', array( $this, 'save_user_cap_handler' ), 10, 4 );
		add_action( 'updated_user_meta', array( $this, 'save_user_cap_handler' ), 10, 4 );
		add_action( 'deleted_user_meta', array( $this, 'save_user_cap_handler' ), 10, 4 );

		// updates
		add_action( 'set_site_transient_update_plugins', $handler, 10, 1 );
		add_action( 'set_site_transient_update_themes', $handler, 10, 1 );
		add_action( 'set_site_transient_update_core', $handler, 10, 1 );
		add_filter( 'jetpack_sync_before_enqueue_set_site_transient_update_plugins', array( $this, 'filter_update_keys' ), 10, 2 );

		add_action( 'deleted_plugin', $handler, 10, 2 );
		add_action( 'activated_plugin', $handler, 10, 2 );
		add_action( 'deactivated_plugin', $handler, 10, 2 );


		// synthetic actions for full sync
		add_action( 'jetpack_full_sync_start', $handler );
		add_action( 'jetpack_full_sync_end', $handler );
		add_action( 'jetpack_full_sync_comments', $handler ); // also send comments meta
		add_action( 'jetpack_full_sync_updates', $handler );

		add_action( 'jetpack_full_sync_users', $handler );
		add_action( 'jetpack_full_sync_terms', $handler, 10, 2 );
		
		// Module Activation
		add_action( 'jetpack_activate_module', $handler );
		add_action( 'jetpack_deactivate_module', $handler );

		// Send periodic checksum
		add_action( 'jetpack_sync_checksum', $handler );
	}

	// removes unnecessary keys from synced updates data
	function filter_update_keys( $args ) {
		$updates = $args[0];

		if ( isset( $updates->no_update ) ) {
			unset( $updates->no_update );
		}

		return $args;
	}

	function set_taxonomy_whitelist( $taxonomies ) {
		$this->taxonomy_whitelist = $taxonomies;
	}

	function set_codec( iJetpack_Sync_Codec $codec ) {
		$this->codec = $codec;
	}

	function get_codec() {
		return $this->codec;
	}

	function set_full_sync_client( $full_sync_client ) {
		if ( $this->full_sync_client ) {
			remove_action( 'jetpack_sync_full', array( $this->full_sync_client, 'start' ) );
		}

		$this->full_sync_client = $full_sync_client;

		/**
		 * Sync all objects in the database with the server
		 */
		add_action( 'jetpack_sync_full', array( $this->full_sync_client, 'start' ) );
	}

	function get_full_sync_client() {
		return $this->full_sync_client;
	}

	function action_handler() {
		$current_filter = current_filter();
		$args           = func_get_args();

		if ( $current_filter == 'upgrader_process_complete' ) {
			array_shift( $args );
		}

		// don't sync private meta
		if ( preg_match( '/^(added|updated|deleted)_.*_meta$/', $current_filter )
		     && $args[2][0] === '_'
		     && ! in_array( $args[2], Jetpack_Sync_Defaults::$default_whitelist_meta_keys )
		) {
			return;
		}

		/**
		 * Modify or reject the data within an action before it is enqueued locally.
		 *
		 * @since 4.2.0
		 *
		 * @param array The action parameters
		 */
		$args = apply_filters( "jetpack_sync_before_enqueue_$current_filter", $args );

		// allow listeners to abort
		if ( $args === false ) {
			return;
		}

		// if we add any items to the queue, we should 
		// try to ensure that our script can't be killed before
		// they are sent
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$this->sync_queue->add( array(
			$current_filter,
			$args,
			get_current_user_id(),
			microtime( true )
		) );
	}

	function send_theme_info() {
		global $_wp_theme_features;

		$theme_support = array();

		foreach ( Jetpack_Sync_Defaults::$default_theme_support_whitelist as $theme_feature ) {
			$has_support = current_theme_supports( $theme_feature );
			if ( $has_support ) {
				$theme_support[ $theme_feature ] = $_wp_theme_features[ $theme_feature ];
			}

		}

		/**
		 * Fires when the client needs to sync theme support info
		 * Only sends theme support attributes whitelisted in Jetpack_Sync_Defaults::$default_theme_support_whitelist
		 *
		 * @since 4.2.0
		 *
		 * @param object the theme support hash
		 */
		do_action( 'jetpack_sync_current_theme_support', $theme_support );
		return 1; // The number of actions enqueued
	}

	function save_term_handler( $term_id, $tt_id, $taxonomy ) {
		if ( class_exists( 'WP_Term' ) ) {
			$term_object = WP_Term::get_instance( $term_id, $taxonomy );
		} else {
			$term_object = get_term_by( 'id', $term_id, $taxonomy );
		}

		/**
		 * Fires when the client needs to sync a new term
		 *
		 * @since 4.2.0
		 *
		 * @param object the Term object
		 */
		do_action( 'jetpack_sync_save_term', $term_object );
	}

	function send_attachment_info( $attachment_id ) {
		$attachment = get_post( $attachment_id );

		/**
		 * Fires when the client needs to sync an attachment for a post
		 *
		 * @since 4.2.0
		 *
		 * @param int The attachment ID
		 * @param object The attachment
		 */
		do_action( 'jetpack_sync_save_add_attachment', $attachment_id, $attachment );
	}

	function save_user_handler( $user_id, $old_user_data = null ) {

		// ensure we only sync users who are members of the current blog
		if ( ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return;
		}

		$user = $this->sanitize_user( get_user_by( 'id', $user_id ) );

		// Older versions of WP don't pass the old_user_data in ->data
		if ( isset( $old_user_data->data ) ) {
			$old_user = $old_user_data->data;
		} else {
			$old_user = $old_user_data;
		}

		if ( $old_user !== null ) {
			unset( $old_user->user_pass );
			if ( serialize( $old_user ) === serialize( $user->data ) ) {
				return;
			}
		}
		/**
		 * Fires when the client needs to sync an updated user
		 *
		 * @since 4.2.0
		 *
		 * @param object The WP_User object
		 */
		do_action( 'jetpack_sync_save_user', $user );
	}

	function save_user_role_handler( $user_id, $role, $old_roles = null ) {
		$user = $this->sanitize_user( get_user_by( 'id', $user_id ) );

		/**
		 * Fires when the client needs to sync an updated user
		 *
		 * @since 4.2.0
		 *
		 * @param object The WP_User object
		 */
		do_action( 'jetpack_sync_save_user', $user );
	}

	function save_user_cap_handler( $meta_id, $user_id, $meta_key, $capabilities ) {

		// if a user is currently being removed as a member of this blog, we don't fire the event
		if ( current_filter() === 'deleted_user_meta' 
		&&
			preg_match( '/capabilities|user_level/', $meta_key )
		&& 
			! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return;
		}

		$user = $this->sanitize_user( get_user_by( 'id', $user_id ) );
		if ( $meta_key === $user->cap_key ) {
			/**
			 * Fires when the client needs to sync an updated user
			 *
			 * @since 4.2.0
			 *
			 * @param object The WP_User object
			 */
			do_action( 'jetpack_sync_save_user', $user );
		}
	}

	public function sanitize_user( $user ) {
		unset( $user->data->user_pass );
		return $user;
	}

	public function sanitize_user_and_expand( $user ) {
		$user = $this->sanitize_user( $user );
		return $this->add_to_user( $user );
	}

	public function add_to_user( $user ) {
		$user->allowed_mime_types = get_allowed_mime_types( $user );
		return $user;
	}

	public function expand_user( $args ) {
		list( $user ) = $args;
		return array( $this->add_to_user( $user ) );
	}


	function expand_wp_comment_status_change( $args ) {
		return array( $args[0], $this->filter_comment( $args[1] ) );
	}

	function expand_wp_insert_comment( $args ) {
		return array( $args[0], $this->filter_comment( $args[1] ) );
	}

	function filter_comment( $comment ) {
		/**
		 * Filters whether to prevent sending comment data to .com
		 *
		 * Passing true to the filter will prevent the comment data from being sent
		 * to the WordPress.com.
		 * Instead we pass data that will still enable us to do a checksum against the
		 * Jetpacks data but will prevent us from displaying the data on in the API as well as
		 * other services.
		 * @since 4.2.0
		 *
		 * @param boolean false prevent post data from bing sycned to WordPress.com
		 * @param mixed $comment WP_COMMENT object
		 */
		if ( apply_filters( 'jetpack_sync_prevent_sending_comment_data', false, $comment ) ) {
			$blocked_comment = new stdClass();
			$blocked_comment->comment_ID = $comment->comment_ID;
			$blocked_comment->comment_date = $comment->comment_date;
			$blocked_comment->comment_date_gmt = $comment->comment_date_gmt;
			$blocked_comment->comment_approved = 'jetpack_sync_blocked';
			return $blocked_comment;
		}

		return $comment;
	}

	public function full_sync_updates() {
		/**
		 * Tells the client to sync all updates to the server
		 *
		 * @since 4.1
		 *
		 * @param boolean Whether to expand updates (should always be true)
		 */
		do_action( 'jetpack_full_sync_updates', true );
		return 1; // The number of actions enqueued
	}

	public function get_all_updates() {
		return array(
			'core' => get_site_transient( 'update_core' ),
			'plugins' => get_site_transient( 'update_plugins' ),
			'themes' => get_site_transient( 'update_themes' ),
		);
	}

	function set_defaults() {
		$this->sync_queue = new Jetpack_Sync_Queue( 'sync' );

		$this->set_full_sync_client( Jetpack_Sync_Full::getInstance() );
		$this->taxonomy_whitelist        = Jetpack_Sync_Defaults::$default_taxonomy_whitelist;
		$this->is_multisite              = is_multisite();
	}
}