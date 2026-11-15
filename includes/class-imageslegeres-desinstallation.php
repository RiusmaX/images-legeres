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
	);

	/**
	 * Restaure les originaux retaillés et les métadonnées sauvegardées, supprime les frères
	 * .webp et les fichiers de flux, puis désinstalle (ADR 0004 §7, niveau « tout rendre »).
	 */
	public static function tout_rendre() {
		foreach ( ImagesLegeres_Table::fichiers_retailles() as $ligne ) {
			self::restaurer_fichier( $ligne );
		}

		self::supprimer_dossier_images_legeres();
		self::desinstaller();
	}

	/**
	 * Supprime les tables, les options et les métas de l'extension ; laisse les fichiers
	 * (ADR 0004 §7, niveau « désinstaller »). C'est le point d'entrée appelé par uninstall.php.
	 */
	public static function desinstaller() {
		global $wpdb;

		ImagesLegeres_Table::supprimer();

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
