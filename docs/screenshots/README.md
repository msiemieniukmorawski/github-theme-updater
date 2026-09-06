# Screenshots

Drop the images here, then uncomment the Screenshots section in
[`README.md`](../../README.md) and [`README.pl.md`](../../README.pl.md).

## What to capture

| File | Screen | What has to be visible |
| --- | --- | --- |
| `update.png` | **Theme from GitHub → Update** | The three status cards (installed version, newest on GitHub, repository), the protected-paths line, and the primary update button. Best taken when an update is actually available, so the highlighted card shows. |
| `settings.png` | **Theme from GitHub → Settings** | The repository and token fields plus the protected paths textarea with a few example rules in it. |
| `backups.png` | **Theme from GitHub → Backups** | The table with two or three entries, so the version, date, size and the Restore/Delete actions are all shown. |

Optional fourth: **Instructions**, if you want the step-by-step help tab visible
in the README.

## How to take them

- Browser window at **1440 px** wide, browser zoom 100%.
- Collapse the WordPress admin menu (the arrow at the bottom of the sidebar) so
  the content column dominates the frame.
- Crop to the content area — the WordPress sidebar and admin bar add noise and
  date the screenshot to a particular WordPress version.
- Save as PNG. Keep each file under roughly 300 KB; if a shot is heavier, resize
  it to 1200 px wide rather than compressing it into mush.

## Before publishing

Blur or replace anything site-specific:

- the real repository name, if the client's repo should not be public;
- the site name in the admin bar;
- the theme directory name, if it identifies a client.

A masked token never appears in the interface — the settings field shows only
`ghp_****6789` — but check the screenshot anyway before it goes into a public
repository.
