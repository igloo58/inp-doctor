<?php
/**
 * Safe, reversible fixes:
 * - Passive listeners (limited scope)
 * - Off-screen content-visibility:auto
 * - Viewport meta guard (insert only if missing)
 *
 * @package INPDoctor
 */

declare(strict_types=1);

final class INPD_Fixes {
	const OPT_PASSIVE  = 'inpd_fix_passive';
	const OPT_CONTENTV = 'inpd_fix_contentvis';
	const OPT_VIEWPORT = 'inpd_fix_viewport';

	public function hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'wp_head', [ $this, 'emit_inline_js' ], 20 ); // after most theme head output
	}

	public function menu(): void {
		add_submenu_page(
			'inpd',
			__( 'Safe Fixes', 'inpd' ),
			__( 'Safe Fixes', 'inpd' ),
			'manage_options',
			'inpd-fixes',
			[ $this, 'render' ]
		);
	}

	public function register(): void {
		// Seed defaults on first run (all enabled).
		if ( false === get_option( self::OPT_PASSIVE, false ) ) {
			update_option( self::OPT_PASSIVE, true, false );
		}
		if ( false === get_option( self::OPT_CONTENTV, false ) ) {
			update_option( self::OPT_CONTENTV, true, false );
		}
		if ( false === get_option( self::OPT_VIEWPORT, false ) ) {
			update_option( self::OPT_VIEWPORT, true, false );
		}

		register_setting( 'inpd_fixes', self::OPT_PASSIVE, [
			'type'              => 'boolean',
			'sanitize_callback' => static fn( $v ) => (bool) $v,
			'default'           => true,
		] );
		register_setting( 'inpd_fixes', self::OPT_CONTENTV, [
			'type'              => 'boolean',
			'sanitize_callback' => static fn( $v ) => (bool) $v,
			'default'           => true,
		] );
		register_setting( 'inpd_fixes', self::OPT_VIEWPORT, [
			'type'              => 'boolean',
			'sanitize_callback' => static fn( $v ) => (bool) $v,
			'default'           => true,
		] );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'inpd' ) );
		}

		$passive  = (bool) get_option( self::OPT_PASSIVE, true );
		$contentv = (bool) get_option( self::OPT_CONTENTV, true );
		$viewport = (bool) get_option( self::OPT_VIEWPORT, true );

		echo '<div class="wrap"><h1>' . esc_html__( 'Safe Fixes', 'inpd' ) . '</h1>';
		echo '<form action="' . esc_url( admin_url( 'options.php' ) ) . '" method="post">';
		settings_fields( 'inpd_fixes' );
		echo '<table class="form-table" role="presentation"><tbody>';

                echo '<tr><th>' . esc_html__( 'Passive listeners (safe scope)', 'inpd' ) . '</th><td>';
                echo '<input type="hidden" name="' . esc_attr( self::OPT_PASSIVE ) . '" value="0" />';
                echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_PASSIVE ) . '" value="1" ' . checked( $passive, true, false ) . ' /> ';
		echo esc_html__( 'Set passive listeners for scroll/wheel on window/document only (non-breaking).', 'inpd' ) . '</label></td></tr>';

                echo '<tr><th>' . esc_html__( 'Off-screen content-visibility', 'inpd' ) . '</th><td>';
                echo '<input type="hidden" name="' . esc_attr( self::OPT_CONTENTV ) . '" value="0" />';
                echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_CONTENTV ) . '" value="1" ' . checked( $contentv, true, false ) . ' /> ';
		echo esc_html__( 'Apply content-visibility:auto to obvious large sections below the fold.', 'inpd' ) . '</label></td></tr>';

                echo '<tr><th>' . esc_html__( 'Viewport meta guard', 'inpd' ) . '</th><td>';
                echo '<input type="hidden" name="' . esc_attr( self::OPT_VIEWPORT ) . '" value="0" />';
                echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_VIEWPORT ) . '" value="1" ' . checked( $viewport, true, false ) . ' /> ';
		echo esc_html__( 'Inject a viewport meta only if the page is missing one.', 'inpd' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save Changes', 'inpd' ) );
		echo '</form></div>';
	}

	/**
	 * Emit a single inline JS payload implementing the fixes that are enabled.
	 * Uses JS to avoid duplicating a viewport meta if one already exists and to scope changes safely.
	 */
	public function emit_inline_js(): void {
		if ( is_admin() ) {
			return;
		}

		$want_passive  = (bool) get_option( self::OPT_PASSIVE, true );
		$want_contentv = (bool) get_option( self::OPT_CONTENTV, true );
		$want_viewport = (bool) get_option( self::OPT_VIEWPORT, true );

		if ( ! $want_passive && ! $want_contentv && ! $want_viewport ) {
			return;
		}

		$chunks = [];

		if ( $want_passive ) {
			$chunks[] = <<<JS
(function(){
  if (!window.addEventListener) return;
  try { // feature-detect passive support
    var supported=false;
    var opts=Object.defineProperty({}, "passive", { get:function(){ supported=true; } });
    window.addEventListener("test", function(){}, opts);
    window.removeEventListener("test", function(){}, opts);
    if (!supported) return;
  } catch(e){ return; }

  var orig = EventTarget.prototype.addEventListener;
  var PASSIVE = { passive: true };
  var SAFE = { scroll:1, wheel:1 };
  EventTarget.prototype.addEventListener = function(type, listener, options){
    try {
      if ((this===window || this===document) && SAFE[type]) {
        if (options===undefined || options===false) return orig.call(this, type, listener, PASSIVE);
        if (options && typeof options==="object" && !("passive" in options)) { options.passive=true; return orig.call(this, type, listener, options); }
      }
    } catch(e){}
    return orig.call(this, type, listener, options);
  };
})();
JS;
		}

		if ( $want_contentv ) {
			$chunks[] = <<<JS
(function(){
  try {
    var s=document.createElement("style");
    s.textContent=".inpd-cv{content-visibility:auto;contain-intrinsic-size:1px 1000px}";
    document.head.appendChild(s);

    var nodes=document.querySelectorAll(".wp-block-cover, .wp-block-group, main > section, article, .entry-content > *");
    var cutoff = window.innerHeight * 0.75;
    for (var i=0;i<nodes.length;i++){
      var el=nodes[i];
      if (!el || el.offsetParent===null) continue; // skip hidden
      var r=el.getBoundingClientRect();
      if (r.top < cutoff) continue; // near viewport
      el.classList.add("inpd-cv");
    }
  } catch(e){}
})();
JS;
		}

		if ( $want_viewport ) {
			$chunks[] = <<<JS
(function(){
  try {
    if (!document.querySelector('meta[name="viewport"]')) {
      var m=document.createElement("meta");
      m.name="viewport";
      m.content="width=device-width, initial-scale=1";
      if (document.head && document.head.firstChild && document.head.firstChild.parentNode===document.head && document.head.prepend) {
        document.head.prepend(m);
      } else if (document.head) {
        document.head.insertBefore(m, document.head.firstChild||null);
      }
    }
  } catch(e){}
})();
JS;
		}

		$payload = implode( "\n", $chunks );

		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core handles escaping.
			wp_print_inline_script_tag( $payload, [ 'id' => 'inpd-fixes' ] );
		} else {
			// WP <5.7 fallback.
			echo '<script id="inpd-fixes">' . $payload . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
