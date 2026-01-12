<?php
/**
 * Klasa zarządzająca menu administracyjnym wtyczki
 *
 * @package PhotoJob_Organizer
 */

// Zabezpieczenie przed bezpośrednim dostępem
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Klasa PhotoJob_Admin_Menu
 */
class PhotoJob_Admin_Menu {

    /**
     * Instancja singletona
     */
    private static $instance = null;

    /**
     * Pobierz instancję singletona
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /**
     * Dodaj strony menu
     */
    public function add_menu_pages() {
        // Główne menu wtyczki
        add_menu_page(
            __( 'PhotoJob Organizer', 'photojob-organizer' ),
            __( 'PhotoJob', 'photojob-organizer' ),
            'manage_woocommerce',
            'photojob-organizer',
            array( $this, 'render_main_page' ),
            'dashicons-camera',
            56
        );

        // Podstrona: Raport księgowy
        add_submenu_page(
            'photojob-organizer',
            __( 'Raport księgowy', 'photojob-organizer' ),
            __( 'Raport księgowy', 'photojob-organizer' ),
            'manage_woocommerce',
            'photojob-accounting-report',
            array( $this, 'render_accounting_report_page' )
        );

        // Zamień nazwę pierwszego submenu
        global $submenu;
        if ( isset( $submenu['photojob-organizer'] ) ) {
            $submenu['photojob-organizer'][0][0] = __( 'Przegląd', 'photojob-organizer' );
        }
    }

    /**
     * Renderuj główną stronę
     */
    public function render_main_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <div class="card">
                <h2><?php _e( 'Witaj w PhotoJob Organizer', 'photojob-organizer' ); ?></h2>
                <p><?php _e( 'Narzędzie do organizacji zamówień fotograficznych i generowania raportów księgowych.', 'photojob-organizer' ); ?></p>

                <h3><?php _e( 'Dostępne funkcje:', 'photojob-organizer' ); ?></h3>
                <ul>
                    <li>
                        <strong><?php _e( 'Raport księgowy', 'photojob-organizer' ); ?></strong> -
                        <?php _e( 'Generuj zestawienia transakcji dla księgowej z wybranego zakresu dat.', 'photojob-organizer' ); ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=photojob-accounting-report' ) ); ?>" class="button button-primary">
                            <?php _e( 'Przejdź do raportu', 'photojob-organizer' ); ?>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="card">
                <h3><?php _e( 'Wymagania', 'photojob-organizer' ); ?></h3>
                <ul>
                    <li>
                        <?php
                        if ( class_exists( 'WooCommerce' ) ) {
                            echo '<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ';
                            _e( 'WooCommerce jest aktywne', 'photojob-organizer' );
                        } else {
                            echo '<span class="dashicons dashicons-dismiss" style="color: red;"></span> ';
                            _e( 'WooCommerce nie jest aktywne', 'photojob-organizer' );
                        }
                        ?>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Renderuj stronę raportu księgowego
     */
    public function render_accounting_report_page() {
        // Załaduj klasę strony raportu
        $accounting_report = PhotoJob_Accounting_Report_Page::get_instance();
        $accounting_report->render();
    }

    /**
     * Załaduj zasoby administracyjne (CSS, JS)
     */
    public function enqueue_admin_assets( $hook ) {
        // Sprawdź czy jesteśmy na stronie wtyczki
        if ( strpos( $hook, 'photojob' ) === false ) {
            return;
        }

        // Dodaj style
        wp_enqueue_style(
            'photojob-admin-styles',
            PHOTOJOB_PLUGIN_URL . 'admin/css/admin-styles.css',
            array(),
            PHOTOJOB_VERSION
        );

        // Dodaj skrypty
        wp_enqueue_script(
            'photojob-admin-scripts',
            PHOTOJOB_PLUGIN_URL . 'admin/js/admin-scripts.js',
            array( 'jquery' ),
            PHOTOJOB_VERSION,
            true
        );
    }
}
