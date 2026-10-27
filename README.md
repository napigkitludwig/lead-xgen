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

## Running the Application

Start the development environment (web server, queue worker and Vite):

```bash
composer run dev
```

Then open <http://127.0.0.1:8000> in your browser. Press `Ctrl+C` to stop everything.

If Vite's default port is busy it picks the next free one automatically; no action is needed.

## Testing and Code Style

```bash
php artisan test --compact   # run the test suite
composer run lint            # format code with Laravel Pint
```

## Troubleshooting

- **Wrong PHP version:** run `php -v`. On Windows, XAMPP's PHP can shadow a newer install on your `PATH`; put the PHP 8.3+ binary first.
- **Frontend changes not showing, or a "Unable to locate file in Vite manifest" error:** run `npm run build`, or keep `composer run dev` running.
- **Database connection errors:** make sure MySQL is running and the `DB_*` values in `.env` match your setup.
