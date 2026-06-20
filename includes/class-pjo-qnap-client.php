<?php
/**
 * PhotoJob_QNAP_Client — klient QNAP File Station API (QTS 5+)
 *
 * Reużywa wzorzec auth z testu połączenia (Settings → QNAP):
 *  - login: POST /cgi-bin/authLogin.cgi  (user + pwd=base64) → <authSid>
 *  - operacje: /cgi-bin/filemanager/utilRequest.cgi?func=...&sid=...
 *
 * Bezpośredni cURL (NIE wp_remote_*) — bo na sesje.mojkadr.eu wtyczki (W3TC/Cart Editor)
 * hookują WP HTTP middleware i zwracają puste body w AJAX context (lekcja z v1.3.3).
 *
 * Obsługa OBU formatów odpowiedzi File Station (lekcja feedback_qnap_v5_api_lesson):
 *  - v4: { "status": 1, ... }
 *  - v5: { "success": "true", "status": 25, ... }
 *
 * @package PhotoJob_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PhotoJob_QNAP_Client {

    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var bool */
    private $use_ssl;
    /** @var bool */
    private $verify_ssl;
    /** @var string */
    private $user;
    /** @var string */
    private $password;

    /** @var string Zalogowany SID (pusty = brak sesji) */
    private $sid = '';

    /** @var string Ostatni błąd (dla diagnostyki) */
    public $last_error = '';

    /** @var array Surowe logi requestów (dla debug w UI) */
    public $log = array();

    /** Limity bezpieczeństwa przy rekurencyjnym indeksowaniu źródła */
    const MAX_INDEX_FILES = 200000;
    const MAX_INDEX_DEPTH = 14;

    /**
     * @param array|null $settings Nadpisanie ustawień (domyślnie z pjo_settings_qnap).
     */
    public function __construct( $settings = null ) {
        $q = $settings ?: get_option( 'pjo_settings_qnap', array() );
        $this->host       = isset( $q['host'] ) ? trim( $q['host'] ) : '';
        $this->port       = isset( $q['port'] ) ? absint( $q['port'] ) : 443;
        $this->use_ssl    = isset( $q['use_ssl'] ) ? (bool) $q['use_ssl'] : true;
        $this->verify_ssl = isset( $q['verify_ssl'] ) ? (bool) $q['verify_ssl'] : false;
        $this->user       = isset( $q['user'] ) ? trim( $q['user'] ) : '';
        $this->password   = class_exists( 'PhotoJob_Settings' ) ? PhotoJob_Settings::get_qnap_password() : '';
    }

    public function is_configured() {
        return $this->host !== '' && $this->user !== '' && $this->password !== '';
    }

    public function base_url() {
        $proto = $this->use_ssl ? 'https' : 'http';
        return sprintf( '%s://%s:%d', $proto, $this->host, $this->port );
    }

    /** ============ AUTH ============ */

    public function login() {
        if ( $this->sid !== '' ) {
            return true;
        }
        if ( ! $this->is_configured() ) {
            $this->last_error = __( 'QNAP nie jest skonfigurowany (host / użytkownik / hasło).', 'photojob-organizer' );
            return false;
        }
        $url = $this->base_url() . '/cgi-bin/authLogin.cgi';
        $res = $this->raw_request( $url, 'POST', array(
            'user' => $this->user,
            'pwd'  => base64_encode( $this->password ),
        ), array( 'Accept: text/xml, application/xml' ) );

        if ( $res === false ) {
            return false; // last_error ustawiony
        }
        $body = $res['body'];
        $sid = '';
        if ( preg_match( '#<authSid>(<!\[CDATA\[)?([^<\]]+)(\]\]>)?</authSid>#', $body, $m ) ) {
            $sid = $m[2];
        } elseif ( preg_match( '#<authSid>([^<]+)</authSid>#', $body, $m ) ) {
            $sid = $m[1];
        }
        $passed = '';
        if ( preg_match( '#<authPassed>(?:<!\[CDATA\[)?([^<\]]*)#', $body, $m ) ) {
            $passed = $m[1];
        }
        if ( $sid === '' || $passed !== '1' ) {
            $this->last_error = sprintf(
                __( 'Logowanie do QNAP nie powiodło się (authPassed=%s).', 'photojob-organizer' ),
                $passed !== '' ? $passed : 'brak'
            );
            return false;
        }
        $this->sid = $sid;
        return true;
    }

    public function logout() {
        if ( $this->sid === '' ) {
            return;
        }
        $url = $this->base_url() . '/cgi-bin/authLogout.cgi?sid=' . rawurlencode( $this->sid );
        $this->raw_request( $url, 'GET' );
        $this->sid = '';
    }

    /** ============ FILE STATION OPS ============ */

    /**
     * Wywołanie File Station utilRequest.cgi.
     *
     * @param string $func  np. get_list, createdir, copy, rename, stat
     * @param array  $params
     * @param string $method
     * @return array|false  Zdekodowany JSON lub false (last_error ustawiony).
     */
    private function fs_request( $func, $params = array(), $method = 'GET' ) {
        if ( ! $this->login() ) {
            return false;
        }
        $params = array_merge( array( 'func' => $func, 'sid' => $this->sid ), $params );
        $url = $this->base_url() . '/cgi-bin/filemanager/utilRequest.cgi';

        if ( $method === 'GET' ) {
            $url .= '?' . $this->build_query( $params );
            $res = $this->raw_request( $url, 'GET', null, array( 'Accept: application/json, text/xml' ) );
        } else {
            $res = $this->raw_request( $url, 'POST', $params, array( 'Accept: application/json, text/xml' ) );
        }
        if ( $res === false ) {
            return false;
        }
        $json = json_decode( $res['body'], true );
        if ( ! is_array( $json ) ) {
            $this->last_error = sprintf(
                __( 'QNAP zwrócił nie-JSON dla func=%s (HTTP %d): %s', 'photojob-organizer' ),
                $func, $res['code'], substr( wp_strip_all_tags( $res['body'] ), 0, 200 )
            );
            return false;
        }
        return $json;
    }

    /**
     * Czy odpowiedź File Station to sukces? Obsługuje formaty v4 i v5.
     */
    private function is_ok( $json ) {
        if ( ! is_array( $json ) ) {
            return false;
        }
        if ( isset( $json['success'] ) ) {
            $s = $json['success'];
            if ( $s === true || $s === 'true' || $s === 1 || $s === '1' ) {
                return true;
            }
        }
        if ( isset( $json['status'] ) ) {
            $st = (int) $json['status'];
            // v4: status=1 OK. v5: status 0/25 spotykane jako OK przy success.
            if ( $st === 1 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lista zawartości folderu.
     *
     * @return array|false  Lista wpisów [ ['filename'=>, 'isfolder'=>0/1], ... ]
     */
    public function get_list( $path ) {
        $json = $this->fs_request( 'get_list', array(
            'path'      => $path,
            'list_mode' => 'all',
            'start'     => 0,
            'limit'     => 100000,
            'sort'      => 'filename',
            'dir'       => 'ASC',
        ), 'GET' );
        if ( $json === false ) {
            return false;
        }
        // datas (v5) lub data (v4) → lista (także pusty folder = pusta tablica).
        if ( isset( $json['datas'] ) && is_array( $json['datas'] ) ) {
            return $json['datas'];
        }
        if ( isset( $json['data'] ) && is_array( $json['data'] ) ) {
            return $json['data'];
        }
        // Brak listy danych. Jeśli odpowiedź NIE jest OK → realny błąd (ścieżka nie
        // istnieje / brak dostępu) — sygnalizujemy false, a nie cichą pustą listę.
        if ( ! $this->is_ok( $json ) ) {
            $this->last_error = sprintf(
                __( 'Nie można wylistować "%s" (QNAP: %s)', 'photojob-organizer' ),
                $path, substr( wp_json_encode( $json ), 0, 200 )
            );
            return false;
        }
        return array();
    }

    /**
     * Czy ścieżka (folder) istnieje? Sprawdza przez get_list rodzica.
     */
    public function folder_exists( $path ) {
        $parent = rtrim( dirname( $path ), '/' );
        $name = basename( $path );
        if ( $parent === '' ) {
            $parent = '/';
        }
        $items = $this->get_list( $parent );
        if ( $items === false ) {
            return false;
        }
        foreach ( $items as $it ) {
            if ( isset( $it['filename'] ) && $it['filename'] === $name && ! empty( $it['isfolder'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Utwórz pojedynczy folder (idempotentnie — "już istnieje" traktujemy jako OK).
     */
    public function createdir( $parent_path, $folder_name ) {
        $json = $this->fs_request( 'createdir', array(
            'dest_path'   => $parent_path,
            'dest_folder' => $folder_name,
        ), 'GET' );
        if ( $json === false ) {
            return false;
        }
        if ( $this->is_ok( $json ) ) {
            return true;
        }
        // Folder już istnieje → OK. QNAP zwraca różne kody, więc weryfikujemy realnie.
        if ( $this->folder_exists( rtrim( $parent_path, '/' ) . '/' . $folder_name ) ) {
            return true;
        }
        $this->last_error = sprintf(
            __( 'Nie udało się utworzyć folderu "%s" w "%s": %s', 'photojob-organizer' ),
            $folder_name, $parent_path, wp_json_encode( $json )
        );
        return false;
    }

    /**
     * Utwórz zagnieżdżoną ścieżkę (mkdir -p). $full_path absolutny od share, np.
     * /MójKadr/Druk/2025-Zimowa/10316/Odbitki/15x23
     */
    public function make_path( $full_path ) {
        $full_path = '/' . trim( $full_path, '/' );
        $parts = explode( '/', trim( $full_path, '/' ) );
        $current = '';
        foreach ( $parts as $part ) {
            if ( $part === '' ) {
                continue;
            }
            $parent = $current === '' ? '/' : $current;
            if ( ! $this->createdir( $parent, $part ) ) {
                return false;
            }
            $current = $parent === '/' ? '/' . $part : $parent . '/' . $part;
        }
        return true;
    }

    /**
     * Skopiuj pojedynczy plik do folderu docelowego (zachowuje nazwę).
     *
     * @param string $source_dir   katalog źródłowy
     * @param string $source_file  nazwa pliku
     * @param string $dest_dir     katalog docelowy
     * @param bool   $overwrite
     */
    public function copy_file( $source_dir, $source_file, $dest_dir, $overwrite = true ) {
        $json = $this->fs_request( 'copy', array(
            'source_total' => 1,
            'source_path'  => $source_dir,
            'source_file'  => $source_file,
            'dest_path'    => $dest_dir,
            'mode'         => 1,
            'dup'          => $overwrite ? 'overwrite' : 'skip',
        ), 'GET' );
        if ( $json === false ) {
            return false;
        }
        if ( $this->is_ok( $json ) ) {
            return true;
        }
        // Weryfikacja realna: plik pojawił się w docelowym?
        if ( $this->file_exists_in( $dest_dir, $source_file ) ) {
            return true;
        }
        $this->last_error = sprintf(
            __( 'Kopiowanie "%s" → "%s" nie powiodło się: %s', 'photojob-organizer' ),
            $source_dir . '/' . $source_file, $dest_dir, wp_json_encode( $json )
        );
        return false;
    }

    /**
     * Zmień nazwę pliku w katalogu.
     */
    public function rename_file( $dir, $old_name, $new_name ) {
        if ( $old_name === $new_name ) {
            return true;
        }
        $json = $this->fs_request( 'rename', array(
            'path'        => $dir,
            'source_name' => $old_name,
            'dest_name'   => $new_name,
        ), 'GET' );
        if ( $json === false ) {
            return false;
        }
        if ( $this->is_ok( $json ) ) {
            return true;
        }
        if ( $this->file_exists_in( $dir, $new_name ) ) {
            return true;
        }
        $this->last_error = sprintf(
            __( 'Zmiana nazwy "%s" → "%s" w "%s" nie powiodła się: %s', 'photojob-organizer' ),
            $old_name, $new_name, $dir, wp_json_encode( $json )
        );
        return false;
    }

    public function file_exists_in( $dir, $filename ) {
        $items = $this->get_list( $dir );
        if ( $items === false ) {
            return false;
        }
        foreach ( $items as $it ) {
            if ( isset( $it['filename'] ) && $it['filename'] === $filename ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Zbuduj indeks plików źródłowych: basename(bez rozszerzenia, lowercase) → pełna ścieżka.
     * Rekurencyjnie przechodzi drzewo $root. Wynik cache'owany w instancji.
     *
     * @return array  [ 'dsc_4821' => array('dir'=>'/MójKadr/Sesje/...', 'file'=>'DSC_4821.JPG'), ... ]
     */
    /** @var array Memoizacja indeksu per root (jeden request = jeden skan magazynu) */
    private $index_cache = array();

    /** Czas życia trwałego cache indeksu magazynu */
    const INDEX_TTL = 900; // 15 min

    /**
     * Katalog cache (uploads/pjo-cache). Cache trzymamy w PLIKU, nie w transient/DB —
     * mapa ~5700 plików (1–2 MB) bywa zbyt duża dla wp_options (max_allowed_packet)
     * i przy wyłączonym object cache transient cicho nie zapisuje się → re-skan za każdym
     * razem. Plik JSON jest pewny i szybki w odczycie.
     */
    private function index_cache_dir() {
        $u = wp_upload_dir();
        $dir = trailingslashit( $u['basedir'] ) . 'pjo-cache';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            @file_put_contents( $dir . '/index.html', '' ); // brak listingu
        }
        return $dir;
    }

    private function index_cache_file( $root ) {
        return $this->index_cache_dir() . '/srcidx-' . md5( $root . '|' . $this->host . '|' . $this->user ) . '.json';
    }

    /**
     * @param string $root
     * @param bool   $force  true → pomiń cache i przeskanuj od nowa.
     */
    public function index_source_files( $root, $force = false ) {
        if ( ! $force && isset( $this->index_cache[ $root ] ) ) {
            return $this->index_cache[ $root ];
        }
        $file = $this->index_cache_file( $root );
        if ( ! $force && is_readable( $file ) && ( time() - filemtime( $file ) ) < self::INDEX_TTL ) {
            $map = json_decode( (string) file_get_contents( $file ), true );
            if ( is_array( $map ) ) {
                $this->index_cache[ $root ] = $map;
                return $map;
            }
        }
        $map = array();
        $count = 0;
        $this->index_recurse( $root, $map, $count, 0 );
        $this->index_cache[ $root ] = $map;
        // Cache tylko niepusty wynik (pusty = zła ścieżka/błąd → nie utrwalaj).
        if ( ! empty( $map ) ) {
            @file_put_contents( $file, wp_json_encode( $map ) );
        }
        return $map;
    }

    /**
     * Wyczyść trwały cache indeksu magazynu (po dodaniu nowych sesji).
     */
    public function clear_source_index( $root ) {
        unset( $this->index_cache[ $root ] );
        $file = $this->index_cache_file( $root );
        if ( file_exists( $file ) ) {
            @unlink( $file );
        }
        // sprzątanie po starym transiencie (z 1.6.5)
        delete_transient( 'pjo_srcidx_' . md5( $root . '|' . $this->host . '|' . $this->user ) );
    }

    private function index_recurse( $dir, &$map, &$count, $depth ) {
        if ( $depth > self::MAX_INDEX_DEPTH || $count >= self::MAX_INDEX_FILES ) {
            return;
        }
        $items = $this->get_list( $dir );
        if ( $items === false ) {
            return;
        }
        foreach ( $items as $it ) {
            $name = $it['filename'] ?? '';
            if ( $name === '' || $name === '.' || $name === '..' ) {
                continue;
            }
            $full = rtrim( $dir, '/' ) . '/' . $name;
            if ( ! empty( $it['isfolder'] ) ) {
                $this->index_recurse( $full, $map, $count, $depth + 1 );
            } else {
                $base = self::strip_ext( $name );
                $key = mb_strtolower( $base );
                // Pierwsze trafienie wygrywa (nie nadpisujemy — gdyby był duplikat nazwy w 2 sesjach).
                if ( ! isset( $map[ $key ] ) ) {
                    $map[ $key ] = array( 'dir' => $dir, 'file' => $name );
                }
                $count++;
                if ( $count >= self::MAX_INDEX_FILES ) {
                    return;
                }
            }
        }
    }

    public static function strip_ext( $filename ) {
        return preg_replace( '/\.[^.]+$/', '', $filename );
    }

    public static function get_ext( $filename ) {
        if ( preg_match( '/\.([^.]+)$/', $filename, $m ) ) {
            return $m[1];
        }
        return '';
    }

    /** ============ LOW-LEVEL cURL ============ */

    /**
     * @return array{body:string,code:int,content_type:string}|false
     */
    private function raw_request( $url, $method = 'GET', $post = null, $headers = array() ) {
        if ( ! function_exists( 'curl_init' ) ) {
            $this->last_error = __( 'PHP cURL niedostępne na serwerze.', 'photojob-organizer' );
            return false;
        }
        $ch = curl_init( $url );
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
            CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_USERAGENT      => 'PhotoJob-Organizer/' . ( defined( 'PHOTOJOB_VERSION' ) ? PHOTOJOB_VERSION : '0' ) . ' (FolderBuilder)',
            CURLOPT_FOLLOWLOCATION => false,
        );
        if ( ! empty( $headers ) ) {
            $opts[ CURLOPT_HTTPHEADER ] = $headers;
        }
        if ( $method === 'POST' ) {
            $opts[ CURLOPT_POST ] = true;
            $opts[ CURLOPT_POSTFIELDS ] = is_array( $post ) ? $this->build_query( $post ) : (string) $post;
        }
        curl_setopt_array( $ch, $opts );
        $raw = curl_exec( $ch );
        $errno = curl_errno( $ch );
        $err = curl_error( $ch );
        $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $content_type = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
        $header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
        curl_close( $ch );

        if ( $errno ) {
            $hint = '';
            if ( $errno === 28 ) {
                $hint = ' ' . __( '(timeout)', 'photojob-organizer' );
            } elseif ( $errno === 60 ) {
                $hint = ' ' . __( '(certyfikat SSL — odznacz "Weryfikuj" w ustawieniach QNAP)', 'photojob-organizer' );
            }
            $this->last_error = sprintf( __( 'Błąd cURL #%d: %s', 'photojob-organizer' ), $errno, $err ) . $hint;
            return false;
        }
        $body = $header_size > 0 ? substr( $raw, $header_size ) : $raw;
        $this->log[] = array( 'url' => $url, 'method' => $method, 'code' => $code, 'len' => strlen( (string) $body ) );
        return array( 'body' => (string) $body, 'code' => $code, 'content_type' => $content_type );
    }

    /**
     * http_build_query z rawurlencode (poprawne kodowanie UTF-8 i spacji w ścieżkach QNAP).
     */
    private function build_query( $params ) {
        $pairs = array();
        foreach ( $params as $k => $v ) {
            $pairs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
        }
        return implode( '&', $pairs );
    }
}
