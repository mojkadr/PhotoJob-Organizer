<?php
/**
 * PhotoJob_Access_Sync — automatyczny etap produkcji po przyznaniu dostępu (Photo Access).
 *
 * Gdy wtyczka Photo Access (auto-download-access) przyzna dostęp do zdjęć, zapisuje
 * post-meta `_ada_access_granted = 'yes'` — w KAŻDEJ ścieżce grantu (ADA_Access_Processor,
 * legacy Drive, ręczne przypisanie, wymuszony reprocess). Nasłuchujemy na tę metę
 * (bez edycji tamtej wtyczki) i ustawiamy etap produkcji w pjo_order_meta:
 *
 *   - zamówienie TYLKO elektroniczne (brak odbitek)  → link_sent  (📧 Link wysłany)
 *   - zamówienie z odbitkami do druku (JPG + wydruk)  → incomplete (⚠ Niekompletne)
 *
 * Niedestrukcyjne: nie cofa ręcznie ustawionego, dalszego etapu (działa tylko gdy etap
 * jest pusty / „pending").
 *
 * @package PhotoJob_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PhotoJob_Access_Sync {

    const GRANT_META = '_ada_access_granted';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Photo Access pisze przez update_post_meta() → oba hooki łapią pierwszy grant
        // (added) i ponowny po reprocess, który najpierw kasuje metę (added znów).
        add_action( 'added_post_meta',   array( $this, 'on_access_meta' ), 10, 4 );
        add_action( 'updated_post_meta', array( $this, 'on_access_meta' ), 10, 4 );
    }

    public function on_access_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( $meta_key !== self::GRANT_META || $meta_value !== 'yes' ) {
            return;
        }
        $this->auto_set_stage_on_grant( (int) $object_id );
    }

    /**
     * Ustaw etap produkcji po przyznaniu dostępu. Nie nadpisuje ręcznego postępu.
     */
    public function auto_set_stage_on_grant( $order_id ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return; // _ada_access_granted na nie-zamówieniu — ignoruj
        }

        $stage = self::is_electronic_only( $order ) ? 'link_sent' : 'incomplete';

        global $wpdb;
        $table = $wpdb->prefix . 'pjo_order_meta';
        $current = $wpdb->get_var( $wpdb->prepare( "SELECT production_status FROM {$table} WHERE order_id=%d", $order_id ) );

        // Nie cofaj ręcznie zaawansowanego etapu (np. shipped). Tylko świeże / pending.
        if ( $current !== null && ! in_array( $current, array( '', 'pending' ), true ) ) {
            return;
        }

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE order_id=%d", $order_id ) );
        if ( $exists ) {
            $wpdb->update( $table, array( 'production_status' => $stage ), array( 'order_id' => $order_id ) );
        } else {
            $wpdb->insert( $table, array( 'order_id' => $order_id, 'production_status' => $stage ) );
        }

        $label = ( $stage === 'link_sent' )
            ? __( 'Link wysłany', 'photojob-organizer' )
            : __( 'Niekompletne (odbitki do druku)', 'photojob-organizer' );
        $order->add_order_note( sprintf(
            /* translators: %s = etap produkcji */
            __( '[PJO] Photo Access przyznał dostęp → etap produkcji: %s.', 'photojob-organizer' ),
            $label
        ) );
    }

    /**
     * Czy zamówienie zawiera WYŁĄCZNIE wersje elektroniczne (brak odbitek do druku)?
     * Pozycja „do druku" ma meta WAPF z rozmiarem (\d+x\d+) — spójne z Folder Builderem.
     */
    public static function is_electronic_only( $order ) {
        $has_any = false;
        foreach ( $order->get_items() as $item ) {
            $has_any = true;
            foreach ( $item->get_meta_data() as $meta ) {
                if ( $meta->key === '' || substr( $meta->key, 0, 1 ) === '_' ) {
                    continue;
                }
                $v = is_string( $meta->value ) ? $meta->value : maybe_serialize( $meta->value );
                $v = wp_strip_all_tags( $v );
                if ( preg_match( '/\d+\s*[x×]\s*\d+/u', $v ) ) {
                    return false; // znaleziono rozmiar → jest druk
                }
            }
        }
        return $has_any; // ma pozycje, ale żadna nie ma rozmiaru → tylko elektroniczne
    }
}
