# MySQL Setup

Use `mysql_setup.sql` to create the starter database for the Stitch Rescue stack.

## After Creating The Database

1. Update `web_system/.env` with your MySQL credentials
2. Run Laravel migrations:

```bash
php artisan migrate
```

3. Point the Flutter app to the Laravel API base URL