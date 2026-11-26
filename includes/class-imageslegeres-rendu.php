<?php
/**
 * Primitives de rendu : recadrage sur un rectangle (stocké, ou centré à défaut) pour les
 * sous-tailles harmonisées, fond ajouté (ADR 0003 point 2) pour les seules images de flux. Opère sur un objet Imagick fourni par
 * l'appelant ; n'ouvre, n'écrit et ne nomme aucun fichier — c'est le rôle de
 * `ImagesLegeres_Harmonisation`.
 *
 * Cette classe remplace `ImagesLegeres_Editor` (F5, 2026-11-15). L'extension ne branche plus
 * d'éditeur sur `wp_image_editors` : un éditeur écrit la sous-taille sous le nom de fichier
 * natif et occupe son entrée de `_wp_attachment_metadata['sizes']`, donc la sert à tous les
 * visiteurs sans passer par la réécriture d'adresse — le mode aperçu ne protégeait plus rien.
 * Les sous-tailles natives repassent intégralement par WordPress, inchangées.
 *
 * Les coordonnées du rectangle sont relatives aux dimensions de l'image *au moment de l'appel* :
 * elles survivent donc à une retaille de l'original (ADR 0005 point 2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Rendu {

	/**
	 * Une taille déclarée est « cible » quand largeur et hauteur sont toutes les deux posées
	 * et que leur ratio correspond (à 1 % près) au ratio cible réglé.
	 */
	public static function est_taille_cible( array $taille, $ratio_cible ) {
		$largeur = isset( $taille['width'] ) ? (int) $taille['width'] : 0;
		$hauteur = isset( $taille['height'] ) ? (int) $taille['height'] : 0;

		if ( $largeur <= 0 || $hauteur <= 0 ) {
			return false;
		}

		return abs( ( $largeur / $hauteur ) - $ratio_cible ) <= ImagesLegeres_Recadrage::TOLERANCE_RATIO;
	}

	/**
	 * Retrouve la pièce jointe à partir du chemin absolu d'un fichier original. Interroge
	 * `_wp_attached_file` plutôt que de dépendre d'un contexte posé par un appelant.
	 */
	public static function attachment_id_pour_fichier( $chemin_absolu ) {
		global $wpdb;

		$relatif = ImagesLegeres_Moteur::chemin_relatif( $chemin_absolu );

		if ( '' === $relatif ) {
			return 0;
		}

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$relatif
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Recadre l'image sur le rectangle relatif stocké, puis la ramène à la taille cible. Le
	 * rectangle est calculé sur les dimensions *actuelles* de l'image — donc toujours la
	 * bonne zone, retaillée ou non.
	 */
	public static function appliquer_recadrage( Imagick $image, array $rectangle, $largeur_cible, $hauteur_cible ) {
		$boite = self::calculer_boite_recadrage( $rectangle, $image->getImageWidth(), $image->getImageHeight() );

		$image->cropImage( $boite['largeur'], $boite['hauteur'], $boite['x'], $boite['y'] );
		$image->setImagePage( 0, 0, 0, 0 );
		$image->resizeImage( $largeur_cible, $hauteur_cible, Imagick::FILTER_LANCZOS, 1 );
	}

	/**
	 * Rectangle relatif centré, le plus grand que l'image contient au ratio donné : le repli
	 * d'une photo produit harmonisée tant qu'aucun cadrage n'est posé.
	 */
	public static function rectangle_centre( $largeur, $hauteur, $ratio ) {
		$w = min( 1.0, ( $hauteur * $ratio ) / $largeur );
		$h = min( 1.0, ( $largeur / $ratio ) / $hauteur );

		return array(
			'x' => ( 1 - $w ) / 2,
			'y' => ( 1 - $h ) / 2,
			'w' => $w,
			'h' => $h,
		);
	}

	/**
	 * Convertit un rectangle relatif en boîte de recadrage en pixels, rapportée aux
	 * dimensions données (celles de l'image au moment de l'appel — d'où la survie du
	 * rectangle à une retaille de l'original, ADR 0005 point 2). Bornée à l'intérieur de
	 * l'image. Isolé pour être vérifiable au pixel près.
	 */
	public static function calculer_boite_recadrage( array $rectangle, $largeur_courante, $hauteur_courante ) {
		$x1 = (int) round( $rectangle['x'] * $largeur_courante );
		$y1 = (int) round( $rectangle['y'] * $hauteur_courante );
		$x2 = (int) round( ( $rectangle['x'] + $rectangle['w'] ) * $largeur_courante );
		$y2 = (int) round( ( $rectangle['y'] + $rectangle['h'] ) * $hauteur_courante );

		$x1 = max( 0, min( $x1, $largeur_courante - 1 ) );
		$y1 = max( 0, min( $y1, $hauteur_courante - 1 ) );
		$x2 = max( $x1 + 1, min( $x2, $largeur_courante ) );
		$y2 = max( $y1 + 1, min( $y2, $hauteur_courante ) );

		return array(
			'x'       => $x1,
			'y'       => $y1,
			'largeur' => $x2 - $x1,
			'hauteur' => $y2 - $y1,
		);
	}

	/**
	 * Ajoute un fond (jamais de recadrage) : la photo entière tient dans le rectangle cible,
	 * centrée, le reste comblé par la couleur médiane des pixels de bord (repli : blanc), ou
	 * la couleur réglée si `couleur_fond_auto` est désactivé (ADR 0003 point 2).
	 */
	public static function appliquer_fond_ajoute( Imagick $image, $largeur_cible, $hauteur_cible ) {
		$largeur_courante = $image->getImageWidth();
		$hauteur_courante = $image->getImageHeight();

		$echelle          = min( $largeur_cible / $largeur_courante, $hauteur_cible / $hauteur_courante );
		$nouvelle_largeur = max( 1, (int) round( $largeur_courante * $echelle ) );
		$nouvelle_hauteur = max( 1, (int) round( $hauteur_courante * $echelle ) );

		$couleur = self::couleur_fond( $image );

		$image->resizeImage( $nouvelle_largeur, $nouvelle_hauteur, Imagick::FILTER_LANCZOS, 1 );

		$toile = new Imagick();
		$toile->newImage( $largeur_cible, $hauteur_cible, $couleur );
		$toile->setImageFormat( $image->getImageFormat() );

		$decalage_x = (int) round( ( $largeur_cible - $nouvelle_largeur ) / 2 );
		$decalage_y = (int) round( ( $hauteur_cible - $nouvelle_hauteur ) / 2 );

		$toile->compositeImage( $image, Imagick::COMPOSITE_OVER, $decalage_x, $decalage_y );

		// Remplace le contenu de l'image appelante par la toile composée : même objet,
		// nouveaux pixels, pour que l'appelant continue d'utiliser la même variable.
		$image->clear();
		$pixels = $toile->getImageBlob();
		$image->readImageBlob( $pixels );
		$toile->clear();
		$toile->destroy();
	}

	/**
	 * Couleur de fond réglée, ou couleur médiane des pixels de bord de l'image si
	 * `couleur_fond_auto` est actif (défaut), ou blanc si la médiane ne peut être calculée.
	 */
	public static function couleur_fond( Imagick $image ) {
		$reglages = ImagesLegeres_Reglages::tout();

		if ( ! $reglages['couleur_fond_auto'] && ! empty( $reglages['couleur_fond'] ) ) {
			try {
				return new ImagickPixel( $reglages['couleur_fond'] );
			} catch ( Exception $erreur ) {
				return new ImagickPixel( 'white' );
			}
		}

		$mediane = self::couleur_mediane_des_bords( $image );

		return $mediane ? $mediane : new ImagickPixel( 'white' );
	}

	/**
	 * Échantillonne les pixels des quatre bords de l'image et rend la couleur médiane
	 * (canal par canal), ou null si l'image est trop petite pour échantillonner.
	 */
	public static function couleur_mediane_des_bords( Imagick $image ) {
		$largeur = $image->getImageWidth();
		$hauteur = $image->getImageHeight();

		if ( $largeur < 2 || $hauteur < 2 ) {
			return null;
		}

		$echantillons = min( 20, $largeur, $hauteur );
		$points       = array();

		for ( $i = 0; $i < $echantillons; $i++ ) {
			$x = (int) round( ( $i / max( 1, $echantillons - 1 ) ) * ( $largeur - 1 ) );
			$y = (int) round( ( $i / max( 1, $echantillons - 1 ) ) * ( $hauteur - 1 ) );

			$points[] = array( $x, 0 );
			$points[] = array( $x, $hauteur - 1 );
			$points[] = array( 0, $y );
			$points[] = array( $largeur - 1, $y );
		}

		$rouges = array();
		$verts  = array();
		$bleus  = array();

		foreach ( $points as $point ) {
			try {
				$pixel    = $image->getImagePixelColor( $point[0], $point[1] );
				$couleurs = $pixel->getColor();
				$rouges[] = (int) $couleurs['r'];
				$verts[]  = (int) $couleurs['g'];
				$bleus[]  = (int) $couleurs['b'];
			} catch ( Exception $erreur ) {
				continue;
			}
		}

		if ( empty( $rouges ) ) {
			return null;
		}

		return new ImagickPixel(
			sprintf( 'rgb(%d,%d,%d)', self::mediane( $rouges ), self::mediane( $verts ), self::mediane( $bleus ) )
		);
	}

	private static function mediane( array $valeurs ) {
		sort( $valeurs );
		$milieu = (int) floor( count( $valeurs ) / 2 );

		return $valeurs[ $milieu ];
	}
}
