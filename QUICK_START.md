# PhotoJob Organizer - Szybki Start

## Aktualna Sytuacja (2026-01-14)

### Status
✅ **NAPRAWIONE** - Błąd formatu pliku Excel został naprawiony

### Aktualna Wersja
**v1.0.5** (stabilna) - `releases/photojob-organizer-1.0.5.zip`

### Co zostało naprawione
- ✅ Plik Excel (.xlsx) teraz otwiera się poprawnie w Microsoft Excel
- ✅ Dodano prawdziwe generowanie XLSX zamiast CSV z rozszerzeniem .xlsx
- ✅ Użyto biblioteki SimpleXLSXGen (pojedynczy plik PHP, bez Composer)

---

## Instalacja dla Użytkownika

1. **Sprawdź wymagania:**
   - WordPress 5.8+
   - PHP 7.4+
   - **WooCommerce 5.0+** ← KRYTYCZNE!

2. **Pobierz wtyczkę:**
   - Użyj: `releases/photojob-organizer-1.0.5.zip`

3. **Zainstaluj:**
   - WordPress Admin → Wtyczki → Dodaj nową → Prześlij wtyczkę
   - Wybierz plik ZIP
   - Kliknij "Zainstaluj teraz"
   - Aktywuj wtyczkę

4. **Użycie:**
   - Menu: PhotoJob → Raport księgowy
   - Wybierz daty
   - Kliknij "Pobierz raport"

---

## Szybkie Debugowanie

### 1. Włącz debug w WordPress
```php
// wp-config.php (przed "That's all, stop editing!")
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### 2. Sprawdź log
```
wp-content/debug.log
```

### 3. Sprawdź czy WooCommerce działa
WordPress Admin → WooCommerce → Ustawienia

---

## Budowanie Nowego Release

```bash
cd PhotoJob-Organizer

# 1. Zmień wersję w plikach:
# - photojob-organizer.php (linia 6 i 25)
# - README.md (changelog)

# 2. Zbuduj ZIP:
mkdir -p temp-build/photojob-organizer
cp -r photojob-organizer.php includes admin temp-build/photojob-organizer/
cd temp-build
powershell -Command "Compress-Archive -Path 'photojob-organizer' -DestinationPath '../releases/photojob-organizer-X.Y.Z.zip' -Force"
cd ..
rm -rf temp-build

# 3. Weryfikuj:
unzip -l releases/photojob-organizer-X.Y.Z.zip
```

---

## Kluczowe Pliki

| Plik | Rola |
|------|------|
| `photojob-organizer.php` | Główny plik wtyczki, inicjalizacja |
| `admin/class-accounting-report-page.php` | Strona raportu + obsługa eksportu |
| `admin/class-admin-menu.php` | Menu administracyjne |
| `includes/class-accounting-table-generator.php` | Generowanie danych tabeli |
| `includes/class-excel-exporter.php` | Eksport do Excel/CSV |

---

## Ostatnie Zmiany (v1.0.5)

✅ Naprawiono format pliku Excel XLSX
✅ Dodano bibliotekę SimpleXLSXGen
✅ Plik Excel teraz otwiera się poprawnie
✅ Prawdziwy format XLSX zamiast CSV z rozszerzeniem .xlsx

---

## Więcej Informacji

Zobacz: **DEVELOPMENT_LOG.md** - pełna historia projektu i wszystkie szczegóły
