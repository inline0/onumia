<?php
/**
 * Email Log module runtime.
 *
 * @package Onumia
 */

declare(strict_types=1);

namespace Onumia\Modules\EmailLog;

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
#[Setting( 'logBody', SettingType::Boolean, default: true )]
#[Setting( 'redactRecipients', SettingType::Boolean, default: false )]
#[Setting( 'retentionDays', SettingType::Integer, default: 30, min: 1, max: 365 )]
final class EmailLog extends Module {
	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
	 */
	#[DataSource( 'emails', shape: DataSourceShape::Collection, pagination: PaginationMode::Server )]
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
	#[Entries( name: 'emailLogEmails', singular: 'Email', plural: 'Emails', key: 'id', storage: EntryStorage::Table, source: 'emails', table: 'emails' )]
	#[EntryField( name: 'id', type: SettingType::String, label: 'ID', primary: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'sentAt', type: SettingType::Integer, label: 'Sent timestamp', filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'sentAtLabel', type: SettingType::String, label: 'Sent at', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'status', type: SettingType::String, label: 'Status', filter: true, filter_type: 'option', allowed: array( 'sent', 'failed' ), create: false, update: false, read_only: true )]
	#[EntryField( name: 'statusLabel', type: SettingType::String, label: 'Status', list: true, create: false, update: false, read_only: true )]
	#[EntryField( name: 'toPreview', type: SettingType::String, label: 'To', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'toRecipients', type: SettingType::String, label: 'To recipients', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'ccRecipients', type: SettingType::String, label: 'CC recipients', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'bccRecipients', type: SettingType::String, label: 'BCC recipients', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'fromAddress', type: SettingType::String, label: 'From', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'replyTo', type: SettingType::String, label: 'Reply-To', filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'subject', type: SettingType::String, label: 'Subject', list: true, filter: true, filter_type: 'text', create: false, update: false, read_only: true )]
	#[EntryField( name: 'bodyText', type: SettingType::String, label: 'Plain text body', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'bodyHtml', type: SettingType::String, label: 'HTML body', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'headersPreview', type: SettingType::String, label: 'Headers', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'attachmentsCount', type: SettingType::Integer, label: 'Attachments', list: true, filter: true, filter_type: 'number', create: false, update: false, read_only: true )]
	#[EntryField( name: 'attachmentsPreview', type: SettingType::String, label: 'Attachment paths', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	#[EntryField( name: 'error', type: SettingType::String, label: 'Error', filter: true, filter_type: 'text', create: false, update: false, read_only: true, props: array( 'multiline' => true ) )]
	public function emails( array $params ): array {
		$rows = array_reverse( $this->table( 'emails' )->export_rows() );
		return $this->paginated_rows( array_map( array( $this, 'email_for_display' ), $rows ), $params );
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{resent:int,ids:list<int>}
	 */
	#[Action( 'resendSelected' )]
	#[Input( 'ids', SettingType::Array, default: array() )]
	public function resend_selected( array $input ): array {
		$ids    = is_array( $input['ids'] ?? null ) ? $input['ids'] : array();
		$resent = array();

		foreach ( $ids as $id ) {
			if ( ! is_numeric( $id ) ) {
				continue;
			}

			$row = $this->table( 'emails' )->find( (int) $id );
			if ( null === $row ) {
				continue;
			}

			$payload = $this->payload_from_row( $row );
			if ( function_exists( 'wp_mail' ) ) {
				\wp_mail( $payload['to'], $payload['subject'], $payload['message'], $payload['headers'], $payload['attachments'] );
			}
			$resent[] = (int) $id;
		}

		return array(
			'resent' => count( $resent ),
			'ids'    => $resent,
		);
	}

	/**
	 * @param array<string,mixed> $input Input.
	 * @return array{sent:bool}
	 */
	#[Action( 'sendTestEmail' )]
	#[Input( 'to', SettingType::String, default: '' )]
	#[Input( 'subject', SettingType::String, default: 'Onumia test email' )]
	#[Input( 'message', SettingType::String, default: 'This message was sent by the Onumia Email Log test action.' )]
	public function send_test_email( array $input ): array {
		$sent = false;
		if ( function_exists( 'wp_mail' ) ) {
			$to = $this->string_value( $input['to'] ?? '' );
			if ( '' === $to && function_exists( 'get_option' ) ) {
				$admin_email = \get_option( 'admin_email', '' );
				$to          = is_string( $admin_email ) ? $admin_email : '';
			}

			$sent = \wp_mail(
				'' === $to ? 'admin@example.test' : $to,
				$this->string_value( $input['subject'] ?? 'Onumia test email', 'Onumia test email' ),
				$this->string_value( $input['message'] ?? 'This message was sent by the Onumia Email Log test action.', 'This message was sent by the Onumia Email Log test action.' )
			);
		}

		return array( 'sent' => $sent );
	}

	/**
	 * @param array<string,mixed> $payload Mail payload.
	 * @return array<string,mixed>
	 */
	#[WpFilter( 'wp_mail', priority: 999, accepted_args: 1 )]
	public function capture_wp_mail_payload( array $payload ): array {
		if ( ! $this->enabled() ) {
			return $payload;
		}

		$this->record_payload( $payload, 'sent', '' );
		return $payload;
	}

	#[WpAction( 'wp_mail_failed', priority: 10, accepted_args: 1 )]
	public function capture_wp_mail_failure( mixed $error ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$message = $this->error_message( $error );
		$data    = $this->error_data( $error );
		$payload = $this->mail_payload_from_mixed( $data );
		if ( array() === $payload ) {
			$payload = array(
				'to'          => array(),
				'subject'     => 'Unknown failed email',
				'message'     => '',
				'headers'     => array(),
				'attachments' => array(),
			);
		}

		$fingerprint = $this->fingerprint( $payload );
		$existing    = $this->table( 'emails' )->recent( 1, null, array( 'fingerprint' => $fingerprint ) );
		if ( array() !== $existing && isset( $existing[0]['id'] ) && is_numeric( $existing[0]['id'] ) ) {
			$now = $this->now();
			$this->table( 'emails' )->update(
				(int) $existing[0]['id'],
				array(
					'occurred_at' => $now,
					'sent_at'     => $now,
					'status'      => 'failed',
					'error'       => substr( $message, 0, 512 ),
				)
			);
			return;
		}

		$this->record_payload( $payload, 'failed', $message );
	}

	#[WpAction( 'onumia_tables_cleanup', priority: 10, accepted_args: 0 )]
	public function prune_runtime_tables(): void {
		$this->table( 'emails' )->purge( $this->retention_days() );
	}

	/**
	 * @param array<string,mixed> $payload Payload.
	 */
	private function record_payload( array $payload, string $status, string $error ): void {
		$payload = $this->normalized_payload( $payload );
		$table   = $this->table( 'emails' );
		$table->purge( $this->retention_days() );

		$now         = $this->now();
		$headers     = $this->parsed_headers( $payload['headers'] );
		$attachments = $this->string_list( $payload['attachments'], unique: false, allow_scalar: true );
		$body        = $this->body_columns( $this->string_value( $payload['message'] ?? '' ), $headers );

		$table->insert(
			array(
				'occurred_at'       => $now,
				'sent_at'           => $now,
				'status'            => in_array( $status, array( 'sent', 'failed' ), true ) ? $status : 'sent',
				'to_recipients'     => $this->recipient_list( $payload['to'] ),
				'cc_recipients'     => $headers['cc'] ?? array(),
				'bcc_recipients'    => $headers['bcc'] ?? array(),
				'from_address'      => $headers['from'][0] ?? '',
				'reply_to'          => $headers['reply-to'][0] ?? '',
				'subject'           => substr( $this->string_value( $payload['subject'] ?? '' ), 0, 255 ),
				'body_text'         => $body['text'],
				'body_html'         => $body['html'],
				'headers'           => $headers,
				'attachments'       => $attachments,
				'attachments_count' => count( $attachments ),
				'error'             => '' === $error ? '' : substr( $error, 0, 512 ),
				'fingerprint'       => $this->fingerprint( $payload ),
			)
		);
	}

	private function log_body(): bool {
		return true === $this->setting( 'logBody' );
	}

	private function redact_recipients(): bool {
		return true === $this->setting( 'redactRecipients' );
	}

	/**
	 * @param array<string,mixed> $payload Payload.
	 * @return array{to:mixed,subject:string,message:string,headers:mixed,attachments:mixed}
	 */
	private function normalized_payload( array $payload ): array {
		return array(
			'to'          => $payload['to'] ?? array(),
			'subject'     => $this->string_value( $payload['subject'] ?? '' ),
			'message'     => $this->string_value( $payload['message'] ?? '' ),
			'headers'     => $payload['headers'] ?? array(),
			'attachments' => $payload['attachments'] ?? array(),
		);
	}

	/**
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private function recipient_list( mixed $value ): array {
		if ( is_string( $value ) ) {
			$parts = preg_split( '/,/', $value );
			$value = false === $parts ? array() : $parts;
		}

		return $this->string_list( $value, unique: false, allow_scalar: true );
	}

	/**
	 * @param mixed $headers Headers.
	 * @return array<string,list<string>>
	 */
	private function parsed_headers( mixed $headers ): array {
		$lines = is_array( $headers ) ? $headers : preg_split( "/\r\n|\n|\r/", $this->string_value( $headers ) );
		$lines = is_array( $lines ) ? $lines : array();

		$parsed = array();
		foreach ( $lines as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}
			$line = trim( (string) $line );
			if ( '' === $line || ! str_contains( $line, ':' ) ) {
				continue;
			}
			$parts = explode( ':', $line, 2 );
			$key   = strtolower( trim( $parts[0] ) );
			$value = trim( $parts[1] );
			if ( '' === $key || '' === $value ) {
				continue;
			}

			if ( in_array( $key, array( 'to', 'cc', 'bcc', 'from', 'reply-to' ), true ) ) {
				$parsed[ $key ] = array_merge( $parsed[ $key ] ?? array(), $this->recipient_list( $value ) );
				continue;
			}

			$parsed[ $key ]   = $parsed[ $key ] ?? array();
			$parsed[ $key ][] = $value;
		}

		return $parsed;
	}

