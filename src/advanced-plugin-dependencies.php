<?php
/**
 * WordPress Plugin Administration API: WP_Plugin_Dependencies class
 *
 * @package WordPress
 * @subpackage Administration
 * @since 6.4.0
 */

/**
 * Child class for installing plugin dependencies.
 *
 * It is designed to add plugin dependencies as designated
 * to a new view in the plugins install page.
 */
class Advanced_Plugin_Dependencies extends WP_Plugin_Dependencies {

	/**
	 * Holds associative array of slug|endpoint, if present.
	 *
	 * @var array
	 */
	protected static $api_endpoints = array();

	/**
	 * Holds $args from `plugins_api_result` hook.
	 *
	 * @var stdClass
	 */
	private static $args;

	/**
	 * Initialize, load filters, and get started.
	 *
	 * @return void
	 */
	public static function initialize() {
		if ( is_admin() ) {
			add_filter( 'plugins_api_result', array( __CLASS__, 'add_plugin_card_dependencies' ), 10, 3 );
			add_filter( 'plugins_api_result', array( __CLASS__, 'plugins_api_result' ), 10, 3 );
			add_filter( 'plugins_api_result', array( __CLASS__, 'empty_plugins_api_result' ), 10, 3 );
			add_filter( 'upgrader_post_install', array( __CLASS__, 'fix_plugin_containing_directory' ), 10, 3 );
			add_filter( 'wp_plugin_dependencies_slug', array( __CLASS__, 'split_slug' ), 10, 1 );
			add_filter( 'plugin_install_description', array( __CLASS__, 'plugin_install_description_installed' ), 10, 2 );
			add_filter( 'plugin_install_description', array( __CLASS__, 'set_plugin_card_data' ), 10, 1 );
			add_filter( 'plugin_install_action_links', array( __CLASS__, 'disable_activate_button' ), 10, 2 );

			self::remove_hook( 'admin_notices', array( 'WP_Plugin_Dependencies', 'admin_notices' ) );
			self::remove_hook( 'network_admin_notices', array( 'WP_Plugin_Dependencies', 'admin_notices' ) );

			add_action( 'admin_init', array( __CLASS__, 'modify_plugin_row' ), 15 );
			add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
			add_action( 'network_admin_notices', array( __CLASS__, 'admin_notices' ) );
		}

		$required_headers = parent::parse_plugin_headers();
		self::$slugs      = parent::sanitize_required_headers( $required_headers );
		parent::get_plugin_api_data();
	}

	/**
	 * Modify plugins_api() response.
	 *
	 * @param stdClass $res    Object of results.
	 * @param string   $action Variable for plugins_api().
	 * @param stdClass $args   Object of plugins_api() args.
	 * @return stdClass
	 */
	public static function plugins_api_result( $res, $action, $args ) {
		if ( property_exists( $args, 'browse' ) && 'dependencies' === $args->browse ) {
			$res->info = array(
				'page'    => 1,
				'pages'   => 1,
				'results' => count( (array) self::$plugin_data ),
			);

			$res->plugins = self::$plugin_data;
		}

		return $res;
	}

	/**
	 * Get default empty API response for non-dot org plugin.
	 *
	 * @param stdClass $res    Object of results.
	 * @param string   $action Variable for plugins_api().
	 * @param stdClass $args   Object of plugins_api() args.
	 * @return stdClass
	 */
	public static function empty_plugins_api_result( $res, $action, $args ) {
		if ( is_wp_error( $res ) ) {
			$res = self::get_empty_plugins_api_response( $res, (array) $args );
		}

		return $res;
	}

	/**
	 * Modify the plugin row.
	 *
	 * @global $pagenow Current page.
	 *
	 * @return void
	 */
	public static function modify_plugin_row() {
		global $pagenow;
		if ( 'plugins.php' !== $pagenow ) {
			return;
		}
		$dependency_paths = self::get_dependency_filepaths();
		foreach ( $dependency_paths as $plugin_file ) {
			if ( $plugin_file ) {
				self::modify_dependency_plugin_row( $plugin_file );
			}
		}

		foreach ( array_keys( self::$requires_plugins ) as $plugin_file ) {
			self::modify_requires_plugin_row( $plugin_file );
		}
	}

