<?php
/**
 * Commande WP-CLI `wp images-legeres`.
 *
 * Ce socle (mission 12) ne porte que `status` et `orphelins`. Les sous-commandes `crop`,
 * `alt` et `flux check` (interfaces usine, spec § 10) arrivent avec les missions 15 et 18.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Cli {

	/**
	 * Affiche l'état de l'extension : réglages actifs, compte des fichiers par état.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json ou yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$reglages = ImagesLegeres_Reglages::tout();
		$compte   = ImagesLegeres_Table::compter_par_etat();

		$donnees = array(
			array( 'cle' => 'version', 'valeur' => IMAGES_LEGERES_VERSION ),
			array( 'cle' => 'mode_apercu_actif', 'valeur' => $reglages['mode_apercu_actif'] ? '1' : '0' ),
			array( 'cle' => 'table_existe', 'valeur' => ImagesLegeres_Table::existe() ? '1' : '0' ),
		);

		foreach ( array( 'a-faire', 'en-cours', 'fait', 'ignore', 'erreur' ) as $etat ) {
			$donnees[] = array(
				'cle'    => 'fichiers_' . $etat,
				'valeur' => (string) ( $compte[ $etat ] ?? 0 ),
			);
		}

		\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $donnees, array( 'cle', 'valeur' ) );
	}

	/**
	 * Liste ce qui resterait d'Images légères après une désinstallation : table, options,
	 * métas, drop-ins, dossier uploads/images-legeres. Rend une liste vide si tout est propre.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv ou yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function orphelins( $args, $assoc_args ) {
		global $wpdb;

		$orphelins = array();

		if ( ImagesLegeres_Table::existe() ) {
			$orphelins[] = array( 'type' => 'table', 'identifiant' => ImagesLegeres_Table::nom() );
		}

		foreach ( ImagesLegeres_Desinstallation::OPTIONS as $option ) {
			if ( false !== get_option( $option, false ) ) {
				$orphelins[] = array( 'type' => 'option', 'identifiant' => $option );
			}
		}

		foreach ( ImagesLegeres_Desinstallation::METAS as $meta_key ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $total > 0 ) {
				$orphelins[] = array( 'type' => 'meta', 'identifiant' => $meta_key . ' (' . $total . ')' );
			}
		}

		foreach ( array( 'object-cache.php', 'advanced-cache.php' ) as $drop_in ) {
			if ( file_exists( trailingslashit( WP_CONTENT_DIR ) . $drop_in ) ) {
				$orphelins[] = array( 'type' => 'drop-in', 'identifiant' => $drop_in );
			}
		}

		$uploads = wp_get_upload_dir();
		$dossier = trailingslashit( $uploads['basedir'] ) . 'images-legeres';
		if ( is_dir( $dossier ) ) {
			$orphelins[] = array( 'type' => 'dossier', 'identifiant' => $dossier );
		}

		\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $orphelins, array( 'type', 'identifiant' ) );
	}
}
