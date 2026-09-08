# GitHub Theme Updater

[English](README.md) · **Polski**

Aktualizacja motywu WordPressa prosto z repozytorium GitHub (także prywatnego), z kopiami zapasowymi, przywracaniem wersji i ochroną wybranych plików przed nadpisaniem.

- **Wersja:** 2.2.0
- **Autor:** [ms-m.pl](https://ms-m.pl)
- **Wymagania:** WordPress 5.8+, PHP 7.4+
- **Text domain:** `github-theme-updater`

---

## Zrzuty ekranu

| Aktualizacja | Ustawienia | Kopie zapasowe |
| --- | --- | --- |
| ![Zakładka Aktualizacja](docs/screenshots/update.png) | ![Zakładka Ustawienia](docs/screenshots/settings.png) | ![Zakładka Kopie zapasowe](docs/screenshots/backups.png) |

---

## Struktura

```
github-theme-updater.php      bootstrap: stałe, autoloader, rejestracja hooków
uninstall.php                 sprzątanie po trwałym usunięciu wtyczki
includes/
  class-plugin.php            budowa grafu obiektów i rejestracja hooków
  class-lifecycle.php         aktywacja, dezaktywacja, migracja z 1.x
  class-settings.php          schemat i walidacja ustawień (jedna opcja)
  class-token-storage.php     szyfrowanie tokenu przed zapisem do bazy
  class-repository.php        obiekt wartości: właściciel/repozytorium + budowa URL-i
  class-release.php           obiekt wartości: wydanie albo zrzut gałęzi
  class-github-client.php     całe HTTP do GitHuba + cache + mapowanie błędów
  class-path-rules.php        dopasowywanie chronionych ścieżek
  class-filesystem.php        rekurencyjne operacje na plikach przez WP_Filesystem
  class-backup-manager.php    tworzenie, przywracanie i rotacja kopii zapasowych
  class-theme-installer.php   przebieg aktualizacji + automatyczne wycofanie
  class-update-checker.php    cykliczne sprawdzanie wersji i powiadomienie
  class-auto-updater.php      aktualizacje bez udziału człowieka: harmonogram dzienny i uruchomienia z webhooka
  class-notifier.php          raporty e-mail z automatycznych uruchomień
  class-webhook.php           endpoint webhooka GitHuba: weryfikacja podpisu, filtrowanie, kolejkowanie
  class-notices.php           komunikaty przenoszone przez przekierowanie
  class-admin-page.php        menu i zakładki
  class-admin-actions.php     obsługa formularzy (nonce, uprawnienia, redirect)
views/                        szablony zakładek
assets/                       style i skrypt panelu
languages/                    plik .pot do tłumaczeń
tests/                        testy PHPUnit (działają bez WordPressa)
.github/workflows/ci.yml      lint, PHPCS, PHPStan i testy przy każdym pushu
composer.json                 zależności deweloperskie i skrypty kontrolne
phpcs.xml.dist                ruleset WordPress Coding Standards
phpstan.neon.dist             konfiguracja analizy statycznej
phpunit.xml.dist              konfiguracja testów
```

Zasada podziału: `Github_Client` jako jedyny rozmawia po HTTP, `Filesystem` jako jedyny dotyka dysku, a `Theme_Installer` tylko układa kolejność kroków. Reszta wtyczki widzi wyłącznie obiekty `Release` i `WP_Error`.

---

## Ustawienia

Wszystko siedzi w jednej opcji `gthu_settings`, więc zapis formularza jest atomowy.

| Klucz | Domyślnie | Opis |
| --- | --- | --- |
| `repository` | `''` | `właściciel/repozytorium`; przy zapisie normalizowany z dowolnego adresu GitHuba |
| `token` | `''` | token dostępu, szyfrowany AES-256-CBC kluczem z soli WordPressa |
| `theme_slug` | `''` | nazwa katalogu motywu do nadpisywania |
| `source` | `release` | `release` (wydania) albo `branch` (bieżący stan gałęzi) |
| `branch` | `main` | używane tylko w trybie gałęzi |
| `asset_pattern` | `''` | wzorzec nazwy pliku ZIP dołączonego do wydania, np. `motyw-*.zip`; puste = kod źródłowy |
| `include_prereleases` | `false` | czy pokazywać wydania oznaczone jako pre-release |
| `protected_paths` | `languages`, `.env`, `acf-json` | ścieżki nietykane przy aktualizacji |
| `ignored_paths` | `.git`, `node_modules` | ścieżki nigdy nie kopiowane: ani do kopii, ani z archiwum; na dysku zostają nietknięte |
| `create_backup` | `true` | kopia zapasowa przed każdą aktualizacją |
| `backup_limit` | `3` | ile kopii przechowywać |
| `check_updates` | `true` | cykliczne sprawdzanie i powiadomienie w kokpicie |
| `delete_data` | `false` | czy usunąć dane wtyczki przy jej odinstalowaniu |
| `auto_update` | `false` | raz dziennie instaluj nowsze wydanie bez klikania — patrz [Aktualizacje automatyczne](#aktualizacje-automatyczne) |
| `auto_update_time` | `03:00` | preferowana godzina tego uruchomienia, w strefie czasowej strony |
| `notify_emails` | `''` | adresy (po przecinku), które dostają raport po każdym automatycznym uruchomieniu |
| `webhook_enabled` | `false` | aktualizuj od razu po opublikowaniu wydania, przez webhook GitHuba |
| `webhook_secret` | `''` | sekret webhooka, generowany przez wtyczkę i szyfrowany jak token; `webhook_secret_at` zapisuje, kiedy |

Stan działania (`gthu_state`, `autoload = false`): `installed_version`, `installed_at`, `installed_by`, `last_check`, `latest_version`, `last_error`, a także wynik ostatniego automatycznego uruchomienia (`auto_last_run`, `auto_last_trigger`, `auto_last_status`, `auto_last_message`, `auto_notified_error`) i ostatniego wywołania webhooka (`webhook_last_at`, `webhook_last_status`, `webhook_last_message`).

Dziennik operacji (`gthu_history`, `autoload = false`): ostatnie 5 aktualizacji i przywróceń, także nieudanych — z wersją, poprzednią wersją, wynikiem, liczbą skopiowanych plików, autorem i czasem trwania. Widoczny na dole zakładki Aktualizacja; filtr `gthu_history_limit` zmienia liczbę przechowywanych wpisów.

### Chronione ścieżki

Jedna reguła w linii, liczona od katalogu motywu:

| Reguła | Znaczenie |
| --- | --- |
| `languages` | cały katalog wraz z zawartością |
| `.env` | pojedynczy plik |
| `assets/css/klient.css` | plik w podkatalogu |
| `*.log` | dopasowanie wieloznaczne na dowolnym poziomie |
| `# tekst` | komentarz, pomijany |

Segmenty `..` są usuwane przy normalizacji, więc reguła nie sięgnie poza katalog motywu.

### Ignorowane ścieżki

Ta sama składnia, inny cel. Chroniona ścieżka to coś, co właściciel strony chce zachować — i nadal trafia do każdej kopii zapasowej. Ignorowana ścieżka to coś, na co wtyczka w ogóle nie powinna patrzeć: nie trafia do kopii, nie jest instalowana z archiwum, nie jest kasowana ani nadpisywana na dysku. Domyślnie to `.git` i `node_modules` — pozostałości deweloperskie, które nie są częścią motywu, ale potrafią mieć dziesiątki tysięcy plików i wydłużyć kopię zapasową do wielu minut. Dopisz `vendor`, jeśli jest w `.gitignore` repozytorium; nie dopisuj, jeśli motyw potrzebuje commitowanego `vendor` do działania, bo po pierwszej instalacji by go zabrakło.

---

## Token w wp-config.php

Wariant zalecany na produkcji — token nie trafia wtedy do bazy danych i nie da się go odczytać z panelu:

```php
define( 'GTHU_GITHUB_TOKEN', 'ghp_...' );
```

Stała ma pierwszeństwo przed wartością zapisaną w ustawieniach.

---

## Przebieg aktualizacji

1. Pobranie archiwum ZIP wybranego wydania.
2. Rozpakowanie do katalogu roboczego w `wp-content/upgrade/`.
3. Odnalezienie katalogu motywu w archiwum po pliku `style.css` (GitHub nazywa archiwa `owner-repo-sha`, więc nazwa katalogu jest nieprzewidywalna).
4. Sprawdzenie, czy wszystko, co ma zostać usunięte, da się usunąć: każdy katalog musi być zapisywalny dla użytkownika PHP, a na Windowsie pliki tylko do odczytu są od razu odblokowywane. Ścieżka, której nie da się usunąć, zatrzymuje aktualizację w tym miejscu, zanim cokolwiek się zmieni.
5. Kopia zapasowa obecnego motywu.
6. Wyczyszczenie katalogu motywu z pominięciem chronionych i ignorowanych ścieżek.
7. Skopiowanie nowych plików, również z pominięciem chronionych i ignorowanych ścieżek.
8. Sprzątnięcie plików tymczasowych i zapis stanu.

Do kroku 6 katalog motywu nie jest w ogóle ruszany, więc błąd pobierania, uszkodzone archiwum czy problem z uprawnieniami nie mają jak popsuć działającej strony. Jeśli krok 6 albo 7 zawiedzie, motyw jest automatycznie przywracany z kopii z kroku 5, a komunikat z wynikiem mówi, czy to przywracanie się udało.

Naraz może trwać tylko jedna aktualizacja — trzyma wiersz opcji `gthu_install_lock`, zakładany jednym `INSERT IGNORE`, więc dwa żądania nie mogą wygrać oba. Przywracanie kopii i ręczna kopia biorą tę samą blokadę. Zanim cokolwiek zostanie skasowane, instalator porównuje też nagłówki `Theme Name` i `Text Domain` z `style.css` w archiwum i na dysku i odmawia podmiany jednego motywu na inny (filtr `gthu_theme_identity_matches` pozwala to nadpisać).

### Podgląd postępu

W trakcie aktualizacji zakładka Aktualizacja pokazuje listę powyższych etapów, zaznacza bieżący, wyświetla licznik plików przy kopii zapasowej i kopiowaniu oraz odlicza czas trwania. Instalator zapisuje bieżący etap w transiencie `gthu_install_progress`; skrypt panelu wysyła formularz przez `fetch()` i co sekundę odpytuje endpoint AJAX `gthu_progress`, a po zakończeniu żądania przechodzi pod adres z odpowiedzi, więc komunikat z wynikiem pojawia się jak dotąd. Wejście na zakładkę Aktualizacja w trakcie operacji uruchomionej gdzie indziej podłącza się do jej postępu i odświeża stronę po zakończeniu.

Bez JavaScriptu formularz wysyła się zwyczajnie, a wynik pojawia się po przekierowaniu — tak jak wcześniej.

---

## Aktualizacje automatyczne

Domyślnie wyłączone. Obie opcje są w panelu „Aktualizacje automatyczne" na zakładce Ustawienia, działają **tylko w trybie wydań** (gałąź nie ma numeru wersji do porównania) i wykonują dokładnie tę samą sekwencję co przycisk Aktualizuj — blokada, kopia zapasowa, weryfikacja, kopiowanie, wycofanie przy błędzie, dziennik operacji — a na końcu wysyłają raport e-mailem. Przycisk **Uruchom teraz** pod ustawieniami wykonuje jedno takie uruchomienie od razu, razem z mailami, więc cały łańcuch można sprawdzić, gdy ktoś patrzy.

### Uruchomienie z harmonogramu

`auto_update` planuje zdarzenie WP-Cron `gthu_auto_update` codziennie o godzinie `auto_update_time` (strefa czasowa strony). Uruchomienie pobiera listę wydań z pominięciem cache, porównuje najnowsze z `installed_version` i instaluje je tylko wtedy, gdy jest nowsze. Brak nowszej wersji oznacza brak działania i brak maila. Uruchomienie, które zastanie zajętą blokadę (trwająca aktualizacja lub przywracanie), jest pomijane do następnego dnia. Harmonogram jest synchronizowany przy każdym zapisie ustawień, po każdym uruchomieniu (dryf przy zmianie czasu) i przez sprawdzanie dwa razy dziennie jako zabezpieczenie.

**Godzina to „nie wcześniej niż", a nie gwarancja.** WordPress nie ma własnego zegara; WP-Cron budzi się tylko wtedy, gdy jakieś żądanie uruchomi PHP. Uruchomienie spóźnia się, gdy:

- nikt nie odwiedza strony w nocy — zadanie czeka na pierwsze żądanie po zaplanowanej godzinie;
- cache stron albo CDN obsługuje odwiedzających bez dotykania PHP;
- ustawione jest `DISABLE_WP_CRON`, a cron systemowy hostingu odpala się rzadziej;
- inna operacja trzyma w tym momencie blokadę;
- zmieniła się strefa czasowa strony albo czas letni/zimowy (korekta przy następnym uruchomieniu lub zapisie ustawień);
- serwer jest zajęty albo poprzedni proces crona jeszcze trwa — WordPress uruchamia tylko jeden naraz.

Aby godzina była dokładna, dodaj `define( 'DISABLE_WP_CRON', true );` do `wp-config.php` i niech cron systemowy wywołuje `wp-cron.php` co kilka minut:

```
*/5 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

### Raporty e-mail

`notify_emails` przyjmuje jeden lub więcej adresów (rozdzielonych przecinkami, średnikami lub spacjami; błędne wpisy są zgłaszane przy zapisie). Każde automatyczne uruchomienie, które coś zainstaluje albo się na tym wyłoży, wysyła wiadomość tekstową z poprzednią i nową wersją, źródłem uruchomienia, nazwą kopii zapasowej, liczbą plików, chronionymi ścieżkami, linkiem do ekranu wtyczki i notatką z wydania napisaną na GitHubie. Nieudane sprawdzenie GitHuba (wygasły token, zmieniona nazwa repozytorium) jest zgłaszane raz na dany błąd. Aktualizacje uruchomione ręcznie z zakładki Aktualizacja nie są mailowane. **Wyślij testowy e-mail** potwierdza adresy i konfigurację poczty na stronie.

### Webhook GitHuba

`webhook_enabled` sprawia, że aktualizacja dzieje się w chwili opublikowania wydania. Konfiguracja:

1. Zaznacz opcję i zapisz. Wtyczka generuje 64-znakowy sekret, zapisuje go zaszyfrowany (jak token) i pokazuje **raz**; adres do wpisania pojawia się pod polem wyboru. Stała `GTHU_WEBHOOK_SECRET` w `wp-config.php` może zastąpić zapisany sekret.
2. Na GitHubie: repozytorium → Settings → Webhooks → Add webhook. Payload URL `https://example.com/wp-json/gthu/v1/release`, content type `application/json`, sekret z kroku 1, weryfikacja SSL włączona, zdarzenia: „Let me select individual events" → tylko **Releases**, Active.
3. GitHub wysyła ping; w „Recent Deliveries" powinno być 200, a na zakładce Ustawienia „GitHub wysłał ping".

Strona musi być dostępna z internetu (lokalna instalacja deweloperska wymaga tunelu). Po dostarczeniu wtyczka kolejkuje jednorazowe zdarzenie `gthu_webhook_update` i szturcha cron, więc aktualizacja rusza w ciągu minuty lub dwóch; gdy API GitHuba nie nadążyło jeszcze za zdarzeniem, próba jest ponawiana po 3 i 6 minutach.

Jak endpoint jest zabezpieczony:

- Trasa w ogóle nie jest rejestrowana, gdy funkcja jest wyłączona lub nie ma sekretu — odpowiada 404 jak każdy nieznany adres.
- Treści powyżej 256 KB są odrzucane, zanim zostaną odczytane.
- Każde żądanie musi nieść `X-Hub-Signature-256`, czyli HMAC-SHA256 surowej treści pod sekretem, porównywany przez `hash_equals()` w callbacku uprawnień. Niepodpisane żądania dostają 401, źle podpisane 403; jedno i drugie zostawia tylko notatkę na zakładce Ustawienia.
- Liczą się wyłącznie zdarzenia `release` z akcją `published`. `ping` odpowiada pongiem; inne zdarzenia i akcje zwracają 202 i nic nie robią.
- `repository.full_name` z treści musi zgadzać się ze skonfigurowanym repozytorium (inaczej 403). Szkice oraz, o ile `include_prereleases` nie jest włączone, wydania wstępne są ignorowane.
- Identyfikatory `X-GitHub-Delivery` są pamiętane przez tydzień, więc ponowne dostarczenie lub odtworzone żądanie nic nie robi.
- **Treść żądania nigdy nie decyduje, co zostanie zainstalowane.** Zweryfikowane dostarczenie tylko kolejkuje zwykłe automatyczne uruchomienie, które pyta API GitHuba o najnowsze wydanie własnym tokenem strony i instaluje je tylko wtedy, gdy jest nowsze od zainstalowanego — po tym samym sprawdzeniu tożsamości motywu, kopii zapasowej i wycofaniu co każda inna aktualizacja, pod tą samą blokadą.

---

## Punkty rozszerzeń

### Akcje

| Hook | Argumenty | Kiedy |
| --- | --- | --- |
| `gthu_before_install` | `Release $release` | przed pobraniem archiwum |
| `gthu_after_install` | `Release $release, array $summary` | po udanej aktualizacji |
| `gthu_install_failed` | `WP_Error $error` | po nieudanej próbie |
| `gthu_auto_update_finished` | `string $status, string $trigger, string $message, ?Release $release` | po każdym automatycznym uruchomieniu, niezależnie od wyniku (`updated`, `failed`, `up_to_date`, `locked`, `check_failed`, `branch_mode`, `not_configured`) |

### Filtry

| Hook | Domyślnie | Zastosowanie |
| --- | --- | --- |
| `gthu_capability` | `manage_options`, a na multisite `manage_network_themes` | uprawnienie wymagane do obsługi wtyczki |
| `gthu_backup_dir` | `wp-content/gthu-backups` | katalog kopii zapasowych |
| `gthu_cache_lifetime` | `900` | czas życia cache listy wydań (sekundy) |
| `gthu_download_timeout` | `300` | limit czasu pobierania archiwum (sekundy) |
| `gthu_history_limit` | `5` | liczba operacji przechowywanych w dzienniku na zakładce Aktualizacja |
| `gthu_notification_recipients` | adresy z ustawień | kto dostaje raport; drugi argument to zdarzenie (`installed`, `failed`, `check_failed`, `test`) |
| `gthu_notification_message` | `['to', 'subject', 'body', 'headers']` | raport przed `wp_mail()`; zwróć tablicę bez `to`, aby go nie wysyłać |

Przykład — czyszczenie cache obiektowego po każdej aktualizacji:

```php
add_action( 'gthu_after_install', function ( $release, $summary ) {
    wp_cache_flush();
}, 10, 2 );
```

---

## FAQ — co może pójść nie tak

### Czy nieudana aktualizacja może położyć działającą stronę?

Do momentu wykonania kopii zapasowej katalog motywu nie jest w ogóle ruszany. Błąd pobierania, zły token, uszkodzone archiwum, brak `style.css` w paczce — wszystko to kończy się komunikatem i zerowymi zmianami na dysku. Jeśli aktualizacja wywali się później, przy kopiowaniu plików, motyw jest automatycznie przywracany z kopii zrobionej chwilę wcześniej.

Realne okno ryzyka jest jedno: między wyczyszczeniem katalogu motywu a skopiowaniem nowych plików. Przy dużym motywie trwa to kilka sekund i w tym czasie odwiedzający mogą zobaczyć błąd. Aktualizacje warto robić poza szczytem ruchu.

### Co, jeśli zamknę kartę w trakcie aktualizacji?

Instalator ustawia `ignore_user_abort( true )`, więc PHP dokańcza pracę mimo rozłączenia przeglądarki. Nie zobaczysz komunikatu o wyniku — ale jeśli wrócisz na zakładkę Aktualizacja, zanim aktualizacja się skończy, zobaczysz bieżący etap, a strona odświeży się sama po zakończeniu.

Gdyby proces został ubity twardo (restart PHP-FPM, przekroczony `max_execution_time` serwera), katalog motywu może zostać w stanie pośrednim. Wtedy: **Kopie zapasowe → Przywróć** ostatnią pozycję z listy.

### Co się stanie, jeśli dwie osoby klikną „Aktualizuj" jednocześnie?

Druga dostanie komunikat, że operacja już trwa. Aktualizacja, przywracanie kopii i ręczna kopia dzielą tę samą blokadę (opcja `gthu_install_lock`, honorowana 15 minut), zakładaną atomowo, więc nie da się ich uruchomić równolegle ani przeplatać, nawet gdy oba kliknięcia trafią w tę samą sekundę.

Jeśli proces został zabity i zostawił blokadę, zakładka Aktualizacja pokazuje, od kiedy blokada trwa, i daje przycisk **Zdejmij blokadę** — nie trzeba czekać tych 15 minut.

### Zmieniłem sole w wp-config.php i wtyczka przestała się łączyć

Token jest szyfrowany kluczem wyprowadzonym z `wp_salt( 'auth' )`. Zmiana soli (albo przeniesienie bazy na instalację z innym `wp-config.php`) sprawia, że zapisanego tokenu nie da się odszyfrować — trzeba wkleić go ponownie w Ustawieniach. Jeśli migrujesz strony między środowiskami, wygodniej trzymać token w stałej `GTHU_GITHUB_TOKEN`.

### Pliki, których nie ma w repozytorium, znikają po aktualizacji

Tak i jest to zamierzone: katalog motywu ma po aktualizacji odpowiadać zawartości repozytorium. Dotyczy to zarówno plików usuniętych z repo, jak i tych wgranych ręcznie na serwer. Wszystko, co ma przetrwać, musi trafić na listę **chronionych ścieżek** przed aktualizacją.

Najczęstszy przypadek: katalog `vendor/` albo `node_modules/` jest w `.gitignore`, więc nie ma go w archiwum z GitHuba — i po aktualizacji motyw przestaje działać. Dwa wyjścia: dopisać katalog do chronionych ścieżek albo dołączać do wydania zbudowaną paczkę ZIP i ustawić `asset_pattern`.

### Wgrałem wersję z błędem, strona się wysypała

Dwie drogi powrotu, obie w panelu:

1. **Kopie zapasowe → Przywróć** — wraca dokładnie to, co było przed aktualizacją.
2. **Aktualizacja → Wróć do wcześniejszej wersji** — instaluje wybrane starsze wydanie z GitHuba.

Jeśli panel jest niedostępny, kopie leżą w `wp-content/gthu-backups/` i można je przekopiować przez FTP.

### Czy wtyczka zaktualizuje motyw sama?

Tylko jeśli ją o to poprosisz. Domyślnie sprawdza wersje dwa razy dziennie i pokazuje powiadomienie, a instalację uruchamia człowiek. Panel „Aktualizacje automatyczne" na zakładce Ustawienia dodaje dwie opcjonalne drogi bez klikania: nocne uruchomienie o wybranej godzinie oraz webhook GitHuba, który aktualizuje w chwili opublikowania wydania. Obie idą tą samą ścieżką co przycisk — kopia zapasowa, weryfikacja, wycofanie, dziennik — i wysyłają raport e-mailem. Patrz [Aktualizacje automatyczne](#aktualizacje-automatyczne).

### Ustawiłem aktualizację na 3:00, a poszła o 6:40. Dlaczego?

Bo WordPress nie ma własnego zegara. WP-Cron, jego harmonogram, budzi się tylko wtedy, gdy jakieś żądanie uruchomi PHP na stronie — odsłona, ekran panelu, wywołanie REST. Wybrana godzina to więc „nie wcześniej niż": uruchomienie startuje z pierwszym takim żądaniem po niej. Typowe powody spóźnienia:

- **Brak odwiedzin w nocy.** Na cichej stronie zadanie czeka na pierwszego porannego odwiedzającego.
- **Cache stron albo CDN.** Strony z cache są serwowane bez PHP, więc nawet ruchliwa strona może wyglądać dla harmonogramu na pustą.
- **`DISABLE_WP_CRON` i cron systemowy.** Uruchomienie odbywa się przy najbliższym odpaleniu tego crona — co 5 minut, co godzinę, jak skonfigurował hosting.
- **Zajęta blokada.** Aktualizacja, przywracanie albo kopia zapasowa uruchomiona ręcznie w tym momencie przesuwa uruchomienie na następny dzień.
- **Zmiana strefy czasowej albo czasu letniego/zimowego.** Harmonogram jest poprawiany przy następnym uruchomieniu i zapisie ustawień, więc jedno uruchomienie może wypaść o godzinę obok.
- **Zajęty serwer.** WordPress uruchamia jeden proces crona naraz i pomija uruchomienie, którego nie może rozpocząć.

Aby godzina była pewna, dodaj `define( 'DISABLE_WP_CRON', true );` do `wp-config.php` i niech cron systemowy wywołuje `wp-cron.php` co kilka minut — patrz [Uruchomienie z harmonogramu](#uruchomienie-z-harmonogramu). Przycisk **Uruchom teraz** pod ustawieniami nie zależy od żadnej z tych rzeczy.

### Czy aktualizacja w nocy, bez nadzoru, jest bezpieczna?

Tak samo jak kliknięcie w dzień: to ta sama ścieżka w kodzie. Wtyczka pobiera wydanie, sprawdza, czy w archiwum jest motyw i czy to ten sam motyw, który leży na dysku, sprawdza, czy każdy plik do usunięcia da się usunąć, robi kopię zapasową i dopiero wtedy podmienia pliki. Błąd w trakcie kopiowania automatycznie przywraca kopię. Czego wtyczka nie wie, to czy *nowa wersja* motywu jest dobra — od tego jest strona testowa. Zostaw `create_backup` włączone i przeczytaj rano maila.

### W nocy nic się nie stało. Aktualizacja została pominięta?

Najpewniej nie było nic do zrobienia: uruchomienie instaluje tylko wtedy, gdy na GitHubie jest wydanie **nowsze** niż `installed_version`, a uruchomienie bez instalacji nie wysyła maila. Linia „Ostatnie automatyczne uruchomienie" na zakładce Ustawienia pokazuje, kiedy poszło i co zdecydowało („Nic do zrobienia: zainstalowana v1.4.0, najnowsza na GitHubie v1.4.0"). Jeśli ta linia jest stara, WP-Cron się nie obudził — patrz poprzednie pytanie. Jeśli mówi o zajętej blokadzie, ktoś w tym momencie aktualizował albo przywracał.

### Jakie maile będę dostawać?

Tylko o automatycznych uruchomieniach (harmonogram lub webhook) i tylko wtedy, gdy coś się wydarzyło: udana aktualizacja, nieudana próba albo nieudane sprawdzenie GitHuba (wygasły token, zmieniona nazwa repozytorium — zgłaszane raz na dany błąd, nie co noc). Aktualizacje uruchomione ręcznie z zakładki Aktualizacja nie są mailowane. Raport zawiera poprzednią i nową wersję, źródło uruchomienia, nazwę kopii zapasowej, liczbę plików, chronione ścieżki i opis wydania napisany na GitHubie. Jeśli nic nie przychodzi, kliknij **Wyślij testowy e-mail**: sam WordPress często ląduje w spamie, a wtyczka SMTP to naprawia.

### Czy adres webhooka można bezpiecznie wystawić na świat?

Tak. Gdy opcja jest wyłączona, adres nie istnieje (404). Gdy jest włączona, każde żądanie musi nieść podpis HMAC-SHA256 GitHuba pod sekretem, który znasz tylko Ty i GitHub; wszystko bez podpisu lub ze złym podpisem jest odrzucane, zanim zostanie odczytane dalej. Przyjmowane są wyłącznie zdarzenia „release published" dla skonfigurowanego repozytorium, powtórzone dostarczenia są ignorowane, a — co najważniejsze — żądanie nigdy nie decyduje, co zostanie zainstalowane. Ono tylko kolejkuje to samo uruchomienie, którego używa harmonogram, a ono pyta API GitHuba Twoim tokenem i instaluje najnowsze wydanie, jeśli jest nowsze. Szczegóły w [Webhook GitHuba](#webhook-githuba).

### Webhook na GitHubie pokazuje czerwony krzyżyk

Otwórz „Recent Deliveries" na GitHubie i spójrz na kod odpowiedzi. 404: opcja jest wyłączona na stronie albo adres wpisano z literówką. 401: żądanie nie miało podpisu — pole „Secret" na GitHubie jest puste. 403: sekret się różni albo webhook siedzi w innym repozytorium niż wpisane w Ustawieniach; zakładka Ustawienia pokazuje, które z nich. Timeout zwykle oznacza, że strona nie jest dostępna z internetu — lokalna strona deweloperska potrzebuje tunelu.

### Czy mogę aktualizować automatycznie z gałęzi?

Nie. Gałąź nie ma numeru wersji, więc wtyczka nie potrafi stwierdzić, czy bieżący stan kodu jest nowszy od zainstalowanego. Obie opcje automatyczne zapisują się, ale w trybie Gałąź nic nie robią; zakładka Ustawienia o tym informuje. Zamiast tego publikuj wydania — patrz krok 3 na zakładce Instrukcja.

### Czy stracę ustawienia motywu, treści albo widgety?

Nie — aktualizacja dotyczy wyłącznie plików w katalogu motywu. Baza danych, opcje motywu, Customizer, menu i treści zostają nietknięte.

### Czy potrzebuję tokenu do publicznego repozytorium?

Nie. Token jest wymagany tylko dla repozytoriów prywatnych. Warto go jednak dodać nawet przy publicznym — bez tokenu GitHub limituje ruch do 60 zapytań na godzinę z jednego adresu IP, co na współdzielonym hostingu potrafi się skończyć błędem 403.

### Repozytorium zawiera kilka motywów albo całe wp-content

Wtyczka szuka `style.css` i przy kilku motywach trafi na pierwszy znaleziony, co nie musi być tym właściwym. Zalecane: osobne repozytorium na motyw. Alternatywa: dołączać do wydania gotową paczkę ZIP z samym motywem i wskazać ją przez `asset_pattern`.

### Czy kopie zapasowe są widoczne z internetu?

Katalog dostaje `index.php`, `.htaccess` i `web.config` blokujące dostęp — to pokrywa Apache i IIS. Na nginx trzeba dodać regułę samodzielnie albo przenieść kopie poza katalog publiczny filtrem `gthu_backup_dir`. Kopie zajmują miejsce: rozmiar motywu razy liczba przechowywanych wersji.

Celowo **nie** leżą w `wp-content/upgrade/`: WordPress czyści ten katalog przed każdą aktualizacją rdzenia, wtyczki i motywu, więc zostałyby skasowane.

### Czy wordpress.org może nadpisać mój motyw albo tę wtyczkę?

Tylko wtedy, gdy motyw albo wtyczka w katalogu wordpress.org ma ten sam slug — wtedy core chętnie zaproponuje „aktualizację”, która podmieni Twój kod na cudzy. Wtyczka deklaruje `Update URI: false`, co każe WordPressowi nigdy nie szukać jej w katalogu, i filtruje `site_transient_update_themes`, usuwając slug zarządzanego motywu z odpowiedzi katalogu. Dla pewności dodaj też `Update URI: https://twoja-domena.example/` do `style.css` motywu.

### Czy działa na multisite?

Działa, ale traktuj to jako teren nieprzetestowany. Ponieważ katalog motywów jest wspólny dla całej sieci, wtyczka wymaga tam uprawnienia `manage_network_themes` — administrator pojedynczej strony nie podmieni motywu używanego przez pozostałe. Ustawienia są nadal per-site, więc dwie podstrony wskazujące ten sam katalog motywu nadpisywałyby się nawzajem; zakładka Aktualizacja o tym ostrzega.

### Czy mogę używać tego z motywem potomnym?

Tak, wtyczka zarządza jednym katalogiem — tym z pola „Katalog motywu". Może to być motyw nadrzędny albo potomny. Dwa katalogi jednocześnie wymagałyby drugiej instancji wtyczki.

---

## Rozwój

```bash
composer install     # zależności deweloperskie
composer test        # PHPUnit
composer phpcs       # WordPress Coding Standards
composer phpstan     # analiza statyczna, poziom 6
composer phpcbf      # automatyczne poprawki
composer check       # lint + phpcs + phpstan + testy
```

Każdy push i pull request uruchamia te same cztery kontrole na GitHub Actions
([`.github/workflows/ci.yml`](.github/workflows/ci.yml)): sprawdzenie składni
i testy na PHP od 7.4 do 8.3 oraz PHPCS i PHPStan na 8.3.

Testy pokrywają klasy z najbardziej ryzykowną logiką, które nie zależą od
WordPressa: rozpoznawanie repozytorium, dopasowywanie chronionych ścieżek,
porównywanie wersji wydań, szyfrowanie tokenu i walidację nazwy katalogu motywu.
`tests/bootstrap.php` zaślepia te kilka funkcji WordPressa, których używają, więc
do uruchomienia testów nie trzeba instalacji WordPressa.

Wtyczka przechodzi `phpcs` na rulesecie WordPress bez błędów i ostrzeżeń oraz
PHPStan na poziomie 6 bez błędów. Każdy komentarz `phpcs:ignore` w kodzie ma
podane uzasadnienie.

> PHPStan nie zaindeksuje projektu, którego ścieżka zawiera znaki spoza ASCII.
> Jeśli trzymasz wtyczkę w katalogu w rodzaju `.moduły/`, uruchom analizę
> z kopii w ścieżce ASCII albo zostaw to CI.

---

## Historia zmian

Zobacz [CHANGELOG.md](CHANGELOG.md) (po angielsku).
---

## Rozwiązywanie problemów

Wersja dla użytkownika strony jest w panelu: **Motyw z GitHuba → Instrukcja → „Gdy coś nie działa"**. Poniżej to, co przydaje się przy diagnozie po stronie kodu.

### Komunikaty z GitHuba

| Komunikat | Przyczyna | Co zrobić |
| --- | --- | --- |
| `GitHub odrzucił token (401)` | token wygasł albo jest niekompletny | wygenerować nowy; przy fine-grained sprawdzić datę ważności |
| `Brak uprawnień do tego repozytorium (403)` | token nie obejmuje repozytorium | fine-grained: repozytorium na liście „Only select repositories" + `Contents: Read-only`; klasyczny: zakres `repo` |
| `Przekroczono limit zapytań (403)` | wyczerpany rate limit | odczekać; nagłówek `x-ratelimit-remaining` jest sprawdzany i rozróżniany od braku uprawnień |
| `Nie znaleziono repozytorium (404)` | literówka albo prywatne repo bez tokenu | sprawdzić `właściciel/repozytorium` i czy token jest zapisany |
| `W repozytorium … nie ma jeszcze żadnego wydania` | brak releases | utworzyć wydanie albo przełączyć `source` na `branch` |

### Problemy z plikami

**`WordPress nie ma bezpośredniego dostępu do plików (metoda: ftpext)`**
Wtyczka celowo obsługuje wyłącznie transport `direct` — aktualizacja nie ma jak zapytać o dane FTP w połowie kasowania motywu. Rozwiązanie:

```php
define( 'FS_METHOD', 'direct' );
```

Jeśli to nie pomaga, problem leży w uprawnieniach do `wp-content/themes` (właściciel katalogu musi zgadzać się z użytkownikiem, na którym działa PHP).

**`W pobranym archiwum nie znaleziono pliku style.css`**
`style.css` musi leżeć w katalogu głównym repozytorium albo maksymalnie trzy poziomy niżej (`Filesystem::locate_theme_root()`). Jeśli repozytorium zawiera całe `wp-content`, trzeba wskazać repozytorium z samym motywem albo dołączyć do wydania zbudowaną paczkę i ustawić `asset_pattern`.

**`vendor nie może zostać usunięte przez użytkownika serwera WWW` / `Nie udało się usunąć …`**
Sprawdzenie wstępne (krok 4 powyżej) znalazło ścieżkę, której użytkownik PHP nie może usunąć, i zatrzymało się, zanim cokolwiek ruszyło. Na Linuksie to niemal zawsze pliki należące do innego użytkownika — zwykle wgrane przez SFTP z innego konta niż to, na którym działa PHP-FPM; trzeba zrobić im `chown` na użytkownika PHP albo nadać katalogom prawo zapisu dla grupy. Na Windowsie winowajcą jest zwykle atrybut „tylko do odczytu”, który git ustawia na plikach pack w katalogach `.git` (także zagnieżdżonych w paczkach `vendor` instalowanych z VCS); wtyczka sama go zdejmuje, więc ten komunikat oznacza, że plik trzyma coś innego, np. edytor albo indekser. Ścieżka, która nie jest częścią motywu, i tak powinna trafić na listę ignorowanych.

**Aktualizacja przerywa się w połowie**
`Theme_Installer::run()` ustawia `set_time_limit( 600 )` i `ignore_user_abort( true )`, ale nie przebije twardych limitów serwera. Przy dużych motywach warto sprawdzić `max_execution_time` oraz limity proxy (np. `proxy_read_timeout` w nginx). Limit samego pobierania zmienia filtr `gthu_download_timeout`.

### Stan i cache

- Lista wydań jest w transiencie `gthu_releases_cache` (domyślnie 15 minut). Przycisk **Sprawdź ponownie** czyści go i pobiera dane na nowo; cache czyści się też sam po zmianie repozytorium lub tokenu.
- Ostatni błąd zapisuje się w `gthu_state.last_error` i jest widoczny na dole zakładki Aktualizacja.
- Powiadomienia o nowych wersjach opierają się na cronie WordPressa (`gthu_check_for_updates`, dwa razy dziennie), podobnie jak automatyczna aktualizacja z harmonogramu (`gthu_auto_update`, codziennie o wybranej godzinie) i uruchomienie z webhooka (`gthu_webhook_update`, zdarzenia jednorazowe). Przy `DISABLE_WP_CRON` trzeba mieć skonfigurowany cron systemowy, inaczej `latest_version` nie odświeży się samo, a nocne uruchomienie czeka na odwiedzającego — ręczne sprawdzenie i przycisk **Uruchom teraz** działają niezależnie.

```bash
wp cron event list | grep gthu          # czy zadania są zaplanowane i na kiedy
wp cron event run gthu_check_for_updates
wp cron event run gthu_auto_update      # nocne uruchomienie, od razu
wp option get gthu_state --format=json  # stan: wersje, ostatnie sprawdzenie, ostatni błąd, ostatnie automatyczne uruchomienie, ostatni webhook
wp option get gthu_history --format=json  # ostatnie aktualizacje i przywrócenia
wp transient delete gthu_releases_cache
```

### Zmiany zniknęły po aktualizacji

Aktualizacja zastępuje zawartość katalogu motywu wersją z repozytorium. Wszystko, co ma przetrwać, musi być na liście chronionych ścieżek **przed** aktualizacją. Jeśli już przepadło — zakładka **Kopie zapasowe** zawiera zrzut sprzed aktualizacji (o ile `create_backup` było włączone).

---

## Migracja z wersji 1.0

Przy pierwszym uruchomieniu wtyczka przepisuje stare opcje (`gthu_github_repo`, `gthu_github_token`, `gthu_theme_slug`, `gthu_last_installed_version`) do nowego schematu. Stare opcje zostają nietknięte, żeby dało się wrócić do poprzedniej wersji.

Co zmieniło się w zachowaniu:

- W polu repozytorium wystarczy `właściciel/repozytorium` — pełny adres API też zadziała i zostanie znormalizowany.
- Katalog motywu w archiwum jest wyszukiwany po `style.css`, a nie po nazwie zgodnej ze slugiem. Wersja 1.0 szukała katalogu o nazwie sluga, czego archiwa GitHuba nigdy nie zawierają.
- Ochrona `/languages` nie jest już wpisana w kod — to pozycja na konfigurowalnej liście.
- Wersja zapisywana po aktualizacji to tag wydania, a nie nazwa pliku archiwum.
- Formularze wymagają nonce i uprawnień; w 1.0 aktualizację mógł wywołać każdy zalogowany użytkownik, który trafił na adres panelu.
- Zapis plików idzie przez `WP_Filesystem`, a nie przez bezpośrednie `unlink()`/`copy()`.

---

## Tłumaczenia

Językiem źródłowym jest angielski. Wszystkie napisy przechodzą przez funkcje i18n
z domeną `github-theme-updater`, a wtyczka zawiera tłumaczenie polskie:

```
languages/
  github-theme-updater.pot   szablon, 236 stringów z komentarzami dla tłumaczy
  pl_PL.po                   tłumaczenie polskie
  pl_PL.mo                   skompilowane, to ten plik ładuje WordPress
```

Strona działająca po polsku podchwyci `pl_PL` automatycznie. Żeby dodać kolejny
język, skopiuj `.pot`, przetłumacz i skompiluj:

```bash
cp languages/github-theme-updater.pot languages/de_DE.po
# przetłumacz de_DE.po, następnie:
wp i18n make-mo languages/
```

Po zmianie napisów w kodzie odśwież szablon:

```bash
wp i18n make-pot . languages/github-theme-updater.pot --domain=github-theme-updater
```

Na WordPressie 6.5 i nowszym `wp i18n make-php languages/` wygeneruje dodatkowo
pliki `.l10n.php`, które ładują się szybciej niż `.mo`.
