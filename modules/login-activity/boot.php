<?php
/**
 * Login Activity module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\LoginActivity;

use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
use Onumia\Modules\Attributes\ObjectShape;
use Onumia\Modules\Attributes\Setting;
use Onumia\Modules\Attributes\WpAction;
use Onumia\Modules\Contracts\DataSourceShape;
use Onumia\Modules\Contracts\EntryStorage;
use Onumia\Modules\Contracts\PaginationMode;
use Onumia\Modules\Contracts\SettingType;
use Onumia\Modules\Module;

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'enabled', SettingType::Boolean, default: false )]
#[Setting( 'retentionDays', SettingType::Integer, default: 90, min: 1, max: 365 )]
final class LoginActivity extends Module {
	private const RESULT_SUCCESS = 'success';
	private const RESULT_FAILED  = 'failed';

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'loginActivityLogins', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'loginActivityLogins', singular: 'Login', plural: 'Logins', key: 'id', storage: EntryStorage::Table, source: 'loginActivityLogins', table: 'logins' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'occurredAt', type: SettingType::Integer, label: 'Timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'occurredAtLabel', type: SettingType::String, label: 'Time', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'userId', type: SettingType::Integer, label: 'User ID', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'userLogin', type: SettingType::String, label: 'User', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'result', type: SettingType::String, label: 'Result', filter: true, filter_type: 'option', allowed: array( 'success', 'failed' ), create: false, update: false, read_only: true )]
	#[EntryField( name: 'resultLabel', type: SettingType::String, label: 'Result', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'ipHash', type: SettingType::String, label: 'IP hash', create: false, update: false, read_only: true )]
	#[EntryField( name: 'ipDisplay', type: SettingType::String, label: 'IP hash', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'userAgent', type: SettingType::String, label: 'User agent', filter: true, filter_type: 'text', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'userAgentPreview', type: SettingType::String, label: 'User agent', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'referrer', type: SettingType::String, label: 'Referrer', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	public function logins( array $params ): array {
		$rows = array_reverse( $this->table( 'logins' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'login_for_display' ), $rows ), $params );
	}

	/**
	 * @param mixed $user User object.
	 */
	#[WpAction( 'wp_login', priority: 10, accepted_args: 2 )]
	public function record_successful_login( string $user_login, mixed $user = null ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$this->record_login( $this->login_request_username( $user_login ), self::RESULT_SUCCESS, $this->user_id_from_user( $user ) );
	}

	#[WpAction( 'wp_login_failed', priority: 10, accepted_args: 1 )]
	public function record_failed_login( string $username ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$username = $this->login_request_username( $username );
		$this->record_login( $username, self::RESULT_FAILED, $this->user_id_for_login( $username ) );
	}

	#[WpAction( 'onumia_tables_cleanup', priority: 10, accepted_args: 0 )]
	public function prune_runtime_tables(): void {
		$this->table( 'logins' )->purge( $this->retention_days() );
	}

	private function record_login( string $username, string $result, ?int $user_id ): void {
		$username = '' === trim( $username ) ? '<unknown>' : trim( $username );

		$this->table( 'logins' )->insert(
			array(
				'occurred_at' => $this->now(),
				'user_id'     => $user_id,
				'user_login'  => substr( $username, 0, 60 ),
				'result'      => $result,
				'ip_hash'     => $this->current_ip_hash(),
				'user_agent'  => $this->current_user_agent(),
				'referrer'    => $this->current_referrer(),
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

	private function current_user_agent(): string {
		$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		return substr( is_string( $user_agent ) ? $user_agent : '', 0, 255 );
	}

	private function current_referrer(): string {
		$referrer = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) );
		return substr( is_string( $referrer ) ? $referrer : '', 0, 512 );
	}

	private function login_request_username( string $username ): string {
		$username = trim( $username );
		if ( '' !== $username ) {
			return $username;
		}

		$posted = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress owns the login form nonce during authentication.
		if ( isset( $_POST['log'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WordPress owns the login form nonce; value is unslashed and sanitized below.
			$posted = wp_unslash( $_POST['log'] );
		}

		if ( is_array( $posted ) ) {
			return '';
		}

		$value = sanitize_text_field( $posted );
		return is_string( $value ) ? trim( $value ) : '';
	}

	private function user_id_from_user( mixed $user ): ?int {
		if ( ! is_object( $user ) ) {
			return null;
		}

		$id = $user->ID ?? $user->id ?? null;
		return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
	}

	private function user_id_for_login( string $username ): ?int {
		if ( '' === trim( $username ) || ! function_exists( 'get_user_by' ) ) {
			return null;
		}

		$user = get_user_by( str_contains( $username, '@' ) ? 'email' : 'login', $username );
		return $this->user_id_from_user( is_object( $user ) ? $user : null );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function login_for_display( array $row ): array {
		$timestamp  = isset( $row['occurred_at'] ) && is_numeric( $row['occurred_at'] ) ? (int) $row['occurred_at'] : 0;
		$user_agent = (string) ( $row['user_agent'] ?? '' );
		$result     = (string) ( $row['result'] ?? '' );

		return array(
			'id'               => (string) ( $row['id'] ?? '' ),
			'occurredAt'       => $timestamp,
			'occurredAtLabel'  => $this->time_label( $timestamp ),
			'userId'           => isset( $row['user_id'] ) && is_numeric( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'userLogin'        => (string) ( $row['user_login'] ?? '<unknown>' ),
			'result'           => $result,
			'resultLabel'      => $this->result_label( $result ),
			'ipHash'           => (string) ( $row['ip_hash'] ?? '' ),
			'ipDisplay'        => substr( (string) ( $row['ip_hash'] ?? '' ), 0, 12 ),
			'userAgent'        => $user_agent,
			'userAgentPreview' => mb_strlen( $user_agent ) > 80 ? mb_substr( $user_agent, 0, 77 ) . '...' : $user_agent,
			'referrer'         => (string) ( $row['referrer'] ?? '' ),
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
