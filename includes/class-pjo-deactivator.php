<?php
/**
 * Deaktywacja wtyczki
 *
 * Tabele bazy i ustawienia zostawiamy — pełen cleanup jest w uninstall.php
 * (gdyby kiedyś powstał). Tu tylko flush rewrite.
 *
 * @package PhotoJob_Organizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PhotoJob_Deactivator {

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
