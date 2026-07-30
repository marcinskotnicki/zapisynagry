# zapisynagry

Tablica zapisów na spotkania planszówkowe. Jedna strona pokazuje wszystkie stoły,
zaplanowane na nich gry i to, kto gra — każdy może dodać grę, zająć miejsce albo
założyć ankietę, żeby wspólnie zdecydować, w co zagracie.

Powstało z myślą o klubach i konwentach: gość nie potrzebuje konta, a organizator
nie potrzebuje arkusza kalkulacyjnego.

*[English version of this file](README.md) · [Dokumentacja techniczna (EN)](docs/TECHNICAL.md)*

---

## Co potrafi

**Stoły i gry.** Wydarzenie dzieli się na dni, dzień na stoły, a stół mieści
kolejne gry. Przy każdej grze widać godzinę rozpoczęcia, czas trwania, liczbę
graczy, wagę (złożoność) oraz listę zapisanych.

**Zapisy.** Kliknij wolne miejsce, podaj imię i już jesteś. Gdy gra się zapełni,
kolejne osoby trafiają na listę rezerwową i awansują automatycznie, jeśli ktoś
zrezygnuje.

**Ankiety.** Nie wiecie, w co zagrać? Zamiast gry załóż ankietę: wypisz kilka
propozycji i pozwól głosować. Ankieta sama zamieni się w grę — albo w chwili, gdy
któraś propozycja zbierze komplet graczy, albo (jeśli tak wybierzesz) dopiero po
upływie terminu, dzięki czemu wszystkie propozycje zbierają głosy do końca.

**Dyskusje.** Przy każdej grze i ankiecie można prowadzić krótką rozmowę.

**Wiadomości.** Napisz do osoby, która przyniosła grę, albo do wszystkich
zapisanych — bez ujawniania czyjegokolwiek adresu.

**Lista mailingowa.** Odwiedzający mogą poprosić o powiadomienia o nowych grach
na danym wydarzeniu.

---

## Dla odwiedzających

### Dodawanie gry

Wybierz stół i kliknij **Dodaj grę**. Możesz wyszukać tytuł w BoardGameGeek — to
uzupełni miniaturkę, czas gry i wagę — albo wpisać wszystko ręcznie.

Zostaniesz zapytany, jak chcesz podejść do zasad: czy je wytłumaczysz, streścisz
w skrócie, czy zakładasz, że wszyscy już grę znają. Gracze widzą to, zanim się
zapiszą.

Jeśli podasz adres e-mail, dostaniesz powiadomienie, gdy ktoś dołączy do Twojej
gry lub z niej zrezygnuje. Pozwala on też później edytować albo usunąć grę bez
zakładania konta.

### Zajmowanie miejsca

Kliknij wolne miejsce przy dowolnej grze. Jeśli komplet jest już zebrany,
trafisz na listę rezerwową i awansujesz automatycznie, gdy zwolni się miejsce.

Aby zrezygnować, użyj przycisku **Zrezygnuj** przy swoim nazwisku.

### Ankiety

Ankieta to miejsce w grafiku, w którym gra nie została jeszcze wybrana. Dodaj co
najmniej dwie propozycje i pozwól ludziom zagłosować.

Ankieta rozstrzyga się sama. Domyślnie dzieje się to w chwili, gdy któraś
propozycja zbierze potrzebną liczbę graczy. Jeśli osoba zakładająca ankietę
zaznaczyła **Czekaj na koniec głosowania**, ankieta potrwa do terminu, a wygra
propozycja z najlepszym wypełnieniem.

Autor ankiety może też zakończyć głosowanie wcześniej, edytować ankietę albo ją
usunąć.

### Edytowanie tego, co dodałeś

Jeśli w chwili dodawania byłeś zalogowany, po prostu wejdź w edycję ponownie.

Jeśli nie — serwis poprosi o potwierdzenie tożsamości: zwykle przez ponowne
wpisanie użytego adresu e-mail albo przez kod wysłany na ten adres. To, który
sposób obowiązuje, ustala organizator.

### Powiadomienia

Jeśli organizator włączył tę opcję, pod grafikiem znajdziesz pole, w którym
możesz zostawić adres, żeby dowiadywać się o nowych grach na tym wydarzeniu.
Każdy e-mail zawiera link do wypisania się, a zapisy dotyczą pojedynczego
wydarzenia — zapisanie się na jedno nie zapisuje Cię na kolejne.

