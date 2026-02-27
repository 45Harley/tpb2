-- ============================================================
-- First Congressional Branch Threat Collection — 2026-02-26
-- Backlog: Jan 20, 2025 – Feb 2026 (~13 months)
-- 11 threats: enabling overreach, blocking oversight, corruption
-- ============================================================

INSERT INTO executive_threats
  (threat_date, title, description, threat_type, target,
   source_url, action_script, official_id, is_active, branch)
VALUES

-- ============================================================
-- HOUSE — Blocking Oversight of DOGE and Executive Branch
-- ============================================================

('2025-02-05', 'House Oversight Republicans Block Subpoena of Musk Over DOGE Access to Government Data',
 'House Oversight Committee Republicans voted along party lines to table a Democratic motion to subpoena Elon Musk to testify about DOGE''s access to federal databases containing Social Security numbers, medical histories, and financial records of millions of Americans. Committee Chair James Comer argued Musk "answers to the president." Democrats attempted the subpoena at least twice more, with each blocked on party-line votes.',
 'tactical', 'Congressional Oversight, Privacy, Government Accountability',
 'https://abcnews.go.com/Politics/republicans-block-musk-congressional-subpoena-doge-continues-access/story?id=118487749',
 'Contact your representative, especially if they serve on the Oversight Committee. Ask: "Why did you vote to protect Musk from testifying about accessing Americans'' private data?" Demand hearings on DOGE''s access to federal databases.',
 568, 1, 'congressional'),

-- ============================================================
-- SENATE — Confirming Unqualified Nominees
-- ============================================================

('2025-01-24', 'Senate Confirms Hegseth as Defense Secretary 50-50 Despite Sexual Assault Allegations and Misconduct',
 'The Senate confirmed Pete Hegseth as Secretary of Defense on a 50-50 tie-breaking vote by Vice President Vance, despite allegations of sexual assault, public drunkenness, financial mismanagement of veterans'' charities, and bipartisan concerns about his lack of qualifications. Only three Republicans — Collins, Murkowski, and McConnell — voted against. Senator Chris Murphy called Hegseth "dangerously and woefully unqualified" to lead the world''s largest military.',
 'tactical', 'Military Readiness, National Security, Government Competence',
 'https://www.npr.org/2025/01/24/nx-s1-5272854/trump-cabinet-picks-pete-hegseth-senate-confirmation-vote',
 'Contact your senators. Ask: "Did you vote to confirm a Defense Secretary accused of sexual assault with no senior military or government experience? Why?" Hold senators accountable for enabling unqualified nominees.',
 473, 1, 'congressional'),

('2025-02-14', 'Senate Confirms RFK Jr. as HHS Secretary Despite Anti-Vaccine Record and Pandemic Misinformation',
 'The Senate confirmed Robert F. Kennedy Jr. as Secretary of Health and Human Services 52-48, despite his decades-long anti-vaccine advocacy, promotion of debunked claims linking vaccines to autism, and spreading of COVID-19 misinformation. Kennedy''s anti-vaccine organization, Children''s Health Defense, has been linked to declining vaccination rates. Only Mitch McConnell joined Democrats in opposing.',
 'tactical', 'Public Health, Scientific Integrity, Pandemic Preparedness',
 'https://cronkitenews.azpbs.org/2025/02/14/rfk-tulsi-gabbard-confirmation-kelly-gallego-losing-streak-trump-cabinet-nominees/',
 'Contact your senators. Ask: "Did you vote to put an anti-vaccine activist in charge of public health for 330 million Americans?" Demand oversight of HHS policy changes affecting vaccination programs.',
 473, 1, 'congressional'),

