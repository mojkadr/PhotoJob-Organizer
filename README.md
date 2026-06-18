# PhotoJob Organizer

**Wersja:** 1.1.0 (Faza A: Fundament rozszerzenia) — czerwiec 2026

Wtyczka WordPress do organizacji zamówień fotograficznych, kompletacji zdjęć w struktury per zamówienie i generowania raportów księgowych. Zaprojektowana dla studia fotograficznego sprzedającego odbitki przez WooCommerce.

## Roadmapa modułów

| Faza | Status | Zawartość |
|------|--------|-----------|
| **A** | ✅ v1.1.0 | Fundament: tabele DB, role (admin/pracownik), Settings page (7 zakładek) |
| **B** | ⏳ | Dashboard zamówień (14 kolumn, filtry sezon/rok/status, inline edit) |
| **C** | ⏳ | Folder Builder QNAP (auto-kopiowanie z magazynu sesji wg konwencji `{Sezon}/{NrZam}/{Typ}/{Rozmiar}/`) |
| **D** | ⏳ | Reception Module + Etykiety na koperty (PDF arkusze Avery A4) |
| **E** | ✅ v1.0+ | Eksport XLSX księgowy |
| **F** | ⏳ | Print Export (ZIP+CSV dla nphoto; opcjonalnie browser bot) |

## Role

- **Administrator / Shop Manager** — pełen dostęp (finanse, raporty, ustawienia, zarządzanie zamówieniami)
- **Pracownik Foto** (`pjo_worker`) — TYLKO przyjęcie odbitek (sprawdzanie zawartości paczek) i drukowanie etykiet. **Bez dostępu do kwot, maili i adresów klientów** (poza modułem etykiet, gdzie adres jest potrzebny).

## Opis

PhotoJob Organizer to narzędzie stworzone specjalnie dla fotografów prowadzących sklep internetowy na WooCommerce. Wtyczka automatycznie generuje zestawienia transakcji w formacie Excel lub CSV, gotowe do przekazania księgowej.

## Funkcje

### Raport księgowy

Generuj profesjonalne zestawienia transakcji zawierające:
- **Data zakupu** - data złożenia zamówienia
- **Nr zam.** - numer zamówienia w systemie
- **Status** - aktualny status zamówienia (Zrealizowane, W trakcie realizacji, itp.)
- **Klient** - imię i nazwisko klienta
- **Przychód netto [zł]** - wartość zamówienia bez podatku VAT
- **Sposób płatności** - metoda płatności użyta w zamówieniu

### Eksport do Excel/CSV

- Eksport do formatu XLSX (Excel)
- Eksport do formatu CSV
- Automatyczne formatowanie zgodne z wzorem księgowym
- Dodawanie informacji o firmie (nazwa, adres, NIP)
- Podsumowanie z sumą przychodów i liczbą zamówień

## Wymagania

- WordPress 5.8 lub nowszy
- PHP 7.4 lub nowszy
- WooCommerce 5.0 lub nowszy

## Instalacja

1. Pobierz pliki wtyczki
2. Przenieś folder `PhotoJob-Organizer` do katalogu `/wp-content/plugins/`
3. Aktywuj wtyczkę w panelu administracyjnym WordPress w menu "Wtyczki"
4. Przejdź do menu "PhotoJob" → "Raport księgowy"

## Użycie

### Generowanie raportu księgowego

1. W panelu administracyjnym WordPress przejdź do **PhotoJob** → **Raport księgowy**
2. Wybierz **datę początkową** (domyślnie: 01.12.2025)
3. Wybierz **datę końcową** (domyślnie: dzisiejsza data)
4. Wybierz **format eksportu** (Excel lub CSV)
5. Kliknij **"Pobierz raport"**

Plik zostanie automatycznie pobrany na Twój komputer.

### Szybkie przyciski zakresu dat

Na stronie raportu dostępne są szybkie przyciski do wyboru popularnych zakresów dat:
- **Bieżący miesiąc** - od 1. do ostatniego dnia bieżącego miesiąca
- **Poprzedni miesiąc** - cały poprzedni miesiąc
- **Grudzień 2025** - od 01.12.2025 do 31.12.2025
- **Ostatnie 30 dni** - ostatnie 30 dni

## Format raportu

Raport zawiera:

### Nagłówek
- Nazwa firmy
- Adres
- NIP
- Miesiąc raportu

### Tabela transakcji
Kolumny: Data zakupu | Nr zam. | Status | Klient | Przychód netto [zł] | Sposób płatności

### Podsumowanie
- Liczba zamówień
- Suma przychodu netto
- Zakres dat

## Statusy zamówień

Domyślnie raport zawiera zamówienia o statusach:
- **Zrealizowane** (completed)
- **W trakcie realizacji** (processing)

Inne statusy (anulowane, zwrócone, itp.) nie są uwzględniane w raporcie.

## Metody płatności

