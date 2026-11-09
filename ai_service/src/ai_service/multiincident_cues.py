"""Bounded experimental incident-report cues, not a clinical triage protocol.

The legacy profile is imported but never modified. This opt-in profile adds
explicit combinations of reported danger signs; it does not diagnose disease,
manufacture confidence, or decide that a person is medically safe.
"""
from __future__ import annotations

from dataclasses import dataclass
import re
import unicodedata

from .incident_types import normalize_incident_type
from . import text_cues as legacy


RULE_VERSION = "multiincident-cues-20261109-v4"
RULE_NAME = "Experimental Multilingual Multi-Incident Cues"


def _rx(expression: str) -> re.Pattern:
    return re.compile(r"\b(?:" + expression + r")\b", re.I)


# These are warning-sign phrases, not diagnostic labels. Each combination below
# also requires bounded person context and an unblocked present assertion.
SIGN_PATTERNS = {
    "no_response": _rx(r"(?:will not|does not|cannot) wake (?:up)?|(?:does not|will not) respond to (?:voice|touch|shaking)|no response to (?:voice|touch|shaking)|(?:di|dili) (?:gyud |jud |na )?(?:mapukaw|makamata|momata)|wa'?y panimuot|wala siyay panimuot"),
    "abnormal_breathing": _rx(r"(?:not|abnormal|irregular) breathing(?: normally)?|breathing (?:is )?(?:abnormal|irregular)|(?:dili|di) normal (?:ang )?(?:ginhawa|pagginhawa|breathing)|hindi normal (?:ang )?(?:paghinga|huminga)"),
    "no_chest_movement": _rx(r"chest (?:is |does )?not mov(?:e|ing)|no (?:visible )?chest movement|(?:wala|walay) (?:nag)?lihok (?:ang |sa )?dughan|walang galaw (?:ang |sa )?dibdib|hindi gumagalaw (?:ang )?dibdib"),
    "no_verbal_response": _rx(r"(?:does not|will not|cannot) (?:answer|respond)(?: when (?:spoken to|called))?|(?:dili|di) (?:gyud |jud |na )?(?:siya |sya )?mo(?:tubag|respond)|wala (?:siya )?motubag|walay tubag|(?:hindi|di) (?:na )?(?:siya )?(?:sumasagot|tumutugon)|(?:hindi|di) (?:siya )?nagrerespond"),
    "no_body_movement": _rx(r"(?:does not|will not|cannot) move|not moving|motionless|(?:hindi|di) (?:siya )?gumagalaw|walang galaw|(?:dili|di) (?:gyud |jud |na )?(?:siya )?molihok|wala (?:siya )?naglihok|walay lihok"),
    "massive_blood_loss": _rx(r"blood.{0,20}(?:pour(?:s|ing)?|gush(?:es|ing)?|spurt(?:s|ing)?|flow(?:s|ing)? heavily)|(?:pour(?:s|ing)?|gush(?:es|ing)?|spurt(?:s|ing)?).{0,15}blood|malakas (?:ang |na )?agos (?:ng )?dugo|bumubuhos (?:ang )?dugo|dugo.{0,15}bumubuhos|kusog (?:ang |nga )?agas (?:sa )?dugo"),
    "chest_pressure": _rx(r"(?:crushing |sudden |acute )?chest (?:pain|pressure|tightness)|(?:pain|pressure|tightness) (?:in|on) (?:the |their |his |her )?chest|(?:sakit|naninikip|nanininikip|paninikip|mabigat|mabigat na pakiramdam).{0,25}(?:dibdib)|(?:sakit|huot|gihuot|sakit ug huot).{0,25}(?:dughan)"),
    "cold_sweat": _rx(r"cold sweat|cold and sweaty|cold (?:and )?clammy|malamig (?:ang |at malagkit ang )?pawis|malamig.{0,18}pawis|bugnaw (?:ang |ug pilit ang )?singot|bugnaw.{0,18}singot"),
    "cold_skin": _rx(r"cold|malamig|bugnaw"),
    "clammy_skin": _rx(r"clammy|sweaty|malagkit ang pawis|pilit ang singot"),
    "face_droop": _rx(r"face.{0,25}(?:droop|drooped|drooping)|facial droop|(?:droop|drooped|drooping).{0,25}face|(?:lumaylay|tabingi|nakalaylay).{0,30}mukha|(?:mukha).{0,25}(?:lumaylay|tabingi)|(?:nilaylay|milaylay|natakilid).{0,30}nawong|nawong.{0,25}(?:nilaylay|milaylay|natakilid)"),
    "one_arm_weak": _rx(r"(?:one|left|right) arm.{0,20}(?:weak|cannot|can't|unable)|(?:weak|weakness).{0,20}(?:one|left|right) arm|(?:mahina|nanghina).{0,25}(?:isang|kaliwang|kanang) braso|(?:isang|kaliwang|kanang) braso.{0,20}(?:mahina|nanghina)|(?:luya|naluya).{0,25}(?:usa ka|wala nga|tuo nga) bukton|(?:usa ka|wala nga|tuo nga) bukton.{0,20}(?:luya|naluya)|weak ang (?:usa ka|one) arm"),
    "speech_change": _rx(r"slurred (?:speech|words)|(?:speech|words).{0,15}slurred|cannot speak clearly|can't speak clearly|bulol (?:ang )?(?:pagsasalita|salita)|hirap magsalita|(?:dili|di) klaro ang (?:sinultihan|pagstorya|pag istorya)|nagkabulol|slurred ang speech"),
    "new_onset": _rx(r"sudden|suddenly|new onset|just started|bigla|biglang|kalit|bag ong nagsugod|bag-ong nagsugod"),
    "convulsions": _rx(r"convulsing|convulsions?|seizures?|kombulsyon|kinukumbulsyon|nangingisay|nagkumbulsyon|nagkombulsyon"),
    "repeated_ongoing": _rx(r"keeps convulsing|repeated|repeating|continuous|continues|still convulsing|multiple seizures|sunod sunod|sunod-sunod|tuloy tuloy|tuloy-tuloy|pabalik balik|pabalik-balik|nagpadayon|gihapon"),
    # Negation is part of this affirmative warning sign. Local assertion guards
    # handle this family separately instead of treating "not regained" as denial.
    "no_recovery": _rx(r"(?:has |have |had )?not (?:regained|recovered).{0,20}(?:awareness|consciousness)|without (?:regaining |recovering )?(?:awareness|consciousness|recovery)|(?:does not|cannot) (?:wake|respond) between|no (?:recovery|awareness) between|hindi (?:na)?gkakamalay|hindi nagkamalay|hindi (?:na )?nagigising sa pagitan|walang malay sa pagitan|wala (?:pa )?(?:makabawi|makabawi og|makauli).{0,15}panimuot|wala(?:y)? (?:pa )?panimuot taliwala|walay panimuot sa tunga|no awareness between"),
    "airway_swelling": _rx(r"(?:tongue|throat).{0,35}(?:swell|swelling|swollen)|(?:swell|swelling|swollen).{0,35}(?:tongue|throat)|namamaga.{0,30}(?:dila|lalamunan)|(?:dila|lalamunan).{0,30}namamaga|nihubag.{0,30}(?:dila|tutunlan)|(?:dila|tutunlan).{0,30}nihubag|(?:tongue|throat).{0,30}swelling"),
    "breathing_compromise": _rx(r"struggling to breathe|difficulty breathing|breathing.{0,20}(?:difficult|hard)|gasping (?:for air)?|cannot breathe|can't breathe|cannot (?:speak|finish) (?:a )?full sentence|hirap (?:na hirap )?huminga|nahihirapang huminga|halos hindi makapagsalita|(?:naglisod|nalisod|lisod).{0,25}(?:ginhawa|pagginhawa|breathe|breathing)|(?:dili|di).{0,15}(?:kaginhawa|makaginhawa|ka-breathe)|halos (?:dili|di) (?:na )?(?:makasulti|kasulti)|dili makasulti og full sentence"),
    "blue_lips": _rx(r"lips.{0,20}(?:turning )?blue|blue.{0,15}lips|(?:nangingitim|namumula asul|nangingasul).{0,15}labi|labi.{0,15}(?:nangingitim|nangingasul)|(?:nangasul|nangitom).{0,15}ngabil|ngabil.{0,15}(?:nangasul|nangitom)"),
    "chest_compression": _rx(r"(?:pinning|pinned|compressing|crushing).{0,30}(?:person's |patient's |their |his |her )?chest|chest.{0,25}(?:pinned|compressed|crushed)|naiipit.{0,30}dibdib|dibdib.{0,30}(?:naiipit|nakadagan)|naipit.{0,30}dughan|dughan.{0,30}(?:naipit|nadaganan)|pins.{0,30}chest"),
    "smoke_airway": _rx(r"soot.{0,25}(?:mouth|nose)|(?:mouth|nose).{0,25}soot|uling.{0,30}(?:bibig|ilong|baba)|(?:facial|face) burns|burns (?:on|to) (?:the |their |his |her )?face|paso.{0,18}(?:mukha|nawong)"),
    "hoarse_voice": _rx(r"hoarse|paos(?: ang)? (?:boses|tingog)|hoarse ang voice"),
    "pallor": _rx(r"pale|pallor|maputla|namumutla|luspad|nangluspad"),
    "decreasing_awareness": _rx(r"becoming difficult to wake|getting (?:hard|difficult) to wake|barely (?:answer|respond)|less responsive|hard (?:to |nang )?gisingin|mahirap nang gisingin|halos hindi na makasagot|lisod (?:na |nang )?(?:pukawon|pukaw)|halos dili na makatubag|lisod na pukawon"),
    "active_fire": _rx(r"(?:fire|flames?).{0,25}(?:still burning|still spreading|spreading|uncontained|out of control)|(?:ongoing|active|uncontained|uncontrolled|spreading) fire|(?:house|building|vehicle|shop).{0,20}(?:still burning|is burning|is on fire)|burning (?:house|building|vehicle|shop)|nasusunog (?:pa )?ang (?:bahay|gusali|tindahan|sasakyan)|(?:sunog|apoy).{0,25}(?:lumalaganap|kumakalat|nagpapatuloy)|(?:lumalaganap|kumakalat|tuloy-tuloy).{0,20}(?:sunog|apoy)|(?:kalayo|sunog).{0,25}(?:nagpadayon|mikaylap|nagkaylap|nagkadako)|(?:nagpadayon|nagkaylap|nagdilaab).{0,20}(?:kalayo|sunog|balay)"),
    "active_flood": _rx(r"(?:floodwater|flood water|water).{0,20}(?:still rising|rising|blocking (?:the )?exit)|rising water|(?:baha|tubig).{0,25}(?:pataas|tumataas|umaakyat|nisaka|misaka|nagsaka|nagkataas)|(?:tumataas|umaakyat|nagkataas|nagsaka).{0,20}(?:baha|tubig)|nag-anam.{0,15}kataas.{0,15}tubig"),
    "active_assault": _rx(r"(?:attacker|assailant).{0,25}(?:still (?:hitting|striking|attacking)|attacking|striking)|(?:person|man|woman) is (?:still )?(?:punching|kicking|beating|striking) (?:a |the )?(?:person|man|woman|child|victim)|(?:ongoing|active) (?:armed )?assault|(?:being|is still) attacked (?:right )?now|(?:armado|may armas).{0,30}(?:nananakit|nambubugbog)|patuloy.{0,20}(?:nananakit|nambubugbog)|(?:gisumbag|gibunalan|gihampak).{0,15}gihapon"),
    "personal_entrapment": _rx(r"trapped|cannot get (?:out|free)|can't get (?:out|free)|cannot escape|(?:naipit|nakakulong|hindi makalabas|hindi makatakas)|(?:natanggong|naipit|dili makagawas|dili makaikyas)"),
    "direct_personal_threat": _rx(r"(?:held|holding|being held) at gunpoint|gun (?:is )?(?:pressed|pointed).{0,25}(?:head|person|patient|victim|worker)|knife.{0,25}(?:against|at|to).{0,20}(?:throat|neck)|(?:attacker|assailant).{0,20}strangling|tinututukan.{0,20}baril|kutsilyo.{0,20}(?:leeg|lalamunan)|(?:gitionan|gitutokan).{0,20}pusil|kutsilyo.{0,20}(?:liog|tutunlan)"),
    "water_struggle": _rx(r"struggling.{0,35}(?:river|water)|(?:nagpupumiglas|nanlimbasog).{0,35}(?:ilog|tubig|suba)|(?:ilog|tubig|suba).{0,35}(?:nagpupumiglas|nanlimbasog)"),
    "repeated_submersion": _rx(r"repeatedly (?:goes?|going) under|keeps going under|paulit-ulit (?:na )?lumulubog|paulit ulit (?:na )?lumulubog|sige (?:og |ug )?lunod|balik-balik (?:nga )?nalubog"),
    "head_not_above_water": _rx(r"cannot keep.{0,25}head above.{0,15}water|cannot keep.{0,15}above.{0,15}water|hindi (?:niya )?maitaas.{0,20}ulo.{0,15}tubig|(?:dili|di) (?:niya )?mapabiling? (?:ibabaw|ibabaw sa tubig).{0,25}(?:tubig|ulo)|(?:dili|di).{0,20}mapataas.{0,15}ulo.{0,15}tubig"),
    "open_pedestrian_hazard": _rx(r"(?:missing|removed|open) (?:drain|manhole) cover|(?:drain|manhole) cover (?:is |has been )?(?:missing|removed)|uncovered (?:drain|manhole)|bukas na (?:kanal|manhole)|walay tabon (?:ang )?(?:kanal|manhole)"),
    "pedestrian_exposure": _rx(r"busy (?:footpath|walkway|sidewalk)|pedestrians? (?:are )?(?:being )?(?:diverted|diverting|approaching|walking)|diverting pedestrians|opening needs (?:securing|a barrier)|needs (?:to be )?(?:secured|cordoned|a barrier)|daghang (?:naglakaw|molabay)|dapat harangan|kinahanglan.{0,15}(?:harangan|kural|barrier)"),
    "active_forced_entry": _rx(r"(?:stranger|intruder|burglar|person).{0,20}(?:forcing|breaking|cutting).{0,25}(?:padlock|lock|door)|(?:padlock|lock|door).{0,25}(?:being forced|being broken)|pinipilit.{0,20}(?:kandado|pinto)|gipugos.{0,20}(?:kandado|pultahan)"),
    "intervention_needed": _rx(r"intervention (?:is )?needed|needs? intervention|police (?:are )?needed|request(?:ing)? (?:police|intervention)|owner (?:is )?(?:away|elsewhere)|empty shop|empty store|walang tao (?:sa )?tindahan|kinahanglan.{0,15}(?:pulis|interbensyon)|kailangan.{0,15}(?:pulis|makialam)"),
}

