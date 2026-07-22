<?php

/**
 * WP-CLI production preflight command.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Cli;

use Onumia\Core\Plugin;
use Onumia\Support\PreflightDoctor;

final class DoctorCommand {
	public function __construct(
		private readonly Plugin $plugin,
	) {}

	/**
	 * @param string[]             $args       Positional args.
	 * @param array<string,string> $assoc_args Assoc args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$report = ( new PreflightDoctor( $this->plugin ) )->report();
		if ( 'text' === ( $assoc_args['format'] ?? '' ) ) {
			$this->text( $report );
		} else {
			\WP_CLI::line( (string) json_encode( $report, JSON_UNESCAPED_SLASHES ) );
		}

		if ( 'critical' === ( $report['status'] ?? '' ) ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * @param array<string,mixed> $report Report.
	 */
	private function text( array $report ): void {
		\WP_CLI::line( 'status: ' . ( is_scalar( $report['status'] ?? null ) ? (string) $report['status'] : 'unknown' ) );
		$checks = $report['checks'] ?? array();
		if ( ! is_array( $checks ) ) {
			return;
		}

		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$name    = is_scalar( $check['name'] ?? null ) ? (string) $check['name'] : 'check';
			$status  = is_scalar( $check['status'] ?? null ) ? (string) $check['status'] : 'unknown';
			$message = is_scalar( $check['message'] ?? null ) ? (string) $check['message'] : '';
			\WP_CLI::line( "{$status}: {$name} - {$message}" );
		}
	}
}
