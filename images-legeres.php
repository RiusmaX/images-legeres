<?php
/**
 * Plugin Name:       Images légères
 * Plugin URI:        https://github.com/RiusmaX/images-legeres
 * Description:       Convertit les images en WebP, retaille les originaux surdimensionnés, harmonise les photos produit WooCommerce et sert la même photo aux flux Google et Meta — sans dépendance réseau ni clé.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Marius Sergent
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       images-legeres
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMAGES_LEGERES_VERSION', '0.2.0' );
define( 'IMAGES_LEGERES_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMAGES_LEGERES_URL', plugin_dir_url( __FILE__ ) );
define( 'IMAGES_LEGERES_BASENAME', plugin_basename( __FILE__ ) );

require_once IMAGES_LEGERES_DIR . 'includes/class-imageslegeres-autoloader.php';

ImagesLegeres_Autoloader::register();

register_activation_hook( __FILE__, array( 'ImagesLegeres_Activation', 'activer' ) );
register_deactivation_hook( __FILE__, array( 'ImagesLegeres_Activation', 'desactiver' ) );

/**
 * Désinstallation complète via uninstall.php (niveau "désinstaller").
 */

add_action( 'plugins_loaded', array( 'ImagesLegeres_Plugin', 'demarrer' ) );
