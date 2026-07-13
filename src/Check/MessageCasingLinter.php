<?php

/**
 * Enforces Onumia UI message casing.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Check;

final class MessageCasingLinter {
	/**
	 * @return Finding[]
	 */
	public function lint_ui_label( string $message, string $reference, string $file, string $path ): array {
		return $this->lint_text( $message, $reference, $file, $path, false );
	}

	/**
	 * @return Finding[]
	 */
	public function lint_help_text( string $message, string $reference, string $file, string $path ): array {
		return $this->lint_text( $message, $reference, $file, $path, true );
	}

	/**
	 * @return Finding[]
	 */
	private function lint_text( string $message, string $reference, string $file, string $path, bool $help_text ): array {
		$text = trim( $message );
		if ( '' === $text ) {
			return array();
		}

		$findings = array();
		if ( 1 === preg_match( '/[A-Za-z]/', $text, $letter_match, PREG_OFFSET_CAPTURE ) ) {
			$letter = $letter_match[0][0];
			if ( strtoupper( $letter ) !== $letter ) {
				$findings[] = new Finding(
					"Message {$reference} used at {$path} must start with an uppercase letter.",
					'onumia.check.messageCasing',
					$file
				);
			}
		}

		$count = preg_match_all( '/[A-Za-z][A-Za-z0-9]*/', $text, $matches );
		if ( false === $count || 0 === $count ) {
			return $findings;
		}

		$words = $matches[0];
		foreach ( $words as $index => $word ) {
			$canonical = AcronymList::canonical( $word );
			if ( null !== $canonical ) {
				if ( $word !== $canonical ) {
					$findings[] = new Finding(
						"Message {$reference} uses acronym {$word}; use {$canonical}. Suggested: " . $this->suggest( $text ),
						'onumia.check.messageAcronym',
						$file
					);
				}
				continue;
			}

			if ( $help_text ) {
				continue;
			}

			if ( 0 === $index ) {
				continue;
			}

			if ( 1 === preg_match( '/[A-Z]/', $word ) ) {
				$findings[] = new Finding(
					"Message {$reference} should use sentence case. Suggested: " . $this->suggest( $text ),
					'onumia.check.messageCasing',
					$file
				);
			}
		}

		return $findings;
	}

	private function suggest( string $text ): string {
		$index = 0;
		return preg_replace_callback(
			'/[A-Za-z][A-Za-z0-9]*/',
			static function ( array $match ) use ( &$index ): string {
				$word      = $match[0];
				$canonical = AcronymList::canonical( $word );
				if ( null !== $canonical ) {
					++$index;
					return $canonical;
				}

				$lower = strtolower( $word );
				if ( 0 === $index ) {
					++$index;
					return ucfirst( $lower );
				}

				++$index;
				return $lower;
			},
			$text
		) ?? $text;
	}
}
