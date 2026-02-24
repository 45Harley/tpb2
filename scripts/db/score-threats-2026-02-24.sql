-- ============================================================
-- Score + Tag all 227 threats — 2026-02-24
-- Geometric 0-1000 criminality scale
-- Tags: 1=judicial_defiance 2=press_freedom 3=civil_rights
--   4=war_powers 5=immigration 6=corruption 7=separation_of_powers
--   8=election_integrity 9=foreign_policy 10=public_health
--   11=federal_workforce 12=first_amendment 13=due_process
--   14=fiscal 15=epstein
-- ============================================================

-- SEVERITY SCORES (0-1000 geometric scale)
UPDATE executive_threats SET severity_score = CASE threat_id
  WHEN 1 THEN 400   -- Mass Pardon Jan 6
  WHEN 2 THEN 350   -- Defied Court Order Deportations
  WHEN 3 THEN 250   -- Threatened Venezuela Invasion
  WHEN 4 THEN 600   -- Invaded Venezuela No Auth
  WHEN 5 THEN 200   -- Threatens Colombia+Greenland
  WHEN 6 THEN 300   -- Jan 6 Compensation Fund
  WHEN 7 THEN 300   -- Mass Fire Jan 6 Prosecutors
  WHEN 8 THEN 400   -- End Birthright Citizenship
  WHEN 9 THEN 100   -- Federal Hiring Freeze
  WHEN 10 THEN 80   -- Review NGO Funding
  WHEN 11 THEN 350  -- Vance: Judges Cant Control Exec
  WHEN 12 THEN 350  -- Defied Judge Funding Freeze
  WHEN 13 THEN 200  -- Deleted Police Misconduct DB
  WHEN 14 THEN 200  -- Revoked TPS Haitians
  WHEN 15 THEN 250  -- EO Covington Burling
  WHEN 16 THEN 250  -- EO Perkins Coie
  WHEN 17 THEN 300  -- Education Cuts 50%
  WHEN 18 THEN 250  -- EO Paul Weiss
  WHEN 19 THEN 500  -- Alien Enemies Act
  WHEN 20 THEN 450  -- Defied Judge Mid-Flight
  WHEN 21 THEN 350  -- Called for Boasberg Impeachment
  WHEN 22 THEN 250  -- Shut Down Immigration Oversight
  WHEN 23 THEN 250  -- EO WilmerHale
  WHEN 24 THEN 400  -- Coerced Law Firms $340M
  WHEN 25 THEN 250  -- EO Threatening University Funding
  WHEN 26 THEN 450  -- Refused Return Wrongfully Deported
  WHEN 27 THEN 350  -- Judge Wilkinson Lawlessness
  WHEN 28 THEN 300  -- Judge Rules Unprecedented Attack
  WHEN 29 THEN 500  -- Deported to CECOT
  WHEN 30 THEN 200  -- Jan 6 Revisionist Webpage
  WHEN 31 THEN 150  -- Warns GOP Re Impeachment
  WHEN 32 THEN 400  -- Troops to Chicago
  WHEN 33 THEN 200  -- Seize Russian Tanker
  WHEN 34 THEN 350  -- Control Venezuela Oil
  WHEN 35 THEN 750  -- ICE Kills Unarmed Woman
  WHEN 36 THEN 350  -- Withdraws 66 Intl Orgs
  WHEN 37 THEN 150  -- $1.5T Military Budget
  WHEN 38 THEN 50   -- Impeachment Articles Filed (accountability)
  WHEN 39 THEN 400  -- ICE Largest Police Force
  WHEN 40 THEN 200  -- $15K Visa Bonds 38 Countries
  WHEN 41 THEN 350  -- State Dept Western Hemisphere Control
  WHEN 42 THEN 350  -- DOJ Targets Somali Community
  WHEN 43 THEN 800  -- DOGE Cuts 720K Deaths
  WHEN 44 THEN 400  -- 300K Federal Workers Out
  WHEN 45 THEN 350  -- Political Loyalty Essays
  WHEN 46 THEN 50   -- Bipartisan Rebuke (pushback)
  WHEN 47 THEN 250  -- Strip 30K Union Rights
  WHEN 48 THEN 300  -- Combat Exercises Panama
  WHEN 49 THEN 350  -- Demands Venezuelan Oil Spoils
  WHEN 50 THEN 300  -- Military Option Greenland
  WHEN 51 THEN 350  -- Vance ICE Absolute Immunity
  WHEN 52 THEN 400  -- HHS Freezes $10B Blue States
  WHEN 53 THEN 150  -- Bribe Greenlanders
  WHEN 54 THEN 200  -- Seize 5th Tanker
  WHEN 55 THEN 400  -- FBI Blocks State Investigation
  WHEN 56 THEN 300  -- Five States Sue Funding Freeze
  WHEN 57 THEN 700  -- Border Patrol Shoots Couple Portland
  WHEN 58 THEN 100  -- Gabbard Excluded
  WHEN 59 THEN 500  -- Only My Own Morality
  WHEN 60 THEN 250  -- Threatens Senators War Powers
  WHEN 61 THEN 300  -- Greenland Hard Way
  WHEN 62 THEN 400  -- Land Strikes Mexican Cartels
  WHEN 63 THEN 100  -- Leaked Embargoed Jobs Data
  WHEN 64 THEN 200  -- Cancels Venezuela Takes Credit
  WHEN 65 THEN 100  -- 57 Times Democrats
  WHEN 66 THEN 400  -- Greenland Invasion Plan Special Forces
  WHEN 67 THEN 400  -- Military Strike Options Iran
  WHEN 68 THEN 100  -- Americans Leave Venezuela
  WHEN 69 THEN 100  -- Iran Threatens US Bases
  WHEN 70 THEN 50   -- Credit Card Cap No Enforcement
  WHEN 71 THEN 150  -- Most Cubans DEAD
  WHEN 72 THEN 300  -- USDA Freezes Food MN
  WHEN 73 THEN 450  -- Criminal Investigation Fed Chair
  WHEN 74 THEN 400  -- Noem 3000+ Minneapolis
  WHEN 75 THEN 400  -- DOJ Civil Rights Leaders Resign
  WHEN 76 THEN 500  -- DOJ Treats Good as Perpetrator
  WHEN 77 THEN 300  -- MN IL Sue Federal Invasion
  WHEN 78 THEN 350  -- Day of Reckoning Retribution
  WHEN 79 THEN 200  -- Threatens Sen Tillis
  WHEN 80 THEN 100  -- Tillis Blocks Fed Nominees
  WHEN 81 THEN 350  -- 1000 More CBP Minneapolis
  WHEN 82 THEN 500  -- Insurrection Act Threat
  WHEN 83 THEN 700  -- ICE Shoots 2nd Person MN
  WHEN 84 THEN 300  -- Suspends Visas 75 Countries
  WHEN 85 THEN 250  -- Ends TPS Somalis
  WHEN 86 THEN 200  -- Seize 6th Tanker
  WHEN 87 THEN 400  -- Noem ICE Discussed Insurrection Act
  WHEN 88 THEN 350  -- ICE Detains at Food Pantry
  WHEN 89 THEN 400  -- ICE Record 73K Detainees
  WHEN 90 THEN 600  -- Third Person Dies Custody
  WHEN 91 THEN 300  -- Birthright to SCOTUS
  WHEN 92 THEN 250  -- Tariffs NATO Allies Greenland
  WHEN 93 THEN 100  -- Trump Davos Europe
  WHEN 94 THEN 350  -- Board of Peace Replace UN
  WHEN 95 THEN 80   -- Nobel No Peace Obligation
  WHEN 96 THEN 150  -- 200% French Wine Tariff
  WHEN 97 THEN 200  -- EU Freezes Trade Deal
  WHEN 98 THEN 750  -- 2nd American Killed Minneapolis
  WHEN 99 THEN 150  -- Altered Photo Mock Protester
  WHEN 100 THEN 500 -- ICE Entering Homes No Warrants
  WHEN 101 THEN 400 -- FBI Agent Resigns Cover-Up
  WHEN 102 THEN 200 -- Pentagon Shifts From China
  WHEN 103 THEN 150 -- Vance Mexico City Policy
  WHEN 104 THEN 150 -- Philadelphia Slavery Exhibits
  WHEN 105 THEN 600 -- Lethal Strike Drug Boat 2 Dead
  WHEN 106 THEN 450 -- DOJ Investigating Political Opponents
  WHEN 107 THEN 100 -- Deputy AG Humanely
  WHEN 108 THEN 200 -- Preempt LA Fire Rebuilding
  WHEN 109 THEN 350 -- Threatens Iran Armada
  WHEN 110 THEN 250 -- Cuba National Emergency
  WHEN 111 THEN 500 -- DOJ Arrests Don Lemon
  WHEN 112 THEN 30  -- Body Cameras (accountability step)
  WHEN 113 THEN 400 -- ICE Targeting Refugees No Record
  WHEN 114 THEN 50  -- Trump Softer Touch
  WHEN 115 THEN 500 -- ICE Violated 96 Court Orders
  WHEN 116 THEN 200 -- Judge Demands ICE Justify
  WHEN 117 THEN 300 -- Courts Overwhelmed Detention
  WHEN 118 THEN 200 -- Preempts CA Wildfire
  WHEN 119 THEN 350 -- Exits 66 Intl Bodies
  WHEN 120 THEN 150 -- Sues Own Government $10B
  WHEN 121 THEN 15  -- IndyCar Race Near Mall
  WHEN 122 THEN 200 -- Partial Shutdown
  WHEN 123 THEN 400 -- DOGE Pentagon IT Risk
  WHEN 124 THEN 300 -- DOJ Weaponization Czar
  WHEN 125 THEN 150 -- Shutdown Ends No Reforms
  WHEN 126 THEN 350 -- Climate Vaccine Pages Down
  WHEN 127 THEN 500 -- Admin Tells Judges Ignore
  WHEN 128 THEN 300 -- DHS Bill Cuts IG
  WHEN 129 THEN 350 -- Layoffs Impact Black Women
  WHEN 130 THEN 400 -- Schedule F 50K
  WHEN 131 THEN 400 -- DOJ Journalist Sources
  WHEN 132 THEN 400 -- USAID 83% Cancelled
  WHEN 133 THEN 300 -- CBS $20B Chilling Effect
  WHEN 134 THEN 450 -- 131 Threats Against Judges
  WHEN 135 THEN 200 -- Bondi Death Sentences
  WHEN 136 THEN 250 -- ICE Defends After 2 Killings
  WHEN 137 THEN 500 -- Undermining 2026 Election
  WHEN 138 THEN 350 -- Bondi Grilled DOJ Targeting
  WHEN 139 THEN 250 -- White House Bans AP
  WHEN 140 THEN 400 -- Miller 100+ EOs
  WHEN 141 THEN 300 -- Miller DEI Elimination
  WHEN 142 THEN 400 -- Rescinds Sensitive Locations
  WHEN 143 THEN 350 -- Miller DOGE Alliance
  WHEN 144 THEN 600 -- Habeas Corpus Suspension
  WHEN 145 THEN 500 -- Courts No Jurisdiction
  WHEN 146 THEN 350 -- 3000/Day Arrest Quota
  WHEN 147 THEN 200 -- Miller Palantir Conflict
  WHEN 148 THEN 450 -- Dismantle the Left
  WHEN 149 THEN 400 -- Antifa Terrorist Designation
  WHEN 150 THEN 500 -- NSPM-7
  WHEN 151 THEN 800 -- Southern Spear 148 Killed
  WHEN 152 THEN 450 -- Plenary Authority
  WHEN 153 THEN 450 -- Criticism = Terrorism
  WHEN 154 THEN 550 -- Denaturalization Program
  WHEN 155 THEN 400 -- Operation Metro Surge
  WHEN 156 THEN 600 -- Land Strike Venezuela
  WHEN 157 THEN 500 -- Federal Immunity Good Killing
  WHEN 158 THEN 350 -- Brands Pretti Terrorist
  WHEN 159 THEN 500 -- EPA Endangerment Repeal
  WHEN 160 THEN 550 -- Secret Domestic Terrorist List
  WHEN 161 THEN 250 -- ICE 37 Force No Terminations
  WHEN 162 THEN 100 -- Pentagon Coal Power
  WHEN 163 THEN 350 -- Withholds Epstein Files
  WHEN 164 THEN 400 -- Surveilling Congressional Searches
  WHEN 165 THEN 300 -- DOGE Institutionalized
  WHEN 166 THEN 150 -- Retaliates GOP Senators Race
  WHEN 167 THEN 350 -- Pediatricians Challenge Vaccine
  WHEN 168 THEN 300 -- Second Carrier Iran
  WHEN 169 THEN 250 -- Pentagon Bars 34 Universities
  WHEN 170 THEN 200 -- Vought USAID for Security
  WHEN 171 THEN 350 -- Vought CDC Cuts Despite Sign
  WHEN 172 THEN 400 -- 2400 PhD Scientists Exit
  WHEN 173 THEN 200 -- DHS Partial Shutdown
  WHEN 174 THEN 200 -- Rubio Ukraine Concessions
  WHEN 175 THEN 550 -- 150+ ICE Court Violations
  WHEN 176 THEN 350 -- Botched Deportations
  WHEN 177 THEN 500 -- Nationalize Voting
  WHEN 178 THEN 450 -- Arrest Refugees No GC
  WHEN 179 THEN 300 -- Demands AP Change Stylebook
  WHEN 180 THEN 500 -- Military Prepared Strike Iran
  WHEN 181 THEN 350 -- FBI Investigates Antifa Money
  WHEN 182 THEN 150 -- Hegseth Removes Army Spox
  WHEN 183 THEN 300 -- Defense Lawyers DOJ Tracker
  WHEN 184 THEN 300 -- Noem Celebrates Source Catch
  WHEN 185 THEN 300 -- Board of Peace Meeting
  WHEN 186 THEN 500 -- Iran Strike 10-15 Days
  WHEN 187 THEN 400 -- SCOTUS Tariff Trump Attacks
  WHEN 188 THEN 400 -- Immediately Reimpose Tariff
  WHEN 189 THEN 300 -- Bessent Refuses Refunds
  WHEN 190 THEN 200 -- Patel FBI Jet Olympics
  WHEN 191 THEN 400 -- 80% ICE Leadership Fired
  WHEN 192 THEN 100 -- Veterans Sue Arch
  WHEN 193 THEN 400 -- Raises to 15% Tariff
  WHEN 194 THEN 250 -- DHS Shutdown Week 2
  WHEN 195 THEN 300 -- Education Transfers Despite Ban
  WHEN 196 THEN 200 -- Beirut Evacuation
  WHEN 197 THEN 300 -- Hegseth Threatens Anthropic
  WHEN 198 THEN 300 -- Miller Daily Micromanagement
  WHEN 199 THEN 200 -- Lutnick Epstein Island
  WHEN 200 THEN 350 -- Patel Refuses Epstein Answer
  WHEN 201 THEN 400 -- Bondi Redacted Epstein
  WHEN 202 THEN 350 -- Bondi Patel Comms Missing
  WHEN 203 THEN 350 -- Maxwell Clemency
  WHEN 204 THEN 100 -- Navy Sec Epstein Flight
  WHEN 205 THEN 500 -- Guantanamo 30K Detainees
  WHEN 206 THEN 400 -- DHS Military Base Detention
  WHEN 207 THEN 600 -- Alligator Alcatraz
  WHEN 208 THEN 400 -- Big Beautiful Bill $45B
  WHEN 209 THEN 550 -- Fort Bliss $1.26B
  WHEN 210 THEN 500 -- 23 Warehouses 18 States
  WHEN 211 THEN 250 -- Arizona Warehouse $70M
  WHEN 212 THEN 250 -- Pennsylvania $207M
  WHEN 213 THEN 250 -- El Paso $122.8M
  WHEN 214 THEN 250 -- Georgia $128.6M
  WHEN 215 THEN 250 -- Maryland Lawsuit
  WHEN 216 THEN 50  -- Kansas City BLOCKED (victory)
  WHEN 217 THEN 400 -- GEO CoreCivic Record Profits
  WHEN 218 THEN 100 -- Noem SD Travel $640K
  WHEN 219 THEN 150 -- Noem Dark Money $80K
  WHEN 220 THEN 250 -- Noem $200M Gulfstream Jets
  WHEN 221 THEN 200 -- $70M Boeing Queen Bed
  WHEN 222 THEN 80  -- Blanket Pilot Firing
  WHEN 223 THEN 300 -- Panama Canal Threat
  WHEN 224 THEN 250 -- Canada 51st State
  WHEN 225 THEN 250 -- Colombia Tariff Coercion
  WHEN 226 THEN 800 -- 148 Killed Venezuelan Strikes
  WHEN 227 THEN 400 -- Troops Chicago Portland
  ELSE severity_score
