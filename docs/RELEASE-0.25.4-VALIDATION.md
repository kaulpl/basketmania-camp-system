# Kontrola wydania 0.25.4

Wydanie przygotowano bezpośrednio na bazie paczki 0.25.3.

Przeprowadzone kontrole:

- składnia wszystkich plików PHP na PHP 8.4,
- obecność wszystkich 40 plików wskazanych przez `require_once`,
- obecność wszystkich 34 klas uruchamianych metodą `::init()`,
- zgodność 40 placeholderów szablonu umowy z mapą danych generatora,
- porównanie paczki z 0.25.3: poza numerem wersji dodano wyłącznie klasę migracyjną i plik szablonu,
- zachowanie `class-bcs-workflow-engine.php` oraz pełnego bootstrapu wersji 0.25.3.

Migracja wzoru umowy zastępuje wyłącznie pusty lub niezmieniony domyślny wzór. Samodzielnie edytowane wzory administratora nie są nadpisywane.
