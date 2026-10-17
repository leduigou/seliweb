<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * File d'attente des mails de notification d'annonce.
 *
 * Contexte : l'hébergeur (O2Switch) limite les envois SMTP à 30/minute.
 * Publier une annonce peut notifier d'un coup tous les membres d'un
 * groupe ayant coché cette notification — potentiellement plus de 30
 * mails envoyés dans la même requête. Plutôt qu'un envoi direct et
 * massif, chaque mail est déposé dans seliweb_mail_queue, puis un cron
 * réglé sur une minute en dépile un petit lot à chaque passage.
 *
 * Volontairement limité aux notifications d'annonces (le seul endroit
 * du plugin où une action déclenche plusieurs mails d'un coup) — les
 * mails à un seul destinataire (contact, inscription, événements…)
 * restent envoyés immédiatement, sans passer par cette file.
 */
class Seliweb_Mail_Queue {

    // Nombre de mails envoyés à chaque passage du cron — nettement sous
    // la limite de 30/minute de l'hébergeur, avec une marge confortable.
    const LOT = 20;

    const CRON_HOOK  = 'seliweb_cron_mail_queue';
    const OPTION_KEY = 'seliweb_mail_queue_last_run';

    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'ajouter_intervalle' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'traiter' ) );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'seliweb_minute', self::CRON_HOOK );
        }
    }

    public static function ajouter_intervalle( $schedules ) {
        $schedules['seliweb_minute'] = array(
            'interval' => 60,
            'display'  => __( 'Toutes les minutes (file de mails Seliweb)', 'seliweb' ),
        );
        return $schedules;
    }

    // Dépose un mail dans la file au lieu de l'envoyer immédiatement.
    public static function ajouter( $destinataire, $sujet, $corps, $headers = array() ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'seliweb_mail_queue', array(
            'destinataire'  => $destinataire,
            'sujet'         => $sujet,
            'corps'         => $corps,
            'headers'       => $headers ? wp_json_encode( $headers ) : null,
            'date_creation' => current_time( 'mysql' ),
        ) );
    }

    // Callable du cron : envoie un lot, puis retire les lignes traitées
    // (succès ou échec — pas de nouvelle tentative, cohérent avec le
    // reste du plugin qui ne fait pas de file de mails à retry).
    public static function traiter() {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_mail_queue';

        $lignes = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table ORDER BY id ASC LIMIT %d", self::LOT
        ) );

        $envoyes = 0;
        foreach ( $lignes as $l ) {
            $headers = $l->headers ? json_decode( $l->headers, true ) : array();
            if ( wp_mail( $l->destinataire, $l->sujet, $l->corps, is_array( $headers ) ? $headers : array() ) ) {
                $envoyes++;
            }
            $wpdb->delete( $table, array( 'id' => $l->id ) );
        }

        update_option( self::OPTION_KEY, array(
            'date'    => current_time( 'mysql' ),
            'envoyes' => $envoyes,
        ) );
    }

    // Nombre de mails actuellement en attente (compte en direct, utile
    // au tableau de bord entre deux passages du cron).
    public static function en_attente() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}seliweb_mail_queue" );
    }
}
