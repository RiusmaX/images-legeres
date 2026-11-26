<?php
/**
 * Sous-commande `wp images-legeres crop` : interface usine pour le rectangle de recadrage
 * (spec § 10, ADR 0005). Toutes les sous-commandes rendent du JSON et s'arrêtent là : elles
 * ne savent pas qui les lit. Idempotentes ; une entrée invalide est refusée (code de sortie
 * non nul, rien d'écrit) plutôt qu'écrite à moitié.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Cli_Crop {

	/**
	 * Rend le rectangle stocké sur une pièce jointe (ou `null`), et la marque « fond
	 * assumé » éventuelle.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Identifiant de la pièce jointe.
	 *
	 * [--format=<format>]
	 * : format de sortie.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - yaml
	 *   - var_export
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$id = $this->verifier_attachment( $args[0] );

		$donnee = array(
			'attachment_id' => $id,
			'rectangle'     => ImagesLegeres_Recadrage::obtenir( $id ),
			'fond_assume'   => ImagesLegeres_Recadrage::fond_assume( $id ),
		);

		WP_CLI::print_value( $donnee, $assoc_args );
	}

	/**
	 * Pose un rectangle de recadrage, en pixels. Refuse (WP_CLI::error, code de sortie non
	 * nul, rien d'écrit) un rectangle hors image, dont le ratio s'écarte de plus de 1 % du
	 * ratio cible, ou dont le carré résultant ferait moins de 500 px de côté.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Identifiant de la pièce jointe.
	 *
	 * --x=<x>
	 * : Abscisse du coin haut-gauche, en pixels, dans le référentiel de `--ref`.
	 *
	 * --y=<y>
	 * : Ordonnée du coin haut-gauche, en pixels.
	 *
	 * --w=<w>
	 * : Largeur du rectangle, en pixels.
	 *
	 * --h=<h>
	 * : Hauteur du rectangle, en pixels.
	 *
	 * [--ref=<largeurxhauteur>]
	 * : Dimensions de référence (ex. 2560x1920) pour convertir en relatif. Par défaut, les
	 * dimensions actuelles de l'original.
	 *
	 * [--source=<source>]
	 * ---
	 * default: cli
	 * ---
	 *
	 * [--format=<format>]
	 * : format de sortie.
	 * ---
	 * default: json
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function set( $args, $assoc_args ) {
		$id = $this->verifier_attachment( $args[0] );

		foreach ( array( 'x', 'y', 'w', 'h' ) as $cle ) {
			if ( ! isset( $assoc_args[ $cle ] ) ) {
				WP_CLI::error( "--{$cle} manquant" );
			}
		}

		list( $largeur_ref, $hauteur_ref ) = $this->lire_ref( \WP_CLI\Utils\get_flag_value( $assoc_args, 'ref', '' ) );
		$source = \WP_CLI\Utils\get_flag_value( $assoc_args, 'source', 'cli' );

		$resultat = ImagesLegeres_Recadrage::definir(
			$id,
			(float) $assoc_args['x'],
			(float) $assoc_args['y'],
			(float) $assoc_args['w'],
			(float) $assoc_args['h'],
			$largeur_ref,
			$hauteur_ref,
			$source
		);

		if ( is_wp_error( $resultat ) ) {
			WP_CLI::error( $resultat->get_error_message() );
		}

		WP_CLI::print_value(
			array(
				'attachment_id' => $id,
				'statut'        => 'pose',
				'rectangle'     => ImagesLegeres_Recadrage::obtenir( $id ),
			),
			$assoc_args
		);
	}

	/**
	 * Retire le rectangle (et la marque « fond assumé »). Idempotent : sans rectangle, ne
	 * fait rien et rend quand même un statut.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Identifiant de la pièce jointe.
	 *
	 * [--format=<format>]
	 * : format de sortie.
	 * ---
	 * default: json
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function clear( $args, $assoc_args ) {
		$id = $this->verifier_attachment( $args[0] );

		ImagesLegeres_Recadrage::effacer( $id );

		WP_CLI::print_value( array( 'attachment_id' => $id, 'statut' => 'efface' ), $assoc_args );
	}

	/**
	 * Importe un lot de rectangles (ou de marques « fond assumé ») depuis un fichier JSON :
	 * une liste d'objets `{id, x, y, w, h, ref?}` ou `{id, fond_assume: true, motif}`.
	 * Chaque entrée est validée et écrite indépendamment des autres ; une entrée invalide
	 * est refusée seule (rien d'écrit pour elle), le code de sortie est non nul si au moins
	 * une entrée a échoué.
	 *
	 * ## OPTIONS
	 *
	 * <fichier>
	 * : Chemin du fichier JSON.
	 *
	 * [--format=<format>]
	 * : format de sortie.
	 * ---
	 * default: json
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function import( $args, $assoc_args ) {
		$fichier = $args[0];

		if ( ! file_exists( $fichier ) ) {
			WP_CLI::error( "fichier introuvable : {$fichier}" );
		}

		$lignes = json_decode( (string) file_get_contents( $fichier ), true );

		if ( ! is_array( $lignes ) ) {
			WP_CLI::error( 'JSON invalide : une liste est attendue' );
		}

		$resultats = array();
		$echecs    = 0;

		foreach ( $lignes as $ligne ) {
			$resultat = $this->importer_ligne( $ligne );
			$resultats[] = $resultat;

			if ( 'echec' === $resultat['statut'] ) {
				++$echecs;
			}
		}

		WP_CLI::print_value( array( 'resultats' => $resultats, 'echecs' => $echecs ), $assoc_args );

		if ( $echecs > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Liste les images produit hors ratio cible et sans rectangle (état « à recadrer »),
	 * marque « fond assumé » comprise.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : format de sortie.
	 * ---
	 * default: json
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function pending( $args, $assoc_args ) {
		$ids = ImagesLegeres_Moteur::ids_images_produit();

		$resultats = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( ! ImagesLegeres_Recadrage::a_recadrer( $id ) ) {
				continue;
			}

			$metadata = wp_get_attachment_metadata( $id );
			$parent   = ImagesLegeres_Moteur::produit_porteur( $id );
			$largeur  = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
			$hauteur  = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;

			$resultats[] = array(
				'id'      => $id,
				'adresse' => wp_get_attachment_url( $id ),
				'largeur' => $largeur,
				'hauteur' => $hauteur,
				'ratio'   => $hauteur > 0 ? round( $largeur / $hauteur, 4 ) : null,
				'produit' => array(
					'id'    => $parent,
					'titre' => get_the_title( $parent ),
				),
			);
		}

		WP_CLI::print_value( $resultats, $assoc_args );
	}

	private function importer_ligne( $ligne ) {
		$id = isset( $ligne['id'] ) ? (int) $ligne['id'] : 0;

		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return array( 'id' => $id, 'statut' => 'echec', 'motif' => 'pièce jointe introuvable' );
		}

		if ( ! empty( $ligne['fond_assume'] ) ) {
			ImagesLegeres_Recadrage::marquer_fond_assume( $id, isset( $ligne['motif'] ) ? (string) $ligne['motif'] : '', 'import' );

			return array( 'id' => $id, 'statut' => 'fond_assume' );
		}

		foreach ( array( 'x', 'y', 'w', 'h' ) as $cle ) {
			if ( ! isset( $ligne[ $cle ] ) ) {
				return array( 'id' => $id, 'statut' => 'echec', 'motif' => "champ {$cle} manquant" );
			}
		}

		list( $largeur_ref, $hauteur_ref ) = $this->lire_ref( isset( $ligne['ref'] ) ? $ligne['ref'] : '' );

		$resultat = ImagesLegeres_Recadrage::definir(
			$id,
			(float) $ligne['x'],
			(float) $ligne['y'],
			(float) $ligne['w'],
			(float) $ligne['h'],
			$largeur_ref,
			$hauteur_ref,
			'import'
		);

		if ( is_wp_error( $resultat ) ) {
			return array( 'id' => $id, 'statut' => 'echec', 'motif' => $resultat->get_error_message() );
		}

		return array( 'id' => $id, 'statut' => 'pose' );
	}

	/**
	 * Convertit `--ref=LARGEURxHAUTEUR` en paire de nombres, ou `array( null, null )` pour
	 * retomber sur les dimensions actuelles de l'original.
	 */
	private function lire_ref( $brut ) {
		if ( empty( $brut ) ) {
			return array( null, null );
		}

		if ( ! preg_match( '/^(\d+)x(\d+)$/', (string) $brut, $m ) ) {
			WP_CLI::error( '--ref invalide, attendu LARGEURxHAUTEUR (ex. 2560x1920)' );
		}

		return array( (float) $m[1], (float) $m[2] );
	}

	private function verifier_attachment( $id_brut ) {
		$id = (int) $id_brut;

		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			WP_CLI::error( "pièce jointe introuvable : {$id_brut}" );
		}

		return $id;
	}
}
