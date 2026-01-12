<?php
/**
 * Plugin Name: PhotoJob Organizer
 * Plugin URI: https://github.com/yourusername/photojob-organizer
 * Description: Narzędzie do organizacji zamówień fotograficznych i generowania raportów księgowych
 * Version: 1.0.0
 * Author: Twoje Imię
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: photojob-organizer
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Zabezpieczenie przed bezpośrednim dostępem
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Definicje stałych
define( 'PHOTOJOB_VERSION', '1.0.0' );
define( 'PHOTOJOB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PHOTOJOB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PHOTOJOB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Klasa główna wtyczki PhotoJob Organizer
 */
class PhotoJob_Organizer {

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
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Załaduj zależności
     */
    private function load_dependencies() {
        require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-accounting-table-generator.php';
        require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-excel-exporter.php';

        if ( is_admin() ) {
            require_once PHOTOJOB_PLUGIN_DIR . 'admin/class-admin-menu.php';
            require_once PHOTOJOB_PLUGIN_DIR . 'admin/class-accounting-report-page.php';
        }
    }

    /**
     * Inicjalizuj hooki
     */
    private function init_hooks() {
        add_action( 'plugins_loaded', array( $this, 'check_requirements' ) );
        add_action( 'init', array( $this, 'load_textdomain' ) );

        if ( is_admin() ) {
            PhotoJob_Admin_Menu::get_instance();
        }
    }

    /**
     * Sprawdź wymagania wtyczki
     */
    public function check_requirements() {
        // Sprawdź czy WooCommerce jest aktywne
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return false;
        }

        return true;
    }

    /**
     * Powiadomienie o braku WooCommerce
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e( 'PhotoJob Organizer wymaga aktywnej wtyczki WooCommerce.', 'photojob-organizer' ); ?></p>
        </div>
        <?php
    }

    /**
     * Załaduj tłumaczenia
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'photojob-organizer',
            false,
            dirname( PHOTOJOB_PLUGIN_BASENAME ) . '/languages'
        );
    }
}

/**
 * Uruchom wtyczkę
 */
function photojob_organizer() {
    return PhotoJob_Organizer::get_instance();
}

// Inicjalizuj wtyczkę
photojob_organizer();
