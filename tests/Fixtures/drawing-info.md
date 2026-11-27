# Drawing information fixture

`drawing-info.dwg` is an original synthetic R2010 drawing created for this package and distributed under the package's MIT license. It contains only generated geometry and test metadata. SHA-256 is `fae4d619c70e916b453fc364877e6840afdddb1e30ba8d5737d9ca70c09d9056`.

The seed was an ASCII DXF with an R2000 header, `INSUNITS=0`, and sixteen copies of a line on layer 0 from (0, 0) to (1, 1). The workspace's Windows LibreDWG tools converted the seed to DWG with `dxf2dwg --as r2000` and dumped it with `dwgread -O JSON`. The metadata below was set in the dump before writing this fixture with `dwgwrite -I JSON --as r2010`.

| Source field | Fixture value |
| --- | --- |
| `HEADER.INSUNITS` | `0` |
| `HEADER.TDUCREATE` | `[2440588, 45296123]`, or `1970-01-01T12:34:56.123Z` |
| `HEADER.TDUUPDATE` | `[2451545, 86399999]`, or `2000-01-01T23:59:59.999Z` |
| `SummaryInfo.TITLE` | `R18 synthetic drawing` |
| `SummaryInfo.SUBJECT` | `Drawing information` |
| `SummaryInfo.AUTHOR` | `DWG Converter tests` |
| `SummaryInfo.props` | `KEY` with value `0017`, then `KEY` with value ` A-101 ` |

The Integration suite reads the fixture with `LIBREDWG_DWGREAD`. The write tools were used only to create the fixture.
