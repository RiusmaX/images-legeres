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
	 * Elle sert aussi quand une taille déclarée n'a pas de fichier natif (original trop petit) :
	 * WordPress sert alors l'original, qui doit lui aussi avoir son carré.
	 *
	 * Sans elle, la plus grande harmonisée d'une photo est celle de la fiche produit
	 * (1024 px de côté chez Monko) alors que l'original en fait 1440 : l'agrandissement
	 * serait servi en nette perte de définition, ou resterait sur le rectangle natif —
	 * c'est-à-dire sur un autre cadrage que la photo affichée. Elle n'a pas de sous-taille
	 * native en face : son `chemin_natif` est celui de l'original, ce qui la rend
	 * substituable partout où la page sert l'original.
	 */
	const TAILLE_GRANDE = 'images_legeres_grande';

	/**
	 * Côté maximal de la grande image servie au zoom et à la visionneuse.
	 *
	 * F8 prenait LA plus grande harmonisée, donc le `2048x2048` de WordPress : 841 Ko de
	 * JPEG (837 Ko de WebP) pour une visionneuse qui, sur un téléphone, n'affiche jamais
	 * plus de 1 536 px de côté. Le `1536x1536` de la même photo pèse 577 Ko — et 417 Ko une
	 * fois les profils retirés. Au-delà de 1 536 px l'octet supplémentaire ne se voit nulle
	 * part ; en deçà, l'agrandissement perdrait en définition.
	 */
	const COTE_GRANDE_MAX = 1536;

	/**
	 * Qualité d'encodage des fichiers harmonisés — JPEG **et** frère .webp.
	 *
	 * Les deux doivent sortir de la MÊME valeur, et c'est tout le défaut que cette fiche
	 * corrige (F9) : le JPEG était écrit à 82 (`jpeg_quality`) et son frère .webp à
	 * `qualite_webp_produit`, dont la valeur par défaut est 90. Un WebP de qualité 90 tiré
	 * d'un JPEG de qualité 82 est 4 à 5 % PLUS LOURD que lui (mesuré sur les harmonisées de
	 * production, 2026-11-16) : `ImagesLegeres_Moteur::convertir_vers_webp` le refusait
	 * alors comme « webp plus lourd que l'original » et le supprimait. 40 harmonisées de
	 * monkobougie.fr sont restées sans frère .webp pour cette seule raison. À 82 des deux
	 * côtés, le même WebP est 21 à 28 % plus léger que son JPEG.
	 */
	public static function qualite() {
		return (int) apply_filters( 'jpeg_quality', 82, 'image_resize' );
	}

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
	 * Sans rectangle : le carré centré, le plus grand que l'original contient (son petit
	 * côté, rapporté au ratio cible).
	 *
	 * @param string     $original          chemin absolu du fichier de la pièce jointe
	 * @param array|null $rectangle         rectangle relatif posé, ou null (carré centré)
	 * @param array      $tailles_declarees nom => array( largeur, hauteur ) déjà retenues
	 *
	 * @return array|null array( largeur, hauteur ) ou null
	 */
	public static function taille_grande( $original, $rectangle, array $tailles_declarees ) {
		$naturel = self::taille_naturelle( $original, $rectangle );

		if ( null === $naturel ) {
			return null;
		}

		list( $largeur, $hauteur ) = $naturel;

		$plus_grande_declaree = 0;

		foreach ( $tailles_declarees as $dimensions ) {
			$plus_grande_declaree = max( $plus_grande_declaree, (int) $dimensions[0] );
		}

		return $largeur > $plus_grande_declaree ? array( $largeur, $hauteur ) : null;
	}

	/**
	 * Carré que l'original donne à son échelle native (voir `taille_grande`).
	 *
	 * @return array|null array( largeur, hauteur ) ou null
	 */
	private static function taille_naturelle( $original, $rectangle ) {
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
			$largeur = (int) floor( min( (int) $mesure[0], (int) $mesure[1] * $ratio ) );
		}

		return $largeur < 1 ? null : array( $largeur, (int) max( 1, round( $largeur / $ratio ) ) );
	}

	/**
	 * Carré qui remplace l'original quand une taille déclarée n'a pas de fichier natif : parmi
	 * les dimensions déjà produites, la plus petite qui couvre l'original sans dépasser
	 * `COTE_GRANDE_MAX`, sinon la plus grande sous ce plafond, sinon la plus petite.
	 *
	 * @return array|null array( largeur, hauteur ), null si chaque taille a son fichier natif
	 */
	private static function taille_pour_original( $original, $rectangle, $metadata, array $tailles ) {
		$naturel = self::taille_naturelle( $original, $rectangle );

		if ( null === $naturel || empty( array_diff_key( $tailles, isset( $metadata['sizes'] ) ? (array) $metadata['sizes'] : array() ) ) ) {
			return null;
		}

		$candidates = array_values( $tailles );
		usort(
			$candidates,
			function ( $a, $b ) {
				return $a[0] - $b[0];
			}
		);

		$retenue = $candidates[0];

		foreach ( $candidates as $dimensions ) {
			if ( $dimensions[0] > self::COTE_GRANDE_MAX ) {
				break;
			}

			$retenue = $dimensions;

			if ( $dimensions[0] >= $naturel[0] ) {
				break;
			}
		}

		return $retenue;
	}

	/** Tailles à produire pour un original donné, carré « grand » compris. */
	private static function tailles_pour( $original, $rectangle, $metadata ) {
		$tailles = self::tailles_a_harmoniser();
		$grande  = self::taille_grande( $original, $rectangle, $tailles );

		if ( null === $grande ) {
			$grande = self::taille_pour_original( $original, $rectangle, $metadata, $tailles );
		}

		if ( null !== $grande ) {
			$tailles[ self::TAILLE_GRANDE ] = $grande;
		}

		return $tailles;
	}

	/**
	 * Fichier natif qu'une harmonisée remplace, '' s'il n'y en a pas. Le carré « grand » ne
	 * remplace aucune sous-taille : c'est l'ORIGINAL qu'il remplace à l'agrandissement, donc
	 * c'est lui son chemin natif.
	 */
	private static function chemin_natif( $original, $metadata, $nom ) {
		if ( ! empty( $metadata['sizes'][ $nom ]['file'] ) ) {
			return trailingslashit( dirname( $original ) ) . $metadata['sizes'][ $nom ]['file'];
		}

		return self::TAILLE_GRANDE === $nom ? $original : '';
	}

	/**
	 * Vrai quand chaque harmonisée attendue est inventoriée, présente, pas plus ancienne que
	 * l'original, rattachée au bon fichier natif, et pourvue de son .webp (ou d'un refus
	 * tracé). La voie automatique s'en sert pour ne rien réécrire sans raison. Un rectangle
	 * de recadrage modifié n'est pas détecté : `wp images-legeres harmoniser` reste la voie.
	 */
	public static function a_jour( $attachment_id ) {
		$original = get_attached_file( $attachment_id );

		if ( ! $original || ! file_exists( $original ) ) {
			return false;
		}

		$inventaire = array();

		foreach ( ImagesLegeres_Harmonisees::par_attachment( $attachment_id ) as $ligne ) {
			$inventaire[ $ligne['chemin_harmonise'] . '|' . $ligne['taille'] ] = $ligne;
		}

		$metadata  = wp_get_attachment_metadata( $attachment_id );
		$attendues = array();

		foreach ( self::tailles_pour( $original, ImagesLegeres_Recadrage::obtenir( $attachment_id ), $metadata ) as $nom => $dimensions ) {
			$destination = self::chemin_harmonise( $original, $dimensions[0], $dimensions[1] );
			$natif       = self::chemin_natif( $original, $metadata, $nom );
			$attendues[] = array( $destination, (string) $nom, '' !== $natif ? ImagesLegeres_Moteur::chemin_relatif( $natif ) : '' );
		}

		foreach ( $attendues as list( $destination, $nom, $natif ) ) {
			$relatif = ImagesLegeres_Moteur::chemin_relatif( $destination );
			$cle     = $relatif . '|' . $nom;

			if ( empty( $inventaire[ $cle ] )
				|| $inventaire[ $cle ]['chemin_natif'] !== $natif
				|| ! file_exists( $destination )
				|| filemtime( $destination ) < filemtime( $original ) ) {
				return false;
			}

			if ( ImagesLegeres_Reglages::valeur( 'conversion_webp_active' ) && ! file_exists( $destination . '.webp' ) ) {
				$trace = ImagesLegeres_Table::par_chemin( $relatif );

				if ( ! $trace || 'ignore' !== ( (array) $trace )['etat'] ) {
					return false;
				}
			}
		}

		return ! empty( $attendues );
	}

	/** Chemin absolu du fichier harmonisé d'une pièce jointe pour une taille donnée. */
	public static function chemin_harmonise( $chemin_original, $largeur, $hauteur ) {
		$dossier   = trailingslashit( dirname( $chemin_original ) );
		$extension = strtolower( pathinfo( $chemin_original, PATHINFO_EXTENSION ) );
		$base      = wp_basename( $chemin_original, '.' . pathinfo( $chemin_original, PATHINFO_EXTENSION ) );

		return $dossier . $base . self::SUFFIXE . (int) $largeur . 'x' . (int) $hauteur . '.' . $extension;
	}

	/**
	 * Pièces jointes à harmoniser par défaut : les photos produit cadrées à la main (rectangle
	 * posé ou marque « fond assumé »), et celles déjà harmonisées, pour que leurs carrés
	 * suivent la règle de rendu courante (avant la 0.2.4, sans rectangle, un fond était
	 * ajouté).
	 */
	public static function ids_a_harmoniser() {
		$ids = array();

		foreach ( ImagesLegeres_Moteur::ids_photos_produit() as $id ) {
			if ( null === ImagesLegeres_Recadrage::obtenir( $id )
				&& ! ImagesLegeres_Recadrage::fond_assume( $id )
				&& empty( ImagesLegeres_Harmonisees::par_attachment( $id ) ) ) {
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

		if ( ! ImagesLegeres_Moteur::est_photo_produit( $attachment_id ) ) {
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

		$tailles = self::tailles_pour( $original, $rectangle, $metadata );
		$ecrits  = array();
		$maj     = current_time( 'mysql', true ); // Une seule date par génération.

		foreach ( $tailles as $nom => $dimensions ) {
			list( $largeur, $hauteur ) = $dimensions;

			$destination = self::chemin_harmonise( $original, $largeur, $hauteur );
			$natif = self::chemin_natif( $original, $metadata, $nom );

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
					'maj'              => $maj,
				)
			);

			$ecrits[ (string) $nom ] = ImagesLegeres_Moteur::chemin_relatif( $destination );

			$motif = $rectangle ? 'recadrage' : 'carré centré';

			// Le frère .webp de l'harmonisée passe par le moteur : même seuil, même trace
			// dans ImagesLegeres_Table, donc vu par « Tout rendre » et par `orphelins`.
			if ( ImagesLegeres_Reglages::valeur( 'conversion_webp_active' ) ) {
				$webp = ImagesLegeres_Moteur::convertir_vers_webp( $destination, self::qualite() );
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

		// Seulement quand tout est écrit : après un échec, l'ancienne grande reste servie.
		if ( ! $dry_run && count( $ecrits ) === count( $tailles ) ) {
			self::retirer_grande_perimee( $attachment_id, $ecrits );
		}

		if ( empty( $resultats ) ) {
			$resultats[] = array( 'taille' => '', 'fichier' => '', 'etat' => 'ignore', 'motif' => 'aucune taille déclarée au ratio cible' );
		}

		return $resultats;
	}

	/**
	 * Les dimensions du carré « grand » dépendent du cadrage : quand il change, l'ancien rang
	 * resterait inventorié et, s'il est plus large, servi à l'agrandissement. Son fichier
	 * n'est supprimé que si plus aucun rang ne le désigne.
	 *
	 * @param int   $attachment_id pièce jointe régénérée
	 * @param array $ecrits        nom de taille => chemin relatif, tout juste écrits
	 */
	private static function retirer_grande_perimee( $attachment_id, array $ecrits ) {
		$uploads = wp_get_upload_dir();
		$basedir = $uploads['basedir'];

		foreach ( ImagesLegeres_Harmonisees::par_attachment( $attachment_id ) as $ligne ) {
			$chemin = $ligne['chemin_harmonise'];

			if ( self::TAILLE_GRANDE !== $ligne['taille']
				|| ( isset( $ecrits[ self::TAILLE_GRANDE ] ) && $ecrits[ self::TAILLE_GRANDE ] === $chemin ) ) {
				continue;
			}

			ImagesLegeres_Harmonisees::supprimer_ligne( $ligne['id'] );

			if ( in_array( $chemin, $ecrits, true ) || ImagesLegeres_Harmonisees::est_reference( $chemin ) ) {
				continue;
			}

			$absolu = trailingslashit( $basedir ) . $chemin;

			foreach ( array( $absolu, $absolu . '.webp' ) as $fichier ) {
				if ( ImagesLegeres_Moteur::sous_uploads( $fichier ) && file_exists( $fichier ) ) {
					wp_delete_file_from_directory( $fichier, $basedir );
				}
			}

			ImagesLegeres_Table::supprimer_par_chemin( $chemin );
		}
	}

	/**
	 * Pièces jointes dont AU MOINS une harmonisée inventoriée n'a pas de frère .webp sur le
	 * disque — le rattrapage de `--webp-manquants`.
	 *
	 * Pourquoi par pièce jointe et pas fichier par fichier : le frère .webp se fabrique
	 * depuis l'harmonisée, et l'harmonisée elle-même doit être réécrite (profils retirés,
	 * qualité alignée). Régénérer la pièce jointe entière par `generer()` est la seule voie
	 * qui laisse l'inventaire, le poids enregistré et la trace de `ImagesLegeres_Table`
	 * cohérents entre eux.
	 *
	 * Idempotente : une fois le rattrapage passé, plus aucune harmonisée n'est sans frère,
	 * la liste est vide et un second appel ne réécrit rien.
	 *
	 * @return int[] Identifiants de pièces jointes, triés.
	 */
	public static function ids_webp_manquant() {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$ids     = array();

		foreach ( ImagesLegeres_Harmonisees::tout() as $ligne ) {
			$harmonisee = $base . $ligne['chemin_harmonise'];

			// Une ligne dont le fichier a disparu n'est pas un « webp manquant » : c'est un
			// trou d'inventaire, que `harmoniser` sans option traite déjà.
			if ( ! file_exists( $harmonisee ) || file_exists( $harmonisee . '.webp' ) ) {
				continue;
			}

			$ids[ (int) $ligne['attachment_id'] ] = true;
		}

		$ids = array_keys( $ids );
		sort( $ids );

		return $ids;
	}

	/**
	 * Écrit un fichier harmonisé depuis l'original, par recadrage sur le rectangle posé, sur
	 * le carré centré sinon : jamais de fond ajouté. Écriture atomique. Rend '' si tout va
	 * bien, le motif d'erreur sinon.
	 */
	private static function ecrire( $original, $destination, $rectangle, $largeur, $hauteur ) {
		$tmp = '';

		try {
			$image = new Imagick( $original );

			if ( ! $rectangle ) {
				$rectangle = ImagesLegeres_Rendu::rectangle_centre(
					$image->getImageWidth(),
					$image->getImageHeight(),
					ImagesLegeres_Recadrage::ratio_cible_numerique()
				);
			}

			ImagesLegeres_Rendu::appliquer_recadrage( $image, $rectangle, $largeur, $hauteur );

			// Qualité d'encodage alignée sur celle des sous-tailles natives de WordPress :
			// sans ce réglage, Imagick reprend celle de la source (92 et plus sur une photo
			// de téléphone) et l'harmonisée pèse trois fois le fichier qu'elle remplace —
			// c'est le `-1024x1024.jpg` de 258 Ko relevé en production le 2026-11-15.
			// Profils retirés comme le fait WordPress pour ses propres sous-tailles, le
			// profil couleur excepté : sans ce retrait, l'harmonisée traîne l'EXIF complet
			// du téléphone (app1 53 653 o, app11 6 479 o, exif 26 171 o, xmp 1 034 o =
			// 81 501 o mesurés sur une photo Pixel de production). Un carré de 50 px pesait
			// 88 802 octets pour 818 octets d'image, et ces 81 Ko incompressibles
			// repartaient tels quels dans le frère .webp.
			ImagesLegeres_Compat::retirer_profils( $image );

			$image->setImageFormat( strtolower( pathinfo( $original, PATHINFO_EXTENSION ) ) === 'png' ? 'png' : 'jpeg' );

			if ( 'PNG' !== strtoupper( $image->getImageFormat() ) ) {
				$image->setImageCompressionQuality( self::qualite() );
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

		$carres = array_unique( array_column( ImagesLegeres_Harmonisees::par_attachment( $attachment_id ), 'chemin_harmonise' ) );

		foreach ( $carres as $carre ) {
			if ( ImagesLegeres_Harmonisees::reference_ailleurs( $carre, $attachment_id ) ) {
				continue;
			}

			foreach ( array( $base . $carre, $base . $carre . '.webp' ) as $chemin ) {
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

		// Un carré partagé par plusieurs tailles n'est listé (et supprimé) qu'une fois.
		$trouves = array_values( array_unique( $trouves ) );
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
