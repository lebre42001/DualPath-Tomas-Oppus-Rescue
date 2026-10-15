"""Create macro-free Excel review workbooks for the short_panic_v1 package."""

from __future__ import annotations

import csv
import hashlib
import json
import sys
import zipfile
from datetime import datetime
from pathlib import Path
from xml.etree import ElementTree as ET

import xlsxwriter
from xlsxwriter.utility import xl_col_to_name


ROOT = Path(__file__).resolve().parents[2]
PACK = ROOT / "ai_service" / "artifacts" / "review_packs" / "short_panic_v1_human_review_20261001"
SOURCE_A = PACK / "ai_assisted" / "REVIEWER_A_CONFIRMATION.csv"
SOURCE_B = PACK / "ai_assisted" / "REVIEWER_B_LANGUAGE_CONFIRMATION.csv"
OUTPUT_A = PACK / "reviewer_A" / "REVIEWER_A_SHORT_PANIC.xlsx"
OUTPUT_B = PACK / "reviewer_B" / "REVIEWER_B_SHORT_PANIC.xlsx"
BEFORE = PACK / "coordinator" / "excel_workflow_before.json"
VALIDATION = PACK / "coordinator" / "EXCEL_WORKFLOW_VALIDATION.json"
RUBRIC = "TEXT-RUBRIC-0.2-B"
NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def formats(workbook: xlsxwriter.Workbook) -> dict:
    return {
        "title": workbook.add_format({"bold": True, "font_size": 16, "font_color": "#FFFFFF", "bg_color": "#17365D", "align": "center", "valign": "vcenter"}),
        "meta_label": workbook.add_format({"bold": True, "bg_color": "#D9EAF7", "border": 1}),
        "meta": workbook.add_format({"border": 1, "bg_color": "#F3F6F9", "locked": True}),
        "editable": workbook.add_format({"border": 1, "bg_color": "#FFF2CC", "locked": False, "valign": "top"}),
        "editable_wrap": workbook.add_format({"border": 1, "bg_color": "#FFF2CC", "locked": False, "text_wrap": True, "valign": "top"}),
        "header": workbook.add_format({"bold": True, "font_color": "#FFFFFF", "bg_color": "#1F4E78", "border": 1, "align": "center", "valign": "vcenter", "text_wrap": True}),
        "source": workbook.add_format({"border": 1, "bg_color": "#E7E6E6", "locked": True, "valign": "top"}),
        "source_wrap": workbook.add_format({"border": 1, "bg_color": "#E7E6E6", "locked": True, "text_wrap": True, "valign": "top"}),
        "formula": workbook.add_format({"border": 1, "bg_color": "#DDEBF7", "locked": True, "valign": "top"}),
        "green": workbook.add_format({"bg_color": "#C6EFCE", "font_color": "#006100"}),
        "yellow": workbook.add_format({"bg_color": "#FFEB9C", "font_color": "#9C6500"}),
        "red": workbook.add_format({"bg_color": "#FFC7CE", "font_color": "#9C0006"}),
        "instruction_title": workbook.add_format({"bold": True, "font_size": 15, "font_color": "#FFFFFF", "bg_color": "#17365D"}),
        "instruction_head": workbook.add_format({"bold": True, "font_size": 12, "font_color": "#1F4E78", "bg_color": "#D9EAF7"}),
        "instruction": workbook.add_format({"text_wrap": True, "valign": "top"}),
        "summary_label": workbook.add_format({"bold": True, "border": 1, "bg_color": "#D9EAF7"}),
        "summary_value": workbook.add_format({"border": 1, "align": "center"}),
        "ready": workbook.add_format({"bold": True, "font_size": 14, "align": "center", "border": 1, "bg_color": "#C6EFCE", "font_color": "#006100"}),
        "not_ready": workbook.add_format({"bold": True, "font_size": 14, "align": "center", "border": 1, "bg_color": "#FFC7CE", "font_color": "#9C0006"}),
        "hidden": workbook.add_format({"locked": True}),
    }


