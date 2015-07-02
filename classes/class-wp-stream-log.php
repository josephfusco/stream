<?php

class WP_Stream_Log {

	/**
	 * Hold class instance
	 *
	 * @access public
	 * @static
	 *
	 * @var WP_Stream_Log
	 */
	public static $instance;

	/**
	 * Previous Stream record ID, used for chaining same-session records
	 *
	 * @access public
	 *
	 * @var int
	 */
	public $prev_record;

	/**
	 * Load log handler class
	 *
	 * @access public
	 * @static
	 *
	 * @return void
	 */
	public static function load() {
		/**
		 * Filter allows developers to change log handler class
		 *
		 * @param  array
		 *
		 * @return string
		 */
		$log_handler = apply_filters( 'wp_stream_log_handler', __CLASS__ );

		self::$instance = new $log_handler;
	}

	/**
	 * Return an active instance of this class, and create one if it doesn't exist
	 *
	 * @access public
	 * @static
	 *
	 * @return WP_Stream_Log
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Log handler
	 *
	 * @access public
	 *
	 * @param  string $connector
	 * @param  string $message
	 * @param  array  $args
	 * @param  int    $object_id
	 * @param  string $context
	 * @param  string $action
	 * @param  int    $user_id
	 *
	 * @return mixed True if updated, otherwise false|WP_Error
	 */
	public function log( $connector, $message, $args, $object_id, $context, $action, $user_id = null ) {
		global $wpdb;

		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		if ( is_null( $object_id ) ) {
			$object_id = 0;
		}

		$wp_cron_tracking = isset( WP_Stream_Settings::$options['advanced_wp_cron_tracking'] ) ? WP_Stream_Settings::$options['advanced_wp_cron_tracking'] : false;
		$author           = new WP_Stream_Author( $user_id );
		$agent            = $author->get_current_agent();

		// WP Cron tracking requires opt-in and WP Cron to be enabled
		if ( ! $wp_cron_tracking && 'wp_cron' === $agent ) {
			return false;
		}

		$user       = new WP_User( $user_id );
		$roles      = get_option( $wpdb->get_blog_prefix() . 'user_roles' );
		$visibility = 'publish';

		if ( self::is_record_excluded( $connector, $context, $action, $user ) ) {
			$visibility = 'private';
		}

		$user_meta = array(
			'user_email'      => (string) ! empty( $user->user_email ) ? $user->user_email : '',
			'display_name'    => (string) $author->get_display_name(),
			'user_login'      => (string) ! empty( $user->user_login ) ? $user->user_login : '',
			'user_role_label' => (string) $author->get_role(),
			'agent'           => (string) $agent,
		);

		if ( 'wp_cli' === $agent && function_exists( 'posix_getuid' ) ) {
			$uid       = posix_getuid();
			$user_info = posix_getpwuid( $uid );

			$user_meta['system_user_id']   = (int) $uid;
			$user_meta['system_user_name'] = (string) $user_info['name'];
		}

		// Prevent any meta with null values from being logged
		$stream_meta = array_filter(
			$args,
			function ( $var ) {
				return ! is_null( $var );
			}
		);

		// All meta must be strings, so we will serialize any array meta values
		array_walk(
			$stream_meta,
			function( &$v ) {
				$v = (string) maybe_serialize( $v );
			}
		);

		// Add user meta to Stream meta
		$stream_meta['user_meta'] = $user_meta;

		// Get the current time in milliseconds
		$iso_8601_extended_date = wp_stream_get_iso_8601_extended_date();

		$recordarr = array(
			'object_id'  => (int) $object_id,
			'site_id'    => (int) is_multisite() ? get_current_site()->id : 1,
			'blog_id'    => (int) apply_filters( 'wp_stream_blog_id_logged', get_current_blog_id() ),
			'user_id'    => (int) $user_id,
			'user_role'  => (string) ! empty( $user->roles ) ? $user->roles[0] : '',
			'created'    => (string) $iso_8601_extended_date,
			'visibility' => (string) $visibility,
			'type'       => 'record',
			'summary'    => (string) vsprintf( $message, $args ),
			'connector'  => (string) $connector,
			'context'    => (string) $context,
			'action'     => (string) $action,
			'ip'         => (string) wp_stream_filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP ),
			'meta'       => (array) $stream_meta,
		);

		$result = WP_Stream::$db->insert( $recordarr );

		self::debug_backtrace( $recordarr );

