<?php

/**
 * Module check reporter.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Check;

final class Reporter {
	/**
	 * @param Finding[] $findings Findings.
	 */
	public function plain( array $findings ): string {
		if ( array() === $findings ) {
			return '';
		}

		$lines = array();
		foreach ( $findings as $finding ) {
			$lines[] = sprintf(
				'%s:%d: %s [%s]',
				$finding->file,
				$finding->line,
				$finding->message,
				$finding->identifier
			);
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param Finding[] $findings Findings.
	 */
	public function json( array $findings ): string {
		return json_encode(
			array_map(
				static fn( Finding $finding ): array => array(
					'message'    => $finding->message,
					'identifier' => $finding->identifier,
					'file'       => $finding->file,
					'line'       => $finding->line,
					'severity'   => $finding->severity,
				),
				$findings
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n";
	}
}
