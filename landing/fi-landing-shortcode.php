<?php
/**
 * [future_island_landing] — نمایش لندینگ Future Island Saap در iframe تمام‌عرض
 *
 * نحوهٔ استفاده:
 * ۱) فایل future-island-saap-landing.html را در هاست آپلود کنید در مسیر:
 *       wp-content/uploads/fi/future-island-saap-landing.html
 *    (اگر جای دیگری گذاشتید، یا پیش‌فرض src پایین را عوض کنید،
 *     یا در شورت‌کد بدهید: [future_island_landing src="https://..."])
 * ۲) این اسنیپت را در WPCode به صورت «PHP Snippet» و Run Everywhere فعال کنید.
 * ۳) در صفحهٔ اصلی بنویسید:  [future_island_landing]
 *    ارتفاع دلخواه:          [future_island_landing height="900px"]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function fi_landing_shortcode( $atts ) {
	$defaults = array(
		'src'    => content_url( 'uploads/fi/future-island-saap-landing.html' ),
		// 100svh یعنی قد کامل ویوپورت؛ مقدار ثابت مثل 900px هم می‌شود داد
		'height' => '100svh',
	);
	$a = shortcode_atts( $defaults, $atts, 'future_island_landing' );

	$src = esc_url( $a['src'] );
	if ( '' === $src ) {
		return '';
	}

	$height = preg_match( '/\A[0-9]+(\.[0-9]+)?(px|vh|svh|dvh|lvh|em|rem|%)\z/', $a['height'] )
		? $a['height']
		: '100svh';

	// width:100vw + margin منفی: از کانتینر باریک قالب بیرون می‌زند و تمام‌عرض می‌شود.
	// height دوبار نوشته شده تا مرورگرهای قدیمی که svh را نمی‌شناسند روی vh بمانند.
	// allow پایین لازم است تا اتوپلی ویدیوی یوتیوبِ داخل لندینگ کار کند.
	return '<div class="fi-landing-embed" style="width:100vw;max-width:100vw;margin-left:calc(50% - 50vw);line-height:0">'
		. '<iframe src="' . $src . '"'
		. ' title="Future Island Saap"'
		. ' style="width:100%;height:100vh;height:' . esc_attr( $height ) . ';border:0;display:block"'
		. ' loading="eager"'
		. ' allow="autoplay; fullscreen; encrypted-media; picture-in-picture; clipboard-write; web-share"'
		. ' allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>'
		. '</div>';
}
add_shortcode( 'future_island_landing', 'fi_landing_shortcode' );
