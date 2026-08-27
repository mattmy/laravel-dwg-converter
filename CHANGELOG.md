# Changelog

## Unreleased

- Add DWG to DXF, raster image, and embedded-thumbnail operations backed by user-installed CLI tools.
- Bind each source at `toDxf()`, `toImage()`, or `thumbnail()` and keep terminal verbs argument-free.
- Add bounded temporary workspaces, one-time outputs, Laravel Storage streaming, and stable package failures.
- Replace the unshipped SVG operation with the fixed DXF, LibreOffice PNG, and ImageMagick raster pipeline.
- Add PNG, JPEG, and WebP output formats, intermediate DXF-version selection, and HIGH, MEDIUM, LOW preview-resolution presets.
- Add `Dwg::toJson($source)->convert()` for validated LibreDWG structural JSON output.
- Allow missing, null, zero, and negative byte-limit settings to disable their respective limits.
