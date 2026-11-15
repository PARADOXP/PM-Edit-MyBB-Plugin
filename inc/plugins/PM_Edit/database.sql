-- PM Edit Plugin Database Schema

-- Create PM edit history table
CREATE TABLE IF NOT EXISTS {TABLE_PREFIX}pm_edit_history (
  hid INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pmid INT UNSIGNED NOT NULL DEFAULT '0',
  uid INT UNSIGNED NOT NULL DEFAULT '0',
  edituid INT UNSIGNED NOT NULL DEFAULT '0',
  original_subject VARCHAR(120) NOT NULL DEFAULT '',
  original_message TEXT NOT NULL,
  edited_subject VARCHAR(120) NOT NULL DEFAULT '',
  edited_message TEXT NOT NULL,
  editreason VARCHAR(150) NOT NULL DEFAULT '',
  dateline INT UNSIGNED NOT NULL DEFAULT '0',
  ipaddress VARCHAR(45) NOT NULL DEFAULT '',
  PRIMARY KEY (hid),
  KEY pmid (pmid),
  KEY edituid (edituid),
  KEY dateline (dateline)
) ENGINE=MyISAM;
