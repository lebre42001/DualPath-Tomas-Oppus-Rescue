"""Validate completed short-panic Excel reviews and export sealed CSV submissions.

The script uses only the Python standard library to read XLSX files. It never
changes workbooks or templates and creates output only after both workbooks pass.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import zipfile
from datetime import datetime
from pathlib import Path
from xml.etree import ElementTree as ET


ROOT = Path(__file__).resolve().parents[2]
PACK = ROOT / "ai_service" / "artifacts" / "review_packs" / "short_panic_v1_human_review_20261001"
SOURCE_A = PACK / "ai_assisted" / "REVIEWER_A_CONFIRMATION.csv"
SOURCE_B = PACK / "ai_assisted" / "REVIEWER_B_LANGUAGE_CONFIRMATION.csv"
DEFAULT_A = PACK / "reviewer_A" / "REVIEWER_A_SHORT_PANIC.xlsx"
DEFAULT_B = PACK / "reviewer_B" / "REVIEWER_B_SHORT_PANIC.xlsx"
DEFAULT_OUTPUT = PACK / "coordinator" / "submissions"
RUBRIC = "TEXT-RUBRIC-0.2-B"
LABELS = {"minor", "serious", "critical", "needs_review"}
NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main", "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships"}
REL_NS = {"p": "http://schemas.openxmlformats.org/package/2006/relationships"}


class ReviewValidationError(ValueError):
    pass


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def column_number(reference: str) -> int:
    letters = re.match(r"[A-Z]+", reference).group(0)
    value = 0
    for character in letters:
        value = value * 26 + ord(character) - 64
    return value


class XlsxBook:
    def __init__(self, path: Path):
        self.path = path
        if not path.is_file():
            raise ReviewValidationError(f"Workbook does not exist: {path}")
        try:
            self.archive = zipfile.ZipFile(path)
            self.shared = self._shared_strings()
            self.sheets, self.states = self._sheet_paths()
        except (zipfile.BadZipFile, KeyError, ET.ParseError) as exc:
            raise ReviewValidationError(f"Invalid XLSX workbook: {path}") from exc

    def _shared_strings(self) -> list[str]:
        try:
            root = ET.fromstring(self.archive.read("xl/sharedStrings.xml"))
        except KeyError:
            return []
        return ["".join(node.text or "" for node in item.findall(".//m:t", NS)) for item in root.findall("m:si", NS)]

    def _sheet_paths(self) -> tuple[dict[str, str], dict[str, str]]:
        workbook = ET.fromstring(self.archive.read("xl/workbook.xml"))
        rels = ET.fromstring(self.archive.read("xl/_rels/workbook.xml.rels"))
        targets = {item.attrib["Id"]: item.attrib["Target"] for item in rels.findall("p:Relationship", REL_NS)}
        paths: dict[str, str] = {}
        states: dict[str, str] = {}
        for sheet in workbook.findall("m:sheets/m:sheet", NS):
            name = sheet.attrib["name"]
            target = targets[sheet.attrib[f"{{{NS['r']}}}id"]].lstrip("/")
            if not target.startswith("xl/"):
                target = "xl/" + target
            paths[name] = target
            states[name] = sheet.attrib.get("state", "visible")
        return paths, states

    def cells(self, sheet_name: str) -> dict[str, dict[str, str]]:
        if sheet_name not in self.sheets:
            raise ReviewValidationError(f"Missing sheet {sheet_name} in {self.path.name}")
        root = ET.fromstring(self.archive.read(self.sheets[sheet_name]))
        result: dict[str, dict[str, str]] = {}
        for cell in root.findall(".//m:c", NS):
            reference = cell.attrib["r"]
            kind = cell.attrib.get("t")
            formula = cell.findtext("m:f", default="", namespaces=NS)
            if kind == "inlineStr":
                value = "".join(node.text or "" for node in cell.findall(".//m:t", NS))
            else:
                value = cell.findtext("m:v", default="", namespaces=NS)
                if kind == "s" and value != "":
                    value = self.shared[int(value)]
                elif kind == "b":
                    value = "TRUE" if value == "1" else "FALSE"
            result[reference] = {"value": value, "formula": formula, "style": cell.attrib.get("s", "0")}
        return result

    def value(self, cells: dict[str, dict[str, str]], reference: str) -> str:
        return str(cells.get(reference, {}).get("value", "")).strip()


def validate_timestamp(value: str) -> None:
    if not value:
        raise ReviewValidationError("Review Completion Timestamp is blank")
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise ReviewValidationError("Completion timestamp must be valid ISO-8601") from exc
    if parsed.tzinfo is None or parsed.utcoffset() is None:
        raise ReviewValidationError("Completion timestamp must include a timezone")


def validate_metadata(book: XlsxBook, expected_id: str, expected_name: str) -> tuple[dict, str]:
    cells = book.cells("REVIEW")
    if book.value(cells, "B2") != expected_id:
        raise ReviewValidationError(f"Reviewer ID must be {expected_id} in {book.path.name}")
    if book.value(cells, "B3") != expected_name:
        raise ReviewValidationError(f"Reviewer Name must be {expected_name} in {book.path.name}")
    if book.value(cells, "B5") != RUBRIC:
        raise ReviewValidationError(f"Rubric Version must be {RUBRIC} in {book.path.name}")
    timestamp = book.value(cells, "B6")
    validate_timestamp(timestamp)
    if book.states.get("IMPORT_DATA") not in {"hidden", "veryHidden"}:
        raise ReviewValidationError(f"IMPORT_DATA must remain hidden in {book.path.name}")
    return cells, timestamp


def validate_source_rows(book: XlsxBook, cells: dict, source: list[dict[str, str]]) -> dict[str, dict[str, str]]:
    if len(source) != 80:
        raise ReviewValidationError("Source confirmation CSV must contain exactly 80 rows")
    by_alias = {row["neutral_alias"]: row for row in source}
    aliases: list[str] = []
    for number, row_number in enumerate(range(9, 89), 1):
        if book.value(cells, f"A{row_number}") not in {str(number), f"{number}.0"}:
            raise ReviewValidationError(f"Row number changed at REVIEW!A{row_number}")
        alias = book.value(cells, f"B{row_number}")
        if alias not in by_alias or alias in aliases:
            raise ReviewValidationError(f"Missing, unknown or duplicate alias at REVIEW!B{row_number}")
        aliases.append(alias)
        original = by_alias[alias]
        expected = {
            f"C{row_number}": original["language"], f"D{row_number}": original["text"],
            f"E{row_number}": original["ai_suggested_label"], f"F{row_number}": original["ai_decisive_evidence"],
        }
        for reference, value in expected.items():
            if book.value(cells, reference) != value:
                raise ReviewValidationError(f"Protected source value changed at REVIEW!{reference}")
    if set(aliases) != set(by_alias):
        raise ReviewValidationError("Workbook aliases do not exactly match the source CSV")
    return by_alias


def validate_import_structure(book: XlsxBook, expected_headers: list[str], source: list[dict[str, str]]) -> None:
    cells = book.cells("IMPORT_DATA")
    actual = [book.value(cells, f"{chr(64 + index)}1") for index in range(1, len(expected_headers) + 1)]
    if actual != expected_headers:
        raise ReviewValidationError(f"IMPORT_DATA headers are incompatible in {book.path.name}")
    source_by_alias = {row["neutral_alias"]: row for row in source}
    for row_number in range(2, 82):
        alias = book.value(cells, f"B{row_number}")
        if alias not in source_by_alias:
            raise ReviewValidationError(f"IMPORT_DATA alias mismatch at row {row_number}")
        original = source_by_alias[alias]
        for reference, value in ((f"A{row_number}", original["candidate_id"]), (f"C{row_number}", original["language"]),
                                 (f"D{row_number}", original["text"]), (f"E{row_number}", original["ai_suggested_label"])):
            if book.value(cells, reference) != value:
                raise ReviewValidationError(f"IMPORT_DATA source mismatch at {reference}")


def reviewer_a_rows(path: Path) -> tuple[list[dict[str, str]], str]:
    source = read_csv(SOURCE_A)
    book = XlsxBook(path)
    cells, timestamp = validate_metadata(book, "REV-A-002", "Bimbo Trimucha")
    by_alias = validate_source_rows(book, cells, source)
    fields = ["candidate_id", "neutral_alias", "language", "text", "ai_suggested_label", "reviewer_id",
              "responder_decision", "human_final_label", "human_decisive_evidence", "human_notes",
              "human_immediate_attention", "reviewed_at", "rubric_version", "human_review_status"]
    validate_import_structure(book, fields, source)
    output = []
    decision_map = {"✓ APPROVE": "approve", "✎ REVISE": "revise", "? UNRESOLVED": "unresolved"}
    attention_map = {"✓ YES": "yes", "✗ NO": "no", "? UNCERTAIN": "uncertain"}
    for row_number in range(9, 89):
        alias = book.value(cells, f"B{row_number}")
        original = by_alias[alias]
        visible_decision = book.value(cells, f"G{row_number}")
        corrected = book.value(cells, f"H{row_number}")
        attention = book.value(cells, f"I{row_number}")
        note = book.value(cells, f"J{row_number}")
        if visible_decision not in decision_map:
            raise ReviewValidationError(f"Reviewer A row {row_number} is INCOMPLETE: Decision is missing")
        decision = decision_map[visible_decision]
        if corrected and corrected not in LABELS:
            raise ReviewValidationError(f"Reviewer A row {row_number} has an invalid Corrected Label")
        if decision == "approve":
            if corrected:
                raise ReviewValidationError(f"Reviewer A row {row_number} is ERROR: APPROVE has a Corrected Label")
            if attention not in attention_map:
                raise ReviewValidationError(f"Reviewer A row {row_number} is INCOMPLETE: Immediate Attention is missing")
            final_label = original["ai_suggested_label"]
            evidence = note or "Reviewer approved the AI evidence shown in the workbook: " + original["ai_decisive_evidence"]
            status = "completed"
        elif decision == "revise":
            if not corrected:
                raise ReviewValidationError(f"Reviewer A row {row_number} is ERROR: REVISE needs a Corrected Label")
            if not note or attention not in attention_map:
                raise ReviewValidationError(f"Reviewer A row {row_number} is INCOMPLETE: REVISE needs a note and Immediate Attention")
            final_label, evidence, status = corrected, note, "completed"
        else:
            if corrected:
                raise ReviewValidationError(f"Reviewer A row {row_number} is ERROR: UNRESOLVED has a Corrected Label")
            if not note:
                raise ReviewValidationError(f"Reviewer A row {row_number} is INCOMPLETE: UNRESOLVED needs a note")
            final_label, evidence, status = "", note, "unresolved"
        output.append({
            "candidate_id": original["candidate_id"], "neutral_alias": alias, "language": original["language"],
            "text": original["text"], "ai_suggested_label": original["ai_suggested_label"], "reviewer_id": "REV-A-002",
            "responder_decision": decision, "human_final_label": final_label, "human_decisive_evidence": evidence,
            "human_notes": note, "human_immediate_attention": attention_map.get(attention, ""), "reviewed_at": timestamp,
            "rubric_version": RUBRIC, "human_review_status": status,
        })
    return output, timestamp


def reviewer_b_rows(path: Path) -> tuple[list[dict[str, str]], str]:
    source = read_csv(SOURCE_B)
    book = XlsxBook(path)
    cells, timestamp = validate_metadata(book, "REV-B-001", "Bimbo Cortel")
    by_alias = validate_source_rows(book, cells, source)
    fields = list(source[0])
    validate_import_structure(book, fields, source)
    decisions = {"✓ CONFIRM": "confirm", "✎ REVISE": "revise", "? UNRESOLVED": "unresolved"}
    naturalness = {"✓ NATURAL": "natural", "○ ACCEPTABLE": "acceptable", "✗ UNNATURAL": "unnatural", "? UNCERTAIN": "uncertain"}
    issues = {"NONE", "NEGATION", "SUBJECT/PERSON", "TIMELINE", "PHYSICAL VS DIGITAL RESPONSE",
              "HUMAN VS OBJECT/ENGINE", "AMBIGUOUS WORDING", "TRANSLATION/LANGUAGE", "OTHER"}
    output = []
    for row_number in range(9, 89):
        alias = book.value(cells, f"B{row_number}")
        original = by_alias[alias]
        shown = book.value(cells, f"G{row_number}")
        corrected = book.value(cells, f"H{row_number}")
        shown_natural = book.value(cells, f"I{row_number}")
        issue = book.value(cells, f"J{row_number}")
        note = book.value(cells, f"K{row_number}")
        if shown not in decisions:
            raise ReviewValidationError(f"Reviewer B row {row_number} is INCOMPLETE: Decision is missing")
        if issue not in issues:
            raise ReviewValidationError(f"Reviewer B row {row_number} is INCOMPLETE: Main Issue is missing")
        decision = decisions[shown]
        if corrected and corrected not in LABELS:
            raise ReviewValidationError(f"Reviewer B row {row_number} has an invalid Corrected Label")
        if decision == "confirm":
            if corrected:
                raise ReviewValidationError(f"Reviewer B row {row_number} is ERROR: CONFIRM has a Corrected Label")
            if shown_natural not in naturalness:
                raise ReviewValidationError(f"Reviewer B row {row_number} is INCOMPLETE: CONFIRM needs Language Naturalness")
            final_label, status = original["ai_suggested_label"], "completed"
        elif decision == "revise":
            if not corrected:
                raise ReviewValidationError(f"Reviewer B row {row_number} is ERROR: REVISE needs a Corrected Label")
            if not note:
                raise ReviewValidationError(f"Reviewer B row {row_number} is INCOMPLETE: REVISE needs a Short Note")
            final_label, status = corrected, "completed"
        else:
            if corrected:
                raise ReviewValidationError(f"Reviewer B row {row_number} is ERROR: UNRESOLVED has a Corrected Label")
            if not note:
                raise ReviewValidationError(f"Reviewer B row {row_number} is INCOMPLETE: UNRESOLVED needs a Short Note")
            final_label, status = "", "unresolved"
        uncertain = decision == "unresolved" or issue == "AMBIGUOUS WORDING"
        semantic_default = "uncertain" if uncertain else "yes"
        meaning = "yes" if decision == "confirm" else "no" if decision == "revise" else "uncertain"
        output.append({
            "candidate_id": original["candidate_id"], "neutral_alias": alias, "language": original["language"],
            "text": original["text"], "ai_suggested_label": original["ai_suggested_label"],
            "ai_decisive_evidence": original["ai_decisive_evidence"], "reviewer_id": "REV-B-001",
            "meaning_confirmed": meaning, "language_naturalness": naturalness.get(shown_natural, "uncertain"),
            "negation_correct": "no" if issue == "NEGATION" else semantic_default,
            "subject_correct": "no" if issue in {"SUBJECT/PERSON", "PHYSICAL VS DIGITAL RESPONSE", "HUMAN VS OBJECT/ENGINE"} else semantic_default,
            "timeline_correct": "no" if issue == "TIMELINE" else semantic_default,
            "severity_interpretation_supported": "yes" if decision == "confirm" else "no" if decision == "revise" else "uncertain",
            "suggested_correction": note if decision == "revise" else "", "human_final_label": final_label,
            "human_notes": f"Main issue: {issue}." + (f" {note}" if note else ""), "reviewed_at": timestamp,
            "rubric_version": RUBRIC, "human_review_status": status,
        })
    return output, timestamp


def write_csv(path: Path, rows: list[dict[str, str]]) -> None:
    with path.open("x", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(rows[0]), lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Import two completed, submission-ready short-panic Excel reviews.")
    parser.add_argument("--reviewer-a", type=Path, default=DEFAULT_A)
    parser.add_argument("--reviewer-b", type=Path, default=DEFAULT_B)
    parser.add_argument("--output-root", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args(argv)
    try:
        rows_a, timestamp_a = reviewer_a_rows(args.reviewer_a.resolve())
        rows_b, timestamp_b = reviewer_b_rows(args.reviewer_b.resolve())
        if {row["neutral_alias"] for row in rows_a} != {row["neutral_alias"] for row in rows_b}:
            raise ReviewValidationError("Reviewer A and B alias sets differ")
        stamp = datetime.now().astimezone().strftime("%Y%m%d_%H%M%S%z")
        destination = args.output_root.resolve() / stamp
        destination.mkdir(parents=True, exist_ok=False)
        file_a = destination / "REVIEWER_A_CONFIRMATION_SUBMITTED.csv"
        file_b = destination / "REVIEWER_B_CONFIRMATION_SUBMITTED.csv"
        write_csv(file_a, rows_a)
        write_csv(file_b, rows_b)
        receipt = {
            "status": "sealed_human_submissions_pending_cross_review_and_adjudication",
            "created_at": datetime.now().astimezone().isoformat(), "rubric_version": RUBRIC,
            "reviewer_A": {"reviewer_id": "REV-A-002", "reviewed_at": timestamp_a, "rows": 80},
            "reviewer_B": {"reviewer_id": "REV-B-001", "reviewed_at": timestamp_b, "rows": 80},
            "source_workbooks": {
                str(args.reviewer_a.resolve()): sha256(args.reviewer_a.resolve()),
                str(args.reviewer_b.resolve()): sha256(args.reviewer_b.resolve()),
            },
            "exports": {file_a.name: sha256(file_a), file_b.name: sha256(file_b)},
            "human_review_import_complete": True,
            "adjudication_complete": False,
            "training_authorized_by_this_import": False,
            "approved_for_deployment": False,
        }
        receipt_path = destination / "submission_receipt.json"
        receipt_path.write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(json.dumps({"status": "created", "directory": str(destination), "rows_A": 80, "rows_B": 80,
                          "receipt": str(receipt_path), "approved_for_deployment": False}, indent=2))
        return 0
    except (ReviewValidationError, OSError, KeyError, IndexError, ET.ParseError) as exc:
        parser.exit(2, f"error: {exc}\nNo submission directory or CSV was created.\n")


if __name__ == "__main__":
    raise SystemExit(main())