HAZARD_FAMILIES = {"active_fire", "active_flood", "active_assault"}
SCENE_COMPONENTS = HAZARD_FAMILIES | {"open_pedestrian_hazard", "pedestrian_exposure", "active_forced_entry", "intervention_needed"}
INTERNAL_DENIAL_FAMILIES = SCENE_COMPONENTS | {"direct_personal_threat", "chest_pressure", "face_droop", "airway_swelling", "blue_lips", "chest_compression", "smoke_airway", "hoarse_voice", "cold_sweat"}
EXTRA_NEGATION = _rx(r"nobody|no one|no longer|not any|neither")

HISTORY = _rx(r"earlier|previously|in the past|last night|yesterday|last week|last month|last year|childhood|as a child|(?:a |one |two |several |many )?(?:decades?|years?) ago|kanina|noon|kahapon|noong bata|noong kabataan|ganiha|kagahapon|kaniadto|sa pagkabata|sa bata pa")
UNCERTAINTY = _rx(r"unsure|uncertain|unknown|unclear|not sure|do not know|does not know|cannot (?:tell|verify|confirm|see|determine|assess)|can't (?:tell|verify|confirm|see|determine|assess)|may have|might have|may be|might be|maybe|possibly|perhaps|hindi (?:ko )?(?:alam|sigurado|matiyak|makita)|di (?:ko )?(?:alam|sigurado|makita)|wala ko kabalo|dili (?:ko )?sigurado|dili (?:nako )?(?:makumpirma|makita)|basin|baka|unverified|rumou?r")
PERSON = _rx(r"person|people|patient|victim|casualty|driver|rider|passenger|worker|resident|child|man|woman|someone|he|she|they|their|his|her|my|tao|tawo|pasyente|biktima|drayber|pasahero|bata|lalaki|babae|siya|sya|ilang|iyang|niya|ako|akong")
HUMAN_CONDITION = _rx(r"(?:leg|arm|hand|head|thigh|calf|forearm|neck|foot|abdomen|chest) wound|wound.{0,20}(?:leg|arm|hand|head|thigh|calf|forearm|neck|foot|abdomen|chest)|(?:blood|bleeding).{0,45}(?:thigh|calf|forearm|neck|leg|arm|foot)|(?:sugat|samad|nasamad|nahiwa).{0,30}(?:paa|tiil|kamot|bukton|binti|kamay|braso|ulo)|(?:head|ulo).{0,35}(?:water|tubig)|(?:water|tubig).{0,30}(?:head|ulo)|chest pain|chest pressure|dughan|dibdib|tongue|throat|dila|tutunlan|lalamunan|face|nawong|mukha|lips|ngabil|labi")
NONHUMAN = _rx(r"battery|baterya|engine|makina|phone|cellphone|camera|computer|screen|display|sensor|motor|pump|signal|radio|radyo|node|router|device|dog|cat|iro|aso|iring|pusa")
AWAKE_NOW = _rx(r"now (?:fully )?(?:awake|conscious)|(?:awake|conscious) (?:again )?now|awake again|gising (?:na )?ngayon|ngayon (?:ay )?gising|mata (?:na )?karon|karon (?:kay )?mata|awake (?:na |ug talking )?now")
AWAKE_PRESENT = _rx(r"(?:is|are|also|currently) (?:fully )?(?:awake|conscious)|ay gising|gising na|mata na siya|siya (?:kay )?mata")
BREATHING_NORMAL_NOW = _rx(r"now breathing normally|breathing normally now|now breathes normally|breathing (?:is )?normal now|normal na (?:ang )?(?:paghinga|huminga|ginhawa|pagginhawa)|normal (?:na )?ang breathing (?:now|karon)|normal breathing (?:now|karon)")
PAIN_RESOLVED = _rx(r"(?:chest pain|chest pressure|pain).{0,15}(?:gone|resolved|stopped)|no (?:more )?chest pain (?:now|anymore)|wala na(?:y)?(?: ang)? sakit sa (?:dughan|dibdib)|wala nang sakit sa dibdib")
QUOTATION = _rx(r"quoted|quoting|translat(?:e|ing|ion)|definition|meaning of|lesson|word means|safety class|mga salita|mga pulong|pangutana ra|tanong lamang|reading (?:a |the )?(?:poster|sign|book|screen)|(?:poster|sign|book|screen).{0,15}(?:says|reads)|for (?:a |the )?(?:poster|lesson|presentation|script)|training exercise|practice (?:drill|scenario)|role ?play|role-play|actors? following|acting (?:as|out)|pretend|simulated patient")
ATTRIBUTED = _rx(r"(?:the )?report says|reports say|according to (?:a |the )?report|ayon sa ulat|giingon sa report")
UNRESOLVED_REPORT = _rx(r"cannot (?:confirm|verify|tell|decide) which (?:statement|version|report|claim) is (?:correct|true)|cannot resolve (?:the )?(?:contradiction|conflicting reports)|reports (?:are )?conflicting")
RESPONSIVENESS_UNCLEAR = _rx(r"(?:present |current )?responsiveness needs clarification|(?:present |current )?responsiveness (?:is |remains )?unclear|unclear which (?:subject|person|patient|animal) (?:is )?(?:actually )?(?:present|affected|being described)|cannot tell whether (?:the )?(?:subject|victim) is (?:a )?(?:person|human|animal)")
NO_HUMAN_HARM = _rx(r"(?:nobody|no one) (?:was |is )?(?:hit|hurt|injured)|no (?:reported )?(?:injuries|casualties)|everyone (?:is |was )?(?:safe and )?uninjured|walay (?:nasamdan|nasamad|naigo)|walang (?:nasaktan|nasugatan|natamaan)")
SAFE_SCENE = _rx(r"safely parked|(?:parked|moved|stopped).{0,25}off the road|(?:area|scene) (?:is |was )?(?:now )?safe|hazard (?:has been |is )?removed|wala nay peligro|ligtas na ang lugar|luwas na ang (?:dapit|lugar)")
RESOLVED_CONTEXT = {
    "cosmetic": _rx(r"paint marks?|paint transfer|only (?:a )?scuff|cosmetic marks?|pintura lang|pintura ra"),
    "clear_path": _rx(r"(?:lane|road|path|footpath|walkway) (?:is |has been )?(?:now )?(?:clear|safe|cleared)|clear (?:lane|road|path)|hawan na (?:ang )?(?:dalan|agianan)|maluwag na ang daan"),
    "cold_ashes": _rx(r"cold ashes|ashes.{0,30}(?:cold|cool)|abo.{0,15}(?:bugnaw|malamig)|(?:bugnaw|malamig).{0,15}abo"),
    "no_embers": _rx(r"no (?:hot |live |remaining )?embers|no (?:heat|smoke|flames)|walay (?:baga|kainit|aso)|walang (?:baga|init|usok)"),
    "drain_cleared": _rx(r"(?:drain|drainage|kanal).{0,25}(?:cleared|unblocked|cleared out|nahawan|nalinis)|(?:cleared|unblocked|nahawan|nalinis).{0,20}(?:drain|kanal)"),
    "no_flood_impact": _rx(r"no (?:house|houses|home|homes) (?:is |are |was |were |has been |have been )?flooded|no (?:standing water|floodwater) remains|walang bahay na binaha|walay balay nga gibaha"),
    "secured_animal": _rx(r"(?:dog|animal|iro|aso).{0,25}(?:secured|restrained|leashed|calmed)|(?:secured|restrained|leashed|calmed).{0,20}(?:dog|animal|iro|aso)"),
    "no_bite": _rx(r"no bites?|(?:nobody|no one) (?:was |is )?bitten|walay (?:gipaak|napaakan)|walang nakagat"),
    "calm": _rx(r"(?:scene|crowd|people|everyone|dog|animal) (?:is |are )?(?:now )?calm|calm now|calmed down|kalmado na|nahilom na"),
    "balloon": _rx(r"party balloon|balloon.{0,15}(?:popped|burst)|(?:popped|burst).{0,15}balloon"),
    "no_weapon": _rx(r"no (?:weapon|gun)|not (?:gunfire|a gunshot)|walang armas|walay armas"),
    "well_now": _rx(r"(?:fully |completely )?well now|now (?:fully |completely )?well|feels? (?:well|normal) now|now feels? (?:well|normal)|maayo na (?:ang )?paminaw|mabuti na ang pakiramdam"),
    "normal_breathing": _rx(r"breathing normally|normal breathing|normal (?:ang )?(?:ginhawa|pagginhawa|paghinga)|normal (?:na )?huminga"),
    "alert": _rx(r"awake|alert|talking normally|gising|mata na|makasulti|maayos magsalita"),
}

