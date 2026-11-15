<?php
/**
 * Journal de l'extension : table d'état pour le détail (ImagesLegeres_Table), fichier
 * uploads/images-legeres/journal-<date>.log pour la trace lisible (ADR 0004 §5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Journal {

	public static function dossier() {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . 'images-legeres';
	}

	public static function fichier_du_jour() {
		return trailingslashit( self::dossier() ) . 'journal-' . gmdate( 'Y-m-d' ) . '.log';
	}

	public static function preparer_dossier() {
		$dossier = self::dossier();

		if ( ! file_exists( $dossier ) ) {
			wp_mkdir_p( $dossier );
		}

		$index = trailingslashit( $dossier ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$htaccess = trailingslashit( $dossier ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Écrit une ligne : chemin, décision, poids avant/après, durée, erreur.
	 */
	public static function ecrire( $chemin, $decision, $poids_avant = 0, $poids_apres = 0, $duree_ms = 0, $erreur = '' ) {
		self::preparer_dossier();

		$ligne = sprintf(
			"%s\t%s\t%s\t%d\t%d\t%d\t%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			$chemin,
			$decision,
			$poids_avant,
			$poids_apres,
			$duree_ms,
			$erreur
		);

		file_put_contents( self::fichier_du_jour(), $ligne, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Rend les N dernières lignes du journal du jour (ou du jour le plus récent trouvé).
	 */
	public static function dernieres_lignes( $nombre = 50 ) {
		$fichier = self::fichier_le_plus_recent();

		if ( ! $fichier || ! file_exists( $fichier ) ) {
			return array();
		}

		$lignes = file( $fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $lignes ) {
			return array();
		}

		return array_slice( $lignes, -1 * $nombre );
	}

	public static function fichier_le_plus_recent() {
		$dossier = self::dossier();

		if ( ! is_dir( $dossier ) ) {
			return '';
		}

		$fichiers = glob( trailingslashit( $dossier ) . 'journal-*.log' );

		if ( empty( $fichiers ) ) {
			return '';
		}

		sort( $fichiers );

		return array_pop( $fichiers );
	}
}
