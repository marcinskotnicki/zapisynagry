<?php
/* =============================================================================
 *  defaults/texts/pl.php — gotowe teksty instrukcji, po polsku.
 * -----------------------------------------------------------------------------
 *  Te teksty są PROPONOWANE, nigdy narzucane. Na ekranie opcji jest przycisk
 *  „wczytaj standardowe instrukcje”, który wpisuje je do tych pól własnych
 *  komunikatów, które są jeszcze PUSTE; nic, co klub już napisał, nie zostanie
 *  nadpisane.
 *
 *  DLA KOGO SĄ NAPISANE: dla kogoś, kto nie korzystał wcześniej z takiej
 *  tablicy zapisów i może dopiero zaczynać z planszówkami. Czyli: krótko,
 *  prosto i konkretnie — co nacisnąć. Bez żargonu, którego od razu się nie
 *  tłumaczy; „BoardGameGeek” pojawia się tylko przy dodawaniu gry i od razu
 *  jest wyjaśnione.
 *
 *  JAK SĄ WYŚWIETLANE: jako jeden akapit ze zwykłym tekstem (bez HTML-a).
 *  Kroki numerowane idą w linii jako „1) … 2) …”, co czyta się dobrze i działa
 *  w każdym motywie.
 *
 *  Klucze pochodzą z custom_msg_keys(). Brak klucza tutaj oznacza po prostu
 *  brak standardowego tekstu dla tego pola. Edycja tego pliku to zamierzony
 *  sposób zmiany treści — nic więcej nie trzeba ruszać.
 * ============================================================================= */

return [

    // Strona wydarzenia — pierwsze, co widzi większość odwiedzających.
    'msg_below_event' =>
        'Ta strona pokazuje wszystkie wydarzenia i zaplanowane na nich gry: Wybierz datę spotkania, grę, która Cię interesuje i kliknij „Zapisz się”, żeby zająć miejsce. Nie musisz mieć konta.',

    // Ekran dodawania gry: nazwa, a potem BGG / ankieta / ręcznie.
    'msg_adding_game' =>
        'Wpisz nazwę gry, którą chcesz zaproponować i sposób jej dodania. BoardGameGeek to duża internetowa baza gier planszowych — dodanie stamtąd samo uzupełni okładkę, czas gry i liczbę graczy. Jeśli Twojej gry tam nie ma albo nie możesz jej znaleźć, wybierz „dodaj ręcznie” i wpisz dane samodzielnie. Możesz wziąć grę z szafki klubu lub przynieść do grania swoją. Jeśli gra jest prywatną własnością innego gracza - najpierw zapytaj go, czy zechce ją przynieść na spotkanie.',

    // Zajmowanie miejsca przy grze.
    'msg_assigning_player' =>
        'Podaj imię, które mają widzieć inni gracze — samo imię w zupełności '
        . 'wystarczy. Jeśli miejsca są już zajęte, możesz zapisać się na listę '
        . 'rezerwową i zagrasz, gdy ktoś zrezygnuje.',

    // Zakładanie ankiety.
    'msg_adding_poll' =>
        'Nie wiesz, w co zagrać? Zamiast jednej gry załóż ankietę. '
        . '1) Dodaj dwie lub więcej gier do wyboru. 2) Każdy głosuje na te, na '
        . 'które ma ochotę. 3) Gdy wystarczająco dużo osób wybierze tę samą grę, '
        . 'powstaje zwykły stół, na który można się zapisać.',

    // Głosowanie w ankiecie.
    'msg_voting' =>
        'Zagłosuj na każdą grę, w którą chętnie zagrasz — nie tylko na jedną. '
        . 'Im więcej opcji zaznaczysz, tym większa szansa, że znajdzie się coś '
        . 'dla całego stołu. Zdanie możesz zmienić później.',

    // Notka nad polami z adresem e-mail.
    'msg_email_field' =>
        'Twój adres e-mail służy wyłącznie temu, żeby organizator albo inni '
        . 'gracze przy Twoim stole mogli się z Tobą skontaktować w sprawie tej '
        . 'gry. Nigdy nie jest pokazywany na stronie.',

    // Własna biblioteka członka.
    'msg_my_library' =>
        'Wypisz gry, które masz, żeby inni wiedzieli, co jest pod ręką. '
        . '1) Dodaj grę z BoardGameGeek, wklejając jej adres, albo dodaj ją '
        . 'ręcznie. 2) Ukryj to, co jest wypożyczone lub trzymane gdzie indziej '
        . '— zostanie na Twojej liście, ale inni nie zobaczą tego jako dostępne.',

    // Wspólna biblioteka klubu.
    'msg_library' =>
        'To gry, które mają członkowie klubu. W zakładce „Gry” zobaczysz, kto co '
        . 'posiada, a w „Członkowie” możesz przejrzeć zbiory jednej osoby. Jeśli '
        . 'chcesz w coś zagrać, poproś właściciela, żeby przyniósł grę na '
        . 'spotkanie.',
];
