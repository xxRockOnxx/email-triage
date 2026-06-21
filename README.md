# Email Triage

Automated email triage for a Gmail inbox. The app polls Gmail over IMAP, strips
personally identifiable information with [Microsoft Presidio](https://microsoft.github.io/presidio/),
classifies and drafts replies with a local LLM, and embeds messages with a local
embedding model for similarity work — all surfaced through a Vue dashboard where
you can review, approve, correct, archive, delete, flag, or draft replies.

## Tech stack

- **Backend:** Laravel 13 (PHP 8.5)
- **Frontend:** Inertia.js + Vue 3, Tailwind CSS v4, Vite
- **Database:** PostgreSQL 18 with [pgvector](https://github.com/pgvector/pgvector)
- **Local dev:** [Laravel Sail](https://laravel.com/docs/sail) (Docker)
- **ML services:** Ollama (LLM + embeddings, host-native) and Presidio (PII, containerized)

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) with the Compose plugin
- [Ollama](https://ollama.com/) installed and running **on the host** (not in Docker)
- PHP 8.5+ and [Composer](https://getcomposer.org/) — only needed for the one-time
  bootstrap below; everything else runs through Sail

## Setup

### 1. Install PHP dependencies

Sail needs `vendor/laravel/sail` on disk before it can build the app container, so
start with a Composer install:

```bash
composer install
```

If you don't have PHP/Composer locally, bootstrap it with Sail's Composer image
(adjust the tag if you change the Sail runtime version in `compose.yaml`):

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php85-composer:latest \
    composer install
```

### 2. Configure the environment

```bash
cp .env.example .env
```

**Set the database to Postgres.** `.env.example` ships with `DB_CONNECTION=sqlite`,
but only Postgres (+pgvector) is provisioned by `compose.yaml`. Replace the SQLite
block with:

```env
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=sail
DB_PASSWORD=password
```

Then fill in the Gmail and Ollama settings — see [Configuration](#configuration).

### 3. Start Sail

This builds the app image and brings up the app container, Postgres, and both
Presidio services:

```bash
./vendor/bin/sail up -d --build
```

### 4. Generate the app key and run migrations

```bash
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

### 5. Install JavaScript dependencies

```bash
./vendor/bin/sail npm install
```

### 6. Set up Ollama on the host

Ollama runs natively (the app container reaches it via `host.docker.internal`,
which `compose.yaml` maps to the host gateway). Start it and pull the models your
`.env` references:

```bash
ollama serve   # or start the Ollama app / system service
ollama pull qwen3:4b            # matches OLLAMA_TRIAGE_MODEL
ollama pull qwen3-embedding     # matches OLLAMA_EMBEDDING_MODEL
```

Pull whatever model names you actually set for `OLLAMA_TRIAGE_MODEL` and
`OLLAMA_EMBEDDING_MODEL`. If you change the defaults, update both the `.env` and
the `ollama pull` commands.

## Running

The app is served by the Sail container at <http://localhost> (override the port
with `APP_PORT`). Vite runs on port 5173 (`VITE_PORT`).

For local development, run these in separate terminals (or use a multiplexer):

```bash
./vendor/bin/sail npm run dev       # Vite dev server (HMR)
./vendor/bin/sail artisan queue:work    # Process queued jobs (Gmail polling, triage, embeddings)
./vendor/bin/sail artisan schedule:work # Drive the scheduled Gmail poll
```

> The composer `dev` script (`composer dev`) also exists — it launches
> `artisan serve`, a queue listener, Pail logs, and Vite via `concurrently`. Use
> it for a host-PHP workflow; under Sail the commands above are preferred since
> the container already serves the app.

## Testing

```bash
./vendor/bin/sail test
# or
./vendor/bin/sail artisan test
```

## Configuration

Key `.env` variables:

| Variable                              | Default                             | Purpose                                                                        |
| ------------------------------------- | ----------------------------------- | ------------------------------------------------------------------------------ |
| `APP_PORT`                            | `80`                                | Host port mapped to the app container                                          |
| `VITE_PORT`                           | `5173`                              | Host port for the Vite dev server                                              |
| `FORWARD_DB_PORT`                     | `5432`                              | Host port forwarded to Postgres                                                |
| `GMAIL_ACCOUNT_EMAIL`                 | —                                   | Gmail address to poll                                                          |
| `GMAIL_APP_PASSWORD`                  | —                                   | Gmail [app password](https://myaccount.google.com/apppasswords) (2FA required) |
| `GMAIL_POLL_CRON`                     | `*/5 * * * *`                       | Cron expression for polling frequency                                          |
| `GMAIL_INITIAL_FETCH_DAYS`            | `3`                                 | How many days of history to fetch on first run                                 |
| `OLLAMA_BASE_URL`                     | `http://host.docker.internal:11434` | Ollama endpoint (host-native)                                                  |
| `OLLAMA_TRIAGE_MODEL`                 | `qwen3:4b`                          | LLM used for triage/reply drafting                                             |
| `OLLAMA_EMBEDDING_MODEL`              | `qwen3-embedding`                   | Model used for embeddings                                                      |
| `TRIAGE_DEFAULT_CONFIDENCE_THRESHOLD` | `75`                                | Confidence below which triage needs review                                     |
| `PRESIDIO_ANALYZER_URL`               | `http://presidio-analyzer:3000`     | Presidio analyzer service                                                      |
| `PRESIDIO_ANONYMIZER_URL`             | `http://presidio-anonymizer:3000`   | Presidio anonymizer service                                                    |

## Services

`compose.yaml` provisions:

- **`laravel.test`** — the Sail `8.5` app image (nginx/PHP), exposing the app and Vite ports.
- **`pgsql`** — `pgvector/pgvector:0.8.2-pg18` (Postgres 18 with the pgvector extension).
- **`presidio-analyzer`** / **`presidio-anonymizer`** — Microsoft Presidio containers for PII detection and redaction.

Ollama is intentionally **not** in `compose.yaml` — it must run on the host so it
can use the local GPU/CPU directly.

## Common commands

```bash
./vendor/bin/sail up -d          # start services in the background
./vendor/bin/sail down            # stop services
./vendor/bin/sail ps              # list running containers
./vendor/bin/sail logs -f         # tail app logs
./vendor/bin/sail artisan tinker  # REPL
```
