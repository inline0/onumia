<?php

/**
 * Module public REST route attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;
use Onumia\Modules\Contracts\HttpMethod;
use Onumia\Modules\Contracts\RouteAuth;

/**
 * Declares a public REST route owned by a module.
 *
 * Use this attribute on module methods that need a public HTTP endpoint, such
 * as license checks, download links, webhooks, or signed callbacks. The method
 * receives validated input through the module route dispatcher.
 *
 * Public routes must declare their authentication mode and rate limit. They
 * should not rely on the admin app nonce unless `WordPressUser` auth is used.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 210
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class PublicRoute {
	public function __construct(
		public string $path,
		public HttpMethod|string $method = HttpMethod::Post,
		public RouteAuth|string $auth = RouteAuth::None,
		public int|string $rate_limit = 60,
	) {}
}
