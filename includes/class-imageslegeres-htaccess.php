<?php
/**
 * Mode .htaccess optionnel (négociation Accept côté Apache) — désactivé par défaut
 * (ADR 0001, point 4). Recours pour le CSS Divi en cache non couvert par le tampon
 * de sortie (spec § 8). N'écrit jamais en dehors du bloc balisé qui lui appartient.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Htaccess {

	const DEBUT = '# BEGIN ImagesLegeres';
	const FIN   = '# END ImagesLegeres';

	public static function appliquer( $actif ) {
		$chemin = self::chemin();

		if ( ! $chemin ) {
			return false;
		}

		$actuel  = file_exists( $chemin ) ? (string) file_get_contents( $chemin ) : '';
		$nettoye = self::retirer_bloc( $actuel );

		if ( $actif ) {
			$nettoye = rtrim( $nettoye ) . "\n" . self::bloc() . "\n";
		}

		return (bool) file_put_contents( $chemin, $nettoye );
	}

	private static function chemin() {
		$upload_dir = wp_upload_dir();

		if ( empty( $upload_dir['basedir'] ) ) {
			return false;
		}

		return untrailingslashit( $upload_dir['basedir'] ) . '/.htaccess';
	}

	private static function retirer_bloc( $contenu ) {
		$motif = '#' . preg_quote( self::DEBUT, '#' ) . '.*?' . preg_quote( self::FIN, '#' ) . '\n?#s';

		return (string) preg_replace( $motif, '', $contenu );
	}

	private static function bloc() {
		return self::DEBUT . "\n" .
			"<IfModule mod_rewrite.c>\n" .
			"RewriteEngine On\n" .
			"RewriteCond %{HTTP_ACCEPT} image/webp\n" .
			"RewriteCond %{REQUEST_FILENAME}.webp -f\n" .
			"RewriteRule ^(.+)\\.(jpe?g|png)$ $1.$2.webp [T=image/webp,L]\n" .
			"</IfModule>\n" .
			"<IfModule mod_headers.c>\n" .
			"<FilesMatch \"\\.(jpe?g|png)$\">\n" .
			"Header append Vary Accept\n" .
			"</FilesMatch>\n" .
			"</IfModule>\n" .
			self::FIN;
	}
}
