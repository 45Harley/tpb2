-- /talk Phase 4: Register group roles in user_roles table
-- Run on sandge5_tpb2
-- Date: 2026-02-14

INSERT INTO user_roles (role_id, role_name, description, responsibilities, requirements, points_per_task, is_volunteer_role, min_age, role_emoji, is_system_role) VALUES
(37, 'Group Facilitator', 'Leads a /talk deliberation group. Runs gatherer and crystallize to synthesize group ideas into proposals.', 'Run gatherer to find thematic connections, crystallize group ideas into proposals, manage group membership, promote members to facilitator, set group status (forming/active/crystallized/archived)', 'Account required. Any member can create a group and become facilitator. Additional facilitators can be promoted by existing facilitators.', 15, 1, NULL, '🎯', 0),
(38, 'Group Member', 'Participates in a /talk deliberation group. Contributes ideas and marks them shareable for group synthesis.', 'Share ideas to the group, brainstorm individually or collaboratively, mark ideas as shareable for gatherer/crystallize, react to and build on other members ideas', 'Account required. Join open groups freely, or be added to closed groups by a facilitator.', 5, 1, NULL, '💬', 0),
(39, 'Group Observer', 'Observes a /talk deliberation group. Can view ideas and proposals but cannot contribute.', 'View group ideas, read gather digests and crystallized proposals, follow group progress', 'Account required. Assigned by facilitator for stakeholders who need visibility without participation.', 0, 1, NULL, '👁', 0);
