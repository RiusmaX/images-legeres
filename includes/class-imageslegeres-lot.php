<?php
/**
 * Orchestration du lot (spec § 11 mission 19, ADR 0004 §1-3) : deux voies d'exécution sur la
 * même file (ImagesLegeres_File) et les mêmes garde-fous (plage horaire, pause, charge) —
 * Action Scheduler pour le quotidien (une image par action, concurrence 1, rechargée par
 * cette classe à chaque action), WP-CLI pour les gros lots, hors serveur web.
 *
 * Régénération et nettoyage des fichiers de flux (ADR 0006 point 7, mission 19b) : chaque
 * image traitée par la file passe aussi par `ImagesLegeres_Flux::generer()` (sans effet sur
 * une image qui n'est pas une image de produit — c'est elle qui le vérifie). Quand un réglage
 * de flux change (`HOOK_FLUX_ACCROCHE`), les images de produit déjà dans la file sont remises
 * à `a-faire` : elles repassent par `traiter_un()` au prochain passage de la file (Action
 * Scheduler ou `wp images-legeres run`), qui régénère leur fichier de flux avec la nouvelle
 * empreinte et supprime l'ancien — `ImagesLegeres_Flux::generer()` s'en charge seul, aucune
 * logique de nettoyage n'est dupliquée ici.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesLegeres_Lot {

	const OPTION_ETAT        = 'images_legeres_lot_etat';
	const HOOK                = 'images_legeres_lot_traiter_un';
	const HOOK_FLUX_ACCROCHE  = 'images_legeres_flux_reglages_changes';
	const GROUPE_AS           = 'images-legeres';

	const CLES_REGLAGES_FLUX = array(
		'image_flux_active',
		'flux_google_actif',
		'flux_meta_actif',
		'dimension_flux',
		'qualite_flux',
	);

	public static function enregistrer() {
		add_action( self::HOOK, array( __CLASS__, 'traiter_action' ) );
		add_action( self::HOOK_FLUX_ACCROCHE, array( __CLASS__, 'remettre_flux_en_file' ) );
	}

	/**
	 * Action Scheduler est fourni par WooCommerce (bibliothèque embarquée) sur ce site de
	 * test ; en son absence, la file automatique (Lancer/Pause/Reprendre) se désactive
	 * proprement, mais `wp images-legeres run` reste utilisable car il ne dépend jamais
	 * d'Action Scheduler (boucle synchrone, hors serveur web, ADR 0004 §1).
	 */
	public static function action_scheduler_disponible() {
		return function_exists( 'as_schedule_single_action' );
	}

	public static function etat() {
		return get_option( self::OPTION_ETAT, 'arret' );
	}

	public static function demarrer() {
		update_option( self::OPTION_ETAT, 'en-cours' );
		ImagesLegeres_File::amorcer();
		ImagesLegeres_File::reprendre_bloquees();

		if ( self::action_scheduler_disponible() ) {
			self::planifier_prochaine( 0 );
		}
	}

	public static function mettre_en_pause() {
		update_option( self::OPTION_ETAT, 'en-pause' );
	}

	public static function reprendre() {
		update_option( self::OPTION_ETAT, 'en-cours' );
		ImagesLegeres_File::reprendre_bloquees();

		if ( self::action_scheduler_disponible() && ! self::action_deja_planifiee() ) {
			self::planifier_prochaine( 0 );
		}
	}

	private static function action_deja_planifiee() {
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array(), self::GROUPE_AS );
	}

	public static function planifier_prochaine( $delai_secondes ) {
		if ( ! self::action_scheduler_disponible() ) {
			return;
		}

		as_schedule_single_action( time() + max( 0, (int) round( $delai_secondes ) ), self::HOOK, array(), self::GROUPE_AS );
	}

	public static function annuler_planifications() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUPE_AS );
		}
	}

	/**
	 * Gestionnaire de l'action Action Scheduler : traite une pièce jointe puis replanifie la
	 * suivante. Concurrence 1 par construction (une action en traite une seule, et ne
	 * planifie la suivante qu'après avoir terminé).
	 */
	public static function traiter_action() {
		if ( 'en-cours' !== self::etat() ) {
			return;
		}

		ImagesLegeres_File::reprendre_bloquees();

		if ( ! self::dans_plage_horaire() ) {
			self::planifier_prochaine( self::retard_secondes() );
			return;
		}

		if ( self::charge_trop_elevee() ) {
			self::planifier_prochaine( self::retard_secondes() );
			return;
		}

		$attachment_id = ImagesLegeres_File::prochaine();

		if ( ! $attachment_id ) {
			update_option( self::OPTION_ETAT, 'termine' );
			return;
		}

		self::traiter_un( $attachment_id );

		self::planifier_prochaine( (float) ImagesLegeres_Reglages::valeur( 'pause_entre_images_secondes' ) );
	}

	private static function traiter_un( $attachment_id ) {
		ImagesLegeres_File::marquer_en_cours( $attachment_id );

		$resultats   = ImagesLegeres_Moteur::traiter_attachment( $attachment_id );
		$resultats[] = ImagesLegeres_Flux::generer( $attachment_id );

		list( $etat, $motif ) = self::resumer_resultats( $resultats );

		ImagesLegeres_File::marquer_resultat( $attachment_id, $etat, $motif );

		return $etat;
	}

	private static function resumer_resultats( array $resultats ) {
		$motifs = array();
		$a_une_erreur = false;
		$a_un_fait    = false;

		foreach ( $resultats as $resultat ) {
			if ( 'erreur' === $resultat['etat'] ) {
				$a_une_erreur = true;
			} elseif ( 'fait' === $resultat['etat'] ) {
				$a_un_fait = true;
			}

			if ( ! empty( $resultat['motif'] ) ) {
				$motifs[] = $resultat['motif'];
			}
		}

		if ( $a_une_erreur ) {
			return array( 'erreur', implode( ' ; ', $motifs ) );
		}

		if ( $a_un_fait ) {
			return array( 'fait', implode( ' ; ', $motifs ) );
		}

		return array( 'ignore', implode( ' ; ', $motifs ) );
	}

	/**
	 * Délai, en secondes, avant de retenter quand la file est hors plage horaire ou que la
	 * charge est trop élevée (« reprise au cycle suivant », ADR 0004 §2).
	 */
	public static function retard_secondes() {
		return (int) apply_filters( 'images_legeres_lot_retard_secondes', 5 * MINUTE_IN_SECONDS );
	}

	public static function dans_plage_horaire( $maintenant = null ) {
		$reglages = ImagesLegeres_Reglages::tout();
		$debut    = self::minutes_depuis_minuit( $reglages['plage_horaire_debut'] );
		$fin      = self::minutes_depuis_minuit( $reglages['plage_horaire_fin'] );
		$heure    = null === $maintenant ? self::minutes_depuis_minuit( current_time( 'H:i' ) ) : self::minutes_depuis_minuit( $maintenant );

		if ( $debut === $fin ) {
			return true; // plage de 24 h (réglage identique aux deux bornes)
		}

		if ( $debut < $fin ) {
			return $heure >= $debut && $heure < $fin;
		}

		// Plage traversant minuit (ex. 22:00–06:00).
		return $heure >= $debut || $heure < $fin;
	}

	private static function minutes_depuis_minuit( $heure ) {
		$parties = explode( ':', (string) $heure );
		$h       = isset( $parties[0] ) ? (int) $parties[0] : 0;
		$m       = isset( $parties[1] ) ? (int) $parties[1] : 0;

		return $h * 60 + $m;
	}

	public static function charge_trop_elevee() {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return false;
		}

		$charge = sys_getloadavg();

		if ( ! is_array( $charge ) || empty( $charge ) ) {
			return false;
		}

		$seuil = (float) ImagesLegeres_Reglages::valeur( 'charge_max_serveur' );

		return $seuil > 0 && $charge[0] > $seuil;
	}

	/**
	 * Boucle synchrone WP-CLI (ADR 0004 §1) : amorce la file, reprend les lignes bloquées,
	 * refuse de tourner hors plage horaire (sauf $forcer), s'arrête tôt si la charge dépasse
	 * le seuil (reprenable au prochain appel), traite jusqu'à $limite pièces jointes (0 = sans
	 * limite, jusqu'à vider la file).
	 */
	public static function executer_cli( $limite = 0, $forcer = false ) {
		ImagesLegeres_File::amorcer();
		ImagesLegeres_File::reprendre_bloquees();

		if ( ! $forcer && ! self::dans_plage_horaire() ) {
			return array(
				'refuse'   => true,
				'motif'    => 'hors plage horaire',
				'traites'  => 0,
				'resume'   => array(),
				'restants' => ImagesLegeres_File::reste_a_faire(),
			);
		}

		$pause  = (float) ImagesLegeres_Reglages::valeur( 'pause_entre_images_secondes' );
		$resume = array(
			'fait'   => 0,
			'ignore' => 0,
			'erreur' => 0,
		);

		$traites = 0;
		$arret   = '';

		while ( $limite <= 0 || $traites < $limite ) {
			ImagesLegeres_File::reprendre_bloquees();

			if ( ! $forcer && ! self::dans_plage_horaire() ) {
				$arret = 'hors plage horaire';
				break;
			}

			if ( ! $forcer && self::charge_trop_elevee() ) {
				$arret = 'charge serveur trop élevée';
				break;
			}

			$attachment_id = ImagesLegeres_File::prochaine();

			if ( ! $attachment_id ) {
				break;
			}

			$etat = self::traiter_un( $attachment_id );
			++$resume[ $etat ];
			++$traites;

			if ( $pause > 0 && ( $limite <= 0 || $traites < $limite ) && ImagesLegeres_File::reste_a_faire() > 0 ) {
				usleep( (int) round( $pause * 1000000 ) );
			}
		}

		return array(
			'refuse'   => false,
			'motif'    => $arret,
			'traites'  => $traites,
			'resume'   => $resume,
			'restants' => ImagesLegeres_File::reste_a_faire(),
		);
	}

	/**
	 * Remet à `a-faire` les lignes de la file dont la pièce jointe est une image de produit :
	 * gestionnaire de `HOOK_FLUX_ACCROCHE` (ADR 0006 point 7). Ces lignes repassent par
	 * `traiter_un()` au prochain passage de la file, qui régénère leur fichier de flux avec
	 * les réglages à jour et supprime l'ancien. N'amorce ni ne démarre la file : un changement
	 * de réglage ne fait pas démarrer seul un traitement en arrière-plan (même règle qu'à
	 * l'activation de l'extension, ADR 0004).
	 */
	public static function remettre_flux_en_file() {
		ImagesLegeres_File::remettre_images_produit_a_faire();
	}

	/**
	 * Point d'accroche générique (spec § 11 mission 19) : si un réglage de flux a changé,
	 * signale-le. Gestionnaire attaché : `remettre_flux_en_file()` (ADR 0006 point 7).
	 */
	public static function signaler_si_reglages_flux_changes( array $avant, array $apres ) {
		foreach ( self::CLES_REGLAGES_FLUX as $cle ) {
			$valeur_avant = $avant[ $cle ] ?? null;
			$valeur_apres = $apres[ $cle ] ?? null;

			if ( $valeur_avant !== $valeur_apres ) {
				do_action( self::HOOK_FLUX_ACCROCHE, $avant, $apres );
				return true;
			}
		}

		return false;
	}
}
