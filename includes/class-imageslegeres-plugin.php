<?php
/**
 * Point d'entrée runtime : chargement des traductions, écran d'administration, WP-CLI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Plugin {

	public static function demarrer() {
		load_plugin_textdomain( 'images-legeres', false, dirname( IMAGES_LEGERES_BASENAME ) . '/languages' );

		if ( is_admin() ) {
			ImagesLegeres_Admin::enregistrer();
		}

		ImagesLegeres_Service::enregistrer();
		ImagesLegeres_Flux::enregistrer();
		ImagesLegeres_Lot::enregistrer();
		ImagesLegeres_Cycle_Vie::enregistrer();

		add_filter( 'wp_image_editors', array( 'ImagesLegeres_Editor', 'enregistrer' ) );
		add_filter( 'woocommerce_get_image_size_single', array( 'ImagesLegeres_Recadrage', 'filtrer_taille_single' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'images-legeres', 'ImagesLegeres_Cli' );
			WP_CLI::add_command( 'images-legeres crop', 'ImagesLegeres_Cli_Crop' );
			WP_CLI::add_command( 'images-legeres alt', 'ImagesLegeres_Cli_Alt' );
			WP_CLI::add_command( 'images-legeres flux', 'ImagesLegeres_Cli_Flux' );
			WP_CLI::add_command( 'images-legeres qualite', 'ImagesLegeres_Cli_Qualite' );
		}
	}
}
