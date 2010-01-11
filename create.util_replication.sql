CREATE DATABASE `util_replication` ;
CREATE TABLE IF NOT EXISTS `util_replication`.`test` (
  `id` int(11) NOT NULL auto_increment,
  `created` datetime NOT NULL,
  `data` varchar(32) NOT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;