END
WHERE threat_id BETWEEN 1 AND 227;

-- ============================================================
-- TAG ASSIGNMENTS (threat_id, tag_id)
-- ============================================================
INSERT INTO threat_tag_map (threat_id, tag_id) VALUES
-- 1: Mass Pardon Jan 6
(1,1),(1,7),
-- 2: Defied Court Order Deportations
(2,1),(2,5),(2,13),
-- 3: Threatened Venezuela Invasion
(3,4),(3,9),
-- 4: Invaded Venezuela No Auth
(4,4),(4,9),(4,7),
-- 5: Threatens Colombia+Greenland
(5,4),(5,9),
-- 6: Jan 6 Compensation Fund
(6,7),(6,6),
-- 7: Mass Fire Jan 6 Prosecutors
(7,1),(7,7),
-- 8: End Birthright Citizenship
(8,3),(8,7),(8,5),
-- 9: Federal Hiring Freeze
(9,11),
-- 10: Review NGO Funding
(10,12),(10,7),
-- 11: Vance Judges Cant Control
(11,1),(11,7),
-- 12: Defied Judge Funding Freeze
(12,1),(12,7),
-- 13: Deleted Police Misconduct DB
(13,3),(13,6),
-- 14: Revoked TPS Haitians
(14,5),(14,3),
-- 15: EO Covington Burling
(15,12),(15,1),
-- 16: EO Perkins Coie
(16,12),(16,1),
-- 17: Education Cuts 50%
(17,11),(17,7),
-- 18: EO Paul Weiss
(18,12),(18,1),
-- 19: Alien Enemies Act
(19,13),(19,5),(19,1),
-- 20: Defied Judge Mid-Flight
(20,1),(20,13),(20,5),
-- 21: Boasberg Impeachment Call
(21,1),
-- 22: Shut Down Immigration Oversight
(22,5),(22,6),
-- 23: EO WilmerHale
(23,12),(23,1),
-- 24: Coerced Law Firms $340M
(24,6),(24,12),(24,1),
-- 25: EO Threatening University Funding
(25,12),(25,7),
-- 26: Refused Return Wrongfully Deported
(26,1),(26,13),(26,5),
-- 27: Judge Wilkinson Lawlessness
(27,1),
-- 28: Judge Rules Unprecedented Attack
(28,1),(28,12),
-- 29: Deported to CECOT
(29,13),(29,5),(29,3),
-- 30: Jan 6 Revisionist Webpage
(30,12),(30,7),
-- 31: Warns GOP Impeachment
(31,8),(31,7),
-- 32: Troops Chicago
(32,4),(32,3),(32,12),
-- 33: Seize Russian Tanker
(33,4),(33,9),
-- 34: Control Venezuela Oil
(34,9),(34,4),
-- 35: ICE Kills Unarmed Woman
(35,3),(35,5),(35,13),
-- 36: Withdraws 66 Intl Orgs
(36,9),(36,7),
-- 37: $1.5T Military Budget
(37,4),(37,14),
-- 38: Impeachment Articles vs Noem
(38,5),
-- 39: ICE Largest Police Force
(39,5),(39,3),(39,13),
-- 40: $15K Visa Bonds
(40,5),(40,3),
-- 41: Western Hemisphere Control
(41,9),(41,4),
-- 42: DOJ Targets Somali Community
(42,3),(42,5),(42,13),
-- 43: DOGE 720K Deaths
(43,10),(43,11),(43,3),
-- 44: 300K Workers Out
(44,11),(44,3),
-- 45: Political Loyalty Essays
(45,12),(45,11),(45,6),
-- 46: Bipartisan Rebuke
(46,9),
-- 47: Strip Union Rights
(47,11),(47,3),
-- 48: Combat Exercises Panama
(48,4),(48,9),
-- 49: Demands Venezuelan Oil
(49,9),(49,4),(49,6),
-- 50: Military Greenland
(50,4),(50,9),
-- 51: Vance Absolute Immunity
(51,1),(51,5),
-- 52: HHS Freezes $10B Blue States
(52,7),(52,10),(52,3),
-- 53: Bribe Greenlanders
(53,9),(53,6),
-- 54: Seize 5th Tanker
(54,4),(54,9),
-- 55: FBI Blocks State Investigation
(55,1),(55,6),(55,3),
-- 56: Five States Sue
(56,7),(56,1),
-- 57: Shoots Couple Portland
(57,3),(57,5),(57,13),
-- 58: Gabbard Excluded
(58,7),
-- 59: Only My Morality
(59,7),(59,1),
-- 60: Threatens Senators War Powers
(60,7),(60,4),
-- 61: Greenland Hard Way
(61,4),(61,9),
-- 62: Land Strikes Mexico
(62,4),(62,9),
-- 63: Leaked Jobs Data
(63,6),
-- 64: Cancels Venezuela
(64,9),(64,4),
-- 65: 57 Times Democrats
(65,12),
-- 66: Greenland Invasion Plan
(66,4),(66,9),
-- 67: Military Strike Iran
(67,4),(67,9),
-- 68: Americans Leave Venezuela
(68,9),
-- 69: Iran Threatens
(69,9),(69,4),
-- 70: Credit Card Cap
(70,7),
-- 71: Cubans DEAD
(71,9),
-- 72: USDA Freezes Food MN
(72,3),(72,7),(72,5),
-- 73: Criminal Probe Fed Chair
(73,7),(73,6),(73,1),
-- 74: Noem 3000+ Minneapolis
(74,5),(74,3),(74,4),
-- 75: DOJ Civil Rights Resign
(75,3),(75,6),(75,1),
-- 76: DOJ Treats Good Perpetrator
(76,3),(76,13),(76,1),
-- 77: MN IL Sue
(77,5),(77,7),
-- 78: Day of Reckoning
(78,12),(78,7),
-- 79: Threatens Tillis
(79,7),
-- 80: Tillis Blocks Nominees
(80,7),
-- 81: 1000 More CBP
(81,5),(81,3),(81,4),
-- 82: Insurrection Act Threat
(82,4),(82,3),(82,12),
-- 83: ICE Shoots 2nd Person
(83,3),(83,5),(83,13),
-- 84: Suspends Visas 75 Countries
(84,5),(84,3),(84,9),
-- 85: Ends TPS Somalis
(85,5),(85,3),
-- 86: 6th Tanker
(86,4),(86,9),
-- 87: Noem ICE Insurrection Act
(87,4),(87,5),
-- 88: ICE Food Pantry
(88,5),(88,13),(88,3),
-- 89: ICE 73K Detainees
(89,13),(89,5),
-- 90: Third Dies Custody
(90,3),(90,13),(90,5),
-- 91: Birthright SCOTUS
(91,3),(91,5),(91,7),
-- 92: Tariffs NATO Greenland
(92,9),(92,7),
-- 93: Davos Europe
(93,9),
-- 94: Board of Peace
(94,9),(94,7),
-- 95: Nobel No Peace
(95,9),
-- 96: French Wine Tariff
(96,9),(96,6),
-- 97: EU Freezes Trade
(97,9),
-- 98: 2nd American Killed
(98,3),(98,5),(98,13),
-- 99: Altered Photo
(99,12),(99,2),
-- 100: ICE No Warrants
(100,13),(100,3),(100,5),
-- 101: FBI Agent Resigns Cover-Up
(101,1),(101,3),(101,6),
-- 102: Pentagon China Shift
(102,4),(102,9),
-- 103: Vance Mexico City
(103,3),(103,9),
-- 104: Philadelphia Slavery
(104,3),(104,12),
-- 105: Lethal Strike Drug Boat
(105,4),(105,13),
-- 106: DOJ Political Opponents
(106,1),(106,12),(106,6),
-- 107: Deputy AG Humanely
(107,5),
-- 108: Preempt LA Fire
(108,7),
-- 109: Iran Armada
(109,4),(109,9),
-- 110: Cuba Emergency
(110,9),(110,7),
-- 111: DOJ Arrests Don Lemon
(111,2),(111,12),(111,1),
-- 112: Body Cameras
(112,5),
-- 113: ICE Refugees No Record
(113,5),(113,13),(113,3),
-- 114: Softer Touch
(114,5),
-- 115: ICE 96 Court Orders
(115,1),(115,5),(115,13),
-- 116: Judge Demands Justify
(116,1),(116,5),
-- 117: Courts Overwhelmed
(117,1),(117,13),(117,5),
-- 118: Preempts CA Wildfire
(118,7),
-- 119: Exits 66 Bodies
(119,9),(119,7),
-- 120: Sues Own Govt $10B
(120,6),(120,14),
-- 121: IndyCar Race
(121,6),
-- 122: Partial Shutdown
(122,11),(122,7),
-- 123: DOGE Pentagon IT
(123,11),(123,4),
-- 124: DOJ Weaponization Czar
(124,1),(124,6),
-- 125: Shutdown No Reforms
(125,5),(125,7),
-- 126: Pages Taken Down
(126,10),(126,12),
-- 127: Admin Tells Judges Ignore
(127,1),(127,7),
-- 128: DHS Cuts IG
(128,6),(128,7),
-- 129: Layoffs Black Women
(129,3),(129,11),
-- 130: Schedule F 50K
(130,11),(130,7),(130,3),
-- 131: DOJ Journalist Sources
(131,2),(131,12),
-- 132: USAID 83% Cancelled
(132,9),(132,7),(132,10),
-- 133: CBS $20B
(133,2),(133,12),
-- 134: 131 Threats Judges
(134,1),
-- 135: Bondi Death Sentences
(135,3),(135,1),
-- 136: ICE Defends 2 Killings
(136,3),(136,5),
-- 137: Undermine 2026 Election
(137,8),(137,7),
-- 138: Bondi DOJ Targeting
(138,1),(138,6),
-- 139: Bans AP
(139,2),(139,12),
-- 140: Miller 100+ EOs
(140,7),(140,11),(140,5),
-- 141: Miller DEI
(141,3),(141,11),(141,12),
-- 142: Sensitive Locations
(142,5),(142,3),(142,13),
-- 143: Miller DOGE
(143,11),(143,6),
-- 144: Habeas Corpus
(144,13),(144,1),(144,7),
-- 145: No Jurisdiction Abolish Court
(145,1),(145,7),
-- 146: 3000/Day Quota
(146,5),(146,13),
-- 147: Palantir Conflict
(147,6),
-- 148: Dismantle Left
(148,12),(148,3),
-- 149: Antifa Designation
(149,12),(149,3),(149,13),
-- 150: NSPM-7
(150,12),(150,3),(150,13),
-- 151: Southern Spear 148 Dead
(151,4),(151,13),(151,3),
-- 152: Plenary Authority
(152,7),(152,1),
-- 153: Criticism = Terrorism
(153,12),(153,7),
-- 154: Denaturalization
(154,3),(154,5),(154,13),
-- 155: Metro Surge
(155,5),(155,3),(155,4),
-- 156: Land Strike Venezuela
(156,4),(156,9),
-- 157: Federal Immunity Good Killing
(157,3),(157,1),(157,5),
-- 158: Brands Pretti Terrorist
(158,12),(158,3),
-- 159: EPA Endangerment
(159,10),(159,7),
-- 160: Secret Terrorist List
(160,12),(160,3),(160,13),
-- 161: ICE 37 Force No Term
(161,3),(161,5),(161,6),
-- 162: Pentagon Coal
(162,14),(162,7),
-- 163: Withholds Epstein
(163,15),(163,6),(163,1),
-- 164: Surveilling Congress Epstein
(164,15),(164,7),(164,6),
-- 165: DOGE Institutionalized
(165,11),(165,7),
-- 166: Retaliates GOP Race
(166,12),(166,6),
-- 167: Pediatricians Vaccine
(167,10),
-- 168: 2nd Carrier Iran
(168,4),
-- 169: Pentagon Bars Universities
(169,12),(169,4),
-- 170: Vought USAID Security
(170,6),(170,14),
-- 171: Vought CDC Cuts
(171,10),(171,7),
-- 172: 2400 PhD Exit
(172,10),(172,11),
-- 173: DHS Shutdown
(173,11),(173,7),
-- 174: Rubio Ukraine
(174,9),
-- 175: 150+ Court Violations
(175,1),(175,5),(175,13),
-- 176: Botched Deportations
(176,5),(176,1),(176,13),
-- 177: Nationalize Voting
(177,8),(177,7),
-- 178: Arrest Refugees No GC
(178,5),(178,13),(178,3),
-- 179: Demands AP Stylebook
(179,2),(179,12),
-- 180: Military Strike Iran
(180,4),(180,7),
-- 181: FBI Antifa Money
(181,12),(181,3),
-- 182: Hegseth Army Spox
(182,4),(182,11),
-- 183: DOJ Tracker
(183,1),(183,13),
-- 184: Noem Source Catch
(184,2),(184,12),
-- 185: Board Peace Meeting
(185,9),(185,7),
-- 186: Iran 10-15 Days
(186,4),(186,7),
-- 187: SCOTUS Tariff Attacks
(187,1),(187,7),
-- 188: Reimpose Tariff
(188,1),(188,7),
-- 189: Bessent Refuses Refunds
(189,6),(189,1),
-- 190: Patel FBI Jet
(190,6),(190,14),
-- 191: 80% ICE Leadership
(191,11),(191,5),(191,6),
-- 192: Veterans Sue Arch
(192,14),(192,7),
-- 193: 15% Tariff
(193,1),(193,7),
-- 194: DHS Shutdown Wk2
(194,11),(194,5),
-- 195: Education Transfers
(195,7),(195,11),
-- 196: Beirut Evacuation
(196,4),(196,9),
-- 197: Hegseth Anthropic
(197,4),(197,12),(197,7),
-- 198: Miller Micromanage
(198,5),(198,7),(198,11),
-- 199: Lutnick Epstein
(199,15),(199,6),
-- 200: Patel Epstein Answer
(200,15),(200,6),(200,1),
-- 201: Bondi Redacted Epstein
(201,15),(201,6),(201,1),
-- 202: Bondi Patel Comms Missing
(202,15),(202,6),
-- 203: Maxwell Clemency
(203,15),(203,6),
-- 204: Navy Sec Flight
(204,15),(204,6),
-- 205: Guantanamo
(205,13),(205,5),(205,14),
-- 206: Military Base Detention
(206,5),(206,4),(206,13),
-- 207: Alligator Alcatraz
(207,13),(207,5),(207,3),
-- 208: Big Beautiful Bill
(208,5),(208,13),(208,14),
-- 209: Fort Bliss
(209,13),(209,5),(209,3),
-- 210: 23 Warehouses
(210,5),(210,13),(210,14),
-- 211: Arizona Warehouse
(211,5),(211,13),(211,14),
-- 212: Pennsylvania
(212,5),(212,13),(212,14),
-- 213: El Paso
(213,5),(213,13),(213,14),
-- 214: Georgia
(214,5),(214,13),(214,14),
-- 215: Maryland
(215,5),(215,13),
-- 216: Kansas City BLOCKED
(216,5),
-- 217: Private Prison Profits
(217,6),(217,14),(217,5),
-- 218: Noem SD Travel
(218,6),(218,14),
-- 219: Noem Dark Money
(219,6),
-- 220: Noem Gulfstream
(220,6),(220,14),
-- 221: Boeing Queen Bed
(221,6),(221,14),
-- 222: Blanket Pilot
(222,6),
-- 223: Panama Canal
(223,9),(223,4),
-- 224: Canada 51st State
(224,9),
-- 225: Colombia Tariff
(225,9),(225,7),
-- 226: 148 Killed Venezuelan
(226,4),(226,3),(226,13),
-- 227: Troops Chicago Portland
(227,4),(227,3),(227,12);
