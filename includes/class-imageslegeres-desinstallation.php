<?php
/**
 * Niveaux 2 (« tout rendre ») et 3 (« désinstaller ») de la désinstallation (ADR 0004 §7).
 *
 * Aucun drop-in n'est jamais posé par l'extension (object-cache.php, advanced-cache.php) :
 * ces méthodes n'ont donc rien à en retirer, mais le contrôle d'orphelins (ImagesLegeres_Cli)
 * vérifie leur absence pour attraper une régression.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Desinstallation {

	const METAS = array(
		'_images_legeres_crop',
		'_images_legeres_meta_origine',
		'_images_legeres_flux',
	);

	const OPTIONS = array(
		ImagesLegeres_Reglages::OPTION,
		'images_legeres_version',
		ImagesLegeres_Activation::OPTION_AUTRE_EDITEUR,
		ImagesLegeres_Lot::OPTION_ETAT,
	);

	/**
	 * Restaure les originaux retaillés et les métadonnées sauvegardées, supprime les frères
	 * .webp et les fichiers de flux, puis désinstalle (ADR 0004 §7, niveau « tout rendre »).
	 */
	public static function tout_rendre() {
		foreach ( ImagesLegeres_Table::fichiers_retailles() as $ligne ) {
			self::restaurer_fichier( $ligne );
		}

		ImagesLegeres_Lot::annuler_planifications();

		// SEC-4 : restaurer_fichier() ne retire que le frère de l'original retaillé, un par
		// pièce jointe. Les frères de toutes les sous-tailles restaient sur le disque, servis
		// en 200, et `wp images-legeres orphelins` rendait « propre » quand même.
		self::supprimer_freres_webp();

		self::supprimer_dossier_images_legeres();
		self::desinstaller();
	}

	/**
	 * Supprime les frères `.webp` que l'extension a elle-même produits. Rend la liste des
	 * chemins supprimés.
	 *
	 * SEC-9 : cette méthode effaçait tout `<nom>.jpg.webp` trouvé dans uploads, au motif
	 * qu'un WebP tiers s'appellerait `<nom>.webp` sans l'extension d'origine. C'est faux :
	 * le double suffixe est imposé par la règle `RewriteCond %{REQUEST_FILENAME}.webp -f`,
	 * celle-là même que cette extension écrit, et c'est donc aussi la convention d'EWWW
	 * Image Optimizer, de WebP Express et de ShortPixel. « Tout rendre » détruisait le
	 * travail d'un autre outil sans le dire. La suppression est désormais bornée à ce que
	 * `ImagesLegeres_Table` connaît ; le reste est listé par `lister_freres_webp_tiers()`
	 * et jamais touché.
	 */
	public static function supprimer_freres_webp() {
		$uploads   = wp_get_upload_dir();
		$basedir   = $uploads['basedir'];
		$supprimes = array();

		foreach ( self::lister_freres_webp() as $chemin ) {
			wp_delete_file_from_directory( $chemin, $basedir );

			if ( ! file_exists( $chemin ) ) {
				$supprimes[] = $chemin;
			}
		}

		return $supprimes;
	}

	/**
	 * Frères `.webp` produits par l'extension : ceux dont la source est une ligne de
	 * `ImagesLegeres_Table`. C'est exactement ce que « Tout rendre » supprime, et ce que
	 * `wp images-legeres orphelins` doit compter (SEC-4 : un contrôle qui ne voit pas ce qui
	 * reste est pire que pas de contrôle).
	 */
	public static function lister_freres_webp() {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$freres  = array();

		foreach ( ImagesLegeres_Table::tout() as $ligne ) {
			if ( empty( $ligne['chemin'] ) ) {
				continue;
			}

			$frere = $base . $ligne['chemin'] . '.webp';

			if ( ! ImagesLegeres_Moteur::sous_uploads( $frere ) || ! file_exists( $frere ) ) {
				continue;
			}

			if ( ! in_array( $frere, $freres, true ) ) {
				$freres[] = $frere;
			}
		}

		sort( $freres );

		return $freres;
	}

	/**
	 * Frères `.webp` présents dans uploads mais inconnus de `ImagesLegeres_Table` : le
	 * travail d'un autre outil d'optimisation. Listés pour que l'administrateur les voie,
	 * jamais supprimés (SEC-9).
	 */
	public static function lister_freres_webp_tiers() {
		$connus = self::lister_freres_webp();
		$tiers  = array();

		foreach ( self::lister_tous_freres_webp() as $chemin ) {
			if ( ! in_array( $chemin, $connus, true ) ) {
				$tiers[] = $chemin;
			}
		}

		return $tiers;
	}

	/**
	 * Balayage brut de uploads : tout fichier `<nom>.jpg.webp`, `<nom>.jpeg.webp` ou
	 * `<nom>.png.webp`, quelle qu'en soit l'origine. Ne dit rien de qui l'a produit : sert
	 * de dénominateur à `lister_freres_webp_tiers()` et aux tests.
	 */
	public static function lister_tous_freres_webp() {
		$uploads = wp_get_upload_dir();
		$base    = untrailingslashit( $uploads['basedir'] );

		if ( ! is_dir( $base ) ) {
			return array();
		}

		$freres   = array();
		$iterateur = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterateur as $fichier ) {
			if ( ! $fichier->isFile() ) {
				continue;
			}

			if ( preg_match( '#\.(?:jpe?g|png)\.webp$#i', $fichier->getFilename() ) ) {
				$freres[] = $fichier->getPathname();
			}
		}

		sort( $freres );

		return $freres;
	}

	/**
	 * Supprime les tables, les options et les métas de l'extension ; laisse les fichiers
	 * (ADR 0004 §7, niveau « désinstaller »). C'est le point d'entrée appelé par uninstall.php.
	 */
	public static function desinstaller() {
		global $wpdb;

		ImagesLegeres_Table::supprimer();
		ImagesLegeres_File::supprimer();

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		foreach ( self::METAS as $meta_key ) {
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
	}

	private static function restaurer_fichier( array $ligne ) {
		$uploads     = wp_get_upload_dir();
		$base        = trailingslashit( $uploads['basedir'] );
		$sauvegarde  = $ligne['chemin_original_sauvegarde'];
		$destination = $base . $ligne['chemin'];

		if ( '' !== $sauvegarde && file_exists( $base . $sauvegarde ) ) {
			copy( $base . $sauvegarde, $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$webp = $destination . '.webp';
		if ( file_exists( $webp ) ) {
			unlink( $webp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$attachment_id = (int) $ligne['attachment_id'];
		if ( $attachment_id > 0 ) {
			$meta_origine = get_post_meta( $attachment_id, '_images_legeres_meta_origine', true );
			if ( is_array( $meta_origine ) ) {
				wp_update_attachment_metadata( $attachment_id, $meta_origine );
			}
		}
	}

	private static function supprimer_dossier_images_legeres() {
		$uploads = wp_get_upload_dir();
		$dossier = trailingslashit( $uploads['basedir'] ) . 'images-legeres';

		if ( is_dir( $dossier ) ) {
			self::supprimer_recursivement( $dossier );
		}
	}

	private static function supprimer_recursivement( $chemin ) {
		$elements = scandir( $chemin );

		if ( false === $elements ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( '.' === $element || '..' === $element ) {
				continue;
			}

			$cible = trailingslashit( $chemin ) . $element;

			if ( is_dir( $cible ) ) {
				self::supprimer_recursivement( $cible );
			} else {
				unlink( $cible ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		rmdir( $chemin ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