	/**
	 * @param array<string,list<string>> $headers Headers.
	 * @return array{text:string,html:string}
	 */
	private function body_columns( string $message, array $headers ): array {
		if ( ! $this->log_body() ) {
			return array(
				'text' => '',
				'html' => '',
			);
		}

		$content_type = strtolower( implode( ' ', $headers['content-type'] ?? array() ) );
		$is_html      = str_contains( $content_type, 'html' ) || wp_strip_all_tags( $message ) !== $message;

		return array(
			'text' => $is_html ? trim( wp_strip_all_tags( $message ) ) : $message,
			'html' => $is_html ? $message : '',
		);
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function email_for_display( array $row ): array {
		$sent_at     = isset( $row['sent_at'] ) && is_numeric( $row['sent_at'] ) ? (int) $row['sent_at'] : 0;
		$to          = $this->display_recipients( is_array( $row['to_recipients'] ?? null ) ? $row['to_recipients'] : array() );
		$cc          = $this->display_recipients( is_array( $row['cc_recipients'] ?? null ) ? $row['cc_recipients'] : array() );
		$bcc         = $this->display_recipients( is_array( $row['bcc_recipients'] ?? null ) ? $row['bcc_recipients'] : array() );
		$attachments = is_array( $row['attachments'] ?? null ) ? $this->string_list( $row['attachments'], unique: false, allow_scalar: true ) : array();
		$headers     = is_array( $row['headers'] ?? null ) ? $row['headers'] : array();

		return array(
			'id'                 => (string) ( $row['id'] ?? '' ),
			'sentAt'             => $sent_at,
			'sentAtLabel'        => $this->time_label( $sent_at, 'M j, Y H:i' ),
			'status'             => (string) ( $row['status'] ?? '' ),
			'statusLabel'        => ucfirst( (string) ( $row['status'] ?? '' ) ),
			'toPreview'          => $this->recipient_preview( $to ),
			'toRecipients'       => implode( "\n", $to ),
			'ccRecipients'       => implode( "\n", $cc ),
			'bccRecipients'      => implode( "\n", $bcc ),
			'fromAddress'        => $this->display_recipient( (string) ( $row['from_address'] ?? '' ) ),
			'replyTo'            => $this->display_recipient( (string) ( $row['reply_to'] ?? '' ) ),
			'subject'            => (string) ( $row['subject'] ?? '' ),
			'bodyText'           => (string) ( $row['body_text'] ?? '' ),
			'bodyHtml'           => (string) ( $row['body_html'] ?? '' ),
			'headersPreview'     => $this->pretty_json( $headers ),
			'attachmentsCount'   => isset( $row['attachments_count'] ) && is_numeric( $row['attachments_count'] ) ? (int) $row['attachments_count'] : count( $attachments ),
			'attachmentsPreview' => implode( "\n", $attachments ),
			'error'              => (string) ( $row['error'] ?? '' ),
			'fingerprint'        => (string) ( $row['fingerprint'] ?? '' ),
		);
	}

	/**
	 * @param list<string> $recipients Recipients.
	 * @return list<string>
	 */
	private function display_recipients( array $recipients ): array {
		return array_values( array_map( fn( string $recipient ): string => $this->display_recipient( $recipient ), $recipients ) );
	}

	private function display_recipient( string $recipient ): string {
		return $this->redact_recipients() ? $this->redact_email_like_value( $recipient ) : $recipient;
	}

	private function redact_email_like_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( 1 !== preg_match( '/^([^@\s]+)@([^@\s]+)$/', $value, $matches ) ) {
			return '[redacted]';
		}

		$local  = (string) $matches[1];
		$domain = (string) $matches[2];
		$parts  = explode( '.', $domain );
		$host   = (string) ( $parts[0] ?? $domain );
		$tld    = count( $parts ) > 1 ? '.' . (string) end( $parts ) : '';

		return $this->first_char( $local ) . '***@' . $this->first_char( $host ) . '***' . $tld;
	}

