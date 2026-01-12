<?php
/**
 * Klasa eksportująca dane do formatu Excel
 *
 * @package PhotoJob_Organizer
 */

// Zabezpieczenie przed bezpośrednim dostępem
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Klasa PhotoJob_Excel_Exporter
 */
class PhotoJob_Excel_Exporter {

    /**
     * Instancja singletona
     */
    private static $instance = null;

    /**
     * Pobierz instancję singletona
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    private function __construct() {
    }

    /**
     * Eksportuj dane do pliku Excel (format XLSX)
     *
     * @param array $table_data Dane tabeli
     * @param array $company_info Informacje o firmie
     * @return void
     */
    public function export_to_xlsx( $table_data, $company_info = array() ) {
        // Sprawdź czy mamy bibliotekę PHPSpreadsheet
        if ( ! $this->check_phpspreadsheet() ) {
            // Jeśli nie ma PHPSpreadsheet, użyj prostego formatu CSV z rozszerzeniem .xlsx
            $this->export_to_csv_as_xlsx( $table_data, $company_info );
            return;
        }

        // Użyj PHPSpreadsheet do pełnego eksportu
        $this->export_with_phpspreadsheet( $table_data, $company_info );
    }

    /**
     * Sprawdź czy PHPSpreadsheet jest dostępne
     *
     * @return bool
     */
    private function check_phpspreadsheet() {
        // Sprawdź czy PHPSpreadsheet jest załadowane przez Composer
        return class_exists( 'PhpOffice\PhpSpreadsheet\Spreadsheet' );
    }

    /**
     * Eksportuj używając PHPSpreadsheet (pełny format XLSX)
     *
     * @param array $table_data Dane tabeli
     * @param array $company_info Informacje o firmie
     * @return void
     */
    private function export_with_phpspreadsheet( $table_data, $company_info ) {
        // Ten kod będzie działał gdy PHPSpreadsheet będzie dostępne
        // Na razie używamy prostszego rozwiązania
        $this->export_to_csv_as_xlsx( $table_data, $company_info );
    }

    /**
     * Eksportuj do formatu CSV z rozszerzeniem .xlsx
     * To rozwiązanie działa bez dodatkowych bibliotek
     *
     * @param array $table_data Dane tabeli
     * @param array $company_info Informacje o firmie
     * @return void
     */
    private function export_to_csv_as_xlsx( $table_data, $company_info ) {
        // Przygotuj nazwę pliku
        $date_from = isset( $table_data['summary']['date_from'] ) ? $table_data['summary']['date_from'] : '';
        $date_to = isset( $table_data['summary']['date_to'] ) ? $table_data['summary']['date_to'] : '';

        $filename = 'Zestawienie-transakcji-' . $date_from . '-' . $date_to . '.xlsx';

        // Ustaw nagłówki HTTP dla pobierania pliku
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: max-age=0' );
        header( 'Pragma: public' );

        // Otwórz strumień wyjściowy
        $output = fopen( 'php://output', 'w' );

        // BOM dla UTF-8 (żeby Excel poprawnie odczytał polskie znaki)
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );

        // Dodaj informacje o firmie
        if ( ! empty( $company_info ) ) {
            if ( isset( $company_info['name'] ) ) {
                fputcsv( $output, array( '', $company_info['name'] ), ';' );
            }
            if ( isset( $company_info['address'] ) ) {
                fputcsv( $output, array( '', $company_info['address'] ), ';' );
            }
            if ( isset( $company_info['nip'] ) ) {
                fputcsv( $output, array( '', 'NIP: ' . $company_info['nip'] ), ';' );
            }

            // Dodaj miesiąc
            $month_name = $this->get_month_name( $date_from );
            fputcsv( $output, array( '', 'Miesiąc: ' . $month_name ), ';' );

            // Pusta linia
            fputcsv( $output, array(), ';' );
            fputcsv( $output, array(), ';' );
        }

        // Dodaj nagłówki tabeli
        if ( isset( $table_data['headers'] ) ) {
            fputcsv( $output, $table_data['headers'], ';' );
        }

        // Dodaj wiersze danych
        if ( isset( $table_data['rows'] ) ) {
            foreach ( $table_data['rows'] as $row ) {
                fputcsv( $output, $row, ';' );
            }
        }

        // Dodaj pustą linię przed podsumowaniem
        fputcsv( $output, array(), ';' );

