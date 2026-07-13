<?php

/**
 * Module job schedule.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Contracts;

/**
 * Defines schedules for module jobs.
 *
 * Use this enum in `Job` declarations to select the WordPress cron interval
 * Onumia should use for recurring module work. The values map to supported
 * schedules known by the job registrar.
 *
 * WordPress cron is traffic-driven and not guaranteed to run at exact wall
 * clock times. Job methods should be safe to retry.
 *
 * @api
 * @since 0.1.0
 * @category Module Schema
 * @order 350
 */
enum JobSchedule: string {
	case FiveMinutes = 'five_minutes';
	case Hourly      = 'hourly';
	case TwiceDaily  = 'twice_daily';
	case Daily       = 'daily';
	case Weekly      = 'weekly';
}
