-- ============================================================
-- First Judicial Branch Threat Collection — 2026-02-26
-- Backlog: Jan 2024 – Feb 2026 (~25 months)
-- 15 threats: SCOTUS ethics crisis + lower court enabling
-- ============================================================

INSERT INTO executive_threats
  (threat_date, title, description, threat_type, target,
   source_url, action_script, official_id, is_active, branch)
VALUES

-- ============================================================
-- THOMAS — Ethics Violations & Conflicts of Interest
-- ============================================================

('2024-06-07', 'Thomas Acknowledges He Should Have Disclosed Harlan Crow Luxury Trips, Amends Financial Disclosures',
 'Justice Clarence Thomas filed amended financial disclosures acknowledging he "inadvertently omitted" two 2019 trips paid for by billionaire Harlan Crow — a trip to Indonesia and one to Bohemian Grove. Even after the amendments, Senate Judiciary Committee investigators found at least three additional undisclosed private jet trips from Crow in 2017, 2019, and 2021. Thomas has accepted an estimated $4.2 million in gifts from Crow over two decades, including luxury travel, private jet flights, yacht trips, a $267,000 real estate deal, and private school tuition.',
 'strategic', 'Judicial Ethics, Financial Disclosure Law, Supreme Court Integrity',
 'https://www.propublica.org/article/clarence-thomas-gift-disclosures-harlan-crow',
 'Contact your senators on the Judiciary Committee. Ask: "Will you support enforceable ethics rules for the Supreme Court?" Support Fix the Court and CREW campaigns for judicial ethics reform.',
 328, 1, 'judicial'),

('2024-04-25', 'Thomas Refuses to Recuse from January 6 Cases Despite Wife''s Direct Role in Overturning 2020 Election',
 'Justice Thomas again chose not to recuse himself from January 6-related cases before the Supreme Court, despite his wife Ginni Thomas''s documented efforts to overturn the 2020 election — including 29 text messages to White House Chief of Staff Mark Meadows urging him to keep fighting, attendance at the January 6 rally, and advocacy with state legislators to appoint alternate electors. Thomas was the lone dissenter when the Court allowed records containing his wife''s texts to be released to the January 6 Committee.',
 'strategic', 'Judicial Impartiality, Supreme Court Ethics, January 6 Accountability',
 'https://www.cnn.com/2024/04/25/politics/clarence-thomas-january-6-case',
 'Contact your senators on the Judiciary Committee. Ask: "Why is Justice Thomas allowed to rule on cases where his wife was a participant?" Demand enforceable recusal standards for Supreme Court justices.',
 328, 1, 'judicial'),

('2024-07-01', 'Thomas Concurrence Questions Special Counsel Legitimacy, Providing Roadmap to Dismiss Trump Cases',
 'In the Trump v. United States immunity ruling, Justice Thomas wrote a separate concurrence questioning whether Special Counsel Jack Smith was constitutionally appointed — arguing no office for special counsel has been "established by law" by Congress. This concurrence provided the legal roadmap that Judge Aileen Cannon used two weeks later to dismiss the Trump classified documents case entirely. Legal scholars called it an advisory opinion with no basis in the case before the Court.',
 'strategic', 'Special Counsel Independence, Rule of Law, Accountability Mechanisms',
 'https://www.supremecourt.gov/opinions/23pdf/23-939_e2pg.pdf',
 'Contact your representatives. Ask: "Will you pass legislation formally establishing the special counsel office to prevent this constitutional argument from being used again?" Support Protect Democracy and other rule-of-law organizations.',
 328, 1, 'judicial'),

('2026-02-20', 'Thomas Dissents in Tariff Ruling, Would Have Upheld Sweeping Unilateral Presidential Trade Power',
 'Justice Thomas dissented from the 6-3 ruling striking down Trump''s IEEPA tariffs, arguing the president had broad constitutional authority to impose tariffs without congressional approval. Thomas''s position would have allowed any president to unilaterally reshape the entire U.S. trade system by declaring economic emergencies — a massive transfer of congressional power to the executive branch.',
 'tactical', 'Separation of Powers, Congressional Trade Authority',
 'https://www.foxnews.com/politics/thomas-rips-supreme-court-tariffs-ruling-says-majority-errs-constitution',
 'Contact your senators. Ask: "Do you believe the president should have unilateral power to impose tariffs without Congress?" Support efforts to reassert congressional trade authority.',
 328, 1, 'judicial'),

