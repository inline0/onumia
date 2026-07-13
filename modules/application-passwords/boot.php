<?php
/**
 * Application Passwords module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\ApplicationPasswords;

use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
use Onumia\Modules\Attributes\ObjectShape;
use Onumia\Modules\Attributes\Setting;
use Onumia\Modules\Attributes\WpAction;
use Onumia\Modules\Contracts\ActionIntent;
use Onumia\Modules\Contracts\DataSourceShape;
use Onumia\Modules\Contracts\EntryStorage;
use Onumia\Modules\Contracts\PaginationMode;
use Onumia\Modules\Contracts\SettingType;
use Onumia\Modules\Module;

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'enabled', SettingType::Boolean, default: false )]
#[Setting( 'retentionDays', SettingType::Integer, default: 60, min: 1, max: 365 )]
final class ApplicationPasswords extends Module {
	private const RESULT_SUCCESS = 'success';
	private const RESULT_FAILED  = 'failed';

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'passwords', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
	#[Input( 'query', SettingType::Object, default: array() )]
	#[Input( 'page', SettingType::Integer, default: 0 )]
	#[Input( 'pageSize', SettingType::Integer, default: 10 )]
	#[ObjectShape(
		'query',
		array(
			'search'  => 'string',
			'filters' => 'array',
			'sorting' => 'array',
			'page'    => 'array',
		)
	)]
	#[Entries( name: 'passwords', singular: 'Password', plural: 'Passwords', key: 'id', storage: EntryStorage::Manual, source: 'passwords' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'userId', type: SettingType::Integer, label: 'User ID', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'userLogin', type: SettingType::String, label: 'User', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'userEmail', type: SettingType::String, label: 'Email', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'uuid', type: SettingType::String, label: 'UUID', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'name', type: SettingType::String, label: 'App name', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'createdAt', type: SettingType::Integer, label: 'Created timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'createdLabel', type: SettingType::String, label: 'Created', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastUsedAt', type: SettingType::Integer, label: 'Last used timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastUsedLabel', type: SettingType::String, label: 'Last used', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastIp', type: SettingType::String, label: 'Last IP', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	public function passwords( array $params ): array {
		return $this->paginated_rows( $this->password_rows(), $params );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'usage', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
	#[Input( 'query', SettingType::Object, default: array() )]
	#[Input( 'page', SettingType::Integer, default: 0 )]
	#[Input( 'pageSize', SettingType::Integer, default: 10 )]
	#[ObjectShape(
		'query',
		array(
			'search'  => 'string',
			'filters' => 'array',
			'sorting' => 'array',
			'page'    => 'array',
		)
	)]
	#[Entries( name: 'usage', singular: 'Usage row', plural: 'Usage rows', key: 'id', storage: EntryStorage::Table, source: 'usage', table: 'usage' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'occurredAt', type: SettingType::Integer, label: 'Timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'occurredAtLabel', type: SettingType::String, label: 'Time', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'userId', type: SettingType::Integer, label: 'User ID', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'userLogin', type: SettingType::String, label: 'User', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'uuid', type: SettingType::String, label: 'UUID', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'appName', type: SettingType::String, label: 'App', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'result', type: SettingType::String, label: 'Result', allowed: array( 'success', 'failed' ), filter: true, filter_type: 'option', create: false, update: false, read_only: true )]
	#[EntryField( name: 'resultLabel', type: SettingType::String, label: 'Result', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'endpoint', type: SettingType::String, label: 'Endpoint', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'ipHash', type: SettingType::String, label: 'IP hash', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'ipDisplay', type: SettingType::String, label: 'IP hash', list: true, create: false, update: false, read_only: true )]
	public function usage( array $params ): array {
		$rows = array_reverse( $this->table( 'usage' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'usage_for_display' ), $rows ), $params );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{revoked:int,failed:list<string>}
	 */
	#[Action( 'revokePasswords', intent: ActionIntent::Revoke )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function revoke_passwords( array $input ): array {
		$failed  = array();
		$revoked = 0;

		foreach ( $this->string_list( $input['ids'] ?? array() ) as $id ) {
			$parts   = explode( ':', $id, 2 );
			$user_id = isset( $parts[0] ) && is_numeric( $parts[0] ) ? (int) $parts[0] : 0;
			$uuid    = $parts[1] ?? '';
			if ( $user_id <= 0 || '' === $uuid || ! class_exists( '\WP_Application_Passwords' ) ) {
				$failed[] = $id;
				continue;
			}

			$result = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
			if ( $this->is_wp_error( $result ) || true !== $result ) {
				$failed[] = $id;
				continue;
			}

			++$revoked;
		}

		return array(
			'revoked' => $revoked,
			'failed'  => $failed,
		);
	}

	/**
	 * @param mixed               $user User.
	 * @param array<string,mixed> $item Password item.
	 */
	#[WpAction( 'application_password_did_authenticate', priority: 10, accepted_args: 2 )]
	public function record_successful_authentication( mixed $user, array $item ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$this->record_usage( self::RESULT_SUCCESS, $this->user_id_from_user( $user ), $this->user_login_from_user( $user ), $item );
	}

	/**
	 * @param mixed $error Error.
	 */
	#[WpAction( 'application_password_failed_authentication', priority: 10, accepted_args: 1 )]
	public function record_failed_authentication( mixed $error ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$data = $this->wp_error_data( $error );
		$user = is_object( $data ) ? $data : ( is_array( $data['user'] ?? null ) ? (object) $data['user'] : ( is_object( $data['user'] ?? null ) ? $data['user'] : null ) );

		$this->record_usage(
			self::RESULT_FAILED,
			$this->user_id_from_user( $user ),
			$this->user_login_from_user( $user ),
			array(
				'uuid' => '',
				'name' => '<unknown>',
			)
		);
	}

	#[WpAction( 'onumia_tables_cleanup', priority: 10, accepted_args: 0 )]
	public function prune_runtime_tables(): void {
		$this->table( 'usage' )->purge( $this->retention_days() );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function password_rows(): array {
		if ( ! class_exists( '\WP_Application_Passwords' ) || ! function_exists( 'get_users' ) ) {
			return array();
		}

		$rows = array();
		foreach (
			\get_users(
				array(
					'fields' => 'all',
					'number' => 9999,
				)
			) as $user
		) {
			$user_id = $this->user_id_from_user( $user );
			if ( null === $user_id ) {
				continue;
			}

			$passwords = \WP_Application_Passwords::get_user_application_passwords( $user_id );
			if ( ! is_array( $passwords ) ) {
				continue;
			}

			foreach ( $passwords as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$uuid = is_string( $item['uuid'] ?? null ) ? $item['uuid'] : '';
				if ( '' === $uuid ) {
					continue;
				}

				$created   = $this->timestamp_value( $item['created'] ?? null );
				$last_used = $this->timestamp_value( $item['last_used'] ?? null );

				$rows[] = array(
					'id'            => "{$user_id}:{$uuid}",
					'userId'        => $user_id,
					'userLogin'     => $this->user_login_from_user( $user ) ?? "user-{$user_id}",
					'userEmail'     => $this->user_email_from_user( $user ),
					'uuid'          => $uuid,
					'name'          => is_string( $item['name'] ?? null ) ? $item['name'] : '',
					'createdAt'     => $created,
					'createdLabel'  => $this->time_label( $created ),
					'lastUsedAt'    => $last_used,
					'lastUsedLabel' => $this->time_label( $last_used ),
					'lastIp'        => is_string( $item['last_ip'] ?? null ) ? $item['last_ip'] : '',
				);
			}
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $item Password item.
	 */
	private function record_usage( string $result, ?int $user_id, ?string $user_login, array $item ): void {
		$this->table( 'usage' )->insert(
			array(
				'occurred_at'   => $this->now(),
				'user_id'       => $user_id,
				'user_login'    => substr( $user_login ?? '<unknown>', 0, 60 ),
				'password_uuid' => substr( is_string( $item['uuid'] ?? null ) ? $item['uuid'] : '', 0, 36 ),
				'app_name'      => substr( is_string( $item['name'] ?? null ) ? $item['name'] : '<unknown>', 0, 255 ),
				'result'        => $result,
				'endpoint'      => substr( $this->current_endpoint(), 0, 255 ),
				'ip_hash'       => $this->current_ip_hash(),
			)
		);
	}

	private function current_ip_hash(): string {
		return $this->ip_hash( $this->current_ip() );
	}

	private function current_ip(): string {
		$remote_addr = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return is_string( $remote_addr ) ? $remote_addr : '';
	}

	private function current_endpoint(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately after unslashing.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$route       = is_array( $request_uri ) ? '' : sanitize_text_field( $request_uri );
		if ( isset( $_GET['rest_route'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passive audit metadata, sanitized immediately after unslashing.
			$rest_route = wp_unslash( $_GET['rest_route'] );
			$route      = is_array( $rest_route ) ? '' : sanitize_text_field( $rest_route );
		}

		return is_string( $route ) ? $route : '';
	}

	private function timestamp_value( mixed $value ): int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	private function user_id_from_user( mixed $user ): ?int {
		if ( ! is_object( $user ) ) {
			return null;
		}

		$id = $user->ID ?? $user->id ?? null;
		return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
	}

	private function user_login_from_user( mixed $user ): ?string {
		if ( ! is_object( $user ) ) {
			return null;
		}

		$login = $user->user_login ?? $user->login ?? null;
		return is_string( $login ) && '' !== trim( $login ) ? trim( $login ) : null;
	}

	private function user_email_from_user( mixed $user ): string {
		if ( ! is_object( $user ) ) {
			return '';
		}

		$email = $user->user_email ?? $user->email ?? null;
		return is_string( $email ) ? $email : '';
	}

	/**
	 * @param mixed $error Error.
	 * @return array<string,mixed>|object|null
	 */
	private function wp_error_data( mixed $error ): array|object|null {
		if ( is_object( $error ) && method_exists( $error, 'get_error_data' ) ) {
			$data = $error->get_error_data();
			return is_array( $data ) || is_object( $data ) ? $data : null;
		}

		return null;
	}

	private function is_wp_error( mixed $value ): bool {
		return function_exists( 'is_wp_error' ) ? \is_wp_error( $value ) : is_object( $value ) && $value instanceof \WP_Error;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function usage_for_display( array $row ): array {
		$timestamp = $this->timestamp_value( $row['occurred_at'] ?? null );
		$result    = is_string( $row['result'] ?? null ) ? $row['result'] : '';
		$hash      = is_string( $row['ip_hash'] ?? null ) ? $row['ip_hash'] : '';

		return array(
			'id'              => (string) ( $row['id'] ?? '' ),
			'occurredAt'      => $timestamp,
			'occurredAtLabel' => $this->time_label( $timestamp ),
			'userId'          => isset( $row['user_id'] ) && is_numeric( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'userLogin'       => (string) ( $row['user_login'] ?? '<unknown>' ),
			'uuid'            => (string) ( $row['password_uuid'] ?? '' ),
			'appName'         => (string) ( $row['app_name'] ?? '<unknown>' ),
			'result'          => $result,
			'resultLabel'     => $this->result_label( $result ),
			'endpoint'        => (string) ( $row['endpoint'] ?? '' ),
			'ipHash'          => $hash,
			'ipDisplay'       => substr( $hash, 0, 12 ),
		);
	}

	private function result_label( string $result ): string {
		return match ( $result ) {
			self::RESULT_SUCCESS => 'Success',
			self::RESULT_FAILED => 'Failed',
			default => ucfirst( $result ),
		};
	}
}