def write_metadata(sheet, fmt: dict, reviewer_id: str, name: str, role: str) -> None:
    sheet.merge_range("A1:F1", "SHORT PANIC HUMAN CONFIRMATION", fmt["title"])
    values = [("Reviewer ID:", reviewer_id), ("Reviewer Name:", name), ("Role:", role), ("Rubric Version:", RUBRIC)]
    for row, (label, value) in enumerate(values, 1):
        sheet.write(row, 0, label, fmt["meta_label"])
        sheet.write(row, 1, value, fmt["meta"])
    sheet.write("A6", "Review Completion Timestamp:", fmt["meta_label"])
    sheet.write_blank("B6", None, fmt["editable"])
    sheet.write("C6", "ISO-8601 with timezone, e.g. 2026-10-01T21:30:00+08:00", fmt["instruction"])
    sheet.data_validation("B6", {"validate": "custom", "value": '=OR(B6="",AND(LEN(B6)>=20,MID(B6,11,1)="T",OR(MID(B6,20,1)="+",MID(B6,20,1)="-",RIGHT(B6,1)="Z")))',
                                 "input_title": "Completion time", "input_message": "Enter ISO-8601 with timezone after all 80 rows are READY.",
                                 "error_title": "Invalid timestamp", "error_message": "Use ISO-8601 with timezone.", "error_type": "stop"})
    sheet.set_row(0, 26)


def add_common_sheets(workbook, fmt: dict, reviewer: str, decision_word: str, status_column: str, final_column: str) -> None:
    how = workbook.add_worksheet("HOW_TO_REVIEW")
    how.set_column("A:A", 3)
    how.set_column("B:B", 105)
    how.merge_range("A1:B1", f"GIYA SA PAG-REVIEW - {reviewer}", fmt["instruction_title"])
    instructions_a = [
        "Basaha ang Emergency Text.", "Tan-awa ang AI Suggested Label.", "Kung sakto, pili: ✓ APPROVE.",
        "Kung sayop, pili: ✎ REVISE ug pili sa Corrected Label.",
        "Kung dili ma-decide, pili: ? UNRESOLVED ug butangi og mubo nga note.",
        "Pili kung Immediate Attention: YES, NO, or UNCERTAIN.", "Siguroha nga Row Status = READY.",
        "Human sa tanan 80, ibutang ang aktuwal nga Review Completion Timestamp.",
    ]
    instructions_b = [
        "Basaha ug sabta ang original language.", "Check ang negation, subject/person, timeline ug context.",
        "Kung sakto ang meaning ug label, pili: ✓ CONFIRM.", "Kung sayop, pili: ✎ REVISE ug pili sa Corrected Label.",
        "Kung dili masabtan, pili: ? UNRESOLVED ug butangi og mubo nga note.",
        "Pili ang Language Naturalness ug Main Issue.", "Siguroha nga Row Status = READY.",
        "Human sa tanan 80, ibutang ang aktuwal nga Review Completion Timestamp.",
    ]
    how.write("A3", "MGA LAKANG", fmt["instruction_head"])
    for index, text in enumerate(instructions_a if reviewer == "Reviewer A" else instructions_b, 1):
        how.write(index + 2, 0, index, fmt["instruction"])
        how.write(index + 2, 1, text, fmt["instruction"])
        how.set_row(index + 2, 27)
    guide_row = 12
    how.write(guide_row, 0, "LABEL GUIDE", fmt["instruction_head"])
    label_guide = [
        ("minor", "Walay injury o clearly superficial, controlled, low-risk injury."),
        ("serious", "Concrete injury/impairment beyond superficial Minor, without immediate life threat."),
        ("critical", "Immediate or potentially immediate threat to life."),
        ("needs_review", "Ang text mismo kulang o materially ambiguous."),
    ]
    for offset, (label, definition) in enumerate(label_guide, 1):
        how.write(guide_row + offset, 0, label, fmt["summary_label"])
        how.write(guide_row + offset, 1, definition, fmt["instruction"])
    how.merge_range(guide_row + 6, 0, guide_row + 6, 1, "Critical does not mean confirmed death.", fmt["red"])
    how.protect()

    summary = workbook.add_worksheet("SUMMARY")
    summary.set_column("A:A", 28)
    summary.set_column("B:B", 22)
    summary.merge_range("A1:B1", f"{reviewer.upper()} REVIEW SUMMARY", fmt["title"])
    labels = ["Total Cases", "Ready", "Incomplete", "Errors", f"{decision_word}", "Revised", "Unresolved",
              "Final Minor", "Final Serious", "Final Critical", "Final Needs Review"]
    decision_token = "APPROVE" if reviewer == "Reviewer A" else "CONFIRM"
    formulas = [
        '=COUNTA(REVIEW!B9:B88)', f'=COUNTIF(REVIEW!{status_column}9:{status_column}88,"READY")',
        f'=COUNTIF(REVIEW!{status_column}9:{status_column}88,"INCOMPLETE")', f'=COUNTIF(REVIEW!{status_column}9:{status_column}88,"ERROR")',
        f'=COUNTIF(REVIEW!G9:G88,"✓ {decision_token}")', '=COUNTIF(REVIEW!G9:G88,"✎ REVISE")',
        '=COUNTIF(REVIEW!G9:G88,"? UNRESOLVED")', f'=COUNTIF(REVIEW!{final_column}9:{final_column}88,"minor")',
        f'=COUNTIF(REVIEW!{final_column}9:{final_column}88,"serious")', f'=COUNTIF(REVIEW!{final_column}9:{final_column}88,"critical")',
        f'=COUNTIF(REVIEW!{final_column}9:{final_column}88,"needs_review")',
    ]
    cached = [80, 0, 80, 0, 0, 0, 0, 0, 0, 0, 0]
    for row, (label, formula, value) in enumerate(zip(labels, formulas, cached, strict=True), 2):
        summary.write(row - 1, 0, label, fmt["summary_label"])
        summary.write_formula(row - 1, 1, formula, fmt["summary_value"], value)
    summary.write("A14", "Completion Timestamp", fmt["summary_label"])
    summary.write_formula("B14", "=REVIEW!B6", fmt["summary_value"], "")
    summary.write("A16", "Submission Status", fmt["summary_label"])
    summary.write_formula("B16", '=IF(AND(B3=80,B4=0,B5=0,REVIEW!B6<>""),"SUBMISSION READY","NOT READY")', fmt["not_ready"], "NOT READY")
    summary.conditional_format("B16", {"type": "text", "criteria": "containing", "value": "SUBMISSION READY", "format": fmt["ready"]})
    summary.conditional_format("B16", {"type": "text", "criteria": "containing", "value": "NOT READY", "format": fmt["not_ready"]})
    summary.write("A18", "Import rule", fmt["summary_label"])
    summary.write("B18", "Import is allowed only when SUBMISSION READY.", fmt["instruction"])
    summary.protect()


