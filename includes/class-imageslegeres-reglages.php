<?php
/**
 * Réglages de l'extension : valeurs par défaut (spec § 5) et accès à l'option unique.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Reglages {

	const OPTION = 'images_legeres_reglages';

	public static function defauts() {
		return array(
			'conversion_webp_active'       => true,
			'qualite_webp_generale'        => 82,
			'qualite_webp_produit'         => 90,
			'gain_minimal_pourcent'        => 10,
			'reecriture_sortie_active'     => true,
			'mode_htaccess_actif'          => false,
			'mode_compatible_actif'        => false,
			'dimension_max_nouveaux_envois' => 1920,
			'retaille_originaux_active'    => false,
			'harmonisation_produit_active' => false,
			'ratio_cible'                  => '1:1',
			'couleur_fond_auto'            => true,
			'couleur_fond'                 => '',
			'image_flux_active'            => false,
			'flux_google_actif'            => false,
			'flux_meta_actif'              => false,
			'dimension_flux'               => 1500,
			'qualite_flux'                 => 85,
			'plage_horaire_debut'          => '00:00',
			'plage_horaire_fin'            => '06:00',
			'pause_entre_images_secondes'  => 2,
			'charge_max_serveur'           => 4.0,
			'mode_apercu_actif'            => true,
		);
	}

	public static function tout() {
		$enregistres = get_option( self::OPTION, array() );

		return wp_parse_args( $enregistres, self::defauts() );
	}

	public static function valeur( $cle ) {
		$reglages = self::tout();

		return isset( $reglages[ $cle ] ) ? $reglages[ $cle ] : null;
	}

	public static function enregistrer( array $reglages ) {
		$fusionnes = wp_parse_args( $reglages, self::tout() );

		update_option( self::OPTION, $fusionnes );

		return $fusionnes;
	}

	public static function initialiser_defauts() {
		if ( false === get_option( self::OPTION, false ) ) {
			update_option( self::OPTION, self::defauts() );
		}
	}
}