	private function first_char( string $value ): string {
		return '' === $value ? '*' : mb_substr( $value, 0, 1 );
	}

	/**
	 * @param list<string> $recipients Recipients.
	 */
	private function recipient_preview( array $recipients ): string {
		if ( array() === $recipients ) {
			return '';
		}

		$extra = count( $recipients ) - 1;
		return $extra > 0 ? "{$recipients[0]} +{$extra} more" : $recipients[0];
	}


	/**
	 * @param array<string,mixed> $row Row.
	 * @return array{to:list<string>,subject:string,message:string,headers:list<string>,attachments:list<string>}
	 */
	private function payload_from_row( array $row ): array {
		$headers = array();
		foreach ( is_array( $row['headers'] ?? null ) ? $row['headers'] : array() as $key => $values ) {
			foreach ( $this->string_list( $values, unique: false, allow_scalar: true ) as $value ) {
				$headers[] = "{$key}: {$value}";
			}
		}

		return array(
			'to'          => is_array( $row['to_recipients'] ?? null ) ? $this->string_list( $row['to_recipients'], unique: false, allow_scalar: true ) : array(),
			'subject'     => (string) ( $row['subject'] ?? '' ),
			'message'     => '' !== (string) ( $row['body_html'] ?? '' ) ? (string) $row['body_html'] : (string) ( $row['body_text'] ?? '' ),
			'headers'     => $headers,
			'attachments' => is_array( $row['attachments'] ?? null ) ? $this->string_list( $row['attachments'], unique: false, allow_scalar: true ) : array(),
		);
	}

