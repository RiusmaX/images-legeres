<?php
/**
 * Inventaire des sous-tailles harmonisées (F5).
 *
 * Table propre à l'extension : une ligne par fichier harmonisé écrit sur le disque, avec le
 * fichier natif qu'il remplace à l'affichage. Deux raisons de ne pas vivre dans
 * `_wp_attachment_metadata` : une entrée de `sizes` serait servie à TOUT LE MONDE dès sa
 * création — le mode aperçu ne protégerait plus rien (constat de production du 2026-11-15) —
 * et elle écraserait l'entrée native, que l'extension ne doit jamais toucher.
 *
 * C'est aussi l'index que `ImagesLegeres_Service` interroge pour substituer, en une requête
 * par page, les adresses natives par leurs harmonisées.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Harmonisees {

	public static function nom() {
		global $wpdb;

		return $wpdb->prefix . 'imageslegeres_harmonisees';
	}

	public static function creer() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::nom();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			taille VARCHAR(100) NOT NULL DEFAULT '',
			chemin_natif VARCHAR(500) NOT NULL DEFAULT '',
			chemin_harmonise VARCHAR(500) NOT NULL,
			largeur INT UNSIGNED NOT NULL DEFAULT 0,
			hauteur INT UNSIGNED NOT NULL DEFAULT 0,
			poids BIGINT UNSIGNED NOT NULL DEFAULT 0,
			maj DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY chemin_harmonise (chemin_harmonise(191)),
			KEY chemin_natif (chemin_natif(191)),
			KEY attachment_id (attachment_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public static function supprimer() {
		global $wpdb;

		$table = self::nom();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function existe() {
		global $wpdb;

		$table = self::nom();

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/** Insère ou met à jour la ligne d'un fichier harmonisé (unique par chemin). */
	public static function enregistrer( array $donnees ) {
		global $wpdb;

		if ( empty( $donnees['chemin_harmonise'] ) ) {
			return 0;
		}

		// Création à la première écriture, jamais au chargement : une table recréée à chaque
		// requête ressortirait en orphelin juste après « Tout rendre », qui vient de la
		// supprimer.
		if ( ! self::existe() ) {
			self::creer();
		}

		$table    = self::nom();
		$donnees['maj'] = current_time( 'mysql', true );
		$existante = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE chemin_harmonise = %s", $donnees['chemin_harmonise'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $existante ) {
			$wpdb->update( $table, $donnees, array( 'id' => $existante->id ) );

			return (int) $existante->id;
		}

		$wpdb->insert( $table, $donnees );

		return (int) $wpdb->insert_id;
	}

	public static function tout() {
		global $wpdb;

		if ( ! self::existe() ) {
			return array();
		}

		$table = self::nom();

		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function par_attachment( $attachment_id ) {
		global $wpdb;

		if ( ! self::existe() ) {
			return array();
		}

		$table = self::nom();

		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE attachment_id = %d ORDER BY id ASC", (int) $attachment_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}

	public static function supprimer_lignes_attachment( $attachment_id ) {
		global $wpdb;

		if ( ! self::existe() ) {
			return 0;
		}

		return (int) $wpdb->delete( self::nom(), array( 'attachment_id' => (int) $attachment_id ), array( '%d' ) );
	}

	public static function compter() {
		global $wpdb;

		if ( ! self::existe() ) {
			return 0;
		}

		$table = self::nom();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Table de substitution « chemin natif relatif → ligne harmonisée » pour la liste de
	 * chemins donnée. Une seule requête, bornée aux adresses réellement présentes dans la
	 * page : charger tout l'inventaire à chaque requête coûterait d'autant plus cher que le
	 * catalogue grandit.
	 */
	public static function map_pour_chemins( array $relatifs ) {
		global $wpdb;

		$relatifs = array_values( array_unique( array_filter( $relatifs ) ) );

		if ( empty( $relatifs ) || ! self::existe() ) {
			return array();
		}

		$table         = self::nom();
		$emplacements  = implode( ', ', array_fill( 0, count( $relatifs ), '%s' ) );
		$lignes        = $wpdb->get_results(
			$wpdb->prepare( "SELECT attachment_id, chemin_natif, chemin_harmonise, largeur, hauteur FROM {$table} WHERE chemin_natif IN ( {$emplacements} )", $relatifs ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$map = array();

		foreach ( (array) $lignes as $ligne ) {
			$map[ $ligne['chemin_natif'] ] = array(
				'attachment' => (int) $ligne['attachment_id'],
				'chemin'     => $ligne['chemin_harmonise'],
				'largeur'    => (int) $ligne['largeur'],
				'hauteur'    => (int) $ligne['hauteur'],
			);
		}

		return $map;
	}

	/**
	 * La plus grande harmonisée de chacune des pièces jointes données.
	 *
	 * Sert la galerie produit : l'agrandissement doit montrer LA MÊME photo que l'affichage,
	 * dans sa plus grande définition disponible. Chercher cette grande parmi les seules
	 * adresses présentes dans la balise ne suffit pas — une balise de galerie peut n'avoir
	 * ni `srcset` ni adresse de grande taille, et le zoom se retrouverait servi plus petit
	 * que l'original qu'il remplace.
	 *
	 * @return array attachment_id => array( chemin, largeur, hauteur )
	 */
	public static function plus_grandes_par_attachment( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( empty( $ids ) || ! self::existe() ) {
			return array();
		}

		$table        = self::nom();
		$emplacements = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$lignes       = $wpdb->get_results(
			$wpdb->prepare( "SELECT attachment_id, chemin_harmonise, largeur, hauteur FROM {$table} WHERE attachment_id IN ( {$emplacements} )", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$plus_grandes = array();

		foreach ( (array) $lignes as $ligne ) {
			$id = (int) $ligne['attachment_id'];

			if ( isset( $plus_grandes[ $id ] ) && $plus_grandes[ $id ]['largeur'] >= (int) $ligne['largeur'] ) {
				continue;
			}

			$plus_grandes[ $id ] = array(
				'attachment' => $id,
				'chemin'     => $ligne['chemin_harmonise'],
				'largeur'    => (int) $ligne['largeur'],
				'hauteur'    => (int) $ligne['hauteur'],
			);
		}

		return $plus_grandes;
	}
}
