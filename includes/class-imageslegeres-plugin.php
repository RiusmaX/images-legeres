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

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'images-legeres', 'ImagesLegeres_Cli' );
		}
	}
}
