<?php
/**
 * Compile a .po into the binary .mo WordPress actually loads.
 *
 * Normally `msgfmt` does this. It isn't always present (it wasn't here), and
 * a translation that only exists as .po is a translation WordPress ignores —
 * so the compiler ships with the plugin.
 *
 *   php tools/po2mo.php languages/mahan-academy-es_ES.po
 *
 * @package Mahan_Academy
 */

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: php tools/po2mo.php <file.po> [file.mo]\n" );
	exit( 1 );
}
$po = $argv[1];
$mo = isset( $argv[2] ) ? $argv[2] : preg_replace( '/\.po$/', '.mo', $po );
if ( ! file_exists( $po ) ) {
	fwrite( STDERR, "No such file: $po\n" );
	exit( 1 );
}

/**
 * Unescape a PO string literal.
 */
function po_unquote( $line ) {
	$line = trim( $line );
	if ( '' === $line || '"' !== $line[0] ) {
		return '';
	}
	$inner = substr( $line, 1, -1 );
	return stripcslashes( $inner );
}

$entries = array();
$cur     = array( 'ctx' => null, 'id' => null, 'plural' => null, 'str' => array() );
$field   = null;

foreach ( file( $po, FILE_IGNORE_NEW_LINES ) as $raw ) {
	$line = trim( $raw );

	if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
		continue;
	}

	if ( 0 === strpos( $line, 'msgctxt ' ) ) {
		// A new msgctxt starts a new entry.
		if ( null !== $cur['id'] ) {
			$entries[] = $cur;
			$cur = array( 'ctx' => null, 'id' => null, 'plural' => null, 'str' => array() );
		}
		$cur['ctx'] = po_unquote( substr( $line, 8 ) );
		$field      = 'ctx';
	} elseif ( 0 === strpos( $line, 'msgid_plural ' ) ) {
		$cur['plural'] = po_unquote( substr( $line, 13 ) );
		$field         = 'plural';
	} elseif ( 0 === strpos( $line, 'msgid ' ) ) {
		if ( null !== $cur['id'] ) {
			$entries[] = $cur;
			$cur = array( 'ctx' => null, 'id' => null, 'plural' => null, 'str' => array() );
		}
		$cur['id'] = po_unquote( substr( $line, 6 ) );
		$field     = 'id';
	} elseif ( preg_match( '/^msgstr\[(\d+)\]\s(.*)$/', $line, $m ) ) {
		$cur['str'][ (int) $m[1] ] = po_unquote( $m[2] );
		$field = 'str' . (int) $m[1];
	} elseif ( 0 === strpos( $line, 'msgstr ' ) ) {
		$cur['str'][0] = po_unquote( substr( $line, 7 ) );
		$field         = 'str0';
	} elseif ( '"' === $line[0] && null !== $field ) {
		// Continuation line.
		$more = po_unquote( $line );
		if ( 'ctx' === $field ) {
			$cur['ctx'] .= $more;
		} elseif ( 'id' === $field ) {
			$cur['id'] .= $more;
		} elseif ( 'plural' === $field ) {
			$cur['plural'] .= $more;
		} elseif ( 0 === strpos( $field, 'str' ) ) {
			$idx = (int) substr( $field, 3 );
			$cur['str'][ $idx ] = ( isset( $cur['str'][ $idx ] ) ? $cur['str'][ $idx ] : '' ) . $more;
		}
	}
}
if ( null !== $cur['id'] ) {
	$entries[] = $cur;
}

// Build the id => translation table in MO's key format.
$table  = array();
$empty  = 0;
foreach ( $entries as $e ) {
	$str = isset( $e['str'] ) ? $e['str'] : array();
	ksort( $str );
	$translated = implode( "\0", $str );
	// An untranslated entry must NOT be written: gettext would return the
	// empty string as the "translation" and the UI would go blank, which is
	// worse than falling back to English.
	if ( '' !== $e['id'] && '' === trim( $translated ) ) {
		$empty++;
		continue;
	}
	$key = $e['id'];
	if ( null !== $e['ctx'] && '' !== $e['ctx'] ) {
		$key = $e['ctx'] . "\4" . $key;
	}
	if ( null !== $e['plural'] ) {
		$key .= "\0" . $e['plural'];
	}
	$table[ $key ] = $translated;
}

ksort( $table, SORT_STRING );

$count   = count( $table );
$ids     = array_keys( $table );
$strs    = array_values( $table );
$id_blob = '';
$st_blob = '';
$id_tab  = '';
$st_tab  = '';

$header_size = 28;
$id_off      = $header_size + ( $count * 8 * 2 );
$st_off      = $id_off; // filled after we know the id table size

// Originals table.
$offset = $id_off + ( 0 ); // data starts after both tables; computed below.
$data_start = $header_size + ( $count * 16 );
$pos = $data_start;
foreach ( $ids as $s ) {
	$id_tab  .= pack( 'VV', strlen( $s ), $pos );
	$id_blob .= $s . "\0";
	$pos     += strlen( $s ) + 1;
}
foreach ( $strs as $s ) {
	$st_tab  .= pack( 'VV', strlen( $s ), $pos );
	$st_blob .= $s . "\0";
	$pos     += strlen( $s ) + 1;
}

$header = pack(
	'VVVVVVV',
	0x950412de,                       // magic (little endian)
	0,                                // revision
	$count,                           // number of strings
	$header_size,                     // offset of originals table
	$header_size + ( $count * 8 ),    // offset of translations table
	0,                                // hash table size (none)
	$header_size + ( $count * 16 )    // offset of hash table
);

file_put_contents( $mo, $header . $id_tab . $st_tab . $id_blob . $st_blob );
echo "Wrote $count translated strings to $mo";
echo $empty ? " ($empty left untranslated — those fall back to the original)\n" : "\n";
