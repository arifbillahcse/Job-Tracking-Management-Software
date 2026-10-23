#!/usr/bin/env python3
"""Small file inspectors for the shell smoke tests (CSV / XLSX content checks that are awkward in bash).

  smoke_helpers.py csvrows FILE            print the number of data rows
  smoke_helpers.py injection FILE          assert formula-looking cells were neutralised with a leading apostrophe
  smoke_helpers.py problems FILE ROWS      assert an import problem report has ROWS problem rows with row numbers and reasons
  smoke_helpers.py xlsx FILE ROWS          assert FILE is a well-formed workbook with ROWS data rows, real dates, a frozen header and a filter
  smoke_helpers.py zip FILE                assert FILE is an intact zip containing xl/workbook.xml
"""
import csv
import sys
import xml.dom.minidom as minidom
import zipfile


def read_csv(path):
    with open(path, encoding="utf-8-sig", newline="") as handle:
        return list(csv.reader(handle))


def main():
    cmd, path = sys.argv[1], sys.argv[2]
    if cmd == "csvrows":
        print(len(read_csv(path)) - 1)
    elif cmd == "injection":
        row = read_csv(path)[1]
        assert row[1].startswith("'=HYPERLINK"), row[1]
        assert row[2] == "'+cmd", row[2]
        assert row[5] == "'-1" and row[6] == "'@x", (row[5], row[6])
    elif cmd == "problems":
        rows = read_csv(path)
        assert rows[0][:2] == ["Row", "Problems"], rows[0]
        assert len(rows) - 1 == int(sys.argv[3]), (len(rows) - 1, sys.argv[3])
        assert all(r[0].isdigit() and r[1] for r in rows[1:]), rows[1:]
        assert any("not a valid date" in r[1] for r in rows[1:])
        assert any(r[2].startswith("'=") for r in rows[1:]), "formula cell not neutralised"
    elif cmd == "xlsx":
        archive = zipfile.ZipFile(path)
        assert archive.testzip() is None
        for name in archive.namelist():
            minidom.parseString(archive.read(name))  # every part is well-formed XML
        sheet = archive.read("xl/worksheets/sheet1.xml").decode()
        rows = sheet.count("<row ") - 1
        assert rows == int(sys.argv[3]), (rows, sys.argv[3])
        assert 'pane ySplit="1"' in sheet and "<autoFilter" in sheet and 's="2"' in sheet
    elif cmd == "zip":
        archive = zipfile.ZipFile(path)
        assert archive.testzip() is None and "xl/workbook.xml" in archive.namelist()
    else:
        raise SystemExit("unknown command " + cmd)


if __name__ == "__main__":
    main()
