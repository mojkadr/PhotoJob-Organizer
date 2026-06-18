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
        $name = self::SEQ_OPTION;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $name
        ) );
        wp_cache_delete( $name, 'options' );
        $val = (int) get_option( $name, 1 );
        return max( 1, $val );
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
}