# Profile-local entity handling deliberately does not widen the legacy role
# aliases. Device recovery/denial can therefore never cancel a human assertion.
HUMAN_ALIASES = {
    "people": "person", "pasyente": "patient", "biktima": "victim",
    "tawo": "person", "tao": "person", "drayber": "driver",
    "pasahero": "passenger", "bata": "child", "lalaki": "man",
    "babae": "woman", "babaye": "woman", "inahan": "mother",
    "nanay": "mother", "ina": "mother", "amahan": "father",
    "tatay": "father", "ama": "father", "empleyado": "worker",
    "trabahante": "worker", "manggagawa": "worker", "residente": "resident",
}
HUMAN_ROLES = "person|people|patient|victim|casualty|driver|rider|passenger|worker|operator|resident|child|baby|infant|man|woman|mother|father|parent|daughter|son|girl|boy|grandmother|grandfather|" + "|".join(HUMAN_ALIASES)
ENTITY_PATTERN = re.compile(
    r"\b(?:(?P<which>one|first|another|other|second|third|fourth|same|isang|isa|usa|lain|laing|unang|ikalawang|pangalawang|ikatlong|pangatlong|ikatlo|pangatlo|ikaduha|ikaduhang|ikatulo|ikatulong|parehong)\s+(?:(?:ka|nga)\s+)?)?"
    r"(?P<human>" + HUMAN_ROLES + r")\b|(?P<nonhuman>" + NONHUMAN.pattern + r")", re.I,
)
ORDINAL = {"one": "first", "isang": "first", "isa": "first", "usa": "first", "unang": "first", "another": "other", "lain": "other", "laing": "other", "ikalawang": "second", "pangalawang": "second", "ikaduha": "second", "ikaduhang": "second", "ikatlong": "third", "pangatlong": "third", "ikatlo": "third", "pangatlo": "third", "ikatulo": "third", "ikatulong": "third"}
GENERIC_ROLES = {"person", "patient", "victim", "casualty"}
HUMAN_PRONOUN = _rx(r"he|she|they|their|his|her|siya|sya|iyang|niya|ilang")
CONTINUATION = re.compile(r"^\s*(?:still|currently|now|karon|ngayon|gihapon)\b", re.I)
LOCATIVE = re.compile(r"^\s*(?:(?:is|stands?|standing|lying|lies?)\s+)?(?:near|beside|next to|by|duol sa|tupad sa|malapit sa)\s+(?:the\s+)?$", re.I)
PROFILE_CLAUSES = re.compile(r"\b(?:but|however|pero|apan|and|ug|at(?!\s+(?:gunpoint|(?:the |their |his |her )?(?:throat|neck))\b)|except|while|whereas|samantalang)\b", re.I)


