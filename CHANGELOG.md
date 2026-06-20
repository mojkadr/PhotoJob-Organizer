# CHANGELOG — PhotoJob Organizer

## [1.6.4] — 2026-06-20 — Autor MójKadr + instant-save ścieżki magazynu

### Fixed
- Nagłówek wtyczki: **Author = MójKadr** (było „Twoje Imię"), Plugin URI / Author URI
  ustawione na realne.
- **Magazyn 0 plików = case-sensitive ścieżka.** Realna ścieżka to `/MójKadr/SESJE/…`
  (`SESJE` wielkimi literami), a domyślne/zapisane `/MójKadr/Sesje` na QNAP (Linux) to
  inny folder → 0 plików. Przeglądarka ścieżki to teraz pokazuje.

### Changed
- Przycisk **„✅ Użyj tej ścieżki jako magazynu"** w przeglądarce QNAP **zapisuje od ręki**
  (AJAX `pjo_set_source_path`) — koniec szukania „Zapisz zmiany" na dole. Jeden klik =
  ścieżka ustawiona i zapisana, wracasz do dashboardu i Folder Builder już ją widzi.

## [1.6.3] — 2026-06-20 — Auto-etap tylko dla zrealizowanych + przeglądarka ścieżki QNAP

### Changed
- Auto-etap produkcji (`PhotoJob_Access_Sync`) działa teraz **tylko dla zamówień
  ZREALIZOWANYCH** (status `completed`). Photo Access auto-grantuje też na „W realizacji"
  — te są pomijane. `auto_set_stage_on_grant()` zwraca bool → backfill liczy dokładnie.

### Added — przeglądarka ścieżki magazynu (znajdź właściwą ścieżkę File Station)
- Ustawienia → QNAP: przycisk **📂 Przeglądaj** przy polu „Ścieżka magazynu zdjęć".
  Listuje zawartość ścieżki na NAS (zapisane credentialsy), foldery klikalne (drill-down),
  „⬆ do góry", licznik plików, przycisk **✅ Użyj tej ścieżki**. Koniec zgadywania —
  widać dokładnie co File Station widzi i ustawia się właściwą ścieżkę magazynu.
  > Uwaga: „0 plików" na planie druku = magazyn źródłowy ma ZŁĄ ścieżkę — ustaw ją tutaj.

## [1.6.2] — 2026-06-20 — Auto-etap produkcji po grancie Photo Access

### Added — automatyczny etap produkcji
- **PhotoJob_Access_Sync** (zawsze ładowana) — nasłuchuje na post-meta
  `_ada_access_granted = yes`, którą Photo Access zapisuje w KAŻDEJ ścieżce grantu
  (procesor / legacy Drive / ręczne przypisanie / wymuszony reprocess). Bez edycji tamtej
  wtyczki. Po przyznaniu dostępu ustawia etap produkcji:
  - zamówienie **tylko elektroniczne** (brak odbitek) → **📧 Link wysłany**,
  - zamówienie **z odbitkami do druku** (JPG + wydruk) → **⚠ Niekompletne** (druk dalej do zrobienia).
  - Niedestrukcyjne: nie cofa ręcznie zaawansowanego etapu (działa tylko gdy etap pusty / „pending").
  - Dodaje notatkę do zamówienia z ustawionym etapem.
- **Backfill**: przycisk „🔄 Zsynchronizuj etapy z Photo Access" w dashboardzie — ustawia etap
  dla zamówień, które JUŻ mają przyznany dostęp (automat działa od teraz na nowe granty).
- Logika „tylko elektroniczne" scalona w jednym miejscu (`PhotoJob_Access_Sync::is_electronic_only`);
  dashboard deleguje, żeby heurystyka się nie rozjechała.

## [1.6.1] — 2026-06-20 — Poprawki: diagnostyka magazynu, dubel obok siebie, re-grant

### Fixed — #1 Magazyn źródłowy „0 plików" był cichy
- `PhotoJob_QNAP_Client::get_list()` rozróżnia teraz **pusty folder** od **błędu API**
  (ścieżka nie istnieje / brak dostępu) — błąd zwraca `false` + `last_error` zamiast
  cichej pustej listy.
- Folder Builder pokazuje **jasne ostrzeżenie** gdy magazyn zwróci 0 plików lub jest
  niedostępny, z nazwą ścieżki i podpowiedzią („to ścieżka File Station, np.
  `/SESJE/Zielony i Niebieski Motylek`"). Koniec mylącego „Plik nie znaleziony" przy
  złej ścieżce magazynu.
- Ustawienia → QNAP: pole „Ścieżka magazynu zdjęć" ma placeholder + opis tłumaczący,
  że to ścieżka File Station od nazwy zasobu (`M:\SESJE\…` = `/SESJE/…`).

### Added — #2 Szybki re-grant dostępu w dashboardzie
- Przycisk **🔓** w kolumnie Akcje każdego zamówienia → „wymuś ponowne przyznanie
  dostępu" bez wchodzenia w ekran zamówienia WC. Odpala **ten sam hook**
  (`woocommerce_order_action_ada_force_reprocess`) co akcja Photo Access (alpha16) —
  zero duplikacji logiki; gdy wtyczka nieaktywna, czytelny błąd.

### Changed — #3 Duble i grupy pakowania obok siebie
- Lista zamówień układa członków grupy pakowania / klastra dubli (mail/nazwisko) zaraz
  pod „kotwicą" (pierwszym w kolejności daty) — ułatwia pakowanie. Działa w obrębie
  bieżącej strony (jeśli bliźniak na innej stronie → zwiększ „/strona" lub zawęź filtr).

## [1.6.0] — 2026-06-18 — Grupy pakowania (#3) + Wydruki/numeracja (#4)

### Added — #3 Grupy pakowania (wykrywanie dubli)
- **PhotoJob_Duplicates** — wykrywa zamówienia W REALIZACJI dzielące ten sam e-mail
  LUB imię+nazwisko → klastry (cache 5 min). Niedestrukcyjne łączenie w **grupę
  pakowania** (`pack_group` w `pjo_order_meta`) — zamówienia zostają osobne w WC.
- **Dashboard**: checkbox przy kliencie + pasek „📦 Połącz w grupę pakowania",
  badge **👥 dubel** (tooltip z numerami bliźniaków), badge **📦 PACK-xxxx** (klik = rozłącz).

### Added — #4 Wydruki + numeracja
- **PhotoJob_Print_Batch** — numer wydruku `YY + inicjały firmy + litera sezonu +
  GLOBALNY licznik` → `26ZiNMW1`. Licznik atomowy (ON DUPLICATE KEY), nigdy się nie resetuje.
- **Integracja z Folder Builderem**: „Wykonaj na QNAP" nadaje numer wydruku, buduje pod
  `/Druk/{numer}/{Sezon}/{NrZam}/...`, **buduje CAŁĄ grupę pakowania** pod jednym numerem
  (jedna koperta), stempluje `print_batch` przy każdym zamówieniu, zapisuje rekord wydruku.
- **Dashboard**: badge **🖨 26ZiNMW1** przy zamówieniu (szybka kontrola „zamówione do druku");
  panel buildera pokazuje plany wszystkich zamówień grupy + nadany numer po wykonaniu.
- Tabela **pjo_print_batches** (number unikalny, order_ids, file_count, qnap_path).
- Memoizacja indeksu magazynu w kliencie QNAP (grupa N zamówień = 1 skan źródła).

### DB
- DB_VERSION → 1.5.0: `pjo_order_meta` +`pack_group` +`print_batch` (+indeksy);
  nowa tabela `pjo_print_batches`.

## [1.5.1] — 2026-06-18 — Dashboard UX (uwagi do panelu)

### Added
- **Bank domyślny** — gdy zamówienie nie ma przypisanego banku, dropdown pokazuje
  domyślnie pierwszy z listy (np. mBank) zamiast „— wybierz —”. *(Pod bramki płatnicze
  BLIK/Stripe/Montonio — osobne zadanie roadmapy.)*
- **Wersja elektroniczna → auto-zamknięcie** — nowy etap produkcji **📧 Link wysłany**;
  gdy zamówienie zawiera WYŁĄCZNIE wersje elektroniczne (brak odbitek do druku), ustawienie
  tego etapu od razu zmienia status WC na **Zrealizowane** (helper `order_is_electronic_only()`).
- **Lightbox miniatur** — w rozwiniętym zamówieniu klik w miniaturę otwiera duży podgląd
  (overlay, Esc/klik zamyka) — szybka wizualna identyfikacja zdjęcia.

## [1.5.0] — 2026-06-18 — Faza C: Folder Builder (QNAP)

### Added
- **Folder Builder** — generuje strukturę druku na QNAP wprost z zamówienia WC, eliminuje
  ręczne dopisywanie prefiksów `15x23-1x_10316_`. Konwencja:
  `{Sezon}/{NrZam}/{Typ}/{Rozmiar}/{rozmiar}-{ilość}x_{nrZam}_{nazwa}.{ext}`
- **PhotoJob_QNAP_Client** (`includes/class-pjo-qnap-client.php`) — klient File Station API:
  - login (reużyty wzorzec auth z testu QNAP: `authLogin.cgi` → `authSid`)
  - `make_path` (mkdir -p), `copy_file`, `rename_file`, `get_list`, `folder_exists`
  - **`index_source_files`** — rekurencyjny indeks magazynu źródłowego (basename→ścieżka),
    z limitami bezpieczeństwa (200k plików / głębokość 14)
  - bezpośredni cURL (omija WP HTTP middleware — lekcja v1.3.3), obsługa formatów v4/v5
- **PhotoJob_Folder_Builder** (`includes/class-pjo-folder-builder.php`) — silnik:
  - `build_plan()` — zamówienie → plan (sezon z kategorii, Typ+Rozmiar z meta WAPF,
    nazwa pliku = nazwa produktu WC); **dry-run** dopasowuje pliki źródłowe na QNAP
  - `execute_plan()` — tworzy foldery + kopiuje + przemianowuje pliki na QNAP
  - zapis bazowej ścieżki do `pjo_order_meta.qnap_folder_path`
- **Dashboard zamówień**: przycisk **🗂** w kolumnie Akcje → panel z planem (podgląd
  dopasowania źródeł) + przycisk **▶ Wykonaj na QNAP**; flaga ✅ gdy folder już zbudowany
- **Ustawienia → QNAP**: nowe pole **Ścieżka budowania druku** (`print_build_path`, default `/MójKadr/Druk`)

### Decisions (2026-06-18)
- Wykonanie: **fizycznie na QNAP** przez File Station API (nie sam plan)
- Nazwa pliku: **nazwa produktu WC = nazwa pliku zdjęcia** (tak tworzy je photo-adder)
- Bezpiecznik: **dry-run/podgląd przed wykonaniem** — każdy plik źródłowy weryfikowany
  w magazynie zanim cokolwiek się kopiuje

### DB
- DB_VERSION → 1.4.0 (migracja seeduje `print_build_path` do istniejącego `pjo_settings_qnap`;
  bez zmian schematu tabel — `qnap_folder_path` istniał od v1.3.0)

## [1.4.0] — 2026-06-09 — Faza B: Dashboard zamówień

### Added
- **Strona "Zamówienia"** w menu PhotoJob (`PhotoJob_Orders_Dashboard`)
- **Tabela 11 kolumn** wg Excela "Zestawienie zamówień":
  - Data | Nr | Klient | Email+Tel | Kwota | Status WC | Bank | Etap produkcji | Uwagi/FV | Akcje
  - Pracownik widzi okrojony zestaw (bez kwot/email/telefonu/banku) — moduł Reception ma swój focus
- **Filtry:** zakres dat, status WC, etap produkcji, FV (tak/nie), search (klient/nr/email)
- **Inline edit przez AJAX** (`pjo_update_order_field`):
  - Status WC (dropdown) → `$order->update_status()`
  - Bank (dropdown z `pjo_settings_banks`) → `pjo_order_meta.bank_account`
  - Etap produkcji (6 stanów: pending/sent_to_lab/received_from_lab/incomplete/ready_to_pack/shipped) → `pjo_order_meta.production_status`
- **Expand row** (przycisk +): AJAX (`pjo_get_line_items`) ładuje line items zamówienia z:
  - Thumbnail produktu
  - Nazwa + kategoria (ścieżka kategorii)
  - **Meta WAPF "Wydruk odbitki"** (typ + rozmiar, np. "15x23cm")
  - Ilość + kwota
- **Badge FV 🧾** + **badge uwagi 📝** z tooltip pokazującym treść `customer_note`
- **Kolory wierszy per status WC** (zielony processing, szary completed, czerwony cancelled)
- **Pagination** smart (1 … current-1 current current+1 … last) + per_page configurable (10-200)

### Notes
- HPOS (High Performance Orders Storage) wspierany — `wc_get_orders()` jest HPOS-safe
- Helper `production_status_label()` z emoji statusami (⏳📤📥⚠📦✅)

## [1.3.3] — 2026-06-09 — QNAP fix: bezpośredni cURL

### Fixed
- **QNAP test: puste body w AJAX context** — debug v1.3.2 ujawnił że na `sesje.mojkadr.eu` `wp_remote_post()` zwraca **puste body** w AJAX wp-admin, mimo że ten sam call z PHP CLI dostaje pełne 1100 bajtów XML. Coś z lokalnych wtyczek (W3TC? Cart Editor APF?) hookuje WP HTTP middleware. **Fix:** używamy bezpośrednio PHP cURL (`curl_init`/`curl_exec`) — bez middleware WP. Test wykonany przez `plink + curl + php-cli` na hostido potwierdził że QNAP odpowiada poprawnym XML auth, problem był tylko po stronie WP HTTP layer
- Dodatkowy parser `<authPassed>` obsługujący CDATA (`<authPassed><![CDATA[0]]></authPassed>`)
- Konkretne podpowiedzi cURL errno: #28 (timeout), #60 (SSL cert)

## [1.3.2] — 2026-06-09 — Hotfix QNAP debug + performance

### Added
- **DEBUG mode w QNAP teście** — gdy login się nie udaje, pod komunikatem pojawia się `<details>` "🔍 DEBUG" z URL/HTTP code/Content-Type/Body excerpt. Pozwala zdiagnozować dlaczego authPassed=brak (HTML zamiast XML? rate limit? inny endpoint?)
- **Cache `term_counts`** in-memory na request — zamiast WP_Query per term, 1 SQL po wszystkie kategorie

### Fixed
- **Performance zakładek Sezony/Placówki** — `count_products_in_category_tree()` robił `WP_Query` per term (100+ queries dla placówek). Zamienione na 1 SQL z `wp_term_taxonomy.count` (WP utrzymuje to automatycznie) + rekurencyjna suma poddrzewa w PHP. Zakładka powinna ładować się <500ms (poprzednio ~3s+)
- **QNAP authSid parsing** dodana obsługa CDATA (`<authSid><![CDATA[...]]></authSid>`)
- **QNAP error messages** — różne hinty dla różnych przypadków: HTML response → "to strona panelu, sprawdź port", authPassed=0 → "credentials wrong", brak XML → "endpoint nie odpowiada standardowo"

## [1.3.1] — 2026-06-09 — Hotfixy v1.3.0

### Fixed
- **Excel księgowy: brakujące dane firmy** — `get_company_info()` w `class-accounting-report-page.php` czytał TYLKO z WC settings (`get_bloginfo('name')`, `WC()->countries->get_base_*()`, `woocommerce_store_vat_number`), ignorując nowe `pjo_settings_company` z Settings PJO. Priority zmieniony: PJO Settings → fallback WC. Dorzucony REGON jako 4. pole nagłówka
- **Excel: puste linie "NIP: " gdy pole puste** — `isset()` → `! empty()` w exporterze
- **QNAP test: HTTP 404** — endpoint `/cgi-bin/filemanager/wfm2Login.cgi` nie istnieje na QTS 5+. Zmienione na `/cgi-bin/authLogin.cgi` (wzorzec z Photo Access alpha35 który działa)
- **QNAP: brak toggle HTTPS** — dodany checkbox **HTTPS** + checkbox **Weryfikuj certyfikat SSL** (default: HTTPS=on, verify=off — QNAP `*.myqnapcloud.com` ma self-signed)
- **QNAP test: niejasna obsługa response** — teraz precyzyjne parsing `<authSid>` + `<authPassed>` z XML, błąd HTTP 404 daje podpowiedź "sprawdź host/port"

## [1.3.0] — 2026-06-08 noc — Faza A.2: Auto-detect z WC + QNAP password

### Added
- **PhotoJob_WC_Inspector** — nowa klasa czytająca strukturę WC:
  - `get_seasons()` — auto-wykrycie sezonów z `product_cat` (kategorie 2. poziomu zawierające "Wiosenna"/"Zimowa"/"Letnia"/"Jesienna")
  - `get_facilities()` — auto-wykrycie placówek z hierarchii kategorii (Klient → Rok → Sezon → Oddział → Typ → Grupa)
  - `get_detected_sizes()` — skan line items WC, wyciąga unikalne wartości meta typu "Wydruk odbitki" (z wtyczki WAPF)
  - Cache 1h w `transient`, manualny refresh przyciskiem
- **Pole hasła QNAP** (`<input type="password">`) — wpisujesz hasło bezpośrednio, szyfrowane AES-256-CBC z kluczem pochodnym od `AUTH_KEY` z `wp-config.php`. Z bazy nie da się odczytać bez WP secrets
- **Test QNAP z form values** — AJAX bierze AKTUALNE wartości formularza (nie z bazy) — test działa od razu bez Save. Wskazuje skąd brane hasło: pole / zapisane / stała PHP
- **Helper `PhotoJob_Settings::get_qnap_password()`** — used przez Folder Builder w Fazie C
- **Przycisk "🔄 Odśwież z WooCommerce"** na zakładkach Sezony/Placówki

### Changed
- **Zakładka Sezony** — READ-ONLY auto-detect zamiast wpisywania (user explicit: "powinny być zczytywane z zamówień woo i kategorii"). Pokazuje wykryte sezony + rok + slug + liczba produktów + link do edycji term w WP
- **Zakładka Placówki** — READ-ONLY auto-detect z `product_cat` hierarchy. Drzewo: Klient → Rok → Sezon → Oddział → Typ grupy → Grupa. Filtr live + licznik. Bez CRUD
- **Zakładka Mapowanie produktów USUNIĘTA** — rozmiar/typ jest w meta line items WC (wtyczka WAPF: "Wydruk odbitki": "15x23cm (25 zł)"). Folder Builder w Fazie C parsuje to bezpośrednio z order, mapowanie ręczne niepotrzebne

### Removed
- Tabela `pjo_facilities` — przestaje być tworzona (auto-detect z product_cat). Stara tabela z v1.2.0 zostaje, ale ignorowana
- Option `pjo_settings_seasons` (lista sezonów) — auto z WC
- Option `pjo_settings_product_map` (mapowanie produktów) — niepotrzebne
- Handler `pjo_facility_action`, `pjo_auto_detect_products`, `pjo_search_products` — bezużyteczne po refactorze

### Fixed
- Test połączenia QNAP nie działał bez Save formularza (alerted "Host lub użytkownik nie jest ustawiony"). Teraz AJAX bierze wartości z form na żywo.

## [1.2.0] — 2026-06-08 wieczór — Faza A.1: Feedback iteration

### Added
- **Zakładka Placówki** (klienci instytucjonalni: Zielony Motylek/Bałtycka/żłobek). CRUD: dodaj/edytuj/usuń. Pola: klient, oddział, typ grupy, nazwa grupy, kod (dopasowanie do nazwy zdjęcia), adres, kontakt
- **Tabela `pjo_facilities`** w bazie
- **Pola `wants_invoice` + `invoice_data` + `customer_note_cached` + `facility_id`** w `pjo_order_meta`
- **Sync uwag klienta** — przy każdym `woocommerce_new_order` / `woocommerce_update_order` cache'ujemy `customer_note` do `pjo_order_meta.customer_note_cached`. Heurystyka: jeśli uwagi zawierają "faktur"/"FV"/"NIP" → ustawiamy `wants_invoice=1` i kopiujemy treść do `invoice_data` (do dopracowania w panelu zamówienia w Fazie B)
- **Auto-wykrycie mapowania produktów** — przycisk "⚡ Auto-wykryj z nazw produktów" parsuje WC produkty regexem: rozpoznaje Odbitka/Karta Folio BOX/Eco Gift BOX/Wersja elektroniczna/JPG/Fotoalbum/Wall Decor + rozmiar `WxH`. Wypełnia puste pola, user zatwierdza Save
- **Test połączenia QNAP** — przycisk "🔌 Testuj połączenie" → AJAX login do File Station API → zielony/czerwony wynik
- **Rozbudowa zakładki Etykiety:**
  - Co drukować (checkboxy): imię/adres/nr zam./telefon/email/data/kod kreskowy/własny tekst
  - Własny tekst (textarea)
  - URL grafiki + przycisk "📁 Wybierz z biblioteki" (WP Media Library)
  - Pozycja grafiki: lewy/prawy górny/dolny, środek, tło (znak wodny)
  - Wielkość grafiki w % szerokości etykiety
  - Obramowanie: toggle, grubość, styl (solid/dashed/dotted/double), kolor
  - **Podgląd HTML** etykiety na sample data (live preview, bez F5)
- **Paginacja Mapowania produktów** — zamiast 200 produktów ładujemy: wszystkie zmapowane + 50 najnowszych. Reszta przez live search AJAX (`pjo_search_products`). Fix wolnego ładowania zakładki przy 2200 produktów

### Changed
- Zakładka **Sezony** dostała tooltip wyjaśniający że "sezon" = pora roku/edycja (Wiosenna 2026), wg schematu `25WMP3` — bo było mylące. Placówki (np. Zielony Motylek) są w nowej zakładce
- **Activator deaktywuje stare wersje wtyczki** (`PhotoJob_Activator::deactivate_old_versions`) — fix błędu krytycznego przy instalacji nowej wersji obok starej v1.0.5 (kolizja klas)
- WP Media Library auto-enqueue na stronie Settings (potrzebne dla wyboru grafiki etykiety)

### Removed
- **LookAt Gallery** usunięty z zakładki Sklepy zewnętrznych i z `pjo_settings_labs` — user explicit: "napewno nie będziemy z tej aplikacji korzystać. Chodzi mi i szybkie i tanie zamawianie zdjęć, a nie płacenie dodatkowo za możliwość ich zamawiania przez klienta"

### Fixed
- Wolne ładowanie zakładek (Mapowanie produktów przy 2200 produktów WC)
- Kolizja klas `PhotoJob_*` przy aktualizacji z v1.0.5 → v1.1.0

## [1.1.0] — 2026-06-08 — Faza A: Fundament rozszerzenia

### Added
- **Tabele bazy danych** (4) — `pjo_order_meta`, `pjo_print_orders`, `pjo_reception_log`, `pjo_label_sheets`
- **Rola `pjo_worker`** ("Pracownik Foto") z capabilities `pjo_reception_check` + `pjo_print_labels`
- **Capabilities `pjo_*`** dla admin/shop_manager: `pjo_view_finance`, `pjo_export_accounting`, `pjo_manage_settings`, `pjo_manage_orders`, `pjo_reception_check`, `pjo_print_labels`
- **Strona Ustawienia** z 7 zakładkami (multi-tenant lite — nic hardcoded):
  - Firma (nazwa, NIP, REGON, adres)
  - Banki (lista)
  - Sezony (lista — domyślnie "Wiosenna", "Zimowa")
  - Mapowanie produktów WC → typ/rozmiar
  - QNAP (host, port, user, ścieżki — hasło z stałej PHP)
  - Etykiety (template Avery, capacity, sender)
  - Sklepy zewnętrzne (nphoto: export_zip/browser_bot/api; lookat)
- **Activator** (`PhotoJob_Activator`) z `register_activation_hook` + `dbDelta` + auto-upgrade DB schemy
- **Deactivator** (`PhotoJob_Deactivator`) z `flush_rewrite_rules`
- Strona główna **dostosowana per rola** (pracownik widzi tylko swoje moduły) + status systemu (WC + DB schema + worker role)

### Changed
- Capability dla Raportu księgowego: `manage_woocommerce` → `pjo_export_accounting` (z fallback na `manage_woocommerce` dla wstecznej kompatybilności)
- Menu główne PJO ma teraz capability rozróżniającą admin vs worker (`pjo_manage_orders` || `pjo_reception_check`)

### Notes
- Faza A buduje fundament dla Faz B-F. Pełne moduły (Dashboard, Folder Builder QNAP, Reception, Etykiety, Print Export) dorzucamy w kolejnych release'ach.
- Decision log: nphoto i lookat.gallery NIE mają publicznego API (research czerwiec 2026) — zostajemy przy MVP "export ZIP do panelu online".

## [1.0.5] — 2026-01-14
- Naprawiono format pliku Excel XLSX (prawdziwy XLSX zamiast CSV z rozszerzeniem)
- Dodano bibliotekę SimpleXLSXGen (single-file PHP, bez Composer)

## [1.0.4] — 2026-01-14
- Stabilizacja przed v1.1.0

## [1.0.2] — 2026-01-13
- Poprawiono strukturę archiwum ZIP (folder `photojob-organizer/` w korzeniu)
- Inicjalizacja modułów admin na hooku `plugins_loaded`

## [1.0.1] — 2026-01-13
- Naprawiono rejestrację hooka eksportu (`admin_post_*`)

## [1.0.0] — 2026-01-13
- Pierwsza wersja: Raport księgowy Excel/CSV
