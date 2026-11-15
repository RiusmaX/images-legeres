<?php
/**
 * Couche de compatibilité ImageMagick 6 / ImageMagick 7.
 *
 * Pourquoi elle existe : l'extension `imagick` ne définit pas les mêmes constantes selon la
 * version d'ImageMagick contre laquelle elle a été compilée. Une constante de classe absente
 * ne vaut pas `null` en PHP 8 : elle lève une `Error` fatale. Écrire `Imagick::ALPHACHANNEL_OFF`
 * sans garde tuait donc `wp images-legeres qualite` sur l'hébergement mutualisé d'Infomaniak
 * (ImageMagick 6.9.11), sans message — constat de production du 2026-11-15 (I1b).
 *
 * Deux constantes seulement sont propres à ImageMagick 7 dans ce dépôt :
 *
 * - `Imagick::ALPHACHANNEL_OFF` (ImageMagick 6 a `ALPHACHANNEL_DEACTIVATE`) ;
 * - `Imagick::METRIC_STRUCTURAL_SIMILARITY_ERROR` (pas de SSIM natif en ImageMagick 6).
 *
 * Toutes les autres employées ici (`COLORSPACE_SRGB`, `FILTER_LANCZOS`, `PIXEL_CHAR`,
 * `COMPOSITE_OVER`, `COMPOSITE_COPYOPACITY`, `GRAVITY_CENTER`, `NOISE_GAUSSIAN`,
 * `ALPHACHANNEL_REMOVE`) existent dans les deux. Elles passent tout de même par
 * `constante()` quand leur absence changerait le résultat plutôt que de le faire échouer.
 *
 * ## Simuler ImageMagick 6 là où ImageMagick 7 est installé
 *
 * Le conteneur de test embarque ImageMagick 7 : le chemin de repli ne s'y exercerait jamais.
 * Deux constantes d'environnement de test le forcent, à poser dans `wp-config.php` :
 *
 * - `IMAGES_LEGERES_SIMULER_IMAGEMAGICK_6` : masque les constantes propres à ImageMagick 7 ;
 * - `IMAGES_LEGERES_MASQUER_CONSTANTES_IMAGICK` : liste séparée par des virgules de
 *   constantes `Imagick::` à masquer en plus, pour atteindre le dernier repli
 *   (aplatissement sur fond blanc) qu'aucune version réelle ne déclenche.
 *
 * Aucune des deux n'est définie en production : le code y voit les constantes réelles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Compat {

	/**
	 * Constantes `Imagick::` qui n'existent qu'avec ImageMagick 7.
	 */
	const CONSTANTES_IMAGEMAGICK_7 = array(
		'ALPHACHANNEL_OFF',
		'METRIC_STRUCTURAL_SIMILARITY_ERROR',
	);

	/**
	 * Façons de retirer le canal alpha, de la plus fidèle à la moins, dans l'ordre d'essai.
	 * `OFF` (ImageMagick 7) et `DEACTIVATE` (ImageMagick 6) désactivent le canal sans toucher
	 * aux pixels ; `REMOVE` le compose sur la couleur de fond.
	 */
	const ALPHA_PAR_ORDRE = array(
		'ALPHACHANNEL_OFF',
		'ALPHACHANNEL_DEACTIVATE',
		'ALPHACHANNEL_REMOVE',
	);

	/**
	 * Valeur d'une constante `Imagick::<nom>`, ou `null` si elle n'existe pas ici.
	 *
	 * @param string $nom Nom court, sans le préfixe `Imagick::`.
	 * @return int|string|null
	 */
	public static function constante( $nom ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return null;
		}

		if ( in_array( $nom, self::constantes_masquees(), true ) ) {
			return null;
		}

		if ( ! defined( 'Imagick::' . $nom ) ) {
			return null;
		}

		return constant( 'Imagick::' . $nom );
	}

	/**
	 * Constantes masquées par l'environnement de test. Vide en production.
	 *
	 * @return string[]
	 */
	public static function constantes_masquees() {
		$masquees = array();

		if ( defined( 'IMAGES_LEGERES_SIMULER_IMAGEMAGICK_6' ) && IMAGES_LEGERES_SIMULER_IMAGEMAGICK_6 ) {
			$masquees = self::CONSTANTES_IMAGEMAGICK_7;
		}

		if ( defined( 'IMAGES_LEGERES_MASQUER_CONSTANTES_IMAGICK' ) && IMAGES_LEGERES_MASQUER_CONSTANTES_IMAGICK ) {
			foreach ( explode( ',', (string) IMAGES_LEGERES_MASQUER_CONSTANTES_IMAGICK ) as $nom ) {
				$nom = trim( $nom );

				if ( '' !== $nom ) {
					$masquees[] = $nom;
				}
			}
		}

		return array_values( array_unique( $masquees ) );
	}

	/**
	 * Retire le canal alpha d'une image, quelle que soit la version d'ImageMagick.
	 *
	 * Essaie les constantes de `ALPHA_PAR_ORDRE` ; si aucune n'existe — ou si toutes
	 * échouent — aplatit l'image sur un fond blanc, ce qui donne le même résultat visuel
	 * pour une image sans transparence réelle.
	 *
	 * Rend l'objet à utiliser ensuite : c'est le même, sauf sur le chemin d'aplatissement,
	 * qui produit un nouvel objet Imagick (l'ancien est détruit). L'appelant DOIT réaffecter.
	 *
	 * @param Imagick $image  Image à traiter.
	 * @param string  $moyen  Rempli avec le moyen effectivement employé (sortie).
	 * @return Imagick
	 */
	public static function retirer_alpha( Imagick $image, &$moyen = null ) {
		foreach ( self::ALPHA_PAR_ORDRE as $nom ) {
			$valeur = self::constante( $nom );

			if ( null === $valeur ) {
				continue;
			}

			try {
				$image->setImageAlphaChannel( $valeur );
				$moyen = $nom;

				return $image;
			} catch ( Throwable $erreur ) {
				unset( $erreur );
			}
		}

		return self::aplatir_sur_blanc( $image, $moyen );
	}

	/**
	 * Dernier repli : compose l'image sur un fond blanc opaque. Rend un nouvel objet Imagick
	 * et détruit l'ancien.
	 *
	 * `flattenImages()` est déprécié depuis Imagick 3.3 et retiré par certaines distributions :
	 * la composition explicite sur une toile opaque marche partout et dit ce qu'elle fait.
	 *
	 * @param Imagick $image Image à aplatir.
	 * @param string  $moyen Rempli avec `aplatissement` (sortie).
	 * @return Imagick
	 */
	private static function aplatir_sur_blanc( Imagick $image, &$moyen = null ) {
		$largeur = $image->getImageWidth();
		$hauteur = $image->getImageHeight();
		$format  = $image->getImageFormat();

		$toile = new Imagick();
		$toile->newImage( $largeur, $hauteur, new ImagickPixel( 'white' ) );
		$toile->setImageFormat( $format );

		$composite = self::constante( 'COMPOSITE_OVER' );

		if ( null === $composite ) {
			// `COMPOSITE_OVER` existe dans toutes les versions connues d'imagick ; s'il
			// manquait, mieux vaut rendre l'image telle quelle qu'une toile blanche vide.
			$toile->destroy();
			$moyen = 'aucun';

			return $image;
		}

		$toile->compositeImage( $image, $composite, 0, 0 );

		$image->clear();
		$image->destroy();

		$moyen = 'aplatissement';

		return $toile;
	}
}
