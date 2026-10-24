# Deployment guide

For a Linux server (Ubuntu 22.04/24.04 or Debian 12) with Apache **or** Nginx, PHP-FPM and MySQL/MariaDB. About an hour the first time.

> **What has and has not been tested.** The application, the release archive (`tests/release-check.sh`) and the configuration templates were checked on PHP 8.3 with SQLite, and the templates were checked for consistency with the code. They were **not** applied to a live Apache/Nginx server and the application has **not** been run against a real MySQL/MariaDB server, because none was available while it was built. Step 9 and the checks in step 11 exist to find any such problem on day one, on a copy, before real data is entered. Do this on a staging server first.

## 0. What you need

| | |
|---|---|
| Server | 1 CPU, 1 GB RAM, 5 GB disk is enough for tens of thousands of jobs |
| PHP | 8.1 or newer, with `pdo_mysql`, `mbstring`, `fileinfo`, `zip`, `ctype`, `json` |
| Database | MySQL 8.0+ or MariaDB 10.5+, `utf8mb4` |
| Web server | Apache 2.4 (+ `mod_rewrite`) or Nginx, serving from the `public/` folder |
| HTTPS | A domain name and a certificate (Let's Encrypt is free) |
| Access | `sudo` on the server |

## 1. Install the software (Ubuntu example)

```bash
sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-zip php8.3-xml mariadb-server
# then ONE of:
sudo apt install -y nginx                       # Nginx
sudo apt install -y apache2 libapache2-mod-fcgid  # Apache (uses PHP-FPM via proxy_fcgi)
sudo mysql_secure_installation
```
Use the PHP version your distribution provides (8.1 or newer) wherever this guide says `8.3`.

## 2. Create the database and its user

```sql
CREATE DATABASE job_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'job_tracker'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, SHOW VIEW, TRIGGER
  ON job_tracker.* TO 'job_tracker'@'localhost';
FLUSH PRIVILEGES;
```
The application never needs access to any other database. `CREATE/ALTER/INDEX/DROP/REFERENCES` are used by `php cli/migrate.php` (also when you upgrade); `SHOW VIEW, TRIGGER` let `mysqldump` take backups.

## 3. Put the files in place

```bash
sudo mkdir -p /var/www/job-tracker
sudo tar -xzf job-tracker-1.0.0.tar.gz -C /var/www/job-tracker --strip-components=1
cd /var/www/job-tracker
sha256sum -c ../job-tracker-1.0.0.tar.gz.sha256     # if you copied the checksum file next to the archive
```
Build the archive on your own machine with `scripts/build-release.sh` (see `docs/operations.md`).

Permissions: the code is read-only for the web server; only `storage/` is writable.
```bash
sudo chown -R root:root /var/www/job-tracker
sudo chown -R www-data:www-data /var/www/job-tracker/storage   # nginx/apache user; use the PHP-FPM pool user if different
sudo chmod -R u=rwX,g=rX,o= /var/www/job-tracker                 # nothing readable by "others"
sudo chmod 700 /var/www/job-tracker/storage/backups
```

## 4. Configure

```bash
cd /var/www/job-tracker
sudo cp deploy/env.production.example .env
sudo nano .env            # set APP_URL, DB_PASSWORD (and DB_* if different)
sudo chown root:www-data .env && sudo chmod 640 .env
```
Never put `.env` inside `public/`. If the app is served from a sub-folder (e.g. `https://example.com/jobs/`), include it in `APP_URL`.

PHP settings: copy `deploy/php-overrides.ini` to `/etc/php/8.3/fpm/conf.d/90-job-tracker.ini` **and** to `/etc/php/8.3/cli/conf.d/` (the cron scripts use the CLI), then `sudo systemctl restart php8.3-fpm`.

## 5. Web server

**Nginx:** `sudo cp deploy/nginx.conf.example /etc/nginx/sites-available/job-tracker`, edit the lines marked `EDIT`, `sudo ln -s /etc/nginx/sites-available/job-tracker /etc/nginx/sites-enabled/`, `sudo nginx -t && sudo systemctl reload nginx`.

**Apache:** `sudo cp deploy/apache-vhost.conf.example /etc/apache2/sites-available/job-tracker.conf`, edit the lines marked `EDIT`, then
`sudo a2enmod rewrite headers ssl proxy_fcgi setenvif expires && sudo a2enconf php8.3-fpm && sudo a2ensite job-tracker && sudo apachectl configtest && sudo systemctl reload apache2`.

The one rule that matters: **the document root is the `public/` folder.** Everything else (`app/`, `storage/`, `database/`, `.env`) must not be reachable from the web.

## 6. HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx     # or python3-certbot-apache
sudo certbot --nginx -d jobs.example.com              # or --apache
```
Certificates renew automatically (`systemctl list-timers | grep certbot`). In `.env` keep `SESSION_SECURE=true`.

## 7. Create the tables and the first administrator

```bash
cd /var/www/job-tracker
sudo -u www-data php cli/migrate.php
sudo -u www-data php cli/seed.php
sudo -u www-data php cli/create-admin.php --username=owner --name="Your Name"
```
`create-admin` prints a one-time password. Sign in with the **username** you gave and that password; you are asked to choose your own. No default or demo accounts exist, and none should be created in production.

## 8. Self-check

```bash
sudo -u www-data php cli/doctor.php         # must end with "Ready"; fix any [FAIL] and read each [warn]
sudo -u www-data php cli/route-audit.php    # must say "No problems found."
```

## 9. Check it against the real database (first deployment only)

Because MySQL could not be tested while building, do this on staging before real data goes in:
1. Open the site, sign in, create a job, edit it, dispatch it, deliver it, delete and restore it.
2. Import `Import Jobs -> CSV template` filled with 5 rows (one wrong), then export to CSV and Excel.
3. `php cli/backup.php` and confirm a `.sql.gz` appears in `storage/backups/`.
4. Open **Audit Log** and confirm every step above is listed.
5. If you have the source repository: copy `tests/` to the server and run `tests/smoke-jobs.sh https://your-staging-site ...` (see the README). Report any failure; do not enter real data until all pass.

## 10. Schedule the housekeeping

```bash
sudo -u www-data crontab -e      # paste the lines from deploy/cron.example (edit APP=...)
```
This runs the daily backup and cleanup and a weekly self-check.

## 11. Go-live checklist

- [ ] `php cli/doctor.php` says **Ready** with no warnings you do not understand
- [ ] The site opens only over **HTTPS**; `http://` redirects
- [ ] `https://your-domain/.env`, `/app/Config/app.php`, `/storage/logs/` and `/database/` all show **404**
- [ ] The administrator signed in and **changed the temporary password**
- [ ] Demo accounts do not exist (`doctor` fails in production if any `@example.com` user exists)
- [ ] A backup ran and a copy is **off the server** (docs/operations.md)
- [ ] A **restore was rehearsed** on another machine from that backup
- [ ] Users were created for the team with the right roles (docs/admin-guide.md)
- [ ] The client signed off the UAT checklist (docs/uat-checklist.md)

## Shared hosting / cPanel

Possible if the host offers PHP 8.1+ with the extensions in step 0 and MySQL. There are two ways; use the first if you can.

**A. Document root = `public/` (best).** In cPanel *Domains* (or *Subdomains*) set the domain's **Document Root** to `<app folder>/public`, for example `/home/you/job-tracker/public`. Put the application **above** `public_html` if you can. In `.env`: `APP_URL=https://jobs.example.com`.

**B. The host cannot change the document root** (e.g. the app is uploaded into `public_html/admin`, so it is reached as `https://example.com/admin/`). The root `.htaccess` that ships with the application then serves everything from `public/` and keeps `app/`, `storage/`, `database/`, `.env` and the other private parts blocked. Requirements: `mod_rewrite` (standard on cPanel) and **`APP_URL` must include the folder**:
```
APP_URL=https://tracking-management.demo.softorio.com/admin
```
(no trailing slash, no `/public`). Then open `https://tracking-management.demo.softorio.com/admin/`. If you see the server's own **403 "Access to this resource on the server is denied"** page, the root `.htaccess` is an old version: upload the current one. If you see **404**, `mod_rewrite` rules in `.htaccess` are not being applied (ask the host to enable `AllowOverride All`).

For both:
- Create the database and user in cPanel (*MySQL Databases*), add the user to the database with all privileges, and put the values in `.env` (`DB_HOST` is usually `localhost`; cPanel prefixes names, e.g. `cpuser_jobtracker`).
- Run the commands from step 7 in the cPanel **Terminal** (or SSH): `php cli/migrate.php`, `php cli/seed.php`, `php cli/create-admin.php ...`, `php cli/doctor.php`. If `php` is an old version there, use the full path your host documents, e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`.
- Set the PHP version to 8.1+ and `upload_max_filesize`/`post_max_size` (>= 6M) in *MultiPHP Manager* / *MultiPHP INI Editor*.
- Make `storage/` writable by the account (normally already true on cPanel; `chmod -R u+rwX storage`).
- Add the cron lines from `deploy/cron.example` in *Cron Jobs* (use the full PHP path).
- Do not copy the files into `public_html` loose: keep them in a folder and use A or B.

## Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| White page or "Something went wrong" | Read `storage/logs/app-YYYY-MM-DD.log` and the web server error log. Usually a wrong `.env` value, a missing PHP extension (`doctor` shows it) or `storage/` not writable |
| Server's own **403 Forbidden** page on the site root or `/admin/` | Old root `.htaccess` (it refused everything outside `public/`), or the document root points at the wrong folder: see *Shared hosting / cPanel* |
| 404 on every page except `/` | URL rewriting is off: Apache `AllowOverride All` + `mod_rewrite`, or Nginx `try_files` as in the template |
| Pages work but styles are missing | Document root is not `public/`, or `public/assets/vendor/` was not shipped (the release builder refuses to build without it) |
| "File too large" on import | `upload_max_filesize` / `post_max_size` in PHP and `client_max_body_size` / `LimitRequestBody` in the web server (templates set 6M/8M) |
| Signed out all the time | `SESSION_SECURE=true` but the site is reached over plain HTTP; use HTTPS |
| Everyone shares one IP and gets throttled | Behind a proxy: set `TRUST_PROXY=true` only if the proxy overwrites `X-Forwarded-For` |
| Times are off by hours | Settings -> Business time zone |
| Database error on first run | Check `DB_*` in `.env`; `mysql -u job_tracker -p job_tracker` should connect; the user needs the grants in step 2 |