('2025-02-14', 'Senate Confirms Gabbard as DNI Despite Bipartisan National Security Concerns',
 'The Senate confirmed Tulsi Gabbard as Director of National Intelligence despite bipartisan concerns about her lack of intelligence experience, her 2017 meeting with Syrian dictator Bashar al-Assad, and her repeated parroting of Russian talking points on Ukraine. The confirmation placed a nominee with documented sympathies toward U.S. adversaries in charge of coordinating all 18 intelligence agencies.',
 'tactical', 'Intelligence Community, National Security, Foreign Influence',
 'https://cronkitenews.azpbs.org/2025/02/14/rfk-tulsi-gabbard-confirmation-kelly-gallego-losing-streak-trump-cabinet-nominees/',
 'Contact your senators. Ask: "Did you vote to put someone who met with Assad and echoed Russian propaganda in charge of U.S. intelligence?" Demand oversight of intelligence community operations under Gabbard.',
 473, 1, 'congressional'),

-- ============================================================
-- HOUSE — Enabling Executive Overreach via Legislation
-- ============================================================

('2025-02-25', 'House Passes Budget Resolution Enabling $4T "Big Beautiful Bill" with Massive Safety Net Cuts',
 'The House passed a budget resolution 217-215 that became the framework for Trump''s "One Big, Beautiful Bill Act" — a nearly $4 trillion package that slashed Medicaid by $600 billion (potentially stripping coverage from 10.9 million people), cut SNAP benefits for 22.3 million families, imposed first-ever Medicaid work requirements, and allocated $170 billion for border wall construction and deportation operations. The bill was signed into law July 4, 2025.',
 'strategic', 'Healthcare Access, Food Security, Social Safety Net',
 'https://www.pbs.org/newshour/nation/60-years-after-medicaid-was-signed-into-law-trumps-one-big-beautiful-bill-is-chiseling-it-back',
 'Contact your representative. Ask: "Did you vote for a bill that could strip healthcare from 11 million people and food assistance from 22 million families?" Support community health centers and food banks in your area.',
 589, 1, 'congressional'),

('2025-07-18', 'House Passes $9B DOGE Cuts Package Eliminating PBS, NPR, and Foreign Aid Funding',
 'The House passed Trump''s $9 billion DOGE-inspired spending cuts package, eliminating funding for the Corporation for Public Broadcasting (which distributes grants to NPR and PBS), gutting USAID foreign aid programs, and codifying cuts that DOGE had initiated unilaterally. The legislation gave statutory backing to agency dismantling that courts had blocked when done by executive action alone.',
 'tactical', 'Public Broadcasting, Foreign Aid, Government Services',
 'https://www.cnn.com/2025/07/18/politics/house-trump-doge-cuts-bill',
 'Contact your representative. Ask: "Did you vote to defund public broadcasting and eliminate foreign aid?" Support your local PBS/NPR stations and organizations challenging the cuts.',
 589, 1, 'congressional'),

('2025-06-01', 'House Republicans Advance Bill Allowing President to Abolish Agencies Without Congressional Oversight',
 'Republican members of the House Oversight Committee advanced the Reorganizing Government Act, which would allow the president to unilaterally abolish federal agencies and fire federal workers, bypassing the normal 60-vote Senate threshold by requiring only a simple majority to approve reorganization plans. The bill would codify DOGE''s agency dismantling work and remove congressional checks on executive restructuring of government.',
 'strategic', 'Separation of Powers, Congressional Authority, Government Structure',
 'https://www.afge.org/article/gop-lawmakers-push-through-legislation-that-would-let-trump-abolish-any-agency-without-congressional-oversight/',
 'Contact your representative. Ask: "Do you support giving any president the unilateral power to abolish agencies Congress created?" Demand preservation of congressional authority over government structure.',
 568, 1, 'congressional'),

-- ============================================================
-- HOUSE — Gutting DHS Oversight
-- ============================================================