@dataclass(frozen=True)
class _Entity:
    start: int
    end: int
    key: str
    human: bool


class _Subjects:
    def __init__(self):
        self.last_human: str | None = None
        self.last_device: str | None = None
        self.last_subject: tuple[str, bool] | None = None
        self.roles: dict[str, set[str]] = {}

    def entities(self, clause: str) -> list[_Entity]:
        entities = []
        # In Cebuano, "aso" is smoke; a dog is normally "iro". Do not let
        # the Tagalog animal homonym override an explicitly stated Cebuano tawo.
        smoke_context = bool(_rx(r"tawo|duol sa aso|aso sa sunog|baga nga aso|itom nga aso").search(clause))
        for match in ENTITY_PATTERN.finditer(clause):
            if entities and LOCATIVE.search(clause[entities[-1].end:match.start()]):
                # A noun used as a location does not replace the actual subject:
                # "worker beside the engine" still describes the worker.
                continue
            human = match["human"]
            if not human:
                role = match["nonhuman"]
                if role == "aso" and smoke_context:
                    continue
                key = "nonhuman:" + role
                entities.append(_Entity(match.start(), match.end(), key, False))
                continue
            role = HUMAN_ALIASES.get(human, human)
            which = ORDINAL.get(match["which"], match["which"] or "")
            existing = self.roles.get(role, set())
            if which in {"same", "parehong"} and self.last_human:
                key = self.last_human
            elif which:
                key = f"human:{which}:{role}"
            elif role in GENERIC_ROLES and self.last_human:
                key = self.last_human
            elif len(existing) == 1:
                key = next(iter(existing))
            elif len(existing) > 1:
                key = "human:ambiguous:" + role
            else:
                key = "human:" + role
            self.roles.setdefault(role, set()).add(key)
            entities.append(_Entity(match.start(), match.end(), key, True))
        return entities

    def resolve(self, clause: str, start: int, end: int, entities: list[_Entity]) -> tuple[str, bool]:
        inside = [entity for entity in entities if start <= entity.start < end]
        preceding = [entity for entity in entities if entity.end <= start]
        following = [entity for entity in entities if entity.start >= end]
        selected = inside[0] if inside else preceding[-1] if preceding else following[0] if following else None
        if selected:
            return selected.key, selected.human
        if HUMAN_PRONOUN.search(clause) and self.last_human:
            return self.last_human, True
        if _rx(r"it|its").search(clause) and self.last_device:
            return self.last_device, False
        if self.last_subject:
            # Coordinated symptom clauses normally retain their subject. An
            # intervening explicit device switches this continuation binding.
            return self.last_subject
        if HUMAN_CONDITION.search(clause):
            return self.last_human or "human:implicit-patient", True
        if CONTINUATION.search(clause) and self.last_human:
            return self.last_human, True
        return "unknown", False

    def observe(self, entities: list[_Entity], implicit_human: str | None) -> None:
        for entity in entities:
            self.last_subject = (entity.key, entity.human)
            if entity.human:
                self.last_human = entity.key
            else:
                self.last_device = entity.key
        if not any(entity.human for entity in entities) and implicit_human:
            self.last_human = implicit_human
            self.last_subject = (implicit_human, True)


