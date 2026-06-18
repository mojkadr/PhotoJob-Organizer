<?php
/**
 * Plugin Name: PhotoJob Organizer
 * Plugin URI: https://github.com/yourusername/photojob-organizer
 * Description: Narzędzie do organizacji zamówień fotograficznych i generowania raportów księgowych
 * Version: 1.5.1
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
define( 'PHOTOJOB_VERSION', '1.5.1' );
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
        // Core (Faza A v1.1.0+)
        require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-pjo-roles.php';
        require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-pjo-activator.php';
        require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-pjo-deactivator.php';
        require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-pjo-wc-inspector.php';

        // Folder Builder (Faza C v1.5.1)
        require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-pjo-qnap-client.php';
        require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-pjo-folder-builder.php';

        // Accounting (v1.0.x)
        require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-accounting-table-generator.php';
        require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-excel-exporter.php';

        if ( is_admin() ) {
            require_once PHOTOJOB_PLUGIN_DIR . 'admin/class-admin-menu.php';
            require_once PHOTOJOB_PLUGIN_DIR . 'admin/class-accounting-report-page.php';
            require_once PHOTOJOB_PLUGIN_DIR . 'admin/class-pjo-settings.php';
            require_once PHOTOJOB_PLUGIN_DIR . 'admin/class-pjo-orders-dashboard.php';
        }
    }

    /**
     * Inicjalizuj hooki
     */
    private function init_hooks() {
        add_action( 'plugins_loaded', array( $this, 'check_requirements' ) );
        add_action( 'plugins_loaded', array( $this, 'init_admin_modules' ) );
        add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_db' ) );
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_dependencies' ) );

        // Sync customer note z WC do naszej tabeli (cache do dashboardu)
        add_action( 'woocommerce_new_order', array( $this, 'sync_order_meta' ), 10, 1 );
        add_action( 'woocommerce_update_order', array( $this, 'sync_order_meta' ), 10, 1 );
    }

    /**
     * Załaduj WP Media Library na stronie settings (potrzebne dla wyboru grafiki etykiety)
     */
    public function enqueue_admin_dependencies( $hook ) {
        if ( strpos( $hook, 'photojob-settings' ) !== false ) {
            wp_enqueue_media();
        }
    }

    /**
     * Synchronizuj uwagi klienta i wykryj "wants_invoice" (po heurystyce słów kluczowych)
     * z customer_note WC do tabeli pjo_order_meta.
     */
    public function sync_order_meta( $order_id ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_order_meta';
        $note = $order->get_customer_note();

        // Heurystyka: czy klient prosi o FV?
        $wants_invoice = 0;
        $invoice_data = '';
        if ( $note && preg_match( '/\b(faktur|fv|nip\s*[:=]?\s*\d{10}|invoice)\b/iu', $note ) ) {
            $wants_invoice = 1;
            $invoice_data = $note; // user może dopracować w panelu zamówienia (Faza B)
        }

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE order_id=%d", $order_id ) );
        $data = array(
            'customer_note_cached' => $note,
            'wants_invoice'        => $wants_invoice,
        );
        if ( $wants_invoice && ! empty( $invoice_data ) ) {
            $data['invoice_data'] = $invoice_data;
        }
        if ( $existing ) {
            $wpdb->update( $table, $data, array( 'order_id' => $order_id ) );
        } else {
            $data['order_id'] = $order_id;
            $wpdb->insert( $table, $data );
        }
    }

    /**
     * Inicjalizuj moduły administracyjne
     */
    public function init_admin_modules() {
        if ( is_admin() ) {
            PhotoJob_Admin_Menu::get_instance();
            PhotoJob_Accounting_Report_Page::get_instance();
            PhotoJob_Settings::get_instance();
            PhotoJob_Orders_Dashboard::get_instance();
        }
    }

    /**
     * Sprawdź czy schema DB jest aktualna — uruchom dbDelta jeśli nie
     */
    public function maybe_upgrade_db() {
        $current = get_option( PhotoJob_Activator::DB_VERSION_OPTION, '0' );
        if ( version_compare( $current, PhotoJob_Activator::DB_VERSION, '<' ) ) {
            PhotoJob_Activator::activate();
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

// Activation / deactivation hooks — wymagają require_once przed register_*
require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-pjo-roles.php';
require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-pjo-activator.php';
require_once PHOTOJOB_PLUGIN_DIR . 'includes/class-pjo-deactivator.php';
register_activation_hook( __FILE__, array( 'PhotoJob_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PhotoJob_Deactivator', 'deactivate' ) );
