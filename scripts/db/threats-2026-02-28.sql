-- ============================================================
-- New Threats Collection — 2026-02-28
-- 9 new threats from Feb 26-28 (not captured in prior collections)
-- Deduplicated against 263 existing threats in executive_threats
-- ============================================================

INSERT INTO executive_threats
  (threat_date, title, description, threat_type, target,
   source_url, action_script, official_id, is_active, severity_score, branch)
VALUES

-- ============================================================
-- EXECUTIVE — Taxpayer Privacy / Immigration Enforcement (Feb 26)
-- ============================================================

('2026-02-26', 'Judge Rules IRS Illegally Shared Taxpayer Addresses with ICE 42,695 Times',
 'A federal judge ruled that the IRS violated federal law approximately 42,695 times by sharing confidential taxpayer addresses with Immigration and Customs Enforcement. A data-sharing agreement signed by Treasury Secretary Scott Bessent and DHS Secretary Kristi Noem allowed ICE to submit 1.28 million names for cross-verification against IRS tax records. The IRS returned information on 47,000 people, disclosing addresses even when ICE had not provided one — a clear violation of IRS Code 6103, one of the strictest confidentiality laws in federal statute.',
 'strategic', 'Financial Privacy, Taxpayer Protections, Immigrant Communities',
 'https://abcnews.com/US/wireStory/irs-broke-law-disclosing-confidential-information-ice-42695-130539829',
 'Contact your senators on the Finance Committee. Ask: "What are you doing about the IRS illegally sharing 42,695 taxpayer addresses with ICE?" Demand enforcement of IRS Code 6103 protections. Support the ACLU''s challenge to the Bessent-Noem data-sharing agreement.',
 3000, 1, 400, 'executive'),

-- Score: 400 (High Crime)
-- Rationale: 42,695 individual violations of one of the strictest federal privacy
-- laws. Signed by two Cabinet secretaries. Weaponizes the tax system against
-- immigrant communities. Chilling effect on tax filing by undocumented residents.
-- Tags: immigration (5), civil_rights (3), corruption (6)

-- ============================================================
-- EXECUTIVE — Election Integrity (Feb 26)
-- ============================================================

('2026-02-26', 'White House Allies Circulate Draft Executive Order to Declare Election Emergency and Ban Mail-In Voting',
 'Anti-voting activists coordinating with the White House circulated a 17-page draft executive order that would declare a national emergency over elections based on debunked claims of Chinese interference. The draft would ban mail-in ballots, require hand-marked paper ballots counted in public, mandate voter re-registration with proof of citizenship before the 2026 midterms, and give the president extraordinary power over state-administered elections. Constitutional scholars and election officials called it blatantly illegal. Trump denied knowledge of the draft.',
 'strategic', 'Election Integrity, Voting Rights, State Election Authority',
 'https://www.washingtonpost.com/politics/2026/02/26/trump-elections-executive-order-activists/',
 'Contact your senators and representatives immediately. Ask: "Will you publicly oppose any executive order that bans mail-in voting or seizes federal control of state elections?" Contact your state election officials and urge them to defend state authority. Support Democracy Docket and the Brennan Center for Justice.',
 326, 1, 400, 'executive'),

-- Score: 400 (High Crime)
-- Rationale: Draft (not signed) but White House coordination confirmed by multiple
-- outlets. Would ban mail-in voting used by 46% of voters in 2024, override state
-- election authority, require mass re-registration. If enacted, most severe attack
-- on voting rights since Reconstruction. Scored as draft, not enacted action.
-- Tags: election_integrity (8), separation_of_powers (7), first_amendment (12)

-- ============================================================
-- EXECUTIVE — Court Defiance / Rule of Law (Feb 26)
-- ============================================================

('2026-02-26', 'Minnesota Chief Judge Threatens Criminal Contempt After ICE Violates 200+ Federal Court Orders',
 'Chief Judge Patrick Schiltz of Minnesota''s federal district court threatened criminal contempt against U.S. Attorney Daniel Rosen and ICE officials after documenting over 200 court order violations across 143 cases stemming from Operation Metro Surge immigration raids. Schiltz stated this is unprecedented in American history: "The Court is not aware of another occasion in which a federal court has had to threaten contempt — again and again and again — to force the United States government to comply with court orders." A second federal judge scheduled a separate contempt hearing for March 3 over ICE''s refusal to return seized property.',
 'strategic', 'Rule of Law, Judicial Authority, Due Process',
 'https://minnesotareformer.com/2026/02/26/federal-judge-in-minnesota-threatens-trump-administration-officials-with-contempt-over-violated-orders/',
 'Contact your senators on the Judiciary Committee. Ask: "What are you doing about ICE violating over 200 federal court orders in Minnesota?" Demand congressional oversight of immigration enforcement''s systematic defiance of courts. Support the ACLU of Minnesota.',
 3000, 1, 500, 'executive'),

