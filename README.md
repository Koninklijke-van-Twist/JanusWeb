# Janus

Webversie van de Janus urenregistratie (WinForms-port).

Uren, kilometers, vakantie/ziek, overuren en PDF-rapportages. Data wordt per gebruiker opgeslagen als `web/cache/hours/{email}.json` in hetzelfde JSON-formaat als de desktop `savedata.json`, zodat bestaande bestanden geïmporteerd kunnen worden.

OData-lezingen gaan via Mímir wanneer `$mimirApi` in `web/auth.php` staat. Die key hoort naast de Business Central-gegevens te blijven: `$baseUrl`, `$environment`, `$auth` en `$auth_list`. Faalt Mímir (verbinding, timeout, non-2xx, ongeldige JSON of een foutpayload), dan haalt Janus dezelfde data via de directe BC-route en de lokale odata-filecache, en slaat Mímir voor de rest van dat PHP-verzoek over. Zonder die BC-gegevens wordt de oorspronkelijke Mímir-fout opnieuw gegooid. Zonder `$mimirApi` blijft alleen de directe route actief. Webverzoeken en CLI (er is geen aparte nightly OData-job in deze repo; `odata.php` geldt voor beide) gebruiken dezelfde fallback. Mímir-timeouts: connect 10s, totaal ongeveer 90s op het web en 600s in CLI.
