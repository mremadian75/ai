<?php
/**
 * Merge a translation dictionary against the current .pot into a .po.
 *
 *   php tools/make-po.php es_ES
 *
 * Reads tools/i18n-<lang>.php (msgid => translation) and languages/
 * mahan-academy.pot, and writes languages/mahan-academy-<locale>.po.
 *
 * Reports two things worth acting on:
 *  - strings in the .pot with no translation yet (they fall back to English)
 *  - entries in the dictionary matching no .pot string — almost always a typo
 *    or a source string that changed, i.e. a translation quietly doing nothing
 *
 * @package Mahan_Academy
 */

$root   = dirname( __DIR__ );
$locale = isset( $argv[1] ) ? $argv[1] : 'es_ES';
$lang   = strtolower( substr( $locale, 0, 2 ) );

$dict_file = $root . '/tools/i18n-' . $lang . '.php';
if ( ! file_exists( $dict_file ) ) {
	fwrite( STDERR, "No dictionary: tools/i18n-$lang.php\n" );
	exit( 1 );
}
$dict = include $dict_file;

$pot = $root . '/languages/mahan-academy.pot';
if ( ! file_exists( $pot ) ) {
	fwrite( STDERR, "No template — run: php tools/make-pot.php\n" );
	exit( 1 );
}

$plural_forms = 'nplurals=2; plural=(n != 1);';

$lines = file( $pot, FILE_IGNORE_NEW_LINES );
$out   = array();
$out[] = '# Mahan Academy — ' . $locale . '.';
$out[] = '# Edit tools/i18n-' . $lang . '.php, then: php tools/make-po.php ' . $locale;
$out[] = 'msgid ""';
$out[] = 'msgstr ""';
$out[] = '"Project-Id-Version: Mahan Academy\n"';
$out[] = '"Language: ' . $locale . '\n"';
$out[] = '"MIME-Version: 1.0\n"';
$out[] = '"Content-Type: text/plain; charset=UTF-8\n"';
$out[] = '"Content-Transfer-Encoding: 8bit\n"';
$out[] = '"Plural-Forms: ' . $plural_forms . '\n"';
$out[] = '"X-Domain: mahan-academy\n"';
$out[] = '';

function po_q( $s ) {
	$s = str_replace( array( '\\', '"', "\t", "\r" ), array( '\\\\', '\\"', '\\t', '\\r' ), $s );
	return '"' . str_replace( "\n", '\\n', $s ) . '"';
}
function po_unq( $line ) {
	$line = trim( $line );
	if ( '' === $line || '"' !== $line[0] ) {
		return '';
	}
	return stripcslashes( substr( $line, 1, -1 ) );
}

$total = 0;
$done  = 0;
$used  = array();
$i     = 0;
$n     = count( $lines );

// Skip the template header block.
while ( $i < $n && '' !== trim( $lines[ $i ] ) ) {
	$i++;
}

while ( $i < $n ) {
	$line = $lines[ $i ];
	$t    = trim( $line );

	if ( 0 === strpos( $t, 'msgid ' ) && 'msgid ""' !== $t ) {
		$msgid = po_unq( substr( $t, 6 ) );
		// Fold continuation lines.
		$j = $i + 1;
		while ( $j < $n && '"' === substr( trim( $lines[ $j ] ), 0, 1 ) ) {
			$msgid .= po_unq( $lines[ $j ] );
			$j++;
		}
		$total++;
		$tr = isset( $dict[ $msgid ] ) ? $dict[ $msgid ] : '';
		if ( '' !== $tr ) {
			$done++;
			$used[ $msgid ] = true;
		}

		$out[] = 'msgid ' . po_q( $msgid );

		// A plural entry needs msgstr[0]/msgstr[1]; we only translate the
		// singular form here, so leave both blank unless the dictionary
		// supplies an array.
		if ( $j < $n && 0 === strpos( trim( $lines[ $j ] ), 'msgid_plural ' ) ) {
			$plural = po_unq( substr( trim( $lines[ $j ] ), 13 ) );
			$out[]  = 'msgid_plural ' . po_q( $plural );
			$forms  = is_array( $tr ) ? $tr : array( $tr, $tr );
			$out[]  = 'msgstr[0] ' . po_q( isset( $forms[0] ) ? $forms[0] : '' );
			$out[]  = 'msgstr[1] ' . po_q( isset( $forms[1] ) ? $forms[1] : '' );
			$j++;
			while ( $j < $n && 0 === strpos( trim( $lines[ $j ] ), 'msgstr' ) ) {
				$j++;
			}
		} else {
			$out[] = 'msgstr ' . po_q( is_array( $tr ) ? $tr[0] : $tr );
			while ( $j < $n && 0 === strpos( trim( $lines[ $j ] ), 'msgstr' ) ) {
				$j++;
			}
		}
		$out[] = '';
		$i     = $j;
		continue;
	}

	// Carry references and contexts through unchanged.
	if ( 0 === strpos( $t, '#' ) || 0 === strpos( $t, 'msgctxt ' ) ) {
		$out[] = $t;
	}
	$i++;
}

$dest = $root . '/languages/mahan-academy-' . $locale . '.po';
file_put_contents( $dest, implode( "\n", $out ) . "\n" );

$orphans = array_diff( array_keys( $dict ), array_keys( $used ) );
printf( "Wrote %s: %d/%d translated (%d%%)\n", basename( $dest ), $done, $total, $total ? round( $done / $total * 100 ) : 0 );
if ( $orphans ) {
	// A dictionary entry that matches nothing is a translation doing nothing —
	// usually the source string changed underneath it.
	echo count( $orphans ) . " dictionary entries match no source string:\n";
	foreach ( array_slice( $orphans, 0, 20 ) as $o ) {
		echo '  - ' . $o . "\n";
	}
}
