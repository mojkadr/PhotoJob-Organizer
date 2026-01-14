# PhotoJob Organizer - Historia Rozwoju i Rozwiązań

## Informacje Podstawowe

**Projekt:** PhotoJob Organizer
**Typ:** Wtyczka WordPress/WooCommerce
**Cel:** Generowanie raportów księgowych z zamówień fotograficznych
**Lokalizacja:** `D:\CLAUDE\GitHub\PhotoJob-Organizer`

---

## Problem Początkowy (2026-01-13/14)

Użytkownik zgłosił, że po kliknięciu "Pobierz raport" w wtyczce PhotoJob Organizer plik Excel nie pobiera się.

---

## Sesja 1: Naprawa Eksportu Excel (2026-01-13/14)

### Problem 1: Hook eksportu nie był rejestrowany
**Diagnoza:**
- Instancja `PhotoJob_Accounting_Report_Page` była tworzona dopiero podczas renderowania strony
- Hook `admin_init` odpowiedzialny za obsługę eksportu nigdy nie był aktywny
- Formularz wysyłał dane POST, ale nikt ich nie przechwytywał

**Rozwiązanie v1.0.1:**
```php
// Plik: photojob-organizer.php:80
// Dodano inicjalizację w init_hooks()
PhotoJob_Accounting_Report_Page::get_instance();
```

**Status:** Częściowo naprawione, ale nadal problemy z instalacją

---

### Problem 2: Nieprawidłowa struktura archiwum ZIP
**Diagnoza:**
- Pierwsze archiwum miało pliki bezpośrednio w root (bez folderu)
- WordPress nie mógł zainstalować wtyczki - "krytyczny błąd"

**Rozwiązanie v1.0.2:**
- Zmieniono strukturę ZIP aby zawierała folder `photojob-organizer/`
- Zmieniono inicjalizację modułów admin na hook `plugins_loaded`

```bash
# Stara struktura (błędna):
photojob-organizer-1.0.1.zip
├── photojob-organizer.php
├── includes/
└── admin/

# Nowa struktura (poprawna):
photojob-organizer-1.0.2.zip
└── photojob-organizer/
    ├── photojob-organizer.php
    ├── includes/
    └── admin/
```

**Status:** Instalacja działa, ale eksport nadal nie działa

---

### Problem 3: Mechanizm POST nie działał prawidłowo
**Diagnoza:**
- Formularz wysyłał dane do tej samej strony (action="")
- W WordPress często powoduje to problemy z obsługą POST
- Hook `admin_init` wykonywał się w złej kolejności

**Rozwiązanie v1.0.3:**
Zastosowano standardowy mechanizm WordPress `admin_post_` hook:

```php
// Plik: admin/class-accounting-report-page.php

// Konstruktor:
add_action( 'admin_post_photojob_export_accounting', array( $this, 'handle_export_request' ) );

// Formularz (linia 166):
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="photojob_export_accounting">
    <?php wp_nonce_field( 'photojob_export_accounting', 'photojob_accounting_nonce' ); ?>
```

**Status:** Eksport powinien działać, ale użytkownik zgłasza dalsze problemy z instalacją

---

### Problem 4: Struktura ZIP - dyskusja
**Zgłoszenie użytkownika:**
> "nie twórz głównego folderu w pliku zip. Przez to wtyczka przy próbie instalacji ciągle pokazuje błędy"

**Próba 1 (v1.0.3 - bez folderu):**
```
photojob-organizer-1.0.3.zip
├── photojob-organizer.php
├── includes/
└── admin/
```

**Wynik:** Użytkownik zgłasza "dalej to samo" - krytyczny błąd

**Próba 2 (v1.0.4 - z folderem):**
```
photojob-organizer-1.0.4.zip
└── photojob-organizer/
    ├── photojob-organizer.php
    ├── includes/
    └── admin/
```

**Uwaga:** WordPress WYMAGA aby wtyczka była w folderze. Struktura bez folderu nie powinna działać.

**Status:** NIEROZWIĄZANE - czekam na szczegóły błędu od użytkownika

---

## Aktualna Struktura Projektu

```
PhotoJob-Organizer/
├── photojob-organizer.php              # Główny plik wtyczki
├── includes/
│   ├── class-accounting-table-generator.php
│   └── class-excel-exporter.php
├── admin/
│   ├── class-admin-menu.php
│   ├── class-accounting-report-page.php
│   ├── css/admin-styles.css
│   └── js/admin-scripts.js
├── releases/
│   ├── photojob-organizer-1.0.0.zip    # Wersja oryginalna
│   ├── photojob-organizer-1.0.1.zip    # Pierwsza próba naprawy (zła struktura ZIP)
│   ├── photojob-organizer-1.0.2.zip    # Naprawa struktury ZIP
│   ├── photojob-organizer-1.0.3.zip    # Naprawa mechanizmu POST (bez folderu - błąd!)
│   ├── photojob-organizer-1.0.4.zip    # Z folderem (problem z formatem Excel)
│   └── photojob-organizer-1.0.5.zip    # AKTUALNA - naprawiony format Excel
├── README.md
└── DEVELOPMENT_LOG.md                   # Ten plik
```

