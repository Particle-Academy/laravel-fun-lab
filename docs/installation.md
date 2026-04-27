# Installation Guide

This guide covers installing Laravel Fun Lab in your Laravel application, including all installation options and configuration steps.

## Requirements

- PHP 8.2 or higher
- Laravel 11.x or 12.x
- Composer

## Installation

### Step 1: Install via Composer

Install the package using Composer:

```bash
composer require particle-academy/laravel-fun-lab
```

### Step 2: Run the Installation Command

Run the interactive installation command:

```bash
php artisan lfl:install
```

This command will:

1. **Publish the configuration file** (`config/lfl.php`)
2. **Prompt for table prefix** (default: `lfl_`)
3. **Run database migrations** (with confirmation prompt)
4. **Optionally install UI components** (if `--ui` flag is used)

## Installation Options

The `lfl:install` command supports several options:

### `--ui` Flag

Install optional UI components (Blade views):

```bash
php artisan lfl:install --ui
```

This will:
- Publish Blade views to `resources/views/vendor/lfl`
- Enable the UI layer in configuration
- Make leaderboard, profile, and admin views available

### `--force` Flag

Overwrite existing configuration files:

```bash
php artisan lfl:install --force
```

Use this if you've already published the config and want to regenerate it.

### `--skip-migrations` Flag

Skip running database migrations:

```bash
php artisan lfl:install --skip-migrations
```

Useful if you want to review migrations first or run them manually later.

### `--prefix` Option

Set the table prefix non-interactively:

```bash
php artisan lfl:install --prefix=custom_
```

This sets the table prefix without prompting. All LFL tables will be prefixed with this value (e.g., `custom_awards`, `custom_achievements`).

### Combining Options

You can combine multiple options:

```bash
php artisan lfl:install --ui --force --prefix=game_
```

## Manual Installation

If you prefer to install manually or need more control:

### 1. Publish Configuration

```bash
php artisan vendor:publish --tag=lfl-config
```

This creates `config/lfl.php` in your application.

### 2. Configure Table Prefix

Edit `config/lfl.php` and set your desired table prefix:

```php
'table_prefix' => env('LFL_TABLE_PREFIX', 'lfl_'),
```

Or set it in your `.env` file:

```env
LFL_TABLE_PREFIX=custom_
```

### 3. Run Migrations

```bash
php artisan migrate
```

This creates all LFL database tables with your configured prefix.

### 4. (Optional) Publish UI Views

If you want to use the optional UI layer:

```bash
php artisan vendor:publish --tag=lfl-views
```

Then enable UI in `config/lfl.php`:

```php
'ui' => [
    'enabled' => true,
    // ...
],
```

## Post-Installation

After installation, you should:

1. **Add the `Awardable` trait** to your User model (or any model you want to track)
2. **Review the configuration** in `config/lfl.php`
3. **Set up your first achievement** using `LFL::setup()`
4. **Start awarding points** using `LFL::award()`

See the [Usage Guide](usage.md) for examples and common patterns.

## Non-Interactive Installation

For CI/CD environments or automated deployments, the installer detects non-interactive mode and uses defaults:

- Table prefix: `lfl_` (or value from `LFL_TABLE_PREFIX` env var)
- Migrations: Prompt defaults to "yes" but can be skipped with `--skip-migrations`
- Config overwrite: Defaults to "no" unless `--force` is used

Example for CI/CD:

```bash
php artisan lfl:install --skip-migrations --prefix=lfl_
```

## Troubleshooting

### Config Already Exists

If you see "LFL config already exists", use `--force` to overwrite:

```bash
php artisan lfl:install --force
```

### Migration Errors

If migrations fail, check:
- Database connection is configured correctly
- Table prefix doesn't conflict with existing tables
- You have proper database permissions

### UI Components Not Showing

If UI components aren't available:
- Ensure you ran `lfl:install --ui` or published views manually
- Check that `config/lfl.php` has `'ui.enabled' => true`
- Verify routes are registered (check `routes/web.php`)

## Next Steps

- [Configuration Reference](configuration.md) - Learn about all configuration options
- [Usage Guide](usage.md) - See examples and common patterns
- [API Reference](api.md) - Complete API documentation

