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
			// Décision de Marius du 2026-11-15 : tout doit être en WebP. Le seuil ne sert
			// plus qu'à écarter le cas où le WebP pèserait plus lourd que l'original.
			'gain_minimal_pourcent'        => 0,
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
			// Voie unique du SSIM : le calcul PHP sur la luminance (ADR 0007, ssim.py).
			// `Imagick::compareImages()` moyenne le SSIM sur les canaux RVB et rend une
			// valeur plus basse (0,99399 contre 0,99738 sur la même paire, F4) : le seuil
			// ne voudrait pas dire la même chose d'un hébergement à l'autre.
			'ssim_imagick_actif'           => false,
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

	/**
	 * Migration du seuil de gain à 0 % (décision de Marius du 2026-11-15) : un site déjà
	 * installé garde l'option telle qu'elle a été enregistrée, et serait donc resté à 10 % —
	 * c'est ce seuil qui a laissé 87 sous-tailles sans WebP en production.
	 *
	 * Appelée au seul changement de version (activation ou mise à jour), jamais à chaque
	 * requête : aucune option témoin à écrire, donc rien qui survive à « Tout rendre » et
	 * ressorte en orphelin.
	 */
	public static function migrer() {
		$enregistres = get_option( self::OPTION, false );

		if ( ! is_array( $enregistres ) || ! isset( $enregistres['gain_minimal_pourcent'] ) || 0 === (int) $enregistres['gain_minimal_pourcent'] ) {
			return false;
		}

		$enregistres['gain_minimal_pourcent'] = 0;
		update_option( self::OPTION, $enregistres );

		return true;
	}

	public static function initialiser_defauts() {
		if ( false === get_option( self::OPTION, false ) ) {
			update_option( self::OPTION, self::defauts() );
		}
	}
}