-- ============================================================
-- ALITO — Partisan Signaling, Ethics, Conflicts
-- ============================================================

('2024-05-16', 'Alito Flew Inverted American Flag (Stop the Steal Symbol) at Home, Then Refused to Recuse from Jan 6 Cases',
 'The New York Times reported that an upside-down American flag — a symbol adopted by "Stop the Steal" election deniers and carried by January 6 insurrectionists — flew at Justice Alito''s Virginia home in January 2021, days after the Capitol attack. A second report revealed an "Appeal to Heaven" flag (a Christian nationalist symbol also carried at the Capitol) at his New Jersey beach house. Despite calls from Democratic senators and ethics experts, Alito refused to recuse from any January 6 or Trump-related cases, blaming his wife for the flags.',
 'strategic', 'Judicial Impartiality, January 6 Accountability, Supreme Court Ethics',
 'https://www.axios.com/2024/05/29/samuel-alito-scotus-jan-6-flag-recusal',
 'Contact your senators on the Judiciary Committee. Ask: "How can Justice Alito claim impartiality in January 6 cases when Stop the Steal symbols flew at his home?" Demand enforceable recusal standards.',
 329, 1, 'judicial'),

('2024-06-03', 'Alito Secretly Recorded Agreeing U.S. Needs to Return to "Godliness," Can''t Negotiate with the Left',
 'Filmmaker Lauren Windsor secretly recorded Justice Alito at the Supreme Court Historical Society dinner agreeing that America needs to "return to a place of godliness" and that conservatives are "probably right" that they cannot negotiate with the political left and must focus on "winning." Ethics experts called the comments a clear violation of judicial norms requiring impartiality. In the same recording, Alito''s wife vowed revenge over the flag controversy. Chief Justice Roberts, recorded at the same event, pushed back on similar suggestions.',
 'tactical', 'Judicial Impartiality, Separation of Church and State, Democratic Norms',
 'https://abcnews.go.com/Politics/justice-alito-secretly-recorded-audio-apparently-agrees-nation/story?id=111014360',
 'Contact your senators. Ask: "Should a Supreme Court justice be openly aligning with one political faction and agreeing they must ''win'' against the other?" Support campaigns for Supreme Court term limits and enforceable ethics.',
 329, 1, 'judicial'),

('2024-06-13', 'Alito Failed to Disclose $100K+ Paul Singer Luxury Trip, Then Ruled on Singer''s Cases at Least 10 Times',
 'ProPublica revealed Justice Alito took a luxury fishing trip to Alaska in 2008 on a private jet provided by hedge fund billionaire Paul Singer — a trip worth over $100,000 one way. Alito never disclosed the trip, citing the "personal hospitality" exemption. He then participated in at least 10 cases where Singer had financial interests before the Court, without recusing. The Senate Judiciary Committee''s December 2024 report found this exemption was misapplied and constitutes a violation of federal disclosure law.',
 'strategic', 'Judicial Ethics, Financial Conflicts of Interest, Supreme Court Integrity',
 'https://www.propublica.org/article/samuel-alito-luxury-fishing-trip-paul-singer-scotus-supreme-court',
 'Contact your senators on the Judiciary Committee. Ask: "Why was Justice Alito allowed to rule on cases involving a billionaire who gave him $100,000+ in luxury travel?" Support the Supreme Court Ethics Act.',
 329, 1, 'judicial'),