---

## Dla organizatorów

Panel administratora znajduje się pod adresem `/admin.php` (albo po prostu
`/admin`). Wszystko poniżej jest właśnie tam.

### Zakładanie wydarzenia

**Nowe wydarzenie** tworzy je od zera: nazwa, liczba dni, godziny otwarcia i
zamknięcia każdego dnia oraz liczba stołów.

Dzień może przekraczać północ — 18:00 → 03:00 traktowane jest jako jeden wieczór,
a nie dwa dni, i gry zaczynające się po północy trafiają w odpowiednie miejsce na
osi czasu.

### Opcje

Zakładka **Opcje** to główny panel sterowania. Najważniejsze ustawienia:

| Ustawienie | Za co odpowiada |
|---|---|
| Strefa czasowa | Zegar, według którego działa cały serwis. Musi zgadzać się z lokalem, inaczej ankiety rozstrzygną się w złym momencie. |
| Konta | Czy odwiedzający mogą się rejestrować, czy serwis działa wyłącznie dla gości. |
| Kto może dodawać gry / zapisywać się | Goście czy tylko zalogowani. |
| Wymaganie e-maila | Nigdy, zawsze, albo decyduje autor każdej gry. |
| Metoda weryfikacji | Jak gość potwierdza, że jest autorem wpisu. |
| Captcha | Chroni publiczne formularze; wymaga kluczy reCAPTCHA. |
| Kod API BGG | Włącza wyszukiwanie w BoardGameGeek. Bez niego gry dodaje się ręcznie. |
| Lista mailingowa | Pole zapisu i powiadomienia o nowych grach. |
| Motyw i język | Wartości domyślne oraz to, czy odwiedzający mogą je zmieniać. |

### Wysyłanie wiadomości

Zakładka **Mailing** pozwala napisać do jednej z czterech grup:

- osoby zapisane na bieżące wydarzenie
- wszyscy, którzy kiedykolwiek się zapisali, na dowolne wydarzenie
- uczestnicy tego wydarzenia (dodali grę, zapisali się lub głosowali)
- obie grupy związane z tym wydarzeniem naraz

Osoby z listy mailingowej automatycznie dostają link do wypisania się. Liczba
obok każdej opcji pokazuje, ilu jest odbiorców.

### Pozostałe zakładki

- **Użytkownicy** — zakładanie kont, nadawanie uprawnień administratora,
  blokowanie.
- **Miniaturki** — grafiki zastępcze dla gier bez wpisu w BGG oraz favicon
  serwisu.
- **Logi** — historia tego, co zostało dodane, zmienione i usunięte, oraz przez
  kogo.
- **Archiwum** — minione wydarzenia, dostępne tylko do odczytu.
- **Aktualizacja** — pobiera najnowszą wersję z GitHuba i ją wgrywa, razem ze
  zmianami w bazie danych.

---

## Instalacja

**Wgraj sam plik `install.php` i otwórz go w przeglądarce.** Instalator sam
pobierze resztę aplikacji z GitHuba, więc nie musisz kopiować niczego więcej.

Instalator przeprowadzi Cię przez trzy kroki: sprawdzi, czy serwer spełnia
wymagania, pobierze i rozpakuje aplikację, utworzy bazę danych i poprosi o dane
konta administratora. Po zakończeniu usuwa sam siebie.

**Wymagania.** PHP 7.4 lub nowsze (zalecane 8.x) z rozszerzeniami `pdo_sqlite`,
`curl`, `zip`, `gd` i `mbstring` oraz katalog z prawem zapisu. Dane trzymane są w
SQLite, więc nie trzeba osobnego serwera bazy danych. Wystarczy dowolny hosting
współdzielony z PHP.

**Po instalacji**, w Opcjach:

- Ustaw od razu **strefę czasową** — zależą od niej godziny gier i terminy
  ankiet.
- Wpisz **dane SMTP**, jeśli chcesz wysyłać e-maile. Bez nich serwis działa
  normalnie, ale niczego nie wysyła.
- Ustaw **adres serwisu**, żeby linki w e-mailach prowadziły we właściwe miejsce.

---

## Podziękowania

Dane o grach pochodzą z [BoardGameGeek](https://boardgamegeek.com/) poprzez ich
API XML. Za wysyłkę poczty odpowiada
[PHPMailer](https://github.com/PHPMailer/PHPMailer).