def build_reviewer_a(rows: list[dict[str, str]]) -> None:
    workbook = xlsxwriter.Workbook(OUTPUT_A)
    workbook.set_properties({"title": "Reviewer A Short Panic Confirmation", "comments": "Human responses are intentionally blank."})
    workbook.set_calc_mode("auto")
    fmt = formats(workbook)
    review = workbook.add_worksheet("REVIEW")
    write_metadata(review, fmt, "REV-A-002", "Bimbo Trimucha", "Domain / Emergency Reviewer")
    headers = ["Number", "neutral_alias", "language", "Emergency Text", "AI Suggested Label", "AI Evidence",
               "Decision", "Corrected Label", "Immediate Attention", "Short Note", "Final Label", "Row Status"]
    for column, header in enumerate(headers):
        review.write(7, column, header, fmt["header"])
    for index, row in enumerate(rows, 9):
        zero = index - 1
        review.write_number(zero, 0, index - 8, fmt["source"])
        for column, key in ((1, "neutral_alias"), (2, "language"), (3, "text"), (4, "ai_suggested_label"), (5, "ai_decisive_evidence")):
            review.write(zero, column, row[key], fmt["source_wrap"] if column in {3, 5} else fmt["source"])
        for column in range(6, 10):
            review.write_blank(zero, column, None, fmt["editable_wrap"] if column == 9 else fmt["editable"])
        final_formula = f'=IF(G{index}="✓ APPROVE",E{index},IF(G{index}="✎ REVISE",H{index},""))'
        status_formula = (f'=IF(OR(AND(G{index}="✓ APPROVE",H{index}<>""),AND(G{index}="✎ REVISE",H{index}=""),'
                          f'AND(G{index}="? UNRESOLVED",OR(H{index}<>"",K{index}<>""))),"ERROR",'
                          f'IF(OR(AND(G{index}="✓ APPROVE",I{index}<>""),AND(G{index}="✎ REVISE",H{index}<>"",J{index}<>"",I{index}<>""),'
                          f'AND(G{index}="? UNRESOLVED",J{index}<>"")),"READY","INCOMPLETE"))')
        review.write_formula(zero, 10, final_formula, fmt["formula"], "")
        review.write_formula(zero, 11, status_formula, fmt["formula"], "INCOMPLETE")
        review.set_row(zero, 54)
    review.data_validation("G9:G88", {"validate": "list", "source": ["✓ APPROVE", "✎ REVISE", "? UNRESOLVED"]})
    review.data_validation("H9:H88", {"validate": "list", "source": ["minor", "serious", "critical", "needs_review"]})
    review.data_validation("I9:I88", {"validate": "list", "source": ["✓ YES", "✗ NO", "? UNCERTAIN"]})
    for rng in ("G9:G88", "L9:L88"):
        review.conditional_format(rng, {"type": "text", "criteria": "containing", "value": "APPROVE" if rng.startswith("G") else "READY", "format": fmt["green"]})
        review.conditional_format(rng, {"type": "text", "criteria": "containing", "value": "REVISE" if rng.startswith("G") else "INCOMPLETE", "format": fmt["yellow"]})
        review.conditional_format(rng, {"type": "text", "criteria": "containing", "value": "UNRESOLVED" if rng.startswith("G") else "ERROR", "format": fmt["red"]})
    review.autofilter("A8:L88")
    review.freeze_panes(8, 0)
    review.set_column("A:A", 8); review.set_column("B:B", 13); review.set_column("C:C", 10); review.set_column("D:D", 40)
    review.set_column("E:E", 18); review.set_column("F:F", 45); review.set_column("G:G", 18); review.set_column("H:H", 17)
    review.set_column("I:I", 19); review.set_column("J:J", 32); review.set_column("K:K", 15); review.set_column("L:L", 14)
    review.protect("", {"autofilter": True, "select_unlocked_cells": True, "select_locked_cells": True})
    add_common_sheets(workbook, fmt, "Reviewer A", "Approved", "L", "K")
    imp = workbook.add_worksheet("IMPORT_DATA")
    fields = ["candidate_id", "neutral_alias", "language", "text", "ai_suggested_label", "reviewer_id",
              "responder_decision", "human_final_label", "human_decisive_evidence", "human_notes",
              "human_immediate_attention", "reviewed_at", "rubric_version", "human_review_status"]
    for column, field in enumerate(fields): imp.write(0, column, field, fmt["header"])
    for offset, row in enumerate(rows, 2):
        review_row = offset + 7
        for column, key in enumerate(("candidate_id", "neutral_alias", "language", "text", "ai_suggested_label")):
            imp.write(offset - 1, column, row[key], fmt["hidden"])
        formulas = [
            "=REVIEW!$B$2", f'=IF(REVIEW!G{review_row}="✓ APPROVE","approve",IF(REVIEW!G{review_row}="✎ REVISE","revise",IF(REVIEW!G{review_row}="? UNRESOLVED","unresolved","")))',
            f"=REVIEW!K{review_row}", f'=IF(REVIEW!G{review_row}="✓ APPROVE",REVIEW!F{review_row},REVIEW!J{review_row})',
            f"=REVIEW!J{review_row}", f'=IF(REVIEW!I{review_row}="✓ YES","yes",IF(REVIEW!I{review_row}="✗ NO","no",IF(REVIEW!I{review_row}="? UNCERTAIN","uncertain","")))',
            f'=IF(REVIEW!L{review_row}="READY",REVIEW!$B$6,"")', "=REVIEW!$B$5",
            f'=IF(AND(REVIEW!L{review_row}="READY",OR(REVIEW!G{review_row}="✓ APPROVE",REVIEW!G{review_row}="✎ REVISE")),"completed",IF(AND(REVIEW!L{review_row}="READY",REVIEW!G{review_row}="? UNRESOLVED"),"unresolved",""))',
        ]
        for column, formula in enumerate(formulas, 5): imp.write_formula(offset - 1, column, formula, fmt["hidden"], "")
    imp.hide()
    workbook.close()


