"""Explicit experimental word/phrase suggestions, separate from learned scores.

These are report-language rules for admin review, not a clinical triage protocol.
Negation/context coverage is deliberately bounded; uncertainty abstains.
"""
from __future__ import annotations

import re
import unicodedata

RULE_VERSION = "text-cues-20261109-v2"
RULE_NAME = "DualPath Trilingual Severity Rules"

# Group names support conflict checks across translations and synonyms.
CUES = (
    ("critical", "reported_death", r"dead|died|deceased|killed|patay|namatay|napatay|nasawi|binawian ng buhay"),
    ("critical", "reported_instability", r"fifty[\s-]*fifty|50[\s/\-]*50|critical condition|critically injured|life[\s-]threatening|nag[\s-]*aagaw[\s-]+buhay|naghihingalo|naghingalo"),
    ("critical", "unresponsive", r"unconscious|unresponsive|(?:cannot|can not|does not|unable to) wake up|cannot (?:wake|rouse) (?:him|her|them|the (?:patient|victim|driver|rider|passenger))(?: up)?|cannot be (?:woken|awakened|roused)|does not respond to (?:voice|touch)|walang malay|nawalan ng malay|(?:hindi|di) (?:na )?(?:magising|nagigising|gumigising)|walay panimuot|nawad an og panimuot|nawad-an og panimuot|dili (?:na )?(?:siya )?(?:momata|makamata|mapukaw)"),
    ("critical", "absent_breathing", r"not breathing|isn't breathing|isnt breathing|cannot breathe|can't breathe|hindi (?:na )?humihinga|di (?:na )?humihinga|walang hininga|dili (?:na )?mo\s*ginhawa|wala nay ginhawa|walay ginhawa|wala na nagginhawa"),
    ("critical", "heavy_bleeding", r"heavy bleeding|severe bleeding|bleeding heavily|uncontrolled bleeding|profuse bleeding|malakas ang pagdurugo|malakas na pagdurugo|grabe ang pagdugo|grabe nga pagdugo|kusog ang pagdugo|daghang dugo nga dili mohunong"),
    ("critical", "breathing_difficulty", r"difficulty breathing|struggling to breathe|labored breathing|hirap huminga|nahihirapang huminga|halos dili (?:na )?(?:siya )?maka?ginhawa|naglisod (?:og |sa )?pagginhawa|lisod makita ang ginhawa"),
    ("critical", "heavy_bleeding", r"(?:heavy|severe|profuse|uncontrolled) (?:\w+\s+){0,3}bleeding|bleeding (?:\w+\s+){0,4}uncontrolled|kusog nga pagdugo|(?:dugo|pagdugo|nagdugo).{0,50}(?:dili|di).{0,12}mohunong"),
    ("critical", "active_threat", r"drowning|nalulunod|nalumos|trapped in (?:a |the )?burning (?:car|building|house)|naipit sa nasusunog na sasakyan"),
    ("serious", "injury", r"injured|injuries|injury|hurt|wounded|wound|wounds|bleeding|fracture|fractured|broken (?:arms?|legs?|bones?)|nasamdan|nasamad|nasamaran|samaran|samad|nagdugo|nabal[iy]|nasugatan|nasaktan|sugat|dumudugo|bali ang (?:kamay|paa|binti|braso)"),
)
PATTERNS = [(level, group, re.compile(r"\b(?:" + expression + r")\b", re.I)) for level, group, expression in CUES]
SENTENCES = re.compile(r"[.!?;\n]+")
CLAUSES = re.compile(r"\b(?:but|however|pero|apan|and|ug|at|except|while|whereas|samantalang)\b", re.I)
NEGATION = re.compile(r"\b(?:no|not|never|without|denies|cannot|walay|wala|walang|hindi|di|dili|ayaw|huwag)\b", re.I)
UNCERTAIN = re.compile(r"\b(?:not sure|unsure|unknown|unclear|not confirmed|do not know|does not know|cannot tell|cannot verify|cannot confirm|can't verify|hindi sigurado|di sigurado|dili sigurado|wala ko kabalo|di ko alam|hindi ko alam|wala pang kumpirmasyon|basin lahi)\b", re.I)
NONCURRENT = re.compile(r"\b(?:yesterday|last week|last month|last year|kahapon|kagahapon|kaniadto|noong isang taon)\b", re.I)
ONGOING = re.compile(r"\b(?:now|still|currently|ongoing|karon|ngayon|gihapon|pa rin|hangtod karon)\b", re.I)
HYPOTHETICAL = re.compile(r"\b(?:what if|if|kung|kapag|halimbawa|pananglitan|example|sample|test report|simulation|for testing|training example|joke|hypothetical)\b", re.I)
NONPATIENT = re.compile(r"^\s*(?:(?:ang|na|yung|the|a|an|sa|nga|both|all|two|parehong|dalawang|mga|duha|ka|among|our)\s+){0,5}(?:battery|baterya|makina|engines?|signal|phone|cellphone|suga|lights?|end|tired|gutom|aso|iro|dog|cat)\b", re.I)
NONPATIENT_PREFIX = re.compile(r"\b(?:battery|batteries|baterya|makina|engines?|signal|phone|cellphone|suga|lights?|aso|iro|dog|cat)(?:\s+(?:is|are|was|were|ay|kay|nga|na))*\s*$", re.I)
EDUCATIONAL = re.compile(r"\b(?:injury prevention|meaning of|word means|translation of|definition of|safety lesson)\b", re.I)
FINANCIAL = re.compile(r"\b(?:profit|profits|money|share|sharing|split|bet|coin|coins|pusta|kwarta|hatian|kita sa negosyo|chance of rain)\b", re.I)
PERSON_CONTEXT = re.compile(r"\b(?:victim|patient|driver|passenger|rider|biktima|pasyente|drayber|pasahero|nasamdan|nasugatan|injured|kondisyon|condition|kalagayan|kahimtang|tao|tawo|bata|lalaki|babae|siya|sya|he|she|they|survival|life|kinabuhi|buhay)\b", re.I)
HAZARD = re.compile(r"\b(?:fire|burning|sunog|nag dilaab|nagdilaab|baha|flood|chemical|poison|trapped|naipit|natanggong)\b", re.I)
MINOR_DAMAGE = re.compile(r"\b(?:small scratch|minor scratch|small dent|minor dent|minor damage|cosmetic damage|gasgas lang|garas ra|gamayng garas|maliit na gasgas)\b", re.I)
NO_INJURY = re.compile(r"\b(?:no injuries|no one (?:is |was )?injured|nobody (?:is |was )?(?:injured|hurt)|everyone (?:is |was )?(?:safe and )?uninjured|walay (?:nasamdan|nasamad)|walang (?:nasaktan|nasugatan))\b", re.I)
NONSTOP = re.compile(r"\b(?:dugo|pagdugo|bleeding)\b.{0,90}\b(?:dili|di|hindi|does not|cannot)\s+(?:kini\s+|ito\s+|it\s+)?(?:mohunong|tumitigil|tumigil|stop)\b", re.I)
STOP_CONTINUATION = re.compile(r"^\s*(?:it\s+)?(?:dili|di|hindi|does not|cannot)\s+(?:kini\s+|ito\s+)?(?:mohunong|tumitigil|tumigil|stop)\b", re.I)
RECOVERY = re.compile(r"\b(?:now (?:awake|conscious)|(?:awake|conscious) now|karon mata|mata (?:na )?karon|ngayon gising|gising (?:na )?ngayon)\b", re.I)
SUBJECT = re.compile(r"\b(?:(?P<which>one|first|second|another|other|isang|isa|usa|lain|laing|ikalawang|pangalawang)\s+)?(?P<role>driver|drayber|passenger|pasahero|rider|victim|biktima|patient|pasyente|person|tao|tawo|child|bata|man|lalaki|woman|babae)\b", re.I)
SUBJECT_ALIASES = {"drayber": "driver", "pasahero": "passenger", "biktima": "victim",
                   "pasyente": "patient", "tao": "person", "tawo": "person", "bata": "child",
                   "lalaki": "man", "babae": "woman"}
