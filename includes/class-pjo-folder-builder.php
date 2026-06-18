<?php
/**
 * PhotoJob_Folder_Builder — silnik Fazy C.
 *
 * Zamienia zamówienie WooCommerce na PLAN DRUKU: strukturę folderów + przemianowane
 * nazwy plików wg konwencji klientki:
 *
 *   {Sezon}/{NrZam}/{Typ}/{Rozmiar}/{rozmiar}-{ilość}x_{nrZam}_{oryginalna_nazwa}
 *
 * Mapowanie (decyzje 2026-06-18):
 *  - oryginalna_nazwa = NAZWA PRODUKTU WC (= nazwa pliku zdjęcia; tak tworzy je photo-adder)
 *  - Typ              = KLUCZ meta WAPF (np. "Wydruk odbitki")
 *  - Rozmiar          = WARTOŚĆ meta WAPF (np. "15x23cm" → znormalizowane "15x23")
 *  - Sezon            = ancestor kategorii produktu (poziom sezonu) + rok jeśli jest
 *  - NrZam            = numer zamówienia WC
 *
 * Plik źródłowy dopasowywany do magazynu (/MójKadr/Sesje) po nazwie bez rozszerzenia —
 * z indeksu zbudowanego przez PhotoJob_QNAP_Client::index_source_files().
 *
 * @package PhotoJob_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PhotoJob_Folder_Builder {

    /** @var array cache term map product_cat (term_id => term) */
    private static $term_map = null;

    /**
     * Zbuduj plan druku dla zamówienia.
     *
     * @param int                     $order_id
     * @param PhotoJob_QNAP_Client|null $qnap  Jeśli podany → dry-run: dopasowuje pliki źródłowe.
     * @param string|null             $batch_number  Numer wydruku (26ZiNMW1) — root /Druk/{number}/...
     *                                              Null = podgląd bez numeru (nadawany przy execute).
     * @return array {
     *   order_no:string, season:string, build_root:string,
     *   items: array<array{...}>, warnings: string[]
     * }|WP_Error
     */
    public static function build_plan( $order_id, $qnap = null, $batch_number = null ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return new WP_Error( 'no_wc', __( 'WooCommerce nieaktywne.', 'photojob-organizer' ) );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'no_order', __( 'Nie znaleziono zamówienia.', 'photojob-organizer' ) );
        }

        $qopt = get_option( 'pjo_settings_qnap', array() );
        $build_root = isset( $qopt['print_build_path'] ) && $qopt['print_build_path'] !== ''
            ? '/' . trim( $qopt['print_build_path'], '/' )
            : '/MójKadr/Druk';
        // #4: numer wydruku staje się głównym folderem paczki na QNAP.
        if ( $batch_number ) {
            $build_root .= '/' . self::fs_safe( $batch_number );
        }
        $source_root = isset( $qopt['source_path'] ) && $qopt['source_path'] !== ''
            ? '/' . trim( $qopt['source_path'], '/' )
            : '/MójKadr/Sesje';

        $order_no = $order->get_order_number();
        $order_no = self::fs_safe( $order_no );

        // Indeks plików źródłowych (jeden raz na cały plan) — tylko gdy dry-run / execute.
        $source_index = null;
        $index_error = '';
        if ( $qnap instanceof PhotoJob_QNAP_Client ) {
            $source_index = $qnap->index_source_files( $source_root );
            if ( $source_index === array() && $qnap->last_error ) {
                $index_error = $qnap->last_error;
            }
        }

        $items = array();
        $warnings = array();
        $season_votes = array();

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            $source_name = trim( $item->get_name() );
            $qty = max( 1, (int) $item->get_quantity() );

            // Typ + Rozmiar z meta WAPF (wartość = rozmiar typu "15x23cm")
            list( $type, $size_raw ) = self::extract_type_and_size( $item );
            $size_norm = self::normalize_size( $size_raw );

            // Sezon z kategorii produktu
            $season_info = $product ? self::resolve_season_for_product( $product->get_id() ) : array( 'season' => '', 'year' => '' );
            $season_label = self::season_folder( $season_info );
            if ( $season_label !== '' ) {
                $season_votes[ $season_label ] = ( $season_votes[ $season_label ] ?? 0 ) + 1;
            }

            $row = array(
                'item_id'     => $item_id,
                'source_name' => $source_name,
                'qty'         => $qty,
                'type'        => $type,
                'size_raw'    => $size_raw,
                'size_norm'   => $size_norm,
                'season'      => $season_label,
                'skip'        => false,
                'reason'      => '',
                'source_found'=> null,
                'source_dir'  => '',
                'source_file' => '',
                'ext'         => '',
            );

            // Walidacja: bez rozmiaru nie zbudujemy nazwy zgodnej z konwencją.
            if ( $size_norm === '' ) {
                $row['skip'] = true;
                $row['reason'] = __( 'Brak rozmiaru (meta WAPF) — pozycja pominięta.', 'photojob-organizer' );
                $items[] = $row;
                continue;
            }
            if ( $source_name === '' ) {
                $row['skip'] = true;
                $row['reason'] = __( 'Brak nazwy produktu/pliku.', 'photojob-organizer' );
                $items[] = $row;
                continue;
            }

            // Ścieżka docelowa (relatywna do build_root)
            $type_folder = $type !== '' ? self::fs_safe( $type ) : __( 'Inne', 'photojob-organizer' );
            $rel_dir = trim( $season_label, '/' ) . '/' . $order_no . '/' . $type_folder . '/' . self::fs_safe( $size_norm );
            $rel_dir = preg_replace( '#/+#', '/', $rel_dir );

            $base_target = sprintf( '%s-%dx_%s_%s', $size_norm, $qty, $order_no, self::fs_safe( $source_name ) );

            $row['target_dir'] = $rel_dir;
            $row['target_dir_full'] = $build_root . '/' . $rel_dir;
            $row['target_base'] = $base_target; // bez rozszerzenia

            // Dry-run: dopasuj plik źródłowy
            if ( is_array( $source_index ) ) {
                $key = mb_strtolower( PhotoJob_QNAP_Client::strip_ext( $source_name ) );
                if ( isset( $source_index[ $key ] ) ) {
                    $row['source_found'] = true;
                    $row['source_dir']  = $source_index[ $key ]['dir'];
                    $row['source_file'] = $source_index[ $key ]['file'];
                    $row['ext'] = PhotoJob_QNAP_Client::get_ext( $source_index[ $key ]['file'] );
                } else {
                    $row['source_found'] = false;
                    $row['reason'] = __( 'Plik nie znaleziony w magazynie źródłowym.', 'photojob-organizer' );
                }
            }
            $ext = $row['ext'] !== '' ? '.' . $row['ext'] : '';
            $row['target_filename'] = $base_target . $ext;
            $row['target_full'] = $row['target_dir_full'] . '/' . $row['target_filename'];

            $items[] = $row;
        }

        // Sezon na poziomie zamówienia = najczęstszy
        arsort( $season_votes );
        $season = key( $season_votes );
        if ( ! $season ) {
            $season = '';
            $warnings[] = __( 'Nie udało się wykryć sezonu z kategorii produktów — sprawdź hierarchię product_cat.', 'photojob-organizer' );
        }
        if ( $index_error ) {
            $warnings[] = sprintf( __( 'Indeksowanie magazynu źródłowego: %s', 'photojob-organizer' ), $index_error );
        }

        return array(
            'order_id'     => $order_id,
            'order_no'     => $order_no,
            'season'       => $season,
            'build_root'   => $build_root,
            'source_root'  => $source_root,
            'batch_number' => $batch_number,
            'items'        => $items,
            'warnings'     => $warnings,
            'indexed'      => is_array( $source_index ) ? count( $source_index ) : null,
        );
    }

    /**
     * Kontekst zamówienia do numeracji wydruku: dominujący klient (root kategorii),
     * sezon i rok z line items.
     *
     * @return array [client, season, year]
     */
    public static function resolve_order_context( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return array( 'client' => '', 'season' => '', 'year' => '' );
        }
        $client_votes = array();
        $season_votes = array();
        $year_votes = array();
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }
            $info = self::resolve_season_for_product( $product->get_id() );
            if ( ! empty( $info['season'] ) ) {
                $season_votes[ $info['season'] ] = ( $season_votes[ $info['season'] ] ?? 0 ) + 1;
            }
            if ( ! empty( $info['year'] ) ) {
                $year_votes[ $info['year'] ] = ( $year_votes[ $info['year'] ] ?? 0 ) + 1;
            }
            $client = self::resolve_client_for_product( $product->get_id() );
            if ( $client !== '' ) {
                $client_votes[ $client ] = ( $client_votes[ $client ] ?? 0 ) + 1;
            }
        }
        arsort( $client_votes );
        arsort( $season_votes );
        arsort( $year_votes );
        return array(
            'client' => (string) key( $client_votes ),
            'season' => (string) key( $season_votes ),
            'year'   => (string) key( $year_votes ),
        );
    }

    /**
     * Klient = root (najwyższy przodek) kategorii produktu.
     */
    private static function resolve_client_for_product( $product_id ) {
        $terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return '';
        }
        $map = self::get_term_map();
        foreach ( $terms as $t ) {
            $cur = $t;
            $guard = 0;
            while ( $cur && $cur->parent && isset( $map[ $cur->parent ] ) && $guard < 20 ) {
                $cur = $map[ $cur->parent ];
                $guard++;
            }
            if ( $cur ) {
                return $cur->name;
            }
        }
        return '';
    }

    /**
     * Wykonaj plan na QNAP: nadaj numer wydruku, utwórz foldery, skopiuj i przemianuj pliki.
     * Buduje CAŁĄ grupę pakowania (jeśli zamówienie do niej należy) pod jednym numerem.
     *
     * @return array {batch_number, copied, created_dirs, errors, base_path, orders, results}
     */
    public static function execute_plan( $order_id, PhotoJob_QNAP_Client $qnap ) {
        // #3: jeśli zamówienie jest w grupie pakowania → budujemy wszystkich członków razem.
        $member_ids = self::pack_group_members( $order_id );

        // #4: numer wydruku z kontekstu (dominujący klient/sezon/rok grupy).
        $ctx = self::resolve_order_context( $member_ids[0] );
        $num = PhotoJob_Print_Batch::generate_number( $ctx['client'], $ctx['season'], $ctx['year'] );
        $batch_number = $num['number'];

        $results = array();
        $copied = 0;
        $errors = array();
        $made_dirs = array();
        $build_root = '';
        $total_files = 0;

        foreach ( $member_ids as $mid ) {
            $plan = self::build_plan( $mid, $qnap, $batch_number );
            if ( is_wp_error( $plan ) ) {
                $results[] = array( 'name' => '#' . $mid, 'status' => 'error', 'msg' => $plan->get_error_message() );
                $errors[] = '#' . $mid;
                continue;
            }
            $build_root = $plan['build_root'];

            foreach ( $plan['items'] as $row ) {
                if ( ! empty( $row['skip'] ) ) {
                    $results[] = array( 'name' => $row['source_name'], 'status' => 'skip', 'msg' => $row['reason'], 'order' => $plan['order_no'] );
                    continue;
                }
                if ( $row['source_found'] !== true ) {
                    $results[] = array( 'name' => $row['source_name'], 'status' => 'missing', 'msg' => $row['reason'], 'order' => $plan['order_no'] );
                    $errors[] = $row['source_name'];
                    continue;
                }
                $dest_dir = $row['target_dir_full'];
                if ( ! isset( $made_dirs[ $dest_dir ] ) ) {
                    if ( ! $qnap->make_path( $dest_dir ) ) {
                        $results[] = array( 'name' => $row['source_name'], 'status' => 'error', 'msg' => __( 'Folder: ', 'photojob-organizer' ) . $qnap->last_error, 'order' => $plan['order_no'] );
                        $errors[] = $row['source_name'];
                        continue;
                    }
                    $made_dirs[ $dest_dir ] = true;
                }
                if ( ! $qnap->copy_file( $row['source_dir'], $row['source_file'], $dest_dir, true ) ) {
                    $results[] = array( 'name' => $row['source_name'], 'status' => 'error', 'msg' => __( 'Kopia: ', 'photojob-organizer' ) . $qnap->last_error, 'order' => $plan['order_no'] );
                    $errors[] = $row['source_name'];
                    continue;
                }
                if ( ! $qnap->rename_file( $dest_dir, $row['source_file'], $row['target_filename'] ) ) {
                    $results[] = array( 'name' => $row['source_name'], 'status' => 'error', 'msg' => __( 'Rename: ', 'photojob-organizer' ) . $qnap->last_error, 'order' => $plan['order_no'] );
                    $errors[] = $row['source_name'];
                    continue;
                }
                $copied++;
                $total_files++;
                $results[] = array( 'name' => $row['source_name'], 'status' => 'ok', 'msg' => $row['target_full'], 'order' => $plan['order_no'] );
            }

            // Zapisz ścieżkę + stempel numeru wydruku przy każdym zamówieniu.
            $order_base = $build_root . '/' . trim( $plan['season'], '/' ) . '/' . $plan['order_no'];
            self::save_order_folder_path( $mid, $order_base );
            self::stamp_print_batch( $mid, $batch_number );
        }

        // Rekord wydruku (jeśli cokolwiek skopiowano albo żeby zarezerwować numer).
        if ( $copied > 0 ) {
            PhotoJob_Print_Batch::save( array(
                'number'          => $batch_number,
                'client_initials' => $num['client_initials'],
                'season_letter'   => $num['season_letter'],
                'year2'           => $num['year2'],
                'qnap_path'       => $build_root,
                'order_ids'       => $member_ids,
                'file_count'      => $total_files,
                'status'          => empty( $errors ) ? 'built' : 'partial',
            ) );
        }

        return array(
            'batch_number' => $batch_number,
            'copied'       => $copied,
            'created_dirs' => count( $made_dirs ),
            'errors'       => $errors,
            'base_path'    => $build_root,
            'orders'       => $member_ids,
            'results'      => $results,
        );
    }

    /**
     * Członkowie grupy pakowania zamówienia — albo grupa, albo samo zamówienie.
     *
     * @return int[]
     */
    public static function pack_group_members( $order_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_order_meta';
        $group = $wpdb->get_var( $wpdb->prepare( "SELECT pack_group FROM {$table} WHERE order_id=%d", $order_id ) );
        if ( $group && class_exists( 'PhotoJob_Duplicates' ) ) {
            $members = PhotoJob_Duplicates::group_members( $group );
            if ( ! empty( $members ) ) {
                return $members;
            }
        }
        return array( (int) $order_id );
    }

    private static function stamp_print_batch( $order_id, $number ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_order_meta';
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE order_id=%d", $order_id ) );
        if ( $exists ) {
            $wpdb->update( $table, array( 'print_batch' => $number ), array( 'order_id' => $order_id ) );
        } else {
            $wpdb->insert( $table, array( 'order_id' => $order_id, 'print_batch' => $number ) );
        }
    }

    /**
     * Zapisz bazową ścieżkę folderu druku w pjo_order_meta.qnap_folder_path (upsert).
     */
    private static function save_order_folder_path( $order_id, $path ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pjo_order_meta';
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE order_id=%d", $order_id ) );
        if ( $existing ) {
            $wpdb->update( $table, array( 'qnap_folder_path' => $path ), array( 'order_id' => $order_id ) );
        } else {
            $wpdb->insert( $table, array( 'order_id' => $order_id, 'qnap_folder_path' => $path ) );
        }
    }

    /** ============ PARSING ============ */

    /**
     * Wyciągnij Typ (klucz meta) + Rozmiar (wartość) z meta WAPF line itemu.
     * Bierzemy meta, której WARTOŚĆ wygląda jak rozmiar (\d+ x \d+).
     *
     * @return array [type, size_raw]
     */
    private static function extract_type_and_size( $item ) {
        $fallback_type = '';
        foreach ( $item->get_meta_data() as $meta ) {
            $k = $meta->key;
            if ( $k === '' || substr( $k, 0, 1 ) === '_' ) {
                continue;
            }
            $v = is_string( $meta->value ) ? $meta->value : maybe_serialize( $meta->value );
            $v_clean = trim( preg_replace( '/\s*\(.*$/u', '', wp_strip_all_tags( $v ) ) );
            if ( $v_clean === '' ) {
                continue;
            }
            if ( $fallback_type === '' ) {
                $fallback_type = $k;
            }
            if ( preg_match( '/\d+\s*[x×]\s*\d+/u', $v_clean ) ) {
                return array( trim( $k ), $v_clean );
            }
        }
        // Brak meta-rozmiaru — zwróć typ fallback bez rozmiaru.
        return array( $fallback_type, '' );
    }

    /**
     * "15x23cm" → "15x23" ; "15 x 23 cm" → "15x23".
     */
    public static function normalize_size( $size_raw ) {
        if ( $size_raw === '' ) {
            return '';
        }
        if ( preg_match( '/(\d+)\s*[x×]\s*(\d+)/u', $size_raw, $m ) ) {
            return $m[1] . 'x' . $m[2];
        }
        return self::fs_safe( $size_raw );
    }

    /**
     * Sezon kategorii produktu: znajdź w łańcuchu przodków term-sezon
     * (nazwa zawiera wiosenn/zimow/letni/jesienn) + rok (parent 4-cyfrowy).
     *
     * @return array [season=>string, year=>string]
     */
    private static function resolve_season_for_product( $product_id ) {
        $terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array( 'season' => '', 'year' => '' );
        }
        $map = self::get_term_map();
        foreach ( $terms as $t ) {
            // Idź w górę aż znajdziesz sezon
            $cur = $t;
            $guard = 0;
            while ( $cur && $guard < 20 ) {
                if ( preg_match( '/(wiosenn|zimow|letni|jesienn)/iu', $cur->name ) ) {
                    $year = '';
                    $parent = $map[ $cur->parent ] ?? null;
                    if ( $parent && preg_match( '/^\d{4}$/', $parent->name ) ) {
                        $year = $parent->name;
                    }
                    return array( 'season' => $cur->name, 'year' => $year );
                }
                $cur = isset( $map[ $cur->parent ] ) ? $map[ $cur->parent ] : null;
                $guard++;
            }
        }
        return array( 'season' => '', 'year' => '' );
    }

    private static function get_term_map() {
        if ( self::$term_map !== null ) {
            return self::$term_map;
        }
        $all = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
        $map = array();
        if ( ! is_wp_error( $all ) ) {
            foreach ( $all as $t ) {
                $map[ $t->term_id ] = $t;
            }
        }
        self::$term_map = $map;
        return $map;
    }

    /**
     * Nazwa folderu sezonu: "2025 Zimowa" → "2025-Zimowa". Bez roku → samo "Zimowa".
     */
    private static function season_folder( $info ) {
        $season = trim( $info['season'] ?? '' );
        $year = trim( $info['year'] ?? '' );
        if ( $season === '' ) {
            return '';
        }
        $label = $year !== '' ? $year . '-' . $season : $season;
        return self::fs_safe( $label );
    }

    /**
     * Bezpieczna nazwa na system plików: usuń / \ : * ? " < > | i znaki kontrolne,
     * zwiń spacje. Zachowuje polskie znaki (QNAP/ext4 = UTF-8).
     */
    public static function fs_safe( $s ) {
        $s = (string) $s;
        $s = preg_replace( '#[\\\\/:\*\?"<>\|]+#u', '-', $s );
        $s = preg_replace( '/[\x00-\x1F\x7F]+/u', '', $s );
        $s = preg_replace( '/\s+/u', ' ', $s );
        return trim( $s, " -\t\n" );
    }
}
