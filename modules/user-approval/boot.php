<?php
/**
 * User Approval module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\UserApproval;

use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
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

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'enabled', SettingType::Boolean, default: false )]
#[Setting( 'defaultRoleOnApproval', SettingType::String, default: 'subscriber' )]
#[Setting( 'autoApproveDomains', SettingType::Array, default: array() )]
#[Setting( 'notifyUserOnApproval', SettingType::Boolean, default: true )]
#[Setting( 'notifyUserOnRejection', SettingType::Boolean, default: false )]
#[Setting( 'deleteOnRejection', SettingType::Boolean, default: false )]
#[Setting( 'notifyAdminOnPending', SettingType::Boolean, default: true )]
final class UserApproval extends Module {
	public const META_STATUS         = '_onumia_approval_status';
	public const META_DECIDED_AT     = '_onumia_approval_decided_at';
	public const META_DECIDED_BY     = '_onumia_approval_decided_by';
	public const META_REQUESTED_ROLE = '_onumia_approval_requested_role';

	private const STATUS_PENDING  = 'pending';
	private const STATUS_APPROVED = 'approved';
	private const STATUS_REJECTED = 'rejected';

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'pendingUsers', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'pendingUsers', singular: 'Pending user', plural: 'Pending users', key: 'id', storage: EntryStorage::Manual, source: 'pendingUsers', delete_action: 'rejectUsers', destructive_mode: 'deactivate' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'userLogin', type: SettingType::String, label: 'Username', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'email', type: SettingType::String, label: 'Email', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'registeredAt', type: SettingType::Integer, label: 'Registered timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'registeredLabel', type: SettingType::String, label: 'Registered', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'requestedRole', type: SettingType::String, label: 'Requested role', filter: true, filter_type: 'option', optionsSource: array( 'source' => 'wp.user.roles' ), create: false, update: false, read_only: true )]
	#[EntryField( name: 'requestedRoleLabel', type: SettingType::String, label: 'Requested role', list: true, create: false, update: false, read_only: true )]
	public function pending_users( array $params ): array {
		return $this->paginated_rows( array_map( array( $this, 'user_for_display' ), $this->pending_user_objects() ), $params );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{approved:int}
	 */
	#[Action( 'approveUsers' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function approve_users( array $input ): array {
		$approved = 0;
		foreach ( $this->input_ids( $input ) as $user_id ) {
			if ( ! $this->user_exists( $user_id ) ) {
				continue;
			}

			$this->set_status( $user_id, self::STATUS_APPROVED );
			$this->set_decision_meta( $user_id );
			$this->set_user_role( $user_id, $this->default_role() );
			$this->notify_user( $user_id, 'approval' );
			$this->audit( 'user_approval.approved', $user_id );
			++$approved;
		}

		return array( 'approved' => $approved );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{rejected:int,deleted:int}
	 */
	#[Action( 'rejectUsers' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function reject_users( array $input ): array {
		$rejected = 0;
		$deleted  = 0;
		foreach ( $this->input_ids( $input ) as $user_id ) {
			if ( ! $this->user_exists( $user_id ) ) {
				continue;
			}

			$this->set_status( $user_id, self::STATUS_REJECTED );
			$this->set_decision_meta( $user_id );
			$this->notify_user( $user_id, 'rejection' );
			$this->audit( 'user_approval.rejected', $user_id );
			++$rejected;

			if ( true === $this->setting( 'deleteOnRejection' ) && $this->delete_user( $user_id ) ) {
				++$deleted;
			}
		}

		return array(
			'rejected' => $rejected,
			'deleted'  => $deleted,
		);
	}

	#[WpAction( 'user_register', priority: 10, accepted_args: 1 )]
	public function handle_user_register( int $user_id ): void {
		if ( ! $this->enabled() || ! $this->user_exists( $user_id ) ) {
			return;
		}

		$requested_role = $this->requested_role( $user_id );
		$this->update_user_meta( $user_id, self::META_REQUESTED_ROLE, $requested_role );

		if ( $this->email_auto_approved( $this->user_email( $user_id ) ) ) {
			$this->set_status( $user_id, self::STATUS_APPROVED );
			$this->set_user_role( $user_id, $this->default_role() );
			$this->audit( 'user_approval.auto_approved', $user_id );
			return;
		}

		$this->set_status( $user_id, self::STATUS_PENDING );
		$this->set_user_role( $user_id, '' );
		$this->notify_admin_pending( $user_id );
		$this->audit( 'user_approval.pending', $user_id );
	}

	#[WpFilter( 'wp_authenticate_user', priority: 10, accepted_args: 2 )]
	public function authenticate_user( mixed $user, string $password = '' ): mixed {
		unset( $password );

		if ( ! $this->enabled() || $this->is_wp_error( $user ) ) {
			return $user;
		}

		$user_id = $this->user_id( $user );
		if ( $user_id <= 0 ) {
			return $user;
		}

		$status = $this->status( $user_id );
		if ( self::STATUS_PENDING === $status ) {
			return $this->wp_error( 'onumia_user_approval_pending', 'Your account is waiting for approval.' );
		}

		if ( self::STATUS_REJECTED === $status ) {
			return $this->wp_error( 'onumia_user_approval_rejected', 'Your account registration was rejected.' );
		}

		return $user;
	}

	#[WpFilter( 'registration_errors', priority: 10, accepted_args: 3 )]
	public function registration_errors( mixed $errors, string $sanitized_user_login = '', string $user_email = '' ): mixed {
		unset( $sanitized_user_login, $user_email );
		return $errors;
	}

	/**
	 * @return list<object>
	 */
	private function pending_user_objects(): array {
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}

		$users = \get_users(
			array(
				'number'  => 500,
				'orderby' => 'registered',
				'order'   => 'DESC',
			)
		);

		if ( ! is_array( $users ) ) {
			return array();
		}

		$pending = array();
		foreach ( $users as $user ) {
			$user_id = $this->user_id( $user );
			if ( $user_id > 0 && self::STATUS_PENDING === $this->status( $user_id ) ) {
				$pending[] = (object) $user;
			}
		}

		return $pending;
	}

	/**
	 * @param object $user User.
	 * @return array<string,mixed>
	 */
	private function user_for_display( object $user ): array {
		$user_id        = $this->user_id( $user );
		$registered_raw = $this->user_property( $user, 'user_registered' );
		$registered_at  = '' !== $registered_raw ? (int) strtotime( $registered_raw ) : 0;
		$role           = $this->requested_role_meta( $user_id );

		return array(
			'id'                 => (string) $user_id,
			'userLogin'          => $this->user_property( $user, 'user_login' ),
			'email'              => $this->user_property( $user, 'user_email' ),
			'registeredAt'       => $registered_at,
			'registeredLabel'    => $this->date_label( $registered_at ),
			'requestedRole'      => $role,
			'requestedRoleLabel' => $this->role_label( $role ),
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @param list<array<string,mixed>> $rows Rows.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	private function default_role(): string {
		$role = $this->setting( 'defaultRoleOnApproval' );
		return is_string( $role ) && '' !== trim( $role ) ? trim( $role ) : 'subscriber';
	}

	private function requested_role( int $user_id ): string {
		$user = $this->get_user( $user_id );
		if ( is_object( $user ) ) {
			$roles = $user->roles ?? null;
			if ( is_array( $roles ) && isset( $roles[0] ) && is_string( $roles[0] ) && '' !== $roles[0] ) {
				return $roles[0];
			}
		}

		return $this->default_role();
	}

	private function requested_role_meta( int $user_id ): string {
		$role = $this->user_meta( $user_id, self::META_REQUESTED_ROLE );
		return is_string( $role ) && '' !== $role ? $role : $this->default_role();
	}

	private function email_auto_approved( string $email ): bool {
		$parts = explode( '@', strtolower( trim( $email ) ) );
		$domain = $parts[1] ?? '';
		if ( '' === $domain ) {
			return false;
		}

		return in_array( $domain, $this->domain_values( $this->setting( 'autoApproveDomains' ) ), true );
	}

	/**
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private function domain_values( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$domains = array();
		foreach ( $value as $item ) {
			$domain = '';
			if ( is_string( $item ) ) {
				$domain = $item;
			} elseif ( is_array( $item ) && is_string( $item['value'] ?? null ) ) {
				$domain = $item['value'];
			}

			$domain = strtolower( trim( $domain ) );
			if ( '' !== $domain ) {
				$domains[] = ltrim( $domain, '@' );
			}
		}

		return array_values( array_unique( $domains ) );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return list<int>
	 */
	private function input_ids( array $input ): array {
		$ids = is_array( $input['ids'] ?? null ) ? $input['ids'] : array();
		$normalized = array();
		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$normalized[] = (int) $id;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	private function status( int $user_id ): string {
		$status = $this->user_meta( $user_id, self::META_STATUS );
		return is_string( $status ) ? $status : '';
	}

	private function set_status( int $user_id, string $status ): void {
		$this->update_user_meta( $user_id, self::META_STATUS, $status );
	}

	private function set_decision_meta( int $user_id ): void {
		$this->update_user_meta( $user_id, self::META_DECIDED_AT, $this->now() );
		$this->update_user_meta( $user_id, self::META_DECIDED_BY, $this->current_user_id() );
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

	private function delete_user( int $user_id ): bool {
		if ( function_exists( 'wp_delete_user' ) ) {
			return true === \wp_delete_user( $user_id );
		}

		return false;
	}

	private function notify_user( int $user_id, string $event ): void {
		if ( 'approval' === $event && true !== $this->setting( 'notifyUserOnApproval' ) ) {
			return;
		}

		if ( 'rejection' === $event && true !== $this->setting( 'notifyUserOnRejection' ) ) {
			return;
		}

		$email = $this->user_email( $user_id );
		if ( '' === $email || ! function_exists( 'wp_mail' ) ) {
			return;
		}

		$approved = 'approval' === $event;
		\wp_mail(
			$email,
			$approved ? 'Your account has been approved' : 'Your account registration was rejected',
			$approved ? 'Your account is approved and ready to use.' : 'Your account registration was rejected.'
		);
	}

	private function notify_admin_pending( int $user_id ): void {
		if ( true !== $this->setting( 'notifyAdminOnPending' ) || ! function_exists( 'wp_mail' ) ) {
			return;
		}

		$admin_email = function_exists( 'get_option' ) ? \get_option( 'admin_email', '' ) : '';
		if ( ! is_string( $admin_email ) || '' === $admin_email ) {
			return;
		}

		\wp_mail( $admin_email, 'New user registration pending approval', 'A new user registration is waiting for approval.' );
	}

	private function audit( string $event, int $user_id ): void {
		if ( function_exists( 'do_action' ) ) {
			\do_action(
				'onumia_activity_log_record',
				array(
					'event'   => $event,
					'entity'  => 'user',
					'userId'  => $user_id,
					'actorId' => $this->current_user_id(),
				)
			);
		}
	}

	private function user_exists( int $user_id ): bool {
		return null !== $this->get_user( $user_id );
	}

	private function get_user( int $user_id ): ?object {
		if ( function_exists( 'get_user_by' ) ) {
			$user = \get_user_by( 'id', (string) $user_id );
			if ( is_object( $user ) ) {
				return $user;
			}
		}

		if ( function_exists( 'get_userdata' ) ) {
			$user = \get_userdata( $user_id );
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

	private function user_email( int $user_id ): string {
		$user = $this->get_user( $user_id );
		return is_object( $user ) ? $this->user_property( $user, 'user_email' ) : '';
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

	private function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
	}

	private function date_label( int $timestamp ): string {
		return $this->time_label( $timestamp );
	}

	private function role_label( string $role ): string {
		if ( function_exists( 'wp_roles' ) ) {
			$roles = \wp_roles()->roles;
			if ( is_array( $roles ) && is_string( $roles[ $role ]['name'] ?? null ) ) {
				return $roles[ $role ]['name'];
			}
		}

		return '' === $role ? 'None' : ucfirst( str_replace( '_', ' ', $role ) );
	}

	private function is_wp_error( mixed $value ): bool {
		return function_exists( 'is_wp_error' ) ? \is_wp_error( $value ) : ( class_exists( '\WP_Error' ) && $value instanceof \WP_Error );
	}

	private function wp_error( string $code, string $message ): mixed {
		return class_exists( '\WP_Error' ) ? new \WP_Error( $code, $message ) : $message;
	}
}
