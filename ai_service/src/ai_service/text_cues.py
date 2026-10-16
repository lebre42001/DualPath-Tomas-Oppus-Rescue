"""Explicit experimental word/phrase suggestions, separate from learned scores.

These are report-language rules for admin review, not a clinical triage protocol.
Negation/context coverage is deliberately bounded; uncertainty abstains.
"""
from __future__ import annotations

import re
import unicodedata

RULE_VERSION = "text-cues-20261016-v1"
RULE_NAME = "DualPath Trilingual Severity Rules"

# Group names support conflict checks across translations and synonyms.
CUES = (
    ("critical", "reported_death", r"dead|died|deceased|killed|patay|namatay|napatay|nasawi|binawian ng buhay"),
    ("critical", "reported_instability", r"fifty[\s-]*fifty|50[\s/\-]*50|critical condition|critically injured|life[\s-]threatening|nag[\s-]*aagaw[\s-]+buhay|naghihingalo|naghingalo"),
    ("critical", "unresponsive", r"unconscious|unresponsive|walang malay|nawalan ng malay|walay panimuot|nawad an og panimuot|nawad-an og panimuot|dili momata|dili na momata"),
    ("critical", "absent_breathing", r"not breathing|isn't breathing|isnt breathing|cannot breathe|can't breathe|hindi (?:na )?humihinga|di (?:na )?humihinga|walang hininga|dili (?:na )?mo\s*ginhawa|wala nay ginhawa|walay ginhawa|wala na nagginhawa"),
    ("critical", "heavy_bleeding", r"heavy bleeding|severe bleeding|bleeding heavily|uncontrolled bleeding|profuse bleeding|malakas ang pagdurugo|malakas na pagdurugo|grabe ang pagdugo|grabe nga pagdugo|kusog ang pagdugo|daghang dugo nga dili mohunong"),
    ("critical", "breathing_difficulty", r"difficulty breathing|struggling to breathe|labored breathing|hirap huminga|nahihirapang huminga|halos dili (?:na )?(?:siya )?maka?ginhawa|naglisod (?:og |sa )?pagginhawa|lisod makita ang ginhawa"),
    ("critical", "heavy_bleeding", r"(?:heavy|severe|profuse|uncontrolled) (?:\w+\s+){0,3}bleeding|bleeding (?:\w+\s+){0,4}uncontrolled|kusog nga pagdugo|(?:dugo|pagdugo|nagdugo).{0,50}(?:dili|di).{0,12}mohunong"),
    ("critical", "active_threat", r"drowning|nalulunod|nalumos|trapped in (?:a |the )?burning (?:car|building|house)|naipit sa nasusunog na sasakyan"),
    ("serious", "injury", r"injured|injuries|injury|hurt|wounded|wound|wounds|bleeding|fracture|fractured|broken (?:arms?|legs?|bones?)|nasamdan|nasamad|nasamaran|samaran|samad|nagdugo|nabal[iy]|nasugatan|nasaktan|sugat|dumudugo|bali ang (?:kamay|paa|binti|braso)"),
)
PATTERNS = [(level, group, re.compile(r"\b(?:" + expression + r")\b", re.I)) for level, group, expression in CUES]
CLAUSES = re.compile(r"[.!?;,\n]+|\b(?:but|however|pero|apan|and|ug|at|except)\b", re.I)
NEGATION = re.compile(r"\b(?:no|not|never|without|denies|walay|wala|walang|hindi|di|dili|ayaw|huwag)\b", re.I)
UNCERTAIN = re.compile(r"\b(?:not sure|unsure|unknown|unclear|not confirmed|cannot verify|cannot confirm|can't verify|hindi sigurado|di sigurado|dili sigurado|wala ko kabalo|di ko alam|hindi ko alam|wala pang kumpirmasyon|basin lahi)\b", re.I)
NONCURRENT = re.compile(r"\b(?:yesterday|last week|last month|last year|kahapon|kagahapon|kaniadto|noong isang taon)\b", re.I)
ONGOING = re.compile(r"\b(?:now|still|currently|ongoing|karon|ngayon|gihapon|pa rin|hangtod karon)\b", re.I)
HYPOTHETICAL = re.compile(r"^\s*(?:what if|if|kung|kapag|halimbawa|pananglitan|example|sample|test report|simulation|for testing|training example|joke|hypothetical)\b", re.I)
NONPATIENT = re.compile(r"^\s*(?:(?:ang|na|yung|the|a|sa|nga)\s+){0,2}(?:battery|baterya|makina|engine|signal|phone|cellphone|suga|lights?|end|tired|gutom|aso|iro|dog|cat)\b", re.I)
NONPATIENT_PREFIX = re.compile(r"\b(?:battery|baterya|makina|engine|signal|phone|cellphone|suga|lights?|aso|iro|dog|cat)(?:\s+(?:is|was|ay|kay|nga|na))*\s*$", re.I)
EDUCATIONAL = re.compile(r"\b(?:injury prevention|meaning of|word means|translation of|definition of|safety lesson)\b", re.I)
FINANCIAL = re.compile(r"\b(?:profit|profits|money|share|sharing|split|bet|coin|coins|pusta|kwarta|hatian|kita sa negosyo|chance of rain)\b", re.I)
PERSON_CONTEXT = re.compile(r"\b(?:victim|patient|driver|passenger|rider|biktima|pasyente|drayber|pasahero|nasamdan|nasugatan|injured|kondisyon|condition|kalagayan|kahimtang|tao|tawo|bata|lalaki|babae|siya|sya|he|she|they|survival|life|kinabuhi|buhay)\b", re.I)
HAZARD = re.compile(r"\b(?:fire|burning|sunog|nag dilaab|nagdilaab|baha|flood|chemical|poison|trapped|naipit|natanggong)\b", re.I)
MINOR_DAMAGE = re.compile(r"\b(?:small scratch|minor scratch|small dent|minor dent|minor damage|cosmetic damage|gasgas lang|garas ra|gamayng garas|maliit na gasgas)\b", re.I)
NO_INJURY = re.compile(r"\b(?:no injuries|no one (?:is |was )?injured|nobody (?:is |was )?(?:injured|hurt)|everyone (?:is |was )?(?:safe and )?uninjured|walay (?:nasamdan|nasamad)|walang (?:nasaktan|nasugatan))\b", re.I)