@dataclass(frozen=True)
class _Fact:
    family: str
    phrase: str
    subject: str
    blocked: bool = False
    negated: bool = False
    context_review: bool = False


def _normalize(description: str) -> str:
    text = unicodedata.normalize("NFKC", description).casefold()
    text = text.replace("\u2019", "'").replace("\u2018", "'")
    for expression, expanded in (
        (r"isn'?t", "is not"), (r"aren'?t", "are not"),
        (r"wasn'?t", "was not"), (r"weren'?t", "were not"),
        (r"doesn'?t", "does not"), (r"don'?t", "do not"),
        (r"didn'?t", "did not"), (r"can'?t", "cannot"),
        (r"won'?t", "will not"),
    ):
        text = re.sub(r"\b" + expression + r"\b", expanded, text)
    return text


def _profile_clauses(text: str):
    # Reuse the legacy time/conditional scaffolding without treating the English
    # preposition in "at gunpoint/at the throat" as the Tagalog conjunction.
    for sentence in legacy.SENTENCES.split(text):
        parts = []
        for clause in PROFILE_CLAUSES.split(sentence):
            if parts and legacy.STOP_CONTINUATION.search(clause):
                parts[-1] += " " + clause
            else:
                parts.append(clause)
        historical = hypothetical = False
        for clause in parts:
            historical = historical or bool(legacy.NONCURRENT.search(clause))
            hypothetical = hypothetical or bool(legacy.HYPOTHETICAL.search(clause) and not (legacy.UNCERTAIN.search(clause) or UNCERTAINTY.search(clause)))
            yield clause, historical, hypothetical


