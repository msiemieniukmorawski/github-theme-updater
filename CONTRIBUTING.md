# Contributing

## Local setup

```bash
composer install
composer check   # lint, PHPCS, PHPStan and the PHPUnit suite
```

The test suite runs without a WordPress installation - `tests/bootstrap.php`
provides the handful of core functions the plugin touches, and
`tests/fixtures/wp/` stands in for `WP_Filesystem`.

### On Windows

Two things behave differently and are worth knowing before you conclude the
build is broken:

- **Line endings.** `.gitattributes` pins the working tree to LF, because the
  WordPress Coding Standards ruleset rejects CRLF and CI runs on Linux. If you
  cloned before that file existed, run `git add --renormalize .` once.
- **Non-ASCII paths.** PHPStan's WordPress extension fails to resolve its stubs
  when the checkout sits under a directory with non-ASCII characters in its
  name, and reports well over a thousand phantom "undefined function" errors.
  That is an artefact of the path, not of the code. Move the checkout to an
  ASCII path, or trust the `Static analysis` job in CI.

Also note that Git Bash on Windows has no `rsync` and no `zip`, and its `grep`
does not support `-P`. The packaging step in `release.yml` therefore cannot be
reproduced locally. The evidence that it works is the log of the build step in
CI, or the artifact it uploads.

## Workflows

| File | Trigger | What it does |
| --- | --- | --- |
| `.github/workflows/ci.yml` | push and pull requests to `main` | `php -l` across the tree on several PHP versions, PHPCS with inline annotations, PHPStan, PHPUnit |
| `.github/workflows/release.yml` | a `v*` tag, or a manual dispatch | Verifies the version, builds the ZIP, checks its contents, uploads it as an artifact, and on a tag publishes a GitHub release |

A CI run marked **cancelled** is not a failure. `cancel-in-progress` kills the
older run on a ref when a newer push arrives, and the icon looks much like the
one for a failed run.

## Releasing

The version number lives in **three** places in this repository, and the
release workflow checks all three against the tag before it builds anything:

1. `Version:` in the plugin header of `github-theme-updater.php`
2. the `VERSION` constant a few lines below it
3. `Stable tag:` in `readme.txt`

There is no `package.json`, because the plugin has no build step. Two more
places are not checked automatically but should be kept current: the
`- **Version:**` line at the top of `README.md` and `README.pl.md`, and the
`Project-Id-Version` header of the `.pot` file.

### Steps

1. On a working branch, bump the three version fields above, add the entry to
   `CHANGELOG.md`, and add `= X.Y.Z =` blocks to the `== Changelog ==` and
   `== Upgrade Notice ==` sections of `readme.txt`.

2. Regenerate the translation template, so its version header and any new
   strings are current:

   ```bash
   wp i18n make-pot . languages/github-theme-updater.pot \
     --slug=github-theme-updater --domain=github-theme-updater \
     --exclude=vendor,tests,docs,node_modules,.wordpress-org
   ```

3. Run `composer check` and open a pull request. Let CI go green.

4. Merge into `main`.

5. **Tag only after the merge.** A workflow runs from the tree of the commit it
   is tagged on, so a tag placed on a commit that predates `release.yml` starts
   nothing at all, and a tag placed before the version bump is merged fails the
   version check.

   ```bash
   git checkout main
   git pull
   git tag -a v2.3.1 -m "Release 2.3.1"
   git push origin v2.3.1
   ```

6. Watch the run, and read its state from the API rather than from the icons:

   ```bash
   curl -s "https://api.github.com/repos/msiemieniukmorawski/github-theme-updater/actions/runs?per_page=5"
   ```

7. Download the published ZIP and look inside it before telling anyone the
   release is out. The release job checks this too, but a human should see it
   once:

   ```bash
   curl -sL -o gthu.zip \
     "https://github.com/msiemieniukmorawski/github-theme-updater/releases/latest/download/github-theme-updater.zip"
   unzip -l gthu.zip
   ```

   Because the file name never changes and `generate_release_notes` fills in
   the notes, that `releases/latest/download/` address always points at the
   newest package. It is also the address to hand to anyone installing the
   plugin by hand.

### Publishing to wordpress.org

The GitHub release and the wordpress.org directory are separate channels, and
the tag does not update the directory. To push a release to SVN:

```bash
svn co https://plugins.svn.wordpress.org/github-theme-updater/ svn-gthu
cd svn-gthu

rsync -a --delete --exclude='.svn' /path/to/dist/github-theme-updater/ trunk/
svn add trunk/* --force
svn cp trunk tags/2.3.1
svn ci -m "Release 2.3.1"
```

`Stable tag:` in `readme.txt` is what decides which version the directory
actually serves. Point it at a tag that exists under `tags/`, or users get
nothing.

Banners, icons and screenshots live in `assets/` at the **root** of the SVN
repository, next to `trunk/` and `tags/` rather than inside them, and they are
published independently of any version. See
[`.wordpress-org/README.md`](.wordpress-org/README.md).

## If a release job fails

- **403 when creating the release.** Settings -> Actions -> General -> Workflow
  permissions has to be set to "Read and write permissions". `permissions:
  contents: write` in the workflow cannot grant more than the repository
  allows.
- **The version check fails.** The tag and the three version fields disagree.
  Fix the fields on `main`, delete the tag locally and remotely
  (`git tag -d vX.Y.Z && git push --delete origin vX.Y.Z`), and tag again.
- **Nothing ran at all.** The tag is on a commit whose tree has no
  `release.yml`. Merge first, then tag.
