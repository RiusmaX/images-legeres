<?php
/**
 * Désinstallation (niveau 3, « désinstaller » — ADR 0004 §7) : appelé par WordPress quand
 * l'extension est supprimée depuis l'écran Extensions. Supprime table, options et métas ;
 * laisse les fichiers (c'est « Tout rendre », joué depuis l'écran d'administration, qui
 * restaure puis nettoie les fichiers avant d'appeler la même désinstallation).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-imageslegeres-reglages.php';
require_once __DIR__ . '/includes/class-imageslegeres-table.php';
require_once __DIR__ . '/includes/class-imageslegeres-activation.php';
require_once __DIR__ . '/includes/class-imageslegeres-desinstallation.php';

ImagesLegeres_Desinstallation::desinstaller();
