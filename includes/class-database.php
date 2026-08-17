<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Seliweb_Database {

    const DB_VERSION     = '2.8';
    const DB_VERSION_KEY = 'seliweb_db_version';

    public static function install() {
        $installed = get_option( self::DB_VERSION_KEY, '0' );
        if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
            self::create_tables();
            update_option( self::DB_VERSION_KEY, self::DB_VERSION );
        }
    }

    private static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_parametres (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            cle         VARCHAR(100) NOT NULL,
            valeur      TEXT,
            PRIMARY KEY (id),
            UNIQUE KEY cle (cle)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_categories (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nom         VARCHAR(150) NOT NULL,
            slug        VARCHAR(150) NOT NULL,
            modifiable  TINYINT(1)   NOT NULL DEFAULT 1,
            supprimable TINYINT(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_rubriques (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            categorie_id INT UNSIGNED NOT NULL,
            nom          VARCHAR(150) NOT NULL,
            PRIMARY KEY (id),
            KEY categorie_id (categorie_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_statuts (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nom         VARCHAR(100) NOT NULL,
            slug        VARCHAR(100) NOT NULL,
            modifiable  TINYINT(1)   NOT NULL DEFAULT 1,
            supprimable TINYINT(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_monnaies (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nom         VARCHAR(100) NOT NULL,
            symbole     VARCHAR(20),
            est_defaut  TINYINT(1)   NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY nom (nom)
        ) $charset;";

        // est_defaut : 1 = ce groupe est attribué automatiquement aux nouveaux inscrits
        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_groupes (
            id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nom                  VARCHAR(150) NOT NULL,
            est_defaut           TINYINT(1)   NOT NULL DEFAULT 0,
            limite_annonces      INT UNSIGNED DEFAULT NULL,
            contact_mail_cache   TINYINT(1)   NOT NULL DEFAULT 0,
            contact_mail_visible TINYINT(1)   NOT NULL DEFAULT 0,
            contact_tel          TINYINT(1)   NOT NULL DEFAULT 0,
            contact_adresse      TINYINT(1)   NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_groupes_monnaies (
            groupe_id  INT UNSIGNED NOT NULL,
            monnaie_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (groupe_id, monnaie_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_inscriptions (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id    BIGINT UNSIGNED NOT NULL,
            civilite      ENUM('Mr','Mme') DEFAULT NULL,
            nom           VARCHAR(100),
            prenom        VARCHAR(100),
            organisme     VARCHAR(200),
            tel_portable  VARCHAR(30),
            tel_fixe      VARCHAR(30),
            adresse1      VARCHAR(255),
            adresse2      VARCHAR(255),
            ville         VARCHAR(100),
            code_postal   VARCHAR(10),
            PRIMARY KEY (id),
            UNIQUE KEY wp_user_id (wp_user_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_membres (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id       BIGINT UNSIGNED NOT NULL,
            groupe_id        INT UNSIGNED DEFAULT NULL,
            notif_groupes    VARCHAR(255) DEFAULT NULL,
            civilite         ENUM('Mr','Mme') DEFAULT NULL,
            tel_portable     VARCHAR(30),
            tel_fixe         VARCHAR(30),
            adresse1         VARCHAR(255),
            adresse2         VARCHAR(255),
            ville            VARCHAR(100),
            code_postal      VARCHAR(10),
            show_email       TINYINT(1)   NOT NULL DEFAULT 1,
            show_tel_portable TINYINT(1)  NOT NULL DEFAULT 1,
            show_tel_fixe    TINYINT(1)   NOT NULL DEFAULT 1,
            show_adresse     TINYINT(1)   NOT NULL DEFAULT 1,
            numero_sel       INT UNSIGNED DEFAULT NULL,
            decouvert_max    INT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY wp_user_id (wp_user_id),
            KEY groupe_id (groupe_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_annonces (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            membre_id       INT UNSIGNED NOT NULL,
            categorie_id    INT UNSIGNED NOT NULL,
            rubrique_id     INT UNSIGNED DEFAULT NULL,
            type_annonce    ENUM('offre','demande') DEFAULT NULL,
            titre           VARCHAR(255) NOT NULL,
            texte           TEXT,
            statut_id       INT UNSIGNED DEFAULT NULL,
            date_creation   DATETIME     NOT NULL,
            date_expiration DATE         DEFAULT NULL,
            est_don         TINYINT(1)   NOT NULL DEFAULT 0,
            photo1          VARCHAR(255) DEFAULT NULL,
            photo2          VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY membre_id    (membre_id),
            KEY categorie_id (categorie_id),
            KEY statut_id    (statut_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_annonces_photos (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            annonce_id  INT UNSIGNED NOT NULL,
            url         VARCHAR(255) NOT NULL,
            ordre       TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY annonce_id (annonce_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_annonces_prix (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            annonce_id    INT UNSIGNED NOT NULL,
            monnaie_id    INT UNSIGNED NOT NULL,
            prix          VARCHAR(20),
            coordination  ENUM('ET','OU') DEFAULT NULL,
            PRIMARY KEY (id),
            KEY annonce_id (annonce_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_transactions (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            date        DATE         NOT NULL,
            libelle     VARCHAR(255) NOT NULL,
            montant     INT UNSIGNED NOT NULL,
            created_at  DATETIME     NOT NULL,
            modified_at DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            KEY date (date)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_ecritures (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_id  INT UNSIGNED NOT NULL,
            membre_id       INT UNSIGNED NOT NULL,
            type            ENUM('debit','credit') NOT NULL,
            PRIMARY KEY (id),
            KEY transaction_id (transaction_id),
            KEY membre_id (membre_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_cotisations (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id            INT UNSIGNED NOT NULL DEFAULT 0,
            exercice              VARCHAR(20)   DEFAULT NULL,
            libelle               VARCHAR(200)  DEFAULT NULL,
            montant               INT UNSIGNED NOT NULL DEFAULT 0,
            date_paiement         DATE         NOT NULL,
            statut                ENUM('en_attente','paye','echec') NOT NULL DEFAULT 'en_attente',
            helloasso_order_id    VARCHAR(100)  DEFAULT NULL,
            helloasso_payer_email VARCHAR(200)  DEFAULT NULL,
            helloasso_payer_nom   VARCHAR(200)  DEFAULT NULL,
            paheko_synced         TINYINT(1)   NOT NULL DEFAULT 0,
            created_at            DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY wp_user_id (wp_user_id),
            KEY statut (statut),
            KEY exercice (exercice),
            KEY helloasso_order_id (helloasso_order_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_exercices (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            libelle    VARCHAR(100) NOT NULL,
            date_debut DATE         DEFAULT NULL,
            date_fin   DATE         DEFAULT NULL,
            est_actif  TINYINT(1)  NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset;";

        // Catalogue des paiements en ligne (HelloAsso) : adhésion, renouvellement
        // de cotisation, offres (ex. passage au groupe Annonceurs), dons...
        // groupe_depart_id NULL = accessible quel que soit le groupe actuel du
        // membre. groupe_arrivee_id NULL = ne change pas le groupe du membre.
        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_paiements_offres (
            id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nom                    VARCHAR(150) NOT NULL,
            description            TEXT,
            tarifs                 TEXT,
            groupe_depart_id       INT UNSIGNED DEFAULT NULL,
            groupe_arrivee_id      INT UNSIGNED DEFAULT NULL,
            enregistre_cotisation  TINYINT(1)   NOT NULL DEFAULT 0,
            exercice               VARCHAR(20)  DEFAULT NULL,
            consentement_texte     TEXT,
            compte_paheko_banque   VARCHAR(10)  DEFAULT NULL,
            compte_paheko_recette  VARCHAR(10)  DEFAULT NULL,
            PRIMARY KEY (id)
        ) $charset;";

        // Journal des paiements reçus via webhook HelloAsso (adhésion, offre,
        // don...), qu'ils aient pu être rattachés automatiquement à un membre
        // et une offre ou non (statut en_attente = à traiter manuellement).
        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_paiements (
            id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            offre_id             INT UNSIGNED DEFAULT NULL,
            wp_user_id           BIGINT UNSIGNED DEFAULT NULL,
            cotisation_id        INT UNSIGNED DEFAULT NULL,
            montant              INT UNSIGNED NOT NULL DEFAULT 0,
            date_paiement        DATE         NOT NULL,
            statut               ENUM('rattache','en_attente') NOT NULL DEFAULT 'en_attente',
            helloasso_order_id   VARCHAR(100) DEFAULT NULL,
            helloasso_form_slug  VARCHAR(150) DEFAULT NULL,
            payer_email          VARCHAR(200) DEFAULT NULL,
            payer_nom            VARCHAR(200) DEFAULT NULL,
            created_at           DATETIME     NOT NULL,
            PRIMARY KEY (id),
            KEY offre_id (offre_id),
            KEY wp_user_id (wp_user_id),
            KEY statut (statut),
            KEY helloasso_order_id (helloasso_order_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$wpdb->prefix}seliweb_cotisations_reglements (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            cotisation_id  INT UNSIGNED NOT NULL,
            montant        INT          NOT NULL DEFAULT 0,
            monnaie_id     INT UNSIGNED NOT NULL DEFAULT 0,
            mode_paiement  VARCHAR(30)  NOT NULL DEFAULT 'especes',
            coordination   ENUM('ET','OU') DEFAULT NULL,
            PRIMARY KEY (id),
            KEY cotisation_id (cotisation_id)
        ) $charset;";

        foreach ( $sql as $query ) {
            dbDelta( $query );
        }

        // Migration v1.0 → v1.1 : ajout colonne est_defaut si absente
        self::maybe_add_column(
            $wpdb->prefix . 'seliweb_groupes',
            'est_defaut',
            'TINYINT(1) NOT NULL DEFAULT 0 AFTER nom'
        );
        self::maybe_add_column(
            $wpdb->prefix . 'seliweb_annonces_prix',
            'coordination',
            "ENUM('ET','OU') DEFAULT NULL AFTER prix"
        );
        // Créer la table inscriptions si absente (migration v1.2)
        self::maybe_create_inscriptions();

        // Migration v0.6 : nom/prenom/organisme déplacés vers wp_usermeta
        self::maybe_migrate_membres_v06();

        // Migration v0.6 : ajout colonnes préférences de confidentialité
        $tm = $wpdb->prefix . 'seliweb_membres';
        self::maybe_add_column( $tm, 'show_email',        'TINYINT(1) NOT NULL DEFAULT 1 AFTER code_postal' );
        self::maybe_add_column( $tm, 'show_tel_portable', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER show_email' );
        self::maybe_add_column( $tm, 'show_tel_fixe',     'TINYINT(1) NOT NULL DEFAULT 1 AFTER show_tel_portable' );
        self::maybe_add_column( $tm, 'show_adresse',      'TINYINT(1) NOT NULL DEFAULT 1 AFTER show_tel_fixe' );

        // Migration v0.6 : colonnes module SEL
        self::maybe_add_column( $tm, 'numero_sel',    'INT UNSIGNED DEFAULT NULL AFTER show_adresse' );
        self::maybe_add_column( $tm, 'decouvert_max', 'INT UNSIGNED DEFAULT NULL AFTER numero_sel' );
        // Migration: decouvert_max de DECIMAL à INT UNSIGNED
        $col_info = $wpdb->get_row( "SHOW COLUMNS FROM `$tm` LIKE 'decouvert_max'" );
        if ( $col_info && strpos( strtolower( $col_info->Type ), 'decimal' ) !== false ) {
            $wpdb->query( "ALTER TABLE `$tm` MODIFY COLUMN `decouvert_max` INT UNSIGNED DEFAULT NULL" );
        }

        // Migration v1.5 : nouvelles colonnes seliweb_cotisations
        $t_cot = $wpdb->prefix . 'seliweb_cotisations';
        self::maybe_add_column( $t_cot, 'exercice', "VARCHAR(20)  DEFAULT NULL AFTER wp_user_id" );
        self::maybe_add_column( $t_cot, 'libelle',  "VARCHAR(200) DEFAULT NULL AFTER exercice" );

        // Migration v1.7 : colonnes sync Paheko par cotisation
        self::maybe_add_column( $t_cot, 'paheko_id_year', "INT DEFAULT NULL" );
        self::maybe_add_column( $t_cot, 'paheko_id_fee',  "INT DEFAULT NULL" );

        // Migration v1.8 : statuts de synchronisation SEL + exclusion
        self::maybe_add_column( $t_cot, 'sel_synced',  "TINYINT(1) NOT NULL DEFAULT 0" );
        self::maybe_add_column( $t_cot, 'sync_exclu',  "TINYINT(1) NOT NULL DEFAULT 0" );

        // Migration v1.8 : monnaie légale (euro)
        self::maybe_add_column(
            $wpdb->prefix . 'seliweb_monnaies',
            'est_legale',
            "TINYINT(1) NOT NULL DEFAULT 0 AFTER est_defaut"
        );

        // Migration v1.9 : blocage de compte membre
        self::maybe_add_column( $tm, 'bloque', "TINYINT(1) NOT NULL DEFAULT 0" );

        // Migration v2.0 : photos multiples par annonce (liées au groupe),
        // image de rubrique utilisée par défaut si l'annonce n'en a pas.
        $ta = $wpdb->prefix . 'seliweb_annonces';
        self::maybe_add_column( $wpdb->prefix . 'seliweb_rubriques', 'image', "VARCHAR(255) DEFAULT NULL" );
        self::maybe_add_column( $wpdb->prefix . 'seliweb_groupes',   'photos_max', "TINYINT UNSIGNED NOT NULL DEFAULT 1" );
        self::maybe_add_column( $ta, 'photo_principale_id', "INT UNSIGNED DEFAULT NULL AFTER est_don" );
        self::maybe_migrate_photos_v2();
        self::seed_rubrique_images_defaut();

        // Migration v2.1 : date de naissance du membre (facultative)
        self::maybe_add_column( $tm, 'date_naissance', "DATE DEFAULT NULL" );

        // Migration v2.2 : notifications d'annonces par groupe (remplace le
        // booléen global notif_annonces par une liste de groupes cochés).
        self::maybe_add_column( $tm, 'notif_groupes', "VARCHAR(255) DEFAULT NULL AFTER groupe_id" );
        self::maybe_migrate_notif_groupes();

        // Migration v2.5 : exercice concerné par un paiement de cotisation
        // (le lien HelloAsso saisi correspond à un exercice précis).
        self::maybe_add_column(
            $wpdb->prefix . 'seliweb_paiements_offres',
            'exercice',
            "VARCHAR(20) DEFAULT NULL AFTER enregistre_cotisation"
        );

        // Migration v2.6 : synchronisation Paheko des offres (hors cotisations) —
        // compte banque / compte de recette choisis par offre, suivi de sync par paiement.
        self::maybe_add_column( $wpdb->prefix . 'seliweb_paiements_offres', 'compte_paheko_banque',  "VARCHAR(10) DEFAULT NULL" );
        self::maybe_add_column( $wpdb->prefix . 'seliweb_paiements_offres', 'compte_paheko_recette', "VARCHAR(10) DEFAULT NULL" );
        self::maybe_add_column( $wpdb->prefix . 'seliweb_paiements', 'paheko_synced',  "TINYINT(1) NOT NULL DEFAULT 0" );
        self::maybe_add_column( $wpdb->prefix . 'seliweb_paiements', 'paheko_id_year', "INT DEFAULT NULL" );

        // Migration v2.7 : paiement via l'API Checkout Intent HelloAsso —
        // procédure unique pour toutes les offres (cotisations comprises).
        // Remplace le tarif en texte libre par des tarifs structurés (un ou
        // plusieurs, montant réel envoyé à HelloAsso), ajoute un texte de
        // consentement optionnel (ex. adhésion) et un indicateur "don". Le
        // lien vers un formulaire HelloAsso n'est plus nécessaire : le
        // paiement est généré dynamiquement à chaque clic.
        self::maybe_add_column( $wpdb->prefix . 'seliweb_paiements_offres', 'tarifs', "TEXT AFTER description" );
        self::maybe_add_column( $wpdb->prefix . 'seliweb_paiements_offres', 'consentement_texte', "TEXT" );
        self::maybe_add_column( $wpdb->prefix . 'seliweb_paiements_offres', 'est_don', "TINYINT(1) NOT NULL DEFAULT 0" );
        self::maybe_drop_column( $wpdb->prefix . 'seliweb_paiements_offres', 'tarif' );
        self::maybe_drop_column( $wpdb->prefix . 'seliweb_paiements_offres', 'helloasso_url' );

        // Migration v2.8 : "Paiements" devient "Abonnements" — les offres sont
        // désormais génériques (cotisation, don, ou autre). Le champ "don" est
        // retiré (un don se distingue simplement par son titre) et les comptes
        // Paheko banque/recette sont saisis pour tous les abonnements, cotisations
        // comprises (la synchronisation Paheko des cotisations reste néanmoins
        // inchangée, basée sur exercice + service/tarif Paheko).
        self::maybe_drop_column( $wpdb->prefix . 'seliweb_paiements_offres', 'est_don' );

        self::insert_defaults();
    }

    /**
     * Migration v2.0 : reprend les photo1/photo2 existantes de chaque annonce
     * dans la nouvelle table seliweb_annonces_photos (ordre 0/1), la photo1
     * devenant la photo principale (comportement inchangé pour les annonces
     * déjà publiées). Ne s'exécute que si les colonnes existent encore
     * (migration déjà faite sinon).
     */
    private static function maybe_migrate_photos_v2() {
        global $wpdb;
        $ta  = $wpdb->prefix . 'seliweb_annonces';
        $tap = $wpdb->prefix . 'seliweb_annonces_photos';

        $cols = $wpdb->get_col( "DESCRIBE `$ta`", 0 );
        if ( ! in_array( 'photo1', $cols, true ) ) return; // déjà migré

        $rows = $wpdb->get_results( "SELECT id, photo1, photo2 FROM `$ta` WHERE photo1 IS NOT NULL OR photo2 IS NOT NULL" );
        foreach ( $rows as $row ) {
            $principale_id = null;
            if ( ! empty( $row->photo1 ) ) {
                $wpdb->insert( $tap, array( 'annonce_id' => $row->id, 'url' => $row->photo1, 'ordre' => 0 ) );
                $principale_id = $wpdb->insert_id;
            }
            if ( ! empty( $row->photo2 ) ) {
                $wpdb->insert( $tap, array( 'annonce_id' => $row->id, 'url' => $row->photo2, 'ordre' => 1 ) );
            }
            if ( $principale_id ) {
                $wpdb->update( $ta, array( 'photo_principale_id' => $principale_id ), array( 'id' => $row->id ) );
            }
        }

        self::maybe_drop_column( $ta, 'photo1' );
        self::maybe_drop_column( $ta, 'photo2' );
    }

    /**
     * Associe à chaque rubrique sans image une image par défaut bundlée
     * dans assets/img/rubriques/{Nom de la rubrique}.{jpg|jpeg|png|gif|webp},
     * si le fichier existe. Idempotent — ne touche jamais une rubrique qui a
     * déjà une image (personnalisée ou déjà seedée), donc peut être rappelée
     * sans risque à chaque fois que de nouvelles images par défaut sont
     * ajoutées au plugin.
     */
    public static function seed_rubrique_images_defaut() {
        global $wpdb;
        $tr  = $wpdb->prefix . 'seliweb_rubriques';
        $dir = SELIWEB_DIR . 'assets/img/rubriques/';
        if ( ! is_dir( $dir ) ) return;

        $rubriques = $wpdb->get_results( "SELECT id, nom FROM `$tr` WHERE image IS NULL" );
        $ext_autorisees = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

        foreach ( $rubriques as $r ) {
            foreach ( $ext_autorisees as $ext ) {
                $fichier = $dir . $r->nom . '.' . $ext;
                if ( file_exists( $fichier ) ) {
                    $url = SELIWEB_URL . 'assets/img/rubriques/' . rawurlencode( $r->nom . '.' . $ext );
                    $wpdb->update( $tr, array( 'image' => $url ), array( 'id' => $r->id ) );
                    break;
                }
            }
        }
    }

    /**
     * Migration v2.2 : les membres qui recevaient déjà les annonces par mail
     * (notif_annonces=1) basculent sur notif_groupes = leur propre groupe,
     * ce qui reproduit le principal cas d'usage réel (recevoir les annonces
     * de son groupe) sans notifier tous les autres groupes comme avant.
     */
    private static function maybe_migrate_notif_groupes() {
        global $wpdb;
        $tm   = $wpdb->prefix . 'seliweb_membres';
        $cols = $wpdb->get_col( "DESCRIBE `$tm`", 0 );
        if ( ! in_array( 'notif_annonces', $cols, true ) ) return; // déjà migré

        $membres = $wpdb->get_results( "SELECT id, groupe_id, notif_annonces FROM `$tm`" );
        foreach ( $membres as $m ) {
            if ( (int) $m->notif_annonces === 1 && $m->groupe_id ) {
                $wpdb->update( $tm, array( 'notif_groupes' => (string) intval( $m->groupe_id ) ), array( 'id' => $m->id ) );
            }
        }

        self::maybe_drop_column( $tm, 'notif_annonces' );
    }

    /**
     * Ajoute une colonne si elle n'existe pas déjà (migration safe)
     */
    private static function maybe_add_column( $table, $column, $definition ) {
        global $wpdb;
        $cols = $wpdb->get_col( "DESCRIBE `$table`", 0 );
        if ( ! in_array( $column, $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `$column` $definition" );
        }
    }

    /**
     * Supprime une colonne si elle existe encore (migration safe)
     */
    private static function maybe_drop_column( $table, $column ) {
        global $wpdb;
        $cols = $wpdb->get_col( "DESCRIBE `$table`", 0 );
        if ( in_array( $column, $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `$table` DROP COLUMN `$column`" );
        }
    }

    /**
     * Migration v0.6 : déplace nom/prenom/organisme de seliweb_membres vers wp_usermeta.
     * Ne s'exécute que si la colonne nom existe encore.
     */
    private static function maybe_migrate_membres_v06() {
        global $wpdb;
        $tm   = $wpdb->prefix . 'seliweb_membres';
        $cols = $wpdb->get_col( "DESCRIBE `$tm`", 0 );

        if ( ! in_array( 'nom', $cols, true ) ) {
            return; // Déjà migré
        }

        $membres = $wpdb->get_results( "SELECT wp_user_id, nom, prenom, organisme FROM `$tm`" );
        foreach ( $membres as $m ) {
            if ( $m->prenom )    update_user_meta( $m->wp_user_id, 'first_name',          $m->prenom );
            if ( $m->nom )       update_user_meta( $m->wp_user_id, 'last_name',           $m->nom );
            if ( $m->organisme ) update_user_meta( $m->wp_user_id, 'seliweb_organisme',   $m->organisme );
            if ( $m->nom && $m->prenom ) {
                wp_update_user( array(
                    'ID'           => $m->wp_user_id,
                    'display_name' => $m->prenom . ' ' . $m->nom,
                ) );
            }
        }

        self::maybe_drop_column( $tm, 'nom' );
        self::maybe_drop_column( $tm, 'prenom' );
        self::maybe_drop_column( $tm, 'organisme' );
    }

    private static function insert_defaults() {
        global $wpdb;

        // Catégories — INSERT IGNORE fonctionne grâce à UNIQUE KEY slug
        $categories = array(
            array( 'nom' => 'Annonces',      'slug' => 'annonces',    'modifiable' => 0, 'supprimable' => 0 ),
            array( 'nom' => 'Objets (prêt)', 'slug' => 'objets-pret', 'modifiable' => 1, 'supprimable' => 1 ),
            array( 'nom' => 'Compétences',   'slug' => 'competences', 'modifiable' => 1, 'supprimable' => 1 ),
        );
        foreach ( $categories as $cat ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}seliweb_categories (nom,slug,modifiable,supprimable) VALUES (%s,%s,%d,%d)",
                $cat['nom'], $cat['slug'], $cat['modifiable'], $cat['supprimable']
            ) );
        }

        // Rubriques par défaut — injectées seulement si la table est vide
        $tr = $wpdb->prefix . 'seliweb_rubriques';
        $nb_rubriques = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tr" );
        if ( $nb_rubriques === 0 ) {
            // Récupérer les IDs des catégories par slug
            $tc      = $wpdb->prefix . 'seliweb_categories';
            $cat_ids = array();
            foreach ( array('annonces','objets-pret','competences') as $slug ) {
                $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tc WHERE slug=%s LIMIT 1", $slug ) );
                if ( $id ) $cat_ids[$slug] = (int)$id;
            }

            $rubriques_defaut = array(
                'annonces' => array(
                    'Aide à domicile', 'Aide administrative', 'Animaux', 'Autres',
                    'Bricolage - Mécanique', 'Informatique - Téléphonie', 'Jardinage',
                    'Loisirs - Musique', 'Maison - cuisine - nourriture', 'Objets divers',
                    'Prêts outillage et autres objets', 'Santé - Bien-être',
                    'Scolaire - Langues', 'Transports - Covoiturage',
                    'Travaux manuels - couture', 'Culture - activités culturelles',
                    'Beauté - Coiffure', 'Hébergement', 'Sorties',
                    'Garde d\'enfants - Baby sitting',
                ),
                'objets-pret' => array(
                    'Matériel électrique', 'Auto-Vélo', 'Sport',
                ),
                'competences' => array(
                    'Tapisserie', 'Electricité', 'Peinture', 'Plomberie', 'Carrelage',
                    'Placoplâtre', 'Menuiserie', 'Maçonnerie', 'Vitrerie', 'Serrurerie',
                    'Etanchéïté', 'Jardinage', 'Cuisine', 'Couture', 'Autre',
                    'Langues', 'Massage - Santé - Bien être',
                ),
            );

            foreach ( $rubriques_defaut as $slug => $liste ) {
                if ( ! isset( $cat_ids[$slug] ) ) continue;
                foreach ( $liste as $nom ) {
                    $wpdb->insert( $tr, array(
                        'categorie_id' => $cat_ids[$slug],
                        'nom'          => $nom,
                    ) );
                }
            }
        }

        // Statuts — INSERT IGNORE fonctionne grâce à UNIQUE KEY slug
        $statuts = array(
            array( 'nom' => 'Urgent',    'slug' => 'urgent',    'modifiable' => 0, 'supprimable' => 0 ),
            array( 'nom' => 'Répondu',  'slug' => 'repondu',  'modifiable' => 0, 'supprimable' => 0 ),
            array( 'nom' => 'Expiré',   'slug' => 'expire',   'modifiable' => 0, 'supprimable' => 0 ),
            array( 'nom' => 'Permanent', 'slug' => 'permanent', 'modifiable' => 1, 'supprimable' => 1 ),
        );
        foreach ( $statuts as $st ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}seliweb_statuts (nom,slug,modifiable,supprimable) VALUES (%s,%s,%d,%d)",
                $st['nom'], $st['slug'], $st['modifiable'], $st['supprimable']
            ) );
        }

        // Monnaies — vérification explicite par nom pour éviter les doublons
        // (UNIQUE KEY nom ajouté en v1.2, migration des index via maybe_add_unique)
        $monnaies = array(
            array( 'nom' => 'Euro', 'symbole' => '€',   'est_defaut' => 1 ),
            array( 'nom' => 'Sel',  'symbole' => 'SEL', 'est_defaut' => 0 ),
            array( 'nom' => 'June', 'symbole' => 'Ğ',   'est_defaut' => 0 ),
        );
        foreach ( $monnaies as $mon ) {
            $existe = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}seliweb_monnaies WHERE nom=%s LIMIT 1",
                $mon['nom']
            ) );
            if ( ! $existe ) {
                $wpdb->insert( "{$wpdb->prefix}seliweb_monnaies", array(
                    'nom'        => $mon['nom'],
                    'symbole'    => $mon['symbole'],
                    'est_defaut' => $mon['est_defaut'],
                ) );
            }
        }
    }

    public static function get_installed_version() {
        return get_option( self::DB_VERSION_KEY, '0' );
    }

    // ================================================================
    // Création automatique des pages front-end et du menu
    // Appelée à l'activation du plugin (register_activation_hook)
    // ================================================================
    public static function create_pages_and_menu() {

        // ---- Pages à créer ----
        $pages = array(
            array(
                'title'    => __( 'Annonces', 'seliweb' ),
                'key'      => 'seliweb_annonces',
                'slug'     => 'annonces-sel',
                'content'  => '',
                'template' => 'template-annonces.php',
            ),
            array(
                'title'   => __( 'Connexion', 'seliweb' ),
                'key'     => 'seliweb_login',
                'slug'    => 'connexion-sel',
                'content' => '[seliweb_login]',
            ),
            array(
                'title'   => __( "S'inscrire", 'seliweb' ),
                'key'     => 'seliweb_inscription',
                'slug'    => 'inscription-sel',
                'content' => '[seliweb_inscription]',
            ),
            array(
                'title'   => __( 'Mon compte', 'seliweb' ),
                'key'     => 'seliweb_mon_compte',
                'slug'    => 'mon-compte-sel',
                'content' => '[seliweb_mon_compte]',
            ),
        );

        $page_ids = array();
        foreach ( $pages as $page ) {
            // Ne créer la page que si elle n'existe pas déjà
            $existing = get_page_by_path( $page['slug'] );
            if ( $existing ) {
                $page_ids[ $page['key'] ] = $existing->ID;
                if ( ! empty( $page['template'] ) ) {
                    update_post_meta( $existing->ID, '_wp_page_template', $page['template'] );
                }
                continue;
            }
            $id = wp_insert_post( array(
                'post_title'   => $page['title'],
                'post_content' => $page['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $page['slug'],
            ) );
            if ( ! is_wp_error( $id ) ) {
                $page_ids[ $page['key'] ] = $id;
                if ( ! empty( $page['template'] ) ) {
                    update_post_meta( $id, '_wp_page_template', $page['template'] );
                }
            }
        }

        // Sauvegarder les IDs pour usage ultérieur
        update_option( 'seliweb_page_ids', $page_ids );

        // ---- Menu "Seliweb Navigation" ----
        $menu_name = __( 'Seliweb Navigation', 'seliweb' );
        $menu_id   = wp_create_nav_menu( $menu_name );

        if ( ! is_wp_error( $menu_id ) ) {
            // Ordre des éléments du menu
            $menu_items = array(
                'seliweb_annonces'    => __( 'Annonces', 'seliweb' ),
                'seliweb_login'       => __( 'Connexion', 'seliweb' ),
                'seliweb_inscription' => __( "S'inscrire", 'seliweb' ),
                'seliweb_mon_compte'  => __( 'Mon compte', 'seliweb' ),
            );
            $order = 1;
            foreach ( $menu_items as $shortcode => $label ) {
                if ( isset( $page_ids[ $shortcode ] ) ) {
                    wp_update_nav_menu_item( $menu_id, 0, array(
                        'menu-item-title'     => $label,
                        'menu-item-object'    => 'page',
                        'menu-item-object-id' => $page_ids[ $shortcode ],
                        'menu-item-type'      => 'post_type',
                        'menu-item-status'    => 'publish',
                        'menu-item-position'  => $order++,
                    ) );
                }
            }

            // Assigner le menu à l'emplacement "primary" du thème actif si disponible
            $locations = get_theme_mod( 'nav_menu_locations' );
            if ( isset( $locations['primary'] ) && empty( $locations['primary'] ) ) {
                $locations['primary'] = $menu_id;
                set_theme_mod( 'nav_menu_locations', $locations );
            }

            update_option( 'seliweb_menu_id', $menu_id );
        }
    }

    // ================================================================
    // Suppression des pages et du menu à la désactivation
    // ================================================================
    public static function delete_pages_and_menu() {
        $page_ids = get_option( 'seliweb_page_ids', array() );
        foreach ( $page_ids as $id ) {
            wp_delete_post( $id, true );
        }
        delete_option( 'seliweb_page_ids' );

        $menu_id = get_option( 'seliweb_menu_id' );
        if ( $menu_id ) {
            wp_delete_nav_menu( $menu_id );
            delete_option( 'seliweb_menu_id' );
        }
    }

    /**
     * Force l'ajout de la colonne coordination si absente.
     * Appelée sur admin_init pour s'assurer de l'exécution même
     * si DB_VERSION n'a pas changé.
     */
    public static function insert_defaults_rubriques() {
        global $wpdb;
        $tr = $wpdb->prefix . 'seliweb_rubriques';
        $nb = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tr" );
        if ( $nb > 0 ) return; // Ne pas écraser si déjà remplies

        $tc      = $wpdb->prefix . 'seliweb_categories';
        $cat_ids = array();
        foreach ( array('annonces','objets-pret','competences') as $slug ) {
            $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tc WHERE slug=%s LIMIT 1", $slug ) );
            if ( $id ) $cat_ids[$slug] = (int)$id;
        }

        $rubriques_defaut = array(
            'annonces' => array(
                'Aide à domicile', 'Aide administrative', 'Animaux', 'Autres',
                'Bricolage - Mécanique', 'Informatique - Téléphonie', 'Jardinage',
                'Loisirs - Musique', 'Maison - cuisine - nourriture', 'Objets divers',
                'Prêts outillage et autres objets', 'Santé - Bien-être',
                'Scolaire - Langues', 'Transports - Covoiturage',
                'Travaux manuels - couture', 'Culture - activités culturelles',
                'Beauté - Coiffure', 'Hébergement', 'Sorties',
                'Garde d\'enfants - Baby sitting',
            ),
            'objets-pret' => array(
                'Matériel électrique', 'Auto-Vélo', 'Sport',
            ),
            'competences' => array(
                'Tapisserie', 'Electricité', 'Peinture', 'Plomberie', 'Carrelage',
                'Placoplâtre', 'Menuiserie', 'Maçonnerie', 'Vitrerie', 'Serrurerie',
                'Etanchéïté', 'Jardinage', 'Cuisine', 'Couture', 'Autre',
                'Langues', 'Massage - Santé - Bien être',
            ),
        );

        foreach ( $rubriques_defaut as $slug => $liste ) {
            if ( ! isset( $cat_ids[$slug] ) ) continue;
            foreach ( $liste as $nom ) {
                $wpdb->insert( $tr, array(
                    'categorie_id' => $cat_ids[$slug],
                    'nom'          => $nom,
                ) );
            }
        }
    }

    public static function maybe_create_inscriptions() {
        global $wpdb;
        $table   = $wpdb->prefix . 'seliweb_inscriptions';
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE $table (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id    BIGINT UNSIGNED NOT NULL,
            civilite      ENUM('Mr','Mme') DEFAULT NULL,
            nom           VARCHAR(100),
            prenom        VARCHAR(100),
            organisme     VARCHAR(200),
            tel_portable  VARCHAR(30),
            tel_fixe      VARCHAR(30),
            adresse1      VARCHAR(255),
            adresse2      VARCHAR(255),
            ville         VARCHAR(100),
            code_postal   VARCHAR(10),
            PRIMARY KEY (id),
            UNIQUE KEY wp_user_id (wp_user_id)
        ) $charset;";
        dbDelta( $sql );
    }

    public static function maybe_migrate_coordination() {
        global $wpdb;

        // 1. Colonne coordination sur seliweb_annonces_prix
        $table = $wpdb->prefix . 'seliweb_annonces_prix';
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = %s AND table_name = %s",
            DB_NAME, $table
        ) );
        if ( $exists ) {
            $cols = $wpdb->get_col( "DESCRIBE `$table`", 0 );
            if ( ! in_array( 'coordination', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `coordination` ENUM('ET','OU') DEFAULT NULL AFTER `prix`" );
            }
        }

        // 1a. Injecter les rubriques par défaut si la table est vide
        self::insert_defaults_rubriques();

        // 1b. Mettre à jour modifiable/supprimable des catégories Objets et Compétences
        $tc = $wpdb->prefix . 'seliweb_categories';
        $wpdb->query( $wpdb->prepare(
            "UPDATE `$tc` SET modifiable=1, supprimable=1 WHERE slug IN (%s, %s)",
            'objets-pret', 'competences'
        ) );

        // 1c. Migration table monnaies : remplacer modifiable/supprimable par est_defaut
        $tmon = $wpdb->prefix . 'seliweb_monnaies';
        $cols_mon = $wpdb->get_col( "DESCRIBE `$tmon`", 0 );
        // Ajouter est_defaut si absente
        if ( ! in_array( 'est_defaut', $cols_mon, true ) ) {
            $wpdb->query( "ALTER TABLE `$tmon` ADD COLUMN `est_defaut` TINYINT(1) NOT NULL DEFAULT 0 AFTER symbole" );
            // Marquer Euro comme défaut par défaut
            $wpdb->query( $wpdb->prepare(
                "UPDATE `$tmon` SET est_defaut=1 WHERE nom=%s LIMIT 1", 'Euro'
            ) );
        }
        // Supprimer anciennes colonnes si elles existent encore
        if ( in_array( 'modifiable', $cols_mon, true ) ) {
            $wpdb->query( "ALTER TABLE `$tmon` DROP COLUMN `modifiable`" );
        }
        if ( in_array( 'supprimable', $cols_mon, true ) ) {
            $wpdb->query( "ALTER TABLE `$tmon` DROP COLUMN `supprimable`" );
        }
        // Ajouter UNIQUE KEY nom si absente
        $index_mon = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema=DATABASE() AND table_name='$tmon' AND index_name='nom'"
        );
        if ( ! $index_mon ) {
            $wpdb->query( "DELETE m1 FROM `$tmon` m1 INNER JOIN `$tmon` m2 WHERE m1.id > m2.id AND m1.nom = m2.nom" );
            $wpdb->query( "ALTER TABLE `$tmon` ADD UNIQUE KEY nom (nom)" );
        }

        // 2. Nouvelles colonnes sur seliweb_membres (migration champs profil)
        $tm = $wpdb->prefix . 'seliweb_membres';
        $tm_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=%s AND table_name=%s",
            DB_NAME, $tm
        ) );
        if ( $tm_exists ) {
            $cols = $wpdb->get_col( "DESCRIBE `$tm`", 0 );
            $new_cols = array(
                'civilite'     => "ENUM('Mr','Mme') DEFAULT NULL AFTER notif_annonces",
                'nom'          => "VARCHAR(100) DEFAULT NULL AFTER civilite",
                'prenom'       => "VARCHAR(100) DEFAULT NULL AFTER nom",
                'organisme'    => "VARCHAR(200) DEFAULT NULL AFTER prenom",
                'tel_portable' => "VARCHAR(30)  DEFAULT NULL AFTER organisme",
                'tel_fixe'     => "VARCHAR(30)  DEFAULT NULL AFTER tel_portable",
                'adresse1'     => "VARCHAR(255) DEFAULT NULL AFTER tel_fixe",
                'adresse2'     => "VARCHAR(255) DEFAULT NULL AFTER adresse1",
                'code_postal'  => "VARCHAR(10)  DEFAULT NULL AFTER ville",
            );
            foreach ( $new_cols as $col => $def ) {
                if ( ! in_array( $col, $cols, true ) ) {
                    $wpdb->query( "ALTER TABLE `$tm` ADD COLUMN `$col` $def" );
                }
            }
            // Migrer l'ancienne colonne 'telephone' -> 'tel_portable' si elle existe
            if ( in_array( 'telephone', $cols, true ) && ! in_array( 'tel_portable', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE `$tm` CHANGE `telephone` `tel_portable` VARCHAR(30) DEFAULT NULL" );
            }
            // Migrer l'ancienne colonne 'adresse' -> 'adresse1' si elle existe
            if ( in_array( 'adresse', $cols, true ) && ! in_array( 'adresse1', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE `$tm` CHANGE `adresse` `adresse1` VARCHAR(255) DEFAULT NULL" );
            }
        }

        // 3. UNIQUE KEY nom sur seliweb_monnaies (évite les doublons à la réactivation)
        $tm = $wpdb->prefix . 'seliweb_monnaies';
        $tm_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = %s AND table_name = %s",
            DB_NAME, $tm
        ) );
        if ( $tm_exists ) {
            $index = $wpdb->get_var(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = '$tm'
                   AND index_name = 'nom'"
            );
            if ( ! $index ) {
                // Supprimer les doublons éventuels avant d'ajouter la contrainte
                $wpdb->query(
                    "DELETE m1 FROM `$tm` m1
                     INNER JOIN `$tm` m2
                     WHERE m1.id > m2.id AND m1.nom = m2.nom"
                );
                $wpdb->query( "ALTER TABLE `$tm` ADD UNIQUE KEY nom (nom)" );
            }
        }
    }

}

add_action( 'plugins_loaded', array( 'Seliweb_Database', 'install' ) );
add_action( 'admin_init',     array( 'Seliweb_Database', 'maybe_migrate_coordination' ) );
