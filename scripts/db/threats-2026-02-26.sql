-- ============================================================
-- New Threats Collection — 2026-02-26
-- 9 new threats from Feb 25-26 (State of the Union + aftermath)
-- Deduplicated against 227 existing threats in executive_threats
-- ============================================================

INSERT INTO executive_threats
  (threat_date, title, description, threat_type, target,
   source_url, action_script, official_id, is_active, branch)
VALUES

-- ============================================================
-- EXECUTIVE — Medicaid / Healthcare Targeting
-- ============================================================

('2026-02-25', 'Vance Announces $259M Medicaid Freeze on Minnesota, Senior Treasury Official Quits in Protest',
 'Vice President JD Vance announced the administration is withholding $259.5 million in Medicaid funding from Minnesota to "turn the screws" on the Democratic-led state, citing fraud within the Somali immigrant community. CMS Administrator Mehmet Oz formalized the freeze. John Hurley, Treasury Undersecretary for Terrorism and Financial Intelligence, resigned after privately objecting to the administration''s targeting of the Somali community. The crackdown signals potential expansion to other Democratic-led states.',
 'tactical', 'Healthcare Access, Federalism, Somali Community',
 'https://www.nbcnews.com/politics/jd-vance/vance-trump-war-fraud-suspending-medicaid-payments-minnesota-rcna260655',
 'Call your senators. Ask: "Do you support withholding Medicaid from entire states as political punishment?" Contact Minnesota''s congressional delegation to demand restoration of funding. Support the National Health Law Program.',
 9112, 1, 'executive'),

-- ============================================================
-- EXECUTIVE — Epstein Cover-Up
-- ============================================================

('2026-02-25', 'DOJ Withheld 50+ Pages of Epstein Files Containing Sexual Abuse Allegations Against Trump',
 'NPR investigation revealed the Justice Department withheld or removed more than 50 pages of FBI interview records from the Epstein files release — specifically three summaries of 2019 FBI interviews with a woman who accused Trump of sexual assault. Only her accusations against Epstein were published; allegations against Trump were suppressed. Both Republican and Democratic members of Congress announced investigations into the withholding.',
 'strategic', 'DOJ Independence, Accountability, Epstein Investigation',
 'https://www.npr.org/2026/02/24/nx-s1-5723968/epstein-files-trump-accusation-maxwell',
 'Contact your representatives on the House Oversight Committee. Ask: "Why were Epstein files mentioning Trump withheld from public release?" Demand the DOJ release all legally required documents. Support bipartisan oversight efforts.',
 9390, 1, 'executive'),

-- ============================================================
-- EXECUTIVE — FBI Retaliation
-- ============================================================

('2026-02-25', 'FBI Fires Approximately 10 Employees Who Investigated Trump Classified Documents Case',
 'The FBI dismissed approximately 10 employees who worked on the investigation into classified documents found at Mar-a-Lago. The firings represent a continued pattern of retaliatory action against investigators and prosecutors who pursued cases involving Trump. Former FBI officials described the dismissals as a chilling message to career law enforcement.',
 'tactical', 'FBI Independence, Rule of Law',
 'https://www.justsecurity.org/132599/early-edition-february-26-2026/',
 'Call your senators on the Judiciary Committee. Ask: "What are you doing to protect FBI agents from political retaliation?" Support the FBI Agents Association and demand congressional hearings on politicized firings.',
 9398, 1, 'executive'),

-- ============================================================
-- EXECUTIVE — Intelligence / Oversight Obstruction
-- ============================================================

('2026-02-25', 'Administration Invokes Executive Privilege to Block Whistleblower Complaint Against DNI Gabbard',
 'The Trump administration refused to share classified intelligence behind a whistleblower complaint against Director of National Intelligence Tulsi Gabbard, citing executive privilege. Congressional intelligence committee members from both parties demanded access. The complaint reportedly involves concerns about Gabbard''s handling of classified information and contacts with foreign officials.',
 'tactical', 'Congressional Oversight, Intelligence Community Integrity',
 'https://www.justsecurity.org/132599/early-edition-february-26-2026/',
 'Contact your senators on the Intelligence Committee. Ask: "Why is the administration blocking congressional oversight of a whistleblower complaint about the DNI?" Demand compliance with whistleblower protection laws.',
 326, 1, 'executive'),

-- ============================================================
-- EXECUTIVE — Military / Congressional Speech
-- ============================================================

