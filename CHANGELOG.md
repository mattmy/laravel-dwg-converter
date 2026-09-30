# Changelog

All notable changes to this project will be documented in this file. Laravel DWG Converter follows
[Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.2.0] - 2026-09-30

### Changed

- Lower the minimum PHP version to 8.2 while retaining Laravel 12 and 13 support; CI now verifies PHP 8.2 with Laravel 12 at lowest and current dependency sets.

### Fixed

- Preserve the underlying JSON parser exception when LibreDWG produces malformed JSON.
- Close an opened input stream when creating the workspace output snapshot fails.

## [0.1.2] - 2026-09-09

### Changed

- Allow `DwgOutput::storeAs()` to derive a safe filename when omitted and normalize or append the trusted output extension.

## [0.1.1] - 2026-09-02

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

[Unreleased]: https://github.com/mattmy/laravel-dwg-converter/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/mattmy/laravel-dwg-converter/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/mattmy/laravel-dwg-converter/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/mattmy/laravel-dwg-converter/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/mattmy/laravel-dwg-converter/releases/tag/v0.1.0
