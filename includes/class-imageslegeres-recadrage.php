<?php
/**
 * Rectangle de recadrage produit (spec § 6, ADR 0005) : méta `_images_legeres_crop` en
 * coordonnées relatives (x, y, w, h entre 0 et 1), lecture/écriture/validation. L'extension
 * n'invente jamais un cadrage : cette classe ne fait qu'appliquer ou effacer ce qu'on lui
 * donne. Les sous-commandes WP-CLI qui pilotent ces méthodes arrivent avec la mission 16.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Recadrage {

	const META = '_images_legeres_crop';
	const TOLERANCE_RATIO = 0.01;
	const COTE_MINIMAL_PX = 500;

	/**
	 * Rend le rectangle stocké sur la pièce jointe, ou null s'il n'y en a pas (y compris
	 * quand seul `fond_assume` est posé : ce n'est pas un rectangle).
	 */
	public static function obtenir( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META, true );

		if ( ! is_array( $meta ) || ! isset( $meta['x'], $meta['y'], $meta['w'], $meta['h'] ) ) {
			return null;
		}

		return $meta;
	}

	/**
	 * Rend true si la pièce jointe porte la marque « fond assumé » (aucun carré ne contient
	 * le produit entier : décision tracée, pas un échec).
	 */
	public static function fond_assume( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META, true );

		return is_array( $meta ) && ! empty( $meta['fond_assume'] );
	}

	/**
	 * Pose un rectangle en pixels, rapporté aux dimensions de référence `$largeur_ref` /
	 * `$hauteur_ref` (par défaut les dimensions actuelles de l'original), converti et stocké
	 * en coordonnées relatives. Refuse (WP_Error, rien d'écrit) un rectangle hors image, dont
	 * le ratio s'écarte de plus de 1 % du ratio cible, ou dont le carré résultant ferait moins
	 * de 500 px de côté.
	 *
	 * @return true|WP_Error
	 */
	public static function definir( $attachment_id, $x, $y, $w, $h, $largeur_ref = null, $hauteur_ref = null, $source = 'admin' ) {
		$chemin = get_attached_file( $attachment_id );

		if ( ! $chemin || ! file_exists( $chemin ) ) {
			return new WP_Error( 'images_legeres_fichier_introuvable', "fichier de la pièce jointe {$attachment_id} introuvable" );
		}

		if ( null === $largeur_ref || null === $hauteur_ref ) {
			try {
				$image       = new Imagick( $chemin );
				$largeur_ref = $image->getImageWidth();
				$hauteur_ref = $image->getImageHeight();
				$image->clear();
				$image->destroy();
			} catch ( Exception $erreur ) {
				return new WP_Error( 'images_legeres_lecture_impossible', $erreur->getMessage() );
			}
		}

		$largeur_ref = (float) $largeur_ref;
		$hauteur_ref = (float) $hauteur_ref;

		if ( $largeur_ref <= 0 || $hauteur_ref <= 0 ) {
			return new WP_Error( 'images_legeres_reference_invalide', 'dimensions de référence invalides' );
		}

		$x_rel = $x / $largeur_ref;
		$y_rel = $y / $hauteur_ref;
		$w_rel = $w / $largeur_ref;
		$h_rel = $h / $hauteur_ref;

		$verification = self::valider( $x_rel, $y_rel, $w_rel, $h_rel, $w, $h );

		if ( is_wp_error( $verification ) ) {
			return $verification;
		}

		update_post_meta(
			$attachment_id,
			self::META,
			array(
				'x'           => $x_rel,
				'y'           => $y_rel,
				'w'           => $w_rel,
				'h'           => $h_rel,
				'fond_assume' => false,
				'source'      => (string) $source,
				'date'        => current_time( 'mysql', true ),
			)
		);

		return true;
	}

	/**
	 * Marque la pièce jointe « fond assumé » : aucun rectangle, le repli fond ajouté
	 * s'applique, avec un motif tracé (spec § 10, ADR 0005 point 4).
	 */
	public static function marquer_fond_assume( $attachment_id, $motif, $source = 'admin' ) {
		update_post_meta(
			$attachment_id,
			self::META,
			array(
				'x'           => null,
				'y'           => null,
				'w'           => null,
				'h'           => null,
				'fond_assume' => true,
				'motif'       => (string) $motif,
				'source'      => (string) $source,
				'date'        => current_time( 'mysql', true ),
			)
		);

		return true;
	}

	/**
	 * Retire le rectangle (et la marque « fond assumé ») : la pièce jointe revient à l'état
	 * « à recadrer » si elle reste hors ratio.
	 */
	public static function effacer( $attachment_id ) {
		return delete_post_meta( $attachment_id, self::META );
	}

	/**
	 * Valide un rectangle en coordonnées relatives contre le ratio cible réglé : dans l'image
	 * (0 <= x, y ; x + w <= 1 ; y + h <= 1), ratio à 1 % du ratio cible, carré résultant d'au
	 * moins 500 px de côté.
	 *
	 * Le ratio se vérifie sur les dimensions en **pixels** du rectangle (`$largeur_px` /
	 * `$hauteur_px`) quand elles sont fournies, jamais sur le rapport des coordonnées
	 * relatives seules : `$w_rel / $h_rel` n'est le ratio réel que si l'image de référence
	 * est carrée (`largeur_ref == hauteur_ref`), ce qui n'est vrai que pour 81 % des photos
   * produit (spec § 1). Sur les 19 % restantes (3:4, 4:5, 2:3), cette confusion rejetait un
	 * rectangle carré valide et pouvait accepter un rectangle réellement hors ratio — trouvé
	 * en mission 16 en posant un carré 1500×1500 sur un original 2000×1500 (`w_rel` = 0,75,
	 * `h_rel` = 1,0, ratio calculé 0,75 au lieu de 1,0 attendu).
	 *
	 * @return true|WP_Error
	 */
	public static function valider( $x_rel, $y_rel, $w_rel, $h_rel, $largeur_px = null, $hauteur_px = null ) {
		if ( $w_rel <= 0 || $h_rel <= 0 ) {
			return new WP_Error( 'images_legeres_rectangle_vide', 'rectangle de largeur ou hauteur nulle' );
		}

		if ( $x_rel < 0 || $y_rel < 0 || ( $x_rel + $w_rel ) > 1.0001 || ( $y_rel + $h_rel ) > 1.0001 ) {
			return new WP_Error( 'images_legeres_rectangle_hors_image', 'le rectangle dépasse les bords de l\'image' );
		}

		$ratio_cible     = self::ratio_cible_numerique();
		$ratio_rectangle = ( null !== $largeur_px && null !== $hauteur_px && (float) $hauteur_px > 0 )
			? ( (float) $largeur_px / (float) $hauteur_px )
			: ( $w_rel / $h_rel );

		if ( abs( $ratio_rectangle - $ratio_cible ) > self::TOLERANCE_RATIO ) {
			return new WP_Error(
				'images_legeres_ratio_invalide',
				sprintf( 'ratio du rectangle (%.3f) hors tolérance du ratio cible (%.3f)', $ratio_rectangle, $ratio_cible )
			);
		}

		if ( null !== $largeur_px && null !== $hauteur_px && ( min( (float) $largeur_px, (float) $hauteur_px ) < self::COTE_MINIMAL_PX ) ) {
			return new WP_Error( 'images_legeres_trop_petit', 'le carré résultant ferait moins de 500 px de côté' );
		}

		return true;
	}

	/**
	 * Rend le ratio cible réglé (largeur / hauteur) à partir de la chaîne « 1:1 », « 4:5 »… du
	 * réglage `ratio_cible`.
	 */
	public static function ratio_cible_numerique() {
		$brut = (string) ImagesLegeres_Reglages::valeur( 'ratio_cible' );

		if ( false === strpos( $brut, ':' ) ) {
			return 1.0;
		}

		list( $largeur, $hauteur ) = array_map( 'floatval', explode( ':', $brut, 2 ) );

		if ( $largeur <= 0 || $hauteur <= 0 ) {
			return 1.0;
		}

		return $largeur / $hauteur;
	}

	/**
	 * Rend true si une pièce jointe rattachée à un produit est hors ratio cible, sans
	 * rectangle ni marque « fond assumé » : état « à recadrer » (spec § 3, ADR 0005 point 5).
	 */
	public static function a_recadrer( $attachment_id ) {
		if ( ! ImagesLegeres_Moteur::est_image_produit( $attachment_id ) ) {
			return false;
		}

		if ( null !== self::obtenir( $attachment_id ) || self::fond_assume( $attachment_id ) ) {
			return false;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
			return false;
		}

		$ratio_image = $metadata['width'] / $metadata['height'];
		$ratio_cible = self::ratio_cible_numerique();

		return abs( $ratio_image - $ratio_cible ) > self::TOLERANCE_RATIO;
	}

	/**
	 * `woocommerce_get_image_size_single` (ADR 0003 point 4, repris par l'ADR 0005 point 6) :
	 * la fiche produit sert une sous-taille carrée 1024×1024, recadrée, au lieu de 1024×0.
	 * Filtre, jamais d'écriture dans les options WooCommerce.
	 */
	public static function filtrer_taille_single( $taille ) {
		if ( ! ImagesLegeres_Reglages::valeur( 'harmonisation_produit_active' ) ) {
			return $taille;
		}

		return array(
			'width'  => 1024,
			'height' => 1024,
			'crop'   => true,
		);
	}
}
