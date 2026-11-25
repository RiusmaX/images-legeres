<?php
/** Étapes de test-tailles.sh, jouées par `wp eval-file tailles.php <étape>`. */

global $wpdb;

$etape = $args[0];

function verifier( $description, $ok, $detail = '' ) {
	echo ( $ok ? 'OK   - ' : 'FAIL - ' ) . $description . ( '' !== $detail ? " ($detail)" : '' ) . "\n";
}

function creer_image( $largeur, $hauteur ) {
	$produit = wp_insert_post( array( 'post_type' => 'product', 'post_title' => "p{$largeur}x{$hauteur}", 'post_status' => 'publish' ) );
	$image   = new Imagick();
	$image->newImage( $largeur, $hauteur, new ImagickPixel( '#336699' ) );
	$image->setImageFormat( 'jpeg' );
	$envoi = wp_upload_dir();
	$chemin = trailingslashit( $envoi['path'] ) . "tailles-{$largeur}x{$hauteur}.jpg";
	$image->writeImage( $chemin );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'tailles', 'post_status' => 'inherit', 'post_parent' => $produit ), $chemin );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $chemin ) );
	set_post_thumbnail( $produit, $id );

	return $id;
}

function base() {
	return trailingslashit( wp_get_upload_dir()['basedir'] );
}

function relatif( $adresse ) {
	return ltrim( substr( $adresse, strlen( wp_get_upload_dir()['baseurl'] ) ), '/' );
}

function carre( $relatif ) {
	$m = is_file( base() . $relatif ) ? getimagesize( base() . $relatif ) : false;

	return $m && $m[0] === $m[1] && false !== strpos( $relatif, '-il-harmonise-' );
}

/** Tous les fichiers du dossier uploads, harmonisés ou non. */
function fichiers( $harmonises ) {
	$liste = array();

	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( base(), FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( $f->isFile() && ( false !== strpos( $f->getFilename(), '-il-harmonise-' ) ) === $harmonises ) {
			$liste[] = $f->getPathname();
		}
	}

	sort( $liste );

	return $liste;
}

function lignes() {
	global $wpdb;

	return $wpdb->get_results( 'SELECT * FROM ' . ImagesLegeres_Harmonisees::nom() . ' ORDER BY id', ARRAY_A );
}

function index_table() {
	global $wpdb;

	$noms = array_unique( array_column( $wpdb->get_results( 'SHOW INDEX FROM ' . ImagesLegeres_Harmonisees::nom(), ARRAY_A ), 'Key_name' ) );
	sort( $noms );

	return implode( ',', $noms );
}

/** Table au schéma exact de la 0.2.2, remplie d'un rang par carré à partir des rangs actuels. */
function revenir_au_schema_022() {
	global $wpdb;

	$table     = ImagesLegeres_Harmonisees::nom();
	$existants = array_values( array_column( lignes(), null, 'chemin_harmonise' ) );
	$wpdb->query( "DROP TABLE {$table}" );
	$wpdb->query( "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		taille VARCHAR(100) NOT NULL DEFAULT '',
		chemin_natif VARCHAR(500) NOT NULL DEFAULT '',
		chemin_harmonise VARCHAR(500) NOT NULL,
		largeur INT UNSIGNED NOT NULL DEFAULT 0,
		hauteur INT UNSIGNED NOT NULL DEFAULT 0,
		poids BIGINT UNSIGNED NOT NULL DEFAULT 0,
		maj DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY chemin_harmonise (chemin_harmonise(191)),
		KEY chemin_natif (chemin_natif(191)),
		KEY attachment_id (attachment_id)
	) {$wpdb->get_charset_collate()}" );

	foreach ( $existants as $ligne ) {
		$ligne['maj'] = '2026-11-15 00:00:00';
		$wpdb->insert( $table, $ligne );
	}

	$reglages = get_option( ImagesLegeres_Reglages::OPTION );
	$reglages[ ImagesLegeres_Reglages::CLE_MIGRATIONS ] = array_values( array_diff( (array) $reglages[ ImagesLegeres_Reglages::CLE_MIGRATIONS ], array( 'harmonisees-par-taille-2026-11-25' ) ) );
	update_option( ImagesLegeres_Reglages::OPTION, $reglages );
}

