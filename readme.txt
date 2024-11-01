=== WP Copyright ===
Contributors: zitrusblau
Tags: copyright, media manager, media upload, blur, images, upload, media, discipline, image processing
Requires at least: 4.6
Tested up to: 4.9.4
Stable tag: 2.0.5
Requires PHP: 5.6
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Enforces copyright discipline by blurring all uploaded images as long as the associated copyright info is undefined.

== Description ==

This plugin can be used as a discrete but efficient tool for websites which have to make sure that copyright information for images is mandatory.

How it works: It simply blurs all uploaded images. The original only gets restored when the copyright info is set.
The text field for the copyright info is provided as a form field in the attachment details view.

A simple but elegant solution to enforce copyright discipline among authors and editors.

This plugin is compatible with the plugin "Enhanced Media Library" (tested with version 2.4.5).

== Installation ==

= We recommend your host supports: =

* PHP version 5.6 or greater
* MySQL version 5.6 or greater
* WP Memory limit of 64 MB or greater (128 MB or higher is preferred)
* Installed gd library for image processing.

= Installation =

1. Install using the WordPress built-in Plugin installer, or Extract the zip file and drop the contents in the `wp-content/plugins/` directory of your WordPress installation.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Screenshots ==

1. Upload & Restore
2. Uploaded images get blurred immediately.
3. The original image gets restored when the copyright text field is not empty anymore.

== Frequently Asked Questions ==

= How can I get the copyright text of an image? =

Copyright texts are saved as postmeta fields with meta key "copyright".
Use the attachment_id, the meta key "copyright" and the core function "get_postmeta" to retrieve the copyright text of an image.

== Changelog ==

= 2.0.5 - 23.01.2019 =
* Fixed: Copyright check

= 2.0.4 - 10.09.2018 =
* Fixed: Unlimited loop in case of Exif copyright metadata removal

= 2.0.3 - 06.09.2018 =
* Fixed: Octal representation of file permissions

= 2.0.2 - 05.09.2018 =
* New: Default options

= 2.0.1 - 04.09.2018 =
* New: Settings for exif copyright key

= 2.0.0 - 03.09.2018 =
* New: Locked / Released permissions in settings
* Fixed: Less javascript, more database while locking / releasing images (More stability and overall performance)
* New: Recognition of certain EXIF meta data for automatic copyright and tag settings

= 1.1.2 - 04.04.2018 =
* Fixed: Another illegal function override

= 1.1.1 - 03.04.2018 =
* Fixed: Illegal function override

= 1.1.0 - 29.03.2018 =
* New Feature: Wp Copyright Settings Page
* Bugfix: Template loads without destroying core features in media modal
* Enhancement: Loading indicator

= 1.0.0 - 28.08.2017 =
* Initial Public Release
