<?php
/**
 * Service de réécriture à la sortie : remplace les adresses d'uploads par leur frère
 * .webp dans le HTML produit, quand le fichier frère existe (spec § 3-4, ADR 0001).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Service {

	const DOSSIER_FLUX = 'images-legeres/flux/';

	private static $apercu_restreint_a_l_admin = false;

	public static function enregistrer() {
		add_action( 'template_redirect', array( __CLASS__, 'demarrer_tampon' ), PHP_INT_MAX );
	}

	public static function demarrer_tampon() {
		if ( ! self::reecriture_active_pour_la_requete() ) {
			return;
		}

		self::$apercu_restreint_a_l_admin = (bool) ImagesLegeres_Reglages::valeur( 'mode_apercu_actif' );

		ob_start( array( __CLASS__, 'reecrire_tampon' ) );
	}

	/**
	 * Mode aperçu actif : seul un administrateur connecté reçoit la réécriture.
	 * Mode aperçu désactivé : réécriture pour tous les visiteurs.
	 */
	public static function reecriture_active_pour_la_requete() {
		if ( ! ImagesLegeres_Reglages::valeur( 'reecriture_sortie_active' ) ) {
			return false;
		}

		if ( ImagesLegeres_Reglages::valeur( 'mode_apercu_actif' ) ) {
			return self::est_administrateur_connecte();
		}

		return true;
	}

	public static function est_administrateur_connecte() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	public static function reecrire_tampon( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return $html;
		}

		$upload_dir = wp_upload_dir();
		$baseurl    = untrailingslashit( $upload_dir['baseurl'] );
		$basedir    = untrailingslashit( $upload_dir['basedir'] );

		if ( ImagesLegeres_Reglages::valeur( 'mode_compatible_actif' ) ) {
			// Mode compatible (ADR 0001, point 3) : seules les balises <img> reçoivent un
			// repli <picture>, les fonds CSS (style="" et <style>) restent en JPEG/PNG.
			$html_reecrit = self::reecrire_images_mode_compatible( $html, $baseurl, $basedir );
		} else {
			$motif        = '#' . preg_quote( $baseurl, '#' ) . '/[^\s"\'\)]+\.(?:jpe?g|png)#i';
			$html_reecrit = preg_replace_callback(
				$motif,
				function ( $correspondance ) use ( $baseurl, $basedir ) {
					return self::adresse_frere( $correspondance[0], $baseurl, $basedir );
				},
				$html
			);
		}

		if ( null === $html_reecrit ) {
			return $html;
		}

		if ( self::$apercu_restreint_a_l_admin && ! headers_sent() ) {
			header( 'Cache-Control: private, no-store' );
		}

		return $html_reecrit;
	}

	/**
	 * Rend l'adresse du frère .webp si le fichier existe sur disque, en excluant
	 * toujours le dossier des images de flux (jamais réécrit, Meta ne lit pas le WebP).
	 */
	private static function adresse_frere( $adresse, $baseurl, $basedir ) {
		$chemin_relatif = substr( $adresse, strlen( $baseurl ) );

		if ( false !== strpos( $chemin_relatif, '/' . self::DOSSIER_FLUX ) ) {
			return $adresse;
		}

		// SEC-5 : une adresse contenant « .. » sortirait du dossier uploads. Le réécriveur
		// ne doit jamais tester l'existence d'un fichier hors de uploads : ce serait un
		// oracle d'existence sur le reste du disque.
		if ( false !== strpos( $chemin_relatif, '..' ) ) {
			return $adresse;
		}

		$chemin_disque = $basedir . $chemin_relatif;
		$chemin_webp   = $chemin_disque . '.webp';

		if ( ! file_exists( $chemin_webp ) ) {
			return $adresse;
		}

		$reel = realpath( $chemin_webp );
		$base = realpath( $basedir );

		if ( false === $reel || false === $base || 0 !== strpos( $reel, trailingslashit( $base ) ) ) {
			return $adresse;
		}

		return $adresse . '.webp';
	}

	/**
	 * Enveloppe chaque <img> dans un <picture> avec un repli WebP (<source>), sans toucher
	 * au reste du HTML : les fonds CSS restent servis en JPEG/PNG (mode compatible).
	 */
	private static function reecrire_images_mode_compatible( $html, $baseurl, $basedir ) {
		return preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $correspondance ) use ( $baseurl, $basedir ) {
				$balise       = $correspondance[0];
				$srcset_webp  = null;

				if ( preg_match( '#\bsrcset=["\']([^"\']+)["\']#i', $balise, $sous ) ) {
					$parties     = array_filter( array_map( 'trim', explode( ',', $sous[1] ) ) );
					$converties  = array();

					foreach ( $parties as $partie ) {
						$segments      = preg_split( '#\s+#', $partie, 2 );
						$descripteur   = isset( $segments[1] ) ? ' ' . $segments[1] : '';
						$converties[]  = self::adresse_frere( $segments[0], $baseurl, $basedir ) . $descripteur;
					}

					$srcset_webp = implode( ', ', $converties );
				} elseif ( preg_match( '#\bsrc=["\']([^"\']+)["\']#i', $balise, $sous ) ) {
					$srcset_webp = self::adresse_frere( $sous[1], $baseurl, $basedir );
				}

				if ( null === $srcset_webp || false === strpos( $srcset_webp, '.webp' ) ) {
					return $balise;
				}

				return '<picture><source type="image/webp" srcset="' . esc_attr( $srcset_webp ) . '" />' . $balise . '</picture>';
			},
			$html
		);
	}
}
