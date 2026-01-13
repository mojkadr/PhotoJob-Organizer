<?php
/**
 * Klasa strony raportu księgowego
 *
 * @package PhotoJob_Organizer
 */

// Zabezpieczenie przed bezpośrednim dostępem
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Klasa PhotoJob_Accounting_Report_Page
 */
class PhotoJob_Accounting_Report_Page {

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
        // Użyj admin_post_ hook dla obsługi akcji POST
        add_action( 'admin_post_photojob_export_accounting', array( $this, 'handle_export_request' ) );
    }

    /**
     * Obsłuż żądanie eksportu
     */
    public function handle_export_request() {
        // Weryfikacja nonce
        if ( ! isset( $_POST['photojob_accounting_nonce'] ) ||
             ! wp_verify_nonce( $_POST['photojob_accounting_nonce'], 'photojob_export_accounting' ) ) {
            wp_die( __( 'Błąd weryfikacji bezpieczeństwa.', 'photojob-organizer' ) );
        }

        // Sprawdź uprawnienia
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'Nie masz uprawnień do wykonania tej akcji.', 'photojob-organizer' ) );
        }

        // Pobierz daty z formularza
        $date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( $_POST['date_from'] ) : '';
        $date_to = isset( $_POST['date_to'] ) ? sanitize_text_field( $_POST['date_to'] ) : '';
        $export_format = isset( $_POST['export_format'] ) ? sanitize_text_field( $_POST['export_format'] ) : 'xlsx';

        // Walidacja dat
        if ( empty( $date_from ) ) {
            add_settings_error(
                'photojob_accounting',
                'missing_date_from',
                __( 'Musisz podać datę początkową.', 'photojob-organizer' ),
                'error'
            );
            return;
        }

        // Jeśli nie ma daty końcowej, użyj dzisiejszej
        if ( empty( $date_to ) ) {
            $date_to = current_time( 'Y-m-d' );
        }

        // Sprawdź czy daty są w poprawnym formacie
        if ( ! $this->validate_date( $date_from ) || ! $this->validate_date( $date_to ) ) {
            add_settings_error(
                'photojob_accounting',
                'invalid_date',
                __( 'Nieprawidłowy format daty.', 'photojob-organizer' ),
                'error'
            );
            return;
        }

        // Generuj dane tabeli
        $generator = PhotoJob_Accounting_Table_Generator::get_instance();
        $table_data = $generator->generate_table_data( $date_from, $date_to );

        // Pobierz informacje o firmie z ustawień WooCommerce
        $company_info = $this->get_company_info();

        // Eksportuj do wybranego formatu
        $exporter = PhotoJob_Excel_Exporter::get_instance();

        if ( $export_format === 'csv' ) {
            $exporter->export_to_csv( $table_data, $company_info );
        } else {
            $exporter->export_to_xlsx( $table_data, $company_info );
        }

        // Jeśli dotarliśmy tutaj, coś poszło nie tak
        exit;
    }

    /**
     * Pobierz informacje o firmie z ustawień WooCommerce
     *
     * @return array Informacje o firmie
     */
    private function get_company_info() {
        $store_name = get_bloginfo( 'name' );
        $store_address = WC()->countries->get_base_address();
        $store_city = WC()->countries->get_base_city();
        $store_postcode = WC()->countries->get_base_postcode();

        // Pobierz NIP z ustawień (jeśli jest)
        $nip = get_option( 'woocommerce_store_vat_number', '' );

        // Zbuduj adres
        $address_parts = array_filter( array(
            $store_address,
            $store_postcode . ' ' . $store_city,
        ) );

        return array(
            'name'    => $store_name,
            'address' => implode( "\n", $address_parts ),
            'nip'     => $nip,
        );
    }

    /**
     * Waliduj datę
     *
     * @param string $date Data do walidacji
     * @param string $format Format daty
     * @return bool True jeśli data jest poprawna
     */
    private function validate_date( $date, $format = 'Y-m-d' ) {
        $d = DateTime::createFromFormat( $format, $date );
        return $d && $d->format( $format ) === $date;
    }

    /**
     * Renderuj stronę
     */
    public function render() {
        ?>
        <div class="wrap">
            <h1><?php _e( 'Raport księgowy', 'photojob-organizer' ); ?></h1>

            <?php settings_errors( 'photojob_accounting' ); ?>

            <div class="card">
                <h2><?php _e( 'Generuj zestawienie transakcji', 'photojob-organizer' ); ?></h2>
                <p><?php _e( 'Wybierz zakres dat, aby wygenerować raport księgowy z zamówień.', 'photojob-organizer' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="photojob_export_accounting">
                    <?php wp_nonce_field( 'photojob_export_accounting', 'photojob_accounting_nonce' ); ?>

                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="date_from"><?php _e( 'Data początkowa', 'photojob-organizer' ); ?></label>
                                </th>
                                <td>
                                    <input
                                        type="date"
                                        id="date_from"
                                        name="date_from"
                                        value="<?php echo esc_attr( isset( $_POST['date_from'] ) ? $_POST['date_from'] : '2025-12-01' ); ?>"
                                        required
                                    >
                                    <p class="description">
                                        <?php _e( 'Data, od której mają być pobierane zamówienia (domyślnie: 01.12.2025)', 'photojob-organizer' ); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="date_to"><?php _e( 'Data końcowa', 'photojob-organizer' ); ?></label>
                                </th>
                                <td>
                                    <input
                                        type="date"
                                        id="date_to"
                                        name="date_to"
                                        value="<?php echo esc_attr( isset( $_POST['date_to'] ) ? $_POST['date_to'] : current_time( 'Y-m-d' ) ); ?>"
                                    >
                                    <p class="description">
                                        <?php _e( 'Data, do której mają być pobierane zamówienia (domyślnie: dzisiaj)', 'photojob-organizer' ); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="export_format"><?php _e( 'Format eksportu', 'photojob-organizer' ); ?></label>
                                </th>
                                <td>
                                    <select id="export_format" name="export_format">
                                        <option value="xlsx"><?php _e( 'Excel (.xlsx)', 'photojob-organizer' ); ?></option>
                                        <option value="csv"><?php _e( 'CSV (.csv)', 'photojob-organizer' ); ?></option>
                                    </select>
                                    <p class="description">
                                        <?php _e( 'Wybierz format, w jakim chcesz pobrać raport', 'photojob-organizer' ); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p class="submit">
                        <button type="submit" name="photojob_export_accounting" class="button button-primary">
                            <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                            <?php _e( 'Pobierz raport', 'photojob-organizer' ); ?>
                        </button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h3><?php _e( 'Informacje o raporcie', 'photojob-organizer' ); ?></h3>
                <p><?php _e( 'Raport zawiera następujące kolumny:', 'photojob-organizer' ); ?></p>
                <ul>
                    <li><strong><?php _e( 'Data zakupu', 'photojob-organizer' ); ?></strong> - <?php _e( 'data złożenia zamówienia', 'photojob-organizer' ); ?></li>
                    <li><strong><?php _e( 'Nr zam.', 'photojob-organizer' ); ?></strong> - <?php _e( 'numer zamówienia w systemie', 'photojob-organizer' ); ?></li>
                    <li><strong><?php _e( 'Status', 'photojob-organizer' ); ?></strong> - <?php _e( 'aktualny status zamówienia', 'photojob-organizer' ); ?></li>
                    <li><strong><?php _e( 'Klient', 'photojob-organizer' ); ?></strong> - <?php _e( 'imię i nazwisko klienta', 'photojob-organizer' ); ?></li>
                    <li><strong><?php _e( 'Przychód netto [zł]', 'photojob-organizer' ); ?></strong> - <?php _e( 'wartość zamówienia bez podatku VAT', 'photojob-organizer' ); ?></li>
                    <li><strong><?php _e( 'Sposób płatności', 'photojob-organizer' ); ?></strong> - <?php _e( 'metoda płatności użyta w zamówieniu', 'photojob-organizer' ); ?></li>
                </ul>

                <p>
                    <strong><?php _e( 'Domyślnie pobierane są zamówienia o statusach:', 'photojob-organizer' ); ?></strong>
                    <?php _e( 'Zrealizowane, W trakcie realizacji', 'photojob-organizer' ); ?>
                </p>
            </div>
        </div>
        <?php
    }
}