$demandees = array( 'woocommerce_thumbnail', 'large', 'woocommerce_single', 'woocommerce_gallery_thumbnail' );
$neuf      = 'PRIMARY,attachment_id,carre_taille,chemin_natif';

if ( 'substitution' === $etape ) {
	ImagesLegeres_Reglages::enregistrer( array( 'harmonisation_produit_active' => true, 'conversion_webp_active' => true ) );
	$ids = array();

	// 1440x1612 : le cas de production (large = 914x1024). Puis deux originaux trop petits.
	foreach ( array( array( 1440, 1612 ), array( 914, 1024 ), array( 800, 900 ) ) as list( $l, $h ) ) {
		$nom      = "{$l}x{$h}";
		$id       = creer_image( $l, $h );
		$ids[]    = $id;
		$servies  = array();
		$balises  = array();

		foreach ( $demandees as $taille ) {
			$servies[ $taille ] = relatif( wp_get_attachment_image_src( $id, $taille )[0] );
			$balises[ $taille ] = wp_get_attachment_image( $id, $taille );
		}

		$resultats = ImagesLegeres_Harmonisation::generer( $id );
		verifier( "$nom : génération sans erreur", ! in_array( 'erreur', array_column( $resultats, 'etat' ), true ) );
		verifier( "$nom : un rang par taille", count( $resultats ) === count( ImagesLegeres_Harmonisees::par_attachment( $id ) ), count( $resultats ) . ' / ' . count( ImagesLegeres_Harmonisees::par_attachment( $id ) ) );
		verifier( "$nom : à jour juste après génération", ImagesLegeres_Harmonisation::a_jour( $id ) );

		foreach ( $demandees as $taille ) {
			$map = ImagesLegeres_Harmonisees::map_pour_chemins( array( $servies[ $taille ] ) );
			verifier( "$nom $taille : correspondance carrée pour " . basename( $servies[ $taille ] ), isset( $map[ $servies[ $taille ] ] ) && carre( $map[ $servies[ $taille ] ]['chemin'] ), isset( $map[ $servies[ $taille ] ] ) ? basename( $map[ $servies[ $taille ] ]['chemin'] ) : 'aucune' );

			$html = ImagesLegeres_Service::reecrire_tampon( $balises[ $taille ] );
			preg_match_all( '#https?://[^\s"\',]+\.(?:jpe?g|png)#i', $html, $m );
			$rectangles = array_filter( $m[0], function ( $a ) { return ! carre( relatif( $a ) ); } );
			preg_match( '#\ssrc="([^"]+)"#', $html, $src );
			verifier( "$nom $taille : le HTML réécrit ne sert que des carrés", ! empty( $src ) && carre( relatif( $src[1] ) ) && empty( $rectangles ), implode( ' ', array_map( 'basename', $rectangles ) ) );
		}

		$natifs = ImagesLegeres_Moteur::chemin_relatif( get_attached_file( $id ) );
		$sizes  = wp_get_attachment_metadata( $id )['sizes'];
		$sans   = array();

		foreach ( array_intersect_key( $sizes, ImagesLegeres_Harmonisation::tailles_a_harmoniser() ) as $taille => $s ) {
			$chemin = dirname( $natifs ) . '/' . $s['file'];

			if ( empty( ImagesLegeres_Harmonisees::map_pour_chemins( array( $chemin ) ) ) ) {
				$sans[] = $taille;
			}
		}

		verifier( "$nom : chaque sous-taille native cible a sa correspondance", empty( $sans ), implode( ' ', $sans ) );
		echo "    natives : " . implode( ' ', array_keys( $sizes ) ) . "\n";

		if ( $l < 1024 ) {
			$map = ImagesLegeres_Harmonisees::map_pour_chemins( array( $natifs ) );
			verifier( "$nom : l'original servi à la place des tailles absentes a un carré de 1024", isset( $map[ $natifs ] ) && 1024 === $map[ $natifs ]['largeur'], isset( $map[ $natifs ] ) ? (string) $map[ $natifs ]['largeur'] : 'aucune' );
		}
	}

	$carres = array_column( lignes(), 'chemin_harmonise' );
	verifier( 'des tailles partagent un même carré', count( $carres ) > count( array_unique( $carres ) ), count( $carres ) . ' rangs, ' . count( array_unique( $carres ) ) . ' carrés' );
	update_option( 'il_test_ids', $ids );
}

