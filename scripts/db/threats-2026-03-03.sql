-- ============================================================
-- New Threats Collection — 2026-03-03
-- 4 new threats from Mar 2-3 (not captured in prior collections)
-- Deduplicated against 274 existing threats in executive_threats
-- ============================================================

INSERT INTO executive_threats
  (threat_date, title, description, threat_type, target,
   source_url, action_script, official_id, is_active, severity_score, branch)
VALUES

-- ============================================================
-- EXECUTIVE — War Powers / Iran (Mar 2)
-- ============================================================

('2026-03-02', 'Congress Demands War Powers Vote as Trump Submits Belated Iran Notification After Launching Strikes Without Authorization',
 'Members of Congress demanded an immediate vote on a war powers resolution after President Trump submitted a belated War Powers Resolution notification regarding the Feb 28 strikes on Iran. Both the House and Senate prepared war powers resolutions to restrain the military campaign, which was launched without congressional authorization. Speaker Johnson called limiting Trump''s authority "dangerous," signaling most Republicans will block the resolutions. Even if passed, Trump would likely veto, and Congress lacks a two-thirds majority to override.',
 'strategic', 'Congressional War Powers, Constitutional Checks and Balances',
 'https://www.pbs.org/newshour/nation/members-of-congress-demand-swift-vote-on-war-powers-resolution-after-trump-orders-iran-strike-without-congressional-approval',
 'Call your representative and both senators TODAY. Ask: "Will you vote YES on the War Powers Resolution to reassert Congress''s constitutional authority over military action?" If they say no, ask: "Then who decides when America goes to war — one person or the people''s representatives?"',
 326, 1, 500, 'executive'),

-- Score: 500 (High Crime)
-- Rationale: Launching major combat operations against a sovereign nation without
-- congressional authorization is a direct violation of the War Powers Act and
-- Article I of the Constitution. The belated notification and expected veto of
-- any resolution shows contempt for congressional authority.
-- Tags: military (12), rule_of_law (14), corruption (6)

-- ============================================================
-- JUDICIAL — Tariff Refund Delay Rejected (Mar 2)
-- ============================================================

('2026-03-02', 'Federal Appeals Court Rejects Trump Admin Request to Delay $175B Tariff Refund Process After Supreme Court Ruling',
 'The U.S. Court of Appeals for the Federal Circuit declined the Trump administration''s request to delay implementation of the Supreme Court''s 6-3 ruling that struck down IEEPA tariffs. The administration asked for 90 days before issuing its mandate "to allow the political branches an opportunity to consider options," but the court cleared the way for the U.S. Court of International Trade to begin crafting relief for small businesses that successfully challenged the tariffs. The administration continues to resist returning $175 billion in tariffs ruled unconstitutional.',
 'tactical', 'Small Businesses, Importers, Rule of Law',
 'https://www.cbsnews.com/news/federal-appeals-court-rejects-trump-tariff-refund-delay-supreme-court/',
 'Contact your representative. Ask: "The Supreme Court ruled these tariffs unconstitutional. Why is the administration still trying to delay returning $175 billion to American businesses?" Support small business organizations pushing for immediate refunds.',
 326, 1, 300, 'executive'),

-- Score: 300 (High Crime threshold)
-- Rationale: Attempting to delay compliance with a Supreme Court ruling. The 6-3
-- decision was clear. Requesting 90 days to "consider options" is stalling.
-- $175B extracted from businesses under unconstitutional authority.
-- Tags: rule_of_law (14), economic (8)

-- ============================================================
-- EXECUTIVE — DOJ Abandons Law Firm Defense (Mar 2-3)
-- ============================================================

('2026-03-03', 'DOJ Drops Defense of All Four Unconstitutional Executive Orders Targeting Law Firms, Making Court Rulings Permanent',
 'The Department of Justice voluntarily dismissed its appeals of four federal court rulings that found Trump''s executive orders targeting law firms Perkins Coie, WilmerHale, Jenner & Block, and Susman Godfrey unconstitutional. The dismissal makes permanent the rulings that the orders violated the First Amendment and due process. However, other prestigious firms had already capitulated, agreeing to provide nearly $940 million in free legal work to the administration rather than fight in court — effectively letting intimidation succeed even as the legal basis collapsed.',
 'tactical', 'Legal Profession, First Amendment, Rule of Law',
 'https://www.nbcnews.com/politics/trump-administration/doj-drops-suits-law-firms-judges-find-executive-orders-unconstitutiona-rcna261434',
 'Share this story. The courts ruled these orders unconstitutional — but $940 million in coerced legal work was already extracted. Ask your representative: "What will you do to prevent executive orders from being used to punish lawyers for representing the wrong clients?"',
 326, 1, 350, 'executive'),

-- Score: 350 (High Crime)
-- Rationale: While the DOJ dropped the appeals (a partial win), the damage was done:
-- $940M in coerced capitulation from other firms. The orders were always
-- unconstitutional — using them to extort compliance before courts could act
-- is an abuse of executive power. Pattern of using legally baseless orders
-- to extract compliance through intimidation.
-- Tags: rule_of_law (14), civil_rights (3), corruption (6)

-- ============================================================
-- EXECUTIVE — ICE Oversight Defiance (Mar 2)
-- ============================================================

('2026-03-02', 'Judge Again Blocks DHS After Noem Secretly Reinstated Banned Policy Restricting Congressional Oversight of ICE Facilities',
 'U.S. District Judge Jia Cobb suspended DHS Secretary Kristi Noem''s latest attempt to require seven days'' notice before members of Congress can visit ICE detention facilities. After a court stayed the original policy in December, Noem secretly reinstated it through an undisclosed memorandum — which came to light only after lawmakers were denied entry to a Minnesota ICE facility despite presenting a valid court order. The judge found the administration cited no "concrete examples of safety issues" from unannounced visits and that it likely used restricted funds to enforce the policy.',
 'strategic', 'Congressional Oversight, Detention Conditions, Separation of Powers',
 'https://thehill.com/policy/national-security/5763401-judge-blocks-dhs-restrictions-visits/',
 'Call your representative. Ask: "Did you know DHS Secretary Noem secretly reinstated a policy a court already blocked, then denied entry to members of Congress with a valid court order? What is being hidden in these facilities?" Demand full transparency and unannounced oversight visits.',
 3000, 1, 450, 'executive');

-- Score: 450 (High Crime)
-- Rationale: Secretly reinstating a policy already blocked by a court is contempt
-- of judicial authority. Denying entry to lawmakers with a valid court order
-- is obstruction of congressional oversight. Using restricted funds adds a
-- fiscal violation. Pattern of defiance: original policy → court blocks →
-- secret reinstatement → caught → blocked again.
-- Tags: rule_of_law (14), immigration (5), corruption (6)


-- ============================================================
-- TAGGING
-- ============================================================
-- Run after INSERT to tag these threats:
-- (Use threat_ids from the auto-increment after insert)
--
-- Threat 275 (War Powers): military (12), rule_of_law (14), corruption (6)
-- Threat 276 (Tariff Refund): rule_of_law (14), economic (8)
-- Threat 277 (Law Firms): rule_of_law (14), civil_rights (3), corruption (6)
-- Threat 278 (ICE Oversight): rule_of_law (14), immigration (5), corruption (6)
