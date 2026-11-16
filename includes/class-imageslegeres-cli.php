<?php
/**
 * Commande WP-CLI `wp images-legeres`.
 *
 * `status`, `orphelins` (mission 12) et `convert` (mission 13, moteur). Les sous-commandes
 * `crop`, `alt` et `flux` (interfaces usine, spec § 10) sont dans leurs propres classes
 * (`ImagesLegeres_Cli_Crop`, `ImagesLegeres_Cli_Alt`, `ImagesLegeres_Cli_Flux`, mission 16).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Cli {

	/**
	 * Affiche l'état de l'extension : réglages actifs, compte des fichiers par état.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json ou yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$reglages = ImagesLegeres_Reglages::tout();
		$compte   = ImagesLegeres_Table::compter_par_etat();

		$donnees = array(
			array( 'cle' => 'version', 'valeur' => IMAGES_LEGERES_VERSION ),
			array( 'cle' => 'mode_apercu_actif', 'valeur' => $reglages['mode_apercu_actif'] ? '1' : '0' ),
			array( 'cle' => 'table_existe', 'valeur' => ImagesLegeres_Table::existe() ? '1' : '0' ),
			array(
				'cle'    => 'autre_editeur_detecte',
				'valeur' => implode( ',', (array) get_option( ImagesLegeres_Activation::OPTION_AUTRE_EDITEUR, array() ) ),
			),
		);

		foreach ( array( 'a-faire', 'en-cours', 'fait', 'ignore', 'erreur' ) as $etat ) {
			$donnees[] = array(
				'cle'    => 'fichiers_' . $etat,
				'valeur' => (string) ( $compte[ $etat ] ?? 0 ),
			);
		}

		// Depuis que le seuil de gain vaut 0 % (décision du 2026-11-15), le seul fichier
		// qui reste servi en JPEG/PNG est celui dont le WebP pèserait plus lourd : il se
		// compte, sinon « toute sous-taille a son WebP » n'est pas vérifiable.
		$donnees[] = array( 'cle' => 'fichiers_webp_plus_lourd', 'valeur' => (string) ImagesLegeres_Table::compter_webp_plus_lourd() );
		$donnees[] = array( 'cle' => 'gain_minimal_pourcent', 'valeur' => (string) $reglages['gain_minimal_pourcent'] );
		$donnees[] = array( 'cle' => 'harmonisation_produit_active', 'valeur' => $reglages['harmonisation_produit_active'] ? '1' : '0' );
		$donnees[] = array( 'cle' => 'sous_tailles_harmonisees', 'valeur' => (string) ImagesLegeres_Harmonisees::compter() );

		\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $donnees, array( 'cle', 'valeur' ) );
	}

	/**
	 * Liste ce qui resterait d'Images légères après une désinstallation : table, options,
	 * métas, drop-ins, dossier uploads/images-legeres. Rend une liste vide si tout est propre.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv ou yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function orphelins( $args, $assoc_args ) {
		global $wpdb;

		$orphelins = array();

		if ( ImagesLegeres_Table::existe() ) {
			$orphelins[] = array( 'type' => 'table', 'identifiant' => ImagesLegeres_Table::nom() );
		}

		if ( ImagesLegeres_File::existe() ) {
			$orphelins[] = array( 'type' => 'table', 'identifiant' => ImagesLegeres_File::nom() );
		}

		if ( ImagesLegeres_Harmonisees::existe() ) {
			$orphelins[] = array( 'type' => 'table', 'identifiant' => ImagesLegeres_Harmonisees::nom() );
		}

		foreach ( ImagesLegeres_Desinstallation::OPTIONS as $option ) {
			if ( false !== get_option( $option, false ) ) {
				$orphelins[] = array( 'type' => 'option', 'identifiant' => $option );
			}
		}

		foreach ( ImagesLegeres_Desinstallation::METAS as $meta_key ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $total > 0 ) {
				$orphelins[] = array( 'type' => 'meta', 'identifiant' => $meta_key . ' (' . $total . ')' );
			}
		}

		foreach ( array( 'object-cache.php', 'advanced-cache.php' ) as $drop_in ) {
			if ( file_exists( trailingslashit( WP_CONTENT_DIR ) . $drop_in ) ) {
				$orphelins[] = array( 'type' => 'drop-in', 'identifiant' => $drop_in );
			}
		}

		$uploads = wp_get_upload_dir();
		$dossier = trailingslashit( $uploads['basedir'] ) . 'images-legeres';
		if ( is_dir( $dossier ) ) {
			$orphelins[] = array( 'type' => 'dossier', 'identifiant' => $dossier );
		}

		// SEC-4 : les frères .webp des sous-tailles survivaient à « Tout rendre » sans que ce
		// contrôle les voie. Il ne regardait que le dossier images-legeres/.
		$freres = ImagesLegeres_Desinstallation::lister_freres_webp();
		if ( ! empty( $freres ) ) {
			$orphelins[] = array(
				'type'        => 'fichier',
				'identifiant' => sprintf( 'frères .webp dans uploads (%d) — ex. %s', count( $freres ), ImagesLegeres_Moteur::chemin_relatif( $freres[0] ) ),
			);
		}

		// F5 : les sous-tailles harmonisées sont des fichiers de l'extension, à côté des
		// natifs. Un contrôle qui ne les voit pas laisserait « Tout rendre » passer pour
		// propre alors que les carrés restent en ligne.
		$harmonisees = ImagesLegeres_Harmonisation::fichiers_existants();
		if ( ! empty( $harmonisees ) ) {
			$orphelins[] = array(
				'type'        => 'fichier',
				'identifiant' => sprintf( 'sous-tailles harmonisées (%d) — ex. %s', count( $harmonisees ), ImagesLegeres_Moteur::chemin_relatif( $harmonisees[0] ) ),
			);
		}

		// SEC-9 : les WebP à double suffixe d'un autre outil d'optimisation. Comptés à part
		// et jamais supprimés — le contrôle doit dire ce qu'il voit sans se les attribuer.
		$tiers = ImagesLegeres_Desinstallation::lister_freres_webp_tiers();
		if ( ! empty( $tiers ) ) {
			$orphelins[] = array(
				'type'        => 'autre outil',
				'identifiant' => sprintf( 'frères .webp d\'un autre outil (%d, jamais supprimés) — ex. %s', count( $tiers ), ImagesLegeres_Moteur::chemin_relatif( $tiers[0] ) ),
			);
		}

		\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $orphelins, array( 'type', 'identifiant' ) );
	}

	/**
	 * Traite une pièce jointe : conversion WebP de l'original et de ses sous-tailles, et
	 * retaille de l'original si le réglage est actif. Pour les essais : --dry-run n'écrit
	 * rien et liste ce qui serait fait.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Identifiant de la pièce jointe.
	 *
	 * [--dry-run]
	 * : N'écrit rien, liste les fichiers qui seraient traités.
	 *
	 * @when after_wp_load
	 */
	public function convert( $args, $assoc_args ) {
		$id = (int) $args[0];

		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			WP_CLI::error( 'pièce jointe introuvable : ' . $args[0] );
		}

		$dry_run   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$resultats = ImagesLegeres_Moteur::traiter_attachment( $id, $dry_run );

		\WP_CLI\Utils\format_items( 'table', $resultats, array( 'chemin', 'etat', 'motif' ) );

		if ( $dry_run ) {
			WP_CLI::log( 'dry-run : aucune écriture.' );
		}
	}

	/**
	 * Produit les sous-tailles harmonisées des images produit cadrées, et leurs frères .webp.
	 *
	 * Les fichiers sont écrits à côté des natifs, sous un nom propre à l'extension
	 * (`<base>-il-harmonise-<L>x<H>.<ext>`) : aucun fichier natif n'est touché, aucune entrée
	 * de `_wp_attachment_metadata` n'est modifiée. Ils ne sont servis que par la réécriture à
	 * la sortie, donc réservés aux administrateurs connectés tant que le mode aperçu est actif.
	 *
	 * ## OPTIONS
	 *
	 * [<ids>...]
	 * : Identifiants de pièces jointes. Sans argument : toutes les images produit cadrées
	 * (rectangle posé ou fond assumé).
	 *
	 * [--webp-manquants]
	 * : Rattrapage : ne traite que les pièces jointes dont au moins une harmonisée n'a pas
	 * de frère .webp sur le disque. Idempotent — une fois passé, la liste est vide.
	 * Incompatible avec une liste d'identifiants.
	 *
	 * [--dry-run]
	 * : N'écrit rien, liste ce qui serait produit.
	 *
	 * [--format=<format>]
	 * : table, json ou yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function harmoniser( $args, $assoc_args ) {
		if ( ! ImagesLegeres_Reglages::valeur( 'harmonisation_produit_active' ) ) {
			WP_CLI::error( 'harmonisation produit désactivée : rien à produire.' );
		}

		$dry_run    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$rattrapage = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'webp-manquants', false );

		if ( $rattrapage && $args ) {
			WP_CLI::error( '--webp-manquants choisit lui-même les pièces jointes : ne lui en passe pas.' );
		}

		if ( $rattrapage ) {
			$ids = ImagesLegeres_Harmonisation::ids_webp_manquant();

			if ( empty( $ids ) ) {
				WP_CLI::success( 'aucune harmonisée sans frère .webp : rien à rattraper.' );

				return;
			}

			WP_CLI::log( sprintf( 'rattrapage : %d pièce(s) jointe(s) avec au moins une harmonisée sans frère .webp.', count( $ids ) ) );
		} else {
			$ids = $args ? array_map( 'intval', $args ) : ImagesLegeres_Harmonisation::ids_a_harmoniser();
		}

		if ( empty( $ids ) ) {
			WP_CLI::warning( 'aucune image produit cadrée : rien à produire.' );

			return;
		}

		$lignes = array();

		foreach ( $ids as $id ) {
			foreach ( ImagesLegeres_Harmonisation::generer( $id, $dry_run ) as $resultat ) {
				$lignes[] = array(
					'id'      => (string) $id,
					'taille'  => $resultat['taille'],
					'fichier' => $resultat['fichier'],
					'etat'    => $resultat['etat'],
					'motif'   => $resultat['motif'],
				);
			}
		}

		\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $lignes, array( 'id', 'taille', 'fichier', 'etat', 'motif' ) );

		if ( $dry_run ) {
			WP_CLI::log( 'dry-run : aucune écriture.' );
		}
	}

	/**
	 * Lance le lot : amorce la file (ajoute les pièces jointes images pas encore suivies),
	 * reprend les lignes bloquées par une coupure, puis traite jusqu'à épuisement de la file
	 * ou à la limite donnée. Hors serveur web (ADR 0004 §1) : voie du lot initial, lancée à la
	 * main ou par une tâche planifiée, de nuit.
	 *
	 * ## OPTIONS
	 *
	 * [--limite=<n>]
	 * : Nombre de pièces jointes au plus à traiter. 0 = jusqu'à vider la file.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--forcer]
	 * : Ignore la plage horaire et le seuil de charge (usage manuel assumé).
	 *
	 * [--format=<format>]
	 * : table, json ou yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function run( $args, $assoc_args ) {
		$limite = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'limite', 0 );
		$forcer = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'forcer', false );

		$resultat = ImagesLegeres_Lot::executer_cli( $limite, $forcer );

		if ( $resultat['refuse'] ) {
			WP_CLI::warning( 'file non lancée : ' . $resultat['motif'] . ' (reprends avec --forcer pour ignorer).' );
		} elseif ( '' !== $resultat['motif'] ) {
			WP_CLI::log( 'arrêt anticipé : ' . $resultat['motif'] . '.' );
		}

		$donnees = array(
			array( 'cle' => 'traites', 'valeur' => (string) $resultat['traites'] ),
			array( 'cle' => 'fait', 'valeur' => (string) ( $resultat['resume']['fait'] ?? 0 ) ),
			array( 'cle' => 'ignore', 'valeur' => (string) ( $resultat['resume']['ignore'] ?? 0 ) ),
			array( 'cle' => 'erreur', 'valeur' => (string) ( $resultat['resume']['erreur'] ?? 0 ) ),
			array( 'cle' => 'restants', 'valeur' => (string) $resultat['restants'] ),
		);

		\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $donnees, array( 'cle', 'valeur' ) );
	}
}