	/**
	 * Actually make modifications to plugin row of plugin dependencies.
	 *
	 * @param string $plugin_file Plugin file.
	 * @return void
	 */
	public static function modify_dependency_plugin_row( $plugin_file ) {
		self::remove_hook( 'after_plugin_row_meta', array( 'WP_Plugin_Dependencies', 'modify_plugin_row_elements' ), 10 );
		add_action( 'after_plugin_row_meta', array( __CLASS__, 'modify_plugin_row_elements' ), 10, 3 );
	}

	/**
	 * Add 'Manage Dependencies' action link to plugin row of requiring plugin.
	 *
	 * @param string $plugin_file Plugin file.
	 * @return void
	 */
	public static function modify_requires_plugin_row( $plugin_file ) {
		self::remove_hook( 'after_plugin_row_meta', array( 'WP_Plugin_Dependencies', 'modify_plugin_row_elements_requires' ), 10);
		add_filter( 'after_plugin_row_meta', array( __CLASS__, 'modify_plugin_row_elements_requires' ), 10, 2 );
		add_filter( 'plugin_action_links_' . $plugin_file, array( __CLASS__, 'add_manage_dependencies_action_link' ), 10, 2 );
		add_filter( 'network_admin_plugin_action_links_' . $plugin_file, array( __CLASS__, 'add_manage_dependencies_action_link' ), 10, 2 );
	}

	/**
	 * Disable 'Activate' for dependencies with unmet dependencies.
	 *
	 * @global $pagenow Current page.
	 *
	 * @param array $action_links Array of plugin install action links.
	 * @param array $plugin       Array of plugin data.
	 * @return array
	 */
	public static function disable_activate_button( $action_links, $plugin ) {
		global $pagenow;

		if ( 'plugin-install.php' !== $pagenow
			|| ! str_contains( $action_links[0], 'activate-now' )
		) {
			return $action_links;
		}

		$requires     = isset( self::$requires_plugins[ self::$plugin_dirnames[ $plugin['slug'] ] ]['RequiresPlugins'] )
			? self::$requires_plugins[ self::$plugin_dirnames[ $plugin['slug'] ] ]['RequiresPlugins']
			: '';
		$requires_arr = explode( ',', $requires );
		$requires_arr = array_filter( $requires_arr );
		foreach ( $requires_arr as $require ) {
			$inactive_dependency = is_plugin_inactive( self::$plugin_dirnames[ $require ] );
			if ( $inactive_dependency ) {
				$action_links[0] .= '<span class="screen-reader-text">' . __( 'Cannot activate due to unmet dependency', 'advanced-plugin-dependencies' ) . '</span>';
				$action_links[0]  = str_replace( 'activate-now', 'activate-now button-disabled', $action_links[0] );
				break;
			}
		}

		return $action_links;
	}

	/**
	 * Add 'Required by: ...' and 'Requires: ...' to plugin install cards.
	 *
	 * @param string $description Short description of plugin.
	 * @param array  $plugin      Array of plugin data.
	 * @return string
	 */
	public static function plugin_install_description_installed( $description, $plugin ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_title_with_dashes( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'dependencies' !== $tab ) {
			return $description;
		}

		$required = array();
		$requires = array();
		if ( ! isset( $plugin['requires_plugins'] ) ) {
			$plugin['requires_plugins'] = array();
		}
		if ( in_array( $plugin['slug'], array_keys( self::$plugin_data ), true ) ) {
			$dependents = self::get_dependency_sources( $plugin );
			$dependents = explode( ', ', $dependents );
			$required[] = '<strong>' . __( 'Required by:', 'advanced-plugin-dependencies' ) . '</strong>';
			$required   = array_merge( $required, $dependents );
		}

		foreach ( (array) $plugin['requires_plugins'] as $slug ) {
			if ( isset( self::$plugin_data[ $slug ] ) ) {
				$require_names = self::$plugin_data[ $slug ]['name'];
				$requires[]    = $require_names;
			}
		}

		self::$plugin_card_data = array_merge( self::$plugin_card_data, $requires, $required );

		return $description;
	}

