import argparse
import csv
import sys


EXPECTED_COLUMNS = [
    "full_name",
    "role",
    "event_name",
    "opinion",
    "opinion_date_raw",
    "source",
    "profile_image_url",
]


def sql_escape(value: str) -> str:
    value = value.replace("\\", "\\\\")
    value = value.replace("'", "''")
    return value


def to_sql_value(value: str) -> str:
    if value is None:
        return "NULL"
    value = value.strip()
    if value == "":
        return "NULL"
    return f"'{sql_escape(value)}'"


def iter_rows(csv_path: str, encoding: str):
    with open(csv_path, "r", encoding=encoding, newline="") as handle:
        reader = csv.reader(handle)
        header = next(reader, None)
        if header is None:
            return
        for row in reader:
            if not row:
                continue
            yield row


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Convert references CSV to one SQL INSERT per line."
    )
    parser.add_argument("csv_path", help="Path to referencje CSV file.")
    parser.add_argument(
        "-o", "--output", help="Output SQL file path. Defaults to stdout."
    )
    parser.add_argument(
        "--encoding",
        default="utf-8-sig",
        help="CSV encoding (default: utf-8-sig).",
    )
    parser.add_argument(
        "--table",
        default="references_entries",
        help="Target table name (default: references_entries).",
    )
    args = parser.parse_args()

    out_handle = open(args.output, "w", encoding="utf-8") if args.output else sys.stdout
    try:
        for row in iter_rows(args.csv_path, args.encoding):
            row = (row + [""] * len(EXPECTED_COLUMNS))[: len(EXPECTED_COLUMNS)]
            values = [to_sql_value(value) for value in row]

            insert_values = values[:]
            insert_values.insert(5, "NULL")  # opinion_date is derived later
            insert_values.append("1")  # is_enabled

            columns = [
                "full_name",
                "role",
                "event_name",
                "opinion",
                "opinion_date_raw",
                "opinion_date",
                "source",
                "profile_image_url",
                "is_enabled",
            ]

            sql = (
                f"INSERT INTO {args.table} ({', '.join(columns)}) "
                f"VALUES ({', '.join(insert_values)});"
            )
            out_handle.write(sql + "\n")
    finally:
        if out_handle is not sys.stdout:
            out_handle.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
