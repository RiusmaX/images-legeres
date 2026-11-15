<?php
/**
 * Réglages de l'extension : valeurs par défaut (spec § 5) et accès à l'option unique.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Reglages {

	const OPTION = 'images_legeres_reglages';

	/**
	 * Clé, DANS l'option de réglages, du registre des migrations déjà appliquées.
	 *
	 * Volontairement HORS de `defauts()` : ce n'est pas un réglage d'utilisateur.
	 * `ImagesLegeres_Admin::enregistrer_reglages()` reconstruit le tableau posté en
	 * parcourant `defauts()` ; la clé y figurerait qu'elle serait remise à vide à chaque
	 * enregistrement des réglages, et toutes les migrations se rejoueraient. Elle survit
	 * par le `wp_parse_args( $reglages, self::tout() )` de `enregistrer()`.
	 */
	const CLE_MIGRATIONS = 'migrations_appliquees';

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
	 * Migrations de réglages, par nom, dans l'ordre d'application.
	 *
	 * Pourquoi par nom et pas par numéro de version de l'extension : la mise à jour du
	 * 2026-11-15 en production portait le même `0.1.0` avant et après (5723b6b → 8c3af92).
	 * `ImagesLegeres_Plugin::verifier_version()` comparait deux chaînes identiques, ne
	 * déclenchait rien, et le seuil de gain est resté à 10 % — il a fallu le migrer à la
	 * main (I1b). Une mise à jour déposée par Git ou par archive ne change pas forcément le
	 * numéro de version : s'y fier, c'est parier sur la discipline de celui qui publie.
	 *
	 * Le registre des migrations déjà appliquées vit DANS l'option de réglages, sous la clé
	 * `migrations_appliquees`, pas dans une option à part : « Tout rendre » supprime
	 * l'option de réglages, et une option témoin séparée ressortirait en orphelin au
	 * contrôle `wp images-legeres orphelins`.
	 *
	 * Chaque migration reçoit le tableau des réglages enregistrés et rend le tableau
	 * modifié. Elle doit être idempotente : elle peut être rejouée sur un site où son effet
	 * est déjà acquis (option restaurée depuis une sauvegarde, par exemple).
	 *
	 * @return array<string, callable>
	 */
	public static function migrations() {
		return array(
			'gain-minimal-zero-2026-11-15' => array( __CLASS__, 'migration_gain_minimal_zero' ),
		);
	}

	/**
	 * Décision de Marius du 2026-11-15 : tout doit être en WebP. Le seuil de gain hérité
	 * (10 %) est ce qui a laissé 87 sous-tailles sans frère .webp en production.
	 *
	 * @param array $reglages Réglages enregistrés.
	 * @return array
	 */
	public static function migration_gain_minimal_zero( array $reglages ) {
		if ( isset( $reglages['gain_minimal_pourcent'] ) ) {
			$reglages['gain_minimal_pourcent'] = 0;
		}

		return $reglages;
	}

	/**
	 * Applique les migrations que ce site n'a pas encore vues, et les inscrit.
	 *
	 * Appelée à chaque chargement de l'extension (`ImagesLegeres_Plugin::demarrer`) et à
	 * l'activation. Le coût d'une requête où il n'y a rien à faire est nul : l'option de
	 * réglages est autoloadée — déjà en cache mémoire — et le reste est un `in_array` sur un
	 * tableau de quelques noms. Aucune écriture tant qu'aucune migration ne manque.
	 *
	 * Site jamais installé (option absente) : rien n'est écrit ici, c'est
	 * `initialiser_defauts()` qui pose les défauts et marque toutes les migrations comme
	 * acquises — des défauts neufs n'ont rien à migrer.
	 *
	 * @return string[] Noms des migrations appliquées lors de cet appel (vide si aucune).
	 */
	public static function migrer() {
		$enregistres = get_option( self::OPTION, false );

		if ( ! is_array( $enregistres ) ) {
			return array();
		}

		$deja = isset( $enregistres[ self::CLE_MIGRATIONS ] ) && is_array( $enregistres[ self::CLE_MIGRATIONS ] )
			? $enregistres[ self::CLE_MIGRATIONS ]
			: array();

		$appliquees = array();

		foreach ( self::migrations() as $nom => $rappel ) {
			if ( in_array( $nom, $deja, true ) ) {
				continue;
			}

			$enregistres  = call_user_func( $rappel, $enregistres );
			$deja[]       = $nom;
			$appliquees[] = $nom;
		}

		if ( empty( $appliquees ) ) {
			return array();
		}

		$enregistres[ self::CLE_MIGRATIONS ] = array_values( $deja );
		update_option( self::OPTION, $enregistres );

		return $appliquees;
	}

	public static function initialiser_defauts() {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}

		// Les défauts sont déjà à jour : marquer toutes les migrations comme acquises évite
		// qu'elles se rejouent sur une installation neuve.
		$defauts                          = self::defauts();
		$defauts[ self::CLE_MIGRATIONS ]  = array_keys( self::migrations() );

		update_option( self::OPTION, $defauts );
	}
}
