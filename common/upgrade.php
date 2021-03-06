<?php
/**
 * The DMI-TCAT auto-upgrade script.
 *
 * This script can be executed from the command-line to upgrade your TCAT (mysql) database.
 *
 * This script will also be included from the capture interface to *test* whether upgrades
 * are available ('dry-run' mode) and inform the user.
 *
 * OPTIONAL COMMAND LINE ARGUMENTS
 *
 *     --non-interactive        run without any user interaction (for cron use) 
 *     --au0                    auto-upgrade everything with time consumption level 'trivial' (DEFAULT) (for non-interactive mode) 
 *     --au1                    auto-upgrade everything with time consumption level 'substantial' (for non-interactive mode) 
 *     --au2                    auto-upgrade everything with time consumption level 'expensive' (for non-interactive mode) 
 *     binname                  restrict upgrade actions to a specific bin 
 *
 * @package dmitcat
 */

if (php_sapi_name() == 'cli' || php_sapi_name() == 'cgi-fcgi') {
    include_once("../config.php");
    include "functions.php";
    include "../capture/common/functions.php";
}

function get_all_bins() {
    $dbh = pdo_connect();
    $sql = "select querybin from tcat_query_bins";
    $rec = $dbh->prepare($sql);
    $bins = array();
    if ($rec->execute() && $rec->rowCount() > 0) {
        while ($res = $rec->fetch()) {
            $bins[] = $res['querybin'];
        }
    }
    $dbh = false;
    return $bins;
}

/*
 * Ask the user whether to execute a certain upgrade step.
 */
function cli_yesnoall($update, $time_indication = 1, $commit = null) {
    $indicatestrings = array ( 'trivial', 'substantial', 'expensive' );
    $indicatestring = $indicatestrings[$time_indication];
    if (isset($commit)) {
        print "Would you like to execute this upgrade step: $update? [y]es, [n]o or [a]ll for this operation? (time indication: $indicatestring, commit $commit)\n";
    } else {
        print "Would you like to execute this upgrade step: $update? [y]es, [n]o or [a]ll for this operation? (time indication: $indicatestring)\n";
    }
    fscanf(STDIN, "%s\n", $str);
    $chr = substr($str, 0, 1);
    if ($chr == 'Y' || $chr == 'y') {
        return 'y';
    } elseif ($chr == 'A' || $chr == 'a') {
        return 'a';
    } else {
        return 'n';
    }
}

/**
 * Check for possible upgrades to the TCAT database.
 *
 * This function has two modes. In dry run mode, it tests whether the TCAT (mysql) database
 * is out-of-date. 
 * In normal mode, it will execute upgrades to the TCAT database. The upgrade script is intended to
 * be run from the command-line and allows for user-interaction. A special 'non-interactive'
 * option allows upgrades to be performed automatically (by cron). Even more refined behaviour
 * can be performed by setting the aulevel parameter.
 *
 * @param boolean $dry_run       Enable dry run mode.
 * @param boolean $interactive   Enable interactive mode.
 * @param integer $aulevel       Auto-upgrade level (0, 1 or 2)
 * @param string  $single        Restrict upgrades to a single bin
 *
 * @return array in dry run mode, ie. an associational array with two boolean keys for 'suggested' and 'required'; otherwise void
 */
