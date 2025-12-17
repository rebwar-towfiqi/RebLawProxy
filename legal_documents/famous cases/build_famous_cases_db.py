#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
build_famous_cases_db.py – ساخت پایگاه‌داده پرونده‌های مشهور از فایل‌های متنی

Usage:
    python build_famous_cases_db.py --src "legal_documents/famous cases" --db laws.db
"""

import os
import argparse
import sqlite3
from pathlib import Path

def normalize_title(file_name: str) -> str:
    """Remove 'famous_cases.' prefix and .txt suffix"""
    base = Path(file_name).stem
    if base.lower().startswith("famous_cases."):
        base = base[14:]
    return base.strip()

def load_cases(src_folder: Path) -> list[tuple[str, str]]:
    """Read all .txt files in the folder and return list of (title, summary)"""
    cases = []
    for file in sorted(src_folder.glob("*.txt")):
        title = normalize_title(file.name)
        try:
            text = file.read_text(encoding="utf-8").strip()
            if text:
                cases.append((title, text))
        except Exception as e:
            print(f"❌ Error reading {file.name}: {e}")
    return cases

def init_db(db_file: Path):
    """Create the table if it does not exist"""
    with sqlite3.connect(db_file) as conn:
        conn.execute("""
        CREATE TABLE IF NOT EXISTS famous_cases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT UNIQUE,
            summary TEXT
        );
        """)
        conn.commit()

def insert_cases(db_file: Path, cases: list[tuple[str, str]]):
    """Insert all cases into database"""
    with sqlite3.connect(db_file) as conn:
        cur = conn.cursor()
        count = 0
        for title, summary in cases:
            try:
                cur.execute("INSERT OR REPLACE INTO famous_cases (title, summary) VALUES (?, ?)", (title, summary))
                count += 1
            except Exception as e:
                print(f"⚠️ Could not insert '{title}': {e}")
        conn.commit()
    print(f"✅ {count} پرونده وارد پایگاه‌داده شد.")

def main():
    parser = argparse.ArgumentParser(description="Build famous cases database")
    parser.add_argument("--src", type=str, required=True, help="Path to folder containing .txt files")
    parser.add_argument("--db", type=str, default="laws.db", help="Output SQLite database file")
    args = parser.parse_args()

    src_folder = Path(args.src)
    db_file = Path(args.db)

    if not src_folder.exists():
        print(f"❌ مسیر یافت نشد: {src_folder}")
        return

    init_db(db_file)
    cases = load_cases(src_folder)
    insert_cases(db_file, cases)

if __name__ == "__main__":
    main()
