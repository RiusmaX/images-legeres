<?php
/**
 * Moteur de conversion WebP et de retaille (spec § 3.1, § 3.2 ; ADR 0002, 0004).
 *
 * Pour une pièce jointe : convertit l'original et chacune de ses sous-tailles en fichier
 * frère .webp, et retaille l'original au-delà de la dimension maximale après copie de
 * sécurité. Tient l'état dans ImagesLegeres_Table. N'implémente pas le recadrage produit
 * (mission 15) ni l'image de flux (mission 18) ni la file de lot (mission 19) : ici, un
 * seul appel traite une seule pièce jointe, de façon synchrone et idempotente.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Moteur {

	const FORMATS_TOUJOURS_EXCLUS = array( 'svg' );

	/**
	 * Traite une pièce jointe : retaille éventuelle de l'original, puis conversion de
	 * l'original et de chacune de ses sous-tailles. Rend un tableau de résultats, un par
	 * fichier physique concerné.
	 */
	public static function traiter_attachment( $attachment_id, $dry_run = false ) {
		$chemin_original = get_attached_file( $attachment_id );

		if ( ! $chemin_original || ! file_exists( $chemin_original ) ) {
			return array(
				array(
					'chemin' => '',
					'etat'   => 'erreur',
					'motif'  => 'fichier de la pièce jointe introuvable',
				),
			);
		}

		$exclusion = self::motif_exclusion( $chemin_original );

		if ( $exclusion ) {
			$resultat = array(
				'chemin' => self::chemin_relatif( $chemin_original ),
				'etat'   => 'ignore',
				'motif'  => $exclusion,
			);

			if ( ! $dry_run ) {
				self::enregistrer_etat( $attachment_id, $resultat );
			}

			return array( $resultat );
		}

		$reglages = ImagesLegeres_Reglages::tout();

		if ( $reglages['retaille_originaux_active'] && ! $dry_run ) {
			self::retailler_original( $attachment_id );
		}

		$qualite   = self::est_image_produit( $attachment_id ) ? (int) $reglages['qualite_webp_produit'] : (int) $reglages['qualite_webp_generale'];
		$resultats = array();

		foreach ( self::fichiers_de_attachment( $attachment_id ) as $chemin ) {
			if ( $dry_run ) {
				$resultats[] = array(
					'chemin' => self::chemin_relatif( $chemin ),
					'etat'   => 'a-faire',
					'motif'  => 'dry-run : aucune écriture',
				);
				continue;
			}

			$resultat            = self::convertir_vers_webp( $chemin, $qualite );
			$resultat['chemin']  = self::chemin_relatif( $chemin );
			self::enregistrer_etat( $attachment_id, $resultat );
			$resultats[] = $resultat;
		}

		return $resultats;
	}

	/**
	 * Convertit un fichier image en frère .webp. Écriture atomique (temporaire puis
	 * renommage). Idempotent : si le frère existe déjà et n'est pas plus ancien que
	 * l'original, ne refait rien.
	 */
	public static function convertir_vers_webp( $chemin, $qualite ) {
		if ( ! file_exists( $chemin ) ) {
			return array(
				'etat'  => 'erreur',
				'motif' => 'fichier introuvable',
			);
		}

		$exclusion = self::motif_exclusion( $chemin );
		if ( $exclusion ) {
			return array(
				'etat'  => 'ignore',
				'motif' => $exclusion,
			);
		}

		$destination = $chemin . '.webp';

		if ( file_exists( $destination ) && filemtime( $destination ) >= filemtime( $chemin ) ) {
			return array(
				'etat'           => 'fait',
				'motif'          => 'déjà converti, rien à refaire',
				'taille_origine' => filesize( $chemin ),
				'taille_webp'    => filesize( $destination ),
				'gain_octets'    => filesize( $chemin ) - filesize( $destination ),
			);
		}

		$taille_origine = filesize( $chemin );

		try {
			$image = new Imagick( $chemin );

			$largeur = $image->getImageWidth();
			$hauteur = $image->getImageHeight();

			$image->setImageFormat( 'webp' );
			$image->setImageCompressionQuality( (int) $qualite );
			$image->setOption( 'webp:method', '6' );

			$tmp = $chemin . '.webp.tmp-' . wp_generate_password( 8, false, false );
			$image->writeImage( $tmp );
			$image->clear();
			$image->destroy();
		} catch ( Exception $erreur ) {
			return array(
				'etat'           => 'erreur',
				'motif'          => $erreur->getMessage(),
				'taille_origine' => $taille_origine,
			);
		}

		$taille_webp   = filesize( $tmp );
		$gain_pourcent = $taille_origine > 0 ? ( ( $taille_origine - $taille_webp ) / $taille_origine ) * 100 : 0;
		$gain_minimal  = (float) ImagesLegeres_Reglages::valeur( 'gain_minimal_pourcent' );

		if ( $gain_pourcent < $gain_minimal ) {
			wp_delete_file( $tmp );

			if ( file_exists( $destination ) ) {
				wp_delete_file( $destination );
			}

			return array(
				'etat'            => 'ignore',
				'motif'           => sprintf( 'gain %.1f %% sous le seuil de %d %%', $gain_pourcent, $gain_minimal ),
				'taille_origine'  => $taille_origine,
				'largeur_origine' => $largeur,
				'hauteur_origine' => $hauteur,
			);
		}

		if ( ! rename( $tmp, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_delete_file( $tmp );

			return array(
				'etat'           => 'erreur',
				'motif'          => 'renommage du fichier temporaire impossible',
				'taille_origine' => $taille_origine,
			);
		}

		return array(
			'etat'            => 'fait',
			'motif'           => '',
			'taille_origine'  => $taille_origine,
			'taille_webp'     => $taille_webp,
			'gain_octets'     => $taille_origine - $taille_webp,
			'largeur_origine' => $largeur,
			'hauteur_origine' => $hauteur,
		);
	}

	/**
	 * Retaille l'original d'une pièce jointe au-delà de la dimension maximale réglée, après
	 * copie de sécurité dans uploads/images-legeres/originaux/, puis régénère ses
	 * sous-tailles. Idempotent : un original déjà sous la dimension maximale (y compris
	 * après une précédente retaille) n'est pas retouché.
	 */
	public static function retailler_original( $attachment_id ) {
		$chemin = get_attached_file( $attachment_id );

		if ( ! $chemin || ! file_exists( $chemin ) ) {
			return array(
				'etat'  => 'erreur',
				'motif' => 'fichier introuvable',
			);
		}

		if ( self::motif_exclusion( $chemin ) ) {
			return array(
				'etat'  => 'ignore',
				'motif' => 'format exclu de la retaille',
			);
		}

		$dimension_max = (int) ImagesLegeres_Reglages::valeur( 'dimension_max_nouveaux_envois' );

		try {
			$image = new Imagick( $chemin );
		} catch ( Exception $erreur ) {
			return array(
				'etat'  => 'erreur',
				'motif' => $erreur->getMessage(),
			);
		}

		$largeur  = $image->getImageWidth();
		$hauteur  = $image->getImageHeight();
		$plus_grand_cote = max( $largeur, $hauteur );

		if ( $plus_grand_cote <= $dimension_max ) {
			$image->clear();
			$image->destroy();

			return array(
				'etat'  => 'ignore',
				'motif' => 'sous la dimension maximale',
			);
		}

		$chemin_sauvegarde = self::chemin_sauvegarde_original( $chemin );
		wp_mkdir_p( dirname( $chemin_sauvegarde ) );

		if ( ! file_exists( $chemin_sauvegarde ) && ! copy( $chemin, $chemin_sauvegarde ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$image->clear();
			$image->destroy();

			return array(
				'etat'  => 'erreur',
				'motif' => 'copie de sécurité de l\'original impossible',
			);
		}

		$metadonnees_avant = wp_get_attachment_metadata( $attachment_id );
		update_post_meta( $attachment_id, '_images_legeres_meta_origine', $metadonnees_avant );

		$ratio            = $dimension_max / $plus_grand_cote;
		$nouvelle_largeur = max( 1, (int) round( $largeur * $ratio ) );
		$nouvelle_hauteur = max( 1, (int) round( $hauteur * $ratio ) );

		$image->resizeImage( $nouvelle_largeur, $nouvelle_hauteur, Imagick::FILTER_LANCZOS, 1 );

		$tmp = $chemin . '.tmp-' . wp_generate_password( 8, false, false );

		try {
			$image->writeImage( $tmp );
		} catch ( Exception $erreur ) {
			$image->clear();
			$image->destroy();

			return array(
				'etat'  => 'erreur',
				'motif' => $erreur->getMessage(),
			);
		}

		$image->clear();
		$image->destroy();

		if ( ! rename( $tmp, $chemin ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_delete_file( $tmp );

			return array(
				'etat'  => 'erreur',
				'motif' => 'renommage de l\'original retaillé impossible',
			);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$nouvelle_metadata = wp_create_image_subsizes( $chemin, $attachment_id );
		wp_update_attachment_metadata( $attachment_id, $nouvelle_metadata );

		$chemin_relatif_table = self::chemin_relatif( $chemin );

		ImagesLegeres_Table::enregistrer(
			array(
				'chemin'                     => $chemin_relatif_table,
				'attachment_id'               => (int) $attachment_id,
				'retaille'                    => 1,
				'chemin_original_sauvegarde'  => self::chemin_relatif( $chemin_sauvegarde ),
				'largeur_origine'             => $largeur,
				'hauteur_origine'             => $hauteur,
			)
		);

		return array(
			'etat'     => 'fait',
			'motif'    => '',
			'largeur'  => $nouvelle_largeur,
			'hauteur'  => $nouvelle_hauteur,
			'sauvegarde' => $chemin_sauvegarde,
		);
	}

	public static function chemin_sauvegarde_original( $chemin_absolu ) {
		$uploads = wp_get_upload_dir();
		$relatif = self::chemin_relatif( $chemin_absolu );

		return trailingslashit( $uploads['basedir'] ) . 'images-legeres/originaux/' . $relatif;
	}

	/**
	 * Rend le motif d'exclusion d'un fichier (svg, gif animé), ou une chaîne vide s'il est
	 * éligible.
	 */
	public static function motif_exclusion( $chemin ) {
		$extension = strtolower( pathinfo( $chemin, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, self::FORMATS_TOUJOURS_EXCLUS, true ) ) {
			return 'svg exclu';
		}

		if ( 'gif' === $extension && self::est_gif_anime( $chemin ) ) {
			return 'gif animé exclu';
		}

		if ( 'webp' === $extension ) {
			return 'déjà au format webp';
		}

		return '';
	}

	public static function est_gif_anime( $chemin ) {
		try {
			$image = new Imagick();
			$image->pingImage( $chemin );
			$nombre_images = $image->getNumberImages();
			$image->clear();
			$image->destroy();

			return $nombre_images > 1;
		} catch ( Exception $erreur ) {
			return false;
		}
	}

	private static function est_image_produit( $attachment_id ) {
		$parent_id = wp_get_post_parent_id( $attachment_id );

		if ( ! $parent_id ) {
			return false;
		}

		return 'product' === get_post_type( $parent_id );
	}

	private static function fichiers_de_attachment( $attachment_id ) {
		$chemin = get_attached_file( $attachment_id );

		if ( ! $chemin ) {
			return array();
		}

		$fichiers = array( $chemin );
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! empty( $metadata['sizes'] ) ) {
			$dossier = dirname( $chemin );

			foreach ( $metadata['sizes'] as $taille ) {
				if ( ! empty( $taille['file'] ) ) {
					$chemin_taille = trailingslashit( $dossier ) . $taille['file'];

					if ( ! in_array( $chemin_taille, $fichiers, true ) ) {
						$fichiers[] = $chemin_taille;
					}
				}
			}
		}

		return $fichiers;
	}

	public static function chemin_relatif( $chemin_absolu ) {
		$uploads = wp_get_upload_dir();

		return str_replace( trailingslashit( $uploads['basedir'] ), '', $chemin_absolu );
	}

	private static function enregistrer_etat( $attachment_id, array $resultat ) {
		$donnees = array(
			'chemin'        => $resultat['chemin'],
			'attachment_id' => (int) $attachment_id,
			'etat'          => $resultat['etat'],
			'motif'         => isset( $resultat['motif'] ) ? (string) $resultat['motif'] : '',
		);

		foreach ( array( 'taille_origine', 'taille_webp', 'gain_octets', 'largeur_origine', 'hauteur_origine' ) as $colonne ) {
			if ( isset( $resultat[ $colonne ] ) ) {
				$donnees[ $colonne ] = (int) $resultat[ $colonne ];
			}
		}

		ImagesLegeres_Table::enregistrer( $donnees );
	}
}
