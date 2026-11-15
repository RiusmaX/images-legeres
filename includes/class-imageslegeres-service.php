<?php
/**
 * Service de réécriture à la sortie (spec § 3-4, ADR 0001).
 *
 * Deux substitutions, dans cet ordre, sur le HTML produit :
 *
 * 1. **harmonisée** — une adresse de sous-taille native remplacée par la sous-taille
 *    harmonisée de l'extension, quand elle existe (F5) ;
 * 2. **WebP** — une adresse `.jpg`/`.png` remplacée par son frère `.webp`, quand il existe.
 *
 * Les deux passent par le même chemin et donc par la même garde : en mode aperçu, seul un
 * administrateur connecté reçoit la réécriture. C'est la raison d'être de ce point unique —
 * tant que l'harmonisation écrivait sous le nom de fichier natif, elle s'appliquait à tous
 * les visiteurs sans passer par ici, et le mode aperçu ne protégeait plus rien.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Service {

	const DOSSIER_FLUX = 'images-legeres/flux/';

	private static $apercu_restreint_a_l_admin = false;

	/** Substitutions natif => harmonisée pour la requête en cours. */
	private static $harmonisees = array();

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

		$html = self::harmoniser_tampon( $html, $baseurl, $basedir );

		if ( ImagesLegeres_Reglages::valeur( 'mode_compatible_actif' ) ) {
			// Mode compatible (ADR 0001, point 3) : seules les balises <img> reçoivent un
			// repli <picture>, les fonds CSS (style="" et <style>) restent en JPEG/PNG.
			$html_reecrit = self::reecrire_images_mode_compatible( $html, $baseurl, $basedir );
		} else {
			$html_reecrit = preg_replace_callback(
				self::motif_adresses( $baseurl ),
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

	/** Motif des adresses d'uploads en JPEG/PNG. */
	private static function motif_adresses( $baseurl ) {
		return '#' . preg_quote( $baseurl, '#' ) . '/[^\s"\'\)]+\.(?:jpe?g|png)#i';
	}

	/**
	 * Remplace, dans chaque `<img>`, l'adresse d'une sous-taille native par celle de la
	 * sous-taille harmonisée quand l'extension en a produit une.
	 *
	 * Le `srcset` ne garde que les entrées qui ont elles aussi une harmonisée : sans ce
	 * filtrage, le navigateur choisirait tantôt un carré, tantôt le rectangle natif, selon
	 * la largeur d'affichage. `width` et `height` suivent, sinon le navigateur réserve la
	 * place du natif et déforme l'image.
	 */
	private static function harmoniser_tampon( $html, $baseurl, $basedir ) {
		if ( ! ImagesLegeres_Reglages::valeur( 'harmonisation_produit_active' ) ) {
			return $html;
		}

		self::$harmonisees = ImagesLegeres_Harmonisees::map_pour_chemins( self::chemins_relatifs_du_html( $html, $baseurl ) );

		if ( empty( self::$harmonisees ) ) {
			return $html;
		}

		$reecrit = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $correspondance ) use ( $baseurl, $basedir ) {
				return self::harmoniser_balise( $correspondance[0], $baseurl, $basedir );
			},
			$html
		);

		return null === $reecrit ? $html : $reecrit;
	}

	/** Chemins relatifs à uploads de toutes les adresses d'images de la page. */
	private static function chemins_relatifs_du_html( $html, $baseurl ) {
		if ( ! preg_match_all( self::motif_adresses( $baseurl ), $html, $correspondances ) ) {
			return array();
		}

		$relatifs = array();

		foreach ( array_unique( $correspondances[0] ) as $adresse ) {
			$relatif = self::chemin_relatif_adresse( $adresse, $baseurl );

			if ( '' !== $relatif ) {
				$relatifs[] = $relatif;
			}
		}

		return $relatifs;
	}

	/** Chemin relatif à uploads d'une adresse, ou '' si elle n'en vient pas ou sort du dossier. */
	private static function chemin_relatif_adresse( $adresse, $baseurl ) {
		if ( 0 !== strpos( $adresse, $baseurl . '/' ) ) {
			return '';
		}

		$relatif = ltrim( substr( $adresse, strlen( $baseurl ) ), '/' );

		// SEC-5 : une adresse contenant « .. » sortirait du dossier uploads.
		return false === strpos( $relatif, '..' ) ? $relatif : '';
	}

	/** Harmonisée d'une adresse, ou null quand il n'y en a pas. */
	private static function harmonisee( $adresse, $baseurl, $basedir ) {
		$relatif = self::chemin_relatif_adresse( $adresse, $baseurl );

		if ( '' === $relatif || empty( self::$harmonisees[ $relatif ] ) ) {
			return null;
		}

		$ligne  = self::$harmonisees[ $relatif ];
		$chemin = ltrim( (string) $ligne['chemin'], '/' );

		// SEC-F5-1 : le chemin SORTANT vient de la colonne `chemin_harmonise`, pas du HTML.
		// Le servir tel quel, c'est accorder à une valeur stockée la confiance qu'on refuse
		// à l'adresse entrante — asymétrie relevée par l'audit du lot F4+F5, et que la
		// branche WebP voisine (`adresse_frere`) ne commet pas. Même garde qu'elle : refus
		// de « .. » et confinement realpath sous `basedir`, portés par `sous_uploads()`.
		// Un chemin qui ne passe pas ne donne AUCUNE substitution : l'adresse d'origine est
		// servie telle quelle.
		if ( '' === $chemin || ! ImagesLegeres_Moteur::sous_uploads( trailingslashit( $basedir ) . $chemin ) ) {
			return null;
		}

		return array(
			'adresse' => $baseurl . '/' . $chemin,
			'largeur' => (int) $ligne['largeur'],
			'hauteur' => (int) $ligne['hauteur'],
		);
	}

	/** La plus large d'un jeu de variantes harmonisées. */
	private static function plus_large( array $variantes ) {
		$meilleure = null;

		foreach ( $variantes as $variante ) {
			if ( null === $meilleure || $variante['largeur'] > $meilleure['largeur'] ) {
				$meilleure = $variante;
			}
		}

		return $meilleure;
	}

	private static function harmoniser_balise( $balise, $baseurl, $basedir ) {
		$cible = null;

		if ( preg_match( '#\bsrc=["\']([^"\']+)["\']#i', $balise, $sous ) ) {
			$cible = self::harmonisee( $sous[1], $baseurl, $basedir );
		}

		$gardees  = array();
		$a_srcset = (bool) preg_match( '#\bsrcset=["\']([^"\']+)["\']#i', $balise, $ss );

		if ( $a_srcset ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', $ss[1] ) ) ) as $partie ) {
				$segments = preg_split( '#\s+#', $partie, 2 );
				$variante = self::harmonisee( $segments[0], $baseurl, $basedir );

				if ( $variante ) {
					$gardees[ $variante['adresse'] ] = $variante;
				}
			}
		}

		if ( null === $cible ) {
			if ( empty( $gardees ) ) {
				return $balise;
			}

			// Le `src` n'a pas d'harmonisée mais une entrée du `srcset` en a une : on
			// substitue quand même cette entrée. Le `src` (et `width`/`height`) suit la plus
			// large des harmonisées gardées — laisser le natif servirait un rectangle en
			// repli pendant que le `srcset` sert des carrés, et réserverait la place du
			// rectangle : c'est exactement le mélange de rapports que le filtrage du
			// `srcset` existe pour éviter.
			$cible = self::plus_large( $gardees );
		}

		$balise = preg_replace( '#\bsrc=["\'][^"\']*["\']#i', 'src="' . esc_attr( $cible['adresse'] ) . '"', $balise, 1 );

		if ( $a_srcset ) {
			if ( $gardees ) {
				$entrees = array();

				foreach ( $gardees as $variante ) {
					$entrees[] = $variante['adresse'] . ' ' . $variante['largeur'] . 'w';
				}

				$balise = preg_replace( '#\bsrcset=["\'][^"\']*["\']#i', 'srcset="' . esc_attr( implode( ', ', array_unique( $entrees ) ) ) . '"', $balise, 1 );
			} else {
				$balise = preg_replace( '#\s+srcset=["\'][^"\']*["\']#i', '', $balise, 1 );
				$balise = preg_replace( '#\s+sizes=["\'][^"\']*["\']#i', '', $balise, 1 );
			}
		}

		$balise = preg_replace( '#\bwidth=["\'][^"\']*["\']#i', 'width="' . (int) $cible['largeur'] . '"', $balise, 1 );
		$balise = preg_replace( '#\bheight=["\'][^"\']*["\']#i', 'height="' . (int) $cible['hauteur'] . '"', $balise, 1 );

		return $balise;
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
				$balise      = $correspondance[0];
				$srcset_webp = null;

				if ( preg_match( '#\bsrcset=["\']([^"\']+)["\']#i', $balise, $sous ) ) {
					$parties    = array_filter( array_map( 'trim', explode( ',', $sous[1] ) ) );
					$converties = array();

					foreach ( $parties as $partie ) {
						$segments     = preg_split( '#\s+#', $partie, 2 );
						$descripteur  = isset( $segments[1] ) ? ' ' . $segments[1] : '';
						$converties[] = self::adresse_frere( $segments[0], $baseurl, $basedir ) . $descripteur;
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
