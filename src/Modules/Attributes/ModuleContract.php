<?php

/**
 * Module contract attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;

/**
 * Marks a class as a Onumia module contract.
 *
 * Use this class attribute on the single module class defined in `boot.php`.
 * It establishes the default capability, default enabled state, and optional
 * feature flag for all parsed settings, actions, data sources, routes, and jobs.
 *
 * The loader expects exactly one module contract class per module boot file.
 * This attribute is parsed before module code is instantiated.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 190
 */
#[Attribute( Attribute::TARGET_CLASS )]
final readonly class ModuleContract {
	public function __construct(
		public bool $default_enabled = false,
		public string $capability = 'manage_options',
		public ?string $feature_flag = null,
	) {}
}
