<?php
/**
 * Speculative Loading (Speculation Rules) — prefetch-only defaults for WP ≥ 6.8.
 *
 * @package INPDoctor
 */

declare(strict_types=1);

final class INPD_Speculation {
	const OPT_ENABLE   = 'inpd_spec_enabled';
	const OPT_EXCLUDES = 'inpd_spec_excludes'; // array of patterns (simple contains or path prefixes)

	/**
	 * Hook all actions/filters.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_head', [ $this, 'emit_rules' ], 2 );
	}

	/**
	 * Whether the current site/core supports our conservative rules.
	 */
	public static function supported(): bool {
		return version_compare( get_bloginfo( 'version' ), '6.8', '>=' );
	}

	/**
	 * Admin submenu.
	 */
	public function menu(): void {
		add_submenu_page(
			'inpd',
			__( 'Speculative Loading', 'inpd' ),
			__( 'Speculative Loading', 'inpd' ),
			'manage_options',
			'inpd-speculation',
			[ $this, 'render' ]
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings(): void {
		// Defaults: enabled on WP ≥ 6.8; otherwise false (no-op).
		if ( false === get_option( self::OPT_ENABLE, false ) ) {
			update_option( self::OPT_ENABLE, self::supported(), false );
		}
		if ( false === get_option( self::OPT_EXCLUDES, false ) ) {
			// Sensible conservative defaults.
			update_option(
				self::OPT_EXCLUDES,
				[
					'/checkout',   // Woo checkout
					'/cart',       // Woo cart
					'/my-account', // account pages
					'/wp-admin',   // admin
					'?',           // any querystring
				],
				false
			);
		}

		register_setting(
			'inpd_spec_group',
			self::OPT_ENABLE,
			[
				'type'              => 'boolean',
				'sanitize_callback' => static function ( $v ) {
					return (bool) $v;
				},
				'default'           => self::supported(),
				'show_in_rest'      => false,
			]
		);

		register_setting(
			'inpd_spec_group',
			self::OPT_EXCLUDES,
			[
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'sanitize_excludes' ],
				'default'           => [],
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * Convert a newline-separated string or array to a clean array of patterns.
	 *
	 * @param mixed $raw Raw input.
	 * @return array
	 */
        public static function sanitize_excludes( $raw ): array {
                $items = [];

                if ( is_array( $raw ) ) {
                        array_walk_recursive(
                                $raw,
                                static function ( $value ) use ( &$items ) {
                                        $parts = preg_split( '/\r\n|\r|\n/', (string) $value );
                                        if ( false === $parts ) {
                                                $parts = [ (string) $value ];
                                        }
                                        $items   = array_merge( $items, $parts );
                                }
                        );
                } else {
                        $parts = preg_split( '/\r\n|\r|\n/', (string) $raw );
                        if ( false !== $parts ) {
                                $items = $parts;
                        }
                }

                $out = [];
                foreach ( (array) $items as $p ) {
                        $p = trim( (string) $p );
                        if ( '' === $p ) {
                                continue;
                        }
                        if ( strlen( $p ) > 200 ) {
				$p = substr( $p, 0, 200 );
			}
			$out[] = $p;
		}
		$out = array_values( array_unique( $out ) );
		return $out;
	}

	/**
	 * Render admin UI.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'inpd' ) );
		}

		$enabled  = (bool) get_option( self::OPT_ENABLE, self::supported() );
		$excludes = (array) get_option( self::OPT_EXCLUDES, [] );

		echo '<div class="wrap"><h1>' . esc_html__( 'Speculative Loading', 'inpd' ) . '</h1>';

		if ( ! self::supported() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Speculation Rules require WordPress 6.8 or newer. This site will no-op safely on older versions.', 'inpd' );
			echo '</p></div>';
		}

		echo '<form action="' . esc_url( admin_url( 'options.php' ) ) . '" method="post">';
		settings_fields( 'inpd_spec_group' );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Enable prefetch (same-origin)', 'inpd' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_ENABLE ) . '" value="1" ' . checked( $enabled, true, false ) . ' />';
		echo ' ' . esc_html__( 'Enable conservative link prefetch on hover/viewport (no prerender).', 'inpd' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Exclude patterns', 'inpd' ) . '</th><td>';
		echo '<p>' . esc_html__( 'One per line. Simple contains or path prefix. Examples: /checkout, /cart, /my-account, ?, /wp-admin', 'inpd' ) . '</p>';
		echo '<textarea name="' . esc_attr( self::OPT_EXCLUDES ) . '[]" rows="8" cols="60" style="width: 420px;">' . esc_textarea( implode( "\n", $excludes ) ) . '</textarea>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save Changes', 'inpd' ) );
		echo '</form>';

		echo '<p><em>' . esc_html__( 'Notes: Prefetch is limited to same-origin links. Prerender remains OFF by default. Excludes are applied as CSS selectors for safety.', 'inpd' ) . '</em></p>';

		echo '</div>';
	}

	/**
	 * Emit a <script type="speculationrules"> with a conservative prefetch rule.
	 */
	public function emit_rules(): void {
		if ( is_admin() || is_feed() || is_robots() ) {
			return;
		}
		if ( ! self::supported() ) {
			return;
		}
		$enabled = (bool) get_option( self::OPT_ENABLE, self::supported() );
		if ( ! $enabled ) {
			return;
		}

		$excludes = (array) get_option( self::OPT_EXCLUDES, [] );

		// Build a combined "not selector" from excludes: e.g. a[href*="?"],
		// a[href*="/checkout"], a[href^="/wp-admin"], etc.
		$not_parts = [ 'a[rel~="nofollow"]', 'a[target="_blank"]' ];

		foreach ( $excludes as $p ) {
			$p = trim( (string) $p );
			if ( '' === $p ) {
				continue;
			}
			if ( '?' === $p ) {
				$not_parts[] = 'a[href*="?"]';
				continue;
			}
			// If looks like a path prefix, use ^=, else contains.
			if ( '/' === $p[0] ) {
				$not_parts[] = 'a[href^="' . esc_attr( $p ) . '"]';
			} else {
				$not_parts[] = 'a[href*="' . esc_attr( $p ) . '"]';
			}
		}

                $same_origin_selectors = [ 'a[href^="/"]' ];

                $home_variants = array_unique(
                        array_filter(
                                [
                                        untrailingslashit( home_url() ),
                                        untrailingslashit( home_url( '', 'https' ) ),
                                        untrailingslashit( home_url( '', 'http' ) ),
                                ]
                        )
                );

                foreach ( $home_variants as $home_base ) {
                        $encoded = esc_attr( $home_base );
                        $same_origin_selectors[] = 'a[href="' . $encoded . '"]';
                        $same_origin_selectors[] = 'a[href^="' . $encoded . '/"]';
                        $same_origin_selectors[] = 'a[href^="' . $encoded . '?"]';
                        $same_origin_selectors[] = 'a[href^="' . $encoded . '#"]';
                }

                $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
                if ( $home_path && '/' !== $home_path ) {
                        $same_origin_selectors[] = 'a[href^="' . esc_attr( $home_path ) . '"]';
                }

                $same_origin = [
                        'selector_matches' => implode( ',', array_values( array_unique( $same_origin_selectors ) ) ),
                ];

		$not_selector = implode( ',', $not_parts );

		$rule = [
			'source'    => 'document',
			'where'     => [
				'and' => [
					$same_origin,
					[ 'not' => [ 'selector_matches' => $not_selector ] ],
				],
			],
			'eagerness' => 'conservative', // safer default
		];

		$data = [
			'prefetch' => [ $rule ],
			// No prerender by default.
		];

		echo "\n" . '<script type="speculationrules">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}
}
