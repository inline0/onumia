<?php

/**
 * Module callable surfaces.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Contracts;

/**
 * Defines runtime surfaces for module callables.
 *
 * Use this enum in `Action` and `DataSource` declarations to indicate whether
 * a callable belongs to backend, admin, or frontend-facing usage. Surface
 * metadata helps Onumia keep admin-only and public behavior separated.
 *
 * Current bundled modules are backend/admin oriented. Frontend surfaces should
 * only be used when a dedicated public rendering contract exists.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 390
 */
enum Surface: string {
	case Backend  = 'backend';
	case Admin    = 'admin';
	case Frontend = 'frontend';
}
