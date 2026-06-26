if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FUTURE ISLAND - Header v9.3
 * Rewrite over v9.2.1. IMPORTANT: deactivate the old v9.2 snippet to avoid a
 * duplicate header (this version uses *_v93 names + .fi-header-v93 markup).
 *
 * What changed:
 *   - FIX (logo box / CLS): removed the transform:scale() hack. The logo now
 *     uses aspect-ratio + a fixed height, so the exact box is reserved before
 *     the PNG decodes. No "big box then pop".
 *   - FIX (header covering body / manual spacers): header is position:fixed and
 *     ships its OWN flow spacer (.fi-header-v93-spacer) whose height equals the
 *     header height via pure CSS. Header height is now CONSTANT (only the bg /
 *     shadow / logo restyle on scroll), so the spacer always matches and content
 *     never hides under the header. You no longer need a per-page spacer.
 *   - MOBILE: redesigned as a minimal full-screen editorial overlay (big display
 *     links, hairline dividers, lime active state) instead of boxed rows.
 *   - LANG: nav, CTA, ticker and ARIA labels are in Spanish.
 *   - POLISH: cleaner background, subtle entrance, refined active/hover states.
 *   - Removed the "Precios" nav item.
 * WPCode Pro ready.
 */

if ( ! function_exists( 'fi_header_nav_config_v93' ) ) {
	function fi_header_nav_config_v93() {
		return [
			'logo_text' => 'Future Island',
			'logo_url'  => home_url( '/' ),
			'logo_img'  => 'https://future-island.club/wp-content/uploads/2026/05/Untitled-design-59-e1779891511378.png',

			'nav_items' => [
				[ 'label' => 'Casos de uso',  'url' => 'https://future-island.club/use-cases/' ],
				[ 'label' => 'Cómo funciona', 'url' => 'https://future-island.club/how-future-island-works/' ],
				[ 'label' => 'Blog',          'url' => 'https://future-island.club/blog/' ],
				[ 'label' => 'FAQ',           'url' => 'https://future-island.club/how-future-island-works/#faq' ],
			],

			'primary_cta' => [
				'label' => 'Solicitar demo',
				'url'   => 'https://future-island.club/demo/',
			],

			'ticker'      => 'Señal → Lectura → Brief',
			'mobile_foot' => 'Una isla, no otro tablero.',
		];
	}
}

if ( ! function_exists( 'fi_header_normalize_path_v93' ) ) {
	function fi_header_normalize_path_v93( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( empty( $path ) ) {
			$path = '/';
		}

		return trailingslashit( $path );
	}
}

if ( ! function_exists( 'fi_header_is_item_active_v93' ) ) {
	/**
	 * Active only when the path matches AND there is no hash fragment. Hash items
	 * (#pricing, #faq) share the parent page path, so they are never "the page".
	 */
	function fi_header_is_item_active_v93( $url, $current_path ) {
		if ( false !== strpos( $url, '#' ) ) {
			return false;
		}

		return fi_header_normalize_path_v93( $url ) === $current_path;
	}
}

if ( ! function_exists( 'fi_header_get_current_path_v93' ) ) {
	function fi_header_get_current_path_v93() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		return fi_header_normalize_path_v93( strtok( $request_uri, '?' ) );
	}
}

