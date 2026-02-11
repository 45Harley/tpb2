-- ================================================
-- Connecticut State Page - Data Updates
-- ================================================
-- Run after deploying z-states/ct/index.php
-- Updates states table with current CT data
-- ================================================

USE sandge5_tpb2;

-- Update state metadata with latest census/registration data
UPDATE states SET
    population = 3675069,
    capital_city = 'Hartford',
    largest_city = 'Bridgeport',
    largest_city_population = 148654,
    legislature_url = 'https://www.cga.ct.gov',
    voters_democrat = 850000,
    voters_republican = 500000,
    voters_independent = 1050000
WHERE abbreviation = 'CT';

-- ================================================
-- Verification
-- ================================================
-- SELECT state_id, state_name, population, capital_city, largest_city,
--        voters_democrat, voters_republican, voters_independent
-- FROM states WHERE abbreviation = 'CT';

-- ================================================
-- Notes
-- ================================================
-- Population: 3,675,069 (2024 Census estimate)
-- Voter registration: ~2.4M active voters
--   Democrat: ~850,000 (35%)
--   Republican: ~500,000 (21%)
--   Unaffiliated: ~1,050,000 (42%)
-- Source: portal.ct.gov/SOTS/Election-Services
--
-- Elected officials (Governor, Lt. Governor, AG, etc.)
-- are already in the elected_officials table and
-- maintained via OpenStates/manual updates.
-- No official inserts needed — CT officials are current.
