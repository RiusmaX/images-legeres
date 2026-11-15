<?php
/**
 * Éditeur de sous-tailles dérivé d'Imagick (spec § 3.3, ADR 0003 point 5, ADR 0005).
 *
 * Branché par le filtre `wp_image_editors`, ajouté en tête de liste. Son seul écart au
 * comportement natif : les sous-tailles au ratio cible (spec § 5, réglage `ratio_cible`) des
 * images rattachées à un produit, quand l'harmonisation est active. Pour ces sous-tailles-là :
 * un rectangle stocké (`_images_legeres_crop`) donne un recadrage exact ; son absence donne un
 * fond ajouté (jamais un recadrage automatique, ADR 0005). Toute autre sous-taille et toute
 * autre image passent par le comportement natif, inchangé.
 *
 * Deux points d'entrée se recoupent : `make_subsize()` (chemin pris par
 * `wp_create_image_subsizes()` depuis WordPress 5.3 dès que l'éditeur l'implémente — c'est le
 * cas d'Imagick — et donc par `wp media regenerate`, l'envoi d'un média et la régénération
 * WooCommerce) et `multi_resize()` (resté le chemin natif pour tout appelant qui ne passe pas
 * par `wp_create_image_subsizes()`). Les deux délèguent à la même logique.
 *
 * Les coordonnées du rectangle sont relatives aux dimensions de l'image *au moment de l'appel* :
 * elles survivent donc à une retaille de l'original (ADR 0005 point 2), puisque c'est toujours
 * la même proportion qui est appliquée, quelle que soit la résolution courante.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress ne charge WP_Image_Editor_Imagick qu'à la demande (premier appel de
// wp_get_image_editor()) ; notre autoloader peut inclure ce fichier avant, d'où ce chargement
// explicite pour que la classe mère existe toujours au moment de la déclaration ci-dessous.
if ( ! class_exists( 'WP_Image_Editor_Imagick' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
	require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
}

class ImagesLegeres_Editor extends WP_Image_Editor_Imagick {

	/**
	 * Filtre `wp_image_editors` : notre éditeur en tête, les éditeurs natifs en repli pour
	 * tout ce qu'il ne traite pas différemment.
	 */
	public static function enregistrer( $editeurs ) {
		array_unshift( $editeurs, __CLASS__ );

		return array_unique( $editeurs );
	}

	/**
	 * Chemin réellement pris par `wp_create_image_subsizes()` sur cette version de WordPress
	 * (vérifié le 2026-11-15 : `_wp_make_subsizes()` préfère `make_subsize()` dès que l'éditeur
	 * l'implémente, et ne retombe sur `multi_resize()` que sinon — jamais le cas pour Imagick).
	 * Une sous-taille à la fois : c'est le point d'entrée à surveiller pour l'envoi d'un média,
	 * `wp media regenerate` et la régénération déclenchée par WooCommerce.
	 */
	public function make_subsize( $size_data ) {
		$harmonisee = $this->harmoniser_si_applicable( $size_data );

		return null !== $harmonisee ? $harmonisee : parent::make_subsize( $size_data );
	}

	/**
	 * Conservé pour tout appelant qui passerait directement par `multi_resize()` plutôt que
	 * par `wp_create_image_subsizes()` — même logique que `make_subsize()`, une taille à la fois.
	 */
	public function multi_resize( $sizes ) {
		$cibles = array();
		$autres = array();

		foreach ( $sizes as $nom => $taille ) {
			$harmonisee = $this->harmoniser_si_applicable( $taille );

			if ( null !== $harmonisee ) {
				$cibles[ $nom ] = $harmonisee;
			} else {
				$autres[ $nom ] = $taille;
			}
		}

		$resultats = $autres ? parent::multi_resize( $autres ) : array();

		foreach ( $cibles as $nom => $resultat ) {
			$resultats[ $nom ] = $resultat;
		}

		return $resultats;
	}

	/**
	 * Rend la sous-taille harmonisée (recadrée ou avec fond ajouté) pour `$size_data`, ou null
	 * quand les conditions de l'harmonisation ne sont pas réunies (réglage inactif, image hors
	 * produit, ou taille qui n'est pas au ratio cible) — l'appelant retombe alors sur le
	 * comportement natif.
	 */
	private function harmoniser_si_applicable( array $size_data ) {
		if ( ! ImagesLegeres_Reglages::valeur( 'harmonisation_produit_active' ) ) {
			return null;
		}

		$attachment_id = self::attachment_id_pour_fichier( $this->file );

		if ( ! $attachment_id || ! ImagesLegeres_Moteur::est_image_produit( $attachment_id ) ) {
			return null;
		}

		if ( ! self::est_taille_cible( $size_data, ImagesLegeres_Recadrage::ratio_cible_numerique() ) ) {
			return null;
		}

		return $this->generer_sous_taille_harmonisee( $attachment_id, $size_data );
	}

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
	 * Retrouve la pièce jointe à partir du chemin absolu du fichier en cours d'édition
	 * (toujours l'original : `wp_create_image_subsizes()` charge l'éditeur sur ce fichier).
	 * Interroge `_wp_attached_file` plutôt que de dépendre d'un contexte posé par un appelant :
	 * fonctionne quel que soit le chemin qui a mené à `multi_resize()` (envoi, régénération,
	 * commande WP-CLI).
	 */
	public static function attachment_id_pour_fichier( $chemin_absolu ) {
		global $wpdb;

		$relatif = ImagesLegeres_Moteur::chemin_relatif( $chemin_absolu );

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$relatif
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Produit une sous-taille harmonisée : recadrage exact si un rectangle est posé, fond
	 * ajouté sinon (ADR 0005 point 4). Rend un tableau au format attendu par
	 * `wp_create_image_subsizes()` (file, width, height, mime-type, filesize), ou null en cas
	 * d'erreur.
	 */
	protected function generer_sous_taille_harmonisee( $attachment_id, array $taille ) {
		$largeur_cible = (int) $taille['width'];
		$hauteur_cible = (int) $taille['height'];

		try {
			$clone = clone $this->image;
		} catch ( Exception $erreur ) {
			return null;
		}

		$rectangle = ImagesLegeres_Recadrage::obtenir( $attachment_id );

		try {
			if ( $rectangle ) {
				self::appliquer_recadrage( $clone, $rectangle, $largeur_cible, $hauteur_cible );
			} else {
				self::appliquer_fond_ajoute( $clone, $largeur_cible, $hauteur_cible );
			}

			$extension   = strtolower( pathinfo( $this->file, PATHINFO_EXTENSION ) );
			$nom_fichier = $this->generate_filename( $largeur_cible . 'x' . $hauteur_cible, null, $extension );

			$clone->setImageFormat( $this->image->getImageFormat() );
			$clone->writeImage( $nom_fichier );
			$clone->clear();
			$clone->destroy();
		} catch ( Exception $erreur ) {
			if ( isset( $clone ) ) {
				$clone->clear();
				$clone->destroy();
			}

			return null;
		}

		return array(
			'file'      => wp_basename( $nom_fichier ),
			'width'     => $largeur_cible,
			'height'    => $hauteur_cible,
			'mime-type' => $this->mime_type,
			'filesize'  => filesize( $nom_fichier ),
		);
	}

	/**
	 * Recadre l'image (clone) sur le rectangle relatif stocké, puis la ramène à la taille
	 * cible. Le rectangle est calculé sur les dimensions *actuelles* du clone — celles de
	 * l'original au moment de l'appel — donc toujours la bonne zone, retaillé ou non.
	 */
	public static function appliquer_recadrage( Imagick $image, array $rectangle, $largeur_cible, $hauteur_cible ) {
		$boite = self::calculer_boite_recadrage( $rectangle, $image->getImageWidth(), $image->getImageHeight() );

		$image->cropImage( $boite['largeur'], $boite['hauteur'], $boite['x'], $boite['y'] );
		$image->setImagePage( 0, 0, 0, 0 );
		$image->resizeImage( $largeur_cible, $hauteur_cible, Imagick::FILTER_LANCZOS, 1 );
	}

	/**
	 * Convertit un rectangle relatif en boîte de recadrage en pixels, rapportée aux
	 * dimensions `$largeur_courante` / `$hauteur_courante` données (celles de l'image au
	 * moment de l'appel — d'où la survie du rectangle à une retaille de l'original, ADR 0005
	 * point 2). Bornée à l'intérieur de l'image. Isolé de `appliquer_recadrage()` pour être
	 * vérifiable au pixel près, indépendamment du flou introduit par un redimensionnement.
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
		$hauteur_courante  = $image->getImageHeight();

		$echelle = min( $largeur_cible / $largeur_courante, $hauteur_cible / $hauteur_courante );
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

		// Remplace le contenu du clone appelant par la toile composée : même objet, nouveau
		// pixel, pour que l'appelant continue d'utiliser la même variable $image.
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
				$pixel     = $image->getImagePixelColor( $point[0], $point[1] );
				$couleurs  = $pixel->getColor();
				$rouges[]  = (int) $couleurs['r'];
				$verts[]   = (int) $couleurs['g'];
				$bleus[]   = (int) $couleurs['b'];
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