function upgrades($dry_run = false, $interactive = true, $aulevel = 2, $single = null) {
    global $database;
    global $all_bins;
    $all_bins = get_all_bins();
    $dbh = pdo_connect();
    
    // Tracker whether an update is suggested, or even required during a dry run.
    // These values are ONLY tracked when doing a dry run; do not use them for CLI feedback.
    $suggested = false; $required = false;

    // 29/08/2014 Alter tweets tables to add new fields, ex. 'possibly_sensitive'
    
    $query = "SHOW TABLES";
    $rec = $dbh->prepare($query);
    $rec->execute();
    $results = $rec->fetchAll(PDO::FETCH_COLUMN);
    $ans = '';
    if ($interactive == false) {
        // require auto-upgrade level 1 or higher
        if ($aulevel > 0) {
            $ans = 'a';
        } else {
            $ans = 'SKIP';
        }
    }
    if ($ans !== 'SKIP') {
        foreach ($results as $k => $v) {
            if (!preg_match("/_tweets$/", $v)) continue; 
            if ($single && $v !== $single . '_tweets') { continue; }
            $query = "SHOW COLUMNS FROM $v";
            $rec = $dbh->prepare($query);
            $rec->execute();
            $columns = $rec->fetchAll(PDO::FETCH_COLUMN);
            $update = TRUE;
            foreach ($columns as $i => $c) {
                if ($c == 'from_user_withheld_scope') {
                    $update = FALSE;
                    break;
                }
            }
            if ($update && $dry_run) {
                $suggested = true;
                $update = false;
            }
            if ($update) {
                if ($ans !== 'a') {
                    $ans = cli_yesnoall("Add new columns and indexes (ex. possibly_sensitive) to table $v", 1, '639a0b93271eafca98c02e5a01968572d4435191');
                }
                if ($ans == 'a' || $ans == 'y') {
                    logit("cli", "Adding new columns (ex. possibly_sensitive) to table $v");
                    $definitions = array(
                                  "`from_user_withheld_scope` varchar(32)",
                                  "`from_user_favourites_count` int(11)",
                                  "`from_user_created_at` datetime",
                                  "`possibly_sensitive` tinyint(1)",
                                  "`truncated` tinyint(1)",
                                  "`withheld_copyright` tinyint(1)",
                                  "`withheld_scope` varchar(32)"
                                );
                    $query = "ALTER TABLE " . quoteIdent($v); $first = TRUE;
                    foreach ($definitions as $subpart) {
                        if (!$first) { $query .= ", "; } else { $first = FALSE; }
                        $query .= " ADD COLUMN $subpart";
                    }
                    // and add indexes
                    $query .= ", ADD KEY `from_user_created_at` (`from_user_created_at`)" .
                              ", ADD KEY `from_user_withheld_scope` (`from_user_withheld_scope`)" .
                              ", ADD KEY `possibly_sensitive` (`possibly_sensitive`)" .
                              ", ADD KEY `withheld_copyright` (`withheld_copyright`)" .
                              ", ADD KEY `withheld_scope` (`withheld_scope`)";
                    $rec = $dbh->prepare($query);
                    $rec->execute();
                }
            }
        }
    }

    // 16/09/2014 Create a new withheld table for every bin

    foreach ($all_bins as $bin) {
        if ($single && $bin !== $single) { continue; }
        $exists = false;
        foreach ($results as $k => $v) {
            if ($v == $bin . '_places') {
                $exists = true;
            }
        }
        if (!$exists && $dry_run) {
            $suggested = true;
            $exists = true;
        }
        if (!$exists) {
            $create = $bin . '_withheld';
            logit("cli", "Creating new table $create");
            $sql = "CREATE TABLE IF NOT EXISTS " . quoteIdent($create) . " (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `tweet_id` bigint(20) NOT NULL,
                    `user_id` bigint(20),
                    `country` char(5),
                        PRIMARY KEY (`id`),
                                KEY `user_id` (`user_id`),
                                KEY `tweet_id` (`user_id`),
                                KEY `country` (`country`)
                    ) ENGINE=MyISAM  DEFAULT CHARSET=utf8mb4";
            $create_withheld = $dbh->prepare($sql);
            $create_withheld->execute();
        }
    }

    // 16/09/2014 Create a new places table for every bin

    foreach ($all_bins as $bin) {
        if ($single && $bin !== $single) { continue; }
        $exists = false;
        foreach ($results as $k => $v) {
            if ($v == $bin . '_places') {
                $exists = true;
            }
        }
        if (!$exists && $dry_run) {
            $suggested = true;
            $exists = true;
        }
        if (!$exists) {
            $create = $bin . '_places';
            logit("cli", "Creating new table $create");
            $sql = "CREATE TABLE IF NOT EXISTS " . quoteIdent($create) . " (
                    `id` varchar(32) NOT NULL,
                    `tweet_id` bigint(20) NOT NULL,
                        PRIMARY KEY (`id`, `tweet_id`)
                    ) ENGINE=MyISAM  DEFAULT CHARSET=utf8mb4";
            $create_places = $dbh->prepare($sql);
            $create_places->execute();
        }
    }

    // 23/09/2014 Set global database collation to utf8mb4

    $query = "show variables like \"character_set_database\"";
    $rec = $dbh->prepare($query);
    $rec->execute();
    $results = $rec->fetch(PDO::FETCH_ASSOC);
    $character_set_database = isset($results['Value']) ? $results['Value'] : 'unknown';
    
    $query = "show variables like \"collation_database\"";
    $rec = $dbh->prepare($query);
    $rec->execute();
    $results = $rec->fetch(PDO::FETCH_ASSOC);
    $collation_database = isset($results['Value']) ? $results['Value'] : 'unknown';
    
    if ($character_set_database == 'utf8' && ($collation_database == 'utf8_general_ci' || $collation_database == 'utf8_unicode_ci')) {

        if ($dry_run) {
            $suggested = true;
        } else {
            $skipping = false;
            if (!$single) {
                $ans = '';
                if ($interactive == false) {
                    // require auto-upgrade level 1 or higher
                    if ($aulevel > 0) {
                        $ans = 'a';
                    } else {
                        $skipping = true;
                    }
                } else {
                    $ans = cli_yesnoall("Change default database character to utf8mb4", 1, '639a0b93271eafca98c02e5a01968572d4435191');
                }
                if ($ans == 'y' || $ans == 'a') {
                    logit("cli", "Converting database character set from utf8 to utf8mb4");
                    $query = "ALTER DATABASE $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                    $rec = $dbh->prepare($query);
                    $rec->execute();
                } else {
                    $skipping = true;
                }
            }

            if ($interactive == false) {
                // conversion per bin requires auto-upgrade level 2
                if ($aulevel > 1) {
                    $skipping = false;
                } else {
                    $skipping = true;
                }
            }

            if (!$skipping) {
                $query = "SHOW TABLES";
                $rec = $dbh->prepare($query);
                $rec->execute();
                $results = $rec->fetchAll(PDO::FETCH_COLUMN);
                $ans = '';
                if ($interactive == false) {
                    $ans = 'a';
                }
                foreach ($results as $k => $v) {
                    if (preg_match("/_places$/", $v) || preg_match("/_withheld$/", $v)) continue; 
                    if ($single && $v !== $single . '_tweets' && $v !== $single . '_hashtags' && $v !== $single . '_mentions' && $v !== $single . '_urls') continue;
                    if ($interactive && $ans !== 'a') {
                        $ans = cli_yesnoall("Convert table $v character set utf8 to utf8mb4", 2, '639a0b93271eafca98c02e5a01968572d4435191');
                    }
                    if ($ans == 'y' || $ans == 'a') {
                        logit("cli", "Converting table $v character set utf8 to utf8mb4");
                        $query ="ALTER TABLE $v DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                        $rec = $dbh->prepare($query);
                        $rec->execute();
                        $query ="ALTER TABLE $v CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                        $rec = $dbh->prepare($query);
                        $rec->execute();
                        logit("cli", "Repairing and optimizing table $v");
                        $query ="REPAIR TABLE $v";
                        $rec = $dbh->prepare($query);
                        $rec->execute();
                        $query ="OPTIMIZE TABLE $v";
                        $rec = $dbh->prepare($query);
                        $rec->execute();
                    }
                }
            }
        }

    }

    // 24/02/2015 remove media_type, photo_size_width and photo_size_height fields from _urls table
    //            create media table

    $query = "SHOW TABLES";
    $rec = $dbh->prepare($query);
    $rec->execute();
    $results = $rec->fetchAll(PDO::FETCH_COLUMN);
    foreach ($results as $k => $v) {
        if (!preg_match("/_urls$/", $v)) continue; 
        if ($single && $v !== $single . '_urls') { continue; }
        $query = "SHOW COLUMNS FROM $v";
        $rec = $dbh->prepare($query);
        $rec->execute();
        $columns = $rec->fetchAll(PDO::FETCH_COLUMN);
        $update_remove = FALSE;
        foreach ($columns as $i => $c) {
            if ($c == 'photo_size_width') {
                $update_remove = TRUE;
                break;
            }
        }
        if ($update_remove) {
            $suggested = true;
            $update_remove = false;
        }
        if ($update_remove) {
            logit("cli", "Removing columns media_type, photo_size_width and photo_size_height from table $v");
            $query = "ALTER TABLE " . quoteIdent($v) .
                        " DROP COLUMN `media_type`," .
                        " DROP COLUMN `photo_size_width`," .
                        " DROP COLUMN `photo_size_height`";
            $rec = $dbh->prepare($query);
            $rec->execute();
            // NOTE: column url_is_media_upload has been deprecated, but will not be removed because it signifies an older structure
        }
        $mediatable = preg_replace("/_urls$/", "_media", $v);
        if (!in_array($mediatable, array_values($results))) {
            if ($dry_run) {
                $suggested = true;
            } else {
                logit("cli", "Creating table $mediatable");
                $query = "CREATE TABLE IF NOT EXISTS " . quoteIdent($mediatable) . " (
                    `id` bigint(20) NOT NULL,
                    `tweet_id` bigint(20) NOT NULL,
                    `url` varchar(2048),
                    `url_expanded` varchar(2048),
                    `media_url_https` varchar(2048),
                    `media_type` varchar(32),
                    `photo_size_width` int(11),
                    `photo_size_height` int(11),
                    `photo_resize` varchar(32),
                    `indice_start` int(11),
                    `indice_end` int(11),
                    PRIMARY KEY (`id`, `tweet_id`),
                            KEY `media_url_https` (`media_url_https`),
                            KEY `media_type` (`media_type`),
                            KEY `photo_size_width` (`photo_size_width`),
                            KEY `photo_size_height` (`photo_size_height`),
                            KEY `photo_resize` (`photo_resize`)
                    ) ENGINE=MyISAM  DEFAULT CHARSET=utf8mb4";
                $rec = $dbh->prepare($query);
                $rec->execute();
            }
        }
        if ($update_remove && $dry_run == false) {
            logit("cli", "Please run the upgrade-media.php script to lookup media data for Tweets in your bins.");
        }
    }

    // 03/03/2015 Add comments column

    $query = "SHOW COLUMNS FROM tcat_query_bins";
    $rec = $dbh->prepare($query);
    $rec->execute();
    $columns = $rec->fetchAll(PDO::FETCH_COLUMN);
    $update = TRUE;
    foreach ($columns as $i => $c) {
        if ($c == 'comments') {
            $update = FALSE;
            break;
        }
    }
    if ($update && $dry_run) {
        $suggested = true;
        $update = false;
    }
    if ($update) {
        logit("cli", "Adding new comments column to table tcat_query_bins");
        $query = "ALTER TABLE tcat_query_bins ADD COLUMN `comments` varchar(2048) DEFAULT NULL";
        $rec = $dbh->prepare($query);
        $rec->execute();
    }

    // 17/04/2015 Change column to user_id to BIGINT in tcat_query_bins_users

    $query = "SHOW FULL COLUMNS FROM tcat_query_bins_users";
    $rec = $dbh->prepare($query);
    $rec->execute();
    $results = $rec->fetchAll();
    $update = FALSE;
    foreach ($results as $result) {
        if ($result['Field'] == 'user_id' && !preg_match("/bigint/", $result['Type'])) {
            $update = TRUE;
            break;
        }
    }
    if ($update) {
        $suggested = true;
        $required = true;        // this is a bugfix, therefore required
        $update = false;
    }
    if ($update) {
        logit("cli", "Changing column type for column user_id in table tcat_query_bins_users");
        $query = "ALTER TABLE tcat_query_bins_users MODIFY `user_id` BIGINT NULL";
        $rec = $dbh->prepare($query);
        $rec->execute();
    }

    // 13/08/2015 Use original retweet text for all truncated tweets & original/cached user for all retweeted tweets

    $ans = '';
    if ($interactive == false) {
        // require auto-upgrade level 2
        if ($aulevel > 1) {
            $ans = 'a';
        } else {
            $ans = 'SKIP';
        }
    }
    if ( $ans != 'SKIP' ) {
        foreach ($all_bins as $bin) {
            if ($single && $bin !== $single) { continue; }
            /*
             * Look for any tweets that have different length than pseudocode: length("RT @originaluser: " + text)
             * Also look for any tweets that have a different original username than what we find in the tweet text: "RT @retweetsuser: "
             * (.. yes, this can happen: the Twitter API can return a different retweeted username in the retweeted status substructure
             *      than in the retweet text itself - this may happen when a username has been renamed ..)
             *
             * The testing query should always return 0 for any bin we've already updated. This test is cheaper than the update itself,
             * which is more inclusive but does not return information about whether the step itself is neccessary.
             * Caveat: If the original tweet was exactly 140 characters, and the truncated retweet as well, this test will fail
             *         to detect it and still return 0 for that specific retweet. However, we can assume it will find other, more common,
             *         tweets that do return 1.
             */
            $tester = "select count(*) as count from " . $bin . "_tweets A inner join " . $bin . "_tweets B on A.retweet_id = B.id where LENGTH(A.text) != LENGTH(B.text) + LENGTH(B.from_user_name) + LENGTH('RT @: ') or substr(A.text, position('@' in A.text) + 1, position(': ' in A.text) - 5) != B.from_user_name limit 1";
            $rec = $dbh->prepare($tester);
            $rec->execute();
            $results = $rec->fetch(PDO::FETCH_ASSOC);
            $count = isset($results['count']) ? $results['count'] : 0;
            if ($count > 0) {
                if ($dry_run) {
                    $suggested = true;
                }
                if ($interactive && $ans !== 'a') {
                    $ans = cli_yesnoall("Use the original retweet text and username for truncated tweets in bin $bin - this will ALTER tweet contents", 2, 'n/a');
                }
                if ($ans == 'y' || $ans == 'a') {
                    logit("cli", "Using original retweet text and username for tweets in bin $bin");
                    /* Note: original tweet may have been length 140 and truncated retweet may have length 140,
                     * therefore we need to check for more than just length. Here we update everything with length >= 140 and ending with '...' */
                    $fixer = "update $bin" . "_tweets A inner join " . $bin . "_tweets B on A.retweet_id = B.id set A.text = CONCAT('RT @', B.from_user_name, ': ', B.text) where (length(A.text) >= 140 and A.text like '%…') or substr(A.text, position('@' in A.text) + 1, position(': ' in A.text) - 5) != B.from_user_name";
                    $rec = $dbh->prepare($fixer);
                    $rec->execute();
                }
            }
        }
    }

    // End of upgrades

    if ($dry_run) {
        return array( 'suggested' => $suggested, 'required' => $required );
    }
}

