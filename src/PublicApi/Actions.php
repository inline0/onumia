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
}
