<?php
/**
 * Image de flux Google et Meta (spec § 3.4, § 6 ; ADR 0006).
 *
 * Produit, pour chaque image de produit, un fichier JPEG carré dédié hors du système de
 * sous-tailles WordPress (`uploads/images-legeres/flux/<attachment_id>-<empreinte>.jpg`),
 * dont le nom change avec le rectangle de recadrage ou les réglages de flux — c'est ce qui
 * fait reprendre l'image par Meta (ADR 0006 point 1). Réutilise les mêmes opérations Imagick
 * que l'éditeur de sous-tailles (recadrage exact ou fond ajouté, mission 15) : la même
 * décision photo par photo s'applique, ici appliquée à une cible toujours carrée.
 *
 * Branchement des flux Google Listings and Ads (filtre dédié `woocommerce_gla_product_attribute_values`)
 * et Facebook for WooCommerce (aucun filtre dédié trouvé dans la version 3.7.8 : repli documenté
 * par drapeau posé/retiré autour de la génération du flux, ADR 0006 point 4). Aucune des deux
 * extensions n'est modifiée.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Flux {

	const DOSSIER       = 'images-legeres/flux/';
	const PLANCHER_PX   = 500;
	const META_FLUX     = '_images_legeres_flux';

	/**
	 * Nom de taille rendu à Facebook for WooCommerce par `armer_drapeau_meta()`. C'est lui
	 * qui borne le détournement (SEC-3) : `get_all_image_urls()` repasse cette valeur à
	 * chacun de ses `wp_get_attachment_image_url()` — image principale, image du parent
	 * d'une variation, images multiples — et à eux seuls. Toute autre résolution d'image de
	 * la même requête (thème, écran d'administration) demande une taille normale et n'est
	 * donc pas détournée.
	 */
	const TAILLE_META = 'images-legeres-flux';

	/** Drapeau posé/retiré autour de la génération du flux Meta (voir en-tête de fichier). */
	private static $drapeau_meta = false;

	public static function enregistrer() {
		add_filter( 'woocommerce_gla_product_attribute_values', array( __CLASS__, 'filtrer_gla' ), 10, 3 );

		add_filter( 'facebook_for_woocommerce_fb_product_image_size', array( __CLASS__, 'armer_drapeau_meta' ) );
		add_action( 'wc_facebook_feed_generation_completed', array( __CLASS__, 'desarmer_drapeau_meta' ) );
	}

	/**
	 * Rend true si le branchement Google est réglé actif (flux général + ce flux).
	 */
	public static function google_actif() {
		return (bool) ImagesLegeres_Reglages::valeur( 'image_flux_active' ) && (bool) ImagesLegeres_Reglages::valeur( 'flux_google_actif' );
	}

	/**
	 * Rend true si le branchement Meta est réglé actif (flux général + ce flux).
	 */
	public static function meta_actif() {
		return (bool) ImagesLegeres_Reglages::valeur( 'image_flux_active' ) && (bool) ImagesLegeres_Reglages::valeur( 'flux_meta_actif' );
	}

	/**
	 * Filtre `woocommerce_gla_product_attribute_values` (dédié, voir en-tête de fichier) :
	 * remplace l'image principale et les images de galerie par leur adresse de flux quand
	 * elle existe, laisse l'adresse d'origine pour les autres (sous le plancher ou sans flux
	 * produit encore).
	 */
	public static function filtrer_gla( $attributs, $wc_product, $adapter ) {
		if ( ! self::google_actif() || ! $wc_product instanceof WC_Product ) {
			return $attributs;
		}

		$image_id = $wc_product->get_image_id();

		if ( $image_id ) {
			$attributs['imageLink'] = self::url_ou_origine( $image_id );
		}

		$gallery_image_ids = $wc_product->get_gallery_image_ids() ?: array();

		if ( ! empty( $gallery_image_ids ) ) {
			$attributs['additionalImageLinks'] = array_values(
				array_unique(
					array_map(
						function ( $id ) {
							return self::url_ou_origine( $id );
						},
						$gallery_image_ids
					)
				)
			);
		}

		return $attributs;
	}

	/**
	 * Armé pendant toute la génération du flux Meta (filtre appelé une fois par produit, au
	 * tout début de la résolution de ses images, `fbproduct.php::get_all_image_urls()`).
	 */
	public static function armer_drapeau_meta( $taille ) {
		if ( ! self::meta_actif() ) {
			return $taille;
		}

		self::$drapeau_meta = true;

		// Le filtre n'est jamais branché globalement (SEC-3) : il n'existe qu'entre
		// l'armement et le désarmement, et il est retiré du crochet au désarmement.
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filtrer_image_src_meta' ), 10, 4 );

		return self::TAILLE_META;
	}

	public static function desarmer_drapeau_meta() {
		self::$drapeau_meta = false;

		remove_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filtrer_image_src_meta' ), 10 );
	}

	/**
	 * Filtre `wp_get_attachment_image_src`, actif uniquement entre l'armement et le
	 * désarmement du drapeau Meta — jamais globalement (ADR 0006 point 4). Remplace
	 * l'adresse (et les dimensions) par celles du fichier de flux quand il existe.
	 */
	public static function filtrer_image_src_meta( $image, $attachment_id, $size, $icon ) {
		if ( ! self::$drapeau_meta || ! self::meta_actif() ) {
			return $image;
		}

		// SEC-3 : le détournement ne vaut que pour le jeton de taille rendu à Meta par
		// `armer_drapeau_meta()`. Toute autre taille désarme ET sort intacte — la version
		// précédente désarmait mais détournait quand même cette résolution-là, si bien qu'une
		// vignette de thème partait en pleine dimension du flux avec des dimensions
		// déclarées fausses.
		if ( self::TAILLE_META !== $size ) {
			self::desarmer_drapeau_meta();

			return $image;
		}

		if ( ! $image ) {
			return $image;
		}

		$flux = self::flux_existant( $attachment_id );

		if ( ! $flux ) {
			return $image;
		}

		$image[0] = $flux['url'];
		$image[1] = $flux['dimension'];
		$image[2] = $flux['dimension'];

		return $image;
	}

	/**
	 * Rend l'adresse de flux de la pièce jointe si un fichier existe, l'adresse d'origine
	 * sinon (image sous le plancher, ou pas encore de flux produit pour elle).
	 */
	public static function url_ou_origine( $attachment_id ) {
		$flux = self::flux_existant( $attachment_id );

		return $flux ? $flux['url'] : wp_get_attachment_image_url( $attachment_id, 'full', false );
	}

	/**
	 * Rend le fichier de flux actuellement sur disque pour cette pièce jointe (chemin, url,
	 * dimension), à partir de la méta posée par `generer()`, ou null s'il n'y en a pas.
	 */
	public static function flux_existant( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META_FLUX, true );

		if ( ! is_array( $meta ) || empty( $meta['fichier'] ) ) {
			return null;
		}

		$chemin = self::dossier_absolu() . $meta['fichier'];

		if ( ! file_exists( $chemin ) ) {
			return null;
		}

		try {
			$taille = getimagesize( $chemin );
		} catch ( Exception $erreur ) {
			return null;
		}

		return array(
			'chemin'    => $chemin,
			'url'       => self::dossier_url() . $meta['fichier'],
			'empreinte' => isset( $meta['empreinte'] ) ? $meta['empreinte'] : '',
			'dimension' => $taille ? (int) $taille[0] : 0,
		);
	}

	public static function dossier_absolu() {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::DOSSIER;
	}

	public static function dossier_url() {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['baseurl'] ) . self::DOSSIER;
	}

	/**
	 * Condensat du rectangle de recadrage (ou de la marque « fond assumé ») et des réglages
	 * de flux courants : changer l'un ou l'autre change l'empreinte, donc le nom de fichier
	 * (ADR 0006 point 1).
	 */
	public static function empreinte( $attachment_id, $dimension, $qualite ) {
		$rectangle = ImagesLegeres_Recadrage::obtenir( $attachment_id );

		$base = array(
			'rectangle' => $rectangle,
			'fond'      => null === $rectangle ? 'fond_assume' : null,
			'dimension' => (int) $dimension,
			'qualite'   => (int) $qualite,
		);

		return substr( sha1( wp_json_encode( $base ) ), 0, 12 );
	}

	/**
	 * Rend le côté réel (en pixels, sans agrandissement) du carré atteignable pour cette
	 * image : la petite dimension du rectangle recadré s'il y en a un, ou la plus grande
	 * dimension de l'original sinon (c'est elle que le fond ajouté comble sans agrandir).
	 */
	public static function cote_reel( $attachment_id, $largeur, $hauteur ) {
		$rectangle = ImagesLegeres_Recadrage::obtenir( $attachment_id );

		if ( $rectangle ) {
			$boite = ImagesLegeres_Editor::calculer_boite_recadrage( $rectangle, $largeur, $hauteur );

			return (int) min( $boite['largeur'], $boite['hauteur'] );
		}

		return (int) max( $largeur, $hauteur );
	}

	/**
	 * Génère (ou régénère) le fichier de flux d'une pièce jointe produit. Idempotent :
	 * l'empreinte courante donne toujours le même nom de fichier, donc n'écrit rien de
	 * nouveau si le fichier existe déjà. Supprime tout autre fichier de flux de la même
	 * pièce jointe (empreinte précédente).
	 */
	public static function generer( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return array( 'etat' => 'erreur', 'motif' => 'identifiant de pièce jointe invalide' );
		}

		if ( ! ImagesLegeres_Moteur::est_image_produit( $attachment_id ) ) {
			return array( 'etat' => 'ignore', 'motif' => 'pas une image de produit' );
		}

		$chemin_original = get_attached_file( $attachment_id );

		if ( ! $chemin_original || ! file_exists( $chemin_original ) ) {
			return array( 'etat' => 'erreur', 'motif' => 'fichier de la pièce jointe introuvable' );
		}

		try {
			$image = new Imagick( $chemin_original );
		} catch ( Exception $erreur ) {
			return array( 'etat' => 'erreur', 'motif' => $erreur->getMessage() );
		}

		$largeur = $image->getImageWidth();
		$hauteur = $image->getImageHeight();
		$cote    = self::cote_reel( $attachment_id, $largeur, $hauteur );

		if ( $cote < self::PLANCHER_PX ) {
			$image->clear();
			$image->destroy();
			self::supprimer( $attachment_id );

			return array(
				'etat'   => 'sous-plancher',
				'motif'  => sprintf( 'carré atteignable de %d px, sous le plancher de %d px', $cote, self::PLANCHER_PX ),
				'cote'   => $cote,
			);
		}

		$reglages      = ImagesLegeres_Reglages::tout();
		$dimension_max = (int) $reglages['dimension_flux'];
		$qualite       = (int) $reglages['qualite_flux'];
		$dimension     = min( $dimension_max, $cote );

		$empreinte       = self::empreinte( $attachment_id, $dimension_max, $qualite );
		$nom_fichier     = $attachment_id . '-' . $empreinte . '.jpg';
		$chemin_fichier  = self::dossier_absolu() . $nom_fichier;

		if ( file_exists( $chemin_fichier ) ) {
			$image->clear();
			$image->destroy();

			return array( 'etat' => 'fait', 'motif' => 'déjà à jour', 'chemin' => $nom_fichier, 'dimension' => $dimension );
		}

		$rectangle = ImagesLegeres_Recadrage::obtenir( $attachment_id );

		try {
			if ( $rectangle ) {
				ImagesLegeres_Editor::appliquer_recadrage( $image, $rectangle, $dimension, $dimension );
			} else {
				ImagesLegeres_Editor::appliquer_fond_ajoute( $image, $dimension, $dimension );
			}

			$image->setImageFormat( 'jpeg' );
			$image->setImageCompressionQuality( $qualite );
			$image->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );

			wp_mkdir_p( self::dossier_absolu() );
			ImagesLegeres_Protection::poser_flux();

			$tmp = self::dossier_absolu() . $nom_fichier . '.tmp-' . wp_generate_password( 8, false, false );
			$image->writeImage( $tmp );
			$image->clear();
			$image->destroy();
		} catch ( Exception $erreur ) {
			if ( isset( $image ) ) {
				$image->clear();
				$image->destroy();
			}

			return array( 'etat' => 'erreur', 'motif' => $erreur->getMessage() );
		}

		if ( ! rename( $tmp, $chemin_fichier ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_delete_file( $tmp );

			return array( 'etat' => 'erreur', 'motif' => 'renommage du fichier de flux impossible' );
		}

		self::supprimer_autres_fichiers( $attachment_id, $nom_fichier );

		update_post_meta(
			$attachment_id,
			self::META_FLUX,
			array(
				'empreinte' => $empreinte,
				'fichier'   => $nom_fichier,
			)
		);

		return array( 'etat' => 'fait', 'motif' => '', 'chemin' => $nom_fichier, 'dimension' => $dimension );
	}

	/**
	 * Supprime le fichier de flux courant d'une pièce jointe (et sa méta) : image repassée
	 * sous le plancher, « tout rendre », ou désinstallation.
	 */
	public static function supprimer( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return;
		}

		self::supprimer_autres_fichiers( $attachment_id, null );
		delete_post_meta( $attachment_id, self::META_FLUX );
	}

	/**
	 * Supprime tout fichier de flux de cette pièce jointe autre que `$fichier_a_garder`
	 * (ou tous, si null) : nettoyage de l'empreinte précédente à la régénération.
	 */
	private static function supprimer_autres_fichiers( $attachment_id, $fichier_a_garder ) {
		$dossier       = self::dossier_absolu();
		// SEC-7 : le motif glob ne doit jamais pouvoir recevoir autre chose qu'un entier —
		// un « * » effacerait tous les fichiers de flux du site.
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 || ! is_dir( $dossier ) ) {
			return;
		}

		// SEC-8 : troisième point de suppression atteint depuis le crochet `delete_attachment`.
		// Le motif glob est déjà borné à un entier (SEC-7) et au dossier de flux, mais la
		// borne explicite coûte un appel et survit à un futur changement de dossier.
		foreach ( glob( $dossier . $attachment_id . '-*.jpg' ) as $chemin ) {
			if ( null === $fichier_a_garder || wp_basename( $chemin ) !== $fichier_a_garder ) {
				wp_delete_file_from_directory( $chemin, $dossier );
			}
		}
	}
}