	/**
	 * @param array<string,mixed> $payload Payload.
	 */
	private function fingerprint( array $payload ): string {
		$payload = $this->normalized_payload( $payload );
		return sha1(
			implode(
				'|',
				array(
					implode( ',', $this->recipient_list( $payload['to'] ) ),
					$payload['subject'],
					$payload['message'],
					implode( ',', $this->string_list( $payload['attachments'], unique: false, allow_scalar: true ) ),
				)
			)
		);
	}

	private function error_message( mixed $error ): string {
		if ( is_object( $error ) && method_exists( $error, 'get_error_message' ) ) {
			$message = $error->get_error_message();
			return is_string( $message ) ? $message : '';
		}

		return is_scalar( $error ) ? (string) $error : '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function error_data( mixed $error ): array {
		if ( is_object( $error ) && method_exists( $error, 'get_error_data' ) ) {
			$data = $error->get_error_data();
			return is_array( $data ) ? $data : array();
		}

		return array();
	}

	/**
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 */
	private function mail_payload_from_mixed( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$known = array_intersect_key( $value, array_flip( array( 'to', 'subject', 'message', 'headers', 'attachments' ) ) );
		return array() === $known ? array() : $known;
	}

	/**
	 * @param mixed $value Value.
	 */
	private function string_value( mixed $value, string $default = '' ): string {
		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * @param mixed $value Value.
	 */
	private function pretty_json( mixed $value ): string {
		$json = function_exists( 'wp_json_encode' ) ? \wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '{}';
	}
}