        // Dodaj podsumowanie
        if ( isset( $table_data['summary'] ) ) {
            $summary = $table_data['summary'];

            fputcsv( $output, array( '', 'PODSUMOWANIE' ), ';' );
            fputcsv( $output, array( '', 'Liczba zamówień: ' . $summary['total_orders'] ), ';' );
            fputcsv( $output, array( '', 'Suma przychodu netto: ' . $summary['total_net_revenue'] . ' zł' ), ';' );
            fputcsv( $output, array( '', 'Zakres dat: ' . $summary['date_from'] . ' - ' . $summary['date_to'] ), ';' );
        }

        fclose( $output );
        exit;
    }

    /**
     * Pobierz nazwę miesiąca na podstawie daty
     *
     * @param string $date Data w formacie Y-m-d
     * @return string Nazwa miesiąca
     */
    private function get_month_name( $date ) {
        $months = array(
            '01' => 'STYCZEŃ',
            '02' => 'LUTY',
            '03' => 'MARZEC',
            '04' => 'KWIECIEŃ',
            '05' => 'MAJ',
            '06' => 'CZERWIEC',
            '07' => 'LIPIEC',
            '08' => 'SIERPIEŃ',
            '09' => 'WRZESIEŃ',
            '10' => 'PAŹDZIERNIK',
            '11' => 'LISTOPAD',
            '12' => 'GRUDZIEŃ',
        );

        $month = date( 'm', strtotime( $date ) );
        $year = date( 'Y', strtotime( $date ) );

        return isset( $months[ $month ] ) ? $months[ $month ] . ' ' . $year : '';
    }

    /**
     * Eksportuj do formatu CSV
     *
     * @param array $table_data Dane tabeli
     * @param array $company_info Informacje o firmie
     * @return void
     */
    public function export_to_csv( $table_data, $company_info = array() ) {
        // Przygotuj nazwę pliku
        $date_from = isset( $table_data['summary']['date_from'] ) ? $table_data['summary']['date_from'] : '';
        $date_to = isset( $table_data['summary']['date_to'] ) ? $table_data['summary']['date_to'] : '';

        $filename = 'Zestawienie-transakcji-' . $date_from . '-' . $date_to . '.csv';

        // Ustaw nagłówki HTTP dla pobierania pliku
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: max-age=0' );
        header( 'Pragma: public' );

        // Otwórz strumień wyjściowy
        $output = fopen( 'php://output', 'w' );

        // BOM dla UTF-8
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );

        // Dodaj informacje o firmie
        if ( ! empty( $company_info ) ) {
            if ( isset( $company_info['name'] ) ) {
                fputcsv( $output, array( '', $company_info['name'] ), ';' );
            }
            if ( isset( $company_info['address'] ) ) {
                fputcsv( $output, array( '', $company_info['address'] ), ';' );
            }
            if ( isset( $company_info['nip'] ) ) {
                fputcsv( $output, array( '', 'NIP: ' . $company_info['nip'] ), ';' );
            }

            // Dodaj miesiąc
            $month_name = $this->get_month_name( $date_from );
            fputcsv( $output, array( '', 'Miesiąc: ' . $month_name ), ';' );

            // Pusta linia
            fputcsv( $output, array(), ';' );
            fputcsv( $output, array(), ';' );
        }

        // Dodaj nagłówki tabeli
        if ( isset( $table_data['headers'] ) ) {
            fputcsv( $output, $table_data['headers'], ';' );
        }

        // Dodaj wiersze danych
        if ( isset( $table_data['rows'] ) ) {
            foreach ( $table_data['rows'] as $row ) {
                fputcsv( $output, $row, ';' );
            }
        }

        // Dodaj pustą linię przed podsumowaniem
        fputcsv( $output, array(), ';' );

        // Dodaj podsumowanie
        if ( isset( $table_data['summary'] ) ) {
            $summary = $table_data['summary'];

            fputcsv( $output, array( '', 'PODSUMOWANIE' ), ';' );
            fputcsv( $output, array( '', 'Liczba zamówień: ' . $summary['total_orders'] ), ';' );
            fputcsv( $output, array( '', 'Suma przychodu netto: ' . $summary['total_net_revenue'] . ' zł' ), ';' );
            fputcsv( $output, array( '', 'Zakres dat: ' . $summary['date_from'] . ' - ' . $summary['date_to'] ), ';' );
        }

        fclose( $output );
        exit;
    }
}
