<?php
/**
 * Klasa generująca tabelę księgową z zamówień WooCommerce
 *
 * @package PhotoJob_Organizer
 */

// Zabezpieczenie przed bezpośrednim dostępem
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Klasa PhotoJob_Accounting_Table_Generator
 */
class PhotoJob_Accounting_Table_Generator {

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
     * Pobierz zamówienia dla zakresu dat
     *
     * @param string $date_from Data początkowa (format: Y-m-d)
     * @param string $date_to Data końcowa (format: Y-m-d)
     * @param array $statuses Statusy zamówień do uwzględnienia
     * @return array Tablica zamówień
     */
    public function get_orders( $date_from, $date_to = null, $statuses = array( 'completed', 'processing' ) ) {
        // Jeśli nie podano daty końcowej, użyj dzisiejszej daty
        if ( null === $date_to ) {
            $date_to = current_time( 'Y-m-d' );
        }

        // Przygotuj argumenty zapytania
        $args = array(
            'limit'          => -1,
            'date_created'   => $date_from . '...' . $date_to,
            'status'         => $statuses,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'return'         => 'ids',
        );

        // Pobierz zamówienia
        $order_ids = wc_get_orders( $args );
        $orders_data = array();

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                continue;
            }

            // Pobierz dane zamówienia
            $order_data = $this->extract_order_data( $order );

            if ( $order_data ) {
                $orders_data[] = $order_data;
            }
        }

        return $orders_data;
    }

    /**
     * Wyciągnij dane zamówienia
     *
     * @param WC_Order $order Obiekt zamówienia
     * @return array|null Dane zamówienia
     */
    private function extract_order_data( $order ) {
        if ( ! $order ) {
            return null;
        }

        // Data zakupu
        $date_created = $order->get_date_created();
        $date_purchase = $date_created ? $date_created->date( 'Y-m-d' ) : '';

        // Numer zamówienia
        $order_number = $order->get_order_number();

        // Status zamówienia
        $status = $this->get_order_status_label( $order->get_status() );

        // Klient
        $customer_name = $this->get_customer_name( $order );

        // Przychód netto
        $revenue_net = $this->calculate_net_revenue( $order );

        // Sposób płatności
        $payment_method = $this->get_payment_method_label( $order );

        return array(
            'date_purchase'   => $date_purchase,
            'order_number'    => $order_number,
            'status'          => $status,
            'customer'        => $customer_name,
            'revenue_net'     => $revenue_net,
            'payment_method'  => $payment_method,
        );
    }

    /**
     * Pobierz etykietę statusu zamówienia
     *
     * @param string $status Kod statusu
     * @return string Etykieta statusu
     */
    private function get_order_status_label( $status ) {
        $statuses = array(
            'pending'    => 'Oczekujące',
            'processing' => 'W trakcie realizacji',
            'on-hold'    => 'Wstrzymane',
            'completed'  => 'Zrealizowane',
            'cancelled'  => 'Anulowane',
            'refunded'   => 'Zwrócone',
            'failed'     => 'Niepowodzenie',
        );

        return isset( $statuses[ $status ] ) ? $statuses[ $status ] : ucfirst( $status );
    }

    /**
     * Pobierz imię i nazwisko klienta
     *
     * @param WC_Order $order Obiekt zamówienia
     * @return string Imię i nazwisko
     */
    private function get_customer_name( $order ) {
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();

        $customer_name = trim( $first_name . ' ' . $last_name );

        // Jeśli nie ma imienia i nazwiska, użyj adresu email
        if ( empty( $customer_name ) ) {
            $customer_name = $order->get_billing_email();
        }

        return $customer_name;
    }

    /**
     * Oblicz przychód netto
     *
     * @param WC_Order $order Obiekt zamówienia
     * @return float Przychód netto
     */
    private function calculate_net_revenue( $order ) {
        // Suma zamówienia bez podatku
        $total = $order->get_total();
        $total_tax = $order->get_total_tax();

        $net_revenue = $total - $total_tax;

        return round( $net_revenue, 2 );
    }

    /**
     * Pobierz etykietę metody płatności
     *
     * @param WC_Order $order Obiekt zamówienia
     * @return string Etykieta metody płatności
     */
    private function get_payment_method_label( $order ) {
        $payment_method = $order->get_payment_method();
        $payment_method_title = $order->get_payment_method_title();

        // Mapowanie popularnych metod płatności
        $payment_methods = array(
            'bacs'   => 'przelew',
            'cod'    => 'gotówka przy odbiorze',
            'cheque' => 'czek',
            'paypal' => 'PayPal',
            'stripe' => 'karta kredytowa',
        );

        // Jeśli mamy mapowanie, użyj go
        if ( isset( $payment_methods[ $payment_method ] ) ) {
            return $payment_methods[ $payment_method ];
        }

        // W przeciwnym razie użyj tytułu metody płatności
        return ! empty( $payment_method_title ) ? $payment_method_title : $payment_method;
    }

    /**
     * Generuj dane tabeli dla zakresu dat
     *
     * @param string $date_from Data początkowa (format: Y-m-d)
     * @param string $date_to Data końcowa (format: Y-m-d)
     * @return array Dane tabeli
     */
    public function generate_table_data( $date_from, $date_to = null ) {
        // Pobierz zamówienia
        $orders = $this->get_orders( $date_from, $date_to );

        // Przygotuj dane tabeli
        $table_data = array(
            'headers' => array(
                'Data zakupu',
                'Nr zam.',
                'Status',
                'Klient',
                'Przychód netto [zł]',
                'Sposób płatności',
            ),
            'rows' => array(),
            'summary' => array(),
        );

        $total_net_revenue = 0;

        foreach ( $orders as $order_data ) {
            $table_data['rows'][] = array(
                $order_data['date_purchase'],
                $order_data['order_number'],
                $order_data['status'],
                $order_data['customer'],
                number_format( $order_data['revenue_net'], 2, ',', ' ' ),
                $order_data['payment_method'],
            );

            $total_net_revenue += $order_data['revenue_net'];
        }

        // Dodaj podsumowanie
        $table_data['summary'] = array(
            'total_orders'      => count( $orders ),
            'total_net_revenue' => number_format( $total_net_revenue, 2, ',', ' ' ),
            'date_from'         => $date_from,
            'date_to'           => $date_to ? $date_to : current_time( 'Y-m-d' ),
        );

        return $table_data;
    }
}