('2026-02-20', 'Alito Dissents in Tariff Ruling, Would Have Upheld Sweeping Unilateral Presidential Trade Power',
 'Justice Alito joined Thomas and Kavanaugh in dissenting from the 6-3 ruling striking down Trump''s IEEPA tariffs. Alito''s position would have allowed the president to unilaterally impose sweeping tariffs by declaring economic emergencies, bypassing Congress''s constitutional authority over trade and taxation.',
 'tactical', 'Separation of Powers, Congressional Trade Authority',
 'https://www.scotusblog.com/2026/02/supreme-court-strikes-down-tariffs/',
 'Contact your senators. Ask: "Do you believe the president should have unilateral power to impose tariffs without Congress?" Support legislation reasserting congressional trade authority.',
 329, 1, 'judicial'),

-- ============================================================
-- KAVANAUGH — Enabling Executive Overreach
-- ============================================================

('2026-02-20', 'Kavanaugh Dissents in Tariff Ruling, Argues Courts Should Not Check Presidential Tariff Power',
 'Justice Kavanaugh wrote the primary dissent in the 6-3 IEEPA tariff ruling, arguing tariffs "are a traditional and common tool to regulate importation" and that the Court drew "illogical" lines. Kavanaugh argued such debates "are not for the Federal Judiciary to resolve" — a position that would strip courts of their role in checking executive trade authority. Trump praised Kavanaugh for the dissent, saying it made him "so proud."',
 'tactical', 'Separation of Powers, Judicial Review, Congressional Trade Authority',
 'https://www.foxnews.com/politics/kavanaugh-says-court-drew-illogical-line-tariffs-argues-ieepa-plainly-covers-import-duties',
 'Contact your senators. Ask: "Should courts refuse to check presidential power over trade, as Justice Kavanaugh argues?" Support efforts to reassert congressional authority over tariffs.',
 333, 1, 'judicial'),

-- ============================================================
-- ROBERTS / INSTITUTIONAL — Immunity, Ethics Code, Shadow Docket
-- ============================================================

('2024-07-01', 'Supreme Court Grants Broad Presidential Immunity from Criminal Prosecution in 6-3 Ruling',
 'Chief Justice Roberts authored the 6-3 ruling in Trump v. United States granting former presidents absolute immunity for official acts within their "core constitutional authority" and presumptive immunity for acts on the "outer perimeter" of their duties. Justice Sotomayor''s dissent warned the ruling makes the president "a king above the law." The decision effectively ended the federal January 6 prosecution of Trump and established sweeping new protections for presidential misconduct.',
 'strategic', 'Rule of Law, Presidential Accountability, Constitutional Checks and Balances',
 'https://www.supremecourt.gov/opinions/23pdf/23-939_e2pg.pdf',
 'Contact your representatives. Ask: "Will you support a constitutional amendment clarifying that no president is above the law?" Support Protect Democracy and constitutional reform organizations.',
 349, 1, 'judicial'),

('2025-07-14', 'SCOTUS Uses Shadow Docket to Rapidly Expand Executive Power, 47 of 65 Judges Surveyed Disagree with Practice',
 'The Supreme Court''s emergency "shadow docket" has been used since January 2025 to rapidly expand executive power without full briefing or oral argument. Over 100 emergency matters considered this term include allowing mass deportations, permitting Department of Education dismantling, and blocking lower court injunctions against administration actions. Justice Kagan wrote in dissent: "Our emergency docket should never be used to permit what our own precedent bars." A New York Times survey found 47 of 65 federal judges disagreed with the Court''s shadow docket practices.',
 'strategic', 'Judicial Process, Transparency, Executive Power, Separation of Powers',
 'https://www.scotusblog.com/2025/09/supreme-court-behavior-on-the-shadow-docket/',
 'Contact your senators on the Judiciary Committee. Ask: "Are you concerned that the Supreme Court is making major decisions on its shadow docket without full argument or transparency?" Support the Shadow Docket Sunshine Act.',
 349, 1, 'judicial'),

