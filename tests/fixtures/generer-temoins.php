<?php
/**
 * Génère les images témoins du test du moteur (mission 13), dans /tmp/images-legeres-temoins.
 * Images neutres, sans donnée de client — exécuté dans le conteneur de test.
 */

// Dans le dossier du plugin (monté à la fois dans les conteneurs wordpress et cli) : les
// deux doivent voir les mêmes fichiers. Jamais commité (.gitignore).
$dossier = __DIR__ . '/tmp';
if ( ! is_dir( $dossier ) ) {
	mkdir( $dossier, 0777, true );
}

function degrade( $largeur, $hauteur ) {
	$image = new Imagick();
	$image->newImage( $largeur, $hauteur, new ImagickPixel( 'white' ) );
	$degrade = new Imagick();
	$degrade->newPseudoImage( $largeur, $hauteur, 'gradient:#3a6ea5-#c0e0de' );
	return $degrade;
}

// 1. JPEG à 2560 px (au-delà de la dimension maximale par défaut).
$img = degrade( 2560, 2560 );
$img->setImageFormat( 'jpeg' );
$img->writeImage( "$dossier/jpeg-2560.jpg" );
$img->destroy();

// 2. JPEG à 471 px (sous la dimension maximale).
$img = degrade( 471, 471 );
$img->setImageFormat( 'jpeg' );
$img->writeImage( "$dossier/jpeg-471.jpg" );
$img->destroy();

// 3. PNG opaque (photo, pas de transparence).
$img = degrade( 800, 600 );
$img->setImageFormat( 'png' );
$img->writeImage( "$dossier/png-opaque.png" );
$img->destroy();

// 4. PNG transparent — degradé de couleur et degradé alpha (plus réaliste qu'un aplat :
// un aplat sur fond transparent est déjà si petit en PNG qu'il ne peut pas gagner 10 % en
// WebP, ce que le garde-fou de gain rejette à juste titre).
function png_degrade_transparent( $largeur, $hauteur, $couleur_depart, $couleur_fin ) {
	$couleur = new Imagick();
	$couleur->newPseudoImage( $largeur, $hauteur, "gradient:$couleur_depart-$couleur_fin" );
	$alpha = new Imagick();
	$alpha->newPseudoImage( $largeur, $hauteur, 'gradient:black-white' );
	$couleur->compositeImage( $alpha, Imagick::COMPOSITE_COPYOPACITY, 0, 0 );
	$couleur->setImageFormat( 'png' );
	return $couleur;
}

$img = png_degrade_transparent( 800, 600, '#3a6ea5', '#c0e0de' );
$img->writeImage( "$dossier/png-transparent.png" );
$img->destroy();

// 5. WebP déjà converti (doit être ignoré, jamais reconverti).
$img = degrade( 600, 600 );
$img->setImageFormat( 'webp' );
$img->writeImage( "$dossier/webp-deja-converti.webp" );
$img->destroy();

// 6. SVG (jamais touché).
file_put_contents(
	"$dossier/image.svg",
	'<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="200" height="200" fill="#3a6ea5"/></svg>'
);

// 7. GIF animé (jamais touché).
$gif = new Imagick();
foreach ( array( 'red', 'green', 'blue' ) as $couleur ) {
	$frame = new Imagick();
	$frame->newImage( 100, 100, new ImagickPixel( $couleur ) );
	$frame->setImageFormat( 'gif' );
	$frame->setImageDelay( 20 );
	$gif->addImage( $frame );
	$frame->destroy();
}
$gif->setImageFormat( 'gif' );
$gif->writeImages( "$dossier/gif-anime.gif", true );
$gif->destroy();

// 8. GIF statique (une seule image — éligible à la conversion, § 5 : seuls les GIF animés
// sont exclus).
$img = degrade( 500, 500 );
$img->setImageFormat( 'gif' );
$img->writeImage( "$dossier/gif-statique.gif" );
$img->destroy();

// 9. PNG quasi incompressible (bruit fractal) : ne doit pas gagner 10 %, reste "ignore".
$img = new Imagick();
$img->newPseudoImage( 400, 400, 'plasma:fractal' );
$img->setImageFormat( 'png' );
$img->writeImage( "$dossier/png-bruit-incompressible.png" );
$img->destroy();

// 10. JPEG qualité produit (sera attaché à un produit WooCommerce dans le test, qualité 90).
$img = degrade( 1024, 1024 );
$img->setImageFormat( 'jpeg' );
$img->writeImage( "$dossier/jpeg-produit.jpg" );
$img->destroy();

// 11. PNG produit transparent (qualité 90 + transparence conservée en même temps).
$img = png_degrade_transparent( 600, 600, '#c16e28', '#f4d9b0' );
$img->writeImage( "$dossier/png-produit-transparent.png" );
$img->destroy();

// 12. Servira de pièce jointe au fichier manquant (copie de jpeg-471, supprimée du disque
// par le test juste avant l'appel au moteur, pour vérifier l'état "erreur").
copy( "$dossier/jpeg-471.jpg", "$dossier/jpeg-manquant.jpg" );

echo "Témoins générés dans $dossier\n";
