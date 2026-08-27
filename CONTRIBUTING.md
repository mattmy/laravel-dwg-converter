# Contributing

Run `composer validate --strict`, `vendor/bin/pint --test`, `composer analyse`, `vendor/bin/pest`, and `composer audit` before submitting a change. The analysis script disables PHPStan worker processes for reliable Windows development. Do not add LibreDWG binaries to the package archive.

This library does not commit `composer.lock`; CI resolves both lowest and current dependency sets because consumers do not use a library lock file.

For a real integration run, set `DWG_CONVERTER_TEST_DWG`, `DWG_CONVERTER_TEST_IMAGE_DWG`,
`LIBREDWG_DWGBMP`, `LIBREDWG_DWG2DXF`, `DWG_CONVERTER_LIBREOFFICE`, and
`DWG_CONVERTER_IMAGEMAGICK`, then run `vendor/bin/pest --testsuite=Integration --fail-on-skipped`.