WHICH_ALIASES = {"one": "first", "isang": "first", "isa": "first", "usa": "first",
                 "another": "other", "lain": "other", "laing": "other",
                 "ikalawang": "second", "pangalawang": "second"}


def _subject(clause: str, position: int, previous: str) -> str:
    """Bounded role matching, not general pronoun/coreference resolution."""
    matches = list(SUBJECT.finditer(clause))
    if not matches:
        return previous
    subject = min(matches, key=lambda match: min(abs(position - match.start()), abs(position - match.end())))
    role = SUBJECT_ALIASES.get(subject['role'], subject['role'])
    which = subject['which'] or ""
    return WHICH_ALIASES.get(which, which) + ":" + role


def _clauses(text: str):
    """Keep time/conditional scope local; a 'now' elsewhere cannot revive a past cue."""
    for sentence in SENTENCES.split(text):
        parts = []
        for clause in CLAUSES.split(sentence):
            # 'Bleeding ... and does not stop' is one assertion, not a denial.
            if parts and STOP_CONTINUATION.search(clause):
                parts[-1] += " " + clause
            else:
                parts.append(clause)
        historical = hypothetical = False
        for clause in parts:
            historical = historical or bool(NONCURRENT.search(clause))
            # 'Unsure if/kung X' qualifies X, not a different person's later
            # assertion. A genuine conditional/example keeps sentence scope.
            hypothetical = hypothetical or bool(HYPOTHETICAL.search(clause) and not UNCERTAIN.search(clause))
            yield clause, historical, hypothetical