if (php_sapi_name() == 'cli' || php_sapi_name() == 'cgi-fcgi') {
    // make sure only one upgrade script is running
    $thislockfp = script_lock('upgrade');
    if (!is_resource($thislockfp)) {
        logit("cli", "upgrade.php already running, skipping this check");
        exit();
    }

    $interactive = true;
    $aulevel = 0;
    $single = null;

    if ($argc > 1) {
        for ($a = 1; $a < $argc; $a++) {
            if ($argv[$a] == '--non-interactive') {
                $interactive = false;
            } elseif ($argv[$a] == '--au0') {
                $aulevel = 0;
            } elseif ($argv[$a] == '--au1') {
                $aulevel = 1;
            } elseif ($argv[$a] == '--au2') {
                $aulevel = 2;
            } else {
                $single = $argv[$a];
            }
        }
    }

    if ($interactive) {
        logit("cli", "Running in interactive mode");
    } else {
        logit("cli", "Running in non-interactive mode");
        switch ($aulevel) {
            case 0: { logit("cli", "Automatically executing upgrades with label: trivial"); break; }
            case 1: { logit("cli", "Automatically executing upgrades with label: substantial"); break; }
            case 2: { logit("cli", "Automatically executing upgrades with label: expensive"); break; }
        }
    }

    if (isset($single)) {
        logit("cli", "Restricting upgrade to bin $single");
    } else {
        logit("cli", "Executing global upgrade");
    }

    upgrades(false, $interactive, $aulevel, $single);
}


