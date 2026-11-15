<?php
/**
 * Activation / désactivation (niveaux 1 des trois niveaux, ADR 0004 §7).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Activation {

	const OPTION_AUTRE_EDITEUR = 'images_legeres_autre_editeur';

	const EDITEURS_NATIFS = array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' );

	public static function activer() {
		ImagesLegeres_Protection::poser_toutes();
		ImagesLegeres_Table::creer();
		ImagesLegeres_File::creer();
		ImagesLegeres_Reglages::initialiser_defauts();
		update_option( 'images_legeres_version', IMAGES_LEGERES_VERSION );

		$autre_editeur = self::detecter_autre_editeur();
		update_option( self::OPTION_AUTRE_EDITEUR, $autre_editeur );

		if ( ! empty( $autre_editeur ) ) {
			ImagesLegeres_Journal::ecrire(
				'wp_image_editors',
				'avertissement',
				0,
				0,
				0,
				'éditeur(s) d\'image déjà branché(s) avant Images légères : ' . implode( ', ', $autre_editeur )
			);
		}
	}

	/**
	 * La réécriture s'arrête, le site revient à ses images d'origine en moins d'une minute :
	 * rien n'est supprimé sur le disque ni en base, c'est le réglage « réécriture à la sortie »
	 * qui cesse d'être évalué puisque le plugin n'est plus chargé (ADR 0004 §7). Les actions
	 * Action Scheduler déjà planifiées sont annulées : une fois le plugin désactivé, sa classe
	 * de traitement ne se recharge plus, une action orpheline échouerait au prochain passage
	 * de la file d'Action Scheduler.
	 */
	public static function desactiver() {
		ImagesLegeres_Lot::annuler_planifications();
		update_option( ImagesLegeres_Lot::OPTION_ETAT, 'arret' );
	}

	/**
	 * Détecte, à l'activation, si un autre plugin filtre déjà `wp_image_editors` pour
	 * ajouter ou remplacer un éditeur (ADR 0003 point 5, repris par l'ADR 0005 point 6).
	 * Jamais bloquant : un avertissement tracé au journal et affiché dans l'écran État
	 * (spec § 5) — l'activation continue dans tous les cas.
	 *
	 * @return string[] Classes inattendues trouvées (vide si aucune).
	 */
	public static function detecter_autre_editeur() {
		$resultat = apply_filters( 'wp_image_editors', self::EDITEURS_NATIFS );

		return array_values( array_diff( (array) $resultat, self::EDITEURS_NATIFS, array( 'ImagesLegeres_Editor' ) ) );
	}
}
