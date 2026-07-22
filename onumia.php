<?php
/**
 * Plugin Name: Onumia
 * Description: Onumia modular WordPress control layer.
 * Version: 0.1.3
 * Requires PHP: 8.2
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: onumia
 *
 * @package Onumia
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

require_once __DIR__ . '/package-bootstrap.php';
if ( ! onumia_package_bootstrap( __FILE__ ) ) {
	return;
}

$onumia_version = require __DIR__ . '/version.php';
if ( ! is_string( $onumia_version ) || '' === trim( $onumia_version ) ) {
	return;
}

define( 'ONUMIA_VERSION', trim( $onumia_version ) );
define( 'ONUMIA_PLUGIN_FILE', __FILE__ );

$onumia_autoloader = __DIR__ . '/vendor/autoload.php';
if ( ! file_exists( $onumia_autoloader ) ) {
	return;
}

require_once $onumia_autoloader;

$onumia_scoped_autoloader = __DIR__ . '/lib/vendor-prefixed/autoload.php';
if ( file_exists( $onumia_scoped_autoloader ) ) {
	require_once $onumia_scoped_autoloader;
}

if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook(
		ONUMIA_PLUGIN_FILE,
		static function (): void {
			( new Onumia\Core\Plugin( ONUMIA_PLUGIN_FILE, ONUMIA_VERSION ) )->activate();
		}
	);
}

if ( function_exists( 'register_uninstall_hook' ) ) {
	register_uninstall_hook( ONUMIA_PLUGIN_FILE, array( Onumia\Core\Plugin::class, 'uninstall' ) );
}

$onumia_plugin = new Onumia\Core\Plugin( ONUMIA_PLUGIN_FILE, ONUMIA_VERSION );
$onumia_plugin->boot();

unset( $onumia_version );
