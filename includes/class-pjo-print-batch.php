<?php
/**
 * PhotoJob_Print_Batch — numeracja i rejestr wydruków (Faza C #4).
 *
 * Numer wg schematu: YY + inicjały firmy + litera sezonu + GLOBALNY licznik.
 *   2026 / "Zielony i Niebieski Motylek" / Wiosenna / 1  →  26ZiNMW1
 *
 * Licznik (pjo_print_batch_seq) jest GLOBALNY i nigdy się nie resetuje —
 * końcówka numeru zawsze unikalna w całym systemie (decyzja 2026-06-18).
 *
 * @package PhotoJob_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PhotoJob_Print_Batch {

    const SEQ_OPTION = 'pjo_print_batch_seq';
    const START_OPTION = 'pjo_print_start_number';

    /**
     * Numer startowy numeracji (z ustawień, default 1).
     */
    public static function get_start_number() {
        return max( 1, (int) get_option( self::START_OPTION, 1 ) );
    }

    public static function set_start_number( $n ) {
        update_option( self::START_OPTION, max( 1, (int) $n ) );
    }

    /**
     * Reset licznika tak, by NASTĘPNY numer = numer startowy.
     * (Licznik trzyma „ostatni użyty"; ustawiamy start-1.)
     */
    public static function reset_counter() {
        update_option( self::SEQ_OPTION, self::get_start_number() - 1 );
    }

    /**
     * Zbuduj numer wydruku z danych klienta/sezonu, rezerwując kolejny globalny licznik.
     *
     * @param string $client  pełna nazwa firmy/klienta (root kategorii)
     * @param string $season  nazwa sezonu (np. "Wiosenna")
     * @param string $year    rok 4-cyfrowy (np. "2026") — fallback bieżący
     * @return array {number, client_initials, season_letter, year2, seq}
     */
    public static function generate_number( $client, $season, $year = '' ) {
        $year2 = $year !== '' && preg_match( '/(\d{2})$/', $year, $m ) ? $m[1] : substr( (string) gmdate( 'Y' ), -2 );
        $initials = self::client_initials( $client );
        $season_letter = self::season_letter( $season );
        $seq = self::next_seq();
        $number = $year2 . $initials . $season_letter . $seq;
        return array(
            'number'          => $number,
            'client_initials' => $initials,
            'season_letter'   => $season_letter,
            'year2'           => $year2,
            'seq'             => $seq,
        );
    }

    /**
     * Inicjały firmy: pierwsza litera każdego słowa, zachowując wielkość.
     * "Zielony i Niebieski Motylek" → "ZiNM".
     */
    public static function client_initials( $client ) {
        $client = trim( (string) $client );
        if ( $client === '' ) {
            return 'X';
        }
        $words = preg_split( '/\s+/u', $client );
        $out = '';
        foreach ( $words as $w ) {
            if ( $w === '' ) {
                continue;
            }
            $out .= mb_substr( $w, 0, 1 );
        }
        return $out !== '' ? $out : 'X';
    }

    /**
     * Litera sezonu (wielka): Wiosenna→W, Zimowa→Z, Letnia→L, Jesienna→J.
     */
    public static function season_letter( $season ) {
        $season = trim( (string) $season );
        if ( $season === '' ) {
            return '';
        }
        return mb_strtoupper( mb_substr( $season, 0, 1 ) );
    }

    /**
     * Atomowo zwiększ globalny licznik wydruków i zwróć nową wartość.
     */
    private static function next_seq() {
        global $wpdb;
        // Atomowy upsert na wp_options, żeby równoległe buildy nie nadały tego samego numeru.
        // Floor = numer startowy: pierwszy build (INSERT) = start; kolejne = max(prev+1, start),
        // więc podniesienie startu w ustawieniach przeskakuje numerację w górę.
        $name = self::SEQ_OPTION;
        $start = self::get_start_number();
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %d, 'no')
             ON DUPLICATE KEY UPDATE option_value = GREATEST( CAST(option_value AS UNSIGNED) + 1, %d )",
            $name, $start, $start
        ) );
        wp_cache_delete( $name, 'options' );
        $val = (int) get_option( $name, $start );
        return max( $start, $val );
    }

    /**
     * Zapisz rekord wydruku.
     *
     * @return string|false  numer wydruku lub false
     */
    public static function save( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_print_batches';
        $row = array(
            'number'          => $data['number'],
            'client_initials' => $data['client_initials'] ?? '',
            'season_letter'   => $data['season_letter'] ?? '',
            'year2'           => $data['year2'] ?? '',
            'qnap_path'       => $data['qnap_path'] ?? '',
            'order_ids'       => isset( $data['order_ids'] ) ? implode( ',', (array) $data['order_ids'] ) : '',
            'file_count'      => (int) ( $data['file_count'] ?? 0 ),
            'status'          => $data['status'] ?? 'built',
            'created_by'      => get_current_user_id(),
        );
        $ok = $wpdb->insert( $table, $row );
        return $ok ? $data['number'] : false;
    }

    public static function get_by_number( $number ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_print_batches';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE number=%s", $number ), ARRAY_A );
    }

    /**
     * Ostatnie paczki wydruku (do dropdownu „dodaj do istniejącego folderu").
     *
     * @return array<array{number,qnap_path,order_ids,file_count,created_at}>
     */
    public static function recent( $limit = 25 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_print_batches';
        $limit = max( 1, min( 200, (int) $limit ) );
        return (array) $wpdb->get_results(
            "SELECT number, qnap_path, order_ids, file_count, created_at FROM {$table} ORDER BY id DESC LIMIT {$limit}",
            ARRAY_A
        );
    }

    /**
     * Dopisz do istniejącego rekordu wydruku: scal listę zamówień i dolicz pliki.
     * Jeśli rekord nie istnieje (np. folder utworzony ręcznie) — utwórz nowy.
     *
     * @param string $number      numer wydruku (26ZiNMW4)
     * @param int[]  $order_ids   zamówienia dorzucone w tej akcji
     * @param int    $added_files liczba skopiowanych plików
     * @param string $qnap_path   ścieżka folderu paczki (do zapisu gdy brak rekordu)
     * @return bool
     */
    public static function append_record( $number, $order_ids, $added_files, $qnap_path = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_print_batches';
        $row = self::get_by_number( $number );
        $new_ids = array_map( 'absint', (array) $order_ids );
        if ( ! $row ) {
            return (bool) self::save( array(
                'number'     => $number,
                'qnap_path'  => $qnap_path,
                'order_ids'  => $new_ids,
                'file_count' => (int) $added_files,
                'status'     => 'built',
            ) );
        }
        $existing_ids = array_filter( array_map( 'absint', explode( ',', (string) $row['order_ids'] ) ) );
        $merged = array_values( array_unique( array_merge( $existing_ids, $new_ids ) ) );
        return false !== $wpdb->update(
            $table,
            array(
                'order_ids'  => implode( ',', $merged ),
                'file_count' => (int) $row['file_count'] + (int) $added_files,
            ),
            array( 'number' => $number )
        );
    }
}
