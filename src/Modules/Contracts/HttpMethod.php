<?php

/**
 * Public module route HTTP method.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Contracts;

/**
 * Defines HTTP methods supported by public module routes.
 *
 * Use this enum in `PublicRoute` declarations to describe how external clients
 * call a module-owned REST endpoint. The method becomes part of the registered
 * WordPress REST route contract.
 *
 * Route methods are parsed statically and should match the semantics of the
 * callable method. Mutating routes should not be declared as `Get`.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 340
 */
enum HttpMethod: string {
	case Get    = 'GET';
	case Post   = 'POST';
	case Put    = 'PUT';
	case Patch  = 'PATCH';
	case Delete = 'DELETE';
}
