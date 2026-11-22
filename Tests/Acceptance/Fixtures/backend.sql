INSERT INTO `pages` (`uid`, `pid`, `title`, `slug`, `sys_language_uid`, `l10n_parent`, `l10n_source`, `perms_userid`, `perms_groupid`, `perms_user`, `perms_group`, `perms_everybody`, `doktype`, `is_siteroot`)
VALUES (1, 0, 'Main', '/', 0, 0, 0, 1, 1, 31, 31, 1, 1, 1);

INSERT INTO `be_groups` (`uid`, `pid`, `title`, `oauth2_id`)
VALUES
	(1, 0, 'Editors', ''),
	(2, 0, 'Mapped editors', 'typo3-editors'),
	(3, 0, 'Mapped admins', 'typo3-admins');
