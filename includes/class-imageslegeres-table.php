<?php
/**
 * Table d'état des fichiers (spec § 6) : création, suppression, lecture.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Table {

	public static function nom() {
		global $wpdb;

		return $wpdb->prefix . 'imageslegeres_fichiers';
	}

	public static function creer() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::nom();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			chemin VARCHAR(500) NOT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			taille_origine BIGINT UNSIGNED NOT NULL DEFAULT 0,
			largeur_origine INT UNSIGNED NOT NULL DEFAULT 0,
			hauteur_origine INT UNSIGNED NOT NULL DEFAULT 0,
			taille_webp BIGINT UNSIGNED NOT NULL DEFAULT 0,
			gain_octets BIGINT NOT NULL DEFAULT 0,
			etat VARCHAR(20) NOT NULL DEFAULT 'a-faire',
			motif VARCHAR(500) NOT NULL DEFAULT '',
			retaille TINYINT(1) NOT NULL DEFAULT 0,
			chemin_original_sauvegarde VARCHAR(500) NOT NULL DEFAULT '',
			maj DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY chemin (chemin(191)),
			KEY attachment_id (attachment_id),
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

	public static function compter_par_etat() {
		global $wpdb;

		if ( ! self::existe() ) {
			return array();
		}

		$table     = self::nom();
		$resultats = $wpdb->get_results( "SELECT etat, COUNT(*) AS total FROM {$table} GROUP BY etat", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$compte = array();
		foreach ( (array) $resultats as $ligne ) {
			$compte[ $ligne['etat'] ] = (int) $ligne['total'];
		}

		return $compte;
	}

	public static function gain_total_octets() {
		global $wpdb;

		if ( ! self::existe() ) {
			return 0;
		}

		$table = self::nom();

		return (int) $wpdb->get_var( "SELECT SUM(gain_octets) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function fichiers_retailles() {
		global $wpdb;

		if ( ! self::existe() ) {
			return array();
		}

		$table = self::nom();

		return (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE retaille = 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function tout() {
		global $wpdb;

		if ( ! self::existe() ) {
			return array();
		}

		$table = self::nom();

		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function vider() {
		global $wpdb;

		if ( ! self::existe() ) {
			return;
		}

		$table = self::nom();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