('2026-01-26', 'Spending Bill Slashes DHS Oversight Funding by 77%, Eliminates Immigration Detention Ombudsman',
 'A compromise spending package cut funding for the DHS Office of Civil Rights and Civil Liberties from $43 million to $10 million — a 77% reduction — and eliminated the entire $28.6 million budget for the Office of the Immigration Detention Ombudsman. These offices investigate civil rights violations and abuse in immigration detention. The cuts came as ICE detention expanded dramatically under the Trump administration.',
 'tactical', 'Civil Rights Oversight, Immigration Detention Accountability',
 'https://rollcall.com/2026/01/26/spending-bill-would-solidify-trump-cuts-in-dhs-oversight/',
 'Contact your representatives on the Appropriations Committee. Ask: "Why did you vote to eliminate oversight of immigration detention conditions?" Support the ACLU and Human Rights First monitoring of detention facilities.',
 589, 1, 'congressional'),

-- ============================================================
-- CONGRESSIONAL — Insider Trading / Corruption
-- ============================================================

('2025-05-15', 'Rep. Bresnahan Sold $130K in Medicaid Provider Stocks 7 Days Before Voting to Slash Medicaid by $600B',
 'Rep. Rob Bresnahan offloaded up to $130,000 worth of stock in Centene, Elevance Health, UnitedHealth, and CVS Health — companies that manage nearly half of all Medicaid enrollees. Seven days later, he voted for the "Big Beautiful Bill" that slashed Medicaid by nearly $1 trillion. Bresnahan had introduced a stock trading ban bill just 9 days before the trades. He has made over 600 trades totaling $7 million since taking office.',
 'tactical', 'Congressional Ethics, Insider Trading, Public Trust',
 'https://www.nbcnews.com/politics/congress/rep-rob-bresnahan-sold-stock-medicaid-providers-vote-big-bill-rcna244859',
 'Contact your representative. Ask: "Do you support the Stop Insider Trading Act with real penalties, not just $200 fines?" Support Campaign Legal Center and CREW efforts to ban congressional stock trading.',
 869, 1, 'congressional'),

-- ============================================================
-- SENATE — Enabling Court Defiance
-- ============================================================

('2025-09-01', 'Senate Intelligence Committee Republicans Block Oversight of Trump Administration Intelligence Activities',
 'Senate Intelligence Committee Vice Chair Mark Warner revealed that the Trump administration was systematically blocking congressional intelligence oversight, and Republican committee members declined to use their subpoena authority to compel compliance. The obstruction included refusing to brief committee members on classified intelligence activities and blocking access to whistleblower complaints — a direct violation of intelligence oversight statutes established after Watergate.',
 'strategic', 'Congressional Oversight, Intelligence Community, Separation of Powers',
 'https://www.warner.senate.gov/public/index.cfm/2025/9/senate-intelligence-committee-vice-chair-on-trump-administration-blocking-congressional-intelligence-oversight',
 'Contact your senators, especially those on the Intelligence Committee. Ask: "Why are you allowing the executive branch to block intelligence oversight Congress is legally entitled to?" Demand enforcement of intelligence oversight statutes.',
 473, 1, 'congressional'),

-- ============================================================
-- SENATE — Lockstep Voting
-- ============================================================

('2025-12-31', 'Congressional Republicans Voted in Lockstep with Trump Throughout 2025, Abandoning Oversight Role',
 'Analysis showed that congressional Republicans voted with Trump''s position on virtually every major issue in 2025 — confirming unqualified nominees, passing the Big Beautiful Bill, codifying DOGE cuts, blocking oversight subpoenas, and declining to check executive overreach on tariffs, immigration, and agency dismantling. Two-thirds of Americans said the system of checks and balances was not working. Political scientists identified three reasons: policy alignment, lack of electoral fear, and fear of Trump''s retribution including primary challenges.',
 'strategic', 'Separation of Powers, Constitutional Checks and Balances, Democratic Accountability',
 'https://votehub.com/2026/02/04/republicans-in-congress-voted-in-lockstep-with-trump-in-2025/',
 'Contact your representative and senators. Ask: "When was the last time you voted against the president on anything?" Track their votes at votehub.com and congress.gov. Support primary challengers who prioritize constitutional duty over party loyalty.',
 589, 1, 'congressional');