if ( 'migration-semer' === $etape ) {
	revenir_au_schema_022();
	update_option( 'il_test_lignes', lignes() );
	verifier( 'base 0.2.2 reconstituée, avec des rangs', 'PRIMARY,attachment_id,chemin_harmonise,chemin_natif' === index_table() && count( lignes() ) > 0, count( lignes() ) . ' rangs, ' . index_table() );
}

if ( 'migration-verifier' === $etape ) {
	// Le chargement de ce processus a déjà joué la migration, comme une requête ordinaire.
	$avant = get_option( 'il_test_lignes' );
	$ids   = get_option( 'il_test_ids' );
	verifier( 'migrée au simple chargement', $neuf === index_table(), index_table() );
	verifier( 'migration inscrite au registre', in_array( 'harmonisees-par-taille-2026-11-25', ImagesLegeres_Reglages::valeur( 'migrations_appliquees' ), true ) );
	verifier( 'aucune ligne perdue ni modifiée', lignes() === $avant, count( lignes() ) . ' / ' . count( $avant ) );
	verifier( 'second passage : rien à faire', array() === ImagesLegeres_Reglages::migrer() && ImagesLegeres_Harmonisees::migrer_cle() && $neuf === index_table() && lignes() === $avant );

	revenir_au_schema_022();
	$avant = lignes();
	$un    = ImagesLegeres_Reglages::migrer();
	$deux  = ImagesLegeres_Reglages::migrer();
	verifier( 'rejouée deux fois de suite : une seule application, lignes intactes', array( 'harmonisees-par-taille-2026-11-25' ) === $un && array() === $deux && lignes() === $avant && $neuf === index_table() );

	revenir_au_schema_022();
	$avant = lignes();
	ImagesLegeres_Harmonisees::creer();
	ImagesLegeres_Harmonisees::creer();
	verifier( 'activation (creer) deux fois sur la table 0.2.2 : migrée, lignes intactes', lignes() === $avant && $neuf === index_table(), index_table() );
	ImagesLegeres_Reglages::migrer();

	verifier( 'rangs 0.2.2 vus comme périmés', ! ImagesLegeres_Harmonisation::a_jour( $ids[0] ) );
	$anciens = array_column( lignes(), 'id' );

	foreach ( $ids as $id ) {
		ImagesLegeres_Harmonisation::generer( $id );
		verifier( "régénération de la pièce jointe $id : à jour", ImagesLegeres_Harmonisation::a_jour( $id ) );
	}

	verifier( 'régénération : les rangs existants sont conservés', array() === array_diff( $anciens, array_column( lignes(), 'id' ) ) );
	$total = count( lignes() );
	ImagesLegeres_Harmonisation::generer( $ids[0] );
	verifier( 'régénérer encore n\'ajoute aucun rang', count( lignes() ) === $total );
}

