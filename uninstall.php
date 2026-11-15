<?php
/**
 * Désinstallation (niveau 3, « désinstaller » — ADR 0004 §7) : appelé par WordPress quand
 * l'extension est supprimée depuis l'écran Extensions. Supprime table, options et métas ;
 * laisse les fichiers (c'est « Tout rendre », joué depuis l'écran d'administration, qui
 * restaure puis nettoie les fichiers avant d'appeler la même désinstallation).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// WordPress charge ce fichier SANS le fichier principal de l'extension : ni les constantes
// ni le chargeur de classes ne sont en place. Une liste de `require` à la main se périme à
// chaque classe ajoutée à la désinstallation — c'est ce qui a mis l'écran « erreur
// critique » sur `wp plugin uninstall` quand l'harmonisation est arrivée (F5). Le chargeur
// de classes, lui, ne se périme pas.
if ( ! defined( 'IMAGES_LEGERES_DIR' ) ) {
	define( 'IMAGES_LEGERES_DIR', plugin_dir_path( __FILE__ ) );
}

require_once IMAGES_LEGERES_DIR . 'includes/class-imageslegeres-autoloader.php';

ImagesLegeres_Autoloader::register();

ImagesLegeres_Desinstallation::desinstaller();