	/**
	 * Add 'Dependencies' link to install plugin tab in plugin row action links.
	 *
	 * @param array  $actions     Plugin action links.
	 * @param string $plugin_file File name.
	 * @return array
	 */
	public static function add_manage_dependencies_action_link( $actions, $plugin_file ) {
		if ( ! isset( $actions['activate'] ) ) {
			return $actions;
		}

		if ( str_contains( $actions['activate'], 'Activate' ) ) {
			$actions['dependencies'] = self::get_dependency_link();
		}

		return $actions;
	}

	/**
	 * Display admin notice if dependencies not installed.
	 *
	 * @global $pagenow Current page.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		global $pagenow;

		// Exit early if user unable to act on notice.
		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		// Only display on specific pages.
		if ( in_array( $pagenow, array( 'plugin-install.php', 'plugins.php' ), true ) ) {
			/*
			 * Plugin deactivated if dependencies not met.
			 * Transient on a 10 second timeout.
			 */
			$deactivate_requires = get_site_transient( 'wp_plugin_dependencies_deactivate_plugins' );
			if ( ! empty( $deactivate_requires ) ) {
				foreach ( $deactivate_requires as $deactivated ) {
					$deactivated_plugins[] = self::$plugins[ $deactivated ]['Name'];
				}
				$deactivated_plugins = implode( ', ', $deactivated_plugins );
				printf(
					'<div class="notice-error notice is-dismissible"><p>'
					/* translators: 1: plugin names, 2: link to Dependencies install page */
					. esc_html__( '%1$s plugin(s) have been deactivated. There are uninstalled or inactive dependencies. Go to the %2$s install page.', 'advanced-plugin-dependencies' )
					. '</p></div>',
					'<strong>' . esc_html( $deactivated_plugins ) . '</strong>',
					wp_kses_post( self::get_dependency_link( true ) )
				);
			} else {
				// More dependencies to install.
				$installed_slugs = array_map( 'dirname', array_keys( self::$plugins ) );
				$intersect       = array_intersect( self::$slugs, $installed_slugs );
				asort( $intersect );
				if ( $intersect !== self::$slugs ) {
					$message_html = __( 'There are additional plugin dependencies that must be installed.', 'advanced-plugin-dependencies' );

					// Display link (if not already on Dependencies install page).
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$tab = isset( $_GET['tab'] ) ? sanitize_title_with_dashes( wp_unslash( $_GET['tab'] ) ) : '';
					if ( 'plugin-install.php' !== $pagenow || 'dependencies' !== $tab ) {
						$message_html .= ' ' . sprintf(
							/* translators: 1: link to Dependencies install page */
							__( 'Go to the %s install page.', 'advanced-plugin-dependencies' ),
							wp_kses_post( self::get_dependency_link( true ) ),
							'</a>'
						);
					}

					printf(
						'<div class="notice-warning notice is-dismissible"><p>%s</p></div>',
						wp_kses_post( $message_html )
					);
				}
			}

