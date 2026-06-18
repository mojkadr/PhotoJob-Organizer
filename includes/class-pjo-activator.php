<?php
/**
 * Aktywacja wtyczki — tworzenie tabel bazy danych i ról
 *
 * @package PhotoJob_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PhotoJob_Activator {

    const DB_VERSION = '1.5.0';
    const DB_VERSION_OPTION = 'pjo_db_version';

    public static function activate() {
        self::deactivate_old_versions();
        self::create_tables();
        self::register_roles();
        self::set_default_options();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Jeśli WP ma zarejestrowany OSOBNY folder ze starszą wersją wtyczki (np. `photojob-organizer-old`
     * lub `photojob-organizer-2`), deaktywuj go żeby nie było kolizji nazw klas (PhotoJob_*).
     */
    private static function deactivate_old_versions() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $current_basename = plugin_basename( PHOTOJOB_PLUGIN_DIR . 'photojob-organizer.php' );
        $all = get_plugins();
        foreach ( $all as $basename => $data ) {
            if ( $basename === $current_basename ) {
                continue;
            }
            $is_pjo = ( isset( $data['Name'] ) && stripos( $data['Name'], 'PhotoJob' ) !== false )
                   || ( isset( $data['TextDomain'] ) && $data['TextDomain'] === 'photojob-organizer' );
            if ( $is_pjo && is_plugin_active( $basename ) ) {
                deactivate_plugins( $basename, true );
            }
        }
    }

    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $orders_meta = $wpdb->prefix . 'pjo_order_meta';
        $sql_orders_meta = "CREATE TABLE {$orders_meta} (
            order_id BIGINT(20) UNSIGNED NOT NULL,
            production_status VARCHAR(50) NOT NULL DEFAULT 'pending',
            qnap_folder_path TEXT NULL,
            bank_account VARCHAR(50) NULL,
            season VARCHAR(100) NULL,
            facility_id BIGINT(20) UNSIGNED NULL,
            pack_group VARCHAR(64) NULL,
            print_batch VARCHAR(64) NULL,
            wants_invoice TINYINT(1) NOT NULL DEFAULT 0,
            invoice_data TEXT NULL,
            customer_note_cached TEXT NULL,
            notes TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (order_id),
            KEY idx_production_status (production_status),
            KEY idx_season (season),
            KEY idx_facility (facility_id),
            KEY idx_pack_group (pack_group),
            KEY idx_print_batch (print_batch),
            KEY idx_wants_invoice (wants_invoice)
        ) {$charset_collate};";
        dbDelta( $sql_orders_meta );

        // Wydruki (print batches) — Faza C #4. Numer 26ZiNMW1 globalnie unikalny.
        $print_batches = $wpdb->prefix . 'pjo_print_batches';
        $sql_print_batches = "CREATE TABLE {$print_batches} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            number VARCHAR(64) NOT NULL,
            client_initials VARCHAR(20) NULL,
            season_letter VARCHAR(4) NULL,
            year2 VARCHAR(2) NULL,
            qnap_path TEXT NULL,
            order_ids TEXT NULL,
            file_count INT NOT NULL DEFAULT 0,
            status VARCHAR(50) NOT NULL DEFAULT 'built',
            created_by BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_number (number),
            KEY idx_status (status)
        ) {$charset_collate};";
        dbDelta( $sql_print_batches );

        // pjo_facilities USUNIĘTE w v1.3.0 — placówki są auto-wykrywane
        // z hierarchii product_cat przez PhotoJob_WC_Inspector::get_facilities().
        // Stara tabela (jeśli istnieje z v1.2.0) zostaje — dane nie tracone, ale ignorowane.

        $print_orders = $wpdb->prefix . 'pjo_print_orders';
        $sql_print_orders = "CREATE TABLE {$print_orders} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            lab VARCHAR(50) NOT NULL,
            zip_path TEXT NULL,
            csv_path TEXT NULL,
            sent_at DATETIME NULL,
            confirmation_code VARCHAR(255) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'prepared',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_order_id (order_id),
            KEY idx_lab (lab),
            KEY idx_status (status)
        ) {$charset_collate};";
        dbDelta( $sql_print_orders );

        $reception_log = $wpdb->prefix . 'pjo_reception_log';
        $sql_reception = "CREATE TABLE {$reception_log} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            line_item_id BIGINT(20) UNSIGNED NOT NULL,
            received_count INT NOT NULL DEFAULT 0,
            expected_count INT NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            checked_by BIGINT(20) UNSIGNED NULL,
            checked_at DATETIME NULL,
            notes TEXT NULL,
            PRIMARY KEY  (id),
            KEY idx_order_id (order_id),
            KEY idx_status (status),
            KEY idx_line_item (line_item_id)
        ) {$charset_collate};";
        dbDelta( $sql_reception );

        $label_sheets = $wpdb->prefix . 'pjo_label_sheets';
        $sql_labels = "CREATE TABLE {$label_sheets} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            template VARCHAR(50) NOT NULL,
            current_count INT NOT NULL DEFAULT 0,
            capacity INT NOT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            pdf_path TEXT NULL,
            PRIMARY KEY  (id),
            KEY idx_template (template),
            KEY idx_completed (completed_at)
        ) {$charset_collate};";
        dbDelta( $sql_labels );
    }

    private static function register_roles() {
        PhotoJob_Roles::install();
    }

    private static function set_default_options() {
        if ( false === get_option( 'pjo_settings_company' ) ) {
            add_option( 'pjo_settings_company', array(
                'name'    => get_bloginfo( 'name' ),
                'nip'     => get_option( 'woocommerce_store_vat_number', '' ),
                'regon'   => '',
                'address' => '',
            ) );
        }
        if ( false === get_option( 'pjo_settings_banks' ) ) {
            add_option( 'pjo_settings_banks', array( 'mBank', 'Revolut' ) );
        }
        // pjo_settings_seasons + pjo_settings_product_map USUNIĘTE w v1.3.0 —
        // auto-detect z product_cat / line items WC, nic do konfiguracji.
        // Cleanup starych opcji:
        delete_option( 'pjo_settings_seasons' );
        delete_option( 'pjo_settings_product_map' );
        if ( false === get_option( 'pjo_settings_qnap' ) ) {
            add_option( 'pjo_settings_qnap', array(
                'host'             => '',
                'port'             => 443,
                'user'             => '',
                'share_path'       => '/MójKadr/Klienci',
                'source_path'      => '/MójKadr/Sesje',
                'print_build_path' => '/MójKadr/Druk',
            ) );
        } else {
            // Migracja v1.5.0 (Faza C): dorzuć print_build_path jeśli brak.
            $qnap = get_option( 'pjo_settings_qnap', array() );
            if ( ! isset( $qnap['print_build_path'] ) || $qnap['print_build_path'] === '' ) {
                $qnap['print_build_path'] = '/MójKadr/Druk';
                update_option( 'pjo_settings_qnap', $qnap );
            }
        }
        if ( false === get_option( 'pjo_settings_labels' ) ) {
            add_option( 'pjo_settings_labels', array(
                'template'        => 'avery-l7163',
                'capacity'        => 14,
                'sender_address'  => '',
                'show_logo'       => 1,
                'fields'          => array( 'name', 'address', 'order_no' ),
                'graphic_url'     => '',
                'graphic_position' => 'top-left',
                'graphic_size_pct' => 20,
                'border_enabled'  => 0,
                'border_width'    => 1,
                'border_style'    => 'solid',
                'border_color'    => '#000000',
                'custom_text'     => '',
            ) );
        } else {
            // Migracja: dorzuć nowe pola jeśli istniejąca instalacja nie ma ich.
            $labels = get_option( 'pjo_settings_labels', array() );
            $defaults = array(
                'fields'           => array( 'name', 'address', 'order_no' ),
                'graphic_url'      => '',
                'graphic_position' => 'top-left',
                'graphic_size_pct' => 20,
                'border_enabled'   => 0,
                'border_width'     => 1,
                'border_style'     => 'solid',
                'border_color'     => '#000000',
                'custom_text'      => '',
            );
            $labels = array_merge( $defaults, $labels );
            update_option( 'pjo_settings_labels', $labels );
        }
        if ( false === get_option( 'pjo_settings_labs' ) ) {
            add_option( 'pjo_settings_labs', array(
                'nphoto' => array(
                    'enabled'    => 1,
                    'mode'       => 'export_zip',
                    'zip_format' => 'flat_per_size',
                ),
            ) );
        } else {
            // Migracja v1.2.0: usuwamy lookat ze stałych ustawień (user explicit: NIE używamy LookAt)
            $labs = get_option( 'pjo_settings_labs', array() );
            if ( isset( $labs['lookat'] ) ) {
                unset( $labs['lookat'] );
                update_option( 'pjo_settings_labs', $labs );
            }
        }
    }
}
