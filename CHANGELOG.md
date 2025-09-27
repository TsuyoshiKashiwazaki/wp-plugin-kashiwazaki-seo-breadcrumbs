# Changelog

All notable changes to Kashiwazaki SEO Perfect Breadcrumbs will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2025-09-27

### Fixed
- **Subdirectory Installation URL Structure** - Fixed breadcrumb generation to correctly parse URL structure when WordPress is installed in a subdirectory
- **Home URL in Subdirectory Installations** - Home breadcrumb now correctly points to domain root instead of WordPress installation directory
- **URL Path Parsing** - Improved URL segment extraction to include WordPress installation directory as part of the breadcrumb hierarchy

### Improved
- **URL Structure Analysis** - Enhanced to properly handle complete URL paths including WordPress subdirectories
- **Breadcrumb Hierarchy** - Now correctly represents actual URL structure from domain root

## [1.0.0] - 2025-09-21

### 🎉 Initial Release

### Added
- **Revolutionary URL Structure-based Hierarchy Parsing Engine** - Analyzes actual URL paths to build breadcrumb hierarchy
- **Intelligent URL Analysis** - Automatically constructs hierarchy from URL structure without depending on WordPress internal structure
- **404 Error Auto-avoidance** - Automatically detects broken links and uses alternative URLs
- **301/302 Redirect Tracking** - Follows redirects to ensure valid breadcrumb links
- **Schema.org Structured Data Support** - Automatically generates JSON-LD structured data for SEO
- **Creator Credit Feature** - Outputs software creator information as structured data (SoftwareApplication schema)
- **URL Scraping Feature** - Automatically fetches page titles from URLs
- **24-hour Cache System** - Improves performance by caching URL check results and titles
- **Cache Clear Function** - One-click cache clearing from admin panel
- **3 Design Patterns** - Classic, Modern, and Rounded styles
- **Customizable Font Size** - Adjustable from 10px to 24px
- **SVG Icons** - Visual hierarchy indicators with home, folder, and page icons
- **Auto-insertion Feature** - Automatically adds breadcrumbs to selected post types (top/bottom/both)
- **Shortcode Support** - `[kspb_breadcrumbs]` for flexible placement
- **Theme Function** - `kspb_display_breadcrumbs()` for direct theme integration
- **Subdirectory Installation Support** - Works perfectly with WordPress installed in subdirectories
- **External Directory Recognition** - Includes non-WordPress directories in hierarchy
- **Infinite Loop Prevention** - Maximum depth of 10 levels to prevent infinite recursion
- **Responsive Design** - Mobile-friendly breadcrumb display
- **Japanese Language Support** - Full support for Japanese text and UI

### Technical Features
- URL path-based hierarchy construction
- WordPress hierarchy independence
- Support for custom post types
- Category, tag, and archive page support
- Multi-site compatibility
- GPL-2.0-or-later license

### Performance
- Optimized URL checking with HEAD requests
- Efficient caching mechanism
- Minimal database queries
- Lightweight CSS and no JavaScript dependencies

---

[1.0.1]: https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases/tag/v1.0.1
[1.0.0]: https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases/tag/v1.0.0