<?php

/**
 * Onumia admin app page.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Admin;

use Onumia\PublicApi\Filters;

final class AppPage {
	private const CAPABILITY              = 'manage_options';
	private const PAGE_SLUG               = 'onumia';
	private const PAGE_HOOKS              = array( 'toplevel_page_onumia', 'tools_page_onumia', 'settings_page_onumia' );
	private const VITE_HANDLE             = 'onumia-app-vite-client';
	private const APP_HANDLE              = 'onumia-app';
	private const ADMIN_MENU_STYLE_HANDLE = 'onumia-admin-menu';
	private const RESET_STYLE_HANDLE      = 'onumia-admin-reset';
	private const SURFACE_STYLE_HANDLE    = 'onumia-app-surface-reset';
	private const ENTRYPOINT              = 'src/apps/onumia/main.tsx';
	private const ASSET_DIRECTORY         = 'assets/app';
	private const BRAND_LOGO              = 'assets/brand/logo.svg';
	private const BRAND_LOGO_LIGHT        = 'assets/brand/logo-light.svg';
	private const BRAND_ICON              = 'assets/brand/icon.svg';
	private const BRAND_ICON_LIGHT        = 'assets/brand/icon-light.svg';

	public static function register(): void {
		if ( ! \is_admin() ) {
			return;
		}

		\add_action( 'admin_menu', array( self::class, 'register_menu_page' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		\add_filter( 'script_loader_tag', array( self::class, 'filter_script_tag' ), 10, 3 );
	}

	public static function register_menu_page(): void {
		$location = self::menu_location();
		if ( 'tools' === $location['placement'] || 'settings' === $location['placement'] ) {
			\add_submenu_page(
				'tools' === $location['placement'] ? 'tools.php' : 'options-general.php',
				'Onumia',
				'Onumia',
				self::CAPABILITY,
				self::PAGE_SLUG,
				array( self::class, 'render_page' ),
				$location['position']
			);
			return;
		}

		\add_menu_page(
			'Onumia',
			'Onumia',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render_page' ),
			'none',
			$location['position']
		);
	}

	public static function render_page(): void {
		if ( ! \current_user_can( self::CAPABILITY ) ) {
			\wp_die( \esc_html( 'You do not have permission to access Onumia.' ) );
		}

		self::render_app_root( null, 'onumia-admin-page', 'Loading Onumia' );
	}

	public static function render_surface_app( string $app_name ): void {
		self::render_app_root( $app_name, 'onumia-admin-page onumia-app-surface-page', 'Loading Onumia app' );
	}

	public static function enqueue_surface_assets(): void {
		self::enqueue_admin_menu_icon();
		self::enqueue_surface_reset();

		$dev_server = self::dev_server_url();
		if ( null !== $dev_server ) {
			self::enqueue_dev_assets( $dev_server );
			return;
		}

		self::enqueue_build_assets();
	}

	private static function render_app_root( ?string $surface_app_name, string $class_name, string $loading_label ): void {
		$dev_server = self::dev_server_url();
		if ( null !== $dev_server ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The preamble escapes the dev-server URL before generating fixed module script markup.
			echo self::build_dev_react_preamble( $dev_server );
		}

		$brand_logo_url = self::asset_url( self::BRAND_LOGO );
		$brand_icon_url = self::asset_url( self::BRAND_ICON );
		$surface_attr   = null === $surface_app_name ? '' : sprintf( ' data-surface-app-name="%s"', \esc_attr( $surface_app_name ) );
		$open_ai_key    = self::can_emit_provider_keys() ? self::open_ai_key() : '';
		$anthropic_key  = self::can_emit_provider_keys() ? self::anthropic_key() : '';
		$google_key     = self::can_emit_provider_keys() ? self::google_key() : '';
		printf(
			'<div class="%1$s"><div class="onumia-app-loader" data-onumia-app-loader role="status" aria-label="%2$s"><img alt="" aria-hidden="true" height="128" src="%3$s" width="128" /></div><div id="onumia-app-root" data-rest-root="%4$s" data-rest-nonce="%5$s" data-version="%6$s" data-brand-logo-url="%7$s" data-brand-logo-light-url="%8$s" data-brand-icon-url="%9$s" data-brand-icon-light-url="%10$s" data-open-ai-key="%11$s" data-anthropic-key="%12$s" data-google-key="%13$s" data-env-role="%14$s" data-current-user-id="%15$d"%16$s></div></div>',
			\esc_attr( $class_name ),
			\esc_attr( $loading_label ),
			\esc_url( $brand_icon_url ),
			\esc_url( \rest_url( 'onumia/v1/' ) ),
			\esc_attr( \wp_create_nonce( 'wp_rest' ) ),
			\esc_attr( self::version() ),
			\esc_url( $brand_logo_url ),
			\esc_url( self::asset_url( self::BRAND_LOGO_LIGHT ) ),
			\esc_url( $brand_icon_url ),
			\esc_url( self::asset_url( self::BRAND_ICON_LIGHT ) ),
			\esc_attr( $open_ai_key ),
			\esc_attr( $anthropic_key ),
			\esc_attr( $google_key ),
			\esc_attr( self::env_role() ),
			(int) \get_current_user_id(),
			$surface_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_attr above.
		);
		\wp_print_inline_script_tag( self::loader_watch_script(), array( 'id' => 'onumia-app-loader-watch' ) );
	}

	private static function can_emit_provider_keys(): bool {
		return function_exists( 'current_user_can' ) && \current_user_can( self::CAPABILITY );
	}

	public static function enqueue_assets( string $hook ): void {
		self::enqueue_admin_menu_icon();

		if ( ! self::is_onumia_hook( $hook ) ) {
			return;
		}

		self::enqueue_admin_reset();

		$dev_server = self::dev_server_url();
		if ( null !== $dev_server ) {
			self::enqueue_dev_assets( $dev_server );
			return;
		}

		self::enqueue_build_assets();
	}

	public static function filter_script_tag( string $tag, string $handle, string $src ): string {
		if ( ! \in_array( $handle, array( self::VITE_HANDLE, self::APP_HANDLE ), true ) ) {
			return $tag;
		}

		return \sprintf(
			"%s\n",
			\wp_get_script_tag(
				array(
					'type'        => 'module',
					'crossorigin' => 'anonymous',
					'src'         => $src,
					'id'          => $handle . '-js',
				)
			)
		);
	}

	private static function enqueue_dev_assets( string $dev_server ): void {
		\wp_register_script( self::VITE_HANDLE, $dev_server . '/@vite/client', array(), null, true );
		\wp_script_add_data( self::VITE_HANDLE, 'type', 'module' );
		\wp_enqueue_script( self::VITE_HANDLE );

		\wp_register_script( self::APP_HANDLE, $dev_server . '/' . self::entrypoint(), array(), null, true );
		\wp_script_add_data( self::APP_HANDLE, 'type', 'module' );
		\wp_enqueue_script( self::APP_HANDLE );
	}

	private static function enqueue_admin_menu_icon(): void {
		\wp_register_style( self::ADMIN_MENU_STYLE_HANDLE, false, array(), self::version() );
		\wp_enqueue_style( self::ADMIN_MENU_STYLE_HANDLE );
		\wp_add_inline_style( self::ADMIN_MENU_STYLE_HANDLE, self::admin_menu_icon_css() );
	}

	private static function enqueue_admin_reset(): void {
		\wp_register_style( self::RESET_STYLE_HANDLE, false, array(), self::version() );
		\wp_enqueue_style( self::RESET_STYLE_HANDLE );
		\wp_add_inline_style( self::RESET_STYLE_HANDLE, self::admin_reset_css() );
	}

	private static function enqueue_surface_reset(): void {
		\wp_register_style( self::SURFACE_STYLE_HANDLE, false, array(), self::version() );
		\wp_enqueue_style( self::SURFACE_STYLE_HANDLE );
		\wp_add_inline_style( self::SURFACE_STYLE_HANDLE, self::surface_reset_css() );
	}

	private static function admin_menu_icon_css(): string {
		$icon_url = \esc_url( self::asset_url( self::BRAND_ICON ) );

		return <<<CSS
#adminmenu #toplevel_page_onumia div.wp-menu-image:before {
	background-color: currentColor;
	content: "";
	display: block;
	height: 20px;
	margin: 6px auto 0;
	padding: 0;
	width: 20px;
	-webkit-mask: url("{$icon_url}") center / contain no-repeat;
	mask: url("{$icon_url}") center / contain no-repeat;
}
CSS;
	}

	private static function surface_reset_css(): string {
		return <<<'CSS'
html {
	overscroll-behavior: none;
}

body.onumia-app-surface-active {
	background: oklch(0.97 0 0);
	scrollbar-color: oklch(0.21 0 0 / 14%) transparent;
	scrollbar-width: thin;
}

body.onumia-app-surface-active::-webkit-scrollbar {
	background: transparent;
	height: 7px;
	width: 7px;
}

body.onumia-app-surface-active::-webkit-scrollbar-track {
	background: transparent;
}

body.onumia-app-surface-active::-webkit-scrollbar-thumb {
	background: oklch(0.21 0 0 / 14%);
	border-radius: 999px;
}

body.onumia-app-surface-active::-webkit-scrollbar-thumb:hover {
	background: oklch(0.21 0 0 / 22%);
}

body.onumia-app-surface-active #wpcontent {
	padding-left: 0;
}

body.onumia-app-surface-active #wpfooter,
body.onumia-app-surface-active #screen-meta-links,
body.onumia-app-surface-active #message,
body.onumia-app-surface-active.index-php .wrap > h1,
body.onumia-app-surface-active.index-php #welcome-panel,
body.onumia-app-surface-active.index-php #dashboard-widgets-wrap,
body.onumia-app-surface-active .notice,
body.onumia-app-surface-active .updated,
body.onumia-app-surface-active .error,
body.onumia-app-surface-active .update-nag {
	display: none;
}

body.onumia-app-surface-active .wrap {
	margin: 0;
}

body.onumia-app-surface-active .onumia-app-surface-page {
	margin: 0;
	min-height: calc(100vh - var(--wp-admin--admin-bar--height, 32px));
	position: relative;
}

body.onumia-app-surface-active #onumia-app-root {
	min-height: calc(100vh - var(--wp-admin--admin-bar--height, 32px));
	position: relative;
	z-index: 2;
}

body.onumia-app-surface-active .onumia-app-loader {
	align-items: center;
	display: flex;
	inset: 0;
	justify-content: center;
	min-height: calc(100vh - var(--wp-admin--admin-bar--height, 32px));
	pointer-events: none;
	position: absolute;
	z-index: 1;
}

body.onumia-app-surface-active .onumia-app-loader[hidden] {
	display: none;
}

body.onumia-app-surface-active .onumia-app-loader img {
	animation: onumia-loader-pulse 1.6s ease-in-out infinite;
	filter: grayscale(1);
	height: 32px;
	max-width: min(220px, calc(100vw - 64px));
	opacity: 0.44;
	width: auto;
}
CSS;
	}

	private static function admin_reset_css(): string {
		return <<<'CSS'
html {
	overscroll-behavior: none;
}

body.toplevel_page_onumia,
body.tools_page_onumia,
body.settings_page_onumia {
	background: oklch(0.97 0 0);
	scrollbar-color: oklch(0.21 0 0 / 14%) transparent;
	scrollbar-width: thin;
}

body.toplevel_page_onumia::-webkit-scrollbar,
body.tools_page_onumia::-webkit-scrollbar,
body.settings_page_onumia::-webkit-scrollbar {
	background: transparent;
	height: 7px;
	width: 7px;
}

body.toplevel_page_onumia::-webkit-scrollbar-track,
body.tools_page_onumia::-webkit-scrollbar-track,
body.settings_page_onumia::-webkit-scrollbar-track {
	background: transparent;
}

body.toplevel_page_onumia::-webkit-scrollbar-thumb,
body.tools_page_onumia::-webkit-scrollbar-thumb,
body.settings_page_onumia::-webkit-scrollbar-thumb {
	background: oklch(0.21 0 0 / 14%);
	border-radius: 999px;
}

body.toplevel_page_onumia::-webkit-scrollbar-thumb:hover,
body.tools_page_onumia::-webkit-scrollbar-thumb:hover,
body.settings_page_onumia::-webkit-scrollbar-thumb:hover {
	background: oklch(0.21 0 0 / 22%);
}

body.toplevel_page_onumia #wpcontent,
body.tools_page_onumia #wpcontent,
body.settings_page_onumia #wpcontent {
	padding-left: 0;
}

body.toplevel_page_onumia #wpfooter,
body.tools_page_onumia #wpfooter,
body.settings_page_onumia #wpfooter,
body.toplevel_page_onumia .notice,
body.tools_page_onumia .notice,
body.settings_page_onumia .notice,
body.toplevel_page_onumia .updated,
body.tools_page_onumia .updated,
body.settings_page_onumia .updated,
body.toplevel_page_onumia .error,
body.tools_page_onumia .error,
body.settings_page_onumia .error {
	display: none;
}

body.toplevel_page_onumia .onumia-admin-page,
body.tools_page_onumia .onumia-admin-page,
body.settings_page_onumia .onumia-admin-page {
	margin: 0;
	min-height: calc(100vh - var(--wp-admin--admin-bar--height, 32px));
	position: relative;
}

body.toplevel_page_onumia #onumia-app-root,
body.tools_page_onumia #onumia-app-root,
body.settings_page_onumia #onumia-app-root {
	min-height: calc(100vh - var(--wp-admin--admin-bar--height, 32px));
	position: relative;
	z-index: 2;
}

body.toplevel_page_onumia .onumia-app-loader,
body.tools_page_onumia .onumia-app-loader,
body.settings_page_onumia .onumia-app-loader {
	align-items: center;
	display: flex;
	inset: 0;
	justify-content: center;
	min-height: calc(100vh - var(--wp-admin--admin-bar--height, 32px));
	pointer-events: none;
	position: absolute;
	z-index: 1;
}

body.toplevel_page_onumia .onumia-app-loader[hidden],
body.tools_page_onumia .onumia-app-loader[hidden],
body.settings_page_onumia .onumia-app-loader[hidden] {
	display: none;
}

body.toplevel_page_onumia .onumia-app-loader img,
body.tools_page_onumia .onumia-app-loader img,
body.settings_page_onumia .onumia-app-loader img {
	animation: onumia-loader-pulse 1.6s ease-in-out infinite;
	filter: grayscale(1);
	height: 32px;
	max-width: min(220px, calc(100vw - 64px));
	opacity: 0.44;
	width: auto;
}

@keyframes onumia-loader-pulse {
	0%,
	100% {
		opacity: 0.34;
	}

	50% {
		opacity: 0.72;
	}
}
CSS;
	}

	private static function loader_watch_script(): string {
		return <<<'JS'
(() => {
	const root = document.getElementById("onumia-app-root");
	const loader = document.querySelector("[data-onumia-app-loader]");

	if (!root || !loader) {
		return;
	}

	const sync = () => {
		loader.hidden = root.childElementCount > 0;
	};

	sync();

	if (typeof MutationObserver !== "undefined") {
		new MutationObserver(sync).observe(root, { childList: true });
	}
})();
JS;
	}

	private static function build_dev_react_preamble( string $dev_server ): string {
		$refresh_url = \esc_url( $dev_server . '/@react-refresh' );

		return <<<HTML
<script type="module">
import RefreshRuntime from "{$refresh_url}";

RefreshRuntime.injectIntoGlobalHook(window);
window.\$RefreshReg\$ = () => {};
window.\$RefreshSig\$ = () => (type) => type;
window.__vite_plugin_react_preamble_installed__ = true;
</script>
HTML;
	}

	private static function enqueue_build_assets(): void {
		$manifest  = self::read_manifest();
		$entry     = $manifest[ self::entrypoint() ] ?? null;
		$asset_url = self::asset_base_url();

		if ( ! \is_array( $entry ) || '' === $asset_url ) {
			return;
		}

		$base = \trailingslashit( $asset_url );
		$css  = $entry['css'] ?? array();
		if ( \is_array( $css ) ) {
			foreach ( $css as $index => $css_file ) {
				if ( \is_string( $css_file ) && '' !== $css_file ) {
					\wp_enqueue_style(
						self::APP_HANDLE . '-style-' . (string) $index,
						$base . \ltrim( $css_file, '/' ),
						array(),
						self::build_asset_version( $css_file )
					);
				}
			}
		}

		$file = $entry['file'] ?? '';
		if ( \is_string( $file ) && '' !== $file ) {
			\wp_register_script(
				self::APP_HANDLE,
				$base . \ltrim( $file, '/' ),
				array(),
				self::build_asset_version( $file ),
				true
			);
			\wp_script_add_data( self::APP_HANDLE, 'type', 'module' );
			\wp_enqueue_script( self::APP_HANDLE );
		}
	}

	private static function build_asset_version( string $file ): ?string {
		$path  = \dirname( __DIR__, 2 ) . '/' . self::asset_directory() . '/' . \ltrim( $file, '/\\' );
		$mtime = \is_file( $path ) ? \filemtime( $path ) : false;

		if ( \is_int( $mtime ) ) {
			return (string) $mtime;
		}

		$version = self::version();

		return '' === $version ? null : $version;
	}

	private static function dev_server_url(): ?string {
		$dev_server = self::configured_dev_server_url();
		if ( null === $dev_server ) {
			return null;
		}

		if ( 'dev' === self::env_role() ) {
			return $dev_server;
		}

		return self::dev_server_available_override() ? $dev_server : null;
	}

	private static function configured_dev_server_url(): ?string {
		if ( \defined( 'ONUMIA_UI_APP_DEV_SERVER' ) ) {
			$value = \constant( 'ONUMIA_UI_APP_DEV_SERVER' );
			if ( \is_string( $value ) && '' !== \trim( $value ) ) {
				return \rtrim( \trim( $value ), '/' );
			}
		}

		$value = \getenv( 'ONUMIA_UI_APP_DEV_SERVER' );
		$value = false === $value ? '' : \trim( (string) $value );

		return '' === $value ? null : \rtrim( $value, '/' );
	}

	private static function dev_server_available_override(): bool {
		if ( \defined( 'ONUMIA_UI_APP_DEV_SERVER_AVAILABLE' ) ) {
			$value = \constant( 'ONUMIA_UI_APP_DEV_SERVER_AVAILABLE' );
			if ( \is_bool( $value ) ) {
				return $value;
			}

			if ( \is_string( $value ) ) {
				return \in_array( \strtolower( \trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
			}
		}

		$value      = \getenv( 'ONUMIA_UI_APP_DEV_SERVER_AVAILABLE' );
		$normalized = false === $value ? '' : \strtolower( \trim( (string) $value ) );

		return \in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true );
	}

	private static function env_role(): string {
		if ( \defined( 'ONUMIA_ENV_ROLE' ) ) {
			$value = \constant( 'ONUMIA_ENV_ROLE' );
			return \is_string( $value ) ? \trim( $value ) : '';
		}

		$value = \getenv( 'ONUMIA_ENV_ROLE' );

		return false === $value ? '' : \trim( (string) $value );
	}

	private static function version(): string {
		$value = \defined( 'ONUMIA_VERSION' ) ? \constant( 'ONUMIA_VERSION' ) : '';

		return \is_string( $value ) ? $value : '';
	}

	private static function open_ai_key(): string {
		return self::env_value( array( 'OPEN_AI_KEY', 'OPENAI_API_KEY' ) );
	}

	private static function anthropic_key(): string {
		return self::env_value( array( 'ANTHROPIC_API_KEY', 'ANTHROPIC_KEY' ) );
	}

	private static function google_key(): string {
		return self::env_value( array( 'GOOGLE_GENERATIVE_AI_API_KEY', 'GOOGLE_API_KEY', 'GOOGLE_KEY', 'GEMINI_API_KEY' ) );
	}

	/**
	 * @param list<string> $keys
	 */
	private static function env_value( array $keys ): string {
		$env = self::read_env_file();

		foreach ( $keys as $key ) {
			$value = $env[ $key ] ?? null;
			if ( \is_string( $value ) && '' !== \trim( $value ) ) {
				return \trim( $value );
			}
		}

		return '';
	}

	/**
	 * @return array<string,string>
	 */
	private static function read_env_file(): array {
		$file = \dirname( self::plugin_file() ) . '/.env';
		if ( ! \is_file( $file ) ) {
			return array();
		}

		$lines = @\file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file
		if ( ! \is_array( $lines ) ) {
			return array();
		}

		$env = array();
		foreach ( $lines as $line ) {
			$line = \trim( $line );
			if ( '' === $line || \str_starts_with( $line, '#' ) || ! \str_contains( $line, '=' ) ) {
				continue;
			}

			$parts = \explode( '=', $line, 2 );
			$key   = \trim( $parts[0] );
			$value = \trim( $parts[1], " \t\n\r\0\x0B\"'" );
			if ( '' !== $key ) {
				$env[ $key ] = $value;
			}
		}

		return $env;
	}

	private static function is_onumia_hook( string $hook ): bool {
		return \in_array( $hook, self::PAGE_HOOKS, true );
	}

	/**
	 * @return array{placement:string,position:int}
	 */
	private static function menu_location(): array {
		$default  = array(
			'placement' => 'top-level',
			'position'  => 99,
		);
		$filtered = Filters::admin_menu_location( $default );

		$placement = $filtered['placement'] ?? $default['placement'];
		$position  = $filtered['position'] ?? $default['position'];
		if ( ! \is_string( $placement ) || ! \in_array( $placement, array( 'top-level', 'tools', 'settings' ), true ) ) {
			$placement = $default['placement'];
		}

		if ( ! \is_int( $position ) ) {
			$position = $default['position'];
		}

		return array(
			'placement' => $placement,
			'position'  => max( 1, min( 999, $position ) ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function read_manifest(): array {
		if ( ! \is_readable( self::manifest_path() ) ) {
			return array();
		}

		$contents = \file_get_contents( self::manifest_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data     = \is_string( $contents ) && '' !== $contents ? \json_decode( $contents, true ) : array();
		if ( ! \is_array( $data ) ) {
			return array();
		}

		$result = array();
		foreach ( $data as $key => $item ) {
			if ( \is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}

		return $result;
	}

	private static function manifest_path(): string {
		return \dirname( __DIR__, 2 ) . '/' . self::asset_directory() . '/manifest.json';
	}

	private static function asset_base_url(): string {
		return \trailingslashit( \plugins_url( self::asset_directory(), self::plugin_file() ) );
	}

	private static function asset_url( string $path ): string {
		return \plugins_url( \ltrim( $path, '/' ), self::plugin_file() );
	}

	private static function plugin_file(): string {
		$value = \defined( 'ONUMIA_PLUGIN_FILE' ) ? \constant( 'ONUMIA_PLUGIN_FILE' ) : \dirname( __DIR__, 2 ) . '/onumia.php';

		return \is_string( $value ) ? $value : \dirname( __DIR__, 2 ) . '/onumia.php';
	}

	private static function entrypoint(): string {
		$entrypoint = self::ENTRYPOINT;
		$filtered   = Filters::app_entrypoint( $entrypoint );
		if ( '' !== \trim( $filtered ) ) {
			$entrypoint = \trim( $filtered );
		}

		return $entrypoint;
	}

	private static function asset_directory(): string {
		$directory = self::ASSET_DIRECTORY;
		$filtered  = Filters::app_asset_directory( $directory );
		if ( '' !== \trim( $filtered ) ) {
			$directory = \trim( $filtered );
		}

		return \trim( $directory, '/\\' );
	}
}
