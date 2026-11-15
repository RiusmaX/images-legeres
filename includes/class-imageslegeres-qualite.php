<?php
/**
 * Mesure de la « perte invisible » (spec § 7) : SSIM et PSNR entre une image source et sa
 * version WebP, à dimensions égales.
 *
 * Pourquoi « à dimensions égales » : le moteur peut retailler l'original avant de le
 * convertir (ImagesLegeres_Moteur::retailler_original, § 3.2). Comparer l'original d'avant
 * retaille au WebP d'après mesure alors deux pertes à la fois — la retaille ET la
 * compression — et impute à la seconde ce que la première a coûté. Ici, si les deux images
 * n'ont pas les mêmes dimensions, la source est ramenée aux dimensions du WebP avec le même
 * filtre que le moteur (Lanczos), et le rapport le signale (`retaille_normalisee`).
 *
 * Sert à trois usages : les tests du dépôt, le balayage de calibrage
 * (tests/mesure-balayage.php) et la recette sur un site réel
 * (`wp images-legeres qualite <ids>`).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Qualite {

	/**
	 * Filtre de retaille, le même que ImagesLegeres_Moteur::retailler_original : normaliser
	 * les dimensions avec un autre filtre ajouterait au WebP une perte qu'il n'a pas commise.
	 */
	const FILTRE_RETAILLE = Imagick::FILTER_LANCZOS;

	/**
	 * Applique à un objet Imagick les réglages d'encodage WebP du moteur. Seul endroit où
	 * ils sont écrits : le moteur et la mesure doivent encoder exactement pareil, sinon le
	 * balayage calibre un réglage que la production n'utilise pas.
	 */
	public static function appliquer_encodage_webp( Imagick $image, $qualite ) {
		$image->setImageFormat( 'webp' );
		$image->setImageCompressionQuality( (int) $qualite );
		$image->setOption( 'webp:method', '6' );

		return $image;
	}

	/**
	 * Encode une source vers un fichier WebP à la qualité demandée, sans toucher à la source
	 * ni à son éventuel frère .webp déjà en place. Rend le chemin écrit.
	 */
	public static function encoder_vers( $chemin_source, $chemin_destination, $qualite ) {
		$image = new Imagick( $chemin_source );
		self::appliquer_encodage_webp( $image, $qualite );
		$image->writeImage( $chemin_destination );
		$image->clear();
		$image->destroy();

		return $chemin_destination;
	}

	/**
	 * Compare deux fichiers image et rend SSIM, PSNR, dimensions et poids.
	 *
	 * @return array|WP_Error
	 */
	public static function comparer_fichiers( $chemin_source, $chemin_webp ) {
		if ( ! file_exists( $chemin_source ) ) {
			return new WP_Error( 'images_legeres_source_absente', sprintf( 'source introuvable : %s', $chemin_source ) );
		}

		if ( ! file_exists( $chemin_webp ) ) {
			return new WP_Error( 'images_legeres_webp_absent', sprintf( 'WebP introuvable : %s', $chemin_webp ) );
		}

		try {
			$source = new Imagick( $chemin_source );
			$webp   = new Imagick( $chemin_webp );
		} catch ( Exception $erreur ) {
			return new WP_Error( 'images_legeres_lecture_impossible', $erreur->getMessage() );
		}

		$largeur_source = $source->getImageWidth();
		$hauteur_source = $source->getImageHeight();
		$largeur_webp   = $webp->getImageWidth();
		$hauteur_webp   = $webp->getImageHeight();

		$retaille_normalisee = ( $largeur_source !== $largeur_webp || $hauteur_source !== $hauteur_webp );

		if ( $retaille_normalisee ) {
			$source->resizeImage( $largeur_webp, $hauteur_webp, self::FILTRE_RETAILLE, 1 );
		}

		// Deux normalisations sans lesquelles la métrique mesure autre chose que la
		// compression : un espace colorimétrique commun, et le canal alpha retiré quand
		// aucune des deux images n'est réellement transparente (ImageMagick compte sinon un
		// plan opaque de plus dans la moyenne des canaux).
		foreach ( array( $source, $webp ) as $image ) {
			if ( Imagick::COLORSPACE_SRGB !== $image->getImageColorspace() ) {
				$image->transformImageColorspace( Imagick::COLORSPACE_SRGB );
			}
		}

		if ( ! $source->getImageAlphaChannel() && ! $webp->getImageAlphaChannel() ) {
			$source->setImageAlphaChannel( Imagick::ALPHACHANNEL_OFF );
			$webp->setImageAlphaChannel( Imagick::ALPHACHANNEL_OFF );
		}

		try {
			$ssim = $source->compareImages( $webp, Imagick::METRIC_STRUCTURAL_SIMILARITY_ERROR );
			$psnr = $source->compareImages( $webp, Imagick::METRIC_PEAKSIGNALTONOISERATIO );
		} catch ( Exception $erreur ) {
			$source->clear();
			$source->destroy();
			$webp->clear();
			$webp->destroy();

			return new WP_Error( 'images_legeres_comparaison_impossible', $erreur->getMessage() );
		}

		// compareImages rend array( image de différence, distorsion ) : la distorsion est la
		// valeur de la métrique. L'image de différence est jetée (sinon elle reste en
		// mémoire native d'Imagick pour rien).
		$valeur_ssim = (float) $ssim[1];
		$valeur_psnr = (float) $psnr[1];

		// Piège d'ImageMagick : sur deux images identiques l'erreur quadratique est nulle et
		// la métrique PSNR rend 0, pas l'infini. Lu tel quel, « PSNR = 0 dB » ferait passer
		// une copie parfaite pour le pire résultat possible. Vérifié le 2026-11-15 sur une
		// paire identique (SSIM = 1, PSNR rendu = 0).
		if ( 0.0 === $valeur_psnr && $valeur_ssim >= 0.999999 ) {
			$valeur_psnr = INF;
		}

		foreach ( array( $ssim[0], $psnr[0] ) as $difference ) {
			if ( $difference instanceof Imagick ) {
				$difference->clear();
				$difference->destroy();
			}
		}

		$source->clear();
		$source->destroy();
		$webp->clear();
		$webp->destroy();

		return array(
			'source'              => $chemin_source,
			'webp'                => $chemin_webp,
			'format_source'       => strtolower( (string) pathinfo( $chemin_source, PATHINFO_EXTENSION ) ),
			'largeur_source'      => $largeur_source,
			'hauteur_source'      => $hauteur_source,
			'largeur_webp'        => $largeur_webp,
			'hauteur_webp'        => $hauteur_webp,
			'retaille_normalisee' => $retaille_normalisee,
			'ssim'                => $valeur_ssim,
			'psnr'                => is_finite( $valeur_psnr ) ? $valeur_psnr : INF,
			'poids_source'        => filesize( $chemin_source ),
			'poids_webp'          => filesize( $chemin_webp ),
			'gain_pourcent'       => filesize( $chemin_source ) > 0
				? ( ( filesize( $chemin_source ) - filesize( $chemin_webp ) ) / filesize( $chemin_source ) ) * 100
				: 0.0,
		);
	}

	/**
	 * Mesure une source à une qualité donnée, sans rien laisser sur le disque : encode dans un
	 * fichier temporaire, compare, efface. C'est la voie du balayage de calibrage.
	 *
	 * @return array|WP_Error
	 */
	public static function mesurer_a_qualite( $chemin_source, $qualite ) {
		if ( ! file_exists( $chemin_source ) ) {
			return new WP_Error( 'images_legeres_source_absente', sprintf( 'source introuvable : %s', $chemin_source ) );
		}

		$temporaire = sys_get_temp_dir() . '/images-legeres-mesure-' . wp_generate_password( 10, false, false ) . '.webp';

		try {
			self::encoder_vers( $chemin_source, $temporaire, $qualite );
		} catch ( Exception $erreur ) {
			return new WP_Error( 'images_legeres_encodage_impossible', $erreur->getMessage() );
		}

		$mesure = self::comparer_fichiers( $chemin_source, $temporaire );

		if ( file_exists( $temporaire ) ) {
			wp_delete_file( $temporaire );
		}

		if ( is_wp_error( $mesure ) ) {
			return $mesure;
		}

		$mesure['qualite'] = (int) $qualite;
		$mesure['webp']    = '(temporaire)';

		return $mesure;
	}

	/**
	 * Mesure une pièce jointe telle qu'elle est sur le disque : son original et le frère
	 * .webp réellement écrit par le moteur. Rend aussi la qualité qui lui a été appliquée et
	 * si sa source est déjà un format compressé avec perte — deux informations sans
	 * lesquelles un SSIM n'est pas interprétable.
	 *
	 * @return array|WP_Error
	 */
	public static function mesurer_attachment( $attachment_id ) {
		$chemin = get_attached_file( $attachment_id );

		if ( ! $chemin || ! file_exists( $chemin ) ) {
			return new WP_Error( 'images_legeres_attachment_absent', sprintf( 'pièce jointe %d : fichier introuvable', $attachment_id ) );
		}

		$webp = $chemin . '.webp';

		if ( ! file_exists( $webp ) ) {
			return new WP_Error( 'images_legeres_webp_absent', sprintf( 'pièce jointe %d : aucun frère .webp (pas encore convertie ?)', $attachment_id ) );
		}

		$mesure = self::comparer_fichiers( $chemin, $webp );

		if ( is_wp_error( $mesure ) ) {
			return $mesure;
		}

		$reglages = ImagesLegeres_Reglages::tout();
		$produit  = ImagesLegeres_Moteur::est_image_produit( $attachment_id );

		$mesure['attachment_id']  = (int) $attachment_id;
		$mesure['produit']        = $produit;
		$mesure['qualite']        = $produit ? (int) $reglages['qualite_webp_produit'] : (int) $reglages['qualite_webp_generale'];
		$mesure['source_avec_perte'] = in_array( $mesure['format_source'], array( 'jpg', 'jpeg' ), true );

		return $mesure;
	}

	/**
	 * Seuil applicable à une mesure (spec § 7) : 0,99 de SSIM pour une image produit,
	 * 0,98 sinon.
	 */
	public static function seuil_ssim( $produit ) {
		return $produit ? 0.99 : 0.98;
	}
}