def _comma_fragment(clause: str, position: int) -> str:
    """An unrelated 'now' across a comma does not date the cue as current."""
    start = clause.rfind(",", 0, position) + 1
    end = clause.find(",", position)
    return clause[start:end if end >= 0 else len(clause)]


def analyze_cues(description: str) -> dict | None:
    text = unicodedata.normalize("NFKC", description).casefold()
    text = text.replace("\u00e2\u20ac\u2122", "'").replace("\u2019", "'").replace("\u2018", "'")
    for contraction, expanded in ((r"isn'?t", "is not"), (r"aren'?t", "are not"),
                                  (r"wasn'?t", "was not"), (r"weren'?t", "were not"),
                                  (r"doesn'?t", "does not"), (r"don'?t", "do not"),
                                  (r"didn'?t", "did not"), (r"can'?t", "cannot")):
        text = re.sub(r"\b" + contraction + r"\b", expanded, text)
    positive, blocked, seen, recoveries = [], [], [], []
    previous_subject = "unspecified"
    for clause, historical, hypothetical in _clauses(text):
        subject = _subject(clause, 0, previous_subject)
        previous_subject = subject
        if not (hypothetical or UNCERTAIN.search(clause)):
            for recovery in RECOVERY.finditer(clause):
                prefix = " ".join(clause[:recovery.start()].split()[-6:])
                current = ONGOING.search(_comma_fragment(clause, recovery.start()))
                if not NEGATION.search(prefix) and (not historical or current):
                    recoveries.append(_subject(clause, recovery.start(), subject))
        for level, group, pattern in (*PATTERNS, ("critical", "heavy_bleeding", NONSTOP)):
            for match in pattern.finditer(clause):
                phrase = match.group(0)[:80]
                seen.append(phrase)
                prefix = " ".join(clause[:match.start()].split()[-6:])
                prefix = re.sub(r"\bnot only\b", "", prefix)
                if group == "heavy_bleeding":
                    prefix = re.sub(r"\b(?:dili|di|hindi|does not|cannot)\s+(?:kini\s+|ito\s+)?(?:mohunong|tumitigil|tumigil|stop)\b", "", prefix)
                negated = bool(NEGATION.search(prefix))
                wrong_subject = bool(NONPATIENT.search(clause[match.end():]) or NONPATIENT_PREFIX.search(prefix))
                financial = group == "reported_instability" and FINANCIAL.search(clause)
                fifty = re.fullmatch(r"(?:fifty[\s-]*fifty|50[\s/\-]*50)", phrase)
                no_patient_context = fifty and clause.strip() != phrase and not PERSON_CONTEXT.search(clause)
                uncertain = bool(UNCERTAIN.search(clause))
                cue_subject = _subject(clause, match.start(), subject)
                past = historical and not ONGOING.search(_comma_fragment(clause, match.start()))
                context_block = past or hypothetical or uncertain or bool(EDUCATIONAL.search(clause))
                if negated or wrong_subject or financial or no_patient_context or context_block:
                    blocked.append((group, phrase, negated, cue_subject, bool(context_block)))
                else:
                    positive.append((level, group, phrase, cue_subject))

    minor = MINOR_DAMAGE.search(text)
    no_injury = NO_INJURY.search(text)
    hazard = HAZARD.search(text)
    if minor and no_injury and not UNCERTAIN.search(text) and not hazard:
        positive.append(("minor", "limited_damage", minor.group(0), "unspecified"))
        seen.extend((minor.group(0), no_injury.group(0)))
    if hazard:
        seen.append(hazard.group(0))
    if not seen:
        return None
    # Do not let one person's uncertainty/recovery cancel another person's clear
    # current danger cue. Contradictions are checked for the same bounded subject.
    conflicted = [item for item in positive
                  if any(item[1] == group and item[3] == subject
                         for group, _, negated, subject, _ in blocked if negated)
                  or (item[1] == "unresponsive" and item[3] in recoveries)]
    positive = [item for item in positive if item not in conflicted]
    review_context = any(context for _, _, _, _, context in blocked)
    conflict = bool(conflicted)
    asserted_critical = any(level == "critical" for level, _, _, _ in positive)
    if not positive or ((review_context or conflict) and not asserted_critical):
        label = "needs_review"
        evidence = list(dict.fromkeys(seen))[:8]
        reason = "The description contains negated, uncertain, historical or ambiguous severity language; admin review is required."
    else:
        rank = {"minor": 0, "serious": 1, "critical": 2}
        label = max(positive, key=lambda item: rank[item[0]])[0]
        evidence = list(dict.fromkeys(phrase for level, _, phrase, _ in positive if level == label))[:8]
        reason = f"Text rules suggest {label.title()} from reported phrase(s): " + ", ".join(f'"{cue}"' for cue in evidence) + ". Admin confirmation is required."
        if any(group == "reported_death" for level, group, _, _ in positive if level == label):
            reason += " A reported death is not a confirmed death."
    return {
        "severity": None if label == "needs_review" else label,
        "predicted_label": label,
        "confidence": None,
        "confidence_calibrated": False,
        "probabilities": None,
        "responder_review_required": True,
        "model_name": RULE_NAME,
        "model_version": RULE_VERSION,
        "status": "needs_review" if label == "needs_review" else "complete",
        "summary": "Experimental word/phrase rules; no rule confidence score. " + reason,
        "deployment_status": "experimental",
        "assessment_method": "text_rules",
        "matched_cues": evidence,
        # No positive rule may simply mean only a negated generic injury or an
        # unrecognized hazard was mentioned. Preserve the model in that case.
        # Actual context/conflict blocks and denied Critical cues require review.
        "rule_priority": "override" if label == "critical" or (label == "needs_review" and (
            review_context or conflict or any(group != "injury" for group, _, _, _, _ in blocked))) else "fallback",
    }
