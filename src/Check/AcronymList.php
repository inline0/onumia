<?php

/**
 * Canonical UI acronym allowlist.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Check;

final class AcronymList {
	/**
	 * @var string[]
	 */
	public const WORDS = array(
		'WP',
		'HTML',
		'CSS',
		'JS',
		'JSON',
		'XML',
		'URL',
		'URI',
		'MIME',
		'SEO',
		'SSL',
		'TLS',
		'SMTP',
		'IP',
		'ID',
		'API',
		'REST',
		'MCP',
		'WAI',
		'UI',
		'UX',
		'CDN',
		'CPT',
		'SVG',
	);

	public static function canonical( string $word ): ?string {
		$upper = strtoupper( $word );
		foreach ( self::WORDS as $acronym ) {
			if ( $upper === $acronym ) {
				return $acronym;
			}
		}

		if ( str_ends_with( $upper, 'S' ) ) {
			$singular = substr( $upper, 0, -1 );
			foreach ( self::WORDS as $acronym ) {
				if ( $singular === $acronym ) {
					return "{$acronym}s";
				}
			}
		}

		return null;
	}
}
