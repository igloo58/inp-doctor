<?php
/**
 * REST API endpoints.
 *
 * @package INPDoctor
 */

declare( strict_types=1 );

final class INPD_REST {
	private const NAMESPACE = 'inpd/v1';

	public function hooks(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/event',
			[
				'methods' => 'POST',
				'callback' => [ $this, 'intake' ],
				'permission_callback' => '__return_true',
				'args' => [
					'token' => [ 'required' => false ],
					'events' => [ 'required' => true ],
				],
			]
		);
	}

	/**
	 * POST /inpd/v1/event
	 * Body: { token?: string, events: Array<...> }
	 */
	public function intake( \WP_REST_Request $req ): \WP_REST_Response {
		// Basic same-origin guard.
		$origin = $req->get_header( 'origin' );
		if ( $origin && wp_parse_url( home_url(), PHP_URL_HOST ) !== wp_parse_url( $origin, PHP_URL_HOST ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'err' => 'forbidden-origin' ], 403 );
		}

		// Token must match our ephemeral public token.
		$token = (string) ( $req->get_param( 'token' ) ?? $req->get_header( 'x-inpd-token' ) ?? '' );
		if ( $token !== INPD_Plugin::public_token() ) {
			return new \WP_REST_Response( [ 'ok' => false, 'err' => 'bad-token' ], 403 );
		}

		$events = $req->get_param( 'events' );
		if ( ! is_array( $events ) || empty( $events ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'err' => 'no-events' ], 400 );
		}

		// Limit payload size (simple abuse protection).
		if ( count( $events ) > 100 ) {
			$events = array_slice( $events, 0, 100 );
		}

		global $wpdb;
		$table = INPD_Plugin::table();
		$inserted = 0;

		foreach ( $events as $event ) {
			// Defensive parsing.
			$timestamp = isset( $event['t'] ) ? (int) $event['t'] : time();
			$url = isset( $event['u'] ) ? (string) $event['u'] : '/';
			$type = isset( $event['type'] ) ? (string) $event['type'] : 'click';
			$selector = isset( $event['sel'] ) ? substr( (string) $event['sel'], 0, 255 ) : '';
			$inp = max( 0, (int) ( $event['inp'] ?? 0 ) );
			$long_task = isset( $event['lt'] ) ? max( 0, (int) $event['lt'] ) : null;
			$script = isset( $event['src'] ) ? substr( (string) $event['src'], 0, 255 ) : null;
			$device = in_array( ( $event['dev'] ?? 'other' ), [ 'desktop', 'mobile', 'tablet', 'other' ], true ) ? $event['dev'] : 'other';
			$sample = isset( $event['sr'] ) ? (int) $event['sr'] : 100;

			$wpdb->insert(
				$table,
				[
					'ts' => gmdate( 'Y-m-d H:i:s', $timestamp ),
					'page_url' => $url,
					'interaction_type' => $type,
					'target_selector' => $selector,
					'inp_ms' => $inp,
					'long_task_ms' => $long_task,
					'script_url' => $script,
					'ua_family_hash' => null, // Reserved (may add UA family hashing later).
					'device_type' => $device,
					'sample_rate' => $sample,
				],
				[
					'%s',
					'%s',
					'%s',
					'%s',
					'%d',
					'%d',
					'%s',
					'%s',
					'%s',
					'%d',
				]
			);

			if ( $wpdb->rows_affected > 0 ) {
				$inserted++;
			}
		}

		return new \WP_REST_Response( [ 'ok' => true, 'n' => $inserted ], 200 );
	}
}