def build_reviewer_b(rows: list[dict[str, str]]) -> None:
    workbook = xlsxwriter.Workbook(OUTPUT_B)
    workbook.set_properties({"title": "Reviewer B Short Panic Language Confirmation", "comments": "Human responses are intentionally blank."})
    workbook.set_calc_mode("auto")
    fmt = formats(workbook)
    review = workbook.add_worksheet("REVIEW")
    write_metadata(review, fmt, "REV-B-001", "Bimbo Cortel", "Multilingual / Context Reviewer")
    headers = ["Number", "neutral_alias", "language", "Emergency Text", "AI Suggested Label", "AI Evidence", "Decision",
               "Corrected Label", "Language Naturalness", "Main Language/Context Issue", "Short Note", "Final Label", "Row Status"]
    for column, header in enumerate(headers): review.write(7, column, header, fmt["header"])
    for index, row in enumerate(rows, 9):
        zero = index - 1
        review.write_number(zero, 0, index - 8, fmt["source"])
        for column, key in ((1, "neutral_alias"), (2, "language"), (3, "text"), (4, "ai_suggested_label"), (5, "ai_decisive_evidence")):
            review.write(zero, column, row[key], fmt["source_wrap"] if column in {3, 5} else fmt["source"])
        for column in range(6, 11): review.write_blank(zero, column, None, fmt["editable_wrap"] if column == 10 else fmt["editable"])
        final_formula = f'=IF(G{index}="✓ CONFIRM",E{index},IF(G{index}="✎ REVISE",H{index},""))'
        status_formula = (f'=IF(OR(AND(G{index}="✓ CONFIRM",H{index}<>""),AND(G{index}="✎ REVISE",H{index}=""),'
                          f'AND(G{index}="? UNRESOLVED",OR(H{index}<>"",L{index}<>""))),"ERROR",'
                          f'IF(OR(AND(G{index}="✓ CONFIRM",I{index}<>"",J{index}<>""),AND(G{index}="✎ REVISE",H{index}<>"",J{index}<>"",K{index}<>""),'
                          f'AND(G{index}="? UNRESOLVED",J{index}<>"",K{index}<>"")),"READY","INCOMPLETE"))')
        review.write_formula(zero, 11, final_formula, fmt["formula"], "")
        review.write_formula(zero, 12, status_formula, fmt["formula"], "INCOMPLETE")
        review.set_row(zero, 54)
    review.data_validation("G9:G88", {"validate": "list", "source": ["✓ CONFIRM", "✎ REVISE", "? UNRESOLVED"]})
    review.data_validation("H9:H88", {"validate": "list", "source": ["minor", "serious", "critical", "needs_review"]})
    review.data_validation("I9:I88", {"validate": "list", "source": ["✓ NATURAL", "○ ACCEPTABLE", "✗ UNNATURAL", "? UNCERTAIN"]})
    review.data_validation("J9:J88", {"validate": "list", "source": ["NONE", "NEGATION", "SUBJECT/PERSON", "TIMELINE", "PHYSICAL VS DIGITAL RESPONSE", "HUMAN VS OBJECT/ENGINE", "AMBIGUOUS WORDING", "TRANSLATION/LANGUAGE", "OTHER"]})
    for rng in ("G9:G88", "M9:M88"):
        review.conditional_format(rng, {"type": "text", "criteria": "containing", "value": "CONFIRM" if rng.startswith("G") else "READY", "format": fmt["green"]})
        review.conditional_format(rng, {"type": "text", "criteria": "containing", "value": "REVISE" if rng.startswith("G") else "INCOMPLETE", "format": fmt["yellow"]})
        review.conditional_format(rng, {"type": "text", "criteria": "containing", "value": "UNRESOLVED" if rng.startswith("G") else "ERROR", "format": fmt["red"]})
    review.autofilter("A8:M88"); review.freeze_panes(8, 0)
    review.set_column("A:A", 8); review.set_column("B:B", 13); review.set_column("C:C", 10); review.set_column("D:D", 40)
    review.set_column("E:E", 18); review.set_column("F:F", 45); review.set_column("G:G", 18); review.set_column("H:H", 17)
    review.set_column("I:I", 21); review.set_column("J:J", 30); review.set_column("K:K", 32); review.set_column("L:L", 15); review.set_column("M:M", 14)
    review.protect("", {"autofilter": True, "select_unlocked_cells": True, "select_locked_cells": True})
    add_common_sheets(workbook, fmt, "Reviewer B", "Confirmed", "M", "L")
    imp = workbook.add_worksheet("IMPORT_DATA")
    fields = list(rows[0])
    for column, field in enumerate(fields): imp.write(0, column, field, fmt["header"])
    for offset, row in enumerate(rows, 2):
        review_row = offset + 7
        for column, key in enumerate(("candidate_id", "neutral_alias", "language", "text", "ai_suggested_label", "ai_decisive_evidence")):
            imp.write(offset - 1, column, row[key], fmt["hidden"])
        formulas = [
            "=REVIEW!$B$2", f'=IF(REVIEW!G{review_row}="✓ CONFIRM","yes",IF(REVIEW!G{review_row}="✎ REVISE","no",IF(REVIEW!G{review_row}="? UNRESOLVED","uncertain","")))',
            f'=IF(REVIEW!G{review_row}="","",IF(REVIEW!I{review_row}="✓ NATURAL","natural",IF(REVIEW!I{review_row}="○ ACCEPTABLE","acceptable",IF(REVIEW!I{review_row}="✗ UNNATURAL","unnatural","uncertain"))))',
            f'=IF(REVIEW!J{review_row}="NEGATION","no",IF(REVIEW!G{review_row}="? UNRESOLVED","uncertain","yes"))',
            f'=IF(OR(REVIEW!J{review_row}="SUBJECT/PERSON",REVIEW!J{review_row}="PHYSICAL VS DIGITAL RESPONSE",REVIEW!J{review_row}="HUMAN VS OBJECT/ENGINE"),"no",IF(REVIEW!G{review_row}="? UNRESOLVED","uncertain","yes"))',
            f'=IF(REVIEW!J{review_row}="TIMELINE","no",IF(REVIEW!G{review_row}="? UNRESOLVED","uncertain","yes"))',
            f'=IF(REVIEW!G{review_row}="✓ CONFIRM","yes",IF(REVIEW!G{review_row}="✎ REVISE","no",IF(REVIEW!G{review_row}="? UNRESOLVED","uncertain","")))',
            f'=IF(REVIEW!G{review_row}="✎ REVISE",REVIEW!K{review_row},"")', f"=REVIEW!L{review_row}",
            f'=IF(REVIEW!J{review_row}<>"","Main issue: "&REVIEW!J{review_row}&". "&REVIEW!K{review_row},REVIEW!K{review_row})',
            f'=IF(REVIEW!M{review_row}="READY",REVIEW!$B$6,"")', "=REVIEW!$B$5",
            f'=IF(AND(REVIEW!M{review_row}="READY",OR(REVIEW!G{review_row}="✓ CONFIRM",REVIEW!G{review_row}="✎ REVISE")),"completed",IF(AND(REVIEW!M{review_row}="READY",REVIEW!G{review_row}="? UNRESOLVED"),"unresolved",""))',
        ]
        for column, formula in enumerate(formulas, 6): imp.write_formula(offset - 1, column, formula, fmt["hidden"], "")
    imp.hide(); workbook.close()


