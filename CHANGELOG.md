# Changelog

Wszystkie istotne zmiany w Basketmania Camp System są dokumentowane w tym pliku.

## [0.18.1] - 2026-07-22

### Dodano
- Centrum serwisowe dostępne z menu Basketmania Camp,
- diagnostykę wersji wtyczki, WordPressa i PHP,
- kontrolę wersji schematu bazy danych i obecności najważniejszych tabel,
- kontrolę katalogu uploads, autoloadera Composer i silnika DOMPDF,
- kontrolę połączenia z GitHub API i numeru najnowszego wydania,
- narzędzia do czyszczenia cache aktualizacji, ręcznego uruchamiania migracji oraz generowania testowego PDF,
- ikonę kopiowania pełnej treści opisu bezpośrednio na liście zgłoszeń Feedback.

### Zmieniono
- numer wersji wtyczki z `0.18.0` na `0.18.1`,
- lista Feedback nadal wyświetla pełny opis, a obok niego udostępnia szybkie kopiowanie do schowka.

## [0.18.0] - 2026-07-22

### Dodano
- automatyczne sprawdzanie nowych wersji przez GitHub Releases,
- wyświetlanie aktualizacji na standardowym ekranie wtyczek WordPress,
- pobieranie paczki ZIP bezpośrednio z publicznego repozytorium GitHub,
- obsługę informacji o wydaniu i listy zmian w oknie szczegółów wtyczki,
- automatyczne czyszczenie pamięci podręcznej aktualizatora po instalacji nowej wersji,
- workflow GitHub Actions budujący paczkę ZIP po utworzeniu tagu `v*`,
- automatyczną instalację produkcyjnych zależności Composer podczas budowania wydania,
- kontrolę obecności DOMPDF przed opublikowaniem paczki ZIP.

### Zmieniono
- numer wersji z `0.18.0-dev` na stabilne `0.18.0`,
- źródło aktualizacji z planowanego serwera licencyjnego na GitHub Releases,
- paczka instalacyjna zawiera teraz katalog `vendor` wraz z DOMPDF i autoloaderem Composer.

### Usunięto
- tymczasowy mechanizm blokowania działania wtyczki kodem licencyjnym.

## [0.17.0]
- poprzednia stabilna wersja systemu.
