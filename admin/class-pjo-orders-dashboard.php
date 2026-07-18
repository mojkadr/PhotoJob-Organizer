<?php
/**
 * PhotoJob_Orders_Dashboard — lista zamówień z kolumnami z Excela "Zestawienie zamówień"
 *
 * Funkcje:
 *  - Lista zamówień WC z dodatkowymi polami z pjo_order_meta (bank, uwagi cached, FV)
 *  - Zamówienia spoza sklepu (ręczne, created_via=pjo-manual) — telefon/mail/osobiście
 *  - Filtry: zakres dat, status WC, FV, search
 *  - Inline edit (Status WC, Bank) — AJAX
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
        add_action( 'wp_ajax_pjo_build_bulk', array( $this, 'ajax_build_bulk' ) );
        add_action( 'wp_ajax_pjo_build_append', array( $this, 'ajax_build_append' ) );
        add_action( 'wp_ajax_pjo_link_pack_group', array( $this, 'ajax_link_pack_group' ) );
        add_action( 'wp_ajax_pjo_unlink_pack_group', array( $this, 'ajax_unlink_pack_group' ) );
        add_action( 'wp_ajax_pjo_regrant_access', array( $this, 'ajax_regrant_access' ) );
        add_action( 'wp_ajax_pjo_add_manual_order', array( $this, 'ajax_add_manual_order' ) );
    }

    /** ============ ZAMÓWIENIA SPOZA SKLEPU (ręczne) ============ */

    /**
     * Dodaj zamówienie spoza WooCommerce (telefon / mail / osobiście) — żeby przy
     * pakowaniu nic nie zginęło. Tworzy PRAWDZIWE zamówienie WC (created_via=pjo-manual),
     * więc działa z całą resztą dashboardu: statusy, grupy pakowania, filtry, raporty.
     */
    public function ajax_add_manual_order() {
        if ( ! current_user_can( 'pjo_manage_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'photojob-organizer' ) ) );
        }
        check_ajax_referer( 'pjo_dashboard', 'nonce' );
        if ( ! function_exists( 'wc_create_order' ) ) {
            wp_send_json_error( array( 'message' => __( 'WooCommerce nieaktywne.', 'photojob-organizer' ) ) );
        }

        $name   = sanitize_text_field( wp_unslash( $_POST['client_name'] ?? '' ) );
        $email  = sanitize_email( wp_unslash( $_POST['client_email'] ?? '' ) );
        $phone  = sanitize_text_field( wp_unslash( $_POST['client_phone'] ?? '' ) );
        $amount = (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['amount'] ?? '0' ) ) );
        $items_raw = sanitize_textarea_field( wp_unslash( $_POST['items'] ?? '' ) );
        $note   = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

        if ( $name === '' ) {
            wp_send_json_error( array( 'message' => __( 'Podaj imię i nazwisko klienta.', 'photojob-organizer' ) ) );
        }
        if ( $amount < 0 || $amount > 1000000 ) {
            wp_send_json_error( array( 'message' => __( 'Nieprawidłowa kwota.', 'photojob-organizer' ) ) );
        }

        $order = wc_create_order( array(
            'status'      => 'processing',
            'created_via' => 'pjo-manual',
        ) );
        if ( is_wp_error( $order ) ) {
            wp_send_json_error( array( 'message' => $order->get_error_message() ) );
        }

        // Imię/nazwisko: ostatnie słowo = nazwisko, reszta = imię.
        $parts = preg_split( '/\s+/u', $name );
        $last  = count( $parts ) > 1 ? array_pop( $parts ) : '';
        $order->set_billing_first_name( implode( ' ', $parts ) );
        $order->set_billing_last_name( $last );
        if ( $email !== '' ) {
            $order->set_billing_email( $email );
        }
        if ( $phone !== '' ) {
            $order->set_billing_phone( $phone );
        }
        if ( $note !== '' ) {
            $order->set_customer_note( $note );
        }

        // Pozycje: jedna linia = pozycja, opcjonalnie " x2" na końcu = ilość.
        $added_items = 0;
        foreach ( preg_split( '/\r\n|\r|\n/', $items_raw ) as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            $qty = 1;
            if ( preg_match( '/^(.*?)\s*[x×]\s*(\d{1,3})$/u', $line, $m ) ) {
                $line = trim( $m[1] );
                $qty  = max( 1, (int) $m[2] );
            }
            if ( $line === '' ) {
                continue;
            }
            $item = new WC_Order_Item_Product();
            $item->set_name( $line );
            $item->set_quantity( $qty );
            $order->add_item( $item );
            $added_items++;
        }

        $order->set_total( $amount );
        $order->update_meta_data( '_pjo_manual_order', 'yes' );
        $order->save();
        $order->add_order_note( sprintf(
            /* translators: %s = login użytkownika */
            __( '[PJO] Zamówienie spoza sklepu dodane ręcznie przez %s.', 'photojob-organizer' ),
            wp_get_current_user()->user_login
        ) );

        // Cache uwag + heurystyka FV do pjo_order_meta (ten sam mechanizm co dla zamówień WC).
        if ( class_exists( 'PhotoJob_Organizer' ) ) {
            PhotoJob_Organizer::get_instance()->sync_order_meta( $order->get_id() );
        }

        wp_send_json_success( array(
            'order_id' => $order->get_id(),
            'items'    => $added_items,
            'message'  => sprintf( __( 'Dodano zamówienie #%d.', 'photojob-organizer' ), $order->get_id() ),
        ) );
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

        // Wymuś świeży skan magazynu (np. po dodaniu nowych sesji) — czyści transient.
        if ( ! empty( $_POST['refresh'] ) ) {
            $qnap->clear_source_index( PhotoJob_Folder_Builder::source_root() );
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

    /**
     * Grupowe budowanie folderu druku: WSZYSTKIE zaznaczone zamówienia pod JEDNYM numerem
     * (jeden folder akcji), pogrupowane po formacie odbitki. Jedno logowanie + jeden skan.
     */
    public function ajax_build_bulk() {
        if ( ! current_user_can( 'pjo_manage_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'photojob-organizer' ) ) );
        }
        check_ajax_referer( 'pjo_dashboard', 'nonce' );
        $ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : array();
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Nie zaznaczono zamówień.', 'photojob-organizer' ) ) );
        }
        @set_time_limit( 0 );
        ignore_user_abort( true );

        $qnap = new PhotoJob_QNAP_Client();
        if ( ! $qnap->is_configured() ) {
            wp_send_json_error( array( 'message' => __( 'QNAP nie jest skonfigurowany.', 'photojob-organizer' ) ) );
        }
        if ( ! $qnap->login() ) {
            wp_send_json_error( array( 'message' => __( 'Logowanie do QNAP: ', 'photojob-organizer' ) . $qnap->last_error ) );
        }
        // Pre-skan raz (cache) — build_plan każdego zlecenia użyje gotowego indeksu.
        $qnap->index_source_files( PhotoJob_Folder_Builder::source_root() );

        $res = PhotoJob_Folder_Builder::execute_bulk( $ids, $qnap );
        $qnap->logout();
        if ( isset( $res['error'] ) ) {
            wp_send_json_error( array( 'message' => $res['error'] ) );
        }
        wp_send_json_success( array(
            'batch_number' => $res['batch_number'],
            'orders'       => count( (array) $res['orders'] ),
            'copied'       => (int) $res['copied'],
            'errors'       => count( (array) $res['errors'] ),
            'created_dirs' => (int) $res['created_dirs'],
            'base_path'    => $res['base_path'],
        ) );
    }

    /**
     * Dopisanie zaznaczonych zamówień do JUŻ ISTNIEJĄCEGO folderu wydruku (numeru).
     */
    public function ajax_build_append() {
        if ( ! current_user_can( 'pjo_manage_orders' ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'photojob-organizer' ) ) );
        }
        check_ajax_referer( 'pjo_dashboard', 'nonce' );
        $ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : array();
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        $batch_number = sanitize_text_field( wp_unslash( $_POST['batch_number'] ?? '' ) );
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Nie zaznaczono zamówień.', 'photojob-organizer' ) ) );
        }
        if ( $batch_number === '' ) {
            wp_send_json_error( array( 'message' => __( 'Nie wybrano folderu wydruku.', 'photojob-organizer' ) ) );
        }
        @set_time_limit( 0 );
        ignore_user_abort( true );

        $qnap = new PhotoJob_QNAP_Client();
        if ( ! $qnap->is_configured() ) {
            wp_send_json_error( array( 'message' => __( 'QNAP nie jest skonfigurowany.', 'photojob-organizer' ) ) );
        }
        if ( ! $qnap->login() ) {
            wp_send_json_error( array( 'message' => __( 'Logowanie do QNAP: ', 'photojob-organizer' ) . $qnap->last_error ) );
        }
        $qnap->index_source_files( PhotoJob_Folder_Builder::source_root() );

        $res = PhotoJob_Folder_Builder::execute_into_batch( $ids, $batch_number, $qnap );
        $qnap->logout();
        if ( isset( $res['error'] ) ) {
            wp_send_json_error( array( 'message' => $res['error'] ) );
        }
        wp_send_json_success( array(
            'batch_number' => $res['batch_number'],
            'orders'       => count( (array) $res['orders'] ),
            'copied'       => (int) $res['copied'],
            'errors'       => count( (array) $res['errors'] ),
            'created_dirs' => (int) $res['created_dirs'],
            'base_path'    => $res['base_path'],
            'appended'     => true,
        ) );
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
        // Meta wszystkich zamówień strony JEDNYM zapytaniem (koniec z N+1 per wiersz).
        $metas = $this->fetch_page_meta( $orders );
        // #3: klastry dubli (mail/nazwisko) — tylko dla zarządzających.
        $clusters = ( ! $is_worker && class_exists( 'PhotoJob_Duplicates' ) ) ? PhotoJob_Duplicates::get_clusters() : array();
        // #3: ułóż duble i grupy pakowania obok siebie (ułatwia pakowanie).
        $orders = $this->cluster_adjacent_orders( $orders, $clusters, $metas );
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
                <button type="button" class="button" id="pjo-add-manual">➕ <?php _e( 'Dodaj zamówienie spoza sklepu', 'photojob-organizer' ); ?></button>
                <span class="description" style="font-size:11px;margin-left:6px;"><?php _e( 'Telefon / mail / osobiście — trafia na listę jak każde inne, żeby nie zginęło przy pakowaniu.', 'photojob-organizer' ); ?></span>
            </div>
            <div id="pjo-manual-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100001;align-items:center;justify-content:center;">
                <div style="background:#fff;border-radius:6px;padding:20px 24px;width:440px;max-width:92vw;max-height:90vh;overflow:auto;box-shadow:0 8px 40px rgba(0,0,0,.35);">
                    <h2 style="margin:0 0 12px;">➕ <?php _e( 'Zamówienie spoza sklepu', 'photojob-organizer' ); ?></h2>
                    <p style="margin:4px 0;"><label><strong><?php _e( 'Imię i nazwisko *', 'photojob-organizer' ); ?></strong><br>
                        <input type="text" id="pjo-m-name" style="width:100%;"></label></p>
                    <p style="margin:4px 0;"><label><?php _e( 'Email', 'photojob-organizer' ); ?><br>
                        <input type="email" id="pjo-m-email" style="width:100%;"></label></p>
                    <p style="margin:4px 0;"><label><?php _e( 'Telefon', 'photojob-organizer' ); ?><br>
                        <input type="text" id="pjo-m-phone" style="width:100%;"></label></p>
                    <p style="margin:4px 0;"><label><?php _e( 'Kwota (zł)', 'photojob-organizer' ); ?><br>
                        <input type="text" id="pjo-m-amount" placeholder="0" style="width:120px;"></label></p>
                    <p style="margin:4px 0;"><label><?php _e( 'Pozycje (jedna na linię, ilość jako „x2" na końcu)', 'photojob-organizer' ); ?><br>
                        <textarea id="pjo-m-items" rows="4" style="width:100%;" placeholder="<?php esc_attr_e( "Odbitka 15x23 Zosia x3\nFolio BOX Kacper", 'photojob-organizer' ); ?>"></textarea></label></p>
                    <p style="margin:4px 0;"><label><?php _e( 'Uwagi', 'photojob-organizer' ); ?><br>
                        <textarea id="pjo-m-note" rows="2" style="width:100%;"></textarea></label></p>
                    <p style="margin:14px 0 0;display:flex;gap:10px;align-items:center;">
                        <button type="button" class="button button-primary" id="pjo-m-save"><?php _e( 'Dodaj zamówienie', 'photojob-organizer' ); ?></button>
                        <button type="button" class="button" id="pjo-m-cancel"><?php _e( 'Anuluj', 'photojob-organizer' ); ?></button>
                        <span id="pjo-m-result" style="font-size:12px;"></span>
                    </p>
                </div>
            </div>
            <?php $recent_batches = class_exists( 'PhotoJob_Print_Batch' ) ? PhotoJob_Print_Batch::recent( 25 ) : array(); ?>
            <div id="pjo-pack-bar" style="display:none;background:#fff;border:1px solid #c3c4c7;padding:8px 12px;margin:10px 0;border-radius:4px;line-height:2;">
                <strong><?php _e( 'Zaznaczone:', 'photojob-organizer' ); ?> <span id="pjo-pack-count">0</span></strong>
                <button type="button" class="button button-primary" id="pjo-pack-build">🗂 <?php _e( 'Zbuduj NOWY folder druku', 'photojob-organizer' ); ?></button>
                <span style="margin:0 4px;color:#aaa;">|</span>
                <label><?php _e( 'albo dodaj do istniejącego:', 'photojob-organizer' ); ?>
                    <select id="pjo-append-batch" style="max-width:240px;">
                        <option value=""><?php _e( '— wybierz folder —', 'photojob-organizer' ); ?></option>
                        <?php foreach ( $recent_batches as $b ) :
                            $bn = $b['number'];
                            $cnt = (int) $b['file_count'];
                            $when = ! empty( $b['created_at'] ) ? mysql2date( 'd.m', $b['created_at'] ) : '';
                            ?>
                            <option value="<?php echo esc_attr( $bn ); ?>"><?php echo esc_html( $bn . ' · ' . $cnt . ' plik. · ' . $when ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="button" class="button" id="pjo-pack-append">➕ <?php _e( 'Dodaj', 'photojob-organizer' ); ?></button>
                <span style="margin:0 4px;color:#aaa;">|</span>
                <button type="button" class="button" id="pjo-pack-link">📦 <?php _e( 'Połącz w grupę pakowania', 'photojob-organizer' ); ?></button>
                <span id="pjo-pack-result" style="margin-left:8px;font-size:12px;"></span>
            </div>
            <?php endif; ?>

            <?php $this->render_pagination( $f, $total_pages, $total ); ?>

            <table class="wp-list-table widefat striped pjo-orders-table">
                <thead><tr>
                    <?php if ( ! $is_worker ) : ?>
                        <th style="width:28px;"><input type="checkbox" id="pjo-select-all" title="<?php esc_attr_e( 'Zaznacz/odznacz wszystkie na stronie', 'photojob-organizer' ); ?>"></th>
                    <?php endif; ?>
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
                    <th style="width:120px;"><?php _e( 'Uwagi / FV', 'photojob-organizer' ); ?></th>
                    <th style="width:80px;"><?php _e( 'Akcje', 'photojob-organizer' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( empty( $orders ) ) : ?>
                    <tr><td colspan="<?php echo $is_worker ? 6 : 11; ?>"><em><?php _e( 'Brak zamówień w wybranym zakresie.', 'photojob-organizer' ); ?></em></td></tr>
                <?php else :
                    foreach ( $orders as $order ) :
                        $this->render_order_row( $order, $is_worker, $banks, $wc_statuses, $clusters, $metas );
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
        .pjo-manual-badge { background:#0e7490; color:#fff; padding:1px 6px; border-radius:3px; font-size:11px; cursor:help; margin-left:4px; white-space:nowrap; }
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

            // Inline edit (status, bank)
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
            var packBuildBtn = document.getElementById('pjo-pack-build');
            function refreshPackBar() {
                if (!packBar) return;
                var ids = selectedPackIds();
                packCount.textContent = ids.length;
                packBar.style.display = ids.length >= 1 ? 'block' : 'none';
                if (packLinkBtn) packLinkBtn.style.display = ids.length >= 2 ? '' : 'none';
                if (packBuildBtn) packBuildBtn.disabled = ids.length < 1;
            }
            document.querySelectorAll('.pjo-pack-cb').forEach(function(cb){ cb.addEventListener('change', refreshPackBar); });

            // Zaznacz/odznacz wszystkie na stronie (checkbox w nagłówku)
            var selectAll = document.getElementById('pjo-select-all');
            if (selectAll) selectAll.addEventListener('change', function(){
                document.querySelectorAll('.pjo-pack-cb').forEach(function(cb){ cb.checked = selectAll.checked; });
                refreshPackBar();
            });

            // Grupowe budowanie folderów druku (jedno logowanie + jeden skan na całą paczkę)
            if (packBuildBtn) packBuildBtn.addEventListener('click', function() {
                var ids = selectedPackIds();
                if (ids.length < 1) return;
                if (!confirm('Zbudować JEDEN folder druku na QNAP dla ' + ids.length + ' zaznaczonych zamówień?\nWszystkie trafią pod jeden numer wydruku, pogrupowane po formacie odbitki.')) return;
                var res = document.getElementById('pjo-pack-result');
                packBuildBtn.disabled = true; res.textContent = '⏳ Buduję jeden folder akcji (jeden skan magazynu na całość)…';
                var fd = new FormData();
                fd.append('action', 'pjo_build_bulk');
                fd.append('nonce', nonce);
                ids.forEach(function(id){ fd.append('order_ids[]', id); });
                fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        packBuildBtn.disabled = false;
                        if (!j.success) { res.innerHTML = '<span class="pjo-st-error">❌ ' + esc(j.data && j.data.message ? j.data.message : 'Błąd') + '</span>'; return; }
                        var d = j.data;
                        var errs = d.errors ? ' · <span class="pjo-st-error">błędy: ' + d.errors + '</span>' : '';
                        res.innerHTML = '✅ Wydruk <strong>' + esc(d.batch_number) + '</strong> — ' + d.orders + ' zam., skopiowano <strong>' + d.copied + '</strong> do <code>' + esc(d.base_path) + '</code>' + errs + ' — odświeżam…';
                        setTimeout(function(){ location.reload(); }, 1600);
                    })
                    .catch(function(e){ packBuildBtn.disabled = false; res.innerHTML = '<span class="pjo-st-error">❌ ' + esc(e.message) + '</span>'; });
            });
            // Dodaj zaznaczone do ISTNIEJĄCEGO folderu wydruku (numeru z dropdownu)
            var packAppendBtn = document.getElementById('pjo-pack-append');
            var appendSelect = document.getElementById('pjo-append-batch');
            if (packAppendBtn) packAppendBtn.addEventListener('click', function() {
                var ids = selectedPackIds();
                var batch = appendSelect ? appendSelect.value : '';
                if (ids.length < 1) { alert('Najpierw zaznacz zamówienia.'); return; }
                if (!batch) { alert('Wybierz istniejący folder wydruku z listy.'); return; }
                if (!confirm('Dodać ' + ids.length + ' zaznaczonych zamówień do istniejącego folderu ' + batch + '?\nPliki trafią do tych samych podfolderów formatu — bez nowego numeru.')) return;
                var res = document.getElementById('pjo-pack-result');
                packAppendBtn.disabled = true; res.textContent = '⏳ Dodaję do ' + batch + '…';
                var fd = new FormData();
                fd.append('action', 'pjo_build_append');
                fd.append('nonce', nonce);
                fd.append('batch_number', batch);
                ids.forEach(function(id){ fd.append('order_ids[]', id); });
                fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        packAppendBtn.disabled = false;
                        if (!j.success) { res.innerHTML = '<span class="pjo-st-error">❌ ' + esc(j.data && j.data.message ? j.data.message : 'Błąd') + '</span>'; return; }
                        var d = j.data;
                        var errs = d.errors ? ' · <span class="pjo-st-error">błędy: ' + d.errors + '</span>' : '';
                        res.innerHTML = '✅ Dopisano do <strong>' + esc(d.batch_number) + '</strong> — ' + d.orders + ' zam., skopiowano <strong>' + d.copied + '</strong> do <code>' + esc(d.base_path) + '</code>' + errs + ' — odświeżam…';
                        setTimeout(function(){ location.reload(); }, 1600);
                    })
                    .catch(function(e){ packAppendBtn.disabled = false; res.innerHTML = '<span class="pjo-st-error">❌ ' + esc(e.message) + '</span>'; });
            });

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
                if (first.indexed != null) html += ' · Zindeksowano plików: <strong>' + first.indexed + '</strong> <span style="color:#888;">(cache 15 min)</span>';
                html += ' <button class="button button-small pjo-build-refresh" data-order="' + orderId + '" title="Przeskanuj magazyn od nowa (po dodaniu nowych sesji)">🔄 Odśwież indeks</button>';
                html += '</div>';
                if (multi) html += '<div class="pjo-warn">📦 Zamówienia połączone w grupę pakowania — „Wykonaj" zbuduje wszystkie pod JEDNYM numerem wydruku i spakuje do jednej koperty.</div>';
                allWarn.forEach(function(w){ html += '<div class="pjo-warn">⚠ ' + esc(w) + '</div>'; });

                html += tableHtml;

                html += '<div class="pjo-build-actions" style="flex-wrap:wrap;">';
                if (totalOk > 0) {
                    html += '<button class="button button-primary pjo-build-select" data-order="' + orderId + '">✓ Zaznacz do druku</button>';
                    html += '<span class="pjo-warn" style="margin:0;">To tylko <strong>podgląd</strong>. Budowanie odbywa się <strong>zbiorczo → jeden folder</strong>: zaznacz zamówienia i u góry kliknij <strong>„🗂 Zbuduj NOWY folder druku"</strong> (np. <code>26Z…</code>) albo <strong>„➕ Dodaj"</strong> do istniejącego.</span>';
                } else {
                    html += '<em>Brak plików gotowych do skopiowania (sprawdź dopasowanie nazw / magazyn źródłowy).</em>';
                }
                html += '<span style="font-size:12px;color:#555;">Gotowe: <strong class="pjo-st-ok">' + totalOk + '</strong> · Brak źródła: <strong class="pjo-st-missing">' + totalMiss + '</strong> · Pominięte: <strong>' + totalSkip + '</strong></span>';
                html += '</div>';
                box.innerHTML = html;
            }

            function loadBuildPreview(orderId, box, refresh) {
                box.innerHTML = refresh
                    ? '⏳ Skanuję magazyn od nowa…'
                    : '⏳ Łączę z QNAP, indeksuję magazyn i buduję plan… (pierwszy raz może chwilę potrwać, potem z cache)';
                var fd = new FormData();
                fd.append('action', 'pjo_build_preview');
                fd.append('nonce', nonce);
                fd.append('order_id', orderId);
                if (refresh) fd.append('refresh', '1');
                fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(j) {
                        if (!j.success) { box.innerHTML = '<div class="pjo-warn">❌ ' + esc(j.data && j.data.message ? j.data.message : 'Błąd') + '</div>'; return; }
                        renderPlan(box, j.data);
                    })
                    .catch(function(e){ box.innerHTML = '❌ ' + esc(e.message); });
            }

            document.querySelectorAll('.pjo-build-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var orderId = btn.getAttribute('data-order');
                    var row = document.getElementById('pjo-build-' + orderId);
                    var box = row.querySelector('.pjo-build-content');
                    if (row.style.display === 'table-row') { row.style.display = 'none'; return; }
                    row.style.display = 'table-row';
                    loadBuildPreview(orderId, box, false);
                });
            });

            // „Zaznacz do druku" z podglądu pojedynczego zamówienia → odhacz checkbox + pokaż pasek zbiorczy
            document.addEventListener('click', function(e) {
                var b = e.target.closest ? e.target.closest('.pjo-build-select') : null;
                if (!b) return;
                var orderId = b.getAttribute('data-order');
                var cb = document.querySelector('.pjo-pack-cb[value="' + orderId + '"]');
                if (cb) { cb.checked = true; refreshPackBar(); }
                b.textContent = '✓ Zaznaczone';
                b.disabled = true;
                if (packBar) packBar.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });

            // Odśwież indeks magazynu (delegacja — przycisk renderowany dynamicznie)
            document.addEventListener('click', function(e) {
                var b = e.target.closest ? e.target.closest('.pjo-build-refresh') : null;
                if (!b) return;
                var orderId = b.getAttribute('data-order');
                var row = document.getElementById('pjo-build-' + orderId);
                if (!row) return;
                loadBuildPreview(orderId, row.querySelector('.pjo-build-content'), true);
            });

            // ===== Zamówienie spoza sklepu (modal) =====
            var mModal = document.getElementById('pjo-manual-modal');
            var mBtn = document.getElementById('pjo-add-manual');
            if (mBtn && mModal) {
                var mSave = document.getElementById('pjo-m-save');
                var mRes = document.getElementById('pjo-m-result');
                function mOpen(){ mModal.style.display = 'flex'; document.getElementById('pjo-m-name').focus(); }
                function mClose(){ mModal.style.display = 'none'; mRes.textContent = ''; }
                mBtn.addEventListener('click', mOpen);
                document.getElementById('pjo-m-cancel').addEventListener('click', mClose);
                mModal.addEventListener('click', function(e){ if (e.target === mModal) mClose(); });
                mSave.addEventListener('click', function() {
                    var name = document.getElementById('pjo-m-name').value.trim();
                    if (!name) { mRes.innerHTML = '<span class="pjo-st-error">Podaj imię i nazwisko.</span>'; return; }
                    mSave.disabled = true; mRes.textContent = '⏳ Dodaję…';
                    var fd = new FormData();
                    fd.append('action', 'pjo_add_manual_order');
                    fd.append('nonce', nonce);
                    fd.append('client_name', name);
                    fd.append('client_email', document.getElementById('pjo-m-email').value.trim());
                    fd.append('client_phone', document.getElementById('pjo-m-phone').value.trim());
                    fd.append('amount', document.getElementById('pjo-m-amount').value.trim() || '0');
                    fd.append('items', document.getElementById('pjo-m-items').value);
                    fd.append('note', document.getElementById('pjo-m-note').value);
                    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:fd })
                        .then(function(r){ return r.json(); })
                        .then(function(j) {
                            mSave.disabled = false;
                            if (!j.success) { mRes.innerHTML = '<span class="pjo-st-error">❌ ' + esc(j.data && j.data.message ? j.data.message : 'Błąd') + '</span>'; return; }
                            mRes.innerHTML = '✅ ' + esc(j.data.message) + ' — odświeżam…';
                            setTimeout(function(){ location.reload(); }, 900);
                        })
                        .catch(function(e){ mSave.disabled = false; mRes.innerHTML = '<span class="pjo-st-error">❌ ' + esc(e.message) + '</span>'; });
                });
            }

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
     * @param array      $metas     mapa order_id => wiersz pjo_order_meta (z fetch_page_meta)
     * @return WC_Order[]
     */
    private function cluster_adjacent_orders( $orders, $clusters, $metas = array() ) {
        if ( count( $orders ) < 2 ) {
            return $orders;
        }
        $pack = array();
        foreach ( $metas as $oid => $m ) {
            if ( ! empty( $m['pack_group'] ) ) {
                $pack[ (int) $oid ] = $m['pack_group'];
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

    /**
     * Pobierz wiersze pjo_order_meta dla WSZYSTKICH zamówień strony jednym zapytaniem
     * (zamiast SELECT per wiersz przy renderze).
     *
     * @param WC_Order[] $orders
     * @return array  mapa order_id => wiersz meta (ARRAY_A)
     */
    private function fetch_page_meta( $orders ) {
        if ( empty( $orders ) ) {
            return array();
        }
        $ids = array();
        foreach ( $orders as $o ) {
            $ids[] = $o->get_id();
        }
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_order_meta';
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id IN ({$placeholders})", $ids
        ), ARRAY_A );
        $map = array();
        foreach ( (array) $rows as $r ) {
            $map[ (int) $r['order_id'] ] = $r;
        }
        return $map;
    }

    private function render_order_row( $order, $is_worker, $banks, $wc_statuses, $clusters = array(), $metas = array() ) {
        $oid = $order->get_id();
        $meta = $metas[ $oid ] ?? array();
        $pack_group = $meta['pack_group'] ?? '';
        $print_batch = $meta['print_batch'] ?? '';
        $dup = $clusters[ $oid ] ?? null;
        $is_manual = $order->get_meta( '_pjo_manual_order' ) === 'yes';

        $status = 'wc-' . $order->get_status();
        $bank = $meta['bank_account'] ?? '';
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
            <?php if ( ! $is_worker ) : ?>
                <td><input type="checkbox" class="pjo-pack-cb" value="<?php echo esc_attr( $oid ); ?>" title="<?php esc_attr_e( 'Zaznacz zamówienie (folder druku / grupa pakowania)', 'photojob-organizer' ); ?>"></td>
            <?php endif; ?>
            <td><button class="pjo-expand-btn" data-order="<?php echo esc_attr( $oid ); ?>" title="<?php esc_attr_e( 'Pokaż produkty zamówienia', 'photojob-organizer' ); ?>">+</button></td>
            <td><?php echo esc_html( $date ); ?></td>
            <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $oid ) ); ?>" target="_blank">#<?php echo esc_html( $oid ); ?></a></td>
            <td>
                <?php echo esc_html( $client ); ?>
                <?php if ( $is_manual ) : ?>
                    <span class="pjo-manual-badge" title="<?php esc_attr_e( 'Zamówienie dodane ręcznie (spoza sklepu WWW)', 'photojob-organizer' ); ?>">✍ <?php _e( 'spoza sklepu', 'photojob-organizer' ); ?></span>
                <?php endif; ?>
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
                    <button class="button button-small pjo-build-btn" data-order="<?php echo esc_attr( $oid ); ?>" title="<?php esc_attr_e( 'Podgląd planu druku (budowanie zbiorczo → jeden folder, u góry)', 'photojob-organizer' ); ?>">🗂</button>
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
            <td colspan="<?php echo $is_worker ? 6 : 11; ?>"><!-- worker: 6 kolumn (bez cb/email/kwoty/statusu/banku), pełny widok: 11 -->
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