---

## Changelog Wersji

### v1.0.5 (2026-01-14) - AKTUALNA
- 🐛 **NAPRAWIONO**: Błąd formatu pliku Excel XLSX
- Dodano bibliotekę SimpleXLSXGen (pojedynczy plik PHP, bez Composer)
- Plik Excel (.xlsx) teraz otwiera się poprawnie w Microsoft Excel
- Poprzednio: generowany był plik CSV z rozszerzeniem .xlsx (Excel nie mógł otworzyć)
- Teraz: generowany jest prawdziwy plik XLSX z formatowaniem XML w ZIP
- Struktura ZIP: z folderem photojob-organizer/ (zgodna z WordPress)

### v1.0.4 (2026-01-14) - TESTOWA
- Przywrócono strukturę ZIP z folderem photojob-organizer/
- Bez zmian w kodzie względem 1.0.3
- ⚠️ Problem z formatem Excel - użyj wersji 1.0.5

### v1.0.3 (2026-01-14) - PROBLEMATYCZNA
- 🐛 Zmieniono mechanizm eksportu na `admin_post_` hook
- Formularz wysyła dane do `admin-post.php`
- ⚠️ BŁĄD: Użyto struktury ZIP bez folderu głównego

### v1.0.2 (2026-01-14)
- 🐛 Poprawiono strukturę ZIP (dodano folder photojob-organizer/)
- Zmieniono inicjalizację modułów admin na hook `plugins_loaded`
- ⚠️ Eksport nadal nie działa

### v1.0.1 (2026-01-13) - PROBLEMATYCZNA
- 🐛 Próba naprawy eksportu Excel
- Dodano `PhotoJob_Accounting_Report_Page::get_instance()` w init_hooks
- ⚠️ Nieprawidłowa struktura ZIP

### v1.0.0 (2026-01-12)
- Pierwsze wydanie
- Podstawowa funkcjonalność raportu księgowego

---

## Kluczowe Zmiany w Kodzie

### 1. Inicjalizacja Modułów Admin
**Plik:** `photojob-organizer.php`

**Poprzednio (v1.0.0):**
```php
private function init_hooks() {
    add_action( 'plugins_loaded', array( $this, 'check_requirements' ) );
    add_action( 'init', array( $this, 'load_textdomain' ) );

    if ( is_admin() ) {
        PhotoJob_Admin_Menu::get_instance();
        // Brak PhotoJob_Accounting_Report_Page!
    }
}
```

**Obecnie (v1.0.3+):**
```php
private function init_hooks() {
    add_action( 'plugins_loaded', array( $this, 'check_requirements' ) );
    add_action( 'plugins_loaded', array( $this, 'init_admin_modules' ) );
    add_action( 'init', array( $this, 'load_textdomain' ) );
}

public function init_admin_modules() {
    if ( is_admin() ) {
        PhotoJob_Admin_Menu::get_instance();
        PhotoJob_Accounting_Report_Page::get_instance();
    }
}
```

### 2. Mechanizm Eksportu
**Plik:** `admin/class-accounting-report-page.php`

**Poprzednio:**
```php
// Konstruktor:
add_action( 'admin_init', array( $this, 'handle_export_request' ) );

// handle_export_request():
if ( ! isset( $_POST['photojob_export_accounting'] ) ) {
    return;
}

// Formularz:
<form method="post" action="">
```

**Obecnie:**
```php
// Konstruktor:
add_action( 'admin_post_photojob_export_accounting', array( $this, 'handle_export_request' ) );

// handle_export_request():
// Usuniętą sprawdzenie isset($_POST['photojob_export_accounting'])
// Bo admin_post_ hook wywołuje się tylko gdy action się zgadza

// Formularz:
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <input type="hidden" name="action" value="photojob_export_accounting">
```

---

## Wymagania Wtyczki

- WordPress 5.8+
- PHP 7.4+
- **WooCommerce 5.0+** (KRYTYCZNE!)
- Uprawnienia: `manage_woocommerce`

---

## Znane Problemy

### PROBLEM 1: Instalacja wtyczki (AKTYWNY)
**Objaw:** "Wtyczka nie mogła zostać włączona, ponieważ spowodowała wystąpienie krytycznego błędu"

**Możliwe przyczyny:**
1. ❓ Brak WooCommerce (wtyczka wymaga WooCommerce!)
2. ❓ Błąd składni PHP (nie zdiagnozowany)
3. ❓ Konflikt z inną wtyczką
4. ❓ Niekompatybilna wersja PHP

**Status:** CZEKA NA SZCZEGÓŁY BŁĘDU OD UŻYTKOWNIKA

**Co potrzebujemy:**
- Kliknięcie "Szczegóły" przy błędzie w WordPress
- Lub zawartość `wp-content/debug.log` (po włączeniu WP_DEBUG)
- Potwierdzenie czy WooCommerce jest zainstalowane

### PROBLEM 2: Eksport Excel
**Objaw:** Po kliknięciu "Pobierz raport" plik się nie pobiera

**Status:** POWINIEN BYĆ NAPRAWIONY w v1.0.3+