def _facts(text: str) -> tuple[list[_Fact], set[str], set[str], set[str], set[str]]:
    facts: list[_Fact] = []
    awake, normal_breathing, pain_resolved = set(), set(), set()
    unclear_subjects = set()
    subjects = _Subjects()
    critical_patterns = [(family, pattern) for _level, family, pattern in legacy.PATTERNS]
    critical_patterns.append(("heavy_bleeding", legacy.NONSTOP))
    patterns = [*critical_patterns, *SIGN_PATTERNS.items()]
    # A reported lack of response *when called/touched* is an observation,
    # rather than a hypothetical future event introduced by Tagalog "kapag".
    probe_text = re.sub(r"\bkapag (?=(?:tinatawag|kinakausap|hinahawakan|ginigising)\b)", "habang ", text)
    for clause, historical, hypothetical in _profile_clauses(probe_text):
        entities = subjects.entities(clause)
        implicit_human = None
        uncertain = bool(UNCERTAINTY.search(clause) or legacy.UNCERTAIN.search(clause))
        noncurrent = bool(historical or HISTORY.search(clause)) and not legacy.ONGOING.search(clause)
        context_blocked = uncertain or noncurrent or hypothetical or bool(legacy.EDUCATIONAL.search(clause) or QUOTATION.search(clause))
        for family, pattern in patterns:
            for match in pattern.finditer(clause):
                prefix = " ".join(clause[:match.start()].split()[-7:])
                prefix = re.sub(r"\bnot only\b", "", prefix)
                cue_subject, patient = subjects.resolve(clause, match.start(), match.end(), entities)
                if patient:
                    implicit_human = cue_subject
                negated = bool(legacy.NEGATION.search(prefix) or EXTRA_NEGATION.search(prefix))
                if family in {"no_recovery", "absent_breathing"}:
                    # In these patterns the absence is the positive danger sign;
                    # a separate preceding denial still blocks the assertion.
                    negated = bool(legacy.NEGATION.search(prefix))
                if family == "heavy_bleeding":
                    prefix = re.sub(r"\b(?:dili|di|hindi|does not|cannot|will not)\s+(?:kini\s+|ito\s+|it\s+)?(?:mohunong|tumitigil|tumigil|stop)\b", "", prefix)
                    negated = bool(legacy.NEGATION.search(prefix))
                if family == "no_recovery":
                    negated = bool(_rx(r"not (?:true|the case)|denies|hindi totoong|dili tinuod").search(prefix))
                if family in INTERNAL_DENIAL_FAMILIES:
                    negated |= bool(legacy.NEGATION.search(match.group(0)) or EXTRA_NEGATION.search(match.group(0)))
                # Negation internal to a warning phrase (e.g. cannot breathe)
                # belongs to that phrase; NEGATION is checked only before it.
                wrong_subject = not patient and family not in SCENE_COMPONENTS
                if family == "reported_instability" and legacy.FINANCIAL.search(clause):
                    wrong_subject = True
                # "No update now" after a comma cannot turn an earlier sign
                # into a current observation about that patient's condition.
                fragment = legacy._comma_fragment(clause, match.start())
                fact_noncurrent = bool(historical or HISTORY.search(clause)) and not legacy.ONGOING.search(fragment)
                fact_context_blocked = uncertain or fact_noncurrent or hypothetical or bool(legacy.EDUCATIONAL.search(clause) or QUOTATION.search(clause))
                blocked = bool(fact_context_blocked or negated or wrong_subject)
                review_context = bool(fact_context_blocked or (wrong_subject and not negated and not cue_subject.startswith("nonhuman:")))
                if family in SCENE_COMPONENTS and not patient:
                    cue_subject = "scene"
                facts.append(_Fact(family, match.group(0)[:100], cue_subject, blocked, negated, review_context))
        if not context_blocked and not legacy.NEGATION.search(" ".join(clause.split()[:3])):
            for pattern, targets in ((AWAKE_NOW, awake), (AWAKE_PRESENT, awake), (BREATHING_NORMAL_NOW, normal_breathing), (PAIN_RESOLVED, pain_resolved)):
                for match in pattern.finditer(clause):
                    recovery_prefix = " ".join(clause[:match.start()].split()[-7:])
                    if legacy.NEGATION.search(recovery_prefix) or EXTRA_NEGATION.search(recovery_prefix):
                        continue
                    resolved, human = subjects.resolve(clause, match.start(), match.end(), entities)
                    if human:
                        targets.add(resolved)
                        implicit_human = resolved
        for match in RESPONSIVENESS_UNCLEAR.finditer(clause):
            resolved, human = subjects.resolve(clause, match.start(), match.end(), entities)
            if human:
                unclear_subjects.add(resolved)
        subjects.observe(entities, implicit_human)
    return facts, awake, normal_breathing, pain_resolved, unclear_subjects


