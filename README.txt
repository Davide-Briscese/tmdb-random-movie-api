=== TMDB Random Movie API ===
Contributors: Davide Briscese
Tags: tmdb, api, rest, movie, random, authentication, security
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Endpoint API REST personalizzato che recupera un film casuale da The Movie Database (TMDB). Accessibile solo a utenti autenticati.

== Description ==

Plugin WordPress che aggiunge un endpoint REST protetto per recuperare informazioni sui film da TMDB.

= Caratteristiche principali =

* Endpoint REST: /wp-json/tmdb-random-movie/v1/random-movie
* Autenticazione obbligatoria (solo utenti loggati)
* Disabilitazione selettiva degli endpoint REST nativi di WordPress
* Selezione casuale di film da 5 liste TMDB
* Sistema di caching integrato
* Pannello di amministrazione completo
* Shortcode per il frontend: [tmdb_random_movie]

= Requisiti di sicurezza =

* Endpoint accessibile SOLO a utenti autenticati
* Sanitizzazione di tutti gli input
* Protezione contro accessi non autorizzati
* Supporto X-WP-Nonce per chiamate AJAX

== Installation ==

1. Carica la cartella 'tmdb-random-movie-api' in '/wp-content/plugins/'
2. Attiva il plugin
3. Vai su "Impostazioni → TMDB Random Movie"
4. Inserisci la tua API Key di TMDB
5. Configura le opzioni e salva

== Usage ==

Endpoint API: GET /wp-json/tmdb-random-movie/v1/random-movie

Parametri:
- list_type: trending, popular, top_rated, now_playing, upcoming
- language: it-IT, en-US, ecc.
- region: IT, US, ecc.

Shortcode: [tmdb_random_movie]

Esempio Shortcode personalizzato: [tmdb_random_movie list_type="top_rated" language="it-IT" button_text="⭐ SCOPRI UN FILM ⭐"]

== Changelog ==

= 1.0.2 =
* Fix: Corretta disabilitazione endpoint REST nativi
* Aggiunte istruzioni cURL complete nella pagina admin

= 1.0.0 =
* Rilascio iniziale