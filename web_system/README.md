# Stitch Rescue Web System

Laravel web starter for the Stitch rescue platform.

## Stack

- Laravel 13 (PHP)
- MySQL
- API endpoints for the Flutter mobile app

## Included Starter Areas

- Dashboard summary
- Incident report management pages
- AI severity and transmission views
- Profile and settings views
- JSON API routes under `/api/v1`

## Setup

1. Configure your MySQL credentials in `.env`
2. Create the database `stitch_system`
3. Run migrations:

```bash
php artisan migrate
```

4. Start the app:

```bash
php artisan serve
```
