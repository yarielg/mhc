# MHC – WordPress Plugin

This WordPress plugin is designed for a behavioral clinic that provides services to children with autism and other conditions affecting behavior.
It integrates a custom payroll management system that allows administrators to generate detailed payslips for different worker roles — RBTs (technicians), BCaBAs (intermediate), and BCBAs (analysts) — based on hours worked with each patient, specific rates (general or per patient), and exceptional payments or deductions.

The system is built as a Vue-powered single-page application (SPA) embedded in WordPress via shortcodes. It is optimized to minimize the number of clicks needed to produce payrolls, while still supporting complex scenarios such as:

Assigning and calculating extra payments or deductions.

Managing fixed-rate activities like assessments and supervision.

Handling exceptions where certain workers skip payroll cycles.

Generating individual payslips for each worker with detailed breakdowns of payments by category.

Producing global payroll reports for internal verification and comparison with accounting systems.

By combining a modern Vue frontend with a WordPress backend, the plugin allows secure access from any location, detailed role/patient management, and automated calculations that reduce human error and manual work.

## Requirements

- WordPress 6.x or higher
- PHP 8.0+ (7.4 may work but is not recommended)
- [Composer](https://getcomposer.org/) 2.x
- Node.js 18+ and npm 9+
- Access to `wp-content/plugins/`

## Installation

1. **Clone the repository** into `wp-content/plugins/`:
   ```bash
   cd wp-content/plugins
   git clone <repository-url> mhc
   cd mhc
   ```

2. **Install PHP dependencies**:
   ```bash
   composer install
   composer update
   composer du
   ```

3. **Install JS dependencies**:
   ```bash
   npm install
   ```

4. **On Windows PowerShell**, allow scripts temporarily:
   ```powershell
   Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
   ```

5. **Start the watcher** for development:
   ```bash
   npm run watch
   ```

6. **Activate the plugin** in WordPress admin under *Plugins → MHC *.

> 💡 In production, use the build command (`npm run build`) instead of `watch`.

## Shortcodes

### `[mhc]`
Renders the Vue  container:
```html
<div id="vwp-plugin"></div>
```

### `[mhc__login]`
Displays the WordPress login form with custom labels.
- Redirects logged-in users to `/`
- Includes "Lost your password?" link

Example usage in a WordPress page:
```
[mhc__login]
```

## Development

- Use `npm run watch` during development to compile assets in real-time.
- After updating PHP dependencies, run:
  ```bash
  composer du
  ```

## File Structure

```
mhc-/
├─ inc/
│  └─ Base/Shortcodes.php
├─ assets/        # JS/CSS source
├─ dist/          # Compiled JS/CSS
├─ mhc-.php    # Plugin bootstrap
├─ composer.json
└─ package.json
```

## License

Specify your license here (e.g., MIT, GPL-2.0+).

## Changelog

- v0.1.0 – Initial release with `[mhc_]` and `[mhc__login]` shortcodes.