			$circular_dependencies = self::get_circular_dependencies();
			if ( ! empty( $circular_dependencies ) && count( $circular_dependencies ) > 1 ) {
				/* translators: circular dependencies names */
				$messages  = sprintf( __( 'You have circular dependencies with the following plugins: %s', 'advanced-plugin-dependencies' ), implode( ', ', $circular_dependencies['names'] ) );
				$messages .= '<br>' . __( 'Please contact the plugin developers and make them aware.', 'advanced-plugin-dependencies' );
				printf(
					'<div class="notice-warning notice is-dismissible"><p>%s</p></div>',
					wp_kses_post( $messages )
				);
			}
		}
	}

	/**
	 * Get Dependencies link.
	 *
	 * @param bool $notice Usage in admin notice.
	 * @return string
	 */
	private static function get_dependency_link( $notice = false ) {
		$link_text = $notice ? __( 'Dependencies', 'advanced-plugin-dependencies' ) : __( 'Manage Dependencies', 'advanced-plugin-dependencies' );
		$link      = sprintf(
			'<a href=' . esc_url( network_admin_url( 'plugin-install.php?tab=dependencies' ) ) . ' aria-label="' . __( 'Go to Dependencies tab of Add Plugins page.', 'advanced-plugin-dependencies' ) . '">%s</a>',
			$link_text
		);

		return $link;
	}

	/**
	 * Return empty plugins_api() response.
	 *
	 * @param stdClass|WP_Error $response Response from plugins_api().
	 * @param array             $args     Array of arguments passed to plugins_api().
	 * @return stdClass
	 */
	private static function get_empty_plugins_api_response( $response, $args ) {
		$slug = $args['slug'];
		$args = array(
			'Name'        => $args['slug'],
			'Version'     => '',
			'Author'      => '',
			'Description' => '',
			'RequiresWP'  => '',
			'RequiresPHP' => '',
			'PluginURI'   => '',
		);
		if ( is_wp_error( $response ) || property_exists( $response, 'error' )
			|| ! property_exists( $response, 'slug' )
			|| ! property_exists( $response, 'short_description' )
		) {
			$dependencies      = self::get_dependency_filepaths();
			$file              = $dependencies[ $slug ];
			$args              = $file ? self::$plugins[ $file ] : $args;
			$short_description = __( 'You will need to manually install this dependency. Please contact the plugin\'s developer and ask them to add plugin dependencies support and for information on how to install the this dependency.', 'advanced-plugin-dependencies' );
			$response          = array(
				'name'              => $args['Name'],
				'slug'              => $slug,
				'version'           => $args['Version'],
				'author'            => $args['Author'],
				'contributors'      => array(),
				'requires'          => $args['RequiresWP'],
				'tested'            => '',
				'requires_php'      => $args['RequiresPHP'],
				'sections'          => array(
					'description'  => '<p>' . $args['Description'] . '</p>' . $short_description,
					'installation' => __( 'Ask the plugin developer where to download and install this plugin dependency.', 'advanced-plugin-dependencies' ),
				),
				'short_description' => '<p>' . $args['Description'] . '</p>' . $short_description,
				'download_link'     => '',
				'banners'           => array(),
				'icons'             => array( 'default' => "https://s.w.org/plugins/geopattern-icon/{$slug}.svg" ),
				'last_updated'      => '',
				'num_ratings'       => 0,
				'rating'            => 0,
				'active_installs'   => 0,
				'homepage'          => $args['PluginURI'],
				'external'          => 'xxx',
			);
			$response          = (object) $response;
		}

		return $response;
	}

	/**
	 * Split slug into slug and endpoint.
	 *
	 * @param string $slug Slug.
	 *
	 * @return string
	 */
	public static function split_slug( $slug ) {
		if ( ! str_contains( $slug, '|' ) || str_starts_with( $slug, '|' ) || str_ends_with( $slug, '|' ) ) {
			return $slug;
		}

		$original_slug           = $slug;
		list( $slug, $endpoint ) = explode( '|', $slug );
		$slug                    = trim( $slug );
		$endpoint                = trim( $endpoint );

		if ( '' === $slug || '' === $endpoint ) {
			return $original_slug;
		}

		if ( ! isset( self::$api_endpoints[ $slug ] ) ) {
			self::$api_endpoints[ $slug ] = $endpoint;
		}

		return $slug;
	}

	/**
	 * Filter `plugins_api_result` for adding plugin dependencies.
	 *
	 * @param stdClass $response Response from `plugins_api()`.
	 * @param string   $action   Action type.
	 * @param stdClass $args     Array of data from hook.
	 *
	 * @return void|WP_Error
	 */
	public static function add_plugin_card_dependencies( $response, $action, $args ) {
		$rest_endpoints = self::$api_endpoints;
		self::$args     = $args;

		$plugin_headers = parent::parse_plugin_headers();

		if ( is_wp_error( $response )
			|| ( property_exists( $args, 'slug' ) && array_key_exists( $args->slug, self::$api_endpoints ) )
		) {
			/**
			 * Filter the REST enpoints used for lookup of plugins API data.
			 *
			 * @param array
			 */
			$rest_endpoints = array_merge( $rest_endpoints, apply_filters( 'plugin_dependency_endpoints', $rest_endpoints ) );

			foreach ( $rest_endpoints as $endpoint ) {
				// Endpoint must contain correct slug somewhere in URI.
				if ( ! str_contains( $endpoint, $args->slug ) ) {
					continue;
				}

				// Get local JSON endpoint.
				if ( str_ends_with( $endpoint, 'json' ) ) {
					foreach ( $plugin_headers as $plugin_file => $requires ) {
						if ( str_contains( $requires['RequiresPlugins'], $endpoint ) ) {
							$endpoint = plugin_dir_url( $plugin_file ) . $endpoint;
							break;
						}
					}
				}
				$response = wp_remote_get( $endpoint );

				// Convert response to associative array.
				$response = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( null === $response || isset( $response['error'] ) || isset( $response['code'] ) ) {
					$message  = isset( $response['error'] ) ? $response['error'] : '';
					$response = new WP_Error( 'error', 'Error retrieving plugin data.', $message );
				}
				if ( ! is_wp_error( $response ) ) {
					break;
				}
			}

			// Add slug to hook_extra.
			add_filter( 'upgrader_package_options', array( __CLASS__, 'upgrader_package_options' ), 10, 1 );
		}

		return (object) $response;
	}

	/**
	 * Add slug to hook_extra.
	 *
	 * @see WP_Upgrader::run() for $options details.
	 *
	 * @param array $options Array of options.
	 *
	 * @return array
	 */
	public static function upgrader_package_options( $options ) {
		if ( isset( $options['hook_extra']['temp_backup'] ) ) {
			$options['hook_extra']['slug'] = $options['hook_extra']['temp_backup']['slug'];
		} else {
			$options['hook_extra']['slug'] = self::$args->slug;
		}
		remove_filter( 'upgrader_package_options', array( __CLASS__, 'upgrader_package_options' ), 10 );

		return $options;
	}

	/**
	 * Filter `upgrader_post_install` for plugin dependencies.
	 *
	 * For correct renaming of downloaded plugin directory,
	 * some downloads may not be formatted correctly.
	 *
	 * @param bool  $true       Default is true.
	 * @param array $hook_extra Array of data from hook.
	 * @param array $result     Array of data for installation.
	 *
	 * @return bool
	 */
	public static function fix_plugin_containing_directory( $true, $hook_extra, $result ) {
		if ( ! isset( $hook_extra['slug'] ) ) {
			return $true;
		}

		$from = untrailingslashit( $result['destination'] );
		$to   = trailingslashit( $result['local_destination'] ) . $hook_extra['slug'];

		if ( trailingslashit( strtolower( $from ) ) !== trailingslashit( strtolower( $to ) ) ) {
			$true = move_dir( $from, $to, true );
		}

		return $true;
	}

	/**
	 * Kludge to remove hooks when I can't pass the precise object instance.
	 *
	 * @param string                $hook_name The filter hook to which the function to be removed is hooked.
	 * @param callable|string|array $callback  The callback to be removed from running when the filter is applied.
	 *                                         This method can be called unconditionally to speculatively remove
	 *                                         a callback that may or may not exist.
	 * @param int                   $priority  The exact priority used when adding the original filter callback.
	 *
	 * @return void
	 */
	private static function remove_hook( $hook_name, $callback, $priority = 10 ) {
		global $wp_filter;

		if ( isset( $wp_filter[ $hook_name ] ) ) {
			$hooks = $wp_filter[ $hook_name ];
			if ( isset( $wp_filter[ $hook_name ]->callbacks[ $priority ] ) ) {
				$hooks = $wp_filter[ $hook_name ]->callbacks[ $priority ];
				foreach ( $hooks as $hook ) {
					if ( is_array( $hook['function'] )
						&& ( $hook['function'][0] instanceof $callback[0] || $hook['function'][0] === $callback[0] )
					) {
						remove_filter( $hook_name, array( $hook['function'][0], $callback[1] ), $priority );
					}
				}
			}
		}
	}
}

Advanced_Plugin_Dependencies::initialize();
