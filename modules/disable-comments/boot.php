<?php
/**
 * Disable Comments module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\DisableComments;

use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
use Onumia\Modules\Attributes\EntrySection;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
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
final class DisableComments extends Module {

	private const MATCH_TYPES = array( 'postType', 'role', 'route', 'age' );
	private const MATCH_MODES = array( 'exact', 'prefix', 'regex' );
	private const POLICIES    = array( 'disable', 'close-new', 'mark-as-spam' );

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'rules', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'rules', singular: 'Rule', plural: 'Rules', key: 'id', storage: EntryStorage::Manual, source: 'rules', create_action: 'saveRule', update_action: 'saveRule', delete_action: 'deleteRules' )]
	#[EntrySection( name: 'match', label: 'Match', description: 'Decide when the rule applies.', order: 10, layout: 'tabs' )]
	#[EntrySection( name: 'policy', label: 'Policy', description: 'Choose what happens when the rule matches.', order: 20, layout: 'tabs' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, required: true, create: false, update: true, read_only: true, section: 'match', order: 10 )]
	#[EntryField( name: 'label', type: SettingType::String, label: 'Label', required: true, list: true, filter: true, filter_type: 'text', section: 'match', order: 20 )]
	#[EntryField( name: 'enabled', type: SettingType::Boolean, label: 'Enabled', default: true, section: 'match', order: 30 )]
	#[EntryField( name: 'enabledLabel', type: SettingType::String, label: 'Enabled', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'match', order: 40 )]
	#[EntryField(
		name: 'matchType',
		type: SettingType::String,
		label: 'Match type',
		default: 'postType',
		allowed: array( 'postType', 'role', 'route', 'age' ),
		options: array(
			array(
				'value' => 'postType',
				'label' => 'Post type',
			),
			array(
				'value' => 'role',
				'label' => 'Role',
			),
			array(
				'value' => 'route',
				'label' => 'Route',
			),
			array(
				'value' => 'age',
				'label' => 'Age',
			),
		),
		required: true,
		section: 'match',
		order: 50
	)]
	#[EntryField( name: 'matchTypeLabel', type: SettingType::String, label: 'Match type', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'match', order: 60 )]
	#[EntryField( name: 'matchPattern', type: SettingType::String, label: 'Pattern', required: true, list: true, filter: true, filter_type: 'text', section: 'match', order: 70 )]
	#[EntryField(
		name: 'matchMode',
		type: SettingType::String,
		label: 'Route match',
		default: 'exact',
		allowed: array( 'exact', 'prefix', 'regex' ),
		options: array(
			array(
				'value' => 'exact',
				'label' => 'Exact',
			),
			array(
				'value' => 'prefix',
				'label' => 'Prefix',
			),
			array(
				'value' => 'regex',
				'label' => 'Regex',
			),
		),
		section: 'match',
		order: 80
	)]
	#[EntryField( name: 'matchModeLabel', type: SettingType::String, label: 'Route match', create: false, update: false, read_only: true, section: 'match', order: 90 )]
	#[EntryField(
		name: 'policy',
		type: SettingType::String,
		label: 'Policy',
		default: 'disable',
		allowed: array( 'disable', 'close-new', 'mark-as-spam' ),
		options: array(
			array(
				'value' => 'disable',
				'label' => 'Disable',
			),
			array(
				'value' => 'close-new',
				'label' => 'Close new',
			),
			array(
				'value' => 'mark-as-spam',
				'label' => 'Mark as spam',
			),
		),
		required: true,
		section: 'policy',
		order: 10
	)]
	#[EntryField( name: 'policyLabel', type: SettingType::String, label: 'Policy', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'policy', order: 20 )]
	public function rules(): array {
		return array_map( array( $this, 'rule_for_display' ), $this->stored_rules() );
	}

	/**
	 * @param  array<string,mixed> $input Input.
	 * @return array{ok:bool,rule:array<string,mixed>}
	 */
	#[Action( 'saveRule' )]
	#[Input( 'id', SettingType::String, default: '' )]
	#[Input( 'label', SettingType::String, required: true )]
	#[Input( 'enabled', SettingType::Boolean, default: true )]
	#[Input( 'matchType', SettingType::String, default: 'postType', allowed: array( 'postType', 'role', 'route', 'age' ) )]
	#[Input( 'matchPattern', SettingType::String, required: true )]
	#[Input( 'matchMode', SettingType::String, default: 'exact', allowed: array( 'exact', 'prefix', 'regex' ) )]
	#[Input( 'policy', SettingType::String, default: 'disable', allowed: array( 'disable', 'close-new', 'mark-as-spam' ) )]
	public function save_rule( array $input ): array {
		$id = $this->string_from( $input, 'id' );
		if ( '' === $id ) {
			$id = $this->slug_from_label( $this->string_from( $input, 'label', 'disable-comments-rule' ) );
		}

		$row      = $this->normalize_rule(
			array(
				'id'           => $id,
				'label'        => $this->string_from( $input, 'label', 'Disable comments rule' ),
				'enabled'      => true === ( $input['enabled'] ?? true ),
				'matchType'    => $this->allowed_string( $input, 'matchType', self::MATCH_TYPES, 'postType' ),
				'matchPattern' => $this->string_from( $input, 'matchPattern' ),
				'matchMode'    => $this->allowed_string( $input, 'matchMode', self::MATCH_MODES, 'exact' ),
				'policy'       => $this->allowed_string( $input, 'policy', self::POLICIES, 'disable' ),
			)
		);
		$rules    = $this->stored_rules();
		$replaced = false;
		foreach ( $rules as $index => $rule ) {
			if ( ( $rule['id'] ?? '' ) === $row['id'] ) {
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
	 * @param  array{ids:array<mixed>} $input Input.
	 * @return array{ok:bool,deleted:list<string>}
	 */
	#[Action( 'deleteRules' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function delete_rules( array $input ): array {
		$ids = $this->string_list( $input['ids'] ?? array() );
		$this->save_rules(
			array_values(
				array_filter(
					$this->stored_rules(),
					static fn( array $rule ): bool => ! in_array( (string) ( $rule['id'] ?? '' ), $ids, true )
				)
			)
		);

		return array(
			'ok'      => true,
			'deleted' => $ids,
		);
	}

	#[WpFilter( 'comments_open', priority: 999, accepted_args: 2 )]
	public function comments_open( bool $open, mixed $post_id = null ): bool {
		$rule = $this->first_matching_rule( $this->context_for_post( $post_id ) );
		if ( null === $rule || 'mark-as-spam' === $rule['policy'] ) {
			return $open;
		}

		return false;
	}

	#[WpFilter( 'pings_open', priority: 999, accepted_args: 2 )]
	public function pings_open( bool $open, mixed $post_id = null ): bool {
		return $this->comments_open( $open, $post_id );
	}

	/**
	 * @param  list<mixed> $comments Comments.
	 * @return list<mixed>
	 */
	#[WpFilter( 'comments_array', priority: 999, accepted_args: 2 )]
	public function comments_array( array $comments, mixed $post_id = null ): array {
		$rule = $this->first_matching_rule( $this->context_for_post( $post_id ) );
		return null !== $rule && 'disable' === $rule['policy'] ? array() : $comments;
	}

	/**
	 * @param array<string,mixed> $commentdata Comment data.
	 */
	#[WpFilter( 'pre_comment_approved', priority: 10, accepted_args: 2 )]
	public function pre_comment_approved( mixed $approved, array $commentdata ): mixed {
		$rule = $this->first_matching_rule( $this->context_for_comment( $commentdata ) );
		if ( null === $rule ) {
			return $approved;
		}

		if ( 'mark-as-spam' === $rule['policy'] ) {
			return 'spam';
		}

		return class_exists( '\WP_Error' )
		? new \WP_Error( 'onumia_comments_disabled', 'Comments are disabled by Onumia.' )
		: '0';
	}

	#[WpAction( 'admin_init', priority: 10, accepted_args: 0 )]
	public function remove_comment_meta_boxes(): void {
		if ( ! $this->enabled() || ! function_exists( 'remove_meta_box' ) ) {
			return;
		}

		foreach ( $this->disable_post_type_rules() as $post_type ) {
			\remove_meta_box( 'commentstatusdiv', $post_type, 'normal' );
			\remove_meta_box( 'commentsdiv', $post_type, 'normal' );
		}
	}

	#[WpAction( 'admin_menu', priority: 999, accepted_args: 0 )]
	public function remove_comments_menu_if_globally_disabled(): void {
		if ( ! $this->enabled() || ! function_exists( 'remove_menu_page' ) ) {
			return;
		}

		$post_types = $this->commentable_post_types();
		if ( array() === $post_types ) {
			return;
		}

		$disabled = array_fill_keys( $this->disable_post_type_rules(), true );
		foreach ( $post_types as $post_type ) {
			if ( ! isset( $disabled[ $post_type ] ) ) {
				return;
			}
		}

		\remove_menu_page( 'edit-comments.php' );
	}

	/**
	 * @return list<array{id:string,label:string,enabled:bool,matchType:string,matchPattern:string,matchMode:string,policy:string}>
	 */
	private function stored_rules(): array {
		$rules = array();
		foreach ( $this->array_setting( 'rules' ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$normalized = $this->normalize_rule( $rule );
			if ( '' !== $normalized['id'] && '' !== $normalized['matchPattern'] ) {
				$rules[] = $normalized;
			}
		}

		return $rules;
	}

	/**
	 * @param  array<string,mixed> $rule Rule.
	 * @return array{id:string,label:string,enabled:bool,matchType:string,matchPattern:string,matchMode:string,policy:string}
	 */
	private function normalize_rule( array $rule ): array {
		$id    = $this->slug_from_label( (string) ( $rule['id'] ?? '' ) );
		$label = trim( (string) ( $rule['label'] ?? $id ) );

		return array(
			'id'           => '' === $id ? $this->slug_from_label( $label ) : $id,
			'label'        => '' === $label ? 'Disable comments rule' : $label,
			'enabled'      => true === ( $rule['enabled'] ?? true ),
			'matchType'    => $this->value_in( $rule['matchType'] ?? null, self::MATCH_TYPES, 'postType' ),
			'matchPattern' => trim( (string) ( $rule['matchPattern'] ?? '' ) ),
			'matchMode'    => $this->value_in( $rule['matchMode'] ?? null, self::MATCH_MODES, 'exact' ),
			'policy'       => $this->value_in( $rule['policy'] ?? null, self::POLICIES, 'disable' ),
		);
	}

	/**
	 * @param  array<string,mixed> $rule Rule.
	 * @return array<string,mixed>
	 */
	private function rule_for_display( array $rule ): array {
		$rule['enabledLabel']   = true === $rule['enabled'] ? 'Yes' : 'No';
		$rule['matchTypeLabel'] = $this->match_type_label( $rule['matchType'] );
		$rule['matchModeLabel'] = $this->match_mode_label( $rule['matchMode'] );
		$rule['policyLabel']    = $this->policy_label( $rule['policy'] );
		return $rule;
	}

	/**
	 * @param list<array<string,mixed>> $rules Rules.
	 */
	private function save_rules( array $rules ): void {
		( new ModuleSettingsRepository() )->update_settings(
			$this->definition(),
			array( 'rules' => array_values( $rules ) )
		);
	}

	/**
	 * @param  array<string,mixed> $context Context.
	 * @return array{id:string,label:string,enabled:bool,matchType:string,matchPattern:string,matchMode:string,policy:string}|null
	 */
	private function first_matching_rule( array $context ): ?array {
		if ( ! $this->enabled() ) {
			return null;
		}

		foreach ( $this->stored_rules() as $rule ) {
			if ( true !== $rule['enabled'] ) {
				continue;
			}

			if ( $this->rule_matches( $rule, $context ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $rule    Rule.
	 * @param array<string,mixed> $context Context.
	 */
	private function rule_matches( array $rule, array $context ): bool {
		return match ( $rule['matchType'] ) {
			'postType' => $this->match_post_type( $rule, $context ),
			'role' => $this->match_role( $rule, $context ),
			'route' => $this->match_route( $rule, $context ),
			'age' => $this->match_age( $rule, $context ),
			default => false,
		};
	}

	/**
	 * @param array<string,mixed> $rule    Rule.
	 * @param array<string,mixed> $context Context.
	 */
	private function match_post_type( array $rule, array $context ): bool {
		return strtolower( $rule['matchPattern'] ) === strtolower( (string) ( $context['postType'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $rule    Rule.
	 * @param array<string,mixed> $context Context.
	 */
	private function match_role( array $rule, array $context ): bool {
		$pattern = strtolower( $rule['matchPattern'] );
		if ( 'guest' === $pattern ) {
			return true === ( $context['guest'] ?? false );
		}

		$roles = is_array( $context['roles'] ?? null ) ? $context['roles'] : array();
		return in_array( $pattern, array_map( 'strtolower', array_filter( $roles, 'is_string' ) ), true );
	}

	/**
	 * @param array<string,mixed> $rule    Rule.
	 * @param array<string,mixed> $context Context.
	 */
	private function match_route( array $rule, array $context ): bool {
		$route = (string) ( $context['route'] ?? '' );
			$parsed_path = parse_url( $route, PHP_URL_PATH );
			$path        = is_string( $parsed_path ) && '' !== $parsed_path ? $parsed_path : $route;
		foreach ( array( $route, $path ) as $candidate ) {
			if ( $this->match_pattern( $candidate, $rule['matchPattern'], $rule['matchMode'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $rule    Rule.
	 * @param array<string,mixed> $context Context.
	 */
	private function match_age( array $rule, array $context ): bool {
		$threshold = max( 0, (int) $rule['matchPattern'] );
		if ( 0 === $threshold ) {
			return false;
		}

		$timestamp = $this->post_timestamp( $context['post'] ?? null );
		if ( 0 === $timestamp ) {
			return false;
		}

		$age_days = (int) floor( max( 0, $this->now() - $timestamp ) / DAY_IN_SECONDS );
		return $age_days >= $threshold;
	}

	private function match_pattern( string $value, string $pattern, string $mode ): bool {
		return match ( $mode ) {
			'prefix' => str_starts_with( $value, $pattern ),
			'regex' => $this->regex_matches( $pattern, $value ),
			default => $value === $pattern,
		};
	}

	private function regex_matches( string $pattern, string $value ): bool {
		$expression = @preg_match( $pattern, '' ) !== false ? $pattern : '~' . str_replace( '~', '\~', $pattern ) . '~';
		return 1 === @preg_match( $expression, $value );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function context_for_post( mixed $post_id ): array {
		$post = $this->post_from( $post_id );
		return array(
			'post'     => $post,
			'postType' => $this->post_type_from_post( $post ),
			'route'    => $this->current_route(),
			'guest'    => ! $this->current_user_logged_in(),
			'roles'    => $this->current_user_roles(),
		);
	}

	/**
	 * @param  array<string,mixed> $commentdata Comment data.
	 * @return array<string,mixed>
	 */
	private function context_for_comment( array $commentdata ): array {
		$post_id = isset( $commentdata['comment_post_ID'] ) && is_numeric( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : null;
		$user_id = isset( $commentdata['user_id'] ) && is_numeric( $commentdata['user_id'] ) ? (int) $commentdata['user_id'] : 0;
		$post    = $this->post_from( $post_id );

		return array(
			'post'     => $post,
			'postType' => $this->post_type_from_post( $post ),
			'route'    => $this->current_route(),
			'guest'    => $user_id <= 0,
			'roles'    => $this->roles_for_user_id( $user_id ),
		);
	}

	private function post_from( mixed $post_id ): ?object {
		if ( is_object( $post_id ) ) {
			return $post_id;
		}

		if ( null === $post_id && function_exists( 'get_post' ) ) {
			return \get_post();
		}

		if ( is_numeric( $post_id ) && function_exists( 'get_post' ) ) {
			return \get_post( (int) $post_id );
		}

		return null;
	}

	private function post_type_from_post( ?object $post ): string {
		if ( is_object( $post ) && is_string( $post->post_type ?? null ) ) {
			return $post->post_type;
		}

		if ( is_object( $post ) && isset( $post->ID ) && is_numeric( $post->ID ) && function_exists( 'get_post_type' ) ) {
			$post_type = \get_post_type( (int) $post->ID );
			return is_string( $post_type ) ? $post_type : '';
		}

		return '';
	}

	private function post_timestamp( mixed $post ): int {
		if ( ! is_object( $post ) ) {
			return 0;
		}

		foreach ( array( 'post_date_gmt', 'post_date' ) as $key ) {
			$value = $post->{$key} ?? null;
			if ( is_string( $value ) && '' !== $value ) {
				$timestamp = strtotime( $value . ' UTC' );
				if ( false !== $timestamp ) {
					return $timestamp;
				}
			}
		}

		return 0;
	}

	private function current_route(): string {
     // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passive route matching, sanitized immediately after unslashing.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$route       = is_array( $request_uri ) ? '' : sanitize_text_field( $request_uri );
		return is_string( $route ) ? $route : '';
	}

	private function current_user_logged_in(): bool {
		return function_exists( 'is_user_logged_in' ) && \is_user_logged_in();
	}

	/**
	 * @return list<string>
	 */
	private function current_user_roles(): array {
		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = \wp_get_current_user();
			return $this->roles_from_user( is_object( $user ) ? $user : null );
		}

		return array();
	}

	/**
	 * @return list<string>
	 */
	private function roles_for_user_id( int $user_id ): array {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return array();
		}

		$user = \get_userdata( $user_id );
		return $this->roles_from_user( is_object( $user ) ? $user : null );
	}

	/**
	 * @return list<string>
	 */
	private function roles_from_user( ?object $user ): array {
		$roles = is_object( $user ) && is_array( $user->roles ?? null ) ? $user->roles : array();
		return array_values( array_unique( array_filter( $roles, 'is_string' ) ) );
	}

	/**
	 * @return list<string>
	 */
	private function disable_post_type_rules(): array {
		$post_types = array();
		foreach ( $this->stored_rules() as $rule ) {
			if ( true === $rule['enabled'] && 'postType' === $rule['matchType'] && 'disable' === $rule['policy'] ) {
				$post_types[] = $rule['matchPattern'];
			}
		}

		return array_values( array_unique( array_filter( $post_types ) ) );
	}

	/**
	 * @return list<string>
	 */
	private function commentable_post_types(): array {
		if ( function_exists( 'get_post_types' ) ) {
			$objects = \get_post_types( array( 'public' => true ), 'objects' );
			$types   = array();
			foreach ( is_array( $objects ) ? $objects : array() as $slug => $object ) {
				if ( ! is_string( $slug ) ) {
					continue;
				}

				$supports_comments = function_exists( 'post_type_supports' )
				 ? \post_type_supports( $slug, 'comments' )
				 : true;
				if ( $supports_comments ) {
					$types[] = $slug;
				}
			}

			if ( array() !== $types ) {
				sort( $types );
				return $types;
			}
		}

		return array( 'page', 'post' );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 */
	private function string_from( array $input, string $key, string $default = '' ): string {
		$value = $input[ $key ] ?? $default;
		return is_scalar( $value ) ? trim( (string) $value ) : $default;
	}

	/**
	 * @param list<string> $allowed Allowed values.
	 */
	private function allowed_string( array $input, string $key, array $allowed, string $default ): string {
		return $this->value_in( $input[ $key ] ?? null, $allowed, $default );
	}

	/**
	 * @param list<string> $allowed Allowed values.
	 */
	private function value_in( mixed $value, array $allowed, string $default ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $default;
	}

	private function slug_from_label( string $value ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return \sanitize_key( str_replace( '-', '_', $value ) );
		}

		return strtolower( preg_replace( '/[^a-z0-9_]+/', '_', $value ) ?? '' );
	}

	private function match_type_label( string $value ): string {
		return match ( $value ) {
			'role' => 'Role',
			'route' => 'Route',
			'age' => 'Age',
			default => 'Post type',
		};
	}

	private function match_mode_label( string $value ): string {
		return match ( $value ) {
			'prefix' => 'Prefix',
			'regex' => 'Regex',
			default => 'Exact',
		};
	}

	private function policy_label( string $value ): string {
		return match ( $value ) {
			'close-new' => 'Close new',
			'mark-as-spam' => 'Mark as spam',
			default => 'Disable',
		};
	}
}
