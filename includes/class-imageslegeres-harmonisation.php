<?php
/**
 * Harmonisation produit (spec § 5, ADR 0005, revue F5 du 2026-11-15).
 *
 * L'extension n'écrit JAMAIS dans un fichier natif ni dans `_wp_attachment_metadata['sizes']` :
 * chaque sous-taille harmonisée est un fichier à part, nommé `<base>-il-harmonise-<L>x<H>.<ext>`,
 * inventorié dans `ImagesLegeres_Harmonisees`, et servi uniquement par la réécriture à la
 * sortie (`ImagesLegeres_Service`) — donc sous la même garde que le WebP : en mode aperçu,
 * seul un administrateur connecté les voit.
 *
 * Avant cette revue, la génération passait par un `WP_Image_Editor` branché sur
 * `wp_image_editors` et écrivait sous le nom natif : une régénération suffisait à changer les
 * vignettes pour tous les visiteurs, sans passer par la réécriture d'adresse. Le mode aperçu
 * ne protégeait plus rien.
 *
 * Aucune taille déclarée n'est modifiée non plus : le filtre `woocommerce_get_image_size_single`
 * est retiré. La fiche produit reçoit son carré par substitution d'adresse, pas en faisant
 * fabriquer à WooCommerce une sous-taille native carrée.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Harmonisation {

	/** Suffixe réservé à l'extension : aucun fichier natif ne porte ce motif. */
	const SUFFIXE = '-il-harmonise-';

	/** Taille déclarée de la fiche produit, dont le carré remplace l'affichage. */
	const TAILLE_SINGLE = 'woocommerce_single';

	/**
	 * Taille propre à l'extension : le plus grand carré que l'original donne SANS
	 * agrandissement, pour le zoom et la visionneuse de la fiche produit.
	 *
	 * Sans elle, la plus grande harmonisée d'une photo est celle de la fiche produit
	 * (1024 px de côté chez Monko) alors que l'original en fait 1440 : l'agrandissement
	 * serait servi en nette perte de définition, ou resterait sur le rectangle natif —
	 * c'est-à-dire sur un autre cadrage que la photo affichée. Elle n'a pas de sous-taille
	 * native en face : son `chemin_natif` est celui de l'original, ce qui la rend
	 * substituable partout où la page sert l'original.
	 */
	const TAILLE_GRANDE = 'images_legeres_grande';

	/** Reconnaît un fichier produit par l'extension à son seul nom. */
	public static function est_fichier_harmonise( $chemin ) {
		return (bool) preg_match( '#' . preg_quote( self::SUFFIXE, '#' ) . '\d+x\d+\.[a-z]+$#i', (string) $chemin );
	}

	/**
	 * Tailles à harmoniser : toute taille déclarée déjà au ratio cible (son carré remplace
	 * le recadrage centré de WordPress ou de WooCommerce), plus la taille de la fiche produit,
	 * dont le carré a pour côté la largeur déclarée.
	 *
	 * @return array nom de taille => array( largeur, hauteur )
	 */
	public static function tailles_a_harmoniser() {
		$ratio    = ImagesLegeres_Recadrage::ratio_cible_numerique();
		$tailles  = array();
		$declarees = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();

		foreach ( (array) $declarees as $nom => $taille ) {
			if ( ImagesLegeres_Rendu::est_taille_cible( $taille, $ratio ) ) {
				$tailles[ $nom ] = array( (int) $taille['width'], (int) $taille['height'] );
			}
		}

		if ( isset( $declarees[ self::TAILLE_SINGLE ] ) && ! isset( $tailles[ self::TAILLE_SINGLE ] ) ) {
			$cote = (int) $declarees[ self::TAILLE_SINGLE ]['width'];

			if ( $cote > 0 ) {
				$tailles[ self::TAILLE_SINGLE ] = array( $cote, max( 1, (int) round( $cote / $ratio ) ) );
			}
		}

		return $tailles;
	}

	/**
	 * Dimensions du carré « grand » d'une pièce jointe : le plus grand que son original
	 * donne sans agrandir un seul pixel. Rend null quand il n'apporterait rien de plus que
	 * la plus grande taille déclarée déjà harmonisée.
	 *
	 * Rectangle posé : le carré est le rectangle lui-même, en pixels de l'original.
	 * Fond assumé : l'original tient entier dans le cadre à son échelle native, le côté est
	 * donc son plus grand côté (rapporté au ratio cible).
	 *
	 * @param string     $original          chemin absolu du fichier de la pièce jointe
	 * @param array|null $rectangle         rectangle relatif posé, ou null (fond ajouté)
	 * @param array      $tailles_declarees nom => array( largeur, hauteur ) déjà retenues
	 *
	 * @return array|null array( largeur, hauteur ) ou null
	 */
	public static function taille_grande( $original, $rectangle, array $tailles_declarees ) {
		$mesure = @getimagesize( $original ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $mesure ) || empty( $mesure[0] ) || empty( $mesure[1] ) ) {
			return null;
		}

		$ratio = ImagesLegeres_Recadrage::ratio_cible_numerique();

		if ( $ratio <= 0 ) {
			return null;
		}

		if ( is_array( $rectangle ) && ! empty( $rectangle['w'] ) ) {
			$largeur = (int) floor( (float) $rectangle['w'] * (int) $mesure[0] );
		} else {
			$largeur = (int) max( (int) $mesure[0], round( (int) $mesure[1] * $ratio ) );
		}

		$hauteur = (int) max( 1, round( $largeur / $ratio ) );

		if ( $largeur < 1 ) {
			return null;
		}

		$plus_grande_declaree = 0;

		foreach ( $tailles_declarees as $dimensions ) {
			$plus_grande_declaree = max( $plus_grande_declaree, (int) $dimensions[0] );
		}

		return $largeur > $plus_grande_declaree ? array( $largeur, $hauteur ) : null;
	}

	/** Chemin absolu du fichier harmonisé d'une pièce jointe pour une taille donnée. */
	public static function chemin_harmonise( $chemin_original, $largeur, $hauteur ) {
		$dossier   = trailingslashit( dirname( $chemin_original ) );
		$extension = strtolower( pathinfo( $chemin_original, PATHINFO_EXTENSION ) );
		$base      = wp_basename( $chemin_original, '.' . pathinfo( $chemin_original, PATHINFO_EXTENSION ) );

		return $dossier . $base . self::SUFFIXE . (int) $largeur . 'x' . (int) $hauteur . '.' . $extension;
	}

	/**
	 * Pièces jointes à harmoniser par défaut : les images produit cadrées à la main
	 * (rectangle posé ou fond assumé) et qui ne sont pas déjà au ratio cible. Les autres ne
	 * gagnent rien à être harmonisées — leur sous-taille native est déjà au bon cadre.
	 */
	public static function ids_a_harmoniser() {
		$ids = array();

		foreach ( ImagesLegeres_Moteur::ids_images_produit() as $id ) {
			if ( null === ImagesLegeres_Recadrage::obtenir( $id ) && ! ImagesLegeres_Recadrage::fond_assume( $id ) ) {
				continue;
			}

			$ids[] = (int) $id;
		}

		return $ids;
	}

	/**
	 * Génère (ou régénère) les sous-tailles harmonisées d'une pièce jointe et leurs frères
	 * .webp. Rend un résultat par taille.
	 *
	 * @return array[] array( taille, fichier, etat, motif )
	 */
	public static function generer( $attachment_id, $dry_run = false ) {
		$attachment_id = (int) $attachment_id;

		if ( ! ImagesLegeres_Reglages::valeur( 'harmonisation_produit_active' ) ) {
			return array( array( 'taille' => '', 'fichier' => '', 'etat' => 'ignore', 'motif' => 'harmonisation désactivée' ) );
		}

		if ( ! ImagesLegeres_Moteur::est_image_produit( $attachment_id ) ) {
			return array( array( 'taille' => '', 'fichier' => '', 'etat' => 'ignore', 'motif' => 'image hors produit' ) );
		}

		$original = get_attached_file( $attachment_id );

		if ( ! $original || ! file_exists( $original ) || ! ImagesLegeres_Moteur::sous_uploads( $original ) ) {
			return array( array( 'taille' => '', 'fichier' => '', 'etat' => 'erreur', 'motif' => 'fichier de la pièce jointe introuvable' ) );
		}

		if ( ImagesLegeres_Moteur::motif_exclusion( $original ) ) {
			return array( array( 'taille' => '', 'fichier' => '', 'etat' => 'ignore', 'motif' => ImagesLegeres_Moteur::motif_exclusion( $original ) ) );
		}

		$metadata  = wp_get_attachment_metadata( $attachment_id );
		$rectangle = ImagesLegeres_Recadrage::obtenir( $attachment_id );
		$resultats = array();

		$tailles = self::tailles_a_harmoniser();
		$grande  = self::taille_grande( $original, $rectangle, $tailles );

		if ( null !== $grande ) {
			$tailles[ self::TAILLE_GRANDE ] = $grande;
		}

		foreach ( $tailles as $nom => $dimensions ) {
			list( $largeur, $hauteur ) = $dimensions;

			$destination = self::chemin_harmonise( $original, $largeur, $hauteur );
			// Le carré « grand » ne remplace aucune sous-taille : c'est l'ORIGINAL qu'il
			// remplace à l'agrandissement, donc c'est lui son chemin natif.
			$natif = ! empty( $metadata['sizes'][ $nom ]['file'] )
				? trailingslashit( dirname( $original ) ) . $metadata['sizes'][ $nom ]['file']
				: ( self::TAILLE_GRANDE === $nom ? $original : '' );

			if ( $dry_run ) {
				$resultats[] = array( 'taille' => $nom, 'fichier' => wp_basename( $destination ), 'etat' => 'a-faire', 'motif' => 'dry-run : aucune écriture' );
				continue;
			}

			$ecriture = self::ecrire( $original, $destination, $rectangle, $largeur, $hauteur );

			if ( '' !== $ecriture ) {
				$resultats[] = array( 'taille' => $nom, 'fichier' => wp_basename( $destination ), 'etat' => 'erreur', 'motif' => $ecriture );
				continue;
			}

			ImagesLegeres_Harmonisees::enregistrer(
				array(
					'attachment_id'    => $attachment_id,
					'taille'           => (string) $nom,
					'chemin_natif'     => '' !== $natif ? ImagesLegeres_Moteur::chemin_relatif( $natif ) : '',
					'chemin_harmonise' => ImagesLegeres_Moteur::chemin_relatif( $destination ),
					'largeur'          => (int) $largeur,
					'hauteur'          => (int) $hauteur,
					'poids'            => (int) filesize( $destination ),
				)
			);

			$motif = $rectangle ? 'recadrage' : 'fond ajouté';

			// Le frère .webp de l'harmonisée passe par le moteur : même seuil, même trace
			// dans ImagesLegeres_Table, donc vu par « Tout rendre » et par `orphelins`.
			if ( ImagesLegeres_Reglages::valeur( 'conversion_webp_active' ) ) {
				$webp = ImagesLegeres_Moteur::convertir_vers_webp( $destination, (int) ImagesLegeres_Reglages::valeur( 'qualite_webp_produit' ) );
				ImagesLegeres_Table::enregistrer(
					array(
						'chemin'        => ImagesLegeres_Moteur::chemin_relatif( $destination ),
						'attachment_id' => $attachment_id,
						'etat'          => $webp['etat'],
						'motif'         => isset( $webp['motif'] ) ? (string) $webp['motif'] : '',
						'taille_origine' => isset( $webp['taille_origine'] ) ? (int) $webp['taille_origine'] : 0,
						'taille_webp'   => isset( $webp['taille_webp'] ) ? (int) $webp['taille_webp'] : 0,
						'gain_octets'   => isset( $webp['gain_octets'] ) ? (int) $webp['gain_octets'] : 0,
					)
				);
				$motif .= ', webp ' . $webp['etat'];
			}

			$resultats[] = array( 'taille' => $nom, 'fichier' => wp_basename( $destination ), 'etat' => 'fait', 'motif' => $motif );
		}

		if ( empty( $resultats ) ) {
			$resultats[] = array( 'taille' => '', 'fichier' => '', 'etat' => 'ignore', 'motif' => 'aucune taille déclarée au ratio cible' );
		}

		return $resultats;
	}

	/**
	 * Écrit un fichier harmonisé depuis l'original, par recadrage si un rectangle est posé,
	 * par fond ajouté sinon (ADR 0005 point 4). Écriture atomique. Rend '' si tout va bien,
	 * le motif d'erreur sinon.
	 */
	private static function ecrire( $original, $destination, $rectangle, $largeur, $hauteur ) {
		$tmp = '';

		try {
			$image = new Imagick( $original );

			if ( $rectangle ) {
				ImagesLegeres_Rendu::appliquer_recadrage( $image, $rectangle, $largeur, $hauteur );
			} else {
				ImagesLegeres_Rendu::appliquer_fond_ajoute( $image, $largeur, $hauteur );
			}

			// Qualité d'encodage alignée sur celle des sous-tailles natives de WordPress :
			// sans ce réglage, Imagick reprend celle de la source (92 et plus sur une photo
			// de téléphone) et l'harmonisée pèse trois fois le fichier qu'elle remplace —
			// c'est le `-1024x1024.jpg` de 258 Ko relevé en production le 2026-11-15.
			$image->setImageFormat( strtolower( pathinfo( $original, PATHINFO_EXTENSION ) ) === 'png' ? 'png' : 'jpeg' );

			if ( 'PNG' !== strtoupper( $image->getImageFormat() ) ) {
				$image->setImageCompressionQuality( (int) apply_filters( 'jpeg_quality', 82, 'image_resize' ) );
			}

			$tmp = $destination . '.tmp-' . wp_generate_password( 8, false, false );
			$image->writeImage( $tmp );
			$image->clear();
			$image->destroy();
		} catch ( Exception $erreur ) {
			if ( '' !== $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			return $erreur->getMessage();
		}

		if ( ! rename( $tmp, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_delete_file( $tmp );

			return 'renommage du fichier temporaire impossible';
		}

		return '';
	}

	/**
	 * Supprime les fichiers harmonisés d'une pièce jointe (et leurs frères .webp), leurs
	 * lignes d'inventaire. Appelé par `ImagesLegeres_Cycle_Vie` sur `delete_attachment` :
	 * sans cela, des fichiers dérivables d'une adresse publique survivraient à la suppression
	 * de la photo (SEC-1). Rend les chemins absolus supprimés.
	 */
	public static function supprimer( $attachment_id ) {
		$uploads   = wp_get_upload_dir();
		$basedir   = $uploads['basedir'];
		$base      = trailingslashit( $basedir );
		$supprimes = array();

		foreach ( ImagesLegeres_Harmonisees::par_attachment( $attachment_id ) as $ligne ) {
			foreach ( array( $base . $ligne['chemin_harmonise'], $base . $ligne['chemin_harmonise'] . '.webp' ) as $chemin ) {
				if ( file_exists( $chemin ) && wp_delete_file_from_directory( $chemin, $basedir ) ) {
					$supprimes[] = $chemin;
				}
			}
		}

		ImagesLegeres_Harmonisees::supprimer_lignes_attachment( $attachment_id );

		return $supprimes;
	}

	/** Tous les fichiers harmonisés encore présents sur le disque (et leurs frères .webp). */
	public static function fichiers_existants() {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$trouves = array();

		foreach ( ImagesLegeres_Harmonisees::tout() as $ligne ) {
			foreach ( array( $base . $ligne['chemin_harmonise'], $base . $ligne['chemin_harmonise'] . '.webp' ) as $chemin ) {
				if ( ImagesLegeres_Moteur::sous_uploads( $chemin ) && file_exists( $chemin ) ) {
					$trouves[] = $chemin;
				}
			}
		}

		sort( $trouves );

		return $trouves;
	}

	/** « Tout rendre » : plus aucun fichier harmonisé, plus aucune ligne d'inventaire. */
	public static function tout_supprimer() {
		$uploads   = wp_get_upload_dir();
		$basedir   = $uploads['basedir'];
		$supprimes = array();

		foreach ( self::fichiers_existants() as $chemin ) {
			if ( wp_delete_file_from_directory( $chemin, $basedir ) && ! file_exists( $chemin ) ) {
				$supprimes[] = $chemin;
			}
		}

		return $supprimes;
	}
}