def workbook_xml_validation(path: Path, source: list[dict[str, str]], expected_columns: int, expected_validations: int) -> list[dict[str, object]]:
    checks = []
    with zipfile.ZipFile(path) as archive:
        workbook_xml = ET.fromstring(archive.read("xl/workbook.xml"))
        sheet_names = [node.attrib["name"] for node in workbook_xml.findall("m:sheets/m:sheet", NS)]
        states = {node.attrib["name"]: node.attrib.get("state", "visible") for node in workbook_xml.findall("m:sheets/m:sheet", NS)}
        if sheet_names != ["REVIEW", "HOW_TO_REVIEW", "SUMMARY", "IMPORT_DATA"] or states["IMPORT_DATA"] != "hidden":
            raise ValueError(f"Sheet layout invalid in {path.name}")
        review = ET.fromstring(archive.read("xl/worksheets/sheet1.xml"))
        protection = review.find("m:sheetProtection", NS)
        if protection is None or review.find("m:autoFilter", NS) is None or review.find("m:sheetViews/m:sheetView/m:pane", NS) is None:
            raise ValueError(f"Protection/filter/freeze pane missing in {path.name}")
        if "password" in protection.attrib or "hashValue" in protection.attrib:
            raise ValueError(f"Workbook protection must not require a password in {path.name}")
        validations = review.find("m:dataValidations", NS)
        if validations is None or int(validations.attrib["count"]) != expected_validations:
            raise ValueError(f"Data validation count mismatch in {path.name}")
        if len(review.findall("m:conditionalFormatting", NS)) < 2:
            raise ValueError(f"Conditional formatting missing in {path.name}")
        formulas = review.findall(".//m:f", NS)
        if len(formulas) != 160:
            raise ValueError(f"Final-label/status formula count mismatch in {path.name}")
        summary = ET.fromstring(archive.read("xl/worksheets/sheet3.xml"))
        if len(summary.findall(".//m:f", NS)) < 13:
            raise ValueError(f"Summary formulas missing in {path.name}")
        import_sheet = ET.fromstring(archive.read("xl/worksheets/sheet4.xml"))
        import_rows = import_sheet.findall("m:sheetData/m:row", NS)
        if len(import_rows) != 81:
            raise ValueError(f"IMPORT_DATA row count mismatch in {path.name}")
        if expected_columns not in {13, 12}:
            raise ValueError("Unexpected review column specification")
        # Confirm that editable cells are unlocked and use a fill distinct from
        # the locked source/formula cells.
        styles = ET.fromstring(archive.read("xl/styles.xml"))
        xfs = styles.find("m:cellXfs", NS).findall("m:xf", NS)
        cell_nodes = {node.attrib["r"]: node for node in review.findall(".//m:c", NS)}
        source_xf = xfs[int(cell_nodes["D9"].attrib.get("s", "0"))]
        editable_xf = xfs[int(cell_nodes["G9"].attrib.get("s", "0"))]
        formula_ref = "L9" if expected_columns == 12 else "M9"
        formula_xf = xfs[int(cell_nodes[formula_ref].attrib.get("s", "0"))]
        editable_protection = editable_xf.find("m:protection", NS)
        if editable_protection is None or editable_protection.attrib.get("locked") != "0":
            raise ValueError(f"Editable cells are not unlocked in {path.name}")
        if source_xf.find("m:protection", NS) is not None and source_xf.find("m:protection", NS).attrib.get("locked") == "0":
            raise ValueError(f"Source cells are not locked in {path.name}")
        if formula_xf.find("m:protection", NS) is not None and formula_xf.find("m:protection", NS).attrib.get("locked") == "0":
            raise ValueError(f"Formula cells are not locked in {path.name}")
        if editable_xf.attrib.get("fillId", "0") == source_xf.attrib.get("fillId", "0"):
            raise ValueError(f"Editable cells are not distinctly highlighted in {path.name}")
    checks.extend([
        {"name": "xlsx_zip_and_xml_reload", "result": "passed", "count": 1},
        {"name": "exact_sheet_order_and_hidden_import", "result": "passed", "count": 4},
        {"name": "source_case_count", "result": "passed", "count": len(source)},
        {"name": "dropdown_ranges", "result": "passed", "count": expected_validations},
        {"name": "automatic_row_formulas", "result": "passed", "count": 160},
        {"name": "summary_formulas_and_blank_not_ready_cache", "result": "passed", "count": 13},
        {"name": "sheet_protection_filter_freeze_conditional_formatting", "result": "passed", "count": 6},
        {"name": "editable_cells_unlocked_yellow_and_source_formula_cells_locked", "result": "passed", "count": 4},
    ])
    return checks


