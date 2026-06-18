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
        // Capability dla głównego menu — najniższy wspólny mianownik, żeby pracownik też widział menu PhotoJob.
        // Konkretne submenu są chronione własnymi capabilities.
        $menu_cap = current_user_can( 'pjo_manage_orders' ) ? 'pjo_manage_orders' : 'pjo_reception_check';

        add_menu_page(
            __( 'PhotoJob Organizer', 'photojob-organizer' ),
            __( 'PhotoJob', 'photojob-organizer' ),
            $menu_cap,
            'photojob-organizer',
            array( $this, 'render_main_page' ),
            'dashicons-camera',
            56
        );

        // Zamówienia — Dashboard (Faza B)
        add_submenu_page(
            'photojob-organizer',
            __( 'Zamówienia', 'photojob-organizer' ),
            __( 'Zamówienia', 'photojob-organizer' ),
            current_user_can( 'pjo_manage_orders' ) ? 'pjo_manage_orders' : 'pjo_reception_check',
            PhotoJob_Orders_Dashboard::PAGE_SLUG,
            array( PhotoJob_Orders_Dashboard::get_instance(), 'render_page' )
        );

        // Raport księgowy — tylko admin
        add_submenu_page(
            'photojob-organizer',
            __( 'Raport księgowy', 'photojob-organizer' ),
            __( 'Raport księgowy', 'photojob-organizer' ),
            'pjo_export_accounting',
            'photojob-accounting-report',
            array( $this, 'render_accounting_report_page' )
        );

        // Ustawienia — tylko admin
        add_submenu_page(
            'photojob-organizer',
            __( 'Ustawienia', 'photojob-organizer' ),
            __( 'Ustawienia', 'photojob-organizer' ),
            'pjo_manage_settings',
            PhotoJob_Settings::PAGE_SLUG,
            array( PhotoJob_Settings::get_instance(), 'render_page' )
        );

        // Zamień nazwę pierwszego submenu na "Przegląd"
        global $submenu;
        if ( isset( $submenu['photojob-organizer'] ) ) {
            $submenu['photojob-organizer'][0][0] = __( 'Przegląd', 'photojob-organizer' );
        }
    }

    /**
     * Renderuj główną stronę
     */
    public function render_main_page() {
        $is_worker = PhotoJob_Roles::current_user_is_worker_only();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php if ( $is_worker ) : ?>
                <div class="card">
                    <h2><?php _e( 'Twoje moduły', 'photojob-organizer' ); ?></h2>
                    <ul>
                        <li>
                            <strong><?php _e( 'Przyjęcie odbitek', 'photojob-organizer' ); ?></strong> —
                            <?php _e( 'Sprawdzanie zawartości paczek z laboratorium.', 'photojob-organizer' ); ?>
                            <em><?php _e( '(dostępne od v1.2 — Faza D)', 'photojob-organizer' ); ?></em>
                        </li>
                        <li>
                            <strong><?php _e( 'Etykiety na koperty', 'photojob-organizer' ); ?></strong> —
                            <?php _e( 'Generowanie PDF arkuszy A4 z etykietami.', 'photojob-organizer' ); ?>
                            <em><?php _e( '(dostępne od v1.2 — Faza D)', 'photojob-organizer' ); ?></em>
                        </li>
                    </ul>
                </div>
            <?php else : ?>
                <div class="card">
                    <h2><?php _e( 'Witaj w PhotoJob Organizer', 'photojob-organizer' ); ?></h2>
                    <p><?php _e( 'Narzędzie do organizacji zamówień fotograficznych, kompletacji zdjęć i raportów księgowych.', 'photojob-organizer' ); ?></p>

                    <h3><?php _e( 'Dostępne moduły:', 'photojob-organizer' ); ?></h3>
                    <ul>
                        <?php if ( current_user_can( 'pjo_export_accounting' ) ) : ?>
                        <li>
                            <strong><?php _e( 'Raport księgowy', 'photojob-organizer' ); ?></strong> —
                            <?php _e( 'Generuj zestawienia transakcji dla księgowej z wybranego zakresu dat.', 'photojob-organizer' ); ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=photojob-accounting-report' ) ); ?>" class="button">
                                <?php _e( 'Otwórz', 'photojob-organizer' ); ?>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ( current_user_can( 'pjo_manage_settings' ) ) : ?>
                        <li>
                            <strong><?php _e( 'Ustawienia', 'photojob-organizer' ); ?></strong> —
                            <?php _e( 'Firma, NIP, banki, sezony, mapowanie produktów, QNAP, etykiety, sklepy zewnętrzne.', 'photojob-organizer' ); ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PhotoJob_Settings::PAGE_SLUG ) ); ?>" class="button">
                                <?php _e( 'Otwórz', 'photojob-organizer' ); ?>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <h3><?php _e( 'W roadmapie (po Fazie A):', 'photojob-organizer' ); ?></h3>
                    <ul>
                        <li><strong><?php _e( 'Faza B', 'photojob-organizer' ); ?></strong> — <?php _e( 'Dashboard zamówień (14 kolumn z Excela, filtry sezon/rok/status, inline edit)', 'photojob-organizer' ); ?></li>
                        <li><strong><?php _e( 'Faza C', 'photojob-organizer' ); ?></strong> — <?php _e( 'Folder Builder QNAP (auto-kopiowanie z magazynu sesji, eliminacja ręcznego dopisywania prefiksów)', 'photojob-organizer' ); ?></li>
                        <li><strong><?php _e( 'Faza D', 'photojob-organizer' ); ?></strong> — <?php _e( 'Reception Module + Etykiety na koperty', 'photojob-organizer' ); ?></li>
                        <li><strong><?php _e( 'Faza F', 'photojob-organizer' ); ?></strong> — <?php _e( 'Print Export (ZIP+CSV dla nphoto, browser bot opcjonalnie)', 'photojob-organizer' ); ?></li>
                    </ul>
                </div>

                <div class="card">
                    <h3><?php _e( 'Status systemu', 'photojob-organizer' ); ?></h3>
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
                        <li>
                            <?php
                            $db_version = get_option( PhotoJob_Activator::DB_VERSION_OPTION, '0' );
                            if ( version_compare( $db_version, PhotoJob_Activator::DB_VERSION, '>=' ) ) {
                                echo '<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ';
                                printf( esc_html__( 'Schema bazy danych: v%s', 'photojob-organizer' ), esc_html( $db_version ) );
                            } else {
                                echo '<span class="dashicons dashicons-warning" style="color: orange;"></span> ';
                                printf( esc_html__( 'Schema bazy danych: v%s (wymagana v%s)', 'photojob-organizer' ), esc_html( $db_version ), esc_html( PhotoJob_Activator::DB_VERSION ) );
                            }
                            ?>
                        </li>
                        <li>
                            <?php
                            $worker_exists = get_role( PhotoJob_Roles::WORKER_ROLE ) !== null;
                            if ( $worker_exists ) {
                                echo '<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ';
                                _e( 'Rola "Pracownik Foto" zarejestrowana', 'photojob-organizer' );
                            } else {
                                echo '<span class="dashicons dashicons-dismiss" style="color: red;"></span> ';
                                _e( 'Rola "Pracownik Foto" NIE jest zarejestrowana (re-aktywuj wtyczkę)', 'photojob-organizer' );
                            }
                            ?>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
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
