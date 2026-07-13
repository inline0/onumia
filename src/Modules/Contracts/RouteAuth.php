<?php

/**
 * Public module route authentication mode.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Contracts;

/**
 * Defines authentication modes for public module routes.
 *
 * Use this enum in `PublicRoute` declarations to make a route's access model
 * explicit. It supports unauthenticated routes, WordPress-user routes, and
 * signed or token-based integrations such as licenses and webhooks.
 *
 * The route dispatcher enforces the selected mode. Module methods should still
 * validate resource-specific permissions and payload semantics.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 370
 */
enum RouteAuth: string {
	case None             = 'none';
	case LicenseKey       = 'license_key';
	case DownloadToken    = 'download_token';
	case WebhookSignature = 'webhook_signature';
	case Signature        = 'signature';
	case WordPressUser    = 'wordpress_user';
}
