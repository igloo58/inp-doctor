<?php
/**
 * REST API endpoints.
 *
 * Filters documented here:
 * - inpd/rest/max_payload_bytes (int, default 65536)
 * - inpd/rest/max_batch (int, default 100)
 * - inpd/rest/allowed_origins (array of origins, default [ site origin ])
 * - inpd/rest/throttle_max_per_min (int, default 300)
 * - inpd/rest/accept_future_seconds (int, default 300)
 * - inpd/rest/max_selector_len (int, default 255)
 * - inpd/rest/max_script_url_len (int, default 255)
 * - inpd/rest/max_page_path_len (int, default 2048)
 *
 * @package INPDoctor
 */

declare( strict_types=1 );

final class INPD_REST {
	private const NAMESPACE = 'inpd/v1';

	public function hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/event',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_event' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'token'  => [
						'type'     => 'string',
						'required' => true,
					],
					'events' => [
						'type'     => 'array',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * POST /inpd/v1/event
	 * Body: { token: string, events: Array<...> }
	 */
	public function handle_event( \WP_REST_Request $request ): \WP_REST_Response {
		$max_bytes = (int) apply_filters( 'inpd/rest/max_payload_bytes', 65536 );
		$raw       = (string) $request->get_body();
		if ( strlen( $raw ) > $max_bytes ) {
			return new \WP_REST_Response( [ 'error' => 'payload_too_large' ], 413 );
		}

		$home   = rtrim( (string) home_url( '/' ), '/' );
		$hp     = wp_parse_url( $home );
		$origin = ( $hp['scheme'] ?? 'http' ) . '://' . ( $hp['host'] ?? '' ) . ( isset( $hp['port'] ) ? ':' . $hp['port'] : '' );

		$hdr_origin  = (string) $request->get_header( 'origin' );
		$hdr_referer = (string) $request->get_header( 'referer' );

		$allowed = (array) apply_filters( 'inpd/rest/allowed_origins', [ $origin ] );
		$same    = ( 0 === strpos( $hdr_origin, $origin ) ) || ( 0 === strpos( $hdr_referer, $origin ) );
		$ok      = $same || ( $hdr_origin && in_array( $hdr_origin, $allowed, true ) );

		if ( ! $ok ) {
			return new \WP_REST_Response( [ 'error' => 'forbidden_origin' ], 403 );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'invalid_json' ], 400 );
		}

		$token  = isset( $data['token'] ) ? (string) $data['token'] : '';
		$events = ( isset( $data['events'] ) && is_array( $data['events'] ) ) ? $data['events'] : [];

		if ( $token !== INPD_Plugin::public_token() ) {
			return new \WP_REST_Response( [ 'error' => 'bad_token' ], 403 );
		}

		$max_batch = (int) apply_filters( 'inpd/rest/max_batch', 100 );
		if ( empty( $events ) || count( $events ) > $max_batch ) {
			return new \WP_REST_Response( [ 'error' => 'invalid_batch' ], 400 );
		}

		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		$key  = 'inpd_throttle_' . md5( $token . '|' . $ip );
		$used = (int) get_transient( $key );
		$maxp = (int) apply_filters( 'inpd/rest/throttle_max_per_min', 300 );
		if ( $used >= $maxp ) {
			return new \WP_REST_Response( [ 'error' => 'throttled' ], 429 );
		}

		$clean     = [];
		$max_sel   = (int) apply_filters( 'inpd/rest/max_selector_len', 255 );
		$max_src   = (int) apply_filters( 'inpd/rest/max_script_url_len', 255 );
		$max_path  = (int) apply_filters( 'inpd/rest/max_page_path_len', 2048 );
		$time_skew = (int) apply_filters( 'inpd/rest/accept_future_seconds', 300 );
		$now       = time();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$timestamp = isset( $event['t'] ) ? (int) $event['t'] : 0;
			$url       = isset( $event['u'] ) ? (string) $event['u'] : '';
			$type      = isset( $event['type'] ) ? (string) $event['type'] : '';
			$selector  = isset( $event['sel'] ) ? (string) $event['sel'] : '';
			$inp       = isset( $event['inp'] ) ? (int) $event['inp'] : -1;
			$long_task = array_key_exists( 'lt', $event ) ? ( ( null === $event['lt'] ) ? null : (int) $event['lt'] ) : null;
			$script    = isset( $event['src'] ) ? (string) $event['src'] : '';
			$device    = isset( $event['dev'] ) ? (string) $event['dev'] : 'other';
			$sample    = isset( $event['sr'] ) ? (int) $event['sr'] : 100;

			if ( $timestamp <= 0 || $timestamp > ( $now + $time_skew ) ) {
				continue;
			}
			if ( $inp < 0 || $inp > 120000 ) {
				continue;
			}
			if ( null !== $long_task && ( $long_task < 0 || $long_task > 120000 ) ) {
				$long_task = null;
			}

			if ( strlen( $selector ) > $max_sel ) {
				$selector = substr( $selector, 0, $max_sel );
			}
			if ( strlen( $script ) > $max_src ) {
				$script = substr( $script, 0, $max_src );
			}

			if ( ! in_array( $device, [ 'desktop', 'mobile', 'tablet', 'other' ], true ) ) {
				$device = 'other';
			}

			if ( $sample < 1 || $sample > 100 ) {
				$sample = 100;
			}

			$page = $url;
			if ( '' === $page ) {
				continue;
			}
			if ( 0 === strpos( $page, $origin ) ) {
				$page = substr( $page, strlen( $origin ) );
			}
			if ( '' === $page || '/' !== $page[0] ) {
				continue;
			}
			if ( strlen( $page ) > $max_path ) {
				$page = substr( $page, 0, $max_path );
			}

			$clean[] = [
				'ts'               => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'page_url'         => $page,
				'interaction_type' => substr( $type, 0, 32 ),
				'target_selector'  => $selector,
				'inp_ms'           => $inp,
				'long_task_ms'     => $long_task,
				'script_url'       => $script,
				'device_type'      => $device,
				'sample_rate'      => $sample,
			];
		}

		if ( empty( $clean ) ) {
			return new \WP_REST_Response( [ 'error' => 'no_valid_events' ], 400 );
		}

		$clean = (array) apply_filters( 'inpd/rum/intake_events', $clean, [
			'ip'     => $ip,
			'origin' => $hdr_origin ?: $hdr_referer,
		] );

		global $wpdb;
		$table    = INPD_Plugin::table();
		$inserted = 0;
		$formats  = [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d' ];

		foreach ( $clean as $row ) {
			$ok = $wpdb->insert(
				$table,
				[
					'ts'               => $row['ts'],
					'page_url'         => $row['page_url'],
					'interaction_type' => $row['interaction_type'],
					'target_selector'  => $row['target_selector'],
					'inp_ms'           => $row['inp_ms'],
					'long_task_ms'     => $row['long_task_ms'],
					'script_url'       => $row['script_url'],
					'ua_family_hash'   => null,
					'device_type'      => $row['device_type'],
					'sample_rate'      => $row['sample_rate'],
				],
				$formats
			);

			if ( $ok ) {
				$inserted++;
			}
		}

		set_transient( $key, $used + $inserted, 60 );

		return new \WP_REST_Response( [ 'ok' => true, 'accepted' => $inserted ], 200 );
	}
}
