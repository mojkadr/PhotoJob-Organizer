<?php
/**
 * PhotoJob_Duplicates — wykrywanie zamówień-dubli i grupy pakowania (Faza C #3).
 *
 * Wykrywa zamówienia W REALIZACJI (nie zakończone/anulowane) dzielące ten sam
 * e-mail LUB imię i nazwisko → klastry. Pozwala połączyć je w GRUPĘ PAKOWANIA
 * (niedestrukcyjnie: zamówienia zostają osobno w WC, dostają wspólny pack_group),
 * żeby zdjęcia drukować i pakować razem do jednej koperty.
 *
 * @package PhotoJob_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PhotoJob_Duplicates {

    const CACHE_KEY = 'pjo_dup_clusters';
    const CACHE_TTL = 300; // 5 min

    /**
     * Statusy uznawane za "w realizacji" (kandydaci do łączenia).
     */
    public static function active_statuses() {
        return array( 'processing', 'on-hold', 'pending' );
    }

    /**
     * Zwróć mapę: order_id => array('reason'=>'email|name', 'siblings'=>[order_id,...], 'key'=>...).
     * Tylko zamówienia mające co najmniej jeden "bliźniak".
     *
     * @param bool $force
     */
    public static function get_clusters( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }
        $orders = wc_get_orders( array(
            'limit'  => -1,
            'status' => self::active_statuses(),
            'return' => 'objects',
            'orderby'=> 'date',
            'order'  => 'DESC',
        ) );

        $by_email = array();
        $by_name  = array();
        $meta     = array();
        foreach ( $orders as $o ) {
            $oid = $o->get_id();
            $email = strtolower( trim( $o->get_billing_email() ) );
            $name = self::normalize_name( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
            if ( $name === '' ) {
                $name = self::normalize_name( $o->get_billing_company() );
            }
            $meta[ $oid ] = array( 'email' => $email, 'name' => $name );
            if ( $email !== '' ) {
                $by_email[ $email ][] = $oid;
            }
            if ( $name !== '' ) {
                $by_name[ $name ][] = $oid;
            }
        }

        $clusters = array();
        foreach ( $meta as $oid => $m ) {
            $siblings = array();
            $reason = array();
            if ( $m['email'] !== '' && count( $by_email[ $m['email'] ] ) > 1 ) {
                foreach ( $by_email[ $m['email'] ] as $sid ) {
                    if ( $sid != $oid ) {
                        $siblings[ $sid ] = true;
                    }
                }
                $reason[] = 'email';
            }
            if ( $m['name'] !== '' && count( $by_name[ $m['name'] ] ) > 1 ) {
                foreach ( $by_name[ $m['name'] ] as $sid ) {
                    if ( $sid != $oid ) {
                        $siblings[ $sid ] = true;
                    }
                }
                $reason[] = 'name';
            }
            if ( ! empty( $siblings ) ) {
                $clusters[ $oid ] = array(
                    'reason'   => implode( '+', $reason ),
                    'siblings' => array_keys( $siblings ),
                );
            }
        }
        set_transient( self::CACHE_KEY, $clusters, self::CACHE_TTL );
        return $clusters;
    }

    public static function clear_cache() {
        delete_transient( self::CACHE_KEY );
    }

    public static function normalize_name( $name ) {
        $name = mb_strtolower( trim( (string) $name ) );
        $name = preg_replace( '/\s+/u', ' ', $name );
        return $name;
    }

    /**
     * Połącz zamówienia w grupę pakowania. Jeśli któreś z nich należy już do grupy,
     * dołączamy resztę do tej istniejącej; w przeciwnym razie tworzymy nowy id grupy.
     *
     * @param int[] $order_ids
     * @return array {group:string, members:int[]}|WP_Error
     */
    public static function link_group( $order_ids ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_order_meta';
        $order_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
        if ( count( $order_ids ) < 2 ) {
            return new WP_Error( 'too_few', __( 'Podaj co najmniej 2 zamówienia do połączenia.', 'photojob-organizer' ) );
        }

        // Czy któreś już ma grupę?
        $placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
        $existing = $wpdb->get_col( $wpdb->prepare(
            "SELECT pack_group FROM {$table} WHERE order_id IN ({$placeholders}) AND pack_group IS NOT NULL AND pack_group != ''",
            $order_ids
        ) );
        $group = ! empty( $existing ) ? $existing[0] : 'PACK-' . substr( md5( implode( ',', $order_ids ) . microtime() ), 0, 8 );

        foreach ( $order_ids as $oid ) {
            self::upsert_meta( $oid, 'pack_group', $group );
        }
        self::clear_cache();
        return array( 'group' => $group, 'members' => $order_ids );
    }

    /**
     * Usuń zamówienie z grupy pakowania (rozłączenie).
     */
    public static function unlink( $order_id ) {
        self::upsert_meta( absint( $order_id ), 'pack_group', null );
        self::clear_cache();
        return true;
    }

    /**
     * Pobierz członków grupy pakowania.
     *
     * @return int[]
     */
    public static function group_members( $group ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_order_meta';
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE pack_group=%s", $group ) );
        return array_map( 'absint', $ids );
    }

    private static function upsert_meta( $order_id, $col, $value ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_order_meta';
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE order_id=%d", $order_id ) );
        if ( $exists ) {
            $wpdb->update( $table, array( $col => $value ), array( 'order_id' => $order_id ) );
        } else {
            $wpdb->insert( $table, array( 'order_id' => $order_id, $col => $value ) );
        }
    }
}
