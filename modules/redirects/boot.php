<?php
/**
 * Redirects module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\Redirects;

use Onumia\Modules\Attributes\Action;
use Onumia\Modules\Attributes\DataSource;
use Onumia\Modules\Attributes\Entries;
use Onumia\Modules\Attributes\EntryField;
use Onumia\Modules\Attributes\EntrySection;
use Onumia\Modules\Attributes\Input;
use Onumia\Modules\Attributes\ModuleContract;
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
#[Setting( 'rules', SettingType::Array, default: array() )]
final class Redirects extends Module {
	private const MATCH_MODES  = array( 'exact', 'prefix', 'regex' );
	private const STATUS_CODES = array( 301, 302, 307 );

	/**
	 * @return list<array<string,mixed>>
	 */
	#[DataSource( 'rules', shape: DataSourceShape::Collection, pagination: PaginationMode::Client )]
	#[Entries( name: 'rules', singular: 'Redirect', plural: 'Redirects', key: 'id', storage: EntryStorage::Manual, source: 'rules', create_action: 'saveRule', update_action: 'saveRule', delete_action: 'deleteRules' )]
	#[EntrySection( name: 'match', label: 'Match', description: 'Match the incoming request path.', order: 10, layout: 'tabs' )]
	#[EntrySection( name: 'destination', label: 'Destination', description: 'Choose where matching requests are sent.', order: 20, layout: 'tabs' )]
	#[EntrySection( name: 'behavior', label: 'Behavior', description: 'Enable the rule and control query strings.', order: 30, layout: 'tabs' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, required: true, create: false, update: true, read_only: true, section: 'match', order: 10 )]
	#[EntryField( name: 'label', type: SettingType::String, label: 'Label', required: true, list: true, filter: true, filter_type: 'text', section: 'match', order: 20 )]
	#[EntryField( name: 'fromPattern', type: SettingType::String, label: 'From pattern', required: true, list: true, filter: true, filter_type: 'text', section: 'match', order: 30 )]
	#[EntryField(
		name: 'matchMode',
		type: SettingType::String,
		label: 'Match mode',
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
		list: true,
		filter: true,
		filter_type: 'option',
		section: 'match',
		order: 40
	)]
	#[EntryField( name: 'matchModeLabel', type: SettingType::String, label: 'Match mode', create: false, update: false, read_only: true, section: 'match', order: 50 )]
	#[EntryField( name: 'toUrl', type: SettingType::String, label: 'To URL', required: true, list: true, filter: true, filter_type: 'text', section: 'destination', order: 10 )]
	#[EntryField(
		name: 'statusCode',
		type: SettingType::Integer,
		label: 'Status',
		default: 301,
		allowed: array( 301, 302, 307 ),
		options: array(
			array(
				'value' => 301,
				'label' => '301',
			),
			array(
				'value' => 302,
				'label' => '302',
			),
			array(
				'value' => 307,
				'label' => '307',
			),
		),
		list: true,
		filter: true,
		filter_type: 'option',
		section: 'destination',
		order: 20
	)]
	#[EntryField( name: 'enabled', type: SettingType::Boolean, label: 'Enabled', default: true, section: 'behavior', order: 10 )]
	#[EntryField( name: 'enabledLabel', type: SettingType::String, label: 'Enabled', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true, section: 'behavior', order: 20 )]
	#[EntryField( name: 'preserveQueryString', type: SettingType::Boolean, label: 'Preserve query string', default: true, section: 'behavior', order: 30 )]
	#[EntryField( name: 'preserveQueryStringLabel', type: SettingType::String, label: 'Preserve query', create: false, update: false, read_only: true, section: 'behavior', order: 40 )]
	#[EntryField( name: 'hits', type: SettingType::Integer, label: 'Hits', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true, section: 'behavior', order: 50 )]
	#[EntryField( name: 'lastHitAt', type: SettingType::Integer, label: 'Last hit timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true, section: 'behavior', order: 60 )]
	#[EntryField( name: 'lastHitLabel', type: SettingType::String, label: 'Last hit', list: true, create: false, update: false, read_only: true, section: 'behavior', order: 70 )]
	#[EntryField( name: 'notes', type: SettingType::String, label: 'Notes', props: array( 'multiline' => true ), section: 'behavior', order: 80 )]
	public function rules(): array {
		return array_map( array( $this, 'rule_for_display' ), $this->stored_rules() );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{ok:bool,rule:array<string,mixed>}
	 */
	#[Action( 'saveRule' )]
	#[Input( 'id', SettingType::String, default: '' )]
	#[Input( 'label', SettingType::String, required: true )]
	#[Input( 'enabled', SettingType::Boolean, default: true )]
	#[Input( 'fromPattern', SettingType::String, required: true )]
	#[Input( 'matchMode', SettingType::String, default: 'exact', allowed: array( 'exact', 'prefix', 'regex' ) )]
	#[Input( 'toUrl', SettingType::String, required: true )]
	#[Input( 'statusCode', SettingType::Integer, default: 301, allowed: array( 301, 302, 307 ) )]
	#[Input( 'preserveQueryString', SettingType::Boolean, default: true )]
	#[Input( 'notes', SettingType::String, default: '' )]
	public function save_rule( array $input ): array {
		$id = $this->string_from( $input, 'id' );
		if ( '' === $id ) {
			$id = $this->slug_from_label( $this->string_from( $input, 'label', 'redirect-rule' ) );
		}

		$row      = $this->normalize_rule(
			array(
				'id'                  => $id,
				'label'               => $this->string_from( $input, 'label', 'Redirect rule' ),
				'enabled'             => true === ( $input['enabled'] ?? true ),
				'fromPattern'         => $this->string_from( $input, 'fromPattern' ),
				'matchMode'           => $this->allowed_string( $input, 'matchMode', self::MATCH_MODES, 'exact' ),
				'toUrl'               => $this->string_from( $input, 'toUrl' ),
				'statusCode'          => $this->status_code_from( $input['statusCode'] ?? 301 ),
				'preserveQueryString' => true === ( $input['preserveQueryString'] ?? true ),
				'notes'               => $this->string_from( $input, 'notes' ),
			)
		);
		$rules    = $this->stored_rules();
		$replaced = false;
		foreach ( $rules as $index => $rule ) {
			if ( $row['id'] === $rule['id'] ) {
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
	 * @param array{ids:array<mixed>} $input Input.
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
					static fn( array $rule ): bool => ! in_array( $rule['id'], $ids, true )
				)
			)
		);

		return array(
			'ok'      => true,
			'deleted' => $ids,
		);
	}

	#[WpAction( 'template_redirect', priority: 1, accepted_args: 0 )]
	public function redirect_template_request(): void {
		$this->redirect_current_request();
	}

	public function redirect_current_request(): bool {
		if ( ! $this->enabled() ) {
			return false;
		}

		$request = $this->current_request();
		if ( '' === $request['path'] ) {
			return false;
		}

		$rule = $this->first_matching_rule( $request['path'] );
		if ( null === $rule ) {
			return false;
		}

		$location = $this->target_url( $rule, $request['query'] );
		if ( '' === $location || $this->same_target( $request['path'], $location ) ) {
			return false;
		}

		$this->record_hit( $rule['id'] );

		$redirected = function_exists( 'wp_safe_redirect' )
			? \wp_safe_redirect( $location, $rule['statusCode'] )
			: false;
		if ( $redirected ) {
			$this->terminate_after_redirect();
		}

		return $redirected;
	}

	private function terminate_after_redirect(): void {
		if ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) || defined( '__PHPUNIT_PHAR__' ) || class_exists( \PHPUnit\Framework\TestCase::class, false ) ) {
			return;
		}

		// @codeCoverageIgnoreStart
		exit;
		// @codeCoverageIgnoreEnd
	}

	/**
	 * @return list<array{id:string,label:string,enabled:bool,fromPattern:string,matchMode:string,toUrl:string,statusCode:int,preserveQueryString:bool,notes:string}>
	 */
	private function stored_rules(): array {
		$rules = array();
		foreach ( $this->array_setting( 'rules' ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$normalized = $this->normalize_rule( $rule );
			if ( '' !== $normalized['id'] && '' !== $normalized['fromPattern'] && '' !== $normalized['toUrl'] ) {
				$rules[] = $normalized;
			}
		}

		return $rules;
	}

	/**
	 * @param array<string,mixed> $rule Rule.
	 * @return array{id:string,label:string,enabled:bool,fromPattern:string,matchMode:string,toUrl:string,statusCode:int,preserveQueryString:bool,notes:string}
	 */
	private function normalize_rule( array $rule ): array {
		$label = trim( (string) ( $rule['label'] ?? '' ) );
		$id    = $this->slug_from_label( (string) ( $rule['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = $this->slug_from_label( '' === $label ? 'redirect-rule' : $label );
		}

		$match_mode   = $this->value_in( $rule['matchMode'] ?? null, self::MATCH_MODES, 'exact' );
		$from_pattern = trim( (string) ( $rule['fromPattern'] ?? '' ) );
		if ( 'regex' !== $match_mode ) {
			$from_pattern = $this->normalized_path( $from_pattern );
		}

		return array(
			'id'                  => $id,
			'label'               => '' === $label ? 'Redirect rule' : $label,
			'enabled'             => true === ( $rule['enabled'] ?? true ),
			'fromPattern'         => $from_pattern,
			'matchMode'           => $match_mode,
			'toUrl'               => trim( (string) ( $rule['toUrl'] ?? '' ) ),
			'statusCode'          => $this->status_code_from( $rule['statusCode'] ?? 301 ),
			'preserveQueryString' => true === ( $rule['preserveQueryString'] ?? true ),
			'notes'               => trim( (string) ( $rule['notes'] ?? '' ) ),
		);
	}

	/**
	 * @param array<string,mixed> $rule Rule.
	 * @return array<string,mixed>
	 */
	private function rule_for_display( array $rule ): array {
		$hit = $this->hit_for_rule( $rule['id'] );

		$rule['matchModeLabel']           = $this->match_mode_label( $rule['matchMode'] );
		$rule['enabledLabel']             = true === $rule['enabled'] ? 'Yes' : 'No';
		$rule['preserveQueryStringLabel'] = true === $rule['preserveQueryString'] ? 'Yes' : 'No';
		$rule['hits']                     = $hit['count'];
		$rule['lastHitAt']                = $hit['lastHitAt'];
		$rule['lastHitLabel']             = $this->time_label( $hit['lastHitAt'], 'M j, Y H:i' );
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
	 * @return array{id:string,label:string,enabled:bool,fromPattern:string,matchMode:string,toUrl:string,statusCode:int,preserveQueryString:bool,notes:string}|null
	 */
	private function first_matching_rule( string $path ): ?array {
		foreach ( $this->stored_rules() as $rule ) {
			if ( true !== $rule['enabled'] ) {
				continue;
			}

			if ( $this->matches_rule( $path, $rule ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $rule Rule.
	 */
	private function matches_rule( string $path, array $rule ): bool {
		$pattern = (string) ( $rule['fromPattern'] ?? '' );
		return match ( (string) ( $rule['matchMode'] ?? 'exact' ) ) {
			'prefix' => str_starts_with( $path, $pattern ),
			'regex' => $this->regex_matches( $pattern, $path ),
			default => $path === $pattern,
		};
	}

	private function regex_matches( string $pattern, string $path ): bool {
		$expression = @preg_match( $pattern, '' ) !== false ? $pattern : '~' . str_replace( '~', '\~', $pattern ) . '~';
		return 1 === @preg_match( $expression, $path );
	}

	/**
	 * @return array{path:string,query:string}
	 */
	private function current_request(): array {
		$request_uri = $this->server_string( 'REQUEST_URI' );
		if ( '' === $request_uri ) {
			return array(
				'path'  => '',
				'query' => '',
			);
		}

		$parts = function_exists( 'wp_parse_url' ) ? \wp_parse_url( $request_uri ) : parse_url( $request_uri );
		if ( ! is_array( $parts ) ) {
			return array(
				'path'  => $this->normalized_path( $request_uri ),
				'query' => '',
			);
		}

		return array(
			'path'  => $this->normalized_path( is_string( $parts['path'] ?? null ) ? $parts['path'] : '/' ),
			'query' => is_string( $parts['query'] ?? null ) ? $parts['query'] : '',
		);
	}

	private function target_url( array $rule, string $query ): string {
		$target = trim( (string) ( $rule['toUrl'] ?? '' ) );
		if ( '' === $target ) {
			return '';
		}

		if ( true !== ( $rule['preserveQueryString'] ?? false ) || '' === $query ) {
			return $target;
		}

		$separator = str_contains( $target, '?' ) ? '&' : '?';
		return $target . $separator . $query;
	}

	private function same_target( string $path, string $target ): bool {
		$parts = function_exists( 'wp_parse_url' ) ? \wp_parse_url( $target ) : parse_url( $target );
		if ( ! is_array( $parts ) ) {
			return $path === $this->normalized_path( $target );
		}

		return $path === $this->normalized_path( is_string( $parts['path'] ?? null ) ? $parts['path'] : $target );
	}

	private function record_hit( string $rule_id ): void {
		$table    = $this->table( 'hits' );
		$existing = $table->recent( 1, null, array( 'rule_id' => $rule_id ) );
		$now      = $this->now();

		if ( array() !== $existing ) {
			$row = $existing[0];
			if ( isset( $row['id'] ) && is_numeric( $row['id'] ) ) {
				$table->update(
					(int) $row['id'],
					array(
						'count'       => max( 0, (int) ( $row['count'] ?? 0 ) ) + 1,
						'last_hit_at' => $now,
					)
				);
			}
			return;
		}

		$table->insert(
			array(
				'rule_id'     => $rule_id,
				'count'       => 1,
				'last_hit_at' => $now,
			)
		);
	}

	/**
	 * @return array{count:int,lastHitAt:int}
	 */
	private function hit_for_rule( string $rule_id ): array {
		$existing = $this->table( 'hits' )->recent( 1, null, array( 'rule_id' => $rule_id ) );
		if ( array() === $existing ) {
			return array(
				'count'     => 0,
				'lastHitAt' => 0,
			);
		}

		$row = $existing[0];
		return array(
			'count'     => max( 0, (int) ( $row['count'] ?? 0 ) ),
			'lastHitAt' => max( 0, (int) ( $row['last_hit_at'] ?? 0 ) ),
		);
	}

	private function normalized_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '';
		}

		return '/' . ltrim( $path, '/' );
	}

	private function server_string( string $key ): string {
		$value = match ( $key ) {
			'REQUEST_URI' => \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
			default => '',
		};
		return trim( $value );
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

	private function status_code_from( mixed $value ): int {
		$status = is_numeric( $value ) ? (int) $value : 301;
		return in_array( $status, self::STATUS_CODES, true ) ? $status : 301;
	}

	private function slug_from_label( string $value ): string {
		$slug = preg_replace( '/[^a-z0-9_]+/', '_', str_replace( '-', '_', strtolower( $value ) ) ) ?? '';
		return trim( $slug, '_' );
	}

	private function match_mode_label( string $value ): string {
		return match ( $value ) {
			'prefix' => 'Prefix',
			'regex' => 'Regex',
			default => 'Exact',
		};
	}
}