def main() -> int:
    rebuild = sys.argv[1:] == ["--rebuild-validated-workbooks"]
    if sys.argv[1:] and not rebuild:
        raise SystemExit("usage: create_excel_human_confirmation_workflow.py [--rebuild-validated-workbooks]")
    if rebuild:
        if not all(path.is_file() for path in (OUTPUT_A, OUTPUT_B, BEFORE, VALIDATION)):
            raise SystemExit("error: rebuild requires the existing validated workflow")
        prior = json.loads(VALIDATION.read_text(encoding="utf-8"))
        for relative, item in prior["workbooks"].items():
            path = ROOT / relative
            if sha256(path) != item["sha256"]:
                raise SystemExit(f"error: refusing rebuild because workbook changed: {relative}")
    elif any(path.exists() for path in (OUTPUT_A, OUTPUT_B, BEFORE, VALIDATION)):
        raise SystemExit("error: Excel workflow output already exists; refusing to overwrite")
    rows_a, rows_b = read_csv(SOURCE_A), read_csv(SOURCE_B)
    if len(rows_a) != 80 or len(rows_b) != 80:
        raise SystemExit("error: both source confirmation CSVs must have exactly 80 rows")
    if rebuild:
        before = json.loads(BEFORE.read_text(encoding="utf-8"))
    else:
        before = {
            "stage": "before_excel_human_confirmation_workflow", "created_at": datetime.now().astimezone().isoformat(),
            "instruction_scope": "macro_free_excel_human_confirmation_only", "human_review_complete": False,
            "source_files": {SOURCE_A.relative_to(ROOT).as_posix(): sha256(SOURCE_A), SOURCE_B.relative_to(ROOT).as_posix(): sha256(SOURCE_B)},
            "original_human_worksheets": {
                "reviewer_A/worksheet.csv": sha256(PACK / "reviewer_A" / "worksheet.csv"),
                "reviewer_B/worksheet.csv": sha256(PACK / "reviewer_B" / "worksheet.csv"),
            },
            "prior_pack": {"manifest.json": sha256(PACK / "manifest.json"), "verification.json": sha256(PACK / "verification.json"),
                           "FULL_UPDATE_FOR_CHATGPT.txt": sha256(PACK / "FULL_UPDATE_FOR_CHATGPT.txt")},
        }
        BEFORE.write_text(json.dumps(before, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    build_reviewer_a(rows_a)
    build_reviewer_b(rows_b)
    checks_a = workbook_xml_validation(OUTPUT_A, rows_a, 12, 4)
    checks_b = workbook_xml_validation(OUTPUT_B, rows_b, 13, 5)
    # Independent programmatic reload through the same low-level reader used by
    # the eventual importer. This verifies exact source values and blank inputs.
    from import_excel_human_confirmations import XlsxBook, validate_import_structure, validate_source_rows
    book_a, book_b = XlsxBook(OUTPUT_A), XlsxBook(OUTPUT_B)
    cells_a, cells_b = book_a.cells("REVIEW"), book_b.cells("REVIEW")
    validate_source_rows(book_a, cells_a, rows_a)
    validate_source_rows(book_b, cells_b, rows_b)
    if any(book_a.value(cells_a, f"{column}{row}") for row in range(9, 89) for column in "GHIJ"):
        raise ValueError("Reviewer A human answers were prefilled")
    if any(book_b.value(cells_b, f"{column}{row}") for row in range(9, 89) for column in "GHIJK"):
        raise ValueError("Reviewer B human answers were prefilled")
    if book_a.value(book_a.cells("SUMMARY"), "B16") != "NOT READY" or book_b.value(book_b.cells("SUMMARY"), "B16") != "NOT READY":
        raise ValueError("Blank workbook must show NOT READY")
    fields_a = ["candidate_id", "neutral_alias", "language", "text", "ai_suggested_label", "reviewer_id",
                "responder_decision", "human_final_label", "human_decisive_evidence", "human_notes",
                "human_immediate_attention", "reviewed_at", "rubric_version", "human_review_status"]
    validate_import_structure(book_a, fields_a, rows_a)
    validate_import_structure(book_b, list(rows_b[0]), rows_b)
    for checks in (checks_a, checks_b):
        checks.extend([
            {"name": "exact_source_text_alias_language_label_evidence_reloaded", "result": "passed", "count": 80},
            {"name": "human_answer_cells_blank", "result": "passed", "count": 80},
            {"name": "blank_workbook_submission_status_not_ready", "result": "passed", "count": 1},
            {"name": "import_data_schema_and_source_mapping", "result": "passed", "count": 80},
        ])
    report = {
        "status": "passed", "created_at": datetime.now().astimezone().isoformat(),
        "workbooks": {
            OUTPUT_A.relative_to(ROOT).as_posix(): {"sha256": sha256(OUTPUT_A), "bytes": OUTPUT_A.stat().st_size, "rows": 80, "human_answers_prefilled": 0, "submission_ready": False},
            OUTPUT_B.relative_to(ROOT).as_posix(): {"sha256": sha256(OUTPUT_B), "bytes": OUTPUT_B.stat().st_size, "rows": 80, "human_answers_prefilled": 0, "submission_ready": False},
        },
        "checks": {"reviewer_A": checks_a, "reviewer_B": checks_b},
        "assertion_count": sum(item["count"] for item in checks_a + checks_b),
        "original_source_csv_hashes_unchanged": sha256(SOURCE_A) == before["source_files"][SOURCE_A.relative_to(ROOT).as_posix()] and sha256(SOURCE_B) == before["source_files"][SOURCE_B.relative_to(ROOT).as_posix()],
        "human_review_complete": False, "submission_ready": False, "training_performed": False,
    }
    VALIDATION.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