('2025-06-27', 'SCOTUS Eliminates Universal Injunctions, Removing Key Check on Executive Overreach',
 'In Trump v. CASA (6-3), Justice Barrett''s majority held that federal courts cannot issue "universal injunctions" blocking government policies for non-parties to a case. The ruling strips lower courts of their most powerful tool for checking unconstitutional executive actions — policies can now be enforced against everyone except the specific plaintiffs who sued. Justice Kagan dissented, warning the ruling "dramatically limits courts'' ability to stop even the most flagrantly illegal executive branch conduct."',
 'strategic', 'Judicial Review, Separation of Powers, Federal Court Authority',
 'https://www.sidley.com/en/insights/newsupdates/2025/07/supreme-court-substantially-limits-universal-injunctions',
 'Contact your senators. Ask: "How will federal courts check executive overreach now that universal injunctions have been eliminated?" Support legislation restoring federal courts'' equitable remedial authority.',
 349, 1, 'judicial'),

('2024-12-21', 'Senate Report Finds Thomas and Alito Violated Federal Disclosure Law, No Enforcement Mechanism Exists',
 'The Senate Judiciary Committee released a 93-page report concluding that Justices Thomas and Alito violated federal financial disclosure laws through years of unreported luxury gifts from billionaire donors. The report documented Thomas''s $4.2M+ in Crow gifts and Alito''s misuse of the "personal hospitality" exemption for the Singer fishing trip. Despite these findings, no enforcement mechanism exists — the SCOTUS ethics code adopted in November 2023 has no investigator, no arbiter, and no penalties. Justice Gorsuch was "especially vocal" in opposing any enforcement mechanism.',
 'strategic', 'Judicial Ethics, Financial Disclosure Law, Supreme Court Accountability',
 'https://www.judiciary.senate.gov/press/releases/senate-judiciary-committee-releases-revealing-investigative-report-on-ethical-crisis-at-the-supreme-court',
 'Contact your senators. Ask: "The Senate found two justices violated federal law — what consequences will there be?" Demand passage of the Supreme Court Ethics Act with binding enforcement.',
 349, 1, 'judicial'),

-- ============================================================
-- LOWER COURTS — Enabling, Judge Shopping, Obstruction
-- ============================================================

('2024-07-15', 'Judge Cannon Dismisses Trump Classified Documents Case Using Thomas''s Concurrence as Roadmap',
 'Judge Aileen Cannon dismissed the federal classified documents case against Trump, ruling that Special Counsel Jack Smith was unconstitutionally appointed — adopting the theory Justice Thomas outlined in his July 1 concurrence just two weeks earlier. The ruling contradicted decades of precedent supporting special counsel appointments (Morrison v. Olson). The DOJ appealed, but dropped the appeal after Trump won the 2024 election. Cannon, a Trump appointee, had previously been reversed by the 11th Circuit for improperly intervening in the case.',
 'tactical', 'Special Counsel Independence, Rule of Law, Presidential Accountability',
 'https://www.justsecurity.org/97747/trump-docs-case-dismissed/',
 'Contact your representatives. Ask: "Will you support legislation formally establishing the special counsel office to prevent this ruling from being used as precedent?" Support accountability organizations challenging the dismissal.',
 10968, 1, 'judicial'),

('2026-02-23', 'Judge Cannon Permanently Blocks Release of Jack Smith''s Final Report on Trump Classified Documents',
 'Judge Cannon permanently blocked the public release of Volume II of Special Counsel Jack Smith''s final report on the classified documents investigation, granting requests from Trump and co-defendants. Cannon cited her own prior ruling that Smith was unlawfully appointed. Government watchdog groups American Oversight and the Knight Institute called the ruling a denial of information of "extraordinary national importance." The report detailing Trump''s alleged mishandling of classified documents may never be made public.',
 'tactical', 'Public Accountability, Transparency, Rule of Law',
 'https://www.nbcnews.com/politics/justice-department/trump-appointee-aileen-cannon-blocks-release-jack-smiths-report-classi-rcna260237',
 'Contact your representatives on the Judiciary Committee. Ask: "Should a Trump-appointed judge be allowed to permanently suppress the investigation report into Trump?" Support American Oversight and Knight Institute FOIA efforts.',
 10968, 1, 'judicial');
