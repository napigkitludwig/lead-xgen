# lead-xgen
Lead xGenerator is a modern, high-performance lead generation and management platform built on the Laravel framework. Designed to streamline prospect capture, automated scoring, and pipeline tracking, it empowers sales and marketing teams to turn cold interactions into high-value opportunities with speed and precision.

## Tech Stack

- [Laravel](https://laravel.com) 13 (PHP 8.3+)
- [Livewire](https://livewire.laravel.com) 4 and [Flux UI](https://fluxui.dev) for the interface
- [Laravel Fortify](https://laravel.com/docs/fortify) for authentication
- [Vite](https://vite.dev) for frontend assets
- MySQL

## Requirements

- PHP 8.3 or higher (the project is developed on PHP 8.5)
- [Composer](https://getcomposer.org)
- Node.js and npm
- MySQL 8+ (or MariaDB)

## Installation

1. Clone the repository and enter the project folder:

   ```bash
   git clone https://github.com/napigkitludwig/lead-xgen.git
   cd lead-xgen
   ```

2. Create an empty MySQL database named `leadxgen_db`.

3. Copy the environment file and set your database credentials. The defaults in `.env.example` are:

   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=leadxgen_db
   DB_USERNAME=root
   DB_PASSWORD=
   ```

4. Run the setup script. It installs the PHP and Node dependencies, creates `.env` if missing, generates the app key, runs the migrations and builds the frontend assets:

   ```bash
   composer run setup
   ```

5. Install the browser that Playwright uses for scraping:

   ```bash
   npx playwright install chromium
   ```

## Running the Application

Start the development environment (web server, queue worker and Vite):

```bash
composer run dev
```

Then open <http://127.0.0.1:8000> in your browser. Press `Ctrl+C` to stop everything.

If Vite's default port is busy it picks the next free one automatically; no action is needed.

## Generating Leads

1. Register or log in, then open **Generate Leads** in the sidebar.
2. **Choose a source.** Google Maps and Facebook are available (Facebook needs the one-time login described below). LinkedIn is listed but disabled, because it restricts automated access.
3. **Choose how to find leads:** a specific town/city, multiple towns/cities, a specific country, multiple countries, or a geofence (latitude/longitude plus a radius in kilometres or miles).
4. **Choose business categories** (dental clinics, orthodontists, periodontists and more) and the maximum results per search.
5. The request is queued and scraped by Playwright in the background. Open **Lead Requests** to see every request with its date and time, status and lead count.
6. Open a request to see its leads, then click **Export to Excel** or **Export to Google Sheets**.

Each lead has a business name, email, phone, first name, last name, category, website, location, town, zip code and country. Leads that have both an email and a phone number are listed (and exported) first; tick "Only keep leads that have both an email and a phone number" when generating to drop the rest. The Location is a link that opens the business in Google Maps, in the table and in the exports. Town, zip code and country are read from the address, so they can be empty or occasionally wrong for unusual address formats. Phone, website and address come from Google Maps. Email and contact name are looked up on the business's own website, so they are filled in only when that site shows them.

### Facebook setup

Facebook only shows page search results to logged-in visitors, so save a login session once:

```bash
node resources/scrapers/facebook-login.mjs
```

A browser window opens. Log in, and the session is saved to `storage/app/private/facebook-session.json` (change the location with `FACEBOOK_SESSION_FILE` in `.env`). Use a dedicated Facebook account, because automated access can get an account restricted. Facebook searches use place names, so the geofence option is not available for this source. Redo this step if the session expires.

### Google Sheets setup

Exports use a Google service account, so no per-user Google login is needed:

1. In [Google Cloud Console](https://console.cloud.google.com), create a project and enable the **Google Sheets API** and **Google Drive API**.
2. Create a **service account** and download its JSON key.
3. Save the file somewhere outside the repository and set its full path in `.env`:

   ```env
   GOOGLE_SERVICE_ACCOUNT_JSON=C:/secrets/lead-xgen-service-account.json
   ```

4. On a request page, click **Export to Google Sheets**, enter your Google account email, and the new sheet is shared with you.

Notes:

- The queue worker must be running (`composer run dev` starts it); without it requests stay *Pending*.
- Google Maps returns about 120 results per search. For a whole country, list its main cities instead.
- Scraping may be restricted by the terms of the source site, and emails and names can be personal data under laws such as GDPR. Use the data only for lawful business outreach.
- Set `SCRAPER_NODE_BINARY` in `.env` if `node` is not on the PATH of the queue worker.

## Testing and Code Style

```bash
php artisan test --compact   # run the test suite
composer run lint            # format code with Laravel Pint
```

## Troubleshooting

- **Wrong PHP version:** run `php -v`. On Windows, XAMPP's PHP can shadow a newer install on your `PATH`; put the PHP 8.3+ binary first.
- **Frontend changes not showing, or a "Unable to locate file in Vite manifest" error:** run `npm run build`, or keep `composer run dev` running.
- **Database connection errors:** make sure MySQL is running and the `DB_*` values in `.env` match your setup.
