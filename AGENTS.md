# Repository Guidelines

## Project Structure & Module Organization
Core plugin bootstrap lives in `woocommerce-ucp.php`, which registers hooks and loads everything under `includes/`. Subdirectories map to protocol areas (`includes/checkout`, `auth`, `mcp`, `rest`, `payments`, `security`) and should expose classes prefixed with `WC_UCP_`. Admin UI logic sits in `admin/class-wc-ucp-admin.php`, while lifecycle helpers live in `includes/class-wc-ucp-activator.php` and `includes/class-wc-ucp-deactivator.php`. Discovery assets and uninstall cleanup live in `includes/discovery/` and `uninstall.php`. Keep new modules colocated with their domain folder and register them via the main plugin loader. When adding REST helpers (e.g., the delegated PSP intent endpoint in `class-wc-ucp-payments-controller.php`), place them under `includes/rest/` and wire them up through `WC_UCP_Plugin::register_rest_routes()`.

## Build, Test, and Development Commands
Run `composer install` once to pull PHP, PHPUnit, and WordPress stubs. During development, refresh the autoloader with `composer dump-autoload`. Link the plugin into a local WooCommerce site (e.g., `ln -s $(pwd) ~/Sites/wp/wp-content/plugins/woocommerce-ucp`), then activate via `wp plugin activate woocommerce-ucp`. Execute unit tests with `vendor/bin/phpunit --testsuite default` once you have the WordPress test bootstrap configured (see `wp-phpunit/wp-phpunit`).

## Coding Style & Naming Conventions
Target PHP 7.4+ and follow the WordPress PHP coding standards: tabs for indentation, Yoda conditions for comparisons, and braces on the same line. Use studly `WC_UCP_*` classes, snake_case function names (e.g., `wc_ucp_register_routes`), and descriptive file names like `class-wc-ucp-checkout-controller.php`. Public REST routes should live in dedicated controller classes under `includes/rest/` to keep hooks declarative.

## Testing Guidelines
Adopt `wp-phpunit` for integration tests and `yoast/phpunit-polyfills` for compatibility. Group specs under `tests/{domain}/test-*.php` so they mirror the `includes/` layout, and use descriptive method names such as `test_checkout_session_requires_authenticated_customer`. Focus coverage on the REST controllers, capability filters, and payment providers; mock WooCommerce dependencies to keep runs fast. Failing tests must reproduce observed regressions before fixes ship.

## Commit & Pull Request Guidelines
Commits should read like imperatives ("Restore routing handler"), optionally prefixed with a scope (`docs:`, `fix:`) consistent with recent history. Reference issues or tickets in the body, and keep related changes squashed. PRs need: purpose summary, testing notes (`composer install && vendor/bin/phpunit`), screenshots or cURL traces for admin or API-facing updates, and mention of new settings so reviewers can validate migrations.

## Security & Configuration Tips
Never commit API keys or OAuth credentials; configure them through the WooCommerce > Settings > UCP tab or local environment variables. When adding new endpoints, update the `/.well-known/ucp` manifest and verify authentication guards in `includes/security/`. Default rate limits should remain conservative—document any change so operators can tune production stores safely.
