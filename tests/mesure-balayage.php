<?php
/**
 * Balayage de calibrage de la qualité WebP (spec § 7) : pour chaque image d'un corpus et
 * chaque qualité demandée, mesure SSIM, PSNR et poids, et sort un TSV.
 *
 * Mesure la compression SEULE : la comparaison se fait à dimensions égales
 * (ImagesLegeres_Qualite), et la source est l'image telle qu'elle arrive — pas un original
 * d'avant retaille.
 *
 * Usage (depuis l'hôte, avec le PHP du conteneur `wordpress`, seul à avoir le délégué WebP) :
 *   wp eval-file tests/mesure-balayage.php <dossier-du-corpus> <qualites-separees-par-virgule>
 *
 * Exemple :
 *   wp eval-file .../mesure-balayage.php /tmp/corpus 80,85,88,90,92,95,98
 *
 * Le corpus attend un sous-dossier par famille d'images (produit/, ambiance/, aplats/) :
 * le nom du sous-dossier devient la colonne `famille` du TSV.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "à lancer par wp eval-file\n" );
}

$dossier  = isset( $args[0] ) ? rtrim( $args[0], '/' ) : '';
$qualites = isset( $args[1] ) ? array_map( 'intval', explode( ',', $args[1] ) ) : array( 80, 85, 88, 90, 92, 95, 98 );

if ( '' === $dossier || ! is_dir( $dossier ) ) {
	WP_CLI::error( 'premier argument attendu : un dossier de corpus existant.' );
}

$images = array();

foreach ( glob( $dossier . '/*', GLOB_ONLYDIR ) as $sous_dossier ) {
	$famille = basename( $sous_dossier );

	foreach ( glob( $sous_dossier . '/*' ) as $fichier ) {
		if ( is_file( $fichier ) && preg_match( '/\.(jpe?g|png|tiff?)$/i', $fichier ) ) {
			$images[] = array(
				'famille' => $famille,
				'chemin'  => $fichier,
			);
		}
	}
}

if ( empty( $images ) ) {
	WP_CLI::error( sprintf( 'aucune image trouvée dans %s/<famille>/.', $dossier ) );
}

echo "famille\timage\tlargeur\thauteur\tformat_source\tpoids_source\tqualite\tssim\tpsnr_db\tpoids_webp\tgain_pourcent\n";

foreach ( $images as $image ) {
	foreach ( $qualites as $qualite ) {
		$mesure = ImagesLegeres_Qualite::mesurer_a_qualite( $image['chemin'], $qualite );

		if ( is_wp_error( $mesure ) ) {
			WP_CLI::warning( sprintf( '%s @ q%d : %s', basename( $image['chemin'] ), $qualite, $mesure->get_error_message() ) );
			continue;
		}

		printf(
			"%s\t%s\t%d\t%d\t%s\t%d\t%d\t%.5f\t%s\t%d\t%.1f\n",
			$image['famille'],
			basename( $image['chemin'] ),
			$mesure['largeur_source'],
			$mesure['hauteur_source'],
			$mesure['format_source'],
			$mesure['poids_source'],
			$qualite,
			$mesure['ssim'],
			is_finite( $mesure['psnr'] ) ? sprintf( '%.2f', $mesure['psnr'] ) : 'infini',
			$mesure['poids_webp'],
			$mesure['gain_pourcent']
		);
	}
}
