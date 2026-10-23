# BFMS

Business financial management for one company, with the ledger ready for more. Amounts are stored in centavos. The company currency is PHP.

Laravel 13 is the API at the repository root. The React app is in `frontend/`.

## Demo login

- Email: `owner@bfms.test`
- Password: `password`
- Company: Harbor Goods

## Run locally

```bash
php artisan migrate --seed
php artisan serve
```

In another terminal:

```bash
cd frontend
npm install
npm run dev
```

Open http://localhost:5173. The Vite server proxies `/api`, `/sanctum`, and `/session` to Laravel on port 8000.

MySQL database: `bfms`. Tests use SQLite in memory via `php artisan test`.
