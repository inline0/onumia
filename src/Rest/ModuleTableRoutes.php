<?php

/**
 * REST routes for module-owned runtime tables.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Rest;

use Onumia\Data\ModuleTableStore;
use Onumia\Data\TableHandle;
use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleRegistry;
use Throwable;

final class ModuleTableRoutes {
	private const NAMESPACE = 'onumia/v1';

	public static function register( ModuleRegistry $registry, ModuleTableStore $store = new ModuleTableStore() ): void {
		\register_rest_route(
			self::NAMESPACE,
			'/tables/(?P<module>.+)/(?P<table>[A-Za-z0-9_]+)/purge',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::purge( $registry, $store, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/tables/(?P<module>.+)/(?P<table>[A-Za-z0-9_]+)',
			array(
				array(
					'methods'             => 'DELETE',
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::purge_all( $registry, $store, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			'/tables/(?P<module>.+)/(?P<table>[A-Za-z0-9_]+)/export',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => self::export( $registry, $store, $request ),
					'permission_callback' => array( self::class, 'can_manage_onumia' ),
				),
			)
		);
	}

	public static function can_manage_onumia(): bool {
		return \current_user_can( 'manage_options' );
	}

	public static function purge( ModuleRegistry $registry, ModuleTableStore $store, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$resolved = self::table_from_request( $registry, $store, $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}

		$before_days = self::positive_int_param( $request->get_param( 'before_days' ) );
		$where       = self::where_param( $request->get_param( 'where' ) );
		if ( $where instanceof \WP_Error ) {
			return $where;
		}

		try {
			$removed = $resolved['handle']->purge( $before_days ?? $resolved['module']->advanced()->table( $resolved['table'] )?->retention_days, $where );
			return new \WP_REST_Response( array( 'purged' => $removed ), 200 );
		} catch ( Throwable $throwable ) {
			return new \WP_Error( 'onumia_table_purge_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	public static function purge_all( ModuleRegistry $registry, ModuleTableStore $store, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$resolved = self::table_from_request( $registry, $store, $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}

		try {
			return new \WP_REST_Response( array( 'purged' => $resolved['handle']->purge_all() ), 200 );
		} catch ( Throwable $throwable ) {
			return new \WP_Error( 'onumia_table_delete_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	public static function export( ModuleRegistry $registry, ModuleTableStore $store, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$resolved = self::table_from_request( $registry, $store, $request );
		if ( $resolved instanceof \WP_Error ) {
			return $resolved;
		}

		$format = $request->get_param( 'format' );
		$format = is_string( $format ) && 'csv' === strtolower( $format ) ? 'csv' : 'json';

		try {
			$rows = $resolved['handle']->export_rows();
			return new \WP_REST_Response(
				array(
					'format' => $format,
					'rows'   => 'json' === $format ? $rows : array(),
					'csv'    => 'csv' === $format ? self::csv( $rows ) : '',
				),
				200
			);
		} catch ( Throwable $throwable ) {
			return new \WP_Error( 'onumia_table_export_failed', $throwable->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @return array{module:ModuleDefinition,table:string,handle:TableHandle}|\WP_Error
	 */
	private static function table_from_request( ModuleRegistry $registry, ModuleTableStore $store, \WP_REST_Request $request ): array|\WP_Error {
		$module_name = $request->get_param( 'module' );
		$table_name  = $request->get_param( 'table' );
		if ( ! is_string( $module_name ) || '' === $module_name ) {
			return new \WP_Error( 'onumia_missing_module', 'Module name is required.', array( 'status' => 400 ) );
		}
		if ( ! is_string( $table_name ) || '' === $table_name ) {
			return new \WP_Error( 'onumia_missing_table', 'Table name is required.', array( 'status' => 400 ) );
		}

		$module = $registry->get( $module_name );
		if ( null === $module || ! $module->release_enabled() || ! $module->feature_enabled() ) {
			return new \WP_Error( 'onumia_unknown_module', 'Module was not found.', array( 'status' => 404 ) );
		}

		try {
			return array(
				'module' => $module,
				'table'  => $table_name,
				'handle' => $store->table( $module, $table_name ),
			);
		} catch ( Throwable $throwable ) {
			return new \WP_Error( 'onumia_unknown_table', $throwable->getMessage(), array( 'status' => 404 ) );
		}
	}

	private static function positive_int_param( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return is_numeric( $value ) ? max( 1, (int) $value ) : null;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function where_param( mixed $value ): array|\WP_Error {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'onumia_invalid_where', 'Where payload must be an object.', array( 'status' => 400 ) );
		}

		$where = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$where[ $key ] = $item;
			}
		}

		return $where;
	}

	/**
	 * @param list<array<string,mixed>> $rows Rows.
	 */
	private static function csv( array $rows ): string {
		if ( array() === $rows ) {
			return '';
		}

		$columns = array_keys( $rows[0] );
		$lines   = array( self::csv_line( $columns ) );
		foreach ( $rows as $row ) {
			$lines[] = self::csv_line( array_map( static fn( string $column ): string => self::csv_value( $row[ $column ] ?? '' ), $columns ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param list<string> $values Values.
	 */
	private static function csv_line( array $values ): string {
		return implode(
			',',
			array_map(
				static fn( string $value ): string => '"' . str_replace( '"', '""', $value ) . '"',
				$values
			)
		);
	}

	private static function csv_value( mixed $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		$json = json_encode( $value, JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '';
	}
}
