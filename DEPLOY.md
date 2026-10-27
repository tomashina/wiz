# Deploy vodic

Ovaj projekt je OpenCart instalacija. Web root je `upload/`, a privatni storage je `storagewiz/`.

## Git setup

Nakon sto kreiras prazan remote repozitorij na GitHubu/GitLabu/Bitbucketu:

```bash
git remote add origin git@github.com:USER/REPO.git
git add .
git commit -m "Initial project import"
git push -u origin main
```

`upload/config.php` i `upload/urednik/config.php` nisu u Gitu jer sadrze server-specific putanje i podatke za bazu.

`upload/image/catalog/` mediji nisu u Gitu jer je katalog velik. Njega prebaci odvojeno na server, npr. preko `rsync`.

## Server install

Primjer za Linux server:

```bash
cd /var/www
git clone git@github.com:USER/REPO.git wiz
cd wiz
```

Document root u web serveru postavi na:

```text
/var/www/wiz/upload
```

Privatni storage ostaje izvan document root-a:

```text
/var/www/wiz/storagewiz
```

Na server prebaci ili izradi server verzije ovih fajlova:

```text
upload/config.php
upload/urednik/config.php
```

U njima treba podesiti URL, DB podatke i apsolutne putanje za server, posebno `DIR_APPLICATION`, `DIR_SYSTEM`, `DIR_IMAGE`, `DIR_STORAGE` i slicne OpenCart konstante.

Medije prebaci odvojeno:

```bash
rsync -avz upload/image/catalog/ USER@SERVER:/var/www/wiz/upload/image/catalog/
```

Runtime direktoriji trebaju biti writable za web server usera:

```bash
sudo chown -R www-data:www-data storagewiz upload/image/cache
sudo chmod -R 775 storagewiz/cache storagewiz/logs storagewiz/session storagewiz/upload storagewiz/download storagewiz/modification upload/image/cache
```

Ako server koristi drugi web user, zamijeni `www-data` odgovarajucim userom, npr. `nginx`, `apache` ili hosting korisnikom.

## Baza

Baza nije dio repozitorija. Na serveru treba:

1. kreirati MySQL/MariaDB bazu i usera
2. importati dump postojece baze
3. upisati server DB podatke u oba config fajla

## Update deploy

Za kasnije nadogradnje:

```bash
cd /var/www/wiz
git pull
```

Nakon izmjena koje diraju OCMOD/modifikacije, ocisti OpenCart cache i osvjezi modifications iz admina.

## XML karta web-lokacije (Google indeksiranje)

Glavna karta za indeksiranje je dinamicki sitemap indeks:

```text
https://www.wiz.hr/sitemap.xml
```

Indeks upucuje na karte `/sitemap-products-1.xml`, `/sitemap-categories-1.xml`,
`/sitemap-manufacturers-1.xml` i `/sitemap-information-1.xml`. Svaka karta sadrzi
najvise 1000 URL-ova; dodatne se stranice automatski dodaju prema velicini kataloga.
Generiranje ne ovisi o statusu starog Google Sitemap feed modula niti o HuntBee
postavkama. Zaseban cron ili izrada fizickih XML datoteka nisu potrebni.

Karte ukljucuju omogucene proizvode kojima je nastupio `date_available`, ukljucujuci
proizvode bez zalihe, te omogucene kategorije trenutne trgovine i jezika. Zadrzane
su i javne stranice proizvodjaca i informacija. URL-ovi proizvoda nemaju kategoriju
u putanji; kategorije koriste isti URL kao vlastita kanonska oznaka. `lastmod`
se navodi samo kada proizvod ili kategorija imaju valjan, nebuduci datum promjene.

Pri objavi ovih SEO promjena:

1. Objavi sve izmijenjene datoteke, ukljucujuci novi model
   `upload/catalog/model/extension/feed/google_sitemap.php`, kontroler,
   `upload/.htaccess`, `upload/robots.txt` i izmjene kanonskih URL-ova.
2. U administraciji otvori **Extensions > Modifications** i osvjezi modifikacije,
   zatim ocisti OpenCart i cache teme kako stari URL-ovi ne bi ostali u prikazu.
3. Provjeri da `config_url` i `config_ssl` na produkciji koriste kanonsku adresu
   `https://www.wiz.hr/` i da su SEO URL-ovi ukljuceni.
4. Otvori indeks i sve karte navedene u njemu: svaka mora vratiti HTTP 200 i valjan
   XML. Provjeri da se uzorak URL-a proizvoda iz karte podudara s njegovim
   canonicalom, a stari URL s kategorijom vraca 301 na taj URL.
5. Provjeri da produkcijski `robots.txt` sadrzi
   `Sitemap: https://www.wiz.hr/sitemap.xml`.
6. U Google Search Console za svojstvo `https://www.wiz.hr/` (ili domensko
   svojstvo `wiz.hr`) otvori **Sitemaps**, unesi puni URL
   `https://www.wiz.hr/sitemap.xml` (ili `sitemap.xml` ako je prefiks vec prikazan)
   i klikni **Submit**.
   Prijava zahtijeva pristup odgovarajucem Search Console svojstvu; nije dio
   samog deploya. Ako je stara HuntBee karta vec prijavljena, ukloni staru prijavu
   nakon uspjesnog dohvata nove karte.

Brza provjera nakon deploya:

```bash
curl --fail --silent --show-error https://www.wiz.hr/sitemap.xml | xmllint --noout -
curl --fail --silent --show-error https://www.wiz.hr/sitemap-products-1.xml | xmllint --noout -
curl --fail --silent --show-error https://www.wiz.hr/sitemap-categories-1.xml | xmllint --noout -
```

Ova karta sluzi za Google indeksiranje. `googlebase.xml` ostaje zaseban Merchant
Center feed i ne prijavljuje se kao sitemap.

## Google Merchant XML feed

Produkcijski URL feeda je:

```text
https://www.wiz.hr/googlebase.xml
```

Nakon prvog deploya otvori **Google Merchant XML** preko precaca desno na nadzornoj ploci. Modul ce, ako mapiranja jos ne postoje, postaviti pocetna mapiranja wiz.hr kategorija. Provjeri da je status ukljucen i spremi postavke.

U Merchant Centeru izaberi data source iz datoteke i postavi **Scheduled fetch** jednom dnevno:

- URL: `https://www.wiz.hr/googlebase.xml`
- jezik: hrvatski
- valuta: EUR
- zemlja: Hrvatska

Scheduled fetch je dnevni cron za ovaj dinamicki feed: XML se generira iz trenutasnih cijena, kolicina i statusa pri svakom Googleovu dohvacanju, pa zaseban server cron nije potreban.

Brza provjera nakon deploya:

```bash
curl --fail --silent --show-error https://www.wiz.hr/googlebase.xml | xmllint --noout -
```