		return $result;
	}

	/**
	 * This function is use to check whether or not a record should be excluded from the log
	 *
	 * @access public
	 *
	 * @param string $connector
	 * @param string $context
	 * @param string $action
	 * @param int    $user_id
	 * @param string $ip
	 *
	 * @return bool
	 */
	public function is_record_excluded( $connector, $context, $action, $user = null, $ip = null ) {
		if ( is_null( $user ) ) {
			$user = wp_get_current_user();
		}

		$ip = is_null( $ip ) ? wp_stream_filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP ) : wp_stream_filter_var( $ip, FILTER_VALIDATE_IP );

		$user_role = isset( $user->roles[0] ) ? $user->roles[0] : null;

		$record = array(
			'connector'  => $connector,
			'context'    => $context,
			'action'     => $action,
			'author'     => $user->ID,
			'role'       => $user_role,
			'ip_address' => $ip,
		);

		$exclude_settings = isset( WP_Stream_Settings::$options['exclude_rules'] ) ? WP_Stream_Settings::$options['exclude_rules'] : array();

		if ( isset( $exclude_settings['exclude_row'] ) && ! empty( $exclude_settings['exclude_row'] ) ) {
			foreach ( $exclude_settings['exclude_row'] as $key => $value ) {
				// Prepare values
				$author_or_role = isset( $exclude_settings['author_or_role'][ $key ] ) ? $exclude_settings['author_or_role'][ $key ] : '';
				$connector      = isset( $exclude_settings['connector'][ $key ] ) ? $exclude_settings['connector'][ $key ] : '';
				$context        = isset( $exclude_settings['context'][ $key ] ) ? $exclude_settings['context'][ $key ] : '';
				$action         = isset( $exclude_settings['action'][ $key ] ) ? $exclude_settings['action'][ $key ] : '';
				$ip_address     = isset( $exclude_settings['ip_address'][ $key ] ) ? $exclude_settings['ip_address'][ $key ] : '';

				$exclude = array(
					'connector'  => ! empty( $connector ) ? $connector : null,
					'context'    => ! empty( $context ) ? $context : null,
					'action'     => ! empty( $action ) ? $action : null,
					'ip_address' => ! empty( $ip_address ) ? $ip_address : null,
					'author'     => is_numeric( $author_or_role ) ? absint( $author_or_role ) : null,
					'role'       => ( ! empty( $author_or_role ) && ! is_numeric( $author_or_role ) ) ? $author_or_role : null,
				);

				$exclude_rules = array_filter( $exclude, 'strlen' );

				if ( ! empty( $exclude_rules ) ) {
					$excluded = true;

					foreach ( $exclude_rules as $exclude_key => $exclude_value ) {
						if ( $record[ $exclude_key ] !== $exclude_value ) {
							$excluded = false;
							break;
						}
					}

					if ( $excluded ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Send a full backtrace of calls to the PHP error log for debugging
	 *
	 * @access public
	 * @static
	 *
	 * @param array $recordarr
	 *
	 * @return void
	 */
	public static function debug_backtrace( $recordarr ) {
		/**
		 * Enable debug backtrace on records.
		 *
		 * This filter is for developer use only. When enabled, Stream will send
		 * a full debug backtrace of PHP calls for each record. Optionally, you may
		 * use the available $recordarr parameter to specify what types of records to
		 * create backtrace logs for.
		 *
		 * @since 2.0.2
		 *
		 * @param array $recordarr
		 *
		 * @return bool  Set to FALSE by default (backtrace disabled)
		 */
		$enabled = apply_filters( 'wp_stream_debug_backtrace', false, $recordarr );

		if ( ! $enabled ) {
			return;
		}

		if ( version_compare( PHP_VERSION, '5.3.6', '<' ) ) {
			error_log( 'WP Stream debug backtrace requires at least PHP 5.3.6' );
			return;
		}

		// Record details
		$summary   = isset( $recordarr['summary'] ) ? $recordarr['summary'] : null;
		$author    = isset( $recordarr['author'] ) ? $recordarr['author'] : null;
		$connector = isset( $recordarr['connector'] ) ? $recordarr['connector'] : null;
		$context   = isset( $recordarr['context'] ) ? $recordarr['context'] : null;
		$action    = isset( $recordarr['action'] ) ? $recordarr['action'] : null;

		// Stream meta
		$stream_meta = isset( $recordarr['meta'] ) ? $recordarr['meta'] : null;

		unset( $stream_meta['user_meta'] );

		if ( $stream_meta ) {
			array_walk( $stream_meta, function( &$value, $key ) {
				$value = sprintf( '%s: %s', $key, ( '' === $value ) ? 'null' : $value );
			});

			$stream_meta = implode( ', ', $stream_meta );
		}

		// User meta
		$user_meta = isset( $recordarr['meta']['user_meta'] ) ? $recordarr['meta']['user_meta'] : null;

		if ( $user_meta ) {
			array_walk( $user_meta, function( &$value, $key ) {
				$value = sprintf( '%s: %s', $key, ( '' === $value ) ? 'null' : $value );
			});

			$user_meta = implode( ', ', $user_meta );
		}

		// Debug backtrace
		ob_start();

		debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // Option to ignore args requires PHP 5.3.6

		$backtrace = ob_get_clean();
		$backtrace = array_values( array_filter( explode( "\n", $backtrace ) ) );

		$output = sprintf(
			"WP Stream Debug Backtrace\n\n    Summary | %s\n     Author | %s\n  Connector | %s\n    Context | %s\n     Action | %s\nStream Meta | %s\nAuthor Meta | %s\n\n%s\n",
			$summary,
			$author,
			$connector,
			$context,
			$action,
			$stream_meta,
			$user_meta,
			implode( "\n", $backtrace )
		);

		error_log( $output );
	}

}
