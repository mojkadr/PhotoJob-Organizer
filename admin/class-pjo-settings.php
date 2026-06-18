<?php
/**
 * Strona ustawień PhotoJob Organizer (v1.3.0)
 *
 * Zakładki:
 *  - Firma (nazwa, NIP, REGON, adres)
 *  - Banki (lista)
 *  - Sezony (READ-ONLY — auto-detect z product_cat)
 *  - Placówki (READ-ONLY — auto-detect z product_cat)
 *  - QNAP (host, port, user, hasło w polu + szyfrowanie + test z form values)
 *  - Etykiety (template + co drukować + grafika + obramowanie + test PDF)
 *  - Sklepy zewnętrzne (nphoto)
 *
 * Mapowanie produktów USUNIĘTE — rozmiar/typ jest w line item meta WC (WAPF "Wydruk odbitki").
 *
 * @package PhotoJob_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PhotoJob_Settings {

    const PAGE_SLUG  = 'photojob-settings';
    const NONCE_ACTION = 'pjo_save_settings';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_post_pjo_save_settings', array( $this, 'handle_save' ) );
        add_action( 'admin_post_pjo_refresh_cache', array( $this, 'handle_refresh_cache' ) );
        add_action( 'wp_ajax_pjo_test_qnap', array( $this, 'ajax_test_qnap' ) );
    }

    public function handle_save() {
        if ( ! current_user_can( 'pjo_manage_settings' ) ) {
            wp_die( __( 'Brak uprawnień.', 'photojob-organizer' ) );
        }
        check_admin_referer( self::NONCE_ACTION );

        $tab = isset( $_POST['pjo_tab'] ) ? sanitize_key( $_POST['pjo_tab'] ) : 'company';

        switch ( $tab ) {
            case 'company': $this->save_company(); break;
            case 'banks':   $this->save_banks(); break;
            case 'qnap':    $this->save_qnap(); break;
            case 'labels':  $this->save_labels(); break;
            case 'labs':    $this->save_labs(); break;
        }

        wp_safe_redirect( add_query_arg( array(
            'page' => self::PAGE_SLUG, 'tab' => $tab, 'updated' => '1',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_refresh_cache() {
        if ( ! current_user_can( 'pjo_manage_settings' ) ) {
            wp_die( __( 'Brak uprawnień.', 'photojob-organizer' ) );
        }
        check_admin_referer( 'pjo_refresh_cache' );
        PhotoJob_WC_Inspector::clear_cache();
        $tab = isset( $_POST['pjo_tab'] ) ? sanitize_key( $_POST['pjo_tab'] ) : 'seasons';
        wp_safe_redirect( add_query_arg( array(
            'page' => self::PAGE_SLUG, 'tab' => $tab, 'refreshed' => '1',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private function save_company() {
        update_option( 'pjo_settings_company', array(
            'name'    => sanitize_text_field( $_POST['company_name'] ?? '' ),
            'nip'     => sanitize_text_field( $_POST['company_nip'] ?? '' ),
            'regon'   => sanitize_text_field( $_POST['company_regon'] ?? '' ),
            'address' => sanitize_textarea_field( $_POST['company_address'] ?? '' ),
        ) );
    }

    private function save_banks() {
        $raw = sanitize_textarea_field( $_POST['banks_list'] ?? '' );
        $banks = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
        update_option( 'pjo_settings_banks', array_values( $banks ) );
    }

    private function save_qnap() {
        $current = get_option( 'pjo_settings_qnap', array() );
        $data = array(
            'host'           => sanitize_text_field( $_POST['qnap_host'] ?? '' ),
            'port'           => absint( $_POST['qnap_port'] ?? 443 ),
            'use_ssl'        => isset( $_POST['qnap_use_ssl'] ) ? 1 : 0,
            'verify_ssl'     => isset( $_POST['qnap_verify_ssl'] ) ? 1 : 0,
            'user'           => sanitize_text_field( $_POST['qnap_user'] ?? '' ),
            'share_path'       => sanitize_text_field( $_POST['qnap_share_path'] ?? '' ),
            'source_path'      => sanitize_text_field( $_POST['qnap_source_path'] ?? '' ),
            'print_build_path' => sanitize_text_field( $_POST['qnap_print_build_path'] ?? '' ),
            'password_const'   => sanitize_text_field( $_POST['qnap_password_const'] ?? '' ),
        );
        // Hasło — jeśli user wpisał nowe, szyfruj i zapisz. Jeśli puste — zachowaj stare.
        $new_password = $_POST['qnap_password'] ?? '';
        if ( $new_password !== '' ) {
            $data['password_encrypted'] = self::encrypt_password( $new_password );
        } elseif ( isset( $current['password_encrypted'] ) ) {
            $data['password_encrypted'] = $current['password_encrypted'];
        }
        update_option( 'pjo_settings_qnap', $data );
    }

    private function save_labels() {
        $allowed_fields = array( 'name', 'address', 'order_no', 'phone', 'email', 'date', 'barcode', 'custom_text' );
        $fields = array();
        if ( ! empty( $_POST['label_fields'] ) && is_array( $_POST['label_fields'] ) ) {
            foreach ( $_POST['label_fields'] as $f ) {
                $f = sanitize_key( $f );
                if ( in_array( $f, $allowed_fields, true ) ) {
                    $fields[] = $f;
                }
            }
        }
        update_option( 'pjo_settings_labels', array(
            'template'         => sanitize_text_field( $_POST['label_template'] ?? 'avery-l7163' ),
            'capacity'         => absint( $_POST['label_capacity'] ?? 14 ),
            'sender_address'   => sanitize_textarea_field( $_POST['label_sender'] ?? '' ),
            'show_logo'        => isset( $_POST['label_show_logo'] ) ? 1 : 0,
            'fields'           => $fields,
            'graphic_url'      => esc_url_raw( $_POST['label_graphic_url'] ?? '' ),
            'graphic_position' => sanitize_key( $_POST['label_graphic_position'] ?? 'top-left' ),
            'graphic_size_pct' => max( 5, min( 50, absint( $_POST['label_graphic_size_pct'] ?? 20 ) ) ),
            'border_enabled'   => isset( $_POST['label_border_enabled'] ) ? 1 : 0,
            'border_width'     => max( 1, min( 10, absint( $_POST['label_border_width'] ?? 1 ) ) ),
            'border_style'     => sanitize_key( $_POST['label_border_style'] ?? 'solid' ),
            'border_color'     => sanitize_hex_color( $_POST['label_border_color'] ?? '#000000' ),
            'custom_text'      => sanitize_textarea_field( $_POST['label_custom_text'] ?? '' ),
        ) );
    }

    private function save_labs() {
        $labs = get_option( 'pjo_settings_labs', array() );
        $labs['nphoto'] = array(
            'enabled'    => isset( $_POST['lab_nphoto_enabled'] ) ? 1 : 0,
            'mode'       => sanitize_text_field( $_POST['lab_nphoto_mode'] ?? 'export_zip' ),
            'zip_format' => sanitize_text_field( $_POST['lab_nphoto_zip_format'] ?? 'flat_per_size' ),
        );
        unset( $labs['lookat'] );
        update_option( 'pjo_settings_labs', $labs );
    }

    /** ============ AJAX QNAP TEST — używa AKTUALNYCH wartości z formularza ============ */

    public function ajax_test_qnap() {
        if ( ! current_user_can( 'pjo_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'photojob-organizer' ) ) );
        }
        check_ajax_referer( 'pjo_qnap_test', 'nonce' );

        $host = sanitize_text_field( $_POST['host'] ?? '' );
        $port = absint( $_POST['port'] ?? 443 );
        $use_ssl = isset( $_POST['use_ssl'] ) ? ( (int) $_POST['use_ssl'] === 1 ) : true;
        $user = sanitize_text_field( $_POST['user'] ?? '' );
        $password = $_POST['password'] ?? '';
        $password_const = sanitize_text_field( $_POST['password_const'] ?? '' );
        $verify_ssl = isset( $_POST['verify_ssl'] ) ? ( (int) $_POST['verify_ssl'] === 1 ) : false;

        if ( empty( $host ) || empty( $user ) ) {
            wp_send_json_error( array( 'message' => __( 'Wpisz host i użytkownika powyżej, potem testuj.', 'photojob-organizer' ) ) );
        }

        // Source hasła
        $effective_password = '';
        $source = '';
        if ( $password !== '' ) {
            $effective_password = $password;
            $source = 'pole formularza';
        } else {
            $stored = get_option( 'pjo_settings_qnap', array() );
            if ( ! empty( $stored['password_encrypted'] ) ) {
                $effective_password = self::decrypt_password( $stored['password_encrypted'] );
                $source = 'zapisane';
            } elseif ( $password_const && defined( $password_const ) ) {
                $effective_password = constant( $password_const );
                $source = 'stała PHP';
            }
        }
        if ( $effective_password === '' ) {
            wp_send_json_error( array( 'message' => __( 'Brak hasła — wpisz w pole "Hasło" powyżej.', 'photojob-organizer' ) ) );
        }

        $proto = $use_ssl ? 'https' : 'http';
        $url = sprintf( '%s://%s:%d/cgi-bin/authLogin.cgi', $proto, $host, $port );

        // FIX v1.3.3: bezpośredni cURL zamiast wp_remote_post — bo na sesje.mojkadr.eu jakaś
        // wtyczka (W3TC? Cart Editor?) hookuje HTTP middleware i zwraca puste body w AJAX context
        // (test z PHP CLI: wp_remote_post zwraca 1100 bajtów. Z UI AJAX: 0 bajtów).
        if ( ! function_exists( 'curl_init' ) ) {
            wp_send_json_error( array( 'message' => __( 'PHP cURL nie jest dostępne na tym serwerze.', 'photojob-organizer' ) ) );
        }
        $ch = curl_init( $url );
        curl_setopt_array( $ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query( array( 'user' => $user, 'pwd' => base64_encode( $effective_password ) ) ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_SSL_VERIFYPEER => $verify_ssl,
            CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'PhotoJob-Organizer/' . PHOTOJOB_VERSION . ' (QNAP-test)',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => array( 'Accept: text/xml, application/xml' ),
        ) );
        $raw = curl_exec( $ch );
        $curl_errno = curl_errno( $ch );
        $curl_err = curl_error( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $content_type = curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
        $header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
        curl_close( $ch );

        if ( $curl_errno ) {
            $hint = '';
            if ( $curl_errno === 28 ) {
                $hint = ' ' . __( '(timeout — host nieosiągalny w 20s)', 'photojob-organizer' );
            } elseif ( $curl_errno === 60 ) {
                $hint = ' ' . __( '(certyfikat SSL — odznacz "Weryfikuj")', 'photojob-organizer' );
            }
            wp_send_json_error( array(
                'message' => sprintf( __( 'Błąd cURL #%d: %s', 'photojob-organizer' ), $curl_errno, $curl_err ) . $hint,
                'source'  => $source,
                'url'     => $url,
            ) );
        }
        $body = $header_size > 0 ? substr( $raw, $header_size ) : $raw;

        if ( $code !== 200 ) {
            $hint = '';
            if ( $code === 404 ) {
                $hint = ' ' . __( '(endpoint nie odpowiada — sprawdź host/port; HTTPS=443, HTTP=8080)', 'photojob-organizer' );
            }
            wp_send_json_error( array(
                'message' => sprintf( __( 'HTTP %d od QNAP.', 'photojob-organizer' ), $code ) . $hint,
                'url'     => $url,
            ) );
        }
        // Photo Access wzorzec: <authSid>...</authSid> + <authPassed>1</authPassed>
        $auth_sid = '';
        if ( preg_match( '#<authSid>(<!\[CDATA\[)?([^<\]]+)(\]\]>)?</authSid>#', $body, $m ) ) {
            $auth_sid = $m[2];
        } elseif ( preg_match( '#<authSid>([^<]+)</authSid>#', $body, $m ) ) {
            $auth_sid = $m[1];
        }
        $auth_passed = '';
        if ( preg_match( '#<authPassed>(?:<!\[CDATA\[)?([^<\]]*)#', $body, $m ) ) {
            $auth_passed = $m[1];
        }
        $body_excerpt = substr( wp_strip_all_tags( $body ), 0, 400 );

        if ( empty( $auth_sid ) || $auth_passed !== '1' ) {
            // Diagnostyka: HTML loginu, niewłaściwy endpoint, JSON itp.
            $hint = '';
            if ( stripos( $content_type, 'html' ) !== false ) {
                $hint = __( ' → QNAP zwrócił HTML zamiast XML — to zwykle strona logowania panelu. Możliwe że host odpowiada na port web admina, a nie API. Sprawdź port (HTTPS=443 zwykle, ale na cloud DDNS może być inny). Wklej "DEBUG" niżej do mnie żeby zdiagnozować.', 'photojob-organizer' );
            } elseif ( stripos( $body, 'authPassed' ) === false ) {
                $hint = __( ' → Brak XML auth w response — endpoint authLogin.cgi nie odpowiedział standardowo. Wklej DEBUG niżej.', 'photojob-organizer' );
            } elseif ( $auth_passed === '0' ) {
                $hint = __( ' → Hasło LUB użytkownik nieprawidłowy (authPassed=0). Sprawdź dokładnie credentialsy.', 'photojob-organizer' );
            }
            wp_send_json_error( array(
                'message' => sprintf(
                    __( 'Login nie udał się (authPassed=%s).', 'photojob-organizer' ),
                    $auth_passed !== '' ? esc_html( $auth_passed ) : 'brak'
                ) . $hint,
                'source'       => $source,
                'url'          => $url,
                'http_code'    => $code,
                'content_type' => $content_type,
                'body_excerpt' => $body_excerpt,
                'body_full'    => substr( $body, 0, 1500 ),
            ) );
        }
        wp_send_json_success( array(
            'message' => sprintf( __( 'Połączenie OK. Login udany. Hasło z: %s. SID: %s…', 'photojob-organizer' ), $source, substr( $auth_sid, 0, 8 ) ),
            'host'    => $host,
            'user'    => $user,
        ) );
    }

    /** ============ PASSWORD ENCRYPTION ============ */

    private static function encrypt_password( $password ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return base64_encode( $password ); // weak fallback
        }
        $key = self::get_encryption_key();
        $iv = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $password, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $cipher );
    }

    private static function decrypt_password( $stored ) {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return base64_decode( $stored );
        }
        $raw = base64_decode( $stored );
        if ( strlen( $raw ) < 17 ) {
            return '';
        }
        $iv = substr( $raw, 0, 16 );
        $cipher = substr( $raw, 16 );
        $key = self::get_encryption_key();
        $out = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return $out === false ? '' : $out;
    }

    private static function get_encryption_key() {
        // Pochodne klucza z AUTH_KEY (zmienia się tylko gdy user regeneruje secrets WP)
        $base = defined( 'AUTH_KEY' ) ? AUTH_KEY : ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'pjo_fallback_key_change_me' );
        return hash( 'sha256', $base . 'pjo_qnap_v1', true );
    }

    public static function get_qnap_password() {
        $q = get_option( 'pjo_settings_qnap', array() );
        if ( ! empty( $q['password_encrypted'] ) ) {
            return self::decrypt_password( $q['password_encrypted'] );
        }
        if ( ! empty( $q['password_const'] ) && defined( $q['password_const'] ) ) {
            return constant( $q['password_const'] );
        }
        return '';
    }

    /** ============ RENDER ============ */

    public function render_page() {
        if ( ! current_user_can( 'pjo_manage_settings' ) ) {
            wp_die( __( 'Brak uprawnień.', 'photojob-organizer' ) );
        }
        $tabs = array(
            'company'    => __( 'Firma', 'photojob-organizer' ),
            'banks'      => __( 'Banki', 'photojob-organizer' ),
            'seasons'    => __( 'Sezony', 'photojob-organizer' ),
            'facilities' => __( 'Placówki', 'photojob-organizer' ),
            'qnap'       => __( 'QNAP', 'photojob-organizer' ),
            'labels'     => __( 'Etykiety', 'photojob-organizer' ),
            'labs'       => __( 'Sklepy zewnętrzne', 'photojob-organizer' ),
        );
        $current = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'company';
        if ( ! isset( $tabs[ $current ] ) ) {
            $current = 'company';
        }
        $readonly_tabs = array( 'seasons', 'facilities' );
        ?>
        <div class="wrap pjo-settings">
            <h1><?php _e( 'PhotoJob — Ustawienia', 'photojob-organizer' ); ?></h1>

            <?php if ( ! empty( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php _e( 'Zapisano.', 'photojob-organizer' ); ?></p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['refreshed'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php _e( 'Cache odświeżony z WC.', 'photojob-organizer' ); ?></p></div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
                       class="nav-tab <?php echo ( $slug === $current ) ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php
            if ( in_array( $current, $readonly_tabs, true ) ) {
                // Read-only tab — bez form save, tylko refresh
                $method = 'render_tab_' . $current;
                if ( method_exists( $this, $method ) ) {
                    $this->$method();
                }
            } else {
                ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="pjo_save_settings">
                    <input type="hidden" name="pjo_tab" value="<?php echo esc_attr( $current ); ?>">
                    <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                    <?php
                    $method = 'render_tab_' . $current;
                    if ( method_exists( $this, $method ) ) {
                        $this->$method();
                    }
                    ?>
                    <?php submit_button(); ?>
                </form>
                <?php
            }
            ?>
        </div>
        <?php
    }

    private function render_refresh_button( $tab ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-bottom:15px;">
            <input type="hidden" name="action" value="pjo_refresh_cache">
            <input type="hidden" name="pjo_tab" value="<?php echo esc_attr( $tab ); ?>">
            <?php wp_nonce_field( 'pjo_refresh_cache' ); ?>
            <button type="submit" class="button">🔄 <?php _e( 'Odśwież z WooCommerce', 'photojob-organizer' ); ?></button>
        </form>
        <?php
    }

    private function render_tab_company() {
        $c = wp_parse_args( get_option( 'pjo_settings_company', array() ), array(
            'name' => '', 'nip' => '', 'regon' => '', 'address' => '',
        ) );
        ?>
        <p class="description"><?php _e( 'Dane pojawiają się w nagłówku raportu księgowego i jako nadawca na etykietach.', 'photojob-organizer' ); ?></p>
        <table class="form-table">
            <tr><th><label for="company_name"><?php _e( 'Nazwa firmy', 'photojob-organizer' ); ?></label></th>
                <td><input type="text" class="regular-text" id="company_name" name="company_name" value="<?php echo esc_attr( $c['name'] ); ?>"></td></tr>
            <tr><th><label for="company_nip">NIP</label></th>
                <td><input type="text" class="regular-text" id="company_nip" name="company_nip" value="<?php echo esc_attr( $c['nip'] ); ?>"></td></tr>
            <tr><th><label for="company_regon">REGON</label></th>
                <td><input type="text" class="regular-text" id="company_regon" name="company_regon" value="<?php echo esc_attr( $c['regon'] ); ?>"></td></tr>
            <tr><th><label for="company_address"><?php _e( 'Adres', 'photojob-organizer' ); ?></label></th>
                <td><textarea class="regular-text" rows="3" id="company_address" name="company_address"><?php echo esc_textarea( $c['address'] ); ?></textarea></td></tr>
        </table>
        <?php
    }

    private function render_tab_banks() {
        $banks = get_option( 'pjo_settings_banks', array() );
        ?>
        <p class="description"><?php _e( 'Banki używane do przyjmowania wpłat. Jedna pozycja na wiersz.', 'photojob-organizer' ); ?></p>
        <table class="form-table">
            <tr><th><label for="banks_list"><?php _e( 'Banki', 'photojob-organizer' ); ?></label></th>
                <td><textarea class="large-text code" rows="6" id="banks_list" name="banks_list"><?php echo esc_textarea( implode( "\n", $banks ) ); ?></textarea></td></tr>
        </table>
        <?php
    }

    private function render_tab_seasons() {
        $seasons = PhotoJob_WC_Inspector::get_seasons();
        ?>
        <div class="notice notice-info inline" style="padding:10px;margin:0 0 15px;">
            <p><strong><?php _e( '🤖 Tryb automatyczny', 'photojob-organizer' ); ?></strong></p>
            <p><?php _e( 'Sezony są <strong>auto-wykrywane z kategorii produktów WC</strong>. Każda kategoria 2. poziomu (pod rokiem) zawierająca słowo "Wiosenna" / "Zimowa" / "Letnia" / "Jesienna" → sezon. Nic nie wpisujesz, NIC nie konfigurujesz.', 'photojob-organizer' ); ?></p>
            <p><?php _e( 'Cache 1h. Jeśli dodałeś świeży sezon w WC i nie widać — kliknij Odśwież.', 'photojob-organizer' ); ?></p>
        </div>

        <?php $this->render_refresh_button( 'seasons' ); ?>

        <?php if ( empty( $seasons ) ) : ?>
            <p><em><?php _e( 'Nie wykryto sezonów. Sprawdź czy w WC kategorii produktów masz strukturę: Klient → Rok → Sezon (np. "Zielony i Niebieski Motylek → 2026 → Wiosenna").', 'photojob-organizer' ); ?></em></p>
        <?php else : ?>
            <table class="wp-list-table widefat striped">
                <thead><tr>
                    <th><?php _e( 'Sezon', 'photojob-organizer' ); ?></th>
                    <th><?php _e( 'Rok', 'photojob-organizer' ); ?></th>
                    <th><?php _e( 'Slug', 'photojob-organizer' ); ?></th>
                    <th><?php _e( 'Produktów', 'photojob-organizer' ); ?></th>
                    <th><?php _e( 'WP term_id', 'photojob-organizer' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $seasons as $s ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $s['name'] ); ?></strong></td>
                        <td><?php echo esc_html( $s['year'] ?: '—' ); ?></td>
                        <td><code><?php echo esc_html( $s['slug'] ); ?></code></td>
                        <td><?php echo number_format_i18n( $s['product_count'] ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'term.php?taxonomy=product_cat&tag_ID=' . $s['term_id'] ) ); ?>" target="_blank">#<?php echo esc_html( $s['term_id'] ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    private function render_tab_facilities() {
        $facilities = PhotoJob_WC_Inspector::get_facilities();
        ?>
        <div class="notice notice-info inline" style="padding:10px;margin:0 0 15px;">
            <p><strong><?php _e( '🤖 Tryb automatyczny', 'photojob-organizer' ); ?></strong></p>
            <p><?php _e( 'Placówki są <strong>auto-wykrywane z hierarchii kategorii produktów WC</strong>. Struktura:', 'photojob-organizer' ); ?></p>
            <ul style="margin-left:20px;">
                <li><strong><?php _e( 'Klient', 'photojob-organizer' ); ?></strong> — root (np. "Zielony i Niebieski Motylek")</li>
                <li><strong><?php _e( 'Rok', 'photojob-organizer' ); ?></strong> — "2025", "2026"</li>
                <li><strong><?php _e( 'Sezon', 'photojob-organizer' ); ?></strong> — "Wiosenna", "Zimowa"</li>
                <li><strong><?php _e( 'Oddział', 'photojob-organizer' ); ?></strong> — "Bałtycka", "Mazowiecka", "Jasionka", "Zelwerowicza"</li>
                <li><strong><?php _e( 'Typ grupy', 'photojob-organizer' ); ?></strong> — "Przedszkole", "Żłobek", "Rodzinne"</li>
                <li><strong><?php _e( 'Grupa', 'photojob-organizer' ); ?></strong> — "3 Latki", "5 Latki" (opcjonalnie)</li>
            </ul>
        </div>

        <?php $this->render_refresh_button( 'facilities' ); ?>

        <?php if ( empty( $facilities ) ) : ?>
            <p><em><?php _e( 'Nie wykryto placówek.', 'photojob-organizer' ); ?></em></p>
        <?php else : ?>
            <p>
                <input type="search" id="pjo-facility-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filtruj…', 'photojob-organizer' ); ?>">
                <span class="description"><?php printf( __( '%d placówek wykrytych', 'photojob-organizer' ), count( $facilities ) ); ?></span>
            </p>
            <table class="wp-list-table widefat striped" id="pjo-facility-table">
                <thead><tr>
                    <th><?php _e( 'Klient', 'photojob-organizer' ); ?></th>
                    <th><?php _e( 'Rok', 'photojob-organizer' ); ?></th>
                    <th><?php _e( 'Sezon', 'photojob-organizer' ); ?></th>
                    <th><?php _e( 'Oddział', 'photojob-organizer' ); ?></th>
                    <th><?php _e( 'Typ grupy', 'photojob-organizer' ); ?></th>
                    <th><?php _e( 'Grupa', 'photojob-organizer' ); ?></th>
                    <th><?php _e( 'Produktów', 'photojob-organizer' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $facilities as $f ) : ?>
                    <tr>
                        <td><?php echo esc_html( $f['client'] ); ?></td>
                        <td><?php echo esc_html( $f['year'] ); ?></td>
                        <td><?php echo esc_html( $f['season'] ); ?></td>
                        <td><strong><?php echo esc_html( $f['branch'] ); ?></strong></td>
                        <td><?php echo esc_html( $f['group_type'] ); ?></td>
                        <td><?php echo esc_html( $f['group_name'] ); ?></td>
                        <td><?php echo number_format_i18n( $f['product_count'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <script>
            document.getElementById('pjo-facility-filter').addEventListener('input', function(e) {
                var q = e.target.value.toLowerCase();
                document.querySelectorAll('#pjo-facility-table tbody tr').forEach(function(tr) {
                    tr.style.display = tr.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
                });
            });
            </script>
        <?php endif; ?>
        <?php
    }

    private function render_tab_qnap() {
        $q = wp_parse_args( get_option( 'pjo_settings_qnap', array() ), array(
            'host' => '', 'port' => 443, 'use_ssl' => 1, 'verify_ssl' => 0, 'user' => '', 'share_path' => '', 'source_path' => '', 'print_build_path' => '/MójKadr/Druk', 'password_const' => '', 'password_encrypted' => '',
        ) );
        $has_stored_password = ! empty( $q['password_encrypted'] );
        $const_defined = $q['password_const'] && defined( $q['password_const'] );
        ?>
        <p class="description"><?php _e( 'Dane dostępu do QNAP NAS (File Station API). Magazyn źródłowych zdjęć w <code>source_path</code>, foldery klientów w <code>share_path</code>.', 'photojob-organizer' ); ?></p>
        <table class="form-table">
            <tr><th><label for="qnap_host">Host (DDNS)</label></th>
                <td><input type="text" class="regular-text" id="qnap_host" name="qnap_host" value="<?php echo esc_attr( $q['host'] ); ?>" placeholder="np. Margol123.myqnapcloud.com"></td></tr>
            <tr><th><?php _e( 'Protokół', 'photojob-organizer' ); ?></th>
                <td>
                    <label style="margin-right:15px;"><input type="checkbox" id="qnap_use_ssl" name="qnap_use_ssl" value="1" <?php checked( $q['use_ssl'], 1 ); ?>> HTTPS</label>
                    <label><input type="checkbox" id="qnap_verify_ssl" name="qnap_verify_ssl" value="1" <?php checked( $q['verify_ssl'], 1 ); ?>> <?php _e( 'Weryfikuj certyfikat SSL', 'photojob-organizer' ); ?></label>
                    <p class="description"><?php _e( 'QNAP zwykle ma self-signed certyfikat — odznacz "Weryfikuj" jeśli host to <code>*.myqnapcloud.com</code>.', 'photojob-organizer' ); ?></p>
                </td></tr>
            <tr><th><label for="qnap_port">Port</label></th>
                <td><input type="number" id="qnap_port" name="qnap_port" value="<?php echo esc_attr( $q['port'] ); ?>">
                    <p class="description"><?php _e( 'HTTPS: 443. HTTP: 8080. Sprawdź w panelu QNAP (Panel sterowania → System → Ogólne).', 'photojob-organizer' ); ?></p>
                </td></tr>
            <tr><th><label for="qnap_user"><?php _e( 'Użytkownik', 'photojob-organizer' ); ?></label></th>
                <td><input type="text" class="regular-text" id="qnap_user" name="qnap_user" value="<?php echo esc_attr( $q['user'] ); ?>"></td></tr>
            <tr><th><label for="qnap_password"><?php _e( 'Hasło', 'photojob-organizer' ); ?></label></th>
                <td>
                    <input type="password" class="regular-text" id="qnap_password" name="qnap_password" value="" placeholder="<?php echo $has_stored_password ? esc_attr__( '••••• (zachowane — wpisz nowe żeby zmienić)', 'photojob-organizer' ) : esc_attr__( 'Wpisz hasło QNAP', 'photojob-organizer' ); ?>" autocomplete="new-password">
                    <p class="description">
                        <?php if ( $has_stored_password ) : ?>
                            <span style="color:green;">✓ <?php _e( 'Hasło zapisane (zaszyfrowane w bazie). Wpisz nowe żeby nadpisać, zostaw puste żeby zachować.', 'photojob-organizer' ); ?></span>
                        <?php else : ?>
                            <?php _e( 'Hasło zapisywane jako AES-256-CBC z kluczem pochodnym od AUTH_KEY z wp-config.php. Z bazy nie da się go odczytać bez WP secrets.', 'photojob-organizer' ); ?>
                        <?php endif; ?>
                    </p>
                </td></tr>
            <tr><th><label for="qnap_share_path"><?php _e( 'Ścieżka folderów klientów', 'photojob-organizer' ); ?></label></th>
                <td><input type="text" class="regular-text" id="qnap_share_path" name="qnap_share_path" value="<?php echo esc_attr( $q['share_path'] ); ?>"></td></tr>
            <tr><th><label for="qnap_source_path"><?php _e( 'Ścieżka magazynu zdjęć', 'photojob-organizer' ); ?></label></th>
                <td><input type="text" class="regular-text" id="qnap_source_path" name="qnap_source_path" value="<?php echo esc_attr( $q['source_path'] ); ?>">
                    <p class="description"><?php _e( 'Źródło — tu leżą oryginały sesji (Folder Builder szuka tu plików po nazwie produktu).', 'photojob-organizer' ); ?></p>
                </td></tr>
            <tr><th><label for="qnap_print_build_path"><?php _e( 'Ścieżka budowania druku', 'photojob-organizer' ); ?></label></th>
                <td><input type="text" class="regular-text" id="qnap_print_build_path" name="qnap_print_build_path" value="<?php echo esc_attr( $q['print_build_path'] ); ?>" placeholder="/MójKadr/Druk">
                    <p class="description"><?php _e( 'Cel — Folder Builder tworzy tu strukturę <code>{Sezon}/{NrZam}/{Typ}/{Rozmiar}/…</code> i kopiuje przemianowane pliki do druku.', 'photojob-organizer' ); ?></p>
                </td></tr>
            <tr><th><label for="qnap_password_const"><?php _e( 'Stała PHP z hasłem (opcjonalnie)', 'photojob-organizer' ); ?></label></th>
                <td>
                    <input type="text" class="regular-text" id="qnap_password_const" name="qnap_password_const" value="<?php echo esc_attr( $q['password_const'] ); ?>" placeholder="np. PJO_QNAP_PASSWORD">
                    <p class="description">
                        <?php _e( 'Alternatywa dla zapisanego hasła: stała w <code>wp-config.php</code>. Jeśli zdefiniowana, używana zamiast hasła z bazy.', 'photojob-organizer' ); ?>
                        <?php if ( $q['password_const'] && $const_defined ) : ?>
                            <br><span style="color:green;">✓ <?php _e( 'Stała zdefiniowana', 'photojob-organizer' ); ?></span>
                        <?php elseif ( $q['password_const'] ) : ?>
                            <br><span style="color:orange;">⚠ <?php _e( 'Stała NIE jest zdefiniowana w wp-config.php', 'photojob-organizer' ); ?></span>
                        <?php endif; ?>
                    </p>
                </td></tr>
            <tr><th><?php _e( 'Test połączenia', 'photojob-organizer' ); ?></th>
                <td>
                    <button type="button" id="pjo-qnap-test" class="button">🔌 <?php _e( 'Testuj połączenie', 'photojob-organizer' ); ?></button>
                    <span id="pjo-qnap-test-result" style="margin-left:10px;"></span>
                    <p class="description"><?php _e( 'Test używa AKTUALNYCH wartości z formularza (nie musisz zapisywać). Sprawdza login do File Station API.', 'photojob-organizer' ); ?></p>
                </td></tr>
        </table>

        <script>
        document.getElementById('pjo-qnap-test').addEventListener('click', function() {
            var out = document.getElementById('pjo-qnap-test-result');
            out.innerHTML = '⏳ <?php echo esc_js( __( 'Łączę…', 'photojob-organizer' ) ); ?>';
            var fd = new FormData();
            fd.append('action', 'pjo_test_qnap');
            fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'pjo_qnap_test' ) ); ?>');
            fd.append('host', document.getElementById('qnap_host').value);
            fd.append('port', document.getElementById('qnap_port').value);
            fd.append('use_ssl', document.getElementById('qnap_use_ssl').checked ? '1' : '0');
            fd.append('verify_ssl', document.getElementById('qnap_verify_ssl').checked ? '1' : '0');
            fd.append('user', document.getElementById('qnap_user').value);
            fd.append('password', document.getElementById('qnap_password').value);
            fd.append('password_const', document.getElementById('qnap_password_const').value);
            fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(j) {
                    if (j.success) {
                        out.innerHTML = '<span style="color:green;">✅ ' + j.data.message + '</span>';
                    } else {
                        var d = j.data || {};
                        var msg = '<span style="color:red;">❌ ' + (d.message || 'Błąd') + '</span>';
                        var debug = '<details style="margin-top:8px;"><summary style="cursor:pointer;color:#555;font-size:12px;">🔍 DEBUG (kliknij)</summary><pre style="background:#f0f0f0;padding:10px;font-size:11px;overflow:auto;max-height:300px;border:1px solid #ccc;">';
                        debug += 'URL:          ' + (d.url || '—') + '\n';
                        debug += 'HTTP code:    ' + (d.http_code || '—') + '\n';
                        debug += 'Content-Type: ' + (d.content_type || '—') + '\n';
                        debug += 'Hasło z:      ' + (d.source || '—') + '\n\n';
                        debug += '--- Body (excerpt) ---\n' + (d.body_excerpt || '—') + '\n\n';
                        if (d.body_full) debug += '--- Body (raw) ---\n' + d.body_full;
                        debug += '</pre></details>';
                        out.innerHTML = msg + debug;
                    }
                })
                .catch(function(e) { out.innerHTML = '<span style="color:red;">❌ ' + e.message + '</span>'; });
        });
        </script>
        <?php
    }

    private function render_tab_labels() {
        $l = wp_parse_args( get_option( 'pjo_settings_labels', array() ), array(
            'template' => 'avery-l7163', 'capacity' => 14, 'sender_address' => '', 'show_logo' => 1,
            'fields' => array( 'name', 'address', 'order_no' ),
            'graphic_url' => '', 'graphic_position' => 'top-left', 'graphic_size_pct' => 20,
            'border_enabled' => 0, 'border_width' => 1, 'border_style' => 'solid', 'border_color' => '#000000',
            'custom_text' => '',
        ) );
        $templates = array(
            'avery-l7163'  => 'Avery L7163 (14 etykiet / A4, 99×38mm)',
            'avery-l7160'  => 'Avery L7160 (21 etykiet / A4, 63,5×38mm)',
            'avery-l7165'  => 'Avery L7165 (8 etykiet / A4, 99×67mm)',
            'avery-l7167'  => 'Avery L7167 (1 etykieta / A4, 199,6×289,1mm)',
            'custom'       => __( 'Własny (zdefiniuj capacity)', 'photojob-organizer' ),
        );
        $available_fields = array(
            'name'        => __( 'Imię i nazwisko odbiorcy', 'photojob-organizer' ),
            'address'     => __( 'Adres odbiorcy', 'photojob-organizer' ),
            'order_no'    => __( 'Nr zamówienia', 'photojob-organizer' ),
            'phone'       => __( 'Telefon odbiorcy', 'photojob-organizer' ),
            'email'       => __( 'Email odbiorcy', 'photojob-organizer' ),
            'date'        => __( 'Data wysyłki', 'photojob-organizer' ),
            'barcode'     => __( 'Kod kreskowy (nr zam.)', 'photojob-organizer' ),
            'custom_text' => __( 'Własny tekst (poniżej)', 'photojob-organizer' ),
        );
        $positions = array(
            'top-left'     => __( 'lewy górny', 'photojob-organizer' ),
            'top-right'    => __( 'prawy górny', 'photojob-organizer' ),
            'bottom-left'  => __( 'lewy dolny', 'photojob-organizer' ),
            'bottom-right' => __( 'prawy dolny', 'photojob-organizer' ),
            'center'       => __( 'środek', 'photojob-organizer' ),
            'background'   => __( 'tło (znak wodny)', 'photojob-organizer' ),
        );
        $border_styles = array( 'solid', 'dashed', 'dotted', 'double' );
        ?>
        <p class="description"><?php _e( 'Konfiguracja generatora PDF z etykietami na koperty.', 'photojob-organizer' ); ?></p>

        <h3><?php _e( 'Arkusz', 'photojob-organizer' ); ?></h3>
        <table class="form-table">
            <tr><th><label for="label_template"><?php _e( 'Szablon arkusza', 'photojob-organizer' ); ?></label></th>
                <td><select id="label_template" name="label_template">
                    <?php foreach ( $templates as $k => $v ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $l['template'], $k ); ?>><?php echo esc_html( $v ); ?></option>
                    <?php endforeach; ?>
                </select></td></tr>
            <tr><th><label for="label_capacity"><?php _e( 'Etykiet / arkusz A4', 'photojob-organizer' ); ?></label></th>
                <td><input type="number" id="label_capacity" name="label_capacity" value="<?php echo esc_attr( $l['capacity'] ); ?>" min="1" max="100"></td></tr>
            <tr><th><label for="label_sender"><?php _e( 'Adres nadawcy', 'photojob-organizer' ); ?></label></th>
                <td><textarea class="regular-text" rows="3" id="label_sender" name="label_sender"><?php echo esc_textarea( $l['sender_address'] ); ?></textarea></td></tr>
        </table>

        <h3><?php _e( 'Co drukować', 'photojob-organizer' ); ?></h3>
        <table class="form-table">
            <tr><th><?php _e( 'Pola odbiorcy', 'photojob-organizer' ); ?></th>
                <td>
                    <?php foreach ( $available_fields as $key => $label_text ) : ?>
                        <label style="display:block;margin-bottom:4px;">
                            <input type="checkbox" name="label_fields[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $l['fields'], true ) ); ?>>
                            <?php echo esc_html( $label_text ); ?>
                        </label>
                    <?php endforeach; ?>
                </td></tr>
            <tr><th><label for="label_custom_text"><?php _e( 'Własny tekst', 'photojob-organizer' ); ?></label></th>
                <td><textarea class="regular-text" rows="2" id="label_custom_text" name="label_custom_text"><?php echo esc_textarea( $l['custom_text'] ); ?></textarea></td></tr>
        </table>

        <h3><?php _e( 'Grafika', 'photojob-organizer' ); ?></h3>
        <table class="form-table">
            <tr><th><label for="label_graphic_url"><?php _e( 'URL grafiki', 'photojob-organizer' ); ?></label></th>
                <td>
                    <input type="url" class="regular-text" id="label_graphic_url" name="label_graphic_url" value="<?php echo esc_attr( $l['graphic_url'] ); ?>">
                    <button type="button" class="button" id="pjo-media-graphic">📁 <?php _e( 'Wybierz z biblioteki', 'photojob-organizer' ); ?></button>
                </td></tr>
            <tr><th><label for="label_graphic_position"><?php _e( 'Pozycja', 'photojob-organizer' ); ?></label></th>
                <td><select id="label_graphic_position" name="label_graphic_position">
                    <?php foreach ( $positions as $k => $v ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $l['graphic_position'], $k ); ?>><?php echo esc_html( $v ); ?></option>
                    <?php endforeach; ?>
                </select></td></tr>
            <tr><th><label for="label_graphic_size_pct"><?php _e( 'Wielkość', 'photojob-organizer' ); ?></label></th>
                <td><input type="number" id="label_graphic_size_pct" name="label_graphic_size_pct" value="<?php echo esc_attr( $l['graphic_size_pct'] ); ?>" min="5" max="50"> %</td></tr>
            <tr><th><?php _e( 'Logo z motywu', 'photojob-organizer' ); ?></th>
                <td><label><input type="checkbox" name="label_show_logo" value="1" <?php checked( $l['show_logo'], 1 ); ?>> <?php _e( 'Pokazuj logo (z Wygląd → Logo)', 'photojob-organizer' ); ?></label></td></tr>
        </table>

        <h3><?php _e( 'Obramowanie', 'photojob-organizer' ); ?></h3>
        <table class="form-table">
            <tr><th><?php _e( 'Włącz', 'photojob-organizer' ); ?></th>
                <td><label><input type="checkbox" name="label_border_enabled" value="1" <?php checked( $l['border_enabled'], 1 ); ?>> <?php _e( 'Rysuj ramkę', 'photojob-organizer' ); ?></label></td></tr>
            <tr><th><label for="label_border_width"><?php _e( 'Grubość (px)', 'photojob-organizer' ); ?></label></th>
                <td><input type="number" id="label_border_width" name="label_border_width" value="<?php echo esc_attr( $l['border_width'] ); ?>" min="1" max="10"></td></tr>
            <tr><th><label for="label_border_style"><?php _e( 'Styl', 'photojob-organizer' ); ?></label></th>
                <td><select id="label_border_style" name="label_border_style">
                    <?php foreach ( $border_styles as $bs ) : ?>
                        <option value="<?php echo esc_attr( $bs ); ?>" <?php selected( $l['border_style'], $bs ); ?>><?php echo esc_html( $bs ); ?></option>
                    <?php endforeach; ?>
                </select></td></tr>
            <tr><th><label for="label_border_color"><?php _e( 'Kolor', 'photojob-organizer' ); ?></label></th>
                <td><input type="color" id="label_border_color" name="label_border_color" value="<?php echo esc_attr( $l['border_color'] ?: '#000000' ); ?>"></td></tr>
        </table>

        <h3><?php _e( 'Test wydruku', 'photojob-organizer' ); ?></h3>
        <p><button type="button" class="button" id="pjo-label-preview">👁 <?php _e( 'Podgląd HTML', 'photojob-organizer' ); ?></button></p>
        <div id="pjo-label-preview-area" style="display:none;border:1px solid #c3c4c7;padding:20px;margin-top:10px;background:#f6f7f7;">
            <h4><?php _e( 'Podgląd (sample data)', 'photojob-organizer' ); ?></h4>
            <div id="pjo-label-preview-render" style="background:white;padding:20px;margin:20px auto;max-width:500px;"></div>
        </div>

        <script>
        document.getElementById('pjo-label-preview').addEventListener('click', function() {
            var fields = Array.from(document.querySelectorAll('input[name="label_fields[]"]:checked')).map(function(c){return c.value;});
            var gURL = document.getElementById('label_graphic_url').value;
            var gPos = document.getElementById('label_graphic_position').value;
            var gSize = document.getElementById('label_graphic_size_pct').value;
            var bEn = document.querySelector('input[name="label_border_enabled"]').checked;
            var bW = document.getElementById('label_border_width').value;
            var bS = document.getElementById('label_border_style').value;
            var bC = document.getElementById('label_border_color').value;
            var customText = document.getElementById('label_custom_text').value;
            var sender = document.getElementById('label_sender').value;
            var sample = { name: 'Jan Kowalski', address: 'ul. Przykładowa 12/3\n00-001 Warszawa', order_no: '#10316', phone: '+48 600 000 000', email: 'jan@example.com', date: new Date().toLocaleDateString('pl-PL'), barcode: '||||| ||||| 10316 ||||| |||||', custom_text: customText || '— brak —' };
            var labels = { name: 'Imię', address: 'Adres', order_no: 'Nr zam.', phone: 'Tel', email: 'Email', date: 'Data', barcode: 'Kod', custom_text: '' };
            var content = '';
            if (sender) content += '<div style="font-size:9px;color:#777;border-bottom:1px solid #eee;padding-bottom:4px;margin-bottom:8px;white-space:pre-line;">' + sender + '</div>';
            var graphicHTML = '';
            if (gURL) {
                var posStyle = { 'top-left':'position:absolute;top:8px;left:8px;', 'top-right':'position:absolute;top:8px;right:8px;', 'bottom-left':'position:absolute;bottom:8px;left:8px;', 'bottom-right':'position:absolute;bottom:8px;right:8px;', 'center':'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);opacity:0.4;', 'background':'position:absolute;top:0;left:0;width:100%;height:100%;opacity:0.15;object-fit:cover;' };
                var sty = posStyle[gPos] || posStyle['top-left'];
                if (gPos !== 'background') sty += 'width:' + gSize + '%;';
                graphicHTML = '<img src="' + gURL + '" style="' + sty + '">';
            }
            fields.forEach(function(f) {
                if (f === 'custom_text') {
                    if (sample.custom_text && sample.custom_text !== '— brak —') content += '<div style="font-style:italic;color:#555;margin-top:6px;">' + sample[f] + '</div>';
                } else if (sample[f]) content += '<div><strong>' + labels[f] + ':</strong> ' + sample[f].replace(/\n/g, '<br>') + '</div>';
            });
            var border = bEn ? bW + 'px ' + bS + ' ' + bC : '1px dashed #ccc';
            var style = 'position:relative;border:' + border + ';padding:16px;min-height:120px;font-family:Arial,sans-serif;font-size:13px;overflow:hidden;';
            document.getElementById('pjo-label-preview-render').innerHTML = '<div style="' + style + '">' + graphicHTML + content + '</div>';
            document.getElementById('pjo-label-preview-area').style.display = 'block';
        });
        document.getElementById('pjo-media-graphic').addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) { alert('Media library nie jest dostępna.'); return; }
            var frame = wp.media({ title: 'Wybierz grafikę', button: { text: 'Użyj' }, multiple: false });
            frame.on('select', function() { document.getElementById('label_graphic_url').value = frame.state().get('selection').first().toJSON().url; });
            frame.open();
        });
        </script>
        <?php
    }

    private function render_tab_labs() {
        $labs = get_option( 'pjo_settings_labs', array() );
        $nphoto = wp_parse_args( $labs['nphoto'] ?? array(), array( 'enabled' => 1, 'mode' => 'export_zip', 'zip_format' => 'flat_per_size' ) );
        ?>
        <p class="description"><?php _e( 'Sklepy/laboratoria do eksportu paczek. nPhoto = brak publicznego API.', 'photojob-organizer' ); ?></p>
        <h3>nPhoto</h3>
        <table class="form-table">
            <tr><th><?php _e( 'Włączony', 'photojob-organizer' ); ?></th>
                <td><label><input type="checkbox" name="lab_nphoto_enabled" value="1" <?php checked( $nphoto['enabled'], 1 ); ?>> <?php _e( 'Tak', 'photojob-organizer' ); ?></label></td></tr>
            <tr><th><label for="lab_nphoto_mode"><?php _e( 'Tryb', 'photojob-organizer' ); ?></label></th>
                <td><select id="lab_nphoto_mode" name="lab_nphoto_mode">
                    <option value="export_zip" <?php selected( $nphoto['mode'], 'export_zip' ); ?>><?php _e( 'Eksport ZIP', 'photojob-organizer' ); ?></option>
                    <option value="browser_bot" <?php selected( $nphoto['mode'], 'browser_bot' ); ?>><?php _e( 'Bot przeglądarki', 'photojob-organizer' ); ?></option>
                    <option value="api" <?php selected( $nphoto['mode'], 'api' ); ?>>API</option>
                </select></td></tr>
            <tr><th><label for="lab_nphoto_zip_format"><?php _e( 'Format ZIP', 'photojob-organizer' ); ?></label></th>
                <td><select id="lab_nphoto_zip_format" name="lab_nphoto_zip_format">
                    <option value="flat_per_size" <?php selected( $nphoto['zip_format'], 'flat_per_size' ); ?>><?php _e( 'Płaski per rozmiar', 'photojob-organizer' ); ?></option>
                    <option value="nested" <?php selected( $nphoto['zip_format'], 'nested' ); ?>><?php _e( 'Zagnieżdżony', 'photojob-organizer' ); ?></option>
                </select></td></tr>
        </table>
        <?php
    }
}
