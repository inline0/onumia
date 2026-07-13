<?php
/**
 * Unused Media module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\UnusedMedia;

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

#[ModuleContract( capability: 'manage_options' )]
#[Setting( 'enabled', SettingType::Boolean, default: false )]
#[Setting( 'scanFrequency', SettingType::String, default: 'weekly', allowed: array( 'daily', 'weekly', 'manual' ) )]
#[Setting( 'pendingWindowDays', SettingType::Integer, default: 7, min: 1, max: 90 )]
#[Setting( 'excludeAttachmentIds', SettingType::Array, default: array() )]
#[Setting( 'excludeMimeTypes', SettingType::Array, default: array( 'image/svg+xml' ) )]
final class UnusedMedia extends Module {
	private const SCAN_HOOK  = 'onumia_unused_media_scan';
	private const PURGE_HOOK = 'onumia_unused_media_purge';

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'scanResults', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'scanResults', singular: 'Unused attachment', plural: 'Unused attachments', key: 'id', storage: EntryStorage::Table, source: 'scanResults', table: 'scan_results', delete_action: 'queueForDeletion', destructive_mode: 'archive' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'attachmentId', type: SettingType::Integer, label: 'Attachment ID', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'filename', type: SettingType::String, label: 'Filename', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'mimeType', type: SettingType::String, label: 'MIME type', list: true, filter: true, filter_type: 'option', create: false, update: false, read_only: true )]
	#[EntryField( name: 'sizeBytes', type: SettingType::Integer, label: 'Size bytes', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'sizeLabel', type: SettingType::String, label: 'Size', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'uploadedAt', type: SettingType::Integer, label: 'Uploaded timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'uploadedLabel', type: SettingType::String, label: 'Uploaded', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'scannedAt', type: SettingType::Integer, label: 'Scanned timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'scannedLabel', type: SettingType::String, label: 'Last scan', list: true, create: false, update: false, read_only: true )]
	public function scan_results( array $params ): array {
		$rows = array_reverse( $this->table( 'scan_results' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'scan_row_for_display' ), $rows ), $params );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'pendingDeletions', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'pendingDeletions', singular: 'Pending deletion', plural: 'Pending deletions', key: 'id', storage: EntryStorage::Table, source: 'pendingDeletions', table: 'pending_deletions', delete_action: 'restorePending', destructive_mode: 'deactivate' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'attachmentId', type: SettingType::Integer, label: 'Attachment ID', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'filename', type: SettingType::String, label: 'Filename', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'queuedAt', type: SettingType::Integer, label: 'Queued timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'queuedAtLabel', type: SettingType::String, label: 'Queued at', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'deletesAt', type: SettingType::Integer, label: 'Deletes timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'deletesAtLabel', type: SettingType::String, label: 'Deletes at', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'queuedBy', type: SettingType::Integer, label: 'Queued by user ID', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'queuedByLabel', type: SettingType::String, label: 'Queued by', list: true, create: false, update: false, read_only: true )]
	public function pending_deletions( array $params ): array {
		$rows = array_reverse( $this->table( 'pending_deletions' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'pending_row_for_display' ), $rows ), $params );
	}

	public function boot(): void {
		$this->sync_schedules( false );
	}

	public function settings_updated(): void {
		$this->sync_schedules( true );
	}

	/**
	 * @return array{scanned:int,unused:int}
	 */
	#[Action( 'runScanNow' )]
	public function run_scan_now(): array {
		return $this->run_scan();
	}

	/**
	 * @param array{ids:array<mixed>} $input Input.
	 * @return array{queued:int}
	 */
	#[Action( 'queueForDeletion' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function queue_for_deletion( array $input ): array {
		$ids     = $this->integer_list( $input['ids'] ?? array() );
		$now     = $this->now();
		$deletes = $now + ( $this->pending_window_days() * $this->day_in_seconds() );
		$scan    = $this->table( 'scan_results' );
		$pending = $this->table( 'pending_deletions' );
		$queued  = 0;

		foreach ( $ids as $id ) {
			$row = $scan->find( $id );
			if ( null === $row || ! isset( $row['attachment_id'] ) || ! is_numeric( $row['attachment_id'] ) ) {
				continue;
			}

			$attachment_id = (int) $row['attachment_id'];
			$pending->purge( null, array( 'attachment_id' => $attachment_id ) );
			$pending->insert(
				array(
					'queued_at'          => $now,
					'deletes_at'         => $deletes,
					'attachment_id'      => $attachment_id,
					'queued_by_user_id'  => $this->current_user_id(),
				)
			);
			++$queued;
		}

		return array( 'queued' => $queued );
	}

	/**
	 * @param array{ids:array<mixed>} $input Input.
	 * @return array{restored:int}
	 */
	#[Action( 'restorePending' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function restore_pending( array $input ): array {
		$ids      = $this->integer_list( $input['ids'] ?? array() );
		$pending  = $this->table( 'pending_deletions' );
		$restored = 0;

		foreach ( $ids as $id ) {
			$row = $pending->find( $id );
			if ( null === $row ) {
				continue;
			}
			$restored += $pending->purge( null, array( 'id' => $id ) );
		}

		return array( 'restored' => $restored );
	}

	#[WpAction( 'onumia_unused_media_scan', accepted_args: 0 )]
	public function scheduled_scan(): void {
		if ( $this->scan_schedule_enabled() ) {
			$this->run_scan();
		}
	}

	#[WpAction( 'onumia_unused_media_purge', accepted_args: 0 )]
	public function scheduled_purge(): void {
		if ( $this->enabled() ) {
			$this->purge_expired();
		}
	}

	/**
	 * @return array{scanned:int,unused:int}
	 */
	public function run_scan(): array {
		$scan_table = $this->table( 'scan_results' );
		$scan_table->purge_all();

		$attachments = $this->attachments();
		$used_ids    = $this->used_attachment_ids();
		$text_blob   = $this->used_text_blob();
		$excluded_ids = $this->excluded_attachment_ids();
		$excluded_mimes = $this->excluded_mime_types();
		$now         = $this->now();
		$unused      = 0;

		foreach ( $attachments as $attachment ) {
			$id        = (int) $attachment['id'];
			$mime_type = (string) $attachment['mime_type'];
			$url       = (string) $attachment['url'];
			if ( isset( $used_ids[ $id ] ) || isset( $excluded_ids[ $id ] ) || isset( $excluded_mimes[ $mime_type ] ) ) {
				continue;
			}

			if ( '' !== $url && str_contains( $text_blob, $url ) ) {
				continue;
			}

			$scan_table->insert(
				array(
					'scanned_at'    => $now,
					'attachment_id' => $id,
					'filename'      => (string) $attachment['filename'],
					'mime_type'     => $mime_type,
					'size_bytes'    => (int) $attachment['size_bytes'],
					'uploaded_at'   => (int) $attachment['uploaded_at'],
				)
			);
			++$unused;
		}

		return array(
			'scanned' => count( $attachments ),
			'unused'  => $unused,
		);
	}

	/**
	 * @return array{purged:int}
	 */
	public function purge_expired(): array {
		$now     = $this->now();
		$table   = $this->table( 'pending_deletions' );
		$purged  = 0;

		foreach ( $table->export_rows() as $row ) {
			$id         = isset( $row['id'] ) && is_numeric( $row['id'] ) ? (int) $row['id'] : 0;
			$deletes_at = isset( $row['deletes_at'] ) && is_numeric( $row['deletes_at'] ) ? (int) $row['deletes_at'] : PHP_INT_MAX;
			$attachment = isset( $row['attachment_id'] ) && is_numeric( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
			if ( $id <= 0 || $attachment <= 0 || $deletes_at > $now ) {
				continue;
			}

			$this->delete_attachment( $attachment );
			$purged += $table->purge( null, array( 'id' => $id ) );
		}

		return array( 'purged' => $purged );
	}

	/**
	 * @return list<array{id:int,filename:string,mime_type:string,size_bytes:int,uploaded_at:int,url:string}>
	 */
	private function attachments(): array {
		$posts = $this->posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
			)
		);
		$attachments = array();

		foreach ( $posts as $post ) {
			$id = $this->post_id( $post );
			if ( $id <= 0 ) {
				continue;
			}

			$file = $this->attached_file( $id );
			$attachments[] = array(
				'id'          => $id,
				'filename'    => $this->attachment_filename( $id, $post, $file ),
				'mime_type'   => $this->attachment_mime_type( $id, $post ),
				'size_bytes'  => $this->attachment_size( $file ),
				'uploaded_at' => $this->post_timestamp( $post ),
				'url'         => $this->attachment_url( $id, $post ),
			);
		}

		usort( $attachments, static fn( array $left, array $right ): int => $left['filename'] <=> $right['filename'] );
		return $attachments;
	}

	/**
	 * @return array<int,true>
	 */
	private function used_attachment_ids(): array {
		$used = array();
		foreach ( $this->posts( array( 'post_status' => 'publish' ) ) as $post ) {
			$id = $this->post_id( $post );
			if ( $id <= 0 ) {
				continue;
			}

			$thumbnail = function_exists( 'get_post_meta' ) ? \get_post_meta( $id, '_thumbnail_id', true ) : '';
			if ( is_numeric( $thumbnail ) && (int) $thumbnail > 0 ) {
				$used[ (int) $thumbnail ] = true;
			}
		}

		return $used;
	}

	private function used_text_blob(): string {
		$parts = array();
		foreach ( $this->posts( array( 'post_status' => 'publish' ) ) as $post ) {
			$content = $this->post_field( $post, 'post_content' );
			if ( '' !== $content ) {
				$parts[] = $content;
			}
		}

		foreach ( $this->widget_option_keys() as $key ) {
			$value = function_exists( 'get_option' ) ? \get_option( $key, null ) : null;
			if ( null !== $value ) {
				$parts[] = $this->json_encode( $value );
			}
		}

		$theme_mods = function_exists( 'get_option' ) ? \get_option( 'theme_mods_' . $this->stylesheet(), null ) : null;
		if ( null !== $theme_mods ) {
			$parts[] = $this->json_encode( $theme_mods );
		}

		foreach ( array( 'siteurl', 'home' ) as $option ) {
			$value = function_exists( 'get_option' ) ? \get_option( $option, '' ) : '';
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$parts[] = (string) $value;
			}
		}

		return implode( "\n", array_filter( $parts, 'is_string' ) );
	}

	/**
	 * @return string[]
	 */
	private function widget_option_keys(): array {
		$keys = array( 'widget_text', 'widget_block', 'sidebars_widgets' );
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = \apply_filters( 'onumia_unused_media_widget_option_keys', $keys );
			if ( is_array( $filtered ) ) {
				$keys = array_values( array_filter( $filtered, 'is_string' ) );
			}
		}

		return $keys;
	}

	/**
	 * @param array<string,mixed> $args Args.
	 * @return list<object|array<string,mixed>|int>
	 */
	private function posts( array $args ): array {
		if ( function_exists( 'get_posts' ) ) {
			$result = \get_posts( $args );
			return is_array( $result ) ? array_values( $result ) : array();
		}

		return array();
	}

	private function post_id( object|array|int $post ): int {
		if ( is_int( $post ) ) {
			return $post;
		}

		$id = is_array( $post ) ? ( $post['ID'] ?? 0 ) : ( $post->ID ?? 0 );
		return is_numeric( $id ) ? (int) $id : 0;
	}

	private function post_field( object|array|int $post, string $field ): string {
		if ( is_int( $post ) ) {
			return '';
		}

		$value = is_array( $post ) ? ( $post[ $field ] ?? '' ) : ( $post->{$field} ?? '' );
		return is_scalar( $value ) ? (string) $value : '';
	}

	private function post_timestamp( object|array|int $post ): int {
		$date = $this->post_field( $post, 'post_date_gmt' );
		if ( '' === $date || '0000-00-00 00:00:00' === $date ) {
			$date = $this->post_field( $post, 'post_date' );
		}

		$timestamp = '' === $date ? 0 : strtotime( $date . ' UTC' );
		return false === $timestamp ? 0 : $timestamp;
	}

	private function attached_file( int $attachment_id ): string {
		$file = function_exists( 'get_attached_file' ) ? \get_attached_file( $attachment_id ) : false;
		return is_string( $file ) ? $file : '';
	}

	private function attachment_filename( int $attachment_id, object|array|int $post, string $file ): string {
		if ( '' !== $file ) {
			return substr( basename( $file ), 0, 255 );
		}

		$title = $this->post_field( $post, 'post_title' );
		return '' === $title ? 'attachment-' . (string) $attachment_id : substr( $title, 0, 255 );
	}

	private function attachment_mime_type( int $attachment_id, object|array|int $post ): string {
		if ( function_exists( 'get_post_mime_type' ) ) {
			$mime = \get_post_mime_type( $attachment_id );
			if ( is_string( $mime ) && '' !== $mime ) {
				return substr( $mime, 0, 64 );
			}
		}

		return substr( $this->post_field( $post, 'post_mime_type' ), 0, 64 );
	}

	private function attachment_size( string $file ): int {
		return '' !== $file && is_file( $file ) ? max( 0, (int) filesize( $file ) ) : 0;
	}

	private function attachment_url( int $attachment_id, object|array|int $post ): string {
		$url = function_exists( 'wp_get_attachment_url' ) ? \wp_get_attachment_url( $attachment_id ) : false;
		if ( is_string( $url ) ) {
			return $url;
		}

		return $this->post_field( $post, 'guid' );
	}

	/**
	 * @return array<int,true>
	 */
	private function excluded_attachment_ids(): array {
		$ids = array();
		foreach ( $this->integer_list( $this->setting( 'excludeAttachmentIds' ) ) as $id ) {
			$ids[ $id ] = true;
		}

		return $ids;
	}

	/**
	 * @return array<string,true>
	 */
	private function excluded_mime_types(): array {
		$mimes = array();
		$value = $this->setting( 'excludeMimeTypes' );
		foreach ( is_array( $value ) ? $value : array() as $mime ) {
			if ( is_string( $mime ) && '' !== $mime ) {
				$mimes[ $mime ] = true;
			}
		}

		return $mimes;
	}

	private function pending_window_days(): int {
		$value = $this->setting( 'pendingWindowDays' );
		return is_numeric( $value ) ? max( 1, min( 90, (int) $value ) ) : 7;
	}

	private function scan_schedule_enabled(): bool {
		return $this->enabled() && 'manual' !== $this->scan_frequency();
	}

	private function scan_frequency(): string {
		$value = $this->setting( 'scanFrequency' );
		return is_string( $value ) && in_array( $value, array( 'daily', 'weekly', 'manual' ), true ) ? $value : 'weekly';
	}

	private function sync_schedules( bool $force ): void {
		$this->sync_scan_schedule( $force );
		$this->sync_purge_schedule( $force );
	}

	private function sync_scan_schedule( bool $force ): void {
		if ( ! $this->scan_schedule_enabled() ) {
			$this->clear_schedule( self::SCAN_HOOK );
			return;
		}

		if ( $force ) {
			$this->clear_schedule( self::SCAN_HOOK );
		} elseif ( false !== $this->next_scheduled( self::SCAN_HOOK ) ) {
			return;
		}

		if ( function_exists( 'wp_schedule_event' ) ) {
			\wp_schedule_event( $this->now() + 3600, $this->scan_frequency(), self::SCAN_HOOK );
		}
	}

	private function sync_purge_schedule( bool $force ): void {
		if ( ! $this->enabled() ) {
			$this->clear_schedule( self::PURGE_HOOK );
			return;
		}

		if ( $force ) {
			$this->clear_schedule( self::PURGE_HOOK );
		} elseif ( false !== $this->next_scheduled( self::PURGE_HOOK ) ) {
			return;
		}

		if ( function_exists( 'wp_schedule_event' ) ) {
			\wp_schedule_event( $this->now() + 3600, 'daily', self::PURGE_HOOK );
		}
	}

	private function clear_schedule( string $hook ): void {
		if ( ! function_exists( 'wp_unschedule_event' ) ) {
			return;
		}

		while ( false !== ( $timestamp = $this->next_scheduled( $hook ) ) ) {
			if ( ! \wp_unschedule_event( $timestamp, $hook ) ) {
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

	/**
	 * @param mixed $value Value.
	 * @return list<int>
	 */
	private function integer_list( mixed $value ): array {
		$items = is_array( $value ) ? $value : array();
		$ids   = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$item = $item['value'] ?? null;
			}
			if ( is_numeric( $item ) && (int) $item > 0 ) {
				$ids[] = (int) $item;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function delete_attachment( int $attachment_id ): bool {
		if ( function_exists( 'wp_delete_attachment' ) ) {
			return false !== \wp_delete_attachment( $attachment_id, true );
		}

		return true;
	}

	private function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
	}

	private function day_in_seconds(): int {
		return defined( 'DAY_IN_SECONDS' ) && is_numeric( DAY_IN_SECONDS ) ? (int) DAY_IN_SECONDS : 86400;
	}

	private function stylesheet(): string {
		if ( function_exists( 'get_stylesheet' ) ) {
			$stylesheet = \get_stylesheet();
			return is_string( $stylesheet ) ? $stylesheet : '';
		}

		return 'twentytwentyfive';
	}

	private function json_encode( mixed $value ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$result = \wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
			return is_string( $result ) ? $result : '';
		}

		$result = json_encode( $value, JSON_UNESCAPED_SLASHES );
		return is_string( $result ) ? $result : '';
	}

	private function size_label( int $bytes ): string {
		if ( function_exists( 'size_format' ) ) {
			$label = \size_format( $bytes, 1 );
			return is_string( $label ) ? $label : (string) $bytes . ' B';
		}

		return $bytes >= 1024 ? round( $bytes / 1024, 1 ) . ' KB' : $bytes . ' B';
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function scan_row_for_display( array $row ): array {
		$size     = isset( $row['size_bytes'] ) && is_numeric( $row['size_bytes'] ) ? (int) $row['size_bytes'] : 0;
		$uploaded = isset( $row['uploaded_at'] ) && is_numeric( $row['uploaded_at'] ) ? (int) $row['uploaded_at'] : 0;
		$scanned  = isset( $row['scanned_at'] ) && is_numeric( $row['scanned_at'] ) ? (int) $row['scanned_at'] : 0;

		return array(
			'id'            => (string) ( $row['id'] ?? '' ),
			'attachmentId'  => isset( $row['attachment_id'] ) && is_numeric( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0,
			'filename'      => (string) ( $row['filename'] ?? '' ),
			'mimeType'      => (string) ( $row['mime_type'] ?? '' ),
			'sizeBytes'     => $size,
			'sizeLabel'     => $this->size_label( $size ),
			'uploadedAt'    => $uploaded,
			'uploadedLabel' => $this->time_label( $uploaded, 'M j, Y H:i' ),
			'scannedAt'     => $scanned,
			'scannedLabel'  => $this->time_label( $scanned, 'M j, Y H:i' ),
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function pending_row_for_display( array $row ): array {
		$attachment_id = isset( $row['attachment_id'] ) && is_numeric( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
		$queued        = isset( $row['queued_at'] ) && is_numeric( $row['queued_at'] ) ? (int) $row['queued_at'] : 0;
		$deletes       = isset( $row['deletes_at'] ) && is_numeric( $row['deletes_at'] ) ? (int) $row['deletes_at'] : 0;
		$user_id       = isset( $row['queued_by_user_id'] ) && is_numeric( $row['queued_by_user_id'] ) ? (int) $row['queued_by_user_id'] : 0;

		return array(
			'id'             => (string) ( $row['id'] ?? '' ),
			'attachmentId'   => $attachment_id,
			'filename'       => $this->pending_filename( $attachment_id ),
			'queuedAt'       => $queued,
			'queuedAtLabel'  => $this->time_label( $queued, 'M j, Y H:i' ),
			'deletesAt'      => $deletes,
			'deletesAtLabel' => $this->time_label( $deletes, 'M j, Y H:i' ),
			'queuedBy'       => $user_id,
			'queuedByLabel'  => $this->user_label( $user_id ),
		);
	}

	private function pending_filename( int $attachment_id ): string {
		$file = $this->attached_file( $attachment_id );
		if ( '' !== $file ) {
			return basename( $file );
		}

		foreach ( $this->table( 'scan_results' )->export_rows() as $row ) {
			if ( isset( $row['attachment_id'], $row['filename'] ) && (int) $row['attachment_id'] === $attachment_id && is_string( $row['filename'] ) && '' !== $row['filename'] ) {
				return $row['filename'];
			}
		}

		foreach ( $this->attachments() as $attachment ) {
			if ( $attachment['id'] === $attachment_id ) {
				return $attachment['filename'];
			}
		}

		return 'Attachment ' . (string) $attachment_id;
	}

	private function user_label( int $user_id ): string {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return '';
		}

		$user = \get_userdata( $user_id );
		if ( ! is_object( $user ) ) {
			return (string) $user_id;
		}

		$name = is_string( $user->display_name ?? null ) ? $user->display_name : '';
		return '' === $name ? (string) $user_id : $name;
	}
}
