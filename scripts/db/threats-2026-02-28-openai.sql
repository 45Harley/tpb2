-- ============================================================
-- EXECUTIVE — AI Safety / OpenAI-Pentagon Deal (Feb 27-28, 2026)
-- Follow-on to threat #263 (Hegseth DPA threat against Anthropic)
-- ============================================================

INSERT INTO executive_threats
  (threat_date, title, description, threat_type, target,
   source_url, action_script, official_id, is_active, severity_score, branch)
VALUES

('2026-02-28', 'OpenAI Secures Pentagon Classified AI Access Hours After Anthropic Blacklisted for Refusing to Drop Ethics',
 'Hours after Defense Secretary Hegseth designated Anthropic a supply chain risk — banning all military contractors from using its technology — OpenAI announced a deal to deploy its AI models inside the Pentagon''s classified network. CEO Sam Altman claimed OpenAI would maintain the same red lines Anthropic insisted on: no mass surveillance, no autonomous weapons. Pentagon officials confirmed the agreement includes these restrictions. Critics question whether OpenAI''s guardrails have real enforcement teeth or are PR cover, noting that OpenAI swooped in to profit from a competitor''s principled refusal. The deal rewards compliance and punishes ethical resistance, creating a market incentive for AI companies to cave to government pressure rather than hold safety lines.',
 'tactical', 'AI Safety Standards, Corporate Ethics, Military AI Oversight',
 'https://www.npr.org/2026/02/27/nx-s1-5729118/trump-anthropic-pentagon-openai-ai-weapons-ban',
 'Contact your senators on the Armed Services and Commerce committees. Ask: "How will you ensure OpenAI''s claimed AI safety guardrails in the Pentagon deal are enforceable, not just PR?" Ask: "Does blacklisting Anthropic for ethics while rewarding OpenAI create a race to the bottom on AI safety?" Support organizations pushing for legislative AI safety standards that apply regardless of which company holds the contract.',
 9402, 1, 25, 'executive');

-- Score: 25 (Misconduct)
-- Rationale: OpenAI is not the primary wrongdoer — Hegseth's blacklisting of
-- Anthropic (threat #263, severity 400) is the coercive government action.
-- OpenAI's act is corporate opportunism: profiting from a competitor's
-- principled stand. They claim to keep the same ethics guardrails, but the
-- market signal is clear — resist and get blacklisted, comply and get rewarded.
-- Scored as misconduct (not higher) because OpenAI claims to maintain safety
-- lines and no law was broken. The systemic harm is in the incentive structure,
-- not OpenAI's specific actions.

-- Tags:
-- Corruption / Ethics (6): Corporate opportunism, profiting from competitor's
--   principled refusal. Market incentive to cave rather than hold safety lines.
-- Separation of Powers (7): Follows executive branch pattern of using
--   government power to pick winners/losers in private sector based on compliance.