if ( 'suppression' === $etape ) {
	list( $a, $b, $c ) = get_option( 'il_test_ids' );
	$de = function ( $id ) {
		$f = array();

		foreach ( array_unique( array_column( ImagesLegeres_Harmonisees::par_attachment( $id ), 'chemin_harmonise' ) ) as $r ) {
			$f[] = base() . $r;
			$f[] = base() . $r . '.webp';
		}

		return array_values( array_filter( $f, 'is_file' ) );
	};

	$listes = ImagesLegeres_Harmonisation::fichiers_existants();
	verifier( 'chaque fichier harmonisé listé une seule fois, aucun oublié', $listes === fichiers( true ), count( $listes ) . ' / ' . count( fichiers( true ) ) );

	$attendus = array_values( array_diff( fichiers( true ), $de( $a ) ) );
	$supprimes = array();
	add_filter( 'wp_delete_file', function ( $f ) use ( &$supprimes ) { $supprimes[] = $f; return $f; } );
	wp_delete_attachment( $a, true );
	$harmonises = array_filter( $supprimes, function ( $f ) { return false !== strpos( $f, '-il-harmonise-' ); } );
	verifier( 'delete_attachment : chaque carré supprimé une seule fois', count( $harmonises ) > 0 && count( $harmonises ) === count( array_unique( $harmonises ) ), (string) count( $harmonises ) );
	verifier( 'delete_attachment : les autres pièces jointes intactes, rien ne reste de la supprimée', fichiers( true ) === $attendus && array() === ImagesLegeres_Harmonisees::par_attachment( $a ) );

	// Un carré de C que B référence aussi doit survivre à la suppression de C.
	$partage = ImagesLegeres_Harmonisees::par_attachment( $c )[0]['chemin_harmonise'];
	ImagesLegeres_Harmonisees::enregistrer( array( 'attachment_id' => $b, 'taille' => 'il_test', 'chemin_harmonise' => $partage ) );
	ImagesLegeres_Harmonisation::supprimer( $c );
	verifier( 'carré encore référencé : conservé, les autres supprimés', is_file( base() . $partage ) && array() === array_filter( $attendus, function ( $f ) use ( $c, $partage ) { return false !== strpos( $f, 'tailles-800x900' ) && false === strpos( $f, basename( $partage ) ) && is_file( $f ); } ) );

	$natifs  = fichiers( false );
	$restant = count( fichiers( true ) );
	$rendus  = ImagesLegeres_Harmonisation::tout_supprimer();
	verifier( 'Tout rendre : chaque fichier harmonisé supprimé une fois', $restant > 0 && count( $rendus ) === $restant && $rendus === array_values( array_unique( $rendus ) ) && array() === fichiers( true ), count( $rendus ) . " / $restant" );
	verifier( 'Tout rendre : aucun autre fichier touché', fichiers( false ) === $natifs, (string) count( $natifs ) );
}

if ( 'ordre' === $etape ) {
	// Un natif avec plusieurs carrés : la génération la plus récente, puis le plus grand.
	$ajouter = function ( $taille, $natif, $carre, $cote, $maj ) {
		ImagesLegeres_Harmonisees::enregistrer( array( 'attachment_id' => 999999, 'taille' => $taille, 'chemin_natif' => $natif, 'chemin_harmonise' => $carre, 'largeur' => $cote, 'hauteur' => $cote, 'maj' => $maj ) );
	};
	$ajouter( 'a', 'ordre/r.jpg', 'ordre/ancien-grand.jpg', 1536, '2026-01-01 00:00:00' );
	$ajouter( 'b', 'ordre/r.jpg', 'ordre/recent-petit.jpg', 600, '2026-02-01 00:00:00' );
	$ajouter( 'c', 'ordre/g.jpg', 'ordre/grand.jpg', 1024, '2026-02-01 00:00:00' );
	$ajouter( 'd', 'ordre/g.jpg', 'ordre/petit.jpg', 300, '2026-02-01 00:00:00' );
	$map = ImagesLegeres_Harmonisees::map_pour_chemins( array( 'ordre/r.jpg', 'ordre/g.jpg' ) );
	verifier( 'plusieurs carrés : la génération la plus récente l\'emporte', 'ordre/recent-petit.jpg' === $map['ordre/r.jpg']['chemin'], $map['ordre/r.jpg']['chemin'] );
	verifier( 'même génération : le plus grand carré l\'emporte', 'ordre/grand.jpg' === $map['ordre/g.jpg']['chemin'], $map['ordre/g.jpg']['chemin'] );
	$wpdb->delete( ImagesLegeres_Harmonisees::nom(), array( 'attachment_id' => 999999 ) );
}

echo "FIN $etape\n";
