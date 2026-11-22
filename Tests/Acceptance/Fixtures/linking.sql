-- Existing accounts an IdP identity may be matched against by username or email
INSERT INTO `be_users` (`uid`, `pid`, `username`, `password`, `admin`, `usergroup`, `email`, `realName`, `disable`, `tx_oauth2_client_configs`)
VALUES
	(11, 0, 'editor', '', 0, '1', 'editor@example.org', 'Editor', 0, 0),
	(12, 0, 'editor-verified', '', 0, '1', 'editor-verified@example.org', 'Editor verified', 0, 0),
	(14, 0, 'be-disabled', '', 0, '1', 'be-disabled@example.org', 'BE disabled', 1, 1),
	(15, 0, 'be-duplicate', '', 0, '1', 'be-duplicate@example.org', 'BE duplicate', 0, 2);

INSERT INTO `tx_oauth2_beuser_provider_configuration` (`uid`, `pid`, `parentid`, `provider`, `identifier`)
VALUES
	(1, 0, 14, 'mock-link', 'be-disabled'),
	(2, 0, 15, 'mock-link', 'be-duplicate'),
	(3, 0, 15, 'mock-link', 'be-duplicate');

-- Frontend: the site's storage folder (2) and another site's storage folder (3)
INSERT INTO `pages` (`uid`, `pid`, `title`, `slug`, `perms_userid`, `perms_groupid`, `perms_user`, `perms_group`, `perms_everybody`, `doktype`, `module`)
VALUES
	(2, 1, 'Users', '/users', 1, 1, 31, 31, 1, 254, 'fe_users'),
	(3, 0, 'Other site users', '/other-site-users', 1, 1, 31, 31, 1, 254, 'fe_users');

INSERT INTO `fe_groups` (`uid`, `pid`, `title`)
VALUES
	(1, 3, 'Other site members'),
	(2, 2, 'Members');

INSERT INTO `fe_users` (`uid`, `pid`, `username`, `password`, `usergroup`, `email`, `name`, `disable`, `tx_oauth2_client_configs`)
VALUES
	(21, 3, 'fe-otherpid', '', '1', 'fe-otherpid@example.org', 'FE other pid', 0, 0),
	(22, 2, 'fe-member', '', '2', 'fe-member@example.org', 'FE member', 0, 0);

-- Prints the uid of the logged-in frontend user
INSERT INTO `sys_template` (`uid`, `pid`, `title`, `root`, `clear`, `config`)
VALUES (1, 1, 'Main', 1, 3, 'page = PAGE\npage.10 = COA_INT\npage.10.10 = TEXT\npage.10.10.data = context:frontend.user:id\npage.10.10.wrap = <p id="fe-user-id">|</p>');