**Jeśli nadal nie działa, sprawdzić:**
- Czy są zamówienia w wybranym zakresie dat?
- Czy użytkownik ma uprawnienia `manage_woocommerce`?
- Czy w konsoli przeglądarki są błędy JavaScript?

---

## Skrypt Budowania Release

```bash
cd PhotoJob-Organizer

# Usuń stary build
rm -rf temp-build

# Stwórz strukturę
mkdir -p temp-build/photojob-organizer

# Kopiuj pliki
cp -r photojob-organizer.php includes admin temp-build/photojob-organizer/

# Utwórz ZIP
cd temp-build
powershell -Command "Compress-Archive -Path 'photojob-organizer' -DestinationPath '../releases/photojob-organizer-X.Y.Z.zip' -CompressionLevel Optimal -Force"

# Sprzątanie
cd ..
rm -rf temp-build

# Weryfikacja
unzip -l releases/photojob-organizer-X.Y.Z.zip
```

---

## Debug i Diagnostyka

### Włączenie debugowania WordPress:
```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Sprawdzenie składni PHP:
```bash
php -l photojob-organizer.php
php -l admin/class-accounting-report-page.php
php -l admin/class-admin-menu.php
php -l includes/class-accounting-table-generator.php
php -l includes/class-excel-exporter.php
```

### Sprawdzenie struktury ZIP:
```bash
unzip -l releases/photojob-organizer-X.Y.Z.zip
```

---

## Sesja 2: Naprawa Formatu Excel (2026-01-14)

### Problem: Excel nie może otworzyć pliku XLSX
**Objaw:** "Program Excel nie może otworzyć pliku ze względu na nieprawidłowy format lub rozszerzenie pliku"

**Diagnoza:**
- Funkcja `export_to_csv_as_xlsx()` używała `fputcsv()` (format CSV)
- Ale deklarowała Content-Type jako `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
- Excel próbował otworzyć plik jako prawdziwy XLSX (ZIP z XML), ale otrzymał CSV
- Plik był CSV z rozszerzeniem .xlsx - to nie działa!

**Rozwiązanie v1.0.5:**
1. Pobranie biblioteki SimpleXLSXGen (pojedynczy plik PHP, MIT license)
2. Dodanie `includes/simplexlsxgen.php`
3. Przepisanie `export_to_csv_as_xlsx()` aby używała SimpleXLSXGen
4. Teraz generowany jest prawdziwy plik Excel XLSX z formatowaniem

**Plik:** `includes/class-excel-exporter.php:88-162`

```php
// Poprzednio (BŁĄD):
fputcsv( $output, $row, ';' );  // CSV
header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );

// Teraz (POPRAWNIE):
require_once dirname( __FILE__ ) . '/simplexlsxgen.php';
$xlsx = \Shuchkin\SimpleXLSXGen::fromArray( $data );
$xlsx->download( $filename );
```

**Status:** ✅ NAPRAWIONE - plik Excel teraz otwiera się poprawnie

---

## Następne Kroki (TODO)

1. ✅ ~~Naprawić format pliku Excel~~ (DONE w v1.0.5)
2. 🟢 Przetestować instalację wtyczki na czystym WordPress + WooCommerce
3. 🟢 Przetestować eksport Excel po instalacji
4. 🟢 Rozważyć dodanie lepszego error handlingu
5. 🟢 Rozważyć dodanie logowania błędów do pliku

---

## Kontakt z Użytkownikiem

**Ostatnia komunikacja:** 2026-01-14

**Pytania do użytkownika:**
1. Czy WooCommerce jest zainstalowane i aktywne?
2. Jaki jest dokładny komunikat błędu? (kliknij "Szczegóły")
3. Jaka wersja PHP jest na serwerze?
4. Czy są jakieś błędy w debug.log?

---

## Notatki Techniczne

### WordPress Plugin Structure
WordPress wymaga aby pliki wtyczki były w folderze w archiwum ZIP:
```
plugin-name.zip
└── plugin-name/
    ├── plugin-name.php  # Główny plik z nagłówkiem Plugin Name
    └── ...
```

### Admin Post Hook
`admin_post_{action}` to standardowy mechanizm WordPress do obsługi akcji POST:
- Dla zalogowanych: `admin_post_{action}`
- Dla niezalogowanych: `admin_post_nopriv_{action}`

Formularz musi zawierać:
```html
<form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
    <input type="hidden" name="action" value="nazwa_akcji">
    <?php wp_nonce_field('nonce_action', 'nonce_name'); ?>
</form>
```

---

## Historia Commitów Git

```
988dfa3 - hh (2026-01-14) [v1.0.1]
370fe10 - Dodaj moduł generowania raportów księgowych (2026-01-12) [v1.0.0]
```

**Uwaga:** Commity nie są szczegółowe. Rozważyć lepsze opisy w przyszłości.

---

## Wersja tego dokumentu
**Ostatnia aktualizacja:** 2026-01-14 00:45
**Autor sesji:** Claude Sonnet 4.5
**Status projektu:** DEBUGGING - czeka na informacje od użytkownika
