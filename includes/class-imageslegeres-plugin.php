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

		// SEC-10 : `register_activation_hook` ne part pas sur une mise à jour. Un site passé
		// d'une version antérieure gardait son `images-legeres/.htaccess` en « Deny from all »
		// — celui qui rend le dossier flux/ en 403 pour Google Listings and Ads et Facebook
		// for WooCommerce — jusqu'au premier passage du journal. Deux filets : le crochet de
		// l'assistant de mise à jour, et la comparaison de version, seule à couvrir une mise
		// à jour déposée en FTP ou par Git, où l'assistant ne part jamais.
		add_action( 'upgrader_process_complete', array( __CLASS__, 'apres_mise_a_jour' ), 10, 2 );
		self::verifier_version();

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

	/**
	 * Re-pose les protections quand l'assistant de WordPress vient de mettre à jour cette
	 * extension — et elle seule (SEC-10).
	 *
	 * @param WP_Upgrader $upgrader   Instance de l'assistant (non utilisée).
	 * @param array       $hook_extra Détail de l'opération.
	 */
	public static function apres_mise_a_jour( $upgrader, $hook_extra ) {
		unset( $upgrader );

		if ( ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		$concernees = array();

		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$concernees = $hook_extra['plugins'];
		} elseif ( ! empty( $hook_extra['plugin'] ) ) {
			$concernees = array( $hook_extra['plugin'] );
		}

		if ( ! in_array( IMAGES_LEGERES_BASENAME, $concernees, true ) ) {
			return;
		}

		self::reposer_protections();
	}

	/**
	 * Re-pose les protections dès que la version enregistrée en option diffère de celle du
	 * fichier : couvre les mises à jour que `upgrader_process_complete` ne voit pas (SEC-10).
	 * Un seul `get_option()` autoloadé par requête tant que les deux versions concordent.
	 */
	public static function verifier_version() {
		if ( get_option( 'images_legeres_version' ) === IMAGES_LEGERES_VERSION ) {
			return;
		}

		self::reposer_protections();
	}

	/**
	 * Ne retient la version que si quelque chose a effectivement été protégé. Sans cette
	 * condition, un site qui vient de rendre ses fichiers (« Tout rendre » supprime l'option)
	 * se voyait recréer `images_legeres_version` à la requête suivante, et
	 * `wp images-legeres orphelins` la signalait comme un reste.
	 */
	private static function reposer_protections() {
		if ( ImagesLegeres_Protection::reposer_existants() ) {
			update_option( 'images_legeres_version', IMAGES_LEGERES_VERSION );
		}
	}
}
