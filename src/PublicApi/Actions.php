<?php

/**
 * Documented Onumia public actions.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\PublicApi;

use Onumia\Core\Plugin;

/**
 * Routes supported Onumia action extension points through documented methods.
 *
 * Use this class when external code needs a stable action callback point in
 * the Onumia lifecycle. Production code calls these wrappers so the hook name,
 * parameters, and documentation stay in one place.
 *
 * The wrappers do not perform runtime work beyond firing the corresponding
 * WordPress action.
 */
final class Actions {
	/**
	 * Fires after the Onumia plugin runtime has booted.
	 *
	 * This action runs after modules, REST routes, tables, jobs, privacy hooks,
	 * and the admin app have been registered. Use it for follow-up integration
	 * work that needs access to the active plugin instance.
	 *
	 * Callbacks should not attempt to veto plugin boot at this point. Expensive
	 * work should be deferred to a later WordPress hook or background process.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/runtime/loaded
	 * @category Runtime
	 * @order 10
	 *
	 * @param Plugin $plugin Active Onumia plugin runtime.
	 */
	public static function loaded( Plugin $plugin ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		\do_action( 'onumia/runtime/loaded', $plugin );
	}

	/**
	 * Fires after the optional Onumia Pro runtime has booted.
	 *
	 * This action runs after Pro app discovery, app surface registration, Pro
	 * REST routes, and administrator capabilities have been registered. Use it
	 * for Pro-only integrations that need the active plugin runtime.
	 *
	 * Callbacks should not assume the free module boot can still be changed at
	 * this point. Register Pro module roots during pre-boot filters instead.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/pro/loaded
	 * @category Pro
	 * @order 10
	 *
	 * @param Plugin $plugin Active Onumia plugin runtime.
	 */
	public static function pro_loaded( Plugin $plugin ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		\do_action( 'onumia/pro/loaded', $plugin );
	}

	/**
	 * Fires after a software licensing purchase has been recorded.
	 *
	 * Storefronts can use this to send branded purchase emails or sync
	 * account state without reading Onumia licensing tables directly.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/licensing/purchase_recorded
	 * @category Licensing
	 *
	 * @param array<string,mixed> $purchase Public purchase record.
	 */
	public static function licensing_purchase_recorded( array $purchase ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		\do_action( 'onumia/licensing/purchase_recorded', $purchase );
	}

	/**
	 * Fires after a software license has been issued.
	 *
	 * The raw key is exposed only through this action and is never persisted by
	 * Onumia. Handlers that email or display the key should avoid logging it.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/licensing/license_issued
	 * @category Licensing
	 *
	 * @param array<string,mixed> $license     Public license record.
	 * @param string              $license_key Raw license key for immediate delivery.
	 * @param array<string,mixed> $purchase    Public purchase record.
	 */
	public static function licensing_license_issued( array $license, string $license_key, array $purchase ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		\do_action( 'onumia/licensing/license_issued', $license, $license_key, $purchase );
	}

	/**
	 * Fires after a software license changes status.
	 *
	 * @api
	 * @since 0.1.0
	 * @hook onumia/licensing/license_status_changed
	 * @category Licensing
	 *
	 * @param array<string,mixed> $license         Public license record after the change.
	 * @param string              $previous_status Previous license status.
	 * @param string              $new_status      New license status.
	 */
	public static function licensing_license_status_changed( array $license, string $previous_status, string $new_status ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Onumia public hooks intentionally use slash-style names.
		\do_action( 'onumia/licensing/license_status_changed', $license, $previous_status, $new_status );
	}
}
