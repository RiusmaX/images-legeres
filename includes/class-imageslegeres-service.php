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

	/** Plus grande harmonisée par pièce jointe présente dans la page (zoom, visionneuse). */
	private static $plus_grandes = array();

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

		$attachments = array();

		foreach ( self::$harmonisees as $ligne ) {
			$attachments[] = (int) $ligne['attachment'];
		}

		self::$plus_grandes = ImagesLegeres_Harmonisees::plus_grandes_par_attachment( $attachments );

		// Le lien parent entre dans le motif : la galerie produit de WooCommerce y range
		// l'adresse de la grande image (repli de PhotoSwipe), que la passe doit aligner sur
		// le carré affiché.
		$reecrit = preg_replace_callback(
			'#(?:<a\b[^>]*>\s*)?<img\b[^>]*>#i',
			function ( $correspondance ) use ( $baseurl, $basedir ) {
				return self::harmoniser_bloc( $correspondance[0], $baseurl, $basedir );
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

		return self::variante( self::$harmonisees[ $relatif ], $basedir, $baseurl );
	}

	/**
	 * Variante servable d'une ligne d'inventaire, ou null si son chemin ne passe pas la
	 * garde.
	 */
	private static function variante( array $ligne, $basedir, $baseurl ) {
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
			'attachment' => (int) $ligne['attachment'],
			'adresse'    => $baseurl . '/' . $chemin,
			'chemin'     => $chemin,
			'largeur'    => (int) $ligne['largeur'],
			'hauteur'    => (int) $ligne['hauteur'],
		);
	}

	/** Plus grande harmonisée d'une pièce jointe, revalidée comme toutes les autres. */
	private static function plus_grande_de_attachment( $attachment_id, $baseurl, $basedir ) {
		$attachment_id = (int) $attachment_id;

		if ( empty( self::$plus_grandes[ $attachment_id ] ) ) {
			return null;
		}

		return self::variante( self::$plus_grandes[ $attachment_id ], $basedir, $baseurl );
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

	private static function harmoniser_balise( $balise, $baseurl, $basedir, $lien = '' ) {
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
				// Aucune harmonisée pour cette image : ni l'affichage ni le zoom ne
				// bougent. C'est la garde qui interdit le mélange carré affiché /
				// rectangle zoomé — on sert l'original, cohérent avec lui-même.
				return $lien . $balise;
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

		$candidats                      = $gardees;
		$candidats[ $cible['adresse'] ] = $cible;

		list( $balise, $lien ) = self::aligner_zoom( $balise, $lien, $candidats, $baseurl, $basedir );

		return $lien . $balise;
	}

	/**
	 * Aiguille un bloc « lien de galerie + image » vers la réécriture de l'image, en lui
	 * passant le lien parent ; une image seule passe sans lien.
	 */
	private static function harmoniser_bloc( $bloc, $baseurl, $basedir ) {
		if ( preg_match( '#^(<a\b[^>]*>\s*)(<img\b[^>]*>)$#i', $bloc, $parties ) ) {
			return self::harmoniser_balise( $parties[2], $baseurl, $basedir, $parties[1] );
		}

		return self::harmoniser_balise( $bloc, $baseurl, $basedir );
	}

	/**
	 * Aligne la grande image du zoom et de la visionneuse sur la plus grande harmonisée
	 * disponible pour cette image, et le lien parent avec elle.
	 *
	 * La galerie produit de WooCommerce ne lit pas le `src` pour agrandir : jQuery Zoom lit
	 * `data-src`, PhotoSwipe lit `data-large_image` et ses dimensions déclarées, et retombe
	 * sur le `href` du lien parent. Tant que ces quatre valeurs restaient sur l'original,
	 * l'affichage montrait le carré harmonisé et l'agrandissement le rectangle entier —
	 * cadrages différents, zoom qui ne retombe pas sur le point touché (constat du
	 * 2026-11-16 sur la fiche Vanille : carré 1024x1024 affiché, zoom sur 1440x1920).
	 */
	private static function aligner_zoom( $balise, $lien, array $candidats, $baseurl, $basedir ) {
		$data_src   = self::attribut( $balise, 'data-src' );
		$data_large = self::attribut( $balise, 'data-large_image' );

		foreach ( array( $data_src, $data_large ) as $adresse ) {
			if ( '' === $adresse ) {
				continue;
			}

			// Même garde que partout ailleurs : chemin revalidé sous uploads (F6), aucune
			// adresse inventée. Sans harmonisée, le candidat ne rentre pas.
			$variante = self::harmonisee( $adresse, $baseurl, $basedir );

			if ( $variante ) {
				$candidats[ $variante['adresse'] ] = $variante;
			}
		}

		// La plus grande harmonisée de la pièce jointe elle-même : une balise de galerie
		// peut n'avoir ni `srcset` ni adresse de grande taille, et le zoom serait alors
		// servi plus petit que l'original qu'il remplace.
		foreach ( $candidats as $candidat ) {
			$variante = self::plus_grande_de_attachment( $candidat['attachment'], $baseurl, $basedir );

			if ( $variante ) {
				$candidats[ $variante['adresse'] ] = $variante;
			}
		}

		$grande = self::plus_large( $candidats );

		if ( null === $grande ) {
			return array( $balise, $lien );
		}

		list( $largeur, $hauteur ) = self::dimensions_reelles(
			trailingslashit( $basedir ) . $grande['chemin'],
			array( $grande['largeur'], $grande['hauteur'] )
		);

		$balise = self::remplacer_attribut( $balise, 'data-src', $grande['adresse'] );
		$balise = self::remplacer_attribut( $balise, 'data-large_image', $grande['adresse'] );
		$balise = self::remplacer_attribut( $balise, 'data-large_image_width', (string) $largeur );
		$balise = self::remplacer_attribut( $balise, 'data-large_image_height', (string) $hauteur );

		// Le `href` du lien parent ne se réécrit que s'il portait bien la grande image de
		// CETTE balise : un lien quelconque autour d'une image produit (fiche, catégorie)
		// ne doit jamais devenir un lien vers un fichier d'image.
		if ( '' !== $lien ) {
			$href = self::attribut( $lien, 'href' );

			if ( '' !== $href && ( $href === $data_large || $href === $data_src ) ) {
				$lien = self::remplacer_attribut( $lien, 'href', $grande['adresse'] );
			}
		}

		return array( $balise, $lien );
	}

	/** Valeur d'un attribut d'une balise, ou '' quand il est absent. */
	private static function attribut( $balise, $nom ) {
		return preg_match( '#\b' . preg_quote( $nom, '#' ) . '=["\']([^"\']*)["\']#i', $balise, $sous ) ? $sous[1] : '';
	}

	/** Remplace la valeur d'un attribut déjà présent ; n'en ajoute jamais un. */
	private static function remplacer_attribut( $balise, $nom, $valeur ) {
		$motif = '#\b' . preg_quote( $nom, '#' ) . '=["\'][^"\']*["\']#i';

		if ( ! preg_match( $motif, $balise ) ) {
			return $balise;
		}

		$reecrite = preg_replace( $motif, $nom . '="' . esc_attr( $valeur ) . '"', $balise, 1 );

		return null === $reecrite ? $balise : $reecrite;
	}

	/**
	 * Dimensions réelles du fichier servi, lues sur le disque, en cache pour la requête.
	 *
	 * Les colonnes `largeur`/`hauteur` de l'inventaire disent la taille DEMANDÉE à la
	 * génération ; `data-large_image_width`/`height` doivent décrire le fichier réellement
	 * servi, sinon PhotoSwipe calcule son cadre sur des dimensions qui ne sont pas les
	 * siennes. Fichier illisible : on garde les dimensions de l'inventaire.
	 */
	private static function dimensions_reelles( $chemin_absolu, array $defaut ) {
		static $cache = array();

		if ( ! array_key_exists( $chemin_absolu, $cache ) ) {
			$mesure = @getimagesize( $chemin_absolu ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			$cache[ $chemin_absolu ] = ( is_array( $mesure ) && ! empty( $mesure[0] ) && ! empty( $mesure[1] ) )
				? array( (int) $mesure[0], (int) $mesure[1] )
				: null;
		}

		return null === $cache[ $chemin_absolu ] ? $defaut : $cache[ $chemin_absolu ];
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
