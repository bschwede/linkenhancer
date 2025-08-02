# webtrees module LinkEnhancer

**Cross-references to Gedcom datasets, Markdown editor, context-sensitive link to the GenWiki Webtrees manual**

[![License: GPL v3](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](http://www.gnu.org/licenses/gpl-3.0) ![webtrees major version](https://img.shields.io/badge/webtrees-v2.2.x-green) ![Latest Release](https://img.shields.io/github/v/release/bschwede/linkenhancer)

[Description](#description) | [webtrees](#webtrees) | [Requirements](#requirements) | [Installation](#installation) | [Contributing](#contributing) | [Translation](#translation) | [Support](#support) | [License](#license)


<a name="description"></a>
## Description

This module wraps up some [examples mentioned in the German Webtrees Manual](https://wiki.genealogy.net/Webtrees_Handbuch/Entwicklungsumgebung#Anpassungen_mit_dem_Modul_.22CSS_und_JS.22) and improves the application of these improvements - each component can be activated individually.

The main purpose of this module is to make **links to data records** stored in family trees more convenient. This avoids having to store fully qualified links, which impairs the portability of Gedcom data. By linking the notes to the GEDCOM data records (persons, families, sources, etc.) from the text, it is easier to replace the history module and thus also save this information in the GEDCOM file. The option of embedding the **images** already inserted in the family tree in the notes rounds off this approach. The link function is controlled via the anchor part of the URI.

### Links
Cross references are made with the XREF-ID by providing the GEDCOM record type and if necessary the tree name. It extends webtrees builtin feature, which adds record links with the standard display name by just typing `@XREF-ID@` in text or markdown.

If Webtrees provides better support for UID, referencing via UID will probably also be implemented in this module, as this will make links more fail-safe.
See also:
* Forum post [ Feature Request: Improved support for UID / _UID ](https://www.webtrees.net/index.php/forum/9-request-for-new-feature/39942-feature-request-improved-support-for-uid-uid)
* PR [UID References in notes and text #5145](https://github.com/fisharebest/webtrees/pull/5145)


Different destinations can be addressed with a link, whereby the cross-reference is always the first link (if set) and the others are represented by attached clickable icons. 
Included are the following external targets:
- Wikipedia DE/EN
- Family Search Family Tree
- GenWiki
- GOV
- Residents database - Family research in West Prussia (westpreussen.de)
- OpenStreetMap

Additional external targets can be configured. Any CSS rules required are best added via the “CSS and JS” module. Only the definition of the icon as a background image is actually needed - referencing as data: URL. See also: [mdn web docs - data: URLs](https://developer.mozilla.org/en-US/docs/Web/URI/Reference/Schemes/data)
`.icon-whatever { background-image: url(...) }`

This function is implemented via Javascript and only affects links in notes (with markdown enabled) and HTML blocks on the client side. The existence of the linked data records is not checked in advance. Errors only occur when the link is clicked.

**Syntax:**
- Markdown: `[Link display title](#@wt=i@I1@)`
- HTML: `<a href="#@wt=i@I1@">Link display title</a>`
  e.g. also in cooperation with the name badge function of the [“⚶ Vesta Classic Look & Feel” module](https://github.com/vesta-webtrees-2-custom-modules/vesta_classic_laf): `<a href="#@fsft=<ref/>"></a>`


### Markdown Image Support
Images of gedcom media records reside behind the media firewall. Therefore, this function cannot be provided with JavaScript, but by extending the MarkDownFactory Class.
If restriction rules apply to the record, instead of the image, a message is displayed.

The images are packed into a div container together with an image subtitle - which is also a link to the media data set for GEDCOM objects. The display can be customized as required using the standard or additional CSS classes.

**Syntax:**
- `![alternate text for gedcom object](#@id=@M1@)`
- `![alternate text for picture in public folder](#@public=webtrees.png "title")`
- `![picture with defined height, width and additional css class](#@id=@M1@&h=200&w=200&cname=float-right)`


### Markdown editor
You can also enable a visual **markdown editor** for note textareas. Under the hood the project “TinyMDE - A tiny, dependency-free embeddable HTML/JavaScript Markdown editor” is used - see also: <https://github.com/jefago/tiny-markdown-editor>

Besides syntax higlighting it ships with an icon bar for common format commands, a help popup and line numbering.

Note: Unfortunately, the on-screen keyboard does NOT work as before with the previous text input field. The selected characters end up as an intermediate step in the small text field below the Markdown editor and then must be copied manually to the desired position.


### German Webtrees Manual
A context-sensitive link to the [German Webtrees Manual](https://wiki.genealogy.net/Webtrees_Handbuch) can be added by javascript to the small navigation menu (only on the frontend of webtrees, without applying patch P002).

TODO routes in database table XXX define the context help link. Fallback by generic rules, if nothing applies to the startpage of the manual.

### Patches
The util subfolder contains minor patches for the webtrees core.
These are [diff-files](https://www.gnu.org/software/diffutils/) that can be easily applied or removed using shell from within the installed module folder (necessary for auto detecting webtrees sources):

```bash 
Usage: util/wt-patch.sh [-R] [FILTER]
  -R                  undo Patch (patch -R)
  FILTER              '*' for all or specific (e.g. '01')
```
Don't forget to reapply the patches after updating webtrees.

These are minor bug fixes or functional enhancements — usually in a single file — that are intended to bridge the gap until they are officially fixed/implemented in the webtrees core.

| #    | Description | applies to version |
| :--: | :----       | :---:   |
| P001 | Backlink for level 1 shared notes [#5181](https://github.com/fisharebest/webtrees/issues/5181) <br> */app/Fact.php* | 2.2.1 - |
| P002 | Enable headContent/bodyContent for this module on admin backend in order to show the context help link <br> *resources/views/layouts/administration.phtml* | 2.2.1 - |
| P003 | Record has multiple uid fields [#4828](https://github.com/fisharebest/webtrees/issues/4828) <br> *app/Services/GedcomEditService.php* | 2.2.1 - |



<a name="webtrees"></a>
## webtrees

**[webtrees](https://webtrees.net/)** is an online collaborative genealogy application.
This can be hosted on your own server by following the [Install instructions](https://webtrees.net/install/).


<a name="requirements"></a>
## Requirements

This module requires **webtrees** version 2.2.
This module has the same requirements as [webtrees#system-requirements](https://github.com/fisharebest/webtrees#system-requirements).

This module was tested with **webtrees** versions 2.2.1
and all available themes and some other custom modules.

<a name="installation"></a>
## Installation
To manually install the module, perform the following steps:

1. Download the [latest release](https://github.com/bschwede/linkenhancer/releases/latest) of the module.
2. Upload the downloaded file to your web server.
3. Unzip the package into your ``modules_v4`` directory.
4. Rename the folder to ``linkenhancer``

If everything was successful, you should see a subdirectory ``linkenhancer`` with the unpacked content in the ``modules_v4`` directory.

<a name="contributing"></a>
## Contributing

If you'd like to contribute to this module, great! You can contribute by

* Contributing code - check out the issues for things that need attention. If you have changes you want to make not listed in an issue, please create one, then you can link your pull request.
* Testing - it's all manual currently, please [create an issue](https://github.com/bschwede/linkenhancer/issues) for any bugs you find.

<a name="translation"></a>
## Translation

You can use a local editor, like [Poedit](https://poeditor.com/) or [Notepad++](https://notepad-plus-plus.org/) to make the translations and send them back to me. You can do this via a pull request (if you know how) or by e-mail.

Discussion on translating can be done by creating an [issue](https://github.com/bschwede/linkenhancer/issues).

Updated translations will be included in the next release of this module.

Beside English the following languages are available:
* German



<a name="support"></a>
## Support

* **Issues**: for any ideas you have, or when finding a bug you can raise an [issue](https://github.com/bschwede/linkenhancer/issues).

* **Forum**: general webtrees support can be found at the [webtrees forum](http://www.webtrees.net/).

<a name="license"></a>
## License

* Copyright (C) 2025 Bernd Schwendinger
* Derived from **webtrees** - Copyright 2025 webtrees development team.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.

* * *