Wtyczka automatycznie rozpoznaje popularne metody płatności:
- Przelew bankowy → "przelew"
- Gotówka przy odbiorze → "gotówka przy odbiorze"
- PayPal → "PayPal"
- Stripe → "karta kredytowa"

## Obliczanie przychodu netto

Przychód netto jest obliczany jako:
```
Przychód netto = Suma zamówienia - VAT
```

## Struktura plików

```
PhotoJob-Organizer/
├── photojob-organizer.php          # Główny plik wtyczki
├── includes/
│   ├── class-accounting-table-generator.php  # Generator tabeli księgowej
│   └── class-excel-exporter.php              # Eksporter do Excel/CSV
├── admin/
│   ├── class-admin-menu.php                  # Menu administracyjne
│   ├── class-accounting-report-page.php      # Strona raportu
│   ├── css/
│   │   └── admin-styles.css                  # Style administracyjne
│   └── js/
│       └── admin-scripts.js                  # Skrypty JavaScript
├── dcs/
│   └── zestawienie-ksiegowosc/              # Przykładowe zestawienia
└── README.md                                 # Ten plik
```

## Rozszerzanie funkcjonalności

### Filtr: Statusy zamówień

Możesz zmienić domyślne statusy zamówień uwzględniane w raporcie:

```php
add_filter( 'photojob_order_statuses', function( $statuses ) {
    return array( 'completed', 'processing', 'on-hold' );
} );
```

### Filtr: Informacje o firmie

Możesz dostosować informacje o firmie wyświetlane w raporcie:

```php
add_filter( 'photojob_company_info', function( $company_info ) {
    $company_info['name'] = 'Moja Firma Fotograficzna';
    $company_info['nip'] = '123-456-78-90';
    return $company_info;
} );
```

## FAQ

**Q: Czy raport zawiera zamówienia anulowane?**
A: Nie, domyślnie raport zawiera tylko zamówienia zrealizowane i w trakcie realizacji.

**Q: Czy mogę zmienić format daty w raporcie?**
A: Tak, możesz to zrobić modyfikując funkcję w pliku `class-accounting-table-generator.php`.

**Q: Czy mogę dodać dodatkowe kolumny do raportu?**
A: Tak, możesz rozszerzyć metodę `extract_order_data()` w klasie `PhotoJob_Accounting_Table_Generator`.

**Q: Czy wtyczka działa bez WooCommerce?**
A: Nie, wtyczka wymaga aktywnego WooCommerce.

## Wsparcie

W przypadku problemów lub pytań:
- Sprawdź sekcję FAQ powyżej
- Zgłoś problem w zakładce Issues na GitHub

## Changelog

### 1.0.5 (2026-01-14)
- 🐛 **POPRAWKA KRYTYCZNA**: Naprawiono format pliku Excel XLSX
- Dodano bibliotekę SimpleXLSXGen do generowania prawdziwych plików Excel
- Plik Excel (.xlsx) teraz otwiera się poprawnie w Microsoft Excel
- Poprzednio: generowany był plik CSV z rozszerzeniem .xlsx
- Teraz: generowany jest prawdziwy plik XLSX z formatowaniem

### 1.0.3 (2026-01-14)
- 🐛 **POPRAWKA KRYTYCZNA**: Naprawiono pobieranie raportu Excel
- Zmieniono mechanizm obsługi eksportu na WordPress admin_post_ hook
- Formularz wysyła teraz dane do admin-post.php zamiast do tej samej strony
- Raport Excel (.xlsx) teraz na pewno pobiera się poprawnie po kliknięciu "Pobierz raport"
- ⚠️ UWAGA: Plik miał nieprawidłowy format - użyj wersji 1.0.5

### 1.0.2 (2026-01-14)
- 🐛 **POPRAWKA KRYTYCZNA**: Naprawiono błąd instalacji wtyczki
- Poprawiono strukturę archiwum ZIP (pliki teraz w folderze photojob-organizer/)
- Zmieniono sposób inicjalizacji modułów admin (używa hooka plugins_loaded)
- ⚠️ UWAGA: Pobieranie raportu nadal nie działa - użyj wersji 1.0.3

### 1.0.1 (2026-01-13)
- 🐛 **POPRAWKA**: Naprawiono problem z pobieraniem raportu Excel
- Raport Excel (.xlsx) teraz pobiera się poprawnie po kliknięciu "Pobierz raport"
- Poprawiono inicjalizację obsługi żądań eksportu w admin_init
- ⚠️ UWAGA: Ta wersja ma nieprawidłową strukturę ZIP - użyj wersji 1.0.2

### 1.0.0 (2026-01-12)
- Pierwsze wydanie
- Funkcja generowania raportu księgowego
- Eksport do formatu Excel (XLSX)
- Eksport do formatu CSV
- Interfejs administracyjny
- Walidacja dat
- Szybkie przyciski wyboru zakresu dat
- Podsumowanie transakcji

## Autor

PhotoJob Organizer

## Licencja

GPL v2 lub nowsza

## Podziękowania

Dziękujemy społeczności WordPress i WooCommerce za wspaniałe narzędzia!
