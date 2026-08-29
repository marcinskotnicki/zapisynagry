# zapisynagry

Tablica zapisów na spotkania planszówkowe. Jedna strona pokazuje wszystkie stoły,
zaplanowane na nich gry i to, kto gra — każdy może dodać grę, zająć miejsce albo
założyć ankietę, żeby wspólnie zdecydować, w co zagracie.

Powstało z myślą o klubach i konwentach: gość nie potrzebuje konta, a organizator
nie potrzebuje arkusza kalkulacyjnego.

*[English version of this file](README.md) · [Dokumentacja techniczna (EN)](docs/TECHNICAL.md)*

---

## Licencja 

Warunki w pliku [LICENSE.pl.md](LICENSE.pl.md).

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

Skrócona instrukcja obsługi w pliku [help/DLA-GRACZY.md](help/DLA-GRACZY.md).

---

## Dla organizatorów

Panel administratora znajduje się pod adresem `/admin.php` (albo po prostu
`/admin`). 

Instrukcja wprowadzająca w pliku [help/PIERWSZE-KROKI.md](help/PIERWSZE-KROKI.md).

---

## Instalacja

**Wgraj sam plik `install.php` i otwórz go w przeglądarce.** Instalator sam
pobierze resztę aplikacji z GitHuba, więc nie musisz kopiować niczego więcej.

Instalator przeprowadzi Cię przez trzy kroki: sprawdzi, czy serwer spełnia
wymagania, pobierze i rozpakuje aplikację, utworzy bazę danych i poprosi o dane
konta administratora. Po zakończeniu usuwa sam siebie.

**Wymagania.** PHP 7.4 lub nowsze (zalecane 8.x) z rozszerzeniami `pdo_sqlite`,
`curl`, `zip`, `gd`, `mbstring` i `simplexml` oraz katalog z prawem zapisu. Dane trzymane są w
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

[Informacja o wykorzystaniu sztucznej inteligencji](ai.md).
