<?php
/**
 * Sous-commande `wp images-legeres qualite` : mesure la perte entre une image et son WebP
 * (spec § 7). Sert à la recette sur un site réel — c'est la seule façon de répondre
 * « la perte est-elle invisible ici ? » sur les vraies photos du site plutôt que sur des
 * témoins de laboratoire.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Cli_Qualite {

	/**
	 * Mesure SSIM et PSNR entre l'original de chaque pièce jointe et son WebP.
	 *
	 * Sans `--qualite`, mesure le WebP réellement présent sur le disque. Avec `--qualite`,
	 * réencode dans un fichier temporaire à la qualité demandée et mesure celui-là, sans rien
	 * écrire à côté de l'image : c'est la voie pour essayer un réglage avant de l'appliquer.
	 *
	 * La comparaison se fait toujours à dimensions égales (cf. ImagesLegeres_Qualite) : sans
	 * cela, une retaille préalable serait comptée comme une perte de compression.
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : Identifiants de pièces jointes.
	 *
	 * [--qualite=<n>]
	 * : Réencode à cette qualité WebP au lieu de mesurer le fichier en place.
	 *
	 * [--format=<format>]
	 * : table, json, yaml ou csv.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXEMPLES
	 *
	 *     wp images-legeres qualite 12 13 14
	 *     wp images-legeres qualite 12 --qualite=95 --format=json
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$qualite_demandee = \WP_CLI\Utils\get_flag_value( $assoc_args, 'qualite', null );
		$lignes           = array();
		$echecs           = 0;

		foreach ( $args as $id_brut ) {
			$id = (int) $id_brut;

			if ( $id <= 0 ) {
				WP_CLI::warning( sprintf( 'identifiant invalide ignoré : %s', $id_brut ) );
				++$echecs;
				continue;
			}

			if ( null === $qualite_demandee ) {
				$mesure = ImagesLegeres_Qualite::mesurer_attachment( $id );
			} else {
				$chemin = get_attached_file( $id );

				if ( ! $chemin ) {
					$mesure = new WP_Error( 'images_legeres_attachment_absent', sprintf( 'pièce jointe %d : fichier introuvable', $id ) );
				} else {
					$mesure = ImagesLegeres_Qualite::mesurer_a_qualite( $chemin, (int) $qualite_demandee );

					if ( ! is_wp_error( $mesure ) ) {
						$mesure['attachment_id'] = $id;
						$mesure['produit']       = ImagesLegeres_Moteur::est_image_produit( $id );
					}
				}
			}

			if ( is_wp_error( $mesure ) ) {
				WP_CLI::warning( $mesure->get_error_message() );
				++$echecs;
				continue;
			}

			$seuil = ImagesLegeres_Qualite::seuil_ssim( ! empty( $mesure['produit'] ) );

			$lignes[] = array(
				'id'             => $mesure['attachment_id'],
				'produit'        => ! empty( $mesure['produit'] ) ? 'oui' : 'non',
				'qualite'        => $mesure['qualite'],
				'dimensions'     => $mesure['largeur_webp'] . 'x' . $mesure['hauteur_webp'],
				'source_retaillee' => $mesure['retaille_normalisee'] ? 'oui' : 'non',
				'ssim'           => number_format( $mesure['ssim'], 5, ',', ' ' ),
				'seuil_ssim'     => number_format( $seuil, 2, ',', ' ' ),
				'psnr_db'        => is_finite( $mesure['psnr'] ) ? number_format( $mesure['psnr'], 2, ',', ' ' ) : 'infini',
				'poids_source'   => $mesure['poids_source'],
				'poids_webp'     => $mesure['poids_webp'],
				'gain_pourcent'  => number_format( $mesure['gain_pourcent'], 1, ',', ' ' ),
				'verdict'        => $mesure['ssim'] >= $seuil ? 'tenu' : 'sous le seuil',
			);
		}

		if ( empty( $lignes ) ) {
			WP_CLI::error( 'aucune mesure : vérifie les identifiants donnés.' );
		}

		\WP_CLI\Utils\format_items(
			\WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			$lignes,
			array( 'id', 'produit', 'qualite', 'dimensions', 'source_retaillee', 'ssim', 'seuil_ssim', 'psnr_db', 'poids_source', 'poids_webp', 'gain_pourcent', 'verdict' )
		);

		if ( $echecs > 0 ) {
			WP_CLI::warning( sprintf( '%d pièce(s) jointe(s) non mesurée(s).', $echecs ) );
		}
	}
}
