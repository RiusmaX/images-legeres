<?php
/**
 * Génère la famille « aplats / dégradés » du corpus de calibrage (spec § 7) : visuels de
 * marque, bandeaux, cartes-cadeaux, fonds de page — des images faites de grandes surfaces
 * unies et de dégradés doux, où WebP n'abîme pas un détail mais fait apparaître des bandes.
 *
 * Ces images-là sont synthétiques par nature (un aplat EST un aplat) : contrairement aux
 * photos, les fabriquer ne fausse rien.
 *
 * Usage : wp eval-file tests/fixtures/generer-aplats.php <dossier-de-sortie>
 */

$dossier = isset( $args[0] ) ? rtrim( $args[0], '/' ) : __DIR__ . '/corpus-f2/aplats';

if ( ! is_dir( $dossier ) ) {
	mkdir( $dossier, 0777, true );
}

/** Dégradé linéaire doux, le cas le plus exposé au bandage. */
function il_aplat_degrade( $largeur, $hauteur, $depart, $fin ) {
	$image = new Imagick();
	$image->newPseudoImage( $largeur, $hauteur, "gradient:$depart-$fin" );

	return $image;
}

/** Dégradé radial : halo de fond de page, bandes concentriques si la compression serre. */
function il_aplat_radial( $largeur, $hauteur, $depart, $fin ) {
	$image = new Imagick();
	$image->newPseudoImage( $largeur, $hauteur, "radial-gradient:$depart-$fin" );

	return $image;
}

/** Aplat uni traversé d'un bandeau contrasté : bords francs sur fond plat. */
function il_aplat_bandeau( $largeur, $hauteur, $fond, $bandeau ) {
	$image = new Imagick();
	$image->newImage( $largeur, $hauteur, new ImagickPixel( $fond ) );
	$bande = new Imagick();
	$bande->newImage( $largeur, (int) round( $hauteur / 4 ), new ImagickPixel( $bandeau ) );
	$image->compositeImage( $bande, Imagick::COMPOSITE_OVER, 0, (int) round( $hauteur * 0.375 ) );
	$bande->destroy();

	return $image;
}

/** Aplat uni + texte : les contours de lettres sur fond plat sont le pire cas du WebP. */
function il_aplat_texte( $largeur, $hauteur, $fond, $encre, $texte ) {
	$image = new Imagick();
	$image->newImage( $largeur, $hauteur, new ImagickPixel( $fond ) );
	$dessin = new ImagickDraw();
	$dessin->setFillColor( new ImagickPixel( $encre ) );
	$dessin->setFontSize( max( 24, (int) round( $hauteur / 8 ) ) );
	$dessin->setGravity( Imagick::GRAVITY_CENTER );
	$image->annotateImage( $dessin, 0, 0, 0, $texte );
	$dessin->destroy();

	return $image;
}

$recettes = array(
	// Dégradés : la cire, l'ambre, le lin — la palette de Monko, en grandes surfaces.
	'aplat-01-degrade-ambre.png'     => il_aplat_degrade( 1600, 900, '#c16e28', '#f4d9b0' ),
	'aplat-02-degrade-lin.png'       => il_aplat_degrade( 1600, 900, '#f4efe6', '#d8cdbb' ),
	'aplat-03-degrade-nuit.png'      => il_aplat_degrade( 1200, 1200, '#1c2a38', '#3a6ea5' ),
	'aplat-04-degrade-vert.png'      => il_aplat_degrade( 1500, 500, '#2f4f3a', '#c0e0de' ),
	'aplat-05-radial-halo.png'       => il_aplat_radial( 1400, 1400, '#fffaf2', '#c16e28' ),
	'aplat-06-radial-sombre.png'     => il_aplat_radial( 1200, 800, '#4b3a2a', '#0d0a07' ),
	// Aplats francs : bandeaux et texte, là où le WebP fait baver les bords.
	'aplat-07-bandeau-promo.png'     => il_aplat_bandeau( 1600, 600, '#f4efe6', '#c16e28' ),
	'aplat-08-bandeau-sombre.png'    => il_aplat_bandeau( 1200, 1200, '#1c2a38', '#f4d9b0' ),
	'aplat-09-texte-clair.png'       => il_aplat_texte( 1400, 700, '#f4efe6', '#1c2a38', "Bougie de soja\nparfum bergamote" ),
	'aplat-10-texte-sombre.png'      => il_aplat_texte( 1000, 1000, '#1c2a38', '#f4d9b0', "-20 %\nsur la collection" ),
);

foreach ( $recettes as $nom => $image ) {
	$image->setImageFormat( 'png' );
	$image->writeImage( "$dossier/$nom" );
	$image->clear();
	$image->destroy();
}

echo 'Famille « aplats » générée dans ' . $dossier . ' (' . count( $recettes ) . " images)\n";
