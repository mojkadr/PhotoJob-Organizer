<?php
/**
 * PhotoJob_Orders_Dashboard — lista zamówień z 14 kolumnami z Excela "Zestawienie zamówień"
 *
 * Faza B v1.4.0. Funkcje:
 *  - Lista zamówień WC z dodatkowymi polami z pjo_order_meta (bank, status produkcji, uwagi cached, FV)
 *  - Filtry: zakres dat, status WC, status produkcji, FV, search
 *  - Inline edit (Status WC, Bank, Status produkcji) — AJAX
 *  - Expand row → line items z meta WAPF "Wydruk odbitki" (typ/rozmiar)
 *  - Pagination 30/strona
 *  - Badge FV 🧾 + tooltip uwag klienta
 *  - Auto-detect sezon/placówka z kategorii produktów line items
 *
 * @package PhotoJob_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PhotoJob_Orders_Dashboard {

    const PAGE_SLUG = 'photojob-orders';
    const PER_PAGE_DEFAULT = 30;

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_pjo_update_order_field', array( $this, 'ajax_update_order_field' ) );
        add_action( 'wp_ajax_pjo_get_line_items', array( $this, 'ajax_get_line_items' ) );
        add_action( 'wp_ajax_pjo_build_preview', array( $this, 'ajax_build_preview' ) );
        add_action( 'wp_ajax_pjo_build_execute', array( $this, 'ajax_build_execute' ) );
        add_action( 'wp_ajax_pjo_link_pack_group', array( $this, 'ajax_link_pack_group' ) );
        add_action( 'wp_ajax_pjo_unlink_pack_group', array( $this, 'ajax_unlink_pack_group' ) );
        add_action( 'wp_ajax_pjo_regrant_access', array( $this, 'ajax_regrant_access' ) );
        add_action( 'wp_ajax_pjo_sync_access_stages', array( $this, 'ajax_sync_access_stages' ) );
    }

    /**
     * Backfill: ustaw etap produkcji dla zamówień, które JUŻ mają przyznany dostęp
     * (post-meta `_ada_access_granted = yes`), a etap jest pusty / pending.
     * Jednorazowe domknięcie istniejących zamówień (automat działa od teraz na nowe granty).
     */
    public function ajax_sync_access_stages() {
        if ( ! current_user_can( 'pjo_manage_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'photojob-organizer' ) ) );
        }
        check_ajax_referer( 'pjo_dashboard', 'nonce' );
        if ( ! class_exists( 'PhotoJob_Access_Sync' ) ) {
            wp_send_json_error( array( 'message' => __( 'Moduł synchronizacji niedostępny.', 'photojob-organizer' ) ) );
        }
        @set_time_limit( 0 );
        ignore_user_abort( true );

        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s",
            PhotoJob_Access_Sync::GRANT_META, 'yes'
        ) );
        $sync = PhotoJob_Access_Sync::get_instance();
        $updated = 0;
        foreach ( (array) $ids as $oid ) {
            $oid = (int) $oid;
            if ( $sync->auto_set_stage_on_grant( $oid ) ) {
                $updated++;
            }
        }
        wp_send_json_success( array( 'scanned' => count( (array) $ids ), 'updated' => $updated ) );
    }

    /** ============ RE-GRANT DOSTĘPU (Photo Access) ============ */

    /**
     * Szybkie "wymuś ponowne przyznanie dostępu" z poziomu dashboardu.
     * Odpala TEN SAM hook co akcja w ekranie zamówienia WC (Access-to-photos, alpha16) —
     * zero duplikacji logiki: jeśli wtyczka Photo Access aktywna, robi dokładnie to samo
     * (kasuje guard idempotency + ADA_Access_Processor::process → ponowny link do klienta).
     */
    public function ajax_regrant_access() {
        if ( ! current_user_can( 'pjo_manage_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'photojob-organizer' ) ) );
        }
        check_ajax_referer( 'pjo_dashboard', 'nonce' );
        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => 'Bad request' ) );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Nie znaleziono zamówienia.', 'photojob-organizer' ) ) );
        }
        if ( ! has_action( 'woocommerce_order_action_ada_force_reprocess' ) ) {
            wp_send_json_error( array( 'message' => __( 'Wtyczka Photo Access (re-grant) nie jest aktywna na tym WP.', 'photojob-organizer' ) ) );
        }
        @set_time_limit( 0 );
        ignore_user_abort( true );
        do_action( 'woocommerce_order_action_ada_force_reprocess', $order );
        wp_send_json_success( array(
            'message' => sprintf( __( 'Photo Access: ponowne przyznanie dostępu odpalone dla #%d.', 'photojob-organizer' ), $order_id ),
        ) );
    }

    /** ============ GRUPY PAKOWANIA (#3) ============ */

    public function ajax_link_pack_group() {
        if ( ! current_user_can( 'pjo_manage_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'photojob-organizer' ) ) );
        }
        check_ajax_referer( 'pjo_dashboard', 'nonce' );
        $ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : array();
        $res = PhotoJob_Duplicates::link_group( $ids );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }
        wp_send_json_success( $res );
    }

    public function ajax_unlink_pack_group() {
        if ( ! current_user_can( 'pjo_manage_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'photojob-organizer' ) ) );
        }
        check_ajax_referer( 'pjo_dashboard', 'nonce' );
        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => 'Bad request' ) );
        }
        PhotoJob_Duplicates::unlink( $order_id );
        wp_send_json_success( array( 'order_id' => $order_id ) );
    }

    /** ============ FOLDER BUILDER (Faza C) ============ */

    /**
     * Dry-run: zbuduj plan druku i sprawdź dopasowanie plików źródłowych na QNAP.
     */
    public function ajax_build_preview() {
        if ( ! current_user_can( 'pjo_manage_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'photojob-organizer' ) ) );
        }
        check_ajax_referer( 'pjo_dashboard', 'nonce' );
        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => 'Bad request' ) );
        }
        @set_time_limit( 0 );

        $qnap = new PhotoJob_QNAP_Client();
        if ( ! $qnap->is_configured() ) {
            wp_send_json_error( array( 'message' => __( 'QNAP nie jest skonfigurowany — uzupełnij host/użytkownika/hasło w Ustawienia → QNAP.', 'photojob-organizer' ) ) );
        }
        if ( ! $qnap->login() ) {
            wp_send_json_error( array( 'message' => __( 'Logowanie do QNAP nieudane: ', 'photojob-organizer' ) . $qnap->last_error ) );
        }

        // #3: jeśli zamówienie w grupie pakowania → podgląd całej grupy (jeden numer przy Wykonaj).
        $member_ids = PhotoJob_Folder_Builder::pack_group_members( $order_id );
        $plans = array();
        foreach ( $member_ids as $mid ) {
            $p = PhotoJob_Folder_Builder::build_plan( $mid, $qnap ); // bez numeru — nadawany przy execute
            if ( ! is_wp_error( $p ) ) {
                $plans[] = $p;
            }
        }
        $qnap->logout();
        if ( empty( $plans ) ) {
            wp_send_json_error( array( 'message' => __( 'Nie udało się zbudować planu.', 'photojob-organizer' ) ) );
        }
        wp_send_json_success( array(
            'is_group' => count( $member_ids ) > 1,
            'members'  => $member_ids,
            'plans'    => $plans,
        ) );
    }

    /**
     * Wykonaj plan: utwórz foldery + skopiuj + przemianuj pliki na QNAP.
     */
    public function ajax_build_execute() {
        if ( ! current_user_can( 'pjo_manage_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'photojob-organizer' ) ) );
        }
        check_ajax_referer( 'pjo_dashboard', 'nonce' );
        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => 'Bad request' ) );
        }
        @set_time_limit( 0 );
        ignore_user_abort( true );

        $qnap = new PhotoJob_QNAP_Client();
        if ( ! $qnap->is_configured() ) {
            wp_send_json_error( array( 'message' => __( 'QNAP nie jest skonfigurowany.', 'photojob-organizer' ) ) );
        }
        if ( ! $qnap->login() ) {
            wp_send_json_error( array( 'message' => __( 'Logowanie do QNAP nieudane: ', 'photojob-organizer' ) . $qnap->last_error ) );
        }

        $result = PhotoJob_Folder_Builder::execute_plan( $order_id, $qnap );
        $qnap->logout();
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( array( 'message' => $result['error'] ) );
        }
        wp_send_json_success( $result );
    }

    /** ============ AJAX ============ */

    public function ajax_update_order_field() {
        if ( ! current_user_can( 'pjo_manage_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'photojob-organizer' ) ) );
        }
        check_ajax_referer( 'pjo_dashboard', 'nonce' );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $field    = sanitize_key( $_POST['field'] ?? '' );
        $value    = wp_unslash( $_POST['value'] ?? '' );
        if ( ! $order_id || ! $field ) {
            wp_send_json_error( array( 'message' => 'Bad request' ) );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => 'Order not found' ) );
        }

        global $wpdb;
        $meta_table = $wpdb->prefix . 'pjo_order_meta';

        switch ( $field ) {
            case 'status':
                $allowed = array_keys( wc_get_order_statuses() );
                $new = 'wc-' === substr( $value, 0, 3 ) ? $value : 'wc-' . $value;
                if ( ! in_array( $new, $allowed, true ) ) {
                    wp_send_json_error( array( 'message' => 'Invalid status' ) );
                }
                $order->update_status( str_replace( 'wc-', '', $new ), __( '[PJO Dashboard]', 'photojob-organizer' ) );
                wp_send_json_success( array( 'value' => $new, 'label' => wc_get_order_status_name( $new ) ) );
                break;

            case 'bank':
                $bank = sanitize_text_field( $value );
                $existing = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$meta_table} WHERE order_id=%d", $order_id ) );
                if ( $existing ) {
                    $wpdb->update( $meta_table, array( 'bank_account' => $bank ), array( 'order_id' => $order_id ) );
                } else {
                    $wpdb->insert( $meta_table, array( 'order_id' => $order_id, 'bank_account' => $bank ) );
                }
                wp_send_json_success( array( 'value' => $bank ) );
                break;

            case 'production_status':
                $allowed_ps = self::production_statuses_all();
                $ps = sanitize_key( $value );
                if ( ! in_array( $ps, $allowed_ps, true ) ) {
                    wp_send_json_error( array( 'message' => 'Invalid production status' ) );
                }
                $existing = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$meta_table} WHERE order_id=%d", $order_id ) );
                if ( $existing ) {
                    $wpdb->update( $meta_table, array( 'production_status' => $ps ), array( 'order_id' => $order_id ) );
                } else {
                    $wpdb->insert( $meta_table, array( 'order_id' => $order_id, 'production_status' => $ps ) );
                }
                // #2: zamówienie TYLKO z wersjami elektronicznymi + "Link wysłany" → zamknij od razu (Zrealizowane).
                $wc_completed = false;
                if ( $ps === 'link_sent' && self::order_is_electronic_only( $order ) && $order->get_status() !== 'completed' ) {
                    $order->update_status( 'completed', __( '[PJO] Wersja elektroniczna — link wysłany do klienta.', 'photojob-organizer' ) );
                    $wc_completed = true;
                }
                wp_send_json_success( array(
                    'value'        => $ps,
                    'label'        => self::production_status_label( $ps ),
                    'wc_completed' => $wc_completed,
                    'wc_status'    => 'wc-' . $order->get_status(),
                    'wc_label'     => wc_get_order_status_name( 'wc-' . $order->get_status() ),
                ) );
                break;

            default:
                wp_send_json_error( array( 'message' => 'Unknown field' ) );
        }
    }

    public function ajax_get_line_items() {
        if ( ! current_user_can( 'pjo_manage_orders' ) ) {
            wp_send_json_error();
        }
        check_ajax_referer( 'pjo_dashboard', 'nonce' );
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error();
        }
        $items = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            $product_name = $item->get_name();
            $qty = $item->get_quantity();
            $total = $item->get_total();
            $thumb = '';
            $thumb_large = '';
            $categories_path = '';
            if ( $product ) {
                $thumb_id = $product->get_image_id();
                if ( $thumb_id ) {
                    $thumb = wp_get_attachment_image_url( $thumb_id, 'thumbnail' );
                    $thumb_large = wp_get_attachment_image_url( $thumb_id, 'large' );
                    if ( ! $thumb_large ) {
                        $thumb_large = wp_get_attachment_image_url( $thumb_id, 'full' );
                    }
                }
                $cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
                $categories_path = implode( ' › ', $cats );
            }
            // Custom meta WAPF — np. "Wydruk odbitki"
            $extras = array();
            foreach ( $item->get_meta_data() as $meta ) {
                $k = $meta->key;
                if ( substr( $k, 0, 1 ) === '_' ) {
                    continue;
                }
                $v = is_string( $meta->value ) ? $meta->value : maybe_serialize( $meta->value );
                // Strip cenę w nawiasach: "15x23cm (25 zł)" → "15x23cm"
                $v_clean = trim( preg_replace( '/\s*\(.*$/u', '', wp_strip_all_tags( $v ) ) );
                if ( $v_clean !== '' && $v_clean !== '0' ) {
                    $extras[ $k ] = $v_clean;
                }
            }
            $items[] = array(
                'item_id'     => $item_id,
                'name'        => $product_name,
                'qty'         => $qty,
                'total'       => wc_price( $total ),
                'thumb'       => $thumb,
                'thumb_large' => $thumb_large,
                'extras'      => $extras,
                'categories'  => $categories_path,
            );
        }
        wp_send_json_success( array( 'items' => $items ) );
    }

    /** ============ HELPERS ============ */

    public static function production_status_label( $status ) {
        $map = array(
            'pending'           => __( '⏳ Oczekuje', 'photojob-organizer' ),
            'sent_to_lab'       => __( '📤 Wysłane do labu', 'photojob-organizer' ),
            'received_from_lab' => __( '📥 Przyszły z labu', 'photojob-organizer' ),
            'incomplete'        => __( '⚠ Niekompletne', 'photojob-organizer' ),
            'ready_to_pack'     => __( '📦 Do spakowania', 'photojob-organizer' ),
            'shipped'           => __( '✅ Wysłane do klienta', 'photojob-organizer' ),
            'link_sent'         => __( '📧 Link wysłany', 'photojob-organizer' ),
        );
        return $map[ $status ] ?? $status;
    }

    public static function production_statuses_all() {
        return array( 'pending', 'sent_to_lab', 'received_from_lab', 'incomplete', 'ready_to_pack', 'shipped', 'link_sent' );
    }

    /**
     * #2: Czy zamówienie zawiera WYŁĄCZNIE wersje elektroniczne (brak odbitek do druku)?
     * Kanoniczna logika w PhotoJob_Access_Sync (zawsze ładowana) — tu delegujemy,
     * żeby heurystyka nie rozjechała się w dwóch miejscach.
     */
    public static function order_is_electronic_only( $order ) {
        return PhotoJob_Access_Sync::is_electronic_only( $order );
    }

    /** ============ RENDER ============ */

    public function render_page() {
        if ( ! current_user_can( 'pjo_manage_orders' ) && ! current_user_can( 'pjo_reception_check' ) ) {
            wp_die( __( 'Brak uprawnień.', 'photojob-organizer' ) );
        }
        $is_worker = PhotoJob_Roles::current_user_is_worker_only();

        // Filtry z $_GET
        $f = array(
            'date_from'         => sanitize_text_field( $_GET['date_from'] ?? date( 'Y-m-01' ) ),
            'date_to'           => sanitize_text_field( $_GET['date_to'] ?? date( 'Y-m-d' ) ),
            'status'            => sanitize_text_field( $_GET['status'] ?? '' ),
            'production_status' => sanitize_text_field( $_GET['production_status'] ?? '' ),
            'wants_invoice'     => sanitize_text_field( $_GET['wants_invoice'] ?? '' ),
            'search'            => sanitize_text_field( $_GET['search'] ?? '' ),
            'per_page'          => max( 10, min( 200, absint( $_GET['per_page'] ?? self::PER_PAGE_DEFAULT ) ) ),
            'paged'             => max( 1, absint( $_GET['paged'] ?? 1 ) ),
        );

        $query = $this->build_query( $f, $is_worker );
        $orders = wc_get_orders( $query );
        $total = $this->count_orders( $f );
        $total_pages = max( 1, (int) ceil( $total / $f['per_page'] ) );

        $banks = get_option( 'pjo_settings_banks', array() );
        $wc_statuses = wc_get_order_statuses();
        $prod_statuses = self::production_statuses_all();
        // #3: klastry dubli (mail/nazwisko) — tylko dla zarządzających.
        $clusters = ( ! $is_worker && class_exists( 'PhotoJob_Duplicates' ) ) ? PhotoJob_Duplicates::get_clusters() : array();
        // #3: ułóż duble i grupy pakowania obok siebie (ułatwia pakowanie).
        $orders = $this->cluster_adjacent_orders( $orders, $clusters );
        ?>
        <div class="wrap pjo-dashboard">
            <h1><?php _e( 'Zamówienia', 'photojob-organizer' ); ?>
                <span class="title-count"><?php echo number_format_i18n( $total ); ?></span>
            </h1>

            <form method="get" id="pjo-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                <div style="background:#fff;padding:12px;border:1px solid #c3c4c7;margin:15px 0;display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
                    <label><strong><?php _e( 'Od:', 'photojob-organizer' ); ?></strong><br>
                        <input type="date" name="date_from" value="<?php echo esc_attr( $f['date_from'] ); ?>">
                    </label>
                    <label><strong><?php _e( 'Do:', 'photojob-organizer' ); ?></strong><br>
                        <input type="date" name="date_to" value="<?php echo esc_attr( $f['date_to'] ); ?>">
                    </label>
                    <label><strong><?php _e( 'Status WC:', 'photojob-organizer' ); ?></strong><br>
                        <select name="status">
                            <option value=""><?php _e( '— wszystkie —', 'photojob-organizer' ); ?></option>
                            <?php foreach ( $wc_statuses as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $f['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><strong><?php _e( 'Etap produkcji:', 'photojob-organizer' ); ?></strong><br>
                        <select name="production_status">
                            <option value=""><?php _e( '— wszystkie —', 'photojob-organizer' ); ?></option>
                            <?php foreach ( $prod_statuses as $ps ) : ?>
                                <option value="<?php echo esc_attr( $ps ); ?>" <?php selected( $f['production_status'], $ps ); ?>><?php echo esc_html( self::production_status_label( $ps ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><strong>FV:</strong><br>
                        <select name="wants_invoice">
                            <option value=""><?php _e( '— wszystkie —', 'photojob-organizer' ); ?></option>
                            <option value="1" <?php selected( $f['wants_invoice'], '1' ); ?>>🧾 <?php _e( 'Tak', 'photojob-organizer' ); ?></option>
                            <option value="0" <?php selected( $f['wants_invoice'], '0' ); ?>><?php _e( 'Nie', 'photojob-organizer' ); ?></option>
                        </select>
                    </label>
                    <label style="flex:1;min-width:200px;"><strong><?php _e( 'Szukaj:', 'photojob-organizer' ); ?></strong><br>
                        <input type="search" name="search" value="<?php echo esc_attr( $f['search'] ); ?>" placeholder="<?php esc_attr_e( 'nr zam., klient, email…', 'photojob-organizer' ); ?>" style="width:100%;">
                    </label>
                    <label><strong><?php _e( '/strona:', 'photojob-organizer' ); ?></strong><br>
                        <input type="number" name="per_page" value="<?php echo esc_attr( $f['per_page'] ); ?>" min="10" max="200" style="width:70px;">
                    </label>
                    <div>
                        <button class="button button-primary"><?php _e( 'Filtruj', 'photojob-organizer' ); ?></button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button"><?php _e( 'Reset', 'photojob-organizer' ); ?></a>
                    </div>
                </div>
            </form>

            <?php if ( ! $is_worker ) : ?>
            <div style="margin:8px 0;">
                <button type="button" class="button" id="pjo-sync-stages">🔄 <?php _e( 'Zsynchronizuj etapy z Photo Access', 'photojob-organizer' ); ?></button>
                <span id="pjo-sync-stages-result" style="margin-left:8px;font-size:12px;"></span>
                <span class="description" style="font-size:11px;margin-left:6px;"><?php _e( 'Ustawia etap (📧 Link wysłany / ⚠ Niekompletne) dla zamówień, które już mają przyznany dostęp do zdjęć.', 'photojob-organizer' ); ?></span>
            </div>
            <div id="pjo-pack-bar" style="display:none;background:#fff;border:1px solid #c3c4c7;padding:8px 12px;margin:10px 0;border-radius:4px;">
                <strong><?php _e( 'Zaznaczone:', 'photojob-organizer' ); ?> <span id="pjo-pack-count">0</span></strong>
                <button type="button" class="button button-primary" id="pjo-pack-link">📦 <?php _e( 'Połącz w grupę pakowania', 'photojob-organizer' ); ?></button>
                <span id="pjo-pack-result" style="margin-left:8px;font-size:12px;"></span>
            </div>
            <?php endif; ?>

            <?php $this->render_pagination( $f, $total_pages, $total ); ?>

            <table class="wp-list-table widefat striped pjo-orders-table">
                <thead><tr>
                    <th style="width:30px;"></th>
                    <th style="width:90px;"><?php _e( 'Data', 'photojob-organizer' ); ?></th>
                    <th style="width:80px;"><?php _e( 'Nr', 'photojob-organizer' ); ?></th>
                    <th><?php _e( 'Klient', 'photojob-organizer' ); ?></th>
                    <?php if ( ! $is_worker ) : ?>
                        <th><?php _e( 'Email / Tel', 'photojob-organizer' ); ?></th>
                        <th style="width:90px;"><?php _e( 'Kwota', 'photojob-organizer' ); ?></th>
                        <th style="width:140px;"><?php _e( 'Status WC', 'photojob-organizer' ); ?></th>
                        <th style="width:140px;"><?php _e( 'Bank', 'photojob-organizer' ); ?></th>
                    <?php endif; ?>
                    <th style="width:180px;"><?php _e( 'Etap produkcji', 'photojob-organizer' ); ?></th>
                    <th style="width:120px;"><?php _e( 'Uwagi / FV', 'photojob-organizer' ); ?></th>
                    <th style="width:80px;"><?php _e( 'Akcje', 'photojob-organizer' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( empty( $orders ) ) : ?>
                    <tr><td colspan="11"><em><?php _e( 'Brak zamówień w wybranym zakresie.', 'photojob-organizer' ); ?></em></td></tr>
                <?php else :
                    foreach ( $orders as $order ) :
                        $this->render_order_row( $order, $is_worker, $banks, $wc_statuses, $prod_statuses, $clusters );
                    endforeach;
                endif; ?>
                </tbody>
            </table>

            <?php $this->render_pagination( $f, $total_pages, $total ); ?>
        </div>

        <style>
        .pjo-dashboard .title-count { background:#2271b1; color:#fff; padding:2px 10px; border-radius:10px; font-size:13px; vertical-align:middle; margin-left:8px; }
        .pjo-orders-table { font-size:13px; }
        .pjo-orders-table th { background:#f6f7f7; }
        .pjo-orders-table tr.status-wc-completed { background:#f0f0f0 !important; }
        .pjo-orders-table tr.status-wc-processing td { border-left: 3px solid #46b450; }
        .pjo-orders-table tr.status-wc-on-hold td { border-left: 3px solid #ffba00; }
        .pjo-orders-table tr.status-wc-pending td { border-left: 3px solid #999; }
        .pjo-orders-table tr.status-wc-cancelled { background:#fde2e2 !important; opacity:0.7; }
        .pjo-orders-table tr.status-wc-refunded { background:#fde2e2 !important; opacity:0.7; }
        .pjo-orders-table .pjo-expand-btn { cursor:pointer; background:none; border:1px solid #c3c4c7; border-radius:4px; padding:2px 8px; font-weight:bold; }
        .pjo-orders-table .pjo-inline-edit { background:transparent; border:1px solid transparent; padding:4px; cursor:pointer; }
        .pjo-orders-table .pjo-inline-edit:hover { border-color:#c3c4c7; background:#fff; }
        .pjo-orders-table .pjo-fv-badge { background:#46b450; color:#fff; padding:2px 6px; border-radius:3px; font-size:11px; }
        .pjo-orders-table .pjo-note-badge { background:#ffba00; color:#fff; padding:2px 6px; border-radius:3px; font-size:11px; cursor:help; }
        .pjo-line-items-row td { background:#fafafa !important; padding:0 !important; }
        .pjo-line-items-content { padding:12px 24px; }
        .pjo-line-item { display:flex; gap:12px; padding:8px 0; border-bottom:1px dashed #e0e0e0; align-items:center; }
        .pjo-line-item:last-child { border-bottom:0; }
        .pjo-line-item img { border:1px solid #ddd; border-radius:3px; }
        .pjo-line-item .extras { color:#555; font-size:12px; }
        .pjo-line-item .cats { color:#888; font-size:11px; font-style:italic; }
        .pjo-built-flag { cursor:help; }
        .pjo-build-row td { background:#f3f8ff !important; padding:0 !important; }
        .pjo-build-content { padding:12px 20px; }
        .pjo-build-content h4 { margin:0 0 8px; }
        .pjo-build-meta { font-size:12px; color:#555; margin-bottom:10px; }
        .pjo-build-meta code { background:#fff; border:1px solid #d6e4f5; padding:1px 5px; border-radius:3px; }
        table.pjo-build-table { width:100%; border-collapse:collapse; font-size:12px; background:#fff; }
        table.pjo-build-table th, table.pjo-build-table td { border:1px solid #e0e6ed; padding:5px 8px; text-align:left; vertical-align:top; }
        table.pjo-build-table th { background:#eef4fb; }
        table.pjo-build-table tr.row-missing td { background:#fff5f5; }
        table.pjo-build-table tr.row-skip td { background:#f6f6f6; color:#888; }
        .pjo-build-target { font-family:Consolas,Monaco,monospace; font-size:11px; color:#1a4d80; }
        .pjo-build-actions { margin-top:12px; display:flex; gap:10px; align-items:center; }
        .pjo-warn { background:#fff8e5; border:1px solid #f0d48a; padding:6px 10px; border-radius:4px; font-size:12px; margin-bottom:8px; }
        .pjo-st-ok { color:#1a7f37; font-weight:bold; }
        .pjo-st-missing { color:#c92a2a; font-weight:bold; }
        .pjo-st-skip { color:#888; }
        .pjo-st-error { color:#c92a2a; font-weight:bold; }
        .pjo-dup-badge { background:#f59f00; color:#fff; padding:1px 6px; border-radius:3px; font-size:11px; cursor:help; margin-left:4px; white-space:nowrap; }
        .pjo-pack-badge { background:#2271b1; color:#fff; padding:1px 6px; border-radius:3px; font-size:11px; cursor:pointer; margin-left:4px; white-space:nowrap; }
        .pjo-batch-badge { background:#5a3e8e; color:#fff; padding:1px 6px; border-radius:3px; font-size:11px; white-space:nowrap; }
        .pjo-line-item img.pjo-thumb { cursor:zoom-in; transition:transform .08s; }
        .pjo-line-item img.pjo-thumb:hover { transform:scale(1.05); border-color:#2271b1; }
        .pjo-lightbox { position:fixed; inset:0; background:rgba(0,0,0,.82); z-index:100000; align-items:center; justify-content:center; cursor:zoom-out; }
        .pjo-lightbox-inner { max-width:92vw; max-height:92vh; text-align:center; }
        .pjo-lightbox-inner img { max-width:92vw; max-height:84vh; object-fit:contain; box-shadow:0 4px 40px rgba(0,0,0,.6); border:3px solid #fff; border-radius:4px; }
        .pjo-lightbox-cap { color:#fff; margin-top:10px; font-size:14px; }
        </style>

        <script>
        (function() {
            var nonce = '<?php echo esc_js( wp_create_nonce( 'pjo_dashboard' ) ); ?>';
            var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

            // Expand line items
            document.querySelectorAll('.pjo-expand-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var orderId = btn.getAttribute('data-order');
                    var row = document.getElementById('pjo-items-' + orderId);
                    if (row.style.display === 'table-row') {
                        row.style.display = 'none';
                        btn.textContent = '+';
                        return;
                    }
                    btn.textContent = '−';
                    row.style.display = 'table-row';
                    var content = row.querySelector('.pjo-line-items-content');
                    if (content.getAttribute('data-loaded') === '1') return;
                    content.innerHTML = '⏳ Ładuję…';
                    var fd = new FormData();
                    fd.append('action', 'pjo_get_line_items');
                    fd.append('nonce', nonce);
                    fd.append('order_id', orderId);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function(r){ return r.json(); })
                        .then(function(j) {
                            if (!j.success) { content.innerHTML = '❌ Błąd'; return; }
                            var html = '';
                            j.data.items.forEach(function(it) {
                                html += '<div class="pjo-line-item">';
                                if (it.thumb) html += '<img src="' + it.thumb + '" width="60" height="60" style="object-fit:cover;" class="pjo-thumb" data-large="' + (it.thumb_large || it.thumb) + '" data-name="' + (it.name||'').replace(/"/g,'&quot;') + '" title="Kliknij, by powiększyć">';
                                html += '<div style="flex:1;">';
                                html += '<strong>' + (it.name||'').replace(/</g,'&lt;') + '</strong>';
                                if (it.cats) html += ' <span class="cats">' + it.cats.replace(/</g,'&lt;') + '</span>';
                                var extras = [];
                                Object.keys(it.extras||{}).forEach(function(k) { extras.push('<strong>'+k.replace(/</g,'&lt;')+':</strong> '+(it.extras[k]||'').replace(/</g,'&lt;')); });
                                if (extras.length) html += '<div class="extras">' + extras.join(' • ') + '</div>';
                                html += '</div>';
                                html += '<div style="text-align:right;"><div>' + it.qty + ' szt.</div><div>' + it.total + '</div></div>';
                                html += '</div>';
                            });
                            if (!j.data.items.length) html = '<em>Brak line items.</em>';
                            content.innerHTML = html;
                            content.setAttribute('data-loaded', '1');
                        });
                });
            });

            // Inline edit (status, bank, production_status)
            document.querySelectorAll('.pjo-inline-edit').forEach(function(sel) {
                sel.addEventListener('change', function() {
                    var orderId = sel.getAttribute('data-order');
                    var field = sel.getAttribute('data-field');
                    var value = sel.value;
                    var origValue = sel.getAttribute('data-original') || '';
                    var fd = new FormData();
                    fd.append('action', 'pjo_update_order_field');
                    fd.append('nonce', nonce);
                    fd.append('order_id', orderId);
                    fd.append('field', field);
                    fd.append('value', value);
                    sel.disabled = true;
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function(r){ return r.json(); })
                        .then(function(j) {
                            sel.disabled = false;
                            if (!j.success) {
                                alert('Błąd: ' + (j.data && j.data.message ? j.data.message : 'unknown'));
                                sel.value = origValue;
                                return;
                            }
                            sel.setAttribute('data-original', value);
                            sel.style.background = '#dff0d8';
                            setTimeout(function() { sel.style.background = ''; }, 1200);
                            // Update row style after status change
                            if (field === 'status') {
                                var tr = sel.closest('tr');
                                tr.className = tr.className.replace(/status-wc-\S+/g, '').trim();
                                tr.classList.add('status-' + value);
                            }
                            // #2: "Link wysłany" zamknął e-only zamówienie → odśwież status WC w wierszu
                            if (field === 'production_status' && j.data && j.data.wc_completed) {
                                var tr2 = sel.closest('tr');
                                var statusSel = tr2.querySelector('select[data-field="status"]');
                                if (statusSel) { statusSel.value = j.data.wc_status; statusSel.setAttribute('data-original', j.data.wc_status); }
                                tr2.className = tr2.className.replace(/status-wc-\S+/g, '').trim();
                                tr2.classList.add('status-' + j.data.wc_status);
                            }
                        })
                        .catch(function(e) { sel.disabled = false; alert(e.message); });
                });
            });

            // ===== #5 Lightbox (szybkie powiększenie miniatury) =====
            var lb = document.createElement('div');
            lb.className = 'pjo-lightbox';
            lb.innerHTML = '<div class="pjo-lightbox-inner"><img src=""><div class="pjo-lightbox-cap"></div></div>';
            lb.style.display = 'none';
            document.body.appendChild(lb);
            function closeLb(){ lb.style.display = 'none'; lb.querySelector('img').src = ''; }
            lb.addEventListener('click', closeLb);
            document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeLb(); });
            document.addEventListener('click', function(e) {
                var t = e.target.closest ? e.target.closest('.pjo-thumb') : null;
                if (!t) return;
                var large = t.getAttribute('data-large');
                if (!large) return;
                lb.querySelector('img').src = large;
                lb.querySelector('.pjo-lightbox-cap').textContent = t.getAttribute('data-name') || '';
                lb.style.display = 'flex';
            });

            function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

            // ===== #3 Grupy pakowania (łączenie dubli) =====
            var packBar = document.getElementById('pjo-pack-bar');
            var packCount = document.getElementById('pjo-pack-count');
            function selectedPackIds() {
                return Array.prototype.slice.call(document.querySelectorAll('.pjo-pack-cb:checked')).map(function(c){ return c.value; });
            }
            function refreshPackBar() {
                if (!packBar) return;
                var ids = selectedPackIds();
                packCount.textContent = ids.length;
                packBar.style.display = ids.length >= 2 ? 'block' : 'none';
            }
            document.querySelectorAll('.pjo-pack-cb').forEach(function(cb){ cb.addEventListener('change', refreshPackBar); });
            var packLinkBtn = document.getElementById('pjo-pack-link');
            if (packLinkBtn) packLinkBtn.addEventListener('click', function() {
                var ids = selectedPackIds();
                if (ids.length < 2) return;
                var res = document.getElementById('pjo-pack-result');
                packLinkBtn.disabled = true; res.textContent = '⏳…';
                var fd = new FormData();
                fd.append('action', 'pjo_link_pack_group');
                fd.append('nonce', nonce);
                ids.forEach(function(id){ fd.append('order_ids[]', id); });
                fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        packLinkBtn.disabled = false;
                        if (!j.success) { res.innerHTML = '<span class="pjo-st-error">❌ ' + esc(j.data && j.data.message ? j.data.message : 'Błąd') + '</span>'; return; }
                        res.innerHTML = '✅ ' + esc(j.data.group) + ' — odświeżam…';
                        setTimeout(function(){ location.reload(); }, 600);
                    })
                    .catch(function(e){ packLinkBtn.disabled = false; res.innerHTML = '<span class="pjo-st-error">❌ ' + esc(e.message) + '</span>'; });
            });
            // Rozłączenie — klik w badge grupy
            document.addEventListener('click', function(e) {
                var b = e.target.closest ? e.target.closest('.pjo-pack-badge') : null;
                if (!b) return;
                var tr = b.closest('tr');
                var cb = tr ? tr.querySelector('.pjo-pack-cb') : null;
                var oid = cb ? cb.value : null;
                if (!oid) return;
                if (!confirm('Rozłączyć zamówienie #' + oid + ' z grupy pakowania ' + b.getAttribute('data-group') + '?')) return;
                var fd = new FormData();
                fd.append('action', 'pjo_unlink_pack_group');
                fd.append('nonce', nonce);
                fd.append('order_id', oid);
                fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(j){ if (j.success) location.reload(); else alert('Błąd'); });
            });

            // ===== Folder Builder (Faza C) =====

            function renderPlanTable(plan) {
                var html = '';
                if (plan._multi) html += '<div style="margin:10px 0 4px;font-weight:bold;">📦 Zamówienie #' + esc(plan.order_no) + ' — sezon <code>' + esc(plan.season || '—') + '</code></div>';
                var okCount = 0, missCount = 0, skipCount = 0;
                html += '<table class="pjo-build-table"><thead><tr><th>Plik (produkt)</th><th>Typ</th><th>Rozmiar</th><th>Il.</th><th>Status źródła</th><th>Nazwa docelowa</th></tr></thead><tbody>';
                (plan.items || []).forEach(function(it) {
                    var cls = '', st = '';
                    if (it.skip) { cls = 'row-skip'; st = '<span class="pjo-st-skip">— ' + esc(it.reason) + '</span>'; skipCount++; }
                    else if (it.source_found === true) { st = '<span class="pjo-st-ok">✓ ' + esc(it.source_dir) + '</span>'; okCount++; }
                    else if (it.source_found === false) { cls = 'row-missing'; st = '<span class="pjo-st-missing">✗ ' + esc(it.reason || 'brak') + '</span>'; missCount++; }
                    else { st = '—'; }
                    html += '<tr class="' + cls + '">';
                    html += '<td>' + esc(it.source_name) + '</td>';
                    html += '<td>' + esc(it.type || '—') + '</td>';
                    html += '<td>' + esc(it.size_raw || '—') + '</td>';
                    html += '<td>' + esc(it.qty) + '</td>';
                    html += '<td>' + st + '</td>';
                    html += '<td><span class="pjo-build-target">' + (it.target_dir ? esc(it.target_dir) + '/' : '') + esc(it.target_filename || it.target_base || '') + '</span></td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                return { html: html, ok: okCount, miss: missCount, skip: skipCount };
            }

            function renderPlan(box, data) {
                var orderId = box.getAttribute('data-order');
                var plans = data.plans || [];
                var first = plans[0] || {};
                var multi = !!data.is_group && plans.length > 1;
                var totalOk = 0, totalMiss = 0, totalSkip = 0, tableHtml = '';
                var allWarn = [];

                plans.forEach(function(p) {
                    p._multi = multi;
                    (p.warnings || []).forEach(function(w){ if (allWarn.indexOf(w) < 0) allWarn.push(w); });
                    var r = renderPlanTable(p);
                    tableHtml += r.html;
                    totalOk += r.ok; totalMiss += r.miss; totalSkip += r.skip;
                });

                var html = '<h4>🗂 Plan druku' + (multi ? ' — grupa pakowania (' + plans.length + ' zamówień)' : ' — zamówienie #' + esc(first.order_no)) + '</h4>';
                html += '<div class="pjo-build-meta">Cel: <code>' + esc(first.build_root) + '/{NUMER_WYDRUKU}</code> · Źródło: <code>' + esc(first.source_root) + '</code>';
                if (first.indexed != null) html += ' · Zindeksowano plików: <strong>' + first.indexed + '</strong>';
                html += '</div>';
                if (multi) html += '<div class="pjo-warn">📦 Zamówienia połączone w grupę pakowania — „Wykonaj" zbuduje wszystkie pod JEDNYM numerem wydruku i spakuje do jednej koperty.</div>';
                allWarn.forEach(function(w){ html += '<div class="pjo-warn">⚠ ' + esc(w) + '</div>'; });

                html += tableHtml;

                html += '<div class="pjo-build-actions">';
                if (totalOk > 0) {
                    html += '<button class="button button-primary pjo-build-exec" data-order="' + orderId + '">▶ Wykonaj na QNAP (' + totalOk + ' plik(ów))</button>';
                } else {
                    html += '<em>Brak plików gotowych do skopiowania (sprawdź dopasowanie nazw / magazyn źródłowy).</em>';
                }
                html += '<span style="font-size:12px;color:#555;">Gotowe: <strong class="pjo-st-ok">' + totalOk + '</strong> · Brak źródła: <strong class="pjo-st-missing">' + totalMiss + '</strong> · Pominięte: <strong>' + totalSkip + '</strong></span>';
                html += '<span class="pjo-build-exec-result" style="font-size:12px;"></span>';
                html += '</div>';
                box.innerHTML = html;
            }

            document.querySelectorAll('.pjo-build-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var orderId = btn.getAttribute('data-order');
                    var row = document.getElementById('pjo-build-' + orderId);
                    var box = row.querySelector('.pjo-build-content');
                    if (row.style.display === 'table-row') { row.style.display = 'none'; return; }
                    row.style.display = 'table-row';
                    box.innerHTML = '⏳ Łączę z QNAP, indeksuję magazyn i buduję plan… (może chwilę potrwać)';
                    var fd = new FormData();
                    fd.append('action', 'pjo_build_preview');
                    fd.append('nonce', nonce);
                    fd.append('order_id', orderId);
                    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
                        .then(function(r){ return r.json(); })
                        .then(function(j) {
                            if (!j.success) { box.innerHTML = '<div class="pjo-warn">❌ ' + esc(j.data && j.data.message ? j.data.message : 'Błąd') + '</div>'; return; }
                            renderPlan(box, j.data);
                        })
                        .catch(function(e){ box.innerHTML = '❌ ' + esc(e.message); });
                });
            });

            // Backfill: synchronizacja etapów z Photo Access (jednorazowo dla istniejących)
            var syncBtn = document.getElementById('pjo-sync-stages');
            if (syncBtn) syncBtn.addEventListener('click', function() {
                if (!confirm('Ustawić etap produkcji (Link wysłany / Niekompletne) dla wszystkich zamówień z już przyznanym dostępem?\nNie cofa ręcznie zaawansowanych etapów.')) return;
                var res = document.getElementById('pjo-sync-stages-result');
                syncBtn.disabled = true; res.textContent = '⏳ Synchronizuję…';
                var fd = new FormData();
                fd.append('action', 'pjo_sync_access_stages');
                fd.append('nonce', nonce);
                fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(j) {
                        syncBtn.disabled = false;
                        if (!j.success) { res.innerHTML = '<span class="pjo-st-error">❌ ' + esc(j.data && j.data.message ? j.data.message : 'Błąd') + '</span>'; return; }
                        res.innerHTML = '✅ Zaktualizowano <strong>' + j.data.updated + '</strong> z ' + j.data.scanned + ' (z dostępem) — odświeżam…';
                        setTimeout(function(){ location.reload(); }, 1000);
                    })
                    .catch(function(e){ syncBtn.disabled = false; res.innerHTML = '<span class="pjo-st-error">❌ ' + esc(e.message) + '</span>'; });
            });

            // Re-grant dostępu (Photo Access) — przycisk per zamówienie
            document.querySelectorAll('.pjo-regrant-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var orderId = btn.getAttribute('data-order');
                    if (!confirm('Przyznać ponownie dostęp do zdjęć dla zamówienia #' + orderId + '?\nKlient dostanie link ponownie (Photo Access).')) return;
                    var orig = btn.textContent;
                    btn.disabled = true; btn.textContent = '⏳';
                    var fd = new FormData();
                    fd.append('action', 'pjo_regrant_access');
                    fd.append('nonce', nonce);
                    fd.append('order_id', orderId);
                    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
                        .then(function(r){ return r.json(); })
                        .then(function(j) {
                            btn.disabled = false;
                            if (!j.success) { btn.textContent = orig; alert('Błąd: ' + (j.data && j.data.message ? j.data.message : 'unknown')); return; }
                            btn.textContent = '✅';
                            btn.title = (j.data && j.data.message) ? j.data.message : 'OK';
                            setTimeout(function(){ btn.textContent = orig; }, 2500);
                        })
                        .catch(function(e){ btn.disabled = false; btn.textContent = orig; alert(e.message); });
                });
            });

            // Execute (delegacja — przycisk renderowany dynamicznie)
            document.addEventListener('click', function(e) {
                var b = e.target.closest ? e.target.closest('.pjo-build-exec') : null;
                if (!b) return;
                var orderId = b.getAttribute('data-order');
                if (!confirm('Skopiować i przemianować pliki na QNAP wg planu? Operacja tworzy foldery i kopiuje pliki w magazynie druku.')) return;
                var resultSpan = b.parentNode.querySelector('.pjo-build-exec-result');
                b.disabled = true; b.textContent = '⏳ Kopiuję na QNAP…';
                var fd = new FormData();
                fd.append('action', 'pjo_build_execute');
                fd.append('nonce', nonce);
                fd.append('order_id', orderId);
                fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(j) {
                        b.disabled = false;
                        if (!j.success) { b.textContent = '▶ Wykonaj na QNAP'; if (resultSpan) resultSpan.innerHTML = ' <span class="pjo-st-error">❌ ' + esc(j.data && j.data.message ? j.data.message : 'Błąd') + '</span>'; return; }
                        var d = j.data;
                        b.textContent = '✅ Wydruk ' + esc(d.batch_number);
                        var errs = (d.errors && d.errors.length) ? ' · <span class="pjo-st-error">błędy: ' + d.errors.length + '</span>' : '';
                        if (resultSpan) resultSpan.innerHTML = ' Nr wydruku: <strong>' + esc(d.batch_number) + '</strong> · skopiowano <strong>' + d.copied + '</strong>, folderów: ' + d.created_dirs + errs + ' → <code>' + esc(d.base_path) + '</code>';
                    })
                    .catch(function(e){ b.disabled = false; b.textContent = '▶ Wykonaj na QNAP'; if (resultSpan) resultSpan.innerHTML = ' <span class="pjo-st-error">❌ ' + esc(e.message) + '</span>'; });
            });
        })();
        </script>
        <?php
    }

    /**
     * #3: Przestaw zamówienia tak, by członkowie tej samej grupy pakowania / klastra
     * dubli (mail/nazwisko) szli zaraz pod "kotwicą" (pierwszym z grupy w kolejności daty).
     * Reszta zachowuje naturalną kolejność daty. Działa W OBRĘBIE bieżącej strony —
     * jeśli bliźniak jest na innej stronie, zwiększ "/strona" albo zawęź filtr statusu.
     *
     * @param WC_Order[] $orders
     * @param array      $clusters  mapa order_id => ['siblings'=>[...]]
     * @return WC_Order[]
     */
    private function cluster_adjacent_orders( $orders, $clusters ) {
        if ( count( $orders ) < 2 ) {
            return $orders;
        }
        $ids = array();
        foreach ( $orders as $o ) {
            $ids[] = $o->get_id();
        }
        // pack_group dla zamówień z bieżącej strony — jednym zapytaniem.
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_order_meta';
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT order_id, pack_group FROM {$table} WHERE order_id IN ({$placeholders})", $ids
        ), ARRAY_A );
        $pack = array();
        foreach ( (array) $rows as $r ) {
            if ( ! empty( $r['pack_group'] ) ) {
                $pack[ (int) $r['order_id'] ] = $r['pack_group'];
            }
        }

        $emitted = array();
        $result  = array();
        foreach ( $orders as $o ) {
            $id = $o->get_id();
            if ( isset( $emitted[ $id ] ) ) {
                continue;
            }
            $result[] = $o;
            $emitted[ $id ] = true;

            // Zbierz bliźniaków obecnych na stronie (dubel + grupa pakowania).
            $sibs = array();
            if ( ! empty( $clusters[ $id ]['siblings'] ) ) {
                foreach ( $clusters[ $id ]['siblings'] as $s ) {
                    $sibs[ (int) $s ] = true;
                }
            }
            if ( ! empty( $pack[ $id ] ) ) {
                foreach ( $pack as $pid => $g ) {
                    if ( $g === $pack[ $id ] && $pid !== $id ) {
                        $sibs[ $pid ] = true;
                    }
                }
            }
            if ( empty( $sibs ) ) {
                continue;
            }
            // Dorzuć obecnych, niewyemitowanych — zachowując kolejność daty.
            foreach ( $orders as $o2 ) {
                $id2 = $o2->get_id();
                if ( isset( $sibs[ $id2 ] ) && ! isset( $emitted[ $id2 ] ) ) {
                    $result[] = $o2;
                    $emitted[ $id2 ] = true;
                }
            }
        }
        return $result;
    }

    private function render_order_row( $order, $is_worker, $banks, $wc_statuses, $prod_statuses, $clusters = array() ) {
        global $wpdb;
        $meta_table = $wpdb->prefix . 'pjo_order_meta';
        $oid = $order->get_id();
        $meta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$meta_table} WHERE order_id=%d", $oid ), ARRAY_A );
        $pack_group = $meta['pack_group'] ?? '';
        $print_batch = $meta['print_batch'] ?? '';
        $dup = $clusters[ $oid ] ?? null;

        $status = 'wc-' . $order->get_status();
        $status_label = wc_get_order_status_name( $status );
        $bank = $meta['bank_account'] ?? '';
        $prod_status = $meta['production_status'] ?? 'pending';
        $customer_note = $meta['customer_note_cached'] ?? $order->get_customer_note();
        $wants_invoice = (int) ( $meta['wants_invoice'] ?? 0 );

        $client = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        if ( ! $client ) {
            $client = $order->get_billing_company();
        }
        $email = $order->get_billing_email();
        $phone = $order->get_billing_phone();
        $total_fmt = $order->get_formatted_order_total();
        $date = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : '—';
        ?>
        <tr class="status-<?php echo esc_attr( $status ); ?>">
            <td><button class="pjo-expand-btn" data-order="<?php echo esc_attr( $oid ); ?>" title="<?php esc_attr_e( 'Pokaż produkty zamówienia', 'photojob-organizer' ); ?>">+</button></td>
            <td><?php echo esc_html( $date ); ?></td>
            <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $oid ) ); ?>" target="_blank">#<?php echo esc_html( $oid ); ?></a></td>
            <td>
                <?php if ( ! $is_worker ) : ?>
                    <input type="checkbox" class="pjo-pack-cb" value="<?php echo esc_attr( $oid ); ?>" style="margin-right:4px;" title="<?php esc_attr_e( 'Zaznacz do połączenia', 'photojob-organizer' ); ?>">
                <?php endif; ?>
                <?php echo esc_html( $client ); ?>
                <?php if ( $dup ) : ?>
                    <?php
                    $sib = array_map( function( $s ) { return '#' . $s; }, (array) $dup['siblings'] );
                    $reason_lbl = strpos( $dup['reason'], 'email' ) !== false ? __( 'ten sam e-mail', 'photojob-organizer' ) : __( 'to samo nazwisko', 'photojob-organizer' );
                    ?>
                    <span class="pjo-dup-badge" title="<?php echo esc_attr( $reason_lbl . ': ' . implode( ', ', $sib ) ); ?>">👥 <?php _e( 'dubel', 'photojob-organizer' ); ?></span>
                <?php endif; ?>
                <?php if ( $pack_group ) : ?>
                    <span class="pjo-pack-badge" data-group="<?php echo esc_attr( $pack_group ); ?>" title="<?php echo esc_attr( __( 'Grupa pakowania — kliknij, by rozłączyć', 'photojob-organizer' ) ); ?>">📦 <?php echo esc_html( $pack_group ); ?> ✕</span>
                <?php endif; ?>
            </td>
            <?php if ( ! $is_worker ) : ?>
                <td>
                    <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
                    <?php if ( $phone ) : ?><br><a href="tel:<?php echo esc_attr( $phone ); ?>" style="color:#555;"><?php echo esc_html( $phone ); ?></a><?php endif; ?>
                </td>
                <td><strong><?php echo wp_kses_post( $total_fmt ); ?></strong></td>
                <td>
                    <select class="pjo-inline-edit" data-order="<?php echo esc_attr( $oid ); ?>" data-field="status" data-original="<?php echo esc_attr( $status ); ?>">
                        <?php foreach ( $wc_statuses as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <?php
                    // #1: brak zapisanego banku → domyślnie pierwszy z listy (np. mBank).
                    $bank_display = $bank !== '' ? $bank : ( ! empty( $banks ) ? (string) reset( $banks ) : '' );
                    ?>
                    <select class="pjo-inline-edit" data-order="<?php echo esc_attr( $oid ); ?>" data-field="bank" data-original="<?php echo esc_attr( $bank ); ?>">
                        <option value=""><?php _e( '— wybierz —', 'photojob-organizer' ); ?></option>
                        <?php foreach ( $banks as $b ) : ?>
                            <option value="<?php echo esc_attr( $b ); ?>" <?php selected( $bank_display, $b ); ?>><?php echo esc_html( $b ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            <?php endif; ?>
            <td>
                <select class="pjo-inline-edit" data-order="<?php echo esc_attr( $oid ); ?>" data-field="production_status" data-original="<?php echo esc_attr( $prod_status ); ?>">
                    <?php foreach ( $prod_statuses as $ps ) : ?>
                        <option value="<?php echo esc_attr( $ps ); ?>" <?php selected( $prod_status, $ps ); ?>><?php echo esc_html( self::production_status_label( $ps ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <?php if ( $wants_invoice ) : ?>
                    <span class="pjo-fv-badge" title="<?php echo esc_attr( $customer_note ); ?>">🧾 FV</span>
                <?php endif; ?>
                <?php if ( $customer_note && ! $wants_invoice ) : ?>
                    <span class="pjo-note-badge" title="<?php echo esc_attr( $customer_note ); ?>">📝 <?php _e( 'uwagi', 'photojob-organizer' ); ?></span>
                <?php elseif ( $customer_note && $wants_invoice ) : ?>
                    <span class="pjo-note-badge" title="<?php echo esc_attr( $customer_note ); ?>">📝</span>
                <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $oid ) ); ?>" target="_blank" class="button button-small" title="<?php esc_attr_e( 'Edytuj w WooCommerce', 'photojob-organizer' ); ?>">↗</a>
                <?php if ( ! $is_worker ) : ?>
                    <button class="button button-small pjo-build-btn" data-order="<?php echo esc_attr( $oid ); ?>" title="<?php esc_attr_e( 'Zbuduj folder druku na QNAP', 'photojob-organizer' ); ?>">🗂</button>
                    <button class="button button-small pjo-regrant-btn" data-order="<?php echo esc_attr( $oid ); ?>" title="<?php esc_attr_e( 'Photo Access: wymuś ponowne przyznanie dostępu (ponowny link do klienta)', 'photojob-organizer' ); ?>">🔓</button>
                <?php endif; ?>
                <?php if ( ! empty( $meta['qnap_folder_path'] ) ) : ?>
                    <span class="pjo-built-flag" title="<?php echo esc_attr( $meta['qnap_folder_path'] ); ?>">✅</span>
                <?php endif; ?>
                <?php if ( $print_batch ) : ?>
                    <span class="pjo-batch-badge" title="<?php esc_attr_e( 'Numer wydruku (zamówione do druku)', 'photojob-organizer' ); ?>">🖨 <?php echo esc_html( $print_batch ); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <tr id="pjo-items-<?php echo esc_attr( $oid ); ?>" class="pjo-line-items-row" style="display:none;">
            <td colspan="<?php echo $is_worker ? 6 : 11; ?>">
                <div class="pjo-line-items-content" data-loaded="0"></div>
            </td>
        </tr>
        <?php if ( ! $is_worker ) : ?>
        <tr id="pjo-build-<?php echo esc_attr( $oid ); ?>" class="pjo-build-row" style="display:none;">
            <td colspan="11">
                <div class="pjo-build-content" data-order="<?php echo esc_attr( $oid ); ?>"></div>
            </td>
        </tr>
        <?php endif; ?>
        <?php
    }

    private function render_pagination( $f, $total_pages, $total ) {
        if ( $total_pages <= 1 ) {
            return;
        }
        $current = $f['paged'];
        $base_url = remove_query_arg( 'paged' );
        ?>
        <div class="tablenav" style="margin:10px 0;">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php printf( __( '%d zamówień', 'photojob-organizer' ), $total ); ?></span>
                <span class="pagination-links">
                    <?php
                    $pages_to_show = array( 1, $current - 1, $current, $current + 1, $total_pages );
                    $pages_to_show = array_unique( array_filter( $pages_to_show, function( $p ) use ( $total_pages ) { return $p >= 1 && $p <= $total_pages; } ) );
                    sort( $pages_to_show );
                    $last_p = 0;
                    foreach ( $pages_to_show as $p ) {
                        if ( $last_p && $p - $last_p > 1 ) {
                            echo ' … ';
                        }
                        if ( $p === $current ) {
                            echo '<span class="current" style="padding:4px 8px;background:#2271b1;color:#fff;border-radius:3px;">' . $p . '</span> ';
                        } else {
                            $url = add_query_arg( 'paged', $p, $base_url );
                            echo '<a class="button" href="' . esc_url( $url ) . '">' . $p . '</a> ';
                        }
                        $last_p = $p;
                    }
                    ?>
                </span>
            </div>
        </div>
        <?php
    }

    private function build_query( $f, $is_worker ) {
        $args = array(
            'limit'    => $f['per_page'],
            'paged'    => $f['paged'],
            'orderby'  => 'date',
            'order'    => 'DESC',
            'return'   => 'objects',
        );
        if ( $f['date_from'] ) {
            $args['date_created'] = '>=' . $f['date_from'];
        }
        if ( $f['date_to'] ) {
            if ( isset( $args['date_created'] ) ) {
                $args['date_created'] .= '...' . $f['date_to'] . ' 23:59:59';
            } else {
                $args['date_created'] = '<=' . $f['date_to'] . ' 23:59:59';
            }
        }
        if ( $f['status'] ) {
            $args['status'] = str_replace( 'wc-', '', $f['status'] );
        }
        if ( $f['search'] ) {
            $args['s'] = $f['search'];
        }
        return $args;
    }

    private function count_orders( $f ) {
        $count_args = $this->build_query( $f, false );
        $count_args['limit'] = -1;
        $count_args['return'] = 'ids';
        $count_args['paged'] = 1;
        $ids = wc_get_orders( $count_args );
        return is_array( $ids ) ? count( $ids ) : 0;
    }
}
