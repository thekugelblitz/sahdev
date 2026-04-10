# Sahdev Standalone Cron

Use this script when you want Sahdev cron processing to run independently of the WHMCS `CronJob` hook.

## Script

- `modules/addons/sahdev/cron/sahdev-cron.php`

## Manual test

```bash
php /path/to/whmcs/modules/addons/sahdev/cron/sahdev-cron.php --verbose
```

## Crontab examples

Recommended default (balanced):

```cron
*/5 * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/sahdev/cron/sahdev-cron.php >/dev/null 2>&1
```

High-volume support desks:

```cron
* * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/sahdev/cron/sahdev-cron.php >/dev/null 2>&1
```

## Which interval should I pick?

- Every **5 minutes**: best default for most installations.
- Every **1 minute**: for very active queues where near-real-time updates are needed.

## Notes

- Keep WHMCS system cron enabled; this script is an additional dedicated runner.
- Tool execution already has internal lock handling, so overlap risk is reduced.
- If debugging, run with `--verbose` to print JSON summary.
