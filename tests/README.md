# Test Database Safety

`tests/CreatesApplication.php` blocks unsafe test targets before any test case
runs.

Rules:

1. The Laravel environment must be `testing`.
2. The application database names avdbcms and avdbclip (older installs) are always rejected.
3. Non-sqlite test connections must use a database name ending in `_test`.

The default `phpunit.xml` target is sqlite `:memory:`.

For MariaDB coverage, create a dedicated database such as:

```text
avdbcms_test
```

Never point tests at the production or development application database.
