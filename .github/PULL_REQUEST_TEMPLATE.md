## What this changes

<!-- One or two sentences. Link the issue if there is one. -->

## Release lines

- [ ] `main` (3.x)
- [ ] `2.x` (ported in PHP 7.3 syntax, or follow-up PR linked below)
- [ ] `1.x` (ported, or follow-up PR linked below)

## Checklist

- [ ] Tests added or updated, and they fail without the change
- [ ] `composer test`, `composer analyse` and `composer lint` pass locally
- [ ] Every lock acquired is released in a `finally` or handed to the refresh job explicitly
- [ ] New options are in `config/stampede.php`, `Options`, the README tables and `CHANGELOG.md`
- [ ] `tests/`, `README.md` and `CHANGELOG.md` stay identical across branches
- [ ] Commit subjects use a conventional prefix (`feat:`, `fix:`, `docs:`, `test:`, `ci:`, `chore:`)
