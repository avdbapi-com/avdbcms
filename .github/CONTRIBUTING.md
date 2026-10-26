# Contributing to AVDBCMS

Thanks for helping to make AVDBCMS better. Bug reports, ideas, docs fixes, translations and code are all welcome.

## Before you start

- Questions and setup help: the [help center](https://help.avdbapi.com/) and Telegram:
  - Telegram group: https://t.me/+jj6McO-iYfA0MTM1
  - Telegram support (personal): https://t.me/avdbapi_support
- Issues are for bugs and feature requests.
- Search the [issues](https://github.com/avdbapi-com/avdbcms/issues) first: your topic may already be there.
- Security problems: never in public. Follow [SECURITY.md](SECURITY.md).
- Everyone who takes part follows our [Code of Conduct](CODE_OF_CONDUCT.md).

## Report a bug

Use the **Bug report** form. Give the AVDBCMS, PHP, database and web server versions (**Tools › Health check**, tab
**Server**, shows them), the steps to reproduce the problem and what you expected. Remove passwords, keys, `.env`
values and personal data from logs and screenshots, and keep screenshots safe for work.

## Suggest a feature

Use the **Feature request** form: describe the problem first, then your idea. One idea per request.

## Change code, themes or docs

1. Open an issue first for anything bigger than a small fix, so we can agree on the approach.
2. Fork the repository and create a branch from the default branch, `main`.
3. Keep the change focused: one topic per pull request.
4. Follow the existing style of the file you change (see `.editorconfig`). AVDBCMS runs on **PHP 8.3 or newer**
   (Laravel 12) and on **MySQL 5.7+ / MariaDB 10.3+**: do not use syntax or SQL that these versions lack.
5. A database change is a new migration in `database/migrations/`. Never change a migration that a release already
   has: existing sites only run new migrations, with **Home › Finish update** or the one-click update.
6. Text that visitors or admins see goes through `__()` and into all 18 language files `lang/*.json`, in English at
   least. Check them with `php scripts/i18n_check.php --root=. --min-coverage=95`; the release build stops when this
   check fails.
7. Styles and scripts are built from `resources/` with Node.js 20 (20.19 or newer) or 22.12 or newer and npm 10 or
   newer: run `npm ci`, then `npm run build`. Do not commit `public/build/`: it is built for every release. After a
   change to the theme catalogue, the colour engine or a hero decoration, run `php artisan avd:themes:build` before
   `npm run build`; `php artisan avd:themes:build --check` tells you when a theme file is missing or out of date.
8. Run the tests with `vendor/bin/phpunit` (after `composer install`). They use an in-memory SQLite database
   (`phpunit.xml`), so your PHP needs the `pdo_sqlite` extension. Add tests for what you change. A change must not make
   a test fail that passed before.
9. The Upload18 upload, the video player and the embed code stay unchanged on purpose
   (`resources/js/components/Videos/Player.tsx`, `Ad.tsx`, `VideoUpload.tsx` and `app/Services/Upload18Service.php`).
   Ask in an issue before you change them: such a pull request needs the maintainers' agreement first.
10. Update the docs (`README.md`, `UPGRADE.md`, `CHANGELOG.md`, `COMMUNITY_SETUP_GUIDE.md` or `docs/`) when the change
    needs it. The guides on the help site are written separately: say in your pull request when one of them needs a
    change.
11. Test on a test site with safe-for-work test data, never on a production site.
12. Open the pull request and fill in the template.

## Licenses and third-party code

- AVDBCMS 1.2.0 and later is under the **AVDBCMS Community License 1.0** ([LICENSE](../LICENSE)): free to use,
  change and share, never sold. By sending a contribution you agree that it is published under this license.
- The code of the original open-source project that AVDBCMS is based on keeps its MIT License. Keep its notice
  ("Copyright (c) 2024 Anton Bourtnik") in `LICENSE`, Part B.
- Third-party code, fonts, icons and images keep their own licenses. Add only material whose license allows free
  redistribution, say where it comes from, and list it in [NOTICE](../NOTICE). Add PHP and npm packages through
  `composer.json` and `package.json`. Do not add code from paid or "nulled" scripts and themes.

## Content rules

AVDBCMS lives on GitHub, so everything in the repository (code, docs, examples, test data, issues and pull requests)
must be safe for work: neutral product language, no adult wording, no adult imagery, no real site data.

Never commit secrets: no `.env` file, passwords, API or payment keys, cookies, or the addresses and data of real sites.
