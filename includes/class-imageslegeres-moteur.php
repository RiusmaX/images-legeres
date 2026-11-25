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
	 * Motif porté par le seul cas où une sous-taille reste servie en JPEG/PNG depuis que le
	 * seuil de gain vaut 0 % : le WebP pèserait plus lourd que l'original. Compté à part par
	 * `wp images-legeres status`.
	 */
	const MOTIF_WEBP_PLUS_LOURD = 'webp plus lourd que l\'original';

	/**
	 * Remet en file une pièce jointe dont les sous-tailles viennent de changer (F5).
	 *
	 * Cause mesurée le 2026-11-15 sur l'instance de test : les sous-tailles créées APRÈS le
	 * passage du lot n'étaient jamais reconverties — personne ne redemandait la pièce jointe.
	 * Une régénération (`wp media regenerate`, une taille déclarée ajoutée par le thème, un
	 * changement de réglage WooCommerce) laissait donc des fichiers servis sans frère .webp.
	 *
	 * @param array $metadata      Métadonnées enregistrées (rendues telles quelles).
	 * @param int   $attachment_id Pièce jointe concernée.
	 */
	public static function remettre_en_file( $metadata, $attachment_id ) {
		if ( ImagesLegeres_Reglages::valeur( 'conversion_webp_active' ) ) {
			ImagesLegeres_Lot::mettre_en_file_auto( (int) $attachment_id, ImagesLegeres_File::MOTIF_SOUS_TAILLES );
		}

		return $metadata;
	}

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

			ImagesLegeres_Qualite::appliquer_encodage_webp( $image, $qualite );

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
				'motif'           => $gain_pourcent < 0 && $gain_minimal <= 0
					? sprintf( '%s (%.1f %%)', self::MOTIF_WEBP_PLUS_LOURD, -$gain_pourcent )
					: sprintf( 'gain %.1f %% sous le seuil de %d %%', $gain_pourcent, $gain_minimal ),
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

		if ( '' === $chemin_sauvegarde ) {
			$image->clear();
			$image->destroy();

			return array(
				'etat'  => 'erreur',
				'motif' => 'fichier hors du dossier uploads',
			);
		}

		wp_mkdir_p( dirname( $chemin_sauvegarde ) );
		ImagesLegeres_Protection::poser_originaux();

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

	/**
	 * Chemin de la copie de sécurité pleine résolution. Rend une chaîne vide quand la source
	 * ne vit pas sous uploads (SEC-8) : sans ce repli, un `chemin_relatif()` vide aurait
	 * désigné le dossier `originaux/` lui-même.
	 */
	public static function chemin_sauvegarde_original( $chemin_absolu ) {
		$relatif = self::chemin_relatif( $chemin_absolu );

		if ( '' === $relatif ) {
			return '';
		}

		$uploads = wp_get_upload_dir();

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

	/** Types de contenu WooCommerce qui portent des images produit. */
	const TYPES_PRODUIT = array( 'product', 'product_variation' );

	/**
	 * Rend true si la pièce jointe est une image produit. Définition unique, partagée par le
	 * choix de qualité (ADR 0007), le recadrage, `crop pending`, `alt missing`, les flux et la
	 * file des lots : toute autre façon de reconnaître une image produit est un bogue.
	 *
	 * Une image est produit si elle est **rattachée** à un produit ou à une variation
	 * (`post_parent`), **ou** si elle est **affichée** par l'un d'eux — image principale
	 * (`_thumbnail_id`) ou galerie (`_product_image_gallery`).
	 *
	 * Pourquoi l'usage et pas seulement le rattachement : relevé le 2026-11-15 sur
	 * monkobougie.fr, 105 images posées en image principale ou en galerie d'un produit ont
	 * `post_parent = 0` (téléversées depuis la médiathèque). Elles étaient converties en
	 * qualité générale (82) au lieu de la qualité produit (90), et sortaient du recadrage, du
	 * contrôle des textes alternatifs et des flux.
	 *
	 * Le rattachement sans affichage reste reconnu produit (20 cas sur le même site) : sans
	 * danger, l'image n'est de toute façon servie nulle part.
	 */
	public static function est_image_produit( $attachment_id ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return false;
		}

		$parent_id = wp_get_post_parent_id( $attachment_id );

		if ( $parent_id && in_array( get_post_type( $parent_id ), self::TYPES_PRODUIT, true ) ) {
			return true;
		}

		$types = self::types_produit_sql();

		$porteur_vignette = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta.post_id FROM {$wpdb->postmeta} meta
				INNER JOIN {$wpdb->posts} porteur ON porteur.ID = meta.post_id AND porteur.post_type IN ({$types})
				WHERE meta.meta_key = '_thumbnail_id' AND meta.meta_value = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(string) $attachment_id
			)
		);

		if ( $porteur_vignette ) {
			return true;
		}

		// FIND_IN_SET compare des éléments entiers de la liste séparée par des virgules : une
		// galerie « 123,412 » ne contient pas l'image 12, là où un LIKE '%12%' l'aurait dit.
		$porteur_galerie = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_product_image_gallery'
				AND FIND_IN_SET( %s, REPLACE( meta_value, ' ', '' ) ) > 0 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(string) $attachment_id
			)
		);

		return (bool) $porteur_galerie;
	}

	/**
	 * Requête SQL qui rend les identifiants des pièces jointes image reconnues produit, au
	 * sens de `est_image_produit()`. Une seule requête groupée pour tout un lot : les
	 * commandes qui balaient la médiathèque (`crop pending`, `flux check`, la file des lots)
	 * ne doivent pas poser une question par image.
	 */
	public static function sql_images_produit() {
		global $wpdb;

		$types = self::types_produit_sql();
		$image = "p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'";

		return "SELECT ID FROM (
			SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent AND parent.post_type IN ({$types})
				WHERE {$image}
			UNION
			SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} meta ON meta.meta_key = '_thumbnail_id' AND meta.meta_value = CAST( p.ID AS CHAR )
				INNER JOIN {$wpdb->posts} porteur ON porteur.ID = meta.post_id AND porteur.post_type IN ({$types})
				WHERE {$image}
			UNION
			SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} galerie ON galerie.meta_key = '_product_image_gallery'
					AND FIND_IN_SET( p.ID, REPLACE( galerie.meta_value, ' ', '' ) ) > 0
				WHERE {$image}
		) images_produit";
	}

	/** Identifiants des images produit du site, en une requête. */
	public static function ids_images_produit() {
		global $wpdb;

		return array_map( 'intval', (array) $wpdb->get_col( self::sql_images_produit() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Le produit (ou la variation) qui porte cette image : son parent s'il en est un, sinon
	 * celui qui l'affiche en image principale, sinon celui qui l'affiche en galerie. Sert
	 * aux commandes qui rendent compte image par image ; 0 si aucun.
	 */
	public static function produit_porteur( $attachment_id ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;
		$parent_id     = wp_get_post_parent_id( $attachment_id );

		if ( $parent_id && in_array( get_post_type( $parent_id ), self::TYPES_PRODUIT, true ) ) {
			return (int) $parent_id;
		}

		$types = self::types_produit_sql();

		$porteur = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta.post_id FROM {$wpdb->postmeta} meta
				INNER JOIN {$wpdb->posts} porteur ON porteur.ID = meta.post_id AND porteur.post_type IN ({$types})
				WHERE meta.meta_key = '_thumbnail_id' AND meta.meta_value = %s
				ORDER BY meta.post_id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(string) $attachment_id
			)
		);

		if ( $porteur ) {
			return (int) $porteur;
		}

		$porteur = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_product_image_gallery'
				AND FIND_IN_SET( %s, REPLACE( meta_value, ' ', '' ) ) > 0
				ORDER BY post_id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(string) $attachment_id
			)
		);

		return $porteur ? (int) $porteur : 0;
	}

	/** Liste des types produit, échappée, pour un IN (...) SQL. */
	private static function types_produit_sql() {
		$echappes = array_map(
			function ( $type ) {
				return "'" . esc_sql( $type ) . "'";
			},
			self::TYPES_PRODUIT
		);

		return implode( ', ', $echappes );
	}

	/**
	 * Chemins absolus des fichiers physiques d'une pièce jointe : l'original et chacune de
	 * ses sous-tailles. Public : ImagesLegeres_Cycle_Vie s'en sert pour retrouver les frères
	 * .webp à supprimer quand la pièce jointe disparaît (SEC-1).
	 */
	public static function fichiers_de_attachment( $attachment_id ) {
		$chemin = get_attached_file( $attachment_id );

		if ( ! $chemin ) {
			return array();
		}

		// SEC-8 : les deux sources sont des métadonnées de la pièce jointe, donc des données
		// d'entrée. Un « .. » dans `_wp_attached_file` ou dans `sizes[*]['file']` sortait du
		// dossier uploads, et le crochet `delete_attachment` supprimait ce qu'il y trouvait.
		$fichiers = array();

		if ( self::sous_uploads( $chemin ) ) {
			$fichiers[] = $chemin;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! empty( $metadata['sizes'] ) ) {
			$dossier = dirname( $chemin );

			foreach ( $metadata['sizes'] as $taille ) {
				if ( ! empty( $taille['file'] ) ) {
					$chemin_taille = trailingslashit( $dossier ) . $taille['file'];

					if ( self::sous_uploads( $chemin_taille ) && ! in_array( $chemin_taille, $fichiers, true ) ) {
						$fichiers[] = $chemin_taille;
					}
				}
			}
		}

		return $fichiers;
	}

	/**
	 * Chemin d'un fichier relativement au dossier uploads. Rend une chaîne vide si le chemin
	 * ne vit pas sous uploads.
	 *
	 * SEC-8 : c'était un simple str_replace(). Un « .. » dans `_wp_attached_file` traversait
	 * donc intact jusqu'à `chemin_sauvegarde_original()`, qui rendait un chemin hors de
	 * uploads, que `ImagesLegeres_Cycle_Vie` supprimait ensuite — n'importe quelle extension,
	 * `wp-config.php` compris. La garde est ici, pas seulement chez l'appelant, pour que les
	 * appelants futurs en héritent.
	 */
	public static function chemin_relatif( $chemin_absolu ) {
		$chemin_absolu = (string) $chemin_absolu;

		if ( ! self::sous_uploads( $chemin_absolu ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();

		return str_replace( trailingslashit( wp_normalize_path( $uploads['basedir'] ) ), '', wp_normalize_path( $chemin_absolu ) );
	}

	/**
	 * Rend true si le chemin — existant ou non — se résout sous le dossier uploads (SEC-8).
	 *
	 * Deux gardes, parce qu'aucune ne suffit seule : refus de tout segment « .. » (un nom de
	 * fichier légitime n'en a pas, et le fichier visé peut ne pas encore exister, auquel cas
	 * realpath() ne répond rien), puis confinement par realpath() sous le realpath de
	 * `basedir` quand le fichier existe — c'est ce second cas qui attrape les liens
	 * symboliques.
	 */
	public static function sous_uploads( $chemin ) {
		$chemin = (string) $chemin;

		if ( '' === $chemin ) {
			return false;
		}

		$uploads = wp_get_upload_dir();
		$base    = realpath( $uploads['basedir'] );

		if ( ! $base ) {
			return false;
		}

		$base      = trailingslashit( wp_normalize_path( $base ) );
		$normalise = wp_normalize_path( $chemin );

		foreach ( explode( '/', $normalise ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}

		$reel = realpath( $chemin );

		if ( $reel ) {
			$normalise = wp_normalize_path( $reel );
		}

		return 0 === strpos( trailingslashit( $normalise ), $base );
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
