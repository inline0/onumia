<?php

/**
 * Onumia admin app page.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Admin;

use Onumia\Dev\UiLabAccess;
use Onumia\PublicApi\Filters;
use Onumia\Rest\UiStateRoutes;

final class AppPage {
	private const CAPABILITY              = 'manage_options';
	private const PAGE_SLUG               = 'onumia';
	private const PAGE_HOOKS              = array( 'toplevel_page_onumia', 'tools_page_onumia', 'settings_page_onumia' );
	private const VITE_HANDLE             = 'onumia-app-vite-client';
	private const APP_HANDLE              = 'onumia-app';
	private const ADMIN_MENU_STYLE_HANDLE = 'onumia-admin-menu';
	private const RESET_STYLE_HANDLE      = 'onumia-admin-reset';
	private const FULLSCREEN_STYLE_HANDLE = 'onumia-app-fullscreen-shell';
	private const ENTRYPOINT              = 'src/apps/onumia/main.tsx';
	private const ASSET_DIRECTORY         = 'assets/app';
	private const BRAND_ICON              = 'assets/brand/icon.svg';

	public static function register(): void {
		if ( ! \is_admin() ) {
			return;
		}

		\add_action( 'admin_menu', array( self::class, 'register_menu_page' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'strip_admin_styles' ), 1000 );
		\add_action( 'admin_print_styles', array( self::class, 'strip_admin_styles' ), 1000 );
		\add_filter( 'admin_body_class', array( self::class, 'filter_body_class' ) );
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

		self::render_app_root();
	}

	private static function render_app_root(): void {
		$dev_server = self::dev_server_url();
		if ( null !== $dev_server ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The preamble escapes the dev-server URL before generating fixed module script markup.
			echo self::build_dev_react_preamble( $dev_server );
		}

		$open_ai_key   = self::can_emit_provider_keys() ? self::open_ai_key() : '';
		$anthropic_key = self::can_emit_provider_keys() ? self::anthropic_key() : '';
		$google_key    = self::can_emit_provider_keys() ? self::google_key() : '';
		$development_mode = UiLabAccess::enabled_for_current_request() ? '1' : '0';
		printf(
			'<div id="onumia-app-root" data-rest-root="%1$s" data-rest-nonce="%2$s" data-version="%3$s" data-open-ai-key="%4$s" data-anthropic-key="%5$s" data-google-key="%6$s" data-env-role="%7$s" data-current-user-id="%8$d" data-development-mode="%9$s"></div>',
			\esc_url( \rest_url( 'onumia/v1/' ) ),
			\esc_attr( \wp_create_nonce( 'wp_rest' ) ),
			\esc_attr( self::version() ),
			\esc_attr( $open_ai_key ),
			\esc_attr( $anthropic_key ),
			\esc_attr( $google_key ),
			\esc_attr( self::env_role() ),
			(int) \get_current_user_id(),
			\esc_attr( $development_mode )
		);
	}

	private static function can_emit_provider_keys(): bool {
		return function_exists( 'current_user_can' ) && \current_user_can( self::CAPABILITY );
	}

	public static function enqueue_assets( string $hook ): void {
		self::enqueue_admin_menu_icon();

		if ( ! self::is_onumia_hook( $hook ) ) {
			return;
		}

		if ( self::is_fullscreen() ) {
			self::enqueue_fullscreen_shell();
		} else {
			self::enqueue_admin_reset();
		}

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

	public static function filter_body_class( string $classes ): string {
		if ( ! self::is_fullscreen() || ! self::is_current_onumia_screen() ) {
			return $classes;
		}

		$theme_mode = UiStateRoutes::current_state()['theme_mode'];

		return \trim( $classes . ' onumia-app-fullscreen onumia-theme-' . $theme_mode );
	}

	public static function strip_admin_styles( ?string $hook = null ): void {
		if ( ! self::is_fullscreen() ) {
			return;
		}

		if ( null !== $hook ? ! self::is_onumia_hook( $hook ) : ! self::is_current_onumia_screen() ) {
			return;
		}

		$wp_styles = $GLOBALS['wp_styles'] ?? null;
		$queue     = \is_object( $wp_styles ) && \is_array( $wp_styles->queue ?? null ) ? $wp_styles->queue : array();
		foreach ( $queue as $handle ) {
			if ( ! \is_string( $handle ) || self::should_keep_fullscreen_style( $handle ) ) {
				continue;
			}

			\wp_dequeue_style( $handle );
		}
	}

	private static function enqueue_dev_assets( string $dev_server ): void {
		\wp_register_script( self::VITE_HANDLE, $dev_server . '/@vite/client', array(), null, true );
		\wp_script_add_data( self::VITE_HANDLE, 'type', 'module' );
		\wp_enqueue_script( self::VITE_HANDLE );

		\wp_register_script( self::APP_HANDLE, $dev_server . '/' . self::ENTRYPOINT, array(), null, true );
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

	private static function enqueue_fullscreen_shell(): void {
		\wp_register_style( self::FULLSCREEN_STYLE_HANDLE, false, array(), self::version() );
		\wp_enqueue_style( self::FULLSCREEN_STYLE_HANDLE );
		\wp_add_inline_style( self::FULLSCREEN_STYLE_HANDLE, self::fullscreen_shell_css() );
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

	private static function fullscreen_shell_css(): string {
		return <<<'CSS'
html {
	padding: 0 !important;
}

body.onumia-app-fullscreen {
	--onumia-main-background: oklch(1 0 0);
	position: fixed;
	width: 100%;
	height: 100%;
}

body.onumia-app-fullscreen.onumia-theme-dark {
	--onumia-main-background: oklch(0.17 0 0);
}

@media (prefers-color-scheme: dark) {
	body.onumia-app-fullscreen.onumia-theme-system {
		--onumia-main-background: oklch(0.17 0 0);
	}
}

body.onumia-app-fullscreen.dark {
	--onumia-main-background: oklch(0.17 0 0);
}

body.onumia-app-fullscreen.light {
	--onumia-main-background: oklch(1 0 0);
}

body.onumia-app-fullscreen #wpadminbar,
body.onumia-app-fullscreen #adminmenumain,
body.onumia-app-fullscreen #screen-meta,
body.onumia-app-fullscreen #screen-meta-links,
body.onumia-app-fullscreen #wpfooter,
body.onumia-app-fullscreen .notice,
body.onumia-app-fullscreen .update-nag {
	display: none !important;
}

body.onumia-app-fullscreen,
body.onumia-app-fullscreen #wpwrap,
body.onumia-app-fullscreen #wpcontent,
body.onumia-app-fullscreen #wpbody,
body.onumia-app-fullscreen #wpbody-content {
	background: var(--onumia-main-background);
	margin: 0 !important;
	min-height: 100vh;
	padding: 0 !important;
}

body.onumia-app-fullscreen #wpcontent,
body.onumia-app-fullscreen #wpbody-content {
	margin-left: 0 !important;
}

body.onumia-app-fullscreen #onumia-app-root {
	min-height: 100vh;
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
		$entry     = $manifest[ self::ENTRYPOINT ] ?? null;
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
		$path  = \dirname( __DIR__, 2 ) . '/' . self::ASSET_DIRECTORY . '/' . \ltrim( $file, '/\\' );
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

	private static function is_current_onumia_screen(): bool {
		$screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;
		$id     = null !== $screen ? $screen->id : '';

		return self::is_onumia_hook( $id );
	}

	private static function is_fullscreen(): bool {
		return Filters::app_fullscreen( true );
	}

	private static function should_keep_fullscreen_style( string $handle ): bool {
		return self::FULLSCREEN_STYLE_HANDLE === $handle || \str_starts_with( $handle, self::APP_HANDLE . '-style-' );
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
		return \dirname( __DIR__, 2 ) . '/' . self::ASSET_DIRECTORY . '/manifest.json';
	}

	private static function asset_base_url(): string {
		return \trailingslashit( \plugins_url( self::ASSET_DIRECTORY, self::plugin_file() ) );
	}

	private static function asset_url( string $path ): string {
		return \plugins_url( \ltrim( $path, '/' ), self::plugin_file() );
	}

	private static function plugin_file(): string {
		$value = \defined( 'ONUMIA_PLUGIN_FILE' ) ? \constant( 'ONUMIA_PLUGIN_FILE' ) : \dirname( __DIR__, 2 ) . '/onumia.php';

		return \is_string( $value ) ? $value : \dirname( __DIR__, 2 ) . '/onumia.php';
	}
}
