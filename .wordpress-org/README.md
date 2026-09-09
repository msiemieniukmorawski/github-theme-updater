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

## Editing these files

The HTML the banner and the PNG icons were rendered from is not kept in this
repository. That has one consequence worth knowing before you try to tweak
something:

- **`icon.svg` is the master for the icon.** It is plain, hand-written SVG, so
  edit it directly and re-export `icon-128x128.png` and `icon-256x256.png` from
  it at 128 and 256 pixels square.
- **The banner PNGs have no source.** Changing the banner means rebuilding the
  artwork, not editing it. Keep that in mind before deciding a small change is
  cheap.

Whatever you use to produce a replacement, check two things afterwards, because
neither is visible to the eye and wordpress.org rejects a file that gets them
wrong:

```powershell
Add-Type -AssemblyName System.Drawing
Get-ChildItem .wordpress-org\*.png | ForEach-Object {
  $i = [System.Drawing.Image]::FromFile($_.FullName)
  "$($_.Name): $($i.Width)x$($i.Height)"
  $i.Dispose()
}
```

The dimensions have to match the table above exactly. And if the artwork was
produced by screenshotting a browser, subpixel antialiasing has to be off
(`--disable-lcd-text` in Chrome) - otherwise small text carries orange and blue
fringes baked into the glyph edges, which are obvious against any background
other than the one it was rendered on.

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
