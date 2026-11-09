"""Engineering context checks; these assertions are not medical validation."""
import unittest

from src.ai_service import text_cues
from src.ai_service.multiincident_cues import RULE_VERSION, analyze_multiincident_cues


class MultiIncidentCueTests(unittest.TestCase):
    def critical(self, description, category="medical_emergency"):
        result = analyze_multiincident_cues(description, category)
        self.assertIsNotNone(result, description)
        self.assertEqual(result["severity"], "critical", description)
        self.assertEqual(result["predicted_label"], "critical")
        self.assertEqual(result["model_version"], RULE_VERSION)
        self.assertEqual(result["deployment_status"], "experimental")
        self.assertEqual(result["rule_priority"], "override")
        self.assertTrue(result["responder_review_required"])
        self.assertIsNone(result["confidence"])
        self.assertIsNone(result["probabilities"])
        self.assertFalse(result["confidence_calibrated"])
        return result

    def no_critical(self, description):
        result = analyze_multiincident_cues(description, "other")
        self.assertTrue(result is None or result["severity"] != "critical", description)
        return result

    def serious_hazard(self, description, category):
        result = analyze_multiincident_cues(description, category)
        self.assertIsNotNone(result, description)
        self.assertEqual(result["severity"], "serious", description)
        self.assertEqual(result["rule_priority"], "minimum")
        self.assertIsNone(result["confidence"])
        self.assertIsNone(result["probabilities"])
        self.assertTrue(result["responder_review_required"])
        return result

    def test_chest_pressure_and_cold_sweat_in_three_languages(self):
        for text in (
            "A worker has chest pressure with a cold sweat right now.",
            "Nanininikip ang dibdib ng pasyente at malamig ang pawis niya ngayon.",
            "Huot ang dughan sa tawo ug bugnaw ang singot karon.",
            "Person has chest pressure ug bugnaw ang singot karon.",
        ):
            with self.subTest(text=text):
                self.critical(text)

    def test_new_neurological_signs_require_combination(self):
        for text in (
            "A woman's face suddenly drooped and her words are slurred.",
            "Biglang lumaylay ang mukha ng tao at bulol ang pagsasalita niya.",
            "Kalit nilaylay ang nawong sa tawo ug dili klaro ang sinultihan.",
            "Sudden facial droop sa patient ug weak ang usa ka arm.",
        ):
            with self.subTest(text=text):
                self.critical(text)
        self.no_critical("The person's speech is slurred; no other information was given.")
        self.no_critical("The person reports a sore face without a new change in speech or arm movement.")

    def test_repeated_convulsions_without_recovery(self):
        for text in (
            "The patient has repeated seizures without recovery of consciousness.",
            "Sunod-sunod ang kombulsyon ng tao at hindi nagkakamalay sa pagitan nito.",
            "Sunod-sunod ang kombulsyon sa tawo ug wala makabawi og panimuot.",
            "Repeated seizures sa person, no awareness between episodes.",
        ):
            with self.subTest(text=text):
                self.critical(text)

    def test_swelling_with_breathing_compromise(self):
        for text in (
            "The patient's throat is swollen and he is struggling to breathe.",
            "Namamaga ang lalamunan ng tao at hirap huminga ngayon.",
            "Nihubag ang tutunlan sa tawo ug naglisod sa pagginhawa karon.",
            "Throat is swelling sa person, dili makasulti og full sentence.",
        ):
            with self.subTest(text=text):
                self.critical(text)
        self.no_critical("The person has a swollen finger and is breathing normally.")

    def test_chest_compression(self):
        for text in (
            "A beam is pinning the person's chest and he cannot speak a full sentence.",
            "Naiipit ang dibdib ng tao at hirap huminga.",
            "Naipit ang dughan sa tawo ug naglisod sa pagginhawa.",
        ):
            with self.subTest(text=text):
                self.critical(text, "other")

    def test_smoke_airway_signs(self):
        self.critical("The resident has soot around her nose, a hoarse voice, and breathing is difficult.", "fire_incident")
        self.critical("Naay paso sa nawong sa tawo, paos ang tingog ug naglisod og ginhawa.", "fire_incident")

    def test_pallor_clamminess_and_deteriorating_responsiveness(self):
        for text in (
            "The person is pale, cold and clammy, becoming difficult to wake.",
            "Maputla ang tao at malamig ang pawis, mahirap nang gisingin.",
            "Luspad ang tawo ug bugnaw ang singot, lisod nang pukawon.",
        ):
            with self.subTest(text=text):
                self.critical(text)

    def test_will_not_wake_and_common_blood_flow_language(self):
        self.critical("A driver won't wake up when the rescuer calls him.", "road_accident")
        self.critical("Ang tawo di gyud mapukaw bisan tawgon.", "security_incident")
        self.critical("A person's arm wound has blood gushing from it.", "road_accident")
        self.critical("Kusog ang agas sa dugo gikan sa samad sa tawo.", "other")

    def test_existing_danger_signs_apply_to_all_known_categories(self):
        for category in ("road_accident", "fire_incident", "flood_landslide", "medical_emergency", "security_incident", "other"):
            with self.subTest(category=category):
                self.critical("The patient is unconscious right now.", category)

    def test_negation_contractions_do_not_invent_danger(self):
        for text in (
            "The patient isn't unconscious and isn't bleeding heavily.",
            "The person does not have chest pressure or a cold sweat.",
            "Walang malay is quoted in a lesson; no patient is being reported.",
            "Hindi namamaga ang lalamunan ng tao at normal ang paghinga.",
            "Wala naglisod og ginhawa ang tawo, wala sad nihubag ang tutunlan.",
        ):
            with self.subTest(text=text):
                self.no_critical(text)

    def test_uncertainty_and_historical_or_hypothetical_context(self):
        for text in (
            "I cannot confirm if a person has chest pressure and a cold sweat.",
            "Unsure if the patient is unconscious.",
            "Yesterday the person had chest pressure and a cold sweat.",
            "Ganiha walay panimuot ang tawo, walay update karon.",
            "What if the patient has repeated seizures without recovery?",
            "Basin wala nay ginhawa ang tawo pero dili nako makumpirma.",
            "Someone says a person may have died, but I cannot verify it.",
        ):
            with self.subTest(text=text):
                self.no_critical(text)

    def test_recovery_does_not_automatically_become_minor(self):
        for text in (
            "The person was unresponsive earlier but is awake now.",
            "The person is unconscious now, but the same person is awake now.",
            "The patient is struggling to breathe but is breathing normally now.",
            "Walay panimuot ganiha ang tawo pero mata na karon.",
            "The patient has repeated seizures without recovery but is awake again now.",
        ):
            with self.subTest(text=text):
                result = self.no_critical(text)
                self.assertIsNotNone(result)
                self.assertEqual(result["predicted_label"], "needs_review")

    def test_same_person_direct_conflict_requires_review(self):
        result = self.no_critical("The person is unconscious now but the person is not unconscious now.")
        self.assertIsNotNone(result)
        self.assertEqual(result["predicted_label"], "needs_review")
        self.no_critical("The patient has chest pressure and cold sweat but the patient has no chest pressure.")

    def test_one_person_recovery_does_not_cancel_another_person(self):
        self.critical("One driver was unconscious earlier but is awake now. Another passenger is unconscious now.", "road_accident")
        self.critical("Unsure if the driver has a cut, but the passenger is bleeding heavily now.", "road_accident")
        self.critical("One person has normal breathing now. Another person has a swollen throat and is struggling to breathe.", "fire_incident")

    def test_dead_devices_and_animals_are_not_human_triage(self):
        for text in (
            "The engine is dead and the phone will not wake up.",
            "The driver says the camera is unresponsive but the driver is fine.",
            "The screen shows unresponsive; the passenger is safe.",
            "The dog is unconscious and its owner is talking normally.",
            "Patay ang makina pero walay tawo nga nasamdan.",
        ):
            with self.subTest(text=text):
                self.no_critical(text)

    def test_no_supported_person_context(self):
        result = self.no_critical("Unresponsive. Nothing else is stated.")
        self.assertIsNotNone(result)
        self.assertEqual(result["predicted_label"], "needs_review")
        self.assertIsNone(analyze_multiincident_cues("Please send information about the application."))

    def test_legacy_profile_and_global_patterns_remain_unchanged(self):
        text = "The driver has chest pressure and a cold sweat."
        before = text_cues.analyze_cues(text)
        patterns = tuple(text_cues.PATTERNS)
        version = text_cues.RULE_VERSION
        self.critical(text)
        self.assertEqual(text_cues.analyze_cues(text), before)
        self.assertEqual(tuple(text_cues.PATTERNS), patterns)
        self.assertEqual(text_cues.RULE_VERSION, version)
        self.assertEqual(version, "text-cues-20261109-v2")

    def test_rejects_unknown_incident_type(self):
        with self.assertRaises(ValueError):
            analyze_multiincident_cues("The person is unconscious.", "invented_category")

    def test_separate_workers_cannot_supply_one_composite(self):
        for text in (
            "One worker has chest pressure. Another worker has a cold sweat.",
            "One worker has chest pressure, another worker has a cold sweat.",
            "The mother has chest pressure. The child has a cold sweat.",
            "The driver has chest pressure but the passenger has a cold sweat.",
        ):
            with self.subTest(text=text):
                self.no_critical(text)
        self.critical("One worker has chest pressure. The same worker has a cold sweat.")

    def test_posters_translation_and_actors_are_not_actual_assertions(self):
        for text in (
            "A passenger is reading a poster that says unconscious. There is no casualty at this stop.",
            "The operator asks how to translate blood gushing for a poster. No actual patient is present.",
            "The patient is unconscious in the training exercise, with actors following the instructor.",
            "The instructor is acting out repeated seizures without recovery for a lesson.",
        ):
            with self.subTest(text=text):
                self.no_critical(text)
        self.critical("The worker is unconscious after a sign fell onto him.")
        self.critical("The patient is unconscious after exercising outside.")

    def test_remote_history_cannot_supply_current_combination(self):
        self.no_critical("The patient had chest pressure in childhood. Cold sweat occurred a decade ago.")
        self.no_critical("The patient had chest pressure years ago and a cold sweat in the past.")
        self.critical("The patient had chest pressure in childhood. The patient is unconscious now.")

    def test_device_recovery_and_denial_do_not_cancel_human_danger(self):
        for suffix in (
            "Their phone is awake now.",
            "Their phone is not unresponsive.",
            "Their camera is breathing normally now.",
        ):
            with self.subTest(suffix=suffix):
                self.critical("The patient is unconscious now. " + suffix)

    def test_family_roles_keep_recovery_separate(self):
        self.critical("The child is not breathing now. The mother is breathing normally now.")
        self.critical("Ang bata dili na moginhawa karon. Normal na ang ginhawa sa inahan.")
        self.no_critical("The child is not breathing now. The same child is breathing normally now.")

    def test_location_reference_does_not_replace_patient(self):
        self.critical("Ang tawo duol sa aso dili mapukaw karon.", "fire_incident")
        self.critical("The worker near the engine is unconscious now.")
        self.critical("The passenger beside the phone won't wake up.")
        self.no_critical("Ang aso ay walang malay, at ligtas ang may-ari.")
        self.no_critical("The dog near the worker is unconscious.")

    def test_nonstop_bleeding_with_implicit_body_part_patient(self):
        for text in (
            "Kusog ang pagdugo sa samad sa kamot ug dili mohunong bisan gitamponan.",
            "Malakas ang pagdurugo sa sugat ng braso at hindi tumitigil kahit dinidiinan.",
            "Blood is flowing heavily from a leg wound and will not stop with pressure.",
        ):
            with self.subTest(text=text):
                self.critical(text)
        self.no_critical("Wala nagdugo og kusog ang samad sa kamot ug normal ang ginhawa.")
        self.no_critical("Hindi malakas ang pagdurugo sa sugat ng braso at tumigil na ang dugo.")

    def test_abnormal_breathing_with_nonresponse_is_a_combination(self):
        for text in (
            "Dili normal ang ginhawa sa tawo. Wala naglihok ang dughan ug dili siya motubag kon tawgon.",
            "Hindi normal ang paghinga ng pasyente. Walang galaw ang dibdib at hindi siya sumasagot.",
            "A person has abnormal breathing and does not respond when called.",
        ):
            with self.subTest(text=text):
                self.critical(text)
        self.no_critical("The person has abnormal breathing but is awake and answering; no further detail was reported.")
        self.no_critical("Dili normal ang ginhawa sa tawo ganiha pero normal na ang ginhawa karon ug motubag na siya.")

    def test_unresolved_attributed_contradiction_is_reviewed(self):
        for text in (
            "The report says the same person is unresponsive now but also awake and answering now. I cannot confirm which statement is correct.",
            "According to the report, the patient is unconscious. The patient is awake, but I cannot verify which version is true.",
            "The person is unresponsive now but is also awake and answering now.",
        ):
            with self.subTest(text=text):
                result = self.no_critical(text)
                self.assertEqual(result["predicted_label"], "needs_review")
        self.critical("I cannot confirm the driver's condition. A passenger is unconscious now.")

    def test_explicit_safe_no_casualty_device_report_preserves_model_minor(self):
        for text in (
            "The engine is dead, but nobody was hit or injured. The van is safely parked off the road with no smoke.",
            "The phone is unresponsive. No one was injured and the area is safe.",
            "Patay ang makina pero walay nasamdan, luwas na ang dapit ug wala nay peligro.",
        ):
            with self.subTest(text=text):
                result = analyze_multiincident_cues(text, "road_accident")
                self.assertIsNotNone(result)
                self.assertEqual(result["severity"], "minor")
                self.assertEqual(result["rule_priority"], "fallback")
                self.assertIsNone(result["confidence"])
        self.critical("The engine is dead. The patient is unconscious now, although no one else was injured and the area is safe.")

    def test_heavy_flow_from_additional_body_parts_is_actual_danger(self):
        for text in (
            "Blood pours from a thigh wound despite firm pressure and the person is becoming faint. Their clothing is soaked with blood.",
            "Blood is pouring from the thigh despite pressure and clothing is soaked; the patient is very faint.",
            "Blood gushes from the forearm wound and does not stop under pressure.",
        ):
            with self.subTest(text=text):
                self.critical(text)
        self.no_critical("The dog has blood gushing from a thigh wound; the owner is safe.")

    def test_third_casualty_aliases_and_response_probes(self):
        for text in (
            "Ang unang tao ay gising. Ang ikatlong tao ay hindi gumagalaw at hindi sumasagot kapag tinatawag.",
            "Ang unang tawo normal na ang ginhawa karon. Ang ikatulong tawo dili molihok ug dili siya motubag kon tawgon.",
            "The first patient is breathing normally now. The third patient has no chest movement and does not respond when called.",
        ):
            with self.subTest(text=text):
                self.critical(text)
        self.no_critical("The third radio node is not moving and does not respond when called.")

    def test_unknown_injury_is_not_a_generic_serious_assertion(self):
        for text in (
            "I cannot see whether anybody is hurt.",
            "I can't determine if the passenger is injured because the view is blocked.",
            "Hindi ko makita kung may nasugatan sa loob.",
            "Dili nako makita kon naay nasamdan didto.",
        ):
            with self.subTest(text=text):
                result = analyze_multiincident_cues(text, "other")
                self.assertIsNotNone(result)
                self.assertEqual(result["predicted_label"], "needs_review")
                self.assertEqual(result["rule_priority"], "override")

    def test_current_uncontained_fire_requires_response_without_injury(self):
        for text in (
            "The building is on fire and flames are spreading. Everyone has evacuated and no injuries are reported.",
            "Nasusunog pa ang bahay, ligtas ang mga tao sa labas at walang nasugatan.",
            "Nagdilaab ang balay ug nagkaylap ang kalayo, walay nasamdan ug nanggawas na ang tanan.",
        ):
            with self.subTest(text=text):
                self.serious_hazard(text, "fire_incident")

    def test_current_rising_water_is_an_urgent_hazard(self):
        for text in (
            "Floodwater is rising and blocking the exit, but no person is injured.",
            "Tumataas ang baha at walang nasaktan, kailangan ng responder sa lugar.",
            "Nagsaka ang tubig sa dalan, walay nasamdan ug wala pay tawo nga natanggong.",
        ):
            with self.subTest(text=text):
                self.serious_hazard(text, "flood_landslide")

    def test_active_assault_and_direct_personal_threat_are_distinct(self):
        for text in (
            "An ongoing assault is happening outside. No injuries have been confirmed.",
            "Patuloy na nananakit ang armado habang hinihintay ang pulis.",
            "Gisumbag gihapon ang tawo ug kinahanglan og responders.",
        ):
            with self.subTest(text=text):
                self.serious_hazard(text, "security_incident")
        self.critical("A worker is being held at gunpoint right now.", "security_incident")
        self.critical("The attacker is strangling the victim now.", "security_incident")

    def test_explicit_entrapment_in_active_hazard_is_direct_danger(self):
        for text in (
            "A person is trapped inside a burning building and cannot get out.",
            "A patient cannot escape while floodwater is rising around them.",
            "Ang tawo natanggong ug nagsaka ang tubig sa iyang palibot.",
        ):
            with self.subTest(text=text):
                self.critical(text, "other")

    def test_denied_historical_hypothetical_hazards_do_not_establish_current_urgency(self):
        for text in (
            "The fire is not spreading and the house is not burning. Nobody was injured; the scene is safe.",
            "Yesterday floodwater was rising. There is no update about the scene today.",
            "What if a building is on fire and no person is injured?",
            "The attacker is not attacking anyone now; police have secured the area.",
        ):
            with self.subTest(text=text):
                result = analyze_multiincident_cues(text, "other")
                self.assertTrue(result is None or result["severity"] not in {"critical", "serious"}, text)

    def test_scene_safety_words_cannot_cancel_ongoing_hazard(self):
        self.serious_hazard("Nobody was injured and the people are safely parked off the road, but flames are spreading through the vehicle.", "road_accident")
        self.critical("The area is safe for bystanders and nobody else was injured, but one person is trapped inside a burning house.", "fire_incident")

    def test_continuing_heavy_flow_language_with_resident_aliases(self):
        for text in (
            "A resident has a deep thigh wound. Blood keeps flowing heavily despite pressure and is soaking the cloth.",
            "Lawom nga nasamad sa bildo ang bukton sa residente. Kusog ang agas sa dugo ug dili gihapon mohunong bisan gipiit.",
            "Malalim na nahiwa ng bubog ang braso ng residente. Bumubuhos ang dugo at hindi pa rin tumitigil kahit dinidiinan.",
        ):
            with self.subTest(text=text):
                self.critical(text)

    def test_current_submersion_and_inability_to_stay_above_water(self):
        for text in (
            "A person is struggling in the river and repeatedly goes under. He cannot keep his head above the water.",
            "Nagpupumiglas ang tao sa ilog at paulit-ulit lumulubog. Hindi niya maitaas ang ulo sa tubig.",
            "Nanlimbasog ang tawo sa suba ug sige og lunod. Dili niya mapabiling ibabaw sa tubig ang ulo.",
        ):
            with self.subTest(text=text):
                self.critical(text, "flood_landslide")
        self.no_critical("The dog is struggling in the river and repeatedly goes under; the owner is safe.")
        self.no_critical("Yesterday a person was struggling in the river and repeatedly went under; I have no update today.")

    def test_positive_resolved_scenes_leave_model_assessment_intact(self):
        for text in (
            "Only paint marks remain on the bumper. No one was hurt and the lane is clear.",
            "The ashes were checked and are cold, with no live embers. Nobody was injured.",
            "The drain has been cleared and no houses were flooded. The path is clear.",
            "The dog is secured and calm now. Nobody was bitten and the path is safe.",
            "It was a party balloon that popped. No weapon was present and the crowd is calm now.",
        ):
            with self.subTest(text=text):
                self.assertIsNone(analyze_multiincident_cues(text, "other"), text)
        # Absence of injury alone does not provide positive scene resolution.
        result = analyze_multiincident_cues("No one is hurt, but I cannot check the scene.", "other")
        self.assertTrue(result is None or result["severity"] != "minor")

    def test_resolved_mild_warmth_is_not_prior_grave_history(self):
        mild = "The person felt warm earlier but is fully well now, awake and breathing normally. There is no pain or injury."
        self.assertIsNone(analyze_multiincident_cues(mild, "medical_emergency"))
        prior_grave = "The person was unconscious earlier but is fully well now, awake and breathing normally. There is no pain or injury."
        result = analyze_multiincident_cues(prior_grave, "medical_emergency")
        self.assertIsNotNone(result)
        self.assertEqual(result["predicted_label"], "needs_review")

    def test_responsiveness_clarification_is_bound_to_affected_person(self):
        unclear = "A daughter says her father is talking normally but cannot be awakened at the same moment. His present responsiveness needs clarification."
        result = self.no_critical(unclear)
        self.assertEqual(result["predicted_label"], "needs_review")
        self.critical(unclear + " A passenger is unconscious now.")

    def test_unresolved_animal_or_human_subject_requires_review(self):
        text = "The caller switches between a dead dog and an unresponsive person. It is unclear which subject is actually present."
        result = self.no_critical(text)
        self.assertEqual(result["predicted_label"], "needs_review")

    def test_exposed_environmental_opening_requires_a_supported_hazard_pair(self):
        self.serious_hazard("A drain cover is missing on a busy footpath. Pedestrians are being diverted and the opening needs securing.", "other")
        self.serious_hazard("A missing manhole cover is beside a walkway, and a barrier is needed while pedestrians are walking past.", "other")
        self.no_critical("A missing drain cover is stored in a closed workshop; nobody is near an opening.")
        result = analyze_multiincident_cues("The drain cover is not missing on the busy footpath.", "other")
        self.assertTrue(result is None or result["severity"] != "serious")

    def test_current_forced_entry_requires_response_without_inferred_casualty(self):
        self.serious_hazard("A stranger is forcing the padlock of an empty shop. The owner is elsewhere and intervention is needed.", "security_incident")
        self.serious_hazard("An intruder is cutting the lock at the shop. Police are needed at the scene.", "security_incident")
        result = analyze_multiincident_cues("The stranger is not forcing the padlock. The owner is elsewhere.", "security_incident")
        self.assertTrue(result is None or result["severity"] != "serious")


if __name__ == "__main__":
    unittest.main()
