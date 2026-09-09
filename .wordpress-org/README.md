# wordpress.org directory assets

**Nothing in this directory ships with the plugin.** It is excluded from the
distributable ZIP by [`.distignore`](../.distignore), and it must stay that way.

These files belong in the `assets/` directory at the **root of the SVN
repository** at `https://plugins.svn.wordpress.org/github-theme-updater/`,
which is a sibling of `trunk/` and `tags/`, not a directory inside them:

```
github-theme-updater/
├── assets/      <- the contents of this directory go here
├── tags/
│   └── 2.3.1/
└── trunk/
```

Do not confuse this with the plugin's own [`assets/`](../assets) directory,
which holds the admin CSS and JavaScript and *does* ship in the ZIP.

## What belongs here

| File | Size | Purpose |
| --- | --- | --- |
| `banner-772x250.png` | 772 x 250 | Header on the plugin page |
| `banner-1544x500.png` | 1544 x 500 | The same design at 2x, for high-DPI screens |
| `icon-128x128.png` | 128 x 128 | Icon in search results and the plugin list |
| `icon-256x256.png` | 256 x 256 | The same icon at 2x |
| `icon.svg` | square viewBox | Preferred over the PNG icons where the browser supports it |
| `screenshot-1.png` … | any | Shown under "Screenshots" on the plugin page |

Screenshot numbering has to line up with the numbered list under
`== Screenshots ==` in [`readme.txt`](../readme.txt). `screenshot-1.png` is
described by item 1, and so on. A mismatch shows the wrong caption under the
wrong image, which nothing warns you about.

## Sources

`src/` holds the HTML the banner and the icons were rendered from. It is kept
in the repository on purpose: a PNG cannot be edited afterwards, so the only
practical way to adjust a banner is to change the HTML and render it again.
Being under `.wordpress-org/`, it is excluded from the ZIP along with
everything else here, and it should not be copied into SVN either - upload only
the PNG and SVG files listed above.

To re-render after editing the HTML (Windows, headless Chrome, nothing to
install):

```powershell
$chrome = "C:\Program Files\Google\Chrome\Application\chrome.exe"
$src    = (Resolve-Path .wordpress-org\src).Path -replace '\\','/'
$flags  = @('--headless=old','--disable-gpu','--disable-lcd-text',
            '--force-color-profile=srgb','--hide-scrollbars')

& $chrome @flags --screenshot=".wordpress-org/banner-772x250.png" `
  --window-size=772,250 "file:///$src/banner.html"

& $chrome @flags --force-device-scale-factor=2 `
  --screenshot=".wordpress-org/banner-1544x500.png" `
  --window-size=772,250 "file:///$src/banner.html"

& $chrome @flags --screenshot=".wordpress-org/icon-128x128.png" `
  --window-size=128,128 "file:///$src/icon.html"

& $chrome @flags --force-device-scale-factor=2 `
  --screenshot=".wordpress-org/icon-256x256.png" `
  --window-size=128,128 "file:///$src/icon.html"
```

Three of those flags are not decoration:

- `--disable-lcd-text` turns off subpixel antialiasing. Without it Chrome
  renders small text with ClearType, which bakes orange and blue fringes into
  the glyph edges - clearly visible if you sample a pixel, and wrong on any
  background other than the one it was rendered against. `--headless=new`
  happens to disable it already; `--headless=old` does not.
- `--force-color-profile=srgb` keeps the output independent of whatever
  profile the monitor is using.
- `--headless=old` is used because `--headless=new` silently produced no file
  at all on this machine while a normal Chrome session was running. It exits
  zero and writes nothing, so check that the PNG exists rather than trusting
  the exit code. If `--headless=old` is ever dropped from Chrome, close the
  running Chrome instances and go back to `--headless=new`.

Then check that the files really came out at the intended size, because a wrong
dimension is rejected on upload and is invisible to the eye:

```powershell
Add-Type -AssemblyName System.Drawing
Get-ChildItem .wordpress-org\*.png | ForEach-Object {
  $i = [System.Drawing.Image]::FromFile($_.FullName)
  "$($_.Name): $($i.Width)x$($i.Height)"
  $i.Dispose()
}
```

Two things the HTML has to keep doing, or the render comes out wrong:
`body` needs a zero margin and an explicitly painted background, and every font
has to be a system font or embedded as a `data:` URI. Chrome screenshots before
a webfont finishes downloading, so a linked webfont silently renders as the
fallback.

## Screenshots are not rendered

The `screenshot-*.png` files are real captures of the plugin running in
WordPress, taken from `docs/screenshots/`. They are not generated from HTML and
they are not retouched - the directory guidelines require screenshots to show
the actual product, and a mockup would misrepresent it. If the interface
changes, take new captures following `docs/screenshots/README.md` rather than
editing the existing images.

## Uploading

Assets are published by committing them to SVN; they are not part of a release
tag and they go live within minutes of the commit, independently of the plugin
version.

```bash
svn co https://plugins.svn.wordpress.org/github-theme-updater/ svn-gthu
cp .wordpress-org/*.png .wordpress-org/*.svg svn-gthu/assets/
cd svn-gthu
svn add assets/* --force
svn ci -m "Update directory assets"
```
