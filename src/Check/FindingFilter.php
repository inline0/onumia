<?php

/**
 * Filters check findings by severity.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Check;

final class FindingFilter {
	/**
	 * @param  iterable<array-key,Finding> $findings Findings.
	 * @return list<Finding>
	 */
	public static function errors( iterable $findings ): array {
		$errors = array();
		foreach ( $findings as $finding ) {
			if ( 'error' === $finding->severity ) {
				$errors[] = $finding;
			}
		}

		return $errors;
	}

	/**
	 * @param  iterable<array-key,array{message:string,identifier:string,file:string,line:int,severity:string}> $findings Findings.
	 * @return list<array{message:string,identifier:string,file:string,line:int,severity:string}>
	 */
	public static function response_errors( iterable $findings ): array {
		$errors = array();
		foreach ( $findings as $finding ) {
			if ( 'error' === $finding['severity'] ) {
				$errors[] = $finding;
			}
		}

		return $errors;
	}
}
