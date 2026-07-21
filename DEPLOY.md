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
