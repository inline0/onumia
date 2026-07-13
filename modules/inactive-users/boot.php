<?php
/**
 * Inactive Users module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\InactiveUsers;

use Onumia\Modules\Attributes\Action;
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
use Onumia\Modules\ModuleSettingsRepository;

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'enabled', SettingType::Boolean, default: false )]
#[Setting( 'thresholdDays', SettingType::Integer, default: 180, min: 1, max: 3650 )]
#[Setting( 'action', SettingType::String, default: 'disable', allowed: array( 'none', 'disable', 'delete' ) )]
#[Setting( 'gracePeriodDays', SettingType::Integer, default: 30, min: 0, max: 365 )]
#[Setting( 'excludeRoles', SettingType::Array, default: array( 'administrator' ) )]
#[Setting( 'exemptUserLogins', SettingType::Array, default: array() )]
#[Setting( 'reassignContentTo', SettingType::Integer, default: 0, min: 0 )]
final class InactiveUsers extends Module {
	public const META_LAST_LOGIN    = '_onumia_last_login';
	public const META_DISABLED_AT   = '_onumia_inactive_disabled_at';
	public const META_PREVIOUS_ROLE = '_onumia_inactive_previous_role';

	private const HOOK = 'onumia_inactive_users_scan';

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'inactiveUsers', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'inactiveUsers', singular: 'Inactive user', plural: 'Inactive users', key: 'id', storage: EntryStorage::Manual, source: 'inactiveUsers' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'userLogin', type: SettingType::String, label: 'User', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'role', type: SettingType::String, label: 'Role', filter: true, filter_type: 'option', optionsSource: array( 'source' => 'wp.user.roles' ), create: false, update: false, read_only: true )]
	#[EntryField( name: 'roleLabel', type: SettingType::String, label: 'Role', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastActivityAt', type: SettingType::Integer, label: 'Last activity timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'lastActivityLabel', type: SettingType::String, label: 'Last activity', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'daysInactive', type: SettingType::Integer, label: 'Days inactive', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'status', type: SettingType::String, label: 'Status', filter: true, filter_type: 'option', allowed: array( 'pending', 'disabled', 'scheduled' ), create: false, update: false, read_only: true )]
	#[EntryField( name: 'statusLabel', type: SettingType::String, label: 'Status', list: true, create: false, update: false, read_only: true )]
	public function inactive_users( array $params ): array {
		$rows = array_map( array( $this, 'inactive_user_for_display' ), $this->inactive_user_objects() );
		return $this->paginated_rows( $rows, $params );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'inactiveUserActions', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'inactiveUserActions', singular: 'Inactive action', plural: 'Inactive actions', key: 'id', storage: EntryStorage::Table, source: 'inactiveUserActions', table: 'actions' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'occurredAt', type: SettingType::Integer, label: 'Timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'occurredAtLabel', type: SettingType::String, label: 'Time', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'userId', type: SettingType::Integer, label: 'User ID', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'userLogin', type: SettingType::String, label: 'User', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'action', type: SettingType::String, label: 'Action', filter: true, filter_type: 'option', allowed: array( 'disabled', 'deleted', 're_enabled' ), create: false, update: false, read_only: true )]
	#[EntryField( name: 'actionLabel', type: SettingType::String, label: 'Action', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'reason', type: SettingType::String, label: 'Reason', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'previousRole', type: SettingType::String, label: 'Previous role', filter: true, filter_type: 'option', optionsSource: array( 'source' => 'wp.user.roles' ), create: false, update: false, read_only: true )]
	#[EntryField( name: 'previousRoleLabel', type: SettingType::String, label: 'Previous role', list: true, create: false, update: false, read_only: true )]
	public function actions( array $params ): array {
		$rows = array_reverse( $this->table( 'actions' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'action_for_display' ), $rows ), $params );
	}

	public function boot(): void {
		$this->sync_schedule();
	}

	public function settings_updated(): void {
		$this->sync_schedule();
	}

	/**
	 * @param mixed $user User object.
	 */
	#[WpAction( 'wp_login', priority: 10, accepted_args: 2 )]
	public function record_login( string $user_login, mixed $user = null ): void {
		$user_id = $this->user_id( $user );
		if ( $user_id <= 0 && '' !== trim( $user_login ) && function_exists( 'get_user_by' ) ) {
			$found = \get_user_by( 'login', $user_login );
			$user_id = $this->user_id( is_object( $found ) ? $found : null );
		}

		if ( $user_id > 0 ) {
			$this->update_user_meta( $user_id, self::META_LAST_LOGIN, $this->now() );
		}
	}

	/**
	 * @return array{checked:int,disabled:int,deleted:int}
	 */
	#[Action( 'scanInactiveUsers' )]
	#[WpAction( 'onumia_inactive_users_scan' )]
	public function scan_inactive_users(): array {
		if ( ! $this->enabled() || 'none' === $this->scan_action() ) {
			return array(
				'checked'  => 0,
				'disabled' => 0,
				'deleted'  => 0,
			);
		}

		$checked  = 0;
		$disabled = 0;
		$deleted  = 0;

		foreach ( $this->inactive_user_objects() as $user ) {
			++$checked;
			$result   = $this->apply_action_to_user( $user );
			$disabled += $result['disabled'];
			$deleted  += $result['deleted'];
		}

		return array(
			'checked'  => $checked,
			'disabled' => $disabled,
			'deleted'  => $deleted,
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{exempted:int}
	 */
	#[Action( 'exemptUsers' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function exempt_users( array $input ): array {
		$logins = array();
		foreach ( $this->input_ids( $input ) as $user_id ) {
			$user = $this->get_user( $user_id );
			if ( null === $user ) {
				continue;
			}

			$login = $this->user_property( $user, 'user_login' );
			if ( '' !== $login ) {
				$logins[] = $login;
			}
		}

		$logins = array_values( array_unique( $logins ) );
		if ( array() === $logins ) {
			return array( 'exempted' => 0 );
		}

		( new ModuleSettingsRepository() )->update_settings_with(
			$this->definition(),
			function ( array $settings ) use ( $logins ): array {
				$current = $this->exempt_login_values( $settings['exemptUserLogins'] ?? array() );
				foreach ( $logins as $login ) {
					$current[] = $login;
				}

				return array(
					'exemptUserLogins' => array_map(
						static fn( string $login ): array => array( 'value' => $login ),
						array_values( array_unique( $current ) )
					),
				);
			}
		);

		return array( 'exempted' => count( $logins ) );
	}

	private function sync_schedule(): void {
		if ( ! $this->enabled() ) {
			$this->clear_schedule();
			return;
		}

		if ( false !== $this->next_scheduled( self::HOOK ) ) {
			return;
		}

		if ( function_exists( 'wp_schedule_event' ) ) {
			\wp_schedule_event( $this->now() + 3600, 'daily', self::HOOK );
		}
	}

	private function clear_schedule(): void {
		if ( ! function_exists( 'wp_unschedule_event' ) ) {
			return;
		}

		while ( false !== ( $timestamp = $this->next_scheduled( self::HOOK ) ) ) {
			if ( ! \wp_unschedule_event( $timestamp, self::HOOK ) ) {
				return;
			}
		}
	}

	private function next_scheduled( string $hook ): int|false {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return false;
		}

		$timestamp = \wp_next_scheduled( $hook );
		return is_numeric( $timestamp ) ? (int) $timestamp : false;
	}

	private function threshold_days(): int {
		$value = $this->setting( 'thresholdDays' );
		return is_int( $value ) ? max( 1, min( 3650, $value ) ) : 180;
	}

	private function grace_period_days(): int {
		$value = $this->setting( 'gracePeriodDays' );
		return is_int( $value ) ? max( 0, min( 365, $value ) ) : 30;
	}

	private function scan_action(): string {
		$action = $this->setting( 'action' );
		return is_string( $action ) && in_array( $action, array( 'none', 'disable', 'delete' ), true ) ? $action : 'disable';
	}

	private function reassign_content_to(): ?int {
		$value = $this->setting( 'reassignContentTo' );
		if ( ! is_int( $value ) || $value <= 0 ) {
			return null;
		}

		return $value;
	}

	/**
	 * @return list<string>
	 */
	private function excluded_roles(): array {
		return $this->string_values( $this->setting( 'excludeRoles' ) );
	}

	/**
	 * @return list<string>
	 */
	private function exempt_logins(): array {
		return array_map( 'strtolower', $this->exempt_login_values( $this->setting( 'exemptUserLogins' ) ) );
	}

	/**
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private function exempt_login_values( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$logins = array();
		foreach ( $value as $item ) {
			$login = '';
			if ( is_string( $item ) ) {
				$login = $item;
			} elseif ( is_array( $item ) && is_string( $item['value'] ?? null ) ) {
				$login = $item['value'];
			}

			$login = trim( $login );
			if ( '' !== $login ) {
				$logins[] = $login;
			}
		}

		return array_values( array_unique( $logins ) );
	}

	/**
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private function string_values( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$values = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$values[] = trim( $item );
			}
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * @return list<object>
	 */
	private function inactive_user_objects(): array {
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}

		$users = \get_users(
			array(
				'number'  => 500,
				'orderby' => 'registered',
				'order'   => 'ASC',
			)
		);
		if ( ! is_array( $users ) ) {
			return array();
		}

		$excluded_roles = $this->excluded_roles();
		$exempt_logins  = $this->exempt_logins();
		$inactive       = array();

		foreach ( $users as $user ) {
			if ( ! is_object( $user ) ) {
				continue;
			}

			$user_id = $this->user_id( $user );
			if ( $user_id <= 0 || $this->days_inactive( $user ) <= $this->threshold_days() ) {
				continue;
			}

			$login = strtolower( $this->user_property( $user, 'user_login' ) );
			if ( '' !== $login && in_array( $login, $exempt_logins, true ) ) {
				continue;
			}

			$role = $this->primary_role( $user );
			if ( '' !== $role && in_array( $role, $excluded_roles, true ) ) {
				continue;
			}

			$inactive[] = $user;
		}

		return $inactive;
	}

	/**
	 * @param object $user User.
	 * @return array{id:string,userLogin:string,role:string,roleLabel:string,lastActivityAt:int,lastActivityLabel:string,daysInactive:int,status:string,statusLabel:string}
	 */
	private function inactive_user_for_display( object $user ): array {
		$status = $this->status_for_user( $user );
		$role   = $this->primary_role( $user );

		return array(
			'id'                => (string) $this->user_id( $user ),
			'userLogin'         => $this->user_property( $user, 'user_login' ),
			'role'              => $role,
			'roleLabel'         => $this->role_label( $role ),
			'lastActivityAt'    => $this->last_activity_at( $user ),
			'lastActivityLabel' => $this->date_label( $this->last_activity_at( $user ) ),
			'daysInactive'      => $this->days_inactive( $user ),
			'status'            => $status,
			'statusLabel'       => $this->status_label( $status ),
		);
	}

	/**
	 * @param object $user User.
	 * @return array{disabled:int,deleted:int}
	 */
	private function apply_action_to_user( object $user ): array {
		return match ( $this->scan_action() ) {
			'delete' => $this->delete_or_stage_user( $user ),
			'disable' => $this->disable_user( $user ),
			default => array(
				'disabled' => 0,
				'deleted'  => 0,
			),
		};
	}

	/**
	 * @param object $user User.
	 * @return array{disabled:int,deleted:int}
	 */
	private function disable_user( object $user ): array {
		$user_id = $this->user_id( $user );
		if ( $user_id <= 0 || $this->disabled_at( $user_id ) > 0 ) {
			return array(
				'disabled' => 0,
				'deleted'  => 0,
			);
		}

		$role = $this->primary_role( $user );
		$this->update_user_meta( $user_id, self::META_DISABLED_AT, $this->now() );
		$this->update_user_meta( $user_id, self::META_PREVIOUS_ROLE, $role );
		$this->set_user_role( $user_id, '' );
		$this->log_action( $user_id, $this->user_property( $user, 'user_login' ), 'disabled', $this->inactive_reason( $user ), $role );

		return array(
			'disabled' => 1,
			'deleted'  => 0,
		);
	}

	/**
	 * @param object $user User.
	 * @return array{disabled:int,deleted:int}
	 */
	private function delete_or_stage_user( object $user ): array {
		$user_id     = $this->user_id( $user );
		$disabled_at = $this->disabled_at( $user_id );
		if ( $user_id <= 0 || $disabled_at <= 0 ) {
			return $this->disable_user( $user );
		}

		if ( $this->now() - $disabled_at < $this->grace_period_days() * DAY_IN_SECONDS ) {
			return array(
				'disabled' => 0,
				'deleted'  => 0,
			);
		}

		$login         = $this->user_property( $user, 'user_login' );
		$previous_role = $this->previous_role( $user_id );
		if ( function_exists( 'wp_delete_user' ) && true === \wp_delete_user( $user_id, $this->reassign_content_to() ) ) {
			$this->log_action( $user_id, $login, 'deleted', $this->inactive_reason( $user ), $previous_role );
			return array(
				'disabled' => 0,
				'deleted'  => 1,
			);
		}

		return array(
			'disabled' => 0,
			'deleted'  => 0,
		);
	}

	private function status_for_user( object $user ): string {
		$user_id = $this->user_id( $user );
		if ( $this->disabled_at( $user_id ) <= 0 ) {
			return 'pending';
		}

		return 'delete' === $this->scan_action() ? 'scheduled' : 'disabled';
	}

	private function status_label( string $status ): string {
		return match ( $status ) {
			'disabled' => 'Disabled',
			'scheduled' => 'Scheduled for deletion',
			default => 'Pending action',
		};
	}

	private function action_label( string $action ): string {
		return match ( $action ) {
			'disabled' => 'Disabled',
			'deleted' => 'Deleted',
			're_enabled' => 'Re-enabled',
			default => ucfirst( str_replace( '_', ' ', $action ) ),
		};
	}

	private function inactive_reason( object $user ): string {
		return 'Inactive for ' . $this->days_inactive( $user ) . ' days';
	}

	private function last_activity_at( object $user ): int {
		$user_id = $this->user_id( $user );
		$meta    = $this->user_meta( $user_id, self::META_LAST_LOGIN );
		if ( is_numeric( $meta ) && (int) $meta > 0 ) {
			return (int) $meta;
		}

		$registered = $this->user_property( $user, 'user_registered' );
		if ( '' !== $registered ) {
			$timestamp = strtotime( $registered . ' UTC' );
			return false === $timestamp ? 0 : (int) $timestamp;
		}

		return 0;
	}

	private function days_inactive( object $user ): int {
		$last = $this->last_activity_at( $user );
		if ( $last <= 0 ) {
			return 0;
		}

		return max( 0, (int) floor( ( $this->now() - $last ) / DAY_IN_SECONDS ) );
	}

	private function disabled_at( int $user_id ): int {
		$value = $this->user_meta( $user_id, self::META_DISABLED_AT );
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	private function previous_role( int $user_id ): string {
		$value = $this->user_meta( $user_id, self::META_PREVIOUS_ROLE );
		return is_string( $value ) ? $value : '';
	}

	private function primary_role( object $user ): string {
		$roles = $user->roles ?? null;
		if ( is_array( $roles ) ) {
			foreach ( $roles as $role ) {
				if ( is_string( $role ) && '' !== $role ) {
					return $role;
				}
			}
		}

		$previous = $this->previous_role( $this->user_id( $user ) );
		return '' !== $previous ? $previous : '';
	}

	private function role_label( string $role ): string {
		if ( '' === $role ) {
			return 'None';
		}

		if ( function_exists( 'wp_roles' ) ) {
			$roles = \wp_roles()->roles;
			if ( is_array( $roles ) && is_string( $roles[ $role ]['name'] ?? null ) ) {
				return $roles[ $role ]['name'];
			}
		}

		return ucfirst( str_replace( '_', ' ', $role ) );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function action_for_display( array $row ): array {
		$timestamp     = isset( $row['occurred_at'] ) && is_numeric( $row['occurred_at'] ) ? (int) $row['occurred_at'] : 0;
		$action        = (string) ( $row['action'] ?? '' );
		$previous_role = (string) ( $row['previous_role'] ?? '' );

		return array(
			'id'                => (string) ( $row['id'] ?? '' ),
			'occurredAt'        => $timestamp,
			'occurredAtLabel'   => $this->date_label( $timestamp ),
			'userId'            => isset( $row['user_id'] ) && is_numeric( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'userLogin'         => (string) ( $row['user_login'] ?? '' ),
			'action'            => $action,
			'actionLabel'       => $this->action_label( $action ),
			'reason'            => (string) ( $row['reason'] ?? '' ),
			'previousRole'      => $previous_role,
			'previousRoleLabel' => $this->role_label( $previous_role ),
		);
	}

	private function log_action( int $user_id, string $user_login, string $action, string $reason, string $previous_role ): void {
		$this->table( 'actions' )->insert(
			array(
				'occurred_at'   => $this->now(),
				'user_id'       => $user_id,
				'user_login'    => substr( $user_login, 0, 60 ),
				'action'        => $action,
				'reason'        => substr( $reason, 0, 255 ),
				'previous_role' => '' === $previous_role ? null : $previous_role,
			)
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return list<int>
	 */
	private function input_ids( array $input ): array {
		$ids        = is_array( $input['ids'] ?? null ) ? $input['ids'] : array();
		$normalized = array();
		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$normalized[] = (int) $id;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	private function get_user( int $user_id ): ?object {
		if ( function_exists( 'get_userdata' ) ) {
			$user = \get_userdata( $user_id );
			if ( is_object( $user ) ) {
				return $user;
			}
		}

		if ( function_exists( 'get_user_by' ) ) {
			$user = \get_user_by( 'id', (string) $user_id );
			if ( is_object( $user ) ) {
				return $user;
			}
		}

		return null;
	}

	private function user_id( mixed $user ): int {
		if ( is_numeric( $user ) ) {
			return (int) $user;
		}

		if ( is_array( $user ) && is_numeric( $user['ID'] ?? null ) ) {
			return (int) $user['ID'];
		}

		if ( is_object( $user ) && is_numeric( $user->ID ?? null ) ) {
			return (int) $user->ID;
		}

		return 0;
	}

	private function user_property( object $user, string $property ): string {
		$value = $user->{$property} ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}

	private function user_meta( int $user_id, string $key ): mixed {
		if ( ! function_exists( 'get_user_meta' ) ) {
			return '';
		}

		return \get_user_meta( $user_id, $key, true );
	}

	private function update_user_meta( int $user_id, string $key, mixed $value ): void {
		if ( function_exists( 'update_user_meta' ) ) {
			\update_user_meta( $user_id, $key, $value );
		}
	}

	private function set_user_role( int $user_id, string $role ): void {
		$user = $this->get_user( $user_id );
		if ( is_object( $user ) && method_exists( $user, 'set_role' ) ) {
			$user->set_role( $role );
			return;
		}

		if ( class_exists( '\WP_User' ) ) {
			$wp_user = new \WP_User( $user_id );
			if ( method_exists( $wp_user, 'set_role' ) ) {
				$wp_user->set_role( $role );
			}
		}
	}

	private function date_label( int $timestamp ): string {
		return $this->time_label( $timestamp );
	}
}