def _positively_resolved(text: str, facts: list[_Fact]) -> bool:
    """Permit the model to stand when explicit positive resolution is stated.

    A past grave patient condition is never turned into a Minor suggestion by
    current wellness words. Negated/device-only words are not actual conditions.
    """
    harmless_components = SCENE_COMPONENTS | {"injury", "new_onset", "repeated_ongoing", "cold_skin", "clammy_skin", "personal_entrapment"}
    for fact in facts:
        if fact.negated or fact.subject.startswith("nonhuman:"):
            continue
        if fact.family == "injury" or fact.family not in harmless_components:
            return False
    found = {name for name, pattern in RESOLVED_CONTEXT.items() if pattern.search(text)}
    return any(required <= found for required in (
        {"cosmetic", "clear_path"},
        {"cold_ashes", "no_embers"},
        {"drain_cleared", "no_flood_impact"},
        {"secured_animal", "no_bite", "clear_path"},
        {"balloon", "no_weapon", "calm"},
        {"well_now", "normal_breathing", "alert"},
    ))


def _assessment(label: str, evidence: list[str], reason: str, priority: str = "override") -> dict:
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
        "summary": "Experimental report-language cues; no rule confidence score. " + reason + " Human assessment is required.",
        "deployment_status": "experimental",
        "assessment_method": "text_rules",
        "matched_cues": list(dict.fromkeys(evidence))[:8],
        "rule_priority": priority,
    }