def analyze_cues(description: str) -> dict | None:
    text = unicodedata.normalize("NFKC", description).casefold().replace("’", "'")
    positive, blocked, seen = [], [], []
    for clause in CLAUSES.split(text):
        for level, group, pattern in PATTERNS:
            for match in pattern.finditer(clause):
                phrase = match.group(0)[:80]
                seen.append(phrase)
                prefix = " ".join(clause[:match.start()].split()[-6:])
                prefix = re.sub(r"\bnot only\b", "", prefix)
                if group == "heavy_bleeding":
                    prefix = re.sub(r"\b(?:dili|di|hindi|does not|cannot|can't)\s+(?:kini\s+|ito\s+)?(?:mohunong|tumitigil|tumigil|stop)\b", "", prefix)
                negated = bool(NEGATION.search(prefix))
                wrong_subject = group == "reported_death" and (NONPATIENT.search(clause[match.end():]) or NONPATIENT_PREFIX.search(prefix))
                financial = group == "reported_instability" and FINANCIAL.search(clause)
                fifty = re.fullmatch(r"(?:fifty[\s-]*fifty|50[\s/\-]*50)", phrase)
                no_patient_context = fifty and clause.strip() != phrase and not PERSON_CONTEXT.search(clause)
                uncertain = UNCERTAIN.search(clause)
                if negated or wrong_subject or financial or no_patient_context or uncertain:
                    blocked.append((group, phrase, bool(negated)))
                else:
                    positive.append((level, group, phrase))

    # A negated stop describes ongoing bleeding, not the absence of bleeding.
    nonstop = re.search(r"\b(?:dugo|pagdugo|bleeding)\b.{0,90}\b(?:dili|hindi|does not|cannot)\s+(?:kini\s+|ito\s+)?(?:mohunong|tumitigil|tumigil|stop)\b", text)
    if nonstop and not NEGATION.search(" ".join(text[:nonstop.start()].split()[-3:])):
        positive.append(("critical", "heavy_bleeding", nonstop.group(0)[:80]))
        seen.append(nonstop.group(0)[:80])

    minor = MINOR_DAMAGE.search(text)
    no_injury = NO_INJURY.search(text)
    hazard = HAZARD.search(text)
    if minor and no_injury and not UNCERTAIN.search(text) and not hazard:
        positive.append(("minor", "limited_damage", minor.group(0)))
        seen.extend((minor.group(0), no_injury.group(0)))
    if hazard:
        seen.append(hazard.group(0))
    if not seen:
        return None
    review_context = HYPOTHETICAL.search(text) or EDUCATIONAL.search(text) or UNCERTAIN.search(text) or (NONCURRENT.search(text) and not ONGOING.search(text))
    conflict = any(group == negated_group for _, group, _ in positive
                   for negated_group, _, negated in blocked if negated)
    # A later recovery does not establish current severity from a historical symptom.
    recovery = re.search(r"\b(?:now awake|now conscious|karon mata|ngayon gising)\b", text)
    if recovery and any(group == "unresponsive" for _, group, _ in positive):
        review_context = True
    if review_context or conflict or not positive:
        label = "needs_review"
        evidence = list(dict.fromkeys(seen))[:8]
        reason = "The description contains negated, uncertain, historical or ambiguous severity language; admin review is required."
    else:
        rank = {"minor": 0, "serious": 1, "critical": 2}
        label = max(positive, key=lambda item: rank[item[0]])[0]
        evidence = list(dict.fromkeys(phrase for level, _, phrase in positive if level == label))[:8]
        reason = f"Text rules suggest {label.title()} from reported phrase(s): " + ", ".join(f'"{cue}"' for cue in evidence) + ". Admin confirmation is required."
        if any(group == "reported_death" for level, group, _ in positive if level == label):
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
        "rule_priority": "override" if label == "critical" or review_context or conflict
            or any(group != "injury" for group, _, _ in blocked) else "fallback",
    }
