<?php
/**
 * Role i capabilities PhotoJob Organizer
 *
 * Capabilities:
 *  - pjo_view_finance       — widzi kwoty, raporty księgowe
 *  - pjo_export_accounting  — pobiera Excel księgowy
 *  - pjo_manage_settings    — Settings page
 *  - pjo_manage_orders      — edycja zamówień (status, ilość, adres)
 *  - pjo_reception_check    — moduł przyjęcia odbitek
 *  - pjo_print_labels       — moduł etykiet
 *
 * Role:
 *  - pjo_worker — TYLKO reception_check + print_labels (bez kwot, maili, adresów w widoku reception)
 *  - administrator + shop_manager dostają wszystkie pjo_* capabilities
 *
 * @package PhotoJob_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PhotoJob_Roles {

    const WORKER_ROLE = 'pjo_worker';

    const ADMIN_CAPS = array(
        'pjo_view_finance',
        'pjo_export_accounting',
        'pjo_manage_settings',
        'pjo_manage_orders',
        'pjo_reception_check',
        'pjo_print_labels',
    );

    const WORKER_CAPS = array(
        'read'                 => true,
        'pjo_reception_check'  => true,
        'pjo_print_labels'     => true,
    );

    public static function install() {
        if ( ! get_role( self::WORKER_ROLE ) ) {
            add_role(
                self::WORKER_ROLE,
                __( 'Pracownik Foto', 'photojob-organizer' ),
                self::WORKER_CAPS
            );
        } else {
            $role = get_role( self::WORKER_ROLE );
            foreach ( self::WORKER_CAPS as $cap => $grant ) {
                if ( $grant ) {
                    $role->add_cap( $cap );
                }
            }
        }

        foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }
            foreach ( self::ADMIN_CAPS as $cap ) {
                $role->add_cap( $cap );
            }
        }
    }

    public static function uninstall() {
        remove_role( self::WORKER_ROLE );
        foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }
            foreach ( self::ADMIN_CAPS as $cap ) {
                $role->remove_cap( $cap );
            }
        }
    }

    public static function current_user_is_worker_only() {
        $user = wp_get_current_user();
        if ( ! $user || empty( $user->roles ) ) {
            return false;
        }
        if ( in_array( 'administrator', $user->roles, true ) ) {
            return false;
        }
        if ( in_array( 'shop_manager', $user->roles, true ) ) {
            return false;
        }
        return in_array( self::WORKER_ROLE, $user->roles, true );
    }
}