def analyze_multiincident_cues(description: str, incident_type: str | None = None) -> dict | None:
    """Return a bounded advisory assessment, or None when there is no cue.

    ``incident_type`` validates the caller's category only; the inference layer
    must independently gate this profile on the trained model's supported scope.
    No active model or legacy rule state is changed.
    """
    if incident_type is not None:
        normalize_incident_type(incident_type)
    text = _normalize(description)
    base = legacy.analyze_cues(description)
    facts, awake, normal_breathing, pain_resolved, unclear_subjects = _facts(text)
    if ATTRIBUTED.search(text) and UNRESOLVED_REPORT.search(text):
        return _assessment("needs_review", [fact.phrase for fact in facts], "The attributed report explicitly says its conflicting statements cannot be resolved.")
    if RESPONSIVENESS_UNCLEAR.search(text) and _rx(r"which (?:subject|person|patient|animal)|whether (?:the )?(?:subject|victim)").search(text):
        return _assessment("needs_review", [fact.phrase for fact in facts], "The report explicitly leaves the affected subject or the presence of a human patient unresolved.")
    denied = {(fact.subject, fact.family) for fact in facts if fact.negated}
    present: dict[str, dict[str, list[str]]] = {}
    conflict = False
    for fact in facts:
        if fact.blocked:
            continue
        invalid = (fact.subject, fact.family) in denied or fact.subject in unclear_subjects
        invalid |= fact.family in {"unresponsive", "no_response", "no_recovery", "decreasing_awareness"} and fact.subject in awake
        invalid |= fact.family in {"absent_breathing", "breathing_difficulty", "breathing_compromise", "blue_lips", "abnormal_breathing", "no_chest_movement"} and fact.subject in normal_breathing
        invalid |= fact.family == "chest_pressure" and fact.subject in pain_resolved
        if invalid:
            conflict = True
            continue
        present.setdefault(fact.subject, {}).setdefault(fact.family, []).append(fact.phrase)
    evidence = []
    for signs in present.values():
        # Explicit existing grave-danger assertions remain available for every
        # supported category; unlike generic injury words these may override.
        for family in ("reported_death", "reported_instability", "unresponsive", "no_response", "absent_breathing", "heavy_bleeding", "massive_blood_loss", "breathing_difficulty", "active_threat", "direct_personal_threat"):
            evidence.extend(signs.get(family, []))
        combinations = (
            ({"chest_pressure", "cold_sweat"}, "current chest symptoms with cold sweat"),
            ({"convulsions", "repeated_ongoing", "no_recovery"}, "repeated convulsions without recovery"),
            ({"airway_swelling", "breathing_compromise"}, "airway swelling with breathing compromise"),
            ({"chest_compression", "breathing_compromise"}, "chest compression with breathing compromise"),
            ({"blue_lips", "breathing_compromise"}, "blue lips with breathing compromise"),
            ({"smoke_airway", "hoarse_voice", "breathing_compromise"}, "smoke-related signs with airway compromise"),
            ({"pallor", "cold_sweat", "decreasing_awareness"}, "pallor, cold clamminess and decreasing responsiveness"),
            ({"pallor", "cold_skin", "clammy_skin", "decreasing_awareness"}, "pallor, cold clamminess and decreasing responsiveness"),
            ({"abnormal_breathing", "no_verbal_response"}, "abnormal breathing with absent verbal response"),
            ({"no_chest_movement", "no_verbal_response"}, "absent chest movement with absent verbal response"),
            ({"no_body_movement", "no_verbal_response"}, "absent movement and response to a person"),
            ({"water_struggle", "repeated_submersion"}, "struggling in water with repeated submersion"),
            ({"water_struggle", "head_not_above_water"}, "struggling in water and unable to keep the head above it"),
            ({"repeated_submersion", "head_not_above_water"}, "repeated submersion and inability to keep the head above water"),
        )
        for required, _reason in combinations:
            if required <= signs.keys():
                evidence.extend(phrase for family in sorted(required) for phrase in signs[family])
        neurological = {"face_droop", "one_arm_weak", "speech_change"} & signs.keys()
        if "new_onset" in signs and len(neurological) >= 2:
            evidence.extend(phrase for family in sorted(neurological) for phrase in signs[family])
        if "personal_entrapment" in signs and HAZARD_FAMILIES & signs.keys():
            evidence.extend(signs["personal_entrapment"])
            evidence.extend(phrase for family in sorted(HAZARD_FAMILIES & signs.keys()) for phrase in signs[family])
    if evidence:
        return _assessment("critical", evidence, "The description reports current grave warning sign(s): " + "; ".join(dict.fromkeys(evidence)) + ". This is a reported urgency suggestion, not a diagnosis or confirmation of death.")
    hazard_evidence = [phrase for signs in present.values() for family in HAZARD_FAMILIES for phrase in signs.get(family, [])]
    scene_signs = {family for signs in present.values() for family in signs}
    for required in ({"open_pedestrian_hazard", "pedestrian_exposure"}, {"active_forced_entry", "intervention_needed"}):
        if required <= scene_signs:
            hazard_evidence.extend(phrase for signs in present.values() for family in required for phrase in signs.get(family, []))
    if hazard_evidence:
        return _assessment("serious", hazard_evidence, "The description explicitly reports an ongoing uncontained hazard requiring urgent human response. This does not infer an injury or medical diagnosis.", "minimum")
    if _positively_resolved(text, facts):
        return None
    if NO_HUMAN_HARM.search(text) and SAFE_SCENE.search(text) and not any(fact.context_review for fact in facts):
        positive_patient_warning = any(families.keys() - {"new_onset", "repeated_ongoing"} for subject, families in present.items() if subject.startswith("human:"))
        if not positive_patient_warning:
            return _assessment("minor", [match.group(0) for pattern in (NO_HUMAN_HARM, SAFE_SCENE) for match in pattern.finditer(text)], "The report explicitly states no person was harmed and the scene is safe or the vehicle safely parked. Device wording is not a patient condition.", "fallback")
    review_facts = [fact for fact in facts if fact.family != "injury" or fact.context_review]
    if review_facts and (conflict or any(fact.blocked for fact in review_facts)):
        # Preserve uncertainty and patient/device/time boundaries rather than
        # reusing a stale or contradicted Critical result from the legacy scan.
        return _assessment("needs_review", [fact.phrase for fact in facts], "The warning words are uncertain, denied, historical, recovered, contradictory, or lack a supported patient context.")
    if base is not None:
        if base["severity"] == "critical":
            return _assessment("needs_review", base.get("matched_cues", []), "The warning wording does not establish an unambiguous present patient danger.")
        result = dict(base)
        result.update(model_name=RULE_NAME, model_version=RULE_VERSION)
        return result
    return None
