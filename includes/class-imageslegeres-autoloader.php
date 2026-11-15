<?php
/**
 * Chargeur de classes PSR-4-like pour le préfixe ImagesLegeres_.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Autoloader {

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'charger' ) );
	}

	public static function charger( $classe ) {
		if ( 0 !== strpos( $classe, 'ImagesLegeres_' ) ) {
			return;
		}

		$nom_fichier = strtolower( str_replace( '_', '-', $classe ) );
		$chemin      = IMAGES_LEGERES_DIR . 'includes/class-' . $nom_fichier . '.php';

		if ( file_exists( $chemin ) ) {
			require_once $chemin;
		}
	}
}
