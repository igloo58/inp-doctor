=== INP Doctor ===
Contributors: your-wporg-username
Tags: performance, core web vitals, INP
Requires at least: 6.3
Tested up to: 6.8.3
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Measure & fix Interaction to Next Paint (INP) with safe, reversible optimizations.

== Description ==
**INP Doctor** helps you diagnose and reduce Interaction to Next Paint (INP) delays safely:
- Local RUM (anonymous) with INP attribution
- “Top Offenders” dashboard with URL/selector drill-down + CSV export
- Safe fixes: passive listeners, content-visibility for offscreen, viewport meta guard
- Script defer presets (conservative, dependency-aware)
- Speculative Loading (WP 6.8+): same-origin link prefetch on hover/viewport (no prerender)

Privacy: by default, data stays **on your site**. No cookies, no IPs, and no external telemetry.

**Pro** adds per-handle/per-selector controls, analytics worker offloading presets, and advanced Speculation UI.  
Small notice only: see the footer link in plugin screens.

== Installation ==
1. Upload the `inp-doctor` folder to `/wp-content/plugins/`, or install via Plugins → Add New.
2. Activate **INP Doctor**.
3. Visit **INP Doctor → Top Offenders** to see data (as traffic accrues).
4. Optional: enable **Speculative Loading** (WP 6.8+) and **Safe Fixes** toggles.

== Frequently Asked Questions ==
= Does this send data anywhere? =
No. All RUM events are stored locally in your database (30-day retention). No cookies, no IP addresses.

= Which WordPress/PHP versions are supported? =
WordPress **6.3+** and PHP **8.0+**. Speculation Rules require WordPress **6.8+**.

= Is it safe to enable the “Script defer (presets)” toggle? =
Yes. It uses a conservative denylist and respects dependencies, admin bar, and scripts already marked async/defer/module.

== Screenshots ==
1. Top Offenders with p75, average, worst, events, and example URL
2. Safe Fixes toggles (passive, content-visibility, viewport, defer presets)
3. Speculative Loading settings (excludes with simple patterns)

== Changelog ==
= 1.0.0 =
Initial release.

== Upgrade Notice ==
= 1.0.0 =
First public release: RUM + Top Offenders + Safe Fixes + Speculation (prefetch).
