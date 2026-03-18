#!/usr/bin/env python3
"""
CSV Code Frequency Analyzer
Counts occurrences of codes in the 3rd field of a CSV file and displays
a table with code, display name, count, and percentage.
"""

import csv
import sys
import argparse
from collections import Counter


def analyze_csv(filepath: str, delimiter: str = ",") -> None:
    code_counts: Counter = Counter()
    code_display: dict[str, str] = {}

    try:
        with open(filepath, newline="", encoding="utf-8-sig") as f:
            reader = csv.reader(f, delimiter=delimiter)
            header = next(reader, None)  # Skip header row if present

            for line_num, row in enumerate(reader, start=2):
                if len(row) < 4:
                    print(f"  Warning: line {line_num} has fewer than 4 fields, skipping.", file=sys.stderr)
                    continue

                code = row[13].strip()
                display = row[14].strip()

                if not code:
                    continue

                code_counts[code] += 1
                # Keep the first display name seen for each code
                if code not in code_display:
                    code_display[code] = display

    except FileNotFoundError:
        print(f"Error: File '{filepath}' not found.", file=sys.stderr)
        sys.exit(1)
    except PermissionError:
        print(f"Error: Permission denied reading '{filepath}'.", file=sys.stderr)
        sys.exit(1)

    if not code_counts:
        print("No data found.")
        return

    total = sum(code_counts.values())
    sorted_codes = sorted(code_counts.items(), key=lambda x: x[1], reverse=True)

    # Calculate column widths dynamically
    col_code    = max(len("Code"),    max(len(c)                   for c in code_display))
    col_display = max(len("Display"), max(len(d)                   for d in code_display.values()))
    col_count   = max(len("Count"),   len(f"{total:,}"))
    col_pct     = len("Percentage")

    def md_row(code, display, count, pct):
        return f"| {code:<{col_code}} | {display:<{col_display}} | {count:>{col_count}} | {pct:>{col_pct}} |"

    separator = f"| {'-' * col_code} | {'-' * col_display} | {'-' * col_count} | {'-' * col_pct} |"

    print(f"\n**File:** {filepath}  ")
    print(f"**Rows:** {total:,} | **Unique codes:** {len(code_counts):,}\n")
    print(md_row("Code", "Display", "Count", "Percentage"))
    print(separator)

    for code, count in sorted_codes:
        display = code_display[code]
        pct = f"{count / total * 100:.2f}%"
        print(md_row(code, display, f"{count:,}", pct))

    print(separator)
    print(md_row("**TOTAL**", "", f"{total:,}", "100.00%"))
    print()


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Count code frequencies (3rd field) from a CSV file."
    )
    parser.add_argument("csv_file", help="Path to the CSV file")
    parser.add_argument(
        "-d", "--delimiter",
        default=",",
        help="Field delimiter (default: ','). Use '\\t' for TSV.",
    )
    args = parser.parse_args()

    delimiter = "\t" if args.delimiter == "\\t" else args.delimiter
    analyze_csv(args.csv_file, delimiter)


if __name__ == "__main__":
    main()