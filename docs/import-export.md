# Export and import

Administrators find **Export and import** at the bottom of the *Settings* page. It exports the whole website, imports such an export, and [moves a website over from WordPress](#moving-from-wordpress).

## Exporting the website

*Download the export* gives you one ZIP file with the whole website:

| Included | Not included |
| --- | --- |
| Pages and posts in all languages, with their blocks | User accounts and passwords |
| Categories, menus, redirects | Earlier versions of pages and posts |
| Shared blocks, page templates, forms | Messages visitors sent through forms |
| Images of the media library (the originals) | Unsaved changes of open editors |
| Name and description of the website, languages, theme choice and theme settings, logo | Switches that are decided per installation, such as the read API |

The file is a good backup of the content, and the way to move a website to another server.

## Importing a website

Choose an export file and decide what happens with the content that is already there:

- **Keep it and add the imported content** – nothing is deleted. Imported pages whose address is taken get a number (`about-2`), the existing homepage stays the homepage, menu entries are added after the existing ones, and categories with the same address are reused. Settings are left alone, except that the languages of the imported content are switched on.
- **Delete it and replace it** – all pages, posts, menus, forms, images and so on are removed first, then the export is brought in together with its settings. Users are kept. This has to be confirmed with the checkbox.

Afterwards a report lists what was imported and anything that could not be taken over, for example an image that is missing in the file.

Good to know:

- Posts keep their author when a user with the same email address exists; otherwise they belong to the person who runs the import.
- A theme that is not installed on the target website cannot be activated; the default theme is used.
- An import either succeeds completely or changes nothing.

### Large websites

The web server limits how large an uploaded file may be. The same export and import are available on the command line, without that limit:

```bash
php bin/console app:site:export /backups/website.zip
php bin/console app:site:import /backups/website.zip            # add
php bin/console app:site:import /backups/website.zip --replace  # replace
```

`app:site:export` in a nightly cron job gives you a simple content backup.

## Moving from WordPress

The third box on the page imports a WordPress website.

1. In WordPress, open **Tools → Export**, choose **All content** and download the file.
2. Upload that XML file under *Import from WordPress*. On a multilingual website, choose the language the content is written in.
3. Keep the WordPress website online until the import has finished: the images are fetched from it.

Nothing of the existing website is deleted. What arrives:

| WordPress | Here |
| --- | --- |
| Posts and pages | Posts and pages. Published stays published, everything else becomes a draft, scheduled posts stay scheduled. |
| Categories | Blog categories; a post keeps its first category |
| Images used in the content, and featured images | Media library; the featured image becomes the cover image |
| Excerpt, publish date, address (slug) | The same |
| Old addresses (`/2024/05/17/hello-world/`) | Redirects to the new address |

The content is converted to blocks: paragraphs and lists become text blocks, headings become headings, and images, galleries, quotes, code, separators and YouTube/Vimeo videos become the matching blocks. Columns and groups are flattened: their content follows one after the other.

### What cannot be converted

After the import you see a report. It names, for every post and page, what could not be taken over:

- **Shortcodes of plugins** such as `[contact-form-7]` are removed.
- **Blocks that WordPress fills in itself** (latest posts, search, calendar …) are left out.
- **Tables** are turned into plain text, one line per row.
- **Embeds** other than YouTube and Vimeo, audio and video files, forms and scripts are left out.
- **Images that could not be fetched** – because the old website did not answer, or the file is not an image.
- **Password-protected entries** are imported as drafts.
- **Comments, tags, menus, users and other content types** (products, events …) are not imported. Posts belong to the person who runs the import.

WordPress does not record which page is the front page in its export; choose the homepage in the settings of the page afterwards.

For large websites, use the command line. It has no upload or time limit:

```bash
php bin/console app:wordpress:import /path/to/wordpress.xml --locale=en
```

## For developers

- `App\Transfer\WordPressImporter` reads the WXR file, `App\Transfer\WordPressContentConverter` turns the HTML of a post into blocks, and `App\Transfer\ImageDownloader` fetches the images. Addresses inside the server's own network are never requested, and a download has to pass the same checks as an uploaded image.
- The archive holds `site.json` and the original images in `media/`. `App\Transfer\SiteExporter` writes it, `App\Transfer\SiteImporter` reads it.
- All ids change on import. `App\Transfer\BlockReferences` rewrites references in blocks using the field definitions, so custom block types with image, gallery, shared-block or form fields work without extra code.
- The importer trusts nothing in the archive: text is cleaned like editor input, images are validated like uploads and resized again, links are checked, and only plain file names inside `media/` are read.
- The format has a version number; a newer export is refused by an older installation.
