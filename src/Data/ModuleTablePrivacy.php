<?php

/**
 * WordPress privacy exporter integration for module tables.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Data;

use Onumia\Modules\ModuleDefinition;
use Onumia\Modules\ModuleRegistry;
use Onumia\Modules\ModuleTableDefinition;

final class ModuleTablePrivacy {
	public function __construct(
		private readonly ModuleRegistry $registry,
		private readonly ModuleTableStore $store = new ModuleTableStore(),
	) {}

	public function register(): void {
		if ( function_exists( 'add_filter' ) ) {
			\add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		}
	}

	/**
	 * @param  array<string,array<string,mixed>> $exporters Exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function exporters( array $exporters ): array {
		$exporters['onumia-module-tables'] = array(
			'exporter_friendly_name' => 'Onumia module tables',
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * @return array{data:list<array{group_id:string,group_label:string,item_id:string,data:list<array{name:string,value:string}>}>,done:bool}
	 */
	public function export_personal_data( string $email_address, int $page = 1 ): array {
		unset( $page );
		$user = $this->user_for_email( $email_address );
		if ( null === $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user_id    = $this->user_id_from_object( $user );
		$user_login = $this->user_login_from_object( $user );
		$items      = array();
		$seen       = array();
		foreach ( $this->registry->all() as $module ) {
			if ( ! $module->release_enabled() || ! $module->feature_enabled() ) {
				continue;
			}

			foreach ( $module->advanced()->tables() as $table ) {
				foreach ( $this->privacy_rows( $module, $table, $user_id, $user_login ) as $index => $row ) {
					$key = $module->name() . ':' . $table->name . ':' . ( is_scalar( $row['id'] ?? null ) ? (string) $row['id'] : (string) $index );
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}

					$seen[ $key ] = true;
					$items[]      = $this->item_for_row( $module, $table, $row, $index );
				}
			}
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	private function user_for_email( string $email_address ): ?object {
		if ( function_exists( 'get_user_by' ) ) {
			$user = \get_user_by( 'email', $email_address );
			if ( is_object( $user ) && null !== $this->user_id_from_object( $user ) ) {
				return $user;
			}
		}

		foreach ( \get_users(
			array(
				'search' => $email_address,
				'number' => 50,
			)
		) as $user ) {
			if ( is_object( $user ) && is_string( $user->user_email ?? null ) && 0 === strcasecmp( $email_address, $user->user_email ) ) {
				return $user;
			}
		}

		return null;
	}

	private function user_id_from_object( object $user ): ?int {
		$id = $user->ID ?? null;
		return is_numeric( $id ) ? (int) $id : null;
	}

	private function user_login_from_object( object $user ): ?string {
		$login = $user->user_login ?? null;
		return is_string( $login ) && '' !== $login ? $login : null;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function privacy_rows( ModuleDefinition $module, ModuleTableDefinition $table, ?int $user_id, ?string $user_login ): array {
		$rows    = array();
		$handle  = $this->store->handle( $module, $table );
		$columns = $this->column_names( $table );

		if ( null !== $user_id && in_array( 'user_id', $columns, true ) ) {
			$rows = array_merge( $rows, $handle->recent( 1000, null, array( 'user_id' => $user_id ) ) );
		}

		if ( null !== $user_login ) {
			foreach ( array( 'username', 'last_username' ) as $column ) {
				if ( in_array( $column, $columns, true ) ) {
					$rows = array_merge( $rows, $handle->recent( 1000, null, array( $column => $user_login ) ) );
				}
			}
		}

		return $rows;
	}

	/**
	 * @return list<string>
	 */
	private function column_names( ModuleTableDefinition $table ): array {
		$names = array();
		foreach ( $table->columns as $column ) {
			$names[] = $column->name;
		}

		return $names;
	}

	/**
	 * @param  array<string,mixed> $row Row.
	 * @return array{group_id:string,group_label:string,item_id:string,data:list<array{name:string,value:string}>}
	 */
	private function item_for_row( ModuleDefinition $module, ModuleTableDefinition $table, array $row, int $index ): array {
		$data = array();
		foreach ( $row as $key => $value ) {
			$data[] = array(
				'name'  => $key,
				'value' => $this->value_for_export( $value ),
			);
		}

		return array(
			'group_id'    => 'onumia-module-tables',
			'group_label' => 'Onumia module tables',
			'item_id'     => $module->name() . ':' . $table->name . ':' . ( is_scalar( $row['id'] ?? null ) ? (string) $row['id'] : (string) $index ),
			'data'        => $data,
		);
	}

	private function value_for_export( mixed $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		$json = json_encode( $value, JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '';
	}
}
