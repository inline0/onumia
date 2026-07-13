<?php
/**
 * Activity Log module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\ActivityLog;

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
#[Setting( 'trackedGroups', SettingType::Array, default: array( 'auth', 'posts', 'users', 'plugins', 'themes', 'options' ) )]
#[Setting( 'trackedOptions', SettingType::Array, default: array( 'siteurl', 'home', 'admin_email', 'template', 'stylesheet', 'users_can_register', 'default_role' ) )]
#[Setting( 'retentionDays', SettingType::Integer, default: 90, min: 1, max: 365 )]
final class ActivityLog extends Module {
	private const GROUP_AUTH    = 'auth';
	private const GROUP_POSTS   = 'posts';
	private const GROUP_USERS   = 'users';
	private const GROUP_PLUGINS = 'plugins';
	private const GROUP_THEMES  = 'themes';
	private const GROUP_OPTIONS = 'options';

	private const GROUPS = array(
		self::GROUP_AUTH,
		self::GROUP_POSTS,
		self::GROUP_USERS,
		self::GROUP_PLUGINS,
		self::GROUP_THEMES,
		self::GROUP_OPTIONS,
	);

	private const OPTION_ALLOWLIST = array(
		'admin_email',
		'default_role',
		'home',
		'siteurl',
		'stylesheet',
		'template',
		'users_can_register',
	);

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'activityLogEvents', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'activityLogEvents', singular: 'Event', plural: 'Events', key: 'id', storage: EntryStorage::Table, source: 'activityLogEvents', table: 'events' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'occurredAt', type: SettingType::Integer, label: 'Timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'occurredAtLabel', type: SettingType::String, label: 'Time', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'group', type: SettingType::String, label: 'Group', allowed: array( 'auth', 'posts', 'users', 'plugins', 'themes', 'options' ), filter: true, filter_type: 'option', create: false, update: false, read_only: true )]
	#[EntryField( name: 'groupLabel', type: SettingType::String, label: 'Group label', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'event', type: SettingType::String, label: 'Event', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'eventLabel', type: SettingType::String, label: 'Event label', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'actorType', type: SettingType::String, label: 'Actor type', create: false, update: false, read_only: true )]
	#[EntryField( name: 'actorId', type: SettingType::Integer, label: 'Actor ID', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'actorLogin', type: SettingType::String, label: 'Actor login', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'actor', type: SettingType::String, label: 'Actor', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'targetType', type: SettingType::String, label: 'Target type', create: false, update: false, read_only: true )]
	#[EntryField( name: 'targetId', type: SettingType::String, label: 'Target ID', create: false, update: false, read_only: true )]
	#[EntryField( name: 'target', type: SettingType::String, label: 'Target', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'summary', type: SettingType::String, label: 'Summary', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'payloadJson', type: SettingType::String, label: 'Payload JSON', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	public function events( array $params ): array {
		$rows = array_reverse( $this->table( 'events' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'event_for_display' ), $rows ), $params );
	}

	/**
	 * @param mixed $user User object.
	 */
	#[WpAction( 'wp_login', priority: 10, accepted_args: 2 )]
	public function record_login( string $user_login, mixed $user = null ): void {
		$actor = $this->actor_from_user( $user );
		if ( null === $actor && '' !== trim( $user_login ) ) {
			$actor = array(
				'actor_type'  => 'user',
				'actor_id'    => null,
				'actor_login' => trim( $user_login ),
			);
		}

		$this->record(
			self::GROUP_AUTH,
			'login',
			$actor,
			null,
			$user_login,
			"User {$this->safe_summary_value( $user_login, 'unknown' )} logged in.",
			array( 'user_login' => $user_login )
		);
	}

	#[WpAction( 'wp_login_failed', priority: 10, accepted_args: 1 )]
	public function record_login_failed( string $username ): void {
		$this->record(
			self::GROUP_AUTH,
			'login_failed',
			$this->system_actor(),
			null,
			$username,
			"Failed login for {$this->safe_summary_value( $username, 'unknown user' )}.",
			array( 'user_login' => $username )
		);
	}

	#[WpAction( 'wp_logout', priority: 10, accepted_args: 0 )]
	public function record_logout(): void {
		$actor = $this->current_actor();
		$this->record(
			self::GROUP_AUTH,
			'logout',
			$actor,
			null,
			$actor['actor_login'] ?? 'system',
			"User {$this->safe_summary_value( (string) ( $actor['actor_login'] ?? '' ), 'system' )} logged out.",
			array()
		);
	}

	/**
	 * @param mixed $user User object.
	 */
	#[WpAction( 'password_reset', priority: 10, accepted_args: 2 )]
	public function record_password_reset( mixed $user, string $new_pass = '' ): void {
		unset( $new_pass );
		$target = $this->user_target( $user );
		$this->record(
			self::GROUP_AUTH,
			'password_reset',
			$this->current_actor(),
			'user',
			$target['id'],
			"Password reset for {$this->safe_summary_value( $target['label'], 'user' )}.",
			array( 'user_login' => $target['label'] )
		);
	}

	/**
	 * @param mixed $post Post object.
	 */
	#[WpAction( 'transition_post_status', priority: 10, accepted_args: 3 )]
	public function record_post_status_transition( string $new_status, string $old_status, mixed $post = null ): void {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			$this->record_post_event( 'post_published', $post, 'Published' );
			return;
		}

		if ( 'publish' === $old_status ) {
			$this->record_post_event( 'post_unpublished', $post, 'Unpublished' );
		}
	}

	#[WpAction( 'wp_trash_post', priority: 10, accepted_args: 1 )]
	public function record_post_trashed( int $post_id ): void {
		$this->record_post_event( 'post_trashed', $post_id, 'Trashed' );
	}

	#[WpAction( 'before_delete_post', priority: 10, accepted_args: 1 )]
	public function record_post_deleted( int $post_id ): void {
		$this->record_post_event( 'post_deleted', $post_id, 'Deleted' );
	}

	#[WpAction( 'user_register', priority: 10, accepted_args: 1 )]
	public function record_user_created( int $user_id ): void {
		$user = function_exists( 'get_userdata' ) ? \get_userdata( $user_id ) : false;
		$this->record_user_event( 'user_created', $user_id, $user, 'Created' );
	}

	/**
	 * @param mixed $reassign Reassigned user.
	 * @param mixed $user     User object.
	 */
	#[WpAction( 'delete_user', priority: 10, accepted_args: 3 )]
	public function record_user_deleted( int $user_id, mixed $reassign = null, mixed $user = null ): void {
		unset( $reassign );
		$this->record_user_event( 'user_deleted', $user_id, $user, 'Deleted' );
	}

	/**
	 * @param list<string> $old_roles Old roles.
	 */
	#[WpAction( 'set_user_role', priority: 10, accepted_args: 3 )]
	public function record_user_role_changed( int $user_id, string $role, array $old_roles = array() ): void {
		$user = function_exists( 'get_userdata' ) ? \get_userdata( $user_id ) : false;
		$this->record_user_event(
			'user_role_changed',
			$user_id,
			$user,
			'Changed role for',
			array(
				'new_role'  => $role,
				'old_roles' => array_values( $old_roles ),
			)
		);
	}

	#[WpAction( 'activated_plugin', priority: 10, accepted_args: 1 )]
	public function record_plugin_activated( string $plugin ): void {
		$this->record_plugin_event( 'plugin_activated', $plugin, 'Activated plugin' );
	}

	#[WpAction( 'deactivated_plugin', priority: 10, accepted_args: 1 )]
	public function record_plugin_deactivated( string $plugin ): void {
		$this->record_plugin_event( 'plugin_deactivated', $plugin, 'Deactivated plugin' );
	}

	/**
	 * @param mixed $new_theme New theme.
	 * @param mixed $old_theme Old theme.
	 */
	#[WpAction( 'switch_theme', priority: 10, accepted_args: 3 )]
	public function record_theme_switched( string $new_name, mixed $new_theme = null, mixed $old_theme = null ): void {
		$old_name = $this->theme_name( $old_theme );
		$this->record(
			self::GROUP_THEMES,
			'theme_switched',
			$this->current_actor(),
			'theme',
			$new_name,
			"Switched theme to {$this->safe_summary_value( $new_name, 'unknown theme' )}.",
			array(
				'new_theme' => $new_name,
				'old_theme' => $old_name,
				'theme'     => $this->theme_name( $new_theme ),
			)
		);
	}

	#[WpAction( 'updated_option', priority: 10, accepted_args: 3 )]
	public function record_tracked_option_changed( string $option, mixed $old_value = null, mixed $value = null ): void {
		$option = $this->option_key( $option );
		if ( '' === $option || ! in_array( $option, $this->tracked_options(), true ) ) {
			return;
		}

		$this->record(
			self::GROUP_OPTIONS,
			'tracked_option_changed',
			$this->current_actor(),
			'option',
			$option,
			"Changed option {$option}.",
			array(
				'option' => $option,
				'old'    => $this->payload_value( $old_value ),
				'new'    => $this->payload_value( $value ),
			)
		);
	}

	#[WpAction( 'onumia_tables_cleanup', priority: 10, accepted_args: 0 )]
	public function prune_runtime_tables(): void {
		$this->table( 'events' )->purge( $this->retention_days() );
	}

	/**
	 * @param array{actor_type:string,actor_id:int|null,actor_login:string|null}|null $actor Actor.
	 * @param array<string,mixed>                                                     $payload Payload.
	 */
	public function record( string $group, string $event, ?array $actor, ?string $target_type, string|int|null $target_id, string $summary, array $payload ): void {
		$group = $this->group_key( $group );
		if ( '' === $group || ! $this->enabled() || ! in_array( $group, $this->tracked_groups(), true ) ) {
			return;
		}

		$event  = substr( $this->event_key( $event ), 0, 64 );
		$actor  = null === $actor ? $this->system_actor() : $actor;
		$target = null === $target_id || '' === (string) $target_id ? null : substr( (string) $target_id, 0, 64 );

		$this->table( 'events' )->insert(
			array(
				'occurred_at' => $this->now(),
				'group'       => $group,
				'event'       => '' === $event ? 'event' : $event,
				'actor_type'  => $actor['actor_type'],
				'actor_id'    => $actor['actor_id'],
				'actor_login' => $actor['actor_login'],
				'target_type' => null === $target_type || '' === trim( $target_type ) ? null : substr( $this->target_type( $target_type ), 0, 32 ),
				'target_id'   => $target,
				'summary'     => substr( '' === trim( $summary ) ? ucfirst( str_replace( '_', ' ', $event ) ) : trim( $summary ), 0, 255 ),
				'payload'     => $this->json_encode(
					array(
						'group'       => $group,
						'event'       => $event,
						'actor'       => $actor,
						'target_type' => $target_type,
						'target_id'   => $target,
						'payload'     => $payload,
					)
				),
			)
		);
	}

	/**
	 * @return list<string>
	 */
	private function tracked_groups(): array {
		$groups = array();
		foreach ( $this->array_setting_safe( 'trackedGroups' ) as $group ) {
			$group = $this->group_key( is_scalar( $group ) ? (string) $group : '' );
			if ( in_array( $group, self::GROUPS, true ) ) {
				$groups[] = $group;
			}
		}

		return array_values( array_unique( array() === $groups ? self::GROUPS : $groups ) );
	}

	/**
	 * @return list<string>
	 */
	private function tracked_options(): array {
		$options = array();
		foreach ( $this->array_setting_safe( 'trackedOptions' ) as $item ) {
			$value = is_array( $item ) ? ( $item['value'] ?? '' ) : $item;
			$key   = $this->option_key( is_scalar( $value ) ? (string) $value : '' );
			if ( in_array( $key, self::OPTION_ALLOWLIST, true ) ) {
				$options[] = $key;
			}
		}

		return array_values( array_unique( array() === $options ? self::OPTION_ALLOWLIST : $options ) );
	}

	/**
	 * @return array<mixed>
	 */
	private function array_setting_safe( string $name ): array {
		$value = $this->setting( $name );
		return is_array( $value ) ? $value : array();
	}

	private function record_post_event( string $event, mixed $post, string $verb ): void {
		$post_id   = $this->post_id( $post );
		$post_type = $this->post_type( $post, $post_id );
		$title     = $this->post_title( $post, $post_id );
		$target_id = null === $post_id ? null : (string) $post_id;

		$this->record(
			self::GROUP_POSTS,
			$event,
			$this->current_actor(),
			'post',
			$target_id,
			"{$verb} {$post_type}: {$this->safe_summary_value( $title, (string) $target_id )}.",
			array(
				'post_id'    => $post_id,
				'post_type'  => $post_type,
				'post_title' => $title,
			)
		);
	}

	/**
	 * @param array<string,mixed> $payload Payload.
	 */
	private function record_user_event( string $event, int $user_id, mixed $user, string $verb, array $payload = array() ): void {
		$target = $this->user_target( is_object( $user ) ? $user : $user_id );
		$this->record(
			self::GROUP_USERS,
			$event,
			$this->current_actor(),
			'user',
			$target['id'],
			"{$verb} user {$this->safe_summary_value( $target['label'], (string) $user_id )}.",
			array( 'user_id' => $user_id ) + $payload
		);
	}

	private function record_plugin_event( string $event, string $plugin, string $verb ): void {
		$this->record(
			self::GROUP_PLUGINS,
			$event,
			$this->current_actor(),
			'plugin',
			$plugin,
			"{$verb} {$this->safe_summary_value( $plugin, 'unknown plugin' )}.",
			array( 'plugin' => $plugin )
		);
	}

	/**
	 * @return array{actor_type:string,actor_id:int|null,actor_login:string|null}
	 */
	private function current_actor(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
		if ( $user_id > 0 && function_exists( 'get_userdata' ) ) {
			$actor = $this->actor_from_user( \get_userdata( $user_id ) );
			if ( null !== $actor ) {
				return $actor;
			}
		}

		return $this->system_actor();
	}

	/**
	 * @return array{actor_type:string,actor_id:int|null,actor_login:string|null}|null
	 */
	private function actor_from_user( mixed $user ): ?array {
		if ( ! is_object( $user ) ) {
			return null;
		}

		$id    = $this->object_int( $user, array( 'ID', 'id' ) );
		$login = $this->object_string( $user, array( 'user_login', 'login', 'display_name' ) );
		if ( null === $id && null === $login ) {
			return null;
		}

		return array(
			'actor_type'  => 'user',
			'actor_id'    => $id,
			'actor_login' => null === $login ? null : substr( $login, 0, 60 ),
		);
	}

	/**
	 * @return array{actor_type:string,actor_id:int|null,actor_login:string|null}
	 */
	private function system_actor(): array {
		return array(
			'actor_type'  => 'system',
			'actor_id'    => null,
			'actor_login' => null,
		);
	}

	/**
	 * @return array{id:string,label:string}
	 */
	private function user_target( mixed $user ): array {
		$id    = is_int( $user ) ? $user : ( is_object( $user ) ? $this->object_int( $user, array( 'ID', 'id' ) ) : null );
		$login = is_object( $user ) ? $this->object_string( $user, array( 'user_login', 'login', 'display_name' ) ) : null;
		if ( null === $login && null !== $id && function_exists( 'get_userdata' ) ) {
			$loaded = \get_userdata( $id );
			$login  = is_object( $loaded ) ? $this->object_string( $loaded, array( 'user_login', 'login', 'display_name' ) ) : null;
		}

		return array(
			'id'    => null === $id ? '' : (string) $id,
			'label' => null === $login ? '' : $login,
		);
	}

	private function post_id( mixed $post ): ?int {
		if ( is_numeric( $post ) ) {
			return (int) $post;
		}

		if ( is_object( $post ) ) {
			return $this->object_int( $post, array( 'ID', 'id' ) );
		}

		return null;
	}

	private function post_type( mixed $post, ?int $post_id ): string {
		if ( is_object( $post ) ) {
			$type = $this->object_string( $post, array( 'post_type' ) );
			if ( null !== $type && '' !== $type ) {
				return $type;
			}
		}

		if ( null !== $post_id && function_exists( 'get_post_type' ) ) {
			$type = \get_post_type( $post_id );
			if ( is_string( $type ) && '' !== $type ) {
				return $type;
			}
		}

		return 'post';
	}

	private function post_title( mixed $post, ?int $post_id ): string {
		if ( is_object( $post ) ) {
			$title = $this->object_string( $post, array( 'post_title', 'title' ) );
			if ( null !== $title ) {
				return $title;
			}
		}

		if ( null !== $post_id && function_exists( 'get_post' ) ) {
			$loaded = \get_post( $post_id );
			if ( is_object( $loaded ) ) {
				$title = $this->object_string( $loaded, array( 'post_title', 'title' ) );
				if ( null !== $title ) {
					return $title;
				}
			}
		}

		return '';
	}

	/**
	 * @param list<string> $properties Properties.
	 */
	private function object_int( object $object, array $properties ): ?int {
		foreach ( $properties as $property ) {
			$value = $object->{$property} ?? null;
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}

		return null;
	}

	/**
	 * @param list<string> $properties Properties.
	 */
	private function object_string( object $object, array $properties ): ?string {
		foreach ( $properties as $property ) {
			$value = $object->{$property} ?? null;
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}

		return null;
	}

	private function theme_name( mixed $theme ): string {
		if ( is_object( $theme ) ) {
			foreach ( array( 'name', 'stylesheet', 'template' ) as $property ) {
				$value = $theme->{$property} ?? null;
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return trim( (string) $value );
				}
			}
			if ( method_exists( $theme, 'get' ) ) {
				$value = $theme->get( 'Name' );
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return trim( (string) $value );
				}
			}
		}

		return is_scalar( $theme ) ? trim( (string) $theme ) : '';
	}

	private function group_key( string $value ): string {
		return $this->slug_key( $value, 16 );
	}

	private function event_key( string $value ): string {
		return $this->slug_key( $value, 64 );
	}

	private function option_key( string $value ): string {
		return $this->slug_key( $value, 64 );
	}

	private function target_type( string $value ): string {
		return $this->slug_key( $value, 32 );
	}

	private function slug_key( string $value, int $length ): string {
		$value = function_exists( 'sanitize_key' ) ? \sanitize_key( $value ) : strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $value ) ?? '' );
		return substr( $value, 0, $length );
	}

	private function safe_summary_value( string $value, string $fallback ): string {
		$value = trim( $value );
		return '' === $value ? $fallback : substr( $value, 0, 80 );
	}

	private function payload_value( mixed $value ): mixed {
		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			return $value;
		}

		return is_object( $value ) ? get_class( $value ) : gettype( $value );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function event_for_display( array $row ): array {
		$timestamp    = isset( $row['occurred_at'] ) && is_numeric( $row['occurred_at'] ) ? (int) $row['occurred_at'] : 0;
		$group        = (string) ( $row['group'] ?? '' );
		$event        = (string) ( $row['event'] ?? '' );
		$actor_login  = (string) ( $row['actor_login'] ?? '' );
		$actor_type   = (string) ( $row['actor_type'] ?? 'system' );
		$target_type  = (string) ( $row['target_type'] ?? '' );
		$target_id    = (string) ( $row['target_id'] ?? '' );
		$payload_json = (string) ( $row['payload'] ?? '' );

		return array(
			'id'              => (string) ( $row['id'] ?? '' ),
			'occurredAt'      => $timestamp,
			'occurredAtLabel' => $this->time_label( $timestamp ),
			'group'           => $group,
			'groupLabel'      => $this->group_label( $group ),
			'event'           => $event,
			'eventLabel'      => $this->event_label( $event ),
			'actorType'       => $actor_type,
			'actorId'         => isset( $row['actor_id'] ) && is_numeric( $row['actor_id'] ) ? (int) $row['actor_id'] : 0,
			'actorLogin'      => $actor_login,
			'actor'           => '' === $actor_login ? 'system' : $actor_login,
			'targetType'      => $target_type,
			'targetId'        => $target_id,
			'target'          => '' === $target_type && '' === $target_id ? '' : trim( "{$target_type}:{$target_id}", ':' ),
			'summary'         => (string) ( $row['summary'] ?? '' ),
			'payloadJson'     => $this->pretty_json( $payload_json ),
		);
	}

	private function group_label( string $group ): string {
		return match ( $group ) {
			self::GROUP_AUTH => 'Auth',
			self::GROUP_POSTS => 'Posts',
			self::GROUP_USERS => 'Users',
			self::GROUP_PLUGINS => 'Plugins',
			self::GROUP_THEMES => 'Themes',
			self::GROUP_OPTIONS => 'Options',
			default => ucfirst( $group ),
		};
	}

	private function event_label( string $event ): string {
		return ucwords( str_replace( '_', ' ', $event ) );
	}

	private function json_encode( mixed $payload, int $flags = 0 ): string {
		$flags |= JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$json   = function_exists( 'wp_json_encode' ) ? \wp_json_encode( $payload, $flags ) : json_encode( $payload, $flags );
		return is_string( $json ) ? $json : '{}';
	}

	private function pretty_json( string $json ): string {
		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $json;
		}

		return $this->json_encode( $decoded, JSON_PRETTY_PRINT );
	}
}
