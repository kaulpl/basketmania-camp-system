# KSeF / FA(3)

Wersja 0.72 przygotowuje generator i prewalidację strukturalną XML FA(3) dla środowiska TEST KSeF API 2.0.

Oficjalne parametry schematu:

- namespace: `http://crd.gov.pl/wzor/2025/06/25/13775/`
- `kodSystemowy`: `FA (3)`
- `wersjaSchemy`: `1-0E`
- `WariantFormularza`: `3`
- oficjalne XSD: `https://crd.gov.pl/wzor/2025/06/25/13775/schemat.xsd`

Generator automatycznie wykona pełną walidację XSD, gdy zatwierdzony plik zostanie umieszczony jako `assets/ksef/fa3.xsd`. Do czasu dołączenia zweryfikowanej kopii XSD działa walidacja poprawności XML, namespace oraz wymaganych węzłów fundamentu FA(3).

Środowisko TEST nie może otrzymywać rzeczywistych danych osobowych ani produkcyjnych. Domyślna konfiguracja wymusza anonimizację danych nabywcy i opisu usługi.