('2026-02-25', 'Hegseth Appeals to Overturn Protection of Senator Who Encouraged Military to Resist Unlawful Orders',
 'Defense Secretary Pete Hegseth is appealing a federal judge''s order that blocked the Pentagon from punishing Senator Mark Kelly (D-AZ) for participating in a video encouraging military personnel to resist unlawful orders. Hegseth''s appeal seeks to establish that the Defense Secretary can retaliate against sitting senators for speech about military conduct, a direct threat to both congressional prerogatives and military adherence to lawful command.',
 'tactical', 'Military Independence, Congressional Speech, Separation of Powers',
 'https://www.justsecurity.org/132475/early-edition-february-25-2026/',
 'Call your senators. Ask: "Do you support the Defense Secretary punishing senators for encouraging troops to follow the law?" Contact the Senate Armed Services Committee to demand oversight of Hegseth''s retaliatory actions.',
 9402, 1, 'executive'),

-- ============================================================
-- EXECUTIVE — Financial Surveillance
-- ============================================================

('2026-02-25', 'Administration Weighs Executive Order Requiring Banks to Collect Customer Citizenship Data',
 'The Trump administration is considering an executive order that would require banks to collect citizenship information from all customers. Banks have lobbied the Treasury Department and questioned the legal basis for the proposal. Civil liberties groups warn this would create a financial surveillance system targeting immigrant communities, potentially driving undocumented residents out of the banking system and into exploitative cash-only arrangements.',
 'tactical', 'Financial Privacy, Immigrant Communities, Banking Access',
 'https://www.justsecurity.org/132475/early-edition-february-25-2026/',
 'Contact your representatives on the Financial Services Committee. Ask: "Do you support requiring banks to collect citizenship data from every customer?" Support the ACLU and National Immigration Law Center challenges to financial surveillance.',
 326, 1, 'executive'),

-- ============================================================
-- EXECUTIVE — DOJ / Federalism
-- ============================================================

('2026-02-25', 'DOJ Sues New Jersey to Overturn Governor''s Limits on ICE Enforcement on State Property',
 'The Justice Department filed suit to overturn New Jersey Governor Mikie Sherill''s executive order limiting ICE agents from operating on state-owned property, citing federal supremacy. The lawsuit is part of a broader pattern of the administration using federal litigation to override state protections for immigrant communities. New Jersey joins a growing list of states facing DOJ suits over sanctuary-type policies.',
 'tactical', 'State Sovereignty, Federalism, Immigration Enforcement',
 'https://www.justsecurity.org/132475/early-edition-february-25-2026/',
 'Contact your state representatives and governor. Ask: "What is our state doing to protect residents from federal overreach on immigration enforcement?" Support the Vera Institute of Justice and state-level legal defense funds.',
 9390, 1, 'executive'),

-- ============================================================
-- EXECUTIVE — DOGE / Federal Courts
-- ============================================================

('2026-02-26', 'DOGE Staff Cuts Leave Federal Courthouses Unmanned, Courts Director Pleads for Congressional Help',
 'The Director of the Administrative Office of U.S. Courts urged Congress to grant courts the power to build and operate their own facilities after GSA staff cuts left federal courthouses unmanned, creating safety risks for judges, staff, and the public. The cuts are a direct consequence of DOGE-driven federal workforce reductions. In a related case, a federal judge dismissed a gun charge after prosecutors violated speedy trial rights due to staff shortages caused by the immigration case surge.',
 'tactical', 'Federal Court System, Access to Justice, Judicial Safety',
 'https://www.justsecurity.org/132599/early-edition-february-26-2026/',
 'Contact your representatives on the Judiciary Committee. Ask: "Are you aware that DOGE cuts are leaving federal courthouses unstaffed and unsafe?" Demand emergency funding for court security and operations.',
 9395, 1, 'executive'),

-- ============================================================
-- EXECUTIVE — State Department / Extremism
-- ============================================================

('2026-02-26', 'State Department Official Hosts Far-Right Activist Tommy Robinson, Praises Him as "Free Speech Warrior"',
 'A State Department official met with British far-right activist Tommy Robinson and publicly praised him as a "free speech warrior." Robinson has been convicted of multiple offenses in the UK including contempt of court, fraud, and assault, and is widely regarded as an anti-Muslim extremist. The meeting signals State Department normalization of far-right figures and contradicts longstanding U.S. anti-extremism policy.',
 'tactical', 'Democratic Norms, Anti-Extremism Standards, Diplomatic Credibility',
 'https://www.justsecurity.org/132599/early-edition-february-26-2026/',
 'Contact the State Department and your senators on the Foreign Relations Committee. Ask: "Why is the State Department hosting convicted extremists and calling them free speech warriors?" Demand accountability for normalizing far-right figures.',
 9408, 1, 'executive');
