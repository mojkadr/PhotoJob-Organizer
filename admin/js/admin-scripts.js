/**
 * Skrypty administracyjne dla PhotoJob Organizer
 *
 * @package PhotoJob_Organizer
 */

(function($) {
    'use strict';

    /**
     * Inicjalizacja po załadowaniu DOM
     */
    $(document).ready(function() {

        /**
         * Walidacja formularza eksportu raportu
         */
        $('form').on('submit', function(e) {
            const form = $(this);

            // Sprawdź czy to formularz eksportu
            if (!form.find('button[name="photojob_export_accounting"]').length) {
                return;
            }

            const dateFrom = $('#date_from').val();
            const dateTo = $('#date_to').val();

            // Walidacja daty początkowej
            if (!dateFrom) {
                e.preventDefault();
                alert('Musisz podać datę początkową.');
                $('#date_from').focus();
                return false;
            }

            // Walidacja zakresu dat
            if (dateTo && new Date(dateFrom) > new Date(dateTo)) {
                e.preventDefault();
                alert('Data początkowa nie może być późniejsza niż data końcowa.');
                $('#date_from').focus();
                return false;
            }

            // Pokaż wskaźnik ładowania
            const submitButton = form.find('button[type="submit"]');
            const originalText = submitButton.html();

            submitButton.prop('disabled', true);
            submitButton.html('<span class="dashicons dashicons-update" style="animation: rotation 1s infinite linear;"></span> Generuję raport...');

            // Dodaj animację obrotową
            $('<style>')
                .prop('type', 'text/css')
                .html('@keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(359deg); } }')
                .appendTo('head');

            return true;
        });

        /**
         * Ustawienie domyślnych dat
         */
        function setDefaultDates() {
            const dateFromInput = $('#date_from');
            const dateToInput = $('#date_to');

            // Jeśli data początkowa jest pusta, ustaw 01.12.2025
            if (!dateFromInput.val()) {
                dateFromInput.val('2025-12-01');
            }

            // Jeśli data końcowa jest pusta, ustaw dzisiejszą datę
            if (!dateToInput.val()) {
                const today = new Date();
                const year = today.getFullYear();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');
                dateToInput.val(`${year}-${month}-${day}`);
            }
        }

        // Uruchom ustawienie domyślnych dat
        setDefaultDates();

        /**
         * Podpowiedzi dla zakresu dat
         */
        function setupDateHints() {
            const dateFromInput = $('#date_from');
            const dateToInput = $('#date_to');

            // Dodaj wskazówki po zmianie daty
            dateFromInput.on('change', function() {
                updateDateHint();
            });

            dateToInput.on('change', function() {
                updateDateHint();
            });
        }

        /**
         * Aktualizuj wskazówkę o zakresie dat
         */
        function updateDateHint() {
            const dateFrom = $('#date_from').val();
            const dateTo = $('#date_to').val();

            if (!dateFrom || !dateTo) {
                return;
            }

            const from = new Date(dateFrom);
            const to = new Date(dateTo);
            const diffTime = Math.abs(to - from);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

            // Znajdź lub utwórz element wskazówki
            let hintElement = $('#date-range-hint');
            if (!hintElement.length) {
                hintElement = $('<p id="date-range-hint" class="description" style="margin-top: 10px; font-weight: 600;"></p>');
                $('#date_to').closest('td').append(hintElement);
            }

            if (diffDays === 0) {
                hintElement.text('Wybrany zakres: 1 dzień');
            } else if (diffDays === 1) {
                hintElement.text('Wybrany zakres: 2 dni');
            } else {
                hintElement.text(`Wybrany zakres: ${diffDays + 1} dni`);
            }
        }

        // Uruchom podpowiedzi dat
        setupDateHints();
        updateDateHint();

        /**
         * Szybkie przyciski wyboru zakresu dat
         */
        function setupQuickDateButtons() {
            const dateFromInput = $('#date_from');
            const dateToInput = $('#date_to');

            // Sprawdź czy mamy pola dat
            if (!dateFromInput.length || !dateToInput.length) {
                return;
            }

            // Utwórz kontener dla przycisków
            const buttonContainer = $('<div class="photojob-quick-dates" style="margin-top: 10px;"></div>');

            // Dodaj przyciski
            const buttons = [
                { label: 'Bieżący miesiąc', action: 'current_month' },
                { label: 'Poprzedni miesiąc', action: 'previous_month' },
                { label: 'Grudzień 2025', action: 'december_2025' },
                { label: 'Ostatnie 30 dni', action: 'last_30_days' }
            ];

            buttons.forEach(function(btn) {
                const button = $('<button type="button" class="button button-small" style="margin-right: 5px; margin-bottom: 5px;">' + btn.label + '</button>');

                button.on('click', function() {
                    setDateRange(btn.action);
                });

                buttonContainer.append(button);
            });

            dateToInput.closest('td').append(buttonContainer);
        }

        /**
         * Ustaw zakres dat na podstawie akcji
         */
        function setDateRange(action) {
            const today = new Date();
            let dateFrom, dateTo;

            switch(action) {
                case 'current_month':
                    dateFrom = new Date(today.getFullYear(), today.getMonth(), 1);
                    dateTo = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                    break;

                case 'previous_month':
                    dateFrom = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    dateTo = new Date(today.getFullYear(), today.getMonth(), 0);
                    break;

                case 'december_2025':
                    dateFrom = new Date(2025, 11, 1); // Miesiące są 0-indeksowane
                    dateTo = new Date(2025, 11, 31);
                    break;

                case 'last_30_days':
                    dateTo = new Date();
                    dateFrom = new Date();
                    dateFrom.setDate(dateFrom.getDate() - 30);
                    break;
            }

            // Formatuj daty do YYYY-MM-DD
            const formatDate = function(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            };

            $('#date_from').val(formatDate(dateFrom)).trigger('change');
            $('#date_to').val(formatDate(dateTo)).trigger('change');
        }

        // Uruchom szybkie przyciski
        setupQuickDateButtons();

    });

})(jQuery);
