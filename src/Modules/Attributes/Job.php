<?php

/**
 * Module job attribute.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Attributes;

use Attribute;
use Onumia\Modules\Contracts\JobSchedule;

/**
 * Declares a scheduled module job.
 *
 * Use this attribute on module methods that should run through WordPress cron
 * on a Onumia-managed schedule. Jobs are useful for cleanup, polling, sync,
 * pruning, or other recurring backend maintenance.
 *
 * The job registrar reads this metadata when the module is active. The method
 * should be idempotent because cron timing is not guaranteed by WordPress.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 180
 */
#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final readonly class Job {
	public function __construct(
		public ?string $name = null,
		public JobSchedule|string $schedule = JobSchedule::Daily,
		public bool $run_on_activation = false,
		public bool $enabled = true,
	) {}
}
