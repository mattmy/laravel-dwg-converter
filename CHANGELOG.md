# Changelog

All notable changes to this project will be documented in this file. Laravel DWG Converter follows
[Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [2.0.1] - 2026-10-20

### Removed

- Remove the unused internal stdout-to-file sink and its runner-only size parameter; artifact byte limits and process diagnostics remain unchanged.

### Fixed

- Validate embedded BMP, PNG, and WMF thumbnail headers and container bounds before returning their original bytes, without adding an image decoder dependency.
- Map temporary-directory and snapshot filesystem warnings to the existing package failures, close source streams when snapshot creation fails, and reject incomplete binary snapshot writes.
- Reject NUL bytes in source and temporary-directory paths through the existing input and configuration contracts.
- Check archive entry names directly so the CI exclusion gate also detects forbidden files at the archive root.
- Reject cloning, serialization, and unserialization of `DwgOutput` with `LogicException` without consuming the original result or granting a failed copy ownership of its temporary files.
- Keep temporary cleanup failures from interrupting successful delivery or replacing primary failures, including during destruction; emit one sanitized warning through Laravel's default logger without retrying cleanup or propagating logger failures.
- Refresh artifact metadata before terminal delivery so cached sizes cannot bypass output limits.
- Keep LibreOffice and ImageMagick read failures as process failures rather than invalid DWG input.
- Report truncated WebP artifacts as `image_invalid` before reading their RIFF length.
- Preserve primary failures during explicit snapshot/conversion cleanup and terminal consumption; consumed outputs no longer repeat cleanup in their destructor.
- Report `invalid_configuration` when the configured temporary directory is an existing file.

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

[Unreleased]: https://github.com/mattmy/laravel-dwg-converter/compare/v2.0.1...HEAD
[2.0.1]: https://github.com/mattmy/laravel-dwg-converter/compare/v0.2.0...v2.0.1
[0.2.0]: https://github.com/mattmy/laravel-dwg-converter/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/mattmy/laravel-dwg-converter/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/mattmy/laravel-dwg-converter/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/mattmy/laravel-dwg-converter/releases/tag/v0.1.0
