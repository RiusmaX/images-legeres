<?php
/**
 * Sous-commande `wp images-legeres flux` : interface usine, lecture seule, pour les images
 * de flux Google et Meta (spec § 10, ADR 0006 point 3). Ne dépend pas de la génération
 * effective des fichiers de flux (mission 18) : calcule le côté du carré **disponible**
 * pour chaque image produit à partir du même rectangle de recadrage (ou du même repli
 * « fond ajouté ») que l'affichage, pour signaler en amont les images sous le plancher.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Cli_Flux {

	const PLANCHER_PX = 500;

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
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->posts} produit ON p.post_parent = produit.ID AND produit.post_type = 'product'
			WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$resultats = array();

		foreach ( $ids as $id ) {
			$id        = (int) $id;
			$dimension = $this->cote_disponible( $id );

			if ( null === $dimension || $dimension >= self::PLANCHER_PX ) {
				continue;
			}

			$parent = wp_get_post_parent_id( $id );

			$resultats[] = array(
				'id'                => $id,
				'produit'           => array( 'id' => $parent, 'titre' => get_the_title( $parent ) ),
				'dimension_obtenue' => $dimension,
				'motif'             => sprintf( 'carré disponible de %d px, sous le plancher de %d px', $dimension, self::PLANCHER_PX ),
			);
		}

		WP_CLI::print_value( $resultats, $assoc_args );
	}

	/**
	 * Côté, en pixels, du carré disponible pour l'image de flux de cette pièce jointe :
	 * celui du rectangle de recadrage posé, ou le plus grand côté de l'original (repli
	 * « fond ajouté ») en son absence — même règle que l'affichage (ADR 0005 point 4,
	 * ADR 0006 point 2).
	 */
	private function cote_disponible( $id ) {
		$metadata = wp_get_attachment_metadata( $id );

		if ( empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
			return null;
		}

		$rectangle = ImagesLegeres_Recadrage::obtenir( $id );

		if ( null !== $rectangle ) {
			return (int) round( $rectangle['w'] * (int) $metadata['width'] );
		}

		return max( (int) $metadata['width'], (int) $metadata['height'] );
	}
}
