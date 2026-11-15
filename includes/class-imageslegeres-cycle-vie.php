<?php
/**
 * Cycle de vie des fichiers produits par l'extension (SEC-1).
 *
 * WordPress n'a aucun moyen de connaître les fichiers qu'Images légères écrit : ils ne sont
 * ni des sous-tailles enregistrées, ni des pièces jointes. Sans ce branchement, supprimer
 * définitivement une photo de la médiathèque efface l'original et ses sous-tailles mais
 * laisse en ligne les frères `.webp`, la copie pleine résolution de `images-legeres/originaux/`
 * et l'image de flux — à des adresses dérivables de l'adresse d'origine, qui a été publique.
 *
 * Branché sur `delete_attachment`, qui part **avant** l'effacement par WordPress : les
 * métadonnées et `get_attached_file()` répondent donc encore, ce qui permet de retrouver
 * chaque sous-taille.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Cycle_Vie {

	public static function enregistrer() {
		add_action( 'delete_attachment', array( __CLASS__, 'supprimer_fichiers_produits' ) );
	}

	/**
	 * Supprime tout ce que l'extension a produit pour cette pièce jointe, et ses lignes
	 * d'état. Rend la liste des chemins absolus effectivement supprimés (utile aux tests et
	 * à la trace).
	 *
	 * @param int $attachment_id Pièce jointe en cours de suppression.
	 * @return string[] Chemins supprimés.
	 */
	public static function supprimer_fichiers_produits( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return array();
		}

		$supprimes = array();

		// SEC-8 : tous les chemins supprimés ici sont construits à partir de métadonnées de
		// la pièce jointe. Core fait la même boucle dans `wp_delete_attachment()` et la borne
		// avec `wp_delete_file_from_directory()`, qui résout le realpath() et refuse hors du
		// dossier ; cette extension réimplémentait la boucle sans le garde-fou. La borne est
		// posée ici en plus des refus de `..` côté ImagesLegeres_Moteur, pour que ce crochet
		// reste sûr même si une source de chemin change un jour.
		$uploads = wp_get_upload_dir();
		$basedir = $uploads['basedir'];

		// 1. Frères .webp de l'original et de chacune de ses sous-tailles.
		foreach ( ImagesLegeres_Moteur::fichiers_de_attachment( $attachment_id ) as $chemin ) {
			$frere = $chemin . '.webp';

			if ( file_exists( $frere ) && wp_delete_file_from_directory( $frere, $basedir ) ) {
				$supprimes[] = $frere;
			}
		}

		// 2. Sous-tailles harmonisées (fichiers + frères .webp + lignes d'inventaire).
		foreach ( ImagesLegeres_Harmonisation::supprimer( $attachment_id ) as $chemin ) {
			$supprimes[] = $chemin;
		}

		// 3. Copie de sécurité pleine résolution de l'original retaillé.
		$chemin_original = get_attached_file( $attachment_id );

		if ( $chemin_original ) {
			$sauvegarde = ImagesLegeres_Moteur::chemin_sauvegarde_original( $chemin_original );

			if ( '' !== $sauvegarde && file_exists( $sauvegarde ) && wp_delete_file_from_directory( $sauvegarde, $basedir ) ) {
				$supprimes[] = $sauvegarde;
			}
		}

		// 4. Image de flux Google / Meta (fichier + méta).
		$flux = ImagesLegeres_Flux::flux_existant( $attachment_id );
		ImagesLegeres_Flux::supprimer( $attachment_id );

		if ( $flux && ! file_exists( $flux['chemin'] ) ) {
			$supprimes[] = $flux['chemin'];
		}

		// 5. Lignes d'état : table des fichiers et file d'attente du lot.
		ImagesLegeres_Table::supprimer_par_attachment( $attachment_id );
		ImagesLegeres_File::supprimer_par_attachment( $attachment_id );

		if ( ! empty( $supprimes ) ) {
			ImagesLegeres_Journal::ecrire(
				ImagesLegeres_Moteur::chemin_relatif( (string) $chemin_original ),
				'supprime',
				0,
				0,
				0,
				sprintf( 'pièce jointe %d supprimée : %d fichier(s) produit(s) retiré(s)', $attachment_id, count( $supprimes ) )
			);
		}

		return $supprimes;
	}
}
