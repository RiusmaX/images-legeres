<?php
/**
 * Activation / désactivation (niveaux 1 des trois niveaux, ADR 0004 §7).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Activation {

	public static function activer() {
		ImagesLegeres_Table::creer();
		ImagesLegeres_Reglages::initialiser_defauts();
		update_option( 'images_legeres_version', IMAGES_LEGERES_VERSION );
	}

	/**
	 * La réécriture s'arrête, le site revient à ses images d'origine en moins d'une minute :
	 * rien n'est supprimé sur le disque ni en base, c'est le réglage « réécriture à la sortie »
	 * qui cesse d'être évalué puisque le plugin n'est plus chargé (ADR 0004 §7).
	 */
	public static function desactiver() {
		// Rien à nettoyer au niveau « désactiver » : ni table, ni fichier, ni option supprimés.
	}
}
