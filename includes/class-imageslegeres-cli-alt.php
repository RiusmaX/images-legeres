<?php
/**
 * Sous-commande `wp images-legeres alt` : interface usine pour les textes alternatifs
 * (spec § 10). Lecture seule — aucune méthode de cette classe n'écrit jamais
 * `_wp_attachment_image_alt` : l'extension ne fait que signaler, « wp post meta update »
 * (commande native de WordPress) écrit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Cli_Alt {

	/**
	 * Liste les pièces jointes image **affichées** sans texte alternatif : attachées à un
	 * contenu (`post_parent`), posées comme image mise en avant (`_thumbnail_id`), ou
	 * présentes dans une galerie produit WooCommerce (`_product_image_gallery`) — et dont
	 * `_wp_attachment_image_alt` est absente ou vide. Une pièce jointe orpheline (jamais
	 * affichée nulle part) n'est pas listée.
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
	public function missing( $args, $assoc_args ) {
		$resultats = array();

		foreach ( $this->ids_images_affichees() as $id ) {
			$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );

			if ( '' !== trim( (string) $alt ) ) {
				continue;
			}

			$resultats[] = array(
				'id'        => $id,
				'adresse'   => wp_get_attachment_url( $id ),
				'contextes' => $this->contextes( $id ),
			);
		}

		usort(
			$resultats,
			function ( $a, $b ) {
				return $a['id'] <=> $b['id'];
			}
		);

		WP_CLI::print_value( $resultats, $assoc_args );
	}

	/**
	 * Identifiants, triés et dédoublonnés, des pièces jointes image affichées au sens de
	 * cette commande (définition partagée avec la recette, pour que son recoupement SQL
	 * indépendant porte sur la même notion d'« affichée »).
	 */
	private function ids_images_affichees() {
		global $wpdb;

		$ids = array();

		$attachees = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_parent <> 0"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = array_merge( $ids, array_map( 'intval', $attachees ) );

		$vignettes = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids       = array_merge( $ids, array_map( 'intval', $vignettes ) );

		$galeries = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $galeries as $liste ) {
			foreach ( array_filter( explode( ',', (string) $liste ) ) as $id_brut ) {
				$ids[] = (int) $id_brut;
			}
		}

		$ids = array_unique( array_filter( $ids ) );

		return array_values(
			array_filter(
				$ids,
				function ( $id ) {
					return 'attachment' === get_post_type( $id )
						&& 0 === strpos( (string) get_post_mime_type( $id ), 'image/' );
				}
			)
		);
	}

	/**
	 * Où apparaît cette pièce jointe : le contenu auquel elle est attachée, les contenus
	 * qui la portent en image mise en avant, les produits qui la portent en galerie.
	 */
	private function contextes( $id ) {
		global $wpdb;

		$contextes = array();

		$parent_id = (int) get_post_field( 'post_parent', $id );
		if ( $parent_id ) {
			$contextes[] = array( 'type' => get_post_type( $parent_id ), 'id' => $parent_id, 'titre' => get_the_title( $parent_id ) );
		}

		$porteurs_vignette = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s", (string) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $porteurs_vignette as $porteur_id ) {
			$porteur_id = (int) $porteur_id;
			$contextes[] = array( 'type' => get_post_type( $porteur_id ) . ':vignette', 'id' => $porteur_id, 'titre' => get_the_title( $porteur_id ) );
		}

		$galeries = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $galeries as $galerie ) {
			if ( in_array( (string) $id, array_filter( explode( ',', (string) $galerie->meta_value ) ), true ) ) {
				$contextes[] = array( 'type' => 'product:galerie', 'id' => (int) $galerie->post_id, 'titre' => get_the_title( $galerie->post_id ) );
			}
		}

		return $contextes;
	}
}
