<?php
/**
 * Écran d'administration sous « Médias » : État, Réglages, Lots, Journal (spec § 5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Admin {

	const OPTION_LOT_ETAT = ImagesLegeres_Lot::OPTION_ETAT;
	const SLUG            = 'images-legeres';

	public static function enregistrer() {
		add_action( 'admin_menu', array( __CLASS__, 'ajouter_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'traiter_actions' ) );
	}

	public static function ajouter_page() {
		add_media_page(
			__( 'Images légères', 'images-legeres' ),
			__( 'Images légères', 'images-legeres' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'rendre_page' )
		);
	}

	public static function traiter_actions() {
		if ( ! isset( $_POST['images_legeres_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['images_legeres_action'] ) );

		switch ( $action ) {
			case 'enregistrer_reglages':
				check_admin_referer( 'images_legeres_reglages' );
				$avant    = ImagesLegeres_Reglages::tout();
				$reglages = self::enregistrer_reglages();
				ImagesLegeres_Htaccess::appliquer( ! empty( $reglages['mode_htaccess_actif'] ) );
				ImagesLegeres_Lot::signaler_si_reglages_flux_changes( $avant, $reglages );
				break;

			case 'lot_lancer':
				check_admin_referer( 'images_legeres_lot' );
				ImagesLegeres_Lot::demarrer();
				break;

			case 'lot_pause':
				check_admin_referer( 'images_legeres_lot' );
				ImagesLegeres_Lot::mettre_en_pause();
				break;

			case 'lot_reprendre':
				check_admin_referer( 'images_legeres_lot' );
				ImagesLegeres_Lot::reprendre();
				break;

			case 'tout_rendre':
				check_admin_referer( 'images_legeres_tout_rendre' );
				ImagesLegeres_Desinstallation::tout_rendre();
				wp_safe_redirect( admin_url( 'upload.php' ) );
				exit;
		}
	}

	private static function enregistrer_reglages() {
		$defauts   = ImagesLegeres_Reglages::defauts();
		$reglages  = array();

		foreach ( $defauts as $cle => $valeur_defaut ) {
			if ( is_bool( $valeur_defaut ) ) {
				$reglages[ $cle ] = isset( $_POST[ $cle ] );
				continue;
			}

			if ( ! isset( $_POST[ $cle ] ) ) {
				$reglages[ $cle ] = $valeur_defaut;
				continue;
			}

			$brut = wp_unslash( $_POST[ $cle ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			if ( is_int( $valeur_defaut ) ) {
				$reglages[ $cle ] = (int) $brut;
			} elseif ( is_float( $valeur_defaut ) ) {
				$reglages[ $cle ] = (float) $brut;
			} else {
				$reglages[ $cle ] = sanitize_text_field( $brut );
			}
		}

		return ImagesLegeres_Reglages::enregistrer( $reglages );
	}

	public static function rendre_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$reglages   = ImagesLegeres_Reglages::tout();
		$compte     = ImagesLegeres_Table::compter_par_etat();
		$gain_total = ImagesLegeres_Table::gain_total_octets();
		$lot_etat   = get_option( self::OPTION_LOT_ETAT, 'arret' );
		$journal    = ImagesLegeres_Journal::dernieres_lignes( 50 );

		echo '<div class="wrap"><h1>' . esc_html__( 'Images légères', 'images-legeres' ) . '</h1>';

		self::rendre_encart_etat( $compte, $gain_total, $reglages );
		self::rendre_encart_reglages( $reglages );
		self::rendre_encart_lots( $lot_etat );
		self::rendre_encart_journal( $journal );

		echo '</div>';
	}

	private static function rendre_encart_etat( array $compte, $gain_total, array $reglages ) {
		$total = array_sum( $compte );

		echo '<h2>' . esc_html__( 'État', 'images-legeres' ) . '</h2><table class="widefat striped"><tbody>';
		printf(
			'<tr><th scope="row">%s</th><td>%d</td></tr>',
			esc_html__( 'Fichiers suivis', 'images-legeres' ),
			(int) $total
		);
		printf(
			'<tr><th scope="row">%s</th><td>%d</td></tr>',
			esc_html__( 'Convertis', 'images-legeres' ),
			(int) ( $compte['fait'] ?? 0 )
		);
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Octets économisés', 'images-legeres' ),
			esc_html( size_format( max( 0, (int) $gain_total ) ) )
		);
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Flux Google Listings and Ads', 'images-legeres' ),
			$reglages['flux_google_actif'] ? esc_html__( 'branché', 'images-legeres' ) : esc_html__( 'non branché', 'images-legeres' )
		);
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Flux Facebook for WooCommerce', 'images-legeres' ),
			$reglages['flux_meta_actif'] ? esc_html__( 'branché', 'images-legeres' ) : esc_html__( 'non branché', 'images-legeres' )
		);
		echo '</tbody></table>';

		$autre_editeur = get_option( ImagesLegeres_Activation::OPTION_AUTRE_EDITEUR, array() );

		if ( ! empty( $autre_editeur ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s : liste des classes d'éditeur d'image détectées */
						__( 'Un autre éditeur d’image était déjà branché à l’activation (%s) : vérifiez qu’il n’entre pas en conflit avec la conversion WebP et le recadrage.', 'images-legeres' ),
						implode( ', ', (array) $autre_editeur )
					)
				)
			);
		}

		echo '<p>' . esc_html__( 'Tout rendre restaure les originaux retaillés, supprime les fichiers générés et désinstalle l’extension.', 'images-legeres' ) . '</p>';
		echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Tout rendre : les fichiers générés seront supprimés et l’extension désinstallée. Continuer ?', 'images-legeres' ) ) . '\');">';
		wp_nonce_field( 'images_legeres_tout_rendre' );
		echo '<input type="hidden" name="images_legeres_action" value="tout_rendre" />';
		submit_button( __( 'Tout rendre', 'images-legeres' ), 'delete' );
		echo '</form>';
	}

	private static function rendre_encart_reglages( array $reglages ) {
		echo '<h2>' . esc_html__( 'Réglages', 'images-legeres' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'images_legeres_reglages' );
		echo '<input type="hidden" name="images_legeres_action" value="enregistrer_reglages" />';
		echo '<table class="form-table"><tbody>';

		self::ligne_case( 'conversion_webp_active', __( 'Conversion WebP active', 'images-legeres' ), $reglages );
		self::ligne_nombre( 'qualite_webp_generale', __( 'Qualité WebP générale', 'images-legeres' ), $reglages );
		self::ligne_nombre( 'qualite_webp_produit', __( 'Qualité WebP images produit', 'images-legeres' ), $reglages );
		self::ligne_nombre( 'gain_minimal_pourcent', __( 'Gain minimal pour garder le frère (%)', 'images-legeres' ), $reglages );
		self::ligne_case( 'reecriture_sortie_active', __( 'Réécriture à la sortie', 'images-legeres' ), $reglages );
		self::ligne_case( 'mode_htaccess_actif', __( 'Mode .htaccess (négociation Accept)', 'images-legeres' ), $reglages );
		self::ligne_case( 'mode_compatible_actif', __( 'Mode compatible (repli pour les navigateurs sans WebP)', 'images-legeres' ), $reglages );
		self::ligne_nombre( 'dimension_max_nouveaux_envois', __( 'Dimension maximale des nouveaux envois (px)', 'images-legeres' ), $reglages );
		self::ligne_case( 'retaille_originaux_active', __( 'Retaille des originaux existants', 'images-legeres' ), $reglages );
		self::ligne_case( 'harmonisation_produit_active', __( 'Harmonisation produit', 'images-legeres' ), $reglages );
		self::ligne_texte( 'ratio_cible', __( 'Ratio cible', 'images-legeres' ), $reglages );
		self::ligne_case( 'couleur_fond_auto', __( 'Couleur de fond automatique', 'images-legeres' ), $reglages );
		self::ligne_texte( 'couleur_fond', __( 'Couleur de fond (si non automatique)', 'images-legeres' ), $reglages );
		self::ligne_case( 'image_flux_active', __( 'Image de flux produits', 'images-legeres' ), $reglages );
		self::ligne_case( 'flux_google_actif', __( 'Flux Google Listings and Ads', 'images-legeres' ), $reglages );
		self::ligne_case( 'flux_meta_actif', __( 'Flux Facebook for WooCommerce', 'images-legeres' ), $reglages );
		self::ligne_nombre( 'dimension_flux', __( 'Dimension de l’image de flux (px)', 'images-legeres' ), $reglages );
		self::ligne_nombre( 'qualite_flux', __( 'Qualité JPEG de l’image de flux', 'images-legeres' ), $reglages );
		self::ligne_texte( 'plage_horaire_debut', __( 'Début de plage horaire des lots', 'images-legeres' ), $reglages );
		self::ligne_texte( 'plage_horaire_fin', __( 'Fin de plage horaire des lots', 'images-legeres' ), $reglages );
		self::ligne_nombre( 'pause_entre_images_secondes', __( 'Pause entre deux images (s)', 'images-legeres' ), $reglages );
		self::ligne_nombre( 'charge_max_serveur', __( 'Charge serveur maximale', 'images-legeres' ), $reglages );
		self::ligne_case( 'ssim_imagick_actif', __( 'Mesurer le SSIM avec ImageMagick (étalonnage ; voie normale : calcul PHP sur la luminance)', 'images-legeres' ), $reglages );
		self::ligne_case( 'mode_apercu_actif', __( 'Mode aperçu (réécriture réservée aux administrateurs connectés)', 'images-legeres' ), $reglages );

		echo '</tbody></table>';
		submit_button( __( 'Enregistrer les réglages', 'images-legeres' ) );
		echo '</form>';
	}

	private static function ligne_case( $cle, $libelle, array $reglages ) {
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" %s /> %s</label></td></tr>',
			esc_html( $libelle ),
			esc_attr( $cle ),
			checked( ! empty( $reglages[ $cle ] ), true, false ),
			esc_html__( 'activé', 'images-legeres' )
		);
	}

	private static function ligne_nombre( $cle, $libelle, array $reglages ) {
		printf(
			'<tr><th scope="row">%s</th><td><input type="number" step="any" name="%s" value="%s" class="small-text" /></td></tr>',
			esc_html( $libelle ),
			esc_attr( $cle ),
			esc_attr( $reglages[ $cle ] ?? '' )
		);
	}

	private static function ligne_texte( $cle, $libelle, array $reglages ) {
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="%s" value="%s" class="regular-text" /></td></tr>',
			esc_html( $libelle ),
			esc_attr( $cle ),
			esc_attr( $reglages[ $cle ] ?? '' )
		);
	}

	private static function rendre_encart_lots( $lot_etat ) {
		echo '<h2>' . esc_html__( 'Lots', 'images-legeres' ) . '</h2>';
		printf( '<p>%s <strong>%s</strong></p>', esc_html__( 'État de la file :', 'images-legeres' ), esc_html( $lot_etat ) );

		if ( ! ImagesLegeres_Lot::action_scheduler_disponible() ) {
			echo '<p class="notice notice-warning" style="padding:8px;">' . esc_html__( 'Action Scheduler est indisponible (ni WooCommerce, ni la bibliothèque autonome) : la file automatique ne peut pas tourner. Utilisez « wp images-legeres run » en ligne de commande.', 'images-legeres' ) . '</p>';
		}

		echo '<form method="post" style="display:inline-block;margin-right:8px;">';
		wp_nonce_field( 'images_legeres_lot' );
		echo '<input type="hidden" name="images_legeres_action" value="lot_lancer" />';
		submit_button( __( 'Lancer', 'images-legeres' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" style="display:inline-block;margin-right:8px;">';
		wp_nonce_field( 'images_legeres_lot' );
		echo '<input type="hidden" name="images_legeres_action" value="lot_pause" />';
		submit_button( __( 'Mettre en pause', 'images-legeres' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" style="display:inline-block;">';
		wp_nonce_field( 'images_legeres_lot' );
		echo '<input type="hidden" name="images_legeres_action" value="lot_reprendre" />';
		submit_button( __( 'Reprendre', 'images-legeres' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function rendre_encart_journal( array $journal ) {
		echo '<h2>' . esc_html__( 'Journal', 'images-legeres' ) . '</h2>';

		if ( empty( $journal ) ) {
			echo '<p>' . esc_html__( 'Aucune ligne de journal pour le moment.', 'images-legeres' ) . '</p>';
			return;
		}

		echo '<pre style="max-height:300px;overflow:auto;background:#fff;padding:8px;border:1px solid #ccd0d4;">';
		foreach ( $journal as $ligne ) {
			echo esc_html( $ligne ) . "\n";
		}
		echo '</pre>';

		$fichier = ImagesLegeres_Journal::fichier_le_plus_recent();
		if ( $fichier ) {
			printf( '<p><code>%s</code></p>', esc_html( $fichier ) );
		}
	}
}
