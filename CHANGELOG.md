# Changelog

All notable changes to this project will be documented in this file. Laravel DWG Converter follows
[Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed

- Report converted DXF artifacts as `image/vnd.dxf`, so MIME-based consumers resolve the trusted `.dxf` extension.

## [0.1.0] - 2026-09-01

### Added

- Add DWG to DXF, raster image, and embedded-thumbnail operations backed by user-installed CLI tools.
- Bind each source at `toDxf()`, `toImage()`, or `thumbnail()` and keep terminal verbs argument-free.
- Add bounded temporary workspaces, one-time outputs, Laravel Storage streaming, and stable package failures.
- Replace the unshipped SVG operation with the fixed DXF, LibreOffice PNG, and ImageMagick raster pipeline.
- Add PNG, JPEG, and WebP output formats, intermediate DXF-version selection, and HIGH, MEDIUM, LOW preview-resolution presets.
- Add `Dwg::toJson($source)->convert()` for validated LibreDWG structural JSON output.
- Allow missing, null, zero, and negative byte-limit settings to disable their respective limits.

[Unreleased]: https://github.com/mattmy/laravel-dwg-converter/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/mattmy/laravel-dwg-converter/releases/tag/v0.1.0
