<?php
/**
 * File d'attente du lot (spec § 11 mission 19, ADR 0004 §2-3) : une ligne par pièce jointe
 * à traiter, état a-faire/en-cours/fait/ignore/erreur, reprise après coupure par relecture de
 * la table (pas par curseur).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_File {

	/**
	 * Au-delà de ce délai, une ligne restée « en-cours » est considérée abandonnée (process
	 * tué) et repasse à « a-faire » (ADR 0004 §3). Filtrable : aucun réglage n'expose cette
	 * valeur dans l'écran d'administration, la spec ne la liste pas parmi les réglages § 5.
	 */
	const DELAI_BLOCAGE_MINUTES = 10;

	public static function nom() {
		global $wpdb;

		return $wpdb->prefix . 'imageslegeres_file';
	}

	public static function creer() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::nom();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL,
			etat VARCHAR(20) NOT NULL DEFAULT 'a-faire',
			motif VARCHAR(500) NOT NULL DEFAULT '',
			prise_le DATETIME NULL DEFAULT NULL,
			maj DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY etat (etat)
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

	public static function vider() {
		global $wpdb;

		if ( ! self::existe() ) {
			return;
		}

		$wpdb->query( 'TRUNCATE TABLE ' . self::nom() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Ajoute à la file toute pièce jointe image qui n'y figure pas encore. Rend le nombre de
	 * lignes ajoutées. N'écrase jamais une ligne existante (idempotent).
	 */
	public static function amorcer() {
		global $wpdb;

		if ( ! self::existe() ) {
			return 0;
		}

		$table = self::nom();

		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$table} f ON f.attachment_id = p.ID
			WHERE p.post_type = 'attachment'
			AND p.post_mime_type LIKE 'image/%'
			AND f.id IS NULL"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$ajoutes = 0;

		foreach ( $ids as $id ) {
			$insere = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (attachment_id, etat, maj) VALUES (%d, 'a-faire', %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					(int) $id,
					current_time( 'mysql', true )
				)
			);

			if ( $insere ) {
				++$ajoutes;
			}
		}

		return $ajoutes;
	}

	/**
	 * Remet à « a-faire » les lignes « en-cours » trop anciennes pour avoir survécu à une
	 * coupure (ADR 0004 §3). Rend le nombre de lignes reprises.
	 */
	public static function reprendre_bloquees() {
		global $wpdb;

		if ( ! self::existe() ) {
			return 0;
		}

		$table        = self::nom();
		$delai        = (int) apply_filters( 'images_legeres_file_delai_blocage_minutes', self::DELAI_BLOCAGE_MINUTES );
		$seuil        = gmdate( 'Y-m-d H:i:s', time() - $delai * MINUTE_IN_SECONDS );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET etat = 'a-faire', motif = 'reprise après coupure', maj = %s WHERE etat = 'en-cours' AND prise_le < %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				current_time( 'mysql', true ),
				$seuil
			)
		);
	}

	/**
	 * Rend l'attachment_id de la prochaine ligne « a-faire » (la plus ancienne), ou 0 s'il n'y
	 * en a plus.
	 */
	public static function prochaine() {
		global $wpdb;

		if ( ! self::existe() ) {
			return 0;
		}

		$table = self::nom();

		return (int) $wpdb->get_var( "SELECT attachment_id FROM {$table} WHERE etat = 'a-faire' ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function marquer_en_cours( $attachment_id ) {
		global $wpdb;

		$wpdb->update(
			self::nom(),
			array(
				'etat'     => 'en-cours',
				'prise_le' => current_time( 'mysql', true ),
				'maj'      => current_time( 'mysql', true ),
			),
			array( 'attachment_id' => (int) $attachment_id )
		);
	}

	public static function marquer_resultat( $attachment_id, $etat, $motif = '' ) {
		global $wpdb;

		$wpdb->update(
			self::nom(),
			array(
				'etat'  => $etat,
				'motif' => (string) $motif,
				'maj'   => current_time( 'mysql', true ),
			),
			array( 'attachment_id' => (int) $attachment_id )
		);
	}

	public static function ligne( $attachment_id ) {
		global $wpdb;

		if ( ! self::existe() ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::nom() . ' WHERE attachment_id = %d', (int) $attachment_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}

	public static function compter_par_etat() {
		global $wpdb;

		if ( ! self::existe() ) {
			return array();
		}

		$resultats = $wpdb->get_results( 'SELECT etat, COUNT(*) AS total FROM ' . self::nom() . ' GROUP BY etat', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$compte = array();
		foreach ( (array) $resultats as $ligne ) {
			$compte[ $ligne['etat'] ] = (int) $ligne['total'];
		}

		return $compte;
	}

	/**
	 * Remet à `a-faire` les lignes dont la pièce jointe est une image de produit WooCommerce
	 * (ADR 0006 point 7, mission 19b) : appelé quand un réglage de flux change, pour que ces
	 * images repassent par la file et régénèrent leur fichier de flux. Rend le nombre de
	 * lignes remises.
	 */
	public static function remettre_images_produit_a_faire() {
		global $wpdb;

		if ( ! self::existe() ) {
			return 0;
		}

		$table = self::nom();

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} f
				INNER JOIN {$wpdb->posts} image ON image.ID = f.attachment_id
				INNER JOIN {$wpdb->posts} produit ON produit.ID = image.post_parent AND produit.post_type = 'product'
				SET f.etat = 'a-faire', f.motif = %s, f.maj = %s
				WHERE f.etat != 'en-cours'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'réglages de flux modifiés',
				current_time( 'mysql', true )
			)
		);
	}

	public static function reste_a_faire() {
		global $wpdb;

		if ( ! self::existe() ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::nom() . " WHERE etat = 'a-faire'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
