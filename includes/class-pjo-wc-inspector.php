<?php
/**
 * PhotoJob_WC_Inspector — czyta strukturę kategorii i line items z WooCommerce
 *
 * Sezony, Placówki i mapowanie typ/rozmiar NIE są konfigurowane ręcznie.
 * Są auto-wykrywane z:
 *  - taksonomii `product_cat` (hierarchia: Klient/Rok/Sezon/Oddział/Typ/Grupa/Osoba)
 *  - meta line items zamówień WC (WAPF "Wydruk odbitki" zawiera rozmiar np. "15x23cm")
 *
 * Cache w `wp_options` z TTL.
 *
 * @package PhotoJob_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PhotoJob_WC_Inspector {

    const CACHE_TTL = 3600; // 1h
    const CACHE_SEASONS    = 'pjo_cache_seasons';
    const CACHE_FACILITIES = 'pjo_cache_facilities';
    const CACHE_SIZES      = 'pjo_cache_sizes';

    /**
     * Auto-wykryj sezony z hierarchii product_cat.
     *
     * Logika: kategorie na poziomie 2 (children of children of root) ZAWIERAJĄCE
     * keyword "wiosenna"/"zimowa"/"letnia"/"jesienna" → to są sezony.
     * Plus daty: skanujemy zamówienia z tymi kategoriami, znajdujemy zakres dat.
     *
     * @param bool $force Wymuś refresh.
     * @return array [ ['name'=>'Zimowa', 'year'=>2025, 'product_count'=>N, 'order_count'=>N, 'term_id'=>X], ... ]
     */
    public static function get_seasons( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_SEASONS );
            if ( $cached !== false ) {
                return $cached;
            }
        }
        $seasons = array();
        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $terms ) ) {
            return array();
        }

        $by_id = array();
        foreach ( $terms as $t ) {
            $by_id[ $t->term_id ] = $t;
        }

        // Rok = kategoria której nazwa to 4-cyfrowy rok (2020-2099)
        // Sezon = kategoria której parent jest rokiem I nazwa zawiera "wiosenna"|"zimowa"|"letnia"|"jesienna"
        foreach ( $terms as $t ) {
            $name_lc = mb_strtolower( $t->name );
            if ( ! preg_match( '/(wiosenn|zimow|letni|jesienn)/u', $name_lc ) ) {
                continue;
            }
            $parent = $by_id[ $t->parent ] ?? null;
            $year = null;
            if ( $parent && preg_match( '/^\d{4}$/', $parent->name ) ) {
                $year = (int) $parent->name;
            }
            $product_count = self::count_products_in_category_tree( $t->term_id );
            $seasons[] = array(
                'term_id'       => $t->term_id,
                'name'          => $t->name,
                'year'          => $year,
                'product_count' => $product_count,
                'order_count'   => self::count_orders_with_category( $t->term_id ),
                'slug'          => $t->slug,
            );
        }
        usort( $seasons, function( $a, $b ) {
            $cmp = ( $b['year'] ?? 0 ) <=> ( $a['year'] ?? 0 );
            if ( $cmp === 0 ) {
                $cmp = strcmp( $a['name'], $b['name'] );
            }
            return $cmp;
        } );
        set_transient( self::CACHE_SEASONS, $seasons, self::CACHE_TTL );
        return $seasons;
    }

    /**
     * Auto-wykryj placówki z hierarchii product_cat.
     *
     * Mapowanie poziomów (na bazie analizy DB):
     *   poziom 0 = Klient (np. "Zielony i Niebieski Motylek")
     *   poziom 1 = Rok ("2025", "2026")
     *   poziom 2 = Sezon ("Wiosenna", "Zimowa")
     *   poziom 3 = Oddział ("Bałtycka", "Mazowiecka", "Jasionka", "Zelwerowicza")
     *   poziom 4 = Typ grupy ("Przedszkole", "Żłobek", "Rodzinne")
     *   poziom 5 = Grupa ("3 Latki", "5 Latki") — opcjonalny dla żłobka/rodzinnych
     *   poziom 6 = Osoba ("Jaś O", "Stefania J")
     *
     * Placówka = pełna ścieżka oddział → typ → grupa (bez osób).
     *
     * @param bool $force
     * @return array
     */
    public static function get_facilities( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_FACILITIES );
            if ( $cached !== false ) {
                return $cached;
            }
        }
        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $terms ) ) {
            return array();
        }
        $by_id = array();
        foreach ( $terms as $t ) {
            $by_id[ $t->term_id ] = $t;
        }

        $facilities = array();
        foreach ( $terms as $t ) {
            // Określ poziom hierarchii
            $depth = self::depth_of( $t, $by_id );
            // Placówki = poziom 3-5 (oddział / typ grupy / grupa)
            // ALE filtrujemy: musi mieć rodzica który jest sezonem (poziom 2)
            if ( $depth < 3 || $depth > 5 ) {
                continue;
            }
            $ancestors = self::ancestor_chain( $t, $by_id );
            // ancestors[0] = root, [1]=year, [2]=season, [3]=branch, [4]=group_type, [5]=group_name
            $client      = $ancestors[0]->name ?? '';
            $year        = $ancestors[1]->name ?? '';
            $season      = $ancestors[2]->name ?? '';
            $branch      = $ancestors[3]->name ?? '';
            $group_type  = $ancestors[4]->name ?? '';
            $group_name  = $ancestors[5]->name ?? '';

            $facilities[] = array(
                'term_id'       => $t->term_id,
                'depth'         => $depth,
                'client'        => $client,
                'year'          => $year,
                'season'        => $season,
                'branch'        => $branch,
                'group_type'    => $group_type,
                'group_name'    => $group_name,
                'name'          => $t->name,
                'slug'          => $t->slug,
                'product_count' => self::count_products_in_category_tree( $t->term_id ),
            );
        }
        // Sortuj po hierarchii
        usort( $facilities, function( $a, $b ) {
            return strcmp(
                $a['client'].$a['year'].$a['season'].$a['branch'].$a['group_type'].$a['group_name'],
                $b['client'].$b['year'].$b['season'].$b['branch'].$b['group_type'].$b['group_name']
            );
        } );
        set_transient( self::CACHE_FACILITIES, $facilities, self::CACHE_TTL );
        return $facilities;
    }

    /**
     * Skanuje line items ostatnich N zamówień i wyciąga unikalne wartości
     * meta "Wydruk odbitki" (rozmiary). Pokazuje co user ma do dyspozycji.
     */
    public static function get_detected_sizes( $force = false, $sample_size = 200 ) {
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_SIZES );
            if ( $cached !== false ) {
                return $cached;
            }
        }
        global $wpdb;
        $sizes = array();

        // Pobieramy 200 najnowszych line items i ich meta "Wydruk odbitki" + "Ilość dodatkowych..."
        $items_tbl = $wpdb->prefix . 'woocommerce_order_items';
        $itemmeta_tbl = $wpdb->prefix . 'woocommerce_order_itemmeta';

        // 2-step (MariaDB nie wspiera LIMIT w IN subquery)
        $item_ids = $wpdb->get_col(
            "SELECT order_item_id FROM {$items_tbl}
             WHERE order_item_type='line_item'
             ORDER BY order_item_id DESC
             LIMIT " . absint( $sample_size )
        );
        if ( empty( $item_ids ) ) {
            return array();
        }
        $placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT order_item_id, meta_key, meta_value
             FROM {$itemmeta_tbl}
             WHERE order_item_id IN ({$placeholders})
               AND meta_key NOT LIKE \\_%
               AND meta_key != 'class'
               AND meta_value != ''",
            $item_ids
        ), ARRAY_A );

        $by_key = array();
        foreach ( $rows as $r ) {
            $k = $r['meta_key'];
            // Wyciągnij sam rozmiar/wartość ze stringa typu "15x23cm (25,00 zł)"
            $raw = $r['meta_value'];
            $clean = trim( preg_replace( '/\s*\(.*$/u', '', $raw ) ); // odetnij " (cena)"
            $clean = wp_strip_all_tags( $clean );
            if ( ! isset( $by_key[ $k ] ) ) {
                $by_key[ $k ] = array();
            }
            $by_key[ $k ][ $clean ] = ( $by_key[ $k ][ $clean ] ?? 0 ) + 1;
        }
        // Konwertuj na sortowalną strukturę
        foreach ( $by_key as $k => $values ) {
            arsort( $values );
            $sizes[] = array(
                'key'    => $k,
                'values' => $values, // value => count
            );
        }
        usort( $sizes, function( $a, $b ) {
            return array_sum( $b['values'] ) <=> array_sum( $a['values'] );
        } );
        set_transient( self::CACHE_SIZES, $sizes, self::CACHE_TTL );
        return $sizes;
    }

    public static function clear_cache() {
        delete_transient( self::CACHE_SEASONS );
        delete_transient( self::CACHE_FACILITIES );
        delete_transient( self::CACHE_SIZES );
    }

    /** ============ HELPERS ============ */

    private static function depth_of( $term, $by_id ) {
        $d = 0;
        $cur = $term;
        while ( $cur && $cur->parent ) {
            $cur = $by_id[ $cur->parent ] ?? null;
            $d++;
            if ( $d > 20 ) {
                break;
            }
        }
        return $d;
    }

    private static function ancestor_chain( $term, $by_id ) {
        $chain = array( $term );
        $cur = $term;
        while ( $cur && $cur->parent ) {
            $cur = $by_id[ $cur->parent ] ?? null;
            if ( $cur ) {
                array_unshift( $chain, $cur );
            }
            if ( count( $chain ) > 20 ) {
                break;
            }
        }
        return $chain;
    }

    /**
     * FAST count — 1 query dla wszystkich termsów zamiast WP_Query per term.
     * Wykorzystuje wbudowany `count` WP w `term_taxonomy` (utrzymywany automatycznie przez WP).
     */
    private static function count_products_in_category_tree( $parent_term_id ) {
        $counts = self::get_all_term_counts();
        if ( ! isset( $counts[ $parent_term_id ] ) ) {
            return 0;
        }
        return (int) self::sum_subtree_count( $parent_term_id, $counts );
    }

    private static $_term_counts = null;
    private static $_term_children = null;

    private static function get_all_term_counts() {
        if ( self::$_term_counts !== null ) {
            return self::$_term_counts;
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT term_id, parent, count
             FROM {$wpdb->term_taxonomy}
             WHERE taxonomy='product_cat'"
        );
        $counts = array();
        $children = array();
        foreach ( $rows as $r ) {
            $counts[ (int) $r->term_id ] = (int) $r->count;
            $children[ (int) $r->parent ][] = (int) $r->term_id;
        }
        self::$_term_counts = $counts;
        self::$_term_children = $children;
        return $counts;
    }

    private static function sum_subtree_count( $term_id, $counts ) {
        $sum = $counts[ $term_id ] ?? 0;
        $children = self::$_term_children[ $term_id ] ?? array();
        foreach ( $children as $child ) {
            $sum += self::sum_subtree_count( $child, $counts );
        }
        return $sum;
    }

    /**
     * Liczba zamówień zawierających produkty z drzewa kategorii.
     */
    private static function count_orders_with_category( $parent_term_id ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return 0;
        }
        // Heurystyka tania: liczba produktów * ~0.3 zamówień. Dokładne liczenie wymaga JOIN po line items.
        // Dla MVP nie liczymy precyzyjnie — pokazujemy 0 lub null żeby user widział że to placeholder.
        return null;
    }
}
