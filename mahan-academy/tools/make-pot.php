<?php
/**
 * Extract translatable strings into languages/mahan-academy.pot.
 *
 * WordPress projects normally do this with `wp i18n make-pot`, which needs
 * WP-CLI. This plugin has no build step and shouldn't grow a toolchain
 * dependency just to stay translatable, so the extractor lives here.
 *
 *   php tools/make-pot.php
 *
 * Recognised: __(), _e(), esc_html__(), esc_html_e(), esc_attr__(),
 * esc_attr_e(), _x(), _ex(), esc_html_x(), _n(), _nx().
 * Only calls carrying the mahan-academy text domain are collected.
 *
 * @package Mahan_Academy
 */

$root = dirname( __DIR__ );
$domain = 'mahan-academy';

/** Functions and which argument positions hold text (1-based). */
$FUNCS = array(
	'__'            => array( 'single' => 1, 'domain' => 2 ),
	'_e'            => array( 'single' => 1, 'domain' => 2 ),
	'esc_html__'    => array( 'single' => 1, 'domain' => 2 ),
	'esc_html_e'    => array( 'single' => 1, 'domain' => 2 ),
	'esc_attr__'    => array( 'single' => 1, 'domain' => 2 ),
	'esc_attr_e'    => array( 'single' => 1, 'domain' => 2 ),
	'_x'            => array( 'single' => 1, 'context' => 2, 'domain' => 3 ),
	'_ex'           => array( 'single' => 1, 'context' => 2, 'domain' => 3 ),
	'esc_html_x'    => array( 'single' => 1, 'context' => 2, 'domain' => 3 ),
	'_n'            => array( 'single' => 1, 'plural' => 2, 'domain' => 4 ),
	'_nx'           => array( 'single' => 1, 'plural' => 2, 'context' => 4, 'domain' => 5 ),
);

/**
 * Walk PHP files, skipping anything that isn't ours.
 */
function php_files( $dir ) {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$path = $file->getPathname();
		if ( substr( $path, -4 ) !== '.php' ) {
			continue;
		}
		if ( strpos( $path, '/vendor/' ) !== false || strpos( $path, '/node_modules/' ) !== false ) {
			continue;
		}
		$out[] = $path;
	}
	sort( $out );
	return $out;
}

$entries = array(); // key => { msgid, plural, context, refs[] }

foreach ( php_files( $root ) as $path ) {
	$code   = file_get_contents( $path );
	$tokens = token_get_all( $code );
	$rel    = ltrim( str_replace( $root, '', $path ), '/' );

	for ( $i = 0, $n = count( $tokens ); $i < $n; $i++ ) {
		$tok = $tokens[ $i ];
		if ( ! is_array( $tok ) || T_STRING !== $tok[0] || ! isset( $FUNCS[ $tok[1] ] ) ) {
			continue;
		}
		$fn   = $tok[1];
		$spec = $FUNCS[ $fn ];
		$line = $tok[2];

		// The next meaningful token must be "(" or this is not a call.
		$j = $i + 1;
		while ( $j < $n && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$j++;
		}
		if ( $j >= $n || '(' !== $tokens[ $j ] ) {
			continue;
		}

		// Collect the argument list, tracking nesting so a nested call's
		// commas don't split the outer arguments.
		$depth = 0;
		$args  = array();
		$cur   = array();
		for ( $k = $j; $k < $n; $k++ ) {
			$t = $tokens[ $k ];
			if ( ! is_array( $t ) ) {
				if ( '(' === $t ) {
					$depth++;
					if ( 1 === $depth ) {
						continue;
					}
				} elseif ( ')' === $t ) {
					$depth--;
					if ( 0 === $depth ) {
						$args[] = $cur;
						break;
					}
				} elseif ( ',' === $t && 1 === $depth ) {
					$args[] = $cur;
					$cur    = array();
					continue;
				}
			}
			if ( $depth >= 1 ) {
				$cur[] = $t;
			}
		}

		/**
		 * A literal single-quoted or double-quoted string argument, or null
		 * when the argument is a variable/concatenation we can't resolve.
		 */
		$literal = function ( $arg ) {
			$str = null;
			foreach ( (array) $arg as $t ) {
				if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
					if ( null !== $str ) {
						return null; // concatenation — skip rather than guess
					}
					$raw = $t[1];
					$q   = $raw[0];
					$val = substr( $raw, 1, -1 );
					$val = ( "'" === $q )
						? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $val )
						: stripcslashes( $val );
					$str = $val;
					continue;
				}
				return null;
			}
			return $str;
		};

		$dom = isset( $args[ $spec['domain'] - 1 ] ) ? $literal( $args[ $spec['domain'] - 1 ] ) : null;
		if ( $dom !== $domain ) {
			continue;
		}
		$msgid = isset( $args[ $spec['single'] - 1 ] ) ? $literal( $args[ $spec['single'] - 1 ] ) : null;
		if ( null === $msgid || '' === $msgid ) {
			continue;
		}
		$plural  = isset( $spec['plural'], $args[ $spec['plural'] - 1 ] ) ? $literal( $args[ $spec['plural'] - 1 ] ) : null;
		$context = isset( $spec['context'], $args[ $spec['context'] - 1 ] ) ? $literal( $args[ $spec['context'] - 1 ] ) : null;

		$key = ( null === $context ? '' : $context . "\4" ) . $msgid;
		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array( 'msgid' => $msgid, 'plural' => $plural, 'context' => $context, 'refs' => array() );
		}
		$entries[ $key ]['refs'][] = $rel . ':' . $line;
		$i = $k;
	}
}

ksort( $entries );

function po_quote( $s ) {
	$s = str_replace( array( '\\', '"', "\t", "\r" ), array( '\\\\', '\\"', '\\t', '\\r' ), $s );
	$s = str_replace( "\n", '\\n', $s );
	return '"' . $s . '"';
}

$out  = "# Mahan Academy — translation template.\n";
$out .= "# Regenerate with: php tools/make-pot.php\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: Mahan Academy\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"X-Domain: mahan-academy\\n\"\n\n";

foreach ( $entries as $e ) {
	$refs = array_slice( array_unique( $e['refs'] ), 0, 6 );
	$out .= '#: ' . implode( ' ', $refs ) . "\n";
	if ( null !== $e['context'] ) {
		$out .= 'msgctxt ' . po_quote( $e['context'] ) . "\n";
	}
	$out .= 'msgid ' . po_quote( $e['msgid'] ) . "\n";
	if ( null !== $e['plural'] ) {
		$out .= 'msgid_plural ' . po_quote( $e['plural'] ) . "\n";
		$out .= "msgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
	} else {
		$out .= "msgstr \"\"\n\n";
	}
}

$dest = $root . '/languages/mahan-academy.pot';
if ( ! is_dir( dirname( $dest ) ) ) {
	mkdir( dirname( $dest ), 0755, true );
}
file_put_contents( $dest, $out );
echo 'Wrote ' . count( $entries ) . " strings to languages/mahan-academy.pot\n";
