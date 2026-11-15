<?php
/**
 * SSIM et PSNR calculés en PHP pur, sans la métrique d'ImageMagick.
 *
 * Pourquoi cette classe existe : `Imagick::METRIC_STRUCTURAL_SIMILARITY_ERROR` n'apparaît
 * qu'avec ImageMagick 7. Sur l'hébergement mutualisé d'Infomaniak (ImageMagick 6.9.11), la
 * constante n'est pas définie du tout : `wp images-legeres qualite` y mourait d'une erreur
 * fatale PHP 8, sans message et sans code de sortie exploitable (constaté le 2026-11-15).
 *
 * Définition retenue, identique à `compare -metric SSIM` d'ImageMagick 7 et au témoin
 * `runs/2026-11-15-monko-wp-perf/images-legeres/ssim.py` qui a servi à mesurer à la main :
 * SSIM de Wang et al. 2004, fenêtre gaussienne 11x11 d'écart-type 1,5 normalisée à 1,
 * C1 = 0,01², C2 = 0,03², sur la seule luminance Rec. 709 (0,212656 R + 0,715158 V +
 * 0,072186 B) de valeurs ramenées à l'intervalle [0, 1]. La corrélation est « valide » :
 * aucun remplissage de bord, la carte de SSIM fait (largeur - 10) x (hauteur - 10), et le
 * SSIM rendu en est la moyenne. Le PSNR est calculé sur la même luminance, sur toute
 * l'image, et vaut l'infini quand les deux images sont identiques.
 *
 * Mémoire : la mesure se fait toujours à pleine résolution, jamais sur une réduction. Le
 * calcul ne garde jamais l'image entière en mémoire — le noyau étant séparable, il suffit
 * de onze lignes déjà filtrées horizontalement pour produire une ligne de sortie. Le pic
 * est donc de l'ordre de 11 x largeur x 5 flottants (environ 8 Mo pour 1920 px de large),
 * plus le bloc de pixels en cours de lecture (16 lignes).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Ssim {

	const TAILLE_NOYAU = 11;
	const SIGMA        = 1.5;

	/** C1 = (0,01 x L)² et C2 = (0,03 x L)² avec L = 1 (valeurs ramenées à [0, 1]). */
	const C1 = 0.0001;
	const C2 = 0.0009;

	/** Coefficients de luminance Rec. 709, ceux d'ImageMagick 7 et de ssim.py. */
	const LUMA_R = 0.212656;
	const LUMA_V = 0.715158;
	const LUMA_B = 0.072186;

	/** Nombre de lignes lues en une fois chez le pilote de pixels. */
	const LIGNES_PAR_BLOC = 16;

	/**
	 * Noyau gaussien 1D normalisé. Le noyau 2D est son produit extérieur par lui-même :
	 * comme il somme à 1, le noyau 2D somme aussi à 1 et le filtre séparable donne
	 * exactement le même résultat que la convolution 11x11.
	 */
	public static function noyau() {
		static $noyau = null;

		if ( null !== $noyau ) {
			return $noyau;
		}

		$noyau  = array();
		$somme  = 0.0;
		$centre = ( self::TAILLE_NOYAU - 1 ) / 2.0;

		for ( $i = 0; $i < self::TAILLE_NOYAU; $i++ ) {
			$x       = $i - $centre;
			$valeur  = exp( - ( $x * $x ) / ( 2.0 * self::SIGMA * self::SIGMA ) );
			$noyau[] = $valeur;
			$somme  += $valeur;
		}

		foreach ( $noyau as $i => $valeur ) {
			$noyau[ $i ] = $valeur / $somme;
		}

		return $noyau;
	}

	/**
	 * Compare deux images et rend array( 'ssim', 'psnr', 'largeur', 'hauteur', 'pilote' ).
	 *
	 * Chaque argument est soit un objet Imagick déjà ouvert (cas normal : les images ont
	 * déjà été normalisées par ImagesLegeres_Qualite, les relire depuis le disque mesurerait
	 * autre chose), soit un chemin de fichier.
	 *
	 * @return array|WP_Error
	 */
	public static function comparer( $image_a, $image_b ) {
		$a = self::lecteur( $image_a );

		if ( is_wp_error( $a ) ) {
			return $a;
		}

		$b = self::lecteur( $image_b );

		if ( is_wp_error( $b ) ) {
			return $b;
		}

		$largeur = $a['largeur'];
		$hauteur = $a['hauteur'];

		if ( $largeur !== $b['largeur'] || $hauteur !== $b['hauteur'] ) {
			return new WP_Error(
				'images_legeres_ssim_dimensions',
				sprintf(
					'SSIM impossible : dimensions différentes (%dx%d contre %dx%d).',
					$largeur,
					$hauteur,
					$b['largeur'],
					$b['hauteur']
				)
			);
		}

		$taille = self::TAILLE_NOYAU;

		if ( $largeur < $taille || $hauteur < $taille ) {
			return new WP_Error(
				'images_legeres_ssim_trop_petite',
				sprintf( 'SSIM impossible : image de %dx%d px, il en faut au moins %dx%d.', $largeur, $hauteur, $taille, $taille )
			);
		}

		$noyau  = self::noyau();
		$sortie = $largeur - $taille + 1;

		$anneau       = array();
		$somme_ssim   = 0.0;
		$compte_ssim  = 0;
		$somme_erreur = 0.0;

		$bloc      = self::LIGNES_PAR_BLOC;
		$lignes_a  = array();
		$lignes_b  = array();

		for ( $y = 0; $y < $hauteur; $y++ ) {
			if ( 0 === $y % $bloc ) {
				$combien  = min( $bloc, $hauteur - $y );
				$lignes_a = call_user_func( $a['lire'], $y, $combien );
				$lignes_b = call_user_func( $b['lire'], $y, $combien );

				if ( is_wp_error( $lignes_a ) ) {
					return $lignes_a;
				}

				if ( is_wp_error( $lignes_b ) ) {
					return $lignes_b;
				}
			}

			$ligne_a = $lignes_a[ $y % $bloc ];
			$ligne_b = $lignes_b[ $y % $bloc ];

			$carre_a = array();
			$carre_b = array();
			$produit = array();

			for ( $x = 0; $x < $largeur; $x++ ) {
				$va = $ligne_a[ $x ];
				$vb = $ligne_b[ $x ];

				$carre_a[ $x ] = $va * $va;
				$carre_b[ $x ] = $vb * $vb;
				$produit[ $x ] = $va * $vb;

				$ecart         = $va - $vb;
				$somme_erreur += $ecart * $ecart;
			}

			$anneau[ $y % $taille ] = array(
				self::filtrer_ligne( $ligne_a, $sortie, $noyau ),
				self::filtrer_ligne( $ligne_b, $sortie, $noyau ),
				self::filtrer_ligne( $carre_a, $sortie, $noyau ),
				self::filtrer_ligne( $carre_b, $sortie, $noyau ),
				self::filtrer_ligne( $produit, $sortie, $noyau ),
			);

			if ( $y < $taille - 1 ) {
				continue;
			}

			// Les onze lignes de l'anneau, de la plus ancienne (y - 10) à la plus récente (y).
			$fenetre = array();
			for ( $i = 0; $i < $taille; $i++ ) {
				$fenetre[ $i ] = $anneau[ ( $y + 1 + $i ) % $taille ];
			}

			$colonnes = array();
			for ( $plan = 0; $plan < 5; $plan++ ) {
				$empilees = array();
				for ( $i = 0; $i < $taille; $i++ ) {
					$empilees[ $i ] = $fenetre[ $i ][ $plan ];
				}
				$colonnes[ $plan ] = self::filtrer_colonnes( $empilees, $sortie, $noyau );
			}

			$moyenne_a = $colonnes[0];
			$moyenne_b = $colonnes[1];
			$moment_a  = $colonnes[2];
			$moment_b  = $colonnes[3];
			$moment_ab = $colonnes[4];

			for ( $x = 0; $x < $sortie; $x++ ) {
				$ma = $moyenne_a[ $x ];
				$mb = $moyenne_b[ $x ];

				$variance_a = $moment_a[ $x ] - $ma * $ma;
				$variance_b = $moment_b[ $x ] - $mb * $mb;
				$covariance = $moment_ab[ $x ] - $ma * $mb;

				$somme_ssim += ( ( 2.0 * $ma * $mb + self::C1 ) * ( 2.0 * $covariance + self::C2 ) )
					/ ( ( $ma * $ma + $mb * $mb + self::C1 ) * ( $variance_a + $variance_b + self::C2 ) );
				++$compte_ssim;
			}
		}

		if ( 0 === $compte_ssim ) {
			return new WP_Error( 'images_legeres_ssim_vide', 'SSIM impossible : aucune fenêtre complète dans l\'image.' );
		}

		$erreur_moyenne = $somme_erreur / ( $largeur * $hauteur );

		return array(
			'ssim'    => $somme_ssim / $compte_ssim,
			'psnr'    => 0.0 === $erreur_moyenne ? INF : 10.0 * log10( 1.0 / $erreur_moyenne ),
			'largeur' => $largeur,
			'hauteur' => $hauteur,
			'pilote'  => $a['pilote'],
		);
	}

	/**
	 * Corrélation horizontale « valide » à onze coefficients, déroulée : c'est la boucle la
	 * plus chaude du calcul (cinq plans par ligne), et la forme déroulée y vaut un facteur
	 * deux à trois sur la forme avec boucle interne.
	 */
	private static function filtrer_ligne( array $source, $sortie, array $noyau ) {
		$n0 = $noyau[0];
		$n1 = $noyau[1];
		$n2 = $noyau[2];
		$n3 = $noyau[3];
		$n4 = $noyau[4];
		$n5 = $noyau[5];
		$n6 = $noyau[6];
		$n7 = $noyau[7];
		$n8 = $noyau[8];
		$n9 = $noyau[9];
		$na = $noyau[10];

		$resultat = array();

		for ( $x = 0; $x < $sortie; $x++ ) {
			$resultat[ $x ] = $source[ $x ] * $n0
				+ $source[ $x + 1 ] * $n1
				+ $source[ $x + 2 ] * $n2
				+ $source[ $x + 3 ] * $n3
				+ $source[ $x + 4 ] * $n4
				+ $source[ $x + 5 ] * $n5
				+ $source[ $x + 6 ] * $n6
				+ $source[ $x + 7 ] * $n7
				+ $source[ $x + 8 ] * $n8
				+ $source[ $x + 9 ] * $n9
				+ $source[ $x + 10 ] * $na;
		}

		return $resultat;
	}

	/** Même corrélation, dans l'autre direction : onze lignes déjà filtrées horizontalement. */
	private static function filtrer_colonnes( array $lignes, $sortie, array $noyau ) {
		$c0 = $lignes[0];
		$c1 = $lignes[1];
		$c2 = $lignes[2];
		$c3 = $lignes[3];
		$c4 = $lignes[4];
		$c5 = $lignes[5];
		$c6 = $lignes[6];
		$c7 = $lignes[7];
		$c8 = $lignes[8];
		$c9 = $lignes[9];
		$ca = $lignes[10];

		$n0 = $noyau[0];
		$n1 = $noyau[1];
		$n2 = $noyau[2];
		$n3 = $noyau[3];
		$n4 = $noyau[4];
		$n5 = $noyau[5];
		$n6 = $noyau[6];
		$n7 = $noyau[7];
		$n8 = $noyau[8];
		$n9 = $noyau[9];
		$na = $noyau[10];

		$resultat = array();

		for ( $x = 0; $x < $sortie; $x++ ) {
			$resultat[ $x ] = $c0[ $x ] * $n0
				+ $c1[ $x ] * $n1
				+ $c2[ $x ] * $n2
				+ $c3[ $x ] * $n3
				+ $c4[ $x ] * $n4
				+ $c5[ $x ] * $n5
				+ $c6[ $x ] * $n6
				+ $c7[ $x ] * $n7
				+ $c8[ $x ] * $n8
				+ $c9[ $x ] * $n9
				+ $ca[ $x ] * $na;
		}

		return $resultat;
	}

	/**
	 * Pilote de lecture des pixels : Imagick quand il est là (objet déjà ouvert ou chemin),
	 * GD sinon. Rend array( 'largeur', 'hauteur', 'pilote', 'lire' ) où `lire` est un
	 * appelable ( $y, $nombre ) qui rend $nombre lignes de luminance dans [0, 1].
	 *
	 * @return array|WP_Error
	 */
	private static function lecteur( $image ) {
		if ( class_exists( 'Imagick' ) && $image instanceof Imagick ) {
			return self::lecteur_imagick( $image );
		}

		if ( ! is_string( $image ) ) {
			return new WP_Error( 'images_legeres_ssim_source', 'SSIM impossible : source ni objet Imagick ni chemin de fichier.' );
		}

		if ( ! file_exists( $image ) ) {
			return new WP_Error( 'images_legeres_ssim_source', sprintf( 'SSIM impossible : fichier introuvable (%s).', $image ) );
		}

		if ( class_exists( 'Imagick' ) ) {
			try {
				$objet = new Imagick( $image );
			} catch ( Throwable $erreur ) {
				return new WP_Error( 'images_legeres_ssim_lecture', sprintf( 'SSIM impossible : %s', $erreur->getMessage() ) );
			}

			if ( Imagick::COLORSPACE_SRGB !== $objet->getImageColorspace() ) {
				$objet->transformImageColorspace( Imagick::COLORSPACE_SRGB );
			}

			return self::lecteur_imagick( $objet );
		}

		return self::lecteur_gd( $image );
	}

	/** @return array|WP_Error */
	private static function lecteur_imagick( Imagick $objet ) {
		try {
			$largeur = (int) $objet->getImageWidth();
			$hauteur = (int) $objet->getImageHeight();
		} catch ( Throwable $erreur ) {
			return new WP_Error( 'images_legeres_ssim_lecture', sprintf( 'SSIM impossible : %s', $erreur->getMessage() ) );
		}

		$lire = function ( $y, $nombre ) use ( $objet, $largeur ) {
			try {
				$plat = $objet->exportImagePixels( 0, (int) $y, $largeur, (int) $nombre, 'RGB', Imagick::PIXEL_CHAR );
			} catch ( Throwable $erreur ) {
				return new WP_Error( 'images_legeres_ssim_pixels', sprintf( 'SSIM impossible : %s', $erreur->getMessage() ) );
			}

			return self::luminance_depuis_rvb( $plat, $largeur, (int) $nombre );
		};

		return array(
			'largeur' => $largeur,
			'hauteur' => $hauteur,
			'pilote'  => 'imagick',
			'lire'    => $lire,
		);
	}

	/** @return array|WP_Error */
	private static function lecteur_gd( $chemin ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return new WP_Error( 'images_legeres_ssim_pilote', 'SSIM impossible : ni Imagick ni GD ne sont disponibles.' );
		}

		$donnees = file_get_contents( $chemin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $donnees ) {
			return new WP_Error( 'images_legeres_ssim_lecture', sprintf( 'SSIM impossible : lecture de %s refusée.', $chemin ) );
		}

		$ressource = @imagecreatefromstring( $donnees ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $ressource ) {
			return new WP_Error( 'images_legeres_ssim_lecture', sprintf( 'SSIM impossible : GD ne sait pas décoder %s.', $chemin ) );
		}

		if ( ! imageistruecolor( $ressource ) ) {
			imagepalettetotruecolor( $ressource );
		}

		$largeur = imagesx( $ressource );
		$hauteur = imagesy( $ressource );

		$lire = function ( $y, $nombre ) use ( $ressource, $largeur ) {
			$lignes = array();

			for ( $i = 0; $i < $nombre; $i++ ) {
				$ligne = array();

				for ( $x = 0; $x < $largeur; $x++ ) {
					$couleur     = imagecolorat( $ressource, $x, $y + $i );
					$ligne[ $x ] = ( self::LUMA_R * ( ( $couleur >> 16 ) & 0xFF )
						+ self::LUMA_V * ( ( $couleur >> 8 ) & 0xFF )
						+ self::LUMA_B * ( $couleur & 0xFF ) ) / 255.0;
				}

				$lignes[ $i ] = $ligne;
			}

			return $lignes;
		};

		return array(
			'largeur' => $largeur,
			'hauteur' => $hauteur,
			'pilote'  => 'gd',
			'lire'    => $lire,
		);
	}

	/** Tableau plat R,V,B (0-255) vers lignes de luminance dans [0, 1]. */
	private static function luminance_depuis_rvb( array $plat, $largeur, $nombre ) {
		$lignes = array();
		$indice = 0;

		for ( $i = 0; $i < $nombre; $i++ ) {
			$ligne = array();

			for ( $x = 0; $x < $largeur; $x++ ) {
				$ligne[ $x ] = ( self::LUMA_R * $plat[ $indice ]
					+ self::LUMA_V * $plat[ $indice + 1 ]
					+ self::LUMA_B * $plat[ $indice + 2 ] ) / 255.0;
				$indice     += 3;
			}

			$lignes[ $i ] = $ligne;
		}

		return $lignes;
	}
}
