<?php

/**
 * WP-CLI module catalog audit command.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Cli;

use Onumia\Modules\ModuleCatalogAuditor;
use Onumia\Modules\ModuleRegistry;

// @codeCoverageIgnoreStart
final class ModuleAuditCommand {
	public function __construct(
		private readonly ModuleRegistry $registry,
		private readonly ModuleCatalogAuditor $auditor = new ModuleCatalogAuditor(),
	) {}

	/**
	 * @param string[]              $args       Positional args.
	 * @param array<string,string>  $assoc_args Assoc args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$audit = $this->auditor->audit( $this->registry );
		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			\WP_CLI::line( (string) json_encode( $audit ) );
		} else {
			foreach ( $audit as $key => $value ) {
				if ( is_array( $value ) ) {
					continue;
				}
				\WP_CLI::line( "{$key}: {$value}" );
			}

			\WP_CLI::line( 'modules:' );
			foreach ( $audit['modules'] as $module ) {
				\WP_CLI::line(
					sprintf(
						'- %s: tabs=%d real=%d partial=%d placeholder=%d',
						(string) $module['name'],
						(int) $module['tabSurfaces'],
						(int) $module['real'],
						(int) $module['partials'],
						(int) $module['placeholders']
					)
				);
			}

			if ( array() !== $audit['placeholderTabs'] ) {
				\WP_CLI::line( 'placeholderTabs:' );
				foreach ( $audit['placeholderTabs'] as $placeholder_tab ) {
					\WP_CLI::line( '- ' . $placeholder_tab );
				}
			}
		}

		if ( 0 !== $audit['placeholders'] || 0 !== $audit['partials'] ) {
			\WP_CLI::halt( 1 );
		}
	}
}
// @codeCoverageIgnoreEnd
