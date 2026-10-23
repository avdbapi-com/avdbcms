<!-- Thanks for contributing to AVDBCMS. Please read .github/CONTRIBUTING.md first. -->

## What this changes

<!-- A short description of the change and why it is needed. Link the issue: "Fixes #123". -->

## Type of change

- [ ] Bug fix
- [ ] New feature or improvement
- [ ] Theme or look change
- [ ] Translation
- [ ] Documentation
- [ ] Other:

## How it was tested

<!-- PHP and database versions, web server, look and theme, the steps you followed and what you checked. -->

## Checklist

- [ ] It works on PHP 8.3 and newer.
- [ ] It works with MySQL 5.7+ and MariaDB 10.3+ (or the change does not touch the database). A database change is a
      new migration in `database/migrations/`.
- [ ] New or changed text that visitors or admins see uses `__()` and is in all 18 `lang/*.json` files;
      `php scripts/i18n_check.php --root=. --min-coverage=95` passes.
- [ ] `npm run build` works (if I changed `resources/`), and `php artisan avd:themes:build --check` passes (if I changed
      a theme).
- [ ] The tests pass as before (`vendor/bin/phpunit`), and I added tests for my change where it makes sense.
- [ ] The Upload18 upload, the video player and the embed code are unchanged (or the maintainers agreed in an issue).
- [ ] I updated the docs (README.md, UPGRADE.md, CHANGELOG.md, COMMUNITY_SETUP_GUIDE.md or docs/) where the change
      needs it.
- [ ] Every file I added says where it comes from; third-party code keeps its license and is listed in NOTICE.
- [ ] No passwords, keys, `.env` values, personal data or real site data; test data and screenshots are safe for work.
- [ ] I agree that my contribution is published under the AVDBCMS Community License 1.0.
