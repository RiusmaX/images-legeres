<?php
/**
 * Sous-commande `wp images-legeres flux` : interface usine, lecture seule, pour les images
 * de flux Google et Meta (spec § 10, ADR 0006 point 3). S'appuie sur
 * `ImagesLegeres_Flux::cote_reel()` (mission 18) — le même calcul que la génération
 * effective du fichier de flux, pour ne jamais diverger de ce qui sera réellement produit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Cli_Flux {

	/**
	 * Liste les images produit dont le carré de flux ferait moins de 500 px de côté :
	 * aucun fichier de flux n'est produit pour elles, le flux garde l'adresse d'origine.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : format de sortie.
	 * ---
	 * default: json
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function check( $args, $assoc_args ) {
		$ids = ImagesLegeres_Moteur::ids_images_produit();

		$resultats = array();

		foreach ( $ids as $id ) {
			$id       = (int) $id;
			$metadata = wp_get_attachment_metadata( $id );

			if ( empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
				continue;
			}

			$cote = ImagesLegeres_Flux::cote_reel( $id, (int) $metadata['width'], (int) $metadata['height'] );

			if ( $cote >= ImagesLegeres_Flux::PLANCHER_PX ) {
				continue;
			}

			$parent = ImagesLegeres_Moteur::produit_porteur( $id );

			$resultats[] = array(
				'id'                => $id,
				'produit'           => array( 'id' => $parent, 'titre' => get_the_title( $parent ) ),
				'dimension_obtenue' => $cote,
				'motif'             => sprintf( 'carré atteignable de %d px, sous le plancher de %d px', $cote, ImagesLegeres_Flux::PLANCHER_PX ),
			);
		}

		WP_CLI::print_value( $resultats, $assoc_args );
	}
}