if ( ! function_exists( 'fi_header_should_render_v93' ) ) {
	function fi_header_should_render_v93() {
		if ( is_admin() ) {
			return false;
		}

		$current_path = fi_header_get_current_path_v93();

		$hidden_paths = [
			'/ves-saas-scrapers/',
			'/ves-saas-dashboard/',
		];

		if ( in_array( $current_path, $hidden_paths, true ) ) {
			return false;
		}

		$hidden_keywords = [ 'saas', 'projects' ];

		foreach ( $hidden_keywords as $keyword ) {
			if ( false !== strpos( strtolower( $current_path ), strtolower( $keyword ) ) ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'fi_get_header_markup_v93' ) ) {
	function fi_get_header_markup_v93() {
		if ( ! fi_header_should_render_v93() ) {
			return '';
		}

		$c            = fi_header_nav_config_v93();
		$current_path = fi_header_get_current_path_v93();

		ob_start();
		?>
		<header class="fi-header-v93" id="fi-header-v93" role="banner" aria-label="Cabecera principal de Future Island">
			<div class="fi-header-v93__inner">
				<a class="fi-header-v93__brand" href="<?php echo esc_url( $c['logo_url'] ); ?>" aria-label="Inicio de Future Island">
					<img
						class="fi-header-v93__brand-img"
						src="<?php echo esc_url( $c['logo_img'] ); ?>"
						alt="Future Island"
						width="760"
						height="220"
						loading="eager"
						fetchpriority="high"
						decoding="async"
					/>
				</a>

				<nav class="fi-header-v93__nav" aria-label="Navegación principal">
					<ul class="fi-header-v93__menu">
						<?php foreach ( $c['nav_items'] as $item ) :
							$is_active = fi_header_is_item_active_v93( $item['url'], $current_path );
							?>
							<li class="fi-header-v93__menu-item">
								<a
									href="<?php echo esc_url( $item['url'] ); ?>"
									class="fi-header-v93__menu-link<?php echo $is_active ? ' is-active' : ''; ?>"
									<?php echo $is_active ? 'aria-current="page"' : ''; ?>
								>
									<?php echo esc_html( $item['label'] ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>

				<div class="fi-header-v93__actions">
					<a href="<?php echo esc_url( $c['primary_cta']['url'] ); ?>" class="fi-header-v93__btn fi-header-v93__btn--primary">
						<?php echo esc_html( $c['primary_cta']['label'] ); ?>
						<span aria-hidden="true">&rarr;</span>
					</a>
				</div>

				<button
					class="fi-header-v93__toggle"
					type="button"
					aria-label="Abrir menú"
					aria-controls="fi-header-v93-panel"
					aria-expanded="false"
				>
					<span></span>
					<span></span>
				</button>
			</div>

			<div class="fi-header-v93__panel" id="fi-header-v93-panel" aria-hidden="true">
				<div class="fi-header-v93__panel-inner">
					<div class="fi-header-v93__panel-kicker"><?php echo esc_html( $c['ticker'] ); ?></div>

					<nav class="fi-header-v93__panel-nav" aria-label="Navegación móvil">
						<?php foreach ( $c['nav_items'] as $i => $item ) :
							$m_active = fi_header_is_item_active_v93( $item['url'], $current_path );
							?>
							<a
								href="<?php echo esc_url( $item['url'] ); ?>"
								class="fi-header-v93__panel-link<?php echo $m_active ? ' is-active' : ''; ?>"
								style="--i:<?php echo (int) $i; ?>"
								<?php echo $m_active ? 'aria-current="page"' : ''; ?>
							>
								<span class="fi-header-v93__panel-index" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span>
								<span class="fi-header-v93__panel-label"><?php echo esc_html( $item['label'] ); ?></span>
							</a>
						<?php endforeach; ?>
					</nav>

					<div class="fi-header-v93__panel-foot">
						<a href="<?php echo esc_url( $c['primary_cta']['url'] ); ?>" class="fi-header-v93__btn fi-header-v93__btn--primary fi-header-v93__btn--block">
							<?php echo esc_html( $c['primary_cta']['label'] ); ?>
							<span aria-hidden="true">&rarr;</span>
						</a>
						<p class="fi-header-v93__panel-note">F.I — <?php echo esc_html( $c['mobile_foot'] ); ?></p>
					</div>
				</div>
			</div>
		</header>
		<div class="fi-header-v93-spacer" aria-hidden="true"></div>
		<?php
		return ob_get_clean();
	}
}

if ( ! function_exists( 'fi_header_enqueue_assets_v93' ) ) {
	function fi_header_enqueue_assets_v93() {
		if ( ! fi_header_should_render_v93() ) {
			return;
		}

		wp_enqueue_style(
			'fi-header-v93-fonts',
			'https://fonts.googleapis.com/css2?family=Archivo+Black&family=Instrument+Serif:ital@0;1&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap',
			[],
			null
		);
	}
}
add_action( 'wp_enqueue_scripts', 'fi_header_enqueue_assets_v93', 20 );

add_action( 'wp_head', function () {
	if ( ! fi_header_should_render_v93() ) {
		return;
	}
	?>
	<style id="fi-header-v93-styles">
	.fi-header-v93,
	.fi-header-v93 * { box-sizing: border-box; }

	.fi-header-v93 {
		--fi-paper: #ECE8DF;
		--fi-ink: #0F0F0F;
		--fi-ink-2: #141413;
		--fi-bone: #ECE8DF;
		--fi-bone-soft: rgba(236, 232, 223, 0.62);
		--fi-blue: #3A61E1;
		--fi-lime: #D2F050;
		--fi-orange: #F15D31;

		--fi-display: "Archivo Black", "Arial Black", Impact, system-ui, sans-serif;
		--fi-body: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif;
		--fi-serif: "Instrument Serif", Georgia, serif;
		--fi-mono: "IBM Plex Mono", "Courier New", monospace;

		--fi-h: 104px;          /* CONSTANT header height (spacer mirrors this) */
		--fi-logo-h: 60px;      /* tweak this single value to resize the logo   */
		--fi-max: 1540px;

		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		z-index: 99999;
		height: var(--fi-h);
		isolation: isolate;
		color: var(--fi-bone);
		font-family: var(--fi-body);
		-webkit-font-smoothing: antialiased;
		border-bottom: 1px solid rgba(236, 232, 223, 0.09);
		background: linear-gradient(180deg, rgba(15,15,15,0.96), rgba(15,15,15,0.90)), var(--fi-ink);
		backdrop-filter: blur(16px) saturate(125%);
		-webkit-backdrop-filter: blur(16px) saturate(125%);
		box-shadow: 0 1px 0 rgba(236,232,223,0.04);
		animation: fiHeaderIn 0.55s cubic-bezier(0.2, 0.8, 0.2, 1) both;
		transition: background 240ms ease, box-shadow 240ms ease, border-color 240ms ease;
	}

	@keyframes fiHeaderIn {
		from { opacity: 0; transform: translateY(-12px); }
		to   { opacity: 1; transform: none; }
	}

	/* Flow spacer = exact header height. No manual per-page spacers needed. */
	.fi-header-v93-spacer { height: var(--fi-h, 104px); width: 100%; }

	body.admin-bar .fi-header-v93 { top: 32px; }

	.fi-header-v93.is-scrolled {
		background: rgba(13, 13, 12, 0.98);
		border-bottom-color: rgba(236, 232, 223, 0.12);
		box-shadow: 0 16px 44px rgba(0, 0, 0, 0.34);
	}

	/* Subtle, calmer background: faint grid + one soft lime glow. */
	.fi-header-v93::before {
		content: "";
		position: absolute;
		inset: 0;
		z-index: -2;
		pointer-events: none;
		background-image:
			linear-gradient(rgba(236, 232, 223, 0.035) 1px, transparent 1px),
			linear-gradient(90deg, rgba(236, 232, 223, 0.035) 1px, transparent 1px),
			radial-gradient(circle at 88% 50%, rgba(210, 240, 80, 0.10), transparent 32%);
		background-size: 96px 96px, 96px 96px, 100% 100%;
		opacity: 0.7;
	}

	/* Animated hairline at the bottom edge. */
	.fi-header-v93::after {
		content: "";
		position: absolute;
		left: 0; right: 0; bottom: -1px;
		z-index: -1;
		height: 1px;
		background: linear-gradient(90deg, transparent, rgba(210,240,80,0.4), rgba(236,232,223,0.12), transparent);
		opacity: 0.7;
		pointer-events: none;
	}

	.fi-header-v93__inner {
		width: 100%;
		max-width: var(--fi-max);
		height: 100%;
		margin: 0 auto;
		padding: 0 clamp(20px, 4vw, 64px);
		display: grid;
		grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
		align-items: center;
		gap: clamp(16px, 2.5vw, 40px);
	}

	/* ---------- BRAND (zero-CLS logo) ---------- */
	.fi-header-v93__brand {
		grid-column: 1;
		justify-self: start;
		display: inline-flex;
		align-items: center;
		min-width: 0;
		text-decoration: none;
		line-height: 0;
		transition: opacity 180ms ease, transform 180ms ease;
	}
	.fi-header-v93__brand:hover { transform: translateY(-1px); }

	.fi-header-v93__brand-img {
		display: block;
		height: var(--fi-logo-h);
		width: auto;
		aspect-ratio: 760 / 220;   /* reserves the exact box before decode */
		object-fit: contain;
		object-position: left center;
		transition: height 240ms ease, opacity 180ms ease;
	}
	.fi-header-v93.is-scrolled .fi-header-v93__brand-img { height: calc(var(--fi-logo-h) - 8px); }

	/* ---------- DESKTOP NAV ---------- */
	.fi-header-v93__nav { grid-column: 2; justify-self: center; display: flex; align-items: center; min-width: 0; }

	.fi-header-v93__menu {
		list-style: none;
		margin: 0;
		padding: 5px;
		display: flex;
		align-items: center;
		gap: 2px;
		border: 1px solid rgba(236, 232, 223, 0.10);
		border-radius: 999px;
		background: rgba(236, 232, 223, 0.04);
		box-shadow: inset 0 1px 0 rgba(236, 232, 223, 0.06);
		backdrop-filter: blur(10px);
		-webkit-backdrop-filter: blur(10px);
	}

	.fi-header-v93__menu-link {
		position: relative;
		display: inline-flex;
		align-items: center;
		height: 38px;
		padding: 0 15px;
		border-radius: 999px;
		color: var(--fi-bone-soft);
		font-family: var(--fi-mono);
		font-size: 10.5px;
		font-weight: 600;
		line-height: 1;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		text-decoration: none;
		white-space: nowrap;
		transition: color 180ms ease, background 180ms ease;
	}
	.fi-header-v93__menu-link:hover { color: var(--fi-bone); background: rgba(236, 232, 223, 0.07); }
	.fi-header-v93__menu-link.is-active {
		color: var(--fi-ink);
		background: var(--fi-lime);
		font-weight: 700;
		box-shadow: 0 6px 18px rgba(210, 240, 80, 0.22);
	}

	/* ---------- ACTIONS ---------- */
	.fi-header-v93__actions { grid-column: 3; justify-self: end; display: flex; align-items: center; gap: 12px; }

	.fi-header-v93__btn {
		position: relative;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 9px;
		height: 46px;
		padding: 0 22px;
		border-radius: 999px;
		border: 0;
		font-family: var(--fi-mono);
		font-size: 10.5px;
		font-weight: 700;
		letter-spacing: 0.1em;
		line-height: 1;
		text-transform: uppercase;
		text-decoration: none;
		white-space: nowrap;
		cursor: pointer;
		transition: transform 180ms ease, background 180ms ease, color 180ms ease, box-shadow 180ms ease;
	}
	.fi-header-v93__btn--primary {
		color: var(--fi-ink) !important;
		background: var(--fi-lime);
		box-shadow: 0 0 0 1px rgba(210, 240, 80, 0.2), 0 14px 34px rgba(210, 240, 80, 0.18);
	}
	.fi-header-v93__btn--primary:hover {
		background: var(--fi-bone);
		transform: translateY(-2px);
		box-shadow: 0 0 0 1px rgba(236, 232, 223, 0.22), 0 18px 40px rgba(236, 232, 223, 0.16);
	}
	.fi-header-v93__btn--block { width: 100%; height: 54px; font-size: 11.5px; }

	/* ---------- MOBILE TOGGLE ---------- */
	.fi-header-v93__toggle {
		display: none;
		width: 46px;
		height: 46px;
		align-items: center;
		justify-content: center;
		flex-direction: column;
		gap: 6px;
		border: 0;
		border-radius: 999px;
		background: transparent;
		color: var(--fi-bone);
		cursor: pointer;
	}
	.fi-header-v93__toggle span {
		display: block;
		width: 26px;
		height: 2px;
		border-radius: 999px;
		background: var(--fi-bone);
		transition: transform 240ms cubic-bezier(0.2, 0.8, 0.2, 1), opacity 200ms ease, width 240ms ease;
	}
	.fi-header-v93.is-open .fi-header-v93__toggle span:first-child { transform: translateY(4px) rotate(45deg); }
	.fi-header-v93.is-open .fi-header-v93__toggle span:last-child { transform: translateY(-4px) rotate(-45deg); }

	/* ---------- MOBILE PANEL (minimal full-screen editorial) ---------- */
	.fi-header-v93__panel {
		position: fixed;
		inset: 0;
		z-index: 99998;
		background: linear-gradient(180deg, #111110, #0b0b0a);
		opacity: 0;
		visibility: hidden;
		pointer-events: none;
		transition: opacity 320ms ease, visibility 320ms ease;
	}
	.fi-header-v93__panel::before {
		content: "";
		position: absolute;
		inset: 0;
		pointer-events: none;
		background-image:
			linear-gradient(rgba(236, 232, 223, 0.03) 1px, transparent 1px),
			linear-gradient(90deg, rgba(236, 232, 223, 0.03) 1px, transparent 1px),
			radial-gradient(circle at 80% 12%, rgba(210, 240, 80, 0.08), transparent 38%);
		background-size: 110px 110px, 110px 110px, 100% 100%;
		opacity: 0.8;
	}
	.fi-header-v93.is-open .fi-header-v93__panel { opacity: 1; visibility: visible; pointer-events: auto; }

	.fi-header-v93__panel-inner {
		position: relative;
		display: flex;
		flex-direction: column;
		height: 100%;
		max-height: 100dvh;
		padding: calc(var(--fi-h) + 22px) clamp(22px, 7vw, 44px) calc(env(safe-area-inset-bottom) + 26px);
		overflow-y: auto;
		-webkit-overflow-scrolling: touch;
		overscroll-behavior: contain;
	}

	.fi-header-v93__panel-kicker {
		color: var(--fi-blue);
		font-family: var(--fi-mono);
		font-size: 10.5px;
		font-weight: 600;
		letter-spacing: 0.16em;
		text-transform: uppercase;
		margin-bottom: clamp(20px, 5vh, 40px);
		opacity: 0;
		transform: translateY(10px);
		transition: opacity 360ms ease 120ms, transform 360ms ease 120ms;
	}
	.fi-header-v93.is-open .fi-header-v93__panel-kicker { opacity: 1; transform: none; }

	.fi-header-v93__panel-nav { display: flex; flex-direction: column; }

	.fi-header-v93__panel-link {
		position: relative;
		display: flex;
		align-items: baseline;
		gap: 16px;
		padding: clamp(15px, 2.4vh, 22px) 0;
		border-top: 1px solid rgba(236, 232, 223, 0.1);
		color: var(--fi-bone);
		text-decoration: none;
		opacity: 0;
		transform: translateY(14px);
		transition: opacity 420ms cubic-bezier(0.2, 0.8, 0.2, 1), transform 420ms cubic-bezier(0.2, 0.8, 0.2, 1), color 180ms ease;
		transition-delay: calc(120ms + var(--i, 0) * 70ms);
	}
	.fi-header-v93__panel-link:last-of-type { border-bottom: 1px solid rgba(236, 232, 223, 0.1); }
	.fi-header-v93.is-open .fi-header-v93__panel-link { opacity: 1; transform: none; }

	.fi-header-v93__panel-index {
		flex: 0 0 auto;
		font-family: var(--fi-mono);
		font-size: 11px;
		font-weight: 600;
		letter-spacing: 0.06em;
		color: var(--fi-blue);
		transform: translateY(-4px);
	}
	.fi-header-v93__panel-label {
		font-family: var(--fi-display);
		font-size: clamp(30px, 8.5vw, 46px);
		line-height: 0.98;
		letter-spacing: 0.005em;
		text-transform: uppercase;
		transition: transform 220ms cubic-bezier(0.2, 0.8, 0.2, 1);
	}
	.fi-header-v93__panel-link:active .fi-header-v93__panel-label { transform: translateX(4px); }
	.fi-header-v93__panel-link.is-active { color: var(--fi-lime); }
	.fi-header-v93__panel-link.is-active .fi-header-v93__panel-index { color: var(--fi-lime); }

	.fi-header-v93__panel-foot {
		margin-top: auto;
		padding-top: clamp(26px, 5vh, 44px);
		opacity: 0;
		transform: translateY(14px);
		transition: opacity 420ms ease, transform 420ms ease;
		transition-delay: 460ms;
	}
	.fi-header-v93.is-open .fi-header-v93__panel-foot { opacity: 1; transform: none; }

	.fi-header-v93__panel-note {
		margin: 18px 0 0;
		color: var(--fi-bone-soft);
		font-family: var(--fi-mono);
		font-size: 10px;
		letter-spacing: 0.1em;
		text-transform: uppercase;
	}

	/* ---------- SCROLL LOCK + FOCUS ---------- */
	html.fi-header-v93-lock,
	html.fi-header-v93-lock body { overflow: hidden !important; }

	.fi-header-v93 a:focus-visible,
	.fi-header-v93 button:focus-visible { outline: 2px solid var(--fi-lime); outline-offset: 3px; border-radius: 6px; }
	.fi-header-v93 a:focus:not(:focus-visible),
	.fi-header-v93 button:focus:not(:focus-visible) { outline: none; }

	/* ---------- RESPONSIVE (header height + spacer stay in lock-step) ---------- */
	@media (max-width: 1320px) and (min-width: 981px) {
		.fi-header-v93 { --fi-h: 96px; --fi-logo-h: 54px; }
		.fi-header-v93-spacer { height: 96px; }
		.fi-header-v93__inner { padding: 0 clamp(18px, 3vw, 40px); gap: 16px; }
		.fi-header-v93__menu-link { padding: 0 11px; font-size: 9.8px; letter-spacing: 0.08em; }
		.fi-header-v93__btn { height: 44px; padding: 0 17px; font-size: 10px; }
	}

	@media (max-width: 980px) {
		.fi-header-v93 { --fi-h: 84px; --fi-logo-h: 48px; }
		.fi-header-v93-spacer { height: 84px; }
		body.admin-bar .fi-header-v93 { top: 46px; }
		.fi-header-v93__inner { grid-template-columns: 1fr auto; padding: 0 18px; }
		.fi-header-v93__nav,
		.fi-header-v93__actions { display: none; }
		.fi-header-v93__toggle { display: inline-flex; grid-column: 2; justify-self: end; }
		.fi-header-v93__brand { grid-column: 1; }
	}

	@media (max-width: 520px) {
		.fi-header-v93 { --fi-h: 76px; --fi-logo-h: 42px; }
		.fi-header-v93-spacer { height: 76px; }
		.fi-header-v93__inner { padding: 0 16px; }
	}

	@media (max-width: 360px) {
		.fi-header-v93 { --fi-logo-h: 38px; }
	}

	@media (prefers-reduced-motion: reduce) {
		.fi-header-v93,
		.fi-header-v93 *,
		.fi-header-v93 *::before,
		.fi-header-v93 *::after {
			animation: none !important;
			transition-duration: 0.01ms !important;
		}
		.fi-header-v93__panel-link,
		.fi-header-v93__panel-kicker,
		.fi-header-v93__panel-foot { opacity: 1 !important; transform: none !important; }
	}
	</style>
	<?php
}, 99 );

add_action( 'wp_body_open', function () {
	if ( ! fi_header_should_render_v93() ) {
		return;
	}

	echo fi_get_header_markup_v93(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}, 5 );

add_action( 'wp_footer', function () {
	if ( ! fi_header_should_render_v93() ) {
		return;
	}

	$fallback_markup = did_action( 'wp_body_open' ) ? '' : fi_get_header_markup_v93();
	?>
	<script id="fi-header-v93-js">
	(function () {
		var fallbackMarkup = <?php echo wp_json_encode( $fallback_markup ); ?>;
		var initialized = false;

		function mountFallback() {
			if (fallbackMarkup && !document.querySelector('.fi-header-v93')) {
				document.body.insertAdjacentHTML('afterbegin', fallbackMarkup);
			}
		}

		function init() {
			if (initialized) return;
			initialized = true;
			mountFallback();

			var header = document.getElementById('fi-header-v93');
			if (!header) return;

			var toggle = header.querySelector('.fi-header-v93__toggle');
			var panel  = header.querySelector('.fi-header-v93__panel');
			var ticking = false;

			function onScroll() {
				header.classList.toggle('is-scrolled', window.scrollY > 10);
				ticking = false;
			}
			window.addEventListener('scroll', function () {
				if (!ticking) { window.requestAnimationFrame(onScroll); ticking = true; }
			}, { passive: true });
			onScroll();

			function lock()   { document.documentElement.classList.add('fi-header-v93-lock'); }
			function unlock() { document.documentElement.classList.remove('fi-header-v93-lock'); }

			function focusables() {
				var list = [];
				if (toggle) list.push(toggle);
				if (panel) panel.querySelectorAll('a[href], button:not([disabled])').forEach(function (n) { list.push(n); });
				return list;
			}

			function open() {
				header.classList.add('is-open');
				if (toggle) { toggle.setAttribute('aria-expanded', 'true'); toggle.setAttribute('aria-label', 'Cerrar menú'); }
				if (panel) panel.setAttribute('aria-hidden', 'false');
				lock();
				var first = panel ? panel.querySelector('a[href]') : null;
				if (first) window.requestAnimationFrame(function () { try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); } });
			}

			function close() {
				var wasOpen = header.classList.contains('is-open');
				header.classList.remove('is-open');
				if (toggle) { toggle.setAttribute('aria-expanded', 'false'); toggle.setAttribute('aria-label', 'Abrir menú'); }
				if (panel) panel.setAttribute('aria-hidden', 'true');
				unlock();
				if (wasOpen && toggle) { try { toggle.focus({ preventScroll: true }); } catch (e) { toggle.focus(); } }
			}

			if (toggle && panel) {
				toggle.addEventListener('click', function () {
					header.classList.contains('is-open') ? close() : open();
				});

				panel.querySelectorAll('a').forEach(function (link) { link.addEventListener('click', close); });

				document.addEventListener('keydown', function (e) {
					if (e.key === 'Escape') { close(); return; }
					if (e.key !== 'Tab' || !header.classList.contains('is-open')) return;
					var f = focusables();
					if (!f.length) return;
					var first = f[0], last = f[f.length - 1];
					if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
					else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
				});

				window.addEventListener('resize', function () {
					if (window.innerWidth > 980) close();
				});
			}
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', init);
		} else {
			init();
		}
	})();
	</script>
	<?php
}, 99 );
