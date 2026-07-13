<?php
/**
 * Login Blocker module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\LoginBlocker;

use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
use Onumia\Modules\Attributes\EntrySection;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
use Onumia\Modules\Attributes\ObjectShape;
use Onumia\Modules\Attributes\Setting;
use Onumia\Modules\Attributes\WpAction;
use Onumia\Modules\Attributes\WpFilter;
use Onumia\Modules\Contracts\DataSourceShape;
use Onumia\Modules\Contracts\EntryStorage;
use Onumia\Modules\Contracts\PaginationMode;
use Onumia\Modules\Contracts\SettingType;
use Onumia\Modules\Module;
use Onumia\Modules\ModuleSettingsRepository;

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'enabled', SettingType::Boolean, default: false )]
#[Setting( 'rules', SettingType::Array, default: array() )]
#[Setting(
	'throttle',
	SettingType::Object,
	default: array(
		'enabled'        => true,
		'windowMinutes'  => 10,
		'maxAttempts'    => 5,
		'lockoutMinutes' => 60,
	)
)]
#[Setting(
	'whitelist',
	SettingType::Object,
	default: array(
		'ips'       => array(),
		'usernames' => array(),
	)
)]
#[Setting( 'retentionDays', SettingType::Integer, default: 30, min: 1, max: 365 )]
final class LoginBlocker extends Module {
	private const RESULT_ALLOWED        = 'allowed';
	private const RESULT_WRONG_PASSWORD = 'wrong_password';
	private const RESULT_BLOCKED        = 'blocked_by_rule';
	private const RESULT_LOCKED_OUT     = 'locked_out';
	private const RESULT_THROTTLED      = 'throttled';
	private const ERROR_MESSAGE         = 'Invalid username, email address or incorrect password.';

	/** @var array<string,bool> */
	private array $logged_denials = array();

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'loginBlockerRules', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'rules', singular: 'Rule', plural: 'Rules', key: 'id', storage: EntryStorage::Manual, source: 'loginBlockerRules', create_action: 'saveRule', update_action: 'saveRule', delete_action: 'deleteRules' )]
	#[EntrySection( name: 'identity', label: 'Identity', description: 'Name the login rule.', order: 10, layout: 'tabs' )]
	#[EntrySection( name: 'match', label: 'Match', description: 'Decide which login attempt property is matched.', order: 20, layout: 'tabs' )]
	#[EntrySection( name: 'action', label: 'Action', description: 'Choose whether matching attempts are blocked or throttled.', order: 30, layout: 'tabs' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, required: true, create: false, update: true, read_only: true, section: 'identity', order: 10 )]
	#[EntryField( name: 'label', type: SettingType::String, label: 'Label', required: true, list: true, filter: true, filter_type: 'text', section: 'identity', order: 20 )]
	#[EntryField( name: 'enabled', type: SettingType::Boolean, label: 'Enabled', default: true, section: 'identity', order: 30 )]
	#[EntryField( name: 'enabledLabel', type: SettingType::String, label: 'Enabled', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'identity', order: 40 )]
	#[EntryField(
		name: 'matchType',
		type: SettingType::String,
		label: 'Match type',
		default: 'username',
		allowed: array( 'ip', 'username', 'user_agent' ),
		options: array(
			array(
				'value' => 'ip',
				'label' => 'IP address',
			),
			array(
				'value' => 'username',
				'label' => 'Username',
			),
			array(
				'value' => 'user_agent',
				'label' => 'User agent',
			),
		),
		required: true,
		section: 'match',
		order: 10
	)]
	#[EntryField( name: 'matchTypeLabel', type: SettingType::String, label: 'Match type', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'match', order: 20 )]
	#[EntryField( name: 'matchPattern', type: SettingType::String, label: 'Pattern', required: true, list: true, filter: true, filter_type: 'text', section: 'match', order: 30 )]
	#[EntryField(
		name: 'matchMode',
		type: SettingType::String,
		label: 'Match mode',
		default: 'exact',
		allowed: array( 'exact', 'contains', 'glob', 'regex' ),
		options: array(
			array(
				'value' => 'exact',
				'label' => 'Exact',
			),
			array(
				'value' => 'contains',
				'label' => 'Contains',
			),
			array(
				'value' => 'glob',
				'label' => 'Glob',
			),
			array(
				'value' => 'regex',
				'label' => 'Regex',
			),
		),
		required: true,
		section: 'match',
		order: 40
	)]
	#[EntryField( name: 'matchModeLabel', type: SettingType::String, label: 'Match mode', create: false, update: false, read_only: true, section: 'match', order: 50 )]
	#[EntryField(
		name: 'action',
		type: SettingType::String,
		label: 'Action',
		default: 'block',
		allowed: array( 'block', 'throttle' ),
		options: array(
			array(
				'value' => 'block',
				'label' => 'Block',
			),
			array(
				'value' => 'throttle',
				'label' => 'Throttle',
			),
		),
		required: true,
		section: 'action',
		order: 10
	)]
	#[EntryField( name: 'actionLabel', type: SettingType::String, label: 'Action', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'action', order: 20 )]
	#[EntryField( name: 'lockoutMinutes', type: SettingType::Integer, label: 'Lockout minutes', default: 0, min: 0, max: 10080, section: 'action', order: 30 )]
	#[EntryField( name: 'notes', type: SettingType::String, label: 'Notes', default: '', section: 'action', order: 40, props: array( 'multiline' => true ) )]
	public function rules(): array {
		return array_map( array( $this, 'rule_for_display' ), $this->stored_rules() );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'loginAttempts', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'loginAttempts', singular: 'Login attempt', plural: 'Login attempts', key: 'id', storage: EntryStorage::Table, source: 'loginAttempts', table: 'login_attempts', delete_action: 'blockSelectedAttemptIps', destructive_mode: 'revoke' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'attemptedAt', type: SettingType::Integer, label: 'Timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'attemptedAtLabel', type: SettingType::String, label: 'Time', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'username', type: SettingType::String, label: 'Username', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'ipDisplay', type: SettingType::String, label: 'IP hash', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'result', type: SettingType::String, label: 'Result', filter: true, filter_type: 'option', allowed: array( 'allowed', 'wrong_password', 'blocked_by_rule', 'locked_out', 'throttled' ), create: false, update: false, read_only: true )]
	#[EntryField( name: 'resultLabel', type: SettingType::String, label: 'Result', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'userAgent', type: SettingType::String, label: 'User agent', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'ruleId', type: SettingType::String, label: 'Rule ID', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	public function login_attempts( array $params ): array {
		return $this->paginated_rows(
			array_map( array( $this, 'attempt_for_display' ), array_reverse( $this->table( 'login_attempts' )->export_rows() ) ),
			$params
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'activeLockouts', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'activeLockouts', singular: 'Lockout', plural: 'Lockouts', key: 'id', storage: EntryStorage::Table, source: 'activeLockouts', table: 'lockouts', delete_action: 'unlockLockouts', destructive_mode: 'deactivate' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'target', type: SettingType::String, label: 'Target', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'targetType', type: SettingType::String, label: 'Target type', filter: true, filter_type: 'option', allowed: array( 'ip', 'username' ), create: false, update: false, read_only: true )]
	#[EntryField( name: 'lockedAt', type: SettingType::Integer, label: 'Locked at', create: false, update: false, read_only: true )]
	#[EntryField( name: 'lockedAtLabel', type: SettingType::String, label: 'Locked at', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'expiresAt', type: SettingType::Integer, label: 'Expires at', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'expiresAtLabel', type: SettingType::String, label: 'Expires at', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'attemptCount', type: SettingType::Integer, label: 'Attempts', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastUsername', type: SettingType::String, label: 'Last username', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'ruleId', type: SettingType::String, label: 'Rule ID', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	public function active_lockouts( array $params ): array {
		$now  = $this->now();
		$rows = array_values(
			array_filter(
				array_reverse( $this->table( 'lockouts' )->export_rows() ),
				static fn( array $row ): bool => isset( $row['expires_at'] ) && is_numeric( $row['expires_at'] ) && (int) $row['expires_at'] > $now
			)
		);

		return $this->paginated_rows( array_map( array( $this, 'lockout_for_display' ), $rows ), $params );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'saveRule' )]
	#[Input( 'id', SettingType::String, default: '' )]
	#[Input( 'label', SettingType::String, required: true )]
	#[Input( 'enabled', SettingType::Boolean, default: true )]
	#[Input( 'matchType', SettingType::String, default: 'username', allowed: array( 'ip', 'username', 'user_agent' ) )]
	#[Input( 'matchPattern', SettingType::String, required: true )]
	#[Input( 'matchMode', SettingType::String, default: 'exact', allowed: array( 'exact', 'contains', 'glob', 'regex' ) )]
	#[Input( 'action', SettingType::String, default: 'block', allowed: array( 'block', 'throttle' ) )]
	#[Input( 'lockoutMinutes', SettingType::Integer, default: 0 )]
	#[Input( 'notes', SettingType::String, default: '' )]
	public function save_rule( array $input ): array {
		$rules = $this->stored_rules();
		$id    = $this->string_from( $input, 'id' );
		if ( '' === $id ) {
			$id = $this->slug_from_label( $this->string_from( $input, 'label', 'login-rule' ) );
		}

		$row      = $this->normalize_rule(
			array(
				'id'             => $id,
				'label'          => $this->string_from( $input, 'label', 'Login rule' ),
				'enabled'        => $this->bool_from( $input, 'enabled', true ),
				'matchType'      => $this->allowed_string( $input, 'matchType', array( 'ip', 'username', 'user_agent' ), 'username' ),
				'matchPattern'   => $this->string_from( $input, 'matchPattern' ),
				'matchMode'      => $this->allowed_string( $input, 'matchMode', array( 'exact', 'contains', 'glob', 'regex' ), 'exact' ),
				'action'         => $this->allowed_string( $input, 'action', array( 'block', 'throttle' ), 'block' ),
				'lockoutMinutes' => max( 0, (int) ( $input['lockoutMinutes'] ?? 0 ) ),
				'notes'          => $this->string_from( $input, 'notes' ),
			)
		);
		$replaced = false;
		foreach ( $rules as $index => $rule ) {
			if ( ( $rule['id'] ?? '' ) === $id ) {
				$rules[ $index ] = $row;
				$replaced        = true;
				break;
			}
		}

		if ( ! $replaced ) {
			$rules[] = $row;
		}

		$this->save_rules( $rules );
		return array(
			'ok'   => true,
			'rule' => $this->rule_for_display( $row ),
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'deleteRules' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function delete_rules( array $input ): array {
		$ids = $this->string_list( $input['ids'] ?? array(), unique: false, trim: false );
		$this->save_rules(
			array_values(
				array_filter(
					$this->stored_rules(),
					static fn( array $row ): bool => ! in_array( (string) ( $row['id'] ?? '' ), $ids, true )
				)
			)
		);

		return array(
			'ok'      => true,
			'deleted' => $ids,
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'blockSelectedAttemptIps' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function block_selected_attempt_ips( array $input ): array {
		$ids     = array_map( 'strval', $this->string_list( $input['ids'] ?? array(), unique: false, trim: false ) );
		$attempt = $this->table( 'login_attempts' );
		$rules   = $this->stored_rules();
		foreach ( $attempt->export_rows() as $row ) {
			if ( ! in_array( (string) ( $row['id'] ?? '' ), $ids, true ) ) {
				continue;
			}

			$hash = is_string( $row['ip_hash'] ?? null ) ? $row['ip_hash'] : '';
			if ( '' === $hash ) {
				continue;
			}

			$rules[] = $this->normalize_rule(
				array(
					'id'             => 'block-ip-' . substr( $hash, 0, 12 ),
					'label'          => 'Block IP ' . substr( $hash, 0, 12 ),
					'enabled'        => true,
					'matchType'      => 'ip',
					'matchPattern'   => $hash,
					'matchMode'      => 'exact',
					'action'         => 'block',
					'lockoutMinutes' => 0,
					'notes'          => 'Created from selected login attempts.',
				)
			);
		}

		$this->save_rules( $this->unique_rules( $rules ) );
		return array(
			'ok'  => true,
			'ids' => $ids,
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	#[Action( 'unlockLockouts' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function unlock_lockouts( array $input ): array {
		$ids    = $this->string_list( $input['ids'] ?? array(), unique: false, trim: false );
		$handle = $this->table( 'lockouts' );
		$rows   = $handle->export_rows();
		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) ) {
				foreach ( $rows as $row ) {
					if ( (int) ( $row['id'] ?? 0 ) !== (int) $id ) {
						continue;
					}
					$this->purge_attempts_for_lockout( $row );
					break;
				}
				$handle->purge( null, array( 'id' => (int) $id ) );
			}
		}

		return array(
			'ok'       => true,
			'unlocked' => $ids,
		);
	}

	/**
	 * @param array<string,mixed> $lockout Lockout row.
	 */
	private function purge_attempts_for_lockout( array $lockout ): void {
		$type  = is_string( $lockout['target_type'] ?? null ) ? $lockout['target_type'] : '';
		$value = is_string( $lockout['target_value'] ?? null ) ? $lockout['target_value'] : '';
		if ( '' === $value ) {
			return;
		}

		if ( 'ip' === $type ) {
			$this->table( 'login_attempts' )->purge( null, array( 'ip_hash' => $value ) );
			return;
		}

		if ( 'username' === $type ) {
			$this->table( 'login_attempts' )->purge( null, array( 'username' => $value ) );
		}
	}

	/**
	 * @param mixed $user User or error.
	 */
	#[WpFilter( 'authenticate', priority: 30, accepted_args: 3 )]
	public function authenticate( mixed $user, string $username = '', string $password = '' ): mixed {
		unset( $password );
		$username = $this->login_request_username( $username );
		if ( ! $this->enabled() || '' === $username || $this->is_whitelisted( $username ) ) {
			return $user;
		}

		$decision = $this->evaluate_attempt( $username );
		if ( null === $decision ) {
			return $user;
		}

		$this->log_denial( $username, $decision['result'], $decision['ruleId'] );
		return $this->login_error();
	}

	#[WpAction( 'wp_login', priority: 10, accepted_args: 2 )]
	public function record_successful_login( string $user_login, mixed $user = null ): void {
		unset( $user );
		if ( ! $this->enabled() || $this->is_whitelisted( $user_login ) ) {
			return;
		}

		$this->record_attempt( $user_login, self::RESULT_ALLOWED );
	}

	#[WpAction( 'wp_login_failed', priority: 10, accepted_args: 1 )]
	public function record_failed_login( string $username ): void {
		$username = $this->login_request_username( $username );
		if ( ! $this->enabled() || $this->is_whitelisted( $username ) || isset( $this->logged_denials[ $this->attempt_key( $username ) ] ) ) {
			return;
		}

		$this->record_attempt( $username, self::RESULT_WRONG_PASSWORD );
	}

	/**
	 * @param mixed $user User or error.
	 */
	#[WpFilter( 'wp_authenticate_user', priority: 30, accepted_args: 2 )]
	public function authenticate_user( mixed $user, string $password = '' ): mixed {
		unset( $password );
		$username = $this->username_from_user( $user );
		if ( ! $this->enabled() || '' === $username || $this->is_whitelisted( $username ) ) {
			return $user;
		}

		$decision = $this->evaluate_attempt( $username );
		if ( null === $decision ) {
			return $user;
		}

		$this->log_denial( $username, $decision['result'], $decision['ruleId'] );
		return $this->login_error();
	}

	#[WpAction( 'onumia_tables_cleanup', priority: 10, accepted_args: 0 )]
	public function prune_runtime_tables(): void {
		$this->table( 'login_attempts' )->purge( $this->retention_days() );

		$cutoff = $this->now() - ( 7 * $this->day_seconds() );
		$handle = $this->table( 'lockouts' );
		foreach ( $handle->export_rows() as $row ) {
			if ( isset( $row['expires_at'] ) && is_numeric( $row['expires_at'] ) && (int) $row['expires_at'] < $cutoff && is_numeric( $row['id'] ?? null ) ) {
				$handle->purge( null, array( 'id' => (int) $row['id'] ) );
			}
		}
	}

	/**
	 * @return array{result:string,ruleId:?string}|null
	 */
	public function evaluate_attempt( string $username ): ?array {
		$lockout = $this->active_lockout_for( $username );
		if ( null !== $lockout ) {
			return array(
				'result' => self::RESULT_LOCKED_OUT,
				'ruleId' => is_string( $lockout['rule_id'] ?? null ) ? $lockout['rule_id'] : null,
			);
		}

		foreach ( $this->stored_rules() as $rule ) {
			if ( ! (bool) ( $rule['enabled'] ?? true ) || ! $this->rule_matches( $rule, $username ) ) {
				continue;
			}

			$action = (string) ( $rule['action'] ?? 'block' );
			if ( 'block' === $action ) {
				$minutes = max( 0, (int) ( $rule['lockoutMinutes'] ?? 0 ) );
				if ( $minutes > 0 ) {
					$this->insert_lockout( 'ip', $this->current_ip_hash(), $username, $minutes, (string) ( $rule['id'] ?? '' ), 1 );
				}

				return array(
					'result' => self::RESULT_BLOCKED,
					'ruleId' => is_string( $rule['id'] ?? null ) ? $rule['id'] : null,
				);
			}
		}

		if ( $this->throttle_enabled() && $this->failed_attempt_count() >= $this->throttle_int( 'maxAttempts', 5 ) ) {
			$this->insert_lockout( 'ip', $this->current_ip_hash(), $username, $this->throttle_int( 'lockoutMinutes', 60 ), '', $this->failed_attempt_count() );
			return array(
				'result' => self::RESULT_THROTTLED,
				'ruleId' => null,
			);
		}

		return null;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function stored_rules(): array {
		$rules = $this->setting( 'rules' );
		if ( ! is_array( $rules ) ) {
			return array();
		}

		return array_values( array_map( array( $this, 'normalize_rule' ), array_filter( $rules, 'is_array' ) ) );
	}

	/**
	 * @param list<array<string,mixed>> $rules Rules.
	 */
	private function save_rules( array $rules ): void {
		( new ModuleSettingsRepository() )->update_settings(
			$this->definition(),
			array(
				'rules' => array_values( array_map( array( $this, 'normalize_rule' ), $rules ) ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $rule Rule.
	 * @return array<string,mixed>
	 */
	private function normalize_rule( array $rule ): array {
		return array(
			'id'             => $this->non_empty_string( $rule['id'] ?? '', 'rule-' . substr( md5( (string) random_int( 1, PHP_INT_MAX ) ), 0, 8 ) ),
			'label'          => $this->non_empty_string( $rule['label'] ?? '', 'Login rule' ),
			'enabled'        => true === ( $rule['enabled'] ?? true ),
			'matchType'      => $this->allowed_value( $rule['matchType'] ?? 'username', array( 'ip', 'username', 'user_agent' ), 'username' ),
			'matchPattern'   => $this->non_empty_string( $rule['matchPattern'] ?? '', '*' ),
			'matchMode'      => $this->allowed_value( $rule['matchMode'] ?? 'exact', array( 'exact', 'contains', 'glob', 'regex' ), 'exact' ),
			'action'         => $this->allowed_value( $rule['action'] ?? 'block', array( 'block', 'throttle' ), 'block' ),
			'lockoutMinutes' => max( 0, (int) ( $rule['lockoutMinutes'] ?? 0 ) ),
			'notes'          => is_string( $rule['notes'] ?? null ) ? $rule['notes'] : '',
		);
	}

	/**
	 * @param array<string,mixed> $rule Rule.
	 * @return array<string,mixed>
	 */
	private function rule_for_display( array $rule ): array {
		$rule                   = $this->normalize_rule( $rule );
		$rule['enabledLabel']   = $rule['enabled'] ? 'Enabled' : 'Disabled';
		$rule['matchTypeLabel'] = $this->match_type_label( $rule['matchType'] );
		$rule['matchModeLabel'] = ucfirst( (string) $rule['matchMode'] );
		$rule['actionLabel']    = ucfirst( (string) $rule['action'] );
		return $rule;
	}

	/**
	 * @param list<array<string,mixed>> $rules Rules.
	 * @return list<array<string,mixed>>
	 */
	private function unique_rules( array $rules ): array {
		$indexed = array();
		foreach ( $rules as $rule ) {
			$normalized                            = $this->normalize_rule( $rule );
			$indexed[ (string) $normalized['id'] ] = $normalized;
		}

		return array_values( $indexed );
	}

	/**
	 * @param array<string,mixed> $rule Rule.
	 */
	private function rule_matches( array $rule, string $username ): bool {
		$type    = (string) ( $rule['matchType'] ?? 'username' );
		$pattern = (string) ( $rule['matchPattern'] ?? '' );
		$value   = match ( $type ) {
			'user_agent' => $this->current_user_agent(),
			'ip' => '',
			default => $username,
		};

		$mode = (string) ( $rule['matchMode'] ?? 'exact' );
		if ( 'ip' === $type ) {
			return $this->ip_rule_matches( $pattern, $mode );
		}

		return $this->matches_pattern( $value, $pattern, $mode );
	}

	private function matches_pattern( string $value, string $pattern, string $mode ): bool {
		return match ( $mode ) {
			'contains' => str_contains( strtolower( $value ), strtolower( $pattern ) ),
			'glob' => fnmatch( strtolower( $pattern ), strtolower( $value ) ),
			'regex' => $this->matches_regex( $value, $pattern ),
			default => 0 === strcasecmp( $value, $pattern ),
		};
	}

	private function ip_rule_matches( string $pattern, string $mode ): bool {
		$ip   = $this->current_ip();
		$hash = $this->current_ip_hash();
		if ( '' === $pattern || '' === $ip ) {
			return false;
		}

		if ( 'exact' === $mode && $this->matches_cidr( $ip, $pattern ) ) {
			return true;
		}

		return $this->matches_pattern( $ip, $pattern, $mode ) || $this->matches_pattern( $hash, $pattern, $mode );
	}

	private function matches_regex( string $value, string $pattern ): bool {
		if ( '' === $pattern ) {
			return false;
		}

		$result = @preg_match( $pattern, $value );
		return 1 === $result;
	}

	private function matches_cidr( string $ip, string $pattern ): bool {
		$pattern = trim( $pattern );
		if ( ! str_contains( $pattern, '/' ) ) {
			return 0 === strcasecmp( $ip, $pattern );
		}

		$parts = explode( '/', $pattern, 2 );
		if ( 2 !== count( $parts ) || ! is_numeric( $parts[1] ) ) {
			return false;
		}

		$ip_bytes      = inet_pton( $ip );
		$network_bytes = inet_pton( trim( $parts[0] ) );
		if ( false === $ip_bytes || false === $network_bytes || strlen( $ip_bytes ) !== strlen( $network_bytes ) ) {
			return false;
		}

		$max_bits = strlen( $ip_bytes ) * 8;
		$bits     = (int) $parts[1];
		if ( $bits < 0 || $bits > $max_bits ) {
			return false;
		}

		$full_bytes = intdiv( $bits, 8 );
		if ( $full_bytes > 0 && substr( $ip_bytes, 0, $full_bytes ) !== substr( $network_bytes, 0, $full_bytes ) ) {
			return false;
		}

		$remaining_bits = $bits % 8;
		if ( 0 === $remaining_bits ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $remaining_bits ) ) & 0xff;
		return ( ord( $ip_bytes[ $full_bytes ] ) & $mask ) === ( ord( $network_bytes[ $full_bytes ] ) & $mask );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function active_lockout_for( string $username ): ?array {
		$now     = $this->now();
		$targets = array(
			array(
				'type'  => 'ip',
				'value' => $this->current_ip_hash(),
			),
			array(
				'type'  => 'username',
				'value' => strtolower( $username ),
			),
		);

		foreach ( $targets as $target ) {
			foreach ( $this->table( 'lockouts' )->recent(
				1000,
				null,
				array(
					'target_type'  => $target['type'],
					'target_value' => $target['value'],
				)
			) as $row ) {
				if ( isset( $row['expires_at'] ) && is_numeric( $row['expires_at'] ) && (int) $row['expires_at'] > $now ) {
					return $row;
				}
			}
		}

		return null;
	}

	private function insert_lockout( string $type, string $value, string $username, int $minutes, string $rule_id, int $attempt_count ): void {
		if ( '' === $value || $minutes <= 0 ) {
			return;
		}

		$this->table( 'lockouts' )->insert(
			array(
				'target_type'   => $type,
				'target_value'  => $value,
				'locked_at'     => $this->now(),
				'expires_at'    => $this->now() + ( $minutes * 60 ),
				'attempt_count' => max( 1, $attempt_count ),
				'last_username' => strtolower( $username ),
				'rule_id'       => $rule_id,
			)
		);
	}

	private function failed_attempt_count(): int {
		$cutoff = $this->now() - ( $this->throttle_int( 'windowMinutes', 10 ) * 60 );
		$count  = 0;
		$hash   = $this->current_ip_hash();
		foreach ( $this->table( 'login_attempts' )->recent( 1000, null, array( 'ip_hash' => $hash ) ) as $row ) {
			if ( ! isset( $row['attempted_at'] ) || ! is_numeric( $row['attempted_at'] ) || (int) $row['attempted_at'] < $cutoff ) {
				continue;
			}
			if ( self::RESULT_ALLOWED !== ( $row['result'] ?? '' ) ) {
				++$count;
			}
		}

		return $count;
	}

	private function log_denial( string $username, string $result, ?string $rule_id ): void {
		$this->record_attempt( $username, $result, $rule_id );
		$this->logged_denials[ $this->attempt_key( $username ) ] = true;
	}

	private function record_attempt( string $username, string $result, ?string $rule_id = null ): void {
		$this->table( 'login_attempts' )->insert(
			array(
				'attempted_at' => $this->now(),
				'username'     => strtolower( $username ),
				'ip_hash'      => $this->current_ip_hash(),
				'user_agent'   => $this->current_user_agent(),
				'result'       => $result,
				'rule_id'      => $rule_id ?? '',
			)
		);
	}

	private function is_whitelisted( string $username ): bool {
		$whitelist = $this->setting( 'whitelist' );
		if ( ! is_array( $whitelist ) ) {
			return false;
		}

		return in_array( $this->current_ip(), $this->whitelist_values( $whitelist['ips'] ?? array() ), true )
			|| in_array( strtolower( $username ), array_map( 'strtolower', $this->whitelist_values( $whitelist['usernames'] ?? array() ) ), true );
	}

	/**
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private function whitelist_values( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$items[] = trim( $item );
				continue;
			}

			if ( is_array( $item ) && is_string( $item['value'] ?? null ) && '' !== trim( $item['value'] ) ) {
				$items[] = trim( $item['value'] );
			}
		}

		return $items;
	}

	private function throttle_enabled(): bool {
		$throttle = $this->setting( 'throttle' );
		return is_array( $throttle ) && true === ( $throttle['enabled'] ?? false );
	}

	private function throttle_int( string $key, int $default ): int {
		$throttle = $this->setting( 'throttle' );
		$value    = is_array( $throttle ) ? ( $throttle[ $key ] ?? null ) : null;
		return is_numeric( $value ) ? max( 1, (int) $value ) : $default;
	}

	private function current_ip(): string {
		$remote_addr = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return is_string( $remote_addr ) ? $remote_addr : '';
	}

	private function current_ip_hash(): string {
		return $this->ip_hash( $this->current_ip() );
	}

	private function current_user_agent(): string {
		$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		return substr( is_string( $user_agent ) ? $user_agent : '', 0, 255 );
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

	private function attempt_key( string $username ): string {
		return $this->current_ip_hash() . '|' . strtolower( $username );
	}

	private function username_from_user( mixed $user ): string {
		if ( ! is_object( $user ) ) {
			return '';
		}

		foreach ( array( 'user_login', 'login', 'user_nicename' ) as $property ) {
			$value = $user->{$property} ?? null;
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function login_error(): \WP_Error {
		return new \WP_Error( 'invalid_username', self::ERROR_MESSAGE );
	}


	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function attempt_for_display( array $row ): array {
		$timestamp = isset( $row['attempted_at'] ) && is_numeric( $row['attempted_at'] ) ? (int) $row['attempted_at'] : 0;
		return array(
			'id'               => (string) ( $row['id'] ?? '' ),
			'attemptedAt'      => $timestamp,
			'attemptedAtLabel' => $this->time_label( $timestamp ),
			'username'         => (string) ( $row['username'] ?? '' ),
			'ipDisplay'        => substr( (string) ( $row['ip_hash'] ?? '' ), 0, 12 ),
			'result'           => (string) ( $row['result'] ?? '' ),
			'resultLabel'      => $this->result_label( (string) ( $row['result'] ?? '' ) ),
			'userAgent'        => (string) ( $row['user_agent'] ?? '' ),
			'ruleId'           => (string) ( $row['rule_id'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function lockout_for_display( array $row ): array {
		$locked  = isset( $row['locked_at'] ) && is_numeric( $row['locked_at'] ) ? (int) $row['locked_at'] : 0;
		$expires = isset( $row['expires_at'] ) && is_numeric( $row['expires_at'] ) ? (int) $row['expires_at'] : 0;
		return array(
			'id'             => (string) ( $row['id'] ?? '' ),
			'target'         => 'ip' === ( $row['target_type'] ?? '' ) ? substr( (string) ( $row['target_value'] ?? '' ), 0, 12 ) : (string) ( $row['target_value'] ?? '' ),
			'targetType'     => (string) ( $row['target_type'] ?? '' ),
			'lockedAt'       => $locked,
			'lockedAtLabel'  => $this->time_label( $locked ),
			'expiresAt'      => $expires,
			'expiresAtLabel' => $this->time_label( $expires ),
			'attemptCount'   => (int) ( $row['attempt_count'] ?? 0 ),
			'lastUsername'   => (string) ( $row['last_username'] ?? '' ),
			'ruleId'         => (string) ( $row['rule_id'] ?? '' ),
		);
	}

	private function result_label( string $result ): string {
		return match ( $result ) {
			self::RESULT_ALLOWED => 'Allowed',
			self::RESULT_WRONG_PASSWORD => 'Wrong password',
			self::RESULT_BLOCKED => 'Blocked',
			self::RESULT_LOCKED_OUT => 'Locked out',
			self::RESULT_THROTTLED => 'Throttled',
			default => ucfirst( str_replace( '_', ' ', $result ) ),
		};
	}

	private function match_type_label( string $type ): string {
		return match ( $type ) {
			'ip' => 'IP address',
			'user_agent' => 'User agent',
			default => 'Username',
		};
	}

	/**
	 * @param array<string,mixed> $input Input.
	 */
	private function string_from( array $input, string $key, string $default = '' ): string {
		return is_string( $input[ $key ] ?? null ) ? trim( $input[ $key ] ) : $default;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 */
	private function bool_from( array $input, string $key, bool $default ): bool {
		return is_bool( $input[ $key ] ?? null ) ? (bool) $input[ $key ] : $default;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @param list<string>        $allowed Allowed values.
	 */
	private function allowed_string( array $input, string $key, array $allowed, string $default ): string {
		return $this->allowed_value( $input[ $key ] ?? $default, $allowed, $default );
	}

	/**
	 * @param list<string> $allowed Allowed values.
	 */
	private function allowed_value( mixed $value, array $allowed, string $default ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $default;
	}

	private function non_empty_string( mixed $value, string $default ): string {
		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : $default;
	}

	private function slug_from_label( string $label ): string {
		$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $label ) ?? '' );
		$slug = trim( $slug, '-' );
		return '' === $slug ? 'login-rule' : $slug;
	}

	/**
	 * @return list<string>
	 */
	private function day_seconds(): int {
		return defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
	}
}