-- Score: 500 (Atrocity)
-- Rationale: 200+ court order violations across 143 cases. Judge says unprecedented
-- in US history. Systematic, not accidental. Two separate judges now threatening
-- contempt. Represents institutional breakdown of rule of law — the executive
-- branch openly defying the judiciary at scale.
-- Tags: judicial_defiance (1), due_process (13), immigration (5)

-- ============================================================
-- EXECUTIVE — War Powers / Iran (Feb 26)
-- ============================================================

('2026-02-26', 'CENTCOM Commander Briefs Trump on Military Strike Options Against Iran',
 'Navy Adm. Brad Cooper, commander of U.S. Central Command, briefed President Trump at the White House on potential military options against Iran, including limited strikes on nuclear sites and ballistic missile launchers as well as broader sustained operations. The briefing occurred while U.S. envoys were simultaneously negotiating with Iran in Geneva. Vice President Vance said there is "no chance" of a drawn-out war. The U.S. has moved an unprecedented number of ships and fighter jets within striking distance of Iran.',
 'tactical', 'Peace, Congressional War Powers, Iran Policy',
 'https://abcnews.com/Politics/top-mideast-commander-briefs-trump-military-options-iran/story?id=130544628',
 'Contact your senators and representatives. Ask: "Has the administration consulted Congress about potential military action against Iran as required by the War Powers Act?" Demand congressional authorization before any strikes. Support Veterans for Peace and diplomacy-first organizations.',
 326, 1, 350, 'executive'),

-- Score: 350 (High Crime)
-- Rationale: Active military planning for strikes on a sovereign nation without
-- congressional authorization. Massive force buildup in region. Simultaneous
-- diplomacy undermined by war planning. No War Powers Act consultation with
-- Congress. Scored lower than actual strike would be since no action taken yet.
-- Tags: war_powers (4), foreign_policy (9), separation_of_powers (7)

-- ============================================================
-- EXECUTIVE — First Amendment / Protest Suppression (Feb 27)
-- ============================================================

('2026-02-27', 'DOJ Indicts 30 More Protesters for Anti-ICE Demonstration at Minnesota Church, 39 Total Charged',
 'Attorney General Pam Bondi announced federal indictments against 30 more people who participated in an anti-ICE protest at Cities Church in St. Paul, Minnesota on January 18, bringing total charges to 39. All are charged with conspiracy against religious freedom — using civil rights law to prosecute protest. Those previously charged include independent journalist Don Lemon and activist Nekima Levy Armstrong, whose arrest photo was doctored by the White House. Twenty-five of the newly indicted have already been arrested.',
 'tactical', 'First Amendment, Freedom of Assembly, Right to Protest',
 'https://www.pbs.org/newshour/politics/30-more-people-indicted-over-anti-ice-protest-at-minnesota-church-bondi-says',
 'Contact your representatives. Ask: "Do you support charging 39 people with federal civil rights crimes for protesting at a church?" Demand oversight of DOJ''s use of civil rights statutes to suppress protest. Support the ACLU''s defense of the protesters.',
 9390, 1, 400, 'executive'),

-- Score: 400 (High Crime)
-- Rationale: Weaponizing civil rights law (conspiracy against religious freedom)
-- to prosecute political protest. 39 people charged for exercising First Amendment
-- rights. Includes journalists. White House doctored arrest photo for propaganda.
-- Pattern of using federal power to suppress dissent against immigration policy.
-- Tags: first_amendment (12), civil_rights (3), due_process (13)

-- ============================================================
-- EXECUTIVE — Foreign Policy / Cuba (Feb 27)
-- ============================================================

('2026-02-27', 'Trump Floats "Friendly Takeover" of Cuba Amid U.S. Oil Embargo Driving Island to Collapse',
 'President Trump publicly raised the possibility of a "friendly takeover of Cuba," stating the island nation''s government is "talking with us" and "in a big deal of trouble." The statement came as the administration''s oil embargo on Cuba — imposed after ousting Havana''s key regional backer — has pushed the island to the brink of economic collapse. Secretary of State Rubio is leading negotiations. Trump suggested military action may not be necessary because Cuba''s economy is weak enough to collapse on its own.',
 'tactical', 'Cuban Sovereignty, International Law, Foreign Policy',
 'https://www.pbs.org/newshour/politics/trump-suggests-u-s-could-have-friendly-takeover-of-cuba',
 'Contact your representatives on the Foreign Affairs Committee. Ask: "Does the administration have legal authority to pursue a ''takeover'' of a sovereign nation?" Demand transparency on Cuba negotiations and respect for international law.',
 326, 1, 300, 'executive'),

-- Score: 300 (Serious Felony / High Crime threshold)
-- Rationale: Publicly discussing "takeover" of a sovereign nation. Oil embargo
-- deliberately driving economic collapse. Regime change rhetoric. However, largely
-- rhetorical at this point with no concrete military or legal action.
-- Tags: foreign_policy (9), war_powers (4)

-- ============================================================
-- EXECUTIVE — Immigration Courts / Due Process (Feb 27)
-- ============================================================

