<?php
declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

$third_party_vendor = __DIR__ . '/tools/third-party/vendor';

return array(
	'prefix'                   => 'Onumia\\Lib',
	'exclude-namespaces'       => array(
		'WP_CLI',
	),
	'exclude-classes'          => array(
		'WP_CLI',
		'WP_CLI_Command',
		'WP_Error',
		'WP_HTTP_Response',
		'WP_Post',
		'WP_REST_Request',
		'WP_REST_Response',
		'WP_REST_Server',
		'wpdb',
	),
	'exclude-functions'        => array(
		'add_filter',
		'delete_option',
		'delete_site_option',
		'delete_site_transient',
		'delete_transient',
		'download_url',
		'get_option',
		'get_site_option',
		'get_site_transient',
		'get_transient',
		'home_url',
		'is_multisite',
		'is_plugin_active_for_network',
		'is_wp_error',
		'network_home_url',
		'plugin_basename',
		'set_site_transient',
		'set_transient',
		'update_option',
		'update_site_option',
		'wp_json_encode',
		'wp_parse_url',
		'wp_remote_post',
		'wp_remote_retrieve_body',
		'wp_remote_retrieve_response_code',
		'wp_salt',
	),
	'expose-global-constants'  => true,
	'expose-global-classes'    => false,
	'expose-global-functions'  => true,
	'finders'                  => array(
		Finder::create()
			->files()
			->ignoreVCS( true )
			->in( $third_party_vendor ),
	),
	'patchers'                 => array(
		static function ( string $file_path, string $prefix, string $content ): string {
			unset( $file_path );

			$content = str_replace(
				"'Composer\\Autoload\\ClassLoader' === \$class",
				"'" . $prefix . "\\Composer\\Autoload\\ClassLoader' === \$class",
				$content
			);

			$content = str_replace(
				"spl_autoload_unregister(array('ComposerAutoloader",
				"spl_autoload_unregister(array('" . $prefix . "\\ComposerAutoloader",
				$content
			);

			$content = str_replace(
				"include \$file;",
				"include_once \$file;",
				$content
			);

			$content = str_replace(
				'include $file;',
				'include_once $file;',
				$content
			);

			return $content;
		},
	),
);
