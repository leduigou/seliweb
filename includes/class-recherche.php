<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Recherche plein-texte dans les annonces (titre + texte).
 *
 * Le moteur est Seliweb_Annonces::get_annonces_publiques() via le paramètre
 * « q » (voir class-annonces.php). Le rendu du champ est swv_render_search_box()
 * (class-front.php), partagé avec les emplacements « barre de filtres » et
 * « barre liste/colonnes » réglés dans Réglages Seliweb > Annonces > Affichage.
 *
 * Cette classe n'ajoute que le widget de barre latérale, prévu pour être
 * placé — au choix de l'administrateur — à la place du bloc « Recherche »
 * natif de WordPress. La recherche WordPress native (posts/pages) n'est pas
 * modifiée : c'est un outil distinct.
 */
class Seliweb_Recherche {

    public static function init() {
        add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );
    }

    public static function register_widget() {
        register_widget( 'Seliweb_Recherche_Widget' );
    }
}

class Seliweb_Recherche_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'seliweb_recherche',
            __( 'Recherche d’annonces (Seliweb)', 'seliweb' ),
            array(
                'description' => __( 'Champ de recherche dans le titre et le texte des annonces. À placer, par exemple, à la place du bloc « Recherche » natif de WordPress.', 'seliweb' ),
                'classname'   => 'swv-widget-recherche',
            )
        );
    }

    public function widget( $args, $instance ) {
        if ( ! function_exists( 'swv_render_search_box' ) ) {
            return;
        }

        $titre = isset( $instance['title'] ) ? $instance['title'] : __( 'Rechercher une annonce', 'seliweb' );
        $titre = apply_filters( 'widget_title', $titre, $instance, $this->id_base );

        echo $args['before_widget']; // markup du thème, déjà sûr

        if ( $titre !== '' ) {
            echo $args['before_title'] . esc_html( $titre ) . $args['after_title'];
        }

        swv_render_search_box( array( 'class' => 'swv-searchbox-widget' ) );

        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title = isset( $instance['title'] ) ? $instance['title'] : __( 'Rechercher une annonce', 'seliweb' );
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
                <?php esc_html_e( 'Titre :', 'seliweb' ); ?>
            </label>
            <input class="widefat" type="text"
                   id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                   value="<?php echo esc_attr( $title ); ?>">
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        return array(
            'title' => sanitize_text_field( $new_instance['title'] ?? '' ),
        );
    }
}
