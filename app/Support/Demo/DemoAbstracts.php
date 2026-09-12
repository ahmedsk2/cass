<?php

declare(strict_types=1);

namespace App\Support\Demo;

/**
 * The fifteen fictional abstracts `cass:demo-seed` writes.
 *
 * Everything here is invented. Every address is inside `example.com`, which
 * RFC 2606 reserves precisely so that sample data cannot reach a real mailbox;
 * every author name and every institution is made up; no abstract describes a
 * real study, a real patient or a real result. It is realistic enough that a
 * conference organizer looking at the ranking sees what their own conference
 * will look like, and specific enough that the tracks, the presentation
 * preferences and the custom fields are all exercised.
 *
 * The order is load-bearing: the twelve `submitted` entries come first and draw
 * DEMO27-001 to DEMO27-012 in that order, the withdrawn one draws DEMO27-013,
 * and the two drafts never draw a reference at all.
 *
 * Each abstract is 150-250 words, inside the demo conference's 300-word limit
 * with room for an organizer to paste one of their own in beside it.
 */
final class DemoAbstracts
{
    public const STATE_SUBMITTED = 'submitted';

    public const STATE_WITHDRAWN = 'withdrawn';

    public const STATE_DRAFT = 'draft';

    /**
     * `track` is an index into the conference's three tracks, in the order
     * SeedDemo creates them: 0 sepsis and shock, 1 respiratory support,
     * 2 quality improvement. `corresponding` is an index into `authors`.
     *
     * @return list<array{
     *     title: string,
     *     abstract: string,
     *     track: int,
     *     preference: string,
     *     study_type: string,
     *     ethics: string,
     *     state: string,
     *     corresponding: int,
     *     authors: list<array{name: string, email: string, affiliation: string}>,
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'title' => 'Time to first antibiotic dose in children presenting with septic shock to a regional emergency department',
                'abstract' => 'Background: Delay between recognition of septic shock and the first antibiotic dose is one of the few modifiable steps in early paediatric sepsis care, yet reported delays vary widely between centres. Methods: We reviewed the records of every child under sixteen years who met our unit definition of septic shock on arrival at a regional emergency department over a twenty-four month period. Time zero was the triage observation that first met the trigger criteria. The primary measure was minutes to the first antibiotic dose; secondary measures were minutes to the first fluid bolus, paediatric intensive care admission and length of stay. Results: One hundred and forty-one children met the definition. Median time to the first antibiotic dose was seventy-four minutes, with a wide spread between day and night presentations. Children triaged directly into the resuscitation area received antibiotics a median of thirty-one minutes earlier than those triaged to a cubicle first. Time to the first fluid bolus was shorter and less variable. Conclusion: Antibiotic delay in this cohort was driven less by recognition than by where the child was physically placed after triage. A single change to triage destination is a plausible target for the next improvement cycle, and is easier to sustain overnight than a new alert.',
                'track' => 0,
                'preference' => 'oral',
                'study_type' => 'Original research',
                'ethics' => 'IRB-DEMO-2026-014',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 0,
                'authors' => [
                    ['name' => 'Layla Al-Harbi', 'email' => 'layla.alharbi@example.com', 'affiliation' => 'Demo Children\'s Hospital, Riyadh'],
                    ['name' => 'Omar Bin Saleh', 'email' => 'omar.binsaleh@example.com', 'affiliation' => 'Demo Children\'s Hospital, Riyadh'],
                    ['name' => 'Hana Al-Mutairi', 'email' => 'hana.almutairi@example.com', 'affiliation' => 'Gulf Paediatric Institute, Dammam'],
                ],
            ],
            [
                'title' => 'A nurse-led fluid responsiveness checklist reduces cumulative fluid balance in the first forty-eight hours of paediatric sepsis',
                'abstract' => 'Background: Positive cumulative fluid balance in the first days of paediatric sepsis is consistently associated with longer ventilation, but bedside decisions about the next bolus are often made without a structured reassessment. Methods: We introduced a one-page checklist completed by the bedside nurse before every fluid bolus after the first twenty millilitres per kilogram. The checklist asked four questions about perfusion, respiratory effort, liver edge and the response to the previous bolus. We compared the twelve months before and the twelve months after introduction, using cumulative fluid balance at forty-eight hours as the primary measure. Results: Ninety-six children were included before and one hundred and four afterwards. Median cumulative balance at forty-eight hours fell by a clinically meaningful margin, with the largest difference in children admitted overnight. The number of boluses given fell modestly; the number reassessed within thirty minutes rose sharply. Ventilator-free days at twenty-eight days were unchanged. Conclusion: A short nurse-led checklist changed the reassessment behaviour it was designed to change and was associated with a lower fluid burden, without any change to the fluid protocol itself. Whether that translates into shorter ventilation needs a larger and prospectively powered study.',
                'track' => 0,
                'preference' => 'poster',
                'study_type' => 'QI project',
                'ethics' => 'IRB-DEMO-2026-027',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 1,
                'authors' => [
                    ['name' => 'Noura Al-Qahtani', 'email' => 'noura.alqahtani@example.com', 'affiliation' => 'Coastal Children\'s Hospital, Muscat'],
                    ['name' => 'Yousef Al-Sabah', 'email' => 'yousef.alsabah@example.com', 'affiliation' => 'Coastal Children\'s Hospital, Muscat'],
                ],
            ],
            [
                'title' => 'High-flow nasal cannula as first-line support in infants with bronchiolitis outside the intensive care unit: a two-season cohort',
                'abstract' => 'Background: High-flow nasal cannula therapy is increasingly started on general paediatric wards, but the safety of that practice depends on how reliably deterioration is recognised away from an intensive care environment. Methods: We followed every infant under twelve months started on high-flow for bronchiolitis on two general wards across two consecutive winter seasons. Escalation criteria and an observation frequency were fixed in advance. The primary measure was unplanned intensive care admission within twenty-four hours of starting high-flow; secondary measures were intubation, transfer delay and total oxygen days. Results: Two hundred and eighteen infants were included. Thirty-one required intensive care admission, of whom four were intubated. The median interval between the first breach of an escalation criterion and the decision to transfer was ninety minutes, and was longest between midnight and six in the morning. No infant deteriorated without a documented breach beforehand. Conclusion: Ward-based high-flow was feasible, and every escalation in this cohort was preceded by a criterion that had already been recorded. The gap was in acting on the record rather than in making it, which points at the handover and the escalation pathway rather than at the therapy.',
                'track' => 1,
                'preference' => 'oral',
                'study_type' => 'Original research',
                'ethics' => 'IRB-DEMO-2026-033',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 0,
                'authors' => [
                    ['name' => 'Faisal Al-Ameri', 'email' => 'faisal.alameri@example.com', 'affiliation' => 'Harbour Children\'s Hospital, Abu Dhabi'],
                    ['name' => 'Sara Khalid', 'email' => 'sara.khalid@example.com', 'affiliation' => 'Harbour Children\'s Hospital, Abu Dhabi'],
                    ['name' => 'Mariam Al-Dosari', 'email' => 'mariam.aldosari@example.com', 'affiliation' => 'Riverside Paediatric Institute, Doha'],
                ],
            ],
            [
                'title' => 'Extubation readiness testing driven by respiratory therapists shortens ventilation in a mixed paediatric intensive care unit',
                'abstract' => 'Background: Decisions to extubate are often made on a single ward round, so a child who becomes ready in the afternoon may wait until the following morning. Methods: We gave respiratory therapists a standing order to perform a spontaneous breathing trial twice daily on every ventilated child meeting simple safety criteria, and to report the result directly to the attending physician. The pre-existing protocol, sedation practice and staffing were unchanged. We compared ventilation duration, extubation failure within forty-eight hours and unplanned extubation across eighteen months before and eighteen months after. Results: Three hundred and twelve ventilation episodes were analysed. Median ventilation duration fell by roughly nineteen hours, driven almost entirely by afternoon and evening extubations, which rose from a small minority to about a third of the total. Extubation failure was unchanged at a little under ten per cent. There were no unplanned extubations attributable to the trial itself. Conclusion: Moving the readiness assessment off the ward round and onto a twice-daily schedule shortened ventilation without increasing failure. The change cost no additional staffing, and its effect was concentrated in the hours when nobody had previously been asking the question.',
                'track' => 1,
                'preference' => 'oral',
                'study_type' => 'QI project',
                'ethics' => 'IRB-DEMO-2026-041',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 2,
                'authors' => [
                    ['name' => 'Abdullah Al-Rashid', 'email' => 'abdullah.alrashid@example.com', 'affiliation' => 'Northern Children\'s Hospital, Kuwait City'],
                    ['name' => 'Reem Al-Otaibi', 'email' => 'reem.alotaibi@example.com', 'affiliation' => 'Northern Children\'s Hospital, Kuwait City'],
                    ['name' => 'Tariq Al-Balushi', 'email' => 'tariq.albalushi@example.com', 'affiliation' => 'Coastal Children\'s Hospital, Muscat'],
                ],
            ],
            [
                'title' => 'A structured handover tool and its effect on omitted safety items at paediatric intensive care shift change',
                'abstract' => 'Background: Shift change is where most of what one team knows has to survive into the next, and omissions at handover are a recurring theme in paediatric critical incident reviews. Methods: We audited one hundred consecutive verbal handovers against a list of eleven safety items agreed by the unit, then introduced a printed structured tool populated from the electronic record and re-audited one hundred handovers three months later. Observers were unit nurses trained together and blinded to the audit period where possible. Results: Before the tool, a median of three of the eleven items were omitted, most often the resuscitation status, the airway plan and the pending investigations. After the tool, the median fell to one, with the resuscitation status omitted in only a handful of handovers. Mean handover duration rose by about ninety seconds per patient. Interruptions per handover were unchanged. Conclusion: A printed tool populated from the record closed most of the omission gap at a modest cost in time. The items that remained unreliable were the ones the record itself does not hold in a single field, which is an argument for fixing the record rather than for lengthening the tool.',
                'track' => 2,
                'preference' => 'poster',
                'study_type' => 'QI project',
                'ethics' => 'IRB-DEMO-2026-052',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 0,
                'authors' => [
                    ['name' => 'Aisha Al-Zahrani', 'email' => 'aisha.alzahrani@example.com', 'affiliation' => 'Pearl Paediatric Centre, Manama'],
                    ['name' => 'Khalid Al-Najjar', 'email' => 'khalid.alnajjar@example.com', 'affiliation' => 'Pearl Paediatric Centre, Manama'],
                ],
            ],
            [
                'title' => 'Serial lactate clearance and vasoactive requirement in children with fluid-refractory septic shock',
                'abstract' => 'Background: Lactate clearance is widely used as a marker of resuscitation adequacy in adults, and is often extrapolated to children without a paediatric threshold. Methods: We collected paired lactate measurements at presentation and at six hours in consecutive children admitted with fluid-refractory septic shock over three years, together with the vasoactive-inotropic score at six and twenty-four hours. Clearance was expressed as a percentage change from the presenting value. Children with an alternative explanation for hyperlactataemia were excluded in advance. Results: Eighty-seven children were included. Clearance of at least twenty per cent at six hours was associated with a lower vasoactive-inotropic score at twenty-four hours, and the relationship was strongest in those whose presenting lactate exceeded four millimoles per litre. In children presenting with a lactate below that value, clearance carried little additional information beyond the clinical examination. Conclusion: Lactate clearance appears informative in this cohort only where the presenting value was already markedly raised. Applying a fixed clearance target to every child risks prolonging resuscitation in those whose starting point was never abnormal enough for the target to mean anything.',
                'track' => 0,
                'preference' => 'either',
                'study_type' => 'Original research',
                'ethics' => 'IRB-DEMO-2026-058',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 1,
                'authors' => [
                    ['name' => 'Huda Al-Farsi', 'email' => 'huda.alfarsi@example.com', 'affiliation' => 'Gulf Paediatric Institute, Dammam'],
                    ['name' => 'Bader Al-Shammari', 'email' => 'bader.alshammari@example.com', 'affiliation' => 'Gulf Paediatric Institute, Dammam'],
                    ['name' => 'Salma Ibrahim', 'email' => 'salma.ibrahim@example.com', 'affiliation' => 'Demo Children\'s Hospital, Riyadh'],
                ],
            ],
            [
                'title' => 'Neurally adjusted ventilatory assist in an infant with congenital diaphragmatic hernia and prolonged ventilator dependence',
                'abstract' => 'Background: Infants who remain ventilator dependent after repair of a congenital diaphragmatic hernia are difficult to wean, and conventional pressure support can be poorly synchronised where diaphragmatic mechanics are abnormal. Case: We describe a term infant repaired on the fourth day of life who remained dependent on conventional ventilation at eight weeks, with repeated failed weaning attempts and marked patient-ventilator asynchrony on waveform review. After multidisciplinary discussion, neurally adjusted ventilatory assist was started using a standard oesophageal catheter, with the assist level titrated against the electrical activity of the diaphragm. Asynchrony indices fell within the first day, sedation requirement fell over the following week, and the infant was extubated to non-invasive support on the eleventh day of the new mode. There was no pneumothorax and no catheter-related complication. Follow-up at six months showed no supplemental oxygen requirement. Conclusion: In this single infant, a mode driven by diaphragmatic electrical activity interrupted a cycle of asynchrony and sedation that conventional support had not. A case report cannot establish benefit, but it does establish feasibility in a setting where the diaphragm itself is the problem.',
                'track' => 1,
                'preference' => 'poster',
                'study_type' => 'Case report',
                'ethics' => 'IRB-DEMO-2026-063',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 0,
                'authors' => [
                    ['name' => 'Maha Al-Suwaidi', 'email' => 'maha.alsuwaidi@example.com', 'affiliation' => 'Harbour Children\'s Hospital, Abu Dhabi'],
                    ['name' => 'Ibrahim Al-Kindi', 'email' => 'ibrahim.alkindi@example.com', 'affiliation' => 'Coastal Children\'s Hospital, Muscat'],
                ],
            ],
            [
                'title' => 'Reducing unnecessary daily blood tests in a paediatric intensive care unit through a default-off ordering set',
                'abstract' => 'Background: Repeated phlebotomy contributes to anaemia and to transfusion in critically ill children, and a large share of daily tests in our unit were ordered by a recurring default rather than by a question anybody had asked. Methods: We rebuilt the admission order set so that no laboratory test recurred automatically, and required a clinician to select each recurring test with a stated duration. Clinical protocols were unchanged and any test could still be ordered at any time. We measured tests per patient-day, phlebotomy volume per patient-day and red cell transfusion over twelve months either side. Results: Tests per patient-day fell by about a third, with the largest reductions in coagulation studies and in magnesium. Phlebotomy volume per patient-day fell correspondingly. Red cell transfusion fell slightly, within the range of ordinary year-to-year variation. No child required an unplanned repeat of a test that had been missed, as judged by a monthly safety review of every unexpected deterioration. Conclusion: Removing the default, rather than adding a rule, reduced testing substantially and safely. The intervention required no ongoing education, which is the main reason we expect it to persist.',
                'track' => 2,
                'preference' => 'oral',
                'study_type' => 'QI project',
                'ethics' => 'IRB-DEMO-2026-071',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 0,
                'authors' => [
                    ['name' => 'Dana Al-Hashimi', 'email' => 'dana.alhashimi@example.com', 'affiliation' => 'Riverside Paediatric Institute, Doha'],
                    ['name' => 'Saeed Al-Mansoori', 'email' => 'saeed.almansoori@example.com', 'affiliation' => 'Riverside Paediatric Institute, Doha'],
                    ['name' => 'Lina Haddad', 'email' => 'lina.haddad@example.com', 'affiliation' => 'Pearl Paediatric Centre, Manama'],
                ],
            ],
            [
                'title' => 'Non-invasive ventilation failure in immunocompromised children: predictors available within the first six hours',
                'abstract' => 'Background: Immunocompromised children who fail non-invasive ventilation and are intubated late have worse outcomes than those intubated early, so the value of any predictor lies in how soon it is available. Methods: We reviewed consecutive episodes of non-invasive ventilation in children with haematological malignancy or after stem cell transplantation across two units over four years. Failure was defined as intubation within seventy-two hours. Candidate predictors were restricted to variables recorded within the first six hours. Results: One hundred and nine episodes in eighty-three children were analysed, with a failure rate close to forty per cent. Persistent tachypnoea at two hours, an oxygenation index that did not improve between one and six hours, and the presence of two or more organ dysfunctions at the start were each associated with failure. Fever, neutrophil count and the underlying diagnosis were not. Children who failed after twenty-four hours had the longest intensive care stay. Conclusion: The variables most strongly associated with failure were all available within six hours and required no additional test. A trial that fixes a reassessment point at six hours, rather than leaving it to judgement, is the logical next step.',
                'track' => 1,
                'preference' => 'either',
                'study_type' => 'Original research',
                'ethics' => 'IRB-DEMO-2026-078',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 2,
                'authors' => [
                    ['name' => 'Rania Al-Azzawi', 'email' => 'rania.alazzawi@example.com', 'affiliation' => 'Demo Children\'s Hospital, Riyadh'],
                    ['name' => 'Nasser Al-Hajri', 'email' => 'nasser.alhajri@example.com', 'affiliation' => 'Northern Children\'s Hospital, Kuwait City'],
                    ['name' => 'Zeina Mansour', 'email' => 'zeina.mansour@example.com', 'affiliation' => 'Northern Children\'s Hospital, Kuwait City'],
                ],
            ],
            [
                'title' => 'Family presence during paediatric resuscitation: a survey of staff attitudes before and after a facilitated debrief programme',
                'abstract' => 'Background: Guidelines support offering family presence during resuscitation, but whether it is offered in practice depends largely on how the staff in the room feel about it. Methods: We surveyed all clinical staff in a paediatric intensive care unit and its associated emergency department before and twelve months after introducing a facilitated debrief after every resuscitation, in which family presence was a standing agenda item. The survey used a five-point scale across nine statements about confidence, perceived benefit to families and perceived interference with the resuscitation. Results: Two hundred and six staff responded before and one hundred and ninety-one afterwards, with similar grade distributions. Agreement that family presence benefits the family was high at both points and barely changed. Confidence in supporting a family present in the room rose substantially, and concern about interference fell, with the largest shift among staff of fewer than three years experience. Written comments moved from whether to offer presence towards who should be assigned to support the family. Conclusion: The debrief programme changed confidence rather than belief. That distinction matters, because the barrier it removed was the one that actually stops presence being offered at three in the morning.',
                'track' => 2,
                'preference' => 'poster',
                'study_type' => 'Original research',
                'ethics' => 'IRB-DEMO-2026-084',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 0,
                'authors' => [
                    ['name' => 'Samira Al-Naimi', 'email' => 'samira.alnaimi@example.com', 'affiliation' => 'Pearl Paediatric Centre, Manama'],
                    ['name' => 'Hamad Al-Thani', 'email' => 'hamad.althani@example.com', 'affiliation' => 'Riverside Paediatric Institute, Doha'],
                ],
            ],
            [
                'title' => 'Vasopressin as a catecholamine-sparing agent in paediatric vasodilatory shock: a single-centre experience',
                'abstract' => 'Background: Vasopressin is used in paediatric vasodilatory shock largely on the strength of adult data, and reported paediatric experience is small and heterogeneous. Methods: We identified every child who received vasopressin for vasodilatory shock in one unit over six years and recorded the noradrenaline equivalent dose at the time vasopressin was started and at two, six and twelve hours afterwards, together with lactate, urine output and any documented digital or splanchnic ischaemia. Children receiving vasopressin for diabetes insipidus or after cardiac surgery were excluded. Results: Fifty-four children were included, at a median age of two years. The noradrenaline equivalent dose fell in the majority within six hours, and the fall was largest where vasopressin was started at a lower catecholamine dose. Lactate and urine output trends were mixed. Three children developed reversible digital ischaemia, all at the higher end of the vasopressin dose range used. Conclusion: Vasopressin reduced catecholamine requirement in most children in this cohort, with the greatest apparent effect when it was started earlier rather than as a last resort. The ischaemic events cluster with dose, which argues for a lower ceiling than is sometimes used.',
                'track' => 0,
                'preference' => 'oral',
                'study_type' => 'Original research',
                'ethics' => 'IRB-DEMO-2026-091',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 1,
                'authors' => [
                    ['name' => 'Fatima Al-Marri', 'email' => 'fatima.almarri@example.com', 'affiliation' => 'Gulf Paediatric Institute, Dammam'],
                    ['name' => 'Waleed Al-Ansari', 'email' => 'waleed.alansari@example.com', 'affiliation' => 'Gulf Paediatric Institute, Dammam'],
                ],
            ],
            [
                'title' => 'A sepsis recognition trigger built into routine ward observations: two years of alerts, actions and false positives',
                'abstract' => 'Background: Automated sepsis triggers are easy to build and hard to live with, because a trigger that fires often enough to catch the rare child also fires on many who are not septic. Methods: We embedded an age-adjusted trigger into the electronic observation chart on four general paediatric wards and required a face-to-face medical review within thirty minutes of every alert. Every alert over two years was classified at review and again at seventy-two hours by a reviewer blinded to the initial classification. Results: The trigger fired one thousand four hundred and six times across roughly nineteen thousand admissions. Ninety-one alerts led to a sepsis pathway being started, of which seventy-three were confirmed. The positive predictive value was low, as expected, but the median time from alert to medical review was eleven minutes and no confirmed case was missed by the trigger. Nursing survey responses showed alert fatigue rising in the second year. Conclusion: The trigger performed as designed and the cost is real: roughly fifteen reviews for every pathway started. Whether that is acceptable is a staffing question rather than a statistical one, and it should be answered before a trigger is switched on rather than afterwards.',
                'track' => 0,
                'preference' => 'either',
                'study_type' => 'QI project',
                'ethics' => 'IRB-DEMO-2026-097',
                'state' => self::STATE_SUBMITTED,
                'corresponding' => 0,
                'authors' => [
                    ['name' => 'Yasmin Al-Sayed', 'email' => 'yasmin.alsayed@example.com', 'affiliation' => 'Demo Children\'s Hospital, Riyadh'],
                    ['name' => 'Mohammed Al-Jabri', 'email' => 'mohammed.aljabri@example.com', 'affiliation' => 'Coastal Children\'s Hospital, Muscat'],
                    ['name' => 'Areej Al-Tamimi', 'email' => 'areej.altamimi@example.com', 'affiliation' => 'Harbour Children\'s Hospital, Abu Dhabi'],
                ],
            ],
            [
                'title' => 'Withdrawn: prone positioning duration and oxygenation response in paediatric acute respiratory distress syndrome',
                'abstract' => 'Background: Prone positioning improves oxygenation in many children with acute respiratory distress syndrome, but the duration of each session varies widely between units and is rarely reported. Methods: We planned a retrospective review of every prone session in children meeting the paediatric acute respiratory distress syndrome definition over five years, recording session duration, the oxygenation index before and at the end of each session, and any complication. Sessions were grouped by duration into shorter and longer than twelve hours. Results: Preliminary extraction covered one hundred and thirty-one sessions in forty-four children. Oxygenation improved in the majority of sessions in both groups, and the apparent difference between groups was small and inconsistent across children. Pressure injury was recorded in a small number of longer sessions. Conclusion: This abstract was withdrawn by the authors before review, because a duplicate record was discovered in the source dataset during the audit and the session counts above cannot be relied upon. It is retained in the demonstration data so that the withdrawn state is visible in the organizer panel and on the public status page.',
                'track' => 1,
                'preference' => 'poster',
                'study_type' => 'Original research',
                'ethics' => 'IRB-DEMO-2026-103',
                'state' => self::STATE_WITHDRAWN,
                'corresponding' => 0,
                'authors' => [
                    ['name' => 'Ghada Al-Rumaihi', 'email' => 'ghada.alrumaihi@example.com', 'affiliation' => 'Riverside Paediatric Institute, Doha'],
                    ['name' => 'Sultan Al-Harthy', 'email' => 'sultan.alharthy@example.com', 'affiliation' => 'Coastal Children\'s Hospital, Muscat'],
                ],
            ],
            [
                'title' => 'Draft: early mobilisation after cardiac surgery in infants under six months',
                'abstract' => 'Background: Early mobilisation is established practice in adult critical care and is increasingly attempted in children, but infants after cardiac surgery are frequently excluded from mobilisation protocols on the grounds of haemodynamic fragility. Methods: We are conducting a prospective feasibility study of a staged mobilisation protocol in infants under six months following biventricular repair, beginning with passive positioning on the first postoperative day and progressing to supported sitting. Safety events are defined in advance as any sustained fall in oxygen saturation, any arrhythmia requiring treatment, and any device dislodgement. Results: Recruitment is ongoing and the results section will be completed before the submission deadline. Interim safety review after the first twenty infants has identified no protocol-related event. Conclusion: This abstract is deliberately left as a draft in the demonstration data, so that the draft state, the resume link and the reminder that an unsubmitted abstract is not in the review pool are all visible to anyone looking at the demo conference.',
                'track' => 2,
                'preference' => 'oral',
                'study_type' => 'Original research',
                'ethics' => 'IRB-DEMO-2026-110',
                'state' => self::STATE_DRAFT,
                'corresponding' => 0,
                'authors' => [
                    ['name' => 'Amal Al-Busaidi', 'email' => 'amal.albusaidi@example.com', 'affiliation' => 'Coastal Children\'s Hospital, Muscat'],
                    ['name' => 'Rashid Al-Kuwari', 'email' => 'rashid.alkuwari@example.com', 'affiliation' => 'Riverside Paediatric Institute, Doha'],
                ],
            ],
            [
                'title' => 'Draft: parent-reported sleep disruption in children discharged from paediatric intensive care',
                'abstract' => 'Background: Sleep disruption after paediatric intensive care is commonly described by families and rarely measured, and most published follow-up instruments were designed for adults or for older children. Methods: We are administering a short parent-reported sleep questionnaire at discharge and again at one and three months to families of children who spent at least seventy-two hours in a paediatric intensive care unit. The questionnaire covers sleep onset, night waking, nightmares and daytime sleepiness, and is available in two languages. Results: Data collection is under way and the results section will be completed before the submission deadline. Early returns suggest that night waking is the item families raise most often and that it is not well captured by the existing instruments we reviewed. Conclusion: This abstract is deliberately left as a draft in the demonstration data, alongside one other, so that an organizer can see how drafts appear beside submitted abstracts in the panel and how they are excluded from the reviewer queue and from the ranking.',
                'track' => 2,
                'preference' => 'poster',
                'study_type' => 'Original research',
                'ethics' => 'IRB-DEMO-2026-116',
                'state' => self::STATE_DRAFT,
                'corresponding' => 1,
                'authors' => [
                    ['name' => 'Nadia Al-Khalifa', 'email' => 'nadia.alkhalifa@example.com', 'affiliation' => 'Pearl Paediatric Centre, Manama'],
                    ['name' => 'Talal Al-Mazrouei', 'email' => 'talal.almazrouei@example.com', 'affiliation' => 'Harbour Children\'s Hospital, Abu Dhabi'],
                ],
            ],
        ];
    }
}