('2026-02-27', 'DOJ Fires 20 Immigration Judges, Adding Years to 3.7 Million Case Backlog',
 'The Department of Justice fired 20 immigration judges from the Executive Office for Immigration Review, part of DOGE-linked federal workforce reductions. The firings come as immigration courts face a backlog of 3.7 million cases. Fired judges report that some asylum hearings are now postponed until 2028. The U.S. has lost approximately 25% of its immigration judges in the past year. The cuts undermine the administration''s own stated goal of faster deportation processing.',
 'tactical', 'Immigration Courts, Due Process, Access to Justice',
 'https://www.newsweek.com/doge-federal-cuts-elon-musk-immigration-cases-delay-2037180',
 'Contact your senators on the Judiciary Committee. Ask: "How does firing 20 immigration judges help clear a 3.7 million case backlog?" Demand emergency funding for immigration courts and restoration of fired judges. Support the National Association of Immigration Judges.',
 9390, 1, 350, 'executive'),

-- Score: 350 (High Crime)
-- Rationale: 20 judges fired with 3.7M case backlog. Due process denied to
-- millions. Hearings pushed to 2028. 25% of immigration judges lost in one year.
-- Undermines both due process AND the administration's own deportation goals.
-- Tags: federal_workforce (11), due_process (13), immigration (5)

-- ============================================================
-- EXECUTIVE — Democratic Norms / Rule of Law (Feb 27)
-- ============================================================

('2026-02-27', 'Kennedy Center Board Adds Trump Name Above Kennedy''s in Violation of Federal Law Requiring Act of Congress',
 'The Kennedy Center''s board of trustees, handpicked by Trump, voted to add the president''s name above John F. Kennedy''s on the exterior of the building and renamed the annual awards the "Trump Kennedy Center Honors." Federal law explicitly prohibits renaming the building or adding additional memorials without an act of Congress. Representative Joyce Beatty filed suit arguing only Congress has this authority. Interim president Richard Grenell announced the changes alongside plans to close the center for two years starting July 4, 2026.',
 'tactical', 'Democratic Norms, Cultural Institutions, Rule of Law',
 'https://www.washingtonpost.com/style/2026/02/27/kennedy-center-honors-name/',
 'Contact your representatives. Ask: "Federal law says only Congress can rename the Kennedy Center — why is the board ignoring that law?" Support Rep. Beatty''s legal challenge. Demand the board reverse its decision until Congress acts.',
 326, 1, 50, 'executive'),

-- Score: 50 (Misdemeanor)
-- Rationale: Violates federal law requiring act of Congress to rename. Part of
-- a pattern of ignoring legal constraints. But direct harm to citizens is minimal
-- compared to other threats. Cult of personality concern, not immediate danger.
-- Tags: corruption (6), separation_of_powers (7)

-- ============================================================
-- EXECUTIVE — WAR / Iran (Feb 28) *** BREAKING ***
-- ============================================================

('2026-02-28', 'U.S. and Israel Launch "Operation Epic Fury" Against Iran Without Congressional Authorization, Trump Calls for Regime Overthrow',
 'President Trump announced "major combat operations" against Iran on February 28 in a joint U.S.-Israeli operation codenamed "Epic Fury." The U.S. military is conducting multi-day strikes targeting nuclear sites, missile launchers, air defense systems, and leadership compounds in Tehran. Trump explicitly called for regime change, urging Iranians to "take over your government." An Israeli strike hit a girls'' elementary school in Minab, killing at least 40-53 students. Iran retaliated with missiles and drones against U.S. bases in the UAE, Qatar, Kuwait, Bahrain, and Jordan. Trump warned American lives "may be lost." Congress was not consulted or asked to authorize the strikes. Multiple countries closed airspace. Bipartisan lawmakers condemned the lack of War Powers Act compliance.',
 'strategic', 'Peace, Congressional War Powers, Civilian Lives, International Law, Nuclear Non-Proliferation',
 'https://www.npr.org/2026/02/28/nx-s1-5730158/israel-iran-strikes-trump-us',
 'Contact your senators and representatives IMMEDIATELY. Demand they vote YES on the Kaine-Schumer-Schiff War Powers Resolution to halt unauthorized military action. Ask: "Did the president consult Congress before launching war on Iran? Will you vote to stop this?" Call the Capitol switchboard at (202) 224-3121. Support Veterans for Peace, Win Without War, and the ACLU''s legal challenges to unauthorized military action.',
 326, 1, 800, 'executive');

-- Score: 800 (Crime Against Humanity)
-- Rationale: Unauthorized war launched without congressional authorization in
-- violation of the War Powers Act and Constitution. Multi-day military campaign
-- against a sovereign nation. Explicit call for regime change. At least 40-53
-- children killed in school strike. Iran retaliating against U.S. bases across
-- 5 countries — Americans in harm's way. Regional war triggered with 8+ countries
-- closing airspace. Trump himself warned American lives "may be lost."
-- This is the most severe threat in the entire collection.
-- Tags: war_powers (4), foreign_policy (9), civil_rights (3), separation_of_powers (7)
