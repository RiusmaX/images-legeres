<?php
/**
 * Un cas par processus (les constantes ne se défont pas) : prépare la requête, passe un
 * contenu témoin par le vrai tampon de sortie et dit s'il en sort intact.
 * Usage : wp eval-file edition.php <cas> <identifiant de la page témoin> [public]
 * « public » : l'utilisateur courant ne peut pas modifier, le paramètre doit être ignoré.
 */

list( $cas, $id_page ) = $args;
$public = isset( $args[2] ) && 'public' === $args[2];

$contenu = get_post( (int) $id_page )->post_content;

$_GET  = array();
$_POST = array();

switch ( $cas ) {
	case 'temoin':
	case 'temoin-preview-false':
		if ( 'temoin-preview-false' === $cas ) {
			$_GET['preview'] = 'false';
		}
		break;
	case 'et_fb':
	case 'app_window':
	case 'et_pb_preview':
	case 'et_pb_preview_nonce':
	case 'et_tb':
	case 'preview_id':
		$_GET[ $cas ] = '1';
		break;
	case 'et_pb_preview-post':
		$_POST['et_pb_preview'] = 'true';
		break;
	case 'preview':
		$_GET['preview'] = 'true';
		break;
	case 'is_admin':
		define( 'WP_ADMIN', true );
		break;
	case 'ajax':
		add_filter( 'wp_doing_ajax', '__return_true' );
		break;
	case 'rest':
		define( 'REST_REQUEST', true );
		break;
	case 'json':
		$_SERVER['HTTP_ACCEPT'] = 'application/json';
		break;
	case 'customizer':
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		$GLOBALS['wp_customize'] = new WP_Customize_Manager();
		$previsualisation        = new ReflectionProperty( 'WP_Customize_Manager', 'previewing' );
		$previsualisation->setAccessible( true );
		$previsualisation->setValue( $GLOBALS['wp_customize'], true );
		break;
	default:
		echo "FAIL - cas inconnu : $cas\n";
		return;
}

ob_start();
ImagesLegeres_Service::demarrer_tampon();
$niveau_tampon = ob_get_level();
echo $contenu;
if ( ob_get_level() === $niveau_tampon && in_array( 'ImagesLegeres_Service::reecrire_tampon', ob_list_handlers(), true ) ) {
	ob_end_flush();
}
$sortie = ob_get_clean();

if ( $public || 0 === strpos( $cas, 'temoin' ) ) {
	$ok = false !== strpos( $sortie, '.jpg.webp' ) && false !== strpos( $sortie, '-harmonisee-temoin.jpg' );
	echo ( $ok ? 'OK  ' : 'FAIL' ) . " - $cas : réécriture WebP et harmonisée présentes\n";
} else {
	$ok = $sortie === $contenu;
	echo ( $ok ? 'OK  ' : 'FAIL' ) . " - $cas : sortie identique à post_content\n";
}

if ( ! $ok ) {
	echo $sortie . "\n";
}
