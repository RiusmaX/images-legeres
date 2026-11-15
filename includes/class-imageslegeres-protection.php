<?php
/**
 * Protection d'accès des dossiers que l'extension crée dans `uploads/` (SEC-2, SEC-6).
 *
 * Posée **sans condition** à l'activation, et re-posée par chaque code qui crée un de ces
 * dossiers (le journal, la retaille, l'image de flux) : un site activé avant cette version,
 * ou dont le `.htaccess` a été effacé à la main, retrouve sa protection au prochain passage.
 *
 * Le niveau compte autant que la présence :
 * - `images-legeres/`           public, mais **les journaux** y sont refusés (FilesMatch) ;
 * - `images-legeres/originaux/` entièrement refusé — c'est la copie pleine résolution ;
 * - `images-legeres/flux/`      **jamais** refusé : Google Listings and Ads et Facebook for
 *   WooCommerce doivent pouvoir télécharger ces images (ADR 0006).
 *
 * Syntaxe : `Require all denied` (Apache 2.4, mod_authz_core) avec le bloc Apache 2.2 en
 * repli. L'ancienne forme `Order deny,allow` seule provoque une erreur 500 sur tout le
 * sous-arbre quand `mod_access_compat` n'est pas chargé (SEC-6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Protection {

	const INDEX = "<?php\n// Silence is golden.\n";

	/**
	 * Pose (ou remet à jour) la protection des trois dossiers. Appelée à l'activation, sans
	 * condition, et par les créateurs de dossiers.
	 */
	public static function poser_toutes() {
		self::poser_racine();
		self::poser_originaux();
		self::poser_flux();
	}

	/**
	 * `uploads/images-legeres/` : index muet, et refus des seuls fichiers de journal.
	 * Le dossier lui-même reste traversable — `flux/` en dépend.
	 */
	public static function poser_racine() {
		$dossier = ImagesLegeres_Journal::dossier();

		self::ecrire(
			$dossier,
			"# Images légères — les journaux ne sont jamais servis ; le reste du dossier\n" .
			"# (dont flux/) reste public : Google et Meta doivent le lire.\n" .
			self::bloc_refus( '<FilesMatch "^journal-.*\\.log$">', '</FilesMatch>' )
		);
	}

	/**
	 * `uploads/images-legeres/originaux/` : copie pleine résolution des originaux retaillés.
	 * Refus complet — c'est la définition la plus haute qui existe sur le site.
	 */
	public static function poser_originaux() {
		$uploads = wp_get_upload_dir();
		$dossier = trailingslashit( $uploads['basedir'] ) . 'images-legeres/originaux';

		self::ecrire(
			$dossier,
			"# Images légères — copies pleine résolution : jamais servies.\n" .
			self::bloc_refus( '', '' )
		);
	}

	/**
	 * `uploads/images-legeres/flux/` : index muet seulement (pas de listage de répertoire),
	 * aucune règle de refus — les images doivent rester publiques.
	 */
	public static function poser_flux() {
		$dossier = untrailingslashit( ImagesLegeres_Flux::dossier_absolu() );

		self::ecrire( $dossier, '' );
	}

	/**
	 * Règle de refus, éventuellement bornée à un conteneur (`<FilesMatch …>`), dans les deux
	 * syntaxes Apache : 2.4 d'abord, 2.2 en repli si `mod_authz_core` est absent (SEC-6).
	 */
	private static function bloc_refus( $ouverture, $fermeture ) {
		$indente = '' === $ouverture ? '' : "\t";

		$corps_24 = $indente . "Require all denied\n";
		$corps_22 = $indente . "Order deny,allow\n" . $indente . "Deny from all\n";

		if ( '' !== $ouverture ) {
			$corps_24 = $ouverture . "\n" . $corps_24 . $fermeture . "\n";
			$corps_22 = $ouverture . "\n" . $corps_22 . $fermeture . "\n";
		}

		return "<IfModule mod_authz_core.c>\n" . $corps_24 . "</IfModule>\n" .
			"<IfModule !mod_authz_core.c>\n" . $corps_22 . "</IfModule>\n";
	}

	/**
	 * Crée le dossier si besoin, y pose l'index muet, et écrit le `.htaccess` demandé —
	 * ou le supprime si la règle est vide. Réécrit quand le contenu diffère : c'est ce qui
	 * remplace, à la mise à jour, un `.htaccess` posé par une version précédente.
	 */
	private static function ecrire( $dossier, $regle ) {
		if ( ! is_dir( $dossier ) && ! wp_mkdir_p( $dossier ) ) {
			return false;
		}

		$index = trailingslashit( $dossier ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, self::INDEX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$htaccess = trailingslashit( $dossier ) . '.htaccess';

		if ( '' === $regle ) {
			return true;
		}

		$actuel = file_exists( $htaccess ) ? (string) file_get_contents( $htaccess ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( $actuel === $regle ) {
			return true;
		}

		return false !== file_put_contents( $htaccess, $regle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
